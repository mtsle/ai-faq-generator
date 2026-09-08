<?php
/**
 * Krok 23 etap 3, segment S5 (persystencja) — łatanie luki z Fazy A: `Migrator`
 * (migracja jednorazowa `wp_aifaq_history` → `wp_aifaq_qa_log`, schema v1→v2) miała
 * ZERO testów w całym repo — ani jednej asercji celującej w `Migrator::run()`.
 * `krok19-migracja-test.php` (mimo nazwy) testuje CAŁKIEM INNĄ migrację (podpis
 * przestrzeni embeddingów przy reindeksie), nie tę klasę.
 *
 * Dodatkowo: `FaqRepository` (`src/Data/FaqRepository.php`) sprawdzony grepem —
 * NIE jest wołany z żadnego miejsca w `src/` ani `tests/`. To martwy kod (FAQ trafia
 * do opcji `aifaq_public_faq` przez `PublicFaq`, nie do tabeli `wp_aifaq_faq` przez
 * to repozytorium) — udokumentowane w raporcie końcowym, świadomie BEZ testów ani
 * usuwania (poza zakresem tego etapu, decyzja usera potrzebna do usunięcia kodu
 * produkcyjnego).
 *
 * Pokrywa:
 *  A. Flaga `aifaq_history_migrated` już ustawiona → no-op całkowity (zero zapytań
 *     do bazy, w tym zero `SHOW TABLES`).
 *  B. Świeża instalacja (stara tabela NIE istnieje) → flaga ustawiona, zero INSERT-ów.
 *  C. Stara tabela istnieje z wierszami → każdy wiersz trafia do qa_log z poprawnym
 *     mapowaniem (topic→question, answer=null, status=answered, source=ai, score=0,
 *     user_id zachowany), flaga ustawiona PO migracji.
 *  D. Pusty `created_at` w starym wierszu → fallback na `current_time('mysql')`.
 *  E. Idempotencja: drugie wywołanie `run()` PO migracji nie odpytuje bazy w ogóle.
 *
 * URUCHOMIENIE:  php tests/krok23-etap3-s5-migrator-test.php
 * Kod wyjścia: 0 = OK, 1 = błędy.
 *
 * @package AI_FAQ_Generator
 */

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }

$GLOBALS['__opt'] = array();
if ( ! function_exists( 'get_option' ) ) { function get_option( $k, $d = false ) { return $GLOBALS['__opt'][ $k ] ?? $d; } }
if ( ! function_exists( 'update_option' ) ) { function update_option( $k, $v, $autoload = null ) { $GLOBALS['__opt'][ $k ] = $v; return true; } }
if ( ! function_exists( 'current_time' ) ) { function current_time( $t ) { return '2026-07-28 12:00:00'; } }

require __DIR__ . '/../src/Data/Schema.php';
require __DIR__ . '/../src/Data/Migrator.php';

use AIFAQ\Data\Migrator;
use AIFAQ\Data\Schema;

/**
 * Atrapa $wpdb — tylko metody, których dotyka Migrator.
 * `$old_table_exists` steruje wynikiem `SHOW TABLES LIKE`; `$rows` to zawartość
 * starej tabeli zwracana przez `get_results()`.
 */
class S5Wpdb {
	public $prefix = 'wp_';
	public $old_table_exists;
	public $rows;
	public $show_tables_calls = 0;
	public $select_calls      = 0;
	public $inserts           = array();
	public $queries           = array();   // START TRANSACTION / ROLLBACK / COMMIT
	public $read_fails        = false;     // get_results() oddaje null (padnięte zapytanie)
	public $insert_fails_at   = 0;         // 0 = nigdy; N = N-ty insert zwraca false

	public function __construct( bool $old_table_exists, array $rows = array() ) {
		$this->old_table_exists = $old_table_exists;
		$this->rows             = $rows;
	}
	public function prepare( $query, ...$args ) {
		// Wystarczy zwrócić coś deterministycznego — Migrator używa wyniku tylko
		// jako argumentu get_var(), a atrapa niżej i tak ignoruje treść zapytania.
		return $query;
	}
	public function get_var( $query ) {
		$this->show_tables_calls++;
		return $this->old_table_exists ? ( $this->prefix . 'aifaq_history' ) : null;
	}
	public function get_results( $query, $output = null ) {
		$this->select_calls++;
		return $this->read_fails ? null : $this->rows;
	}
	public function query( $sql ) {
		$this->queries[] = (string) $sql;
		return true;
	}
	public function insert( $table, array $data ) {
		$this->inserts[] = array( 'table' => $table, 'data' => $data );

		if ( $this->insert_fails_at > 0 && count( $this->inserts ) === $this->insert_fails_at ) {
			return false;
		}

		return true;
	}
}

$fail = 0;
function check( $cond, $label ) { global $fail; echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n"; if ( ! $cond ) { $fail++; } }

// ===========================================================================
echo "=== A. Flaga już ustawiona → no-op całkowity ===\n";
// ===========================================================================
global $wpdb;
$GLOBALS['__opt'] = array( Migrator::FLAG_HISTORY => 1 );
$wpdb = new S5Wpdb( true, array( array( 'created_at' => '2026-01-01 00:00:00', 'topic' => 'x', 'user_id' => 1 ) ) );
Migrator::run();
check( 0 === $wpdb->show_tables_calls, 'A1: flaga ustawiona → ZERO zapytań SHOW TABLES' );
check( 0 === $wpdb->select_calls, 'A2: flaga ustawiona → ZERO odczytu starej tabeli' );
check( 0 === count( $wpdb->inserts ), 'A3: flaga ustawiona → ZERO wstawień do qa_log' );

// ===========================================================================
echo "\n=== B. Świeża instalacja (stara tabela NIE istnieje) ===\n";
// ===========================================================================
$GLOBALS['__opt'] = array();
$wpdb = new S5Wpdb( false );
Migrator::run();
check( 1 === $wpdb->show_tables_calls, 'B1: dokładnie jedno sprawdzenie SHOW TABLES' );
check( 0 === $wpdb->select_calls, 'B2: tabela nie istnieje → ZERO odczytu (brak SELECT na nieistniejącej tabeli)' );
check( 0 === count( $wpdb->inserts ), 'B3: zero wstawień' );
check( 1 === (int) ( $GLOBALS['__opt'][ Migrator::FLAG_HISTORY ] ?? 0 ), 'B4: flaga USTAWIONA mimo braku starej tabeli (świeża instalacja liczy się jako "zmigrowana")' );

// ===========================================================================
echo "\n=== C. Stara tabela z wierszami → mapowanie na qa_log ===\n";
// ===========================================================================
$GLOBALS['__opt'] = array();
$wpdb = new S5Wpdb( true, array(
	array( 'created_at' => '2026-01-15 09:00:00', 'topic' => 'Ceny czesnego', 'user_id' => 7 ),
	array( 'created_at' => '2026-02-01 10:00:00', 'topic' => 'Godziny otwarcia', 'user_id' => 3 ),
) );
Migrator::run();
check( 2 === count( $wpdb->inserts ), 'C1: dwa wiersze starej tabeli → dwa INSERT-y' );
check( Schema::table( Schema::T_QA_LOG ) === $wpdb->inserts[0]['table'], 'C2: INSERT idzie do tabeli qa_log (Schema::T_QA_LOG)' );
check( 'Ceny czesnego' === $wpdb->inserts[0]['data']['question'], 'C3: topic → question (pierwszy wiersz)' );
check( null === $wpdb->inserts[0]['data']['answer'], 'C4: answer = null (nie zmyślamy historycznej odpowiedzi)' );
check( 'answered' === $wpdb->inserts[0]['data']['status'], 'C5: status = answered' );
check( 'ai' === $wpdb->inserts[0]['data']['source'], 'C6: source = ai' );
check( 0 === $wpdb->inserts[0]['data']['score'], 'C7: score = 0 (nieznany historycznie, nie zmyślony)' );
check( 7 === $wpdb->inserts[0]['data']['user_id'], 'C8: user_id zachowany z wiersza źródłowego' );
check( '' === $wpdb->inserts[0]['data']['ip_hash'], 'C9: ip_hash pusty (nie istniał w schemacie v1)' );
check( 3 === $wpdb->inserts[1]['data']['user_id'], 'C10: drugi wiersz → user_id 3 (kolejność zachowana)' );
check( 1 === (int) ( $GLOBALS['__opt'][ Migrator::FLAG_HISTORY ] ?? 0 ), 'C11: flaga ustawiona PO migracji wierszy' );

// ===========================================================================
echo "\n=== D. Pusty created_at w starym wierszu → fallback current_time('mysql') ===\n";
// ===========================================================================
$GLOBALS['__opt'] = array();
$wpdb = new S5Wpdb( true, array( array( 'created_at' => '', 'topic' => 'Bez daty', 'user_id' => 0 ) ) );
Migrator::run();
check( '2026-07-28 12:00:00' === $wpdb->inserts[0]['data']['created_at'], 'D1: created_at pusty → fallback na current_time(mysql)' );

// ===========================================================================
echo "\n=== E. Idempotencja: drugie wywołanie PO migracji nie dotyka bazy ===\n";
// ===========================================================================
$GLOBALS['__opt'] = array();
$wpdb = new S5Wpdb( true, array( array( 'created_at' => '2026-01-01 00:00:00', 'topic' => 't', 'user_id' => 1 ) ) );
Migrator::run(); // pierwsze wywołanie — migruje i ustawia flagę.
check( 1 === count( $wpdb->inserts ), 'E1: pierwsze wywołanie migruje 1 wiersz' );
Migrator::run(); // drugie wywołanie — flaga już ustawiona.
check( 1 === count( $wpdb->inserts ), 'E2 (KLUCZOWA): drugie wywołanie NIE dubluje wpisu (nadal dokładnie 1 insert)' );
check( 1 === $wpdb->select_calls, 'E3: drugie wywołanie nie odpytuje starej tabeli ponownie' );

// ===========================================================================
echo "
=== F. Awaria ZAPISU → flaga NIE zapada, migracja da się powtórzyć ===
";
// ===========================================================================
// STRAŻNIK RAU-R07-001. Flagę kasuje dopiero odinstalowanie wtyczki, więc
// zapisana po nieudanej migracji zamyka drogę powrotną na trwałe.
$GLOBALS['__opt'] = array();
$wpdb = new S5Wpdb( true, array(
	array( 'created_at' => '2026-01-15 09:00:00', 'topic' => 'Pierwszy', 'user_id' => 1 ),
	array( 'created_at' => '2026-01-16 09:00:00', 'topic' => 'Drugi', 'user_id' => 2 ),
) );
$wpdb->insert_fails_at = 2;
$wynik_f = Migrator::run();

check( false === $wynik_f, 'F1: nieudane wstawienie → run() zwraca false' );
check( ! isset( $GLOBALS['__opt'][ Migrator::FLAG_HISTORY ] ), 'F2 (KLUCZOWA): flaga NIE zostaje ustawiona — druga próba jest możliwa' );
check( in_array( 'ROLLBACK', $wpdb->queries, true ), 'F3: wstawienia wycofane — powtórka nie zdubluje wierszy' );
check( ! in_array( 'COMMIT', $wpdb->queries, true ), 'F4: brak COMMIT po awarii' );

// ===========================================================================
echo "
=== G. Awaria ODCZYTU → to nie jest pusta historia ===
";
// ===========================================================================
// STRAŻNIK UZUP-01. `get_results()` oddaje null przy padniętym zapytaniu.
// Przed naprawą pętla była wtedy pomijana w całości, a flaga i tak zapadała:
// migracja zamykała się „wykonana", nie przeniosłszy ani jednego wiersza.
$GLOBALS['__opt'] = array();
$wpdb = new S5Wpdb( true, array( array( 'created_at' => '2026-01-15 09:00:00', 'topic' => 'x', 'user_id' => 1 ) ) );
$wpdb->read_fails = true;
$wynik_g = Migrator::run();

check( false === $wynik_g, 'G1: padnięty odczyt → run() zwraca false' );
check( ! isset( $GLOBALS['__opt'][ Migrator::FLAG_HISTORY ] ), 'G2 (KLUCZOWA): flaga NIE zostaje ustawiona po awarii odczytu' );
check( 0 === count( $wpdb->inserts ), 'G3: zero wstawień' );

// ===========================================================================
echo "
=== H. Sukces → run() potwierdza przeniesienie danych ===
";
// ===========================================================================
$GLOBALS['__opt'] = array();
$wpdb = new S5Wpdb( true, array( array( 'created_at' => '2026-01-15 09:00:00', 'topic' => 'x', 'user_id' => 1 ) ) );
$wynik_h = Migrator::run();

check( true === $wynik_h, 'H1: udana migracja → run() zwraca true' );
check( in_array( 'COMMIT', $wpdb->queries, true ), 'H2: wstawienia zatwierdzone' );
check( 1 === (int) ( $GLOBALS['__opt'][ Migrator::FLAG_HISTORY ] ?? 0 ), 'H3: flaga ustawiona' );

$GLOBALS['__opt'] = array();
$wpdb = new S5Wpdb( false );
check( true === Migrator::run(), 'H4: świeża instalacja (brak starej tabeli) też zwraca true' );

// ===========================================================================
echo "
=== I. ZABEZPIECZENIE: wersja bazy nie ma prawa pójść w górę bez migracji ===
";
// ===========================================================================
// RAU-R05-002 + RAU-R07-002. Zapisy `aifaq_db_version` są DWA — w `Plugin`
// (aktualizacja podmianą plików) i w `Activator` (aktywacja). Naprawa jednego
// z nich zostawiłaby drugą drogę otwartą, więc warunek obejmuje CAŁE `src/`.
$src_dir = dirname( __DIR__ ) . '/src';
$pliki   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $src_dir ) );
$zapisy  = array();

foreach ( $pliki as $plik ) {
	if ( ! $plik->isFile() || 'php' !== strtolower( $plik->getExtension() ) ) {
		continue;
	}

	$tresc = (string) file_get_contents( $plik->getPathname() );
	$ile   = preg_match_all( "/update_option\(\s*'aifaq_db_version'/", $tresc );

	if ( $ile > 0 ) {
		$zapisy[ str_replace( DIRECTORY_SEPARATOR, '/', substr( $plik->getPathname(), strlen( $src_dir ) + 1 ) ) ] = $ile;
	}
}

check( array( 'Core/Activator.php' => 1, 'Core/Plugin.php' => 1 ) === $zapisy, 'I1 (KONTROLA POZYTYWNA): w src/ są DOKŁADNIE dwa zapisy aifaq_db_version — Plugin i Activator (znaleziono: ' . json_encode( $zapisy ) . ')' );

$plugin_src = (string) file_get_contents( $src_dir . '/Core/Plugin.php' );
$activ_src  = (string) file_get_contents( $src_dir . '/Core/Activator.php' );

check(
	1 === preg_match( "/if \( Migrator::run\(\) \) \{\s*update_option\( 'aifaq_db_version'/", $plugin_src ),
	'I2: Plugin zapisuje wersję WYŁĄCZNIE w gałęzi udanej Migrator::run()'
);
check(
	1 === preg_match( '/\$zmigrowano\s*=\s*Migrator::run\(\)/', $activ_src ),
	'I3: Activator zapamiętuje wynik migracji'
);
check(
	1 === preg_match( '/if \( \$zmigrowano \) \{\s*update_option\( .aifaq_db_version./', $activ_src ),
	'I4: Activator zapisuje wersję WYŁĄCZNIE w gałęzi udanej migracji'
);

// RAU-R07-003: nagłówek pliku schematu mówił „v4", a stała — „6".
$boot   = (string) file_get_contents( dirname( __DIR__ ) . '/ai-faq-generator.php' );
$schema = (string) file_get_contents( $src_dir . '/Data/Schema.php' );

check( 1 === preg_match( "/AIFAQ_DB_VERSION',\s*'(\d+)'/", $boot, $m_ver ), 'I5 (KONTROLA POZYTYWNA): odczytano AIFAQ_DB_VERSION z pliku głównego' );
check( 1 === preg_match( '/schema v(\d+)/', $schema, $m_hdr ), 'I6 (KONTROLA POZYTYWNA): odczytano wersję z nagłówka Schema.php' );
check( ( $m_ver[1] ?? 'a' ) === ( $m_hdr[1] ?? 'b' ), 'I7: nagłówek Schema.php podaje tę samą wersję co AIFAQ_DB_VERSION (nagłówek: v' . ( $m_hdr[1] ?? '?' ) . ', stała: ' . ( $m_ver[1] ?? '?' ) . ')' );

// ===========================================================================
echo "\n=== PODSUMOWANIE ===\n";
echo ( 0 === $fail ) ? "TEST K23 ETAP 3 SEGMENT S5 (Migrator + FaqRepository martwy kod): WSZYSTKIE ASERCJE OK\n" : "TEST K23 ETAP 3 SEGMENT S5 (Migrator + FaqRepository martwy kod): $fail ASERCJI NIE PRZESZŁO\n";
exit( $fail === 0 ? 0 : 1 );
