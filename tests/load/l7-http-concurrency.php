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
$probe      = fire_batch( 1 );
$probe_ms   = $probe['results'][0]['time'];
$probe_http = (int) $probe['results'][0]['http'];
$probe_err  = (string) $probe['results'][0]['error'];
printf( "  1 żądanie: %.0fms, HTTP %s\n", $probe_ms, $probe_http );

/*
 * RAU-R15-005. Bezpiecznik rozstrzygal WYLACZNIE po czasie odpowiedzi sondy.
 * Skutek: gdy mu-plugin z atrapa dostawcy nie jest wgrany albo padl, a witryna
 * odpowiada SZYBKO kodem 401/429/500 (albo curl konczy sie bledem przed 500 ms),
 * skrypt meldowal „mock aktywny, zero kosztu API" i wysylal kolejnych 15 zadan
 * na trase bijaca w PRAWDZIWE Gemini. Limit chroniacy dobowa pule nie chronil
 * jej dokladnie w scenariuszu, dla ktorego powstal.
 *
 * Dane byly w miejscu decyzji dostepne: `fire_batch()` zwraca `http`, `time`
 * i `error` — czytany byl tylko `time`. Teraz sonda musi spelnic WSZYSTKIE trzy
 * warunki, a kazdy niespelniony jest wymieniony z nazwy w komunikacie.
 *
 * Kod wyjscia: 1, nie 0. Przerwanie z ochrony budzetu NIE jest sukcesem i musi
 * dac sie odroznic od przebiegu, ktory doszedl do konca — takze dla powloki.
 */
$powody = array();
if ( $probe_ms > 500 ) {
	$powody[] = sprintf( 'czas %.0f ms > 500 ms (sugeruje PRAWDZIWE wywolanie Gemini)', $probe_ms );
}
if ( 200 !== $probe_http ) {
	$powody[] = sprintf( 'HTTP %d zamiast 200 (szybka odpowiedz BLEDNA nie jest dowodem na atrape)', $probe_http );
}
if ( '' !== $probe_err ) {
	$powody[] = 'blad curla: ' . $probe_err;
}

if ( $powody ) {
	echo "  STOP: sonda nie potwierdza atrapy dostawcy.\n";
	foreach ( $powody as $powod ) {
		echo "    - {$powod}\n";
	}
	echo "  Sprawdź wp-content/mu-plugins/aifaq-loadtest-mock-provider.php na ai-faq-dev — NIE kontynuuję (ochrona budżetu API).\n";
	exit( 1 );
}
echo "  OK — mock aktywny (HTTP 200, bez bledu curla, ponizej 500 ms), zero kosztu API. Kontynuuję.\n\n";

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
