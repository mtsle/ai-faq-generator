<?php
/**
 * Krok 23 etap 3, segment S8 (REST — trasy, uprawnienia, nonce) — łatanie luki z Fazy A:
 * `AskService::map_result()` (mapowanie wyniku `RagService::ask()` na kod HTTP trasy
 * `POST /ask`, wydzielone z monolitu `RestController` w K23 etap 1) nie miało dotąd ANI
 * JEDNEJ bezpośredniej asercji — ani `map_result()`, ani `with_debug()` nigdy nie były
 * wywołane w żadnym pliku testowym.
 *
 * Przy weryfikacji pozostałych dwóch znalezisk Fazy A dla tego segmentu okazały się
 * FAŁSZYWYMI ALARMAMI (sprawdzone czytaniem kodu, nie zgadywaniem — nie dodaję do nich
 * testów, bo już istnieją):
 *  - `GuestIdentity::ip_hash()` MA realne testy behawioralne w `krok20-capy-test.php`
 *    (`ReflectionMethod` na `RestController::ip_hash()`, który jest tylko cienkim
 *    wrapperem delegującym do `GuestIdentity` — `RestController.php:424-425`).
 *  - Macierz uprawnień w `krok20-capy-test.php` JUŻ uwzględnia zawężenie publikacji do
 *    Redaktora z K23 etap 1 (sekcja „Krok 23: Autor"/„Krok 23: Redaktor", linie 332-356)
 *    — nie jest sprzed tej zmiany, jak sugerował audyt.
 *
 * Pokrywa:
 *  A. `status=error` + `source=rate_limit` → 429, `status: rate_limited`.
 *  B. `status=error` + `source=provider_rate_limit` → 429 (ten sam kod co A).
 *  C. `status=error` + `source=ai` (awaria generyczna) → 502, komunikat OGÓLNY
 *     (KOLEJNOŚĆ GAŁĘZI z kontraktu: 429 sprawdzane PRZED ogólnym 502).
 *  D. `status=answered` + `source=ai` → 200, `cached=false`, `score` zaokrąglony do 4 miejsc.
 *  E. `status=answered` + `source=cache` → 200, `cached=true`.
 *  F. `status=refused` → 200, status przekazany bez zmian.
 *  G. `debug` niepuste, ale wołający NIE jest adminem → `debug` NIE dołączone (żadna
 *     z trzech gałęzi: 200/429/502).
 *  H. `debug` niepuste I wołający JEST adminem → `debug` dołączone (wszystkie 3 gałęzie).
 *  I. `debug` puste (`[]`) → nigdy nie dołączone, nawet dla admina (guard przed pustką).
 *
 * URUCHOMIENIE:  php tests/krok23-etap3-s8-askservice-test.php
 * Kod wyjścia: 0 = OK, 1 = błędy.
 *
 * @package AI_FAQ_Generator
 */

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }

if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		private $data; private $status;
		public function __construct( $data = null, $status = 200 ) { $this->data = $data; $this->status = (int) $status; }
		public function get_data() { return $this->data; }
		public function get_status() { return $this->status; }
	}
}

$GLOBALS['__is_admin'] = false;
if ( ! function_exists( 'current_user_can' ) ) { function current_user_can( $cap ) { return 'manage_options' === $cap && $GLOBALS['__is_admin']; } }

require __DIR__ . '/../src/Rest/AskService.php';

use AIFAQ\Rest\AskService;

$fail = 0;
function check( $cond, $label ) { global $fail; echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n"; if ( ! $cond ) { $fail++; } }

$svc = new AskService();
$debug_payload = array( 'stage' => 'guard', 'top_k' => array( array( 'id' => 1, 'score' => 0.9 ) ) );

// ===========================================================================
echo "=== A/B. Limit własny i limit dostawcy → 429 rate_limited ===\n";
// ===========================================================================
foreach ( array( 'rate_limit', 'provider_rate_limit' ) as $src ) {
	$r = $svc->map_result( array( 'status' => 'error', 'source' => $src, 'answer' => '', 'score' => 0.0, 'debug' => array() ) );
	check( 429 === $r->get_status(), "A/B.$src: HTTP 429" );
	check( 'rate_limited' === ( $r->get_data()['status'] ?? '' ), "A/B.$src: status rate_limited" );
}

// ===========================================================================
echo "\n=== C. Błąd generyczny (source=ai) → 502, komunikat ogólny ===\n";
// ===========================================================================
$r = $svc->map_result( array( 'status' => 'error', 'source' => 'ai', 'answer' => '', 'score' => 0.0, 'debug' => array() ) );
check( 502 === $r->get_status(), 'C1: HTTP 502 (NIE 429 — kolejność gałęzi z kontraktu)' );
check( 'error' === ( $r->get_data()['status'] ?? '' ), 'C2: status error' );
check( false === strpos( json_encode( $r->get_data() ), 'RAW' ), 'C3: brak wycieku surowego komunikatu (message jest z góry ustalony, ogólny)' );

// ===========================================================================
echo "\n=== D. answered + source=ai → 200, cached=false, score zaokrąglony ===\n";
// ===========================================================================
$r = $svc->map_result( array( 'status' => 'answered', 'source' => 'ai', 'answer' => 'Treść odpowiedzi.', 'score' => 0.123456789, 'debug' => array() ) );
$d = $r->get_data();
check( 200 === $r->get_status(), 'D1: HTTP 200' );
check( 'answered' === $d['status'], 'D2: status answered' );
check( false === $d['cached'], 'D3: cached=false dla source=ai' );
check( 0.1235 === $d['score'], 'D4: score zaokrąglony do 4 miejsc (0.123456789 → 0.1235)' );
check( 'Treść odpowiedzi.' === $d['answer'], 'D5: treść odpowiedzi przekazana bez zmian' );

// ===========================================================================
echo "\n=== E. answered + source=cache → 200, cached=true ===\n";
// ===========================================================================
$r = $svc->map_result( array( 'status' => 'answered', 'source' => 'cache', 'answer' => 'X', 'score' => 1.0, 'debug' => array() ) );
check( true === $r->get_data()['cached'], 'E1: cached=true dla source=cache' );

// ===========================================================================
echo "\n=== F. refused → 200, status przekazany bez zmian ===\n";
// ===========================================================================
$r = $svc->map_result( array( 'status' => 'refused', 'source' => 'ai', 'answer' => 'Nie mogę pomóc w tym temacie.', 'score' => 0.2, 'debug' => array() ) );
check( 200 === $r->get_status(), 'F1: HTTP 200 (odmowa to NIE błąd)' );
check( 'refused' === $r->get_data()['status'], 'F2: status refused' );

// ===========================================================================
echo "\n=== G. debug niepuste, wołający NIE jest adminem → NIGDY nie dołączone ===\n";
// ===========================================================================
$GLOBALS['__is_admin'] = false;
foreach ( array(
	array( 'status' => 'error', 'source' => 'rate_limit' ),
	array( 'status' => 'error', 'source' => 'ai' ),
	array( 'status' => 'answered', 'source' => 'ai' ),
) as $base ) {
	$r = $svc->map_result( $base + array( 'answer' => 'x', 'score' => 0.5, 'debug' => $debug_payload ) );
	check( ! array_key_exists( 'debug', $r->get_data() ), 'G: gość (' . $base['status'] . '/' . $base['source'] . ') → BEZ klucza debug w odpowiedzi' );
}

// ===========================================================================
echo "\n=== H. debug niepuste, wołający JEST adminem → dołączone we wszystkich gałęziach ===\n";
// ===========================================================================
$GLOBALS['__is_admin'] = true;
foreach ( array(
	array( 'status' => 'error', 'source' => 'rate_limit' ),
	array( 'status' => 'error', 'source' => 'ai' ),
	array( 'status' => 'answered', 'source' => 'ai' ),
) as $base ) {
	$r = $svc->map_result( $base + array( 'answer' => 'x', 'score' => 0.5, 'debug' => $debug_payload ) );
	check( 'guard' === ( $r->get_data()['debug']['stage'] ?? '' ), 'H: admin (' . $base['status'] . '/' . $base['source'] . ') → debug DOŁĄCZONE i poprawne' );
}

// ===========================================================================
echo "\n=== I. debug puste ([]) → nigdy nie dołączone, nawet dla admina ===\n";
// ===========================================================================
$GLOBALS['__is_admin'] = true;
$r = $svc->map_result( array( 'status' => 'answered', 'source' => 'ai', 'answer' => 'x', 'score' => 0.5, 'debug' => array() ) );
check( ! array_key_exists( 'debug', $r->get_data() ), 'I1: debug=[] → BEZ klucza debug mimo current_user_can(manage_options)===true' );

// ===========================================================================
echo "\n=== PODSUMOWANIE ===\n";
echo ( 0 === $fail ) ? "TEST K23 ETAP 3 SEGMENT S8 (AskService::map_result): WSZYSTKIE ASERCJE OK\n" : "TEST K23 ETAP 3 SEGMENT S8 (AskService::map_result): $fail ASERCJI NIE PRZESZŁO\n";
exit( $fail === 0 ? 0 : 1 );
