<?php
/**
 * Testy REST generatora FAQ (Krok 12) — handlery na atrapach (bez sieci, bez WP).
 *
 * Pokrywa kontrakt trzech tras panelu generatora:
 *  - validate_topic: pusty temat → 400; poprawny → true.
 *  - POST /admin/generate-faq:
 *      * sukces (atrapa providera zwraca pary) → 200, status ok, id>0, pary, ZAPIS do repo;
 *      * błąd providera (WP_Error) → 502, BEZ wycieku surowego błędu, BEZ zapisu;
 *      * brak par (model zwraca []) → 200 status 'empty', BEZ zapisu;
 *      * clamp liczby pytań do 5..20 na poziomie REST (widoczny w promptcie providera).
 *  - GET /admin/generations: kształt listy (metadane + extra_desc), total/pages, clamp strony.
 *  - POST /admin/generations/delete: id<=0 → 400; poprawne id → 200 + delete w repo.
 *
 * Uwierzytelnianie (401 bez capa) jest egzekwowane przez require_admin/permission_callback
 * WordPressa — pokrywa je krok7-rest-test (require_admin + rejestracja tras z require_admin).
 *
 * URUCHOMIENIE:  php tests/krok12-rest-generate-test.php
 * Kod wyjścia: 0 = OK, 1 = błędy.
 *
 * @package AI_FAQ_Generator
 */

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }

// --- shimy funkcji WP ---
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'wp_unslash' ) ) { function wp_unslash( $s ) { return is_string( $s ) ? stripslashes( $s ) : $s; } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ) { return trim( (string) preg_replace( '/<[^>]*>/', '', (string) $s ) ); } }
if ( ! function_exists( 'sanitize_textarea_field' ) ) { function sanitize_textarea_field( $s ) { return trim( (string) preg_replace( '/<[^>]*>/', '', (string) $s ) ); } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $d ) { return json_encode( $d ); } }
if ( ! function_exists( 'current_time' ) ) { function current_time( $t = 'mysql' ) { return '2026-07-18 12:00:00'; } }
if ( ! function_exists( 'get_current_user_id' ) ) { function get_current_user_id() { return 5; } }
if ( ! function_exists( 'mysql2date' ) ) { function mysql2date( $f, $d, $t = true ) { return 'DATE(' . $d . ')'; } }
if ( ! function_exists( 'mb_strtolower' ) ) { function mb_strtolower( $s ) { return strtolower( (string) $s ); } }
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $k, $d = false ) {
		if ( 'date_format' === $k ) { return 'Y-m-d'; }
		if ( 'time_format' === $k ) { return 'H:i'; }
		return $d; // aifaq_settings → array() → Settings::get() zwraca defaulty
	}
}
if ( ! function_exists( 'get_userdata' ) ) {
	function get_userdata( $id ) { $o = new stdClass(); $o->display_name = ( 5 === (int) $id ) ? 'Admin' : ''; return $o; }
}
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $t ) { return $t instanceof WP_Error; } }

// --- shimy klas WP ---
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code; private $message; private $data;
		public function __construct( $code = '', $message = '', $data = '' ) { $this->code = $code; $this->message = $message; $this->data = $data; }
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
		public function get_error_data() { return $this->data; }
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
require __DIR__ . '/../src/Providers/ProviderInterface.php';
require __DIR__ . '/../src/Providers/ProviderFactory.php';
require __DIR__ . '/../src/Faq/FaqGenerator.php';
require __DIR__ . '/../src/Core/Settings.php';
require __DIR__ . '/../src/Rest/RestController.php';

use AIFAQ\Rest\RestController;
use AIFAQ\Providers\ProviderFactory;

/**
 * Atrapa providera — zwraca zaprogramowaną wartość, zapamiętuje ostatni prompt.
 */
class FakeGenProvider implements \AIFAQ\Providers\ProviderInterface {
	public $last_prompt = '';
	private $ret;
	public function __construct( $ret ) { $this->ret = $ret; }
	public function generate( string $prompt, array $options = array() ) { $this->last_prompt = $prompt; return $this->ret; }
	public function embed( array $texts ) { return array(); }
	public function verify() { return true; }
}

/**
 * Atrapa $wpdb — zapamiętuje insert/delete, oddaje zaprogramowane wiersze.
 */
class RestSpyWpdb {
	public $prefix        = 'wp_';
	public $insert_id     = 0;
	public $insert_called = false;
	public $last_data     = array();
	public $rows          = array();
	public $total         = 0;
	public $deleted       = 0;
	public $delete_id     = 0;
	public function prepare( $q, ...$a ) { $q = str_replace( array( '%s', '%d' ), array( "'%s'", '%d' ), $q ); return $a ? vsprintf( $q, $a ) : $q; }
	public function insert( $table, $data ) { $this->insert_called = true; $this->last_data = $data; $this->insert_id = 77; return 1; }
	public function get_results( $q, $o = null ) { return $this->rows; }
	public function get_var( $q ) { return $this->total; }
	public function delete( $table, $where, $fmt = null ) { $this->delete_id = (int) $where['id']; return $this->deleted; }
}

$fail = 0;
function check( $cond, $label ) { global $fail; echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n"; if ( ! $cond ) { $fail++; } }

global $wpdb;
$wpdb = new RestSpyWpdb();
$c    = new RestController();

/** Buduje żądanie z parametrami. */
function req( array $params ) {
	$r = new WP_REST_Request();
	foreach ( $params as $k => $v ) { $r->set_param( $k, $v ); }
	return $r;
}

// ===========================================================================
echo "=== A. validate_topic ===\n";
$v = $c->validate_topic( '' );
check( $v instanceof WP_Error && 'aifaq_empty_topic' === $v->get_error_code(), 'pusty temat → WP_Error empty' );
check( $v instanceof WP_Error && 400 === ( $v->get_error_data()['status'] ?? 0 ), 'pusty temat → status 400' );
$v2 = $c->validate_topic( '<p> </p>' );
check( $v2 instanceof WP_Error, 'samo HTML (puste po sanityzacji) → WP_Error' );
check( true === $c->validate_topic( 'Krowy mleczne' ), 'poprawny temat → true' );

echo "\n=== B. generate-faq — sukces (200 + zapis) ===\n";
$pairs = array(
	array( 'question' => 'Ile mleka daje krowa?', 'answer' => 'Około 25 litrów.' ),
	array( 'question' => 'Co jedzą krowy?', 'answer' => 'Trawę i siano.' ),
);
$prov = new FakeGenProvider( json_encode( $pairs ) );
ProviderFactory::set_override( $prov );
$wpdb->insert_called = false;
$resp = $c->handle_generate_faq( req( array( 'topic' => 'Krowy mleczne', 'description' => 'dla hodowcow', 'count' => 5, 'language' => 'pl' ) ) );
$d    = $resp->get_data();
check( 200 === $resp->get_status(), 'sukces → HTTP 200' );
check( 'ok' === ( $d['status'] ?? '' ), 'status ok' );
check( 77 === ( $d['id'] ?? 0 ), 'zwraca id zapisanego wpisu' );
check( 2 === ( $d['count'] ?? 0 ) && 2 === count( $d['pairs'] ), 'zwraca 2 pary' );
check( true === $wpdb->insert_called, 'zapis do repozytorium wykonany' );
check( 'Krowy mleczne' === ( $wpdb->last_data['topic'] ?? '' ), 'zapisany topic' );
check( 5 === ( $wpdb->last_data['user_id'] ?? 0 ), 'zapisany user_id (get_current_user_id)' );
check( false !== strpos( $prov->last_prompt, 'dokładnie 5 PAR' ), 'liczba pytań (5) przekazana do generatora' );

echo "\n=== C. clamp liczby pytań do 5..20 (na poziomie REST) ===\n";
$prov = new FakeGenProvider( json_encode( $pairs ) );
ProviderFactory::set_override( $prov );
$c->handle_generate_faq( req( array( 'topic' => 'X', 'count' => 100 ) ) );
check( false !== strpos( $prov->last_prompt, 'dokładnie 20 PAR' ), 'count=100 → clamp do 20' );
$prov = new FakeGenProvider( json_encode( $pairs ) );
ProviderFactory::set_override( $prov );
$c->handle_generate_faq( req( array( 'topic' => 'X', 'count' => 2 ) ) );
check( false !== strpos( $prov->last_prompt, 'dokładnie 5 PAR' ), 'count=2 → clamp do 5' );
$prov = new FakeGenProvider( json_encode( $pairs ) );
ProviderFactory::set_override( $prov );
$c->handle_generate_faq( req( array( 'topic' => 'X', 'count' => 0 ) ) );
check( false !== strpos( $prov->last_prompt, 'dokładnie 20 PAR' ), 'count=0 → domyślne z ustawień (20)' );

echo "\n=== D. generate-faq — błąd providera → 502, bez zapisu, bez wycieku ===\n";
ProviderFactory::set_override( new FakeGenProvider( new WP_Error( 'aifaq_gemini_http', 'RAW: Gemini 500 internal boom' ) ) );
$wpdb->insert_called = false;
$resp = $c->handle_generate_faq( req( array( 'topic' => 'Krowy', 'count' => 5 ) ) );
$d    = $resp->get_data();
check( 502 === $resp->get_status(), 'błąd providera → HTTP 502' );
check( 'error' === ( $d['status'] ?? '' ), 'status error' );
check( false === strpos( json_encode( $d ), 'RAW: Gemini' ), 'BEZ wycieku surowego błędu providera' );
check( false === $wpdb->insert_called, 'przy błędzie NIE zapisujemy historii' );

echo "\n=== E. generate-faq — brak par → 200 'empty', bez zapisu ===\n";
ProviderFactory::set_override( new FakeGenProvider( '[]' ) );
$wpdb->insert_called = false;
$resp = $c->handle_generate_faq( req( array( 'topic' => 'Krowy', 'count' => 5 ) ) );
$d    = $resp->get_data();
check( 200 === $resp->get_status() && 'empty' === ( $d['status'] ?? '' ), 'brak par → 200 status empty' );
check( array() === ( $d['pairs'] ?? null ), 'empty → pusta lista par' );
check( false === $wpdb->insert_called, 'empty → brak zapisu historii' );

echo "\n=== F. GET /admin/generations — kształt listy + clamp strony ===\n";
$wpdb->rows  = array(
	array( 'id' => 3, 'created_at' => '2026-07-18 11:00:00', 'topic' => 'Krowy', 'extra_desc' => 'opis', 'num_questions' => 5, 'language' => 'pl', 'user_id' => 5 ),
	array( 'id' => 2, 'created_at' => '2026-07-17 10:00:00', 'topic' => 'Kozy', 'extra_desc' => '', 'num_questions' => 8, 'language' => 'en', 'user_id' => 0 ),
);
$wpdb->total = 2;
$resp = $c->handle_generations( req( array( 'page' => 1, 'per_page' => 20 ) ) );
$d    = $resp->get_data();
check( 200 === $resp->get_status() && 'ok' === $d['status'], 'lista → 200 ok' );
check( 2 === count( $d['items'] ) && 2 === $d['total'], 'zwraca wiersze i total' );
$it = $d['items'][0];
check( 3 === $it['id'] && 'Krowy' === $it['topic'] && 5 === $it['num_questions'], 'metadane wiersza' );
check( isset( $it['extra_desc'] ) && 'opis' === $it['extra_desc'], 'extra_desc obecny (dla Ponownie wygeneruj)' );
check( 'Admin' === $it['user'] && '' === $d['items'][1]['user'], 'user_label: nazwa / puste dla 0' );
check( ! isset( $it['pairs'] ) && ! isset( $it['pairs_json'] ), 'lista NIE zawiera par ani surowego pairs_json' );
// clamp strony poza zakresem (total=2, per_page=20 → 1 strona; page=9 → cofa do 1)
$resp = $c->handle_generations( req( array( 'page' => 9, 'per_page' => 20 ) ) );
check( 1 === $resp->get_data()['page'], 'strona poza zakresem → cofnięta do ostatniej' );

echo "\n=== G. POST /admin/generations/delete ===\n";
$resp = $c->handle_generations_delete( req( array( 'id' => 0 ) ) );
check( 400 === $resp->get_status(), 'id<=0 → HTTP 400' );
$wpdb->deleted = 1;
$resp = $c->handle_generations_delete( req( array( 'id' => 3 ) ) );
check( 200 === $resp->get_status() && true === (bool) $resp->get_data()['deleted'], 'poprawne id → 200 + deleted' );
check( 3 === $wpdb->delete_id, 'delete trafił we właściwe id' );

ProviderFactory::set_override( null );

echo "\n=== PODSUMOWANIE ===\n";
echo ( 0 === $fail ) ? "TEST KROK 12 (rest-generate): WSZYSTKIE ASERCJE OK\n" : "TEST KROK 12 (rest-generate): $fail ASERCJI NIE PRZESZŁO\n";
exit( $fail === 0 ? 0 : 1 );
