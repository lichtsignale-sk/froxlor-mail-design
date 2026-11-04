<?php

/**
 * Tests switching Froxlor's SMTP settings to the relay and back, against an
 * in-memory SQLite copy of panel_settings (no MySQL needed):
 *
 *   php tests/smtp-switch.php
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

require __DIR__ . '/../lib/Froxlor/Ext/MailDesign/SmtpSwitch.php';

use Froxlor\Ext\MailDesign\SmtpSwitch;

if (!extension_loaded('pdo_sqlite')) {
	echo "  SKIP (pdo_sqlite not available)\n";
	exit(0);
}

$failures = 0;
$check = static function (bool $ok, string $what) use (&$failures): void {
	echo ($ok ? '  ok   ' : '  FAIL ') . $what . "\n";
	$failures += $ok ? 0 : 1;
};

/** Fresh panel_settings with Froxlor's defaults (install/froxlor.sql.php). */
$fresh = static function (array $override = []): PDO {
	$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
	$pdo->exec('CREATE TABLE `panel_settings` (`settingid` INTEGER PRIMARY KEY AUTOINCREMENT, `settinggroup` TEXT NOT NULL, `varname` TEXT NOT NULL, `value` TEXT NOT NULL)');
	$rows = $override + [
		'system.mail_use_smtp' => '0',
		'system.mail_smtp_host' => 'localhost',
		'system.mail_smtp_port' => '25',
		'system.mail_smtp_usetls' => '1',
		'system.mail_smtp_auth' => '1',
		'system.mail_smtp_user' => '',
		'system.mail_smtp_passwd' => '',
		'ext_maildesign.enabled' => '0',
	];
	$stmt = $pdo->prepare('INSERT INTO `panel_settings` (`settinggroup`, `varname`, `value`) VALUES (?, ?, ?)');
	foreach ($rows as $key => $value) {
		[$g, $v] = explode('.', $key, 2);
		$stmt->execute([$g, $v, $value]);
	}
	return $pdo;
};
$all = static function (PDO $pdo): array {
	$out = [];
	foreach ($pdo->query('SELECT `settinggroup`, `varname`, `value` FROM `panel_settings` ORDER BY `settinggroup`, `varname`') as $r) {
		$out[$r['settinggroup'] . '.' . $r['varname']] = $r['value'];
	}
	return $out;
};

// Default installation: switch on and off again.
$pdo = $fresh();
$before = $all($pdo);
$switch = new SmtpSwitch($pdo);
$check(!$switch->usesRelay() && !$switch->hasBackup(), 'initially not using the relay');
$switch->enable();
$s = $all($pdo);
$check($switch->usesRelay(), 'enable points Froxlor at the relay');
$check($s['system.mail_smtp_host'] === '127.0.0.1' && $s['system.mail_smtp_port'] === '2530' && $s['system.mail_smtp_usetls'] === '0', 'host, port, no TLS');
$check($s['system.mail_smtp_auth'] === '1' && $s['system.mail_smtp_user'] === 'froxlor-mail-design' && preg_match('/^[0-9a-f]{48}$/', $s['system.mail_smtp_passwd']), 'SMTP AUTH with a random hex password');
$check($s['ext_maildesign.enabled'] === '1', 'styling switched on');
$backup = json_decode($s['ext_maildesign.smtp_backup'] ?? '', true);
$check(is_array($backup) && $backup['mail_smtp_host'] === 'localhost' && $backup['mail_use_smtp'] === '0' && $backup['mail_smtp_usetls'] === '1', 'previous values backed up');

$password = $s['system.mail_smtp_passwd'];
$switch->enable();
$check($all($pdo)['system.mail_smtp_passwd'] === $password && json_decode($all($pdo)['ext_maildesign.smtp_backup'], true) === $backup, 'enabling again changes nothing (backup not overwritten)');

$switch->disable();
$after = $all($pdo);
unset($after['ext_maildesign.enabled'], $before['ext_maildesign.enabled']);
$check($after === $before, 'disable restores exactly the previous settings and drops the backup');
$check(!$switch->usesRelay(), 'no longer using the relay');
$check(str_contains($switch->disable(), 'nothing to do'), 'disabling twice is harmless');

// An SMTP server of the admin's own is never overwritten.
$pdo = $fresh(['system.mail_use_smtp' => '1', 'system.mail_smtp_host' => 'smtp.example.org', 'system.mail_smtp_port' => '587', 'system.mail_smtp_user' => 'me', 'system.mail_smtp_passwd' => 'pw']);
$before = $all($pdo);
try {
	(new SmtpSwitch($pdo))->enable();
	$check(false, 'own SMTP server: enable refused');
} catch (RuntimeException $e) {
	$check($all($pdo) === $before, 'own SMTP server: enable refused, nothing changed');
}

// Admin changed the settings by hand while the relay was on: left alone on disable.
$pdo = $fresh();
$switch = new SmtpSwitch($pdo);
$switch->enable();
$pdo->exec("UPDATE `panel_settings` SET `value` = 'smtp.example.org' WHERE `varname` = 'mail_smtp_host'");
$before = $all($pdo);
$switch->disable();
$after = $all($pdo);
$check($after['system.mail_smtp_host'] === 'smtp.example.org' && $after['system.mail_use_smtp'] === '1' && !isset($after['ext_maildesign.smtp_backup']), 'settings changed by hand are left alone, stale backup dropped');

// Relay switched on without a backup (by hand): disable falls back to sendmail.
$pdo = $fresh(['system.mail_use_smtp' => '1', 'system.mail_smtp_host' => '127.0.0.1', 'system.mail_smtp_port' => '2530', 'system.mail_smtp_auth' => '0']);
$switch = new SmtpSwitch($pdo);
$switch->enable();
$s = $all($pdo);
$check($s['system.mail_smtp_auth'] === '1' && $s['system.mail_smtp_user'] === 'froxlor-mail-design' && $s['system.mail_smtp_passwd'] !== '', 'relay already set by hand: credentials added on enable');
$switch->disable();
$s = $all($pdo);
$check($s['system.mail_use_smtp'] === '0' && $s['system.mail_smtp_user'] === '' && $s['system.mail_smtp_passwd'] === '', 'without backup: back to sendmail, own credentials removed');

// Missing rows (very old installation) are created instead of silently skipped.
$pdo = $fresh();
$pdo->exec("DELETE FROM `panel_settings` WHERE `varname` IN ('mail_smtp_user', 'mail_smtp_passwd')");
$switch = new SmtpSwitch($pdo);
$switch->enable();
$check(($all($pdo)['system.mail_smtp_user'] ?? '') === 'froxlor-mail-design', 'missing setting rows are created');
$switch->disable();
$s = $all($pdo);
$check(!$switch->usesRelay() && !isset($s['system.mail_smtp_user']) && !isset($s['system.mail_smtp_passwd']), 'rows created by enable are removed again on disable');

// Other port
$pdo = $fresh();
$switch = new SmtpSwitch($pdo, 2600);
$switch->enable();
$check($all($pdo)['system.mail_smtp_port'] === '2600' && $switch->usesRelay() && !(new SmtpSwitch($pdo))->usesRelay(), 'port is configurable');

echo $failures === 0 ? "All tests passed\n" : "$failures test(s) failed\n";
exit($failures > 0 ? 1 : 0);
