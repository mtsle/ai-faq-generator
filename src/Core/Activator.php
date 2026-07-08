<?php
/**
 * Logika aktywacji wtyczki.
 *
 * Przy aktywacji: tworzy/aktualizuje 4 tabele (schema v2), przenosi dane
 * ze starej tabeli historii do dziennika Q&A, rejestruje regułę rewrite
 * `/faqgenerator` i przebudowuje reguły (flush), a na końcu zapisuje wersję
 * bazy.
 *
 * @package AI_FAQ_Generator
 */

namespace AIFAQ\Core;

use AIFAQ\Data\Schema;
use AIFAQ\Data\Migrator;

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

		// 4. Zapis wersji bazy.
		update_option( 'aifaq_db_version', AIFAQ_DB_VERSION );
	}
}
