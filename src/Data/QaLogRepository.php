<?php
/**
 * Repozytorium dziennika pytań gości (status/źródło/score).
 *
 * @package AI_FAQ_Generator
 */

namespace AIFAQ\Data;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dostęp do tabeli wp_aifaq_qa_log.
 */
class QaLogRepository extends Repository {

	/**
	 * Tabela dziennika Q&A.
	 */
	protected const TABLE = Schema::T_QA_LOG;

	/**
	 * Dozwolone statusy wpisu w dzienniku.
	 */
	const STATUSES = array( 'answered', 'refused', 'error' );

	/**
	 * Zapisuje wpis w dzienniku i zwraca jego ID.
	 *
	 * @param array<string,mixed> $entry Dane wpisu (question wymagane).
	 */
	public function log( array $entry ): int {
		$row = array(
			'created_at' => $entry['created_at'] ?? current_time( 'mysql' ),
			'question'   => (string) ( $entry['question'] ?? '' ),
			'answer'     => $entry['answer'] ?? null,
			'status'     => in_array( $entry['status'] ?? '', self::STATUSES, true ) ? $entry['status'] : 'answered',
			'source'     => (string) ( $entry['source'] ?? 'ai' ),
			'score'      => (float) ( $entry['score'] ?? 0 ),
			'user_id'    => (int) ( $entry['user_id'] ?? 0 ),
			'ip_hash'    => (string) ( $entry['ip_hash'] ?? '' ),
		);
		return $this->insert( $row );
	}

	/**
	 * Ostatnie wpisy dziennika (do widoku Historia).
	 *
	 * @param int $limit Ile wpisów zwrócić.
	 * @return array<int,array<string,mixed>>
	 */
	public function recent( int $limit = 50 ): array {
		global $wpdb;
		$table = static::table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d", $limit ), // phpcs:ignore WordPress.DB
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}
}
