<?php

/**
 * Local mail relay of Mail Design: one SMTP conversation per process.
 *
 *   php mail-relay.php /var/www/froxlor [allowed-uid,...]
 *
 * Started by systemd (froxlor-mail-design@.service) for every connection
 * accepted on froxlor-mail-design.socket (127.0.0.1:2530), with the
 * connection on stdin/stdout and errors going to the journal. Froxlor sends
 * its mails there via its own SMTP settings; the relay styles them and hands
 * them to the local sendmail.
 *
 * Only clients running as root (Froxlor's cron), as the relay's own user
 * (the panel) or as one of the listed uids are served: their uid is looked
 * up in /proc/net/tcp by the client port systemd passes in REMOTE_PORT.
 * Everybody else is turned away before reading a byte.
 *
 * The classes are loaded directly instead of through Froxlor's autoloader:
 * the relay does not need Froxlor's bootstrap and must not depend on it.
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit(1);
}

require __DIR__ . '/Lang.php';
require __DIR__ . '/Styler.php';
require __DIR__ . '/LogoStore.php';
require __DIR__ . '/DbConnection.php';
require __DIR__ . '/SmtpSession.php';
require __DIR__ . '/Relay.php';

use Froxlor\Ext\MailDesign\Relay;
use Froxlor\Ext\MailDesign\SmtpSession;

$froxlorDir = rtrim((string)($argv[1] ?? ''), '/');
if ($froxlorDir === '' || !is_dir($froxlorDir . '/lib')) {
	SmtpSession::log('Usage: php mail-relay.php <froxlor-directory> [allowed-uid,...] (started by systemd)');
	exit(1);
}

$allowed = [0, function_exists('posix_geteuid') ? posix_geteuid() : -1];
foreach (explode(',', (string)($argv[2] ?? '')) as $uid) {
	if (ctype_digit(trim($uid))) {
		$allowed[] = (int)trim($uid);
	}
}

$remoteAddr = (string)getenv('REMOTE_ADDR');
$remotePort = (int)getenv('REMOTE_PORT');
$peer = $remotePort > 0 ? Relay::peerUid($remotePort) : null;
if ($remoteAddr !== '127.0.0.1' || $peer === null || !in_array($peer, $allowed, true)) {
	SmtpSession::log('Refused connection from ' . $remoteAddr . ':' . $remotePort . ' (uid ' . ($peer ?? 'unknown') . ')');
	fwrite(STDOUT, "554 5.7.1 Not allowed\r\n");
	exit(0);
}

try {
	(new Relay($froxlorDir))->serve(STDIN, STDOUT);
} catch (Throwable $e) {
	SmtpSession::log($e->getMessage());
	exit(1);
}
