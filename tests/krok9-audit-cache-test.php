<?php
/**
 * Regresja audytu Kroku 9 — unieważnianie cache (FIX WYSOKI).
 *
 * Pilnuje, by cache odpowiedzi znikał razem z bazą wiedzy (inaczej `/ask` serwuje
 * stare odpowiedzi z pominięciem retrievera i bramki tematu):
 *  - CacheRepository::clear_all(): TRUNCATE tabeli cache + zwrot liczby wierszy.
 *  - IndexController::run_clear(): czyści wiedzę (DELETE) ORAZ cache (TRUNCATE).
 *  - IndexController::run_reindex(): strażnik źródłowy — wywołuje CacheRepository::clear_all().
 *
 * URUCHOMIENIE:  php tests/krok9-audit-cache-test.php
 * Kod wyjścia: 0 = OK, 1 = błędy.
 *
 * @package AI_FAQ_Generator
 */

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }

if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'get_transient' ) ) { function get_transient( $k ) { return false; } }
if ( ! function_exists( 'set_transient' ) ) { function set_transient( $k, $v, $t = 0 ) { return true; } }
if ( ! function_exists( 'delete_transient' ) ) { function delete_transient( $k ) { return true; } }
if ( ! function_exists( 'register_shutdown_function_shim' ) ) { /* placeholder */ }

// Spy $wpdb — rejestruje wszystkie zapytania.
class SpyWpdb {
	public $prefix = 'wp_';
	public $queries = array();
	public function query( $sql ) { $this->queries[] = $sql; return 1; }
	public function get_var( $sql ) { $this->queries[] = $sql; return 5; }
	public function get_row( $sql, $o = null ) { $this->queries[] = $sql; return array( 'chunks' => 0, 'posts' => 0, 'embedded' => 0 ); }
	public function prepare( $q, ...$a ) { return $q; }
	public function get_results( $q, $o = null ) { return array(); }
	public function found( $needle ) {
		foreach ( $this->queries as $q ) { if ( false !== stripos( $q, $needle ) ) { return true; } }
		return false;
	}
}
$GLOBALS['wpdb'] = new SpyWpdb();

$fail = 0;
function check( $cond, $label ) { global $fail; echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n"; if ( ! $cond ) { $fail++; } }

require __DIR__ . '/../src/Data/Schema.php';
require __DIR__ . '/../src/Data/Repository.php';
require __DIR__ . '/../src/Data/KnowledgeRepository.php';
require __DIR__ . '/../src/Data/CacheRepository.php';
require __DIR__ . '/../src/Admin/IndexController.php';

use AIFAQ\Data\CacheRepository;
use AIFAQ\Admin\IndexController;

echo "== CacheRepository::clear_all() ==\n";
$GLOBALS['wpdb']->queries = array();
$removed = ( new CacheRepository() )->clear_all();
check( 5 === $removed, 'clear_all() zwraca liczbę wierszy (5)' );
check( $GLOBALS['wpdb']->found( 'TRUNCATE TABLE wp_aifaq_cache' ), 'clear_all() robi TRUNCATE na tabeli cache' );

echo "\n== IndexController::run_clear() — czyści wiedzę I cache ==\n";
$GLOBALS['wpdb']->queries = array();
$res = ( new IndexController() )->run_clear();
check( true === ( $res['ok'] ?? false ), 'run_clear ok' );
check( $GLOBALS['wpdb']->found( 'FROM wp_aifaq_knowledge' ), 'run_clear czyści bazę wiedzy' );
check( $GLOBALS['wpdb']->found( 'TRUNCATE TABLE wp_aifaq_cache' ), 'FIX: run_clear TRUNCATE cache (nie serwuje starych odpowiedzi)' );

echo "\n== IndexController::run_reindex() — strażnik źródłowy ==\n";
$src = (string) file_get_contents( __DIR__ . '/../src/Admin/IndexController.php' );
// Wytnij ciało run_reindex do najbliższego run_clear, by sprawdzić że to reindeks woła cache.
$from = strpos( $src, 'function run_reindex' );
$to   = strpos( $src, 'function run_clear' );
$reindex_body = ( false !== $from && false !== $to && $to > $from ) ? substr( $src, $from, $to - $from ) : '';
check( '' !== $reindex_body && false !== strpos( $reindex_body, 'CacheRepository' ) && false !== strpos( $reindex_body, 'clear_all' ), 'run_reindex woła CacheRepository::clear_all (po zmianie treści)' );

echo "\n=== " . ( 0 === $fail ? 'WSZYSTKIE OK' : "BŁĘDÓW: {$fail}" ) . " ===\n";
exit( $fail > 0 ? 1 : 0 );
