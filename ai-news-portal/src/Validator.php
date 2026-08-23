<?php
/**
 * Kontrakt odpowiedzi modelu: cztery pola, ich dlugosci i kategoria z listy.
 *
 * ETAP 4.3. Ta klasa jest jedynym powodem, dla ktorego „nie publikujemy
 * blednych odpowiedzi AI" jest zabezpieczeniem, a nie deklaracja.
 *
 * DLACZEGO ISTNIEJE MIMO WYMUSZONEGO SCHEMATU. Schemat z etapu 4.1 gwarantuje
 * SKLADNIE — ze przyjdzie obiekt z czterema polami typu `string` i `topic`
 * z listy. Dokumentacja Gemini mowi wprost, ze semantyki nie gwarantuje
 * i kaze sprawdzac wartosci w aplikacji. Schemat NIE pilnuje:
 *
 *   - dlugosci: `content` moze przyjsc jako jedno zdanie albo pusty lancuch,
 *   - obcietej odpowiedzi (`finishReason: MAX_TOKENS` daje polowe artykulu,
 *     ktora nadal jest poprawnym JSON-em, bo model konczy pole w polowie),
 *   - HTML-a, ktorego nie chcemy widziec na stronie,
 *   - kategorii przy PUSTEJ liscie `enum` — a wtedy `topic` jest zwyklym
 *     lancuchem i model wpisuje, co chce.
 *
 * ODRZUCENIE JEST TERMINALNE. Odpowiedz, ktora nie spelnia kontraktu, nie idzie
 * do ponowienia: model juz raz zuzyl slot z dobowej puli i drugie podejscie
 * z tym samym materialem najczesciej konczy sie tak samo. Ponowienie (etap 4.4)
 * dotyczy WYLACZNIE niepoprawnego JSON-a, czyli obciecia w transporcie.
 *
 * @package AI_News_Portal
 */

namespace AINP;

// Blokada bezposredniego wywolania pliku.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Walidator odpowiedzi modelu.
 */
final class Validator {

	/** Tytul — dolna granica w znakach. */
	public const MIN_TITLE = 5;

	/** Tytul — gorna granica. Tyle bezpiecznie miesci sie w `post_title`. */
	public const MAX_TITLE = 140;

	/** Zajawka — dolna granica. */
	public const MIN_LEAD = 20;

	/** Zajawka — gorna granica. */
	public const MAX_LEAD = 400;

	/**
	 * Tresc — dolna granica w znakach SAMEGO TEKSTU.
	 *
	 * Liczymy tekst bez znacznikow, nie dlugosc HTML-a. Roznica nie jest
	 * kosmetyczna: dwadziescia pustych akapitow to ponad 800 znakow HTML-a
	 * i zero tresci, a prog ma pilnowac tresci.
	 */
	public const MIN_CONTENT = 800;

	/**
	 * Sprawdza odpowiedz modelu.
	 *
	 * @param mixed                  $json       Zdekodowana odpowiedz.
	 * @param array<int,string>|null $categories Kategorie; `null` z Ustawien.
	 *
	 * @return array{ok:bool,data:array<string,string>,error:string,field:string}
	 */
	public static function check( $json, ?array $categories = null ): array {
		if ( ! is_array( $json ) ) {
			return self::bad( 'Odpowiedź modelu nie jest obiektem', 'json' );
		}

		foreach ( array( 'title', 'lead', 'content', 'topic' ) as $pole ) {
			if ( ! isset( $json[ $pole ] ) || ! is_string( $json[ $pole ] ) ) {
				return self::bad( 'Brak pola „' . $pole . '” w odpowiedzi modelu', $pole );
			}
		}

		// Tytul i zajawka to czysty tekst — na stronie ida do `post_title`
		// i do zajawki, gdzie znacznik jest bledem, a nie formatowaniem.
		$title = self::plain( $json['title'] );
		$lead  = self::plain( $json['lead'] );

		$dlugosc = self::len( $title );
		if ( $dlugosc < self::MIN_TITLE || $dlugosc > self::MAX_TITLE ) {
			return self::bad(
				'Tytuł ma ' . $dlugosc . ' znaków, dozwolone ' . self::MIN_TITLE . '–' . self::MAX_TITLE,
				'title'
			);
		}

		$dlugosc = self::len( $lead );
		if ( $dlugosc < self::MIN_LEAD || $dlugosc > self::MAX_LEAD ) {
			return self::bad(
				'Zajawka ma ' . $dlugosc . ' znaków, dozwolone ' . self::MIN_LEAD . '–' . self::MAX_LEAD,
				'lead'
			);
		}

		/*
		 * `wp_kses_post()` PRZED pomiarem, nie po. Gdyby prog liczyl sie na
		 * surowej odpowiedzi, artykul zlozony ze skryptu i dwoch zdan
		 * przechodzilby prog, a na strone trafialaby sama para zdan.
		 */
		$content = wp_kses_post( (string) $json['content'] );
		$dlugosc = self::len( self::plain( $content ) );

		if ( $dlugosc < self::MIN_CONTENT ) {
			return self::bad(
				'Treść ma ' . $dlugosc . ' znaków tekstu, wymagane co najmniej ' . self::MIN_CONTENT,
				'content'
			);
		}

		$topic = self::match_topic( (string) $json['topic'], $categories );
		if ( '' === $topic ) {
			return self::bad(
				'Kategoria „' . self::plain( $json['topic'] ) . '” nie jest jedną z kategorii wtyczki',
				'topic'
			);
		}

		return array(
			'ok'    => true,
			'data'  => array(
				'title'   => $title,
				'lead'    => $lead,
				'content' => $content,
				'topic'   => $topic,
			),
			'error' => '',
			'field' => '',
		);
	}

	/**
	 * Dopasowuje kategorie z odpowiedzi do listy z Ustawien.
	 *
	 * Porownanie idzie po `remove_accents()` i `mb_strtolower()`, bo model
	 * potrafi oddac „zywienie" zamiast „Żywienie" albo „ZDROWIE" — i byloby
	 * marnotrawstwem wyrzucic z tego powodu artykul, ktory kosztowal jedno
	 * z 20 wywolan na dobe. Zwracana jest zawsze nazwa Z USTAWIEN, nigdy
	 * pisownia modelu: taksonomia ma miec jeden termin na kategorie,
	 * a nie trzy warianty zapisu.
	 *
	 * @param string                 $topic      Kategoria z odpowiedzi.
	 * @param array<int,string>|null $categories Kategorie; `null` z Ustawien.
	 *
	 * @return string Nazwa z Ustawien albo pusty lancuch.
	 */
	public static function match_topic( string $topic, ?array $categories = null ): string {
		$lista = ( null === $categories ) ? (array) Settings::get( 'categories', array() ) : $categories;
		$szukane = self::key( $topic );

		if ( '' === $szukane ) {
			return '';
		}

		foreach ( $lista as $kategoria ) {
			if ( self::key( (string) $kategoria ) === $szukane ) {
				return trim( (string) $kategoria );
			}
		}

		return '';
	}

	/**
	 * Postac porownawcza: bez ogonkow, malymi literami, bez nadmiaru spacji.
	 *
	 * @param string $tekst Wejscie.
	 *
	 * @return string
	 */
	private static function key( string $tekst ): string {
		$tekst = self::plain( $tekst );
		$tekst = remove_accents( $tekst );
		$tekst = function_exists( 'mb_strtolower' ) ? mb_strtolower( $tekst, 'UTF-8' ) : strtolower( $tekst );

		return trim( (string) preg_replace( '/\s+/u', ' ', $tekst ) );
	}

	/**
	 * Czysty tekst: bez znacznikow, bez nadmiaru bialych znakow.
	 *
	 * @param string $tekst Wejscie.
	 *
	 * @return string
	 */
	private static function plain( string $tekst ): string {
		$tekst = wp_strip_all_tags( $tekst );

		return trim( (string) preg_replace( '/[ \t]+/u', ' ', $tekst ) );
	}

	/**
	 * Dlugosc w znakach, nie w bajtach.
	 *
	 * @param string $tekst Wejscie.
	 *
	 * @return int
	 */
	private static function len( string $tekst ): int {
		return function_exists( 'mb_strlen' ) ? (int) mb_strlen( $tekst, 'UTF-8' ) : strlen( $tekst );
	}

	/**
	 * Staly ksztalt odrzucenia.
	 *
	 * @param string $error Powod, prosto do kolumny `note`.
	 * @param string $field Pole, ktore zawiodlo.
	 *
	 * @return array{ok:bool,data:array<string,string>,error:string,field:string}
	 */
	private static function bad( string $error, string $field ): array {
		return array(
			'ok'    => false,
			'data'  => array(),
			'error' => $error,
			'field' => $field,
		);
	}
}
