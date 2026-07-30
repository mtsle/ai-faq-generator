<?php
/**
 * L6 — Generator FAQ współbieżność (Krok 23 etap 4).
 *
 * DWIE części:
 *
 * A) Odniesienie do L3 — NIE duplikacja: `GeneratorService::generate()`
 *    (src/Rest/GeneratorService.php:56,65) woła te SAME statyczne metody
 *    `RagService::site_budget_allows()`/`site_budget_hit()`, których wyścig
 *    (check-then-hit) L3 już DOWIODŁA na prawdziwych równoległych procesach
 *    (sekcja B: np. limit=10, 25 równoległych -> accepted=25). `PublishService::
 *    publish()`/`unpublish()` (src/Rest/PublishService.php:118,146) wołają
 *    TĘ SAMĄ `PublicFaq::publish()`/`unpublish()`, której last-write-wins L3
 *    już DOWIODŁA (sekcja C: 3/5 publikacji znika bez śladu). To są te same
 *    prymitywy, nie osobna hipoteza — nie ma sensu retestować identycznego
 *    kodu drugi raz pod inną nazwą.
 *
 * B) To, czego L3 NIE sprawdziła: równoczesny ZAPIS HISTORII generowań
 *    (`GenerationRepository::log()`, realny INSERT z AUTO_INCREMENT) —
 *    czy wiele procesów zapisujących jednocześnie coś traci/koliduje.
 *    Na PRAWDZIWYM MySQL (izolowana tabela wp_loadtest_aifaq_generations,
 *    jak L5), realne równoległe procesy OS (jak L3/L4).
 *
 * URUCHOMIENIE:  php tests/load/l6-generator-concurrency.php
 * WYMAGA uruchomionego Local (site ai-faq-dev) dla części B. Kod wyjścia
 * zawsze 0 (harness pomiarowy) — wynik trzeba przeczytać.
 *
 * @package AI_FAQ_Generator
 */

echo "=== L6 — Generator FAQ współbieżność ===\n\n";

echo "--- A. Wyścigi generatora = te same prymitywy co L3 (nie retestowane drugi raz) ---\n";
echo "  GeneratorService.php:56  RagService::site_budget_allows()  <- L3-B (sekcja \"Sufit dobowy budżetu\")\n";
echo "  GeneratorService.php:65  RagService::site_budget_hit()     <- L3-B: limit=10, 25 równoległych -> accepted=25 (przekroczenie o 15)\n";
echo "  PublishService.php:118   PublicFaq::publish()               <- L3-C (sekcja \"PublicFaq last-write-wins\")\n";
echo "  PublishService.php:146   PublicFaq::unpublish()              <- L3-C: 3/5 równoległych publikacji zniknęło bez śladu\n";
echo "  Wniosek: obie ścieżki generatora dziedziczą DOKŁADNIE te same, już zmierzone luki — nie ma tu nowej hipotezy do sprawdzenia.\n\n";

echo "--- B. Realny równoległy zapis historii (GenerationRepository::log(), realny MySQL, AUTO_INCREMENT) ---\n";

$php       = defined( 'PHP_BINARY' ) ? PHP_BINARY : 'php';
$ext_dir   = ini_get( 'extension_dir' );
// Dzieci (osobne procesy php.exe) dostają PUSTY php.ini domyślnie (GOTCHA środowiska —
// patrz STAN-PROJEKTU2.md) — mysqli trzeba doładować jawnie, inaczej cichy fatal.
$php_flags = $ext_dir ? ( ' -d extension_dir=' . escapeshellarg( $ext_dir ) . ' -d extension=mysqli' ) : '';
$worker    = __DIR__ . '/l6-history-write-worker.php';

$mysqli = @mysqli_connect( '127.0.0.1', 'root', 'root', 'local', 10011 );
if ( ! $mysqli ) {
	echo "  BRAK POŁĄCZENIA z MySQL Local — pomijam część B (wymaga uruchomionego Local).\n";
	echo "\nKONIEC L6.\n";
	exit( 0 );
}
$T = 'wp_loadtest_aifaq_generations';
$mysqli->query( "DROP TABLE IF EXISTS {$T}" );
$mysqli->query( "CREATE TABLE {$T} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	created_at datetime NOT NULL,
	topic text NOT NULL,
	extra_desc longtext NULL,
	num_questions smallint(5) unsigned NOT NULL DEFAULT 0,
	language varchar(10) NOT NULL DEFAULT 'pl',
	user_id bigint(20) unsigned NOT NULL DEFAULT 0,
	pairs_json longtext NULL,
	PRIMARY KEY (id),
	KEY created_at (created_at),
	KEY created_id (created_at,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4" );

$n = 30;
$handles = array();
for ( $i = 1; $i <= $n; $i++ ) {
	$cmd  = escapeshellarg( $php ) . $php_flags . ' ' . escapeshellarg( $worker ) . ' ' . escapeshellarg( (string) $i );
	$desc = array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) );
	$proc = proc_open( $cmd, $desc, $pipes );
	$handles[] = array( 'proc' => $proc, 'pipes' => $pipes );
}
$results = array();
foreach ( $handles as $h ) {
	$results[] = trim( stream_get_contents( $h['pipes'][1] ) );
	fclose( $h['pipes'][1] ); fclose( $h['pipes'][2] );
	proc_close( $h['proc'] );
}

$res = $mysqli->query( "SELECT COUNT(*) c, COUNT(DISTINCT id) u, COUNT(DISTINCT topic) t FROM {$T}" );
$row = $res->fetch_assoc();
printf(
	"  %d procesów, każdy 1 INSERT: wiersze=%d, unikalne id=%d, unikalne tematy=%d -> %s\n",
	$n, $row['c'], $row['u'], $row['t'],
	( (int) $row['c'] === $n && (int) $row['u'] === $n && (int) $row['t'] === $n )
		? 'OK — zero utraconych/skolidowanych zapisów (AUTO_INCREMENT bezpieczny pod współbieżnością)'
		: 'UWAGA — liczby się nie zgadzają, sprawdzić ręcznie'
);

$mysqli->query( "DROP TABLE IF EXISTS {$T}" );
$mysqli->close();
echo "  (tabela wp_loadtest_aifaq_generations usunięta)\n";

echo "\nKONIEC L6.\n";
