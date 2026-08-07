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
	 * Status pozycji, ktora nie pojdzie dalej.
	 *
	 * Pozycja odsiana przez filtr jest ZAPISYWANA, nie pomijana przy zapisie.
	 * Powod jest praktyczny: wiersz w tabeli z kluczem `UNIQUE` na `url_hash`
	 * sprawia, ze przy kazdym kolejnym przebiegu ten sam adres jest duplikatem,
	 * wiec kanal nie podaje go w kolko od nowa. Bez zapisu odsiana pozycja
	 * wracalaby co godzine i za kazdym razem przechodzila przez filtr.
	 */
	public const STATUS_SKIPPED = 'skipped';

	/**
	 * Sufit pozycji branych z JEDNEGO kanalu w jednym przebiegu.
	 *
	 * Typowy kanal podaje 10–20 wpisow. Sufit jest zabezpieczeniem przed
	 * kanalem, ktory oddaje cale archiwum serwisu — bez niego jeden taki
	 * adres zapchalby tabele i budzet czasu przebiegu. Odrzucone pozycje sa
	 * LICZONE i widoczne w podsumowaniu, zeby nikt nie znikal po cichu.
	 */
	public const MAX_ITEMS_PER_SOURCE = 100;

	/** Status pozycji, ktorej nie udalo sie doprowadzic do konca. */
	public const STATUS_FAILED = 'failed';

	/**
	 * Ile razy probujemy, zanim pozycja idzie na `failed`.
	 *
	 * Dotyczy WYLACZNIE bledow przejsciowych (timeout, 429, 5xx, brak pamieci).
	 * Blad trwaly — 404, zakaz z `robots.txt` — konczy pozycje od razu:
	 * ponawianie go tylko zjada budzet czasu.
	 */
	public const MAX_ATTEMPTS = 3;

	/** Ile pozycji bierze jeden przebieg przygotowania tresci. */
	public const PREPARE_BATCH = 10;

	/**
	 * Budzet czasu jednego przebiegu przygotowania: 15 sekund.
	 *
	 * Bez niego partia dziesieciu pozycji zamawia do 10 x (15 s artykul + 5 s
	 * `robots.txt`), czyli okolo 200 s w jednym zadaniu — a typowy
	 * `max_execution_time` w SAPI webowym to 30–60 s. Proces ginal w polowie
	 * partii: transient z podsumowaniem nie powstawal, a klient dostawal pusta
	 * strone i nie wiedzial, ktore pozycje zostaly przetworzone.
	 *
	 * Budzet sprawdzany jest PRZED wzieciem kolejnej pozycji, bo przerwac
	 * mozna tylko miedzy pozycjami — pozycja w trakcie musi sie domknac,
	 * inaczej zostaje w stanie posrednim.
	 */
	public const PREPARE_BUDGET = 15;

	/** Ile pozycji oglada jedno zapytanie szukajace kandydata dla modelu. */
	public const AI_SCAN = 25;

	/**
	 * Ile razy `pick_for_ai()` ponawia zapytanie, zanim sie podda.
	 *
	 * Odsiane pozycje wypadaja z warunku `status = 'new'`, wiec kolejne
	 * zapytanie widzi juz NASTEPNE wiersze — to jest cale stronicowanie.
	 * Sufit jest po to, zeby przy kilkuset pozycjach poza tematem jedno
	 * klikniecie nie przeorywalo calej tabeli.
	 */
	public const AI_SCAN_ROUNDS = 4;

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

			foreach ( array( 'added', 'skipped', 'duplicates', 'invalid', 'dropped', 'failed' ) as $klucz ) {
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

		// Obie listy czytane RAZ na kanal, nie raz na pozycje: `Settings::all()`
		// to odczyt opcji i scalenie z domyslnymi, a kanal potrafi podac
		// sto pozycji.
		$listy    = Filter::lists();
		$slowa    = $listy['excluded'];
		$wymagane = $listy['required'];

		foreach ( $pozycje as $pozycja ) {
			try {
				$los = self::insert_item( $pozycja, $slowa, $wymagane );
			} catch ( \Throwable $e ) {
				$los = 'error';
			}

			switch ( $los ) {
				case 'added':
					$wynik['added']++;
					break;
				case 'skipped':
					$wynik['skipped']++;
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
	 * ETAP 3.2. Zapis jest tez miejscem, w ktorym zapada werdykt filtra:
	 * pozycja ze slowem wykluczajacym ląduje w tabeli od razu ze statusem
	 * `skipped` i POWODEM w kolumnie `note`. Powod jest tam po to, zeby seria
	 * pominiec z jednego kanalu byla widoczna golym okiem na ekranie Materialy
	 * — to jedyny sygnal, ze lista wykluczen jest za ostra.
	 *
	 * Tresc odsianej pozycji NIE jest zapisywana: do konca zycia tego wiersza
	 * nikt jej nie przeczyta, a kanal z pelnymi tekstami potrafi podac 100
	 * pozycji po kilkadziesiat kilobajtow.
	 *
	 * @param array<string,mixed>    $item  Pozycja z `Feed::parse()`.
	 * @param array<int,string>|null $words Slowa wykluczajace; `null` bierze
	 *                                      liste z ustawien (wygodne przy
	 *                                      wywolaniu pojedynczym, drogie
	 *                                      w petli — stad jawny argument
	 *                                      w `collect_source()`).
	 * @param array<int,string>|null $required Slowa wymagane (wariant C); `null`
	 *                                         bierze liste z ustawien, pusta
	 *                                         tablica wylacza bramke.
	 *
	 * @return string `added`, `skipped`, `duplicate`, `invalid` albo `error`.
	 */
	public static function insert_item( array $item, ?array $words = null, ?array $required = null ): string {
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

		$slowo   = self::excluded_word( $item, $words );
		$odsiane = ( '' !== $slowo );
		$note    = $odsiane ? Filter::note( $slowo ) : '';

		/*
		 * Bramka slow wymaganych stoi PO wykluczeniach i tylko wtedy, gdy tamte
		 * przepuscily. Odwrotna kolejnosc dawalaby przy artykule o kocie notatke
		 * „poza tematem" zamiast nazwy slowa, ktore zadzialalo — a nazwa slowa
		 * jest jedynym sygnalem, po ktorym widac, ze lista wykluczen jest za
		 * ostra. Bramka nie dotyka sieci: patrzy na tytul i zajawke z kanalu.
		 */
		if ( ! $odsiane && ! Filter::has_required( $item, $required ) ) {
			$odsiane = true;
			$note    = Filter::NOTE_OFFTOPIC;
		}

		$status  = $odsiane ? self::STATUS_SKIPPED : self::STATUS_NEW;
		$tresc   = $odsiane ? '' : wp_encode_emoji( self::text( $item, 'content' ) );

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
			$tresc,
			$status,
			self::fit( $note ),
			$teraz,
			$teraz
		);

		$wynik = $wpdb->query( $zapytanie );

		if ( false === $wynik ) {
			return 'error';
		}

		// Zero zmienionych wierszy to duplikat — i to niezaleznie od werdyktu
		// filtra: adres juz jest w tabeli, wiec wiersz zostaje taki, jaki byl.
		if ( 0 === (int) $wynik ) {
			return 'duplicate';
		}

		return $odsiane ? 'skipped' : 'added';
	}

	// -----------------------------------------------------------------------
	// Przygotowanie tresci (etap 3.6)
	// -----------------------------------------------------------------------

	/**
	 * Doprowadza pozycje do stanu, w ktorym ma tresc i odcisk tresci.
	 *
	 * ETAP 3.6. To druga polowa drogi materialu: zebranie skonczylo sie na
	 * wierszu z adresem i trescia z kanalu, tutaj dochodzi tresc pelna,
	 * oczyszczona i ODCISNIETA. Wywolanie modelu jest dopiero w Kroku 4,
	 * a cron w Kroku 5 — do tego czasu przebieg odpala czlowiek przyciskiem
	 * „Przygotuj treści".
	 *
	 * Bierzemy tylko pozycje, ktore odcisku jeszcze nie maja. Odcisk jest
	 * wiec jednoczesnie znacznikiem „ta pozycja jest juz przygotowana" —
	 * osobna kolumna na to samo byla by trzecim stanem do pilnowania.
	 *
	 * @param int        $limit  Ile pozycji wziac.
	 * @param float|null $budget Budzet czasu w sekundach; `null` bierze
	 *                           `PREPARE_BUDGET`. Jawny argument jest tu dla
	 *                           ticku z Kroku 5, ktory poda swoj POZOSTALY
	 *                           czas — i dla testu, ktory nie ma czekac
	 *                           pietnastu sekund, zeby sprawdzic bramke.
	 *
	 * @return array<string,mixed> Podsumowanie w ksztalcie `prepare_summary()`.
	 */
	public static function prepare_batch( int $limit = self::PREPARE_BATCH, ?float $budget = null ): array {
		global $wpdb;

		$wynik = self::prepare_summary();

		$sql = 'SELECT id, url, url_hash, title, excerpt, content, status, attempts FROM ' . Plugin::table()
			. ' WHERE status = %s AND ( content_hash IS NULL OR content_hash = %s )'
			. ' ORDER BY id ASC LIMIT %d';

		$wiersze = $wpdb->get_results( $wpdb->prepare( $sql, self::STATUS_NEW, '', max( 1, $limit ) ) ); // phpcs:ignore WordPress.DB

		if ( ! is_array( $wiersze ) ) {
			return $wynik;
		}

		$listy    = Filter::lists();
		$slowa    = $listy['excluded'];
		$wymagane = $listy['required'];
		$start    = microtime( true );
		$budzet   = ( null === $budget ) ? (float) self::PREPARE_BUDGET : max( 0.1, $budget );

		foreach ( $wiersze as $wiersz ) {
			/*
			 * Sprawdzenie PRZED wzieciem pozycji, nie po. Pozycja raz zaczeta
			 * musi sie domknac, bo w polowie zostawia wiersz w stanie, ktorego
			 * nikt pozniej nie posprzata.
			 */
			if ( ( microtime( true ) - $start ) >= $budzet ) {
				$wynik['budget_hit'] = true;
				break;
			}

			$wynik['taken']++;

			try {
				$los = self::prepare_item( $wiersz, $slowa, $wymagane );
			} catch ( \Throwable $e ) {
				// Ta sama zasada co przy zbieraniu: jedna polamana pozycja
				// nie ma prawa zatrzymac calego przebiegu.
				$los                                   = 'error';
				$wynik['errors'][ (int) $wiersz->id ] = $e->getMessage();
			}

			if ( isset( $wynik[ $los ] ) ) {
				$wynik[ $los ]++;
			}
		}

		return $wynik;
	}

	/**
	 * Przygotowuje JEDNA pozycje: tresc, adres kanoniczny, odcisk tresci.
	 *
	 * Kolejnosc krokow jest cala trescia tego etapu i nie wolno jej zamienic:
	 *
	 *   1. FILTR jeszcze raz. Wiersze zebrane, zanim filtr powstal, nigdy go
	 *      nie widzialy; bez tego sprawdzenia poszlyby prosto do scrapingu.
	 *      Kosztuje zero zadan sieciowych, a oszczedza jedno na kazdej
	 *      odsianej pozycji. Razem z nim bramka slow WYMAGANYCH — z tego
	 *      samego powodu i tez bez sieci.
	 *   2. SCRAPING tylko wtedy, gdy tresc z kanalu jest za krotka.
	 *   3. CANONICAL przed odciskiem tresci: podmiana adresu potrafi odslonic
	 *      duplikat, ktorego nie widac bylo po adresie z kanalu.
	 *   4. ODCISK TRESCI na koncu, juz po oczyszczeniu — inaczej ten sam tekst
	 *      raz z reklama, raz bez, dawalby dwa rozne odciski.
	 *
	 * @param object                 $row      Wiersz tabeli.
	 * @param array<int,string>|null $words    Slowa wykluczajace.
	 * @param array<int,string>|null $required Slowa wymagane (wariant C).
	 *
	 * @return string `ready`, `skipped`, `retry`, `failed` albo `error`.
	 */
	public static function prepare_item( $row, ?array $words = null, ?array $required = null ): string {
		$id  = isset( $row->id ) ? (int) $row->id : 0;
		$url = isset( $row->url ) ? (string) $row->url : '';

		if ( 0 === $id ) {
			return 'error';
		}

		$pozycja = array(
			'title'   => isset( $row->title ) ? (string) $row->title : '',
			'excerpt' => isset( $row->excerpt ) ? (string) $row->excerpt : '',
			'content' => isset( $row->content ) ? (string) $row->content : '',
		);

		// 1. Filtr — dla wierszy zebranych, zanim filtr istnial.
		$slowo = self::excluded_word( $pozycja, $words );

		if ( '' !== $slowo ) {
			self::finish( $id, self::STATUS_SKIPPED, Filter::note( $slowo ) );
			return 'skipped';
		}

		// 1b. Bramka slow wymaganych — tak samo jak przy zapisie, dla wierszy
		// zebranych, zanim wariant C powstal. Kosztuje zero zadan sieciowych
		// i stoi PRZED scrapingiem wlasnie po to.
		if ( ! Filter::has_required( $pozycja, $required ) ) {
			self::finish( $id, self::STATUS_SKIPPED, Filter::NOTE_OFFTOPIC );
			return 'skipped';
		}

		// Tresc Z KANALU tez przechodzi odsiew boksow: kanal potrafi podac
		// artykul razem z ramka „oceń wpis" i zachętą do newslettera. Bez tego
		// smiec wchodzi do promptu, do odcisku tresci i do gotowego artykulu.
		$html = Article::declutter( $pozycja['content'] );

		// 2. Scraping tylko przy zbyt krotkiej tresci z kanalu.
		if ( Article::needs_scraping( $html ) ) {
			$pobrane = Article::fetch( $url );

			if ( ! $pobrane['ok'] ) {
				return self::after_failure( $row, $pobrane );
			}

			$wyciete = Article::extract( $pobrane['html'], $url );

			if ( ! $wyciete['ok'] ) {
				self::finish( $id, self::STATUS_SKIPPED, $wyciete['error'] );
				return 'skipped';
			}

			$html = (string) $wyciete['html'];

			// 3. Canonical moze odslonic duplikat niewidoczny po adresie z kanalu.
			if ( '' !== $wyciete['canonical'] && ! self::claim_canonical( $id, (string) $wyciete['canonical'], $row ) ) {
				self::finish( $id, self::STATUS_SKIPPED, 'Duplikat: ten sam artykuł jest już w tabeli pod adresem kanonicznym' );
				return 'skipped';
			}
		}

		$czysta = Article::clean( $html );

		if ( ! Article::is_long_enough( $czysta ) ) {
			self::finish( $id, self::STATUS_SKIPPED, Article::short_note( $czysta ) );
			return 'skipped';
		}

		// 4. Odcisk tresci — dopiero po oczyszczeniu.
		if ( ! self::claim_content( $id, $czysta ) ) {
			self::finish( $id, self::STATUS_SKIPPED, 'Duplikat treści: ten sam tekst jest już w tabeli pod innym adresem' );
			return 'skipped';
		}

		return 'ready';
	}

	/**
	 * Wybiera nastepna pozycje do wyslania do modelu. BRAMKA SLOW WYMAGANYCH.
	 *
	 * To jest jedyne miejsce, przez ktore przechodzi kazda droga do Gemini,
	 * i dlatego stoi tu bramka, a nie tylko w przygotowaniu tresci.
	 *
	 * POWOD JEST STRUKTURALNY, NIE HISTORYCZNY. Lista slow wymaganych jest
	 * polem w Ustawieniach, a `prepare_batch()` bierze wylacznie wiersze BEZ
	 * odcisku tresci. Kazda zmiana listy przez klienta uniewaznia wiec wiersze
	 * juz przygotowane i NIGDY ich nie dotknie — powstaje zestaw pozycji poza
	 * tematem, do ktorego nie siega zaden filtr wczesniejszy. Zmierzone na
	 * dworku 2026-08-07: 9 z 34 gotowych pozycji bylo poza tematem (wesele,
	 * pizza, jazda konna, garderoba, obiady wegetarianskie x2, Wi-Fi,
	 * przedszkole, ogrzewanie). Przy suficie 20 wywolan na dobe to prawie pol
	 * doby pracy klienta wyrzucone.
	 *
	 * Sprawdzenie jest DARMOWE: `finish()` zeruje tylko `content`, wiec `title`
	 * i `excerpt` — jedyne pola, ktore oglada ta bramka — zostaja w wierszu na
	 * zawsze. Zero zadan HTTP, zero wywolan AI.
	 *
	 * Odrzucona pozycja konczy jako `skipped` z `Filter::NOTE_OFFTOPIC`, tak
	 * samo jak w `prepare_item()` — jeden powod ma jedna notatke.
	 *
	 * @param array<int,string>|null $required Slowa wymagane; `null` z Ustawien.
	 * @param int|null               $rounds   Ile zapytan; `null` = `AI_SCAN_ROUNDS`.
	 *
	 * @return array{row:object|null,offtopic:int,scanned:int}
	 */
	public static function pick_for_ai( ?array $required = null, ?int $rounds = null ): array {
		global $wpdb;

		$wynik = array(
			'row'      => null,
			'offtopic' => 0,
			'scanned'  => 0,
		);

		$wymagane = ( null === $required ) ? Filter::required_words() : $required;
		$rund     = ( null === $rounds ) ? self::AI_SCAN_ROUNDS : max( 1, $rounds );

		/*
		 * Warunek `content <> ''` nie jest ozdobnikiem: pozycja `new` z odciskiem,
		 * ale z wyzerowana trescia, nie ma czego wyslac do modelu, a wybrana
		 * w kolko blokowalaby kolejke.
		 */
		$sql = 'SELECT id, url, title, excerpt, content, content_hash, status FROM ' . Plugin::table()
			. ' WHERE status = %s AND content_hash IS NOT NULL AND content_hash <> %s AND content <> %s'
			. ' ORDER BY id ASC LIMIT %d';

		for ( $runda = 0; $runda < $rund; $runda++ ) {
			$wiersze = $wpdb->get_results(
				$wpdb->prepare( $sql, self::STATUS_NEW, '', '', self::AI_SCAN )
			); // phpcs:ignore WordPress.DB

			if ( ! is_array( $wiersze ) || array() === $wiersze ) {
				return $wynik;
			}

			foreach ( $wiersze as $wiersz ) {
				$wynik['scanned']++;

				/*
				 * Do bramki idzie sam tytul i zajawka — bez tresci, i to jest
				 * warunek jej skutecznosci. W tresci artykulu o pizzy slowo
				 * „pies” pada w stopce albo w boksie powiazanych wpisow, wiec
				 * bramka ogladajaca tresc nie odsialaby niczego.
				 */
				$pozycja = array(
					'title'   => isset( $wiersz->title ) ? (string) $wiersz->title : '',
					'excerpt' => isset( $wiersz->excerpt ) ? (string) $wiersz->excerpt : '',
				);

				if ( Filter::has_required( $pozycja, $wymagane ) ) {
					$wynik['row'] = $wiersz;
					return $wynik;
				}

				self::finish( (int) $wiersz->id, self::STATUS_SKIPPED, Filter::NOTE_OFFTOPIC );
				$wynik['offtopic']++;
			}
		}

		return $wynik;
	}

	/**
	 * Slowo wykluczajace — potwierdzone na tresci BEZ boksow serwisu.
	 *
	 * Odsiew idzie w dwóch spojrzeniach i to jest cala sztuczka:
	 *
	 *   1. Tanie: pelny tekst z kanalu. Nic nie trafia — pozycja przechodzi
	 *      i nie kosztuje ani jednego przetworzenia drzewa HTML.
	 *   2. Drogie, tylko przy PODEJRZENIU: tresc bez ramek „oceń wpis",
	 *      „powiązane wpisy" i „zapisz się do newslettera”. Dopiero jesli slowo
	 *      przetrwa to oczyszczenie, pozycja idzie do `skipped`.
	 *
	 * Powod jest policzony na zywym materiale: z 70 pobranych pozycji filtr
	 * odsiewal 36, ale 15 z nich to byly DOBRE artykuly o psach, zabite przez
	 * slowo ze stopki („newsletter” — 8 pozycji), z boksu powiazanych wpisow
	 * („kot” — 5) i z banera („promocja” — 1). Drugie spojrzenie kosztuje
	 * jedno przetworzenie drzewa na pozycje PODEJRZANA, nie na kazda.
	 *
	 * Zakres pol sie nie zmienia — nadal tytul, zajawka i tresc z kanalu.
	 * Zmienia sie tylko to, ze tresc jest ogladana bez mebli serwisu.
	 *
	 * @param array<string,mixed>    $item  Pozycja.
	 * @param array<int,string>|null $words Slowa wykluczajace.
	 *
	 * @return string Dopasowane slowo albo pusty lancuch.
	 */
	private static function excluded_word( array $item, ?array $words ): string {
		$slowo = Filter::match( $item, $words );

		if ( '' === $slowo ) {
			return '';
		}

		$tresc = isset( $item['content'] ) ? (string) $item['content'] : '';

		if ( '' === trim( $tresc ) ) {
			return $slowo;
		}

		$item['content'] = Article::declutter( $tresc );

		return Filter::match( $item, $words );
	}

	/**
	 * Los pozycji po nieudanym pobraniu.
	 *
	 * Blad przejsciowy wraca do kolejki z podbitym licznikiem prob; przy
	 * `MAX_ATTEMPTS` konczy jako `failed`. Blad trwaly konczy od razu.
	 *
	 * @param object              $row     Wiersz.
	 * @param array<string,mixed> $pobrane Wynik `Article::fetch()`.
	 *
	 * @return string `retry` albo `failed`.
	 */
	private static function after_failure( $row, array $pobrane ): string {
		global $wpdb;

		$id    = (int) $row->id;
		$proby = isset( $row->attempts ) ? (int) $row->attempts : 0;
		$blad  = (string) $pobrane['error'];

		if ( empty( $pobrane['retryable'] ) ) {
			self::finish( $id, self::STATUS_FAILED, $blad );
			return 'failed';
		}

		$proby++;

		if ( $proby >= self::MAX_ATTEMPTS ) {
			self::finish( $id, self::STATUS_FAILED, $blad . ' (prób: ' . $proby . ')' );
			return 'failed';
		}

		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . Plugin::table() . ' SET status = %s, note = %s, attempts = %d, updated_at = %s WHERE id = %d',
				self::STATUS_NEW,
				self::fit( $blad ),
				$proby,
				current_time( 'mysql' ),
				$id
			)
		); // phpcs:ignore WordPress.DB

		return 'retry';
	}

	/**
	 * Podmienia adres na kanoniczny — albo rozpoznaje duplikat.
	 *
	 * O kolizje pyta BAZA, nie kod: `UPDATE IGNORE` na kolumnie z kluczem
	 * `UNIQUE` przy konflikcie nie zmienia wiersza i nie zglasza bledu.
	 * Poznajemy to po odczycie: skoro odcisk nie jest tym, ktory wpisywalismy,
	 * to znaczy, ze zajmuje go inny wiersz.
	 *
	 * @param string $canonical Adres kanoniczny.
	 * @param int    $id        Identyfikator wiersza.
	 * @param object $row       Wiersz.
	 *
	 * @return bool `false`, gdy adres kanoniczny nalezy juz do innej pozycji.
	 */
	private static function claim_canonical( int $id, string $canonical, $row ): bool {
		global $wpdb;

		$hash = Dedup::url_hash( $canonical );

		if ( '' === $hash ) {
			return true;
		}

		$stary = isset( $row->url_hash ) ? (string) $row->url_hash : '';

		// Ten sam zasob pod tym samym odciskiem — nie ma czego podmieniac.
		if ( $hash === $stary ) {
			return true;
		}

		if ( strlen( $canonical ) > self::MAX_URL_BYTES ) {
			return true;
		}

		$wpdb->query(
			$wpdb->prepare(
				'UPDATE IGNORE ' . Plugin::table() . ' SET url = %s, url_hash = %s, updated_at = %s WHERE id = %d',
				$canonical,
				$hash,
				current_time( 'mysql' ),
				$id
			)
		); // phpcs:ignore WordPress.DB

		return $hash === self::read_column( $id, 'url_hash' );
	}

	/**
	 * Zapisuje tresc i jej odcisk — albo rozpoznaje duplikat tresci.
	 *
	 * @param int    $id     Identyfikator wiersza.
	 * @param string $tresc  Tresc po oczyszczeniu.
	 *
	 * @return bool `false`, gdy ten sam tekst wisi juz pod innym adresem.
	 */
	private static function claim_content( int $id, string $tresc ): bool {
		global $wpdb;

		$hash = Dedup::content_hash( $tresc );

		if ( '' === $hash ) {
			return true;
		}

		$wpdb->query(
			$wpdb->prepare(
				'UPDATE IGNORE ' . Plugin::table() . ' SET content = %s, content_hash = %s, note = %s, attempts = 0, updated_at = %s WHERE id = %d',
				wp_encode_emoji( $tresc ),
				$hash,
				'',
				current_time( 'mysql' ),
				$id
			)
		); // phpcs:ignore WordPress.DB

		return $hash === self::read_column( $id, 'content_hash' );
	}

	/**
	 * Zamyka pozycje: status, powod i wyczyszczona tresc.
	 *
	 * Tresc jest zerowana, bo pozycja `skipped` i `failed` nie pojdzie juz do
	 * modelu, a kanal z pelnymi tekstami zostawialby po kilkadziesiat kilobajtow
	 * na kazdej odrzuconej pozycji.
	 *
	 * @param int    $id     Identyfikator wiersza.
	 * @param string $status Status koncowy.
	 * @param string $note   Powod.
	 *
	 * @return void
	 */
	private static function finish( int $id, string $status, string $note ): void {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . Plugin::table() . ' SET status = %s, note = %s, content = %s, updated_at = %s WHERE id = %d',
				$status,
				self::fit( $note ),
				'',
				current_time( 'mysql' ),
				$id
			)
		); // phpcs:ignore WordPress.DB
	}

	/**
	 * Odczyt jednej kolumny wiersza.
	 *
	 * Nazwa kolumny pochodzi WYLACZNIE ze stalych w tym pliku — nigdy
	 * z zadania, wiec nie ma tu czego przygotowywac przez `prepare()`.
	 *
	 * @param int    $id      Identyfikator wiersza.
	 * @param string $kolumna Nazwa kolumny.
	 *
	 * @return string
	 */
	private static function read_column( int $id, string $kolumna ): string {
		global $wpdb;

		$wartosc = $wpdb->get_var(
			$wpdb->prepare( 'SELECT ' . $kolumna . ' FROM ' . Plugin::table() . ' WHERE id = %d', $id )
		); // phpcs:ignore WordPress.DB

		return ( null === $wartosc ) ? '' : (string) $wartosc;
	}

	/**
	 * Pusty ksztalt podsumowania przygotowania tresci.
	 *
	 * @return array<string,mixed>
	 */
	private static function prepare_summary(): array {
		return array(
			'taken'      => 0,
			'ready'      => 0,
			'skipped'    => 0,
			'retry'      => 0,
			'failed'     => 0,
			'error'      => 0,
			'errors'     => array(),
			'budget_hit' => false,
		);
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
			'skipped'    => 0,
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
			'skipped'    => 0,
			'duplicates' => 0,
			'invalid'    => 0,
			'dropped'    => 0,
			'failed'     => 0,
			'error'      => '',
		);
	}
}
