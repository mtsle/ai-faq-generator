<?php
/**
 * Test regresyjny Kroku 5 — WpContentSource (WP → dokumenty, strip HTML).
 *
 * to_plain() testowany bez WordPressa (na natywnych funkcjach), documents()
 * na atrapach get_posts/get_the_title/get_permalink.
 *
 * URUCHOMIENIE:  php -d extension=mbstring tests/krok5-contentsource-test.php
 * Kod wyjścia: 0 = OK, 1 = błędy.
 *
 * @package AI_FAQ_Generator
 */

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }

// --- Atrapy WP (zdefiniowane PRZED załadowaniem klasy) ---
$GLOBALS['__posts'] = array();
if ( ! function_exists( 'get_posts' ) ) {
	function get_posts( $args = array() ) { return $GLOBALS['__posts']; }
}
if ( ! function_exists( 'get_the_title' ) ) {
	function get_the_title( $id ) { return 'Tytuł ' . $id; }
}
if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( $id ) { return 'https://example.test/?p=' . $id; }
}
// strip_shortcodes / wp_strip_all_tags celowo NIE definiujemy — testujemy fallbacki.

require __DIR__ . '/../src/Index/ContentSource.php';
require __DIR__ . '/../src/Index/WpContentSource.php';

use AIFAQ\Index\WpContentSource;

$fail = 0;
function check( $cond, $label ) {
	global $fail;
	echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n";
	if ( ! $cond ) { $fail++; }
}

// ===========================================================================
echo "=== A. to_plain() — czyszczenie HTML ===\n";
$html = "<!-- wp:paragraph --><p>Krowa daje <strong>mleko</strong>.</p><!-- /wp:paragraph -->\n<p>Drugi akapit.</p>";
$txt  = WpContentSource::to_plain( $html );
check( false === strpos( $txt, '<' ) && false === strpos( $txt, '>' ), "brak tagów HTML w wyniku" );
check( false === strpos( $txt, 'wp:' ), "usunięte komentarze bloków Gutenberga" );
check( false !== strpos( $txt, 'Krowa daje mleko.' ), "tekst akapitu zachowany" );
check( false !== strpos( $txt, 'Drugi akapit.' ), "drugi akapit zachowany" );

echo "\n=== B. to_plain() — shortcode, encje, granice słów ===\n";
check( '' === WpContentSource::to_plain( '[gallery ids="1,2,3"]' ), "sam shortcode → pusty tekst" );
check( 'Ala & Ola' === WpContentSource::to_plain( 'Ala &amp; Ola' ), "encje zdekodowane (&amp; → &)" );
$joined = WpContentSource::to_plain( '<li>jeden</li><li>dwa</li>' );
check( false !== strpos( $joined, 'jeden' ) && false !== strpos( $joined, 'dwa' ) && false === strpos( $joined, 'jedendwa' ), "tagi blokowe rozdzielają słowa (brak zlepienia)" );
$tbl = WpContentSource::to_plain( '<table><tr><td>Poniedziałek</td><td>7:00</td></tr></table>' );
check( false === strpos( $tbl, 'Poniedziałek7:00' ), "komórki tabel nie zlepiają się (Poniedziałek / 7:00)" );
check( '' === WpContentSource::to_plain( '' ), "pusty HTML → pusty tekst" );

echo "\n=== C. documents() — mapowanie i pomijanie pustych ===\n";
$GLOBALS['__posts'] = array(
	(object) array( 'ID' => 11, 'post_content' => '<p>Treść pierwsza.</p>' ),
	(object) array( 'ID' => 12, 'post_content' => '<!-- wp:gallery -->[gallery ids="9"]<!-- /wp:gallery -->' ), // brak tekstu → pomiń.
	(object) array( 'ID' => 13, 'post_content' => 'Zwykły tekst.' ),
);
$src  = new WpContentSource();
$docs = $src->documents();
check( 2 === count( $docs ), "3 wpisy, 1 bez treści → 2 dokumenty (pusty pominięty)" );
check( 11 === $docs[0]['post_id'] && 13 === $docs[1]['post_id'], "post_id zmapowane, kolejność zachowana" );
check( 'Tytuł 11' === $docs[0]['title'] && 'https://example.test/?p=11' === $docs[0]['url'], "title + url z WP" );
check( 'Treść pierwsza.' === $docs[0]['text'], "text = zwykły tekst bez HTML" );

echo "\n=== PODSUMOWANIE ===\n";
echo ( 0 === $fail ) ? "TEST KROK 5 (ContentSource): WSZYSTKIE ASERCJE OK\n" : "TEST KROK 5 (ContentSource): $fail ASERCJI NIE PRZESZŁO\n";
exit( $fail === 0 ? 0 : 1 );
