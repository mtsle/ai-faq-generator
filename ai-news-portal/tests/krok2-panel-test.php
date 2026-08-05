<?php
/**
 * Krok 2, etap 2.5 — panel: Materialy, „Pobierz teraz", zrodla w Ustawieniach.
 *
 * Trzy rzeczy pilnowane tu najmocniej:
 *
 *   1. DLUG Z KROKU 1. Zapis Ustawien nie moze skasowac znacznika
 *      `ainp_demo_done` ani zamienic go z `true` na `1` — inaczej przy
 *      najblizszej aktywacji powstanie DRUGI artykul demo.
 *   2. Akcja bez uprawnienia albo bez nonce'a konczy sie ZEREM zmian w bazie.
 *   3. Tresc z bazy (tytul, adres, powod) trafia na ekran przepuszczona przez
 *      escapowanie — to jedyne miejsce, gdzie tekst z obcego serwera laduje
 *      w kokpicie administratora.
 *
 * Zero WordPressa i zero sieci. Asercje ilosciowe sa zawsze `=== N`.
 *
 * URUCHOMIENIE:  php tests/krok2-panel-test.php
 * Kod wyjscia: 0 = OK, 1 = bledy.
 *
 * @package AI_News_Portal
 */

$root = dirname( __DIR__ );
$fail = 0;
$ran  = 0;

/**
 * Asercja.
 *
 * @param bool   $cond  Warunek.
 * @param string $label Opis.
 *
 * @return void
 */
function k2p_check( $cond, $label ) {
	global $fail, $ran;
	$ran++;
	echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n";
	if ( ! $cond ) {
		$fail++;
	}
}

// ---------------------------------------------------------------------------
// Atrapy WordPressa.
// ---------------------------------------------------------------------------
define( 'ABSPATH', __DIR__ . '/atrapy/wp/' );
define( 'AINP_VERSION', '0.1.0' );
define( 'PHP_URL_HOST_FAKE', 1 );

/** Przerwanie zamiast `exit` po przekierowaniu. */
class AINP_Redirect extends \Exception {}

/** Przerwanie zamiast `wp_die()`. */
class AINP_Died extends \Exception {}

$GLOBALS['__opt']       = array();
$GLOBALS['__zapisy']    = 0;      // Ile razy cokolwiek zapisano do opcji.
$GLOBALS['__transient'] = array();
$GLOBALS['__cap']       = true;   // Wynik current_user_can().
$GLOBALS['__nonce_ok']  = true;   // Wynik check_admin_referer().
$GLOBALS['__uid']       = 3;

function current_user_can( $cap ) {
	return (bool) $GLOBALS['__cap'];
}
function get_current_user_id() {
	return (int) $GLOBALS['__uid'];
}
function check_admin_referer( $action, $pole ) {
	$GLOBALS['__sprawdzony_nonce'] = array( $action, $pole );
	if ( ! $GLOBALS['__nonce_ok'] ) {
		throw new AINP_Died( 'zly nonce' );
	}
	return true;
}
function wp_die( $tekst = '' ) {
	throw new AINP_Died( (string) $tekst );
}
function wp_safe_redirect( $url ) {
	$GLOBALS['__redirect'] = $url;
	throw new AINP_Redirect( $url );
}
function admin_url( $sciezka = '' ) {
	return 'https://dworek.local/wp-admin/' . $sciezka;
}
function add_query_arg( $args, $url ) {
	return $url . '?' . http_build_query( $args );
}
function get_option( $k, $default = false ) {
	return array_key_exists( $k, $GLOBALS['__opt'] ) ? $GLOBALS['__opt'][ $k ] : $default;
}
function update_option( $k, $v, $autoload = null ) {
	$GLOBALS['__opt'][ $k ] = $v;
	$GLOBALS['__zapisy']++;
	return true;
}
function set_transient( $k, $v, $ttl = 0 ) {
	$GLOBALS['__transient'][ $k ] = $v;
	return true;
}
function get_transient( $k ) {
	return array_key_exists( $k, $GLOBALS['__transient'] ) ? $GLOBALS['__transient'][ $k ] : false;
}
function delete_transient( $k ) {
	unset( $GLOBALS['__transient'][ $k ] );
	return true;
}
function wp_unslash( $v ) {
	return $v;
}
function sanitize_text_field( $v ) {
	$v = strip_tags( (string) $v );
	return trim( preg_replace( '/[\r\n\t]+/', ' ', $v ) );
}
function esc_url_raw( $url ) {
	return trim( (string) $url );
}
function esc_url( $url ) {
	$url = trim( (string) $url );
	// Tak jak WordPress: dopuszczamy tylko bezpieczne schematy.
	if ( ! preg_match( '#^(https?://|/|\#)#i', $url ) ) {
		return '';
	}
	return htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' );
}
function esc_html( $t ) {
	return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' );
}
function esc_attr( $t ) {
	return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' );
}
function esc_textarea( $t ) {
	return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' );
}
function __( $t, $d = '' ) {
	return $t;
}
function esc_html__( $t, $d = '' ) {
	return esc_html( $t );
}
function wp_nonce_field( $action, $pole ) {
	echo '<input type="hidden" name="' . esc_attr( $pole ) . '" value="nonce-' . esc_attr( $action ) . '" />';
}
function submit_button( $tekst, $typ = 'primary', $nazwa = 'submit', $wrap = true ) {
	echo '<button type="submit" class="' . esc_attr( $typ ) . '">' . esc_html( $tekst ) . '</button>';
}
function checked( $a, $b = true, $echo = true ) {
	return ( $a === $b ) ? 'checked="checked"' : '';
}
function add_action( $hook, $cb, $prio = 10, $args = 1 ) {
	$GLOBALS['__hooki'][] = $hook;
	return true;
}
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}
function wp_safe_remote_get( $url, $args = array() ) {
	return array( 'response' => array( 'code' => 404 ), 'body' => '' );
}
function wp_remote_retrieve_response_code( $r ) {
	return isset( $r['response']['code'] ) ? $r['response']['code'] : 0;
}
function wp_remote_retrieve_body( $r ) {
	return isset( $r['body'] ) ? $r['body'] : '';
}
function is_wp_error( $t ) {
	return false;
}
function home_url( $p = '' ) {
	return 'https://dworek.local' . $p;
}
function current_time( $typ, $gmt = 0 ) {
	return '2026-08-05 12:00:00';
}
function wp_encode_emoji( $t ) {
	return $t;
}

/** Atrapa `$wpdb` na potrzeby odczytu tabeli. */
class AINP_Fake_WPDB {
	public $prefix   = 'wp_';
	public $prepared = 0;
	public $queries  = array();
	public $wiersze  = array();

	public function prepare( $sql, ...$args ) {
		$this->prepared++;
		$i = 0;
		return preg_replace_callback(
			'/%[sd]/',
			function ( $m ) use ( &$i, $args ) {
				$v = array_key_exists( $i, $args ) ? $args[ $i ] : '';
				$i++;
				return ( '%d' === $m[0] ) ? (string) (int) $v : "'" . (string) $v . "'";
			},
			$sql
		);
	}

	public function get_results( $sql ) {
		$this->queries[] = $sql;
		return $this->wiersze;
	}

	public function query( $sql ) {
		$this->queries[] = $sql;
		return 1;
	}

	public function get_charset_collate() {
		return '';
	}
}
$GLOBALS['wpdb'] = new AINP_Fake_WPDB();

require_once $root . '/src/Settings.php';
require_once $root . '/src/Http.php';
require_once $root . '/src/Feed.php';
require_once $root . '/src/Dedup.php';
require_once $root . '/src/Runner.php';
require_once $root . '/src/Plugin.php';
require_once $root . '/src/Admin.php';

use AINP\Admin;
use AINP\Runner;
use AINP\Settings;

echo "=== KROK 2 / ETAP 2.5 — panel (Materialy, Pobierz teraz, zrodla) ===\n\n";

// ---------------------------------------------------------------------------
echo "-- Domyslne kanaly RSS --\n";
// ---------------------------------------------------------------------------
$domyslne = Settings::default_sources();

k2p_check( 4 === count( $domyslne ), 'cztery kanaly domyslne' );
k2p_check( count( $domyslne ) === count( array_unique( $domyslne ) ), 'bez powtorzen' );
foreach ( $domyslne as $adres ) {
	k2p_check( AINP\Http::is_http_url( $adres ), 'domyslny kanal jest adresem http(s): ' . $adres );
}
k2p_check(
	in_array( 'https://www.psy.pl/feed/', $domyslne, true ),
	'psy.pl na liscie (najwiekszy polski portal o psach)'
);
k2p_check(
	! in_array( 'https://www.accdog.pl/blog/feed/', $domyslne, true ),
	'accdog.pl POZA lista — blog sklepowy, sama zajawka'
);

// Swieza instalacja bierze domyslne, wyczyszczona lista zostaje pusta.
$GLOBALS['__opt'] = array();
k2p_check( 4 === count( Runner::sources() ), 'brak opcji: cztery kanaly domyslne' );

$GLOBALS['__opt']['ainp_sources'] = array();
k2p_check(
	0 === count( Runner::sources() ),
	'lista wyczyszczona przez klienta zostaje pusta, domyslne NIE wracaja'
);

$GLOBALS['__opt']['ainp_sources'] = array( 'https://tylko-ten.pl/feed/' );
k2p_check( 1 === count( Runner::sources() ), 'wlasna lista ma pierwszenstwo przed domyslna' );

// ---------------------------------------------------------------------------
echo "\n-- Pole „Kanaly RSS” — zamiana tekstu na liste --\n";
// ---------------------------------------------------------------------------
$wejscie = "https://psy.pl/feed/\r\n  https://ccw24.pl/feed/  \n\nhttps://psy.pl/feed/\nnie-adres\nftp://psy.pl/feed\n/wzgledny\n";
$lista   = Admin::parse_sources( $wejscie );

k2p_check( 2 === count( $lista ), 'z siedmiu linii zostaja dwa adresy' );
k2p_check( 'https://psy.pl/feed/' === $lista[0], 'pierwszy adres zachowany' );
k2p_check( 'https://ccw24.pl/feed/' === $lista[1], 'drugi adres przyciety ze spacji' );
k2p_check( 0 === count( Admin::parse_sources( '' ) ), 'puste pole daje pusta liste' );
k2p_check( 0 === count( Admin::parse_sources( "\n\n   \n" ) ), 'same puste linie daja pusta liste' );

// ---------------------------------------------------------------------------
echo "\n-- DLUG Z KROKU 1: znacznik artykulu demo --\n";
// ---------------------------------------------------------------------------
$GLOBALS['__opt'] = array(
	'ainp_settings' => array(
		'model'             => 'gemini-2.5-flash',
		'daily_cap'         => 20,
		'save_as_draft'     => false,
		'ainp_demo_done'    => true,
	),
);
$GLOBALS['__cap']      = true;
$GLOBALS['__nonce_ok'] = true;

$_POST = array(
	'ainp_sources'        => "https://psy.pl/feed/\nhttps://ccw24.pl/feed/",
	'ainp_model'          => 'gemini-2.5-flash',
	'ainp_daily_cap'      => '20',
	'ainp_categories'     => "Żywienie\nZdrowie",
	'ainp_excluded_words' => 'kot, kotka',
	// Pole wyboru NIE jest zaznaczone — w formularzu HTML znaczy to,
	// ze klucz w ogole nie przychodzi.
);

try {
	Admin::handle_save_settings();
	k2p_check( false, 'zapis konczy sie przekierowaniem' );
} catch ( AINP_Redirect $e ) {
	k2p_check( true, 'zapis konczy sie przekierowaniem' );
}

$po_zapisie = $GLOBALS['__opt']['ainp_settings'];

k2p_check(
	array_key_exists( 'ainp_demo_done', $po_zapisie ),
	'znacznik demo PRZEZYL zapis formularza'
);
k2p_check(
	true === $po_zapisie['ainp_demo_done'],
	'znacznik demo zostal `true`, a nie `1` — porownanie `true ===` w Plugin nadal zadziala'
);
k2p_check(
	true === Settings::get( Settings::KEY_DEMO_DONE, false ),
	'Settings::get() widzi znacznik tak samo jak przed zapisem'
);
k2p_check(
	false === $po_zapisie['save_as_draft'],
	'niezaznaczone pole wyboru daje `false`, nie brak klucza'
);
k2p_check(
	is_bool( $po_zapisie['save_as_draft'] ),
	'przelacznik jest typu bool, nie `1`/`0`'
);

// Zaznaczone pole wyboru daje `true` (bool), nie `1` (string).
$_POST['ainp_save_as_draft'] = '1';
try {
	Admin::handle_save_settings();
} catch ( AINP_Redirect $e ) {
	true;
}
k2p_check(
	true === $GLOBALS['__opt']['ainp_settings']['save_as_draft'],
	'zaznaczone pole wyboru daje `true` typu bool, nie lancuch "1"'
);
k2p_check(
	true === $GLOBALS['__opt']['ainp_settings']['ainp_demo_done'],
	'drugi zapis tez nie rusza znacznika demo'
);

// Proba podstawienia znacznika z zewnatrz.
$_POST['ainp_demo_done'] = '';
$_POST['ainp_settings']  = array( 'ainp_demo_done' => false );
try {
	Admin::handle_save_settings();
} catch ( AINP_Redirect $e ) {
	true;
}
k2p_check(
	true === $GLOBALS['__opt']['ainp_settings']['ainp_demo_done'],
	'znacznika demo NIE da sie skasowac podstawionym polem formularza'
);
unset( $_POST['ainp_demo_done'], $_POST['ainp_settings'] );

// Zrodla zapisane osobna opcja.
k2p_check(
	2 === count( $GLOBALS['__opt']['ainp_sources'] ),
	'zrodla zapisane w osobnej opcji `ainp_sources`'
);

// ---------------------------------------------------------------------------
echo "\n-- Sanityzacja pol Ustawien --\n";
// ---------------------------------------------------------------------------
$latka = Admin::sanitize_settings(
	array(
		'ainp_model'          => '  gemini-3.6-flash  ',
		'ainp_daily_cap'      => '0',
		'ainp_categories'     => "Żywienie\nZdrowie\nŻywienie",
		'ainp_excluded_words' => "kot, kotka\nchomik",
	)
);

k2p_check( ! array_key_exists( 'ainp_demo_done', $latka ), 'latka NIE zawiera znacznika demo' );
k2p_check( 'gemini-3.6-flash' === $latka['model'], 'model przyciety ze spacji' );
k2p_check( 1 === $latka['daily_cap'], 'sufit 0 podniesiony do 1 (0 wylaczyloby prace po cichu)' );
k2p_check( 2 === count( $latka['categories'] ), 'powtorzona kategoria usunieta' );
k2p_check( 3 === count( $latka['excluded_words'] ), 'slowa z przecinkow i linii razem' );

$latka = Admin::sanitize_settings( array( 'ainp_daily_cap' => '5000' ) );
k2p_check( 1000 === $latka['daily_cap'], 'sufit ograniczony z gory do 1000' );

$latka = Admin::sanitize_settings( array( 'ainp_model' => '' ) );
k2p_check( 'gemini-2.5-flash' === $latka['model'], 'pusty model wraca do domyslnego' );

$latka = Admin::sanitize_settings( array( 'ainp_categories' => '   ' ) );
k2p_check(
	7 === count( $latka['categories'] ),
	'pusta lista kategorii wraca do domyslnej (inaczej walidator AI odrzucalby wszystko)'
);

// ---------------------------------------------------------------------------
echo "\n-- Bez uprawnienia i bez nonce'a: ZERO zmian w bazie --\n";
// ---------------------------------------------------------------------------
$GLOBALS['__cap']      = false;
$GLOBALS['__nonce_ok'] = true;
$GLOBALS['__zapisy']   = 0;

foreach ( array( 'handle_save_settings', 'handle_fetch' ) as $akcja ) {
	try {
		Admin::$akcja();
		k2p_check( false, 'bez uprawnienia akcja `' . $akcja . '` zostaje przerwana' );
	} catch ( AINP_Died $e ) {
		k2p_check( true, 'bez uprawnienia akcja `' . $akcja . '` zostaje przerwana' );
	}
}
k2p_check( 0 === $GLOBALS['__zapisy'], 'bez uprawnienia: zero zapisow do opcji' );

$GLOBALS['__cap']      = true;
$GLOBALS['__nonce_ok'] = false;
$GLOBALS['__zapisy']   = 0;

foreach ( array( 'handle_save_settings', 'handle_fetch' ) as $akcja ) {
	try {
		Admin::$akcja();
		k2p_check( false, 'bez nonce\'a akcja `' . $akcja . '` zostaje przerwana' );
	} catch ( AINP_Died $e ) {
		k2p_check( true, 'bez nonce\'a akcja `' . $akcja . '` zostaje przerwana' );
	}
}
k2p_check( 0 === $GLOBALS['__zapisy'], 'bez nonce\'a: zero zapisow do opcji' );
k2p_check(
	array( 'ainp_fetch', '_ainp_nonce' ) === $GLOBALS['__sprawdzony_nonce'],
	'nonce sprawdzany dla WLASCIWEJ akcji i wlasciwego pola'
);

// ---------------------------------------------------------------------------
echo "\n-- Przycisk „Pobierz teraz” --\n";
// ---------------------------------------------------------------------------
$GLOBALS['__cap']        = true;
$GLOBALS['__nonce_ok']   = true;
$GLOBALS['__transient']  = array();
$GLOBALS['__opt']['ainp_sources'] = array( 'https://psy.pl/feed/' );

try {
	Admin::handle_fetch();
} catch ( AINP_Redirect $e ) {
	true;
}

$klucze = array_keys( $GLOBALS['__transient'] );

k2p_check( 1 === count( $klucze ), 'podsumowanie przebiegu odlozone w jednym transiencie' );
k2p_check( 'ainp_last_run_3' === $klucze[0], 'klucz transientu ma prefiks `ainp_` i ID uzytkownika' );
k2p_check(
	1 === $GLOBALS['__transient'][ $klucze[0] ]['sources'],
	'podsumowanie mowi o jednym kanale'
);
k2p_check(
	false !== strpos( (string) $GLOBALS['__redirect'], 'page=ainp-items' ),
	'przekierowanie wraca na ekran Materialow'
);

// ---------------------------------------------------------------------------
echo "\n-- Ekran Materialow: escapowanie tresci z obcego serwera --\n";
// ---------------------------------------------------------------------------
$GLOBALS['wpdb']->wiersze = array(
	(object) array(
		'id'         => 1,
		'url'        => 'javascript:alert(1)',
		'title'      => '<script>alert("xss")</script>Tytul',
		'status'     => 'new',
		'note'       => '<img src=x onerror=alert(1)>',
		'created_at' => '2026-08-05 12:00:00',
	),
);

ob_start();
Admin::render_items();
$html = ob_get_clean();

k2p_check( false === strpos( $html, '<script>' ), 'znacznik <script> z tytulu NIE trafia na ekran' );
/*
 * Tresc `note` ma wyjsc jako TEKST. Samo szukanie ciagu „onerror=" nic nie
 * mowi — po zescapowaniu zostaje on w tresci jako niegrozny tekst. Liczy sie,
 * czy przegladarka zobaczy ZNACZNIK.
 */
k2p_check( false === strpos( $html, '<img' ), 'znacznik <img> z powodu NIE trafia na ekran jako znacznik' );
k2p_check( false !== strpos( $html, '&lt;img src=x onerror=alert(1)&gt;' ), 'powod pokazany jako tekst' );
k2p_check( false === strpos( $html, 'href="javascript:' ), 'adres `javascript:` nie staje sie odnosnikiem' );
k2p_check( false !== strpos( $html, '&lt;script&gt;' ), 'tytul pokazany jako tekst' );
k2p_check( false !== strpos( $html, 'name="action" value="ainp_fetch"' ), 'formularz wola akcje ainp_fetch' );
k2p_check( false !== strpos( $html, 'name="_ainp_nonce"' ), 'formularz niesie pole nonce' );
k2p_check( false !== strpos( $html, 'admin-post.php' ), 'formularz celuje w admin-post.php' );
k2p_check( 1 === $GLOBALS['wpdb']->prepared, 'odczyt tabeli idzie przez prepare()' );
k2p_check(
	false !== strpos( $GLOBALS['wpdb']->queries[0], 'LIMIT 50' ),
	'odczyt ograniczony do 50 pozycji'
);

// Pusta tabela nie wywala ekranu.
$GLOBALS['wpdb']->wiersze = array();
ob_start();
Admin::render_items();
$html = ob_get_clean();
k2p_check( false !== strpos( $html, 'Pobierz teraz' ), 'pusta tabela: przycisk nadal jest' );

// ---------------------------------------------------------------------------
echo "\n-- Ekran Ustawien --\n";
// ---------------------------------------------------------------------------
$GLOBALS['__opt']['ainp_sources'] = array( 'https://psy.pl/feed/', 'https://ccw24.pl/feed/' );

ob_start();
Admin::render_settings();
$html = ob_get_clean();

k2p_check( false !== strpos( $html, 'name="ainp_sources"' ), 'pole kanalow RSS jest w formularzu' );
k2p_check( false !== strpos( $html, 'https://psy.pl/feed/' ), 'zapisane kanaly wypelniaja pole' );
k2p_check( false !== strpos( $html, 'name="action" value="ainp_save_settings"' ), 'formularz wola akcje zapisu' );
k2p_check( false !== strpos( $html, 'name="_ainp_nonce"' ), 'formularz Ustawien niesie nonce' );
k2p_check( false !== strpos( $html, 'name="ainp_save_as_draft"' ), 'przelacznik szkicow jest w formularzu' );

// Ekran bez uprawnienia.
$GLOBALS['__cap'] = false;
foreach ( array( 'render_items', 'render_settings' ) as $ekran ) {
	try {
		ob_start();
		Admin::$ekran();
		ob_end_clean();
		k2p_check( false, 'ekran `' . $ekran . '` zamkniety dla kogos bez uprawnienia' );
	} catch ( AINP_Died $e ) {
		ob_end_clean();
		k2p_check( true, 'ekran `' . $ekran . '` zamkniety dla kogos bez uprawnienia' );
	}
}
$GLOBALS['__cap'] = true;

// ---------------------------------------------------------------------------
echo "\n-- Kontrola kodu (po tokenach, nie po tekscie) --\n";
// ---------------------------------------------------------------------------
$tokeny = token_get_all( file_get_contents( $root . '/src/Admin.php' ) );
$nazwy  = array();
foreach ( $tokeny as $t ) {
	if ( is_array( $t ) && T_STRING === $t[0] ) {
		$nazwy[ $t[1] ] = true;
	}
}

k2p_check( isset( $nazwy['check_admin_referer'] ), 'kod sprawdza nonce' );
k2p_check( isset( $nazwy['current_user_can'] ), 'kod sprawdza uprawnienie' );
k2p_check( isset( $nazwy['wp_unslash'] ), 'kod odslashowuje $_POST' );
k2p_check( isset( $nazwy['wp_safe_redirect'] ), 'przekierowanie przez wp_safe_redirect, nie wp_redirect' );
k2p_check( ! isset( $nazwy['wp_redirect'] ), 'gole wp_redirect nieuzywane' );
k2p_check( isset( $nazwy['update'] ), 'zapis ustawien idzie przez Settings::update()' );

// ---------------------------------------------------------------------------
echo "\n====================================================\n";
echo 'Asercji: ' . $ran . ' | Niezaliczonych: ' . $fail . "\n";
if ( 0 === $fail ) {
	echo "WYNIK: WSZYSTKIE OK\n";
	exit( 0 );
}
echo "WYNIK: SĄ BŁĘDY\n";
exit( 1 );
