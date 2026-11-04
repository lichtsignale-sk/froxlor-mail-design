<?php

/**
 * Checks that the settings page, the migration defaults and the relay agree
 * on the setting names (no Froxlor needed):
 *
 *   php tests/settings.php
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit(1);
}

$root = dirname(__DIR__);
require $root . '/lib/Froxlor/Ext/MailDesign/Lang.php';
require $root . '/lib/Froxlor/Ext/MailDesign/LogoStore.php';

$failures = 0;
$check = static function (bool $ok, string $what) use (&$failures): void {
	echo ($ok ? '  ok   ' : '  FAIL ') . $what . "\n";
	$failures += $ok ? 0 : 1;
};

$definition = require $root . '/actions/admin/settings/192.ext.maildesign.php';
$fields = $definition['groups']['ext_maildesign']['fields'] ?? [];
$check(count($fields) === 7, 'settings group ext_maildesign with 7 fields');

$keysOk = true;
$varnames = [];
foreach ($fields as $key => $field) {
	$keysOk = $keysOk && ($field['settinggroup'] ?? '') === 'ext_maildesign' && $key === $field['settinggroup'] . '_' . $field['varname'];
	$varnames[$field['varname']] = $field['default'];
}
$check($keysOk, 'field keys are "<settinggroup>_<varname>" (needed by Froxlor\'s settings import)');

// The migration's defaults must cover every field, with the same default.
$migration = require $root . '/migrations/001_settings.php';
$check($migration instanceof Closure && (new ReflectionFunction($migration))->getNumberOfParameters() === 1, 'migration 001 is a function of the connection');
// Read the defaults from the migration source (running it needs MySQL).
$source = (string)file_get_contents($root . '/migrations/001_settings.php');
preg_match_all("~'([a-z_]+)' => '([^']*)'~", $source, $m, PREG_SET_ORDER);
$defaults = [];
foreach ($m as [, $k, $v]) {
	$defaults[$k] = $v;
}
$same = array_keys($defaults) == array_keys($varnames);
foreach ($varnames as $k => $d) {
	$normalized = is_bool($d) ? ($d ? '1' : '0') : (string)$d;
	$same = $same && ($defaults[$k] ?? null) === $normalized;
}
$check($same, 'migration defaults match the settings page');

// Every setting the relay reads exists.
$relay = (string)file_get_contents($root . '/lib/Froxlor/Ext/MailDesign/Relay.php');
preg_match_all("~\\\$g \. '([a-z_]+)'~", $relay, $m);
$check($m[1] && !array_diff($m[1], array_keys($varnames)), 'the relay reads only defined settings: ' . implode(', ', array_unique($m[1])));

$check(is_callable($fields['ext_maildesign_logo']['plausibility_check_method'] ?? null), 'logo upload hook is callable');
$check(!isset($fields['ext_maildesign_logo']['image_name']), 'no unused field options');

echo $failures === 0 ? "All tests passed\n" : "$failures test(s) failed\n";
exit($failures > 0 ? 1 : 0);
