<?php
/**
 * Test regresyjny Kroku 5 — Indexer (integracja całego potoku).
 *
 * Wpina PRAWDZIWE: Chunker + EmbeddingBatcher + KnowledgeRepository (atrapa
 * $wpdb) oraz atrapy ContentSource i providera. Sprawdza: pełny przebieg,
 * skip-unchanged (bez kosztu embed), re-indeks po zmianie, czyszczenie wpisu
 * bez treści, zbieranie błędów per wpis.
 *
 * URUCHOMIENIE:  php -d extension=mbstring tests/krok5-indexer-test.php
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
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $d ) { return json_encode( $d ); } }
if ( ! function_exists( 'current_time' ) ) { function current_time( $t ) { return '2026-07-09 00:00:00'; } }
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }

/**
 * Atrapa $wpdb — tabela w pamięci (jak w teście Kroku 4), z no-op transakcjami.
 */
class FakeWpdb {
	public $prefix = 'wp_';
	public $insert_id = 0;
	public $rows = array();
	private $auto = 0;
	private $lastArgs = array();
	public function insert( $table, $data ) { $this->rows[] = array_merge( array( 'id' => ++$this->auto ), $data ); $this->insert_id = $this->auto; return 1; }
	public function delete( $table, $where, $fmt = null ) {
		$before = count( $this->rows );
		$this->rows = array_values( array_filter( $this->rows, function ( $r ) use ( $where ) {
			foreach ( $where as $k => $v ) { if ( (string) ( $r[ $k ] ?? null ) !== (string) $v ) { return true; } }
			return false;
		} ) );
		return $before - count( $this->rows );
	}
	public function query( $sql ) {
		if ( false !== stripos( $sql, 'NOT IN' ) ) {
			$keep = array_map( 'intval', $this->lastArgs );
			$before = count( $this->rows );
			$this->rows = array_values( array_filter( $this->rows, function ( $r ) use ( $keep ) {
				return in_array( (int) $r['post_id'], $keep, true );
			} ) );
			return $before - count( $this->rows );
		}
		if ( false !== stripos( $sql, 'DELETE FROM' ) ) { $n = count( $this->rows ); $this->rows = array(); return $n; }
		return 0; // START TRANSACTION / COMMIT / ROLLBACK — no-op w atrapie.
	}
	public function prepare( $sql, ...$args ) { $this->lastArgs = $args; return $sql; }
	public function get_results( $sql, $o = null ) {
		if ( false !== stripos( $sql, 'chunk_index, content_hash' ) ) {
			$pid = (int) ( $this->lastArgs[0] ?? 0 ); $out = array();
			foreach ( $this->rows as $r ) { if ( (int) $r['post_id'] === $pid ) { $out[] = array( 'chunk_index' => $r['chunk_index'], 'content_hash' => $r['content_hash'] ); } }
			return $out;
		}
		if ( false !== stripos( $sql, 'embedding IS NOT NULL' ) ) {
			$out = array();
			foreach ( $this->rows as $r ) { if ( null !== ( $r['embedding'] ?? null ) ) { $out[] = array( 'id' => $r['id'], 'post_id' => $r['post_id'], 'content' => $r['content'], 'embedding' => $r['embedding'] ); } }
			return $out;
		}
		return array();
	}
	public function get_row( $sql, $o = null ) {
		if ( false !== stripos( $sql, 'COUNT(*)' ) ) {
			$posts = array(); $emb = 0;
			foreach ( $this->rows as $r ) { $posts[ (int) $r['post_id'] ] = true; if ( null !== ( $r['embedding'] ?? null ) ) { ++$emb; } }
			return array( 'chunks' => count( $this->rows ), 'posts' => count( $posts ), 'embedded' => $emb );
		}
		return null;
	}
}

global $wpdb;
$wpdb = new FakeWpdb();

require __DIR__ . '/../src/Data/Schema.php';
require __DIR__ . '/../src/Data/Repository.php';
require __DIR__ . '/../src/Data/KnowledgeRepository.php';
require __DIR__ . '/../src/Providers/ProviderInterface.php';
require __DIR__ . '/../src/Index/ContentSource.php';
require __DIR__ . '/../src/Index/Chunker.php';
require __DIR__ . '/../src/Index/EmbeddingBatcher.php';
require __DIR__ . '/../src/Index/Indexer.php';

use AIFAQ\Data\KnowledgeRepository;
use AIFAQ\Index\Chunker;
use AIFAQ\Index\ContentSource;
use AIFAQ\Index\EmbeddingBatcher;
use AIFAQ\Index\Indexer;
use AIFAQ\Providers\ProviderInterface;

/** Atrapa źródła treści — konfigurowalne dokumenty. */
class FakeSource implements ContentSource {
	public $docs = array();
	public function documents(): array { return $this->docs; }
}

/** Atrapa providera — liczy wywołania embed; wektor = [długość tekstu]. */
class SpyProvider implements ProviderInterface {
	public $embed_calls = 0;
	public $mode = 'ok';
	public function generate( string $prompt, array $options = array() ) { return ''; }
	public function verify() { return true; }
	public function embed( array $texts ) {
		++$this->embed_calls;
		if ( 'error' === $this->mode ) { return new WP_Error( 'boom', 'awaria API' ); }
		$v = array();
		foreach ( $texts as $t ) { $v[] = array( (float) strlen( $t ) ); }
		return $v;
	}
}

$fail = 0;
function check( $cond, $label ) {
	global $fail;
	echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n";
	if ( ! $cond ) { $fail++; }
}

$src   = new FakeSource();
$prov  = new SpyProvider();
$repo  = new KnowledgeRepository();
$idx   = new Indexer( $src, new Chunker( 60, 15 ), new EmbeddingBatcher( $prov, 100 ), $repo );

$src->docs = array(
	array( 'post_id' => 1, 'title' => 'Krowy', 'url' => 'u1', 'text' => 'Krowa daje mleko każdego dnia. Rolnik doi krowy rano i wieczorem. Obora jest duża i ciepła. Cielęta rosną szybko.' ),
	array( 'post_id' => 2, 'title' => 'Byk', 'url' => 'u2', 'text' => 'Byk jest silny.' ),
);

// ===========================================================================
echo "=== A. Pierwszy przebieg ===\n";
$r1 = $idx->run();
check( 2 === $r1['posts'] && 2 === $r1['indexed'] && 0 === $r1['skipped'], "posts=2, indexed=2, skipped=0" );
check( $r1['chunks'] > 2 && array() === $r1['errors'], "zapisano >2 fragmenty, bez błędów (" . $r1['chunks'] . ')' );
check( $r1['chunks'] === count( $repo->all_with_embeddings() ), "wszystkie fragmenty mają embedding" );
$calls_after_1 = $prov->embed_calls;
check( $calls_after_1 >= 2, "provider embed wołany (calls=" . $calls_after_1 . ')' );

echo "\n=== B. Drugi przebieg — skip-unchanged (bez kosztu API) ===\n";
$r2 = $idx->run();
check( 2 === $r2['skipped'] && 0 === $r2['indexed'] && 0 === $r2['chunks'], "skipped=2, indexed=0, chunks=0" );
check( $prov->embed_calls === $calls_after_1, "provider embed NIE wołany ponownie (skip działa)" );

echo "\n=== C. Zmiana jednego wpisu → re-indeks tylko jego ===\n";
$src->docs[1]['text'] = 'Byk jest bardzo silny i groźny dla stada.';
$r3 = $idx->run();
check( 1 === $r3['indexed'] && 1 === $r3['skipped'], "zmieniony wpis: indexed=1, drugi skipped=1" );
check( $prov->embed_calls === $calls_after_1 + 1, "embed wołany dokładnie raz (tylko zmieniony wpis)" );

echo "\n=== D. Wpis traci treść → wyczyszczony ===\n";
$src->docs[1]['text'] = '   ';
$r4 = $idx->run();
check( 1 === $r4['cleared'] && 1 === $r4['skipped'] && 0 === $r4['indexed'], "cleared=1, skipped=1, indexed=0" );
$post2_left = 0;
foreach ( $repo->all_with_embeddings() as $row ) { if ( 2 === (int) $row['post_id'] ) { $post2_left++; } }
check( 0 === $post2_left, "fragmenty wpisu 2 usunięte z bazy" );

echo "\n=== E. Błąd embed → zebrany w raporcie, nie przerywa ===\n";
$prov->mode = 'error';
$src->docs[0]['text'] = 'Zupełnie nowa treść o krowach mlecznych i ich diecie.';
$r5 = $idx->run();
check( ! empty( $r5['errors'] ) && 0 === $r5['indexed'], "błąd embed → errors niepuste, indexed=0" );
check( false !== strpos( $r5['errors'][0], 'awaria API' ), "komunikat błędu providera w raporcie" );

echo "\n=== F. Pruning osieroconych wpisów ===\n";
$prov->mode = 'ok';
$wpdb->rows = array(); // czysta baza.
$src->docs  = array(
	array( 'post_id' => 1, 'title' => 'A', 'url' => 'u1', 'text' => 'Pierwszy wpis o krowach mlecznych i ich diecie.' ),
	array( 'post_id' => 2, 'title' => 'B', 'url' => 'u2', 'text' => 'Drugi wpis o bykach i rozrodzie stada.' ),
);
$idx->run(); // oba zaindeksowane.
$before_prune = count( $repo->all_with_embeddings() );
// Wpis 2 znika ze źródła (usunięty/odpublikowany).
$src->docs = array( $src->docs[0] );
$rP = $idx->run();
check( $rP['pruned'] >= 1, "wpis usunięty ze źródła → pruned≥1 (" . $rP['pruned'] . ')' );
check( 1 === $rP['skipped'] && 0 === $rP['indexed'], "pozostały wpis bez zmian → skipped=1, indexed=0" );
$post2_after = 0;
foreach ( $repo->all_with_embeddings() as $row ) { if ( 2 === (int) $row['post_id'] ) { $post2_after++; } }
check( 0 === $post2_after && $before_prune > count( $repo->all_with_embeddings() ), "fragmenty osieroconego wpisu 2 usunięte z bazy" );

echo "\n=== PODSUMOWANIE ===\n";
echo ( 0 === $fail ) ? "TEST KROK 5 (Indexer): WSZYSTKIE ASERCJE OK\n" : "TEST KROK 5 (Indexer): $fail ASERCJI NIE PRZESZŁO\n";
exit( $fail === 0 ? 0 : 1 );
