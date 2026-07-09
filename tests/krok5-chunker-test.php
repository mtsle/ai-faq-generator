<?php
/**
 * Test regresyjny Kroku 5 — Chunker (czysta logika, bez WP/sieci).
 *
 * Sprawdza: normalizację, pusty tekst, krótki tekst (1 fragment), wielo-fragment
 * z limitem rozmiaru, nakładkę (overlap), twardy podział zbyt długiej jednostki,
 * bezpieczeństwo UTF-8 (polskie znaki nie są przecinane w połowie).
 *
 * URUCHOMIENIE:  php -d extension=mbstring tests/krok5-chunker-test.php
 * Kod wyjścia: 0 = OK, 1 = błędy.
 *
 * @package AI_FAQ_Generator
 */

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }

require __DIR__ . '/../src/Index/Chunker.php';

use AIFAQ\Index\Chunker;

$fail = 0;
function check( $cond, $label ) {
	global $fail;
	echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n";
	if ( ! $cond ) { $fail++; }
}
function mlen( $s ) { return function_exists( 'mb_strlen' ) ? mb_strlen( $s ) : strlen( $s ); }

// ===========================================================================
echo "=== A. Przypadki brzegowe ===\n";
$c = new Chunker( 1000, 200 );
check( array() === $c->chunk( '' ), "pusty tekst → []" );
check( array() === $c->chunk( "   \n\t  " ), "same białe znaki → []" );
$one = $c->chunk( 'Ala ma kota.' );
check( array( 'Ala ma kota.' ) === $one, "krótki tekst → 1 fragment (znormalizowany)" );
$norm = $c->chunk( "Zdanie   z    wieloma\n\n\nspacjami." );
check( 1 === count( $norm ) && 'Zdanie z wieloma spacjami.' === $norm[0], "normalizacja: zwija spacje i puste linie" );

echo "\n=== B. Wiele fragmentów + limit rozmiaru ===\n";
$para = 'Krowa daje mleko. Krowa je trawę. Byk jest duży. Cielę jest małe. Obora jest ciepła. Rolnik doi krowy każdego ranka.';
$cc   = new Chunker( 50, 0 ); // bez nakładki — czysty limit.
$ch   = $cc->chunk( $para );
check( count( $ch ) > 1, "długi tekst → wiele fragmentów (" . count( $ch ) . ')' );
$maxlen = 0;
foreach ( $ch as $frag ) { $maxlen = max( $maxlen, mlen( $frag ) ); }
check( $maxlen <= 50, "każdy fragment ≤ target=50 (max=" . $maxlen . ')' );
$empty = false;
foreach ( $ch as $frag ) { if ( '' === trim( $frag ) ) { $empty = true; } }
check( ! $empty, "brak pustych fragmentów" );

echo "\n=== C. Nakładka (overlap) ===\n";
$co   = new Chunker( 50, 15 );
$cho  = $co->chunk( $para );
check( count( $cho ) > 1, "z nakładką nadal wiele fragmentów" );
// Pierwszy token fragmentu i+1 powinien pochodzić z fragmentu i (nakładka).
$first_tokens = explode( ' ', $cho[1] );
$first_word   = $first_tokens[0];
check( '' !== $first_word && false !== strpos( $cho[0], $first_word ), "fragment #2 zaczyna się treścią z fragmentu #1 (overlap)" );
$maxlen_o = 0;
foreach ( $cho as $frag ) { $maxlen_o = max( $maxlen_o, mlen( $frag ) ); }
check( $maxlen_o <= 50 + 15, "z nakładką fragment ≤ target+overlap=65 (max=" . $maxlen_o . ')' );

echo "\n=== D. Twardy podział zbyt długiej jednostki ===\n";
$long = str_repeat( 'a', 100 ); // jedno „słowo" 100 znaków, bez granic zdań.
$cd   = new Chunker( 20, 0 );
$chd  = $cd->chunk( $long );
check( 5 === count( $chd ), "100 znaków / 20 = 5 kawałków (" . count( $chd ) . ')' );
$ok_len = true;
foreach ( $chd as $frag ) { if ( mlen( $frag ) > 20 ) { $ok_len = false; } }
check( $ok_len, "każdy kawałek ≤ 20 znaków" );
check( $long === implode( '', $chd ), "sklejenie kawałków = oryginał (bez zgubienia treści)" );

echo "\n=== E. Bezpieczeństwo UTF-8 (polskie znaki) ===\n";
$pl  = str_repeat( 'zażółć gęślą jaźń. ', 20 ); // dużo wielobajtowych znaków.
$ce  = new Chunker( 40, 10 );
$che = $ce->chunk( $pl );
$all_valid = true;
foreach ( $che as $frag ) {
	// preg_match z /u zwraca 1 dla poprawnego UTF-8, 0/false dla uszkodzonego.
	if ( 1 !== preg_match( '//u', $frag ) ) { $all_valid = false; }
}
check( count( $che ) > 1, "polski tekst → wiele fragmentów" );
check( $all_valid, "każdy fragment to POPRAWNY UTF-8 (żaden znak nie przecięty)" );

echo "\n=== PODSUMOWANIE ===\n";
echo ( 0 === $fail ) ? "TEST KROK 5 (Chunker): WSZYSTKIE ASERCJE OK\n" : "TEST KROK 5 (Chunker): $fail ASERCJI NIE PRZESZŁO\n";
exit( $fail === 0 ? 0 : 1 );
