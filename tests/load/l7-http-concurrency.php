<?php
/**
 * L7a — spike/stress REALNY na żywej stronie ai-faq-dev.local (Krok 23 etap 4).
 *
 * Wymaga: Local uruchomiony (site ai-faq-dev), oraz TYMCZASOWY mu-plugin
 * `wp-content/mu-plugins/aifaq-loadtest-mock-provider.php` podstawiający
 * mock providera (`ProviderFactory::set_override()`) — bez niego ten skrypt
 * biłby w PRAWDZIWE Gemini i płacił z dobowego limitu 20/dobę. Skrypt SAM
 * weryfikuje przy starcie (czas odpowiedzi < 500ms), że mock jest aktywny,
 * i przerywa, jeśli nie wykryje mocka (bezpiecznik przeciw przypadkowemu
 * zużyciu budżetu).
 *
 * Realne równoczesne żądania HTTP (curl_multi) na `/wp-json/aifaq/v1/ask`,
 * rosnąca współbieżność 1->2->4->8, PRZERYWA przy pierwszym błędzie/timeout —
 * nie pcha dalej na ślepo (GOTCHA środowiska: Local na Windows ma znany
 * deadlock loopback przy niskim `pm.max_children`, patrz STAN-PROJEKTU2.md).
 *
 * URUCHOMIENIE:  php tests/load/l7-http-concurrency.php
 * Kod wyjścia zawsze 0 (harness pomiarowy) — wynik trzeba przeczytać.
 *
 * @package AI_FAQ_Generator
 */

const URL = 'http://ai-faq-dev.local/wp-json/aifaq/v1/ask';

function fire_batch( int $n ): array {
	$mh      = curl_multi_init();
	$handles = array();
	for ( $i = 0; $i < $n; $i++ ) {
		$ch = curl_init( URL );
		curl_setopt_array(
			$ch,
			array(
				CURLOPT_POST           => true,
				CURLOPT_POSTFIELDS     => json_encode( array( 'question' => 'pytanie testowe L7 numer ' . $i . ' ' . uniqid() ) ),
				CURLOPT_HTTPHEADER     => array( 'Content-Type: application/json' ),
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => 15,
			)
		);
		curl_multi_add_handle( $mh, $ch );
		$handles[] = $ch;
	}
	$t0 = microtime( true );
	$running = null;
	do {
		curl_multi_exec( $mh, $running );
		curl_multi_select( $mh );
	} while ( $running > 0 );
	$batch_s = microtime( true ) - $t0;

	$results = array();
	foreach ( $handles as $ch ) {
		$results[] = array(
			'http'  => curl_getinfo( $ch, CURLINFO_HTTP_CODE ),
			'time'  => curl_getinfo( $ch, CURLINFO_TOTAL_TIME ) * 1000,
			'error' => curl_error( $ch ),
		);
		curl_multi_remove_handle( $mh, $ch );
		curl_close( $ch );
	}
	curl_multi_close( $mh );
	return array( 'batch_s' => $batch_s, 'results' => $results );
}

echo "=== L7a — Spike/stress REALNY na ai-faq-dev.local ===\n\n";

echo "--- Bezpiecznik: weryfikacja że mock providera jest aktywny (czas < 500ms = mock, > 500ms = PRAWDZIWE Gemini) ---\n";
$probe = fire_batch( 1 );
$probe_ms = $probe['results'][0]['time'];
printf( "  1 żądanie: %.0fms, HTTP %s\n", $probe_ms, $probe['results'][0]['http'] );
if ( $probe_ms > 500 ) {
	echo "  STOP: czas > 500ms sugeruje PRAWDZIWE wywołanie Gemini (mock nie aktywny lub padł).\n";
	echo "  Sprawdź wp-content/mu-plugins/aifaq-loadtest-mock-provider.php na ai-faq-dev — NIE kontynuuję (ochrona budżetu API).\n";
	exit( 0 );
}
echo "  OK — mock aktywny, zero kosztu API. Kontynuuję.\n\n";

echo "--- Rosnąca współbieżność (realny HTTP, curl_multi) ---\n";
printf( "%-8s %8s %8s %10s %10s %10s\n", 'concur', 'OK', 'errors', 'p50(ms)', 'max(ms)', 'batch(s)' );

$levels = array( 1, 2, 4, 8 );
foreach ( $levels as $n ) {
	$batch = fire_batch( $n );
	$times = array();
	$errors = 0;
	foreach ( $batch['results'] as $r ) {
		if ( 200 !== (int) $r['http'] || '' !== $r['error'] ) { ++$errors; continue; }
		$times[] = $r['time'];
	}
	sort( $times );
	$p50 = $times ? $times[ (int) floor( count( $times ) / 2 ) ] : 0;
	$max = $times ? max( $times ) : 0;
	printf( "%-8d %8d %8d %10.1f %10.1f %10.2f\n", $n, count( $times ), $errors, $p50, $max, $batch['batch_s'] );

	if ( $errors > 0 ) {
		printf( "  -> BŁĘDY przy współbieżności=%d (%d/%d) — PUNKT NASYCENIA na tym środowisku Local. Przerywam eskalację.\n", $n, $errors, $n );
		foreach ( $batch['results'] as $r ) {
			if ( '' !== $r['error'] ) { echo "     curl: {$r['error']}\n"; }
		}
		break;
	}
}

echo "\nUWAGA: to jest limit ŚRODOWISKA DEWELOPERSKIEGO Local na Windows (PHP-FPM/serwer\n";
echo "lokalny), NIE limit kodu wtyczki — patrz STAN-PROJEKTU2.md (GOTCHA pm.max_children).\n";
echo "Model dla wyższej współbieżności (typowej dla produkcji) — patrz L7b w raporcie.\n";
echo "\nKONIEC L7a.\n";
