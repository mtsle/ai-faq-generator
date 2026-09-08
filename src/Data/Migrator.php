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
	 *
	 * @return bool `true`, gdy komplet migracji jest zamknięty (także wtedy, gdy
	 *              nie było czego migrować). `false` znaczy: dane NIE zostały
	 *              przeniesione, a wywołujący NIE MA PRAWA podnieść
	 *              `aifaq_db_version` (AWA-W1-16, TECH-20).
	 */
	public static function run(): bool {
		return self::migrate_history_to_qa_log();
	}

	/**
	 * Przenosi wp_aifaq_history → wp_aifaq_qa_log (raz).
	 *
	 * Flaga zakończenia jest NIEODWRACALNA — kasuje ją dopiero odinstalowanie
	 * wtyczki — więc wolno ją zapisać dopiero wtedy, gdy KAŻDY odczytany wiersz
	 * został wstawiony. Przed naprawą (audyt przebieg-2, RAU-R07-001 i UZUP-01)
	 * flaga zapada bezwarunkowo: ani wynik `insert()`, ani wynik `get_results()`
	 * nie był czytany, więc awaria zapisu ORAZ awaria odczytu kończyły się
	 * migracją „wykonaną", z której nie da się wrócić.
	 *
	 * Wstawienia idą w transakcji: bez niej porzucenie migracji w połowie
	 * zostawiałoby część wierszy w dzienniku, a następna próba dopisałaby je
	 * po raz drugi.
	 *
	 * @return bool
	 */
	private static function migrate_history_to_qa_log(): bool {
		global $wpdb;

		// Już zrobione? Nic nie rób.
		if ( get_option( self::FLAG_HISTORY ) ) {
			return true;
		}

		$history = $wpdb->prefix . 'aifaq_history';

		// Stara tabela nie istnieje (świeża instalacja) — oznacz i wyjdź.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $history ) ); // phpcs:ignore WordPress.DB
		if ( $exists !== $history ) {
			update_option( self::FLAG_HISTORY, 1 );
			return true;
		}

		$rows = $wpdb->get_results( "SELECT created_at, topic, user_id FROM {$history}", ARRAY_A ); // phpcs:ignore WordPress.DB

		// Awaria ODCZYTU to nie jest pusta historia. `get_results()` oddaje `null`,
		// gdy zapytanie padnie — wcześniej pętla była wtedy pomijana w całości,
		// a flaga i tak zapadała.
		if ( ! is_array( $rows ) ) {
			return false;
		}

		$log = Schema::table( Schema::T_QA_LOG );

		$transakcja = is_object( $wpdb ) && method_exists( $wpdb, 'query' );

		if ( $transakcja ) {
			$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB
		}

		try {
			foreach ( $rows as $row ) {
				// Mapowanie: temat generacji → „pytanie" w dzienniku, jako historyczny wpis.
				$wstawiony = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
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

				if ( false === $wstawiony ) {
					if ( $transakcja ) {
						$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB
					}

					return false;
				}
			}
		} catch ( \Throwable $e ) {
			if ( $transakcja ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB
			}

			return false;
		}

		if ( $transakcja ) {
			$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB
		}

		update_option( self::FLAG_HISTORY, 1 );

		return true;
	}
}
