<?php
/**
 * Test round-trip clampów RAG (Dział 3 „RAG Config", Krok 6).
 *
 * Sprawdza GC2 (twardy zakres + bezpieczny default) i GC1 (M11 — pusty submit
 * odmowy ZOSTAWIA bieżący tekst) dla nowych kluczy konfiguracji RAG w
 * AIFAQ\Core\Settings::sanitize()/defaults().
 *
 * URUCHOMIENIE:  php tests/krok6-settings-rag-test.php
 * Kod wyjścia: 0 = OK, 1 = błędy.
 *
 * @package AI_FAQ_Generator
 */

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }

// --- shimy WP ---
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'wp_unslash' ) ) { function wp_unslash( $s ) { return is_string( $s ) ? stripslashes( $s ) : $s; } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ) { return trim( (string) preg_replace( '/[\r\n\t]+/', ' ', (string) preg_replace( '/<[^>]*>/', '', (string) $s ) ) ); } }
if ( ! function_exists( 'sanitize_textarea_field' ) ) { function sanitize_textarea_field( $s ) { return trim( (string) preg_replace( '/<[^>]*>/', '', (string) $s ) ); } }
if ( ! function_exists( 'sanitize_title' ) ) { function sanitize_title( $s ) { $s = strtolower( trim( (string) $s ) ); $s = preg_replace( '/[^a-z0-9]+/', '-', $s ); return trim( (string) $s, '-' ); } }
if ( ! function_exists( 'get_option' ) ) { function get_option( $k, $d = false ) { global $__aifaq_options; return $__aifaq_options[ $k ] ?? $d; } }
if ( ! function_exists( 'update_option' ) ) { function update_option( $k, $v, $a = null ) { global $__aifaq_options; $__aifaq_options[ $k ] = $v; return true; } }

$fail = 0;
function check( $cond, $label ) {
	global $fail;
	echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n";
	if ( ! $cond ) { $fail++; }
}

require __DIR__ . '/../src/Core/Settings.php';
use AIFAQ\Core\Settings;

echo "=== Dział 3: defaults() ma 11 kluczy RAG z bezpiecznymi wartościami ===\n";
$d = Settings::defaults();
check( 0.7 === $d['rag_threshold'], 'default rag_threshold = 0.7' );
check( 5 === $d['rag_top_k'], 'default rag_top_k = 5' );
check( 30 === $d['rag_rate_limit'], 'default rag_rate_limit = 30' );
check( 0.2 === $d['rag_temperature'], 'default rag_temperature = 0.2' );
check( 500 === $d['rag_max_tokens'], 'default rag_max_tokens = 500' );
check( '' !== $d['rag_refusal_message_pl'] && '' !== $d['rag_refusal_message_en'] && '' !== $d['rag_refusal_message_de'], 'defaulty odmów pl/en/de niepuste' );

echo "\n=== Dział 3: sanitize() clampuje do zakresu (GC2, fail-safe) ===\n";
global $__aifaq_options;
$__aifaq_options = array();

$o = Settings::sanitize( array(
	'rag_threshold'  => 5.0,   // ponad zakres → 1.0
	'rag_top_k'      => 99,    // ponad → 10
	'rag_rate_limit' => -4,    // poniżej → 0
	'rag_temperature'=> 2.5,   // ponad → 1.0
	'rag_max_tokens' => 10,    // poniżej → 64
) );
check( 1.0 === $o['rag_threshold'], 'rag_threshold clamp górny → 1.0' );
check( 10 === $o['rag_top_k'], 'rag_top_k clamp górny → 10' );
check( 0 === $o['rag_rate_limit'], 'rag_rate_limit clamp dolny → 0' );
check( 1.0 === $o['rag_temperature'], 'rag_temperature clamp górny → 1.0' );
check( 64 === $o['rag_max_tokens'], 'rag_max_tokens clamp dolny → 64' );

$o2 = Settings::sanitize( array(
	'rag_threshold'  => 0.55,
	'rag_top_k'      => 3,
	'rag_rate_limit' => 15,
	'rag_temperature'=> 0.3,
	'rag_max_tokens' => 800,
) );
check( 0.55 === $o2['rag_threshold'], 'rag_threshold w zakresie zachowany' );
check( 3 === $o2['rag_top_k'], 'rag_top_k w zakresie zachowany' );
check( 800 === $o2['rag_max_tokens'], 'rag_max_tokens w zakresie zachowany' );

echo "\n=== Dział 3: odmowa — pusty submit ZOSTAWIA bieżący tekst (GC1/M11) ===\n";
$__aifaq_options = array( Settings::OPTION => array( 'rag_refusal_message_pl' => 'STARY PL' ) );
$o3 = Settings::sanitize( array( 'rag_refusal_message_pl' => '' ) );
check( 'STARY PL' === $o3['rag_refusal_message_pl'], 'pusty submit odmowy → zachowany bieżący' );
$o4 = Settings::sanitize( array( 'rag_refusal_message_pl' => 'NOWY <b>PL</b>' ) );
check( 'NOWY PL' === $o4['rag_refusal_message_pl'], 'niepusty submit odmowy → zmieniony + sanityzowany (tagi usunięte)' );

echo "\n=== Dział 3: istniejące klucze nietknięte (GC1) ===\n";
$__aifaq_options = array();
$o5 = Settings::sanitize( array( 'temperature' => 0.5 ) );
check( 0.5 === $o5['temperature'], 'temperature (FAQ) nadal działa' );
check( 0.2 === $o5['rag_temperature'], 'rag_temperature osobny od temperature — default zachowany' );
check( 'gemini' === $o5['provider'], 'provider nietknięty' );

echo "\n" . ( 0 === $fail ? "WSZYSTKIE OK" : "BŁĘDY: $fail" ) . "\n";
exit( 0 === $fail ? 0 : 1 );
