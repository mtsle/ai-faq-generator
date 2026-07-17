<?php
/**
 * Testy: GenerationRepository — historia generowań FAQ (Krok 11, Etap 3).
 *
 * Broni kontraktu repozytorium na atrapie $wpdb (bez WordPressa, bez bazy):
 *  - log(): wypisuje WYŁĄCZNIE znane kolumny; pary serializuje do pairs_json (JSON);
 *    num_questions domyślnie = liczba par; rzutowania typów; zwraca insert_id.
 *  - page(): clamp limitu 1..100, nieujemny offset, sort created_at DESC.
 *  - find(): dokłada rozkodowane `pairs` (round-trip JSON), null gdy brak wiersza.
 *  - delete()/count(): dziedziczone działają na tej tabeli.
 *
 * URUCHOMIENIE:  php tests/krok11-generations-repo-test.php
 * Kod wyjścia: 0 = OK, 1 = błędy.
 *
 * @package AI_FAQ_Generator
 */

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }

// --- shimy WP ---
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $d ) { return json_encode( $d ); } }
if ( ! function_exists( 'current_time' ) ) { function current_time( $type = 'mysql' ) { return '2026-07-18 10:00:00'; } }

require __DIR__ . '/../src/Data/Schema.php';
require __DIR__ . '/../src/Data/Repository.php';
require __DIR__ . '/../src/Data/GenerationRepository.php';

/**
 * Atrapa $wpdb: zapamiętuje ostatni insert/zapytania, oddaje zaprogramowane wiersze.
 */
class GenSpyWpdb {
	public $prefix     = 'wp_';
	public $insert_id  = 0;
	public $last_table = '';
	public $last_data  = array();
	public $queries    = array();
	public $row        = null;   // dla get_row (find)
	public $rows       = array(); // dla get_results (page)
	public $var        = 0;       // dla get_var (count)
	public $deleted    = 0;

	public function prepare( $q, ...$a ) {
		$q = str_replace( array( '%s', '%d' ), array( "'%s'", '%d' ), $q );
		return $a ? vsprintf( $q, $a ) : $q;
	}
	public function insert( $table, $data ) {
		$this->last_table = $table;
		$this->last_data  = $data;
		$this->insert_id  = 123;
		return 1;
	}
	public function get_results( $q, $o = null ) { $this->queries[] = $q; return $this->rows; }
	public function get_row( $q, $o = null ) { $this->queries[] = $q; return $this->row; }
	public function get_var( $q ) { $this->queries[] = $q; return $this->var; }
	public function delete( $table, $where, $fmt = null ) { $this->queries[] = "DELETE $table id={$where['id']}"; return $this->deleted; }
}

$fail = 0;
function check( $cond, $label ) { global $fail; echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n"; if ( ! $cond ) { $fail++; } }

global $wpdb;
$wpdb = new GenSpyWpdb();
$repo = new \AIFAQ\Data\GenerationRepository();

// ===========================================================================
echo "=== A. log() — kolumny, serializacja par, insert_id ===\n";
$pairs = array(
	array( 'question' => 'Ile mleka daje krowa?', 'answer' => 'Około 25 litrów dziennie.' ),
	array( 'question' => 'Co jedzą krowy?', 'answer' => 'Trawę i siano.' ),
);
$id = $repo->log( array(
	'topic'      => 'Krowy mleczne',
	'extra_desc' => 'FAQ dla hodowców',
	'language'   => 'pl',
	'user_id'    => 7,
	'pairs'      => $pairs,
) );
$d = $wpdb->last_data;

check( 123 === $id, "zwraca insert_id (123)" );
check( 'wp_aifaq_generations' === $wpdb->last_table, "insert do tabeli wp_aifaq_generations" );
check( 'Krowy mleczne' === ( $d['topic'] ?? null ), "topic zapisane" );
check( 'FAQ dla hodowców' === ( $d['extra_desc'] ?? null ), "extra_desc zapisane" );
check( 'pl' === ( $d['language'] ?? null ), "language zapisane" );
check( 7 === ( $d['user_id'] ?? null ), "user_id rzutowane na int" );
check( 2 === ( $d['num_questions'] ?? null ), "num_questions domyślnie = liczba par (2)" );
check( isset( $d['pairs_json'] ), "kolumna pairs_json obecna" );
$decoded = json_decode( (string) $d['pairs_json'], true );
check( is_array( $decoded ) && $pairs === $decoded, "pairs_json to poprawny JSON par (round-trip)" );
check( isset( $d['created_at'] ), "created_at ustawione (domyślnie current_time)" );
$known = array( 'created_at', 'topic', 'extra_desc', 'num_questions', 'language', 'user_id', 'pairs_json' );
check( array() === array_diff( array_keys( $d ), $known ), "insert wypisuje WYŁĄCZNIE znane kolumny" );

echo "\n=== B. log() — jawny num_questions i brak par ===\n";
$repo->log( array( 'topic' => 'Bez par', 'num_questions' => 5 ) );
$d2 = $wpdb->last_data;
check( 5 === ( $d2['num_questions'] ?? null ), "jawny num_questions ma pierwszeństwo" );
check( '[]' === (string) $d2['pairs_json'], "brak par → pairs_json = []" );
check( null === $d2['extra_desc'], "brak extra_desc → null" );
check( 'pl' === $d2['language'], "brak language → domyślnie pl" );
check( 0 === $d2['user_id'], "brak user_id → 0" );

echo "\n=== C. page() — clamp limitu i offsetu ===\n";
$wpdb->queries = array();
$repo->page( 999, -5 );
$q = end( $wpdb->queries );
check( false !== strpos( $q, 'LIMIT 100' ), "limit > 100 sklampowany do 100" );
check( false !== strpos( $q, 'OFFSET 0' ), "offset < 0 sklampowany do 0" );
$wpdb->queries = array();
$repo->page( 0, 40 );
$q2 = end( $wpdb->queries );
check( false !== strpos( $q2, 'LIMIT 1' ), "limit < 1 sklampowany do 1" );
check( false !== strpos( $q2, 'OFFSET 40' ), "offset dodatni zachowany" );
check( false !== strpos( $q2, 'ORDER BY created_at DESC' ), "sort created_at DESC" );

echo "\n=== D. find() — dekodowanie pairs, null gdy brak ===\n";
$wpdb->row = array( 'id' => 5, 'topic' => 'X', 'pairs_json' => json_encode( $pairs ) );
$found = $repo->find( 5 );
check( is_array( $found ) && isset( $found['pairs'] ), "find() dokłada klucz 'pairs'" );
check( $pairs === ( $found['pairs'] ?? null ), "pairs rozkodowane z pairs_json (round-trip)" );
check( isset( $found['pairs_json'] ), "surowy pairs_json zostaje w wierszu" );

$wpdb->row = array( 'id' => 6, 'topic' => 'Y', 'pairs_json' => 'to-nie-json' );
$bad = $repo->find( 6 );
check( is_array( $bad ) && array() === $bad['pairs'], "zepsuty pairs_json → pairs = [] (nie wybucha)" );

$wpdb->row = null;
check( null === $repo->find( 999 ), "brak wiersza → null" );

echo "\n=== E. delete()/count() dziedziczone ===\n";
$wpdb->deleted = 1;
check( true === $repo->delete( 5 ), "delete() zwraca true gdy usunięto" );
$wpdb->var = 42;
check( 42 === $repo->count(), "count() zwraca liczbę wierszy" );

echo "\n=== PODSUMOWANIE ===\n";
echo ( 0 === $fail ) ? "TEST KROK 11 (repo): WSZYSTKIE ASERCJE OK\n" : "TEST KROK 11 (repo): $fail ASERCJI NIE PRZESZŁO\n";
exit( $fail === 0 ? 0 : 1 );
