<?php
/**
 * Test poprawki K23 etap 1 — sanityzacja ścieżki `/admin/faq/publish` z `pairs`.
 *
 * PRZED poprawką: `PublishService::publish()` wołał `PairsInput::from_snapshot()`
 * na parach przysłanych PROSTO Z PRZEGLĄDARKI (parametr `pairs` żądania REST).
 * `from_snapshot()` świadomie NIE sanityzuje (zakłada dane już zapisane w bazie),
 * więc surowy HTML/JS z formularza szedł bez zmian na publiczną podstronę i do
 * JSON-LD `FAQPage`, dostając tylko `trim()` — inaczej niż eksport
 * (`PairsInput::from_request()`), który robi `sanitize_textarea_field` + `wp_unslash`.
 *
 * PO poprawce: `publish()` woła nową `PairsInput::from_request_for_publish()`,
 * która sanityzuje (jak `from_request()`) I przycina długość/liczbę par (jak
 * `from_snapshot()`, przez wspólną `Exporter::normalize()`).
 *
 * Pokrywa:
 *  A. `PairsInput::from_request_for_publish()` — sanityzacja HTML/skryptu (unit).
 *  B. `PairsInput::from_request_for_publish()` — przycięcie długości pojedynczego pola.
 *  C. `PairsInput::from_request_for_publish()` — cap liczby par do `Exporter::MAX_PAIRS`.
 *  D. Kontrast: `PairsInform::from_snapshot()` na TYCH SAMYCH danych NIE sanityzuje
 *     (dokumentuje, dlaczego `publish()` nie mógł jej dalej używać dla `pairs` z żądania).
 *  E. Integracja: `PublishService::publish()` z `pairs` z „przeglądarki" zapisuje do
 *     `update_option()` treść już sanityzowaną i przyciętą — dowód end-to-end.
 *
 * URUCHOMIENIE:  php tests/krok23-etap1-rest-sanityzacja-test.php
 * Kod wyjścia: 0 = OK, 1 = błędy.
 *
 * @package AI_FAQ_Generator
 */

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }

// --- shimy WP (te same wzorce co audyt-bezpieczenstwa-test.php / krok7-rest-test.php) ---
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'wp_unslash' ) ) { function wp_unslash( $s ) { return is_string( $s ) ? stripslashes( $s ) : $s; } }
if ( ! function_exists( 'sanitize_textarea_field' ) ) { function sanitize_textarea_field( $s ) { return trim( (string) preg_replace( '/<[^>]*>/', '', (string) $s ) ); } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ) { return trim( (string) preg_replace( '/<[^>]*>/', '', (string) $s ) ); } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); } }
if ( ! function_exists( 'esc_url_raw' ) ) { function esc_url_raw( $s ) { return (string) $s; } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); } }
if ( ! function_exists( 'current_time' ) ) { function current_time( $t ) { return '2026-07-28 12:00:00'; } }

// Opcje w pamięci — pozwala zweryfikować DOKŁADNIE to, co trafia do bazy.
$GLOBALS['__opt'] = array();
if ( ! function_exists( 'get_option' ) ) { function get_option( $k, $d = false ) { return $GLOBALS['__opt'][ $k ] ?? $d; } }
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $k, $v, $autoload = null ) { $GLOBALS['__opt'][ $k ] = $v; return true; }
}
if ( ! function_exists( 'delete_option' ) ) { function delete_option( $k ) { unset( $GLOBALS['__opt'][ $k ] ); return true; } }

// current_user_can — bez uprawnień administratora, żeby ścieżka `id` nie mieszała się
// do testu (chcemy wyłącznie ścieżkę `pairs` z przeglądarki, tę z błędu).
if ( ! function_exists( 'current_user_can' ) ) { function current_user_can( $cap ) { return false; } }

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		private $data; private $status;
		public function __construct( $data = null, $status = 200 ) { $this->data = $data; $this->status = (int) $status; }
		public function get_data() { return $this->data; }
		public function get_status() { return $this->status; }
	}
}
class SanReq {
	private $p;
	public function __construct( array $p ) { $this->p = $p; }
	public function get_param( $k ) { return $this->p[ $k ] ?? null; }
}

// publish() woła PageGuard::page_url() tylko do pola `url` w odpowiedzi (poza
// zakresem tego testu) — ładujemy realną klasę; bez `aifaq_page_id` w opcjach
// (get_option shim wyżej) zwraca po prostu pusty string, nieszkodliwie.
require __DIR__ . '/../src/PublicUi/PageGuard.php';
require __DIR__ . '/../src/Faq/Exporter.php';
require __DIR__ . '/../src/Faq/PublicFaq.php';
require __DIR__ . '/../src/Rest/PairsInput.php';
require __DIR__ . '/../src/Rest/PublishService.php';

use AIFAQ\Faq\Exporter;
use AIFAQ\Rest\PairsInput;
use AIFAQ\Rest\PublishService;

$fail = 0;
function check( $cond, $label ) { global $fail; echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n"; if ( ! $cond ) { $fail++; } }

// ===========================================================================
echo "=== A. from_request_for_publish() — sanityzacja HTML/skryptu ===\n";
// ===========================================================================
$zlosliwe = array(
	array(
		'question' => '<script>alert(1)</script>Pytanie ze skryptem',
		'answer'   => 'Odpowiedź z <b onclick="alert(2)">znacznikiem</b> HTML',
	),
);
$got = PairsInput::from_request_for_publish( $zlosliwe );
check( 1 === count( $got ), 'A1: jedna para przeszła' );
check( false === strpos( $got[0]['question'], '<script' ), 'A2: <script> usunięty z pytania' );
check( false === strpos( $got[0]['answer'], '<b' ), 'A3: znacznik <b onclick=...> usunięty z odpowiedzi' );
check( false !== strpos( $got[0]['question'], 'Pytanie ze skryptem' ), 'A4: właściwa treść pytania zachowana' );

// ===========================================================================
echo "\n=== B. from_request_for_publish() — przycięcie długości pola ===\n";
// ===========================================================================
$dlugie = array(
	array(
		'question' => str_repeat( 'a', 1000 ),
		'answer'   => str_repeat( 'b', 6000 ),
	),
);
$got = PairsInput::from_request_for_publish( $dlugie );
check( 1 === count( $got ), 'B1: jedna para przeszła' );
check( Exporter::MAX_QUESTION_CHARS === mb_strlen( $got[0]['question'] ), 'B2: pytanie przycięte do MAX_QUESTION_CHARS (' . Exporter::MAX_QUESTION_CHARS . ')' );
check( Exporter::MAX_ANSWER_CHARS === mb_strlen( $got[0]['answer'] ), 'B3: odpowiedź przycięta do MAX_ANSWER_CHARS (' . Exporter::MAX_ANSWER_CHARS . ')' );

// ===========================================================================
echo "\n=== C. from_request_for_publish() — cap liczby par do Exporter::MAX_PAIRS ===\n";
// ===========================================================================
$duzo = array();
for ( $i = 0; $i < 70; $i++ ) { $duzo[] = array( 'question' => "Q$i", 'answer' => "A$i" ); }
$got = PairsInput::from_request_for_publish( $duzo );
check( Exporter::MAX_PAIRS === count( $got ), 'C1: cap do MAX_PAIRS (' . Exporter::MAX_PAIRS . '), jest: ' . count( $got ) );

// ===========================================================================
echo "\n=== D. Kontrast: from_snapshot() na tych samych danych NIE sanityzuje ===\n";
// ===========================================================================
// Dokumentuje PRZYCZYNĘ błędu: from_snapshot() zakłada dane już zapisane w bazie
// (sanityzowane przy zapisie), więc na SUROWYCH danych z przeglądarki przepuszcza
// znaczniki bez zmian — to właśnie dlatego publish() nie mógł jej dalej wołać
// bezpośrednio dla `pairs` z żądania.
$got_snapshot = PairsInput::from_snapshot( $zlosliwe );
check( 1 === count( $got_snapshot ), 'D1: from_snapshot() też przepuszcza 1 parę' );
check( false !== strpos( $got_snapshot[0]['question'], '<script' ), 'D2 (DOWÓD PRZYCZYNY): from_snapshot() zostawia <script> nietknięty' );
check( false !== strpos( $got_snapshot[0]['answer'], '<b onclick' ), 'D3 (DOWÓD PRZYCZYNY): from_snapshot() zostawia onclick nietknięty' );

// ===========================================================================
echo "\n=== E. Integracja: PublishService::publish() z `pairs` z przeglądarki ===\n";
// ===========================================================================
$GLOBALS['__opt'] = array();
$service = new PublishService();
$resp    = $service->publish( new SanReq( array( 'pairs' => $zlosliwe ) ) );

check( 200 === $resp->get_status(), 'E1: publikacja odpowiada 200 (jest: ' . $resp->get_status() . ')' );
check( 1 === ( $resp->get_data()['count'] ?? 0 ), 'E2: opublikowano dokładnie 1 parę' );

$stored = $GLOBALS['__opt'][ \AIFAQ\Faq\PublicFaq::OPTION ]['pairs'][0] ?? array();
check( false === strpos( $stored['question'] ?? '<zabraklo>', '<script' ), 'E3 (KLUCZOWA): opcja w bazie NIE zawiera <script> ze zgłoszonej luki' );
check( false === strpos( $stored['answer'] ?? '<zabraklo>', 'onclick' ), 'E4 (KLUCZOWA): opcja w bazie NIE zawiera onclick ze zgłoszonej luki' );
check( false !== strpos( $stored['question'] ?? '', 'Pytanie ze skryptem' ), 'E5: właściwa treść pytania trafiła do bazy' );

// Ta sama ścieżka z nadmierną długością — dowód przycięcia end-to-end.
$GLOBALS['__opt'] = array();
$resp2  = $service->publish( new SanReq( array( 'pairs' => $dlugie ) ) );
$stored2 = $GLOBALS['__opt'][ \AIFAQ\Faq\PublicFaq::OPTION ]['pairs'][0] ?? array();
check( 200 === $resp2->get_status(), 'E6: publikacja długich par odpowiada 200' );
check( Exporter::MAX_QUESTION_CHARS === mb_strlen( $stored2['question'] ?? '' ), 'E7 (KLUCZOWA): opcja w bazie ma pytanie przycięte do MAX_QUESTION_CHARS' );
check( Exporter::MAX_ANSWER_CHARS === mb_strlen( $stored2['answer'] ?? '' ), 'E8 (KLUCZOWA): opcja w bazie ma odpowiedź przyciętą do MAX_ANSWER_CHARS' );

// Regresja: poprawne, krótkie pary dalej publikują się bez zmian treści.
$GLOBALS['__opt'] = array();
$resp3   = $service->publish( new SanReq( array( 'pairs' => array( array( 'question' => 'Zwykłe pytanie?', 'answer' => 'Zwykła odpowiedź.' ) ) ) ) );
$stored3 = $GLOBALS['__opt'][ \AIFAQ\Faq\PublicFaq::OPTION ]['pairs'][0] ?? array();
check( 200 === $resp3->get_status(), 'E9 (regresja): zwykła para dalej publikuje się (200)' );
check( 'Zwykłe pytanie?' === ( $stored3['question'] ?? '' ), 'E10 (regresja): treść zwykłej pary nietknięta' );

// ===========================================================================
echo "\n=== PODSUMOWANIE ===\n";
echo ( 0 === $fail ) ? "TEST K23 ETAP 1 (sanityzacja publish): WSZYSTKIE ASERCJE OK\n" : "TEST K23 ETAP 1 (sanityzacja publish): $fail ASERCJI NIE PRZESZŁO\n";
exit( $fail === 0 ? 0 : 1 );
