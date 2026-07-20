<?php
/**
 * Testy rdzenia RAG (Dział 4 „RAG Core", Krok 6).
 *
 * Pokrywa:
 *  - Retriever: cosinus dla wektorów NIEznormalizowanych (znane wartości), norma 0 → 0 (GR2),
 *    kolejność i liczność top-K, pominięcie wektora o złym wymiarze, stronicowanie (GR6).
 *  - TopicGuard: granica progu (≥ przechodzi), pusta lista → refuse, fail-closed (GR4).
 *  - RateLimiter: okno/limit (N ok, N+1 blok), 0 = wyłączony, reset okna (GR5).
 *  - RagService: kolejność potoku (cache→limit→embed→retrieve→guard→answer), odmowa off-topic,
 *    trafienie cache (bez API), błąd embed → error, ścieżka answered + zapis cache + log (GR3/GR4/GR5).
 *
 * URUCHOMIENIE:  php tests/krok6-rag-test.php
 * Kod wyjścia: 0 = OK, 1 = błędy.
 *
 * @package AI_FAQ_Generator
 */

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }

// --- shimy WP ---
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
if ( ! function_exists( 'current_time' ) ) { function current_time( $t ) { return '2026-07-12 00:00:00'; } }

// Transienty w pamięci (dla RateLimiter).
$GLOBALS['__aifaq_transients'] = array();
if ( ! function_exists( 'get_transient' ) ) { function get_transient( $k ) { return $GLOBALS['__aifaq_transients'][ $k ] ?? false; } }
if ( ! function_exists( 'set_transient' ) ) { function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['__aifaq_transients'][ $k ] = $v; return true; } }
if ( ! function_exists( 'delete_transient' ) ) { function delete_transient( $k ) { unset( $GLOBALS['__aifaq_transients'][ $k ] ); return true; } }

$fail = 0;
function check( $cond, $label ) {
	global $fail;
	echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n";
	if ( ! $cond ) { $fail++; }
}
function approx( $a, $b, $eps = 1e-9 ) { return abs( (float) $a - (float) $b ) < $eps; }

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

// --- Atrapy ---

/** Baza wiedzy w pamięci: rows = [ ['id'=>, 'post_id'=>, 'embedding'=>[]], ... ]. */
class FakeKnowledge extends KnowledgeRepository {
	public $rows;
	public $contents;
	public function __construct( array $rows = array(), array $contents = array() ) { $this->rows = $rows; $this->contents = $contents; }
	public function count_embedded(): int { return count( $this->rows ); }
	public function embeddings_page( int $limit, int $offset ): array { return 0 === $offset ? $this->rows : array(); }
	public function contents_for( array $ids ): array {
		$out = array();
		foreach ( $ids as $id ) { if ( isset( $this->contents[ $id ] ) ) { $out[ $id ] = $this->contents[ $id ]; } }
		return $out;
	}
}

class FakeCache extends CacheRepository {
	public $store; public $put_calls = 0;
	public function __construct( $store = null ) { $this->store = $store; }
	public function get_by_question( string $q ): ?array { return $this->store; }
	public function put( string $q, string $a ): int { $this->put_calls++; return 1; }
}

class FakeQaLog extends QaLogRepository {
	public $entries = array();
	public function log( array $entry ): int { $this->entries[] = $entry; return count( $this->entries ); }
}

class FakeProvider implements ProviderInterface {
	public $vector; public $gen; public $embed_calls = 0; public $gen_calls = 0;
	public function __construct( $vector, $gen ) { $this->vector = $vector; $this->gen = $gen; }
	public function generate( string $prompt, array $options = array() ) { $this->gen_calls++; return $this->gen; }
	public function embed( array $texts ) { $this->embed_calls++; return is_wp_error( $this->vector ) ? $this->vector : array( $this->vector ); }
	public function verify() { return true; }
}

// Limiter, który zawsze odmawia (do testu ścieżki rate-limit w RagService).
class DenyLimiter extends RateLimiter {
	public function __construct() { parent::__construct( 1 ); }
	public function allow( string $ip_hash ): bool { return false; }
}

function make_service( $provider, $knowledge, $cache, $qa, $config, $limiter = null ) {
	return new RagService(
		$provider,
		new Retriever( $knowledge ),
		new TopicGuard(),
		$limiter ?? new RateLimiter( 0 ),
		new Answerer( $provider ),
		$knowledge,
		$cache,
		$qa,
		$config
	);
}

$config = array(
	'threshold'   => 0.5,
	'top_k'       => 5,
	'temperature' => 0.2,
	'max_tokens'  => 500,
	'language'    => 'pl',
	'refusals'    => array( 'pl' => 'ODMOWA-PL', 'en' => 'REFUSE-EN', 'de' => '' ),
);

// ============ Retriever ============
echo "=== Retriever: cosinus (wektory NIEznormalizowane) ===\n";
$repo = new FakeKnowledge(
	array(
		array( 'id' => 1, 'post_id' => 10, 'embedding' => array( 3.0, 0.0 ) ), // ten sam kierunek co [1,0] → 1.0
		array( 'id' => 2, 'post_id' => 20, 'embedding' => array( 0.0, 5.0 ) ), // prostopadły → 0.0
		array( 'id' => 3, 'post_id' => 30, 'embedding' => array( 2.0, 2.0 ) ), // 45° → 1/√2
	)
);
$r = new Retriever( $repo );
$res = $r->retrieve( array( 1.0, 0.0 ), 5 );
check( 3 === count( $res ), 'zwraca wszystkie 3 (top_k=5 > baza)' );
check( 1 === $res[0]['id'] && approx( $res[0]['score'], 1.0 ), 'najlepszy = id1, score 1.0 (nieznormalizowany [3,0])' );
$byid = array(); foreach ( $res as $x ) { $byid[ $x['id'] ] = $x['score']; }
check( approx( $byid[3], 1 / sqrt( 2 ) ), 'id3 [2,2] vs [1,0] → 1/√2 ≈ 0.7071' );
check( approx( $byid[2], 0.0 ), 'id2 prostopadły → 0.0' );
check( $res[0]['score'] >= $res[1]['score'] && $res[1]['score'] >= $res[2]['score'], 'posortowane malejąco' );

echo "\n=== Retriever: top-K, zły wymiar, norma 0 ===\n";
$res2 = $r->retrieve( array( 1.0, 0.0 ), 2 );
check( 2 === count( $res2 ), 'top_k=2 przycina do 2' );
$repo3 = new FakeKnowledge( array(
	array( 'id' => 1, 'post_id' => 1, 'embedding' => array( 1.0, 0.0, 0.0 ) ), // zły wymiar (3 vs 2)
	array( 'id' => 2, 'post_id' => 1, 'embedding' => array( 1.0, 0.0 ) ),
) );
$res3 = ( new Retriever( $repo3 ) )->retrieve( array( 1.0, 0.0 ), 5 );
check( 1 === count( $res3 ) && 2 === $res3[0]['id'], 'wektor o złym wymiarze pominięty' );
check( array() === $r->retrieve( array( 0.0, 0.0 ), 5 ), 'zerowy wektor pytania → brak wyników (GR2)' );
check( array() === ( new Retriever( new FakeKnowledge() ) )->retrieve( array( 1.0, 0.0 ), 5 ), 'pusta baza → brak wyników' );

// ============ TopicGuard ============
echo "\n=== TopicGuard: próg (≥), fail-closed ===\n";
$g = new TopicGuard();
$hits = array( array( 'id' => 1, 'post_id' => 1, 'score' => 0.80 ), array( 'id' => 2, 'post_id' => 1, 'score' => 0.40 ) );
$d1 = $g->evaluate( $hits, 0.7 );
check( 'pass' === $d1['decision'] && approx( $d1['score'], 0.80 ), 'najlepszy 0.80 ≥ próg 0.7 → pass' );
check( array( 1 ) === $d1['ids'], 'K19: pass filtruje ids progiem twardym — 0.40 < 0.7 wypada' );
$d1b = $g->evaluate( $hits, 0.7, 0.0, false );
check( array( 1, 2 ) === $d1b['ids'], 'K19: filter_ids=false → wszystkie id (tryb legacy do benchu)' );
$d2 = $g->evaluate( $hits, 0.80 );
check( 'pass' === $d2['decision'], 'dokładnie na progu (0.80 ≥ 0.80) → pass' );
$d3 = $g->evaluate( $hits, 0.81 );
check( 'refuse' === $d3['decision'] && array() === $d3['ids'], 'poniżej progu → refuse, brak id (fail-closed)' );
$d4 = $g->evaluate( array(), 0.5 );
check( 'refuse' === $d4['decision'] && approx( $d4['score'], 0.0 ), 'pusta lista → refuse' );

// ============ RateLimiter ============
echo "\n=== RateLimiter: okno/limit, wyłącznik, reset ===\n";
$t = 1000;
$clock = function () use ( &$t ) { return $t; };
$GLOBALS['__aifaq_transients'] = array();
$rl = new RateLimiter( 3, $clock );
$ip = str_repeat( 'a', 64 );
check( $rl->allow( $ip ), 'przed pierwszym → allow' );
$rl->hit( $ip ); $rl->hit( $ip ); $rl->hit( $ip );
check( ! $rl->allow( $ip ), 'po 3 hitach przy limicie 3 → blok' );
$t += 3601; // okno minęło
check( $rl->allow( $ip ), 'po upływie okna → znów allow (reset)' );
$rl0 = new RateLimiter( 0, $clock );
$rl0->hit( $ip ); $rl0->hit( $ip );
check( $rl0->allow( $ip ), 'limit 0 = wyłączony → zawsze allow' );

// ============ RagService: potok ============
echo "\n=== RagService: trafienie cache (bez API) ===\n";
$GLOBALS['__aifaq_transients'] = array();
$prov = new FakeProvider( array( 1.0, 0.0 ), 'Z KONTEKSTU' );
$cacheHit = new FakeCache( array( 'answer' => 'Odpowiedź z cache' ) );
$qa = new FakeQaLog();
$svc = make_service( $prov, new FakeKnowledge(), $cacheHit, $qa, $config );
$out = $svc->ask( 'Cokolwiek?', str_repeat( 'b', 64 ) );
check( 'answered' === $out['status'] && 'cache' === $out['source'], 'cache hit → answered/source=cache' );
check( 0 === $prov->embed_calls, 'cache hit NIE woła embed (GR5)' );

echo "\n=== RagService: odmowa off-topic (poniżej progu) ===\n";
$repoT = new FakeKnowledge(
	array( array( 'id' => 1, 'post_id' => 5, 'embedding' => array( 1.0, 1.0 ) ) ), // vs [1,0] → 0.707
	array( 1 => 'Treść o krowach.' )
);
$prov2 = new FakeProvider( array( 1.0, 0.0 ), 'NIE POWINNO PAŚĆ' );
$qa2 = new FakeQaLog();
$cfgHigh = array_merge( $config, array( 'threshold' => 0.99 ) );
$svc2 = make_service( $prov2, $repoT, new FakeCache( null ), $qa2, $cfgHigh );
$out2 = $svc2->ask( 'Kim był Napoleon?', str_repeat( 'c', 64 ) );
check( 'refused' === $out2['status'] && 'ODMOWA-PL' === $out2['answer'], 'poniżej progu → refused z komunikatem PL' );
check( 0 === $prov2->gen_calls, 'odmowa NIE woła generate (fail-closed, GR4)' );
check( 'refused' === end( $qa2->entries )['status'], 'wpis w dzienniku = refused' );

echo "\n=== RagService: ścieżka answered (retrieve→guard→answer→cache→log) ===\n";
$repoA = new FakeKnowledge(
	array(
		array( 'id' => 1, 'post_id' => 7, 'embedding' => array( 1.0, 0.0 ) ), // idealne trafienie
		array( 'id' => 2, 'post_id' => 8, 'embedding' => array( 0.0, 1.0 ) ),
	),
	array( 1 => 'Krowa daje mleko.', 2 => 'Coś innego.' )
);
$prov3 = new FakeProvider( array( 1.0, 0.0 ), 'Krowa daje mleko.' );
$cache3 = new FakeCache( null );
$qa3 = new FakeQaLog();
$svc3 = make_service( $prov3, $repoA, $cache3, $qa3, $config );
$out3 = $svc3->ask( 'Ile mleka daje krowa?', str_repeat( 'd', 64 ) );
check( 'answered' === $out3['status'] && 'ai' === $out3['source'], 'trafienie → answered/source=ai' );
check( 'Krowa daje mleko.' === $out3['answer'], 'odpowiedź z providera' );
check( 1 === $prov3->embed_calls && 1 === $prov3->gen_calls, 'dokładnie 1× embed + 1× generate' );
check( 1 === $cache3->put_calls, 'odpowiedź zapisana do cache (GR5)' );
check( approx( $out3['score'], 1.0 ), 'score = najlepszy cosinus (1.0)' );
check( 'answered' === end( $qa3->entries )['status'], 'log answered z ip_hash' );
check( 64 === strlen( end( $qa3->entries )['ip_hash'] ), 'ip_hash zapisany (nie surowe IP, GR7)' );

echo "\n=== RagService: błąd embed → error; rate-limit → error ===\n";
$provErr = new FakeProvider( new WP_Error( 'x', 'boom' ), '' );
$qa4 = new FakeQaLog();
$svc4 = make_service( $provErr, new FakeKnowledge(), new FakeCache( null ), $qa4, $config );
$out4 = $svc4->ask( 'Pytanie', str_repeat( 'e', 64 ) );
check( 'error' === $out4['status'], 'WP_Error z embed → error (GR4)' );

$prov5 = new FakeProvider( array( 1.0, 0.0 ), 'x' );
$qa5 = new FakeQaLog();
$svc5 = make_service( $prov5, new FakeKnowledge(), new FakeCache( null ), $qa5, $config, new DenyLimiter() );
$out5 = $svc5->ask( 'Pytanie', str_repeat( 'f', 64 ) );
check( 'error' === $out5['status'] && 'rate_limit' === $out5['source'], 'przekroczony limit → error/source=rate_limit' );
check( 0 === $prov5->embed_calls, 'rate-limit blokuje PRZED API (GR5)' );

echo "\n=== RagService: puste pytanie → error ===\n";
$svc6 = make_service( new FakeProvider( array( 1.0 ), 'x' ), new FakeKnowledge(), new FakeCache( null ), new FakeQaLog(), $config );
check( 'error' === $svc6->ask( '   ', str_repeat( 'g', 64 ) )['status'], 'puste pytanie → error' );

echo "\n" . ( 0 === $fail ? "WSZYSTKIE OK" : "BŁĘDY: $fail" ) . "\n";
exit( 0 === $fail ? 0 : 1 );
