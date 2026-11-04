<?php

/**
 * Prints "local" if Froxlor's database is reached via a socket or on this
 * host, "remote" otherwise. mail-design.sh uses it to decide whether the
 * relay service may be limited to loopback network access.
 *
 *   php migrations/db-info.php /var/www/froxlor
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit(1);
}

require __DIR__ . '/connection.php';

try {
	echo \Froxlor\Ext\MailDesign\DbConnection::config($argv[1] ?? '')['local'] ? "local\n" : "remote\n";
} catch (RuntimeException $e) {
	fwrite(STDERR, '  ' . maildesign_clean($e->getMessage()) . "\n");
	exit(1);
}
