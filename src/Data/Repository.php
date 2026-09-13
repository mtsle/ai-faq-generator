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
	 * Trwały sygnał porażki retencji: `TABLE bez prefiksu => czas ostatniej
	 * nieudanej próby`. Bez autoloadu. Czyta go kokpit (dashboard.php), kasuje
	 * pierwsze udane czyszczenie danej tabeli i odinstalowanie.
	 */
	public const RETENTION_FAILED_OPTION = 'aifaq_retention_failed';

	/**
	 * Zapisuje albo gasi sygnał porażki retencji tej tabeli.
	 *
	 * „Porażka retencji nie wywraca zapisu" nie może znaczyć „porażka retencji
	 * nie zostawia śladu": nieudany DELETE przechowywał dane gości dłużej, niż
	 * obiecuje ustawienie, i nikt się o tym nie dowiadywał.
	 *
	 * @param int|false $wynik Wynik prune(): false = któreś DELETE padło.
	 */
	protected function note_retention( int|false $wynik ): void {
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) || ! function_exists( 'delete_option' ) ) {
			return;
		}

		$stan = get_option( self::RETENTION_FAILED_OPTION, array() );
		$stan = is_array( $stan ) ? $stan : array();

		if ( false === $wynik ) {
			$stan[ static::TABLE ] = function_exists( 'current_time' ) ? (string) current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
			update_option( self::RETENTION_FAILED_OPTION, $stan, false );
			return;
		}

		if ( ! array_key_exists( static::TABLE, $stan ) ) {
			return; // Najczęstsza ścieżka: nic do gaszenia, zero zapisów.
		}

		unset( $stan[ static::TABLE ] );

		if ( array() === $stan ) {
			delete_option( self::RETENTION_FAILED_OPTION );
		} else {
			update_option( self::RETENTION_FAILED_OPTION, $stan, false );
		}
	}

	/**
	 * Aktualne porażki retencji (dla kokpitu): tabela bez prefiksu => czas.
	 *
	 * @return array<string,string>
	 */
	public static function retention_failures(): array {
		if ( ! function_exists( 'get_option' ) ) {
			return array();
		}

		$stan = get_option( self::RETENTION_FAILED_OPTION, array() );

		return is_array( $stan ) ? array_map( 'strval', $stan ) : array();
	}

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

		// Przy INSERT „zero wierszy" też jest porażką, więc false i 0 dają ten sam
		// wynik — ale rozstrzygnięte jawnie, nie przez prawdziwość wartości.
		if ( false === $ok || 0 === (int) $ok ) {
			return 0;
		}

		return (int) $wpdb->insert_id;
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
	 * Usuwa rekord po ID.
	 *
	 * @param int $id Identyfikator rekordu.
	 *
	 * @return int|false Liczba usuniętych wierszy (0 = nie było takiego rekordu);
	 *                   `false` = błąd SQL. Dawniej `(bool)` sklejał oba przypadki.
	 */
	public function delete( int $id ): int|false {
		global $wpdb;
		$wynik = $wpdb->delete( static::table(), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( false === $wynik ) {
			return false;
		}

		return (int) $wynik;
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
