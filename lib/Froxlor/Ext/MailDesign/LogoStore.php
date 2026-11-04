<?php

/**
 * Logo upload for Mail Design: exactly one file at a fixed place.
 *
 * Froxlor's own image upload (Store::storeSettingImage) stores each file
 * under a random name in img/ and leaves the previous one behind. Here there
 * should only ever be one logo, under a known path
 * (img/mail-logo.<png|jpg|gif>), and a new upload replaces it.
 *
 * It is hooked in via 'plausibility_check_method' on the image field in
 * actions/admin/settings/192.ext.maildesign.php: Froxlor calls this method for
 * every field before saving. It takes the upload, stores it, sets the setting
 * and removes the upload from $_FILES, so Froxlor's own save method finds
 * nothing left to do. Deleting via the checkbox is still handled by Froxlor
 * (it deletes the file and empties the setting).
 *
 * Accepted are PNG, JPEG and GIF, recognised by their content (getimagesize),
 * never by the client's file name or MIME type. SVG is refused on purpose:
 * mail clients do not display it, and it can carry scripts. The target name
 * is fixed, so nothing from the upload ever becomes part of a path.
 */

namespace Froxlor\Ext\MailDesign;

use Froxlor\Froxlor;
use Froxlor\Settings;
use Froxlor\UI\Response;
use Froxlor\Validate\Check;

final class LogoStore
{
	public const MAX_BYTES = 400000;
	/** Largest width and height in pixels (shown 44 px high in the mail). */
	public const MAX_DIMENSION = 2000;
	public const BASENAME = 'mail-logo';

	/** Image type => file extension. */
	public const TYPES = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_GIF => 'gif'];

	/** Called by Froxlor as plausibility_check_method. */
	public static function check($fieldname, $fielddata, $newfieldvalue, $allnewfieldvalues): array
	{
		$upload = $_FILES[$fieldname] ?? null;
		if (!is_array($upload) || empty($upload['tmp_name']) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
			return [Check::FORMFIELDS_PLAUSIBILITY_CHECK_OK];
		}
		// Upload and "delete" ticked at the same time: deleting wins. Froxlor
		// deletes the file named in the current setting afterwards; storing
		// the upload first would let it delete the freshly stored logo.
		if (!empty($_POST[$fieldname . '_delete'])) {
			unset($_FILES[$fieldname]);
			return [Check::FORMFIELDS_PLAUSIBILITY_CHECK_OK];
		}
		if (!is_string($upload['tmp_name']) || ($upload['error'] ?? 0) !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'])) {
			Response::dynamicError(Lang::t('The logo could not be uploaded.'));
		}
		try {
			$filename = self::store($upload['tmp_name'], Froxlor::getInstallDir() . '/img/');
		} catch (\RuntimeException $e) {
			Response::dynamicError($e->getMessage());
		}
		Settings::Set($fielddata['settinggroup'] . '.' . $fielddata['varname'], 'img/' . $filename . '?v=' . time());
		// Froxlor's save method should not do anything any more.
		unset($_FILES[$fieldname]);
		return [Check::FORMFIELDS_PLAUSIBILITY_CHECK_OK];
	}

	/**
	 * Checks the image and stores it as mail-logo.<ext> in the directory; an
	 * earlier logo (also with a different extension) is removed.
	 *
	 * @return string file name without directory
	 */
	public static function store(string $source, string $directory): string
	{
		$directory = rtrim($directory, '/') . '/';
		$size = @filesize($source);
		if ($size === false || $size === 0) {
			throw new \RuntimeException(Lang::t('The logo must be a PNG, JPG or GIF image.'));
		}
		if ($size > self::MAX_BYTES) {
			throw new \RuntimeException(Lang::t('The logo is too large (400 KB at most).'));
		}
		$info = @getimagesize($source);
		if (!$info || !isset(self::TYPES[$info[2]])) {
			throw new \RuntimeException(Lang::t('The logo must be a PNG, JPG or GIF image.'));
		}
		if ($info[0] < 1 || $info[1] < 1 || $info[0] > self::MAX_DIMENSION || $info[1] > self::MAX_DIMENSION) {
			throw new \RuntimeException(Lang::t('The logo may be at most %d × %d pixels.', self::MAX_DIMENSION, self::MAX_DIMENSION));
		}
		if (!is_dir($directory) && !@mkdir($directory, 0775, true)) {
			throw new \RuntimeException(Lang::t('The img/ directory is missing and cannot be created.'));
		}
		$name = self::BASENAME . '.' . self::TYPES[$info[2]];
		$target = $directory . $name;
		// Write next to the target first, then rename: never a half-written file.
		// rename() replaces a symbolic link at the target instead of following it.
		$tmp = $directory . '.' . self::BASENAME . '.' . bin2hex(random_bytes(6)) . '.tmp';
		$ok = is_uploaded_file($source) ? @move_uploaded_file($source, $tmp) : @copy($source, $tmp);
		if (!$ok || !@chmod($tmp, 0644) || !@rename($tmp, $target)) {
			@unlink($tmp);
			throw new \RuntimeException(Lang::t('The logo could not be saved to the img/ directory.'));
		}
		foreach (self::TYPES as $extension) {
			$old = $directory . self::BASENAME . '.' . $extension;
			if (self::BASENAME . '.' . $extension !== $name && (is_file($old) || is_link($old))) {
				@unlink($old);
			}
		}
		return $name;
	}
}
