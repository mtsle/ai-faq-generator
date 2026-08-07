<?php
/**
 * Publikacja artykulu: jeden wpis, jedna kategoria, zero duplikatow.
 *
 * ETAP 4.5. Tresc kosztowala jedno z 20 wywolan na dobe, wiec kazda decyzja
 * w tym pliku jest podporzadkowana jednej zasadzie: NIE WYRZUCAC tego, co juz
 * kupione, i jednoczesnie nie wypuszczac na portal wpisu, ktory jest polowiczny.
 *
 * PIEC MIEJSC, W KTORYCH TO SIE PSUJE PO CICHU:
 *
 *   1. `wp_insert_post()` BEZ drugiego argumentu zwraca `0`, a nie `WP_Error`.
 *      Kod, ktory sprawdza `is_wp_error()`, przepuszcza wtedy porazke jako
 *      sukces i pozycja konczy jako `done` bez wpisu.
 *   2. `tax_input` wymaga uprawnienia `assign_terms`. Z crona nie ma zadnego
 *      uzytkownika, wiec terminy NIE zostaja przypisane — i to bez bledu.
 *      Dlatego kategoria idzie przez `wp_set_object_terms()` PO insercie.
 *   3. `wp_set_object_terms()` TEZ zwraca `WP_Error`. Bez obslugi tego
 *      przypadku na portalu laduje artykul bez kategorii, czyli poza
 *      wszystkimi filtrami i archiwami. Wtedy wpis NIE jest kasowany, tylko
 *      przestawiany na `draft`: tresc zostaje, ale nie jest publiczna.
 *   4. Sprawdzenie duplikatu MUSI pytac o `post_status => 'any'`. Zapytanie
 *      domyslne widzi same `publish`, wiec wpis przestawiony na `draft`
 *      w punkcie 3 bylby przy ponowieniu utworzony DRUGI raz.
 *   5. Artykul demo kasujemy tylko wtedy, gdy nikt go nie tknal
 *      (`post_modified === post_date`). To jedyny scenariusz, w ktorym ta
 *      automatyka mogla by zniszczyc czyjas prace.
 *
 * OKNO MIEDZY WPISEM A KATEGORIA. Wpis powstaje od razu w statusie docelowym,
 * a kategoria dochodzi zaraz po nim — miedzy jednym a drugim jest ulamek
 * sekundy, w ktorym artykul jest publiczny i bezkategoryjny. Kolejnosc jest
 * taka, bo tak stoi w planie i tak opisuje ja odbior; alternatywa (wstaw jako
 * szkic, przypisz, dopiero podnies do `publish`) usuwa to okno kosztem
 * drugiego zapisu i innego przebiegu odbioru. Do rozwazenia przy audycie Kroku.
 *
 * @package AI_News_Portal
 */

namespace AINP;

// Blokada bezposredniego wywolania pliku.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Zapis artykulu w CPT.
 */
final class Publisher {

	/** Powod: pozycja bez adresu zrodla. */
	public const NOTE_NO_SOURCE = 'Pozycja nie ma adresu źródła — link do źródła jest obowiązkowy';

	/**
	 * Publikuje jeden artykul z danych, ktore przeszly przez `Validator`.
	 *
	 * @param object               $row  Wiersz tabeli (`id`, `url`).
	 * @param array<string,string> $data Pola po walidacji.
	 *
	 * @return array{ok:bool,post_id:int,created:bool,status:string,error:string,reason:string,demo_removed:int}
	 */
	public static function publish( $row, array $data ): array {
		$item_id = isset( $row->id ) ? (int) $row->id : 0;
		$zrodlo  = isset( $row->url ) ? trim( (string) $row->url ) : '';

		if ( $item_id <= 0 ) {
			return self::result( false, 0, false, '', 'Pozycja bez identyfikatora', 'bad_item' );
		}

		/*
		 * Link do zrodla jest obowiazkowy i nieusuwalny — artykul bez niego
		 * nie ma prawa powstac. To jedyna rzecz, ktora oddajemy autorowi
		 * materialu, wiec brak adresu jest bledem pozycji, a nie drobiazgiem
		 * do pominiecia.
		 */
		if ( '' === $zrodlo ) {
			return self::result( false, 0, false, '', self::NOTE_NO_SOURCE, 'no_source' );
		}

		// Idempotencja PRZED zapisem. `post_status => 'any'` obejmuje takze
		// szkice — w tym te przestawione po nieudanym przypisaniu kategorii.
		$istniejacy = self::existing_post( $item_id );
		if ( $istniejacy > 0 ) {
			return self::result( true, $istniejacy, false, (string) get_post_status( $istniejacy ), '', 'exists' );
		}

		$status = ( true === Settings::get( 'save_as_draft', false ) ) ? 'draft' : 'publish';

		// Drugi argument `true` jest OBOWIAZKOWY: bez niego funkcja zwraca `0`
		// zamiast `WP_Error` i porazka wyglada jak sukces.
		$post_id = wp_insert_post(
			array(
				'post_type'    => Plugin::CPT,
				'post_status'  => $status,
				'post_author'  => self::author(),
				'post_title'   => (string) ( $data['title'] ?? '' ),
				'post_excerpt' => (string) ( $data['lead'] ?? '' ),
				'post_content' => (string) ( $data['content'] ?? '' ),
				'meta_input'   => array(
					Plugin::META_ITEM   => $item_id,
					Plugin::META_SOURCE => $zrodlo,
				),
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return self::result( false, 0, false, '', $post_id->get_error_message(), 'insert' );
		}

		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return self::result( false, 0, false, '', 'WordPress nie utworzył wpisu', 'insert' );
		}

		$blad = self::assign_topic( $post_id, (string) ( $data['topic'] ?? '' ) );

		if ( '' !== $blad ) {
			/*
			 * Wpisu NIE kasujemy — tresc kosztowala jedno wywolanie z dobowej
			 * puli. Przestawiamy go na szkic: zostaje w kokpicie do reki
			 * czlowieka, ale nie jest publiczny. Idempotencja sie nie psuje,
			 * bo `existing_post()` pyta o `any`.
			 */
			wp_update_post(
				array(
					'ID'          => $post_id,
					'post_status' => 'draft',
				),
				true
			);

			return self::result( false, $post_id, true, 'draft', $blad, 'terms' );
		}

		// Demo znika dopiero po UDANEJ publikacji, razem z kategoria.
		$usuniete = self::remove_demo( $post_id );

		return self::result( true, $post_id, true, $status, '', '', $usuniete );
	}

	/**
	 * Identyfikator wpisu utworzonego juz dla tej pozycji.
	 *
	 * @param int $item_id Identyfikator pozycji w tabeli wtyczki.
	 *
	 * @return int Zero, gdy wpisu nie ma.
	 */
	public static function existing_post( int $item_id ): int {
		$znalezione = get_posts(
			array(
				'post_type'      => Plugin::CPT,
				// Bez tego zapytanie widzi same `publish` i szkic po nieudanym
				// przypisaniu kategorii doczekalby sie DRUGIEGO wpisu.
				'post_status'    => 'any',
				'meta_key'       => Plugin::META_ITEM, // phpcs:ignore WordPress.DB.SlowDBQuery -- meta jest indeksowana.
				'meta_value'     => (string) $item_id, // phpcs:ignore WordPress.DB.SlowDBQuery
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		if ( ! is_array( $znalezione ) || array() === $znalezione ) {
			return 0;
		}

		return (int) $znalezione[0];
	}

	/**
	 * Przypisuje kategorie, zakladajac termin, gdy go jeszcze nie ma.
	 *
	 * @param int    $post_id Wpis.
	 * @param string $topic   Nazwa kategorii (juz po `Validator`).
	 *
	 * @return string Pusty lancuch przy powodzeniu, inaczej powod slowami.
	 */
	private static function assign_topic( int $post_id, string $topic ): string {
		$topic = trim( $topic );

		if ( '' === $topic ) {
			return 'Brak kategorii do przypisania';
		}

		$term = term_exists( $topic, Plugin::TAX );

		if ( ! $term ) {
			$term = wp_insert_term( $topic, Plugin::TAX );
		}

		if ( is_wp_error( $term ) ) {
			return 'Nie udało się utworzyć kategorii „' . $topic . '”: ' . $term->get_error_message();
		}

		$term_id = 0;
		if ( is_array( $term ) && isset( $term['term_id'] ) ) {
			$term_id = (int) $term['term_id'];
		} elseif ( is_numeric( $term ) ) {
			$term_id = (int) $term;
		}

		if ( $term_id <= 0 ) {
			return 'Kategoria „' . $topic . '” nie ma identyfikatora';
		}

		// Identyfikatory, nie nazwy: taksonomia nie jest hierarchiczna po
		// nazwie, a przekazanie lancucha potrafi zalozyc drugi termin.
		$wynik = wp_set_object_terms( $post_id, array( $term_id ), Plugin::TAX );

		if ( is_wp_error( $wynik ) ) {
			return 'Nie udało się przypisać kategorii „' . $topic . '”: ' . $wynik->get_error_message();
		}

		return '';
	}

	/**
	 * Kasuje artykul demo po pierwszej prawdziwej publikacji.
	 *
	 * Warunek `post_modified === post_date` zamyka jedyny scenariusz, w ktorym
	 * ta automatyka mogla by zniszczyc czyjas prace: demo, ktore ktos edytowal,
	 * zostaje. Skasowane recznie nie wraca, bo znacznik `ainp_demo_done` zyje
	 * dalej — i to jest zachowanie poprawne.
	 *
	 * @param int $swiezy_post Wlasnie utworzony wpis, ktorego nie wolno ruszyc.
	 *
	 * @return int Ile artykulow demo skasowano.
	 */
	private static function remove_demo( int $swiezy_post ): int {
		$demo = get_posts(
			array(
				'post_type'      => Plugin::CPT,
				'post_status'    => 'any',
				'meta_key'       => Plugin::META_DEMO, // phpcs:ignore WordPress.DB.SlowDBQuery
				'posts_per_page' => 10,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		if ( ! is_array( $demo ) ) {
			return 0;
		}

		$usuniete = 0;

		foreach ( $demo as $id ) {
			$id = (int) $id;

			if ( $id <= 0 || $id === $swiezy_post ) {
				continue;
			}

			$wpis = get_post( $id );
			if ( ! $wpis ) {
				continue;
			}

			// Edytowane demo zostaje. Porownanie jest na czasie WITRYNY —
			// `post_modified_gmt` bywa puste we wpisach zakladanych programowo.
			if ( (string) $wpis->post_modified !== (string) $wpis->post_date ) {
				continue;
			}

			// Drugi argument `true`: bez niego wpis laduje w koszu i nadal
			// jest widoczny dla zapytan o `post_status => 'any'`.
			wp_delete_post( $id, true );
			$usuniete++;
		}

		return $usuniete;
	}

	/**
	 * Autor artykulu.
	 *
	 * `wp_insert_post()` bez `post_author` bierze biezacego uzytkownika,
	 * a przebieg z crona nie ma zadnego — wpis dostawalby autora `0`, czyli
	 * pusta pozycje „Autor" na liscie wpisow i nic w szablonie. Ta sama
	 * logika stoi przy artykule demo w `Plugin`; powtorzona tutaj swiadomie,
	 * zeby publikacja nie zalezala od prywatnej metody cudzego etapu.
	 *
	 * @return int
	 */
	private static function author(): int {
		$autor = get_current_user_id();

		if ( $autor > 0 ) {
			return $autor;
		}

		$administratorzy = get_users(
			array(
				'role'    => 'administrator',
				'number'  => 1,
				'orderby' => 'ID',
				'order'   => 'ASC',
				'fields'  => 'ID',
			)
		);

		if ( is_array( $administratorzy ) && array() !== $administratorzy ) {
			return (int) $administratorzy[0];
		}

		return 0;
	}

	/**
	 * Staly ksztalt wyniku.
	 *
	 * @param bool   $ok           Powodzenie.
	 * @param int    $post_id      Identyfikator wpisu.
	 * @param bool   $created      Czy wpis powstal w tym wywolaniu.
	 * @param string $status       Status wpisu.
	 * @param string $error        Powod slowami, prosto do `note`.
	 * @param string $reason       Kod powodu.
	 * @param int    $demo_removed Ile artykulow demo skasowano.
	 *
	 * @return array{ok:bool,post_id:int,created:bool,status:string,error:string,reason:string,demo_removed:int}
	 */
	private static function result(
		bool $ok,
		int $post_id,
		bool $created,
		string $status,
		string $error,
		string $reason,
		int $demo_removed = 0
	): array {
		return array(
			'ok'           => $ok,
			'post_id'      => $post_id,
			'created'      => $created,
			'status'       => $status,
			'error'        => $error,
			'reason'       => $reason,
			'demo_removed' => $demo_removed,
		);
	}
}
