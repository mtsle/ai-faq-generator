<?php
/**
 * Krok 23 etap 3, segment S6 (Generator FAQ) — regresja na REALNY BUG znaleziony w
 * Fazie A i naprawiony W TEJ SESJI: `GeneratorService::generate()` przepuszczał pary
 * świeżo wygenerowane przez model PROSTO do bazy (`wp_aifaq_generations.pairs_json`)
 * i do przeglądarki, BEZ wywołania `Exporter::normalize()` — w odróżnieniu od ścieżki
 * publikacji (`PairsInput::from_request_for_publish()`/`from_snapshot()`), którą K23
 * etap 1 już przycinał. Odpowiedź modelu dłuższa niż `Exporter::MAX_QUESTION_CHARS`/
 * `MAX_ANSWER_CHARS` trafiała nieograniczona w OBIE strony (baza + JSON do klienta).
 *
 * NAPRAWA (ta sesja, `src/Rest/GeneratorService.php` linia ~79): `$pairs` przechodzi
 * teraz przez `Exporter::normalize()` PRZED zapisem i PRZED zwróceniem w odpowiedzi —
 * ta sama reguła co reszta produktu (K23 etap 1, znalezisko B9: jedno źródło prawdy
 * dla trim/cap/clip par).
 *
 * Pokrywa:
 *  A. Para z pytaniem/odpowiedzią dłuższą niż limit → PRZYCIĘTA zarówno w odpowiedzi
 *     REST, jak i w danych zapisanych do `GenerationRepository` (dwa niezależne miejsca
 *     karmione TĄ SAMĄ zmienną `$pairs` po naprawie — przed naprawą oba były nieograniczone).
 *  B. Para z polem nie-skalarnym (np. zagnieżdżona tablica w odpowiedzi modelu —
 *     realny scenariusz przy malformed JSON od LLM) → odrzucona przez `normalize()`,
 *     nie fatal.
 *  C. Regresja: normalna, krótka para nadal przechodzi bez zmian treści (naprawa nie
 *     psuje golden path z krok12-rest-generate-test.php).
 *  D. Wszystkie pary odrzucone przez `normalize()` (np. same nieskalarne) → odpowiedź
 *     spada do gałęzi `empty` (200, nie 500/fatal), zgodnie z istniejącym kontraktem
 *     `'ok' !== $status || empty( $pairs )`.
 *
 * URUCHOMIENIE:  php tests/krok23-etap3-s6-generator-clip-test.php
 * Kod wyjścia: 0 = OK, 1 = błędy.
 *
 * @package AI_FAQ_Generator
 */

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }

if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'wp_unslash' ) ) { function wp_unslash( $s ) { return is_string( $s ) ? stripslashes( $s ) : $s; } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ) { return trim( (string) preg_replace( '/<[^>]*>/', '', (string) $s ) ); } }
if ( ! function_exists( 'sanitize_textarea_field' ) ) { function sanitize_textarea_field( $s ) { return trim( (string) preg_replace( '/<[^>]*>/', '', (string) $s ) ); } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $d ) { return json_encode( $d ); } }
if ( ! function_exists( 'current_time' ) ) { function current_time( $t = 'mysql' ) { return '2026-07-28 12:00:00'; } }
if ( ! function_exists( 'get_current_user_id' ) ) { function get_current_user_id() { return 5; } }
if ( ! function_exists( 'mysql2date' ) ) { function mysql2date( $f, $d, $t = true ) { return 'DATE(' . $d . ')'; } }
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $k, $d = false ) {
		if ( 'date_format' === $k ) { return 'Y-m-d'; }
		if ( 'time_format' === $k ) { return 'H:i'; }
		return $d;
	}
}
if ( ! function_exists( 'get_userdata' ) ) { function get_userdata( $id ) { $o = new stdClass(); $o->display_name = 'Admin'; return $o; } }
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $t ) { return $t instanceof WP_Error; } }
$GLOBALS['__aifaq_transients'] = array();
if ( ! function_exists( 'get_transient' ) ) { function get_transient( $k ) { return $GLOBALS['__aifaq_transients'][ $k ] ?? false; } }
if ( ! function_exists( 'set_transient' ) ) { function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['__aifaq_transients'][ $k ] = $v; return true; } }

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
require __DIR__ . '/../src/Faq/Exporter.php';
require __DIR__ . '/../src/Core/Settings.php';
require __DIR__ . '/../src/Rag/RateLimiter.php';
require __DIR__ . '/../src/Rag/RagService.php';
require __DIR__ . '/../src/Rest/RestController.php';
require __DIR__ . '/../src/Rest/RouteRegistrar.php';
require __DIR__ . '/../src/Rest/GuestIdentity.php';
require __DIR__ . '/../src/Rest/PairsInput.php';
require __DIR__ . '/../src/Rest/AskService.php';
require __DIR__ . '/../src/Rest/AdminService.php';
require __DIR__ . '/../src/Rest/GeneratorService.php';
require __DIR__ . '/../src/Rest/PublishService.php';

use AIFAQ\Faq\Exporter;
use AIFAQ\Providers\ProviderFactory;

class S6GenProvider implements \AIFAQ\Providers\ProviderInterface {
	private $ret;
	public function __construct( $ret ) { $this->ret = $ret; }
	public function generate( string $prompt, array $options = array() ) { return $this->ret; }
	public function embed( array $texts ) { return array(); }
	public function verify() { return true; }
}
class S6SpyWpdb {
	public $prefix = 'wp_'; public $insert_id = 0; public $insert_called = false; public $last_data = array();
	public function prepare( $q, ...$a ) { return $q; }
	public function insert( $table, $data ) { $this->insert_called = true; $this->last_data = $data; $this->insert_id = 501; return 1; }
	public function get_results( $q, $o = null ) { return array(); }
	public function get_var( $q ) { return 0; }
	public function delete( $table, $where, $fmt = null ) { return 0; }
}

$fail = 0;
function check( $cond, $label ) { global $fail; echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n"; if ( ! $cond ) { $fail++; } }
function req6( array $params ) { $r = new WP_REST_Request(); foreach ( $params as $k => $v ) { $r->set_param( $k, $v ); } return $r; }

global $wpdb;
$wpdb = new S6SpyWpdb();
$ctrl = new \AIFAQ\Rest\RestController();

// ===========================================================================
echo "=== A. Para z polem PRZEKRACZAJĄCYM limit → przycięta w REST *i* w zapisie ===\n";
// ===========================================================================
$dlugie_pary = array(
	array( 'question' => str_repeat( 'P', 900 ), 'answer' => str_repeat( 'O', 6000 ) ),
);
ProviderFactory::set_override( new S6GenProvider( json_encode( $dlugie_pary ) ) );
$wpdb->insert_called = false;
$resp = $ctrl->handle_generate_faq( req6( array( 'topic' => 'Test', 'count' => 5 ) ) );
$d    = $resp->get_data();
check( 200 === $resp->get_status(), 'A1: 200 OK' );
check( 1 === count( $d['pairs'] ?? array() ), 'A2: jedna para w odpowiedzi' );
check(
	Exporter::MAX_QUESTION_CHARS === mb_strlen( $d['pairs'][0]['question'] ?? '' ),
	'A3 (KLUCZOWA — regresja fixa): question w ODPOWIEDZI REST przycięte do MAX_QUESTION_CHARS (' . Exporter::MAX_QUESTION_CHARS . '), jest: ' . mb_strlen( $d['pairs'][0]['question'] ?? '' )
);
check(
	Exporter::MAX_ANSWER_CHARS === mb_strlen( $d['pairs'][0]['answer'] ?? '' ),
	'A4 (KLUCZOWA — regresja fixa): answer w ODPOWIEDZI REST przycięte do MAX_ANSWER_CHARS (' . Exporter::MAX_ANSWER_CHARS . '), jest: ' . mb_strlen( $d['pairs'][0]['answer'] ?? '' )
);
check( true === $wpdb->insert_called, 'A5: zapis do historii wykonany' );
$saved_pairs = json_decode( (string) ( $wpdb->last_data['pairs_json'] ?? '[]' ), true );
check(
	is_array( $saved_pairs ) && Exporter::MAX_QUESTION_CHARS === mb_strlen( $saved_pairs[0]['question'] ?? '' ),
	'A6 (KLUCZOWA — regresja fixa): question w ZAPISIE DO BAZY RÓWNIEŻ przycięte (przed fixem leciało nieograniczone)'
);

// ===========================================================================
echo "\n=== B. Pole nieskalarne w parze (malformed JSON od modelu) → odrzucona, bez fatala ===\n";
// ===========================================================================
$zle_pary = array(
	array( 'question' => array( 'zagnieżdżona', 'tablica' ), 'answer' => 'X' ),
	array( 'question' => 'Poprawne pytanie?', 'answer' => 'Poprawna odpowiedź.' ),
);
ProviderFactory::set_override( new S6GenProvider( json_encode( $zle_pary ) ) );
$resp = $ctrl->handle_generate_faq( req6( array( 'topic' => 'Test', 'count' => 5 ) ) );
$d    = $resp->get_data();
check( 200 === $resp->get_status(), 'B1: 200 OK (bez fatala mimo pola nieskalarnego)' );
check( 1 === count( $d['pairs'] ?? array() ), 'B2: para z nieskalarnym question ODRZUCONA, zostaje tylko poprawna' );
check( 'Poprawne pytanie?' === ( $d['pairs'][0]['question'] ?? '' ), 'B3: pozostała para ma właściwą treść' );

// ===========================================================================
echo "\n=== C. Regresja: krótka, poprawna para nadal bez zmian treści ===\n";
// ===========================================================================
$ok_pary = array( array( 'question' => 'Ile kosztuje czesne?', 'answer' => 'Od 500 do 800 zł.' ) );
ProviderFactory::set_override( new S6GenProvider( json_encode( $ok_pary ) ) );
$resp = $ctrl->handle_generate_faq( req6( array( 'topic' => 'Cennik', 'count' => 5 ) ) );
$d    = $resp->get_data();
check( 'Ile kosztuje czesne?' === ( $d['pairs'][0]['question'] ?? '' ), 'C1: treść pytania NIETKNIĘTA (fix nie psuje golden path)' );
check( 'Od 500 do 800 zł.' === ( $d['pairs'][0]['answer'] ?? '' ), 'C2: treść odpowiedzi NIETKNIĘTA' );

// ===========================================================================
echo "\n=== D. Wszystkie pary odrzucone przez normalize() → status 'empty', nie fatal ===\n";
// ===========================================================================
$wszystko_zle = array(
	array( 'question' => array( 'x' ), 'answer' => 'a' ),
	array( 'question' => 'q', 'answer' => array( 'y' ) ),
);
ProviderFactory::set_override( new S6GenProvider( json_encode( $wszystko_zle ) ) );
$wpdb->insert_called = false;
$resp = $ctrl->handle_generate_faq( req6( array( 'topic' => 'Test', 'count' => 5 ) ) );
$d    = $resp->get_data();
check( 200 === $resp->get_status(), 'D1: 200 (nie 500) mimo że WSZYSTKIE pary odrzucone' );
check( 'empty' === ( $d['status'] ?? '' ), 'D2: status empty' );
check( array() === ( $d['pairs'] ?? null ), 'D3: pusta lista par' );
check( false === $wpdb->insert_called, 'D4: brak zapisu historii dla pustego wyniku' );

ProviderFactory::set_override( null );

// ===========================================================================
echo "\n=== PODSUMOWANIE ===\n";
echo ( 0 === $fail ) ? "TEST K23 ETAP 3 SEGMENT S6 (GeneratorService — fix przycinania par): WSZYSTKIE ASERCJE OK\n" : "TEST K23 ETAP 3 SEGMENT S6 (GeneratorService — fix przycinania par): $fail ASERCJI NIE PRZESZŁO\n";
exit( $fail === 0 ? 0 : 1 );
