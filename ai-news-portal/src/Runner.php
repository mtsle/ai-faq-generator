<?php
/**
 * Przebieg zbierania: kanaly -> pozycje w tabeli.
 *
 * ETAP 2.4. To POLOWA klasy — ta, ktora POBIERA. Druga polowa (tick crona,
 * przejecie pozycji, budzet czasu i pamieci, ponowienia) dochodzi w Kroku 5;
 * plan wskazuje `Runner.php` jako miejsce wykonania, wiec nie zakladamy tu
 * nowego pliku.
 *
 * Dwie zasady, ktore rzadza calym tym przebiegiem:
 *
 *   1. NIC SIE NIE ZATRZYMUJE. Kazde zrodlo i kazda pozycja siedza we
 *      wlasnym `try/catch`. Padniete zrodlo odklada sie w podsumowaniu jako
 *      wpis o bledzie, a przebieg idzie do nastepnego.
 *   2. O DUPLIKATY PYTA BAZA, NIE KOD. Zapis idzie przez `INSERT IGNORE`
 *      na kolumnie z kluczem `UNIQUE`. Sprawdzenie `SELECT`-em przed zapisem
 *      to wzorzec „przeczytaj, potem zapisz", w ktorym dwa rownolegle
 *      przebiegi potrafia sie minac i wstawic te sama pozycje dwa razy.
 *
 * @package AI_News_Portal
 */

namespace AINP;

// Blokada bezposredniego wywolania pliku.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Zbieranie pozycji z kanalow.
 */
final class Runner {

	/** Status pozycji swiezo zapisanej. */
	public const STATUS_NEW = 'new';

	/**
	 * Sufit pozycji branych z JEDNEGO kanalu w jednym przebiegu.
	 *
	 * Typowy kanal podaje 10–20 wpisow. Sufit jest zabezpieczeniem przed
	 * kanalem, ktory oddaje cale archiwum serwisu — bez niego jeden taki
	 * adres zapchalby tabele i budzet czasu przebiegu. Odrzucone pozycje sa
	 * LICZONE i widoczne w podsumowaniu, zeby nikt nie znikal po cichu.
	 */
	public const MAX_ITEMS_PER_SOURCE = 100;

	/** Sufit dlugosci adresu — tyle ma kolumna `url varchar(2048)`. */
	public const MAX_URL_BYTES = 2048;

	/** Sufit dlugosci tytulu i zajawki — kolumny `text` mieszcza 65535 bajtow. */
	public const MAX_TEXT_BYTES = 65000;

	// -----------------------------------------------------------------------
	// Przebieg
	// -----------------------------------------------------------------------

	/**
	 * Pobiera wszystkie kanaly i zapisuje nowe pozycje.
	 *
	 * @param array<int,string>|null $sources Adresy kanalow; `null` bierze je
	 *                                        z ustawien.
	 *
	 * @return array<string,mixed> Podsumowanie w ksztalcie z `summary()`.
	 */
	public static function collect( ?array $sources = null ): array {
		$sources = ( null === $sources ) ? self::sources() : self::clean_sources( $sources );
		$wynik   = self::summary();

		$wynik['sources'] = count( $sources );

		foreach ( $sources as $url ) {
			try {
				$jedno = self::collect_source( $url );
			} catch ( \Throwable $e ) {
				/*
				 * Sam `try/catch` nie wystarczy jako obietnica „brak zatrzyman",
				 * ale lapie wszystko, co da sie zlapac. Wyczerpania pamieci
				 * nie zlapie nic — od tego jest budzet pamieci w Kroku 5.
				 */
				$jedno          = self::source_summary();
				$jedno['error'] = 'Nieoczekiwany blad: ' . $e->getMessage();
			}

			$wynik['per_source'][ $url ] = $jedno;

			foreach ( array( 'added', 'duplicates', 'invalid', 'dropped', 'failed' ) as $klucz ) {
				$wynik[ $klucz ] += $jedno[ $klucz ];
			}

			if ( '' !== $jedno['error'] ) {
				$wynik['errors'][ $url ] = $jedno['error'];
			}
		}

		return $wynik;
	}

	/**
	 * Pobiera jeden kanal.
	 *
	 * @param string $url Adres kanalu.
	 *
	 * @return array<string,mixed> Podsumowanie zrodla.
	 */
	private static function collect_source( string $url ): array {
		$wynik = self::source_summary();

		$odpowiedz = Http::get_feed( $url );

		if ( ! $odpowiedz['ok'] ) {
			$wynik['error'] = $odpowiedz['error'];
			return $wynik;
		}

		$kanal = Feed::parse( $odpowiedz['body'] );

		if ( ! $kanal['ok'] ) {
			$wynik['error'] = $kanal['error'];
			return $wynik;
		}

		// Pozycje bez adresu odrzucil juz parser — przepisujemy jego licznik,
		// zeby suma w podsumowaniu zgadzala sie z tym, co bylo w kanale.
		$wynik['invalid'] += (int) $kanal['skipped'];

		$pozycje = $kanal['items'];

		if ( count( $pozycje ) > self::MAX_ITEMS_PER_SOURCE ) {
			$wynik['dropped'] = count( $pozycje ) - self::MAX_ITEMS_PER_SOURCE;
			$pozycje          = array_slice( $pozycje, 0, self::MAX_ITEMS_PER_SOURCE );
		}

		foreach ( $pozycje as $pozycja ) {
			try {
				$los = self::insert_item( $pozycja );
			} catch ( \Throwable $e ) {
				$los = 'error';
			}

			switch ( $los ) {
				case 'added':
					$wynik['added']++;
					break;
				case 'duplicate':
					$wynik['duplicates']++;
					break;
				case 'invalid':
					$wynik['invalid']++;
					break;
				default:
					// Blad bazy to co innego niz pozycja bez adresu: pierwsze
					// wymaga uwagi czlowieka, drugie jest normalna praca.
					$wynik['failed']++;
					break;
			}
		}

		return $wynik;
	}

	// -----------------------------------------------------------------------
	// Zapis
	// -----------------------------------------------------------------------

	/**
	 * Zapisuje jedna pozycje.
	 *
	 * `INSERT IGNORE` degraduje naruszenie klucza `UNIQUE` do ostrzezenia:
	 * zapytanie konczy sie powodzeniem, `last_error` zostaje PUSTE, a liczba
	 * zmienionych wierszy wynosi 0. Po tej liczbie — nie po bledzie — poznajemy
	 * duplikat.
	 *
	 * `wp_encode_emoji()` na tytule, zajawce i tresci: przy `DB_CHARSET`
	 * ustawionym na `utf8` (stare instalacje, ktorych WordPress sam nie
	 * przepisuje) emoji wywoluje blad 1366, a `INSERT IGNORE` zamienia go
	 * w ciche ucięcie wiersza. Ryzykiem nie jest tu serwer, tylko stala
	 * w `wp-config.php`.
	 *
	 * @param array<string,mixed> $item Pozycja z `Feed::parse()`.
	 *
	 * @return string `added`, `duplicate`, `invalid` albo `error`.
	 */
	public static function insert_item( array $item ): string {
		global $wpdb;

		$url  = isset( $item['url'] ) ? trim( (string) $item['url'] ) : '';
		$hash = Dedup::url_hash( $url );

		// Bez adresu nie ma czego dedupowac, a pusty odcisk kolidowalby
		// z kazdym innym pustym w kluczu UNIQUE.
		if ( '' === $hash ) {
			return 'invalid';
		}

		if ( strlen( $url ) > self::MAX_URL_BYTES ) {
			return 'invalid';
		}

		$teraz = current_time( 'mysql' );

		$sql = "INSERT IGNORE INTO " . Plugin::table() . '
			( url, url_hash, title, excerpt, content, status, note, attempts, post_id, created_at, updated_at )
			VALUES ( %s, %s, %s, %s, %s, %s, %s, 0, 0, %s, %s )';

		$zapytanie = $wpdb->prepare(
			$sql,
			$url,
			$hash,
			self::fit( wp_encode_emoji( self::text( $item, 'title' ) ) ),
			self::fit( wp_encode_emoji( self::text( $item, 'summary' ) ) ),
			wp_encode_emoji( self::text( $item, 'content' ) ),
			self::STATUS_NEW,
			'',
			$teraz,
			$teraz
		);

		$wynik = $wpdb->query( $zapytanie );

		if ( false === $wynik ) {
			return 'error';
		}

		return ( (int) $wynik > 0 ) ? 'added' : 'duplicate';
	}

	// -----------------------------------------------------------------------
	// Zrodla
	// -----------------------------------------------------------------------

	/**
	 * Adresy kanalow z ustawien.
	 *
	 * Rozroznienie jest celowe: BRAK opcji (swieza instalacja) daje cztery
	 * kanaly domyslne, a opcja zapisana jako PUSTA tablica daje pustke.
	 * Klient, ktory swiadomie wyczyscil liste, nie ma jej dostawac z powrotem
	 * przy kazdym przebiegu.
	 *
	 * @return array<int,string>
	 */
	public static function sources(): array {
		$zapisane = get_option( Settings::OPTION_SOURCES, null );

		if ( null === $zapisane || false === $zapisane ) {
			return self::clean_sources( Settings::default_sources() );
		}

		return is_array( $zapisane ) ? self::clean_sources( $zapisane ) : array();
	}

	/**
	 * Oczyszcza liste adresow: tylko http(s), bez powtorzen.
	 *
	 * Powtorzenie tego samego kanalu na liscie nie robi szkody w bazie (klucz
	 * UNIQUE i tak odrzuci pozycje), ale kosztuje pelne pobranie kanalu
	 * i miejsce w budzecie czasu.
	 *
	 * @param array<int,mixed> $sources Adresy.
	 *
	 * @return array<int,string>
	 */
	public static function clean_sources( array $sources ): array {
		$czyste = array();

		foreach ( $sources as $url ) {
			if ( ! is_string( $url ) ) {
				continue;
			}

			$url = trim( $url );

			if ( ! Http::is_http_url( $url ) ) {
				continue;
			}

			if ( ! in_array( $url, $czyste, true ) ) {
				$czyste[] = $url;
			}
		}

		return $czyste;
	}

	// -----------------------------------------------------------------------
	// Pomocnicze
	// -----------------------------------------------------------------------

	/**
	 * Pole pozycji jako lancuch znakow.
	 *
	 * @param array<string,mixed> $item Pozycja.
	 * @param string              $key  Klucz.
	 *
	 * @return string
	 */
	private static function text( array $item, string $key ): string {
		return isset( $item[ $key ] ) ? (string) $item[ $key ] : '';
	}

	/**
	 * Przycina tekst do rozmiaru kolumny `text`.
	 *
	 * Serwer w trybie scislym odrzuca za dlugi wiersz bledem 1406, a
	 * `INSERT IGNORE` zamienia go w ciche pominiecie CALEJ pozycji. Lepiej
	 * przyciac zajawke, niz zgubic artykul.
	 *
	 * @param string $tekst Wejscie.
	 *
	 * @return string
	 */
	private static function fit( string $tekst ): string {
		if ( strlen( $tekst ) <= self::MAX_TEXT_BYTES ) {
			return $tekst;
		}

		// Ciecie po bajtach moze rozerwac znak wielobajtowy — `mb_strcut`
		// cofa sie do granicy znaku.
		if ( function_exists( 'mb_strcut' ) ) {
			return mb_strcut( $tekst, 0, self::MAX_TEXT_BYTES, 'UTF-8' );
		}

		return substr( $tekst, 0, self::MAX_TEXT_BYTES );
	}

	/**
	 * Pusty ksztalt podsumowania calego przebiegu.
	 *
	 * @return array<string,mixed>
	 */
	private static function summary(): array {
		return array(
			'sources'    => 0,
			'added'      => 0,
			'duplicates' => 0,
			'invalid'    => 0,
			'dropped'    => 0,
			'failed'     => 0,
			'errors'     => array(),
			'per_source' => array(),
		);
	}

	/**
	 * Pusty ksztalt podsumowania jednego zrodla.
	 *
	 * @return array<string,mixed>
	 */
	private static function source_summary(): array {
		return array(
			'added'      => 0,
			'duplicates' => 0,
			'invalid'    => 0,
			'dropped'    => 0,
			'failed'     => 0,
			'error'      => '',
		);
	}
}
