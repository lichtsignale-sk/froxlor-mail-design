<?php

/**
 * Removes the traces of Mail Design from the database.
 *
 *   php migrations/remove.php /var/www/froxlor            switch delivery back, forget the logo
 *   php migrations/remove.php /var/www/froxlor --purge    additionally delete all settings
 *
 * Always, also without --purge:
 * - If Froxlor's SMTP settings still point at the relay, the previous values
 *   are restored (normally mail-design.sh off has done that already; this is
 *   the safety net). Otherwise Froxlor would send to a port nobody listens on.
 * - The logo setting is emptied if it names the uploaded logo, because
 *   uninstall.sh deletes that file.
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit(1);
}

require __DIR__ . '/connection.php';
require_once __DIR__ . '/../lib/Froxlor/Ext/MailDesign/SmtpSwitch.php';

use Froxlor\Ext\MailDesign\SmtpSwitch;

$froxlorDir = $argv[1] ?? '';
$purge = in_array('--purge', array_slice($argv, 2), true);

[$pdo, $db, $host, $user] = maildesign_connect($froxlorDir);
echo '  ' . maildesign_clean("Database '$db' on '$host' as '$user'") . "\n";

$switch = new SmtpSwitch($pdo);
if ($switch->usesRelay() || $switch->hasBackup()) {
	echo '  ' . $switch->disable() . "\n";
}

$stmt = $pdo->prepare("UPDATE `panel_settings` SET `value` = '' WHERE `settinggroup` = 'ext_maildesign' AND `varname` = 'logo' AND `value` LIKE ?");
$stmt->execute(['img/mail-logo.%']);

if (!$purge) {
	echo "  Settings kept (use --purge to delete them as well)\n";
	exit(0);
}

$pdo->exec("DELETE FROM `panel_settings` WHERE `settinggroup` = 'ext_maildesign'");
echo "  Settings deleted\n";
