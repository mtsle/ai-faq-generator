<?php
/**
 * Testy Kroku 19 — sekcja D: Settings (trzy nowe klucze RAG) + widok ustawień.
 *
 * PISANE W CIEMNO (Etap 4, KONTRAKT k19-v3 §8.3 sekcja D). Autor NIE widział kodu E3a.
 *
 * Powód istnienia tego pliku (§2.7): `rag_thinking_budget` to JEDYNE pole w `Settings.php`
 * z legalną wartością UJEMNĄ. Kopiuj-wklej idiomu `max( 0, … )` z sąsiedniego bloku
 * `rag_max_tokens` po cichu zabija semantykę `-1`, czyli jedyne pokrętło mitygacji z §11.1.
 * Asercja D6 (`-7 → -1`, NIE `0`) jest jedynym strażnikiem tego; sprawdza to mutacja #26.
 *
 * Podłoga pokrycia: >= 14 asercji (§8.1 pkt 7).
 *
 * URUCHOMIENIE:  php -d extension=mbstring tests/krok19-settings-test.php
 * Kod wyjścia: 0 = OK, 1 = błędy.
 *
 * @package AI_FAQ_Generator
 */

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
if ( ! defined( 'OBJECT' ) ) { define( 'OBJECT', 'OBJECT' ); }
if ( ! defined( 'AIFAQ_VERSION' ) ) { define( 'AIFAQ_VERSION', '0.22.0-test' ); }
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
if ( ! defined( 'WEEK_IN_SECONDS' ) ) { define( 'WEEK_IN_SECONDS', 604800 ); }

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code; private $message;
		public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $t ) { return $t instanceof WP_Error; } }

$GLOBALS['__opt']         = array();
$GLOBALS['__filters']     = array();
$GLOBALS['__settings_err'] = 0;

if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return $s; } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return $s; } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $s ) { return $s; } }
if ( ! function_exists( 'esc_url_raw' ) ) { function esc_url_raw( $s ) { return $s; } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $d ) { return json_encode( $d ); } }
if ( ! function_exists( 'wp_unslash' ) ) { function wp_unslash( $s ) { return is_string( $s ) ? stripslashes( $s ) : $s; } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ) { return is_string( $s ) ? trim( $s ) : ''; } }
if ( ! function_exists( 'sanitize_textarea_field' ) ) { function sanitize_textarea_field( $s ) { return trim( (string) $s ); } }
if ( ! function_exists( 'sanitize_key' ) ) { function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $s ) ); } }
if ( ! function_exists( 'sanitize_title' ) ) { function sanitize_title( $s ) { return strtolower( trim( (string) $s ) ); } }
if ( ! function_exists( 'current_time' ) ) { function current_time( $t ) { return '2026-07-20 00:00:00'; } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; } }
if ( ! function_exists( 'get_bloginfo' ) ) { function get_bloginfo( $s = 'name' ) { return 'Witryna'; } }
if ( ! function_exists( 'current_user_can' ) ) { function current_user_can( $c ) { return true; } }

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $k, $default = false ) { return array_key_exists( $k, $GLOBALS['__opt'] ) ? $GLOBALS['__opt'][ $k ] : $default; }
}
if ( ! function_exists( 'update_option' ) ) { function update_option( $k, $v, $a = null ) { $GLOBALS['__opt'][ $k ] = $v; return true; } }
if ( ! function_exists( 'add_option' ) ) {
	function add_option( $k, $v = '', $d = '', $a = 'yes' ) {
		if ( array_key_exists( $k, $GLOBALS['__opt'] ) ) { return false; }
		$GLOBALS['__opt'][ $k ] = $v; return true;
	}
}
if ( ! function_exists( 'delete_option' ) ) { function delete_option( $k ) { unset( $GLOBALS['__opt'][ $k ] ); return true; } }
if ( ! function_exists( 'get_transient' ) ) { function get_transient( $k ) { return false; } }
if ( ! function_exists( 'set_transient' ) ) { function set_transient( $k, $v, $t = 0 ) { return true; } }
if ( ! function_exists( 'delete_transient' ) ) { function delete_transient( $k ) { return true; } }
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) {
		$args = func_get_args(); array_shift( $args );
		if ( isset( $GLOBALS['__filters'][ $tag ] ) ) { return call_user_func_array( $GLOBALS['__filters'][ $tag ], $args ); }
		return $value;
	}
}
// Licznik komunikatów walidacji — §2.7 wymaga CISZY przy zejściu `hard` do granicy (D9).
if ( ! function_exists( 'add_settings_error' ) ) {
	function add_settings_error( $setting, $code, $message, $type = 'error' ) { ++$GLOBALS['__settings_err']; }
}

$fail = 0;
$ran  = 0;
function check( $cond, $label ) {
	global $fail, $ran;
	++$ran;
	echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n";
	if ( ! $cond ) { $fail++; }
}
function approx( $a, $b, $eps = 1e-9 ) { return abs( (float) $a - (float) $b ) < $eps; }

$aifaq_p = __DIR__ . '/../src/Core/Settings.php';
if ( file_exists( $aifaq_p ) ) { require_once $aifaq_p; }
$has_settings = class_exists( 'AIFAQ\Core\Settings' );

/** `sanitize()` bywa metodą instancji (callback WP Settings API) albo statyczną. */
function k19_sanitize( array $input ) {
	$ref = new ReflectionMethod( 'AIFAQ\Core\Settings', 'sanitize' );
	$ref->setAccessible( true );
	$obj = $ref->isStatic() ? null : new \AIFAQ\Core\Settings();
	return (array) $ref->invokeArgs( $obj, array( $input ) );
}
/** Pełne, poprawne wejście — sanitize() waliduje pola krzyżowo, więc nie karmimy go pustką. */
function k19_base_input( array $over = array() ) {
	return array_merge(
		array(
			'api_key' => 'K', 'model' => 'gemini-2.5-flash', 'embed_model' => 'gemini-embedding-001',
			'language' => 'pl', 'page_slug' => 'faqgenerator',
			'rag_threshold' => 0.7, 'rag_top_k' => 5, 'rag_max_tokens' => 500, 'rag_temperature' => 0.2,
			'rag_rate_limit' => 30, 'rag_threshold_hard' => 0.55, 'rag_thinking_budget' => 0,
			'rag_contact_hint' => '',
		),
		$over
	);
}

// ===========================================================================
echo "=== A. defaults() — trzy nowe klucze (D1, D2, D3) ===\n";
// ===========================================================================
if ( $has_settings && method_exists( 'AIFAQ\Core\Settings', 'defaults' ) ) {
	$d = (array) \AIFAQ\Core\Settings::defaults();
	// K19/E6: wartość ZMIERZONA w M-cal2 (0.6484 → 0.65) zastąpiła tymczasowe 0.55.
	// Odchylenie [Etap 6] w ODCHYLENIA.md; źródło: zasoby/bench/bench-058-mcal2.tsv.
	check( array_key_exists( 'rag_threshold_hard', $d ) && approx( 0.65, (float) $d['rag_threshold_hard'] ), 'NOWE — D1: defaults()[rag_threshold_hard] ≈ 0.65 (zmierzone M-cal2)' );
	check( array_key_exists( 'rag_thinking_budget', $d ) && 0 === $d['rag_thinking_budget'] && is_int( $d['rag_thinking_budget'] ), 'NOWE — D2: defaults()[rag_thinking_budget] === 0 ORAZ is_int()' );
	check( array_key_exists( 'rag_contact_hint', $d ) && '' === $d['rag_contact_hint'], 'NOWE — D3: defaults()[rag_contact_hint] === \'\'' );

	// D13 — czego §2.7 NIE zmienia (strażnik trzech zielonych asercji z krok6-settings-rag-test).
	check( 500 === $d['rag_max_tokens'], 'REGRESJA — D13: rag_max_tokens NADAL 500 (podłoga jest w kodzie, nie w defaults)' );
	check( approx( 0.7, (float) $d['rag_threshold'] ), 'REGRESJA — D13: rag_threshold NADAL 0.7' );
	check( 5 === $d['rag_top_k'], 'REGRESJA — D13: rag_top_k NADAL 5' );
} else {
	check( false, 'NOWE — sekcja A pominięta: brak klasy AIFAQ\Core\Settings albo metody defaults()' );
}

// ===========================================================================
echo "\n=== B. sanitize() — clampy progu twardego (D4, D5, D8, D9, D10) ===\n";
// ===========================================================================
if ( $has_settings && method_exists( 'AIFAQ\Core\Settings', 'sanitize' ) ) {
	$GLOBALS['__opt']['aifaq_settings'] = \AIFAQ\Core\Settings::defaults();

	$o = k19_sanitize( k19_base_input( array( 'rag_threshold' => 1.0, 'rag_threshold_hard' => 5.0 ) ) );
	check( approx( 1.0, (float) $o['rag_threshold_hard'] ), 'NOWE — D4: hard = 5.0 → 1.0 (clamp górny; jest: ' . var_export( $o['rag_threshold_hard'], true ) . ')' );

	$o = k19_sanitize( k19_base_input( array( 'rag_threshold_hard' => 0.0 ) ) );
	check( approx( 0.05, (float) $o['rag_threshold_hard'] ), 'NOWE — D4: hard = 0.0 → 0.05 (clamp dolny; jest: ' . var_export( $o['rag_threshold_hard'], true ) . ')' );

	$o = k19_sanitize( k19_base_input( array( 'rag_threshold_hard' => 0.567 ) ) );
	check( approx( 0.57, (float) $o['rag_threshold_hard'] ), 'NOWE — D5: hard = 0.567 → 0.57 (round WEWNĄTRZ min/max, jak u sąsiada)' );

	// D8 / D9 — walidacja krzyżowa: hard > soft schodzi CICHO do granicy.
	$GLOBALS['__settings_err'] = 0;
	$o = k19_sanitize( k19_base_input( array( 'rag_threshold' => 0.7, 'rag_threshold_hard' => 0.9 ) ) );
	check( approx( 0.7, (float) $o['rag_threshold_hard'] ), 'NOWE — D8: hard 0.9 > soft 0.7 → hard ścięty do 0.7 (walidacja krzyżowa)' );
	check( 0 === $GLOBALS['__settings_err'], 'NOWE — D9: zejście do granicy jest CICHE — zero add_settings_error (jest: ' . $GLOBALS['__settings_err'] . ')' );

	// D10 — brak pola w $input nie kasuje wartości ($out = $current).
	$GLOBALS['__opt']['aifaq_settings'] = array_merge( (array) \AIFAQ\Core\Settings::defaults(), array( 'rag_threshold_hard' => 0.45 ) );
	$in = k19_base_input();
	unset( $in['rag_threshold_hard'] );
	$o = k19_sanitize( $in );
	check( array_key_exists( 'rag_threshold_hard', $o ) && approx( 0.45, (float) $o['rag_threshold_hard'] ), 'NOWE — D10: brak pola w $input → klucz obecny i równy poprzedniej wartości (0.45)' );
} else {
	check( false, 'NOWE — sekcja B pominięta: brak metody Settings::sanitize()' );
}

// ===========================================================================
echo "\n=== C. sanitize() — budżet myślenia, zbiór {-1,0} u [128,24576] (D6, D7) ===\n";
// ===========================================================================
if ( $has_settings && method_exists( 'AIFAQ\Core\Settings', 'sanitize' ) ) {
	$GLOBALS['__opt']['aifaq_settings'] = \AIFAQ\Core\Settings::defaults();
	$budgets = array(
		array( 9999, 9999, 'wartość legalna w zakresie — clamp jej NIE rusza' ),
		array( 100000, 24576, 'sufit modeli 2.5' ),
		array( 100, 128, 'API odrzuca 1..127 → podciągnięcie do GRANICY, nie do bieżącej' ),
		array( -7, -1, 'myślenie DYNAMICZNE — jedyne pole z legalną wartością ujemną (mutacja #26)' ),
	);
	foreach ( $budgets as $b ) {
		$o = k19_sanitize( k19_base_input( array( 'rag_thinking_budget' => $b[0] ) ) );
		check( $b[1] === $o['rag_thinking_budget'], 'NOWE — D6: budżet ' . $b[0] . ' → ' . $b[1] . ' (' . $b[2] . '; jest: ' . var_export( $o['rag_thinking_budget'], true ) . ')' );
	}
	$o = k19_sanitize( k19_base_input( array( 'rag_thinking_budget' => 'abc' ) ) );
	check( 0 === $o['rag_thinking_budget'], 'NOWE — D7: budżet \'abc\' → 0, brak fatala' );
} else {
	check( false, 'NOWE — sekcja C pominięta: brak metody Settings::sanitize()' );
}

// ===========================================================================
echo "\n=== D. get() bez migracji + przycięcie podpowiedzi kontaktowej (D11, D12) ===\n";
// ===========================================================================
if ( $has_settings && method_exists( 'AIFAQ\Core\Settings', 'get' ) ) {
	// Instalacja SPRZED K19: zapisane ustawienia bez trzech nowych kluczy.
	$GLOBALS['__opt']['aifaq_settings'] = array(
		'api_key' => 'STARY', 'model' => 'gemini-2.5-flash', 'language' => 'pl',
		'rag_threshold' => 0.35, 'rag_top_k' => 5, 'rag_max_tokens' => 500,
	);
	$g = (array) \AIFAQ\Core\Settings::get();
	check( array_key_exists( 'rag_threshold_hard', $g ) && approx( 0.65, (float) $g['rag_threshold_hard'] ), 'NOWE — D11: rag_threshold_hard wstrzyknięty z defaults() BEZ migracji (0.65 — zmierzone M-cal2)' );
	check( array_key_exists( 'rag_thinking_budget', $g ) && 0 === $g['rag_thinking_budget'], 'NOWE — D11: rag_thinking_budget wstrzyknięty z defaults()' );
	check( array_key_exists( 'rag_contact_hint', $g ) && '' === $g['rag_contact_hint'], 'NOWE — D11: rag_contact_hint wstrzyknięty z defaults()' );
	check( approx( 0.35, (float) $g['rag_threshold'] ), 'REGRESJA — D11: zapisana wartość rag_threshold=0.35 NIE jest nadpisywana domyślną' );
} else {
	check( false, 'NOWE — D11 pominięta: brak metody Settings::get()' );
}

if ( $has_settings && method_exists( 'AIFAQ\Core\Settings', 'sanitize' ) ) {
	$GLOBALS['__opt']['aifaq_settings'] = \AIFAQ\Core\Settings::defaults();
	$long = str_repeat( 'a', 200 );
	$o    = k19_sanitize( k19_base_input( array( 'rag_contact_hint' => $long ) ) );
	check( 120 === mb_strlen( (string) $o['rag_contact_hint'] ), 'NOWE — D12: rag_contact_hint > 120 znaków → przycięty do 120 (jest: ' . mb_strlen( (string) $o['rag_contact_hint'] ) . ')' );
} else {
	check( false, 'NOWE — D12 pominięta: brak metody Settings::sanitize()' );
}

// ===========================================================================
echo "\n=== E. Widok ustawień — trzy nowe pola (D14) ===\n";
// ===========================================================================
$view = __DIR__ . '/../src/Admin/views/settings.php';
if ( file_exists( $view ) ) {
	$src = (string) file_get_contents( $view );
	foreach ( array( 'rag_threshold_hard', 'rag_thinking_budget', 'rag_contact_hint' ) as $field ) {
		$lit = 'name="aifaq_settings[' . $field . ']"';
		check( 1 === substr_count( $src, $lit ), 'NOWE — D14: widok zawiera ' . $lit . ' DOKŁADNIE raz (jest: ' . substr_count( $src, $lit ) . ')' );
	}
} else {
	check( false, 'NOWE — D14 pominięta: brak pliku src/Admin/views/settings.php' );
}

// ===========================================================================
echo "\n=== Z. Podłoga pokrycia i wartownik ===\n";
// ===========================================================================
$floor = $ran;
check( $floor >= 14, 'NOWE — wykonano co najmniej 14 asercji (było ' . $floor . ')' );

echo "\nplik dobiegł końca\n";
echo 'Asercje: ' . $ran . ', niezaliczone: ' . $fail . "\n";
exit( 0 === $fail ? 0 : 1 );
