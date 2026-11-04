<?php

/**
 * One SMTP conversation of the local relay: the minimal server side of what
 * PHPMailer (Froxlor's mailer) speaks.
 *
 * Supported: EHLO/HELO, AUTH PLAIN, MAIL, RCPT, DATA, RSET, NOOP, QUIT. No
 * STARTTLS (the relay only listens on 127.0.0.1), no pipelining announced.
 *
 * The relay port is reachable by every local user, so the session is
 * defensive:
 * - MAIL requires a successful AUTH; the credentials are the ones Froxlor
 *   itself uses for SMTP (system.mail_smtp_user/_passwd), checked by the
 *   callback. Without this, any local process could send mails dressed up as
 *   panel mails, with the company's logo and the panel's envelope sender.
 * - The greeting is fixed text; nothing is looked up before the client has
 *   authenticated.
 * - Input is read through an own buffer with stream_select(): an idle
 *   timeout for every wait and an absolute deadline checked after every
 *   read (30 s until authenticated, 5 minutes for the session), so a client
 *   that trickles single bytes cannot hold a slot.
 * - Limits on line length, message size and number of recipients; lines that
 *   are too long are rejected, a message that is too large is read to its end
 *   and refused, never cut.
 * - Envelope addresses must be plain "dot-atom@domain" addresses; they are
 *   passed to sendmail as separate arguments after "--" anyway.
 * - Dot-unstuffing and the end-of-data marker are only recognised at the
 *   start of a line, also when a long line arrives in several chunks.
 */

namespace Froxlor\Ext\MailDesign;

final class SmtpSession
{
	public const MAX_MESSAGE_BYTES = 10485760;
	public const MAX_RECIPIENTS = 100;
	public const MAX_COMMAND_LINE = 2048;
	public const IDLE_TIMEOUT = 30;
	public const PRE_AUTH_DEADLINE = 30;
	public const SESSION_DEADLINE = 300;
	public const MAX_AUTH_FAILURES = 3;
	public const BANNER_NAME = 'froxlor-mail-design';

	/** Data lines are handed on in chunks of at most this size; longer lines are fine. */
	private const DATA_CHUNK = 8192;

	/** @var resource */
	private $in;

	/** @var resource */
	private $out;

	/** @var callable(string, string): bool */
	private $authenticate;

	/** @var callable(string, list<string>, string): array{0:bool, 1:string} */
	private $deliver;

	private int $maxMessageBytes;
	private int $authFailDelay;
	private int $idleTimeout;
	private int $preAuthDeadline;
	private int $sessionDeadline;

	private string $buffer = '';
	private bool $eof = false;
	private bool $authenticated = false;
	private int $authFailures = 0;
	private ?string $from = null;
	/** @var list<string> */
	private array $recipients = [];
	private float $deadline = 0.0;
	private float $startedAt = 0.0;

	/**
	 * @param callable(string $user, string $password): bool $authenticate
	 * @param callable(string $from, list<string> $recipients, string $data): array{0:bool, 1:string} $deliver
	 * @param string $name ignored; the greeting is always the fixed BANNER_NAME (kept for compatibility of the signature)
	 */
	public function __construct(
		callable $authenticate,
		callable $deliver,
		string $name = self::BANNER_NAME,
		int $maxMessageBytes = self::MAX_MESSAGE_BYTES,
		int $authFailDelay = 1,
		int $idleTimeout = self::IDLE_TIMEOUT,
		int $preAuthDeadline = self::PRE_AUTH_DEADLINE,
		int $sessionDeadline = self::SESSION_DEADLINE
	) {
		$this->authenticate = $authenticate;
		$this->deliver = $deliver;
		$this->maxMessageBytes = $maxMessageBytes;
		$this->authFailDelay = $authFailDelay;
		$this->idleTimeout = max(1, $idleTimeout);
		$this->preAuthDeadline = max(1, $preAuthDeadline);
		$this->sessionDeadline = max($this->preAuthDeadline, $sessionDeadline);
	}

	/**
	 * @param resource $in stream to read from (a socket, or STDIN under systemd socket activation)
	 * @param resource|null $out stream to write to; null = the same as $in
	 *        The streams are not closed here.
	 */
	public function handle($in, $out = null): void
	{
		$this->in = $in;
		$this->out = $out ?? $in;
		stream_set_blocking($this->in, true);
		$this->startedAt = microtime(true);
		$this->deadline = $this->startedAt + $this->preAuthDeadline;
		$this->send('220 ' . self::BANNER_NAME . ' ESMTP');

		while (true) {
			$line = $this->readLine(self::MAX_COMMAND_LINE);
			if ($line === null) {
				return;
			}
			if ($line === false) {
				$this->send('500 5.5.2 Line too long');
				continue;
			}
			$verb = strtoupper((string)strtok($line, ' '));
			$arg = ltrim((string)substr($line, strlen($verb)));
			switch ($verb) {
				case 'EHLO':
					$this->resetTransaction();
					$this->send('250-' . self::BANNER_NAME);
					$this->send('250-AUTH PLAIN');
					$this->send('250-8BITMIME');
					$this->send('250 SIZE ' . $this->maxMessageBytes);
					break;
				case 'HELO':
					$this->resetTransaction();
					$this->send('250 ' . self::BANNER_NAME);
					break;
				case 'AUTH':
					if (!$this->auth($arg)) {
						return;
					}
					break;
				case 'MAIL':
					if (!$this->authenticated) {
						$this->send('530 5.7.0 Authentication required');
						break;
					}
					if (!preg_match('/^FROM:\s*<([^>]*)>/i', $arg, $m) || !self::validAddress($m[1], true)) {
						$this->send('501 5.1.7 Invalid sender address');
						break;
					}
					$this->from = $m[1];
					$this->recipients = [];
					$this->send('250 2.1.0 OK');
					break;
				case 'RCPT':
					if ($this->from === null) {
						$this->send('503 5.5.1 MAIL first');
						break;
					}
					if (!preg_match('/^TO:\s*<([^>]*)>/i', $arg, $m) || !self::validAddress($m[1], false)) {
						$this->send('501 5.1.3 Invalid recipient address');
						break;
					}
					if (count($this->recipients) >= self::MAX_RECIPIENTS) {
						$this->send('452 4.5.3 Too many recipients');
						break;
					}
					$this->recipients[] = $m[1];
					$this->send('250 2.1.5 OK');
					break;
				case 'DATA':
					if ($this->from === null || !$this->recipients) {
						$this->send('503 5.5.1 MAIL and RCPT first');
						break;
					}
					if (!$this->data()) {
						return;
					}
					break;
				case 'RSET':
					$this->resetTransaction();
					$this->send('250 2.0.0 OK');
					break;
				case 'NOOP':
					$this->send('250 2.0.0 OK');
					break;
				case 'QUIT':
					$this->send('221 2.0.0 Bye');
					return;
				default:
					$this->send('502 5.5.2 Command not implemented');
			}
		}
	}

	/** @return bool false = close the connection */
	private function auth(string $arg): bool
	{
		if ($this->authenticated) {
			$this->send('503 5.5.1 Already authenticated');
			return true;
		}
		if ($this->from !== null) {
			$this->send('503 5.5.1 AUTH not allowed during a mail transaction');
			return true;
		}
		$parts = preg_split('/\s+/', trim($arg), 2) ?: [''];
		if (strtoupper($parts[0]) !== 'PLAIN') {
			$this->send('504 5.5.4 Only AUTH PLAIN is supported');
			return true;
		}
		$response = $parts[1] ?? '';
		if ($response === '') {
			$this->send('334 ');
			$line = $this->readLine(self::MAX_COMMAND_LINE);
			if ($line === null) {
				return false;
			}
			if ($line === false) {
				$this->send('500 5.5.2 Line too long');
				return true;
			}
			$response = trim($line);
		}
		if ($response === '*') {
			$this->send('501 5.0.0 Authentication cancelled');
			return true;
		}
		$decoded = base64_decode($response, true);
		$fields = $decoded === false ? [] : explode("\0", $decoded);
		$ok = false;
		if (count($fields) === 3 && $fields[1] !== '' && $fields[2] !== '') {
			$ok = (bool)($this->authenticate)($fields[1], $fields[2]);
		}
		if ($ok) {
			$this->authenticated = true;
			$this->deadline = $this->startedAt + $this->sessionDeadline;
			$this->send('235 2.7.0 Authentication successful');
			return true;
		}
		$this->authFailures++;
		if ($this->authFailDelay > 0) {
			sleep($this->authFailDelay);
		}
		$this->send('535 5.7.8 Authentication credentials invalid');
		if ($this->authFailures >= self::MAX_AUTH_FAILURES) {
			$this->send('421 4.7.0 Too many authentication failures');
			return false;
		}
		return true;
	}

	/** @return bool false = close the connection */
	private function data(): bool
	{
		$this->send('354 End data with <CR><LF>.<CR><LF>');
		$message = '';
		$tooLarge = false;
		$atLineStart = true;
		while (true) {
			$chunk = $this->readChunk(self::DATA_CHUNK);
			if ($chunk === null) {
				return false; // connection lost or timed out: deliver nothing
			}
			$complete = str_ends_with($chunk, "\n");
			if ($atLineStart) {
				if ($chunk === ".\r\n" || $chunk === ".\n") {
					break;
				}
				if (str_starts_with($chunk, '.')) {
					$chunk = substr($chunk, 1);
				}
			}
			$atLineStart = $complete;
			if ($tooLarge) {
				continue;
			}
			if (strlen($message) + strlen($chunk) > $this->maxMessageBytes) {
				$tooLarge = true;
				$message = '';
				continue;
			}
			$message .= $chunk;
		}
		$from = (string)$this->from;
		$recipients = $this->recipients;
		$this->resetTransaction();
		if ($tooLarge) {
			$this->send('552 5.3.4 Message too large');
			return true;
		}
		try {
			[$ok, $info] = ($this->deliver)($from, $recipients, $message);
		} catch (\Throwable $e) {
			[$ok, $info] = [false, $e->getMessage()];
		}
		if ($ok) {
			$this->send('250 2.0.0 OK queued');
		} else {
			self::log('Delivery failed: ' . (string)$info);
			$this->send('451 4.3.0 Handing over to sendmail failed');
		}
		return true;
	}

	private function resetTransaction(): void
	{
		$this->from = null;
		$this->recipients = [];
	}

	/**
	 * Reads one command line.
	 *
	 * @return string|false|null the line without CRLF; false = too long (already
	 *         consumed up to its end); null = connection closed or timed out
	 */
	private function readLine(int $max): string|false|null
	{
		$line = $this->readChunk($max);
		if ($line === null) {
			return null;
		}
		if (!str_ends_with($line, "\n")) {
			// Too long: skip the rest of the line.
			do {
				$rest = $this->readChunk($max);
				if ($rest === null) {
					return null;
				}
			} while (!str_ends_with($rest, "\n"));
			return false;
		}
		return rtrim($line, "\r\n");
	}

	/**
	 * Returns the buffered input up to and including the next newline, or
	 * $max bytes if no newline comes before. Waits for more input with
	 * stream_select(), bounded by the idle timeout and the absolute deadline.
	 *
	 * @return string|null null = closed, idle timeout or deadline reached
	 */
	private function readChunk(int $max): ?string
	{
		while (true) {
			$nl = strpos($this->buffer, "\n");
			if ($nl !== false && $nl < $max) {
				$chunk = substr($this->buffer, 0, $nl + 1);
				$this->buffer = (string)substr($this->buffer, $nl + 1);
				return $chunk;
			}
			if (strlen($this->buffer) >= $max) {
				$chunk = substr($this->buffer, 0, $max);
				$this->buffer = (string)substr($this->buffer, $max);
				return $chunk;
			}
			if ($this->eof) {
				return null;
			}
			$left = $this->deadline - microtime(true);
			if ($left <= 0) {
				$this->send('421 4.4.2 Session time limit reached');
				return null;
			}
			$wait = min((float)$this->idleTimeout, $left);
			$read = [$this->in];
			$write = $except = null;
			$ready = @stream_select($read, $write, $except, (int)$wait, (int)(($wait - floor($wait)) * 1000000));
			if ($ready === false) {
				return null;
			}
			if ($ready === 0) {
				if (microtime(true) >= $this->deadline) {
					$this->send('421 4.4.2 Session time limit reached');
				} else {
					$this->send('421 4.4.2 Timeout');
				}
				return null;
			}
			$data = @fread($this->in, 8192);
			if ($data === false || $data === '') {
				$this->eof = true;
				continue;
			}
			$this->buffer .= $data;
			if (microtime(true) >= $this->deadline) {
				$this->send('421 4.4.2 Session time limit reached');
				return null;
			}
		}
	}

	private function send(string $line): void
	{
		@fwrite($this->out, $line . "\r\n");
		@fflush($this->out);
	}

	/** Messages go to stderr (the journal); control characters are removed. */
	public static function log(string $message): void
	{
		if (defined('STDERR')) {
			fwrite(STDERR, (string)preg_replace('/[\x00-\x1f\x7f]+/', ' ', $message) . "\n");
		}
	}

	/**
	 * Envelope address check: "local@domain" with a dot-atom local part
	 * (RFC 5321, no quoted strings), exactly one "@", a host name as domain,
	 * ASCII only (SMTPUTF8 is not offered), no leading dash. The empty
	 * reverse path "<>" is allowed for the sender only.
	 */
	public static function validAddress(string $address, bool $allowEmpty): bool
	{
		if ($address === '') {
			return $allowEmpty;
		}
		if (strlen($address) > 254 || substr_count($address, '@') !== 1 || str_starts_with($address, '-')) {
			return false;
		}
		[$local, $domain] = explode('@', $address);
		$atext = "[A-Za-z0-9!#$%&'*+\\/=?^_`{|}~-]+";
		return strlen($local) <= 64
			&& preg_match('/^' . $atext . '(?:\.' . $atext . ')*$/D', $local) === 1
			&& preg_match('/^(?=.{1,253}$)[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*$/D', $domain) === 1;
	}
}
