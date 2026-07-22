<?php
/**
 * Testy warstwy REST `aifaq/v1` (Krok 7).
 *
 * Pokrywa (bez pełnego środowiska WP — shimy + refleksja):
 *  - Rejestracja tras: namespace `aifaq/v1`, ścieżki /ask + /admin/{status,reindex,clear},
 *    metody HTTP, publiczny vs administracyjny `permission_callback`, deklaracja `args.question`.
 *  - `require_admin`: przepuszcza tylko przy `manage_options`.
 *  - `validate_question`: puste / same białe znaki / samo HTML → 400; za długie → 400; poprawne → true.
 *  - Mapowanie wyniku RagService→HTTP (`ask_response`, refleksja): answered/refused=200,
 *    cache→cached=true, rate_limit→429, error→502; BEZ wycieku surowego błędu providera.
 *  - `ip_hash`: sha256 (64 hex), deterministyczny per IP, różny dla różnych IP; nie zawiera surowego IP.
 *
 * URUCHOMIENIE:  php tests/krok7-rest-test.php
 * Kod wyjścia: 0 = OK, 1 = błędy.
 *
 * @package AI_FAQ_Generator
 */

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }

// --- shimy funkcji WP ---
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'wp_unslash' ) ) { function wp_unslash( $s ) { return is_string( $s ) ? stripslashes( $s ) : $s; } }
if ( ! function_exists( 'sanitize_textarea_field' ) ) { function sanitize_textarea_field( $s ) { return trim( (string) preg_replace( '/<[^>]*>/', '', (string) $s ) ); } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ) { return trim( (string) preg_replace( '/<[^>]*>/', '', (string) $s ) ); } }
if ( ! function_exists( 'wp_salt' ) ) { function wp_salt( $scheme = 'auth' ) { return 'aifaq-test-salt'; } }
if ( ! function_exists( '__return_true' ) ) { function __return_true() { return true; } }

// current_user_can sterowany globalną flagą (test uprawnień).
$GLOBALS['__aifaq_can'] = true;
if ( ! function_exists( 'current_user_can' ) ) { function current_user_can( $cap ) { return (bool) $GLOBALS['__aifaq_can']; } }

// Przechwytywanie rejestracji tras.
$GLOBALS['__aifaq_routes'] = array();
if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route( $ns, $route, $args = array() ) {
		$GLOBALS['__aifaq_routes'][] = array( 'ns' => $ns, 'route' => $route, 'args' => $args );
		return true;
	}
}

// --- shimy klas WP (przestrzeń globalna) ---
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

// --- harness ---
$fail = 0;
function check( $cond, $label ) {
	global $fail;
	echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n";
	if ( ! $cond ) { $fail++; }
}
function is_wp_error_local( $t ) { return $t instanceof WP_Error; }

// RagService — tylko dla stałej MAX_QUESTION_LEN (metody nieużywane; zależności nie są ładowane).
require __DIR__ . '/../src/Rag/RagService.php';
require __DIR__ . '/../src/App/HistoryPanel.php';
require __DIR__ . '/../src/Rest/RestController.php';

use AIFAQ\Rag\RagService;
use AIFAQ\Rest\RestController;

$controller = new RestController();

echo "== Rejestracja tras ==\n";
$controller->register_routes();
$routes = $GLOBALS['__aifaq_routes'];

$by_route = array();
foreach ( $routes as $r ) { $by_route[ $r['route'] ] = $r; }

check( count( $routes ) === 13, 'zarejestrowano dokładnie 13 tras' );
check( isset( $by_route['/ask'], $by_route['/admin/status'], $by_route['/admin/reindex'], $by_route['/admin/clear'], $by_route['/admin/settings'], $by_route['/admin/verify'], $by_route['/admin/history'], $by_route['/admin/history/clear'] ), 'komplet ścieżek /ask + /admin/{status,reindex,clear,settings,verify,history,history/clear}' );
check( isset( $by_route['/admin/generate-faq'], $by_route['/admin/generations'], $by_route['/admin/generations/delete'] ), 'trasy generatora (K12): /admin/generate-faq, /admin/generations, /admin/generations/delete' );
check( isset( $by_route['/admin/export'] ), 'trasa eksportu (K14): /admin/export' );
check( 'POST' === ( $by_route['/admin/export']['args']['methods'] ?? '' ), '/admin/export metoda POST' );
check( isset( $by_route['/admin/generations/detail'] ), 'trasa szczegółu historii (K15): /admin/generations/detail' );

$all_ns = array_unique( array_map( static function ( $r ) { return $r['ns']; }, $routes ) );
check( array( 'aifaq/v1' ) === array_values( $all_ns ), 'wszystkie trasy w namespace aifaq/v1' );

// /ask — publiczne POST.
$ask = $by_route['/ask']['args'] ?? array();
check( 'POST' === $ask['methods'], '/ask metoda POST' );
check( '__return_true' === $ask['permission_callback'], '/ask permission_callback publiczny (__return_true)' );
check( isset( $ask['args']['question'] ) && true === $ask['args']['question']['required'], '/ask wymaga parametru question' );
check( isset( $ask['args']['question']['validate_callback'] ) && is_callable( $ask['args']['question']['validate_callback'] ), '/ask question ma validate_callback' );
check( ( $ask['args']['question']['sanitize_callback'] ?? '' ) === 'sanitize_textarea_field', '/ask question sanitize_callback = sanitize_textarea_field' );

// /admin/* — POST/GET + require_admin.
check( 'GET' === ( $by_route['/admin/status']['args']['methods'] ?? '' ), '/admin/status metoda GET' );
check( 'POST' === ( $by_route['/admin/reindex']['args']['methods'] ?? '' ), '/admin/reindex metoda POST' );
check( 'POST' === ( $by_route['/admin/clear']['args']['methods'] ?? '' ), '/admin/clear metoda POST' );
check( 'POST' === ( $by_route['/admin/settings']['args']['methods'] ?? '' ), '/admin/settings metoda POST' );
check( 'POST' === ( $by_route['/admin/verify']['args']['methods'] ?? '' ), '/admin/verify metoda POST' );
check( 'GET' === ( $by_route['/admin/history']['args']['methods'] ?? '' ), '/admin/history metoda GET' );
check( 'POST' === ( $by_route['/admin/history/clear']['args']['methods'] ?? '' ), '/admin/history/clear metoda POST' );
check( 'POST' === ( $by_route['/admin/generate-faq']['args']['methods'] ?? '' ), '/admin/generate-faq metoda POST' );
check( 'GET' === ( $by_route['/admin/generations']['args']['methods'] ?? '' ), '/admin/generations metoda GET' );
check( 'POST' === ( $by_route['/admin/generations/delete']['args']['methods'] ?? '' ), '/admin/generations/delete metoda POST' );
check( 'GET' === ( $by_route['/admin/generations/detail']['args']['methods'] ?? '' ), '/admin/generations/detail metoda GET' );
check( isset( $by_route['/admin/generate-faq']['args']['args']['topic'] ) && true === $by_route['/admin/generate-faq']['args']['args']['topic']['required'], '/admin/generate-faq wymaga parametru topic' );
check( isset( $by_route['/admin/generations/detail']['args']['args']['id'] ) && true === $by_route['/admin/generations/detail']['args']['args']['id']['required'], '/admin/generations/detail wymaga parametru id' );
// K20 (§5.2): DOKŁADNIE DWIE trasy schodzą do capa narzędzia (`publish_posts`) —
// `/admin/generate-faq` i `/admin/export`. Cała reszta `/admin/*` zostaje admin-only.
// Podział jest asertowany W OBIE STRONY, żeby przeniesienie kolejnej trasy „przy okazji"
// nie przeszło niezauważone.
foreach ( array( '/admin/status', '/admin/reindex', '/admin/clear', '/admin/settings', '/admin/verify', '/admin/history', '/admin/history/clear', '/admin/generations', '/admin/generations/detail', '/admin/generations/delete' ) as $ar ) {
	$pc = $by_route[ $ar ]['args']['permission_callback'] ?? null;
	$ok = is_array( $pc ) && $pc[0] instanceof RestController && 'require_admin' === $pc[1];
	check( $ok, "$ar permission_callback = require_admin" );
}
foreach ( array( '/admin/generate-faq', '/admin/export' ) as $ar ) {
	$pc = $by_route[ $ar ]['args']['permission_callback'] ?? null;
	$ok = is_array( $pc ) && $pc[0] instanceof RestController && 'require_tool_user' === $pc[1];
	check( $ok, "$ar permission_callback = require_tool_user (K20: cap narzedzia)" );
}

echo "\n== require_admin (uprawnienia) ==\n";
$GLOBALS['__aifaq_can'] = true;
check( true === $controller->require_admin(), 'manage_options → przepuszcza' );
$GLOBALS['__aifaq_can'] = false;
check( false === $controller->require_admin(), 'brak uprawnień → odrzuca' );
$GLOBALS['__aifaq_can'] = true;

echo "\n== validate_question ==\n";
$v = $controller->validate_question( '' );
check( is_wp_error_local( $v ) && 'aifaq_empty_question' === $v->get_error_code(), 'puste pytanie → WP_Error empty' );
check( is_wp_error_local( $v ) && 400 === ( $v->get_error_data()['status'] ?? 0 ), 'puste pytanie → status 400' );

$v = $controller->validate_question( '     ' );
check( is_wp_error_local( $v ) && 'aifaq_empty_question' === $v->get_error_code(), 'same białe znaki → WP_Error empty' );

$v = $controller->validate_question( '<p><br></p>' );
check( is_wp_error_local( $v ) && 'aifaq_empty_question' === $v->get_error_code(), 'samo HTML (puste po sanityzacji) → WP_Error empty' );

$long = str_repeat( 'a', RagService::MAX_QUESTION_LEN + 1 );
$v    = $controller->validate_question( $long );
check( is_wp_error_local( $v ) && 'aifaq_question_too_long' === $v->get_error_code(), 'za długie pytanie → WP_Error too_long' );
check( is_wp_error_local( $v ) && 400 === ( $v->get_error_data()['status'] ?? 0 ), 'za długie pytanie → status 400' );

check( true === $controller->validate_question( 'Ile mleka daje krowa?' ), 'poprawne pytanie → true' );
check( true === $controller->validate_question( str_repeat( 'a', RagService::MAX_QUESTION_LEN ) ), 'pytanie na granicy długości → true' );

echo "\n== Mapowanie wyniku RagService → HTTP (ask_response) ==\n";
$map = new ReflectionMethod( RestController::class, 'ask_response' );
$map->setAccessible( true );

$r = $map->invoke( $controller, array( 'status' => 'answered', 'answer' => 'Około 25 litrów.', 'score' => 0.8421, 'source' => 'ai' ) );
check( 200 === $r->get_status(), 'answered → HTTP 200' );
check( 'answered' === $r->get_data()['status'], 'answered → status answered' );
check( 'Około 25 litrów.' === $r->get_data()['answer'], 'answered → treść odpowiedzi przekazana' );
check( false === $r->get_data()['cached'], 'answered/ai → cached=false' );
check( approx_eq( 0.8421, $r->get_data()['score'] ), 'answered → score zaokrąglony' );

$r = $map->invoke( $controller, array( 'status' => 'answered', 'answer' => 'Z cache.', 'score' => 1.0, 'source' => 'cache' ) );
check( 200 === $r->get_status() && true === $r->get_data()['cached'], 'answered/cache → cached=true' );

$r = $map->invoke( $controller, array( 'status' => 'refused', 'answer' => 'Poza tematem.', 'score' => 0.3, 'source' => 'ai' ) );
check( 200 === $r->get_status() && 'refused' === $r->get_data()['status'], 'refused → HTTP 200 + status refused' );

$r = $map->invoke( $controller, array( 'status' => 'error', 'answer' => '', 'score' => 0.0, 'source' => 'rate_limit' ) );
check( 429 === $r->get_status(), 'rate_limit → HTTP 429' );
check( 'rate_limited' === $r->get_data()['status'], 'rate_limit → status rate_limited' );
check( ! empty( $r->get_data()['message'] ), 'rate_limit → komunikat dla klienta' );

$r = $map->invoke( $controller, array( 'status' => 'error', 'answer' => 'RAW: Gemini 500 internal', 'score' => 0.0, 'source' => 'ai' ) );
check( 502 === $r->get_status(), 'błąd generacji → HTTP 502' );
check( 'error' === $r->get_data()['status'], 'błąd → status error' );
$body = json_encode( $r->get_data() );
check( false === strpos( $body, 'RAW: Gemini' ), 'błąd → BEZ wycieku surowego błędu providera' );

$r = $map->invoke( $controller, array() );
check( 502 === $r->get_status(), 'pusty/nieznany wynik → HTTP 502 (fail-safe)' );

echo "\n== ip_hash (GR7 — brak surowego IP) ==\n";
$ip = new ReflectionMethod( RestController::class, 'ip_hash' );
$ip->setAccessible( true );

$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
$h1 = $ip->invoke( $controller );
$h2 = $ip->invoke( $controller );
check( is_string( $h1 ) && 1 === preg_match( '/^[0-9a-f]{64}$/', $h1 ), 'ip_hash = 64 znaki hex (sha256)' );
check( $h1 === $h2, 'ip_hash deterministyczny dla tego samego IP' );
check( false === strpos( $h1, '203.0.113.7' ), 'ip_hash nie zawiera surowego IP' );

$_SERVER['REMOTE_ADDR'] = '198.51.100.42';
$h3 = $ip->invoke( $controller );
check( $h1 !== $h3, 'ip_hash różny dla różnych IP' );

function approx_eq( $a, $b, $eps = 1e-6 ) { return abs( (float) $a - (float) $b ) < $eps; }

echo "\n" . ( $fail ? "FAIL: $fail asercji nie przeszło\n" : "OK: wszystkie asercje przeszły\n" );
exit( $fail ? 1 : 0 );
