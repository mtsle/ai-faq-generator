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

	/**
	 * Ponizej tylu sekund nie zaczynamy zadania — ustalenie audytowe A2.
	 *
	 * Zadanie z timeoutem 2 s przy wolnym serwisie skonczy sie porazka, ktora
	 * NIE jest wina serwisu, a mimo to podbilaby licznik prob — po trzech takich
	 * przebiegach zdrowa pozycja ladowalaby na `failed`. Lepiej nie zaczynac
	 * i zostawic ja nietknieta do nastepnego ticku.
	 *
	 * TRZY, nie piec: dzialka fazy zbierania to cwiartka budzetu ticku, czyli
	 * przy zalozonych 20 s dokladnie 5 s. Prog rowny dzialce odrzucalby PIERWSZY
	 * kanal kazdego przebiegu — o ulamek sekundy, ktory zdazyl uplynac.
	 */
	public const MIN_SECONDS = 3;

	/**
	 * Najmniejszy odstep miedzy dwoma zadaniami do TEGO SAMEGO hosta: 3 s.
	 *
	 * WLASNA stala, nie `MIN_SECONDS`. Do wersji 1.0.0 trzy kotwice dokumentu
	 * (ZACH-W2-13, REG-W2-18, DZIED-11) wskazywaly `MIN_SECONDS` jako miejsce,
	 * w ktorym ta regula zyje — a `MIN_SECONDS` opisuje CO INNEGO: dolny prog
	 * pozostalego budzetu w `timeout_for()`. Obie liczby to 3, wiec rozjazd byl
	 * niewidoczny, a odstepu nie bylo w produkcie w ogole (zero trafien na
	 * `sleep`/`usleep` w calym `ai-news-portal/src`).
	 *
	 * Odstep jest EGZEKWOWANY POMINIECIEM, nie uspieniem. Blokujace `sleep()`
	 * zjadaloby budzet ticku, ktory na malym hostingu jest i tak za krotki
	 * (RAU-R13-003) — pozycja odlozona wraca w nastepnym ticku nietknieta,
	 * bez podbitego licznika prob.
	 */
	public const MIN_HOST_GAP = 3;

	/**
	 * Kiedy ostatnio wyszlo zadanie do danego hosta — znacznik w PAMIECI.
	 *
	 * Nie transient i nie opcja: odstep ma sens w obrebie JEDNEGO przebiegu,
	 * bo miedzy tickami mija godzina. Zapis do bazy przy kazdym zadaniu bylby
	 * kosztem bez zysku. Klucz to `schemat://host:port`, wartosc to wynik
	 * `microtime( true )` po zakonczeniu zadania.
	 *
	 * @var array<string,float>
	 */
	private static $ostatni_kontakt = array();

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
	public static function get_feed( string $url, ?float $remaining = null ): array {
		return self::get( $url, self::FEED, $remaining );
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
	public static function get_article( string $url, ?float $remaining = null ): array {
		$start = microtime( true );

		/*
		 * Bramka budzetu PRZED `robots.txt`, nie za nim. `allowed()` bez budzetu
		 * oddaje `false`, czyli „nie pobieraj" — a to jest powod TRWALY i odeslaloby
		 * zdrowa pozycje na `failed` za brak czasu, nie za cudzy zakaz.
		 */
		if ( self::timeout_for( self::TIMEOUT_ARTICLE, $remaining ) <= 0 ) {
			return self::result( false, 0, '', 'Za mało czasu w tym przebiegu na pobranie strony', 'budget' );
		}

		if ( ! self::allowed( $url, $remaining ) ) {
			return self::result( false, 0, '', 'robots.txt serwisu zabrania pobierania', 'robots' );
		}

		/*
		 * `robots.txt` to OSOBNE zadanie i osobny kawalek budzetu — przy pierwszym
		 * kontakcie z hostem kosztuje do `ROBOTS_TIMEOUT`. Bez odjecia go tutaj
		 * pobranie strony dostaloby budzet, ktorego czesc juz nie istnieje.
		 */
		$zostalo = ( null === $remaining ) ? null : $remaining - ( microtime( true ) - $start );

		return self::get( $url, self::ARTICLE, $zostalo );
	}

	/**
	 * Pobiera adres i zwraca wynik o stalym ksztalcie.
	 *
	 * @param string $url  Adres.
	 * @param string $kind Rodzaj zadania: `self::FEED` albo `self::ARTICLE`.
	 *
	 * @return array<string,mixed> Wynik w ksztalcie z `result()`.
	 */
	public static function get( string $url, string $kind = self::FEED, ?float $remaining = null ): array {
		$url = trim( $url );

		if ( ! self::is_http_url( $url ) ) {
			return self::result( false, 0, '', 'Adres nie jest poprawnym adresem http(s)', 'bad_url' );
		}

		$is_feed = ( self::FEED === $kind );
		$limit   = $is_feed ? self::LIMIT_FEED : self::LIMIT_ARTICLE;
		$timeout = self::timeout_for( $is_feed ? self::TIMEOUT_FEED : self::TIMEOUT_ARTICLE, $remaining );

		/*
		 * USTALENIE AUDYTOWE A2. Bez przyciecia jedno zadanie potrafilo przekroczyc
		 * budzet CALEGO ticku: budzet sprawdzany jest miedzy pozycjami, a pozycja
		 * w trakcie ma prawo isc az do wlasnego timeoutu. Zmierzone: tick z budzetem
		 * 20 s trwal 30 s (kanal 10 s + robots.txt 5 s + strona 15 s), faza publikacji
		 * wypadala w calosci, a na hostingu z `max_execution_time` 30 s proces ginal.
		 */
		if ( $timeout <= 0 ) {
			return self::result( false, 0, '', 'Za mało czasu w tym przebiegu na pobranie adresu', 'budget' );
		}

		/*
		 * GRZECZNOSC WOBEC ZRODLA (ZACH-W2-13, REG-W2-18, DZIED-11). Bramka stoi
		 * PRZED zadaniem — inaczej odstep bylby liczony, ale nie egzekwowany.
		 * Pozycja nie jest bledem: wraca w nastepnym ticku nietknieta.
		 */
		$klucz_hosta = self::host_key( $url );
		$do_odczekania = self::host_gap_remaining( $klucz_hosta );

		if ( $do_odczekania > 0.0 ) {
			return self::result(
				false,
				0,
				'',
				'Odstęp między żądaniami do tego samego serwisu — pozycja wróci w kolejnym przebiegu',
				'host_gap'
			);
		}

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => $timeout,
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

		/*
		 * Znacznik ustawiany PO zadaniu i takze przy bledzie: zadanie WYSZLO,
		 * wiec serwer je zobaczyl. Liczenie odstepu od zakonczenia, nie od
		 * rozpoczecia, jest ostrzejsze — i o to w grzecznosci chodzi.
		 */
		self::mark_host( $klucz_hosta );

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
	/**
	 * Klucz hosta dla znacznika odstepu: `schemat://host:port`.
	 *
	 * Port jest czescia klucza, bo `example.org` i `example.org:8080` to z
	 * punktu widzenia obciazenia dwie rozne uslugi. Adres nie do rozlozenia
	 * daje pusty klucz — takie zadanie i tak odpadnie na `is_http_url()`.
	 *
	 * @param string $url Adres.
	 *
	 * @return string
	 */
	public static function host_key( string $url ): string {
		$parts = wp_parse_url( trim( $url ) );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return '';
		}

		return strtolower( (string) $parts['scheme'] ) . '://'
			. strtolower( (string) $parts['host'] )
			. ( empty( $parts['port'] ) ? '' : ':' . (int) $parts['port'] );
	}

	/**
	 * Ile sekund brakuje do konca odstepu dla tego hosta.
	 *
	 * @param string $klucz Klucz z `host_key()`.
	 *
	 * @return float Zero, gdy wolno pytac od razu.
	 */
	public static function host_gap_remaining( string $klucz ): float {
		if ( '' === $klucz || ! isset( self::$ostatni_kontakt[ $klucz ] ) ) {
			return 0.0;
		}

		$minelo = microtime( true ) - self::$ostatni_kontakt[ $klucz ];

		return ( $minelo >= self::MIN_HOST_GAP ) ? 0.0 : ( self::MIN_HOST_GAP - $minelo );
	}

	/**
	 * Odnotowuje, ze wlasnie wyszlo zadanie do tego hosta.
	 *
	 * @param string $klucz Klucz z `host_key()`.
	 *
	 * @return void
	 */
	private static function mark_host( string $klucz ): void {
		if ( '' !== $klucz ) {
			self::$ostatni_kontakt[ $klucz ] = microtime( true );
		}
	}

	/**
	 * Czysci znaczniki odstepu — WYLACZNIE dla testow.
	 *
	 * Znaczniki zyja w pamieci procesu, wiec w produkcie kazdy tick zaczyna
	 * z pusta tablica i ta metoda nie ma tam wywolania. W tescie pozwala
	 * sprawdzic dwa scenariusze w jednym procesie.
	 *
	 * @return void
	 */
	public static function reset_host_gaps(): void {
		self::$ostatni_kontakt = array();
	}

	/**
	 * Timeout zadania przyciety do pozostalego budzetu przebiegu.
	 *
	 * `null` znaczy „bez budzetu" — tak wola panel, gdzie na koncu czeka
	 * czlowiek. Zwrocone `0` znaczy „nie zaczynaj".
	 *
	 * UWAGA: `MIN_SECONDS` uzyte nizej to prog POZOSTALEGO BUDZETU, a NIE
	 * odstep miedzy zadaniami do hosta — ten mieszka w `MIN_HOST_GAP`.
	 * Obie liczby to 3 i do wersji 1.0.0 trzy kotwice dokumentu myllily je
	 * ze soba.
	 *
	 * @param int        $domyslny  Timeout wlasciwy dla rodzaju zadania.
	 * @param float|null $remaining Pozostaly budzet w sekundach.
	 *
	 * @return int
	 */
	public static function timeout_for( int $domyslny, ?float $remaining = null ): int {
		if ( null === $remaining ) {
			return $domyslny;
		}

		if ( $remaining < self::MIN_SECONDS ) {
			return 0;
		}

		return (int) min( $domyslny, floor( $remaining ) );
	}

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
	public static function allowed( string $url, ?float $remaining = null ): bool {
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

		$robots_timeout = self::timeout_for( self::ROBOTS_TIMEOUT, $remaining );

		/*
		 * Brak budzetu na `robots.txt` NIE moze znaczyc „wolno pobierac" — to byloby
		 * obchodzenie cudzego zakazu zegarkiem. Zwracamy `false`, czyli „nie pobieraj",
		 * i NIE zapisujemy werdyktu do cache'u, wiec nastepny przebieg zapyta uczciwie.
		 */
		if ( $robots_timeout <= 0 ) {
			return false;
		}

		/*
		 * `robots.txt` NIE podlega odstepowi z `MIN_HOST_GAP` i sam go nie
		 * ustawia. To nie jest drugie odwiedziny serwisu, tylko sprawdzenie
		 * zasad TUZ PRZED jednym zadaniem o tresc — i dzieje sie raz na 12 h
		 * (`ROBOTS_TTL`). Objecie go odstepem odkladaloby kazdy pierwszy
		 * artykul z hosta o caly tick, a pominiecie samego sprawdzenia
		 * oznaczaloby pobieranie bez znajomosci zasad. Odstep chroni przed
		 * seryjnym zasysaniem TRESCI i tam jest egzekwowany — w `get()`.
		 */
		$response = wp_safe_remote_get(
			$scheme . '://' . $authority . '/robots.txt',
			array(
				'timeout'             => $robots_timeout,
				'redirection'         => self::REDIRECTS,
				'limit_response_size' => self::ROBOTS_LIMIT,
				'user-agent'          => self::user_agent(),
				'headers'             => array( 'Accept-Encoding' => self::ENCODING ),
			)
		);

		/*
		 * BLAD TRANSPORTU NIE TRAFIA DO CACHE'U. Zwracane `true` zostaje —
		 * „brak zakazu to nie zakaz" jest udokumentowane i obronne. Wada byla
		 * w tym, ze werdykt z NIEUDANEGO pobrania szedl do transientu na 12 h:
		 * jeden timeout albo blad DNS przy pierwszym w oknie pobraniu
		 * `robots.txt` hosta z `Disallow: /` wylaczal ochrone az do wygasniecia
		 * wpisu, czyli przez zdarzenie, przed ktorym mial chronic.
		 *
		 * Wzorzec poprawnego zachowania stoi 25 linii wyzej, w galezi braku
		 * budzetu: tam werdykt tez nie idzie do cache'u.
		 */
		if ( is_wp_error( $response ) ) {
			return true;
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$verdict = true;

		// Kazda odpowiedz inna niz 200 (najczesciej 404) znaczy „brak zasad".
		if ( 200 === $code ) {
			$verdict = self::robots_allows_all( (string) wp_remote_retrieve_body( $response ) );
		}

		// Jedyny `set_transient` w tym pliku — osiagalny WYLACZNIE po udanym pobraniu.
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
