<?php
/**
 * Test regresyjny Kroku 3 — GeminiProvider na ATRAPIE transportu (bez sieci, bez klucza).
 *
 * Weryfikuje KONTRAKT warstwy AI, nie tylko „czy się łączy":
 *  - jaki URL, nagłówki i payload provider WYSYŁA (spy na HttpClient),
 *  - że klucz idzie do nagłówka x-goog-api-key, a NIE do URL,
 *  - poprawne parsowanie odpowiedzi generate/embed,
 *  - wszystkie ścieżki błędu → WP_Error (nie wyjątek, nie null): błąd sieci, kod != 200, śmieciowa odpowiedź,
 *  - embed: outputDimensionality=768, batch dla wielu tekstów, kolejność zachowana, pusta lista → [].
 *
 * URUCHOMIENIE (PHP z rozszerzeniem mbstring):
 *   php -d extension=mbstring tests/krok3-provider-test.php
 *
 * Nie wymaga WordPressa ani klucza API — używa atrapy transportu i shimów WP.
 * Kod wyjścia: 0 = wszystkie asercje OK, 1 = są błędy.
 *
 * @package AI_FAQ_Generator
 */

// --- shimy WP (tylko poza WordPressem) ---
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code; private $message;
		public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
		public function get_error_message() { return $this->message; }
		public function get_error_code() { return $this->code; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $t ) { return $t instanceof WP_Error; } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $d ) { return json_encode( $d ); } }

require __DIR__ . '/../src/Http/HttpClient.php';
require __DIR__ . '/../src/Providers/ProviderInterface.php';
require __DIR__ . '/../src/Providers/GeminiProvider.php';

// --- Atrapa transportu: zapamiętuje ostatnie żądanie, oddaje zaprogramowaną odpowiedź. ---
class SpyHttp implements \AIFAQ\Http\HttpClient {
	public $last = null;
	private $resp;
	public function __construct( $resp ) { $this->resp = $resp; }
	public function request( string $method, string $url, array $options = array() ) {
		$this->last = compact( 'method', 'url', 'options' );
		return $this->resp;
	}
}

$fail = 0;
function check( $cond, $label ) {
	global $fail;
	echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n";
	if ( ! $cond ) { $fail++; }
}

// ===========================================================================
echo "=== A. generate() — happy path ===\n";
$spy = new SpyHttp( array(
	'status' => 200,
	'body'   => json_encode( array( 'candidates' => array( array( 'content' => array( 'parts' => array( array( 'text' => 'Krowy dają mleko.' ) ) ) ) ) ) ),
) );
$p   = new \AIFAQ\Providers\GeminiProvider( $spy, 'SECRET-KEY-123', 'gemini-2.5-flash', 'gemini-embedding-001' );
$out = $p->generate( 'Coś o krowach', array( 'temperature' => 0.2, 'max_tokens' => 100 ) );

check( 'Krowy dają mleko.' === $out, "zwraca dokładny tekst z candidates[0]" );
check( 'POST' === $spy->last['method'], "metoda POST" );
check( str_ends_with( $spy->last['url'], 'gemini-2.5-flash:generateContent' ), "URL kończy się modelem :generateContent" );
check( isset( $spy->last['options']['headers']['x-goog-api-key'] ) && 'SECRET-KEY-123' === $spy->last['options']['headers']['x-goog-api-key'], "klucz w nagłówku x-goog-api-key" );
check( false === strpos( $spy->last['url'], 'SECRET-KEY-123' ), "klucz NIE trafia do URL" );
$body = json_decode( $spy->last['options']['body'], true );
check( 'Coś o krowach' === ( $body['contents'][0]['parts'][0]['text'] ?? null ), "prompt w contents[0].parts[0].text" );
check( 0.2 === ( $body['generationConfig']['temperature'] ?? null ), "temperature=0.2 przekazane" );
check( 100 === ( $body['generationConfig']['maxOutputTokens'] ?? null ), "max_tokens → maxOutputTokens=100" );

echo "\n=== B. generate() — domyślna temperatura, gdy brak opcji ===\n";
$spy2 = new SpyHttp( array( 'status' => 200, 'body' => json_encode( array( 'candidates' => array( array( 'content' => array( 'parts' => array( array( 'text' => 'x' ) ) ) ) ) ) ) ) );
$p2   = new \AIFAQ\Providers\GeminiProvider( $spy2, 'K', 'm', 'e' );
$p2->generate( 'hej' );
$b2 = json_decode( $spy2->last['options']['body'], true );
check( 0.7 === ( $b2['generationConfig']['temperature'] ?? null ), "domyślna temperature=0.7 (DEFAULT_TEMPERATURE)" );
check( ! isset( $b2['generationConfig']['maxOutputTokens'] ), "brak maxOutputTokens gdy nie podano max_tokens" );

echo "\n=== C. generate() — ścieżki błędu → WP_Error ===\n";
$p3 = new \AIFAQ\Providers\GeminiProvider( new SpyHttp( new WP_Error( 'net', 'timeout' ) ), 'K', 'm', 'e' );
$r3 = $p3->generate( 'x' );
check( is_wp_error( $r3 ) && 'net' === $r3->get_error_code(), "błąd sieci propagowany jako WP_Error" );
$p4 = new \AIFAQ\Providers\GeminiProvider( new SpyHttp( array( 'status' => 400, 'body' => json_encode( array( 'error' => array( 'message' => 'API key not valid' ) ) ) ) ), 'K', 'm', 'e' );
$r4 = $p4->generate( 'x' );
check( is_wp_error( $r4 ) && 'aifaq_gemini_http' === $r4->get_error_code() && 'API key not valid' === $r4->get_error_message(), "kod 400 → WP_Error z komunikatem API" );
$p5 = new \AIFAQ\Providers\GeminiProvider( new SpyHttp( array( 'status' => 200, 'body' => json_encode( array( 'foo' => 'bar' ) ) ) ), 'K', 'm', 'e' );
$r5 = $p5->generate( 'x' );
check( is_wp_error( $r5 ) && 'aifaq_gemini_parse' === $r5->get_error_code(), "200 bez candidates → WP_Error parse" );

echo "\n=== D. embed() — happy path (batch, kolejność, 768) ===\n";
$spyE = new SpyHttp( array(
	'status' => 200,
	'body'   => json_encode( array( 'embeddings' => array(
		array( 'values' => array_fill( 0, 768, 0.1 ) ),
		array( 'values' => array_fill( 0, 768, 0.2 ) ),
	) ) ),
) );
$pE   = new \AIFAQ\Providers\GeminiProvider( $spyE, 'K', 'gemini-2.5-flash', 'gemini-embedding-001' );
$vecs = $pE->embed( array( 'mleko', 'siano' ) );
check( is_array( $vecs ) && 2 === count( $vecs ), "zwraca 2 wektory dla 2 tekstów" );
check( 768 === count( $vecs[0] ) && 768 === count( $vecs[1] ), "każdy wektor ma 768 wymiarów" );
check( is_float( $vecs[0][0] ), "elementy rzutowane na float" );
$be = json_decode( $spyE->last['options']['body'], true );
check( str_ends_with( $spyE->last['url'], 'gemini-embedding-001:batchEmbedContents' ), "URL :batchEmbedContents z modelem embed" );
check( 2 === count( $be['requests'] ), "payload ma 2 requesty (batch)" );
check( 768 === ( $be['requests'][0]['outputDimensionality'] ?? null ), "outputDimensionality=768 w payloadzie" );
check( 'models/gemini-embedding-001' === ( $be['requests'][0]['model'] ?? null ), "model jako 'models/<embed_model>'" );
check( 'mleko' === ( $be['requests'][0]['content']['parts'][0]['text'] ?? null ), "tekst[0] w requ.content.parts" );

echo "\n=== E. embed() — ścieżki błędu → WP_Error ===\n";
$pE2 = new \AIFAQ\Providers\GeminiProvider( new SpyHttp( new WP_Error( 'net', 'down' ) ), 'K', 'm', 'e' );
check( is_wp_error( $pE2->embed( array( 'a' ) ) ), "błąd sieci → WP_Error" );
$pE3 = new \AIFAQ\Providers\GeminiProvider( new SpyHttp( array( 'status' => 200, 'body' => json_encode( array( 'embeddings' => array( array( 'nope' => 1 ) ) ) ) ) ), 'K', 'm', 'e' );
check( is_wp_error( $pE3->embed( array( 'a' ) ) ), "embedding bez 'values' → WP_Error parse" );

echo "\n=== F. embed() — pusta lista tekstów ===\n";
$spyF = new SpyHttp( array( 'status' => 200, 'body' => json_encode( array( 'embeddings' => array() ) ) ) );
$pF   = new \AIFAQ\Providers\GeminiProvider( $spyF, 'K', 'm', 'e' );
$rF   = $pF->embed( array() );
$bF   = json_decode( $spyF->last['options']['body'], true );
check( is_array( $rF ) && 0 === count( $rF ), "pusta lista → pusta tablica wektorów (bez błędu)" );
check( isset( $bF['requests'] ) && 0 === count( $bF['requests'] ), "payload requests = [] dla pustego wejścia" );

echo "\n=== PODSUMOWANIE ===\n";
echo ( 0 === $fail ) ? "TEST KROK 3: WSZYSTKIE ASERCJE OK\n" : "TEST KROK 3: $fail ASERCJI NIE PRZESZŁO\n";
exit( $fail === 0 ? 0 : 1 );
