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
	 * @param array<string,mixed> $item Pozycja.
	 *
	 * @return string Tekst znormalizowany.
	 */
	public static function haystack( array $item ): string {
		$czesci = array();

		foreach ( array( 'title', 'summary', 'excerpt', 'content' ) as $klucz ) {
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
