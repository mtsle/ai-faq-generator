<?php
/**
 * Logika deaktywacji wtyczki.
 *
 * Celowo NIE usuwamy danych ani tabel — deaktywacja ma być bezpieczna
 * i odwracalna. Sprzątamy jedynie reguły rewrite (usuwamy wpis
 * `/faqgenerator`, żeby nie zostawić martwej trasy). Pełne czyszczenie
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
	}
}
