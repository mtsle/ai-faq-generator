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
 *
 * Warstwa danych pod Indexer (Krok 5) i Retriever (Krok 6): zapis fragmentów
 * treści wraz z embeddingami, dedup po `content_hash` oraz odczyt do liczenia
 * podobieństwa. Klasa dostarcza wyłącznie PRYMITYWY danych — o strategii
 * indeksowania (pełna przebudowa vs. przyrostowa) decyduje Indexer, nie repo.
 */
class KnowledgeRepository extends Repository {

	/**
	 * Tabela fragmentów wiedzy.
	 */
	protected const TABLE = Schema::T_KNOWLEDGE;

	// -----------------------------------------------------------------------
	// Zapis (używane przez Indexer, Krok 5)
	// -----------------------------------------------------------------------

	/**
	 * Zapisuje pojedynczy fragment wiedzy i zwraca jego ID (0 przy błędzie).
	 *
	 * Sam koduje wektor embeddingu do JSON, uzupełnia `content_hash` (jeśli nie
	 * podano) oraz znacznik czasu. `embedding` może być `null` (fragment jeszcze
	 * niezwektoryzowany) albo tablicą liczb (zostanie zapisana jako JSON).
	 *
	 * @param array<string,mixed> $chunk Dane fragmentu. Klucze:
	 *                                    - `post_id`      (int)                     — wymagane,
	 *                                    - `chunk_index`  (int)                     — kolejność w obrębie wpisu,
	 *                                    - `content`      (string)                  — treść fragmentu (wymagane),
	 *                                    - `content_hash` (string)                  — opcjonalnie; domyślnie z {@see hash()},
	 *                                    - `embedding`    (array<int,float>|null)   — wektor lub `null`,
	 *                                    - `tokens`       (int)                     — szacowana liczba tokenów.
	 * @return int ID wstawionego wiersza lub 0 przy błędzie.
	 */
	public function save_chunk( array $chunk ): int {
		$content   = (string) ( $chunk['content'] ?? '' );
		$embedding = $chunk['embedding'] ?? null;

		return $this->insert(
			array(
				'post_id'      => (int) ( $chunk['post_id'] ?? 0 ),
				'chunk_index'  => (int) ( $chunk['chunk_index'] ?? 0 ),
				'content'      => $content,
				'content_hash' => (string) ( $chunk['content_hash'] ?? self::hash( $content ) ),
				// Pusta tablica ≠ embedding — zapisujemy NULL, nie „[]" (inaczej Retriever
				// policzyłby normę 0 i dzielił przez zero). Wektor musi mieć wymiary.
				'embedding'    => ( is_array( $embedding ) && array() !== $embedding ) ? self::encode_embedding( $embedding ) : null,
				'tokens'       => (int) ( $chunk['tokens'] ?? 0 ),
				'updated_at'   => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Podmienia WSZYSTKIE fragmenty danego wpisu (najpierw kasuje, potem wstawia).
	 *
	 * Prymityw „pełnej przebudowy per wpis". Brakujący `chunk_index` w elemencie
	 * jest uzupełniany kolejnością na liście. `post_id` z argumentu nadpisuje ten
	 * z pojedynczych fragmentów (spójność).
	 *
	 * Operacja jest atomowa (transakcja): przy błędzie zapisu któregokolwiek
	 * fragmentu całość jest wycofywana (ROLLBACK), więc wpis nigdy nie zostaje
	 * z niekompletnym zestawem fragmentów. Na silnikach bez transakcji (MyISAM)
	 * `START TRANSACTION` jest cichym no-opem — zachowanie degraduje się do
	 * nieatomowego, bez błędu.
	 *
	 * @param int                             $post_id ID wpisu źródłowego.
	 * @param array<int,array<string,mixed>>  $chunks  Lista fragmentów (jak w {@see save_chunk()}).
	 * @return int Liczba faktycznie wstawionych fragmentów (0 przy wycofaniu).
	 */
	public function replace_for_post( int $post_id, array $chunks ): int {
		global $wpdb;

		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB

		// try/finally: wyjątek między START a COMMIT/ROLLBACK NIE może zostawić
		// otwartej transakcji (zablokowane wiersze do końca requestu).
		try {
			$this->delete_by_post( $post_id );

			$inserted = 0;
			$index    = 0;
			$failed   = false;

			foreach ( $chunks as $chunk ) {
				$chunk['post_id']     = $post_id;
				$chunk['chunk_index'] = $chunk['chunk_index'] ?? $index;

				if ( $this->save_chunk( $chunk ) > 0 ) {
					++$inserted;
				} else {
					$failed = true;
					break;
				}

				++$index;
			}

			if ( $failed ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB
				return 0;
			}

			$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB
			return $inserted;
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB
			return 0;
		}
	}

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
	 * Czyści całą bazę wiedzy (twardy reset przed pełnym re-indeksowaniem).
	 *
	 * @return int Liczba usuniętych fragmentów.
	 */
	public function clear_all(): int {
		global $wpdb;
		$table = static::table();
		return (int) $wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Usuwa fragmenty wpisów spoza podanej listy (pruning osieroconych).
	 *
	 * Po indeksowaniu wpis, który zniknął ze źródła (usunięty, odpublikowany,
	 * przeniesiony do kosza), musi też zniknąć z bazy wiedzy — inaczej Retriever
	 * dalej serwowałby treść, której właściciel nie publikuje.
	 *
	 * BEZPIECZEŃSTWO: pusta lista `keep` = NO-OP (return 0), NIE czyszczenie całości.
	 * Pusty wynik źródła to prawie zawsze bug/wyścig/filtr wtyczki, a nie świadoma
	 * decyzja „skasuj wszystko" — pełny reset musi iść jawnie przez {@see clear_all()}.
	 *
	 * @param array<int,int> $keep_post_ids ID wpisów, które MAJĄ zostać.
	 * @return int Liczba usuniętych fragmentów.
	 */
	public function delete_missing( array $keep_post_ids ): int {
		global $wpdb;

		if ( array() === $keep_post_ids ) {
			return 0; // NIE kasujemy całej bazy na pustym wejściu (ochrona przed wipe).
		}

		$ids          = array_values( array_unique( array_map( 'intval', $keep_post_ids ) ) );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$table        = static::table();

		return (int) $wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare( "DELETE FROM {$table} WHERE post_id NOT IN ({$placeholders})", ...$ids ) // phpcs:ignore WordPress.DB
		);
	}

	// -----------------------------------------------------------------------
	// Odczyt (dedup dla Indexera, podobieństwo dla Retrievera)
	// -----------------------------------------------------------------------

	/**
	 * Zwraca hashe fragmentów danego wpisu w mapie `chunk_index => content_hash`.
	 *
	 * Pozwala Indexerowi pominąć ponowne embedowanie fragmentów, które się nie
	 * zmieniły (opcjonalny tryb przyrostowy — decyzja należy do Kroku 5).
	 *
	 * @param int $post_id ID wpisu źródłowego.
	 * @return array<int,string> Mapa chunk_index => content_hash.
	 */
	public function hashes_for_post( int $post_id ): array {
		global $wpdb;
		$table = static::table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT chunk_index, content_hash FROM {$table} WHERE post_id = %d", $post_id ), // phpcs:ignore WordPress.DB
			ARRAY_A
		);

		$map = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$map[ (int) $row['chunk_index'] ] = (string) $row['content_hash'];
			}
		}
		return $map;
	}

	/**
	 * Zwraca wszystkie fragmenty z embeddingami (do liczenia podobieństwa).
	 *
	 * NIE pobiera kolumny `content` (longtext) — do samego scoringu potrzebny jest
	 * wyłącznie wektor. Treść top-K fragmentów Retriever dociąga osobno przez
	 * {@see contents_for()}. Uwaga (dług F4): metoda ładuje CAŁĄ bazę naraz —
	 * przy dużej witrynie użyj stronicowania {@see embeddings_page()} zamiast tej.
	 *
	 * @return array<int,array<string,mixed>> Wiersze `id, post_id, embedding` (wektor zdekodowany).
	 */
	public function all_with_embeddings(): array {
		global $wpdb;
		$table = static::table();
		$rows  = $wpdb->get_results( "SELECT id, post_id, embedding FROM {$table} WHERE embedding IS NOT NULL", ARRAY_A ); // phpcs:ignore WordPress.DB

		if ( ! is_array( $rows ) ) {
			return array();
		}

		foreach ( $rows as &$row ) {
			$row['embedding'] = self::decode_embedding( $row['embedding'] ?? null );
		}
		unset( $row );

		return $rows;
	}

	/**
	 * Liczba fragmentów z policzonym embeddingiem (do stronicowania scoringu).
	 *
	 * @return int
	 */
	public function count_embedded(): int {
		global $wpdb;
		$table = static::table();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE embedding IS NOT NULL" ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Jedna strona fragmentów z embeddingami (bez `content`) — do scoringu wsadowego.
	 *
	 * Pozwala Retrieverowi (Krok 6) przejść bazę partiami i utrzymać w pamięci
	 * tylko bieżącą stronę + top-K, zamiast ładować wszystko naraz (dług F4).
	 *
	 * @param int $limit  Rozmiar strony (>0).
	 * @param int $offset Przesunięcie (>=0).
	 * @return array<int,array<string,mixed>> Wiersze `id, post_id, embedding` (wektor zdekodowany).
	 */
	public function embeddings_page( int $limit, int $offset ): array {
		global $wpdb;
		$table = static::table();
		$limit  = max( 1, $limit );
		$offset = max( 0, $offset );
		$rows   = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, post_id, embedding FROM {$table} WHERE embedding IS NOT NULL ORDER BY id LIMIT %d OFFSET %d", $limit, $offset ), // phpcs:ignore WordPress.DB
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		foreach ( $rows as &$row ) {
			$row['embedding'] = self::decode_embedding( $row['embedding'] ?? null );
		}
		unset( $row );

		return $rows;
	}

	/**
	 * Dociąga treść wskazanych fragmentów (mapa id => content) — tylko dla top-K.
	 *
	 * @param array<int,int> $ids ID fragmentów.
	 * @return array<int,string> Mapa id => content.
	 */
	public function contents_for( array $ids ): array {
		global $wpdb;

		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		if ( array() === $ids ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$table        = static::table();
		$rows         = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, content FROM {$table} WHERE id IN ({$placeholders})", ...$ids ), // phpcs:ignore WordPress.DB
			ARRAY_A
		);

		$map = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$map[ (int) $row['id'] ] = (string) $row['content'];
			}
		}
		return $map;
	}

	/**
	 * Statystyki bazy wiedzy (do raportu Indexera i dashboardu).
	 *
	 * @return array{chunks:int,posts:int,embedded:int}
	 *         `chunks` — wszystkie fragmenty, `posts` — liczba różnych wpisów,
	 *         `embedded` — fragmenty z policzonym embeddingiem.
	 */
	public function stats(): array {
		global $wpdb;
		$table = static::table();
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB
			"SELECT COUNT(*) AS chunks,
			        COUNT(DISTINCT post_id) AS posts,
			        SUM(CASE WHEN embedding IS NOT NULL THEN 1 ELSE 0 END) AS embedded
			 FROM {$table}",
			ARRAY_A
		);

		return array(
			'chunks'   => (int) ( $row['chunks'] ?? 0 ),
			'posts'    => (int) ( $row['posts'] ?? 0 ),
			'embedded' => (int) ( $row['embedded'] ?? 0 ),
		);
	}

	// -----------------------------------------------------------------------
	// Kodowanie wektora i hash treści (czyste, testowalne bez bazy)
	// -----------------------------------------------------------------------

	/**
	 * Koduje wektor embeddingu do łańcucha JSON (do kolumny `embedding`).
	 *
	 * @param array<int,float> $vector Wektor liczb zmiennoprzecinkowych.
	 * @return string JSON, np. „[0.1,0.2,...]".
	 */
	public static function encode_embedding( array $vector ): string {
		return (string) wp_json_encode( array_values( array_map( 'floatval', $vector ) ) );
	}

	/**
	 * Dekoduje wektor embeddingu z JSON do tablicy liczb.
	 *
	 * @param string|null $json Zapisany JSON lub `null`.
	 * @return array<int,float>|null Tablica liczb albo `null`, gdy brak/niepoprawny.
	 */
	public static function decode_embedding( ?string $json ): ?array {
		if ( null === $json || '' === $json ) {
			return null;
		}
		$decoded = json_decode( $json, true );
		return is_array( $decoded ) ? array_map( 'floatval', $decoded ) : null;
	}

	/**
	 * Hash treści fragmentu (klucz deduplikacji — wykrywa zmianę treści).
	 *
	 * @param string $content Treść fragmentu.
	 * @return string sha256 (64 znaki hex).
	 */
	public static function hash( string $content ): string {
		return hash( 'sha256', trim( $content ) );
	}
}
