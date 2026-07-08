<?php
/**
 * Migrator danych między wersjami schematu.
 *
 * Etap v2: jednorazowo przenosi rekordy ze starej tabeli `wp_aifaq_history`
 * (admin-only, schema v1) do nowego dziennika `wp_aifaq_qa_log`. Stara tabela
 * NIE jest usuwana (bezpieczeństwo/odwracalność) — oznaczamy tylko flagą, że
 * migracja się odbyła, więc uruchamia się co najwyżej raz.
 *
 * @package AI_FAQ_Generator
 */

namespace AIFAQ\Data;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Jednorazowe migracje danych.
 */
class Migrator {

	/**
	 * Flaga w wp_options informująca, że migracja historii już przebiegła.
	 */
	const FLAG_HISTORY = 'aifaq_history_migrated';

	/**
	 * Uruchamia wszystkie potrzebne migracje.
	 */
	public static function run(): void {
		self::migrate_history_to_qa_log();
	}

	/**
	 * Przenosi wp_aifaq_history → wp_aifaq_qa_log (raz).
	 */
	private static function migrate_history_to_qa_log(): void {
		global $wpdb;

		// Już zrobione? Nic nie rób.
		if ( get_option( self::FLAG_HISTORY ) ) {
			return;
		}

		$history = $wpdb->prefix . 'aifaq_history';

		// Stara tabela nie istnieje (świeża instalacja) — oznacz i wyjdź.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $history ) ); // phpcs:ignore WordPress.DB
		if ( $exists !== $history ) {
			update_option( self::FLAG_HISTORY, 1 );
			return;
		}

		$rows = $wpdb->get_results( "SELECT created_at, topic, user_id FROM {$history}", ARRAY_A ); // phpcs:ignore WordPress.DB
		$log  = Schema::table( Schema::T_QA_LOG );

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				// Mapowanie: temat generacji → „pytanie" w dzienniku, jako historyczny wpis.
				$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$log,
					array(
						'created_at' => $row['created_at'] ?: current_time( 'mysql' ),
						'question'   => (string) ( $row['topic'] ?? '' ),
						'answer'     => null,
						'status'     => 'answered',
						'source'     => 'ai',
						'score'      => 0,
						'user_id'    => (int) ( $row['user_id'] ?? 0 ),
						'ip_hash'    => '',
					)
				);
			}
		}

		update_option( self::FLAG_HISTORY, 1 );
	}
}
