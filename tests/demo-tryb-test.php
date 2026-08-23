<?php
/**
 * Tryb demo — ograniczenia publicznej wystawy (AIFAQ_DEMO).
 *
 * Na wystawie kokpit jest otwarty, a konto gościa MUSI mieć `manage_options` —
 * bez tego nie zobaczy ani jednego ekranu wtyczki. Gość ma więc technicznie
 * dostęp do wszystkiego, co właściciel. Tryb demo odbiera mu dokładnie to,
 * co potrafi zniszczyć wystawę albo spalić darmową pulę Gemini, i każda
 * z tych blokad psuje się po cichu, więc każda ma tu własną asercję:
 *
 *   1. ZAMROŻONE USTAWIENIA. Klucz API, oba modele, sufit dobowy, limit i okno
 *      limitera, zaufany proxy. Ostatni jest najmniej oczywisty, a najgroźniejszy:
 *      przy `rag_trusted_proxy` adres gościa czytany jest z nagłówka, który
 *      ustawia sam klient — włączenie go daje po kubełku na zmyślony adres.
 *   2. OPERACJE NIEDOSTĘPNE. Przebudowa i czyszczenie bazy wiedzy oraz test
 *      połączenia. Blokada reindeksu siedzi w rdzeniu wspólnym dla REST-a
 *      i akcji AJAX, więc test sprawdza rdzeń, nie jedno z wejść.
 *   3. LIMIT NA ADRES IP przy generatorze. Wtyczka ma już limit per użytkownik,
 *      ale na wystawie wszyscy goście siedzą na jednym koncie `demo`.
 *   4. POZA TRYBEM DEMO WSZYSTKO DZIAŁA JAK DOTĄD — połowa tego pliku.
 *
 * MECHANIZM: `AIFAQ_DEMO` jest STAŁĄ, więc jednego procesu nie da się przełączyć
 * z powrotem. Kolejność jest częścią konstrukcji — najpierw komplet asercji BEZ
 * trybu demo, potem `define()` w połowie pliku i komplet Z trybem.
 *
 * URUCHOMIENIE:  php tests/demo-tryb-test.php
 * Kod wyjścia: 0 = OK, 1 = błędy.
 *
 * @package AI_FAQ_Generator
 */

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
if ( ! defined( 'AIFAQ_VERSION' ) ) { define( 'AIFAQ_VERSION', '0.0.0-test' ); }
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }

$GLOBALS['__opt']       = array();
$GLOBALS['__transient'] = array();

// Adres IP gościa — tryb demo liczy po nim limity, więc test go przestawia.
$_SERVER['REMOTE_ADDR'] = '198.51.100.7';

// --- Shimy WP ---------------------------------------------------------------
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $s, $d = null ) { return (string) $s; } }
if ( ! function_exists( 'esc_attr__' ) ) { function esc_attr__( $s, $d = null ) { return (string) $s; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $s ) { return (string) $s; } }
if ( ! function_exists( 'esc_url_raw' ) ) { function esc_url_raw( $s ) { return (string) $s; } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); } }
if ( ! function_exists( 'sanitize_textarea_field' ) ) { function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); } }
if ( ! function_exists( 'sanitize_key' ) ) { function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $s ) ); } }
if ( ! function_exists( 'sanitize_title' ) ) { function sanitize_title( $s ) { return strtolower( trim( preg_replace( '/[^A-Za-z0-9\-_]+/', '-', (string) $s ), '-' ) ); } }
if ( ! function_exists( 'wp_unslash' ) ) { function wp_unslash( $s ) { return is_string( $s ) ? stripslashes( $s ) : $s; } }
if ( ! function_exists( 'absint' ) ) { function absint( $n ) { return abs( (int) $n ); } }
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $h, $v = null, ...$a ) { return $v; } }
if ( ! function_exists( 'add_filter' ) ) { function add_filter( $h, $cb, $p = 10, $n = 1 ) { return true; } }
if ( ! function_exists( 'add_action' ) ) { function add_action( $h, $cb, $p = 10, $n = 1 ) { return true; } }
if ( ! function_exists( 'do_action' ) ) { function do_action( $h, ...$a ) { return null; } }
if ( ! function_exists( 'is_admin' ) ) { function is_admin() { return true; } }
if ( ! function_exists( 'current_user_can' ) ) { function current_user_can( $c ) { return true; } }
if ( ! function_exists( 'add_settings_error' ) ) { function add_settings_error( $s, $c, $m, $t = 'error' ) {} }
if ( ! function_exists( 'get_option' ) ) { function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__opt'] ) ? $GLOBALS['__opt'][ $k ] : $d; } }
if ( ! function_exists( 'add_option' ) ) { function add_option( $k, $v = '', $dep = '', $a = 'yes' ) { if ( array_key_exists( $k, $GLOBALS['__opt'] ) ) { return false; } $GLOBALS['__opt'][ $k ] = $v; return true; } }
if ( ! function_exists( 'update_option' ) ) { function update_option( $k, $v, $a = null ) { $GLOBALS['__opt'][ $k ] = $v; return true; } }
if ( ! function_exists( 'delete_option' ) ) { function delete_option( $k ) { unset( $GLOBALS['__opt'][ $k ] ); return true; } }
if ( ! function_exists( 'get_transient' ) ) { function get_transient( $k ) { return array_key_exists( $k, $GLOBALS['__transient'] ) ? $GLOBALS['__transient'][ $k ] : false; } }
if ( ! function_exists( 'set_transient' ) ) { function set_transient( $k, $v, $t = 0 ) { $GLOBALS['__transient'][ $k ] = $v; return true; } }
if ( ! function_exists( 'delete_transient' ) ) { function delete_transient( $k ) { unset( $GLOBALS['__transient'][ $k ] ); return true; } }
if ( ! function_exists( 'wp_next_scheduled' ) ) { function wp_next_scheduled( $h, $a = array() ) { return false; } }
if ( ! function_exists( 'wp_schedule_event' ) ) { function wp_schedule_event( $t, $r, $h, $a = array() ) { return true; } }
if ( ! function_exists( 'wp_schedule_single_event' ) ) { function wp_schedule_single_event( $t, $h, $a = array() ) { return true; } }
if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) { function wp_clear_scheduled_hook( $h, $a = array() ) { return 0; } }
if ( ! function_exists( 'wp_unschedule_hook' ) ) { function wp_unschedule_hook( $h ) { return 0; } }
if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type = 'mysql', $gmt = 0 ) {
		if ( 'timestamp' === $type || 'U' === $type ) { return time(); }
		if ( 'mysql' === $type ) { return date( 'Y-m-d H:i:s' ); }
		return date( (string) $type );
	}
}
if ( ! class_exists( 'FakeWpdbDemo' ) ) {
	class FakeWpdbDemo {
		public $prefix = 'wp_';
		public function prepare( $q, ...$a ) { return $q; }
		public function get_row( $q, $o = null ) { return null; }
		public function get_var( $q ) { return 0; }
		public function get_results( $q, $o = null ) { return array(); }
		public function query( $q ) { return 0; }
	}
}
$GLOBALS['wpdb'] = new FakeWpdbDemo();

// --- Harness ----------------------------------------------------------------
$fail = 0;
$ran  = 0;
function check( $cond, $label ) {
	global $fail, $ran;
	$ran++;
	echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n";
	if ( ! $cond ) { $fail++; }
}

require_once __DIR__ . '/../src/Core/Demo.php';
require_once __DIR__ . '/../src/Core/Settings.php';

use AIFAQ\Core\Demo;
use AIFAQ\Core\Settings;

/**
 * Ustawia ZAPISANY stan opcji — `sanitize()` startuje od `get()`.
 *
 * @param array $stored Zapisane ustawienia.
 *
 * @return void
 */
function dm_store( array $stored ) {
	$GLOBALS['__opt'][ Settings::OPTION ] = $stored;
}

/**
 * Kasuje transienty odstępu, zostawiając liczniki dobowe — odwzorowuje UPŁYW
 * CZASU. Dopasowanie po nazwie operacji, nie po prywatnym prefiksie klasy:
 * zmiana prefiksu nie ma unieruchomić tego zestawu po cichu.
 *
 * @param string $akcja Nazwa operacji.
 *
 * @return void
 */
function dm_minal_odstep( $akcja ) {
	foreach ( array_keys( $GLOBALS['__transient'] ) as $klucz ) {
		if ( false !== strpos( (string) $klucz, $akcja . '_' ) && false === strpos( (string) $klucz, 'cnt' ) ) {
			unset( $GLOBALS['__transient'][ $klucz ] );
		}
	}
}

/** Stan wystawy: wartości, których gość nie ma prawa ruszyć. */
$wzorzec = array(
	'api_key'           => 'AIzaKLUCZ_WYSTAWY',
	'model'             => 'gemini-2.5-flash',
	'embed_model'       => 'text-embedding-004',
	'rag_daily_budget'  => 12,
	'rag_rate_limit'    => 10,
	'rag_rate_window'   => 'godzina',
	'rag_trusted_proxy' => '0',
);

/** Próba podmiany wszystkiego naraz — jeden „złośliwy" POST. */
$napasc = array(
	'api_key'           => 'AIzaKLUCZ_GOSCIA',
	'model'             => 'gemini-2.5-pro',
	'embed_model'       => 'gemini-embedding-001',
	'rag_daily_budget'  => 9999,
	'rag_rate_limit'    => 200,
	'rag_rate_window'   => 'doba',
	'rag_trusted_proxy' => '1',
	'temperature'       => 0.3,
	'language'          => 'pl',
);

echo "=== TRYB DEMO (AIFAQ_DEMO): ograniczenia publicznej wystawy ===\n\n";

// ===========================================================================
echo "-- BEZ trybu demo: wtyczka nie zauważa, że klasa Demo istnieje --\n";
// ===========================================================================

check( false === Demo::active(), 'Demo::active() fałszywe, gdy stałej nie ma' );

dm_store( $wzorzec );
$po = Settings::sanitize( $napasc );

check( 'AIzaKLUCZ_GOSCIA' === $po['api_key'], 'właściciel PODMIENIA klucz API' );
check( 'gemini-2.5-pro' === $po['model'], 'właściciel zmienia model' );
check( 9999 === (int) $po['rag_daily_budget'], 'właściciel zmienia dobowy sufit' );
check( 200 === (int) $po['rag_rate_limit'], 'właściciel zmienia limit gościa' );
check( 'doba' === $po['rag_rate_window'], 'właściciel zmienia okno limitu' );
check( '1' === (string) $po['rag_trusted_proxy'], 'właściciel włącza zaufany proxy' );

check( Demo::allow( 'generate', 300 ), 'pierwsza generacja dozwolona' );
check( Demo::allow( 'generate', 300 ), 'druga TEŻ dozwolona — bez demo nie ma odstępu' );
check( array() === $GLOBALS['__transient'], 'bez demo nie powstaje ŻADEN transient limitu' );

$wolno = 0;
for ( $i = 0; $i < 6; $i++ ) {
	if ( Demo::allow_generate() ) { $wolno++; }
}
check( 6 === $wolno, 'bez demo generator nie ma limitu dobowego (6 z 6)' );

$bez_demo = Demo::freeze( array( 'api_key' => 'NOWY' ), array( 'api_key' => 'STARY' ) );
check( 'NOWY' === $bez_demo['api_key'], 'freeze() poza demo oddaje wejście bez zmian' );

// ===========================================================================
// PUNKT ZWROTNY — stałej nie da się odłączyć, więc kolejność w tym pliku jest
// częścią konstrukcji testu, nie porządkiem opowiadania.
// ===========================================================================
define( 'AIFAQ_DEMO', true );

$GLOBALS['__transient'] = array();

echo "\n-- Z trybem demo: ustawienia zamrożone --\n";

check( true === Demo::active(), 'Demo::active() prawdziwe po zdefiniowaniu stałej' );

dm_store( $wzorzec );
$po = Settings::sanitize( $napasc );

check( 'AIzaKLUCZ_WYSTAWY' === $po['api_key'], 'klucz API NIE do podmiany' );
check( 'gemini-2.5-flash' === $po['model'], 'model NIE do zmiany' );
check( 'text-embedding-004' === $po['embed_model'], 'model embeddingów NIE do zmiany' );
check( 12 === (int) $po['rag_daily_budget'], 'dobowy sufit NIE do podniesienia' );
check( 10 === (int) $po['rag_rate_limit'], 'limit gościa NIE do podniesienia' );
check( 'godzina' === $po['rag_rate_window'], 'okno limitu NIE do rozciągnięcia' );
check( '0' === (string) $po['rag_trusted_proxy'], 'zaufany proxy NIE do włączenia — inaczej adres brałby się z nagłówka klienta' );

check( 0.3 === (float) $po['temperature'], 'reszta ustawień NADAL się zapisuje — demo nie zamraża całego panelu' );

// Lista zamrożonych pól jest jedna i publiczna; gdyby ktoś dołożył pole
// do klasy i zapomniał o teście, ta asercja to pokaże.
foreach ( Demo::POLA_ZAMROZONE as $pole ) {
	check(
		array_key_exists( $pole, $wzorzec ) && $po[ $pole ] === $wzorzec[ $pole ],
		'pole „' . $pole . '" z listy zamrożonych trzyma wartość wystawy'
	);
}

// Pole, którego nie było w zapisie, nie ma się pojawić z napaści.
dm_store( array( 'model' => 'gemini-2.5-flash' ) );
$po_puste = Settings::sanitize( array( 'api_key' => 'AIzaZ_POWIETRZA' ) );
check( '' === (string) ( $po_puste['api_key'] ?? '' ), 'klucza nie da się ZAŁOŻYĆ tam, gdzie go nie było' );

echo "\n-- Z trybem demo: odstęp i dobowy limit na adres IP --\n";

$GLOBALS['__transient'] = array();

check( Demo::allow( 'generate', 300 ), 'pierwsza operacja z danego IP dozwolona' );
check( ! Demo::allow( 'generate', 300 ), 'druga z tego samego IP ODRZUCONA' );
check( ! Demo::allow( 'generate', 300 ), 'i trzecia — odmowa nie zużywa się po jednym pytaniu' );

$_SERVER['REMOTE_ADDR'] = '203.0.113.55';
check( Demo::allow( 'generate', 300 ), 'INNY adres IP ma własny odstęp' );
$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
check( ! Demo::allow( 'generate', 300 ), 'pierwszy adres nadal odrzucany — liczniki są rozłączne' );

dm_minal_odstep( 'generate' );
check( Demo::allow( 'generate', 300 ), 'po wygaśnięciu odstępu operacja znów dozwolona' );

$GLOBALS['__transient'] = array();

$wolno = 0;
for ( $i = 0; $i < Demo::GENERATE_NA_DOBE + 2; $i++ ) {
	if ( Demo::allow_generate() ) { $wolno++; }
	dm_minal_odstep( 'generate' );
}
check( Demo::GENERATE_NA_DOBE === $wolno, 'dobowy limit generacji trzyma MIMO wygasających odstępów' );

$_SERVER['REMOTE_ADDR'] = '203.0.113.55';
check( Demo::allow_generate(), 'inny gość ma własną pulę generacji' );
$_SERVER['REMOTE_ADDR'] = '198.51.100.7';

// Odmowa z wyczerpanego limitu NIE ma rezerwować odstępu — inaczej każde
// bezskuteczne kliknięcie przedłużałoby blokadę o kolejne minuty.
dm_minal_odstep( 'generate' );
$przed = count( $GLOBALS['__transient'] );
check( ! Demo::allow_generate(), 'po wyczerpaniu limitu generacja odrzucona' );
check( $przed === count( $GLOBALS['__transient'] ), 'odmowa z limitu NIE rezerwuje odstępu' );

echo "\n-- Z trybem demo: operacje niedostępne --\n";

require_once __DIR__ . '/../src/Admin/IndexController.php';

$idx = new AIFAQ\Admin\IndexController();

// Klucz API JEST zapisany, więc odmowa nie może wziąć się z jego braku —
// inaczej asercja mierzyłaby pustą instalację, a nie tryb demo.
dm_store( $wzorzec );

$r_reindex = $idx->run_reindex();
check( empty( $r_reindex['ok'] ), 'przebudowa bazy wiedzy ODRZUCONA' );
check( 403 === (int) ( $r_reindex['status'] ?? 0 ), 'i to kodem 403, nie „brak klucza"' );
check( false !== strpos( (string) ( $r_reindex['message'] ?? '' ), 'demonstracyjna' ), 'komunikat mówi, czemu odmówiono' );

$r_clear = $idx->run_clear();
check( empty( $r_clear['ok'] ), 'czyszczenie bazy wiedzy ODRZUCONE' );
check( 403 === (int) ( $r_clear['status'] ?? 0 ), 'kodem 403' );

// Bramka stoi PRZED zamkiem indeksowania — gdyby stała za nim, odmowa
// zostawiałaby zamek wzięty i blokowała wystawę do wygaśnięcia.
check(
	! array_key_exists( 'aifaq_index_lock', $GLOBALS['__opt'] ),
	'odmowa nie zostawia po sobie zamka indeksowania'
);

echo "\n";
echo '=== Asercji: ' . $ran . ' | błędów: ' . $fail . " ===\n";
if ( 0 === $fail ) {
	echo "WSZYSTKIE OK\n";
	exit( 0 );
}
echo "BŁĘDY\n";
exit( 1 );
