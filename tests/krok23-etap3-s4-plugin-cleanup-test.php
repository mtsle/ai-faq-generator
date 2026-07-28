<?php
/**
 * Krok 23 etap 3, segment S4 (indeksowanie i crawl) — łatanie luki z Fazy A:
 * `Plugin::on_knowledge_post_removed()` (K23 etap 1, znalezisko A2 — NAJPOWAŻNIEJSZE
 * znalezisko audytu: kosz/usunięcie wpisu nie czyściło bazy wiedzy RAG, gość mógł
 * dostawać dezinformację z linkiem do 404) miało dotąd WYŁĄCZNIE testy STATYCZNE
 * (`krok18-pageguard-test.php` #61-#63b: `method_exists`, pozycje literałów stringów
 * w źródle) — ŻADEN test nie WYWOŁUJE metody i nie sprawdza, że faktycznie usuwa
 * fragmenty i czyści cache. Fix na najpoważniejsze znalezisko w projekcie był
 * dotąd "sprawdzony" tylko dowodem, że kod ISTNIEJE, nie że DZIAŁA.
 *
 * `Plugin` ma prywatny konstruktor (singleton) — metoda jest jednak `public` i nie
 * dotyka żadnej właściwości instancji (tylko lokalne `new KnowledgeRepository()`/
 * `new CacheRepository()`), więc `ReflectionClass::newInstanceWithoutConstructor()`
 * pozwala ją wywołać bez uruchamiania całego bootstrapu wtyczki (hooki, Settings, dbDelta).
 *
 * Pokrywa:
 *  A. Usunięcie wpisu, który MIAŁ zaindeksowane fragmenty → `delete_by_post()` kasuje
 *     wiersze z `wp_aifaq_knowledge` I `clear_all()` czyści `wp_aifaq_cache` (dowód end-to-end
 *     naprawy — gość NIE dostanie już starej odpowiedzi z cache po skasowaniu źródła).
 *  B. Usunięcie wpisu BEZ zaindeksowanych fragmentów (0 usuniętych wierszy) → cache
 *     NIE jest czyszczony (unikamy zbędnego `TRUNCATE` przy każdym koszu na stronie).
 *  C. Wyjątek wewnątrz metody (np. awaria bazy) → złapany, `on_knowledge_post_removed()`
 *     nie propaguje `\Throwable` dalej (nie wywala WP przy przenoszeniu wpisu do kosza).
 *
 * URUCHOMIENIE:  php tests/krok23-etap3-s4-plugin-cleanup-test.php
 * Kod wyjścia: 0 = OK, 1 = błędy.
 *
 * @package AI_FAQ_Generator
 */

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }

require __DIR__ . '/../src/Data/Schema.php';
require __DIR__ . '/../src/Data/Repository.php';
require __DIR__ . '/../src/Data/KnowledgeRepository.php';
require __DIR__ . '/../src/Data/CacheRepository.php';
require __DIR__ . '/../src/Core/Plugin.php';

use AIFAQ\Core\Plugin;

/**
 * Atrapa $wpdb — tylko metody, których dotykają delete_by_post()/clear_all().
 * `$fail_mode` symuluje awarię bazy (rzuca wyjątek) dla sekcji C.
 */
class S4Wpdb {
	public $prefix = 'wp_';
	public $delete_calls = array();
	public $truncate_calls = 0;
	public $delete_return; // liczba "usuniętych wierszy" zwracana przez delete().
	public $count_var = 0;
	public $fail_mode = false;

	public function __construct( int $delete_return, int $count_var = 0 ) {
		$this->delete_return = $delete_return;
		$this->count_var     = $count_var;
	}
	public function delete( $table, $where, $formats ) {
		if ( $this->fail_mode ) { throw new \RuntimeException( 'baza padła' ); }
		$this->delete_calls[] = array( $table, $where );
		return $this->delete_return;
	}
	public function get_var( $sql ) { return $this->count_var; }
	public function query( $sql ) { $this->truncate_calls++; return true; }
}

$fail = 0;
function check( $cond, $label ) { global $fail; echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n"; if ( ! $cond ) { $fail++; } }

$ref = new ReflectionClass( Plugin::class );
$plugin = $ref->newInstanceWithoutConstructor();

// ===========================================================================
echo "=== A. Wpis MIAŁ zaindeksowane fragmenty → knowledge KASOWANA + cache CZYSZCZONY ===\n";
// ===========================================================================
global $wpdb;
$wpdb = new S4Wpdb( /* delete_return */ 3 /* fragmenty usunięte */, /* count_var */ 12 );
$plugin->on_knowledge_post_removed( 42 );
check( 1 === count( $wpdb->delete_calls ), 'A1: delete_by_post() wywołany dokładnie raz' );
check( 'wp_aifaq_knowledge' === ( $wpdb->delete_calls[0][0] ?? '' ), 'A2: DELETE na tabeli wp_aifaq_knowledge' );
check( 42 === ( $wpdb->delete_calls[0][1]['post_id'] ?? 0 ), 'A3: WHERE post_id = 42 (ID przekazany z hooka)' );
check( 1 === $wpdb->truncate_calls, 'A4 (KLUCZOWA — dowód naprawy K23 A2): cache TRUNCATE wywołany, bo usunięto >0 fragmentów' );

// ===========================================================================
echo "\n=== B. Wpis BEZ zaindeksowanych fragmentów (0 usuniętych) → cache NIE czyszczony ===\n";
// ===========================================================================
$wpdb = new S4Wpdb( /* delete_return */ 0, 5 );
$plugin->on_knowledge_post_removed( 99 );
check( 1 === count( $wpdb->delete_calls ), 'B1: delete_by_post() nadal wywołany (sprawdzamy zawsze)' );
check( 0 === $wpdb->truncate_calls, 'B2: cache TRUNCATE pominięty — 0 usuniętych fragmentów, zbędny koszt uniknięty' );

// ===========================================================================
echo "\n=== C. Awaria bazy wewnątrz delete_by_post() → wyjątek złapany, brak propagacji ===\n";
// ===========================================================================
$wpdb            = new S4Wpdb( 1 );
$wpdb->fail_mode = true;
$threw = false;
try {
	$plugin->on_knowledge_post_removed( 7 );
} catch ( \Throwable $e ) {
	$threw = true;
}
check( false === $threw, 'C1: wyjątek z delete_by_post() NIE propaguje się na zewnątrz metody (złapany w try/catch)' );
check( 0 === $wpdb->truncate_calls, 'C2: przy wyjątku cache NIE jest czyszczony (linia clear_all() nie zostaje osiągnięta)' );

// ===========================================================================
echo "\n=== PODSUMOWANIE ===\n";
echo ( 0 === $fail ) ? "TEST K23 ETAP 3 SEGMENT S4 (Plugin::on_knowledge_post_removed — naprawa K23 A2): WSZYSTKIE ASERCJE OK\n" : "TEST K23 ETAP 3 SEGMENT S4 (Plugin::on_knowledge_post_removed — naprawa K23 A2): $fail ASERCJI NIE PRZESZŁO\n";
exit( $fail === 0 ? 0 : 1 );
