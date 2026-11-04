<?php

/**
 * The local mail relay: accepts Froxlor's mails via SMTP on 127.0.0.1,
 * styles them (Styler) and hands them to the local sendmail, i.e. the same
 * way they took before.
 *
 * systemd owns the listening socket (froxlor-mail-design.socket, Accept=yes)
 * and starts one instance of froxlor-mail-design@.service per connection,
 * with the connection on stdin/stdout; mail-relay.php calls serve() on it.
 * Because systemd holds the port from boot on and across restarts, no local
 * user can take it over and receive the panel's mails or SMTP password.
 *
 * Runs without Froxlor's classes; the settings are read fresh from the
 * database for every mail, so changes in the panel apply immediately.
 *
 * Failure tolerant: if styling fails, the original mail is sent unchanged.
 * Only if sendmail itself fails does the relay answer with an error, and
 * Froxlor reports "error sending mail" as usual.
 */

namespace Froxlor\Ext\MailDesign;

use PDO;

final class Relay
{
	public const DEFAULT_PORT = 2530;

	/** Mails larger than this are passed through unstyled (Froxlor's are a few KB). */
	public const STYLE_LIMIT = 1048576;

	/** Settings group of this extension in panel_settings. */
	public const SETTINGS_GROUP = 'ext_maildesign';

	private string $froxlorDir;
	private string $sendmail;

	/** @var callable|null returns the settings as "group.varname" => value */
	private $settingsLoader;

	private ?PDO $pdo = null;

	/** @var array<string, array{0:int, 1:?array{0:string,1:string}}> */
	private array $logoCache = [];

	public function __construct(string $froxlorDir, ?callable $settingsLoader = null, ?string $sendmail = null)
	{
		$this->froxlorDir = rtrim($froxlorDir, '/');
		$this->settingsLoader = $settingsLoader;
		$this->sendmail = $sendmail ?? (is_executable('/usr/sbin/sendmail') ? '/usr/sbin/sendmail' : 'sendmail');
	}

	/**
	 * Runs one SMTP conversation. Nothing is read from the database before
	 * the client authenticates; the greeting is fixed.
	 *
	 * @param resource $in
	 * @param resource|null $out null = same stream as $in
	 */
	public function serve($in, $out = null, int $authFailDelay = 1): void
	{
		$session = new SmtpSession(
			fn(string $user, string $password): bool => $this->authenticate($user, $password),
			fn(string $from, array $recipients, string $data): array => $this->deliver($from, $recipients, $data),
			SmtpSession::BANNER_NAME,
			SmtpSession::MAX_MESSAGE_BYTES,
			$authFailDelay
		);
		$session->handle($in, $out);
	}

	/**
	 * Owner (uid) of the client end of a loopback TCP connection, looked up
	 * in /proc/net/tcp by the client's port (systemd passes it as
	 * REMOTE_PORT). null = not found.
	 */
	public static function peerUid(int $clientPort, string $procNetTcp = '/proc/net/tcp'): ?int
	{
		$content = @file_get_contents($procNetTcp);
		return is_string($content) ? self::uidFromProcNetTcp($content, $clientPort) : null;
	}

	/** Parses /proc/net/tcp: the row whose local address is 127.0.0.1:<port>. */
	public static function uidFromProcNetTcp(string $content, int $clientPort): ?int
	{
		$want = sprintf('0100007F:%04X', $clientPort);
		foreach (explode("\n", $content) as $row) {
			$f = preg_split('/\s+/', trim($row));
			if (is_array($f) && count($f) > 7 && strtoupper($f[1]) === $want && ctype_digit($f[7])) {
				return (int)$f[7];
			}
		}
		return null;
	}

	/**
	 * Accepts exactly the credentials Froxlor uses for SMTP. They are set by
	 * mail-design.sh when it points Froxlor at the relay; an empty password
	 * never matches.
	 */
	public function authenticate(string $user, string $password): bool
	{
		$s = $this->settings();
		$expectedUser = (string)($s['system.mail_smtp_user'] ?? '');
		$expectedPassword = (string)($s['system.mail_smtp_passwd'] ?? '');
		if ($expectedUser === '' || $expectedPassword === '') {
			return false;
		}
		// Compare both, always, so the timing does not tell which one was wrong.
		$userOk = hash_equals($expectedUser, $user);
		$passwordOk = hash_equals($expectedPassword, $password);
		return $userOk && $passwordOk;
	}

	/**
	 * Styles the mail and hands it to sendmail.
	 *
	 * @param list<string> $recipients
	 * @return array{0:bool, 1:string}
	 */
	public function deliver(string $from, array $recipients, string $data): array
	{
		$settings = $this->settings();
		$styled = $this->style($data, $settings);
		// Envelope sender as with Froxlor's own PHP pool before: postmaster@<hostname>.
		$hostname = (string)($settings['system.hostname'] ?? '');
		$envelope = preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9.-]{0,251}[A-Za-z0-9])?$/D', $hostname) ? 'postmaster@' . $hostname : $from;
		return $this->sendmail($envelope, $recipients, str_replace("\r\n", "\n", $styled));
	}

	/** @param array<string,string> $settings */
	public function style(string $mail, array $settings): string
	{
		$g = self::SETTINGS_GROUP . '.';
		if (($settings[$g . 'enabled'] ?? '0') !== '1' || strlen($mail) > self::STYLE_LIMIT) {
			return $mail;
		}
		Lang::set((string)($settings['panel.standardlanguage'] ?? 'en'));
		$server = ($settings[$g . 'show_server'] ?? '1') === '1' ? (string)($settings['system.hostname'] ?? '') : '';
		return Styler::style($mail, [
			'company' => (string)($settings[$g . 'company_name'] ?? ''),
			'server' => $server,
			'header_color' => (string)($settings[$g . 'header_color'] ?? ''),
			'accent_color' => (string)($settings[$g . 'accent_color'] ?? ''),
			'footer' => (string)($settings[$g . 'footer'] ?? ''),
			'logo_data' => $this->loadLogo((string)($settings[$g . 'logo'] ?? '')),
		]);
	}

	/**
	 * Loads the logo named in the setting. Only an image file directly in
	 * Froxlor's img/ directory is accepted (the upload stores
	 * "img/mail-logo.png?v=...", Froxlor's settings import a random name
	 * there): no absolute paths, no "..", no URLs, no symbolic links.
	 *
	 * @return array{0:string, 1:string}|null [MIME type, bytes]
	 */
	public function loadLogo(string $setting): ?array
	{
		$path = explode('?', trim($setting), 2)[0];
		if (!preg_match('~^img/[A-Za-z0-9][A-Za-z0-9._-]{0,100}\.(png|jpe?g|gif)$~iD', $path)) {
			return null;
		}
		$file = $this->froxlorDir . '/' . $path;
		clearstatcache(true, $file);
		if (is_link($file) || !is_file($file) || !is_readable($file)) {
			return null;
		}
		$key = $path . '|' . (int)@filemtime($file) . '|' . (int)@filesize($file);
		if (isset($this->logoCache[$key]) && $this->logoCache[$key][0] > time()) {
			return $this->logoCache[$key][1];
		}
		$result = null;
		$data = @file_get_contents($file, false, null, 0, LogoStore::MAX_BYTES + 1);
		if (is_string($data) && $data !== '' && strlen($data) <= LogoStore::MAX_BYTES) {
			$info = @getimagesizefromstring($data);
			$types = [IMAGETYPE_PNG => 'image/png', IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_GIF => 'image/gif'];
			if ($info && isset($types[$info[2]]) && $info[0] <= LogoStore::MAX_DIMENSION && $info[1] <= LogoStore::MAX_DIMENSION) {
				$result = [$types[$info[2]], $data];
			}
		}
		$this->logoCache = [$key => [time() + 600, $result]];
		return $result;
	}

	/**
	 * Settings fresh from the database, so changes in the panel apply at once.
	 *
	 * @return array<string,string> "group.varname" => value; empty if the database is unreachable
	 */
	public function settings(): array
	{
		if ($this->settingsLoader !== null) {
			return ($this->settingsLoader)();
		}
		for ($attempt = 0; $attempt < 2; $attempt++) {
			try {
				if ($this->pdo === null) {
					[$this->pdo] = DbConnection::open($this->froxlorDir, 5);
				}
				$stmt = $this->pdo->prepare("
					SELECT `settinggroup`, `varname`, `value` FROM `panel_settings`
					WHERE `settinggroup` = ?
						OR (`settinggroup` = 'system' AND `varname` IN ('hostname', 'mail_smtp_user', 'mail_smtp_passwd'))
						OR (`settinggroup` = 'panel' AND `varname` = 'standardlanguage')
				");
				$stmt->execute([self::SETTINGS_GROUP]);
				$out = [];
				foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
					$out[$row['settinggroup'] . '.' . $row['varname']] = (string)$row['value'];
				}
				return $out;
			} catch (\Throwable $e) {
				$this->pdo = null;
				if ($attempt === 1) {
					SmtpSession::log('Settings: ' . $e->getMessage());
				}
			}
		}
		return [];
	}

	/**
	 * Hands the mail to sendmail. No shell is involved: the arguments are
	 * passed as an array, the recipients after "--". Its stdin, stdout and
	 * stderr are pipes; the connection (fds 0/1 of this process) is replaced
	 * by them in the child.
	 *
	 * @param list<string> $recipients
	 * @return array{0:bool, 1:string}
	 */
	private function sendmail(string $envelope, array $recipients, string $mail): array
	{
		// Close the database connection first, so sendmail does not inherit it.
		$this->pdo = null;
		$cmd = [$this->sendmail, '-i'];
		if ($envelope !== '') {
			$cmd[] = '-f';
			$cmd[] = $envelope;
		}
		$cmd[] = '--';
		foreach ($recipients as $r) {
			$cmd[] = $r;
		}
		$p = @proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		if (!is_resource($p)) {
			return [false, 'cannot start sendmail'];
		}
		@fwrite($pipes[0], $mail);
		fclose($pipes[0]);
		$output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$code = proc_close($p);
		return [$code === 0, trim((string)$output)];
	}
}
