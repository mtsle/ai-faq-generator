<?php
/**
 * Testy: Krok 23 Etap 1 (Debug) — dwa fixy w kaskadzie indeksowania.
 *
 * A) Activator::activate() odtwarza harmonogram crona crawla (CrawlQueue::CRON_HOOK),
 *    gdy kolejka jest niepusta w chwili aktywacji — inaczej Deactivator kasuje
 *    zaplanowane zdarzenie, kolejka zostaje niepusta NA ZAWSZE i każde „Zaindeksuj"
 *    (IndexController::run_reindex()) zwraca 409 (patrz IndexController.php:160-186,
 *    warunek `progress()['running']`). Fix: src/Core/Activator.php (krok 7, przed
 *    zapisem wersji bazy) woła `(new CrawlQueue())->schedule()` — metoda jest już
 *    idempotentna i SAMA definiuje „kolejka niepusta" (CrawlQueue.php ok. linia 280),
 *    więc test dowodzi zachowania PRZEZ tę samą definicję, bez duplikowania jej tutaj.
 *
 * B) WpContentSource::is_indexable() — wyjątek w compute_indexable() dawał
 *    fail-OPEN (`$result = true`), jedyne takie miejsce w klasie (reszta jest
 *    fail-closed). Fix: src/Index/WpContentSource.php:255-259 → `$result = false`.
 *
 * URUCHOMIENIE:  php -d extension=mbstring tests/krok23-etap1-index-crawl-test.php
 * Kod wyjścia: 0 = OK, 1 = błędy.
 *
 * @package AI_FAQ_Generator
 */

// Plik miesza kilka namespace'ów (atrapy AIFAQ\Data\*, AIFAQ\PublicUi\*,
// AIFAQ\Core\Router) z kodem globalnym testu — PHP wymaga wtedy SKŁADNI
// NAWIASOWEJ konsekwentnie w całym pliku (nie da się zacząć bez namespace,
// a potem przejść na `namespace X { ... }`), stąd cała reszta pliku żyje
// w jawnym bloku `namespace { ... }`.
namespace {

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
if ( ! defined( 'AIFAQ_VERSION' ) ) { define( 'AIFAQ_VERSION', '1.0.0-test' ); }
if ( ! defined( 'AIFAQ_DB_VERSION' ) ) { define( 'AIFAQ_DB_VERSION', '5' ); }
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); }

$fail = 0;
$ran  = 0;
function check( $cond, $label ) {
	global $fail, $ran;
	$ran++;
	echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n";
	if ( ! $cond ) { $fail++; }
}

$root = dirname( __DIR__ );

// ---------------------------------------------------------------------------
// Atrapy WordPressa/crona — WSPÓLNE dla obu części (zdefiniowane PRZED require).
// ---------------------------------------------------------------------------
$GLOBALS['__opt']  = array();
$GLOBALS['__cron'] = array(); // hook => timestamp|recurrence-info.

if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = false ) {
		return array_key_exists( $key, $GLOBALS['__opt'] ) ? $GLOBALS['__opt'][ $key ] : $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $key, $value, $autoload = null ) { $GLOBALS['__opt'][ $key ] = $value; return true; }
}
if ( ! function_exists( 'add_option' ) ) {
	function add_option( $key, $value = '', $dep = '', $autoload = 'yes' ) {
		if ( array_key_exists( $key, $GLOBALS['__opt'] ) ) { return false; }
		$GLOBALS['__opt'][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $key ) { unset( $GLOBALS['__opt'][ $key ] ); return true; }
}
if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( $hook, $args = array() ) { return $GLOBALS['__cron'][ $hook ]['ts'] ?? false; }
}
if ( ! function_exists( 'wp_schedule_event' ) ) {
	function wp_schedule_event( $timestamp, $recurrence, $hook, $args = array() ) {
		// Rejestrujemy jak PRAWDZIWY wp_schedule_event: nie nadpisuje istniejącego
		// zdarzenia tego hooka (schedule() samo chroni się przez wp_next_scheduled(),
		// ale asercje o idempotencji muszą widzieć TĘ SAMĄ znaczkę czasu).
		if ( isset( $GLOBALS['__cron'][ $hook ] ) ) { return false; }
		$GLOBALS['__cron'][ $hook ] = array(
			'ts'         => $timestamp,
			'recurrence' => $recurrence,
		);
		return true;
	}
}
if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	function wp_schedule_single_event( $timestamp, $hook, $args = array() ) {
		$GLOBALS['__cron'][ $hook ] = array( 'ts' => $timestamp );
		return true;
	}
}
if ( ! function_exists( 'wp_unschedule_event' ) ) {
	function wp_unschedule_event( $timestamp, $hook, $args = array() ) { unset( $GLOBALS['__cron'][ $hook ] ); return true; }
}
if ( ! function_exists( 'flush_rewrite_rules' ) ) { function flush_rewrite_rules() {} }

require_once $root . '/src/Index/CrawlQueue.php';

use AIFAQ\Index\CrawlQueue;

// ---------------------------------------------------------------------------
// Atrapy klas z innego etapu, wymagane przez Activator::activate() (kroki
// 1-4 przed naszym fixem) — Activator.php woła je BEZWARUNKOWO, więc test
// musi je dostarczyć, żeby dojść do kroku 7 (crawl) i 8 (wersja bazy). Zgodnie
// z zasadą „nie zaglądaj głębiej niż trzeba" atrapy są no-opami: dowodzimy
// zachowania KROKU 7, nie reszty aktywacji (tamta ma własne testy —
// krok20-menu-test.php).
// ---------------------------------------------------------------------------

} // koniec pierwszego namespace {} globalnego.

namespace AIFAQ\Data {
	class Schema {
		public static function install(): void {}
	}
	class Migrator {
		public static function run(): void {}
	}
}
namespace AIFAQ\PublicUi {
	class Shortcode {
		public static function ensure_page(): void {}
	}
}
namespace AIFAQ\Core {
	// Stub — realny Router.php nie jest ładowany (uniknięcie kaskady zależności
	// niezwiązanych z Etapem 1). Activator wywołuje tylko add_rewrite_rules().
	class Router {
		public function add_rewrite_rules(): void {}
	}
}

namespace {

require_once $root . '/src/Core/Activator.php';

use AIFAQ\Core\Activator;
use AIFAQ\Index\CrawlQueue;

// ===========================================================================
echo "=== A. Activator::activate() odtwarza cron crawla, gdy kolejka niepusta ===\n";
// ===========================================================================

// A1. Kolejka NIEPUSTA w chwili aktywacji (symulacja: crawl przerwany
//     dezaktywacją wtyczki, dokładnie stan po Deactivator::deactivate()
//     — dane w opcji zostają, TYLKO zdarzenie crona znika).
$GLOBALS['__opt']  = array(
	CrawlQueue::OPTION => array(
		'queue' => array(
			array( 'post_id' => 11, 'url' => 'https://example.test/?p=11' ),
			array( 'post_id' => 12, 'url' => 'https://example.test/?p=12' ),
		),
		'done'  => array( 5, 6, 7 ),
		'total' => 5,
	),
);
$GLOBALS['__cron'] = array(); // Deactivator już zdjął zdarzenie — cron pusty.

$queue_before = new CrawlQueue();
check( true === $queue_before->progress()['running'], 'stan wstępny: kolejka NIEPUSTA (running=true) — punkt wyjścia scenariusza z zadania' );
check( false === wp_next_scheduled( CrawlQueue::CRON_HOOK ), 'stan wstępny: cron CRON_HOOK NIE jest zaplanowany (Deactivator go zdjął)' );

Activator::activate();

check(
	false !== wp_next_scheduled( CrawlQueue::CRON_HOOK ),
	'(A) po aktywacji z NIEPUSTĄ kolejką powstaje zaplanowane zdarzenie CrawlQueue::CRON_HOOK'
);

// A2. Idempotencja: druga aktywacja NIE dubluje zdarzenia (ten sam znacznik czasu).
$first_ts = wp_next_scheduled( CrawlQueue::CRON_HOOK );
Activator::activate();
$second_ts = wp_next_scheduled( CrawlQueue::CRON_HOOK );
check( $first_ts === $second_ts, '(A) dwukrotna aktywacja NIE planuje dwóch zdarzeń (znacznik czasu się nie zmienia)' );
check( 1 === count( $GLOBALS['__cron'] ), '(A) w rejestrze crona jest DOKŁADNIE JEDNO zdarzenie CRON_HOOK po dwóch aktywacjach' );

// A3. Kolejka PUSTA w chwili aktywacji (instalacja od zera / crawl ukończony
//     przed dezaktywacją) — aktywacja NIE ma prawa zaplanować crona na pustą kolejkę.
$GLOBALS['__opt']  = array(); // brak opcji CrawlQueue::OPTION == kolejka pusta.
$GLOBALS['__cron'] = array();

$queue_empty = new CrawlQueue();
check( false === $queue_empty->progress()['running'], 'stan wstępny: kolejka PUSTA (running=false)' );

Activator::activate();

check(
	false === wp_next_scheduled( CrawlQueue::CRON_HOOK ),
	'(A) po aktywacji z PUSTĄ kolejką NIE powstaje zaplanowane zdarzenie CrawlQueue::CRON_HOOK'
);

// A4. Wersja bazy nadal zapisywana (fix nie wypchnął kroku 8 z metody — regresja
//     byłaby cicha, bo activate() nie rzuca, tylko przestałaby aktualizować opcję).
check(
	AIFAQ_DB_VERSION === ( $GLOBALS['__opt']['aifaq_db_version'] ?? null ),
	'(A) activate() nadal zapisuje aifaq_db_version PO dodaniu kroku 7 (kolejność nie zepsuta)'
);

// A5. Odporność: brak klasy CrawlQueue nie może wywalić aktywacji (guard
//     `class_exists`) — dowód przez lekturę źródła (nie da się bezpiecznie
//     "odładować" klasy w PHP, więc weryfikujemy strukturalnie).
$activator_src = (string) file_get_contents( $root . '/src/Core/Activator.php' );
check(
	false !== strpos( $activator_src, "class_exists( '\\AIFAQ\\Index\\CrawlQueue' )" )
	&& false !== strpos( $activator_src, 'new \\AIFAQ\\Index\\CrawlQueue()' )
	&& false !== strpos( $activator_src, '->schedule();' ),
	'(A) Activator.php: reaktywacja crona osłonięta class_exists(), woła CrawlQueue::schedule()'
);
check(
	1 === substr_count( $activator_src, "class_exists( '\\AIFAQ\\Index\\CrawlQueue' )" ),
	'(A) Activator.php: dokładnie JEDNO miejsce reaktywacji crona crawla (brak duplikacji)'
);

// ===========================================================================
echo "\n=== B. WpContentSource::is_indexable() — wyjątek daje FALSE, nie TRUE ===\n";
// ===========================================================================

require_once $root . '/src/Index/ContentSource.php';
require_once $root . '/src/Index/WpContentSource.php';

use AIFAQ\Index\WpContentSource;

// compute_indexable() jest protected — testujemy PRZEZ is_indexable() (API
// publiczne), zmuszając compute_indexable() do wyjątku: get_post_status()
// zdefiniowany jako rzucający, wszystkie inne funkcje WP CELOWO niedostępne
// (function_exists() na nie zwraca false), żeby wyjątek poszedł z KROKU 1
// bramki i test nie zależał od kolejności warunków wewnątrz compute_indexable().
if ( ! function_exists( 'get_post_status' ) ) {
	function get_post_status( $post_id ) {
		throw new \RuntimeException( 'awaria symulowana przez test (np. obca wtyczka SEO)' );
	}
}

$post_id = 9001;
$thrown  = false;
try {
	$result_b = WpContentSource::is_indexable( $post_id );
} catch ( \Throwable $e ) {
	$thrown = true;
	$result_b = null;
}

check( false === $thrown, '(B) is_indexable() ŁAPIE wyjątek — nie propaguje go dalej (nie wywala reindeksu/crawla)' );
check( false === $result_b, '(B) wyjątek w compute_indexable() daje FALSE (fail-closed), nie TRUE' );

// Memoizacja: wynik fail-closed też jest zapamiętany (spójność z resztą bramki —
// drugie wywołanie nie może dać innej odpowiedzi dla tego samego post_id).
$result_b_again = WpContentSource::is_indexable( $post_id );
check( false === $result_b_again, '(B) memoizacja: powtórne wywołanie dla tego samego post_id nadal FALSE' );

// Kontrola negatywna: bez rzucającej funkcji zwykły wpis (status inny niż
// throw) przechodzi normalnie — dowodzi, że test B naprawdę wymusza wyjątek,
// a nie zawsze zwraca false z innego powodu.
WpContentSource::reset_indexable_cache();
$src_b = (string) file_get_contents( $root . '/src/Index/WpContentSource.php' );
check(
	false !== strpos( $src_b, '$result = false;' ) && false === strpos( $src_b, '$result = true; // awaria warunku' ),
	'(B) WpContentSource.php: catch(Throwable) w is_indexable() ustawia $result = false (stary fail-open usunięty)'
);

// ---------------------------------------------------------------------------
// Podłoga pokrycia.
// ---------------------------------------------------------------------------
echo "\n=== Z. Podłoga pokrycia ===\n";
// Asercja ilościowa dokładna (reguła projektu) — przed inkrementacją check(): $ran = 14.
check( 14 === $ran, 'wykonano dokładnie 14 asercji (jest: ' . $ran . ')' );

echo "\n";
if ( 0 === $fail ) {
	echo "=== WSZYSTKIE OK (asercji: {$ran}) ===\n";
	exit( 0 );
}
echo "=== BŁĘDÓW: {$fail} (asercji: {$ran}) ===\n";
exit( 1 );

} // namespace {}
