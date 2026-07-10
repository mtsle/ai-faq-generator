<?php
/**
 * Repozytorium cache odpowiedzi (dedup po hashu pytania).
 *
 * @package AI_FAQ_Generator
 */

namespace AIFAQ\Data;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dostęp do tabeli wp_aifaq_cache.
 */
class CacheRepository extends Repository {

	/**
	 * Tabela cache.
	 */
	protected const TABLE = Schema::T_CACHE;

	/**
	 * Zwraca zapisaną odpowiedź dla pytania (po hashu) lub null.
	 *
	 * @param string $question Treść pytania.
	 * @return array<string,mixed>|null
	 */
	public function get_by_question( string $question ): ?array {
		global $wpdb;
		$table = static::table();
		$hash  = self::hash( $question );
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE question_hash = %s", $hash ), // phpcs:ignore WordPress.DB
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Zapisuje odpowiedź w cache; przy tym samym hashu pytania NADPISUJE odpowiedź.
	 *
	 * Kolumna `question_hash` ma `UNIQUE KEY`, więc zwykły INSERT drugi raz padłby
	 * na duplikacie. Używamy `INSERT ... ON DUPLICATE KEY UPDATE` — wpis powstaje
	 * przy pierwszym pytaniu, a kolejne odświeżają odpowiedź (bez błędu, bez wyścigu).
	 *
	 * @param string $question Treść pytania.
	 * @param string $answer   Odpowiedź do zapamiętania.
	 * @return int ID wiersza (0, gdy nie udało się ustalić).
	 */
	public function put( string $question, string $answer ): int {
		global $wpdb;
		$table = static::table();

		$wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"INSERT INTO {$table} (question_hash, question, answer, hits, created_at)
				 VALUES (%s, %s, %s, 0, %s)
				 ON DUPLICATE KEY UPDATE answer = VALUES(answer), created_at = VALUES(created_at)",
				self::hash( $question ),
				$question,
				$answer,
				current_time( 'mysql' )
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Znormalizowany hash pytania (klucz deduplikacji).
	 *
	 * @param string $question Treść pytania.
	 */
	public static function hash( string $question ): string {
		// mbstring bywa niedostępne u części hostingów — bezpieczny fallback.
		$lower      = function_exists( 'mb_strtolower' ) ? mb_strtolower( $question ) : strtolower( $question );
		$normalized = trim( (string) preg_replace( '/\s+/u', ' ', $lower ) );
		return hash( 'sha256', $normalized );
	}
}
