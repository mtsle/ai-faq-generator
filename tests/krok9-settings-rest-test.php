<?php
/**
 * Testy: Ustawienia (save/verify_key) + REST /admin/{settings,verify} (Krok 9, front-app).
 *
 * Pokrywa nowy kod zapisu ustawień z frontu (bez pełnego WP — shimy + atrapa providera):
 *  - Settings::save(): whitelist (częściowy input NIE kasuje RAG/slug), clamp (w tym
 *    NOWA podłoga progu 0.05 z audytu), zwrot zsanityzowanych, wywołanie update_option.
 *  - Settings::verify_key(): pusty→fallback do zapisanego; brak obu→WP_Error 'aifaq_no_key'
 *    (provider NIE dotknięty); niepusty→provider->verify() (true/WP_Error przekazane).
 *  - RestController::handle_settings_save(): przepuszcza tylko api_key/model/temperature/
 *    language; odpowiedź ma model/temperature/language/has_key i NIGDY api_key (anti-leak).
 *  - RestController::handle_verify(): aifaq_no_key→status error+HTTP200; WP_Error providera→
 *    status error + prefiks „Błąd:”; sukces→status ok.
 *
 * URUCHOMIENIE:  php tests/krok9-settings-rest-test.php
 * Kod wyjścia: 0 = OK, 1 = błędy.
 *
 * @package AI_FAQ_Generator
 */

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }

// --- shimy funkcji WP ---
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'wp_unslash' ) ) { function wp_unslash( $s ) { return is_string( $s ) ? stripslashes( $s ) : $s; } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ) { return trim( (string) preg_replace( '/<[^>]*>/', '', (string) $s ) ); } }
if ( ! function_exists( 'sanitize_textarea_field' ) ) { function sanitize_textarea_field( $s ) { return trim( (string) preg_replace( '/<[^>]*>/', '', (string) $s ) ); } }
if ( ! function_exists( 'sanitize_title' ) ) { function sanitize_title( $s ) { return preg_replace( '/[^a-z0-9-]/', '', strtolower( str_replace( ' ', '-', trim( (string) $s ) ) ) ); } }
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $t ) { return $t instanceof WP_Error; } }
if ( ! function_exists( 'wp_salt' ) ) { function wp_salt( $s = 'auth' ) { return 'salt'; } }

// magazyn opcji (shim get_option/update_option).
$GLOBALS['__opt'] = array();
if ( ! function_exists( 'get_option' ) ) { function get_option( $k, $d = false ) { return $GLOBALS['__opt'][ $k ] ?? $d; } }
if ( ! function_exists( 'update_option' ) ) { function update_option( $k, $v ) { $GLOBALS['__opt'][ $k ] = $v; return true; } }

$GLOBALS['__aifaq_can'] = true;
if ( ! function_exists( 'current_user_can' ) ) { function current_user_can( $c ) { return (bool) $GLOBALS['__aifaq_can']; } }

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
		private $p = array();
		public function set_param( $k, $v ) { $this->p[ $k ] = $v; }
		public function get_param( $k ) { return $this->p[ $k ] ?? null; }
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
function check( $cond, $label ) { global $fail; echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n"; if ( ! $cond ) { $fail++; } }

// --- ładowanie kodu ---
require __DIR__ . '/../src/Providers/ProviderInterface.php';
require __DIR__ . '/../src/Providers/ProviderFactory.php';
require __DIR__ . '/../src/Core/Settings.php';
require __DIR__ . '/../src/Rag/RagService.php';
require __DIR__ . '/../src/Rest/RestController.php';

use AIFAQ\Core\Settings;
use AIFAQ\Providers\ProviderFactory;
use AIFAQ\Providers\ProviderInterface;
use AIFAQ\Rest\RestController;

// Atrapa providera (bez sieci) — steruje wynikiem verify() i liczy wywołania.
class FakeProvider implements ProviderInterface {
	public $verify_calls = 0;
	public $verify_result = true;
	public function generate( string $prompt, array $options = array() ) { return ''; }
	public function embed( array $texts ) { return array(); }
	public function verify() { $this->verify_calls++; return $this->verify_result; }
}

function req( array $params ) { $r = new WP_REST_Request(); foreach ( $params as $k => $v ) { $r->set_param( $k, $v ); } return $r; }

$ctrl = new RestController();

echo "== Settings::save — whitelist i clamp ==\n";
$GLOBALS['__opt'] = array(); // czysto → defaults
Settings::save( array( 'model' => 'gemini-2.5-pro', 'temperature' => 5.0, 'language' => 'en', 'api_key' => 'KEY-AAA' ) );
$s = Settings::get();
check( 'gemini-2.5-pro' === $s['model'], 'save: model z whitelisty zapisany' );
check( 1.0 === $s['temperature'], 'save: temperatura clamp górny → 1.0' );
check( 'en' === $s['language'], 'save: język zapisany' );
check( 'KEY-AAA' === $s['api_key'], 'save: klucz zapisany' );
check( 'faqgenerator' === $s['page_slug'], 'save: page_slug NIETKNIĘTY (nie w whitelist)' );
check( 0.7 === $s['rag_threshold'], 'save: rag_threshold NIETKNIĘTY (nie w whitelist)' );

echo "\n== Settings::save — pusty klucz zachowuje zapisany (M3) ==\n";
Settings::save( array( 'api_key' => '', 'model' => 'gemini-2.0-flash' ) );
$s = Settings::get();
check( 'KEY-AAA' === $s['api_key'], 'save: pusty api_key NIE kasuje zapisanego' );
check( 'gemini-2.0-flash' === $s['model'], 'save: inne pole i tak zapisane' );

echo "\n== FIX audytu: podłoga clampu rag_threshold = 0.05 ==\n";
$GLOBALS['__opt'] = array();
check( 0.05 === Settings::sanitize( array( 'rag_threshold' => 0.0 ) )['rag_threshold'], 'threshold 0.0 → 0.05 (fail-open domknięty)' );
check( 0.05 === Settings::sanitize( array( 'rag_threshold' => -1 ) )['rag_threshold'], 'threshold ujemny → 0.05' );
check( 0.05 === Settings::sanitize( array( 'rag_threshold' => 0.02 ) )['rag_threshold'], 'threshold 0.02 → 0.05' );
check( 0.5 === Settings::sanitize( array( 'rag_threshold' => 0.5 ) )['rag_threshold'], 'threshold 0.5 zachowany' );

echo "\n== Settings::verify_key ==\n";
$GLOBALS['__opt'] = array();
$fake = new FakeProvider();
ProviderFactory::set_override( $fake );
$r = Settings::verify_key( '' );
check( $r instanceof WP_Error && 'aifaq_no_key' === $r->get_error_code(), 'pusty klucz + brak zapisanego → WP_Error aifaq_no_key' );
check( 0 === $fake->verify_calls, 'verify_key: provider NIE dotknięty przy pustym kluczu' );

$fake->verify_result = true;
check( true === Settings::verify_key( 'KEY-XYZ' ), 'niepusty klucz OK → true (provider zawołany)' );
check( 1 === $fake->verify_calls, 'verify_key: provider zawołany raz' );

$GLOBALS['__opt']['aifaq_settings'] = array( 'api_key' => 'SAVED-KEY' );
$fake->verify_calls = 0;
Settings::verify_key( '' ); // pusty → fallback do zapisanego → provider zawołany
check( 1 === $fake->verify_calls, 'verify_key: pusty + zapisany klucz → fallback (provider zawołany)' );

echo "\n== RestController::handle_settings_save — whitelist + anti-leak klucza ==\n";
$GLOBALS['__opt'] = array( 'aifaq_settings' => array( 'page_slug' => 'oryginalny', 'rag_top_k' => 5, 'api_key' => 'SECRET-123' ) );
$resp = $ctrl->handle_settings_save( req( array(
	'model'         => 'gemini-2.5-pro',
	'temperature'   => 0.3,
	'language'      => 'de',
	'page_slug'     => 'HACK',      // spoza whitelisty — musi być zignorowane
	'rag_top_k'     => 99,          // spoza whitelisty
) ) );
$data = $resp->get_data();
$json = json_encode( $data );
check( 200 === $resp->get_status(), 'handle_settings_save: HTTP 200' );
check( 'ok' === ( $data['status'] ?? '' ), 'handle_settings_save: status ok' );
check( isset( $data['settings']['model'], $data['settings']['temperature'], $data['settings']['language'], $data['settings']['has_key'] ), 'odpowiedź ma model/temperature/language/has_key' );
check( false === strpos( $json, 'SECRET-123' ), 'ANTI-LEAK: klucz NIE występuje w odpowiedzi' );
check( false === strpos( $json, 'api_key' ), 'odpowiedź nie zawiera pola api_key' );
check( true === $data['settings']['has_key'], 'has_key = true (klucz istnieje)' );
$saved = $GLOBALS['__opt']['aifaq_settings'];
check( 'oryginalny' === $saved['page_slug'], 'page_slug NIE nadpisany przez REST' );
check( 5 === $saved['rag_top_k'], 'rag_top_k NIE nadpisany przez REST' );
check( 'gemini-2.5-pro' === $saved['model'] && 'de' === $saved['language'], 'rdzeń faktycznie zapisany' );

echo "\n== RestController::handle_verify ==\n";
$GLOBALS['__opt'] = array();
ProviderFactory::set_override( new FakeProvider() ); // domyślnie verify=true
$resp = $ctrl->handle_verify( req( array( 'api_key' => '' ) ) );
$data = $resp->get_data();
check( 200 === $resp->get_status() && 'error' === $data['status'], 'verify pusty+brak → HTTP 200 status error' );
check( false !== strpos( (string) $data['message'], 'Podaj klucz' ), 'verify pusty → komunikat „Podaj klucz”' );

$errFake = new FakeProvider();
$errFake->verify_result = new WP_Error( 'gemini_401', 'API key not valid' );
ProviderFactory::set_override( $errFake );
$resp = $ctrl->handle_verify( req( array( 'api_key' => 'ZLY' ) ) );
$data = $resp->get_data();
check( 'error' === $data['status'] && false !== strpos( (string) $data['message'], 'Błąd:' ), 'verify błąd providera → status error + prefiks „Błąd:”' );

$okFake = new FakeProvider();
$okFake->verify_result = true;
ProviderFactory::set_override( $okFake );
$resp = $ctrl->handle_verify( req( array( 'api_key' => 'DOBRY' ) ) );
$data = $resp->get_data();
check( 'ok' === $data['status'] && 200 === $resp->get_status(), 'verify sukces → status ok, HTTP 200' );

ProviderFactory::set_override( null );

echo "\n=== " . ( 0 === $fail ? 'WSZYSTKIE OK' : "BŁĘDÓW: {$fail}" ) . " ===\n";
exit( $fail > 0 ? 1 : 0 );
