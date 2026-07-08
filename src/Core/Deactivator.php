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
	}
}
