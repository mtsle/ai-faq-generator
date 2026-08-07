<?php
/**
 * Wywolanie modelu Gemini: atomowa rezerwacja slotu, budzet czasu, wymuszony
 * schemat odpowiedzi.
 *
 * ETAP 4.1. Klasa robi dokladnie trzy rzeczy i nic wiecej:
 *
 *   1. REZERWUJE SLOT ATOMOWO, ZANIM cokolwiek poleci w siec. Kolejnosc jest
 *      odwrotna, niz podpowiada intuicja („policz po udanym wywolaniu"),
 *      i to jest celowe: timeout albo zerwane polaczenie w trakcie zadania
 *      zuzywaja wywolanie z darmowej puli tak samo jak sukces, a licznik
 *      liczacy dopiero odpowiedzi tego NIE WIDZI. Przy suficie 20 na dobe
 *      taki blad kosztuje pol dnia pracy klienta.
 *   2. NIE POZWALA ZACZAC wywolania, ktore nie zmiesci sie w tym, co zostalo
 *      z budzetu czasu przebiegu. 30 s timeoutu w 20 s budzecie to arytmetyka,
 *      ktora nie wychodzi (pulapka B10 z planu) — timeout jest wiec
 *      DOBIERANY do pozostalego czasu, a ponizej `TIMEOUT_MIN` wywolanie
 *      w ogole sie nie zaczyna i slot zostaje nietkniety.
 *   3. WYMUSZA SCHEMAT ODPOWIEDZI, z `topic` jako `enum` z listy kategorii.
 *
 * NAZWA POLA SCHEMATU — POTWIERDZONA SONDA NA ZYWYM API 2026-08-07, tak jak
 * wymagal plan. Wynik obu prob, `gemini-2.5-flash`, `v1beta`:
 *
 *   - `response_format` na poziomie glownym  → HTTP 400 INVALID_ARGUMENT,
 *     „Unknown name \"response_format\": Cannot find field". Tego zapisu w tej
 *     wersji API po prostu nie ma. Wbrew obawie zapisanej w planie pomylka
 *     NIE jest cicha — zadanie jest odrzucane i NIE zuzywa puli.
 *   - `generationConfig.responseMimeType` + `generationConfig.responseSchema`
 *     → HTTP 200, `finishReason: STOP`, tresc odpowiedzi to poprawny JSON
 *     z dokladnie czterema zadanymi kluczami i `topic` z listy `enum`.
 *
 * Ta klasa NIE waliduje semantyki odpowiedzi — od tego jest `Validator`
 * (etap 4.3). Dokumentacja Gemini mowi wprost, ze wymuszony schemat gwarantuje
 * SKLADNIE, nie sens: dlugosci pol, prog 800 znakow i przynaleznosc `topic`
 * do listy trzeba sprawdzic po swojej stronie.
 *
 * @package AI_News_Portal
 */

namespace AINP;

// Blokada bezposredniego wywolania pliku.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Klient Gemini z licznikiem dobowym.
 */
final class Gemini {

	/**
	 * Szablon adresu REST. `%s` to nazwa modelu.
	 *
	 * Wersja `v1beta` jest ta, w ktorej sonda potwierdzila nazwe pola schematu.
	 * Zmiana na `v1` wymaga POWTORZENIA sondy — pola bywaja w tych galeziach
	 * nazwane inaczej i to jest dokladnie ta pomylka, ktorej ten komentarz ma
	 * zapobiec.
	 */
	public const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

	/** Gorny timeout pojedynczego wywolania w sekundach. */
	public const TIMEOUT_MAX = 30;

	/**
	 * Dolna granica sensownego timeoutu.
	 *
	 * Ponizej tego progu wywolania NIE ZACZYNAMY: zabraloby slot z puli
	 * i prawie na pewno urwalo sie na timeoucie.
	 */
	public const TIMEOUT_MIN = 8;

	/** Sufit odpowiedzi w bajtach — artykul to kilkanascie kB, nie megabajty. */
	public const LIMIT_RESPONSE = 1048576;

	/** Sufit tokenow wyjscia. Hojny SWIADOMIE — patrz `build_request()`. */
	public const MAX_OUTPUT_TOKENS = 8192;

	/** Temperatura. Nisko, bo zadaniem jest przepisanie tresci, nie tworczosc. */
	public const TEMPERATURE = 0.4;

	/** Ile razy powtarzamy przegrana rezerwacje slotu (CAS). */
	public const RESERVE_ATTEMPTS = 5;

	/** Domyslny model, gdy w Ustawieniach jest smiec. */
	public const FALLBACK_MODEL = 'gemini-2.5-flash';

	/** Poczatek ogrodzenia materialu zrodlowego w promptcie. */
	public const FENCE_OPEN = '<<<MATERIAL_ZRODLOWY';

	/** Koniec ogrodzenia materialu zrodlowego w promptcie. */
	public const FENCE_CLOSE = 'MATERIAL_ZRODLOWY>>>';

	/** Powod: brak klucza API w Ustawieniach. */
	public const NOTE_NO_KEY = 'Brak klucza API w Ustawieniach';

	/** Powod: wyczerpany sufit dobowy. */
	public const NOTE_CAP = 'Wyczerpany sufit dobowy wywołań AI';

	/** Powod: za malo czasu w tym przebiegu. */
	public const NOTE_TIME = 'Za mało czasu w tym przebiegu na wywołanie AI';

	/**
	 * Rezerwuje jedno wywolanie z puli dobowej. ATOMOWO.
	 *
	 * Atomowosc daje pojedynczy `UPDATE ... WHERE option_value = <stara>`
	 * (porownanie i zapis w JEDNYM zapytaniu), a nie sekwencja
	 * `get_option()` → `update_option()`, ktora miedzy odczytem a zapisem ma
	 * okno na drugi przebieg. Liczy sie `rows_affected === 1`; zero znaczy
	 * „ktos byl szybszy" i probujemy jeszcze raz na swiezej wartosci.
	 *
	 * Odczyt idzie WPROST do tabeli, z pominieciem pamieci podrecznej obiektow:
	 * przy dwoch procesach PHP cache jednego z nich nie wie o zapisie drugiego,
	 * a to jest dokladnie ten przypadek, ktorego ta metoda ma pilnowac.
	 *
	 * @param int|null $cap Sufit dobowy; `null` bierze wartosc z Ustawien.
	 *
	 * @return array{ok:bool,used:int,cap:int,reason:string} `used` to stan
	 *                PO rezerwacji, gdy `ok`; przy odmowie — stan zastany.
	 */
	public static function reserve_slot( ?int $cap = null ): array {
		global $wpdb;

		$limit = ( null === $cap ) ? (int) Settings::get( 'daily_cap', 20 ) : $cap;
		$limit = max( 0, $limit );
		$today = self::today();

		if ( 0 === $limit ) {
			return self::reservation( false, 0, 0, 'cap' );
		}

		for ( $proba = 0; $proba < self::RESERVE_ATTEMPTS; $proba++ ) {
			$raw = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
					Settings::OPTION_USAGE
				)
			);

			// Wiersza nie ma — pierwsze wywolanie w zyciu instalacji.
			if ( null === $raw ) {
				$ok = add_option( Settings::OPTION_USAGE, self::usage_value( $today, 1 ), '', false );
				if ( $ok ) {
					return self::reservation( true, 1, $limit, '' );
				}
				// Ktos wstawil wiersz w tej samej chwili — czytamy od nowa.
				continue;
			}

			$stan = self::parse_usage( $raw, $today );

			if ( $stan['count'] >= $limit ) {
				return self::reservation( false, $stan['count'], $limit, 'cap' );
			}

			$next = self::usage_value( $today, $stan['count'] + 1 );

			/*
			 * WHERE na starej wartosci to caly mechanizm. Bez niego dwa
			 * przebiegi przy stanie 19/20 zrobilyby po jednym wywolaniu
			 * i oba zapisalyby 20 — czyli 21 zadan na suficie 20.
			 */
			$rows = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
					maybe_serialize( $next ),
					Settings::OPTION_USAGE,
					$raw
				)
			);

			if ( 1 === (int) $rows ) {
				self::forget_cached_usage();
				return self::reservation( true, $stan['count'] + 1, $limit, '' );
			}
		}

		// Pula prob wyczerpana: nie wiemy, czy slot jest wolny, wiec go NIE bierzemy.
		return self::reservation( false, 0, $limit, 'busy' );
	}

	/**
	 * Stan licznika na dzis, bez rezerwowania czegokolwiek.
	 *
	 * @return array{date:string,count:int,cap:int}
	 */
	public static function usage(): array {
		$today = self::today();
		$stan  = self::parse_usage( get_option( Settings::OPTION_USAGE, '' ), $today );

		return array(
			'date'  => $today,
			'count' => $stan['count'],
			'cap'   => max( 0, (int) Settings::get( 'daily_cap', 20 ) ),
		);
	}

	/**
	 * Timeout dobrany do tego, co zostalo z budzetu przebiegu.
	 *
	 * @param float|null $remaining Pozostaly budzet w sekundach; `null` znaczy
	 *                              „bez ograniczenia" (recznie z panelu).
	 *
	 * @return int Sekundy; **0 znaczy: nie wolno zaczynac**.
	 */
	public static function timeout_for( ?float $remaining = null ): int {
		if ( null === $remaining ) {
			return self::TIMEOUT_MAX;
		}
		if ( $remaining < self::TIMEOUT_MIN ) {
			return 0;
		}
		return (int) min( self::TIMEOUT_MAX, floor( $remaining ) );
	}

	/**
	 * Cialo zadania REST.
	 *
	 * Wydzielone i publiczne SPECJALNIE po to, zeby test mogl sprawdzic ksztalt
	 * zadania bez dotykania sieci — nazwa pola schematu jest tu jedyna rzecza,
	 * ktorej pomylka kosztuje cala wartosc wymuszonego schematu.
	 *
	 * `maxOutputTokens` jest hojne swiadomie: `gemini-2.5-flash` zuzywa czesc
	 * budzetu tokenow na wewnetrzne „mysli" (zmierzone we wtyczce 1: 476 z 500
	 * tokenow poszlo na `thoughts`, na odpowiedz zostalo 24). Przy wymogu 800
	 * znakow tresci ciasny sufit skonczylby sie `finishReason: MAX_TOKENS`
	 * i pustym polem `content` — czyli zmarnowanym wywolaniem z puli 20.
	 *
	 * @param string             $prompt     Gotowy prompt (zrodlo: etap 4.2).
	 * @param array<int,string>  $categories Lista kategorii do `enum`.
	 *
	 * @return array<string,mixed>
	 */
	public static function build_request( string $prompt, array $categories ): array {
		return array(
			'contents'         => array(
				array(
					'role'  => 'user',
					'parts' => array( array( 'text' => $prompt ) ),
				),
			),
			'generationConfig' => array(
				'responseMimeType' => 'application/json',
				'responseSchema'   => self::schema( $categories ),
				'temperature'      => self::TEMPERATURE,
				'maxOutputTokens'  => self::MAX_OUTPUT_TOKENS,
			),
		);
	}

	/**
	 * Schemat odpowiedzi: cztery pola, `topic` jako `enum`.
	 *
	 * @param array<int,string> $categories Kategorie z Ustawien.
	 *
	 * @return array<string,mixed>
	 */
	public static function schema( array $categories ): array {
		$enum = array();
		foreach ( $categories as $kategoria ) {
			$kategoria = trim( (string) $kategoria );
			if ( '' !== $kategoria ) {
				$enum[] = $kategoria;
			}
		}
		$enum = array_values( array_unique( $enum ) );

		$topic = array( 'type' => 'string' );
		/*
		 * Puste `enum` jest w API bledem zadania, a nie „dowolna wartoscia".
		 * Przy pustej liscie kategorii wysylamy wiec zwykly `string`
		 * — i tak nie przejdzie przez `Validator`, ale wywolanie nie padnie
		 * na 400 z powodu, ktory z tresci bledu jest nieczytelny.
		 */
		if ( array() !== $enum ) {
			$topic['enum'] = $enum;
		}

		return array(
			'type'                 => 'object',
			'properties'           => array(
				'title'   => array( 'type' => 'string' ),
				'lead'    => array( 'type' => 'string' ),
				'content' => array( 'type' => 'string' ),
				'topic'   => $topic,
			),
			'required'             => array( 'title', 'lead', 'content', 'topic' ),
			'propertyOrdering'     => array( 'title', 'lead', 'content', 'topic' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Prompt dla jednej pozycji, zbudowany z szablonu z Ustawien.
	 *
	 * Cztery znaczniki (`{kategorie}`, `{tytul}`, `{zrodlo}`, `{tresc}`)
	 * podstawiane sa JEDNYM przebiegiem `strtr()`. To nie jest optymalizacja,
	 * tylko zabezpieczenie: przy podstawianiu po kolei znacznik stojacy
	 * w TRESCI artykulu zostalby rozwiniety w nastepnym przebiegu, czyli cudza
	 * strona decydowalaby o ksztalcie naszego promptu.
	 *
	 * Z tego samego powodu material jest opasany ogrodzeniem, a token
	 * ogrodzenia jest z tresci WYCINANY — inaczej wystarczyloby wpisac go
	 * w artykul, zeby „wyjsc" z bloku danych i dopisac wlasne polecenie.
	 *
	 * @param array<string,mixed>    $item       Pozycja: `title`, `content`, `url`.
	 * @param array<int,string>|null $categories Kategorie; `null` bierze z Ustawien.
	 * @param string|null            $template   Szablon; `null` bierze z Ustawien.
	 *
	 * @return string
	 */
	public static function prompt( array $item, ?array $categories = null, ?string $template = null ): string {
		$szablon = ( null === $template ) ? (string) Settings::get( 'prompt', '' ) : $template;
		if ( '' === trim( $szablon ) ) {
			// Klient wyczyscil pole. Pusty prompt to zmarnowane wywolanie z puli,
			// wiec wracamy do szablonu domyslnego zamiast wysylac sam material.
			$domyslne = Settings::defaults();
			$szablon  = (string) ( $domyslne['prompt'] ?? '' );
		}

		$kategorie = ( null === $categories ) ? (array) Settings::get( 'categories', array() ) : $categories;
		$lista     = array();
		foreach ( $kategorie as $kategoria ) {
			$kategoria = trim( (string) $kategoria );
			if ( '' !== $kategoria ) {
				$lista[] = $kategoria;
			}
		}

		$tresc = (string) ( $item['content'] ?? '' );
		$tresc = str_replace( array( self::FENCE_OPEN, self::FENCE_CLOSE ), '', $tresc );
		$blok  = self::FENCE_OPEN . "\n" . trim( $tresc ) . "\n" . self::FENCE_CLOSE;

		$gotowy = strtr(
			$szablon,
			array(
				'{kategorie}' => implode( ', ', array_values( array_unique( $lista ) ) ),
				'{tytul}'     => trim( (string) ( $item['title'] ?? '' ) ),
				'{zrodlo}'    => trim( (string) ( $item['url'] ?? '' ) ),
				'{tresc}'     => $blok,
			)
		);

		/*
		 * Szablon bez `{tresc}` to najgrozniejsza pomylka przy edycji pola:
		 * wywolanie idzie, pula sie zmniejsza, a model nie dostaje materialu
		 * i pisze z niczego. Dokladamy material na koncu zamiast pozwolic na to.
		 */
		if ( false === strpos( $szablon, '{tresc}' ) ) {
			$gotowy .= "\n\n" . $blok;
		}

		return $gotowy;
	}

	/**
	 * Jedno wywolanie modelu.
	 *
	 * KOLEJNOSC BRAM JEST CZESCIA KONTRAKTU i tak jest sprawdzana testem:
	 * klucz → budzet czasu → REZERWACJA SLOTU → dopiero zadanie. Kazda
	 * z trzech pierwszych bram konczy sie odmowa BEZ zuzycia puli.
	 *
	 * @param string                 $prompt     Gotowy prompt.
	 * @param array<int,string>|null $categories Kategorie; `null` bierze z Ustawien.
	 * @param float|null             $remaining  Pozostaly budzet czasu w sekundach.
	 *
	 * @return array{ok:bool,json:array<string,mixed>|null,text:string,error:string,reason:string,code:int,used:int,cap:int}
	 */
	public static function generate( string $prompt, ?array $categories = null, ?float $remaining = null ): array {
		$kategorie = ( null === $categories ) ? (array) Settings::get( 'categories', array() ) : $categories;

		$key = trim( (string) get_option( Settings::OPTION_KEY, '' ) );
		if ( '' === $key ) {
			return self::result( false, null, '', self::NOTE_NO_KEY, 'no_key' );
		}

		$timeout = self::timeout_for( $remaining );
		if ( 0 === $timeout ) {
			return self::result( false, null, '', self::NOTE_TIME, 'time' );
		}

		$slot = self::reserve_slot();
		if ( ! $slot['ok'] ) {
			$powod = ( 'cap' === $slot['reason'] ) ? self::NOTE_CAP : 'Nie udało się zarezerwować wywołania AI';
			return self::result( false, null, '', $powod, $slot['reason'], 0, $slot['used'], $slot['cap'] );
		}

		$response = wp_remote_post(
			sprintf( self::ENDPOINT, self::model() ),
			array(
				'timeout'             => $timeout,
				'redirection'         => 0,
				'limit_response_size' => self::LIMIT_RESPONSE,
				'user-agent'          => Http::user_agent(),
				'headers'             => array(
					'Content-Type' => 'application/json',
					// Klucz w NAGLOWKU, nie w adresie: adres trafia do logow
					// serwera posredniczacego i do naglowka `Referer`.
					'x-goog-api-key' => $key,
					'Accept-Encoding' => Http::ENCODING,
				),
				'body'                => wp_json_encode( self::build_request( $prompt, $kategorie ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return self::result( false, null, '', $response->get_error_message(), 'transport', 0, $slot['used'], $slot['cap'] );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );

		if ( 200 !== $code ) {
			/*
			 * 429 to `RESOURCE_EXHAUSTED`. Z wtyczki 1 wiemy, ze przy limicie
			 * DOBOWYM pole `retryDelay` z odpowiedzi wprowadza w blad — podaje
			 * kilkanascie sekund, a naprawde trzeba czekac do polnocy. Dlatego
			 * niczego z niego nie czytamy.
			 */
			$opis = ( 429 === $code )
				? 'Model odmówił: wyczerpany limit po stronie Google (429)'
				: 'Model odpowiedział kodem ' . $code;

			return self::result( false, null, '', $opis, 'status', $code, $slot['used'], $slot['cap'] );
		}

		$envelope = json_decode( $body, true );
		$text     = '';
		if ( is_array( $envelope ) && isset( $envelope['candidates'][0]['content']['parts'] ) ) {
			foreach ( (array) $envelope['candidates'][0]['content']['parts'] as $part ) {
				if ( is_array( $part ) && isset( $part['text'] ) ) {
					$text .= (string) $part['text'];
				}
			}
		}

		if ( '' === trim( $text ) ) {
			return self::result( false, null, '', 'Model zwrócił pustą odpowiedź', 'empty', $code, $slot['used'], $slot['cap'] );
		}

		$json = json_decode( $text, true );

		/*
		 * Niepoprawny JSON po wymuszeniu schematu jest praktycznie mozliwy tylko
		 * przez obciecie odpowiedzi w transporcie — dlatego surowy tekst wraca
		 * na gorze nawet przy porazce: ponowienie z etapu 4.4 opiera sie na nim.
		 */
		if ( ! is_array( $json ) ) {
			return self::result( false, null, $text, 'Odpowiedź modelu nie jest poprawnym JSON-em', 'bad_json', $code, $slot['used'], $slot['cap'] );
		}

		return self::result( true, $json, $text, '', '', $code, $slot['used'], $slot['cap'] );
	}

	/**
	 * Nazwa modelu z Ustawien, przycieta do znakow bezpiecznych w adresie.
	 *
	 * Wartosc pochodzi z pola w Ustawieniach i trafia do SCIEZKI adresu, wiec
	 * jest wejsciem uzytkownika w miejscu, w ktorym ukosnik albo dwukropek
	 * zmienialyby cel zadania. Zostaja litery, cyfry, kropka, myslnik
	 * i podkreslenie; z pustego wyniku wracamy do modelu domyslnego.
	 *
	 * @return string
	 */
	public static function model(): string {
		$model = (string) Settings::get( 'model', self::FALLBACK_MODEL );
		$model = (string) preg_replace( '/[^A-Za-z0-9._-]/', '', $model );

		return ( '' === $model ) ? self::FALLBACK_MODEL : $model;
	}

	/**
	 * Dzisiejsza data wg czasu witryny.
	 *
	 * Czas witryny, nie UTC: klient patrzy na licznik „dziś" swoim zegarem,
	 * a doba rozliczeniowa Google i tak nie pokrywa sie z zadna z tych stref.
	 *
	 * @return string
	 */
	private static function today(): string {
		return (string) current_time( 'Y-m-d' );
	}

	/**
	 * Odczytuje licznik i zeruje go, gdy zapis jest z innego dnia.
	 *
	 * @param mixed  $raw   Wartosc z opcji (byc moze serializowana).
	 * @param string $today Dzisiejsza data.
	 *
	 * @return array{date:string,count:int}
	 */
	private static function parse_usage( $raw, string $today ): array {
		$dane = maybe_unserialize( $raw );

		if ( ! is_array( $dane ) ) {
			return array(
				'date'  => $today,
				'count' => 0,
			);
		}

		$data = isset( $dane['date'] ) ? (string) $dane['date'] : '';

		return array(
			'date'  => $today,
			'count' => ( $data === $today ) ? max( 0, (int) ( $dane['count'] ?? 0 ) ) : 0,
		);
	}

	/**
	 * Ksztalt zapisu licznika.
	 *
	 * @param string $date  Data.
	 * @param int    $count Liczba wywolan.
	 *
	 * @return array{date:string,count:int}
	 */
	private static function usage_value( string $date, int $count ): array {
		return array(
			'date'  => $date,
			'count' => $count,
		);
	}

	/**
	 * Usuwa licznik z pamieci podrecznej obiektow po zapisie surowym `UPDATE`.
	 *
	 * `$wpdb->query()` omija warstwe `update_option()`, wiec bez tego kolejny
	 * `get_option()` w tym samym zadaniu oddalby wartosc sprzed rezerwacji.
	 *
	 * @return void
	 */
	private static function forget_cached_usage(): void {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( Settings::OPTION_USAGE, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
		}
	}

	/**
	 * Staly ksztalt odpowiedzi rezerwacji.
	 *
	 * @param bool   $ok     Czy slot zarezerwowany.
	 * @param int    $used   Licznik.
	 * @param int    $cap    Sufit.
	 * @param string $reason Powod odmowy: `cap`, `busy`.
	 *
	 * @return array{ok:bool,used:int,cap:int,reason:string}
	 */
	private static function reservation( bool $ok, int $used, int $cap, string $reason ): array {
		return array(
			'ok'     => $ok,
			'used'   => $used,
			'cap'    => $cap,
			'reason' => $reason,
		);
	}

	/**
	 * Staly ksztalt wyniku `generate()`.
	 *
	 * @param bool                     $ok     Powodzenie.
	 * @param array<string,mixed>|null $json   Zdekodowana odpowiedz.
	 * @param string                   $text   Surowy tekst z modelu.
	 * @param string                   $error  Opis bledu.
	 * @param string                   $reason Kod powodu.
	 * @param int                      $code   Kod HTTP.
	 * @param int                      $used   Licznik po rezerwacji.
	 * @param int                      $cap    Sufit dobowy.
	 *
	 * @return array<string,mixed>
	 */
	private static function result(
		bool $ok,
		?array $json,
		string $text,
		string $error,
		string $reason,
		int $code = 0,
		int $used = 0,
		int $cap = 0
	): array {
		return array(
			'ok'     => $ok,
			'json'   => $json,
			'text'   => $text,
			'error'  => $error,
			'reason' => $reason,
			'code'   => $code,
			'used'   => $used,
			'cap'    => $cap,
		);
	}
}
