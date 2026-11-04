<?php

/**
 * Settings of Mail Design (Settings > Mail design).
 *
 * The styling itself is done by the local relay
 * (lib/Froxlor/Ext/MailDesign/mail-relay.php); it reads these values fresh
 * from the database for every mail, so changes apply immediately. The relay
 * is switched on once with mail-design.sh, which points Froxlor's SMTP
 * settings at it.
 *
 * The field keys are "<settinggroup>_<varname>": Froxlor's settings import
 * maps "group.varname" to form fields exactly that way.
 */

use Froxlor\Ext\MailDesign\Lang;
use Froxlor\Ext\MailDesign\LogoStore;

// Loaded by Froxlor; never meant to be requested directly.
if (PHP_SAPI !== 'cli' && realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
	http_response_code(404);
	exit(1);
}

return [
	'groups' => [
		'ext_maildesign' => [
			'title' => Lang::t('Mail design'),
			'icon' => 'fa-solid fa-envelope-open-text',
			'fields' => [
				'ext_maildesign_enabled' => [
					'label' => [
						'title' => Lang::t('Style emails sent by Froxlor'),
						'description' => Lang::t('Turns Froxlor\'s plain-text emails (account information, reports, password reset) into a styled HTML email with a header. Only takes effect while the relay is running: run "./mail-design.sh /var/www/froxlor on" once on the server.'),
					],
					'settinggroup' => 'ext_maildesign',
					'varname' => 'enabled',
					'type' => 'checkbox',
					'default' => false,
					'save_method' => 'storeSettingField',
				],
				'ext_maildesign_company_name' => [
					'label' => [
						'title' => Lang::t('Company name in the header'),
						'description' => Lang::t('Shown large in the header. With a logo, it serves as the alternative text.'),
					],
					'settinggroup' => 'ext_maildesign',
					'varname' => 'company_name',
					'type' => 'text',
					'string_emptyallowed' => true,
					'default' => '',
					'save_method' => 'storeSettingField',
				],
				'ext_maildesign_logo' => [
					'label' => [
						'title' => Lang::t('Logo'),
						'description' => Lang::t('PNG, JPG or GIF up to 400 KB; email clients do not display SVG. There is only one logo: a new upload replaces the old one. The logo is embedded in the email, so it also shows when the email client blocks images from the web. Height in the email is 44 px, so upload it at twice that size (retina). Without a logo, only the company name appears in the header.'),
					],
					'settinggroup' => 'ext_maildesign',
					'varname' => 'logo',
					'type' => 'image',
					'accept' => 'image/jpeg, image/jpg, image/png, image/gif',
					'default' => '',
					'save_method' => 'storeSettingImage',
					'plausibility_check_method' => [LogoStore::class, 'check'],
				],
				'ext_maildesign_show_server' => [
					'label' => [
						'title' => Lang::t('Server name in the header'),
						'description' => Lang::t('Shows the host server (from Settings › System › Hostname) below the name, so customers can see which server the email comes from.'),
					],
					'settinggroup' => 'ext_maildesign',
					'varname' => 'show_server',
					'type' => 'checkbox',
					'default' => true,
					'save_method' => 'storeSettingField',
				],
				'ext_maildesign_header_color' => [
					'label' => [
						'title' => Lang::t('Header color'),
						'description' => Lang::t('Hex value like #1f2937. The text color is chosen light or dark to match. A logo with a transparent background should suit the color.'),
					],
					'settinggroup' => 'ext_maildesign',
					'varname' => 'header_color',
					'type' => 'text',
					'string_regexp' => '/^#[0-9a-fA-F]{6}$/',
					'default' => '#1f2937',
					'save_method' => 'storeSettingField',
				],
				'ext_maildesign_accent_color' => [
					'label' => [
						'title' => Lang::t('Accent color'),
						'description' => Lang::t('Hex value like #2563eb. Line below the header, border of the login details, and links.'),
					],
					'settinggroup' => 'ext_maildesign',
					'varname' => 'accent_color',
					'type' => 'text',
					'string_regexp' => '/^#[0-9a-fA-F]{6}$/',
					'default' => '#2563eb',
					'save_method' => 'storeSettingField',
				],
				'ext_maildesign_footer' => [
					'label' => [
						'title' => Lang::t('Footer'),
						'description' => Lang::t('Optional, e.g. contact details or legal notice. One line per line; addresses starting with https:// become links.'),
					],
					'settinggroup' => 'ext_maildesign',
					'varname' => 'footer',
					'type' => 'textarea',
					'string_emptyallowed' => true,
					'default' => '',
					'save_method' => 'storeSettingField',
				],
			],
		],
	],
];
