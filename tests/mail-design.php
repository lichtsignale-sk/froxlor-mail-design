<?php

/**
 * Tests the mail styling and the logo storage without Froxlor or a database:
 *
 *   php tests/mail-design.php
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit(1);
}

error_reporting(E_ALL);
set_error_handler(static function (int $no, string $msg, string $file, int $line): bool {
	if (!(error_reporting() & $no)) {
		return false; // suppressed with @
	}
	throw new ErrorException($msg, 0, $no, $file, $line);
});

$lib = __DIR__ . '/../lib/Froxlor/Ext/MailDesign';
require $lib . '/Lang.php';
require $lib . '/Styler.php';
require $lib . '/LogoStore.php';

use Froxlor\Ext\MailDesign\Lang;
use Froxlor\Ext\MailDesign\LogoStore;
use Froxlor\Ext\MailDesign\Styler;

$failures = 0;
$check = static function (bool $ok, string $what) use (&$failures): void {
	echo ($ok ? '  ok   ' : '  FAIL ') . $what . "\n";
	$failures += $ok ? 0 : 1;
};

/** HTML part of a styled mail, decoded. */
$htmlOf = static function (string $mail): string {
	if (preg_match('~Content-Type: text/html.*?\r\n\r\n(.*?)\r\n--md-~s', $mail, $m)) {
		return quoted_printable_decode($m[1]);
	}
	return '';
};
$plainOf = static function (string $mail): string {
	if (preg_match('~Content-Type: text/plain.*?\r\n\r\n(.*?)\r\n--md-~s', $mail, $m)) {
		return str_replace("\r\n", "\n", quoted_printable_decode($m[1]));
	}
	return '';
};
/** Header block (up to the first blank line). */
$headOf = static function (string $mail): string {
	return explode("\r\n\r\n", $mail, 2)[0];
};

Lang::set('en');

// A mail as PHPMailer builds it for Froxlor: AltBody (text) + msgHTML (text with <br />).
$text = "Hello Annie,\n\nUsername: anne\nPassword: secret<&>\nAddress: https://h.example:8443/\n\nBye\n";
$b = 'bnd';
$mail = "From: A <a@example.org>\r\nTo: b@example.org\r\nSubject: =?UTF-8?B?QWNjb3VudCBpbmZvcm1hdGlvbg==?=\r\nMIME-Version: 1.0\r\n"
	. "Content-Type: multipart/alternative;\r\n\tboundary=\"$b\"\r\n\r\n--$b\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n$text\r\n"
	. "--$b\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n" . str_replace("\n", '<br />', $text) . "\r\n--$b--\r\n";
$style = ['company' => 'Company <X>', 'server' => 'h.example', 'header_color' => '#ffffff', 'accent_color' => 'broken', 'footer' => 'Contact', 'logo_data' => null];

$new = Styler::style($mail, $style);
$check($new !== $mail && str_contains($new, 'X-Mail-Design: 1'), 'mail is styled');
$check(str_contains($new, 'From: A <a@example.org>') && str_contains($new, 'To: b@example.org'), 'sender and recipient are kept');
$check(substr_count($new, 'Content-Type:') >= 3 && str_contains($new, 'text/plain') && str_contains($new, 'text/html'), 'text and HTML part');
$check(!str_contains($new, 'cid:'), 'no cid reference without a logo');
$check(Styler::style($new, $style) === $new, 'styling twice changes nothing');
$check(Styler::style('garbage without separator', $style) === 'garbage without separator', 'garbage stays unchanged');
$check(substr_count($headOf($new), 'Auto-Submitted:') === 1, 'Auto-Submitted header added once');

$html = $htmlOf($new);
$check(str_contains($html, 'Company &lt;X&gt;'), 'company name is escaped');
$check(str_contains($html, 'secret&lt;&amp;&gt;'), 'password with special characters is escaped');
$check(str_contains($html, 'href="https://h.example:8443/"'), 'address becomes a link');
$check(str_contains($html, '#2563eb') && !str_contains($html, 'broken'), 'invalid color falls back to the default');
$check(str_contains($html, '<title>Account information</title>'), 'subject decoded from the header');
$check(str_contains($html, 'prefers-color-scheme: dark'), 'dark mode provided');
$check(str_contains($html, 'class="md-box"') && str_contains($html, '>Username</td>'), '"Name: value" lines become a box');
$check(str_contains($html, 'Host server h.example') && str_contains($html, 'sent automatically by h.example') && str_contains($html, 'lang="en"'), 'frame texts in English');
$check(str_contains($plainOf($new), "Company <X> · h.example\n") && str_contains($plainOf($new), "\n-- \nContact"), 'text part with name line and footer');

Lang::set('de');
$de = $htmlOf(Styler::style($mail, $style));
$check(str_contains($de, 'Hostserver h.example') && str_contains($de, 'automatisch von h.example versendet') && str_contains($de, 'lang="de"'), 'frame texts in German');
Lang::set('en');

// Nothing from settings or body may become a header line.
$evil = Styler::style($mail, ['company' => "Evil\r\nBcc: victim@example.org", 'footer' => "x\r\nX-Injected: 1"] + $style);
$check(!preg_match('/^(Bcc|X-Injected):/mi', $headOf($evil)), 'no header injection via company name or footer');
$evilSubject = Styler::style(str_replace('Subject: =?UTF-8?B?QWNjb3VudCBpbmZvcm1hdGlvbg==?=', 'Subject: <script>alert(1)</script>', $mail), $style);
$check(!str_contains($htmlOf($evilSubject), '<script>') && str_contains($htmlOf($evilSubject), '&lt;script&gt;'), 'subject is escaped in the HTML');
$jsLink = $htmlOf(Styler::style(str_replace('https://h.example:8443/', 'javascript:alert(1)', $mail), $style));
$check(!str_contains($jsLink, 'href="javascript'), 'only http(s) addresses become links');
$quoteLink = $htmlOf(Styler::style(str_replace('https://h.example:8443/', "https://h.example/a'onmouseover=x", $mail), $style));
$check(!str_contains($quoteLink, "a'onmouseover") && str_contains($quoteLink, 'a&#039;onmouseover'), 'quotes in links are escaped');

// The pattern PHPMailer produces for Froxlor: msgHTML() after AltBody rebuilds
// the text part without any line breaks; the HTML part has them as <br />.
$flat = "From: a@example.org\r\nSubject: Password reset\r\nMIME-Version: 1.0\r\nContent-Type: multipart/alternative;\r\n boundary=\"b1\"\r\n\r\n"
	. "--b1\r\nContent-Type: text/plain; charset=us-ascii\r\n\r\nHello Erika,here is your link. Valid for 24 hours.http://panel.example.org/index.php?action=resetpwd&resetcode=abc123Thank you,your administrator\r\n\r\n"
	. "--b1\r\nContent-Type: text/html; charset=us-ascii\r\n\r\nHello Erika,<br /><br />here is your link. Valid for 24 hours.<br /><br />http://panel.example.org/index.php?action=resetpwd&resetcode=abc123<br /><br />Thank you,<br />your administrator\r\n\r\n--b1--\r\n";
$fixed = Styler::style($flat, $style);
$check(str_contains($plainOf($fixed), "24 hours.\n\nhttp://panel.example.org/index.php?action=resetpwd&resetcode=abc123\n\nThank you,\nyour administrator"), 'text without line breaks: lines taken from the HTML part');
$check(str_contains($htmlOf($fixed), 'href="http://panel.example.org/index.php?action=resetpwd&amp;resetcode=abc123"'), 'reset link stays intact');
// nl2br() output ("<br />\n") must not double the line breaks
$nl2br = "From: a@example.org\r\nSubject: Notice\r\nContent-Type: multipart/alternative; boundary=\"b2\"\r\n\r\n"
	. "--b2\r\nContent-Type: text/plain\r\n\r\nLine oneLine two\r\n--b2\r\nContent-Type: text/html\r\n\r\nLine one<br />\nLine two\r\n--b2--\r\n";
$check(str_contains($plainOf(Styler::style($nl2br, $style)), "Line one\nLine two\n"), 'nl2br HTML gives single line breaks');
// A proper text part wins over the HTML part
$both = str_replace('Line oneLine two', "Line one\nLine two", $nl2br);
$check(str_contains($plainOf(Styler::style($both, $style)), "Line one\nLine two\n"), 'text part with line breaks is used as it is');

// Robustness: broken input falls back to the original mail or a clean result.
$latin1 = "From: a@example.org\r\nSubject: Test\r\nContent-Type: text/plain; charset=ISO-8859-1\r\n\r\nCaf\xE9 cr\xE8me\r\n";
$check(str_contains($htmlOf(Styler::style($latin1, $style)), "Caf\u{e9} cr\u{e8}me"), 'ISO-8859-1 body is converted');
$badUtf8 = "From: a@example.org\r\nSubject: Test\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\nBroken \xC3\x28 bytes\r\nKey: \xFF value\r\n";
$styledBad = Styler::style($badUtf8, $style);
$check(str_contains($styledBad, 'X-Mail-Design: 1') && mb_check_encoding($htmlOf($styledBad), 'UTF-8'), 'invalid UTF-8 does not break styling');
$unknownCharset = "From: a@example.org\r\nSubject: Test\r\nContent-Type: text/plain; charset=x-no-such-charset\r\n\r\nHello\r\n";
$check(Styler::style($unknownCharset, $style) === $unknownCharset, 'unknown charset: original mail unchanged');
$noText = "From: a@example.org\r\nSubject: Test\r\nContent-Type: application/pdf\r\n\r\nJVBERi0=\r\n";
$check(Styler::style($noText, $style) === $noText, 'mail without text part stays unchanged');
$qp = "From: a@example.org\r\nSubject: Test\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\nCaf=C3=A9\r\n";
$check(str_contains($htmlOf(Styler::style($qp, $style)), "Caf\u{e9}"), 'quoted-printable body is decoded');

// Logo embedding
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
$withLogo = Styler::style($mail, ['logo_data' => ['image/png', $png]] + $style);
$check(str_contains($withLogo, 'multipart/related') && str_contains($withLogo, 'Content-ID: <logo-') && str_contains((string)preg_replace('~\s+~', '', $withLogo), base64_encode($png)), 'logo embedded');
$check((bool)preg_match('~src="cid:logo-[0-9a-f]+@mail-design"~', $htmlOf($withLogo)), 'HTML refers to the embedded logo');
$badType = Styler::style($mail, ['logo_data' => ["image/svg+xml\r\nX-Evil: 1", '<svg/>']] + $style);
$check(!str_contains($badType, 'svg') && !str_contains($badType, 'X-Evil'), 'logo with an unknown MIME type is ignored');

// Logo storage: exactly one file, a new upload replaces it (also with another extension)
$dir = sys_get_temp_dir() . '/md-logo-' . bin2hex(random_bytes(4));
mkdir($dir);
$sourcePng = $dir . '/a.png';
file_put_contents($sourcePng, $png);
$sourceGif = $dir . '/b.gif';
file_put_contents($sourceGif, base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'));
$img = $dir . '/img';
$check(LogoStore::store($sourcePng, $img) === 'mail-logo.png' && is_file($img . '/mail-logo.png'), 'logo upload stores mail-logo.png');
$check(LogoStore::store($sourceGif, $img) === 'mail-logo.gif' && !is_file($img . '/mail-logo.png') && count(glob($img . '/*')) === 1, 'a new upload replaces the old logo, one file remains');
$check((fileperms($img . '/mail-logo.gif') & 0777) === 0644, 'logo file mode 0644');
$rejected = static function (string $content, string $name) use ($dir, $img): bool {
	file_put_contents($dir . '/' . $name, $content);
	try {
		LogoStore::store($dir . '/' . $name, $img);
		return false;
	} catch (RuntimeException $e) {
		return is_file($img . '/mail-logo.gif') && count(glob($img . '/*')) === 1;
	}
};
$check($rejected('no image', 'c.png'), 'a non-image is rejected, the old logo stays');
$check($rejected('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'd.svg'), 'SVG is rejected');
$check($rejected("<?php echo 1; ?>", 'e.gif'), 'a PHP file named .gif is rejected');
$check($rejected($png . str_repeat("\0", LogoStore::MAX_BYTES), 'f.png'), 'a logo over 400 KB is rejected');
$check($rejected('', 'g.png'), 'an empty file is rejected');
$hugePng = "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . pack('NN', LogoStore::MAX_DIMENSION + 1, 10) . "\x08\x06\x00\x00\x00" . pack('N', 0);
$check($rejected($hugePng, 'i.png'), 'a logo wider than 2000 pixels is rejected');
Lang::set('de');
try {
	file_put_contents($dir . '/h.png', 'x');
	LogoStore::store($dir . '/h.png', $img);
	$check(false, 'German error message');
} catch (RuntimeException $e) {
	$german = (require __DIR__ . '/../lib/Froxlor/Ext/MailDesign/lang/de.php')['The logo must be a PNG, JPG or GIF image.'];
	$check($e->getMessage() === $german && $german !== 'The logo must be a PNG, JPG or GIF image.', 'error message follows the language');
}
Lang::set('en');
foreach ([$img, $dir] as $d) {
	foreach (array_diff(scandir($d) ?: [], ['.', '..']) as $f) {
		if (is_file($d . '/' . $f)) {
			unlink($d . '/' . $f);
		}
	}
}
rmdir($img);
rmdir($dir);

echo $failures === 0 ? "All tests passed\n" : "$failures test(s) failed\n";
exit($failures > 0 ? 1 : 0);
