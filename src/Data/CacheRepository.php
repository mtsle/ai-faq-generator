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
	 * Zapisuje odpowiedź w cache (nadpisuje przy tym samym hashu).
	 *
	 * @param string $question Treść pytania.
	 * @param string $answer   Odpowiedź do zapamiętania.
	 */
	public function put( string $question, string $answer ): int {
		return $this->insert(
			array(
				'question_hash' => self::hash( $question ),
				'question'      => $question,
				'answer'        => $answer,
				'hits'          => 0,
				'created_at'    => current_time( 'mysql' ),
			)
		);
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
