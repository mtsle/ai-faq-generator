<?php
/**
 * Bazowe repozytorium — cienka warstwa nad $wpdb wspólna dla tabel wtyczki.
 *
 * Każde repozytorium podaje swoją tabelę (stała TABLE ze Schema) i dostaje
 * gotowe operacje insert/find/delete/count. Zapytania idą przez $wpdb
 * z prepare, więc podklasy nie dublują boilerplate.
 *
 * @package AI_FAQ_Generator
 */

namespace AIFAQ\Data;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wspólna baza dla repozytoriów danych wtyczki.
 */
abstract class Repository {

	/**
	 * Nazwa tabeli bez prefiksu (stała Schema::T_*). Ustala podklasa.
	 */
	protected const TABLE = '';

	/**
	 * Pełna nazwa tabeli z prefiksem bazy.
	 */
	public static function table(): string {
		return Schema::table( static::TABLE );
	}

	/**
	 * Wstawia rekord. Zwraca ID nowego wiersza lub 0 przy błędzie.
	 *
	 * @param array<string,mixed> $data Kolumna => wartość.
	 */
	public function insert( array $data ): int {
		global $wpdb;
		$ok = $wpdb->insert( static::table(), $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Pobiera pojedynczy rekord po ID (jako tablica asocjacyjna) lub null.
	 *
	 * @param int $id Identyfikator rekordu.
	 * @return array<string,mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;
		$table = static::table();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), // phpcs:ignore WordPress.DB
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Usuwa rekord po ID. Zwraca true, gdy coś usunięto.
	 *
	 * @param int $id Identyfikator rekordu.
	 */
	public function delete( int $id ): bool {
		global $wpdb;
		return (bool) $wpdb->delete( static::table(), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Liczba rekordów w tabeli.
	 */
	public function count(): int {
		global $wpdb;
		$table = static::table();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB
	}
}
