<?php
/**
 * L3 scenariusz A — reprodukcja locka reindeksu (IndexController.php:132-139):
 * `get_transient(LOCK)` → jeśli puste → `set_transient(LOCK,1,...)`. Dwa
 * osobne wywołania, bez atomowości między nimi.
 *
 * @package AI_FAQ_Generator
 */
require __DIR__ . '/l3-shared-state-shim.php';

if ( get_transient( 'lock' ) ) {
	echo "blocked\n";
	exit( 0 );
}
// Poszerza okno wyścigu (realistycznie: między odczytem locka a jego ustawieniem
// kod robi jeszcze walidację klucza API itp.) — czyni wynik DETERMINISTYCZNYM,
// niezależnym od przypadkowego trafienia w wąskie okno na jednej maszynie.
usleep( 20000 );
set_transient( 'lock', 1, 900 );
echo "acquired\n";
