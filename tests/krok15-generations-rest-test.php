<?php
/**
 * Testy Kroku 15 — trasa szczegółu historii generowań + i18n panelu.
 *
 * Pokrywa (bez pełnego środowiska WP — shimy + atrapa $wpdb):
 *  - `GET /admin/generations/detail`: `id<=0` → 400, brak wiersza → 404, wiersz → 200 z `item.pairs`;
 *  - **kształt `item` jest identyczny z elementem `items[]` z listy plus jedyny dodatkowy klucz `pairs`**
 *    (jeden kształt = jeden kod renderujący po stronie JS — wymóg KONTRAKT k15-v1 §1);
 *  - normalizacja snapshotu par: elementy nie-tablicowe i nieskalarne `question`/`answer` odrzucane
 *    (rzutowanie tablicy na string dałoby „Array to string conversion" — realny błąd złapany w K11),
 *    lista klampowana do `Exporter::MAX_PAIRS`;
 *  - i18n `GenerationsPanel::strings()`: parytet kluczy pl/en/de + komplet kluczy z KONTRAKT §4A.
 *
 * Asercje ilościowe celowo jako `=== N`, nigdy `> 0` — słaba asercja („są jakieś pary") przepuściła
 * w tym projekcie realny błąd na dwa wydania.
 *
 * URUCHOMIENIE:  php tests/krok15-generations-rest-test.php
 * Kod wyjścia: 0 = OK, 1 = błędy.
 *
 * @package AI_FAQ_Generator
 */

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }

// --- shimy funkcji WP ---
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); } }
if ( ! function_exists( 'esc_url_raw' ) ) { function esc_url_raw( $s ) { return (string) $s; } }
if ( ! function_exists( 'wp_unslash' ) ) { function wp_unslash( $s ) { return is_string( $s ) ? stripslashes( $s ) : $s; } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ) { return trim( (string) preg_replace( '/<[^>]*>/', '', (string) $s ) ); } }
if ( ! function_exists( 'sanitize_textarea_field' ) ) { function sanitize_textarea_field( $s ) { return trim( (string) preg_replace( '/<[^>]*>/', '', (string) $s ) ); } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $d ) { return json_encode( $d ); } }
if ( ! function_exists( 'current_time' ) ) { function current_time( $t = 'mysql' ) { return '2026-07-18 12:00:00'; } }
if ( ! function_exists( 'get_current_user_id' ) ) { function get_current_user_id() { return 5; } }
if ( ! function_exists( 'mysql2date' ) ) { function mysql2date( $f, $d, $t = true ) { return 'DATE(' . $d . ')'; } }
if ( ! function_exists( 'get_userdata' ) ) {
	function get_userdata( $id ) { $o = new stdClass(); $o->display_name = ( 5 === (int) $id ) ? 'Admin' : ''; return $o; }
}
if ( ! function_exists( 'get_option' ) ) { function get_option( $k, $d = false ) { return $d; } }
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $t ) { return $t instanceof WP_Error; } }
if ( ! function_exists( 'current_user_can' ) ) { function current_user_can( $c ) { return true; } }
if ( ! function_exists( '__return_true' ) ) { function __return_true() { return true; } }
if ( ! function_exists( 'register_rest_route' ) ) { function register_rest_route( $ns, $r, $a = array() ) { return true; } }
if ( ! function_exists( 'rest_url' ) ) { function rest_url( $p = '' ) { return 'https://example.test/wp-json/' . ltrim( (string) $p, '/' ); } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . ltrim( (string) $p, '/' ); } }
if ( ! function_exists( 'wp_create_nonce' ) ) { function wp_create_nonce( $a = '' ) { return 'nonce'; } }

// --- shimy klas WP ---
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code; private $message;
		public function __construct( $code = '', $message = '', $data = '' ) { $this->code = $code; $this->message = $message; }
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

require __DIR__ . '/../src/Data/Schema.php';
require __DIR__ . '/../src/Data/Repository.php';
require __DIR__ . '/../src/Data/GenerationRepository.php';
require __DIR__ . '/../src/Faq/Exporter.php';
require __DIR__ . '/../src/App/HistoryPanel.php';
require __DIR__ . '/../src/App/GenerationsPanel.php';
require __DIR__ . '/../src/Rest/RestController.php';

use AIFAQ\App\GenerationsPanel;
use AIFAQ\Faq\Exporter;
use AIFAQ\Rest\RestController;

$fail = 0;
function check( $cond, $label ) { global $fail; echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n"; if ( ! $cond ) { $fail++; } }

/**
 * Atrapa $wpdb — oddaje zaprogramowany wiersz (albo null) i listę wierszy.
 */
class GhSpyWpdb {
	public $prefix = 'wp_';
	public $row    = null;   // wiersz oddawany przez get_row()
	public $rows   = array(); // wiersze oddawane przez get_results()
	public $var    = 0;
	public function prepare( $q, ...$a ) { return $q; }
	public function get_row( $q, $out = ARRAY_A ) { return $this->row; }
	public function get_results( $q, $out = ARRAY_A ) { return $this->rows; }
	public function get_var( $q ) { return $this->var; }
	public function insert( $t, $d ) { return 1; }
	public function delete( $t, $w ) { return 1; }
}
global $wpdb;
$wpdb = new GhSpyWpdb();

$controller = new RestController();

/** Buduje wiersz bazy z zadanym snapshotem par. */
function gh_row( $pairs_json, $id = 42 ) {
	return array(
		'id'            => $id,
		'created_at'    => '2026-07-18 12:34:56',
		'topic'         => 'Krowy mleczne',
		'extra_desc'    => 'Opis kontrolny',
		'num_questions' => 3,
		'language'      => 'pl',
		'user_id'       => 5,
		'pairs_json'    => $pairs_json,
	);
}
function gh_request( $id ) { $r = new WP_REST_Request(); $r->set_param( 'id', $id ); return $r; }

// ===========================================================================
echo "=== A. Walidacja identyfikatora ===\n";
foreach ( array( 0, -1, -999 ) as $bad ) {
	$resp = $controller->handle_generation_detail( gh_request( $bad ) );
	check( 400 === $resp->get_status(), "id={$bad} → HTTP 400" );
	check( 'error' === ( $resp->get_data()['status'] ?? '' ), "id={$bad} → status error" );
}

echo "\n=== B. Brak wiersza → 404 ===\n";
$wpdb->row = null;
$resp = $controller->handle_generation_detail( gh_request( 12345 ) );
check( 404 === $resp->get_status(), 'nieistniejące id → HTTP 404' );
check( 'error' === ( $resp->get_data()['status'] ?? '' ), '404 → status error' );

echo "\n=== C. Wiersz → 200 + pary ===\n";
$pairs = array(
	array( 'question' => 'Ile mleka daje krowa?', 'answer' => 'Około 25 litrów.' ),
	array( 'question' => 'Czym się żywią?', 'answer' => 'Paszą i sianem.' ),
	array( 'question' => 'Jak często się doi?', 'answer' => 'Dwa razy dziennie.' ),
);
$wpdb->row = gh_row( wp_json_encode( $pairs ) );
$resp = $controller->handle_generation_detail( gh_request( 42 ) );
$data = $resp->get_data();
$item = $data['item'] ?? array();

check( 200 === $resp->get_status(), 'istniejące id → HTTP 200' );
check( 'ok' === ( $data['status'] ?? '' ), 'status ok' );
check( is_array( $item ), 'odpowiedź niesie item' );
check( 42 === ( $item['id'] ?? 0 ), 'item.id = żądane id' );
check( 'Krowy mleczne' === ( $item['topic'] ?? '' ), 'item.topic z wiersza' );
check( 'Opis kontrolny' === ( $item['extra_desc'] ?? '' ), 'item.extra_desc z wiersza' );
check( 'Admin' === ( $item['user'] ?? '' ), 'item.user = etykieta użytkownika (nie user_id)' );
check( ! isset( $item['user_id'] ), 'item NIE wystawia surowego user_id' );
check( ! isset( $item['pairs_json'] ), 'item NIE wystawia surowego pairs_json' );
check( 3 === count( $item['pairs'] ?? array() ), 'item.pairs ma DOKŁADNIE 3 pary' );
check( 'Ile mleka daje krowa?' === ( $item['pairs'][0]['question'] ?? '' ), 'pierwsza para: question' );
check( 'Około 25 litrów.' === ( $item['pairs'][0]['answer'] ?? '' ), 'pierwsza para: answer' );

echo "\n=== D. Kształt item = kształt elementu listy + `pairs` (KONTRAKT §1) ===\n";
// Element listy budowany tym samym prywatnym builderem — porównujemy zestawy kluczy.
$ref  = new ReflectionMethod( RestController::class, 'generation_item' );
$ref->setAccessible( true );
$list_item = $ref->invoke( $controller, gh_row( wp_json_encode( $pairs ) ), 'd.m.Y' );

$only_in_detail = array_diff( array_keys( $item ), array_keys( $list_item ) );
$only_in_list   = array_diff( array_keys( $list_item ), array_keys( $item ) );
check( array( 'pairs' ) === array_values( $only_in_detail ), 'jedyna nadwyżka szczegółu to `pairs`' );
check( empty( $only_in_list ), 'szczegół nie gubi żadnego klucza z listy' );

echo "\n=== E. Normalizacja snapshotu par ===\n";
$brudne = array(
	array( 'question' => 'Dobre pytanie', 'answer' => 'Dobra odpowiedź' ),
	array( 'question' => array( 'tablica' ), 'answer' => 'nieskalarne Q' ), // odrzucone
	array( 'question' => 'nieskalarne A', 'answer' => array( 'x' ) ),        // odrzucone
	'nie-tablica',                                                            // odrzucone
	array( 'answer' => 'brak klucza question' ),                              // odrzucone (puste Q)
);
$wpdb->row = gh_row( wp_json_encode( $brudne ) );
$resp = $controller->handle_generation_detail( gh_request( 42 ) );
$got  = $resp->get_data()['item']['pairs'] ?? array();
check( 1 === count( $got ), 'ze śmieciowego snapshotu została DOKŁADNIE 1 poprawna para' );
check( 'Dobre pytanie' === ( $got[0]['question'] ?? '' ), 'zachowana właściwa para' );

$wpdb->row = gh_row( 'to nie jest JSON' );
$resp = $controller->handle_generation_detail( gh_request( 42 ) );
check( 200 === $resp->get_status(), 'uszkodzony pairs_json nie wywraca trasy (nadal 200)' );
check( 0 === count( $resp->get_data()['item']['pairs'] ?? array() ), 'uszkodzony snapshot → pusta lista par' );

echo "\n=== F. Klamp liczby par do Exporter::MAX_PAIRS ===\n";
$duzo = array();
for ( $i = 0; $i < 70; $i++ ) { $duzo[] = array( 'question' => "Q$i", 'answer' => "A$i" ); }
$wpdb->row = gh_row( wp_json_encode( $duzo ) );
$resp = $controller->handle_generation_detail( gh_request( 42 ) );
$got  = $resp->get_data()['item']['pairs'] ?? array();
check( Exporter::MAX_PAIRS === count( $got ), 'lista par klampowana do MAX_PAIRS (' . Exporter::MAX_PAIRS . ')' );

// ===========================================================================
echo "\n=== G. i18n GenerationsPanel — parytet pl/en/de + komplet z KONTRAKT §4A ===\n";
$wymagane = array(
	'ghTab', 'ghTitle', 'ghDesc', 'ghLoading', 'ghEmpty', 'ghError',
	'ghCountFmt', 'ghPairsFmt', 'ghDescFmt', 'ghNoUser',
	'ghPrev', 'ghNext', 'ghPageFmt', 'ghRegen', 'ghDelete',
	'ghDeleteConf', 'ghDeleting', 'ghDeleted', 'ghDeleteErr',
	'ghPairsLoad', 'ghPairsEmpty', 'ghPairsErr', 'ghCopyAll', 'ghCopied',
);
$pl = GenerationsPanel::strings( 'pl' );
$en = GenerationsPanel::strings( 'en' );
$de = GenerationsPanel::strings( 'de' );

$brak_pl = array_diff( $wymagane, array_keys( $pl ) );
check( empty( $brak_pl ), 'pl ma komplet kluczy z §4A' . ( empty( $brak_pl ) ? '' : ' — brakuje: ' . implode( ', ', $brak_pl ) ) );
check( array_keys( $pl ) === array_keys( $en ), 'en ma DOKŁADNIE ten sam zestaw kluczy co pl' );
check( array_keys( $pl ) === array_keys( $de ), 'de ma DOKŁADNIE ten sam zestaw kluczy co pl' );

$puste = array();
foreach ( array( 'pl' => $pl, 'en' => $en, 'de' => $de ) as $lang => $set ) {
	foreach ( $set as $k => $v ) { if ( ! is_string( $v ) || '' === trim( $v ) ) { $puste[] = "$lang.$k"; } }
}
check( empty( $puste ), 'żaden string nie jest pusty' . ( empty( $puste ) ? '' : ' — puste: ' . implode( ', ', $puste ) ) );
check( GenerationsPanel::strings( 'xx' ) === $pl, 'nieznany język → fallback na pl' );

echo "\n=== H. Stała parametru regeneracji (jedno źródło prawdy) ===\n";
check( 'aifaq_regen' === GenerationsPanel::REGEN_PARAM, 'REGEN_PARAM = aifaq_regen (KONTRAKT §2)' );
check( 20 === GenerationsPanel::PER_PAGE, 'PER_PAGE = 20' );

// Prefiks gh* nie może kolidować z kluczami dziennika pytań (array_merge w AppShell).
$kolizje = array_intersect( array_keys( $pl ), array_keys( \AIFAQ\App\HistoryPanel::strings( 'pl' ) ) );
check( empty( $kolizje ), 'brak kolizji kluczy z HistoryPanel' . ( empty( $kolizje ) ? '' : ' — kolidują: ' . implode( ', ', $kolizje ) ) );

echo "\n=== PODSUMOWANIE ===\n";
echo ( 0 === $fail ) ? "TEST KROK 15 (historia generowan): WSZYSTKIE ASERCJE OK\n" : "TEST KROK 15 (historia generowan): $fail ASERCJI NIE PRZESZLO\n";
exit( $fail === 0 ? 0 : 1 );
