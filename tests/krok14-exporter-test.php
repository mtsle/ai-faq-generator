<?php
/**
 * Testy Exportera FAQ (Krok 14) — czysta klasa, bez sieci i bez WP.
 *
 * Pokrywa kontrakt {@see \AIFAQ\Faq\Exporter::export()}: pięć formatów, każdy
 * niosący pytania/odpowiedzi, poprawność JSON/JSON-LD (parsowalne, właściwe typy
 * Schema.org), escapowanie w HTML/Gutenberg, normalizację par (skalarne/niepuste,
 * cap) oraz zachowanie na pustej liście (wyjątek — REST i tak waliduje wejście).
 *
 * URUCHOMIENIE:  php tests/krok14-exporter-test.php
 * Kod wyjścia: 0 = OK, 1 = błędy.
 *
 * @package AI_FAQ_Generator
 */

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }

// --- shimy WP potrzebne Exporterowi ---
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $d, $flags = 0, $depth = 512 ) { return json_encode( $d, $flags, $depth ); }
}

require __DIR__ . '/../src/Faq/Exporter.php';

use AIFAQ\Faq\Exporter;

$fail = 0;
function check( $cond, $label ) { global $fail; echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n"; if ( ! $cond ) { $fail++; } }

$exp   = new Exporter();
$pairs = array(
	array( 'question' => 'Ile mleka daje krowa?', 'answer' => 'Około 25 litrów dziennie.' ),
	array( 'question' => 'Czy 3 < 5 i "cudzysłów" & znak?', 'answer' => 'Tak: 3 < 5 & <b>pogrubienie</b>.' ),
);

$out = $exp->export( $pairs );

// ===========================================================================
echo "=== A. Kształt wyniku ===\n";
foreach ( array( 'html', 'gutenberg', 'elementor', 'json', 'jsonld' ) as $k ) {
	check( isset( $out[ $k ] ) && is_string( $out[ $k ] ), "format '$k' obecny i jest stringiem" );
}
check( 5 === count( $out ), 'dokładnie 5 formatów' );

echo "\n=== B. HTML — treść + escapowanie ===\n";
check( false !== strpos( $out['html'], 'Ile mleka daje krowa?' ), 'HTML zawiera pytanie' );
check( false !== strpos( $out['html'], '<details' ) && false !== strpos( $out['html'], '<summary' ), 'HTML ma <details>/<summary>' );
check( false !== strpos( $out['html'], '3 &lt; 5' ), 'HTML escapuje < (&lt;)' );
check( false !== strpos( $out['html'], '&amp;' ), 'HTML escapuje & (&amp;)' );
check( false === strpos( $out['html'], '<b>pogrubienie</b>' ), 'HTML NIE wpuszcza surowego <b> z odpowiedzi (anti-XSS)' );

echo "\n=== C. Gutenberg — komentarze bloków + escapowanie ===\n";
check( false !== strpos( $out['gutenberg'], '<!-- wp:heading {"level":3} -->' ), 'Gutenberg ma blok heading level 3' );
check( false !== strpos( $out['gutenberg'], '<!-- wp:paragraph -->' ), 'Gutenberg ma blok paragraph' );
check( false !== strpos( $out['gutenberg'], '<h3>Ile mleka daje krowa?</h3>' ), 'Gutenberg zawiera pytanie w <h3>' );
check( false !== strpos( $out['gutenberg'], '3 &lt; 5' ), 'Gutenberg escapuje treść' );

echo "\n=== D. JSON — parsowalny, lista par ===\n";
$json = json_decode( $out['json'], true );
check( is_array( $json ) && 2 === count( $json ), 'JSON parsuje się do listy 2 par' );
check( isset( $json[0]['question'], $json[0]['answer'] ), 'JSON: para ma question+answer' );
check( 'Około 25 litrów dziennie.' === ( $json[0]['answer'] ?? '' ), 'JSON: odpowiedź nietknięta (bez HTML-escape)' );
check( false !== strpos( $out['json'], "\n" ), 'JSON jest pretty-printed (wieloliniowy)' );

echo "\n=== E. JSON-LD — poprawny Schema.org FAQPage ===\n";
$ld = json_decode( $out['jsonld'], true );
check( is_array( $ld ), 'JSON-LD parsuje się' );
check( 'https://schema.org' === ( $ld['@context'] ?? '' ), '@context = schema.org' );
check( 'FAQPage' === ( $ld['@type'] ?? '' ), '@type = FAQPage' );
check( isset( $ld['mainEntity'] ) && 2 === count( $ld['mainEntity'] ), 'mainEntity ma 2 wpisy' );
$q0 = $ld['mainEntity'][0] ?? array();
check( 'Question' === ( $q0['@type'] ?? '' ), 'wpis to @type Question' );
check( 'Ile mleka daje krowa?' === ( $q0['name'] ?? '' ), 'Question.name = pytanie' );
check( 'Answer' === ( $q0['acceptedAnswer']['@type'] ?? '' ), 'acceptedAnswer to @type Answer' );
check( isset( $q0['acceptedAnswer']['text'] ) && '' !== $q0['acceptedAnswer']['text'], 'Answer.text niepusty' );

echo "\n=== F. Elementor — poprawny JSON z parami w środku ===\n";
$el = json_decode( $out['elementor'], true );
check( is_array( $el ), 'Elementor parsuje się do tablicy' );
check( isset( $el['content'] ) && is_array( $el['content'] ), 'Elementor ma content[]' );
check( false !== strpos( $out['elementor'], 'accordion' ), 'Elementor używa widgetu accordion' );
check( false !== strpos( $out['elementor'], 'Ile mleka daje krowa?' ), 'Elementor zawiera pytanie' );
check( false !== strpos( $out['elementor'], 'Około 25 litrów dziennie.' ), 'Elementor zawiera odpowiedź' );

echo "\n=== G. Normalizacja par ===\n";
$mixed = array(
	array( 'question' => '  Pytanie z odstępami  ', 'answer' => '  Odpowiedź  ' ),
	array( 'question' => '', 'answer' => 'brak pytania' ),          // odrzucone (puste Q)
	array( 'question' => 'brak odpowiedzi', 'answer' => '' ),        // odrzucone (puste A)
	array( 'question' => array( 'x' ), 'answer' => 'tablica' ),      // odrzucone (nieskalarne)
	array( 'answer' => 'brak klucza question' ),                     // odrzucone (brak Q)
	'nie-tablica',                                                   // odrzucone (nie para)
);
$outN = $exp->export( $mixed );
$jsonN = json_decode( $outN['json'], true );
check( is_array( $jsonN ) && 1 === count( $jsonN ), 'normalizacja: została tylko 1 poprawna para' );
check( 'Pytanie z odstępami' === ( $jsonN[0]['question'] ?? '' ), 'normalizacja: przycięte białe znaki' );

echo "\n=== H. Pusta lista → wyjątek ===\n";
$threw = false;
try {
	$exp->export( array() );
} catch ( \InvalidArgumentException $e ) {
	$threw = true;
}
check( $threw, 'pusta lista par → InvalidArgumentException' );

$threw2 = false;
try {
	$exp->export( array( array( 'question' => '', 'answer' => '' ) ) ); // wszystko odrzucone
} catch ( \InvalidArgumentException $e ) {
	$threw2 = true;
}
check( $threw2, 'same niepoprawne pary → InvalidArgumentException' );

echo "\n=== I. Cap MAX_PAIRS ===\n";
$many = array();
for ( $i = 0; $i < 60; $i++ ) {
	$many[] = array( 'question' => "Q$i", 'answer' => "A$i" );
}
$jsonMany = json_decode( $exp->export( $many )['json'], true );
check( Exporter::MAX_PAIRS === count( $jsonMany ), 'cap: nie więcej niż MAX_PAIRS par w eksporcie' );

echo "\n=== PODSUMOWANIE ===\n";
echo ( 0 === $fail ) ? "TEST KROK 14 (exporter): WSZYSTKIE ASERCJE OK\n" : "TEST KROK 14 (exporter): $fail ASERCJI NIE PRZESZŁO\n";
exit( $fail === 0 ? 0 : 1 );
