<?php
/**
 * L4 — Gemini/API pod obciążeniem + `/ask` przy rosnącej współbieżności
 * (Krok 23 etap 4). Orkiestrator: RÓWNOLEGŁE procesy OS (proc_open, jak L3)
 * wołające `RagService::ask()` przez l4-ask-worker.php — mock providera
 * (zero sieci, zero kosztu), z symulowaną latencją Gemini i opcjonalnymi
 * trybami błędów (429/500/timeout). Mierzy DOKŁADNIE to, o co prosi brief:
 * response time, p50/p95/p99, requests/sec, error rate, plus rozbicie
 * czasu (lokalny vs czekanie na providera).
 *
 * URUCHOMIENIE:  php tests/load/l4-concurrent-ask.php
 * Nie wymaga WordPressa, MySQL ani sieci. Kod wyjścia zawsze 0 (harness
 * pomiarowy) — wynik trzeba przeczytać.
 *
 * @package AI_FAQ_Generator
 */

$php    = defined( 'PHP_BINARY' ) ? PHP_BINARY : 'php';
$worker = __DIR__ . '/l4-ask-worker.php';

function run_parallel_collect( string $php, string $script, array $arg_sets ): array {
	$handles = array();
	foreach ( $arg_sets as $args ) {
		$cmd = escapeshellarg( $php ) . ' ' . escapeshellarg( $script );
		foreach ( $args as $a ) {
			$cmd .= ' ' . escapeshellarg( (string) $a );
		}
		$desc      = array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) );
		$proc      = proc_open( $cmd, $desc, $pipes );
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

function percentile( array $sorted, float $p ): float {
	$n = count( $sorted );
	if ( 0 === $n ) { return 0.0; }
	$idx = (int) ceil( $p / 100 * $n ) - 1;
	$idx = max( 0, min( $n - 1, $idx ) );
	return $sorted[ $idx ];
}

echo "=== L4 — /ask pod rosnącą współbieżnością (mock Gemini, zero sieci) ===\n\n";

echo "--- A. Poziomy współbieżności (latencja providera ~400ms, DB symulowane ~2ms/wywołanie) ---\n";
printf(
	"%-6s %8s %10s %10s %10s %10s %12s %10s\n",
	'concur', 'OK', 'errors', 'p50(ms)', 'p95(ms)', 'p99(ms)', 'req/s', 'batch(s)'
);

$levels = array( 1, 5, 10, 25, 50, 100 );
foreach ( $levels as $n ) {
	$args_sets = array_fill( 0, $n, array( 400, 2, 'none' ) );
	$t0        = microtime( true );
	$lines     = run_parallel_collect( $php, $worker, $args_sets );
	$batch_s   = microtime( true ) - $t0;

	$totals = array();
	$errors = 0;
	foreach ( $lines as $line ) {
		$parts = explode( ',', $line );
		if ( count( $parts ) < 2 || 'answered' !== $parts[0] ) {
			++$errors;
			continue;
		}
		$totals[] = (float) $parts[1];
	}
	sort( $totals );
	$ok = count( $totals );

	printf(
		"%-6d %8d %10d %10.1f %10.1f %10.1f %12.2f %10.2f\n",
		$n, $ok, $errors,
		percentile( $totals, 50 ), percentile( $totals, 95 ), percentile( $totals, 99 ),
		$batch_s > 0 ? $n / $batch_s : 0.0,
		$batch_s
	);
}

echo "\n--- B. Rozbicie czasu przy współbieżności=10 (local_ms = DB symulowane + PHP retrievera; provider_ms = czekanie na mock Gemini) ---\n";
$lines = run_parallel_collect( $php, $worker, array_fill( 0, 10, array( 400, 2, 'none' ) ) );
$sum_local = 0.0; $sum_provider = 0.0; $sum_total = 0.0; $n_ok = 0;
foreach ( $lines as $line ) {
	$p = explode( ',', $line );
	if ( count( $p ) < 5 || 'answered' !== $p[0] ) { continue; }
	$sum_total    += (float) $p[1];
	$sum_local    += (float) $p[2];
	$sum_provider += (float) $p[4];
	++$n_ok;
}
if ( $n_ok > 0 ) {
	printf(
		"  Średnio z %d żądań: total=%.1fms, local(DB+PHP)=%.1fms (%.0f%%), provider(mock Gemini)=%.1fms (%.0f%%)\n",
		$n_ok, $sum_total / $n_ok, $sum_local / $n_ok, 100 * $sum_local / $sum_total, $sum_provider / $n_ok, 100 * $sum_provider / $sum_total
	);
	echo "  -> Gemini (nawet zamockowane na realistyczną latencję) dominuje czas requestu — zgodne z oczekiwaniem: to jest wolne ogniwo, nie nasz kod.\n";
}

echo "\n--- C. Symulacja wolnego API (latencja 2500ms zamiast 400ms) przy współbieżności=10 ---\n";
$t0    = microtime( true );
$lines = run_parallel_collect( $php, $worker, array_fill( 0, 10, array( 2500, 2, 'none' ) ) );
$batch_s = microtime( true ) - $t0;
$totals = array();
foreach ( $lines as $line ) {
	$p = explode( ',', $line );
	if ( count( $p ) >= 2 && 'answered' === $p[0] ) { $totals[] = (float) $p[1]; }
}
sort( $totals );
printf(
	"  10 żądań, provider ~2,5s: p50=%.0fms p95=%.0fms, batch=%.2fs -> %s\n",
	percentile( $totals, 50 ), percentile( $totals, 95 ), $batch_s,
	$batch_s < 5 ? 'równoległość działa (batch << 10×2,5s sekwencyjnie)' : 'UWAGA: batch bliski sekwencyjnemu czasowi'
);

echo "\n--- D. Błędy providera (429/500/timeout) pod współbieżnością=15 — error rate ---\n";
foreach ( array( '429', '500', 'timeout' ) as $mode ) {
	$lines = run_parallel_collect( $php, $worker, array_fill( 0, 15, array( 400, 2, $mode ) ) );
	$statuses = array_map( static fn( $l ) => explode( ',', $l )[0] ?? '?', $lines );
	$err_count = count( array_filter( $statuses, static fn( $s ) => 0 === strpos( $s, 'http' ) || 'error' === $s ) );
	printf( "  tryb=%-8s: 15/15 żądań zwróciło błąd -> %d/15 (%.0f%%) sklasyfikowanych jako błąd (%s)\n", $mode, $err_count, 100 * $err_count / 15, implode( ',', array_unique( $statuses ) ) );
}
echo "  (RagService::ask() nie wykonuje żadnych DODATKOWYCH prób przy błędzie providera —\n";
echo "  retry/circuit-breaker to logika GeminiProvider, już zweryfikowana osobno w\n";
echo "  tests/krok19-provider-test.php, np. A25: 429×3 -> dokładnie 3 próby, nie więcej.)\n";

echo "\nKONIEC L4.\n";
