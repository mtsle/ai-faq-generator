<?php
/**
 * L3 scenariusz B — reprodukcja sufitu dobowego budżetu
 * (RagService.php:693-694,742-752 / GeneratorService.php:56-65):
 * odczyt licznika, porównanie z limitem, jeśli OK → inkrementacja. Dwa
 * osobne wywołania (`get_option`/`update_option`), bez atomowości między nimi.
 *
 * @package AI_FAQ_Generator
 */
require __DIR__ . '/l3-shared-state-shim.php';

$limit = (int) ( $argv[1] ?? 10 );
$count = (int) get_option( 'budget_count', 0 );

if ( $count >= $limit ) {
	echo "rejected\n";
	exit( 0 );
}
usleep( 20000 );
update_option( 'budget_count', $count + 1 );
echo "accepted\n";
