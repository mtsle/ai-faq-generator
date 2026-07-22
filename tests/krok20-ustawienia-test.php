<?php
/**
 * Testy Kroku 20 — ustawienia: osiem nowych kluczy, clampy, idiom `isset()`,
 * whitelista modeli (H1) i podłoga progu dopasowania tematu (H2).
 *
 * NAPISANE W CIEMNO wyłącznie z `plany/krok20/KONTRAKT.md` (wersja k20-v3) oraz
 * `plany/krok20/ODCHYLENIA.md`, bez zaglądania w `src/Core/Settings.php`
 * i `src/Admin/views/settings.php`. Rozbieżność test <-> implementacja jest dowodem,
 * że kontrakt był nieprecyzyjny — idzie do `ODCHYLENIA.md`, nie do cichej korekty asercji.
 *
 * Pokrycie: §2 (tabela ośmiu kluczy + clampy), §2.0/FZ1 (checkboxy POD `isset()`),
 * §2.4 (H1 — relabel, klucze nietknięte), §2.5 (H2 — podłoga w `sanitize()` ORAZ w `get()`),
 * §2.6 (kontrolki w widoku), §13.1, §13.6, §13.15, §13.16.
 *
 * URUCHOMIENIE:  php tests/krok20-ustawienia-test.php
 * Kod wyjścia: 0 = OK, 1 = błędy.
 *
 * @package AI_FAQ_Generator
 */

// ---------------------------------------------------------------------------
// PREAMBUŁA — stałe PRZED pierwszym `require`.
// `AIFAQ_TESTING` CELOWO NIEZDEFINIOWANE: żaden szew tego pliku go nie potrzebuje,
// a §7.4 wiąże tę stałą z wyłączaniem sufitu — nie chcemy jej ubocznego wpływu.
// ---------------------------------------------------------------------------
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
if ( ! defined( 'AIFAQ_VERSION' ) ) { define( 'AIFAQ_VERSION', '0.0.0-test' ); }
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }

$GLOBALS['__opt']      = array();
$GLOBALS['__autoload'] = array();
$GLOBALS['__cnt']      = array( 'update_option' => 0, 'delete_option' => 0, 'add_settings_error' => 0 );

// Łapacz warningów — Fatal/notice w `sanitize()` w czystym CLI to wada (§13.6).
$GLOBALS['aifaq_warnings'] = 0;
set_error_handler(
	function ( $errno, $errstr ) {
		$GLOBALS['aifaq_warnings']++;
		echo "  [PHP WARNING] $errstr\n";
		return true;
	},
	E_WARNING | E_NOTICE | E_DEPRECATED
);

// ---------------------------------------------------------------------------
// Shimy WP — wszystkie PRZED `require`.
//
// UWAGA, TO JEST ŚWIADOMY WYBÓR: `get_registered_nav_menus()` NIE jest tu shimowane.
// §13.6 wymaga, by blok `menu_location` w `sanitize()` był osłonięty
// `function_exists( 'get_registered_nav_menus' )` — brak osłony to Fatal, nie FAIL.
// Funkcję definiujemy DOPIERO w sekcji B2, w czasie wykonania.
// ---------------------------------------------------------------------------
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $msg;
		public function __construct( $c = '', $m = '' ) { $this->msg = (string) $m; }
		public function get_error_message() { return $this->msg; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $t ) { return $t instanceof WP_Error; } }
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( '_e' ) ) { function _e( $s, $d = null ) { echo $s; } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $s, $d = null ) { return (string) $s; } }
if ( ! function_exists( 'esc_html_e' ) ) { function esc_html_e( $s, $d = null ) { echo (string) $s; } }
if ( ! function_exists( 'esc_attr__' ) ) { function esc_attr__( $s, $d = null ) { return (string) $s; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $s ) { return (string) $s; } }
if ( ! function_exists( 'esc_url_raw' ) ) { function esc_url_raw( $s ) { return (string) $s; } }
if ( ! function_exists( 'esc_textarea' ) ) { function esc_textarea( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'wp_kses_post' ) ) { function wp_kses_post( $s ) { return (string) $s; } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $p = '' ) { return 'http://test.local/wp-admin/' . $p; } }
if ( ! function_exists( 'home_url' ) ) { function home_url( $p = '/' ) { return 'http://test.local' . $p; } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $d, $o = 0 ) { return json_encode( $d, $o ); } }
if ( ! function_exists( 'wp_unslash' ) ) { function wp_unslash( $s ) { return is_string( $s ) ? stripslashes( $s ) : $s; } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ) { return trim( preg_replace( '/[\r\n\t]+/', ' ', strip_tags( (string) $s ) ) ); } }
if ( ! function_exists( 'sanitize_textarea_field' ) ) { function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); } }
if ( ! function_exists( 'sanitize_key' ) ) { function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $s ) ); } }
if ( ! function_exists( 'sanitize_title' ) ) { function sanitize_title( $s ) { return strtolower( trim( preg_replace( '/[^A-Za-z0-9\-_]+/', '-', (string) $s ), '-' ) ); } }
if ( ! function_exists( 'absint' ) ) { function absint( $n ) { return abs( (int) $n ); } }
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $h, $v = null, ...$a ) { return $v; } }
if ( ! function_exists( 'add_filter' ) ) { function add_filter( $h, $cb, $p = 10, $n = 1 ) { return true; } }
if ( ! function_exists( 'add_action' ) ) { function add_action( $h, $cb, $p = 10, $n = 1 ) { return true; } }
if ( ! function_exists( 'do_action' ) ) { function do_action( $h, ...$a ) { return null; } }
if ( ! function_exists( 'is_admin' ) ) { function is_admin() { return true; } }
if ( ! function_exists( 'current_user_can' ) ) { function current_user_can( $c ) { return true; } }
if ( ! function_exists( 'add_settings_error' ) ) { function add_settings_error( $s, $c, $m, $t = 'error' ) { $GLOBALS['__cnt']['add_settings_error']++; } }
if ( ! function_exists( 'checked' ) ) { function checked( $a, $b = true, $e = true ) { $r = ( (string) $a === (string) $b ) ? ' checked="checked"' : ''; if ( $e ) { echo $r; } return $r; } }
if ( ! function_exists( 'selected' ) ) { function selected( $a, $b = true, $e = true ) { $r = ( (string) $a === (string) $b ) ? ' selected="selected"' : ''; if ( $e ) { echo $r; } return $r; } }
if ( ! function_exists( 'number_format_i18n' ) ) { function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, (int) $d ); } }

// --- opcje ---
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__opt'] ) ? $GLOBALS['__opt'][ $k ] : $d; }
}
if ( ! function_exists( 'add_option' ) ) {
	function add_option( $k, $v = '', $dep = '', $autoload = 'yes' ) {
		if ( array_key_exists( $k, $GLOBALS['__opt'] ) ) { return false; }
		$GLOBALS['__opt'][ $k ]      = $v;
		$GLOBALS['__autoload'][ $k ] = $autoload;
		return true;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $k, $v, $autoload = null ) {
		$GLOBALS['__cnt']['update_option']++;
		$GLOBALS['__opt'][ $k ] = $v;
		if ( null !== $autoload ) { $GLOBALS['__autoload'][ $k ] = ( false === $autoload ? 'no' : ( true === $autoload ? 'yes' : $autoload ) ); }
		return true;
	}
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $k ) { $GLOBALS['__cnt']['delete_option']++; unset( $GLOBALS['__opt'][ $k ], $GLOBALS['__autoload'][ $k ] ); return true; }
}

// --- cron (E3 dotyka `on_settings_updated()`, które w gałęzi crawla planuje zadania) ---
if ( ! function_exists( 'wp_next_scheduled' ) ) { function wp_next_scheduled( $h, $a = array() ) { return false; } }
if ( ! function_exists( 'wp_schedule_event' ) ) { function wp_schedule_event( $t, $r, $h, $a = array() ) { return true; } }
if ( ! function_exists( 'wp_schedule_single_event' ) ) { function wp_schedule_single_event( $t, $h, $a = array() ) { return true; } }
if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) { function wp_clear_scheduled_hook( $h, $a = array() ) { return 0; } }
if ( ! function_exists( 'wp_unschedule_hook' ) ) { function wp_unschedule_hook( $h ) { return 0; } }
if ( ! function_exists( 'wp_next_scheduled_hook' ) ) { function wp_next_scheduled_hook( $h ) { return false; } }
if ( ! function_exists( 'delete_transient' ) ) { function delete_transient( $k ) { unset( $GLOBALS['__opt'][ '_t_' . $k ] ); return true; } }
if ( ! function_exists( 'get_transient' ) ) { function get_transient( $k ) { return $GLOBALS['__opt'][ '_t_' . $k ] ?? false; } }
if ( ! function_exists( 'set_transient' ) ) { function set_transient( $k, $v, $t = 0 ) { $GLOBALS['__opt'][ '_t_' . $k ] = $v; return true; } }
if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type = 'mysql', $gmt = 0 ) {
		if ( 'timestamp' === $type || 'U' === $type ) { return time(); }
		if ( 'mysql' === $type ) { return date( 'Y-m-d H:i:s' ); }
		return date( (string) $type );
	}
}

if ( ! class_exists( 'FakeWpdb' ) ) {
	class FakeWpdb {
		public $prefix = 'wp_';
		public function prepare( $q, ...$a ) { return $q; }
		public function get_row( $q, $o = null ) { return null; }
		public function get_var( $q ) { return 0; }
		public function get_results( $q, $o = null ) { return array(); }
		public function query( $q ) { return 0; }
	}
}
$GLOBALS['wpdb'] = new FakeWpdb();

// ---------------------------------------------------------------------------
// Harness.
// ---------------------------------------------------------------------------
$fail = 0;
$ran  = 0;
function check( $cond, $label ) {
	global $fail, $ran;
	$ran++;
	echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n";
	if ( ! $cond ) { $fail++; }
}
function skip( $n, $label ) { for ( $i = 0; $i < $n; $i++ ) { check( false, $label ); } }
function nearly( $a, $b ) { return abs( (float) $a - (float) $b ) < 0.0001; }

// ---------------------------------------------------------------------------
// Ładowanie kodu — ręcznie, bez autoloadera.
// ---------------------------------------------------------------------------
$aifaq_p = __DIR__ . '/../src/Core/Settings.php';
if ( file_exists( $aifaq_p ) ) { require_once $aifaq_p; }
$has_set = class_exists( 'AIFAQ\Core\Settings' );
$SET     = 'AIFAQ\Core\Settings';
$OPTKEY  = ( $has_set && defined( $SET . '::OPTION' ) ) ? constant( $SET . '::OPTION' ) : 'aifaq_settings';

/** Wywołuje metodę Settings niezależnie od tego, czy jest statyczna. */
function k20s_call( $method, $args = array() ) {
	$cls = 'AIFAQ\Core\Settings';
	if ( ! method_exists( $cls, $method ) ) { return null; }
	$rm = new ReflectionMethod( $cls, $method );
	$rm->setAccessible( true );
	if ( $rm->isStatic() ) { return $rm->invokeArgs( null, $args ); }
	try {
		$obj = new $cls();
	} catch ( \Throwable $e ) {
		return null;
	}
	return $rm->invokeArgs( $obj, $args );
}
function k20s_defaults() { return (array) k20s_call( 'defaults' ); }
function k20s_sanitize( $input ) { return (array) k20s_call( 'sanitize', array( $input ) ); }
function k20s_get() { return (array) k20s_call( 'get' ); }
function k20s_models() { return (array) k20s_call( 'models' ); }

/** Ustawia ZAPISANY stan opcji (sanitize() startuje od defaults + stored). */
function k20s_store( $stored = array() ) {
	global $OPTKEY;
	$GLOBALS['__opt'][ $OPTKEY ] = $stored;
}

/** Osiem nowych kluczy §2 wraz z wartościami domyślnymi — jedno źródło prawdy w tym pliku. */
$K20_NEW = array(
	'menu_link_enabled'     => '1',
	'menu_location'         => '',
	'menu_label'            => 'Generator FAQ',
	'generations_keep_rows' => 0,
	'generations_keep_days' => 0,
	'rag_rate_window'       => 'godzina',
	'rag_daily_budget'      => 12,
	'rag_trusted_proxy'     => '0',
);

// ===========================================================================
echo "=== A. Komplet nowych kluczy w defaults() (§2) ===\n";
if ( $has_set && method_exists( $SET, 'defaults' ) ) {
	$d = k20s_defaults();
	foreach ( $K20_NEW as $k => $v ) {
		check( array_key_exists( $k, $d ), "defaults() zawiera klucz `$k` (bez wpisu get_field() zwraca null na świeżej instalacji)" );
	}
	check( '1' === (string) ( $d['menu_link_enabled'] ?? '' ), "menu_link_enabled domyślnie '1'" );
	check( '' === (string) ( $d['menu_location'] ?? 'x' ), "menu_location domyślnie '' (pusty)" );
	check( 'Generator FAQ' === (string) ( $d['menu_label'] ?? '' ), "menu_label domyślnie 'Generator FAQ'" );
	check( 0 === (int) ( $d['generations_keep_rows'] ?? -1 ), 'generations_keep_rows domyślnie 0 (retencja OPT-IN, FZ24)' );
	check( 0 === (int) ( $d['generations_keep_days'] ?? -1 ), 'generations_keep_days domyślnie 0 (retencja OPT-IN, FZ24)' );
	check( 'godzina' === (string) ( $d['rag_rate_window'] ?? '' ), "rag_rate_window domyślnie 'godzina' (FZ2 — 'doba' = 24-krotne zaostrzenie)" );
	check( 12 === (int) ( $d['rag_daily_budget'] ?? -1 ), 'rag_daily_budget domyślnie 12 (§2.2 — nie 20)' );
	check( '0' === (string) ( $d['rag_trusted_proxy'] ?? '' ), "rag_trusted_proxy domyślnie '0' (§2.3 — włączony bez proxy = obejście limitera)" );
	check( 10 === (int) ( $d['rag_rate_limit'] ?? -1 ), 'rag_rate_limit: wartość domyślna zmieniona 30 -> 10 (§2)' );
	check( 8 === count( array_intersect_key( $d, $K20_NEW ) ), 'defaults() ma DOKŁADNIE osiem nowych kluczy §2' );
	unset( $d );
} else {
	skip( 19, 'sekcja A pominięta — brak Settings::defaults()' );
}

// ===========================================================================
echo "\n=== B1. Clampy — bez get_registered_nav_menus() (§2, §13.6) ===\n";
if ( $has_set && method_exists( $SET, 'sanitize' ) ) {
	check( ! function_exists( 'get_registered_nav_menus' ), 'warunek sekcji: get_registered_nav_menus() NIE istnieje (czysty PHP CLI)' );

	k20s_store( array() );
	$w0 = $GLOBALS['aifaq_warnings'];
	$o  = k20s_sanitize( array( 'menu_location' => 'header' ) );
	check( $GLOBALS['aifaq_warnings'] === $w0, 'sanitize() bez get_registered_nav_menus() nie generuje warningów (§13.6 — osłona function_exists)' );
	check( 'header' === (string) ( $o['menu_location'] ?? '' ), 'brak funkcji lokalizacji → wartość przechodzi BEZ walidacji (§13.6)' );

	// menu_link_enabled — reguła `'1' === (string) $in`.
	$o = k20s_sanitize( array( 'menu_link_enabled' => '1' ) );
	check( '1' === (string) $o['menu_link_enabled'], "menu_link_enabled '1' → '1'" );
	$o = k20s_sanitize( array( 'menu_link_enabled' => 'yes' ) );
	check( '0' === (string) $o['menu_link_enabled'], "menu_link_enabled 'yes' (śmieć) → '0'" );
	$o = k20s_sanitize( array( 'menu_link_enabled' => 1 ) );
	check( '1' === (string) $o['menu_link_enabled'], "menu_link_enabled int 1 → '1' (rzutowanie na string)" );

	// rag_trusted_proxy — ta sama reguła.
	$o = k20s_sanitize( array( 'rag_trusted_proxy' => '1' ) );
	check( '1' === (string) $o['rag_trusted_proxy'], "rag_trusted_proxy '1' → '1'" );
	$o = k20s_sanitize( array( 'rag_trusted_proxy' => 'on' ) );
	check( '0' === (string) $o['rag_trusted_proxy'], "rag_trusted_proxy 'on' (śmieć) → '0'" );

	// menu_label — sanitize_text_field + obcięcie do 60, pusty → domyślna.
	$o = k20s_sanitize( array( 'menu_label' => str_repeat( 'a', 100 ) ) );
	check( 60 === strlen( (string) $o['menu_label'] ), 'menu_label obcięty do DOKŁADNIE 60 znaków (było 100)' );
	$o = k20s_sanitize( array( 'menu_label' => '' ) );
	check( 'Generator FAQ' === (string) $o['menu_label'], 'menu_label pusty → wartość domyślna' );
	$o = k20s_sanitize( array( 'menu_label' => '   ' ) );
	check( 'Generator FAQ' === (string) $o['menu_label'], 'menu_label z samych spacji → wartość domyślna' );
	$o = k20s_sanitize( array( 'menu_label' => '<b>FAQ</b>' ) );
	check( false === strpos( (string) $o['menu_label'], '<' ), 'menu_label przechodzi przez sanitize_text_field (znaczniki usunięte)' );

	// generations_keep_rows — max( 0, min( 5000, (int) ) ).
	$o = k20s_sanitize( array( 'generations_keep_rows' => -5 ) );
	check( 0 === (int) $o['generations_keep_rows'], 'generations_keep_rows -5 → 0' );
	$o = k20s_sanitize( array( 'generations_keep_rows' => 999999 ) );
	check( 5000 === (int) $o['generations_keep_rows'], 'generations_keep_rows 999999 → 5000 (sufit)' );
	$o = k20s_sanitize( array( 'generations_keep_rows' => 'abc' ) );
	check( 0 === (int) $o['generations_keep_rows'], "generations_keep_rows 'abc' (śmieć) → 0" );
	$o = k20s_sanitize( array( 'generations_keep_rows' => 200 ) );
	check( 200 === (int) $o['generations_keep_rows'], 'generations_keep_rows 200 przechodzi nietknięte' );

	// generations_keep_days — max( 0, min( 3650, (int) ) ).
	$o = k20s_sanitize( array( 'generations_keep_days' => -1 ) );
	check( 0 === (int) $o['generations_keep_days'], 'generations_keep_days -1 → 0' );
	$o = k20s_sanitize( array( 'generations_keep_days' => 99999 ) );
	check( 3650 === (int) $o['generations_keep_days'], 'generations_keep_days 99999 → 3650 (sufit)' );
	$o = k20s_sanitize( array( 'generations_keep_days' => '' ) );
	check( 0 === (int) $o['generations_keep_days'], 'generations_keep_days pusty → 0' );

	// rag_rate_window — whitelista godzina|doba.
	$o = k20s_sanitize( array( 'rag_rate_window' => 'doba' ) );
	check( 'doba' === (string) $o['rag_rate_window'], "rag_rate_window 'doba' przechodzi" );
	$o = k20s_sanitize( array( 'rag_rate_window' => 'tydzien' ) );
	check( 'godzina' === (string) $o['rag_rate_window'], "rag_rate_window spoza whitelisty → 'godzina'" );
	$o = k20s_sanitize( array( 'rag_rate_window' => '' ) );
	check( 'godzina' === (string) $o['rag_rate_window'], "rag_rate_window pusty → 'godzina'" );

	// rag_daily_budget — max( 0, min( 10000, (int) ) ); 0 = wyłączony.
	$o = k20s_sanitize( array( 'rag_daily_budget' => -3 ) );
	check( 0 === (int) $o['rag_daily_budget'], 'rag_daily_budget -3 → 0 (sufit wyłączony)' );
	$o = k20s_sanitize( array( 'rag_daily_budget' => 999999 ) );
	check( 10000 === (int) $o['rag_daily_budget'], 'rag_daily_budget 999999 → 10000 (sufit clampu)' );
	$o = k20s_sanitize( array( 'rag_daily_budget' => 'x' ) );
	check( 0 === (int) $o['rag_daily_budget'], "rag_daily_budget 'x' (śmieć) → 0" );
	$o = k20s_sanitize( array( 'rag_daily_budget' => 0 ) );
	check( 0 === (int) $o['rag_daily_budget'], 'rag_daily_budget 0 zostaje 0 (klucz płatny — sufit całkiem wyłączony)' );

	// rag_rate_limit — clamp 0..200 BEZ ZMIAN (§2).
	$o = k20s_sanitize( array( 'rag_rate_limit' => 500 ) );
	check( 200 === (int) $o['rag_rate_limit'], 'rag_rate_limit 500 → 200 (clamp 0..200 bez zmian)' );
	$o = k20s_sanitize( array( 'rag_rate_limit' => -5 ) );
	check( 0 === (int) $o['rag_rate_limit'], 'rag_rate_limit -5 → 0 (clamp 0..200 bez zmian)' );

	unset( $o, $w0 );
} else {
	skip( 27, 'sekcja B1 pominięta — brak Settings::sanitize()' );
}

// ===========================================================================
echo "\n=== B2. Whitelista lokalizacji — z get_registered_nav_menus() (§2) ===\n";
// Funkcja definiowana W CZASIE WYKONANIA, dopiero teraz: sekcja B1 musiała biec bez niej.
if ( ! function_exists( 'get_registered_nav_menus' ) ) {
	function get_registered_nav_menus() { return array( 'primary' => 'Główne', 'footer' => 'Stopka' ); }
}
if ( $has_set && method_exists( $SET, 'sanitize' ) ) {
	k20s_store( array() );
	$o = k20s_sanitize( array( 'menu_location' => 'primary' ) );
	check( 'primary' === (string) $o['menu_location'], 'menu_location z whitelisty przechodzi' );
	$o = k20s_sanitize( array( 'menu_location' => 'nieistniejaca' ) );
	check( '' === (string) $o['menu_location'], "menu_location spoza whitelisty → '' (§2)" );
	$o = k20s_sanitize( array( 'menu_location' => '' ) );
	check( '' === (string) $o['menu_location'], "menu_location pusty → '' (auto-wybór wg listy preferencji §3.3 pkt 4)" );
	unset( $o );
} else {
	skip( 3, 'sekcja B2 pominięta — brak Settings::sanitize()' );
}

// ===========================================================================
echo "\n=== C. Idiom isset() — zapis z frontu nie gasi przełączników (§2.0, FZ1) ===\n";
if ( $has_set && method_exists( $SET, 'sanitize' ) ) {
	// Stan zapisany: OBA przełączniki włączone + wartości niedomyślne w pozostałych kluczach.
	k20s_store(
		array(
			'menu_link_enabled'     => '1',
			'rag_trusted_proxy'     => '1',
			'menu_location'         => 'primary',
			'menu_label'            => 'Nasze FAQ',
			'generations_keep_rows' => 200,
			'generations_keep_days' => 90,
			'rag_rate_window'       => 'doba',
			'rag_daily_budget'      => 50,
		)
	);
	// Dokładnie te cztery pola przysyła `RestController::handle_settings_save()` (§2.0).
	$front = array( 'api_key' => 'AIza-TEST', 'model' => 'gemini-2.5-flash', 'temperature' => 0.3, 'language' => 'pl' );
	$o     = k20s_sanitize( $front );

	check( '1' === (string) $o['menu_link_enabled'], 'FZ1: zapis z frontu NIE gasi menu_link_enabled (link w menu przeżywa)' );
	check( '1' === (string) $o['rag_trusted_proxy'], 'FZ1: zapis z frontu NIE gasi rag_trusted_proxy (goście nie wracają do jednego kubełka)' );
	check( 'primary' === (string) $o['menu_location'], 'zapis z frontu nie rusza menu_location' );
	check( 'Nasze FAQ' === (string) $o['menu_label'], 'zapis z frontu nie rusza menu_label' );
	check( 200 === (int) $o['generations_keep_rows'], 'zapis z frontu nie rusza generations_keep_rows' );
	check( 90 === (int) $o['generations_keep_days'], 'zapis z frontu nie rusza generations_keep_days' );
	check( 'doba' === (string) $o['rag_rate_window'], 'zapis z frontu nie rusza rag_rate_window' );
	check( 50 === (int) $o['rag_daily_budget'], 'zapis z frontu nie rusza rag_daily_budget' );

	// Odznaczenie checkboxa MUSI działać — dzięki ukrytemu inputowi value="0" klucz JEST w wejściu.
	$o = k20s_sanitize( array( 'menu_link_enabled' => '0', 'rag_trusted_proxy' => '0' ) );
	check( '0' === (string) $o['menu_link_enabled'], "jawne '0' wyłącza menu_link_enabled (ukryty input działa)" );
	check( '0' === (string) $o['rag_trusted_proxy'], "jawne '0' wyłącza rag_trusted_proxy (ukryty input działa)" );

	unset( $o, $front );
} else {
	skip( 10, 'sekcja C pominięta — brak Settings::sanitize()' );
}

// ===========================================================================
echo "\n=== D. H1 — modele z przydziałem ZERO: RELABEL, nie usuwanie (§2.4) ===\n";
if ( $has_set && method_exists( $SET, 'models' ) ) {
	$m    = k20s_models();
	$keys = array_keys( $m );
	check( 4 === count( $m ), 'whitelista modeli ma DOKŁADNIE 4 pozycje (usunięcie czerwieni cudze testy — §2.4)' );
	check(
		array( 'gemini-2.5-flash', 'gemini-flash-latest', 'gemini-2.5-pro', 'gemini-2.0-flash' ) === $keys,
		'klucze modeli NIETKNIĘTE i w tej samej kolejności (§2.4)'
	);
	$lab = function ( $k ) use ( $m ) { return (string) ( $m[ $k ] ?? '' ); };
	check( false !== mb_stripos( $lab( 'gemini-2.5-pro' ), 'PŁATNEGO' ), 'etykieta gemini-2.5-pro ostrzega o kluczu PŁATNYM' );
	check( false !== mb_stripos( $lab( 'gemini-2.0-flash' ), 'PŁATNEGO' ), 'etykieta gemini-2.0-flash ostrzega o kluczu PŁATNYM' );
	check( false !== mb_stripos( $lab( 'gemini-2.5-flash' ), 'darmow' ), 'etykieta gemini-2.5-flash mówi o darmowym przydziale' );
	check( false !== mb_stripos( $lab( 'gemini-flash-latest' ), 'darmow' ), 'etykieta gemini-flash-latest mówi o darmowym przydziale' );
	check( false === mb_stripos( $lab( 'gemini-2.5-flash' ), 'PŁATNEGO' ), 'model zalecany NIE jest oznaczony jako płatny' );
	unset( $m, $keys, $lab );
} else {
	skip( 7, 'sekcja D pominięta — brak Settings::models()' );
}

// ===========================================================================
echo "\n=== E. H2 — podłoga progu tematu w sanitize() ORAZ w get() (§2.5, §13.16, O-31) ===\n";
if ( $has_set && method_exists( $SET, 'sanitize' ) ) {
	// E1 — podłoga w sanitize().
	k20s_store( array() );
	$o = k20s_sanitize( array( 'rag_threshold_hard' => 0.10 ) );
	check( nearly( 0.65, $o['rag_threshold_hard'] ), 'rag_threshold_hard 0.10 → 0.65 (podłoga skalibrowana pomiarem)' );
	$o = k20s_sanitize( array( 'rag_threshold' => 0.10 ) );
	check( nearly( 0.70, $o['rag_threshold'] ), 'rag_threshold 0.10 → 0.70 (podłoga)' );
	$o = k20s_sanitize( array( 'rag_threshold' => 0.05, 'rag_threshold_hard' => 0.05 ) );
	check( nearly( 0.70, $o['rag_threshold'] ) && nearly( 0.65, $o['rag_threshold_hard'] ), 'dawny clamp dolny 0.05 NIE jest już osiągalny (H2 zamyka §0.11)' );

	// E2 — wartości POWYŻEJ podłogi przechodzą nietknięte; §13.16 min( hard, soft ) ZOSTAJE.
	$o = k20s_sanitize( array( 'rag_threshold' => 0.90 ) );
	check( nearly( 0.90, $o['rag_threshold'] ), 'rag_threshold 0.90 (powyżej podłogi) przechodzi nietknięty' );
	$o = k20s_sanitize( array( 'rag_threshold' => 0.90, 'rag_threshold_hard' => 0.80 ) );
	check( nearly( 0.80, $o['rag_threshold_hard'] ), 'przy soft 0.90 twardy 0.80 przechodzi: max( 0.65, min( 0.80, 0.90 ) ) = 0.80 (§13.16)' );
	$o = k20s_sanitize( array( 'rag_threshold' => 0.72, 'rag_threshold_hard' => 0.95 ) );
	check( nearly( 0.72, $o['rag_threshold_hard'] ), 'twardy powyżej miękkiego ściągany do miękkiego (sprzężenie §13.16 zostaje)' );

	// E3 — FZ9: zapis, który NIE przysyła progu, nie ma prawa cofnąć podłogi.
	k20s_store( array( 'rag_threshold' => 0.50, 'rag_threshold_hard' => 0.50 ) );
	$o = k20s_sanitize( array( 'api_key' => 'AIza-TEST', 'model' => 'gemini-2.5-flash', 'temperature' => 0.3, 'language' => 'pl' ) );
	check( nearly( 0.70, $o['rag_threshold'] ), 'FZ9: zapis z frontu (bez pola progu) PODNOSI zapisane 0.50 do 0.70, nie cofa podłogi' );
	check( nearly( 0.65, $o['rag_threshold_hard'] ), 'FZ9: walidacja krzyżowa nie ściąga świeżo nałożonej podłogi twardej' );
	unset( $o );
} else {
	skip( 8, 'sekcja E1-E3 pominięta — brak Settings::sanitize()' );
}
if ( $has_set && method_exists( $SET, 'get' ) ) {
	// E4 — podłoga TAKŻE NA ODCZYCIE (§2.5 pkt 2) — obejmuje instalacje z już zapisaną wartością.
	k20s_store( array( 'rag_threshold' => 0.35, 'rag_threshold_hard' => 0.50 ) );
	$g = k20s_get();
	check( nearly( 0.70, $g['rag_threshold'] ), 'get(): zapisane 0.35 podnoszone do 0.70 (podłoga na ODCZYCIE — instalacje typu Dworek)' );
	check( nearly( 0.65, $g['rag_threshold_hard'] ), 'get(): zapisane 0.50 podnoszone do 0.65 (podłoga na ODCZYCIE)' );
	k20s_store( array( 'rag_threshold' => 0.85, 'rag_threshold_hard' => 0.80 ) );
	$g = k20s_get();
	check( nearly( 0.85, $g['rag_threshold'] ) && nearly( 0.80, $g['rag_threshold_hard'] ), 'get(): wartości powyżej podłogi zwracane bez zmian' );
	unset( $g );
} else {
	skip( 3, 'sekcja E4 pominięta — brak Settings::get()' );
}

// ===========================================================================
echo "\n=== F. Kontrolki w widoku settings.php (§2.6, §2.0, §13.15, O-32) ===\n";
$view = __DIR__ . '/../src/Admin/views/settings.php';
if ( file_exists( $view ) ) {
	$src = (string) file_get_contents( $view );

	// O-32: kryterium czytamy po UNIKALNYCH kluczach, nie po liczbie linii.
	$present = 0;
	foreach ( array_keys( $K20_NEW ) as $k ) {
		if ( false !== strpos( $src, 'aifaq_settings[' . $k . ']' ) ) { $present++; }
	}
	check( 8 === $present, 'wszystkie OSIEM nowych kluczy ma kontrolkę w widoku (bez nich klucze są martwe — §2.6)' );

	// Sześć pól niebędących checkboxami — dokładnie jedno wystąpienie nazwy.
	foreach ( array( 'menu_location', 'menu_label', 'generations_keep_rows', 'generations_keep_days', 'rag_rate_window', 'rag_daily_budget' ) as $k ) {
		check(
			1 === substr_count( $src, 'aifaq_settings[' . $k . ']' ),
			"pole `$k` występuje w widoku DOKŁADNIE raz"
		);
	}

	// Dwa checkboxy — DWA wystąpienia: ukryty input value="0" + sam checkbox (§2.0; patrz O-102).
	foreach ( array( 'menu_link_enabled', 'rag_trusted_proxy' ) as $k ) {
		$n = substr_count( $src, 'aifaq_settings[' . $k . ']' );
		check( 2 === $n, "checkbox `$k` występuje DOKŁADNIE dwa razy (ukryty value=\"0\" + checkbox) — było $n" );

		// Ukryty input MUSI stać PRZED checkboxem, inaczej wygrywa i odznaczenie nigdy nie zapisuje '1'.
		$pos_hidden = strpos( $src, 'aifaq_settings[' . $k . ']' );
		$pos_last   = strrpos( $src, 'aifaq_settings[' . $k . ']' );
		$before     = substr( $src, max( 0, $pos_hidden - 220 ), 220 + strlen( $k ) + 40 );
		check(
			false !== $pos_hidden && $pos_hidden < $pos_last
			&& false !== strpos( $before, 'hidden' ) && false !== strpos( $before, 'value="0"' ),
			"ukryty input value=\"0\" stoi PRZED checkboxem `$k` (§2.0 — bez tego odznaczenie nie ma skutku)"
		);
	}

	// §13.15 — etykieta limitu po zmianie semantyki na okno konfigurowalne musi zostać przepisana.
	check( false === strpos( $src, 'Limit pytań na godzinę' ), '§13.15: stara etykieta „Limit pytań na godzinę" usunięta (dziś by kłamała)' );
	// §2.4/FZ8 — brakujący opis pod selectem modelu (test sprawdza KLUCZ, nie przydział).
	check( false !== strpos( $src, 'aifaq_settings[model]' ), 'select modelu nadal jest w widoku (relabel, nie usuwanie)' );

	unset( $src, $present, $pos_hidden, $pos_last, $before, $n );
} else {
	skip( 12, 'sekcja F pominięta — brak pliku views/settings.php' );
}

// ===========================================================================
echo "\n=== G. §13.1 — zmiana ustawień menu unieważnia bramkę aifaq_menu_ok ===\n";
if ( $has_set && method_exists( $SET, 'on_settings_updated' ) ) {
	$rm   = new ReflectionMethod( $SET, 'on_settings_updated' );
	$argc = $rm->getNumberOfParameters();

	// Bramka zamknięta na '0' + zmiana przełącznika → musi zostać unieważniona.
	$GLOBALS['__opt']['aifaq_menu_ok'] = '0';
	$old = array_merge( k20s_defaults(), array( 'menu_link_enabled' => '0', 'menu_location' => '' ) );
	$new = array_merge( k20s_defaults(), array( 'menu_link_enabled' => '1', 'menu_location' => '' ) );
	$args = ( $argc >= 3 ) ? array( $old, $new, 'aifaq_settings' ) : array( $old, $new );
	try {
		k20s_call( 'on_settings_updated', array_slice( $args, 0, max( 2, $argc ) ) );
		$thrown = false;
	} catch ( \Throwable $e ) {
		$thrown = true;
		echo '  [on_settings_updated rzucilo] ' . $e->getMessage() . "\n";
	}
	check( false === $thrown, 'on_settings_updated() nie rzuca w czystym CLI' );
	check( '' === (string) get_option( 'aifaq_menu_ok', 'BRAK' ), 'zmiana menu_link_enabled unieważnia bramkę aifaq_menu_ok (§13.1)' );

	// To samo dla zmiany lokalizacji.
	$GLOBALS['__opt']['aifaq_menu_ok'] = '0';
	$old  = array_merge( k20s_defaults(), array( 'menu_location' => '' ) );
	$new  = array_merge( k20s_defaults(), array( 'menu_location' => 'primary' ) );
	$args = ( $argc >= 3 ) ? array( $old, $new, 'aifaq_settings' ) : array( $old, $new );
	k20s_call( 'on_settings_updated', array_slice( $args, 0, max( 2, $argc ) ) );
	check( '' === (string) get_option( 'aifaq_menu_ok', 'BRAK' ), 'zmiana menu_location unieważnia bramkę aifaq_menu_ok (§13.1)' );

	// Zapis NIEZMIENIAJĄCY ustawień menu nie ma prawa ruszać bramki.
	$GLOBALS['__opt']['aifaq_menu_ok'] = '1';
	$old  = array_merge( k20s_defaults(), array( 'menu_location' => 'primary', 'temperature' => 0.3 ) );
	$new  = array_merge( k20s_defaults(), array( 'menu_location' => 'primary', 'temperature' => 0.7 ) );
	$args = ( $argc >= 3 ) ? array( $old, $new, 'aifaq_settings' ) : array( $old, $new );
	k20s_call( 'on_settings_updated', array_slice( $args, 0, max( 2, $argc ) ) );
	check( '1' === (string) get_option( 'aifaq_menu_ok', 'BRAK' ), 'zapis niezmieniający ustawień menu ZOSTAWIA bramkę w spokoju' );

	unset( $rm, $argc, $old, $new, $args, $thrown );
} else {
	skip( 4, 'sekcja G pominięta — brak Settings::on_settings_updated()' );
}

// ===========================================================================
echo "\n== Higiena ==\n";
check( 0 === $GLOBALS['aifaq_warnings'], 'zero warningów/notice PHP w całym pliku (było: ' . $GLOBALS['aifaq_warnings'] . ')' );

echo "\n== Podłoga pokrycia ==\n";
$floor = $ran;
check( $floor >= 40, 'wykonano co najmniej 40 asercji (było ' . $floor . ')' );

// Wartownik końca pliku — chroni przed cichym Fatalem w środku.
check( true, 'plik dobiegł końca' );

echo "\n=== " . ( 0 === $fail ? 'WSZYSTKIE OK' : "BŁĘDÓW: {$fail}" ) . " (asercji: {$ran}) ===\n";
exit( $fail > 0 ? 1 : 0 );
