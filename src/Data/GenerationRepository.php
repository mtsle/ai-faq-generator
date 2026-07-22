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
		global $wpdb;

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

		$id = $this->insert( $row );

		// K20 §6.2 — retencja przy zapisie. Wyzwalacz stoi TUTAJ, bo log() ma
		// dokładnie jednego wywołującego (RestController), więc sprzątanie jest
		// deterministyczne: zero crona (wp-cron na małym ruchu klienta nie odpala
		// się tygodniami) i zero trzech miejsc, które musiałyby o tym pamiętać.
		//
		// Bramki są obowiązkowe: `krok11-generations-repo-test.php` nie ładuje
		// Settings, a jego atrapa $wpdb nie ma metody query() — bez nich byłby
		// PHP Fatal w cudzym pliku (zero asercji zamiast jednego FAIL-a).
		// Porażka retencji NIE MA PRAWA wywrócić log() ani zmienić jego wyniku.
		if ( $id > 0 ) {
			try {
				if ( class_exists( '\AIFAQ\Core\Settings' ) && is_object( $wpdb ) && method_exists( $wpdb, 'query' ) ) {
					$rows = (int) \AIFAQ\Core\Settings::get_field( 'generations_keep_rows', 0 );
					$days = (int) \AIFAQ\Core\Settings::get_field( 'generations_keep_days', 0 );
					if ( $rows > 0 || $days > 0 ) {
						$this->prune( $rows, $days );
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		return $id;
	}

	/**
	 * Kasuje stare/nadmiarowe wiersze historii. Zwraca liczbę usuniętych.
	 *
	 * Dwa wymiary działają NIEZALEŻNIE (OR, §13.17): wiersz ginie, gdy jest
	 * starszy niż `$keep_days` ALBO wypada poza `$keep_rows` najnowszych.
	 * `0` w danym wymiarze wyłącza ten wymiar; oba `0` → ZERO zapytań do bazy
	 * (retencja jest opt-in — domyślnie nic nie kasujemy, bo klient może zbierać
	 * historię miesiącami i skasowanie jej jest nieodwracalne).
	 *
	 * Granicę wieku liczymy od `current_time( 'mysql' )`, a nie od `time()`/UTC —
	 * `created_at` zapisywane jest tym samym zegarem ({@see log()}), więc UTC
	 * przesunąłby granicę o offset strefy i skasował za dużo albo za mało.
	 *
	 * @param int $keep_rows Ile najnowszych wierszy zachować (0 = bez limitu).
	 * @param int $keep_days Ile dni historii zachować (0 = bez limitu).
	 */
	public function prune( int $keep_rows, int $keep_days ): int {
		$keep_rows = max( 0, $keep_rows );
		$keep_days = max( 0, $keep_days );

		if ( 0 === $keep_rows && 0 === $keep_days ) {
			return 0;
		}

		global $wpdb;
		$table   = static::table();
		$deleted = 0;

		// 1) Wiek — po indeksowanej kolumnie created_at (KEY created_at w Schema).
		if ( $keep_days > 0 ) {
			$now = strtotime( (string) current_time( 'mysql' ) );
			if ( $now > 0 ) {
				// 86400 zamiast DAY_IN_SECONDS — repozytorium bywa ładowane w czystym CLI.
				// date() (nie gmdate()) domyka round-trip ze strtotime w tej samej strefie.
				$cutoff = date( 'Y-m-d H:i:s', $now - ( $keep_days * 86400 ) ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions

				$n = $wpdb->query(
					$wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) // phpcs:ignore WordPress.DB
				);
				$deleted += max( 0, (int) $n );
			}
		}

		// 2) Liczba wierszy — „trzymaj N najnowszych" po kluczu głównym.
		//    Najpierw ustalamy ID granicznego wiersza (N+1 od końca), potem kasujemy
		//    wszystko poniżej: MySQL nie pozwala na DELETE z podzapytaniem do tej
		//    samej tabeli, a dwa zapytania z WHERE są czytelniejsze niż sztuczka
		//    z tabelą pochodną.
		if ( $keep_rows > 0 ) {
			$boundary = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT id FROM {$table} ORDER BY id DESC LIMIT 1 OFFSET %d", $keep_rows ) // phpcs:ignore WordPress.DB
			);

			if ( $boundary > 0 ) {
				$n = $wpdb->query(
					$wpdb->prepare( "DELETE FROM {$table} WHERE id <= %d", $boundary ) // phpcs:ignore WordPress.DB
				);
				$deleted += max( 0, (int) $n );
			}
		}

		return $deleted;
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
