<?php

/** German translations of Mail Design; the key is the English text (see Lang.php). */

// Loaded by Lang; never meant to be requested directly.
if (PHP_SAPI !== 'cli' && realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
	http_response_code(404);
	exit(1);
}
return [
	// Settings
	'Mail design' => 'E-Mail-Gestaltung',
	'Style emails sent by Froxlor' => 'E-Mails von Froxlor gestalten',
	'Turns Froxlor\'s plain-text emails (account information, reports, password reset) into a styled HTML email with a header. Only takes effect while the relay is running: run "./mail-design.sh /var/www/froxlor on" once on the server.'
		=> 'Macht aus den Klartext-Mails von Froxlor (Kontoinformationen, Berichte, Passwort-Zurücksetzen) eine gestaltete HTML-Mail mit Kopfzeile. Wirkt nur, wenn der Relay läuft: einmalig auf dem Server "./mail-design.sh /var/www/froxlor on" ausführen.',
	'Company name in the header' => 'Firmenname in der Kopfzeile',
	'Shown large in the header. With a logo, it serves as the alternative text.' => 'Erscheint groß in der Kopfzeile. Mit Logo dient er als Alternativtext.',
	'Logo' => 'Logo',
	'PNG, JPG or GIF up to 400 KB; email clients do not display SVG. There is only one logo: a new upload replaces the old one. The logo is embedded in the email, so it also shows when the email client blocks images from the web. Height in the email is 44 px, so upload it at twice that size (retina). Without a logo, only the company name appears in the header.'
		=> 'PNG, JPG oder GIF bis 400 KB; SVG zeigen Mailprogramme nicht an. Es gibt nur ein Logo: Ein neuer Upload ersetzt das alte. Das Logo wird in die Mail eingebettet und erscheint daher auch, wenn das Mailprogramm Bilder aus dem Netz blockiert. Höhe in der Mail 44 px, am besten doppelt so groß hochladen (Retina). Ohne Logo steht nur der Firmenname in der Kopfzeile.',
	'Server name in the header' => 'Servername in der Kopfzeile',
	'Shows the host server (from Settings › System › Hostname) below the name, so customers can see which server the email comes from.'
		=> 'Zeigt unter dem Namen den Hostserver (aus Einstellungen › System › Hostname), damit Kunden sehen, von welchem Server die Mail kommt.',
	'Header color' => 'Farbe der Kopfzeile',
	'Hex value like #1f2937. The text color is chosen light or dark to match. A logo with a transparent background should suit the color.'
		=> 'Hex-Wert wie #1f2937. Die Schriftfarbe wird passend hell oder dunkel gewählt. Ein Logo mit transparentem Hintergrund sollte zur Farbe passen.',
	'Accent color' => 'Akzentfarbe',
	'Hex value like #2563eb. Line below the header, border of the login details, and links.' => 'Hex-Wert wie #2563eb. Linie unter der Kopfzeile, Rahmen der Zugangsdaten und Links.',
	'Footer' => 'Fußzeile',
	'Optional, e.g. contact details or legal notice. One line per line; addresses starting with https:// become links.' => 'Optional, z. B. Kontakt oder Impressum. Eine Zeile pro Zeile; Adressen mit https:// werden zu Links.',

	// Logo upload
	'The logo could not be uploaded.' => 'Das Logo konnte nicht hochgeladen werden.',
	'The logo is too large (400 KB at most).' => 'Das Logo ist zu groß (höchstens 400 KB).',
	'The logo must be a PNG, JPG or GIF image.' => 'Das Logo muss ein PNG-, JPG- oder GIF-Bild sein.',
	'The logo may be at most %d × %d pixels.' => 'Das Logo darf höchstens %d × %d Pixel groß sein.',
	'The img/ directory is missing and cannot be created.' => 'Das Verzeichnis img/ fehlt und lässt sich nicht anlegen.',
	'The logo could not be saved to the img/ directory.' => 'Das Logo konnte nicht im Verzeichnis img/ gespeichert werden.',

	// Mail frame (panel default language)
	'Host server' => 'Hostserver',
	'This message was sent automatically by %s.' => 'Diese Nachricht wurde automatisch von %s versendet.',
	'This message was sent automatically.' => 'Diese Nachricht wurde automatisch versendet.',
];
