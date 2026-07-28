<?php
/**
 * Krok 23 etap 3, segment S2 (dostawca + obsługa błędów API) — łatanie luk z Fazy A:
 * JSON dosłownie niepoprawny składniowo (nie tylko "zła zawartość" jak w innych testach),
 * `GeminiProvider::verify()` bez ŻADNEGO bezpośredniego testu (istniejące testy mockują
 * `ProviderInterface::verify()` w całości — `FakeProvider`, nigdy nie wołają REALNEJ
 * implementacji Gemini z atrapą transportu), dodatkowe kody HTTP 401/403 (dotąd tylko 404
 * jako reprezentant ścieżki "bez retry"), i udokumentowanie kontraktu "pusty klucz API".
 *
 * Pokrywa:
 *  A. `generate()` — ciało 200 z niepoprawnym JSON-em (błąd składni, nie zła zawartość)
 *     → `aifaq_gemini_parse`, bez fatala.
 *  B. `embed()` — to samo dla ścieżki embeddingów.
 *  C. Błąd HTTP (500) z niepoprawnym JSON-em w ciele → generyczny komunikat fallback,
 *     `json_decode()` zwracające `null` nie wywala `isset()` na nim.
 *  D. `verify()` — bezpośrednio na klasie `GeminiProvider` (nie przez `FakeProvider`):
 *     200 → `true`; błąd z komunikatem API → `WP_Error` z TYM komunikatem; błąd bez ciała
 *     JSON → komunikat generyczny z kodem HTTP; błąd transportu → `WP_Error` przekazany.
 *  E. HTTP 401/403 — dokładają się do już przetestowanego 404/429/503 jako reprezentanci
 *     ścieżki "bez retry, `aifaq_gemini_http`".
 *  F. Kontrakt "pusty klucz API": `GeminiProvider` NIE waliduje klucza sam (to świadomie
 *     zadanie warstwy wyżej, `Settings::verify_key()` — patrz `krok9-settings-rest-test.php`,
 *     `aifaq_no_key`) — pusty klucz i tak leci w nagłówku, odpowiedź API (401/403) wraca
 *     jako zwykły `WP_Error`, bez fatala i bez fałszywego sukcesu.
 *
 * URUCHOMIENIE:  php -d extension=mbstring tests/krok23-etap3-s2-provider-test.php
 * Kod wyjścia: 0 = OK, 1 = błędy.
 *
 * @package AI_FAQ_Generator
 */

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }

if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); } }

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code; private $message;
		public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $t ) { return $t instanceof WP_Error; } }

require __DIR__ . '/../src/Http/HttpClient.php';
require __DIR__ . '/../src/Providers/ProviderInterface.php';
require __DIR__ . '/../src/Providers/GeminiProvider.php';

use AIFAQ\Http\HttpClient;
use AIFAQ\Providers\GeminiProvider;

/**
 * Atrapa transportu — zwraca jedną, ustaloną odpowiedź (albo WP_Error) na KAŻDE żądanie.
 */
class S2Http implements HttpClient {
	private $resp;
	public $calls = 0;
	public $last_url = '';
	public function __construct( $resp ) { $this->resp = $resp; }
	public function request( string $method, string $url, array $options = array() ) {
		$this->calls++;
		$this->last_url = $url;
		return $this->resp;
	}
}
function s2_noop_sleep( $s ) {}

$fail = 0;
function check( $cond, $label ) { global $fail; echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n"; if ( ! $cond ) { $fail++; } }

// ===========================================================================
echo "=== A. generate() — JSON niepoprawny SKŁADNIOWO przy 200 ===\n";
// ===========================================================================
$broken_json = '{"candidates": [ to nie jest poprawny JSON ...';
$hA = new S2Http( array( 'status' => 200, 'body' => $broken_json ) );
$pA = new GeminiProvider( $hA, 'KLUCZ', 'gemini-2.5-flash', 'embed-model', '', 's2_noop_sleep' );
$rA = $pA->generate( 'pytanie testowe' );
check( is_wp_error( $rA ), 'A1: niepoprawny JSON przy 200 → WP_Error (nie fatal, nie string)' );
check( is_wp_error( $rA ) && 'aifaq_gemini_parse' === $rA->get_error_code(), 'A2: kod błędu aifaq_gemini_parse' );
check( true === $pA->last_meta()['empty_text'], 'A3: last_meta()[empty_text] === true' );

// ===========================================================================
echo "\n=== B. embed() — JSON niepoprawny SKŁADNIOWO przy 200 ===\n";
// ===========================================================================
$hB = new S2Http( array( 'status' => 200, 'body' => '{ zupelnie zepsuty json' ) );
$pB = new GeminiProvider( $hB, 'KLUCZ', 'gemini-2.5-flash', 'embed-model', '', 's2_noop_sleep' );
$rB = $pB->embed( array( 'tekst 1', 'tekst 2' ) );
check( is_wp_error( $rB ), 'B1: niepoprawny JSON przy 200 → WP_Error' );
check( is_wp_error( $rB ) && 'aifaq_gemini_parse' === $rB->get_error_code(), 'B2: kod błędu aifaq_gemini_parse' );

// ===========================================================================
echo "\n=== C. Błąd HTTP (500) z niepoprawnym JSON w ciele ===\n";
// ===========================================================================
$hC = new S2Http( array( 'status' => 500, 'body' => 'Internal Server Error (nie JSON)' ) );
$pC = new GeminiProvider( $hC, 'KLUCZ', 'gemini-2.5-flash', 'embed-model', '', 's2_noop_sleep' );
$rC = $pC->generate( 'pytanie' );
check( is_wp_error( $rC ), 'C1: 500 z ciałem nie-JSON → WP_Error (json_decode null nie wywala isset)' );
check( is_wp_error( $rC ) && 'aifaq_gemini_http' === $rC->get_error_code(), 'C2: kod aifaq_gemini_http' );
check( is_wp_error( $rC ) && false !== strpos( $rC->get_error_message(), '500' ), 'C3: komunikat fallback zawiera kod HTTP (500)' );

// ===========================================================================
echo "\n=== D. verify() — bezpośrednio na GeminiProvider (bez FakeProvider) ===\n";
// ===========================================================================
$hD1 = new S2Http( array( 'status' => 200, 'body' => json_encode( array( 'models' => array() ) ) ) );
$pD1 = new GeminiProvider( $hD1, 'DOBRY-KLUCZ', 'gemini-2.5-flash', 'embed-model' );
check( true === $pD1->verify(), 'D1: 200 → verify() zwraca true' );
check( 1 === $hD1->calls && false !== strpos( $hD1->last_url, 'generativelanguage.googleapis.com' ), 'D2: dokładnie 1 żądanie GET do API' );

$hD2 = new S2Http( array( 'status' => 400, 'body' => json_encode( array( 'error' => array( 'message' => 'API key not valid' ) ) ) ) );
$pD2 = new GeminiProvider( $hD2, 'ZLY-KLUCZ', 'gemini-2.5-flash', 'embed-model' );
$rD2 = $pD2->verify();
check( is_wp_error( $rD2 ), 'D3: klucz odrzucony → WP_Error' );
check( is_wp_error( $rD2 ) && 'API key not valid' === $rD2->get_error_message(), 'D4: komunikat błędu PRZEKAZANY z API bez zmian' );

$hD3 = new S2Http( array( 'status' => 403, 'body' => 'brak JSON w ciele' ) );
$pD3 = new GeminiProvider( $hD3, 'X', 'gemini-2.5-flash', 'embed-model' );
$rD3 = $pD3->verify();
check( is_wp_error( $rD3 ), 'D5: 403 bez JSON w ciele → WP_Error (nie fatal)' );
check( is_wp_error( $rD3 ) && false !== strpos( $rD3->get_error_message(), '403' ), 'D6: komunikat fallback zawiera kod HTTP (403)' );

$hD4 = new S2Http( new WP_Error( 'http_request_failed', 'DNS padło' ) );
$pD4 = new GeminiProvider( $hD4, 'X', 'gemini-2.5-flash', 'embed-model' );
$rD4 = $pD4->verify();
check( is_wp_error( $rD4 ) && 'http_request_failed' === $rD4->get_error_code(), 'D7: błąd transportu przekazany bez zmian przez verify()' );

// ===========================================================================
echo "\n=== E. HTTP 401/403 — reprezentanci ścieżki bez retry (dołączają do 404/429/503) ===\n";
// ===========================================================================
foreach ( array( 401, 403 ) as $code ) {
	$h = new S2Http( array( 'status' => $code, 'body' => json_encode( array( 'error' => array( 'message' => "blad $code" ) ) ) ) );
	$p = new GeminiProvider( $h, 'X', 'gemini-2.5-flash', 'embed-model', '', 's2_noop_sleep' );
	$r = $p->generate( 'x' );
	check( is_wp_error( $r ) && 'aifaq_gemini_http' === $r->get_error_code(), "E.$code: HTTP $code → aifaq_gemini_http" );
	check( 1 === $h->calls, "E.$code: dokładnie 1 próba, ZERO ponowień (kod nie jest 429/503)" );
}

// ===========================================================================
echo "\n=== F. Kontrakt: pusty klucz API NIE jest walidowany przez GeminiProvider samą ===\n";
// ===========================================================================
// GeminiProvider jest CZYSTYM transportem (patrz docblock klasy) — walidacja "czy w ogóle
// skonfigurowano klucz" świadomie żyje warstwę wyżej (Settings::verify_key(), 'aifaq_no_key',
// patrz krok9-settings-rest-test.php). Ten test dokumentuje, że pusty string w ogóle NIE
// zatrzymuje żądania tutaj — leci do transportu jak każdy inny, a odpowiedź (401/403 od
// prawdziwego API) wraca jako zwykły WP_Error, bez fatala i bez udawanego sukcesu.
$hF = new S2Http( array( 'status' => 401, 'body' => json_encode( array( 'error' => array( 'message' => 'API key not valid.' ) ) ) ) );
$pF = new GeminiProvider( $hF, '', 'gemini-2.5-flash', 'embed-model', '', 's2_noop_sleep' );
$rF = $pF->generate( 'pytanie gościa' );
check( 1 === $hF->calls, 'F1: pusty klucz API NIE blokuje wysyłki — żądanie i tak poszło do transportu' );
check( is_wp_error( $rF ) && 'aifaq_gemini_http' === $rF->get_error_code(), 'F2: odpowiedź API na pusty klucz wraca jako zwykły WP_Error (bez fatala)' );

// ===========================================================================
echo "\n=== PODSUMOWANIE ===\n";
echo ( 0 === $fail ) ? "TEST K23 ETAP 3 SEGMENT S2 (dostawca + błędy API): WSZYSTKIE ASERCJE OK\n" : "TEST K23 ETAP 3 SEGMENT S2 (dostawca + błędy API): $fail ASERCJI NIE PRZESZŁO\n";
exit( $fail === 0 ? 0 : 1 );
