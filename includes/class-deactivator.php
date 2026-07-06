<?php
/**
 * Logika deaktywacji wtyczki.
 *
 * @package AI_FAQ_Generator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Odpalane przy deaktywacji wtyczki.
 *
 * Celowo NIE usuwamy danych ani tabeli — deaktywacja ma być bezpieczna
 * i odwracalna. Pełne czyszczenie odbywa się dopiero w uninstall.php,
 * gdy użytkownik świadomie usuwa wtyczkę.
 */
class AIFAQ_Deactivator {

	/**
	 * Punkt wejścia deaktywacji.
	 */
	public static function deactivate(): void {
		// Miejsce na sprzątanie ulotne (np. flush cache/rewrite) w przyszłości.
	}
}
