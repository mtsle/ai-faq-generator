<?php
/**
 * Repozytorium gotowych par FAQ pod SEO (JSON-LD/eksport).
 *
 * @package AI_FAQ_Generator
 */

namespace AIFAQ\Data;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dostęp do tabeli wp_aifaq_faq.
 */
class FaqRepository extends Repository {

	/**
	 * Tabela FAQ.
	 */
	protected const TABLE = Schema::T_FAQ;

	/**
	 * Zwraca pary FAQ przypięte do wpisu (uporządkowane), 0 = globalne.
	 *
	 * @param int $post_id ID wpisu (0 dla globalnych).
	 * @return array<int,array<string,mixed>>
	 */
	public function for_post( int $post_id ): array {
		global $wpdb;
		$table = static::table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE post_id = %d ORDER BY position ASC, id ASC", $post_id ), // phpcs:ignore WordPress.DB
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Zapisuje parę FAQ i zwraca jej ID.
	 *
	 * @param array<string,mixed> $pair Dane pary (question/answer wymagane).
	 */
	public function save_pair( array $pair ): int {
		$now = current_time( 'mysql' );
		return $this->insert(
			array(
				'post_id'    => (int) ( $pair['post_id'] ?? 0 ),
				'question'   => (string) ( $pair['question'] ?? '' ),
				'answer'     => (string) ( $pair['answer'] ?? '' ),
				'position'   => (int) ( $pair['position'] ?? 0 ),
				'created_at' => $pair['created_at'] ?? $now,
				'updated_at' => $now,
			)
		);
	}
}
