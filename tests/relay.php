<?php

/**
 * Tests the SMTP relay without Froxlor, a database or a mail server:
 *
 *   php tests/relay.php                     protocol, limits, delivery, logo loading
 *   php tests/relay.php /path/to/froxlor    additionally sends a real mail with the
 *                                           PHPMailer of that Froxlor installation
 *                                           (only read from there) through the relay
 *
 * sendmail is replaced by a small script that stores what it receives. The
 * conversations run over a socket pair with a forked client (needs pcntl,
 * as in Debian's PHP CLI); without pcntl those tests are skipped.
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit(1);
}

error_reporting(E_ALL);
set_error_handler(static function (int $no, string $msg, string $file, int $line): bool {
	if (!(error_reporting() & $no)) {
		return false;
	}
	throw new ErrorException($msg, 0, $no, $file, $line);
});

$lib = __DIR__ . '/../lib/Froxlor/Ext/MailDesign';
foreach (['Lang', 'Styler', 'LogoStore', 'DbConnection', 'SmtpSession', 'Relay'] as $class) {
	require $lib . '/' . $class . '.php';
}

use Froxlor\Ext\MailDesign\LogoStore;
use Froxlor\Ext\MailDesign\Relay;
use Froxlor\Ext\MailDesign\SmtpSession;

$failures = 0;
$check = static function (bool $ok, string $what) use (&$failures): void {
	echo ($ok ? '  ok   ' : '  FAIL ') . $what . "\n";
	$failures += $ok ? 0 : 1;
};

$tmp = sys_get_temp_dir() . '/md-relay-' . bin2hex(random_bytes(4));
mkdir($tmp);
mkdir($tmp . '/froxlor/img', 0777, true);
mkdir($tmp . '/froxlor/lib');
mkdir($tmp . '/spool');

// Fake sendmail: stores arguments and message, exit code from a file.
$sendmail = $tmp . '/sendmail';
file_put_contents($sendmail, '#!' . PHP_BINARY . "\n<?php\n"
	. '$d = ' . var_export($tmp . '/spool', true) . ";\n"
	. '$n = count(glob($d . "/*.eml"));' . "\n"
	. 'file_put_contents($d . "/$n.args", json_encode(array_slice($argv, 1)));' . "\n"
	. 'file_put_contents($d . "/$n.eml", stream_get_contents(STDIN));' . "\n"
	. 'exit(is_file($d . "/fail") ? 75 : 0);' . "\n");
chmod($sendmail, 0755);
$spool = static function () use ($tmp): array {
	$out = [];
	foreach (glob($tmp . '/spool/*.eml') ?: [] as $f) {
		$out[] = [json_decode((string)file_get_contents(substr($f, 0, -4) . '.args'), true), (string)file_get_contents($f)];
	}
	return $out;
};
$clearSpool = static function () use ($tmp): void {
	foreach (glob($tmp . '/spool/*') ?: [] as $f) {
		unlink($f);
	}
};

$settings = [
	'system.hostname' => 'h.example',
	'system.mail_smtp_user' => 'froxlor-mail-design',
	'system.mail_smtp_passwd' => 'c0ffee',
	'panel.standardlanguage' => 'en',
	'ext_maildesign.enabled' => '1',
	'ext_maildesign.company_name' => 'Example Ltd',
	'ext_maildesign.show_server' => '1',
	'ext_maildesign.header_color' => '#1f2937',
	'ext_maildesign.accent_color' => '#2563eb',
	'ext_maildesign.footer' => '',
	'ext_maildesign.logo' => '',
];
$relay = new Relay($tmp . '/froxlor', static function () use (&$settings): array {
	return $settings;
}, $sendmail);

$mail = "From: Panel <admin@example.org>\r\nTo: b@example.org\r\nSubject: Hello\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\nLine one\r\nUsername: x\r\nPassword: y\r\n";

// --- Authentication -------------------------------------------------------
$check($relay->authenticate('froxlor-mail-design', 'c0ffee'), 'correct credentials accepted');
$check(!$relay->authenticate('froxlor-mail-design', 'wrong'), 'wrong password rejected');
$check(!$relay->authenticate('someone', 'c0ffee'), 'wrong user rejected');
$saved = $settings;
$settings['system.mail_smtp_passwd'] = '';
$check(!$relay->authenticate('froxlor-mail-design', ''), 'empty stored password never matches');
$settings = $saved;

// --- Delivery ---------------------------------------------------------------
[$ok] = $relay->deliver('admin@example.org', ['b@example.org', 'c@example.org'], $mail);
$out = $spool();
$check($ok && count($out) === 1, 'mail handed to sendmail');
$check(($out[0][0] ?? null) === ['-i', '-f', 'postmaster@h.example', '--', 'b@example.org', 'c@example.org'], 'sendmail arguments: envelope postmaster@hostname, recipients after --');
$check(str_contains($out[0][1] ?? '', 'X-Mail-Design: 1') && !str_contains($out[0][1] ?? '', "\r\n"), 'mail styled, line endings converted for sendmail');
$clearSpool();

$settings['ext_maildesign.enabled'] = '0';
$relay->deliver('admin@example.org', ['b@example.org'], $mail);
$check(($spool()[0][1] ?? '') === str_replace("\r\n", "\n", $mail), 'styling switched off: mail passes unchanged');
$settings = $saved;
$clearSpool();

$settings['system.hostname'] = 'bad host;rm -rf';
$relay->deliver('admin@example.org', ['b@example.org'], $mail);
$check(($spool()[0][0][2] ?? '') === 'admin@example.org', 'invalid hostname: envelope sender falls back to MAIL FROM');
$settings = $saved;
$clearSpool();

$big = $mail . str_repeat("filler line\r\n", (int)(Relay::STYLE_LIMIT / 12) + 10);
$relay->deliver('admin@example.org', ['b@example.org'], $big);
$check(($spool()[0][1] ?? '') === str_replace("\r\n", "\n", $big), 'very large mail passes unstyled');
$clearSpool();

touch($tmp . '/spool/fail');
[$ok] = $relay->deliver('admin@example.org', ['b@example.org'], $mail);
$check(!$ok, 'sendmail failure is reported');
$clearSpool();

$broken = new Relay($tmp . '/froxlor', static fn(): array => $settings, $tmp . '/does-not-exist');
[$ok] = $broken->deliver('admin@example.org', ['b@example.org'], $mail);
$check(!$ok, 'missing sendmail is reported');

// --- Logo loading -----------------------------------------------------------
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
file_put_contents($tmp . '/froxlor/img/mail-logo.png', $png);
file_put_contents($tmp . '/froxlor/img/0123abcd.gif', base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'));
file_put_contents($tmp . '/froxlor/img/notimage.png', 'text');
file_put_contents($tmp . '/secret.png', $png);
symlink($tmp . '/secret.png', $tmp . '/froxlor/img/link.png');
$check(($relay->loadLogo('img/mail-logo.png?v=123')[0] ?? null) === 'image/png', 'uploaded logo is loaded');
$check(($relay->loadLogo('img/0123abcd.gif?v=1')[0] ?? null) === 'image/gif', 'logo from a settings import (random name in img/) is loaded');
$check($relay->loadLogo('img/../../secret.png') === null, 'path traversal refused');
$check($relay->loadLogo($tmp . '/secret.png') === null, 'absolute path refused');
$check($relay->loadLogo('https://example.org/logo.png') === null, 'URL refused');
$check($relay->loadLogo('img/link.png') === null, 'symbolic link refused');
$check($relay->loadLogo('img/notimage.png') === null, 'non-image refused');
$check($relay->loadLogo('') === null, 'no logo set');
$settings['ext_maildesign.logo'] = 'img/mail-logo.png?v=1';
$relay->deliver('admin@example.org', ['b@example.org'], $mail);
$check(str_contains($spool()[0][1] ?? '', 'Content-ID: <logo-'), 'logo embedded on delivery');
$settings = $saved;
$clearSpool();

// Oversized logo (only the header matters to getimagesize)
$hugePng = "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . pack('NN', LogoStore::MAX_DIMENSION + 1, 10) . "\x08\x06\x00\x00\x00" . pack('N', 0);
file_put_contents($tmp . '/froxlor/img/huge.png', $hugePng);
$check($relay->loadLogo('img/huge.png') === null, 'logo larger than 2000 pixels refused');

// --- Envelope addresses -------------------------------------------------------
$good = ['a@example.org', 'first.last+tag@sub.example.org', "o'brien@example.org", 'x@localhost'];
$bad = ['-oQ/tmp@example.org', 'a b@example.org', 'a@b@example.org', '"quoted"@example.org', 'a..b@example.org', '.a@example.org',
	'a@-example.org', 'a@example..org', "a\n@example.org", 'zoë@example.org', 'a@[127.0.0.1]', 'a@exa_mple.org', 'noat', str_repeat('a', 65) . '@example.org'];
$check(count(array_filter($good, static fn($a) => SmtpSession::validAddress($a, false))) === count($good), 'valid dot-atom addresses accepted');
$accepted = array_filter($bad, static fn($a) => SmtpSession::validAddress($a, false));
$check($accepted === [], 'invalid addresses refused' . ($accepted ? ': ' . implode(', ', $accepted) : ''));
$check(SmtpSession::validAddress('', true) && !SmtpSession::validAddress('', false), 'empty reverse path only for the sender');

// --- Peer check (socket activation) ------------------------------------------
$procNetTcp = "  sl  local_address rem_address   st tx_queue rx_queue tr tm->when retrnsmt   uid  timeout inode\n"
	. "   0: 0100007F:09E2 00000000:0000 0A 00000000:00000000 00:00000000 00000000     0        0 1001 1 0 100 0 0 10 0\n"
	. "   1: 0100007F:C350 0100007F:09E2 01 00000000:00000000 00:00000000 00000000  1000        0 1002 1 0 20 4 30 10 -1\n"
	. "   2: 0100007F:09E2 0100007F:C350 01 00000000:00000000 00:00000000 00000000     0        0 1003 1 0 20 4 30 10 -1\n"
	. "   3: 00000000:C351 00000000:0000 0A 00000000:00000000 00:00000000 00000000    33        0 1004 1 0 100 0 0 10 0\n";
$check(Relay::uidFromProcNetTcp($procNetTcp, 50000) === 1000, 'peer uid found by the client port');
$check(Relay::uidFromProcNetTcp($procNetTcp, 50001) === null && Relay::uidFromProcNetTcp($procNetTcp, 1234) === null, 'unknown or non-loopback client port gives no uid');
file_put_contents($tmp . '/proc-net-tcp', $procNetTcp);
$check(Relay::peerUid(50000, $tmp . '/proc-net-tcp') === 1000 && Relay::peerUid(50000, $tmp . '/missing') === null, 'peer uid read from a file');

// mail-relay.php turns away clients it cannot identify, before reading anything.
$entry = __DIR__ . '/../lib/Froxlor/Ext/MailDesign/mail-relay.php';
$run = static function (array $env) use ($entry, $tmp): string {
	$p = proc_open([PHP_BINARY, $entry, $tmp . '/froxlor'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
	fwrite($pipes[0], "EHLO x\r\nQUIT\r\n");
	fclose($pipes[0]);
	$out = (string)stream_get_contents($pipes[1]);
	stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	proc_close($p);
	return $out;
};
$check(str_starts_with($run(['REMOTE_ADDR' => '127.0.0.1', 'REMOTE_PORT' => '1']), '554 '), 'relay entry point: unidentified peer refused');
$check(str_starts_with($run(['REMOTE_ADDR' => '192.0.2.1', 'REMOTE_PORT' => '50000']), '554 '), 'relay entry point: non-loopback peer refused');
$check(str_starts_with($run([]), '554 '), 'relay entry point: no peer information refused');

// --- SMTP conversations -----------------------------------------------------
/**
 * Runs $serve on one end of a socket pair while a forked client writes
 * $input (and then waits $holdSeconds); returns everything the server sent.
 */
$converse = static function (callable $serve, string $input, int $holdSeconds = 0) use ($tmp): string {
	[$server, $client] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
	$outFile = $tmp . '/client-' . bin2hex(random_bytes(4));
	$pid = pcntl_fork();
	if ($pid === 0) {
		fclose($server);
		$received = '';
		stream_set_blocking($client, false);
		$offset = 0;
		while ($offset < strlen($input)) {
			$w = @fwrite($client, substr($input, $offset, 4096));
			if ($w === false) {
				break;
			}
			$offset += $w;
			$received .= (string)@fread($client, 65536);
			if ($w === 0) {
				usleep(1000);
			}
		}
		if ($holdSeconds > 0) {
			sleep($holdSeconds);
		}
		stream_set_blocking($client, true);
		stream_set_timeout($client, 10);
		while (!feof($client)) {
			$chunk = @fread($client, 65536);
			if ($chunk === false || ($chunk === '' && !empty(stream_get_meta_data($client)['timed_out']))) {
				break;
			}
			$received .= $chunk;
		}
		file_put_contents($outFile, $received);
		exit(0);
	}
	fclose($client);
	$serve($server);
	fclose($server);
	pcntl_waitpid($pid, $status);
	$out = (string)@file_get_contents($outFile);
	@unlink($outFile);
	return $out;
};

if (!function_exists('pcntl_fork')) {
	echo "  SKIP SMTP conversations (pcntl not available)\n";
} else {
	$delivered = [];
	$session = static function (array $opts = []) use (&$delivered): callable {
		return static function ($conn) use (&$delivered, $opts): void {
			$s = new SmtpSession(
				static fn(string $u, string $p): bool => $u === 'user' && $p === 'pass',
				static function (string $from, array $rcpt, string $data) use (&$delivered, $opts): array {
					if (!empty($opts['throw'])) {
						throw new RuntimeException('boom');
					}
					$delivered[] = [$from, $rcpt, $data];
					return [empty($opts['fail']), ''];
				},
				'ignored.example',
				$opts['max'] ?? SmtpSession::MAX_MESSAGE_BYTES,
				0,
				$opts['idle'] ?? 5,
				$opts['preauth'] ?? SmtpSession::PRE_AUTH_DEADLINE
			);
			$s->handle($conn);
		};
	};
	$auth = 'AUTH PLAIN ' . base64_encode("\0user\0pass") . "\r\n";
	$codes = static fn(string $out): array => array_map(static fn($l) => substr($l, 0, 3), array_values(array_filter(explode("\r\n", $out))));

	$out = $converse($session(), "EHLO client\r\nQUIT\r\n");
	$check(str_starts_with($out, '220 froxlor-mail-design ESMTP') && str_contains($out, '250-AUTH PLAIN') && str_contains($out, '250 SIZE ') && str_contains($out, '221 '), 'greeting and EHLO capabilities');

	$out = $converse($session(), "EHLO c\r\nMAIL FROM:<a@example.org>\r\nQUIT\r\n");
	$check(str_contains($out, '530 '), 'MAIL without AUTH is refused');

	$delivered = [];
	$out = $converse($session(), "EHLO c\r\nAUTH PLAIN\r\n" . base64_encode("\0user\0pass") . "\r\nMAIL FROM:<a@example.org> BODY=8BITMIME\r\nRCPT TO:<b@example.org>\r\nDATA\r\n"
		. "Subject: x\r\n\r\n..leading dot\r\nnormal\r\n.\r\nQUIT\r\n");
	$check($codes($out) === ['220', '250', '250', '250', '250', '334', '235', '250', '250', '354', '250', '221'], 'two-step AUTH PLAIN and a full transaction');
	$check(($delivered[0][0] ?? '') === 'a@example.org' && ($delivered[0][1] ?? []) === ['b@example.org'], 'envelope passed to delivery');
	$check(($delivered[0][2] ?? '') === "Subject: x\r\n\r\n.leading dot\r\nnormal\r\n", 'dot-unstuffing');

	$out = $converse($session(), "EHLO c\r\nAUTH PLAIN " . base64_encode("\0user\0nope") . "\r\nAUTH PLAIN " . base64_encode("\0user\0nope") . "\r\nAUTH PLAIN " . base64_encode("\0user\0nope") . "\r\nNOOP\r\n");
	$check(substr_count($out, '535 ') === 3 && str_contains($out, '421 ') && !str_contains($out, '250 2.0.0 OK'), 'three failed logins close the connection');

	$out = $converse($session(), "EHLO c\r\nAUTH LOGIN\r\nAUTH PLAIN !!!\r\nQUIT\r\n");
	$check(str_contains($out, '504 ') && str_contains($out, '535 '), 'other mechanisms and garbage refused');

	$out = $converse($session(), "EHLO c\r\n{$auth}RCPT TO:<b@example.org>\r\nMAIL FROM:<a@example.org>\r\nRCPT TO:<-oQ/tmp@example.org>\r\nRCPT TO:<a b@example.org>\r\nRCPT TO:<b@example.org\r\nRCPT TO:<>\r\nDATA\r\nQUIT\r\n");
	$check($codes($out) === ['220', '250', '250', '250', '250', '235', '503', '250', '501', '501', '501', '501', '503', '221'], 'invalid or misplaced envelope commands refused');

	$delivered = [];
	$input = "EHLO c\r\n{$auth}MAIL FROM:<a@example.org>\r\n";
	for ($i = 0; $i <= SmtpSession::MAX_RECIPIENTS; $i++) {
		$input .= "RCPT TO:<r$i@example.org>\r\n";
	}
	$out = $converse($session(), $input . "QUIT\r\n");
	$check(substr_count($out, '452 ') === 1, 'recipient limit');

	$delivered = [];
	$out = $converse($session(['max' => 1000]), "EHLO c\r\n{$auth}MAIL FROM:<a@example.org>\r\nRCPT TO:<b@example.org>\r\nDATA\r\n" . str_repeat(str_repeat('x', 70) . "\r\n", 50) . ".\r\nNOOP\r\nQUIT\r\n");
	$check(str_contains($out, '552 ') && $delivered === [] && str_contains($out, "250 2.0.0 OK\r\n221"), 'too large message refused, session continues');

	$delivered = [];
	$long = str_repeat('a', 8192) . ".\r\n";
	$out = $converse($session(), "EHLO c\r\n{$auth}MAIL FROM:<a@example.org>\r\nRCPT TO:<b@example.org>\r\nDATA\r\nSubject: x\r\n\r\n$long.\r\nQUIT\r\n");
	$check(($delivered[0][2] ?? '') === "Subject: x\r\n\r\n" . $long, 'a dot after a long line split into chunks is not the end of data');

	$out = $converse($session(), "EHLO c\r\n" . str_repeat('N', 3000) . "\r\nNOOP\r\nFOO\r\nQUIT\r\n");
	$check($codes($out) === ['220', '250', '250', '250', '250', '500', '250', '502', '221'], 'over-long command line and unknown command');

	$out = $converse($session(['fail' => true]), "EHLO c\r\n{$auth}MAIL FROM:<a@example.org>\r\nRCPT TO:<b@example.org>\r\nDATA\r\nx\r\n.\r\nQUIT\r\n");
	$check(str_contains($out, '451 '), 'delivery failure answered with 451');

	$out = $converse($session(['throw' => true]), "EHLO c\r\n{$auth}MAIL FROM:<a@example.org>\r\nRCPT TO:<b@example.org>\r\nDATA\r\nx\r\n.\r\nQUIT\r\n");
	$check(str_contains($out, '451 ') && str_contains($out, '221 '), 'exception during delivery answered with 451');

	$delivered = [];
	$out = $converse($session(['idle' => 1]), "EHLO c\r\n{$auth}MAIL FROM:<a@example.org>\r\nRCPT TO:<b@example.org>\r\nDATA\r\nincomplete", 3);
	$check(str_contains($out, '421 4.4.2 Timeout') && $delivered === [], 'idle timeout ends the session, nothing is delivered');

	// Regression: a client trickling single bytes (no newline) must not hold
	// the session beyond the absolute pre-auth deadline.
	$t0 = microtime(true);
	[$server, $client] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
	$pid = pcntl_fork();
	if ($pid === 0) {
		fclose($server);
		for ($i = 0; $i < 12; $i++) {
			@fwrite($client, 'N');
			usleep(500000);
		}
		exit(0);
	}
	fclose($client);
	$session(['idle' => 5, 'preauth' => 2])($server);
	$elapsed = microtime(true) - $t0;
	fclose($server);
	posix_kill($pid, SIGKILL);
	pcntl_waitpid($pid, $status);
	$check($elapsed < 4, sprintf('trickling client is cut off at the pre-auth deadline (%.1f s)', $elapsed));

	// Separate input and output streams, as with socket activation (stdin/stdout).
	[$inA, $inB] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
	[$outA, $outB] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
	fwrite($inB, "EHLO c\r\nQUIT\r\n");
	(new SmtpSession(static fn(): bool => false, static fn(): array => [true, ''], '', 1000, 0, 2))->handle($inA, $outA);
	fclose($outA);
	$check(str_starts_with((string)stream_get_contents($outB), '220 froxlor-mail-design ESMTP'), 'separate input and output streams');

	// The whole relay (settings, styling, fake sendmail) behind a real conversation.
	$clearSpool();
	$out = $converse(static fn($conn) => $relay->serve($conn, null, 0), "EHLO c\r\nAUTH PLAIN " . base64_encode("\0froxlor-mail-design\0c0ffee") . "\r\nMAIL FROM:<admin@example.org>\r\nRCPT TO:<b@example.org>\r\nDATA\r\n$mail.\r\nQUIT\r\n");
	$check(str_starts_with($out, '220 froxlor-mail-design ESMTP') && str_contains($out, '250 2.0.0 OK queued') && str_contains($spool()[0][1] ?? '', 'X-Mail-Design: 1'), 'relay end to end over SMTP');
	$clearSpool();
}

// --- Optional: real PHPMailer from a Froxlor installation ----------------------
$froxlor = $argv[1] ?? '';
if ($froxlor !== '') {
	$phpmailer = rtrim($froxlor, '/') . '/vendor/phpmailer/phpmailer/src';
	if (!is_file($phpmailer . '/PHPMailer.php') || !function_exists('pcntl_fork')) {
		echo "  SKIP PHPMailer test (no $phpmailer or no pcntl)\n";
	} else {
		require $phpmailer . '/Exception.php';
		require $phpmailer . '/PHPMailer.php';
		require $phpmailer . '/SMTP.php';
		$probe = stream_socket_server('tcp://127.0.0.1:0');
		$port = (int)substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
		fclose($probe);
		$pid = pcntl_fork();
		if ($pid === 0) {
			restore_error_handler();
			fclose(STDOUT);
			// Test listener standing in for systemd's socket: one process per connection.
			$listener = stream_socket_server('tcp://127.0.0.1:' . $port);
			while ($conn = @stream_socket_accept($listener, 30)) {
				if (pcntl_fork() === 0) {
					(new Relay($tmp . '/froxlor', static fn(): array => $settings, $sendmail))->serve($conn, null, 0);
					exit(0);
				}
				fclose($conn);
			}
			exit(0);
		}
		usleep(300000);
		// Configured exactly like Froxlor\System\Mailer does with the settings smtp-switch.php writes.
		$send = static function (string $password) use ($port): string {
			$m = new PHPMailer\PHPMailer\PHPMailer(true);
			$m->CharSet = 'UTF-8';
			$m->isSMTP();
			$m->Host = '127.0.0.1';
			$m->Port = $port;
			$m->SMTPAuth = true;
			$m->Username = 'froxlor-mail-design';
			$m->Password = $password;
			$m->SMTPAutoTLS = false;
			$m->setFrom('admin@example.org', 'Panel');
			$m->Subject = "Account information for Zo\u{eb}";
			// Exactly as Froxlor (index.php, API commands): AltBody first, then
			// msgHTML(), which rebuilds AltBody from the HTML without line breaks.
			$body = "Hello Zo\u{eb},\n\nhere is your link for setting a new password. This link is valid for the next 24 hours.\n\n"
				. "http://panel.example.org:9080/index.php?action=resetpwd&resetcode=1e657d74bd458621\n\n"
				. "Username: zoe\nPassword: secret<&>\n\nThank you,\nyour administrator";
			$m->AltBody = $body;
			$m->msgHTML(str_replace("\n", '<br />', $body));
			$m->addAddress('zoe@example.org', "Zo\u{eb}");
			try {
				$m->send();
				return 'sent';
			} catch (PHPMailer\PHPMailer\Exception $e) {
				return $e->getMessage();
			}
		};
		$result = $send('c0ffee');
		$out = $spool();
		$check($result === 'sent' && count($out) === 1, 'PHPMailer sends through the relay');
		$check(($out[0][0] ?? []) === ['-i', '-f', 'postmaster@h.example', '--', 'zoe@example.org'], 'PHPMailer: envelope and recipient');
		$eml = $out[0][1] ?? '';
		$check(str_contains($eml, 'X-Mail-Design: 1') && str_contains($eml, 'Subject: =?UTF-8?') && str_contains(quoted_printable_decode($eml), "Hello Zo\u{eb}"), 'PHPMailer: mail styled, subject and non-ASCII text intact');
		// Text and HTML part of the styled mail
		$partOf = static function (string $mail, string $type): string {
			if (preg_match('~Content-Type: ' . $type . '.*?\n\n(.*?)\n--md-~s', str_replace("\r\n", "\n", $mail), $m)) {
				return str_replace("\r\n", "\n", quoted_printable_decode($m[1]));
			}
			return '';
		};
		$plain = $partOf($eml, 'text/plain');
		$html = $partOf($eml, 'text/html');
		$check(str_contains($plain, "24 hours.\n\nhttp://panel.example.org:9080/index.php?action=resetpwd&resetcode=1e657d74bd458621\n\nUsername: zoe\n"), 'PHPMailer: text part keeps the line structure and an intact reset link');
		$check(str_contains($html, 'href="http://panel.example.org:9080/index.php?action=resetpwd&amp;resetcode=1e657d74bd458621"')
			&& !str_contains($html, 'resetcode=1e657d74bd458621Username'), 'PHPMailer: reset link intact in the HTML part');
		$check(str_contains($html, 'class="md-box"') && str_contains($html, '>Username</td>') && str_contains($html, 'secret&lt;&amp;&gt;'), 'PHPMailer: login details as a box, escaped');
		$check(str_contains($html, 'Thank you,<br>your administrator'), 'PHPMailer: line breaks inside paragraphs kept');
		$clearSpool();
		$check($send('wrong') !== 'sent' && $spool() === [], 'PHPMailer with a wrong password is refused');
		posix_kill($pid, SIGTERM);
		pcntl_waitpid($pid, $status);
	}
}

// Clean up
$rm = static function (string $dir) use (&$rm): void {
	foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $f) {
		$p = $dir . '/' . $f;
		is_dir($p) && !is_link($p) ? $rm($p) : unlink($p);
	}
	rmdir($dir);
};
$rm($tmp);

echo $failures === 0 ? "All tests passed\n" : "$failures test(s) failed\n";
exit($failures > 0 ? 1 : 0);
