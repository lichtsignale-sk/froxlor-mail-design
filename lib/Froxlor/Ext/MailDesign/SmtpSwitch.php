<?php

/**
 * Points Froxlor's mail delivery at the local relay, or back.
 *
 * Changes rows in panel_settings only: Froxlor's own SMTP settings
 * (system.mail_use_smtp, _host, _port, _usetls, _auth, _user, _passwd), read
 * by Froxlor\System\Mailer for every mail. No Froxlor file is touched.
 *
 * - If Froxlor already uses an SMTP server of its own, nothing is switched:
 *   the relay hands mails to the local sendmail, not to that server, and the
 *   admin's configuration must not be overwritten.
 * - Before switching, the previous values are stored as JSON in
 *   ext_maildesign.smtp_backup; switching off restores them exactly.
 * - The relay requires SMTP AUTH. A random password is generated here and
 *   stored in system.mail_smtp_passwd, where Froxlor (sender) and the relay
 *   (receiver) both read it. Local users cannot read panel_settings, so they
 *   cannot use the relay.
 *
 * Works on any PDO connection with a panel_settings table (MySQL in
 * production, SQLite in the tests).
 */

namespace Froxlor\Ext\MailDesign;

use PDO;

final class SmtpSwitch
{
	public const RELAY_HOST = '127.0.0.1';
	/** Same as Relay::DEFAULT_PORT; repeated so this class works on its own. */
	public const DEFAULT_PORT = 2530;
	public const SMTP_USER = 'froxlor-mail-design';
	public const GROUP = 'ext_maildesign';
	public const BACKUP_VARNAME = 'smtp_backup';

	/** Froxlor's settings (group "system") that are changed and backed up. */
	public const SMTP_KEYS = [
		'mail_use_smtp',
		'mail_smtp_host',
		'mail_smtp_port',
		'mail_smtp_usetls',
		'mail_smtp_auth',
		'mail_smtp_user',
		'mail_smtp_passwd',
	];

	private PDO $pdo;
	private int $port;

	public function __construct(PDO $pdo, int $port = self::DEFAULT_PORT)
	{
		$this->pdo = $pdo;
		$this->port = $port;
	}

	/** True if Froxlor currently sends via the relay. */
	public function usesRelay(): bool
	{
		return $this->get('system', 'mail_use_smtp') === '1'
			&& $this->get('system', 'mail_smtp_host') === self::RELAY_HOST
			&& (int)$this->get('system', 'mail_smtp_port') === $this->port;
	}

	public function hasBackup(): bool
	{
		return $this->get(self::GROUP, self::BACKUP_VARNAME) !== null;
	}

	/**
	 * Points Froxlor at the relay.
	 *
	 * @return string message for the operator
	 * @throws \RuntimeException if Froxlor uses an SMTP server of its own
	 */
	public function enable(): string
	{
		$this->pdo->beginTransaction();
		try {
			if ($this->usesRelay()) {
				// Already switched (re-run): only make sure the credentials are set.
				if ($this->get('system', 'mail_smtp_auth') !== '1'
					|| $this->get('system', 'mail_smtp_user') !== self::SMTP_USER
					|| (string)$this->get('system', 'mail_smtp_passwd') === '') {
					$this->setCredentials();
				}
				$this->set(self::GROUP, 'enabled', '1');
				$this->pdo->commit();
				return 'Froxlor already sends via the relay on ' . self::RELAY_HOST . ':' . $this->port;
			}
			if ($this->get('system', 'mail_use_smtp') === '1') {
				throw new \RuntimeException(
					"Froxlor already uses an SMTP server of its own (Settings > System > Mail).\n"
					. "The relay hands mails to the local sendmail, not to that server, so nothing was changed.\n"
					. 'To use Mail Design, switch Froxlor back to "sendmail" first.'
				);
			}
			$backup = [];
			foreach (self::SMTP_KEYS as $key) {
				$backup[$key] = $this->get('system', $key);
			}
			$this->set(self::GROUP, self::BACKUP_VARNAME, (string)json_encode($backup, JSON_UNESCAPED_SLASHES));
			$this->set('system', 'mail_smtp_host', self::RELAY_HOST);
			$this->set('system', 'mail_smtp_port', (string)$this->port);
			$this->set('system', 'mail_smtp_usetls', '0');
			$this->setCredentials();
			$this->set('system', 'mail_use_smtp', '1');
			$this->set(self::GROUP, 'enabled', '1');
			$this->pdo->commit();
			return 'Froxlor now sends via the relay on ' . self::RELAY_HOST . ':' . $this->port;
		} catch (\Throwable $e) {
			if ($this->pdo->inTransaction()) {
				$this->pdo->rollBack();
			}
			throw $e;
		}
	}

	/**
	 * Restores the SMTP settings from before enable(), if Froxlor still
	 * points at the relay. If the admin has changed them in the meantime,
	 * they are left alone.
	 *
	 * @return string message for the operator
	 */
	public function disable(): string
	{
		$this->pdo->beginTransaction();
		try {
			$backupJson = $this->get(self::GROUP, self::BACKUP_VARNAME);
			$backup = $backupJson === null ? null : json_decode($backupJson, true);
			if (!$this->usesRelay()) {
				$this->delete(self::GROUP, self::BACKUP_VARNAME);
				$this->pdo->commit();
				return $backupJson !== null
					? 'Froxlor no longer sent via the relay (changed by hand); SMTP settings left as they are'
					: 'Froxlor did not send via the relay, nothing to do';
			}
			if (is_array($backup)) {
				foreach (self::SMTP_KEYS as $key) {
					if (!array_key_exists($key, $backup)) {
						continue;
					}
					if ($backup[$key] === null) {
						// The row did not exist before enable() created it.
						$this->delete('system', $key);
					} else {
						$this->set('system', $key, (string)$backup[$key]);
					}
				}
				// Never leave Froxlor pointing at a relay that is about to stop.
				if ($this->usesRelay()) {
					$this->set('system', 'mail_use_smtp', '0');
				}
				$message = 'Previous SMTP settings restored';
			} else {
				// No backup (switched by hand or by an older version): back to sendmail.
				$this->set('system', 'mail_use_smtp', '0');
				if ($this->get('system', 'mail_smtp_user') === self::SMTP_USER) {
					$this->set('system', 'mail_smtp_user', '');
					$this->set('system', 'mail_smtp_passwd', '');
				}
				$message = 'Froxlor sends directly via sendmail again';
			}
			$this->delete(self::GROUP, self::BACKUP_VARNAME);
			$this->pdo->commit();
			return $message;
		} catch (\Throwable $e) {
			if ($this->pdo->inTransaction()) {
				$this->pdo->rollBack();
			}
			throw $e;
		}
	}

	private function setCredentials(): void
	{
		// Hex only: the value is shown again in Froxlor's settings form.
		$this->set('system', 'mail_smtp_auth', '1');
		$this->set('system', 'mail_smtp_user', self::SMTP_USER);
		$this->set('system', 'mail_smtp_passwd', bin2hex(random_bytes(24)));
	}

	private function get(string $group, string $varname): ?string
	{
		$stmt = $this->pdo->prepare('SELECT `value` FROM `panel_settings` WHERE `settinggroup` = ? AND `varname` = ?');
		$stmt->execute([$group, $varname]);
		$value = $stmt->fetchColumn();
		return $value === false ? null : (string)$value;
	}

	private function set(string $group, string $varname, string $value): void
	{
		if ($this->get($group, $varname) === null) {
			$this->pdo->prepare('INSERT INTO `panel_settings` (`settinggroup`, `varname`, `value`) VALUES (?, ?, ?)')
				->execute([$group, $varname, $value]);
			return;
		}
		$this->pdo->prepare('UPDATE `panel_settings` SET `value` = ? WHERE `settinggroup` = ? AND `varname` = ?')
			->execute([$value, $group, $varname]);
	}

	private function delete(string $group, string $varname): void
	{
		$this->pdo->prepare('DELETE FROM `panel_settings` WHERE `settinggroup` = ? AND `varname` = ?')
			->execute([$group, $varname]);
	}
}
