<?php
/**
 * L1 — Retrieval @ skala (Krok 23 etap 4).
 *
 * Mierzy Retriever::retrieve() przy rosnącej liczbie zaindeksowanych
 * fragmentów (N), BEZ sieci i BEZ prawdziwej bazy — atrapa
 * KnowledgeRepository generuje syntetyczne wektory 768D w locie, page po
 * page, dokładnie tak jak embeddings_page() w produkcji (ta sama sygnatura,
 * ten sam kształt zwracanych wierszy).
 *
 * Cel: potwierdzić lub obalić hipotezę z eksploracji architektury —
 * Retriever.php:58-101 iteruje CAŁĄ bazę stronicowo i sortuje CAŁY wynik
 * (usort) na KAŻDE wywołanie, więc koszt powinien rosnąć ~liniowo z N.
 * Mierzymy nie tylko czas per N, ale WZROST czasu względem N (czas/N przy
 * każdym poziomie) — żeby to faktycznie zweryfikować pomiarem, nie zgadywać.
 *
 * URUCHOMIENIE:  php tests/load/l1-retrieval-scale.php
 * Nie wymaga WordPressa, MySQL ani sieci. Kod wyjścia zawsze 0 (to harness
 * pomiarowy, nie test PASS/FAIL) — wynik trzeba przeczytać.
 *
 * @package AI_FAQ_Generator
 */

// --- shimy WP ---
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }

require __DIR__ . '/../../src/Data/Schema.php';
require __DIR__ . '/../../src/Data/Repository.php';
require __DIR__ . '/../../src/Data/KnowledgeRepository.php';
require __DIR__ . '/../../src/Rag/Retriever.php';

use AIFAQ\Data\KnowledgeRepository;
use AIFAQ\Rag\Retriever;

const EMB_DIM = 768;

/**
 * Atrapa repo: N syntetycznych wierszy z wektorem 768D, serwowanych
 * stronicowo dokładnie jak produkcyjny embeddings_page(). Liczy realne
 * wywołania "SQL" (page_calls) jak w produkcji (N/PAGE_SIZE zapytań/request).
 */
class ScaleKnowledgeRepository extends KnowledgeRepository {
	public int $total = 0;
	public int $page_calls = 0;

	public function count_embedded(): int {
		return $this->total;
	}

	public function embeddings_page( int $limit, int $offset ): array {
		$this->page_calls++;
		if ( $offset >= $this->total ) {
			return array();
		}
		$rows = array();
		$end  = min( $offset + $limit, $this->total );
		for ( $i = $offset; $i < $end; $i++ ) {
			$rows[] = array(
				'id'        => $i + 1,
				'post_id'   => intdiv( $i, 4 ) + 1,
				'content'   => 'fragment syntetyczny ' . $i,
				'embedding' => synth_vector( $i ),
			);
		}
		return $rows;
	}
}

/** Deterministyczny syntetyczny wektor 768D (bez losowości między uruchomieniami). */
function synth_vector( int $seed ): array {
	$v = array();
	for ( $d = 0; $d < EMB_DIM; $d++ ) {
		// Funkcja okresowa różna per wymiar i per wiersz — nietrywialne podobieństwa.
		$v[] = sin( ( $seed + 1 ) * 0.017 + $d * 0.031 );
	}
	return $v;
}

echo "=== L1 — Retrieval @ skala ===\n\n";
printf( "%-10s %10s %12s %14s %10s\n", 'N', 'page_calls', 'czas (ms)', 'czas/N (µs)', 'pamięć MB' );

$levels  = array( 200, 1000, 5000, 20000, 50000 );
$results = array();

foreach ( $levels as $n ) {
	$repo  = new ScaleKnowledgeRepository();
	$repo->total = $n;
	$retriever = new Retriever( $repo );
	$query     = synth_vector( -1 );

	$mem_before = memory_get_usage();
	$t0         = microtime( true );
	$hits       = $retriever->retrieve( $query, 5 );
	$t1         = microtime( true );
	$mem_peak   = memory_get_peak_usage();

	$ms       = ( $t1 - $t0 ) * 1000;
	$us_per_n = ( $t1 - $t0 ) * 1_000_000 / $n;

	$results[] = array(
		'n'         => $n,
		'ms'        => $ms,
		'us_per_n'  => $us_per_n,
		'page_calls'=> $repo->page_calls,
		'mem_mb'    => $mem_peak / 1024 / 1024,
	);

	printf(
		"%-10d %10d %12.2f %14.3f %10.1f\n",
		$n,
		$repo->page_calls,
		$ms,
		$us_per_n,
		$mem_peak / 1024 / 1024
	);

	if ( 5 !== count( $hits ) ) {
		echo "  UWAGA: oczekiwano 5 trafień top-K, dostano " . count( $hits ) . "\n";
	}
}

echo "\n=== Analiza skalowania (czas/N) ===\n";
$first = $results[0]['us_per_n'];
$last  = end( $results )['us_per_n'];
$ratio = $first > 0 ? $last / $first : 0;
printf( "czas/N przy N=%d: %.3f µs/rekord\n", $results[0]['n'], $first );
printf( "czas/N przy N=%d: %.3f µs/rekord\n", end( $results )['n'], $last );
printf( "stosunek (największe/najmniejsze N): %.2fx\n", $ratio );

if ( $ratio < 1.5 ) {
	echo "WNIOSEK: czas/N ~stały -> zachowanie O(N), zgodnie z analizą kodu (pełna iteracja + usort na każde zapytanie).\n";
} elseif ( $ratio < 3.0 ) {
	echo "WNIOSEK: czas/N rośnie umiarkowanie -> zgodne z O(N) plus narzut usort (O(N log N) na etapie sortowania).\n";
} else {
	echo "WNIOSEK: czas/N rośnie WYRAŹNIE ponad N -> gorsze niż liniowe, wymaga dalszej analizy (możliwy narzut PHP GC/realokacji tablic przy dużych N).\n";
}

echo "\nKONIEC L1.\n";
