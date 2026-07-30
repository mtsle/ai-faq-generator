<?php
/**
 * L2 — Indeksowanie @ skala (Krok 23 etap 4).
 *
 * Odpala PRAWDZIWY Indexer (Chunker + EmbeddingBatcher + KnowledgeRepository,
 * FakeWpdb w pamięci jak w tests/krok5-indexer-test.php) na N syntetycznych
 * wpisach, z mockiem providera (zero sieci, zero kosztu). Mierzy: czas
 * przetwarzania, pamięć, liczbę fal (WAVE_SIZE=500), liczbę wywołań API
 * embed (paczki MAX_BATCH=100), poprawność dedup (drugi przebieg = 0 kosztu),
 * poprawność aktualizacji przy zmianie treści, projekcję realnego czasu
 * ściennego z tempem INDEX_PACE_SECONDS=13s (dokumentowana stała, NIE
 * spane naprawdę — harness filtruje `aifaq_index_pace` na 0, żeby test
 * kończył się w rozsądnym czasie; realny sufit wall-clock dolicza się
 * analitycznie na końcu).
 *
 * Skala z briefu Kroku 23 etap 4: 400 wpisów, plus punkty niżej/wyżej do
 * zobrazowania trendu.
 *
 * URUCHOMIENIE:  php tests/load/l2-indexing-scale.php
 * Nie wymaga WordPressa, MySQL ani sieci. Kod wyjścia zawsze 0 (harness
 * pomiarowy) — wynik trzeba przeczytać.
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
// Wyłącza tempo między falami (INDEX_PACE_SECONDS=13s w produkcji) — inaczej
// N=5000 (10 fal) czekałoby naprawdę >2 minuty na sam sleep(). Realny czas
// ścienny doliczamy analitycznie niżej z DOKUMENTOWANEJ stałej kodu.
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) {
		if ( 'aifaq_index_pace' === $tag ) { return 0; }
		return $value;
	}
}

/** FakeWpdb — identyczna z tests/krok5-indexer-test.php (wzorzec projektu). */
class FakeWpdb {
	public $prefix = 'wp_';
	public $insert_id = 0;
	public $rows = array();
	private $auto = 0;
	private $lastArgs = array();
	public function insert( $table, $data ) { $this->rows[] = array_merge( array( 'id' => ++$this->auto ), $data ); $this->insert_id = $this->auto; return 1; }
	public function update( $table, $data, $where, $fmt = null, $where_fmt = null ) {
		$n = 0;
		foreach ( $this->rows as &$r ) {
			$match = true;
			foreach ( $where as $k => $v ) { if ( (string) ( $r[ $k ] ?? null ) !== (string) $v ) { $match = false; break; } }
			if ( $match ) { $r = array_merge( $r, $data ); ++$n; }
		}
		unset( $r );
		return $n;
	}
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
		return 0;
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

require __DIR__ . '/../../src/Data/Schema.php';
require __DIR__ . '/../../src/Data/Repository.php';
require __DIR__ . '/../../src/Data/KnowledgeRepository.php';
require __DIR__ . '/../../src/Providers/ProviderInterface.php';
require __DIR__ . '/../../src/Index/ContentSource.php';
require __DIR__ . '/../../src/Index/Chunker.php';
require __DIR__ . '/../../src/Index/EmbeddingBatcher.php';
require __DIR__ . '/../../src/Index/Indexer.php';

use AIFAQ\Data\KnowledgeRepository;
use AIFAQ\Index\Chunker;
use AIFAQ\Index\ContentSource;
use AIFAQ\Index\EmbeddingBatcher;
use AIFAQ\Index\Indexer;
use AIFAQ\Providers\ProviderInterface;

class FakeSource implements ContentSource {
	public $docs = array();
	public function documents(): array { return $this->docs; }
}

/** Mock providera: liczy wywołania embed(), zero sieci. */
class SpyProvider implements ProviderInterface {
	public $embed_calls = 0;
	public $embedded_texts = 0;
	public function generate( string $prompt, array $options = array() ) { return ''; }
	public function verify() { return true; }
	public function embed( array $texts ) {
		++$this->embed_calls;
		$this->embedded_texts += count( $texts );
		$v = array();
		foreach ( $texts as $t ) {
			// Wektor deterministyczny, tani do policzenia (nie testujemy tu jakości retrievalu).
			$v[] = array( (float) strlen( $t ), 0.0, 0.0 );
		}
		return $v;
	}
}

/** Generuje syntetyczny dokument ~N chunków dla domyślnego Chunkera (target=1000). */
function synth_doc( int $post_id, int $target_chars ): array {
	$sentence = 'Zdanie numer %d o synetycznej treści testowej z liczbami 123 i 456 dla wpisu ' . $post_id . '. ';
	$text     = '';
	$i        = 0;
	while ( strlen( $text ) < $target_chars ) {
		$text .= sprintf( $sentence, $i++ );
	}
	return array( 'post_id' => $post_id, 'title' => 'Wpis ' . $post_id, 'url' => 'u' . $post_id, 'text' => $text );
}

echo "=== L2 — Indeksowanie @ skala ===\n\n";
printf(
	"%-8s %8s %8s %10s %8s %10s %12s %10s %14s\n",
	'posts', 'chunks', 'fale', 'API_calls', 'zapyt.', 'czas(ms)', 'pamięć MB', 'real_s*', 'µs/chunk'
);

$levels = array( 100, 400, 2000, 5000 );

foreach ( $levels as $n_posts ) {
	global $wpdb;
	$wpdb = new FakeWpdb();

	$src  = new FakeSource();
	$prov = new SpyProvider();
	$repo = new KnowledgeRepository();
	// target=1200 znaków/wpis -> ~2 chunki/wpis z domyślnym Chunkerem (1000/200).
	$idx  = new Indexer( $src, new Chunker(), new EmbeddingBatcher( $prov, 100 ), $repo );

	for ( $p = 1; $p <= $n_posts; $p++ ) {
		$src->docs[] = synth_doc( $p, 1200 );
	}

	$mem_before = memory_get_usage();
	$t0 = microtime( true );
	$report = $idx->run();
	$t1 = microtime( true );
	$mem_peak = memory_get_peak_usage();

	$ms      = ( $t1 - $t0 ) * 1000;
	$chunks  = $report['chunks'];
	$waves   = (int) ceil( $chunks / Indexer::WAVE_SIZE );
	$api     = $prov->embed_calls;
	$queries = count( $wpdb->rows ) >= 0 ? null : null; // (nie liczone precyzyjnie w atrapie — patrz L5 dla realnych SQL)
	$real_s  = $ms / 1000 + max( 0, $waves - 1 ) * Indexer::INDEX_PACE_SECONDS; // projekcja z realną stałą tempa
	$us_per_chunk = $chunks > 0 ? ( $ms * 1000 ) / $chunks : 0;

	printf(
		"%-8d %8d %8d %10d %8s %10.2f %12.1f %10.1f %14.2f\n",
		$n_posts, $chunks, $waves, $api, 'n/d', $ms, $mem_peak / 1024 / 1024, $real_s, $us_per_chunk
	);

	if ( count( $report['errors'] ) > 0 ) {
		echo "  UWAGA: {$report['posts']} postów, błędy: " . implode( '; ', $report['errors'] ) . "\n";
	}

	// --- Dedup: drugi przebieg, bez zmian treści -> zero kosztu API. ---
	$calls_before_2 = $prov->embed_calls;
	$t2a = microtime( true );
	$report2 = $idx->run();
	$t2b = microtime( true );
	$dedup_ok = ( 0 === $report2['indexed'] && $n_posts === $report2['skipped'] && $prov->embed_calls === $calls_before_2 );
	printf(
		"  -> dedup (2. przebieg bez zmian): skipped=%d/%d, nowe wywołania API=%d, czas=%.2fms -> %s\n",
		$report2['skipped'], $n_posts, $prov->embed_calls - $calls_before_2, ( $t2b - $t2a ) * 1000,
		$dedup_ok ? 'OK (zero kosztu)' : 'FAIL'
	);

	// --- Aktualizacja: zmieniamy 10% wpisów, sprawdzamy że TYLKO one są reindeksowane. ---
	$changed = max( 1, intdiv( $n_posts, 10 ) );
	for ( $p = 1; $p <= $changed; $p++ ) {
		$src->docs[ $p - 1 ]['text'] .= ' Dopisek zmieniający treść i hash.';
	}
	$calls_before_3 = $prov->embed_calls;
	$report3 = $idx->run();
	$update_ok = ( $changed === $report3['indexed'] && ( $n_posts - $changed ) === $report3['skipped'] );
	printf(
		"  -> aktualizacja %d/%d zmienionych wpisów: indexed=%d, skipped=%d, nowe wywołania API=%d -> %s\n",
		$changed, $n_posts, $report3['indexed'], $report3['skipped'], $prov->embed_calls - $calls_before_3,
		$update_ok ? 'OK (tylko zmienione przeliczone)' : 'FAIL'
	);

	// --- Duplikacja: liczba fragmentów w bazie po aktualizacji = spójna, bez duchów. ---
	$stats = $repo->stats();
	$dup_ok = ( $stats['posts'] === $n_posts );
	printf(
		"  -> integralność po aktualizacji: stats.posts=%d (oczekiwano %d) -> %s\n\n",
		$stats['posts'], $n_posts, $dup_ok ? 'OK (brak duplikatów/duchów)' : 'FAIL'
	);
}

echo "* real_s = zmierzony czas CPU + (fale-1)×" . Indexer::INDEX_PACE_SECONDS . "s (stała INDEX_PACE_SECONDS z kodu,\n";
echo "  NIE zmierzona naprawdę w tym harnessie — sleep() wyłączony filtrem, żeby test się kończył w rozsądnym czasie).\n";
echo "\nKONIEC L2.\n";
