<?php

/**
 * Database connection for the command line scripts in this directory.
 *
 * Uses the same code as the relay (lib/Froxlor/Ext/MailDesign/DbConnection.php):
 * exactly the credentials and options from Froxlor's lib/userdata.inc.php,
 * which is parsed, never executed (these scripts run as root, the file
 * belongs to the panel user). On failure it prints the reason and exits.
 *
 * Everything printed that may come from that file or from the database goes
 * through maildesign_clean(), so no control characters reach a terminal.
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit(1);
}

require_once __DIR__ . '/../lib/Froxlor/Ext/MailDesign/DbConnection.php';

/** @return array{0: PDO, 1: string, 2: string, 3: string} PDO, database, host, user */
function maildesign_connect(string $froxlorDir): array
{
	try {
		return \Froxlor\Ext\MailDesign\DbConnection::open($froxlorDir);
	} catch (RuntimeException $e) {
		fwrite(STDERR, '  ' . maildesign_clean($e->getMessage()) . "\n");
		exit(1);
	}
}

/** Removes control characters (terminal escape sequences) from text for output. */
function maildesign_clean(string $text): string
{
	return (string)preg_replace('/[\x00-\x09\x0b-\x1f\x7f]|\xc2[\x80-\x9f]/', '', $text);
}
