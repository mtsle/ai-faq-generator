<?php
/**
 * Repozytorium bazy wiedzy — fragmenty treści strony + embeddingi (RAG).
 *
 * @package AI_FAQ_Generator
 */

namespace AIFAQ\Data;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dostęp do tabeli wp_aifaq_knowledge.
 */
class KnowledgeRepository extends Repository {

	/**
	 * Tabela fragmentów wiedzy.
	 */
	protected const TABLE = Schema::T_KNOWLEDGE;

	/**
	 * Usuwa wszystkie fragmenty danego wpisu (przed ponownym indeksowaniem).
	 *
	 * @param int $post_id ID wpisu źródłowego.
	 * @return int Liczba usuniętych fragmentów.
	 */
	public function delete_by_post( int $post_id ): int {
		global $wpdb;
		return (int) $wpdb->delete( static::table(), array( 'post_id' => $post_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Zwraca wszystkie fragmenty z embeddingami (do liczenia podobieństwa).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function all_with_embeddings(): array {
		global $wpdb;
		$table = static::table();
		$rows  = $wpdb->get_results( "SELECT id, post_id, content, embedding FROM {$table} WHERE embedding IS NOT NULL", ARRAY_A ); // phpcs:ignore WordPress.DB
		return is_array( $rows ) ? $rows : array();
	}
}
