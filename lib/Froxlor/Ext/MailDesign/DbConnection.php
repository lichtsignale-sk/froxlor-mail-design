<?php

/**
 * Opens a PDO connection with exactly the credentials and options Froxlor
 * itself uses (lib/userdata.inc.php).
 *
 * Used by the relay (which runs without Froxlor's classes) and by the
 * command line scripts, so the connection logic lives in one place. It needs
 * neither a MySQL command line client nor a second configuration.
 *
 * userdata.inc.php is NOT included. The file belongs to the panel user, and
 * the scripts usually run as root; including it would execute whatever the
 * panel user put there. It is tokenized instead, and only plain literal
 * assignments are accepted ($sql = [...]; $sql['key'] = '...'; strings,
 * nowdoc/heredoc without interpolation, numbers, booleans, null). Anything
 * else is refused. A symbolic link in its place is refused as well.
 */

namespace Froxlor\Ext\MailDesign;

use PDO;

final class DbConnection
{
	/**
	 * @param int $timeout connect timeout in seconds, 0 = driver default
	 * @return array{0:PDO, 1:string, 2:string, 3:string} PDO, database, host (or socket), user
	 * @throws \RuntimeException
	 */
	public static function open(string $froxlorDir, int $timeout = 0): array
	{
		$c = self::config($froxlorDir);
		if ($timeout > 0) {
			$c['options'][PDO::ATTR_TIMEOUT] = $timeout;
		}
		try {
			$pdo = new PDO($c['dsn'], $c['user'], $c['password'], $c['options']);
		} catch (\PDOException $e) {
			throw new \RuntimeException('Database connection failed: ' . $e->getMessage(), 0, $e);
		}
		return [$pdo, $c['db'], $c['where'], $c['user']];
	}

	/**
	 * Builds DSN and options exactly like Froxlor\Database\Database (2.3.x)
	 * for the unprivileged user: $sql['socket'] set => unix_socket; otherwise
	 * $sql['host'] and $sql['port'] (default 3306), and only then TLS from
	 * $sql['ssl']['caFile'] / $sql['ssl']['verifyServerCertificate'].
	 *
	 * @return array{dsn:string, user:string, password:string, options:array<int,mixed>, db:string, where:string, local:bool}
	 * @throws \RuntimeException
	 */
	public static function config(string $froxlorDir): array
	{
		$file = rtrim($froxlorDir, '/') . '/lib/userdata.inc.php';
		if ($froxlorDir === '' || is_link($file) || !is_file($file) || !is_readable($file)) {
			throw new \RuntimeException('lib/userdata.inc.php not found, not readable or a symbolic link.');
		}
		$sql = self::readUserdata($file)['sql'] ?? null;
		if (!is_array($sql) || !isset($sql['db'], $sql['user'])) {
			throw new \RuntimeException('userdata.inc.php contains no usable $sql configuration.');
		}

		$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
		$dsn = ['charset' => 'utf8', 'dbname' => (string)$sql['db']];
		$socket = $sql['socket'] ?? null;
		if ($socket !== null && $socket !== '') {
			$dsn['unix_socket'] = (string)$socket;
			$where = (string)$socket;
			$local = true;
		} else {
			$host = (string)($sql['host'] ?? 'localhost');
			$port = (string)($sql['port'] ?? '3306');
			$dsn['host'] = $host;
			$dsn['port'] = $port;
			$where = $host . ':' . $port;
			// "localhost" makes pdo_mysql use the default socket.
			$local = in_array(strtolower($host), ['localhost', '127.0.0.1', '::1', '[::1]'], true);
			$caFile = (string)($sql['ssl']['caFile'] ?? '');
			if ($caFile !== '') {
				$options[self::mysqlAttribute('SSL_CA')] = $caFile;
				$options[self::mysqlAttribute('SSL_VERIFY_SERVER_CERT')] = (bool)($sql['ssl']['verifyServerCertificate'] ?? false);
			}
		}
		$parts = [];
		foreach ($dsn as $k => $v) {
			$parts[] = $k . '=' . $v;
		}

		return [
			'dsn' => 'mysql:' . implode(';', $parts),
			'user' => (string)$sql['user'],
			'password' => (string)($sql['password'] ?? ''),
			'options' => $options,
			'db' => (string)$sql['db'],
			'where' => $where,
			'local' => $local,
		];
	}

	/**
	 * Reads the variables of a userdata.inc.php without executing it.
	 *
	 * Error messages name the line and the kind of token, never its text: the
	 * file is controlled by another user and the message may end up on root's
	 * terminal.
	 *
	 * @return array<string,mixed> variable name => value (e.g. 'sql', 'sql_root')
	 * @throws \RuntimeException on anything that is not a plain literal assignment
	 */
	public static function readUserdata(string $file): array
	{
		$code = @file_get_contents($file, false, null, 0, 1048576);
		if ($code === false) {
			throw new \RuntimeException('userdata.inc.php is not readable.');
		}
		$tokens = array_values(array_filter(
			token_get_all($code),
			static fn($t) => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG], true)
		));
		$pos = 0;
		$vars = [];

		$peek = static function () use (&$tokens, &$pos) {
			return $tokens[$pos] ?? null;
		};
		$fail = static function (string $what) use (&$tokens, &$pos): void {
			$t = $tokens[$pos] ?? null;
			$near = is_array($t) ? token_name($t[0]) . ' on line ' . $t[2] : ($t === null ? 'end of file' : 'a punctuation character');
			throw new \RuntimeException("Unsupported content in userdata.inc.php: expected $what, found $near.");
		};
		$expect = static function (string $char) use (&$tokens, &$pos, $fail): void {
			if (($tokens[$pos] ?? null) !== $char) {
				$fail("'$char'");
			}
			$pos++;
		};

		$depth = 0;
		$value = null;
		$value = static function () use (&$value, &$tokens, &$pos, &$depth, $peek, $fail, $expect) {
			$t = $peek();
			if ($t === '[' || (is_array($t) && $t[0] === T_ARRAY)) {
				if (++$depth > 16) {
					$fail('less deeply nested arrays');
				}
				$close = ']';
				if ($t !== '[') {
					$pos++;
					$expect('(');
					$close = ')';
				} else {
					$pos++;
				}
				$result = [];
				while ($peek() !== $close) {
					$item = $value();
					if ($peek() === null) {
						$fail("'$close'");
					}
					if (is_array($peek()) && $peek()[0] === T_DOUBLE_ARROW) {
						$pos++;
						if (!is_int($item) && !is_string($item)) {
							$fail('a scalar array key');
						}
						$result[$item] = $value();
					} else {
						$result[] = $item;
					}
					if ($peek() === ',') {
						$pos++;
					} elseif ($peek() !== $close) {
						$fail("',' or '$close'");
					}
				}
				$pos++;
				$depth--;
				return $result;
			}
			if ($t === '-') {
				$pos++;
				$n = $value();
				if (!is_int($n) && !is_float($n)) {
					$fail('a number');
				}
				return -$n;
			}
			if (!is_array($t)) {
				$fail('a value');
			}
			switch ($t[0]) {
				case T_CONSTANT_ENCAPSED_STRING:
					$pos++;
					$quote = $t[1][0];
					$body = substr($t[1], 1, -1);
					return $quote === "'"
						? str_replace(['\\\\', "\\'"], ['\\', "'"], $body)
						: stripcslashes($body);
				case T_START_HEREDOC:
					$pos++;
					$body = '';
					if (is_array($peek()) && $peek()[0] === T_ENCAPSED_AND_WHITESPACE) {
						$body = $peek()[1];
						$pos++;
					}
					if (!is_array($peek()) || $peek()[0] !== T_END_HEREDOC) {
						$fail('end of nowdoc/heredoc without interpolation');
					}
					$pos++;
					// The closing newline belongs to the delimiter, not to the value.
					$body = (string)preg_replace('/\r?\n$/', '', $body);
					return str_contains($t[1], "'") ? $body : stripcslashes($body);
				case T_LNUMBER:
					$pos++;
					return intval($t[1], 0);
				case T_DNUMBER:
					$pos++;
					return (float)$t[1];
				case T_STRING:
					$word = strtolower($t[1]);
					if (in_array($word, ['true', 'false', 'null'], true)) {
						$pos++;
						return $word === 'null' ? null : $word === 'true';
					}
			}
			$fail('a literal value');
		};

		while ($peek() !== null) {
			$t = $peek();
			if (!is_array($t) || $t[0] !== T_VARIABLE) {
				$fail('a variable assignment');
			}
			$name = substr($t[1], 1);
			$pos++;
			$path = [];
			while ($peek() === '[') {
				$pos++;
				$key = $value();
				if (!is_int($key) && !is_string($key)) {
					$fail('a scalar array key');
				}
				$path[] = $key;
				$expect(']');
			}
			$expect('=');
			$v = $value();
			$expect(';');

			$ref = &$vars[$name];
			foreach ($path as $key) {
				if (!is_array($ref)) {
					$ref = [];
				}
				$ref = &$ref[$key];
			}
			$ref = $v;
			unset($ref);
		}

		return $vars;
	}

	/**
	 * pdo_mysql driver attribute by name (e.g. 'SSL_CA'). PHP 8.4 added
	 * Pdo\Mysql::ATTR_* and PHP 8.5 deprecates PDO::MYSQL_ATTR_*; Froxlor
	 * 2.3.16 does the same.
	 */
	public static function mysqlAttribute(string $name): int
	{
		if (defined('Pdo\\Mysql::ATTR_' . $name)) {
			return constant('Pdo\\Mysql::ATTR_' . $name);
		}
		return constant('PDO::MYSQL_ATTR_' . $name);
	}
}
