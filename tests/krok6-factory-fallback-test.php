<?php
/**
 * Test utwardzenia ProviderFactory (v0.8.0) — samo-naprawa wycofanego modelu.
 *
 * Ujawnione podczas live smoke Kroku 6: w ustawieniach zalegał wycofany przez
 * Google `gemini-1.5-flash` → generate() zwracał 404. Fabryka powinna model
 * spoza whitelisty (Settings::models()/embed_models()) zamienić na domyślny.
 *
 * Weryfikacja przez SPY transportu: sprawdzamy URL, który zbudował GeminiProvider
 * (zawiera nazwę modelu) — musi wskazywać model domyślny, nie wycofany.
 *
 * URUCHOMIENIE:  php tests/krok6-factory-fallback-test.php
 * Kod wyjścia: 0 = OK, 1 = błędy.
 *
 * @package AI_FAQ_Generator
 */

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $c; private $m;
		public function __construct( $c = '', $m = '' ) { $this->c = $c; $this->m = $m; }
		public function get_error_message() { return $this->m; }
		public function get_error_code() { return $this->c; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $t ) { return $t instanceof WP_Error; } }
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $d ) { return json_encode( $d ); } }
if ( ! function_exists( 'get_option' ) ) { function get_option( $k, $d = false ) { global $__opt; return $__opt[ $k ] ?? $d; } }

$fail = 0;
function check( $cond, $label ) { global $fail; echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n"; if ( ! $cond ) { $fail++; } }

require __DIR__ . '/../src/Http/HttpClient.php';
require __DIR__ . '/../src/Providers/ProviderInterface.php';
require __DIR__ . '/../src/Providers/GeminiProvider.php';
require __DIR__ . '/../src/Providers/ProviderFactory.php';
require __DIR__ . '/../src/Core/Settings.php';

use AIFAQ\Providers\ProviderFactory;
use AIFAQ\Core\Settings;

/** Spy transportu: zapamiętuje URL, zwraca poprawną odpowiedź generateContent. */
class SpyHttp implements \AIFAQ\Http\HttpClient {
	public $last_url = '';
	public function request( string $method, string $url, array $options = array() ) {
		$this->last_url = $url;
		return array(
			'status' => 200,
			'body'   => wp_json_encode(
				array( 'candidates' => array( array( 'content' => array( 'parts' => array( array( 'text' => 'ok' ) ) ), 'finishReason' => 'STOP' ) ) )
			),
		);
	}
}

global $__opt;

echo "=== Factory: wycofany model w ustawieniach → domyślny (samo-naprawa) ===\n";
$__opt = array( Settings::OPTION => array( 'api_key' => 'K', 'model' => 'gemini-1.5-flash', 'embed_model' => 'gemini-embedding-001' ) );
$spy = new SpyHttp();
$provider = ProviderFactory::make( $spy );
$provider->generate( 'test' );
check( false !== strpos( $spy->last_url, 'gemini-2.5-flash' ), 'URL uzywa domyslnego gemini-2.5-flash' );
check( false === strpos( $spy->last_url, '1.5' ), 'URL NIE zawiera wycofanego 1.5' );

echo "\n=== Factory: prawidłowy model z whitelisty → zachowany ===\n";
$__opt = array( Settings::OPTION => array( 'api_key' => 'K', 'model' => 'gemini-2.5-pro', 'embed_model' => 'gemini-embedding-001' ) );
$spy2 = new SpyHttp();
ProviderFactory::make( $spy2 )->generate( 'test' );
check( false !== strpos( $spy2->last_url, 'gemini-2.5-pro' ), 'model z whitelisty (2.5-pro) zachowany' );

echo "\n=== Factory: pusty model → domyślny (regresja M9) ===\n";
$__opt = array( Settings::OPTION => array( 'api_key' => 'K', 'model' => '', 'embed_model' => '' ) );
$spy3 = new SpyHttp();
ProviderFactory::make( $spy3 )->generate( 'test' );
check( false !== strpos( $spy3->last_url, 'gemini-2.5-flash' ), 'pusty model → domyslny' );

echo "\n" . ( 0 === $fail ? 'WSZYSTKIE OK' : "BLEDY: $fail" ) . "\n";
exit( 0 === $fail ? 0 : 1 );
