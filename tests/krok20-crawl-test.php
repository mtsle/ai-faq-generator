<?php
/**
 * Testy Kroku 20 — sekcja E: CRAWL (KONTRAKT k20-v3 §9).
 *
 * PISANE W CIEMNO (Etap 9b). Autor NIE otwierał `src/Index/CrawlQueue.php`
 * ani `src/Index/RenderedContentSource.php` — asercje pochodzą WYŁĄCZNIE
 * z `KONTRAKT.md` §9.1-§9.4 (w tym poprawki FZ34-FZ37, FZ40, FZ41).
 * Rozbieżność test↔implementacja jest dowodem, że kontrakt był nieprecyzyjny.
 *
 * CO TU JEST BRONIONE (znalezisko z 2026-07-21, `ai-faq-dev`):
 * crawl z przeglądarki pominął 6 z 11 stron z `cURL error 28`, bo Local ma
 * `pm.max_children = 2` — worker #1 trzyma przeglądarka, #2 crawl, a żądanie do samego
 * siebie nie ma trzeciego. Test pętli zwrotnej tego NIE wykrywa (jedno izolowane żądanie).
 * Klient na tanim hostingu dostanie to samo: ubogą bazę wiedzy i bota, który odmawia.
 *
 * Pokrywa:
 *  - A. wsteczna zgodność: stan BEZ nowych kluczy działa bez ostrzeżeń (§9.2);
 *  - B. lista `failed` + ponowienia przez `seed()`, twardy limit 3 prób (§9.2, §9.2-a);
 *  - C. klasyfikacja PO CZASIE, nie po treści komunikatu (§9.3, FZ35);
 *  - D. próg 3 kolejnych timeoutów, licznik PRZEŻYWA tick (§9.3);
 *  - E. reakcja: JEDNA sonda na epizod, dwa różne werdykty, pauza (§9.3, FZ36);
 *  - F. zakazy: `crawl_enabled` nigdy `'0'`, stałe 10/20/15 nietknięte (§9.3).
 *
 * Podłoga pokrycia: >= 35 asercji (ETAP-9b).
 *
 * URUCHOMIENIE:  php -d extension=mbstring tests/krok20-crawl-test.php
 * Kod wyjścia: 0 = OK, 1 = błędy.
 *
 * @package AI_FAQ_Generator
 */

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
if ( ! defined( 'AIFAQ_PLUGIN_DIR' ) ) { define( 'AIFAQ_PLUGIN_DIR', dirname( __DIR__ ) . '/' ); }
if ( ! defined( 'AIFAQ_VERSION' ) ) { define( 'AIFAQ_VERSION', '0.23.0-test' ); }
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
if ( ! defined( 'WEEK_IN_SECONDS' ) ) { define( 'WEEK_IN_SECONDS', 604800 ); }

// ---------------------------------------------------------------------------
// Rejestry harnessu.
// ---------------------------------------------------------------------------
$GLOBALS['__opt']       = array();
$GLOBALS['__autoload']  = array();
$GLOBALS['__meta']      = array();
$GLOBALS['__postdata']  = array();
$GLOBALS['__posts']     = array();
$GLOBALS['__cron']      = array();
$GLOBALS['__writes']    = array();   // wszystkie zapisy opcji (kontrola zakazu crawl_enabled='0')
$GLOBALS['__notices']   = array();   // ostrzeżenia/notice PHP
$GLOBALS['__k20_clock'] = 1000.0;    // zegar szwu now_ms() (sekundy, jak microtime(true))
$GLOBALS['__k20_dur']   = 0.05;      // ile „trwa” pojedyncze pobranie crawla
$GLOBALS['__http']      = array( 'calls' => 0, 'crawl' => 0, 'probe' => 0, 'args' => array(), 'reply' => null, 'probe_reply' => null );

// Ostrzeżenia PHP są tu ASERCJĄ (§9.2: „stan bez tych kluczy musi działać”).
set_error_handler(
	static function ( $no, $str, $file = '', $line = 0 ) {
		$GLOBALS['__notices'][] = $str . ' @ ' . basename( (string) $file ) . ':' . $line;
		return true;
	},
	E_ALL & ~E_DEPRECATED
);

// ---------------------------------------------------------------------------
// Shimy WP (wzorzec krok17-crawl-test.php).
// ---------------------------------------------------------------------------
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code; private $message;
		public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $t ) { return $t instanceof WP_Error; } }
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return (string) $s; } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $s, $d = null ) { return (string) $s; } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return (string) $s; } }
if ( ! function_exists( 'get_bloginfo' ) ) { function get_bloginfo( $show = 'name' ) { return 'Przedszkole Testowe'; } }
if ( ! function_exists( 'home_url' ) ) { function home_url( $path = '/' ) { return 'https://example.test' . $path; } }
if ( ! function_exists( 'get_the_title' ) ) { function get_the_title( $id ) { return 'Tytuł ' . (int) $id; } }
if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( $p = 0 ) { $id = is_object( $p ) ? (int) $p->ID : (int) $p; return 'https://example.test/?p=' . $id; }
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = false ) {
		return array_key_exists( $key, $GLOBALS['__opt'] ) ? $GLOBALS['__opt'][ $key ] : $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $key, $value, $autoload = null ) {
		$GLOBALS['__opt'][ $key ]      = $value;
		$GLOBALS['__autoload'][ $key ] = $autoload;
		$GLOBALS['__writes'][]         = array( 'key' => $key, 'value' => $value );
		return true;
	}
}
if ( ! function_exists( 'add_option' ) ) {
	function add_option( $key, $value = '', $dep = '', $autoload = 'yes' ) {
		if ( array_key_exists( $key, $GLOBALS['__opt'] ) ) { return false; }
		$GLOBALS['__opt'][ $key ]      = $value;
		$GLOBALS['__autoload'][ $key ] = $autoload;
		$GLOBALS['__writes'][]         = array( 'key' => $key, 'value' => $value );
		return true;
	}
}
if ( ! function_exists( 'delete_option' ) ) { function delete_option( $key ) { unset( $GLOBALS['__opt'][ $key ] ); return true; } }
if ( ! function_exists( 'get_post_status' ) ) { function get_post_status( $id = 0 ) { return $GLOBALS['__postdata'][ (int) $id ]['post_status'] ?? 'publish'; } }
if ( ! function_exists( 'get_post_type' ) ) { function get_post_type( $id = 0 ) { return $GLOBALS['__postdata'][ (int) $id ]['post_type'] ?? 'page'; } }
if ( ! function_exists( 'get_post_field' ) ) { function get_post_field( $f, $id = 0, $c = 'display' ) { return $GLOBALS['__postdata'][ (int) $id ][ $f ] ?? ''; } }
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $id, $key = '', $single = false ) {
		$all = $GLOBALS['__meta'][ (int) $id ] ?? array();
		if ( '' === $key ) { return $all; }
		$vals = $all[ $key ] ?? array();
		return $single ? ( $vals[0] ?? '' ) : $vals;
	}
}
if ( ! function_exists( 'update_post_meta' ) ) { function update_post_meta( $id, $k, $v ) { $GLOBALS['__meta'][ (int) $id ][ $k ] = array( $v ); return true; } }
if ( ! function_exists( 'delete_post_meta_by_key' ) ) {
	function delete_post_meta_by_key( $key ) {
		foreach ( $GLOBALS['__meta'] as $id => $rows ) { unset( $GLOBALS['__meta'][ $id ][ $key ] ); }
		return true;
	}
}
if ( ! function_exists( 'get_posts' ) ) { function get_posts( $args = array() ) { return $GLOBALS['__posts']; } }
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $hook, $value = null, ...$a ) { return $value; } }
if ( ! function_exists( 'wp_parse_url' ) ) { function wp_parse_url( $url, $c = -1 ) { return parse_url( $url, $c ); } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ) { return is_string( $s ) ? trim( $s ) : ''; } }
if ( ! function_exists( 'wp_strip_all_tags' ) ) { function wp_strip_all_tags( $s, $br = true ) { return trim( strip_tags( (string) $s ) ); } }
if ( ! function_exists( 'current_time' ) ) { function current_time( $t = 'mysql' ) { return 'mysql' === $t ? gmdate( 'Y-m-d H:i:s' ) : gmdate( (string) $t ); } }
if ( ! function_exists( 'wp_next_scheduled' ) ) { function wp_next_scheduled( $h, $a = array() ) { return $GLOBALS['__cron'][ $h ] ?? false; } }
if ( ! function_exists( 'wp_schedule_event' ) ) { function wp_schedule_event( $t, $rec, $hook, $args = array() ) { $GLOBALS['__cron'][ $hook ] = $t; return true; } }
if ( ! function_exists( 'wp_unschedule_event' ) ) { function wp_unschedule_event( $t, $h, $a = array() ) { unset( $GLOBALS['__cron'][ $h ] ); return true; } }
if ( ! function_exists( 'wp_unschedule_hook' ) ) { function wp_unschedule_hook( $h ) { unset( $GLOBALS['__cron'][ $h ] ); return true; } }
if ( ! function_exists( 'wp_cache_add' ) ) { function wp_cache_add( $k, $v, $g = '', $e = 0 ) { return true; } }
if ( ! function_exists( 'wp_cache_delete' ) ) { function wp_cache_delete( $k, $g = '' ) { return true; } }
if ( ! function_exists( 'wp_using_ext_object_cache' ) ) { function wp_using_ext_object_cache( $u = null ) { return false; } }

/**
 * TRANSPORT — liczy żądania i STERUJE ZEGAREM szwu `now_ms()`.
 *
 * Każde pobranie crawla przesuwa zegar o `__k20_dur` sekund, więc czas trwania żądania
 * jest DOKŁADNIE zaprogramowany, niezależnie od tego, ile razy kod woła `now_ms()`.
 * Sonda pętli zwrotnej idzie na `home_url()` (bez „p=”) i jest liczona osobno.
 */
if ( ! function_exists( 'wp_remote_get' ) ) {
	function wp_remote_get( $url, $args = array() ) {
		++$GLOBALS['__http']['calls'];
		$GLOBALS['__http']['args'][] = array( 'url' => $url, 'args' => $args );
		if ( false !== strpos( (string) $url, 'p=' ) ) {
			++$GLOBALS['__http']['crawl'];
			$GLOBALS['__k20_clock'] += (float) $GLOBALS['__k20_dur'];
			$r = $GLOBALS['__http']['reply'];
		} else {
			++$GLOBALS['__http']['probe'];
			$r = $GLOBALS['__http']['probe_reply'];
		}
		return is_callable( $r ) ? $r( $url, $args ) : $r;
	}
}
if ( ! function_exists( 'wp_remote_request' ) ) { function wp_remote_request( $u, $a = array() ) { return wp_remote_get( $u, $a ); } }
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) { function wp_remote_retrieve_response_code( $r ) { return (int) ( $r['response']['code'] ?? 0 ); } }
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) { function wp_remote_retrieve_body( $r ) { return (string) ( $r['body'] ?? '' ); } }
if ( ! function_exists( 'wp_remote_retrieve_header' ) ) { function wp_remote_retrieve_header( $r, $h ) { return (string) ( $r['headers'][ strtolower( $h ) ] ?? '' ); } }
if ( ! function_exists( 'wp_remote_retrieve_headers' ) ) { function wp_remote_retrieve_headers( $r ) { return $r['headers'] ?? array(); } }

// ---------------------------------------------------------------------------
// Aparat asercji.
// ---------------------------------------------------------------------------
$fail = 0;
$ran  = 0;
function check( $cond, $label ) {
	global $fail, $ran;
	++$ran;
	echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n";
	if ( ! $cond ) { ++$fail; }
}
function ok_reply( $body, $ct = 'text/html; charset=UTF-8' ) {
	return array( 'response' => array( 'code' => 200 ), 'headers' => array( 'content-type' => $ct ), 'body' => $body );
}
function k20_html( $t = 'Treść pobranej podstrony o zajęciach.' ) { return '<html><body><main>' . $t . '</main></body></html>'; }

// ---------------------------------------------------------------------------
// Ładowanie kodu.
// ---------------------------------------------------------------------------
foreach ( array( 'src/Index/ContentSource.php', 'src/Index/WpContentSource.php', 'src/Index/CrawlQueue.php', 'src/Index/RenderedContentSource.php' ) as $rel ) {
	$abs = AIFAQ_PLUGIN_DIR . $rel;
	if ( is_file( $abs ) ) { require_once $abs; }
}

$Q = 'AIFAQ\Index\CrawlQueue';
$R = 'AIFAQ\Index\RenderedContentSource';
$W = 'AIFAQ\Index\WpContentSource';
$has_q = class_exists( $Q );
$has_r = class_exists( $R );

/**
 * Podklasa ze SZWEM CZASU (§9.3, FZ35): klasyfikacja czyta wyłącznie różnicę z `now_ms()`.
 * Bez tego szwu „klasyfikacja po czasie” jest nietestowalna (timeout 15 s to literał
 * w argumentach żądania, którego §9.3 zabrania zmieniać).
 */
if ( $has_q ) {
	class K20_Queue extends \AIFAQ\Index\CrawlQueue {
		protected function now_ms(): float { return (float) $GLOBALS['__k20_clock']; }
	}
}

/** Ustawia stan crawla; `$extra` dokłada nowe klucze (§9.2). */
function k20_state( array $queue, array $done = array(), array $extra = array() ) {
	$base = array(
		'queue'         => $queue,
		'done'          => $done,
		'total'         => count( $queue ) + count( $done ),
		'started'       => 0,
		'needs_reindex' => false,
		'warnings'      => array(),
	);
	$GLOBALS['__opt']['aifaq_crawl_state'] = array_merge( $base, $extra );
}
function k20_get_state() { return (array) ( $GLOBALS['__opt']['aifaq_crawl_state'] ?? array() ); }
function k20_url( $id ) { return 'https://example.test/?p=' . (int) $id; }
function k20_item( $id ) { return array( 'post_id' => (int) $id, 'url' => k20_url( $id ) ); }
function k20_http_reset( $reply = null, $probe = null ) {
	$GLOBALS['__http'] = array( 'calls' => 0, 'crawl' => 0, 'probe' => 0, 'args' => array(), 'reply' => $reply, 'probe_reply' => $probe );
}
/** Rejestruje wpisy jako indeksowalne strony (pod `seed()`). */
function k20_posts( array $ids ) {
	$GLOBALS['__postdata'] = array();
	$GLOBALS['__posts']    = array();
	foreach ( $ids as $id ) {
		$GLOBALS['__postdata'][ $id ] = array( 'post_status' => 'publish', 'post_type' => 'page', 'post_name' => 'strona-' . $id );
		$o                            = new stdClass();
		$o->ID                        = $id;
		$o->post_name                 = 'strona-' . $id;
		$GLOBALS['__posts'][]         = $o;
	}
	if ( method_exists( 'AIFAQ\Index\WpContentSource', 'reset_indexable_cache' ) ) {
		\AIFAQ\Index\WpContentSource::reset_indexable_cache();
	}
}

$TIMEOUT_ERR = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 15001 milliseconds' );
$PLAIN_ERR   = new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' );

// ===========================================================================
echo "=== A. Wsteczna zgodność — stan BEZ nowych kluczy (§9.2) ===\n";
// ===========================================================================
if ( $has_q ) {
	$GLOBALS['__notices'] = array();
	$GLOBALS['__meta']    = array();
	k20_http_reset( ok_reply( k20_html() ) );
	k20_state( array( k20_item( 61 ) ) );
	$GLOBALS['__k20_dur'] = 0.05;
	( new K20_Queue() )->tick();

	check( 1 === $GLOBALS['__http']['crawl'], 'A1: stan STARY (bez failed/timeouts_in_row/paused) → tick() pobiera 1 URL (jest: ' . $GLOBALS['__http']['crawl'] . ')' );
	check( '' !== (string) get_post_meta( 61, '_aifaq_rendered', true ), 'A2: treść zapisana do _aifaq_rendered — stary stan działa bez zmian' );
	check( array() === $GLOBALS['__notices'], 'A3 (§9.2): ZERO ostrzeżeń PHP na stanie bez nowych kluczy (jest: ' . implode( ' | ', $GLOBALS['__notices'] ) . ')' );
	$st = k20_get_state();
	check( in_array( 61, array_map( 'intval', (array) ( $st['done'] ?? array() ) ), true ), 'A4: polityka „done przed żądaniem” ZOSTAJE — wpis w done' );
	check( array() === (array) ( $st['failed'] ?? array() ), 'A5: po UDANYM pobraniu lista failed jest pusta' );
} else {
	check( false, 'sekcja A pominięta — brak klasy AIFAQ\Index\CrawlQueue' );
}

// ===========================================================================
echo "\n=== B. Lista `failed` i ponowienia przez seed() (§9.2, §9.2-a) ===\n";
// ===========================================================================
if ( $has_q ) {
	$GLOBALS['__notices'] = array();
	$GLOBALS['__meta']    = array();
	k20_http_reset( $PLAIN_ERR );
	k20_state( array( k20_item( 71 ) ) );
	$GLOBALS['__k20_dur'] = 0.2;
	( new K20_Queue() )->tick();

	$st     = k20_get_state();
	$failed = (array) ( $st['failed'] ?? array() );
	check( isset( $failed[71] ), 'B1 (§9.2-a): nieudany adres trafia do failed POD KLUCZEM post_id (klucze: ' . implode( ',', array_keys( $failed ) ) . ')' );
	check( 1 === (int) ( $failed[71]['tries'] ?? 0 ), 'B2: tries === 1 po pierwszej porażce (jest: ' . var_export( $failed[71]['tries'] ?? null, true ) . ')' );
	check( '' !== (string) ( $failed[71]['reason'] ?? '' ), 'B3: zapisany POWÓD porażki (niepusty)' );
	check( (int) ( $failed[71]['last'] ?? 0 ) > 0, 'B4: zapisany znacznik czasu last > 0' );
	check( k20_url( 71 ) === (string) ( $failed[71]['url'] ?? '' ), 'B5: zapisany URL wpisu' );
	check( in_array( 71, array_map( 'intval', (array) ( $st['done'] ?? array() ) ), true ), 'B6 (§9.2): adres NADAL jest w done — polityka niezmieniona, failed jest DODATKIEM' );

	// --- seed(): dokłada z powrotem przy tries < 3 i `last` starszym niż 1 h ---
	k20_posts( array( 71 ) );
	k20_state( array(), array( 71 ), array( 'failed' => array( 71 => array( 'url' => k20_url( 71 ), 'tries' => 1, 'reason' => 'cURL error 28', 'last' => time() - 7200 ) ) ) );
	$added = ( new K20_Queue() )->seed();
	$st    = k20_get_state();
	$ids   = array_map( static function ( $r ) { return (int) $r['post_id']; }, (array) ( $st['queue'] ?? array() ) );
	check( 1 === $added, 'B7 (§9.2): seed() dokłada nieudany wpis z powrotem przy tries=1 (jest: ' . $added . ')' );
	check( array( 71 ) === $ids, 'B8: w kolejce dokładnie ten wpis (jest: ' . implode( ',', $ids ) . ')' );

	// --- twardy limit: tries = 3 → NIGDY ---
	k20_posts( array( 71 ) );
	k20_state( array(), array( 71 ), array( 'failed' => array( 71 => array( 'url' => k20_url( 71 ), 'tries' => 3, 'reason' => 'cURL error 28', 'last' => time() - 7200 ) ) ) );
	$added3 = ( new K20_Queue() )->seed();
	check( 0 === $added3, 'B9 (RYZYKO 9): przy tries = 3 seed() NIE dokłada NIGDY (jest: ' . $added3 . ') — strona wywracająca PHP nie może być pobierana w kółko' );
	check( array() === (array) ( k20_get_state()['queue'] ?? array() ), 'B10: kolejka pozostaje pusta' );

	// --- odstęp: `last` świeższe niż 1 h → nie ponawiamy w tym samym przebiegu ---
	k20_posts( array( 71 ) );
	k20_state( array(), array( 71 ), array( 'failed' => array( 71 => array( 'url' => k20_url( 71 ), 'tries' => 1, 'reason' => 'x', 'last' => time() - 60 ) ) ) );
	$added_fresh = ( new K20_Queue() )->seed();
	check( 0 === $added_fresh, 'B11 (§9.2): `last` młodsze niż 1 h → seed() NIE ponawia (trzy próby nie lecą pod rząd w jednym przebiegu; jest: ' . $added_fresh . ')' );

	// --- FZ41: nowe klucze PRZEŻYWAJĄ odczyt+zapis stanu (state() normalizuje) ---
	k20_posts( array( 71 ) );
	k20_state( array(), array( 71 ), array( 'failed' => array( 71 => array( 'url' => k20_url( 71 ), 'tries' => 2, 'reason' => 'x', 'last' => time() - 7200 ) ), 'timeouts_in_row' => 2 ) );
	( new K20_Queue() )->seed();
	$st2 = k20_get_state();
	check( isset( $st2['failed'][71] ), 'B12 (FZ41): klucz `failed` PRZEŻYWA odczyt+zapis stanu — state() go NIE wycina' );
	check( array_key_exists( 'timeouts_in_row', $st2 ), 'B13 (FZ41): klucz `timeouts_in_row` przeżywa normalizację state()' );

	// --- udane pobranie KASUJE wpis z failed i ZERUJE timeouts_in_row ---
	$GLOBALS['__meta'] = array();
	k20_http_reset( ok_reply( k20_html() ) );
	$GLOBALS['__k20_dur'] = 0.05;
	k20_state( array( k20_item( 71 ) ), array(), array( 'failed' => array( 71 => array( 'url' => k20_url( 71 ), 'tries' => 2, 'reason' => 'x', 'last' => time() - 7200 ) ), 'timeouts_in_row' => 2 ) );
	( new K20_Queue() )->tick();
	$st3 = k20_get_state();
	check( ! isset( $st3['failed'][71] ), 'B14 (§9.2): UDANE pobranie kasuje wpis z failed (tries liczy próby Z RZĘDU)' );
	check( 0 === (int) ( $st3['timeouts_in_row'] ?? -1 ), 'B15 (§9.2, ryzyko 10): udane pobranie ZERUJE timeouts_in_row (jest: ' . var_export( $st3['timeouts_in_row'] ?? null, true ) . ')' );
	check( array() === $GLOBALS['__notices'], 'B16: zero ostrzeżeń PHP w całej sekcji B (jest: ' . implode( ' | ', $GLOBALS['__notices'] ) . ')' );
} else {
	check( false, 'sekcja B pominięta — brak CrawlQueue' );
}

// ===========================================================================
echo "\n=== C. Klasyfikacja PO CZASIE, nie po treści komunikatu (§9.3, FZ35) ===\n";
// ===========================================================================
if ( $has_q ) {
	$GLOBALS['__notices'] = array();

	// >= 0.9 x timeout (0.9 * 15 = 13.5 s) → TIMEOUT.
	k20_http_reset( $PLAIN_ERR );                 // komunikat BEZ „cURL error 28”!
	$GLOBALS['__k20_dur'] = 14.0;
	k20_state( array( k20_item( 81 ) ) );
	( new K20_Queue() )->tick();
	$st = k20_get_state();
	check( 1 === (int) ( $st['timeouts_in_row'] ?? 0 ), 'C1 (§9.3): błąd trwający 14 s (>= 0,9 × 15) → policzony jako TIMEOUT, mimo komunikatu „cURL error 7” (jest: ' . var_export( $st['timeouts_in_row'] ?? null, true ) . ')' );

	// Krótki błąd z komunikatem „cURL error 28” → NIE timeout (obrona przed dopasowaniem po treści).
	k20_http_reset( $TIMEOUT_ERR );
	$GLOBALS['__k20_dur'] = 0.2;
	k20_state( array( k20_item( 82 ) ) );
	( new K20_Queue() )->tick();
	$st = k20_get_state();
	check( 0 === (int) ( $st['timeouts_in_row'] ?? 0 ), 'C2 (§9.3, KLUCZOWA): błąd trwający 0,2 s → ZWYKŁY BŁĄD, choć komunikat zawiera „cURL error 28” (jest: ' . var_export( $st['timeouts_in_row'] ?? null, true ) . ') — dopasowanie po treści jest ZAKAZANE' );
	check( isset( k20_get_state()['failed'][82] ), 'C3: zwykły błąd i tak trafia na listę failed (ponowienie mu przysługuje)' );

	// Udane pobranie trwające długo NIE jest timeoutem.
	$GLOBALS['__meta'] = array();
	k20_http_reset( ok_reply( k20_html() ) );
	$GLOBALS['__k20_dur'] = 14.0;
	k20_state( array( k20_item( 83 ) ) );
	( new K20_Queue() )->tick();
	check( 0 === (int) ( k20_get_state()['timeouts_in_row'] ?? 0 ), 'C4: POWOLNE, ale UDANE pobranie nie jest timeoutem' );
	check( array() === $GLOBALS['__notices'], 'C5: zero ostrzeżeń PHP w sekcji C' );
} else {
	check( false, 'sekcja C pominięta — brak CrawlQueue' );
}

// ===========================================================================
echo "\n=== D. Próg 3 timeoutów; licznik PRZEŻYWA tick (§9.3) ===\n";
// ===========================================================================
// Pointa: przy BATCH_SECONDS = 20 i timeoucie 15 s do JEDNEGO ticka mieszczą się dwa
// pobrania — licznik zamknięty w ticku NIGDY nie osiągnąłby progu.
if ( $has_q ) {
	$GLOBALS['__notices'] = array();
	k20_http_reset( $PLAIN_ERR, ok_reply( '<html><body>Witamy w Przedszkole Testowe</body></html>' ) );
	$GLOBALS['__k20_dur'] = 14.0;
	k20_state( array( k20_item( 91 ) ) );
	( new K20_Queue() )->tick();
	$after1 = k20_get_state();
	check( 1 === (int) ( $after1['timeouts_in_row'] ?? 0 ), 'D1: po PIERWSZYM ticku timeouts_in_row === 1' );
	check( array() === (array) ( $after1['paused'] ?? array() ), 'D2: jeden timeout NIE zapala diagnozy' );

	$st = $after1;
	$st['queue'] = array( k20_item( 92 ) );
	$GLOBALS['__opt']['aifaq_crawl_state'] = $st;
	( new K20_Queue() )->tick();
	$after2 = k20_get_state();
	check( 2 === (int) ( $after2['timeouts_in_row'] ?? 0 ), 'D3 (§9.3, SEDNO): licznik PRZEŻYWA tick — po drugim ticku === 2 (jest: ' . var_export( $after2['timeouts_in_row'] ?? null, true ) . ')' );
	check( array() === (array) ( $after2['paused'] ?? array() ), 'D4: DWA timeouty jeszcze NIE zapalają diagnozy' );
	check( 0 === $GLOBALS['__http']['probe'], 'D5: przed osiągnięciem progu sonda NIE pada (jest: ' . $GLOBALS['__http']['probe'] . ')' );

	$st = $after2;
	$st['queue'] = array( k20_item( 93 ) );
	$GLOBALS['__opt']['aifaq_crawl_state'] = $st;
	( new K20_Queue() )->tick();
	$after3 = k20_get_state();
	check( 3 === (int) ( $after3['timeouts_in_row'] ?? 0 ), 'D6: po trzecim ticku licznik === 3' );
	check( array() !== (array) ( $after3['paused'] ?? array() ), 'D7 (§9.3): TRZY timeouty w TRZECH tickach zapalają diagnozę (klucz paused niepusty)' );
	check( isset( $after3['paused']['since'] ) && (int) $after3['paused']['since'] > 0, 'D8 (§9.2): paused ma klucz since (int > 0)' );
	check( isset( $after3['paused']['verdict'] ) && '' !== (string) $after3['paused']['verdict'], 'D9 (§9.2): paused ma niepusty werdykt' );
	check( array() === $GLOBALS['__notices'], 'D10: zero ostrzeżeń PHP w sekcji D' );
} else {
	check( false, 'sekcja D pominięta — brak CrawlQueue' );
}

// ===========================================================================
echo "\n=== E. Reakcja: JEDNA sonda na epizod, dwa werdykty, pauza (§9.3, FZ36) ===\n";
// ===========================================================================
if ( $has_q && $has_r ) {
	$loop_opt = defined( $R . '::OPTION_LOOPBACK' ) ? (string) constant( $R . '::OPTION_LOOPBACK' ) : 'aifaq_loopback';

	/** Trzy ticki z timeoutem; zwraca stan po zapaleniu progu. */
	$run_episode = static function ( $probe_reply ) use ( $PLAIN_ERR, $loop_opt ) {
		$GLOBALS['__opt'][ $loop_opt ] = array( 'ok' => true, 'message' => 'ZNACZNIK-SPRZED-EPIZODU' );
		k20_http_reset( $PLAIN_ERR, $probe_reply );
		$GLOBALS['__k20_dur'] = 14.0;
		k20_state( array( k20_item( 101 ) ) );
		( new K20_Queue() )->tick();
		foreach ( array( 102, 103 ) as $id ) {
			$st                                    = k20_get_state();
			$st['queue']                           = array( k20_item( $id ) );
			$GLOBALS['__opt']['aifaq_crawl_state'] = $st;
			( new K20_Queue() )->tick();
		}
		return k20_get_state();
	};

	// 1. Sonda PRZECHODZI → werdykt „współbieżność” (serwer żyje, ale nie obsłuży dwóch żądań).
	$GLOBALS['__cron']['aifaq_crawl_tick'] = time() + 60;
	$st_ok    = $run_episode( ok_reply( '<html><body>Witamy w Przedszkole Testowe</body></html>' ) );
	$probes_1 = $GLOBALS['__http']['probe'];
	$verdict_ok = (string) ( $st_ok['paused']['verdict'] ?? '' );
	check( 1 === $probes_1, 'E1 (§9.3 pkt 2): po zapaleniu progu pada DOKŁADNIE JEDNA sonda pętli zwrotnej (jest: ' . $probes_1 . ')' );
	check( '' !== $verdict_ok, 'E2: sonda PRZECHODZI → zapisany werdykt (jest: „' . $verdict_ok . '”)' );
	check( array( 'ok' => true, 'message' => 'ZNACZNIK-SPRZED-EPIZODU' ) === (array) ( $GLOBALS['__opt'][ $loop_opt ] ?? array() ), 'E3 (§9.3 pkt 2): sonda NIE nadpisuje opcji ' . $loop_opt . ' (jedyne miejsce zapisu, TTL 6 h, zasila Dashboard)' );
	check( ! isset( $GLOBALS['__cron']['aifaq_crawl_tick'] ), 'E4 (§9.3 pkt 1): crawl PAUZUJE — zadanie cron odwieszone' );

	// Kolejny tick w stanie `paused`: bez sondy, bez pobrań.
	$st              = k20_get_state();
	$st['queue']     = array( k20_item( 104 ) );
	$GLOBALS['__opt']['aifaq_crawl_state'] = $st;
	$GLOBALS['__http']['crawl'] = 0;
	$GLOBALS['__http']['probe'] = 0;
	( new K20_Queue() )->tick();
	check( 0 === $GLOBALS['__http']['crawl'], 'E5 (§9.3 pkt 1): tick() przy niepustym paused WYCHODZI NATYCHMIAST — zero pobrań (jest: ' . $GLOBALS['__http']['crawl'] . ')' );
	check( 0 === $GLOBALS['__http']['probe'], 'E6 (§9.3 pkt 2): sonda NAJWYŻEJ RAZ NA EPIZOD — kolejny tick jej nie powtarza (jest: ' . $GLOBALS['__http']['probe'] . ') → jeden komunikat, nie dziesięć' );

	// 2. Sonda NIE przechodzi → werdykt „nieosiągalne”, INNY niż wyżej.
	$GLOBALS['__cron']['aifaq_crawl_tick'] = time() + 60;
	$st_bad      = $run_episode( new WP_Error( 'http_request_failed', 'nieosiagalne' ) );
	$verdict_bad = (string) ( $st_bad['paused']['verdict'] ?? '' );
	check( 1 === $GLOBALS['__http']['probe'], 'E7: także w tym scenariuszu sonda pada DOKŁADNIE raz (jest: ' . $GLOBALS['__http']['probe'] . ')' );
	check( '' !== $verdict_bad, 'E8: sonda NIE przechodzi → zapisany werdykt (jest: „' . $verdict_bad . '”)' );
	check( $verdict_ok !== $verdict_bad, 'E9 (§9.3, ryzyko 10): werdykty RÓŻNIĄ SIĘ — „serwer nie obsłuży dwóch żądań” vs „strony nieosiągalne” (jest: „' . $verdict_ok . '” vs „' . $verdict_bad . '”)' );
	check( array( 'ok' => true, 'message' => 'ZNACZNIK-SPRZED-EPIZODU' ) === (array) ( $GLOBALS['__opt'][ $loop_opt ] ?? array() ), 'E10: opcja ' . $loop_opt . ' nietknięta także przy nieudanej sondzie' );
} else {
	check( false, 'sekcja E pominięta — brak CrawlQueue albo RenderedContentSource' );
}

// ===========================================================================
echo "\n=== F. Zakazy i liczby zamrożone (§9.3) ===\n";
// ===========================================================================
if ( $has_q ) {
	// `crawl_enabled` NIGDY nie jest gaszone automatycznie (Settings::on_settings_updated()
	// czyściłby kolejkę → SKASOWANA pobrana treść).
	$bad_writes = array();
	foreach ( $GLOBALS['__writes'] as $w ) {
		if ( 'aifaq_settings' === $w['key'] && is_array( $w['value'] ) && '0' === (string) ( $w['value']['crawl_enabled'] ?? '' ) ) {
			$bad_writes[] = 'aifaq_settings[crawl_enabled]=0';
		}
		if ( false !== strpos( (string) $w['key'], 'crawl_enabled' ) ) {
			$bad_writes[] = (string) $w['key'];
		}
	}
	check( array() === $bad_writes, 'F1 (§9.3, ZAKAZ): crawl_enabled NIGDY nie jest zapisywane jako „0” (jest: ' . ( $bad_writes ? implode( ',', $bad_writes ) : 'brak' ) . ')' );

	check( 10 === (int) constant( $Q . '::BATCH_MAX' ), 'F2: BATCH_MAX === 10 (BEZ ZMIAN)' );
	check( 20 === (int) constant( $Q . '::BATCH_SECONDS' ), 'F3: BATCH_SECONDS === 20 (BEZ ZMIAN)' );
	check( 'aifaq_crawl_state' === (string) constant( $Q . '::OPTION' ), 'F4: OPTION === aifaq_crawl_state (BEZ ZMIAN)' );

	// Argumenty żądania — timeout 15, redirection 2, bez ciasteczek.
	$GLOBALS['__meta'] = array();
	k20_http_reset( ok_reply( k20_html() ) );
	$GLOBALS['__k20_dur'] = 0.05;
	k20_state( array( k20_item( 111 ) ) );
	( new K20_Queue() )->tick();
	$args = $GLOBALS['__http']['args'][0]['args'] ?? array();
	check( 15 === (int) ( $args['timeout'] ?? 0 ), 'F5: timeout żądania === 15 (BEZ ZMIAN; jest: ' . var_export( $args['timeout'] ?? null, true ) . ')' );
	check( 2 === (int) ( $args['redirection'] ?? 0 ), 'F6: redirection === 2 (BEZ ZMIAN)' );
	check( isset( $args['cookies'] ) && array() === $args['cookies'], 'F7: cookies === array() — crawl nadal idzie JAKO GOŚĆ (regresja K17)' );
	check( isset( $args['headers']['X-AIFAQ-Crawl'] ), 'F8: nagłówek X-AIFAQ-Crawl obecny (ochrona przed rekurencją)' );
} else {
	check( false, 'sekcja F pominięta — brak CrawlQueue' );
}

// ===========================================================================
echo "\n=== Z. Podłoga pokrycia i wartownik końca pliku ===\n";
// ===========================================================================
check( array() === $GLOBALS['__notices'], 'Z0: ZERO ostrzeżeń PHP w całym pliku (jest: ' . implode( ' | ', $GLOBALS['__notices'] ) . ')' );
check( $ran >= 35, 'Z1: podłoga pokrycia — wykonano co najmniej 35 asercji (jest: ' . $ran . ')' );
check( true, 'Z2 WARTOWNIK: wykonanie doszło do końca pliku (brak cichego Fatala w środku)' );

restore_error_handler();
echo "\nAsercje: " . $ran . ', niezaliczone: ' . $fail . "\n";
exit( 0 === $fail ? 0 : 1 );
