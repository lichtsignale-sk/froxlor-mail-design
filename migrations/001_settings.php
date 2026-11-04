<?php

/**
 * Mail Design: default settings. Idempotent: existing values are kept.
 *
 * Nothing is switched on by this migration: the styling only takes effect
 * once the relay runs (mail-design.sh ... on).
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit(1);
}

return static function (PDO $pdo): void {
	$defaults = [
		'enabled' => '0',
		'company_name' => '',
		'logo' => '',
		'show_server' => '1',
		'header_color' => '#1f2937',
		'accent_color' => '#2563eb',
		'footer' => '',
	];
	$stmt = $pdo->prepare("
		INSERT INTO `panel_settings` (`settinggroup`, `varname`, `value`)
		SELECT 'ext_maildesign', ?, ? FROM DUAL
		WHERE NOT EXISTS (SELECT 1 FROM `panel_settings` WHERE `settinggroup` = 'ext_maildesign' AND `varname` = ?)
	");
	foreach ($defaults as $varname => $value) {
		$stmt->execute([$varname, $value, $varname]);
	}
};
