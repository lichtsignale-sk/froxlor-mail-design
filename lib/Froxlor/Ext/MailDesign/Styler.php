<?php

/**
 * Turns a Froxlor mail (plain text plus a "<br />" HTML copy) into a styled
 * mail: header with logo or company name and server name, readable body text,
 * footer.
 *
 * Pure functions on a mail string, without Froxlor classes: the relay runs as
 * a separate process and the tests call this directly. If anything goes wrong,
 * style() returns the mail unchanged: an unstyled mail is better than none.
 *
 * Every value that ends up in the HTML part is escaped. Nothing taken from the
 * settings or from the mail body is ever written into a header line: the
 * generated headers consist of fixed text, random boundaries and a MIME type
 * from a fixed list.
 */

namespace Froxlor\Ext\MailDesign;

final class Styler
{
	/** Marks a mail that has already been styled, so it is never styled twice. */
	public const MARKER_HEADER = 'X-Mail-Design';

	public const DEFAULT_HEADER_COLOR = '#1f2937';
	public const DEFAULT_ACCENT_COLOR = '#2563eb';

	/** Logo MIME types that may be embedded. */
	private const LOGO_TYPES = ['image/png', 'image/jpeg', 'image/gif'];

	/**
	 * @param array{company?:string, server?:string, header_color?:string, accent_color?:string, footer?:string, logo_data?:?array{0:string,1:string}} $style
	 *        logo_data = [MIME type, bytes], already loaded; null = without image.
	 *        The language of the frame texts comes from Lang (set by the caller).
	 */
	public static function style(string $mail, array $style): string
	{
		// Turn warnings and notices (broken charsets, invalid UTF-8 ...) into
		// exceptions, so that every problem ends in the fallback below instead
		// of a half-built mail.
		set_error_handler(static function (int $no, string $msg, string $file = '', int $line = 0): bool {
			if (!(error_reporting() & $no)) {
				return false;
			}
			throw new \ErrorException($msg, 0, $no, $file, $line);
		});
		try {
			[$head, $body] = self::split($mail);
			$headers = self::parseHeaders($head);
			if (self::headerValue($headers, strtolower(self::MARKER_HEADER)) !== null) {
				return $mail; // already styled
			}
			$text = self::plainText($headers, $body);
			if ($text === null || trim($text) === '') {
				return $mail;
			}
			$subject = self::decodeHeader(self::headerValue($headers, 'subject') ?? '');
			return self::assemble($headers, $subject, trim($text), self::normalizeStyle($style));
		} catch (\Throwable $e) {
			if (defined('STDERR')) {
				fwrite(STDERR, 'Mail design: ' . $e->getMessage() . "\n");
			}
			return $mail;
		} finally {
			restore_error_handler();
		}
	}

	/** @return array{company:string, server:string, header_color:string, accent_color:string, footer:string, logo_data:?array{0:string,1:string}} */
	private static function normalizeStyle(array $style): array
	{
		$logo = $style['logo_data'] ?? null;
		if (!is_array($logo) || !isset($logo[0], $logo[1]) || !in_array($logo[0], self::LOGO_TYPES, true) || $logo[1] === '') {
			$logo = null;
		}
		return [
			'company' => self::utf8(trim((string)($style['company'] ?? ''))),
			'server' => self::utf8(trim((string)($style['server'] ?? ''))),
			'header_color' => self::color((string)($style['header_color'] ?? ''), self::DEFAULT_HEADER_COLOR),
			'accent_color' => self::color((string)($style['accent_color'] ?? ''), self::DEFAULT_ACCENT_COLOR),
			'footer' => self::utf8(str_replace(["\r\n", "\r"], "\n", (string)($style['footer'] ?? ''))),
			'logo_data' => $logo,
		];
	}

	/** @return array{0:string,1:string} head, body */
	private static function split(string $mail): array
	{
		$mail = str_replace("\r\n", "\n", $mail);
		$pos = strpos($mail, "\n\n");
		if ($pos === false) {
			throw new \RuntimeException('no blank line between header and body');
		}
		return [substr($mail, 0, $pos), substr($mail, $pos + 2)];
	}

	/** @return list<array{0:string,1:string}> [name, value] with folded lines kept as CRLF + whitespace */
	private static function parseHeaders(string $head): array
	{
		$out = [];
		foreach (explode("\n", $head) as $line) {
			if ($line !== '' && ($line[0] === ' ' || $line[0] === "\t") && $out) {
				$out[count($out) - 1][1] .= "\r\n" . $line;
				continue;
			}
			$p = strpos($line, ':');
			if ($p === false) {
				continue;
			}
			$out[] = [substr($line, 0, $p), ltrim(substr($line, $p + 1))];
		}
		return $out;
	}

	private static function headerValue(array $headers, string $name): ?string
	{
		foreach ($headers as [$n, $v]) {
			if (strtolower($n) === $name) {
				return $v;
			}
		}
		return null;
	}

	private static function decodeHeader(string $value): string
	{
		$value = (string)preg_replace('/\r?\n[ \t]+/', ' ', $value);
		$decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
		return self::utf8($decoded === false ? $value : $decoded);
	}

	/** Find and decode the text/plain part; otherwise reduce HTML to text. */
	private static function plainText(array $headers, string $body): ?string
	{
		return self::partText(
			self::headerValue($headers, 'content-type') ?? 'text/plain',
			self::headerValue($headers, 'content-transfer-encoding') ?? '7bit',
			$body,
			0
		);
	}

	private static function partText(string $ct, string $cte, string $body, int $depth): ?string
	{
		if ($depth > 5) {
			return null;
		}
		if (preg_match('~^multipart/[a-z]+~i', $ct)) {
			if (!preg_match('/boundary="?([^";\s]+)"?/i', $ct, $m)) {
				return null;
			}
			// PHPMailer's msgHTML() rebuilds the text part from the HTML and
			// drops every <br> (Froxlor calls it after setting AltBody), so the
			// text part may have lost all line breaks. Use whichever version
			// keeps more lines; on a tie the text part.
			$plainFound = null;
			$htmlFound = null;
			foreach (explode('--' . $m[1], $body) as $part) {
				$part = ltrim($part, "\n");
				if ($part === '' || str_starts_with($part, '--')) {
					continue;
				}
				[$h, $b] = array_pad(explode("\n\n", $part, 2), 2, '');
				$ph = self::parseHeaders($h);
				$pct = self::headerValue($ph, 'content-type') ?? 'text/plain';
				$pcte = self::headerValue($ph, 'content-transfer-encoding') ?? '7bit';
				$t = self::partText($pct, $pcte, $b, $depth + 1);
				if ($t === null) {
					continue;
				}
				if (stripos($pct, 'text/html') === 0) {
					$htmlFound ??= $t;
				} else {
					$plainFound ??= $t;
				}
			}
			if ($plainFound === null || ($htmlFound !== null && self::lineCount($htmlFound) > self::lineCount($plainFound))) {
				return $htmlFound;
			}
			return $plainFound;
		}
		if (!preg_match('~^text/(plain|html)~i', $ct, $m)) {
			return null;
		}
		$raw = rtrim($body, "\n");
		$raw = match (strtolower(trim($cte))) {
			'quoted-printable' => quoted_printable_decode($raw),
			'base64' => (string)base64_decode($raw),
			default => $raw,
		};
		$charset = preg_match('/charset="?([^";\s]+)"?/i', $ct, $c) ? $c[1] : 'UTF-8';
		if (strcasecmp($charset, 'utf-8') !== 0 && strcasecmp($charset, 'us-ascii') !== 0) {
			$converted = iconv($charset, 'UTF-8//TRANSLIT', $raw);
			if ($converted === false) {
				return null;
			}
			$raw = $converted;
		}
		if (strtolower($m[1]) === 'html') {
			// Froxlor's "HTML" is its plain text with "<br />" (or nl2br()),
			// not escaped: a password like "a<b&c" stands there as it is. If
			// <br> is the only tag, the rest is taken literally; real HTML is
			// reduced with strip_tags() and entity decoding. In both cases
			// only <br> (and block ends) break lines; raw newlines are spaces
			// (nl2br() output has both "<br />" and "\n").
			$brOnly = !preg_match('~<(?!br\s*/?>)[A-Za-z/!?]~i', $raw);
			$raw = (string)preg_replace('~<br\s*/?>[ \t]*(\r?\n)?~i', "\x00", $raw);
			if (!$brOnly) {
				$raw = (string)preg_replace('~</(p|div|tr|h[1-6]|li)>[ \t]*(\r?\n)?~i', "\x00", $raw);
			}
			$raw = (string)preg_replace('~\r?\n~', ' ', $raw);
			$raw = str_replace("\x00", "\n", $raw);
			if (!$brOnly) {
				$raw = html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8');
			}
		}
		return self::utf8(str_replace("\r", '', $raw));
	}

	private static function lineCount(string $text): int
	{
		return count(array_filter(explode("\n", trim($text)), static fn($l) => trim($l) !== ''));
	}

	/** Replaces invalid UTF-8 sequences, so the /u regular expressions below never fail. */
	private static function utf8(string $s): string
	{
		return mb_check_encoding($s, 'UTF-8') ? $s : mb_scrub($s, 'UTF-8');
	}

	private static function assemble(array $headers, string $subject, string $text, array $style): string
	{
		$text = str_replace(["\r\n", "\r"], "\n", $text);
		$b1 = 'md-' . bin2hex(random_bytes(8));
		$b2 = 'md-' . bin2hex(random_bytes(8));
		$logo = $style['logo_data'];
		$cid = $logo ? 'logo-' . bin2hex(random_bytes(6)) . '@mail-design' : null;
		$html = self::html($subject, $text, $style, $cid);
		$plain = self::plainVersion($text, $style);

		// Headers: keep everything except what describes the content.
		$head = '';
		$hasAutoSubmitted = false;
		foreach ($headers as [$n, $v]) {
			$lower = strtolower($n);
			if (in_array($lower, ['content-type', 'content-transfer-encoding', 'mime-version', 'content-disposition'], true)) {
				continue;
			}
			$hasAutoSubmitted = $hasAutoSubmitted || $lower === 'auto-submitted';
			$head .= $n . ': ' . $v . "\r\n";
		}
		$head .= "MIME-Version: 1.0\r\n";
		$head .= self::MARKER_HEADER . ": 1\r\n";
		if (!$hasAutoSubmitted) {
			$head .= "Auto-Submitted: auto-generated\r\n";
		}
		$head .= 'Content-Type: multipart/alternative; boundary="' . $b1 . "\"\r\n";

		$qp = static fn(string $s): string => quoted_printable_encode(str_replace("\n", "\r\n", $s));

		$body = "\r\n--$b1\r\n";
		$body .= "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n";
		$body .= $qp($plain) . "\r\n";
		$body .= "--$b1\r\n";
		if ($cid !== null) {
			$body .= 'Content-Type: multipart/related; boundary="' . $b2 . "\"\r\n\r\n";
			$body .= "--$b2\r\n";
		}
		$body .= "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n";
		$body .= $qp($html) . "\r\n";
		if ($cid !== null) {
			$body .= "--$b2\r\n";
			$body .= 'Content-Type: ' . $logo[0] . "; name=\"logo\"\r\n";
			$body .= "Content-Transfer-Encoding: base64\r\nContent-ID: <$cid>\r\n";
			$body .= "Content-Disposition: inline; filename=\"logo\"\r\n\r\n";
			$body .= chunk_split(base64_encode($logo[1]), 76, "\r\n");
			$body .= "--$b2--\r\n";
		}
		$body .= "--$b1--\r\n";

		return $head . $body;
	}

	private static function plainVersion(string $text, array $style): string
	{
		$title = $style['company'] !== '' ? $style['company'] : $style['server'];
		if ($style['company'] !== '' && $style['server'] !== '') {
			$title .= ' · ' . $style['server'];
		}
		$out = '';
		if ($title !== '') {
			$out .= $title . "\n" . str_repeat('=', min(60, max(8, mb_strlen($title)))) . "\n\n";
		}
		$out .= $text . "\n";
		if (trim($style['footer']) !== '') {
			$out .= "\n-- \n" . trim($style['footer']) . "\n";
		}
		return $out;
	}

	private static function e(string $s): string
	{
		return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}

	private static function color(string $c, string $default): string
	{
		return preg_match('/^#[0-9a-fA-F]{6}$/D', $c) ? $c : $default;
	}

	/** Best text color (white or dark) on a background. */
	private static function readableOn(string $hex): string
	{
		[$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');
		return (0.299 * $r + 0.587 * $g + 0.114 * $b) > 160 ? '#111827' : '#ffffff';
	}

	/** Turns addresses into links and escapes everything else. */
	private static function inline(string $s, string $accent): string
	{
		$parts = preg_split('~(https?://[^\s<>"]+)~u', $s, -1, PREG_SPLIT_DELIM_CAPTURE);
		if ($parts === false) {
			return self::e($s);
		}
		$out = '';
		foreach ($parts as $i => $t) {
			if ($i % 2 === 1) {
				$target = rtrim($t, '.,;:)');
				$rest = substr($t, strlen($target));
				$out .= '<a href="' . self::e($target) . '" style="color:' . $accent . ';">' . self::e($target) . '</a>' . self::e($rest);
			} else {
				$out .= self::e($t);
			}
		}
		return $out;
	}

	private static function html(string $subject, string $text, array $style, ?string $logoCid): string
	{
		$accent = $style['accent_color'];
		$headerColor = $style['header_color'];
		$headerText = self::readableOn($headerColor);
		$company = $style['company'];
		$server = $style['server'];
		$hasLogo = $logoCid !== null;

		// Content: paragraphs, plus consecutive "Name: value" lines as a table.
		$lines = explode("\n", $text);
		$content = '';
		$paragraph = [];
		$table = [];
		$flushParagraph = static function () use (&$paragraph, &$content, $accent): void {
			if ($paragraph) {
				$content .= '<p style="margin:0 0 16px;font-size:16px;line-height:1.55;">'
					. implode('<br>', array_map(static fn($l) => self::inline($l, $accent), $paragraph)) . '</p>';
				$paragraph = [];
			}
		};
		// Two or more "Name: value" lines become a box; a single one stays a normal line.
		$flushTable = static function () use (&$table, &$content, &$paragraph, $accent): void {
			if (count($table) >= 2) {
				$content .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" class="md-box" '
					. 'style="margin:0 0 20px;border-left:4px solid ' . $accent . ';background:#f3f4f6;border-radius:6px;">';
				foreach ($table as [$k, $v]) {
					$content .= '<tr><td class="md-muted" style="padding:8px 8px 8px 16px;font-size:14px;color:#6b7280;white-space:nowrap;vertical-align:top;">'
						. self::e($k) . '</td><td style="padding:8px 16px 8px 8px;font-size:15px;font-family:Menlo,Consolas,monospace;word-break:break-all;">'
						. self::inline($v, $accent) . '</td></tr>';
				}
				$content .= '</table>';
			} else {
				foreach ($table as [$k, $v]) {
					$paragraph[] = $k . ': ' . $v;
				}
			}
			$table = [];
		};

		foreach ($lines as $line) {
			$line = rtrim($line);
			if (trim($line) === '') {
				$flushTable();
				$flushParagraph();
				continue;
			}
			if (preg_match('/^([\p{L}][\p{L}\d \-.\/()]{0,32}):\s+(\S.*)$/u', $line, $m) && !preg_match('~^https?$~i', $m[1])) {
				$flushParagraph();
				$table[] = [$m[1], $m[2]];
				continue;
			}
			$flushTable();
			$paragraph[] = $line;
		}
		$flushTable();
		$flushParagraph();

		$headerContent = '';
		if ($hasLogo) {
			$headerContent .= '<img src="cid:' . self::e($logoCid) . '" alt="' . self::e($company !== '' ? $company : $server) . '" height="44" style="display:block;border:0;height:44px;max-width:240px;width:auto;margin:0 0 6px;">';
		} elseif ($company !== '') {
			$headerContent .= '<div style="font-size:22px;font-weight:700;line-height:1.2;color:' . $headerText . ';">' . self::e($company) . '</div>';
		}
		if ($server !== '') {
			$headerContent .= '<div style="font-size:13px;line-height:1.4;opacity:.85;color:' . $headerText . ';">'
				. ($company !== '' || $hasLogo ? self::e(Lang::t('Host server')) . ' ' : '') . self::e($server) . '</div>';
		}

		$footer = '';
		if (trim($style['footer']) !== '') {
			$footer .= '<p style="margin:0 0 8px;">' . implode('<br>', array_map(static fn($l) => self::inline(trim($l), $accent), explode("\n", trim($style['footer'])))) . '</p>';
		}
		$footer .= '<p style="margin:0;">'
			. ($server !== ''
				? self::e(Lang::t('This message was sent automatically by %s.', $server))
				: self::e(Lang::t('This message was sent automatically.')))
			. '</p>';

		return '<!DOCTYPE html>
<html lang="' . (Lang::iso() === 'de' ? 'de' : 'en') . '"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="light dark"><meta name="supported-color-schemes" content="light dark">
<title>' . self::e($subject) . '</title>
<style>
@media (prefers-color-scheme: dark){
 .md-bg{background:#0f1115 !important}
 .md-card{background:#1a1d24 !important;color:#e5e7eb !important}
 .md-box{background:#252a33 !important}
 .md-muted,.md-footer{color:#9ca3af !important}
 .md-h{color:#f3f4f6 !important}
 .md-card a{color:#8ec5ff !important}
 .md-footer{border-top-color:#2d333d !important}
}
@media only screen and (max-width:620px){ .md-card{border-radius:0 !important} .md-pad{padding:24px 20px !important} }
</style></head>
<body class="md-bg" style="margin:0;padding:0;background:#eef0f3;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;">' . self::e($subject) . '</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" class="md-bg" style="background:#eef0f3;"><tr><td align="center" style="padding:24px 12px;">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" class="md-card" style="width:100%;max-width:600px;background:#ffffff;color:#1f2937;border-radius:10px;overflow:hidden;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;">
<tr><td style="background:' . $headerColor . ';padding:22px 32px;border-bottom:4px solid ' . $accent . ';" bgcolor="' . $headerColor . '">' . $headerContent . '</td></tr>
<tr><td class="md-pad" style="padding:32px;">
<h1 class="md-h" style="margin:0 0 20px;font-size:22px;line-height:1.3;color:#111827;">' . self::e($subject) . '</h1>
' . $content . '
</td></tr>
<tr><td class="md-footer" style="padding:18px 32px 24px;border-top:1px solid #e5e7eb;font-size:12px;line-height:1.5;color:#6b7280;">' . $footer . '</td></tr>
</table></td></tr></table></body></html>';
	}
}
