<?php

/**
 * Applies all migrations of Mail Design.
 *
 *   php migrations/apply.php /var/www/froxlor
 *   php migrations/apply.php /var/www/froxlor --dry-run
 *
 * Connects via PDO with exactly the credentials and connection options
 * Froxlor itself uses (lib/userdata.inc.php), so the installation needs
 * neither a MySQL command line client nor a second configuration.
 *
 * Migrations are numbered files, applied in name order:
 * - *.sql is executed statement by statement.
 * - *.php returns a function that receives the connection, for steps that
 *   cannot be written idempotently in plain SQL.
 *
 * All migrations are idempotent; running them again is safe.
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit(1);
}

require __DIR__ . '/connection.php';

$froxlorDir = $argv[1] ?? '';
$dryRun = in_array('--dry-run', array_slice($argv, 2), true);

[$pdo, $db, $host, $user] = maildesign_connect($froxlorDir);
echo '  ' . maildesign_clean("Database '$db' on '$host' as '$user'") . "\n";

$files = array_merge(glob(__DIR__ . '/[0-9]*.sql') ?: [], glob(__DIR__ . '/[0-9]*.php') ?: []);
sort($files);
if (!$files) {
	echo "  No migrations found.\n";
	exit(0);
}

foreach ($files as $file) {
	$name = basename($file);
	if ($dryRun) {
		echo "  would apply: $name\n";
		continue;
	}

	try {
		if (str_ends_with($file, '.php')) {
			$migration = require $file;
			if (!is_callable($migration)) {
				throw new RuntimeException('does not return a function');
			}
			$migration($pdo);
		} else {
			foreach (maildesign_statements((string)file_get_contents($file)) as $statement) {
				$pdo->exec($statement);
			}
		}
	} catch (Throwable $e) {
		fwrite(STDERR, "  ERROR in $name:\n    " . maildesign_clean($e->getMessage()) . "\n");
		exit(1);
	}
	echo "  applied: $name\n";
}

/**
 * Splits an SQL file into single statements.
 *
 * Deliberately simple: the SQL migrations of this extension contain no
 * semicolons inside strings and no stored procedures. Comment lines are
 * removed, then the text is split at semicolons.
 */
function maildesign_statements(string $content): array
{
	$lines = [];
	foreach (explode("\n", $content) as $line) {
		if (preg_match('/^\s*--/', $line)) {
			continue;
		}
		$lines[] = $line;
	}
	$raw = explode(';', implode("\n", $lines));
	return array_values(array_filter(array_map('trim', $raw), static fn($s) => $s !== ''));
}
