<?php
/**
 * Odsiew duplikatow: normalizacja adresu i odciski (hashe).
 *
 * ETAP 2.3. Klasa jest CZYSTA — dostaje lancuch znakow i oddaje lancuch
 * znakow. Nie pyta bazy, czy odcisk juz istnieje: robi to sam MySQL przez
 * dwa klucze `UNIQUE` (`ainp_url_hash`, `ainp_content_hash`) i zapis przez
 * `INSERT IGNORE` w etapie 2.4. Sprawdzanie „czy juz jest" zapytaniem przed
 * zapisem to wzorzec „przeczytaj, potem zapisz" — dwa przebiegi crona
 * potrafia sie w nim minac.
 *
 * Odciski sa `sha256` w zapisie szesnastkowym: 64 znaki, dokladnie tyle, ile
 * ma `char(64)` w obu kolumnach tabeli.
 *
 * @package AI_News_Portal
 */

namespace AINP;

// Blokada bezposredniego wywolania pliku.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalizacja adresow i liczenie odciskow.
 */
final class Dedup {

	/**
	 * Parametry sledzace, ktore NIE zmieniaja tresci strony.
	 *
	 * Ten sam artykul rozeslany newsletterem, wrzucony na Facebooka i podany
	 * w kanale RSS ma trzy rozne adresy, roznice sa wylacznie tutaj. Bez tej
	 * listy kazde zrodlo zrobiloby wlasna kopie tego samego tekstu.
	 *
	 * Prefiks `utm_` obejmuje caly zestaw Google Analytics (`utm_source`,
	 * `utm_medium`, `utm_campaign`, `utm_term`, `utm_content`, `utm_id`…),
	 * wiec nie wypisujemy ich pojedynczo.
	 */
	public const TRACKING = array(
		'fbclid',
		'gclid',
		'dclid',
		'msclkid',
		'yclid',
		'igshid',
		'mc_cid',
		'mc_eid',
		'ref',
		'source',
		'_ga',
		'_gl',
	);

	/** Prefiksy parametrow sledzacych. */
	public const TRACKING_PREFIXES = array( 'utm_' );

	/** Ile poczatkowego tekstu wchodzi do odcisku tresci: 8 KB. */
	public const CONTENT_BYTES = 8192;

	// -----------------------------------------------------------------------
	// Adres
	// -----------------------------------------------------------------------

	/**
	 * Sprowadza adres do postaci porownywalnej.
	 *
	 * Kolejno: schemat i host malymi literami, domyslny port precz, kotwica
	 * `#` precz, parametry sledzace precz, reszta parametrow posortowana,
	 * koncowy ukosnik ujednolicony.
	 *
	 * @param string $url Adres z kanalu albo z `<link rel="canonical">`.
	 *
	 * @return string Adres znormalizowany albo pusty lancuch, gdy adres nie
	 *                jest bezwzglednym adresem http(s).
	 */
	public static function normalize_url( string $url ): string {
		$url   = trim( $url );
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}

		$scheme = strtolower( (string) $parts['scheme'] );

		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return '';
		}

		$host = strtolower( (string) $parts['host'] );

		/*
		 * Port domyslny dla schematu nic nie wnosi, a `https://psy.pl:443/a`
		 * i `https://psy.pl/a` to ten sam zasob. Port nietypowy zostaje.
		 */
		$port = '';
		if ( ! empty( $parts['port'] ) ) {
			$numer = (int) $parts['port'];
			$domyslny = ( 'http' === $scheme && 80 === $numer ) || ( 'https' === $scheme && 443 === $numer );
			if ( ! $domyslny ) {
				$port = ':' . $numer;
			}
		}

		$path  = isset( $parts['path'] ) ? (string) $parts['path'] : '';
		$path  = self::normalize_path( $path );
		$query = self::normalize_query( isset( $parts['query'] ) ? (string) $parts['query'] : '' );

		// Kotwica `#` jest wylacznie dla przegladarki — serwer jej nie widzi.
		return $scheme . '://' . $host . $port . $path . $query;
	}

	/**
	 * Odcisk adresu.
	 *
	 * @param string $url Adres.
	 *
	 * @return string 64 znaki albo pusty lancuch, gdy adresu nie da sie
	 *                znormalizowac (pozycja bez adresu nie ma czego dedupowac).
	 */
	public static function url_hash( string $url ): string {
		$norm = self::normalize_url( $url );

		return ( '' === $norm ) ? '' : hash( 'sha256', $norm );
	}

	/**
	 * Ujednolicony koncowy ukosnik.
	 *
	 * Wybor kierunku (ukosnik ZDEJMOWANY) jest dowolny, ale musi byc staly:
	 * `/artykul` i `/artykul/` maja dac ten sam odcisk. Sam korzen zostaje
	 * jako `/`, bo `https://psy.pl` bez sciezki i `https://psy.pl/` to
	 * ten sam zasob.
	 *
	 * @param string $path Sciezka.
	 *
	 * @return string
	 */
	private static function normalize_path( string $path ): string {
		if ( '' === $path ) {
			return '/';
		}

		// Zdwojone ukosniki w srodku sciezki nic nie znacza.
		$path = (string) preg_replace( '#/{2,}#', '/', $path );
		$path = rtrim( $path, '/' );

		return ( '' === $path ) ? '/' : $path;
	}

	/**
	 * Parametry zapytania: bez sledzacych, posortowane.
	 *
	 * Sortowanie jest po to, zeby `?a=1&b=2` i `?b=2&a=1` dawaly ten sam
	 * odcisk — kolejnosc parametrow nie zmienia tresci strony.
	 *
	 * @param string $query Czesc po znaku zapytania.
	 *
	 * @return string Pusty lancuch albo `?…`.
	 */
	private static function normalize_query( string $query ): string {
		if ( '' === trim( $query ) ) {
			return '';
		}

		$pary  = array();
		$grupy = explode( '&', $query );

		foreach ( $grupy as $para ) {
			if ( '' === $para ) {
				continue;
			}

			$rozbite = explode( '=', $para, 2 );
			$klucz   = rawurldecode( $rozbite[0] );

			if ( '' === $klucz || self::is_tracking( $klucz ) ) {
				continue;
			}

			$wartosc = isset( $rozbite[1] ) ? rawurldecode( $rozbite[1] ) : '';
			$pary[]  = rawurlencode( $klucz ) . '=' . rawurlencode( $wartosc );
		}

		if ( ! $pary ) {
			return '';
		}

		sort( $pary, SORT_STRING );

		return '?' . implode( '&', $pary );
	}

	/**
	 * Czy parametr sluzy wylacznie sledzeniu.
	 *
	 * @param string $klucz Nazwa parametru.
	 *
	 * @return bool
	 */
	public static function is_tracking( string $klucz ): bool {
		$klucz = strtolower( $klucz );

		if ( in_array( $klucz, self::TRACKING, true ) ) {
			return true;
		}

		foreach ( self::TRACKING_PREFIXES as $prefiks ) {
			if ( 0 === strpos( $klucz, $prefiks ) ) {
				return true;
			}
		}

		return false;
	}

	// -----------------------------------------------------------------------
	// Tresc
	// -----------------------------------------------------------------------

	/**
	 * Odcisk tresci — druga linia obrony, gdy ten sam tekst wisi pod dwoma
	 * roznymi adresami (przedruk, przeniesiona domena, wersja „amp").
	 *
	 * Liczony z pierwszych 8 KB tekstu po odarciu ze znacznikow: dalsza czesc
	 * artykulu i tak nie zmienia werdyktu, a caly `longtext` w odcisku
	 * kosztowalby pamiec przy kazdej pozycji.
	 *
	 * @param string $tresc Tresc HTML albo goly tekst.
	 *
	 * @return string 64 znaki albo pusty lancuch, gdy nie ma z czego liczyc.
	 *                Pusty lancuch wolajacy zapisuje jako NULL — MySQL
	 *                dopuszcza wiele NULL-i w kluczu UNIQUE, wiec pozycje
	 *                przed ekstrakcja tresci nie koliduja ze soba.
	 */
	public static function content_hash( string $tresc ): string {
		$tekst = self::normalize_text( $tresc );

		if ( '' === $tekst ) {
			return '';
		}

		return hash( 'sha256', substr( $tekst, 0, self::CONTENT_BYTES ) );
	}

	/**
	 * Tekst sprowadzony do postaci porownywalnej.
	 *
	 * Znaczniki, encje, wielkosc liter i uklad bialych znakow zmieniaja sie
	 * przy kazdym przedruku, a tresc zostaje ta sama.
	 *
	 * @param string $tresc Wejscie.
	 *
	 * @return string
	 */
	public static function normalize_text( string $tresc ): string {
		// Skrypty i style: ich TRESC nie jest tekstem artykulu, wiec znika
		// razem ze znacznikami. `strip_tags` samo zostawiloby ja w wyniku.
		$tresc = (string) preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $tresc );
		$tresc = strip_tags( $tresc );
		$tresc = html_entity_decode( $tresc, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// Twarda spacja z encji `&nbsp;` to inny bajt niz spacja.
		$tresc = str_replace( "\xC2\xA0", ' ', $tresc );
		$tresc = (string) preg_replace( '/\s+/u', ' ', $tresc );
		$tresc = trim( $tresc );

		if ( '' === $tresc ) {
			return '';
		}

		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $tresc, 'UTF-8' ) : strtolower( $tresc );
	}
}
