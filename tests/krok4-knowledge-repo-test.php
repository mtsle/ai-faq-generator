<?php
/**
 * Test regresyjny Kroku 4 — KnowledgeRepository (warstwa danych) na ATRAPIE $wpdb.
 *
 * Atrapa symuluje tabelę w pamięci, więc test weryfikuje FAKTYCZNE zachowanie
 * (zapis → odczyt), nie tylko wywołane metody:
 *  - encode/decode embeddingu i hash treści (czyste, bez bazy),
 *  - save_chunk(): kodowanie wektora, auto content_hash, embedding null,
 *  - replace_for_post(): kasuje stare, wstawia nowe, auto chunk_index, nadpisuje post_id,
 *  - hashes_for_post(): mapa chunk_index => hash tylko dla danego wpisu,
 *  - all_with_embeddings(): tylko wiersze z embeddingiem, wektor zdekodowany do float,
 *  - clear_all() + stats().
 *
 * URUCHOMIENIE:  php tests/krok4-knowledge-repo-test.php
 * Nie wymaga WordPressa ani sieci. Kod wyjścia: 0 = OK, 1 = błędy.
 *
 * @package AI_FAQ_Generator
 */

// --- shimy WP ---
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $d ) { return json_encode( $d ); } }
if ( ! function_exists( 'current_time' ) ) { function current_time( $type ) { return '2026-07-09 00:00:00'; } }

/**
 * Atrapa $wpdb — tabela w pamięci. Obsługuje tylko zapytania używane przez repo.
 */
class FakeWpdb {
	public $prefix    = 'wp_';
	public $insert_id = 0;
	public $rows      = array();
	private $auto     = 0;
	private $lastArgs = array();

	public function insert( $table, $data ) {
		$row               = array_merge( array( 'id' => ++$this->auto ), $data );
		$this->rows[]      = $row;
		$this->insert_id   = $this->auto;
		return 1;
	}

	public function delete( $table, $where, $fmt = null ) {
		$before      = count( $this->rows );
		$this->rows  = array_values(
			array_filter(
				$this->rows,
				function ( $r ) use ( $where ) {
					foreach ( $where as $k => $v ) {
						if ( (string) ( $r[ $k ] ?? null ) !== (string) $v ) {
							return true; // nie pasuje → zostaje.
						}
					}
					return false; // pasuje do wszystkich warunków → usuwamy.
				}
			)
		);
		return $before - count( $this->rows );
	}

	public function query( $sql ) {
		if ( false !== stripos( $sql, 'DELETE FROM' ) ) {
			$n          = count( $this->rows );
			$this->rows = array();
			return $n;
		}
		return 0;
	}

	public function prepare( $sql, ...$args ) {
		$this->lastArgs = $args;
		return $sql;
	}

	public function get_results( $sql, $output = null ) {
		if ( false !== stripos( $sql, 'chunk_index, content_hash' ) ) {
			$pid = (int) ( $this->lastArgs[0] ?? 0 );
			$out = array();
			foreach ( $this->rows as $r ) {
				if ( (int) $r['post_id'] === $pid ) {
					$out[] = array( 'chunk_index' => $r['chunk_index'], 'content_hash' => $r['content_hash'] );
				}
			}
			return $out;
		}
		if ( false !== stripos( $sql, 'embedding IS NOT NULL' ) ) {
			$out = array();
			foreach ( $this->rows as $r ) {
				if ( null !== ( $r['embedding'] ?? null ) ) {
					$out[] = array( 'id' => $r['id'], 'post_id' => $r['post_id'], 'content' => $r['content'], 'embedding' => $r['embedding'] );
				}
			}
			return $out;
		}
		return array();
	}

	public function get_row( $sql, $output = null ) {
		if ( false !== stripos( $sql, 'COUNT(*)' ) ) {
			$posts = array();
			$emb   = 0;
			foreach ( $this->rows as $r ) {
				$posts[ (int) $r['post_id'] ] = true;
				if ( null !== ( $r['embedding'] ?? null ) ) { ++$emb; }
			}
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

use AIFAQ\Data\KnowledgeRepository;

$fail = 0;
function check( $cond, $label ) {
	global $fail;
	echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n";
	if ( ! $cond ) { $fail++; }
}
function reset_db() { global $wpdb; $wpdb->rows = array(); }

$repo = new KnowledgeRepository();

// ===========================================================================
echo "=== A. Kodowanie wektora + hash (czyste) ===\n";
check( '[1,2,3]' === KnowledgeRepository::encode_embedding( array( 1, 2, 3 ) ), "encode_embedding → JSON '[1,2,3]'" );
$dec = KnowledgeRepository::decode_embedding( '[1,2,3]' );
check( array( 1.0, 2.0, 3.0 ) === $dec && is_float( $dec[0] ), "decode_embedding → tablica float" );
check( null === KnowledgeRepository::decode_embedding( null ), "decode(null) → null" );
check( null === KnowledgeRepository::decode_embedding( '' ), "decode('') → null" );
check( null === KnowledgeRepository::decode_embedding( 'śmieć' ), "decode(śmieć) → null" );
check( KnowledgeRepository::hash( 'krowa' ) === KnowledgeRepository::hash( 'krowa' ), "hash stabilny dla tej samej treści" );
check( KnowledgeRepository::hash( 'krowa' ) !== KnowledgeRepository::hash( 'koń' ), "hash różny dla różnej treści" );
check( KnowledgeRepository::hash( '  krowa  ' ) === KnowledgeRepository::hash( 'krowa' ), "hash ignoruje białe znaki brzegowe (trim)" );

echo "\n=== B. save_chunk() ===\n";
reset_db();
$id = $repo->save_chunk( array( 'post_id' => 5, 'chunk_index' => 0, 'content' => 'Krowy dają mleko.', 'embedding' => array( 0.1, 0.2 ), 'tokens' => 4 ) );
check( $id > 0, "zwraca ID > 0" );
$row = $wpdb->rows[0];
check( '[0.1,0.2]' === $row['embedding'], "embedding zapisany jako JSON" );
check( KnowledgeRepository::hash( 'Krowy dają mleko.' ) === $row['content_hash'], "content_hash policzony automatycznie" );
check( 4 === $row['tokens'] && '2026-07-09 00:00:00' === $row['updated_at'], "tokens + updated_at ustawione" );
reset_db();
$repo->save_chunk( array( 'post_id' => 5, 'content' => 'bez wektora' ) );
check( null === $wpdb->rows[0]['embedding'], "brak embeddingu → NULL w kolumnie" );
reset_db();
$repo->save_chunk( array( 'post_id' => 5, 'content' => 'pusty wektor', 'embedding' => array() ) );
check( null === $wpdb->rows[0]['embedding'], "pusty wektor [] → NULL (F2, nie '[]')" );
reset_db();
$repo->save_chunk( array( 'post_id' => 5, 'content' => 'x', 'content_hash' => 'RECZNY' ) );
check( 'RECZNY' === $wpdb->rows[0]['content_hash'], "podany content_hash użyty (bez nadpisania)" );

echo "\n=== C. replace_for_post() ===\n";
reset_db();
$repo->save_chunk( array( 'post_id' => 7, 'chunk_index' => 0, 'content' => 'stary A' ) );
$repo->save_chunk( array( 'post_id' => 7, 'chunk_index' => 1, 'content' => 'stary B' ) );
$repo->save_chunk( array( 'post_id' => 9, 'chunk_index' => 0, 'content' => 'obcy wpis' ) );
$n = $repo->replace_for_post( 7, array(
	array( 'content' => 'nowy A' ),
	array( 'content' => 'nowy B' ),
	array( 'content' => 'nowy C' ),
) );
check( 3 === $n, "zwraca liczbę wstawionych = 3" );
$h7 = $repo->hashes_for_post( 7 );
check( 3 === count( $h7 ) && isset( $h7[0], $h7[1], $h7[2] ), "po podmianie 3 fragmenty, chunk_index 0..2 (auto)" );
check( $h7[0] === KnowledgeRepository::hash( 'nowy A' ), "stare fragmenty wpisu 7 usunięte, są nowe" );
check( 1 === count( $repo->hashes_for_post( 9 ) ), "wpis 9 nietknięty (izolacja per post_id)" );
$repo->replace_for_post( 9, array( array( 'post_id' => 123, 'content' => 'z nadpisaniem' ) ) );
check( 1 === count( $repo->hashes_for_post( 9 ) ) && 0 === count( $repo->hashes_for_post( 123 ) ), "post_id z argumentu nadpisuje ten z fragmentu" );

echo "\n=== D. all_with_embeddings() — dekodowanie ===\n";
reset_db();
$repo->save_chunk( array( 'post_id' => 1, 'content' => 'z wektorem', 'embedding' => array( 0.5, 0.6, 0.7 ) ) );
$repo->save_chunk( array( 'post_id' => 1, 'content' => 'bez wektora' ) );
$all = $repo->all_with_embeddings();
check( 1 === count( $all ), "zwraca tylko wiersze z embeddingiem (pomija NULL)" );
check( array( 0.5, 0.6, 0.7 ) === $all[0]['embedding'] && is_float( $all[0]['embedding'][0] ), "embedding zdekodowany do tablicy float" );

echo "\n=== E. clear_all() + stats() ===\n";
reset_db();
$repo->save_chunk( array( 'post_id' => 1, 'content' => 'a', 'embedding' => array( 1 ) ) );
$repo->save_chunk( array( 'post_id' => 1, 'content' => 'b' ) );
$repo->save_chunk( array( 'post_id' => 2, 'content' => 'c', 'embedding' => array( 2 ) ) );
$st = $repo->stats();
check( 3 === $st['chunks'] && 2 === $st['posts'] && 2 === $st['embedded'], "stats: chunks=3, posts=2, embedded=2" );
$removed = $repo->clear_all();
check( 3 === $removed, "clear_all zwraca liczbę usuniętych = 3" );
$st2 = $repo->stats();
check( 0 === $st2['chunks'] && 0 === $st2['posts'] && 0 === $st2['embedded'], "po clear_all baza pusta" );

echo "\n=== PODSUMOWANIE ===\n";
echo ( 0 === $fail ) ? "TEST KROK 4: WSZYSTKIE ASERCJE OK\n" : "TEST KROK 4: $fail ASERCJI NIE PRZESZŁO\n";
exit( $fail === 0 ? 0 : 1 );
