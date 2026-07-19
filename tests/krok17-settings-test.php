<?php
/**
 * Testy Kroku 17 — ustawienia kaskady źródeł (KONTRAKT k17-v3, §5.1).
 *
 * SEDNO TEGO PLIKU — „pułapka checkboxa”, znalezisko krytyka oznaczone jako poważne:
 * `Settings::sanitize()` nadpisuje klucz TYLKO pod `isset()`, a
 * `RestController::handle_settings_save()` przesyła zaledwie 4 pola
 * (`api_key`, `model`, `temperature`, `language`). Naturalny idiom
 * `! empty( $input['crawl_enabled'] )` sprawiłby, że **zapisanie klucza API w panelu
 * na froncie wyłącza crawl**, a następny reindeks wypatroszyłby bazę wiedzy —
 * bez jednego kliknięcia przez użytkownika.
 *
 * Drugi wymóg §5.1: wszystkie 4 nowe klucze MUSZĄ być w `defaults()`, nie tylko
 * w `sanitize()`. Bez tego `get_field()` zwraca `null` na świeżej instalacji
 * i crawl jest domyślnie wyłączony wbrew kontraktowi.
 *
 * URUCHOMIENIE:  php tests/krok17-settings-test.php
 * Kod wyjścia: 0 = OK, 1 = błędy.
 *
 * @package AI_FAQ_Generator
 */

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }

if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'wp_unslash' ) ) { function wp_unslash( $s ) { return is_string( $s ) ? stripslashes( $s ) : $s; } }
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $s ) { return trim( (string) preg_replace( '/[\r\n\t]+/', ' ', (string) preg_replace( '/<[^>]*>/', '', (string) $s ) ) ); }
}
if ( ! function_exists( 'sanitize_textarea_field' ) ) { function sanitize_textarea_field( $s ) { return trim( (string) preg_replace( '/<[^>]*>/', '', (string) $s ) ); } }
if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $s ) { $s = strtolower( trim( (string) $s ) ); $s = preg_replace( '/[^a-z0-9]+/', '-', $s ); return trim( (string) $s, '-' ); }
}
if ( ! function_exists( 'get_option' ) ) { function get_option( $k, $d = false ) { global $__aifaq_options; return $__aifaq_options[ $k ] ?? $d; } }
if ( ! function_exists( 'update_option' ) ) { function update_option( $k, $v, $a = null ) { global $__aifaq_options; $__aifaq_options[ $k ] = $v; return true; } }
if ( ! function_exists( 'delete_option' ) ) { function delete_option( $k ) { global $__aifaq_options; unset( $__aifaq_options[ $k ] ); return true; } }
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $h, $v = null, ...$a ) { return $v; } }

$fail = 0;
$ran  = 0;
function check( $cond, $label ) {
	global $fail, $ran;
	++$ran;
	echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n";
	if ( ! $cond ) { ++$fail; }
}

require_once __DIR__ . '/../src/Core/Settings.php';
$S = 'AIFAQ\Core\Settings';

$GLOBALS['__aifaq_options'] = array();

echo "=== A. Cztery nowe klucze SĄ w defaults() (§5.1) ===\n";
if ( class_exists( $S ) && method_exists( $S, 'defaults' ) ) {
	$d = $S::defaults();
	check( array_key_exists( 'crawl_enabled', $d ), 'defaults() zawiera crawl_enabled' );
	check( array_key_exists( 'crawl_exclude', $d ), 'defaults() zawiera crawl_exclude' );
	check( array_key_exists( 'meta_keys', $d ), 'defaults() zawiera meta_keys' );
	check( array_key_exists( 'meta_post_types', $d ), 'defaults() zawiera meta_post_types' );

	check( '1' === (string) $d['crawl_enabled'], "domyślnie crawl_enabled === '1' (string, nie bool/int)" );
	check( '' === (string) $d['crawl_exclude'], "domyślnie crawl_exclude === ''" );
	check( '' === (string) $d['meta_keys'], "domyślnie meta_keys === ''" );
	check( 'post,page' === (string) $d['meta_post_types'], "domyślnie meta_post_types === 'post,page' (wąsko, §1 pkt 5)" );
} else {
	check( false, 'sekcja A pominięta — brak Settings::defaults()' );
}

echo "\n=== B. PUŁAPKA CHECKBOXA: częściowy zapis NIE wyłącza crawla (§5.1) ===\n";
if ( class_exists( $S ) && method_exists( $S, 'sanitize' ) ) {
	$s = new $S();

	// Stan wyjściowy: crawl włączony (jak po instalacji).
	$GLOBALS['__aifaq_options'][ constant( $S . '::OPTION' ) ] = array_merge(
		$S::defaults(),
		array( 'crawl_enabled' => '1', 'crawl_exclude' => 'koszyk', 'meta_keys' => 'bio_kadry' )
	);

	// Dokładnie to, co wysyła RestController::handle_settings_save() z panelu na froncie.
	$out = $s->sanitize( array( 'api_key' => 'AIza-test', 'model' => 'gemini-2.5-flash', 'temperature' => '0.4', 'language' => 'pl' ) );

	check( '1' === (string) $out['crawl_enabled'], 'zapis klucza API z frontu NIE wyłącza crawla (nadal 1)' );
	check( 'koszyk' === (string) $out['crawl_exclude'], 'crawl_exclude nietknięty przez częściowy zapis' );
	check( 'bio_kadry' === (string) $out['meta_keys'], 'meta_keys nietknięte przez częściowy zapis' );
	check( 'post,page' === (string) $out['meta_post_types'], 'meta_post_types nietknięte przez częściowy zapis' );
	check( 'gemini-2.5-flash' === (string) $out['model'], 'przysłane pole faktycznie zapisane (para pozytywna)' );

	// Para POZYTYWNA dla samego wyłącznika: pełny formularz z ukrytym inputem value="0".
	$out = $s->sanitize( array( 'crawl_enabled' => '0' ) );
	check( '0' === (string) $out['crawl_enabled'], "jawne '0' z ukrytego inputa FAKTYCZNIE wyłącza crawl" );

	$out = $s->sanitize( array( 'crawl_enabled' => '1' ) );
	check( '1' === (string) $out['crawl_enabled'], "jawne '1' (zaznaczony checkbox) włącza crawl" );

	// Wartość spoza zbioru nie może wpuścić śmiecia.
	$out = $s->sanitize( array( 'crawl_enabled' => 'tak-prosze' ) );
	check( '0' === (string) $out['crawl_enabled'], "wartość spoza {'0','1'} normalizowana do '0'" );
} else {
	check( false, 'sekcja B pominięta — brak Settings::sanitize()' );
}

echo "\n=== C. meta_post_types: pusty submit wraca do 'post,page' ===\n";
if ( class_exists( $S ) && method_exists( $S, 'sanitize' ) ) {
	$s   = new $S();
	$out = $s->sanitize( array( 'meta_post_types' => '   ' ) );
	// Pusty ciąg dałby pustą listę typów po parsowaniu z §4 → źródło postmeta
	// nie czytałoby NICZEGO, a wyglądałoby to jak działający, tylko uboższy indeks.
	check( 'post,page' === (string) $out['meta_post_types'], "pusty submit typów wraca do 'post,page', nie do ''" );

	$out = $s->sanitize( array( 'meta_post_types' => 'post,page,kadra' ) );
	check( 'post,page,kadra' === (string) $out['meta_post_types'], 'jawna lista typów zapisana bez zmian' );
} else {
	check( false, 'sekcja C pominięta' );
}

echo "\n=== D. crawl_exclude i meta_keys: pusty submit ZOSTAJE pusty ===\n";
if ( class_exists( $S ) && method_exists( $S, 'sanitize' ) ) {
	$s = new $S();
	// Tu pustka ma sens: „nic nie wykluczaj” / „tylko klucze domyślne”.
	$out = $s->sanitize( array( 'crawl_exclude' => '' ) );
	check( '' === (string) $out['crawl_exclude'], "pusty crawl_exclude zostaje pusty (znaczy 'nic nie wykluczaj')" );

	$out = $s->sanitize( array( 'meta_keys' => '' ) );
	check( '' === (string) $out['meta_keys'], "pusty meta_keys zostaje pusty (znaczy 'tylko domyślne')" );

	$out = $s->sanitize( array( 'crawl_exclude' => ' koszyk , moje-konto ' ) );
	check( false !== strpos( (string) $out['crawl_exclude'], 'koszyk' ), 'lista wykluczeń zapisana' );
} else {
	check( false, 'sekcja D pominięta' );
}

echo "\n=== PODŁOGA ASERCJI ===\n";
check( $ran >= 18, "wykonano co najmniej 18 asercji (było: $ran)" );

echo "\n=== PODSUMOWANIE ===\n";
if ( $fail > 0 ) {
	echo "TEST KROK 17 (ustawienia kaskady): $fail ASERCJI NIE PRZESZŁO (z $ran)\n";
	exit( 1 );
}
echo "TEST KROK 17 (ustawienia kaskady): WSZYSTKIE ASERCJE OK ($ran)\n";
exit( 0 );
