<?php
/**
 * Warstwa sieciowa: jedyne miejsce we wtyczce, ktore wychodzi na zewnatrz.
 *
 * ETAP 2.1. Klasa jest CZYSTYM narzedziem — nie zna bazy, nie zna statusow
 * pozycji i niczego nie zapisuje. Decyzje „ponowic czy odpuscic" podejmuje
 * `Runner` w Kroku 5 na podstawie zwroconego powodu.
 *
 * Trzy rzeczy, ktore ta klasa gwarantuje reszcie wtyczki:
 *
 *   1. ZADNE zadanie nie wisi w nieskonczonosc — kazde ma timeout (10 s feed,
 *      15 s artykul) i najwyzej 3 przekierowania.
 *   2. ZADNA odpowiedz nie wysadzi pamieci — limit 4 MB dla feedu i 1 MB dla
 *      scrapowanego artykulu, liczony w tych samych bajtach, ktore widzi
 *      reszta wtyczki (stad prosba o `identity`, patrz `self::ENCODING`).
 *      Limity sa ROZNE swiadomie: kanal RSS z pelna trescia (psy.pl, 20 wpisow
 *      po ~47 tys. znakow) przekracza 1 MB, a uciety w polowie XML wywraca
 *      parser.
 *   3. ZADNE wywolanie nie rzuca wyjatkiem ani nie zwraca `WP_Error` na
 *      zewnatrz — zawsze wraca tablica o stalym ksztalcie (patrz `result()`).
 *
 * ODCHYLENIE OD PLANU (swiadome): plan wymienia `wp_remote_get`, kod uzywa
 * `wp_safe_remote_get`. Adresy kanalow wpisuje czlowiek w Ustawieniach, a
 * bezpieczny wariant odrzuca adresy celujace w siec lokalna i w adresy IP
 * hosta (ochrona przed SSRF). Poza ta jedna roznica zachowanie jest
 * identyczne — te same argumenty, ta sama odpowiedz.
 *
 * @package AI_News_Portal
 */

namespace AINP;

// Blokada bezposredniego wywolania pliku.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pobieranie zasobow po HTTP z twardymi limitami.
 */
final class Http {

	/** Rodzaj zadania: kanal RSS/Atom. */
	public const FEED = 'feed';

	/** Rodzaj zadania: strona artykulu do scrapingu. */
	public const ARTICLE = 'article';

	/** Timeout pobrania kanalu (sekundy). */
	public const TIMEOUT_FEED = 10;

	/** Timeout pobrania artykulu (sekundy). */
	public const TIMEOUT_ARTICLE = 15;

	/** Sufit odpowiedzi dla kanalu: 4 MB. */
	public const LIMIT_FEED = 4194304;

	/** Sufit odpowiedzi dla artykulu: 1 MB. */
	public const LIMIT_ARTICLE = 1048576;

	/** Maksymalna liczba przekierowan. */
	public const REDIRECTS = 3;

	/** Timeout pobrania `robots.txt` — krotszy, bo to zadanie poboczne. */
	public const ROBOTS_TIMEOUT = 5;

	/** Sufit odpowiedzi dla `robots.txt`: 64 KB. */
	public const ROBOTS_LIMIT = 65536;

	/**
	 * Jak dlugo werdykt `robots.txt` siedzi w transiencie (12 godzin).
	 *
	 * Liczba wpisana wprost, nie `12 * HOUR_IN_SECONDS`: stala klasy jest
	 * wyliczana przy ladowaniu pliku, wiec zalezalaby od tego, czy WordPress
	 * zdazyl zdefiniowac swoja stala.
	 */
	public const ROBOTS_TTL = 43200;

	/**
	 * Kodowanie tresci, o ktore prosimy serwer: ZADNE.
	 *
	 * WordPress liczy `limit_response_size` na bajtach SUROWEJ odpowiedzi —
	 * tych, ktore przyszly z sieci, a wiec skompresowanych. Kod porownywal
	 * z tym limitem dlugosc tresci JUZ ROZPAKOWANEJ, a to sa dwie rozne
	 * jednostki: kompletny kanal 5 MB, ktory po drodze wazyl 400 KB, dostawal
	 * falszywy `too_large`, a naprawde uciety gzip wracal jako binarna sieczka
	 * — rozpakowanie ucietego strumienia sie nie udaje i biblioteka oddaje
	 * wtedy wejscie bez zmian.
	 *
	 * Prosba o `identity` sprowadza obie liczby do tej samej jednostki. Przy
	 * okazji wyrownuje zachowanie miedzy transportami: fsockopen tnie bajty
	 * skompresowane, cURL (`CURLOPT_ENCODING = ''`) rozpakowane — bez tego
	 * naglowka ta sama wtyczka zachowywalaby sie inaczej na dwoch hostingach.
	 *
	 * Cena to wiekszy transfer. Swiadomy wybor: przewidywalny sufit pamieci
	 * jest wart wiecej niz zaoszczedzone kilobajty przy czterech kanalach
	 * na godzine.
	 */
	public const ENCODING = 'identity';

	/**
	 * Prefiks klucza transientu z werdyktem `robots.txt`.
	 *
	 * MUSI zaczynac sie od `ainp_`: `uninstall.php` zamiata transienty
	 * wzorcami `_transient_ainp_%` i `_transient_timeout_ainp_%`. Klucz bez
	 * tego prefiksu zostalby w bazie na zawsze.
	 */
	public const ROBOTS_PREFIX = 'ainp_robots_';

	// -----------------------------------------------------------------------
	// Pobieranie
	// -----------------------------------------------------------------------

	/**
	 * Pobiera kanal RSS/Atom.
	 *
	 * @param string $url Adres kanalu.
	 *
	 * @return array<string,mixed> Wynik w ksztalcie z `result()`.
	 */
	public static function get_feed( string $url ): array {
		return self::get( $url, self::FEED );
	}

	/**
	 * Pobiera strone artykulu, respektujac `robots.txt`.
	 *
	 * Sprawdzenie `robots.txt` jest TYLKO tutaj, nie w `get_feed()`. Kanal RSS
	 * jest publikowany po to, zeby go pobierac; scraping strony to co innego.
	 *
	 * @param string $url Adres artykulu.
	 *
	 * @return array<string,mixed> Wynik w ksztalcie z `result()`.
	 */
	public static function get_article( string $url ): array {
		if ( ! self::allowed( $url ) ) {
			return self::result( false, 0, '', 'robots.txt serwisu zabrania pobierania', 'robots' );
		}

		return self::get( $url, self::ARTICLE );
	}

	/**
	 * Pobiera adres i zwraca wynik o stalym ksztalcie.
	 *
	 * @param string $url  Adres.
	 * @param string $kind Rodzaj zadania: `self::FEED` albo `self::ARTICLE`.
	 *
	 * @return array<string,mixed> Wynik w ksztalcie z `result()`.
	 */
	public static function get( string $url, string $kind = self::FEED ): array {
		$url = trim( $url );

		if ( ! self::is_http_url( $url ) ) {
			return self::result( false, 0, '', 'Adres nie jest poprawnym adresem http(s)', 'bad_url' );
		}

		$is_feed = ( self::FEED === $kind );
		$limit   = $is_feed ? self::LIMIT_FEED : self::LIMIT_ARTICLE;

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => $is_feed ? self::TIMEOUT_FEED : self::TIMEOUT_ARTICLE,
				'redirection'         => self::REDIRECTS,
				'limit_response_size' => $limit,
				'user-agent'          => self::user_agent(),
				'headers'             => array(
					'Accept'          => $is_feed
						? 'application/rss+xml, application/atom+xml, application/xml;q=0.9, text/xml;q=0.9, */*;q=0.8'
						: 'text/html, application/xhtml+xml;q=0.9, */*;q=0.8',
					'Accept-Encoding' => self::ENCODING,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return self::result( false, 0, '', $response->get_error_message(), 'transport' );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );

		/*
		 * Requests przestaje czytac po osiagnieciu limitu i oddaje tresc
		 * UCIETA, bez slowa ostrzezenia. Rozpoznajemy to po dlugosci rownej
		 * limitowi — inaczej parser XML dostalby polowe dokumentu i padl
		 * z bledem, ktory nic nie mowi o prawdziwej przyczynie.
		 *
		 * Porownanie jest uczciwe tylko dlatego, ze prosimy o `identity`
		 * (patrz `self::ENCODING`): limit i dlugosc sa wtedy w tych samych
		 * bajtach.
		 */
		$truncated = ( strlen( $body ) >= $limit );

		if ( 200 !== $code ) {
			return self::result( false, $code, '', 'Serwer odpowiedzial kodem ' . $code, 'status', $truncated );
		}

		/*
		 * Serwer moze zignorowac `identity` i skompresowac mimo wszystko.
		 * Wtedy dlugosc tresci znowu nie znaczy tego, co limit, a samo cialo
		 * jest binarna sieczka. Lepiej oddac jasny blad niz karmic parser XML
		 * albo ekstrakcje tresci bajtami gzipa.
		 */
		if ( self::looks_compressed( $body ) ) {
			return self::result(
				false,
				$code,
				'',
				'Serwer oddal tresc skompresowana mimo prosby o `identity`',
				'compressed',
				$truncated
			);
		}

		if ( $truncated && $is_feed ) {
			return self::result(
				false,
				$code,
				'',
				'Kanal przekroczyl ' . ( $limit / 1048576 ) . ' MB i zostal uciety',
				'too_large',
				true
			);
		}

		/*
		 * Uciety artykul zostaje uzyteczny: ekstrakcja tresci jest tolerancyjna
		 * na niedomkniety HTML, a `Article` i tak bierze tylko czesc tekstu.
		 * Dlatego tu `ok = true` z podniesiona flaga, a nie blad.
		 */
		return self::result( true, $code, $body, '', '', $truncated );
	}

	/**
	 * Czy warto ponowic zadanie po tym wyniku.
	 *
	 * Przejsciowe sa: blad transportu (timeout, DNS, zerwane polaczenie),
	 * 429 i cala rodzina 5xx. Trwale — 4xx (poza 429), zly adres i zakaz
	 * z `robots.txt`; ponawianie ich tylko zjada budzet czasu.
	 *
	 * @param array<string,mixed> $result Wynik z `get()`.
	 *
	 * @return bool
	 */
	public static function is_retryable( array $result ): bool {
		if ( ! empty( $result['ok'] ) ) {
			return false;
		}

		$reason = isset( $result['reason'] ) ? (string) $result['reason'] : '';
		$code   = isset( $result['code'] ) ? (int) $result['code'] : 0;

		if ( 'transport' === $reason ) {
			return true;
		}

		if ( 'status' === $reason ) {
			return ( 429 === $code || $code >= 500 );
		}

		return false;
	}

	/**
	 * Czy tresc wyglada na skompresowany strumien, a nie na tekst.
	 *
	 * Rozpoznajemy po sygnaturze pierwszych bajtow: `1f 8b` to gzip, `1f 9d`
	 * to stary `compress`, a `78` z jednym z czterech dopuszczalnych drugich
	 * bajtow to naglowek zlib (suma kontrolna naglowka musi byc podzielna
	 * przez 31). Sam bajt `78` to litera „x" — dlatego drugi bajt jest
	 * sprawdzany, inaczej dokument zaczynajacy sie od „x" bralibysmy za deflate.
	 *
	 * @param string $body Tresc odpowiedzi.
	 *
	 * @return bool
	 */
	public static function looks_compressed( string $body ): bool {
		if ( strlen( $body ) < 2 ) {
			return false;
		}

		$pierwszy = ord( $body[0] );
		$drugi    = ord( $body[1] );

		if ( 0x1F === $pierwszy && ( 0x8B === $drugi || 0x9D === $drugi ) ) {
			return true;
		}

		if ( 0x78 === $pierwszy ) {
			return in_array( $drugi, array( 0x01, 0x5E, 0x9C, 0xDA ), true );
		}

		return false;
	}

	/**
	 * Podpis wtyczki w naglowku `User-Agent`.
	 *
	 * Adres witryny w podpisie jest po to, zeby administrator zdalnego serwisu
	 * mial do kogo napisac, zanim zablokuje ruch.
	 *
	 * @return string
	 */
	public static function user_agent(): string {
		$version = defined( 'AINP_VERSION' ) ? AINP_VERSION : '0';
		$home    = function_exists( 'home_url' ) ? home_url( '/' ) : '';

		return 'AI News Portal/' . $version . ( '' !== $home ? ' (+' . $home . ')' : '' );
	}

	// -----------------------------------------------------------------------
	// robots.txt
	// -----------------------------------------------------------------------

	/**
	 * Czy `robots.txt` serwisu pozwala pobrac ten adres.
	 *
	 * Sprawdzenie jest BLANKIETOWE i taka jest intencja: pytamy wylacznie
	 * o to, czy serwis zamyka sie na wszystkich robotow (`User-agent: *`
	 * z `Disallow: /`). Nie jestesmy crawlerem chodzacym po linkach — bierzemy
	 * pojedyncze adresy, ktore serwis sam opublikowal we wlasnym kanale RSS.
	 *
	 * Werdykt jest zapamietywany w transiencie PER HOST. Jeden wspolny klucz
	 * oznaczalby, ze wynik z jednego serwisu decyduje o losie wszystkich
	 * pozostalych.
	 *
	 * @param string $url Adres do sprawdzenia.
	 *
	 * @return bool `true` takze wtedy, gdy `robots.txt` nie istnieje albo nie
	 *              dal sie pobrac — brak zakazu to nie zakaz.
	 */
	public static function allowed( string $url ): bool {
		$parts = wp_parse_url( trim( $url ) );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return false;
		}

		$scheme = strtolower( (string) $parts['scheme'] );
		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return false;
		}

		$host      = strtolower( (string) $parts['host'] );
		$authority = $host . ( empty( $parts['port'] ) ? '' : ':' . (int) $parts['port'] );
		$key       = self::ROBOTS_PREFIX . md5( $scheme . '://' . $authority );

		/*
		 * Werdykt trzymamy jako '1'/'0', nie jako `true`/`false`.
		 * `get_transient()` zwraca `false` przy braku wpisu — zapisany
		 * `false` bylby nie do odroznienia od pustego cache'u i kazdy artykul
		 * z zablokowanego serwisu pobieralby `robots.txt` od nowa.
		 */
		$cached = get_transient( $key );
		if ( '1' === $cached || '0' === $cached ) {
			return '1' === $cached;
		}

		$response = wp_safe_remote_get(
			$scheme . '://' . $authority . '/robots.txt',
			array(
				'timeout'             => self::ROBOTS_TIMEOUT,
				'redirection'         => self::REDIRECTS,
				'limit_response_size' => self::ROBOTS_LIMIT,
				'user-agent'          => self::user_agent(),
				'headers'             => array( 'Accept-Encoding' => self::ENCODING ),
			)
		);

		$verdict = true;

		if ( ! is_wp_error( $response ) ) {
			$code = (int) wp_remote_retrieve_response_code( $response );

			// Kazda odpowiedz inna niz 200 (najczesciej 404) znaczy „brak zasad".
			if ( 200 === $code ) {
				$verdict = self::robots_allows_all( (string) wp_remote_retrieve_body( $response ) );
			}
		}

		set_transient( $key, $verdict ? '1' : '0', self::ROBOTS_TTL );

		return $verdict;
	}

	/**
	 * Czy tresc `robots.txt` pozostawia otwarta droge dla dowolnego robota.
	 *
	 * Czysta funkcja, bez sieci i bez cache'u — cala logika parsowania siedzi
	 * tutaj, zeby dala sie sprawdzic testem bez atrap HTTP.
	 *
	 * Patrzymy WYLACZNIE na grupe `User-agent: *`. Blokuje nas dopiero
	 * `Disallow: /` (goly ukosnik) w tej grupie, i to tylko wtedy, gdy nie ma
	 * przy nim `Allow: /` — kolejnosc dyrektyw w grupie nie ma tu znaczenia,
	 * bo interesuje nas sam fakt otwartej furtki.
	 *
	 * @param string $robots Tresc pliku `robots.txt`.
	 *
	 * @return bool
	 */
	public static function robots_allows_all( string $robots ): bool {
		// BOM na poczatku pliku przykleilby sie do pierwszej dyrektywy.
		$robots = preg_replace( '/^\xEF\xBB\xBF/', '', $robots );
		$lines  = preg_split( '/\r\n|\r|\n/', (string) $robots );

		$in_group = false;   // Czy biezaca grupa dotyczy `*`.
		$new_group = true;   // Czy kolejny `User-agent` zaczyna nowa grupe.
		$disallow_all = false;
		$allow_all    = false;

		foreach ( $lines as $line ) {
			// Komentarz moze siedziec takze na koncu linii z dyrektywa.
			$line = trim( (string) preg_replace( '/#.*$/', '', (string) $line ) );

			if ( '' === $line || false === strpos( $line, ':' ) ) {
				continue;
			}

			list( $field, $value ) = explode( ':', $line, 2 );

			$field = strtolower( trim( $field ) );
			$value = trim( $value );

			if ( 'user-agent' === $field ) {
				/*
				 * Kilka linii `User-agent` pod rzad to JEDNA grupa o kilku
				 * adresatach — dopiero dyrektywa zamyka nabor. Bez tego
				 * „User-agent: Googlebot / User-agent: * / Disallow: /"
				 * zostaloby przypisane samemu Googlebotowi.
				 */
				if ( $new_group ) {
					$in_group = false;
				}

				$new_group = false;

				if ( '*' === $value ) {
					$in_group = true;
				}

				continue;
			}

			$new_group = true;

			if ( ! $in_group ) {
				continue;
			}

			if ( 'disallow' === $field && '/' === $value ) {
				$disallow_all = true;
			}

			if ( 'allow' === $field && '/' === $value ) {
				$allow_all = true;
			}
		}

		return ! $disallow_all || $allow_all;
	}

	// -----------------------------------------------------------------------
	// Pomocnicze
	// -----------------------------------------------------------------------

	/**
	 * Czy adres jest bezwzglednym adresem http(s) z hostem.
	 *
	 * @param string $url Adres.
	 *
	 * @return bool
	 */
	public static function is_http_url( string $url ): bool {
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return false;
		}

		$scheme = strtolower( (string) $parts['scheme'] );

		return ( 'http' === $scheme || 'https' === $scheme );
	}

	/**
	 * Staly ksztalt wyniku.
	 *
	 * @param bool   $ok        Czy tresc nadaje sie do dalszej obrobki.
	 * @param int    $code      Kod HTTP; `0`, gdy zadanie w ogole nie doszlo.
	 * @param string $body      Tresc odpowiedzi (pusta przy bledzie).
	 * @param string $error     Komunikat dla czlowieka, do kolumny `note`.
	 * @param string $reason    Powod maszynowy: `bad_url`, `robots`,
	 *                          `transport`, `status`, `too_large`.
	 * @param bool   $truncated Czy odpowiedz zostala ucieta na limicie.
	 *
	 * @return array<string,mixed>
	 */
	private static function result(
		bool $ok,
		int $code,
		string $body,
		string $error,
		string $reason = '',
		bool $truncated = false
	): array {
		return array(
			'ok'        => $ok,
			'code'      => $code,
			'body'      => $body,
			'error'     => $error,
			'reason'    => $reason,
			'truncated' => $truncated,
		);
	}
}
