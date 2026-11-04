<?php

/**
 * Bilingual texts (English/German) for Mail Design.
 *
 * The English source text doubles as the key:
 *
 *     Lang::t('Mail design')                  PHP
 *     Lang::t('This message was sent automatically by %s.', $host)   with placeholders
 *
 * In English the text is returned unchanged; in German the translation comes
 * from lang/de.php (key = English text). If a translation is missing, the
 * English text is shown, never an empty field or a key name.
 *
 * Why not Froxlor's lng(): its language files (lng/*.lng.php) are part of
 * Froxlor; adding entries there would mean patching Froxlor. This layer ships
 * its own catalog.
 *
 * Which language applies: the one of the logged-in user (like Froxlor itself:
 * own language, else the account's default language, else the panel's default
 * language). German ("de", "de-*", "de_*" or Froxlor's language name for
 * German) yields German, any other language English. Without a session (the
 * relay, command line scripts) the panel's default language applies; the
 * relay sets it explicitly with Lang::set() for every mail, because it runs
 * without Froxlor's classes.
 *
 * This class must not depend on Froxlor being loaded: the relay and the tests
 * use it stand-alone.
 */

namespace Froxlor\Ext\MailDesign;

use Froxlor\CurrentUser;
use Froxlor\Settings;

final class Lang
{
	private static ?string $iso = null;

	/** @var array<string,string>|null */
	private static ?array $catalog = null;

	/** Translates an English text; with arguments, sprintf is applied. */
	public static function t(string $en, mixed ...$args): string
	{
		$text = $en;
		if (self::iso() === 'de') {
			$text = self::catalog()[$en] ?? $en;
		}
		return $args ? vsprintf($text, $args) : $text;
	}

	/** 'de' or 'en'. */
	public static function iso(): string
	{
		if (self::$iso !== null) {
			return self::$iso;
		}
		$language = null;
		try {
			if (class_exists(CurrentUser::class) && CurrentUser::hasSession()) {
				$language = CurrentUser::getField('language');
				if (empty($language)) {
					$language = CurrentUser::getField('def_language');
				}
			}
			if (empty($language) && class_exists(Settings::class)) {
				$language = Settings::Get('panel.standardlanguage');
			}
		} catch (\Throwable $e) {
			$language = null;
		}
		return self::$iso = self::toIso($language);
	}

	/** For the relay and tests: force a language (null = detect again). */
	public static function set(?string $language): void
	{
		self::$iso = $language === null ? null : self::toIso($language);
	}

	private static function toIso(mixed $language): string
	{
		$s = strtolower(trim((string)$language));
		return ($s === 'de' || str_starts_with($s, 'de-') || str_starts_with($s, 'de_') || $s === 'deutsch') ? 'de' : 'en';
	}

	/** @return array<string,string> */
	private static function catalog(): array
	{
		if (self::$catalog === null) {
			$catalog = is_file(__DIR__ . '/lang/de.php') ? require __DIR__ . '/lang/de.php' : [];
			self::$catalog = is_array($catalog) ? $catalog : [];
		}
		return self::$catalog;
	}
}
