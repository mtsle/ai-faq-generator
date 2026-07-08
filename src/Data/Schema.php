<?php
/**
 * Definicja schematu bazy (4 tabele) — schema v2.
 *
 * Tworzy/aktualizuje tabele przez dbDelta():
 *  - wp_aifaq_knowledge — fragmenty treści strony + ich embeddingi (RAG),
 *  - wp_aifaq_qa_log    — dziennik pytań gości (status/źródło/score),
 *  - wp_aifaq_cache     — cache odpowiedzi (dedup po hashu pytania),
 *  - wp_aifaq_faq       — gotowe pary FAQ pod SEO (JSON-LD/eksport).
 *
 * dbDelta jest wybredne: typy małymi literami, każde pole w osobnej linii,
 * DWIE spacje po „PRIMARY KEY".
 *
 * @package AI_FAQ_Generator
 */

namespace AIFAQ\Data;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Instalator/aktualizator struktury bazy.
 */
class Schema {

	/**
	 * Nazwy tabel bez prefiksu.
	 */
	const T_KNOWLEDGE = 'aifaq_knowledge';
	const T_QA_LOG    = 'aifaq_qa_log';
	const T_CACHE     = 'aifaq_cache';
	const T_FAQ       = 'aifaq_faq';

	/**
	 * Pełna nazwa tabeli z prefiksem bazy.
	 *
	 * @param string $table Nazwa tabeli bez prefiksu (stała T_*).
	 */
	public static function table( string $table ): string {
		global $wpdb;
		return $wpdb->prefix . $table;
	}

	/**
	 * Tworzy/aktualizuje wszystkie tabele.
	 */
	public static function install(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( self::statements( $charset_collate ) as $sql ) {
			dbDelta( $sql );
		}
	}

	/**
	 * Zestaw instrukcji CREATE TABLE (po jednej na tabelę).
	 *
	 * @param string $charset_collate Kodowanie/porządkowanie z $wpdb.
	 * @return array<int,string>
	 */
	private static function statements( string $charset_collate ): array {
		$knowledge = self::table( self::T_KNOWLEDGE );
		$qa_log    = self::table( self::T_QA_LOG );
		$cache     = self::table( self::T_CACHE );
		$faq       = self::table( self::T_FAQ );

		$out = array();

		// Fragmenty treści + embeddingi (RAG).
		$out[] = "CREATE TABLE {$knowledge} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			chunk_index smallint(5) unsigned NOT NULL DEFAULT 0,
			content longtext NOT NULL,
			content_hash char(64) NOT NULL DEFAULT '',
			embedding longtext NULL,
			tokens smallint(5) unsigned NOT NULL DEFAULT 0,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY post_id (post_id),
			KEY content_hash (content_hash)
		) {$charset_collate};";

		// Dziennik pytań gości.
		$out[] = "CREATE TABLE {$qa_log} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			question text NOT NULL,
			answer longtext NULL,
			status varchar(20) NOT NULL DEFAULT 'answered',
			source varchar(20) NOT NULL DEFAULT 'ai',
			score float NOT NULL DEFAULT 0,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			ip_hash char(64) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY status (status)
		) {$charset_collate};";

		// Cache odpowiedzi (dedup po hashu pytania).
		$out[] = "CREATE TABLE {$cache} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			question_hash char(64) NOT NULL DEFAULT '',
			question text NOT NULL,
			answer longtext NOT NULL,
			hits int(10) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY question_hash (question_hash)
		) {$charset_collate};";

		// Gotowe pary FAQ pod SEO.
		$out[] = "CREATE TABLE {$faq} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			question text NOT NULL,
			answer longtext NOT NULL,
			position smallint(5) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY post_id (post_id)
		) {$charset_collate};";

		return $out;
	}
}
