<?php
/**
 * Repozytorium historii generowań FAQ (kokpit).
 *
 * Każdy wiersz to jedno uruchomienie generatora: wejście właściciela
 * (temat/opis/liczba/język/użytkownik) + snapshot wygenerowanych par Q&A
 * zapisany jako JSON w kolumnie `pairs_json`. Snapshot jest źródłem dla widoku
 * historii, podglądu i akcji „Ponownie wygeneruj" — dlatego pary trzymamy tutaj,
 * a nie w uśpionej tabeli `wp_aifaq_faq`.
 *
 * @package AI_FAQ_Generator
 */

namespace AIFAQ\Data;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dostęp do tabeli wp_aifaq_generations.
 */
class GenerationRepository extends Repository {

	/**
	 * Tabela historii generowań.
	 */
	protected const TABLE = Schema::T_GENERATIONS;

	/**
	 * Zapisuje jedno generowanie i zwraca ID wiersza (0 przy błędzie).
	 *
	 * Pary Q&A przyjmujemy jako tablicę (`pairs`) i serializujemy do `pairs_json`.
	 * Kolumny liczbowe/tekstowe rzutujemy defensywnie — do bazy nie trafia nic
	 * poza jawnie wypisanymi polami.
	 *
	 * @param array<string,mixed> $entry Dane generowania (`topic` wymagane; `pairs` = lista par).
	 */
	public function log( array $entry ): int {
		$pairs = isset( $entry['pairs'] ) && is_array( $entry['pairs'] ) ? array_values( $entry['pairs'] ) : array();

		$row = array(
			'created_at'    => $entry['created_at'] ?? current_time( 'mysql' ),
			'topic'         => (string) ( $entry['topic'] ?? '' ),
			'extra_desc'    => isset( $entry['extra_desc'] ) ? (string) $entry['extra_desc'] : null,
			'num_questions' => (int) ( $entry['num_questions'] ?? count( $pairs ) ),
			'language'      => (string) ( $entry['language'] ?? 'pl' ),
			'user_id'       => (int) ( $entry['user_id'] ?? 0 ),
			'pairs_json'    => wp_json_encode( $pairs ),
		);

		return $this->insert( $row );
	}

	/**
	 * Strona historii (najnowsze najpierw) — do listy w widoku „Historia generowań".
	 *
	 * Zwraca surowe wiersze (bez dekodowania `pairs_json`) — lista pokazuje tylko
	 * metadane; pary rozkodowuje {@see find()} dopiero przy podglądzie/ponownym
	 * generowaniu, żeby nie parsować JSON-a dla każdego wiersza listy.
	 *
	 * @param int $limit  Rozmiar strony (klampowany 1..100).
	 * @param int $offset Przesunięcie (>= 0).
	 * @return array<int,array<string,mixed>>
	 */
	public function page( int $limit = 20, int $offset = 0 ): array {
		global $wpdb;
		$table  = static::table();
		$limit  = max( 1, min( 100, $limit ) );
		$offset = max( 0, $offset );

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", $limit, $offset ), // phpcs:ignore WordPress.DB
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Pobiera generowanie po ID z rozkodowanym snapshotem par.
	 *
	 * Nadpisuje {@see Repository::find()}: do zwróconego wiersza dokłada klucz
	 * `pairs` (tablica par z `pairs_json`) — wygodne dla podglądu i „Ponownie
	 * wygeneruj". Surowy `pairs_json` zostaje bez zmian.
	 *
	 * @param int $id Identyfikator wiersza.
	 * @return array<string,mixed>|null
	 */
	public function find( int $id ): ?array {
		$row = parent::find( $id );
		if ( null === $row ) {
			return null;
		}

		$decoded       = json_decode( (string) ( $row['pairs_json'] ?? '' ), true );
		$row['pairs']  = is_array( $decoded ) ? $decoded : array();

		return $row;
	}
}
