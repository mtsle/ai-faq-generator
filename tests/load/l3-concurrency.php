<?php
/**
 * L3 — Concurrency/bezpieczeństwo (Krok 23 etap 4).
 *
 * Orkiestrator: odpala PRAWDZIWE, RÓWNOLEGŁE procesy OS (proc_open, nie
 * symulacja w jednym procesie) racujące o WSPÓLNY stan (plik, przez
 * l3-shared-state-shim.php — patrz komentarz tam o wierności wobec
 * prawdziwego WordPressa bez trwałego cache obiektowego). Reprodukuje trzy
 * wzorce TOCTOU znalezione w eksploracji architektury oraz sprawdza izolację
 * gość A / gość B w cache odpowiedzi.
 *
 * Każdy scenariusz ma najpierw KONTROLĘ sekwencyjną (procesy jeden po drugim,
 * zero współbieżności) — dowód, że sam harness poprawnie zlicza, gdy race
 * nie może wystąpić — a potem właściwy przebieg RÓWNOLEGŁY.
 *
 * URUCHOMIENIE:  php tests/load/l3-concurrency.php
 * Wymaga PHP CLI (proc_open) — bez WordPressa, MySQL, sieci. Kod wyjścia
 * zawsze 0 (harness pomiarowy + demonstracja bezpieczeństwa) — wynik trzeba
 * przeczytać.
 *
 * @package AI_FAQ_Generator
 */

$php  = defined( 'PHP_BINARY' ) ? PHP_BINARY : 'php';
$dir  = __DIR__;
$state_file = sys_get_temp_dir() . '/aifaq_lt_state_' . getmypid() . '.json';
putenv( 'AIFAQ_LT_STATE_FILE=' . $state_file );

/** Odpala N procesów RÓWNOLEGLE (wszystkie startują ZANIM czekamy na którykolwiek), zbiera stdout. */
function run_parallel( string $php, string $script, array $arg_sets ): array {
	$handles = array();
	foreach ( $arg_sets as $args ) {
		$cmd = escapeshellarg( $php ) . ' ' . escapeshellarg( $script );
		foreach ( $args as $a ) {
			$cmd .= ' ' . escapeshellarg( (string) $a );
		}
		$desc = array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) );
		$proc = proc_open( $cmd, $desc, $pipes );
		$handles[] = array( 'proc' => $proc, 'pipes' => $pipes );
	}
	$out = array();
	foreach ( $handles as $h ) {
		$out[] = trim( stream_get_contents( $h['pipes'][1] ) );
		fclose( $h['pipes'][1] );
		fclose( $h['pipes'][2] );
		proc_close( $h['proc'] );
	}
	return $out;
}

function reset_state( string $state_file ): void {
	@unlink( $state_file );
	file_put_contents( $state_file, '{}' );
	@unlink( $state_file . '.opt_lock' );
}

echo "=== L3 — Concurrency / bezpieczeństwo ===\n";
echo "(stan współdzielony: $state_file — plik tymczasowy, sprzątany na końcu)\n\n";

// ---------------------------------------------------------------------
echo "--- A. Lock reindeksu (IndexController.php:132-139, get_transient->set_transient) ---\n";
$lock_script = $dir . '/l3-scenario-lock.php';

// Kontrola: uruchamiamy PO JEDNYM, czekając na zakończenie przed startem następnego.
reset_state( $state_file );
$seq_results = array();
for ( $i = 0; $i < 5; $i++ ) {
	$seq_results[] = run_parallel( $php, $lock_script, array( array() ) )[0];
}
$seq_acquired = count( array_filter( $seq_results, static fn( $r ) => 'acquired' === $r ) );
printf( "  Kontrola sekwencyjna (5x, jeden po drugim): acquired=%d/5 (oczekiwane dokładnie 1)\n", $seq_acquired );

reset_state( $state_file );
$n_concurrent = 10;
$par_results = run_parallel( $php, $lock_script, array_fill( 0, $n_concurrent, array() ) );
$par_acquired = count( array_filter( $par_results, static fn( $r ) => 'acquired' === $r ) );
printf(
	"  BEFORE — przebieg równoległy (%d procesów naraz): acquired=%d/%d -> %s\n",
	$n_concurrent, $par_acquired, $n_concurrent,
	$par_acquired > 1 ? 'WYŚCIG POTWIERDZONY (' . $par_acquired . ' procesów uznało, że zdobyło lock jednocześnie)' : 'brak wyścigu w tym przebiegu (bezpieczne w tej próbie)'
);

// AFTER: ta sama próba, ale mechanizmem PO poprawce (add_option() atomowy,
// wzorzec 1:1 z IndexController::acquire_lock() po tym etapie).
reset_state( $state_file );
$after_script = $dir . '/l3-scenario-lock-after.php';
$after_results = run_parallel( $php, $after_script, array_fill( 0, $n_concurrent, array() ) );
$after_acquired = count( array_filter( $after_results, static fn( $r ) => 'acquired' === $r ) );
printf(
	"  AFTER  — przebieg równoległy (%d procesów naraz, add_option atomowy): acquired=%d/%d -> %s\n",
	$n_concurrent, $after_acquired, $n_concurrent,
	1 === $after_acquired ? 'POPRAWKA POTWIERDZONA (dokładnie 1 proces zdobywa lock)' : 'UWAGA: nadal >1 (' . $after_acquired . ')'
);

// ---------------------------------------------------------------------
echo "\n--- B. Sufit dobowy budżetu (RagService.php:693-694,742-752, check-then-hit) ---\n";
$budget_script = $dir . '/l3-scenario-budget.php';
$limit = 10;

reset_state( $state_file );
$seq_results = array();
for ( $i = 0; $i < $limit + 5; $i++ ) {
	$seq_results[] = run_parallel( $php, $budget_script, array( array( $limit ) ) )[0];
}
$seq_accepted = count( array_filter( $seq_results, static fn( $r ) => 'accepted' === $r ) );
printf( "  Kontrola sekwencyjna (limit=%d, %d wywołań jeden po drugim): accepted=%d (oczekiwane dokładnie %d)\n", $limit, $limit + 5, $seq_accepted, $limit );

foreach ( array( 15, 25, 50 ) as $n_concurrent ) {
	reset_state( $state_file );
	$par_results = run_parallel( $php, $budget_script, array_fill( 0, $n_concurrent, array( $limit ) ) );
	$par_accepted = count( array_filter( $par_results, static fn( $r ) => 'accepted' === $r ) );
	printf(
		"  Przebieg równoległy (limit=%d, %d procesów naraz): accepted=%d -> %s\n",
		$limit, $n_concurrent, $par_accepted,
		$par_accepted > $limit ? "PRZEKROCZENIE SUFITU o " . ( $par_accepted - $limit ) . ' (potwierdza nieatomowość opisaną w kodzie)' : 'sufit dotrzymany w tej próbie'
	);
}

// ---------------------------------------------------------------------
echo "\n--- C. PublicFaq::publish() last-write-wins (PublicFaq.php:60-112) ---\n";
$publish_script = $dir . '/l3-scenario-publish.php';

reset_state( $state_file );
file_put_contents( $state_file, json_encode( array( 'faq' => 'payload-from-ORIGINAL' ) ) );
$n_publishers = 5;
$args_sets = array();
for ( $i = 1; $i <= $n_publishers; $i++ ) {
	$args_sets[] = array( 'P' . $i );
}
$pub_results = run_parallel( $php, $publish_script, $args_sets );
$final_state = json_decode( (string) file_get_contents( $state_file ), true );
printf( "  %d równoległych publikacji: %s\n", $n_publishers, implode( ', ', $pub_results ) );
printf( "  Stan końcowy: faq=%s, faq_prev=%s\n", $final_state['faq'] ?? '(brak)', $final_state['faq_prev'] ?? '(brak)' );
$final_id   = str_replace( 'payload-from-', '', (string) ( $final_state['faq'] ?? '' ) );
$prev_id    = str_replace( 'payload-from-', '', (string) ( $final_state['faq_prev'] ?? '' ) );
$lost_ids   = array_diff( array_map( static fn( $i ) => 'P' . $i, range( 1, $n_publishers ) ), array( $final_id, $prev_id, 'ORIGINAL' ) );
if ( count( $lost_ids ) > 0 ) {
	printf(
		"  -> UTRATA DANYCH: publikacje %s nie są odzyskiwalne ani z 'faq', ani z 'faq_prev' -> ZNIKNĘŁY BEZ ŚLADU (potwierdza last-write-wins bez blokady)\n",
		implode( ', ', $lost_ids )
	);
} else {
	echo "  -> brak utraconych publikacji w tej próbie (ostatnia i przedostatnia obecne)\n";
}

// ---------------------------------------------------------------------
echo "\n--- D. Izolacja gość A / gość B w cache odpowiedzi (CacheRepository.php) ---\n";
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
if ( ! function_exists( 'current_time' ) ) { function current_time( $t ) { return '2026-07-09 00:00:00'; } }

class FakeCacheWpdb {
	public $prefix = 'wp_';
	public $rows = array();
	public $insert_id = 0;
	private $auto = 0;
	public function prepare( $sql, ...$args ) {
		$out = $sql;
		foreach ( $args as $a ) {
			$repl = is_string( $a ) ? "'" . addslashes( $a ) . "'" : $a;
			$out  = preg_replace( '/%[sfd]/', (string) $repl, $out, 1 );
		}
		return $out;
	}
	public function query( $sql ) {
		if ( preg_match( "/question_hash, question, answer, score, hits, created_at\\)\\s*VALUES\\s*\\('([a-f0-9]+)', '(.*?)', '(.*?)', ([\\d.]+),/s", $sql, $m ) ) {
			foreach ( $this->rows as &$r ) {
				if ( $r['question_hash'] === $m[1] ) {
					$r['answer'] = stripslashes( $m[3] );
					$this->insert_id = $r['id'];
					return 1;
				}
			}
			unset( $r );
			$this->rows[] = array( 'id' => ++$this->auto, 'question_hash' => $m[1], 'question' => stripslashes( $m[2] ), 'answer' => stripslashes( $m[3] ), 'score' => (float) $m[4] );
			$this->insert_id = $this->auto;
			return 1;
		}
		return 0;
	}
	public function get_row( $sql, $o = null ) {
		if ( preg_match( "/question_hash = '([a-f0-9]+)'/", $sql, $m ) ) {
			foreach ( $this->rows as $r ) {
				if ( $r['question_hash'] === $m[1] ) { return $r; }
			}
		}
		return null;
	}
}

global $wpdb;
$wpdb = new FakeCacheWpdb();

require $dir . '/../../src/Data/Schema.php';
require $dir . '/../../src/Data/Repository.php';
require $dir . '/../../src/Data/CacheRepository.php';
$cache = new AIFAQ\Data\CacheRepository();

// Symulacja PRZEPLOTU: gość A i gość B zadają RÓŻNE pytania, operacje
// zapisu/odczytu celowo poprzeplatane (jak dwa równoległe requesty PHP-FPM
// obsługiwane w tej samej milisekundzie na osobnych workerach, ale
// serializowane przez wspólną bazę).
$cache->put( 'Jakie są godziny otwarcia?', 'Odpowiedź dla gościa A: 9-17.', 0.9 );
$cache->put( 'Ile kosztuje pokój deluxe?', 'Odpowiedź dla gościa B: 450 zł.', 0.85 );
$a = $cache->get_by_question( 'Jakie są godziny otwarcia?' );
$b = $cache->get_by_question( 'Ile kosztuje pokój deluxe?' );
$isolated = ( $a['answer'] === 'Odpowiedź dla gościa A: 9-17.' && $b['answer'] === 'Odpowiedź dla gościa B: 450 zł.' );
printf(
	"  Gość A dostaje: \"%s\"\n  Gość B dostaje: \"%s\"\n  -> %s\n",
	$a['answer'] ?? '(brak)', $b['answer'] ?? '(brak)',
	$isolated ? 'OK — cache kluczowany wyłącznie hashem PYTANIA, zero mieszania między gośćmi' : 'FAIL — wykryto mieszanie kontekstu!'
);

@unlink( $state_file );
echo "\nKONIEC L3.\n";
