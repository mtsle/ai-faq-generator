<?php
/**
 * L3 scenariusz A — AFTER: reprodukcja NOWEGO `IndexController::acquire_lock()`
 * (add_option() na UNIQUE, atomowe) po poprawce z tego etapu. Wierna kopia
 * logiki metody — patrz src/Admin/IndexController.php.
 *
 * @package AI_FAQ_Generator
 */
require __DIR__ . '/l3-shared-state-shim.php';

const LOCK_TTL = 900;

function acquire_lock_after(): bool {
	$now = time();
	if ( add_option( 'lock', (string) $now ) ) {
		return true;
	}
	$held = (int) real_get_option( 'lock', 0 );
	if ( $held > 0 && ( $now - $held ) < LOCK_TTL ) {
		return false;
	}
	delete_option( 'lock' );
	return (bool) add_option( 'lock', (string) $now );
}

// To samo sztuczne poszerzenie okna co w scenariuszu BEFORE — żeby porównanie
// było uczciwe (identyczne warunki wyścigu, różni się tylko mechanizm zamka).
usleep( 20000 );

echo acquire_lock_after() ? "acquired\n" : "blocked\n";
