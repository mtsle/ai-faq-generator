<?php
/**
 * Testy: Historia — dziennik pytań gości (Krok 10).
 *
 * Broni trzech rzeczy, na których ten krok stoi:
 *  - WHITELIST STATUSU: do SQL trafia wyłącznie wartość z QaLogRepository::STATUSES.
 *    Filtr przychodzi z URL-a, więc jest to wejście użytkownika przy klauzuli WHERE.
 *  - MINIMALIZACJA (GR7): odpowiedź `/admin/history` NIE zawiera ip_hash ani user_id —
 *    pseudonimowy identyfikator gościa zostaje w bazie.
 *  - Stronicowanie: clamp limitu (1..100), nieujemny offset, wyliczenie liczby stron
 *    i cofnięcie strony poza zakresem (np. tuż po wyczyszczeniu dziennika).
 * Dodatkowo: kontrakt HistoryPanel (komplet kluczy i18n, nieznany język → pl, markup
 * z identyfikatorami, których szuka app.js).
 *
 * URUCHOMIENIE:  php tests/krok10-history-test.php
 * Kod wyjścia: 0 = OK, 1 = błędy.
 *
 * @package AI_FAQ_Generator
 */

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }

// --- shimy funkcji WP ---
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_url_raw' ) ) { function esc_url_raw( $s ) { return (string) $s; } }
if ( ! function_exists( 'wp_unslash' ) ) { function wp_unslash( $s ) { return is_string( $s ) ? stripslashes( $s ) : $s; } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ) { return trim( (string) preg_replace( '/<[^>]*>/', '', (string) $s ) ); } }
if ( ! function_exists( 'sanitize_textarea_field' ) ) { function sanitize_textarea_field( $s ) { return trim( (string) preg_replace( '/<[^>]*>/', '', (string) $s ) ); } }
if ( ! function_exists( 'wp_salt' ) ) { function wp_salt( $scheme = 'auth' ) { return 'aifaq-test-salt'; } }
if ( ! function_exists( 'wp_create_nonce' ) ) { function wp_create_nonce( $a = '' ) { return 'nonce-abc123'; } }
if ( ! function_exists( 'rest_url' ) ) { function rest_url( $p = '' ) { return 'http://test.local/wp-json/' . ltrim( (string) $p, '/' ); } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $p = '' ) { return 'http://test.local/wp-admin/' . $p; } }
if ( ! function_exists( '__return_true' ) ) { function __return_true() { return true; } }
if ( ! function_exists( 'current_user_can' ) ) { function current_user_can( $cap ) { return true; } }
if ( ! function_exists( 'register_rest_route' ) ) { function register_rest_route( $ns, $route, $args = array() ) { return true; } }
if ( ! function_exists( 'current_time' ) ) { function current_time( $type = 'mysql' ) { return 'timestamp' === $type ? 1770000000 : '2026-02-02 03:20:00'; } }
if ( ! function_exists( 'mysql2date' ) ) { function mysql2date( $f, $d, $t = true ) { return 'DATE(' . $d . ')'; } }
if ( ! function_exists( 'get_option' ) ) { function get_option( $k, $d = false ) { return 'date_format' === $k ? 'Y-m-d' : ( 'time_format' === $k ? 'H:i' : $d ); } }

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code; private $message;
		public function __construct( $code = '', $message = '', $data = '' ) { $this->code = $code; $this->message = $message; }
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
	}
}
if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		private $params = array();
		public function set_param( $k, $v ) { $this->params[ $k ] = $v; }
		public function get_param( $k ) { return $this->params[ $k ] ?? null; }
	}
}
if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		private $data; private $status;
		public function __construct( $data = null, $status = 200 ) { $this->data = $data; $this->status = (int) $status; }
		public function get_data() { return $this->data; }
		public function get_status() { return $this->status; }
	}
}

/**
 * $wpdb, który ZAPAMIĘTUJE zapytania — na tym opiera się test whitelisty.
 */
class SpyWpdb {
	public $prefix = 'wp_';
	public $queries = array();
	public $rows    = array();
	public $total   = 0;
	public $deleted = 0;

	public function prepare( $q, ...$a ) {
		// Uproszczone %s/%d wystarczy: w naszych zapytaniach nie ma innych znaczników.
		$q = str_replace( '%s', "'%s'", $q );
		return $a ? vsprintf( $q, $a ) : $q;
	}
	public function get_results( $q, $o = null ) { $this->queries[] = $q; return $this->rows; }
	public function get_var( $q ) { $this->queries[] = $q; return $this->total; }
	public function get_row( $q, $o = null ) {
		$this->queries[] = $q;
		return array(
			'total' => 9, 'today' => 2, 'week' => 5, 'answered' => 6,
			'refused' => 2, 'errors' => 1, 'cached' => 3, 'avg_score' => 0.8123,
		);
	}
	public function query( $q ) { $this->queries[] = $q; return $this->deleted; }
	public function last() { return end( $this->queries ); }
	public function reset() { $this->queries = array(); }
}
$GLOBALS['wpdb'] = new SpyWpdb();

// --- harness ---
$fail = 0;
function check( $cond, $label ) { global $fail; echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n"; if ( ! $cond ) { $fail++; } }

// --- ładowanie kodu ---
require __DIR__ . '/../src/Data/Schema.php';
require __DIR__ . '/../src/Data/Repository.php';
require __DIR__ . '/../src/Data/QaLogRepository.php';
require __DIR__ . '/../src/Rag/RagService.php';
require __DIR__ . '/../src/App/HistoryPanel.php';
require __DIR__ . '/../src/Rest/RestController.php';

use AIFAQ\App\HistoryPanel;
use AIFAQ\Data\QaLogRepository;
use AIFAQ\Rest\RestController;

$wpdb = $GLOBALS['wpdb'];
$repo = new QaLogRepository();

echo "== page(): whitelist statusu ==\n";
foreach ( QaLogRepository::STATUSES as $st ) {
	$wpdb->reset();
	$repo->page( 20, 0, $st );
	check( false !== strpos( $wpdb->last(), "status = '{$st}'" ), "status '{$st}' → WHERE status = '{$st}'" );
}

$wpdb->reset();
$repo->page( 20, 0, '' );
check( false === strpos( $wpdb->last(), 'WHERE' ), "pusty status → BEZ klauzuli WHERE (wszystkie wpisy)" );

// Najważniejsza asercja kroku: wstrzyknięcie przez filtr statusu.
$wpdb->reset();
$repo->page( 20, 0, "answered' OR 1=1 --" );
check( false === strpos( $wpdb->last(), 'OR 1=1' ), 'ANTI-SQLi: status spoza whitelisty NIE trafia do SQL' );
check( false === strpos( $wpdb->last(), 'WHERE' ), 'ANTI-SQLi: nieznany status → zapytanie bez WHERE (nie „udaje” filtra)' );

echo "\n== page(): clamp limitu i offsetu ==\n";
$wpdb->reset();
$repo->page( 9999, 0, '' );
check( false !== strpos( $wpdb->last(), 'LIMIT 100' ), 'limit 9999 → sklampowany do 100' );
$wpdb->reset();
$repo->page( 0, 0, '' );
check( false !== strpos( $wpdb->last(), 'LIMIT 1' ), 'limit 0 → sklampowany do 1' );
$wpdb->reset();
$repo->page( 20, -50, '' );
check( false !== strpos( $wpdb->last(), 'OFFSET 0' ), 'ujemny offset → 0' );
$wpdb->reset();
$repo->page( 20, 40, '' );
check( false !== strpos( $wpdb->last(), 'OFFSET 40' ), 'offset przekazany wprost' );
check( false !== strpos( $wpdb->last(), 'ORDER BY created_at DESC' ), 'sortowanie: najnowsze najpierw' );

echo "\n== count_by() ==\n";
$wpdb->reset();
$repo->count_by( 'refused' );
check( false !== strpos( $wpdb->last(), "status = 'refused'" ), 'count_by(refused) → WHERE status' );
$wpdb->reset();
$repo->count_by( 'nonsens' );
check( false === strpos( $wpdb->last(), 'WHERE' ), 'count_by(nieznany) → liczy wszystko (bez WHERE)' );

echo "\n== stats() ==\n";
$stats = $repo->stats();
check( 9 === $stats['total'] && 2 === $stats['today'] && 5 === $stats['week'], 'liczby przepisane jako int' );
check( 2 === $stats['refused'] && 1 === $stats['errors'] && 3 === $stats['cached'], 'refused/errors/cached jako int' );
check( 0.812 === $stats['avg_score'], 'avg_score zaokrąglone do 3 miejsc' );

echo "\n== purge() ==\n";
$wpdb->reset();
$wpdb->deleted = 7;
check( 7 === $repo->purge(), 'purge() zwraca liczbę usuniętych' );
check( 0 === strpos( $wpdb->last(), 'DELETE FROM' ), 'purge() wykonuje DELETE' );
$wpdb->deleted = false;
check( 0 === $repo->purge(), 'błąd zapytania → 0 (nie false)' );
$wpdb->deleted = 0;

echo "\n== REST /admin/history ==\n";
$wpdb->rows = array(
	array(
		'id' => 5, 'created_at' => '2026-02-02 03:00:00', 'question' => 'Ile mleka daje krowa?',
		'answer' => 'Około 25 litrów.', 'status' => 'answered', 'source' => 'ai', 'score' => 0.8412,
		'user_id' => 3, 'ip_hash' => 'deadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeef',
	),
);
$wpdb->total = 45;

$ctl = new RestController();
$req = new WP_REST_Request();
$req->set_param( 'page', 2 );
$req->set_param( 'per_page', 20 );
$req->set_param( 'status', '' );
$res  = $ctl->handle_history( $req );
$data = $res->get_data();
$json = json_encode( $data );

check( 200 === $res->get_status(), 'HTTP 200' );
check( 'ok' === $data['status'], 'status = ok' );
check( 45 === $data['total'] && 3 === $data['pages'], '45 wpisów / 20 na stronę → 3 strony' );
check( 2 === $data['page'], 'strona przepisana' );

// GR7 — minimalizacja danych gościa.
check( false === strpos( $json, 'ip_hash' ), 'ANTI-LEAK: brak pola ip_hash w odpowiedzi' );
check( false === strpos( $json, 'deadbeef' ), 'ANTI-LEAK: brak wartości ip_hash w odpowiedzi' );
check( false === strpos( $json, 'user_id' ), 'ANTI-LEAK: brak user_id w odpowiedzi' );

$item = $data['items'][0];
check( 'Ile mleka daje krowa?' === $item['question'], 'pytanie przepisane bez zmian (escapuje dopiero render)' );
check( 0.84 === $item['score'], 'score zaokrąglony do 2 miejsc' );
check( isset( $item['date'], $item['iso'] ), 'data w wersji do wyświetlenia i ISO' );
check( array( 'id', 'date', 'iso', 'question', 'answer', 'status', 'source', 'score' ) === array_keys( $item ), 'dokładnie 8 pól wiersza (bez nadmiaru)' );

echo "\n== REST /admin/history — walidacja parametrów ==\n";
$wpdb->reset();
$req2 = new WP_REST_Request();
$req2->set_param( 'page', -3 );
$req2->set_param( 'per_page', 9999 );
$req2->set_param( 'status', "refused'; DROP TABLE x; --" );
$res2  = $ctl->handle_history( $req2 );
$data2 = $res2->get_data();
check( 100 === $data2['per_page'], 'per_page 9999 → 100' );
check( $data2['page'] >= 1, 'ujemna strona → co najmniej 1' );
$sqls = implode( ' || ', $wpdb->queries );
check( false === strpos( $sqls, 'DROP TABLE' ), 'ANTI-SQLi: śmieciowy status nie dociera do SQL' );

// Strona poza zakresem — po wyczyszczeniu dziennika.
$wpdb->total = 5;
$req3 = new WP_REST_Request();
$req3->set_param( 'page', 99 );
$req3->set_param( 'per_page', 20 );
$req3->set_param( 'status', '' );
$data3 = $ctl->handle_history( $req3 )->get_data();
check( 1 === $data3['page'] && 1 === $data3['pages'], 'strona poza zakresem → cofnięta do ostatniej istniejącej' );

$wpdb->total = 0;
$req4 = new WP_REST_Request();
$req4->set_param( 'page', 1 );
$req4->set_param( 'per_page', 20 );
$req4->set_param( 'status', '' );
$data4 = $ctl->handle_history( $req4 )->get_data();
check( 0 === $data4['pages'] && 0 === $data4['total'], 'pusty dziennik → 0 stron, brak dzielenia przez zero' );

echo "\n== REST /admin/history/clear ==\n";
$wpdb->deleted = 12;
$resc = $ctl->handle_history_clear( new WP_REST_Request() );
$datc = $resc->get_data();
check( 200 === $resc->get_status() && 'ok' === $datc['status'], 'HTTP 200 + ok' );
check( 12 === $datc['removed'], 'removed = liczba usuniętych' );
check( isset( $datc['stats'] ), 'odsyła świeże podsumowanie' );

echo "\n== HistoryPanel::strings() ==\n";
$pl = HistoryPanel::strings( 'pl' );
$en = HistoryPanel::strings( 'en' );
$de = HistoryPanel::strings( 'de' );
check( $pl === HistoryPanel::strings( 'klingon' ), 'nieznany język → pl' );
check( array_keys( $pl ) === array_keys( $en ) && array_keys( $pl ) === array_keys( $de ), 'komplet tych samych kluczy w pl/en/de' );
foreach ( array( 'histTitle', 'histEmpty', 'histError', 'histPurgeConf', 'stAnswered', 'stRefused', 'stError', 'srcAi', 'srcCache' ) as $k ) {
	check( ! empty( $pl[ $k ] ), "klucz {$k} niepusty" );
}

echo "\n== HistoryPanel::widget() — kontrakt z app.js ==\n";
$html = HistoryPanel::widget( $pl );
foreach ( array( 'aifaq-hist', 'aifaq-hist-list', 'aifaq-hist-status', 'aifaq-hist-count', 'aifaq-hist-purge', 'aifaq-hist-pager', 'aifaq-hist-page', 'aifaq-hist-prev', 'aifaq-hist-next' ) as $id ) {
	check( false !== strpos( $html, 'id="' . $id . '"' ), "markup ma #{$id}" );
}
foreach ( array( 'total', 'today', 'week', 'refused', 'cached', 'avgscore' ) as $tile ) {
	check( false !== strpos( $html, 'id="aifaq-hist-' . $tile . '"' ), "kafelek #aifaq-hist-{$tile}" );
}
check( substr_count( $html, '<option' ) === 4, 'filtr: wszystkie + 3 statusy' );
check( false === strpos( $html, '<script' ), 'markup nie wnosi inline JS' );

// Panel to pusta powłoka — dane wstawia JS przez textContent (anti-XSS).
check( false === strpos( $html, 'Ile mleka' ), 'widget() nie renderuje wierszy serwerowo (wypełnia je JS)' );

echo "\n== ANTI-XSS: teksty panelu przechodzą przez esc_html ==\n";
$evil = HistoryPanel::strings( 'pl' );
$evil['histTitle'] = '<script>alert(1)</script>';
$html2 = HistoryPanel::widget( $evil );
check( false === strpos( $html2, '<script>alert(1)</script>' ), 'tekst z HTML NIE trafia do markupu surowo' );
check( false !== strpos( $html2, '&lt;script&gt;' ), 'tekst z HTML zescapowany' );

echo "\n" . ( $fail ? "FAIL: {$fail} asercji nie przeszło\n" : "=== WSZYSTKIE OK ===\n" );
exit( $fail ? 1 : 0 );
