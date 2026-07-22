<?php
/**
 * Logika aktywacji wtyczki.
 *
 * Przy aktywacji: tworzy/aktualizuje 4 tabele (schema v2), przenosi dane
 * ze starej tabeli historii do dziennika Q&A, rejestruje regułę rewrite
 * `/faqgenerator` i przebudowuje reguły (flush), tworzy podstronę generatora
 * i pozycję prowadzącą do niej w menu, a na końcu zapisuje wersję bazy.
 *
 * @package AI_FAQ_Generator
 */

namespace AIFAQ\Core;

use AIFAQ\Data\Schema;
use AIFAQ\Data\Migrator;
use AIFAQ\PublicUi\Shortcode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Odpalane raz, przy aktywacji wtyczki.
 */
class Activator {

	/**
	 * Punkt wejścia aktywacji.
	 */
	public static function activate(): void {
		// 1. Struktura bazy (4 tabele) — schema v2.
		Schema::install();

		// 2. Migracja danych ze starej tabeli historii (jednorazowa).
		Migrator::run();

		// 3. Rejestracja reguły rewrite i przebudowa (żeby /faqgenerator działało od razu).
		$router = new Router();
		$router->add_rewrite_rules();
		flush_rewrite_rules();

		// 4. Podstrona „Generator FAQ" z shortcode'em (wejście dla gościa).
		Shortcode::ensure_page();

		// 5. Pozycja „Generator FAQ" w menu nawigacji (Krok 20) — jedyne wejście gościa
		//    do podstrony, bo `/generator-faq/` nie trafia do menu samo z siebie.
		//    KOLEJNOŚĆ MA ZNACZENIE: podstrona musi już istnieć (krok 4), zanim wskaże
		//    ją pozycja menu. `MenuGuard` NIE tworzy menu i niczego nie przypina — gdy
		//    menu nie ma, zostawia stan `no_menu` i komunikat dla właściciela.
		//    Aktywacja nie ma prawa paść przez menu: klasa pod `class_exists`, próba
		//    pod `try/catch` — idiom identyczny z testem pętli zwrotnej niżej.
		if ( class_exists( '\AIFAQ\PublicUi\MenuGuard' )
			&& method_exists( '\AIFAQ\PublicUi\MenuGuard', 'ensure' ) ) {
			try {
				// Znacznik świadomego usunięcia sprawdza sam `ensure()` — klient, który
				// raz skasował link ręcznie, nie dostaje go z powrotem po reaktywacji.
				\AIFAQ\PublicUi\MenuGuard::ensure();
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		// 6. Test pętli zwrotnej (Krok 17) — czy serwer potrafi pobrać własną stronę.
		//    Robimy go RAZ, przy aktywacji i z pominięciem cache (`true`), żeby
		//    właściciel zobaczył ostrzeżenie na Dashboardzie zanim kliknie indeksowanie,
		//    a nie po nieudanym crawlu. Wynik zapisuje sama metoda (opcja `aifaq_loopback`).
		//    Klasa należy do innego etapu — jej brak pomija krok, nigdy nie blokuje aktywacji.
		if ( class_exists( '\AIFAQ\Index\RenderedContentSource' )
			&& method_exists( '\AIFAQ\Index\RenderedContentSource', 'loopback_ok' ) ) {
			try {
				\AIFAQ\Index\RenderedContentSource::loopback_ok( true );
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		// 7. Zapis wersji bazy.
		update_option( 'aifaq_db_version', AIFAQ_DB_VERSION );
	}
}
