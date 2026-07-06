<?php
/**
 * Logika aktywacji wtyczki — tworzenie tabeli historii.
 *
 * @package AI_FAQ_Generator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Odpalane raz, przy aktywacji wtyczki.
 */
class AIFAQ_Activator {

	/**
	 * Nazwa tabeli historii (bez prefiksu).
	 */
	const TABLE = 'aifaq_history';

	/**
	 * Punkt wejścia aktywacji.
	 */
	public static function activate(): void {
		self::create_history_table();
		update_option( 'aifaq_db_version', AIFAQ_DB_VERSION );
	}

	/**
	 * Pełna nazwa tabeli z prefiksem bazy.
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Tworzy/aktualizuje tabelę historii przez dbDelta().
	 */
	public static function create_history_table(): void {
		global $wpdb;

		$table_name      = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		// Uwaga: dbDelta wymaga specyficznego formatowania
		// (dwie spacje po PRIMARY KEY, każde pole w osobnej linii).
		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			topic text NOT NULL,
			question_count smallint(5) unsigned NOT NULL DEFAULT 0,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			payload longtext NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY user_id (user_id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}
}
