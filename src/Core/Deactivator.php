<?php
/**
 * Logika deaktywacji wtyczki.
 *
 * Celowo NIE usuwamy danych ani tabel — deaktywacja ma być bezpieczna
 * i odwracalna. Sprzątamy jedynie reguły rewrite (usuwamy wpis
 * `/faqgenerator`, żeby nie zostawić martwej trasy), zaplanowane zadania cron
 * oraz WŁASNĄ pozycję w menu nawigacji (Krok 20 — inaczej zostałby w menu
 * klienta martwy link do wyłączonego generatora). Pełne czyszczenie
 * odbywa się dopiero w uninstall.php, gdy użytkownik świadomie usuwa wtyczkę.
 *
 * @package AI_FAQ_Generator
 */

namespace AIFAQ\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Odpalane przy deaktywacji wtyczki.
 */
class Deactivator {

	/**
	 * Punkt wejścia deaktywacji.
	 */
	public static function deactivate(): void {
		// Nasza reguła nie jest już rejestrowana na init (wtyczka wyłączona),
		// więc flush przebuduje reguły bez `/faqgenerator`.
		flush_rewrite_rules();

		// Cron pobierania stron (Krok 17) — zdejmujemy WSZYSTKIE zaplanowane zadania
		// tego hooka. Zostawione wisiałyby w `cron` na zawsze: harmonogram
		// `aifaq_minute` rejestruje wyłączona wtyczka, więc WordPress próbowałby
		// odpalać zadanie, którego nikt już nie obsługuje. Danych (pobranego HTML-a)
		// NIE kasujemy — deaktywacja ma być odwracalna, sprząta dopiero uninstall.php.
		if ( function_exists( 'wp_unschedule_hook' ) ) {
			$hook = class_exists( '\AIFAQ\Index\CrawlQueue' )
				? \AIFAQ\Index\CrawlQueue::CRON_HOOK
				: 'aifaq_crawl_tick';
			wp_unschedule_hook( $hook );
		}

		// Pozycja „Generator FAQ" w menu nawigacji (Krok 20). To PIERWSZE miejsce w tym
		// pliku, które kasuje treść — i jedyny wyjątek od reguły „deaktywacja ma być
		// odwracalna". Uzasadnienie: pozycja menu nie jest danymi klienta, tylko naszym
		// interfejsem. Zostawiona po wyłączeniu wtyczki prowadziłaby gościa pod adres,
		// pod którym nie ma już generatora — czyli martwy link w nawigacji witryny,
		// widoczny dla wszystkich. Odwracalność zostaje zachowana: ponowna aktywacja
		// pozycję odtwarza (`Activator` krok 5).
		//
		// `MenuGuard::remove()` broni się sam i kasuje WYŁĄCZNIE pozycję, którą wtyczka
		// utworzyła (`owned = '1'`). Pozycji dodanej ręką klienta nie rusza — ta jest
		// jego treścią. Deaktywacja nie ma prawa paść przez menu, stąd `class_exists`
		// i `try/catch`.
		if ( class_exists( '\AIFAQ\PublicUi\MenuGuard' )
			&& method_exists( '\AIFAQ\PublicUi\MenuGuard', 'remove' ) ) {
			try {
				\AIFAQ\PublicUi\MenuGuard::remove();
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}
	}
}
