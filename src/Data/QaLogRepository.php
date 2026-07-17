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

	/**
	 * Strona dziennika (najnowsze najpierw), opcjonalnie zawężona do statusu.
	 *
	 * Status jest walidowany względem {@see STATUSES} — do SQL trafia wyłącznie
	 * wartość z tej listy (nigdy surowe wejście).
	 *
	 * @param int    $limit  Rozmiar strony (klampowany 1..100).
	 * @param int    $offset Przesunięcie (>= 0).
	 * @param string $status Filtr statusu ('' = wszystkie).
	 * @return array<int,array<string,mixed>>
	 */
	public function page( int $limit = 20, int $offset = 0, string $status = '' ): array {
		global $wpdb;
		$table  = static::table();
		$limit  = max( 1, min( 100, $limit ) );
		$offset = max( 0, $offset );

		if ( $this->is_status( $status ) ) {
			$sql = $wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = %s ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB
				$status,
				$limit,
				$offset
			);
		} else {
			$sql = $wpdb->prepare(
				"SELECT * FROM {$table} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB
				$limit,
				$offset
			);
		}

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Liczba wpisów, opcjonalnie zawężona do statusu (do paginacji).
	 *
	 * @param string $status Filtr statusu ('' = wszystkie).
	 */
	public function count_by( string $status = '' ): int {
		global $wpdb;
		$table = static::table();

		if ( ! $this->is_status( $status ) ) {
			return $this->count();
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", $status ) // phpcs:ignore WordPress.DB
		);
	}

	/**
	 * Podsumowanie dziennika (kafelki Dashboardu i nagłówek Historii).
	 *
	 * `created_at` zapisujemy czasem lokalnym witryny ({@see current_time()}),
	 * więc granice „dziś"/„7 dni" też liczymy w czasie lokalnym.
	 *
	 * @return array<string,int|float>
	 */
	public function stats(): array {
		global $wpdb;
		$table = static::table();

		$today = gmdate( 'Y-m-d 00:00:00', (int) current_time( 'timestamp' ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		$week  = gmdate( 'Y-m-d H:i:s', (int) current_time( 'timestamp' ) - 7 * DAY_IN_SECONDS ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*) AS total,
					SUM(CASE WHEN created_at >= %s THEN 1 ELSE 0 END) AS today,
					SUM(CASE WHEN created_at >= %s THEN 1 ELSE 0 END) AS week,
					SUM(CASE WHEN status = 'answered' THEN 1 ELSE 0 END) AS answered,
					SUM(CASE WHEN status = 'refused' THEN 1 ELSE 0 END) AS refused,
					SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) AS errors,
					SUM(CASE WHEN source = 'cache' THEN 1 ELSE 0 END) AS cached,
					AVG(CASE WHEN status = 'answered' THEN score ELSE NULL END) AS avg_score
				FROM {$table}", // phpcs:ignore WordPress.DB
				$today,
				$week
			),
			ARRAY_A
		);

		$row = is_array( $row ) ? $row : array();

		return array(
			'total'     => (int) ( $row['total'] ?? 0 ),
			'today'     => (int) ( $row['today'] ?? 0 ),
			'week'      => (int) ( $row['week'] ?? 0 ),
			'answered'  => (int) ( $row['answered'] ?? 0 ),
			'refused'   => (int) ( $row['refused'] ?? 0 ),
			'errors'    => (int) ( $row['errors'] ?? 0 ),
			'cached'    => (int) ( $row['cached'] ?? 0 ),
			'avg_score' => round( (float) ( $row['avg_score'] ?? 0 ), 3 ),
		);
	}

	/**
	 * Kasuje cały dziennik. Zwraca liczbę usuniętych wpisów.
	 *
	 * Dziennik to dane gości — właściciel musi móc je usunąć (RODO), dlatego
	 * czyszczenie jest osobną, jawną operacją (nie skutkiem ubocznym reindeksu).
	 */
	public function purge(): int {
		global $wpdb;
		$table = static::table();
		$done  = $wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB

		return ( false === $done ) ? 0 : (int) $done;
	}

	/**
	 * Czy podana wartość jest dozwolonym statusem (whitelist do SQL).
	 *
	 * @param string $status Kandydat na status.
	 */
	private function is_status( string $status ): bool {
		return in_array( $status, self::STATUSES, true );
	}
}
