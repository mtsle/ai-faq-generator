<?php
/**
 * L3 scenariusz C — reprodukcja PublicFaq::publish()/snapshot_previous()
 * (PublicFaq.php:60-112): odczyt bieżącej wersji, zapis jako "poprzednia",
 * zapis nowej. Trzy osobne wywołania opcji, bez blokady — last-write-wins.
 *
 * @package AI_FAQ_Generator
 */
require __DIR__ . '/l3-shared-state-shim.php';

$id = (string) ( $argv[1] ?? '?' );

$prev = get_option( 'faq', null );
update_option( 'faq_prev', $prev );
usleep( 20000 );
update_option( 'faq', 'payload-from-' . $id );
echo "published-$id\n";
