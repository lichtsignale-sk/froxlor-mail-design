<?php

/**
 * Checks the translations of the extension (English source, German catalog):
 *
 *   php tests/lang.php            check everything
 *   php tests/lang.php <path>...  only check source files below these paths
 *
 * - Every text in Lang::t('...') needs a German entry in
 *   lib/Froxlor/Ext/MailDesign/lang/de.php.
 * - Placeholders (%s, %d, %1$s ...) must match between English and German.
 * - Catalog entries that are used nowhere, and German texts identical to the
 *   English ones, are reported as notes (not as errors).
 * The texts must be ONE literal in the source (no concatenation), otherwise
 * this script cannot find them.
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit(1);
}

$root = dirname(__DIR__);
$catalog = require $root . '/lib/Froxlor/Ext/MailDesign/lang/de.php';
if (!is_array($catalog)) {
	echo "  FAIL lang/de.php does not return an array\n";
	exit(1);
}

$targets = array_slice($argv, 1) ?: [$root];
$files = [];
foreach ($targets as $target) {
	$target = realpath($target) ?: $target;
	if (is_file($target)) {
		$files[] = $target;
		continue;
	}
	$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS));
	foreach ($it as $f) {
		$path = $f->getPathname();
		if (preg_match('~/(\.git|tests|lang)/~', $path) || !str_ends_with($path, '.php')) {
			continue;
		}
		$files[] = $path;
	}
}

$unquote = static function (string $lit): string {
	$q = $lit[0];
	$in = substr($lit, 1, -1);
	return $q === "'" ? strtr($in, ["\\'" => "'", '\\\\' => '\\']) : stripcslashes($in);
};
$specs = static function (string $s): array {
	preg_match_all('/%(?:\d+\$)?[-+ 0\']*\d*(?:\.\d+)?[bcdeEfFgGosuxX]/', str_replace('%%', '', $s), $m);
	$l = $m[0];
	sort($l);
	return $l;
};

$used = [];
$errors = 0;
$notes = 0;
foreach ($files as $file) {
	$text = (string)file_get_contents($file);
	if (!preg_match_all('~Lang::t\(\s*(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")~s', $text, $hits, PREG_OFFSET_CAPTURE)) {
		continue;
	}
	foreach ($hits[1] as [$lit, $off]) {
		$en = $unquote($lit);
		$used[$en] = true;
		$line = substr_count(substr($text, 0, $off), "\n") + 1;
		$where = str_replace($root . '/', '', $file) . ':' . $line;
		if (!isset($catalog[$en])) {
			echo "  FAIL $where  no German translation: " . mb_substr($en, 0, 70) . "\n";
			$errors++;
			continue;
		}
		if ($specs($en) !== $specs($catalog[$en])) {
			echo "  FAIL $where  placeholders do not match: " . mb_substr($en, 0, 70) . "\n";
			$errors++;
		}
	}
}
foreach ($catalog as $en => $de) {
	if ($en === $de && mb_strlen($en) > 12) {
		echo "  Note: German equals English: " . mb_substr($en, 0, 70) . "\n";
		$notes++;
	}
}
if (count($targets) === 1 && $targets[0] === $root) {
	foreach (array_diff_key($catalog, $used) as $en => $de) {
		echo "  Note: catalog entry not used: " . mb_substr($en, 0, 70) . "\n";
		$notes++;
	}
}
echo count($used) . " texts used, " . count($catalog) . " in the catalog, $errors errors, $notes notes\n";
exit($errors > 0 ? 1 : 0);
