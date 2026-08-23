<?php
/**
 * Odsiew slowami wykluczajacymi.
 *
 * ETAP 3.1. Filtr stoi PRZED scrapingiem i to jest cala jego wartosc: pozycja
 * odrzucona tutaj nie kosztuje ani jednego zadania HTTP, ani jednego z 20
 * wywolan AI na dobe. Cena za to miejsce w przeplywie jest jawna i zapisana
 * w planie: filtr widzi WYLACZNIE to, co przyszlo w kanale (tytul, zajawka,
 * `content:encoded`), wiec slowa obecne dopiero w tresci pobranej pozniej
 * scrapingiem sa dla niego niewidzialne.
 *
 * Dwie decyzje, ktore trzeba znac, zanim sie tu cokolwiek zmieni:
 *
 *   1. POROWNANIE BEZ ZNAKOW DIAKRYTYCZNYCH, po obu stronach. Lista slow
 *      w ustawieniach jest zapisana bez ogonkow (`zolw`, `krolik`), a tekst
 *      z kanalu je ma. Bez `remove_accents()` na tekscie polowa listy nie
 *      trafilaby nigdy, a nikt by tego nie zauwazyl — brak dopasowania nie
 *      zostawia sladu.
 *   2. GRANICA SLOWA, nie `strpos`. Bez niej `kot` lapie `kotwice`, `kotlet`
 *      i `kotare`, a wtedy filtr zaczyna kasowac artykuly o psach. Odmiany
 *      (`kota`, `kotu`, `kotem`) sa na liscie wypisane osobno wlasnie dlatego,
 *      ze granica slowa nie pozwala im sie dopasowac przez rdzen.
 *
 * @package AI_News_Portal
 */

namespace AINP;

// Blokada bezposredniego wywolania pliku.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Slowa wykluczajace: decyzja „bierzemy czy pomijamy".
 */
final class Filter {

	/**
	 * Sufit tekstu branego do porownania: 128 KB.
	 *
	 * Kanal z pelna trescia potrafi podac artykul na kilkaset kilobajtow, a
	 * kazde slowo z listy to osobne przejscie wyrazeniem regularnym. Slowo
	 * wykluczajace, ktore pojawia sie dopiero w 129. kilobajcie tekstu, i tak
	 * nie mowi nic o temacie artykulu.
	 */
	public const MAX_HAYSTACK_BYTES = 131072;

	/** Poczatek notatki zapisywanej przy pominietej pozycji. */
	public const NOTE_PREFIX = 'Słowo wykluczające: ';

	/**
	 * Notatka przy pozycji odrzuconej przez bramke slow wymaganych.
	 *
	 * Osobna od `NOTE_PREFIX`, bo to zupelnie inny powod: tam artykul mowil
	 * o czyms niechcianym, tutaj nie mowi o psach w ogole. Zlanie obu w jedna
	 * notatke zabiloby jedyny sygnal, po ktorym na ekranie Materialy widac,
	 * ktora z dwoch list jest za ostra.
	 */
	public const NOTE_OFFTOPIC = 'Poza tematem: brak słowa wymaganego w tytule i zajawce';

	/**
	 * Pola ogladane przy szukaniu slowa WYKLUCZAJACEGO.
	 *
	 * Dwie nazwy na zajawke, bo pozycja przychodzi tu z dwoch miejsc:
	 * z `Feed::parse()` (klucz `summary`) albo z wiersza tabeli (`excerpt`).
	 *
	 * @var array<int,string>
	 */
	private const FIELDS_ALL = array( 'title', 'summary', 'excerpt', 'content' );

	/**
	 * Pola, w ktorych musi stac slowo WYMAGANE: tytul i zajawka, bez tresci.
	 *
	 * Zawezenie jest cala wartoscia bramki. W pelnym tekscie artykulu
	 * z kanalu o psach slowo „pies" pada prawie zawsze — nawet w artykule
	 * o pizzy, gdzie siedzi w stopce albo w boksie „powiazane wpisy". Bramka
	 * na pelnym tekscie przepuszczalaby wiec wszystko i byla tylko kosztem.
	 *
	 * @var array<int,string>
	 */
	private const FIELDS_HEAD = array( 'title', 'summary', 'excerpt' );

	// -----------------------------------------------------------------------
	// Decyzja
	// -----------------------------------------------------------------------

	/**
	 * Czy pozycja ma slowo wykluczajace.
	 *
	 * @param array<string,mixed> $item  Pozycja z `Feed::parse()` albo wiersz
	 *                                   tabeli (liczy sie `title`, `summary`
	 *                                   i `content`).
	 * @param array<int,string>|null $words Lista slow; `null` bierze ja
	 *                                      z ustawien.
	 *
	 * @return string Dopasowane slowo albo pusty lancuch, gdy pozycja przechodzi.
	 */
	public static function match( array $item, ?array $words = null ): string {
		$tekst = self::haystack( $item );

		if ( '' === $tekst ) {
			return '';
		}

		$lista = ( null === $words ) ? self::words() : self::clean_words( $words );

		foreach ( $lista as $slowo ) {
			if ( self::contains( $tekst, $slowo ) ) {
				return $slowo;
			}
		}

		return '';
	}

	/**
	 * Czy w tekscie stoi to slowo — z granica slowa po obu stronach.
	 *
	 * Granica jest wlasna, nie `\b`: `\b` w PHP liczy granice wzgledem klasy
	 * `\w`, a ta bez modyfikatora `u` nie zna liter spoza ASCII. Tekst wchodzi
	 * tu juz po `remove_accents()`, ale wyrazy obce („cafe", „uber") potrafia
	 * ogonek zachowac, wiec granica opisana przez `\p{L}` jest jedyna, ktora
	 * nie klamie.
	 *
	 * @param string $haystack Tekst PO normalizacji (`self::normalize()`).
	 * @param string $word     Slowo PO normalizacji.
	 *
	 * @return bool
	 */
	public static function contains( string $haystack, string $word ): bool {
		if ( '' === $word || '' === $haystack ) {
			return false;
		}

		$wzorzec = '/(?<![\p{L}\p{N}])' . preg_quote( $word, '/' ) . '(?![\p{L}\p{N}])/u';

		return 1 === preg_match( $wzorzec, $haystack );
	}

	/**
	 * Czy pozycja mowi o psach — bramka slow WYMAGANYCH (wariant C).
	 *
	 * Odwrotnosc `match()`: tam szukamy powodu do odrzucenia, tutaj powodu do
	 * przyjecia. Kolejnosc w przeplywie jest taka, ze ta bramka stoi PO
	 * wykluczeniach — artykul o kocie ma zostac odrzucony jako artykul o kocie,
	 * a nie jako „nie o psie", bo tylko pierwsza z tych notatek mowi klientowi,
	 * ktore slowo z listy zadzialalo.
	 *
	 * PUSTA LISTA ZNACZY „BEZ WYMAGAN". To nie jest uprzejmosc wobec pustego
	 * pola w Ustawieniach, tylko warunek zgodnosci wstecz: instalacja sprzed
	 * wariantu C ma dzialac dalej dokladnie tak jak przedtem, a klient, ktory
	 * pole wyczysci, ma dostac filtr bez bramki — nie portal odrzucajacy
	 * kazda pozycje.
	 *
	 * @param array<string,mixed>    $item  Pozycja.
	 * @param array<int,string>|null $words Lista slow wymaganych; `null` bierze
	 *                                      ja z ustawien.
	 *
	 * @return bool `true`, gdy pozycja przechodzi (albo gdy lista jest pusta).
	 */
	public static function has_required( array $item, ?array $words = null ): bool {
		$lista = ( null === $words ) ? self::required_words() : self::clean_words( $words );

		if ( ! $lista ) {
			return true;
		}

		$tekst = self::haystack( $item, self::FIELDS_HEAD );

		/*
		 * Pozycja bez tytulu I bez zajawki nie daje sie ocenic — nie ma o czym
		 * orzekac. Idzie do `skipped` z ta sama notatka co artykul o pizzy,
		 * bo dla klienta skutek jest ten sam: nic nie wskazuje, ze to material
		 * o psach. Kanaly z czterech domyslnych zrodel zawsze podaja tytul.
		 */
		if ( '' === $tekst ) {
			return false;
		}

		foreach ( $lista as $slowo ) {
			if ( self::contains( $tekst, $slowo ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Notatka do kolumny `note` przy pozycji pominietej przez filtr.
	 *
	 * Powod pominiecia MUSI byc czytelny na ekranie Materialy — seria pozycji
	 * pominietych przez jedno slowo jest jedynym sygnalem, ze lista wykluczen
	 * jest za ostra. Bez tego pozycje znikaja bez sladu.
	 *
	 * @param string $word Dopasowane slowo.
	 *
	 * @return string
	 */
	public static function note( string $word ): string {
		return self::NOTE_PREFIX . $word;
	}

	// -----------------------------------------------------------------------
	// Wejscie filtra
	// -----------------------------------------------------------------------

	/**
	 * Tekst, ktory filtr oglada: tytul + zajawka + tresc z kanalu.
	 *
	 * Wszystkie trzy pola przychodza Z KANALU, wiec ta metoda nie dotyka sieci.
	 * To jest wlasnie warunek, dzieki ktoremu odrzucona pozycja ma zero zadan
	 * HTTP na koncie.
	 *
	 * @param array<string,mixed>    $item   Pozycja.
	 * @param array<int,string>|null $fields Pola do sklejenia; `null` bierze
	 *                                       komplet (`FIELDS_ALL`). Bramka slow
	 *                                       wymaganych podaje tu sam naglowek.
	 *
	 * @return string Tekst znormalizowany.
	 */
	public static function haystack( array $item, ?array $fields = null ): string {
		$czesci = array();

		foreach ( ( null === $fields ) ? self::FIELDS_ALL : $fields as $klucz ) {
			if ( isset( $item[ $klucz ] ) && is_scalar( $item[ $klucz ] ) ) {
				$czesci[] = (string) $item[ $klucz ];
			}
		}

		$tekst = implode( ' ', $czesci );

		if ( strlen( $tekst ) > self::MAX_HAYSTACK_BYTES ) {
			$tekst = function_exists( 'mb_strcut' )
				? mb_strcut( $tekst, 0, self::MAX_HAYSTACK_BYTES, 'UTF-8' )
				: substr( $tekst, 0, self::MAX_HAYSTACK_BYTES );
		}

		return self::normalize( $tekst );
	}

	/**
	 * Tekst sprowadzony do postaci porownywalnej ze slowem z listy.
	 *
	 * Kolejno: znaczniki i encje precz (`Dedup::normalize_text()` robi to samo
	 * na potrzeby odciskow — jedna definicja „golego tekstu" w calej wtyczce),
	 * potem znaki diakrytyczne, potem ujednolicenie bialych znakow. Ostatni
	 * krok jest po tym, ze `remove_accents()` na niektorych znakach oddaje
	 * dwie litery i potrafi zmienic dlugosc, ale nie uklad spacji.
	 *
	 * @param string $tekst Wejscie.
	 *
	 * @return string
	 */
	public static function normalize( string $tekst ): string {
		$tekst = Dedup::normalize_text( $tekst );

		if ( '' === $tekst ) {
			return '';
		}

		if ( function_exists( 'remove_accents' ) ) {
			$tekst = remove_accents( $tekst );
		}

		/*
		 * Male litery robi juz `Dedup::normalize_text()`. Powtorzenie jest tu
		 * SWIADOMA nadmiarowoscia: `remove_accents()` mapuje tez wersje wielkie
		 * (Ł → L), a gdyby definicja golego tekstu kiedys przestala zmieniac
		 * wielkosc liter, filtr milczaco przepuszczalby „Kot".
		 */
		$tekst = function_exists( 'mb_strtolower' ) ? mb_strtolower( $tekst, 'UTF-8' ) : strtolower( $tekst );

		return trim( (string) preg_replace( '/\s+/u', ' ', $tekst ) );
	}

	// -----------------------------------------------------------------------
	// Lista slow
	// -----------------------------------------------------------------------

	/**
	 * Slowa wykluczajace z ustawien, gotowe do porownania.
	 *
	 * @return array<int,string>
	 */
	public static function words(): array {
		$zapisane = Settings::get( 'excluded_words', array() );

		return self::clean_words( is_array( $zapisane ) ? $zapisane : array() );
	}

	/**
	 * Slowa wymagane z ustawien, gotowe do porownania.
	 *
	 * @return array<int,string>
	 */
	public static function required_words(): array {
		$zapisane = Settings::get( 'required_words', array() );

		return self::clean_words( is_array( $zapisane ) ? $zapisane : array() );
	}

	/**
	 * OBIE listy naraz, przy jednym odczycie opcji.
	 *
	 * `words()` i `required_words()` wolane osobno to dwa razy `Settings::all()`,
	 * czyli dwa odczyty opcji i dwa scalenia z domyslnymi. W petli po kanale
	 * to bez znaczenia — listy czyta sie raz na kanal, nie raz na pozycje —
	 * ale asercja „opcja czytana RAZ na kanal" jest jedynym, co pilnuje, zeby
	 * ktos tego kiedys nie przeniosl do srodka petli. Ta metoda pozwala jej
	 * zostac w mocy.
	 *
	 * @return array{excluded: array<int,string>, required: array<int,string>}
	 */
	public static function lists(): array {
		$ustawienia = Settings::all();

		$wykluczone = isset( $ustawienia['excluded_words'] ) && is_array( $ustawienia['excluded_words'] )
			? $ustawienia['excluded_words']
			: array();

		$wymagane = isset( $ustawienia['required_words'] ) && is_array( $ustawienia['required_words'] )
			? $ustawienia['required_words']
			: array();

		return array(
			'excluded' => self::clean_words( $wykluczone ),
			'required' => self::clean_words( $wymagane ),
		);
	}

	/**
	 * Normalizuje liste slow tak samo jak tekst.
	 *
	 * Klient wpisuje slowa recznie w Ustawieniach, wiec trafiaja tu ogonki,
	 * wielkie litery i podwojne spacje. Bez tej samej normalizacji po obu
	 * stronach porownanie jest loteria.
	 *
	 * @param array<int,mixed> $words Lista z ustawien.
	 *
	 * @return array<int,string>
	 */
	public static function clean_words( array $words ): array {
		$czyste = array();

		foreach ( $words as $slowo ) {
			if ( ! is_scalar( $slowo ) ) {
				continue;
			}

			$slowo = self::normalize( (string) $slowo );

			if ( '' === $slowo || in_array( $slowo, $czyste, true ) ) {
				continue;
			}

			$czyste[] = $slowo;
		}

		return $czyste;
	}
}
