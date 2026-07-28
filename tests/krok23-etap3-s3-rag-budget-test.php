<?php
/**
 * Krok 23 etap 3, segment S3 (potok RAG end-to-end) — łatanie luki z Fazy A:
 * ścieżka WŁAŚCICIELA (`manage_options`) wobec dobowego sufitu witryny
 * (`RagService::budget_active()`/`budget_enabled()`, `RagService.php:517-546`) nie miała
 * ŻADNEGO testu na poziomie `RagService::ask()` — tylko fragmentaryczne testy jednostkowe
 * niższych metod w innych plikach.
 *
 * Kontrakt (z komentarzy w kodzie, §13.12): właściciel jest wyłączony z ODBIJANIA na
 * sufcie (nie dostaje 429, testuje własną wtyczkę po wyczerpaniu puli), ale PŁACI
 * jednostkę tak samo jak gość — inaczej licznik na Dashboardzie kłamałby i sufit dla
 * GOŚCI zamykałby się za późno. Te dwie połówki (bramka vs licznik) stoją na DWÓCH
 * różnych metodach (`budget_active()` vs `budget_enabled()`) — rozjazd między nimi
 * byłby łatwy do przeoczyć przy przyszłej zmianie.
 *
 * Pokrywa:
 *  A. Gość (nie-admin) z wyczerpanym sufitem dobowym → odbity (`error`/`rate_limit`),
 *     licznik NIE rośnie ponad to, co już było (kontrast/regresja).
 *  B. Właściciel (`manage_options`) z DOKŁADNIE TYM SAMYM wyczerpanym sufitem →
 *     NIE odbity, dostaje normalną odpowiedź `answered`.
 *  C. Ta sama sytuacja B: licznik `aifaq_daily_usage` mimo to WZRASTA o dokładnie 1
 *     po żądaniu właściciela (dowód, że jednostka jest płacona, nie tylko przepuszczona).
 *  D. Sufit WYŁĄCZONY (`daily_budget = 0`) → gość też przechodzi, licznik się NIE rusza
 *     w ogóle (żaden odczyt/zapis opcji) — kontrast z A/B, gdzie sufit jest aktywny.
 *
 * URUCHOMIENIE:  php tests/krok23-etap3-s3-rag-budget-test.php
 * Kod wyjścia: 0 = OK, 1 = błędy.
 *
 * @package AI_FAQ_Generator
 */

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code; private $message;
		public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
		public function get_error_message() { return $this->message; }
		public function get_error_code() { return $this->code; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $t ) { return $t instanceof WP_Error; } }
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'wp_unslash' ) ) { function wp_unslash( $s ) { return is_string( $s ) ? stripslashes( $s ) : $s; } }
if ( ! function_exists( 'sanitize_textarea_field' ) ) { function sanitize_textarea_field( $s ) { return trim( (string) preg_replace( '/<[^>]*>/', '', (string) $s ) ); } }

// Zegar i opcje w pamięci — dobowy sufit czyta/pisze WYŁĄCZNIE przez te dwie funkcje.
$GLOBALS['__now']  = '2026-07-28 12:00:00';
$GLOBALS['__opt']  = array();
if ( ! function_exists( 'current_time' ) ) { function current_time( $t ) { return ( 'Y-m-d' === $t ) ? substr( $GLOBALS['__now'], 0, 10 ) : $GLOBALS['__now']; } }
if ( ! function_exists( 'get_option' ) ) { function get_option( $k, $d = false ) { return $GLOBALS['__opt'][ $k ] ?? $d; } }
if ( ! function_exists( 'update_option' ) ) { function update_option( $k, $v, $autoload = null ) { $GLOBALS['__opt'][ $k ] = $v; return true; } }

// current_user_can sterowalny z testu — decyduje, czy „gość" czy „właściciel".
$GLOBALS['__is_admin'] = false;
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $cap ) { return 'manage_options' === $cap && $GLOBALS['__is_admin']; }
}
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $tag, $value ) { return $value; } }

require __DIR__ . '/../src/Data/Schema.php';
require __DIR__ . '/../src/Data/Repository.php';
require __DIR__ . '/../src/Data/KnowledgeRepository.php';
require __DIR__ . '/../src/Data/CacheRepository.php';
require __DIR__ . '/../src/Data/QaLogRepository.php';
require __DIR__ . '/../src/Providers/ProviderInterface.php';
require __DIR__ . '/../src/Rag/Retriever.php';
require __DIR__ . '/../src/Rag/TopicGuard.php';
require __DIR__ . '/../src/Rag/RateLimiter.php';
require __DIR__ . '/../src/Rag/Answerer.php';
require __DIR__ . '/../src/Rag/RagService.php';

use AIFAQ\Data\KnowledgeRepository;
use AIFAQ\Data\CacheRepository;
use AIFAQ\Data\QaLogRepository;
use AIFAQ\Providers\ProviderInterface;
use AIFAQ\Rag\Retriever;
use AIFAQ\Rag\TopicGuard;
use AIFAQ\Rag\RateLimiter;
use AIFAQ\Rag\Answerer;
use AIFAQ\Rag\RagService;

// Te same atrapy co krok6-rag-test.php (jedyne miejsce z tym wzorcem dziedziczenia —
// znane ryzyko architektoniczne z Fazy A: zmiana sygnatury klas bazowych wywali TEN plik
// fatalnie, nic więcej nie ostrzeże).
class S3Knowledge extends KnowledgeRepository {
	public $rows; public $contents;
	public function __construct( array $rows = array(), array $contents = array() ) { $this->rows = $rows; $this->contents = $contents; }
	public function count_embedded(): int { return count( $this->rows ); }
	public function embeddings_page( int $limit, int $offset ): array { return 0 === $offset ? $this->rows : array(); }
	public function contents_for( array $ids ): array {
		$out = array();
		foreach ( $ids as $id ) { if ( isset( $this->contents[ $id ] ) ) { $out[ $id ] = $this->contents[ $id ]; } }
		return $out;
	}
}
class S3Cache extends CacheRepository {
	public function get_by_question( string $q ): ?array { return null; }
	public function put( string $q, string $a, float $score = 0.0 ): int { return 1; }
}
class S3QaLog extends QaLogRepository {
	public $entries = array();
	public function log( array $entry ): int { $this->entries[] = $entry; return count( $this->entries ); }
}
class S3Provider implements ProviderInterface {
	public $vector; public $gen;
	// $gen to tekst zwracany przez generate() (kontrakt ProviderInterface: string|WP_Error),
	// NIE gotowy kształt {status,answer,meta} — tamten składa dopiero Answerer::answer().
	public function __construct( $vector, string $gen ) { $this->vector = $vector; $this->gen = $gen; }
	public function generate( string $prompt, array $options = array() ) { return $this->gen; }
	public function embed( array $texts ) { return array( $this->vector ); }
	public function verify() { return true; }
}

function s3_make_service( $daily_budget ) {
	$knowledge = new S3Knowledge(
		array( array( 'id' => 1, 'post_id' => 10, 'embedding' => array( 1.0, 0.0 ) ) ),
		array( 1 => 'Treść fragmentu o ofercie przedszkola.' )
	);
	$provider = new S3Provider(
		array( 1.0, 0.0 ), // wektor pytania — identyczny kierunek co fragment → score 1.0, na pewno "pass".
		'Odpowiedź modelu.'
	);
	return new RagService(
		$provider,
		new Retriever( $knowledge ),
		new TopicGuard(),
		new RateLimiter( 0 ), // limiter gościa WYŁĄCZONY — izolujemy WYŁĄCZNIE sufit witryny.
		new Answerer( $provider ),
		$knowledge,
		new S3Cache(),
		new S3QaLog(),
		array(
			'threshold'      => 0.5,
			'threshold_hard' => 0.3,
			'top_k'          => 5,
			'temperature'    => 0.2,
			'max_tokens'     => 500,
			'language'       => 'pl',
			'refusals'       => array( 'pl' => 'ODMOWA-PL' ),
			'daily_budget'   => $daily_budget,
		)
	);
}

$fail = 0;
function check( $cond, $label ) { global $fail; echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n"; if ( ! $cond ) { $fail++; } }

// ===========================================================================
echo "=== A. Gość, sufit WYCZERPANY → odbity (kontrast/regresja) ===\n";
// ===========================================================================
$GLOBALS['__is_admin'] = false;
$GLOBALS['__opt']      = array( 'aifaq_daily_usage' => array( 'd' => '2026-07-28', 'n' => 5 ) );
$svc_a  = s3_make_service( 5 ); // budget=5, już zużyte 5 → gość ma być odbity.
$res_a  = $svc_a->ask( 'Jakie są godziny otwarcia?', 'ip-gosc' );
check( 'error' === $res_a['status'], 'A1: gość przy wyczerpanym sufcie → status error (jest: ' . $res_a['status'] . ')' );
check( 'rate_limit' === $res_a['source'], 'A2: source === rate_limit' );
check( 5 === (int) ( $GLOBALS['__opt']['aifaq_daily_usage']['n'] ?? -1 ), 'A3: licznik NIE wzrósł ponad 5 (gość odbity PRZED budget_hit())' );

// ===========================================================================
echo "\n=== B. Właściciel, DOKŁADNIE TEN SAM wyczerpany sufit → NIE odbity ===\n";
// ===========================================================================
$GLOBALS['__is_admin'] = true;
$GLOBALS['__opt']      = array( 'aifaq_daily_usage' => array( 'd' => '2026-07-28', 'n' => 5 ) );
$svc_b = s3_make_service( 5 );
$res_b = $svc_b->ask( 'Jakie są godziny otwarcia?', 'ip-wlasciciel' );
check( 'answered' === $res_b['status'], 'B1: właściciel przy wyczerpanym sufcie → status answered (jest: ' . $res_b['status'] . ')' );
check( 'Odpowiedź modelu.' === $res_b['answer'], 'B2: dostaje realną odpowiedź, nie odmowę' );

// ===========================================================================
echo "\n=== C. Ta sama sytuacja B: licznik MIMO TO rośnie o dokładnie 1 ===\n";
// ===========================================================================
check( 6 === (int) ( $GLOBALS['__opt']['aifaq_daily_usage']['n'] ?? -1 ), 'C1 (KLUCZOWA): licznik 5 → 6 mimo że właściciel NIE został odbity (jednostka zapłacona)' );

// ===========================================================================
echo "\n=== D. Sufit WYŁĄCZONY (daily_budget=0) — kontrast: licznik się NIE rusza ===\n";
// ===========================================================================
$GLOBALS['__is_admin'] = false;
$GLOBALS['__opt']      = array(); // brak opcji w ogóle — dowód, że nic jej nie tworzy.
$svc_d = s3_make_service( 0 );
$res_d = $svc_d->ask( 'Jakie są godziny otwarcia?', 'ip-gosc-2' );
check( 'answered' === $res_d['status'], 'D1: sufit wyłączony → gość dostaje answered' );
check ( ! array_key_exists( 'aifaq_daily_usage', $GLOBALS['__opt'] ), 'D2: opcja aifaq_daily_usage NIE powstała (sufit=0 → zero odczytów/zapisów)' );

// ===========================================================================
echo "\n=== PODSUMOWANIE ===\n";
echo ( 0 === $fail ) ? "TEST K23 ETAP 3 SEGMENT S3 (RAG — sufit dobowy vs właściciel): WSZYSTKIE ASERCJE OK\n" : "TEST K23 ETAP 3 SEGMENT S3 (RAG — sufit dobowy vs właściciel): $fail ASERCJI NIE PRZESZŁO\n";
exit( $fail === 0 ? 0 : 1 );
