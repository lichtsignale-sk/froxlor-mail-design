<?php

/**
 * Points Froxlor's mail delivery at the local relay, or back.
 *
 *   php migrations/smtp-switch.php /var/www/froxlor on [port]
 *   php migrations/smtp-switch.php /var/www/froxlor off [port]
 *   php migrations/smtp-switch.php /var/www/froxlor status [port]
 *
 * Called by mail-design.sh. Changes rows in panel_settings only; see
 * lib/Froxlor/Ext/MailDesign/SmtpSwitch.php for the rules (refuses when
 * Froxlor already uses an SMTP server of its own, backs up and restores the
 * previous values).
 *
 * Exit codes: 0 ok (status: uses the relay), 1 error, 2 refused (own SMTP
 * server), 3 status: does not use the relay.
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit(1);
}

require __DIR__ . '/connection.php';
require_once __DIR__ . '/../lib/Froxlor/Ext/MailDesign/SmtpSwitch.php';

use Froxlor\Ext\MailDesign\SmtpSwitch;

$froxlorDir = $argv[1] ?? '';
$action = $argv[2] ?? 'status';
$port = (int)($argv[3] ?? SmtpSwitch::DEFAULT_PORT);
if (!in_array($action, ['on', 'off', 'status'], true) || $port < 1 || $port > 65535) {
	fwrite(STDERR, "Usage: php migrations/smtp-switch.php <froxlor-directory> on|off|status [port]\n");
	exit(1);
}

[$pdo] = maildesign_connect($froxlorDir);
$switch = new SmtpSwitch($pdo, $port);

try {
	if ($action === 'status') {
		$uses = $switch->usesRelay();
		echo $uses ? "  Froxlor sends via the relay (127.0.0.1:$port)\n" : "  Froxlor does not send via the relay\n";
		if ($switch->hasBackup()) {
			echo "  Previous SMTP settings are backed up and will be restored by \"off\"\n";
		}
		exit($uses ? 0 : 3);
	}
	echo '  ' . ($action === 'on' ? $switch->enable() : $switch->disable()) . "\n";
} catch (PDOException $e) {
	fwrite(STDERR, 'ERROR: ' . maildesign_clean($e->getMessage()) . "\n");
	exit(1);
} catch (RuntimeException $e) {
	fwrite(STDERR, maildesign_clean($e->getMessage()) . "\n");
	exit(2);
} catch (Throwable $e) {
	fwrite(STDERR, 'ERROR: ' . maildesign_clean($e->getMessage()) . "\n");
	exit(1);
}
