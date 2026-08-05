<?php
/**
 * Krok 2, etap 2.4 — zapis pozycji `AINP\Runner`.
 *
 * Sedno testu: zapis idzie przez `INSERT IGNORE`, duplikat poznajemy po
 * LICZBIE ZMIENIONYCH WIERSZY (a nie po bledzie, bo `INSERT IGNORE` zostawia
 * `last_error` pusty), emoji przechodzi przez `wp_encode_emoji()`, a padniete
 * zrodlo albo padnieta pozycja nie zatrzymuja calego przebiegu.
 *
 * `Http`, `Feed`, `Plugin` i `Settings` sa tu ATRAPAMI w tej samej przestrzeni
 * nazw — testujemy `Runner`, nie ich. `Dedup` jest PRAWDZIWY, bo od niego
 * zalezy odcisk trafiajacy do kolumny z kluczem UNIQUE.
 *
 * URUCHOMIENIE:  php tests/krok2-zapis-test.php
 * Kod wyjscia: 0 = OK, 1 = bledy.
 *
 * @package AI_News_Portal
 */

namespace {
	define( 'ABSPATH', __DIR__ . '/atrapy/wp/' );

	$root = dirname( __DIR__ );
	$fail = 0;
	$ran  = 0;

	/**
	 * Asercja.
	 *
	 * @param bool   $cond  Warunek.
	 * @param string $label Opis.
	 *
	 * @return void
	 */
	function k2z_check( $cond, $label ) {
		global $fail, $ran;
		$ran++;
		echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n";
		if ( ! $cond ) {
			$fail++;
		}
	}

	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}

	function current_time( $type, $gmt = 0 ) {
		return '2026-08-05 12:00:00';
	}

	/**
	 * Atrapa `wp_encode_emoji()` — zamienia znaki spoza plaszczyzny
	 * podstawowej na encje HTML, tak jak robi to WordPress.
	 *
	 * @param string $tekst Wejscie.
	 *
	 * @return string
	 */
	function wp_encode_emoji( $tekst ) {
		return preg_replace_callback(
			'/[\x{10000}-\x{10FFFF}]/u',
			function ( $m ) {
				return '&#x' . dechex( mb_ord( $m[0], 'UTF-8' ) ) . ';';
			},
			$tekst
		);
	}

	function get_option( $key, $default = false ) {
		return array_key_exists( $key, $GLOBALS['__opt'] ) ? $GLOBALS['__opt'][ $key ] : $default;
	}

	$GLOBALS['__opt'] = array();

	/**
	 * Atrapa `$wpdb` — zapamietuje zapytania i oddaje zaplanowane wyniki.
	 */
	class AINP_Fake_WPDB {

		public $prefix     = 'wp_';
		public $last_error = '';
		public $prepared   = 0;
		public $queries    = array();

		/** Kolejka wynikow `query()`; gdy pusta, uzywany jest `$domyslny`. */
		public $wyniki = array();

		/** Wynik `query()` poza kolejka. */
		public $domyslny = 1;

		/** Gdy prawda, `query()` rzuca wyjatkiem. */
		public $rzuca = false;

		public function prepare( $sql, ...$args ) {
			$this->prepared++;
			$i = 0;

			return preg_replace_callback(
				'/%[sd]/',
				function ( $m ) use ( &$i, $args ) {
					$v = array_key_exists( $i, $args ) ? $args[ $i ] : '';
					$i++;
					if ( '%d' === $m[0] ) {
						return (string) (int) $v;
					}
					return "'" . str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), (string) $v ) . "'";
				},
				$sql
			);
		}

		public function query( $sql ) {
			$this->queries[] = $sql;

			if ( $this->rzuca ) {
				throw new \RuntimeException( 'baza padla' );
			}

			if ( $this->wyniki ) {
				return array_shift( $this->wyniki );
			}

			return $this->domyslny;
		}
	}
}

namespace AINP {

	/** Atrapa warstwy sieciowej. */
	class Http {

		public static function get_feed( string $url ): array {
			$GLOBALS['__pobrania'][] = $url;

			if ( isset( $GLOBALS['__feeds'][ $url ] ) ) {
				$plan = $GLOBALS['__feeds'][ $url ];

				if ( 'throw' === $plan ) {
					throw new \RuntimeException( 'siec padla' );
				}

				return $plan;
			}

			return array( 'ok' => false, 'code' => 0, 'body' => '', 'error' => 'brak planu', 'reason' => 'transport', 'truncated' => false );
		}

		public static function is_http_url( string $url ): bool {
			$parts = parse_url( $url );

			if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
				return false;
			}

			$scheme = strtolower( (string) $parts['scheme'] );

			return ( 'http' === $scheme || 'https' === $scheme );
		}
	}

	/** Atrapa parsera kanalow. */
	class Feed {

		public static function parse( string $xml ): array {
			if ( isset( $GLOBALS['__parsed'][ $xml ] ) ) {
				return $GLOBALS['__parsed'][ $xml ];
			}

			return array( 'ok' => false, 'error' => 'brak planu', 'format' => '', 'title' => '', 'items' => array(), 'skipped' => 0 );
		}
	}

	/** Atrapa bootstrapu — potrzebna jest tylko nazwa tabeli. */
	class Plugin {

		public static function table(): string {
			return 'wp_ainp_items';
		}
	}

	/** Atrapa ustawien: nazwa opcji ze zrodlami i lista domyslna. */
	class Settings {

		public const OPTION_SOURCES = 'ainp_sources';

		public static function default_sources(): array {
			return array( 'https://domyslny-1.pl/feed/', 'https://domyslny-2.pl/feed/' );
		}
	}
}

namespace {

	require_once $root . '/src/Dedup.php';
	require_once $root . '/src/Runner.php';

	use AINP\Runner;
	use AINP\Dedup;

	/**
	 * Ustawia atrapy w stan poczatkowy.
	 *
	 * @return AINP_Fake_WPDB
	 */
	function k2z_reset() {
		$GLOBALS['__feeds']     = array();
		$GLOBALS['__parsed']    = array();
		$GLOBALS['__pobrania']  = array();
		$GLOBALS['__opt']       = array();
		$GLOBALS['wpdb']        = new AINP_Fake_WPDB();

		return $GLOBALS['wpdb'];
	}

	/**
	 * Buduje odpowiedz `Http::get_feed()`.
	 *
	 * @param string $body Tresc.
	 *
	 * @return array
	 */
	function k2z_ok( $body ) {
		return array( 'ok' => true, 'code' => 200, 'body' => $body, 'error' => '', 'reason' => '', 'truncated' => false );
	}

	/**
	 * Buduje wynik `Feed::parse()`.
	 *
	 * @param array $items   Pozycje.
	 * @param int   $skipped Pominiete przez parser.
	 *
	 * @return array
	 */
	function k2z_kanal( $items, $skipped = 0 ) {
		return array( 'ok' => true, 'error' => '', 'format' => 'rss', 'title' => 'Kanal', 'items' => $items, 'skipped' => $skipped );
	}

	/**
	 * Buduje pozycje kanalu.
	 *
	 * @param string $url     Adres.
	 * @param string $title   Tytul.
	 * @param string $content Tresc.
	 *
	 * @return array
	 */
	function k2z_pozycja( $url, $title = 'Tytul', $content = 'Tresc' ) {
		return array(
			'title'     => $title,
			'url'       => $url,
			'summary'   => 'Zajawka',
			'content'   => $content,
			'guid'      => $url,
			'published' => 0,
		);
	}

	echo "=== KROK 2 / ETAP 2.4 — zapis pozycji (Runner) ===\n\n";

	// -----------------------------------------------------------------------
	echo "-- Ksztalt zapytania --\n";
	// -----------------------------------------------------------------------
	$wpdb = k2z_reset();
	$los  = Runner::insert_item( k2z_pozycja( 'https://psy.pl/a/', "Karma O'Briena", 'Tresc artykulu' ) );

	k2z_check( 'added' === $los, 'nowa pozycja: wynik `added`' );
	k2z_check( 1 === count( $wpdb->queries ), 'nowa pozycja: dokladnie jedno zapytanie' );

	$sql = $wpdb->queries[0];

	k2z_check( 0 === strpos( $sql, 'INSERT IGNORE INTO wp_ainp_items' ), 'zapis idzie przez INSERT IGNORE' );
	k2z_check( 1 === $wpdb->prepared, 'zapytanie zbudowane przez $wpdb->prepare()' );
	k2z_check( false !== strpos( $sql, "'https://psy.pl/a/'" ), 'adres trafia do zapytania' );
	k2z_check(
		false !== strpos( $sql, "'" . Dedup::url_hash( 'https://psy.pl/a/' ) . "'" ),
		'odcisk liczony PRAWDZIWYM Dedupem trafia do zapytania'
	);
	k2z_check( false !== strpos( $sql, "'new'" ), 'status nowej pozycji to `new`' );
	k2z_check( 2 === substr_count( $sql, "'2026-08-05 12:00:00'" ), 'created_at i updated_at ustawione na ten sam czas' );
	k2z_check( false !== strpos( $sql, "O\\'Briena" ), 'apostrof w tytule jest escapowany, nie wpuszczony surowo' );

	// Adres z kanalu bywa otoczony bialymi znakami (wciecie w XML-u).
	// Do kolumny ma trafic postac przycieta, inaczej ten sam artykul zapisany
	// raz ze spacja, a raz bez, da dwa rozne wiersze przy tym samym odcisku.
	$wpdb = k2z_reset();
	Runner::insert_item( k2z_pozycja( "  https://psy.pl/spacje/\n" ) );
	k2z_check(
		false !== strpos( $wpdb->queries[0], "'https://psy.pl/spacje/'" ),
		'adres zapisany bez otaczajacych bialych znakow'
	);

	// -----------------------------------------------------------------------
	echo "\n-- Emoji (blad 1366 przy DB_CHARSET=utf8) --\n";
	// -----------------------------------------------------------------------
	$wpdb    = k2z_reset();
	$pozycja = k2z_pozycja( 'https://psy.pl/emoji/', 'Pies 🐕 i kot', 'Tresc 🐾 z emoji' );
	// Emoji trafia takze do zajawki — kolumna `excerpt` ma ten sam problem
	// z bledem 1366 co `title` i `content`.
	$pozycja['summary'] = 'Zajawka 🦴 z emoji';
	Runner::insert_item( $pozycja );
	$sql = $wpdb->queries[0];

	k2z_check( false !== strpos( $sql, '&#x1f415;' ), 'emoji w TYTULE zamienione na encje' );
	k2z_check( false !== strpos( $sql, '&#x1f43e;' ), 'emoji w TRESCI zamienione na encje' );
	k2z_check( false !== strpos( $sql, '&#x1f9b4;' ), 'emoji w ZAJAWCE zamienione na encje' );
	k2z_check( false === strpos( $sql, "\xF0\x9F\x90\x95" ), 'surowy czterobajtowy znak nie trafia do bazy' );

	// -----------------------------------------------------------------------
	echo "\n-- Duplikat poznajemy po liczbie wierszy, nie po bledzie --\n";
	// -----------------------------------------------------------------------
	$wpdb             = k2z_reset();
	$wpdb->domyslny   = 0;      // INSERT IGNORE odrzucil wiersz na kluczu UNIQUE.
	$wpdb->last_error = '';     // ...i NIE ustawil bledu. Tak wlasnie robi MySQL.
	$los              = Runner::insert_item( k2z_pozycja( 'https://psy.pl/a/' ) );

	k2z_check( 'duplicate' === $los, 'zero zmienionych wierszy = duplikat' );
	k2z_check( '' === $wpdb->last_error, 'duplikat NIE zostawia bledu w last_error' );

	$wpdb           = k2z_reset();
	$wpdb->domyslny = false;    // Prawdziwy blad zapytania.
	k2z_check( 'error' === Runner::insert_item( k2z_pozycja( 'https://psy.pl/a/' ) ), 'false z query() = blad, nie duplikat' );

	// -----------------------------------------------------------------------
	echo "\n-- Pozycje, ktore nie maja prawa trafic do bazy --\n";
	// -----------------------------------------------------------------------
	$zle = array(
		array( array(), 'pozycja bez klucza url' ),
		array( array( 'url' => '' ), 'pusty adres' ),
		array( array( 'url' => '   ' ), 'same spacje' ),
		array( array( 'url' => '/wzgledny' ), 'adres wzgledny' ),
		array( array( 'url' => 'ftp://psy.pl/a' ), 'schemat inny niz http(s)' ),
		array( array( 'url' => 'https://psy.pl/' . str_repeat( 'a', 2100 ) ), 'adres dluzszy niz kolumna varchar(2048)' ),
	);

	foreach ( $zle as $z ) {
		list( $pozycja, $opis ) = $z;
		$wpdb                   = k2z_reset();
		k2z_check( 'invalid' === Runner::insert_item( $pozycja ), 'odrzucone: ' . $opis );
		k2z_check( 0 === count( $wpdb->queries ), 'bez zapytania do bazy: ' . $opis );
	}

	// -----------------------------------------------------------------------
	echo "\n-- Caly przebieg: dwa zrodla, jedno padniete --\n";
	// -----------------------------------------------------------------------
	$wpdb = k2z_reset();

	$GLOBALS['__feeds'] = array(
		'https://psy.pl/feed/'      => k2z_ok( 'XML-A' ),
		'https://padniety.pl/feed/' => array( 'ok' => false, 'code' => 503, 'body' => '', 'error' => 'Serwer odpowiedzial kodem 503', 'reason' => 'status', 'truncated' => false ),
		'https://drugi.pl/feed/'    => k2z_ok( 'XML-B' ),
	);
	$GLOBALS['__parsed'] = array(
		'XML-A' => k2z_kanal(
			array(
				k2z_pozycja( 'https://psy.pl/1/' ),
				k2z_pozycja( 'https://psy.pl/2/' ),
				k2z_pozycja( 'bez-adresu' ),
			),
			1
		),
		'XML-B' => k2z_kanal( array( k2z_pozycja( 'https://drugi.pl/1/' ) ) ),
	);
	// Pierwsza pozycja nowa, druga duplikat, potem znowu nowa.
	$wpdb->wyniki = array( 1, 0, 1 );

	$w = Runner::collect(
		array( 'https://psy.pl/feed/', 'https://padniety.pl/feed/', 'https://drugi.pl/feed/' )
	);

	k2z_check( 3 === $w['sources'], 'policzone trzy zrodla' );
	k2z_check( 2 === $w['added'], 'dwie nowe pozycje' );
	k2z_check( 1 === $w['duplicates'], 'jeden duplikat' );
	k2z_check( 2 === $w['invalid'], 'dwie pozycje bez adresu: jedna z parsera, jedna z zapisu' );
	k2z_check( 1 === count( $w['errors'] ), 'dokladnie jeden wpis o bledzie' );
	k2z_check(
		isset( $w['errors']['https://padniety.pl/feed/'] ),
		'blad przypisany do WLASCIWEGO zrodla'
	);
	k2z_check(
		'Serwer odpowiedzial kodem 503' === $w['errors']['https://padniety.pl/feed/'],
		'komunikat przepisany z warstwy sieciowej, a nie zastapiony bledem parsera'
	);
	k2z_check(
		! isset( $w['per_source']['https://padniety.pl/feed/']['items'] )
			&& 0 === $w['per_source']['https://padniety.pl/feed/']['added'],
		'padniete zrodlo nie idzie do parsera z pusta trescia'
	);
	k2z_check(
		1 === $w['per_source']['https://drugi.pl/feed/']['added'],
		'zrodlo PO padnietym zostalo przetworzone'
	);
	k2z_check( 3 === count( $GLOBALS['__pobrania'] ), 'kazde zrodlo probowane dokladnie raz' );

	// Kanal, ktorego nie da sie rozlozyc.
	$wpdb                = k2z_reset();
	$GLOBALS['__feeds']  = array( 'https://psy.pl/feed/' => k2z_ok( 'SMIEC' ) );
	$GLOBALS['__parsed'] = array( 'SMIEC' => array( 'ok' => false, 'error' => 'Kanal nie jest poprawnym XML-em', 'format' => '', 'title' => '', 'items' => array(), 'skipped' => 0 ) );
	$w                   = Runner::collect( array( 'https://psy.pl/feed/' ) );

	k2z_check( 0 === $w['added'], 'kanal nie do rozlozenia: nic nie zapisane' );
	k2z_check(
		false !== strpos( $w['errors']['https://psy.pl/feed/'], 'XML' ),
		'kanal nie do rozlozenia: powod przepisany z parsera'
	);

	// -----------------------------------------------------------------------
	echo "\n-- Nic sie nie zatrzymuje --\n";
	// -----------------------------------------------------------------------
	$wpdb               = k2z_reset();
	$GLOBALS['__feeds'] = array(
		'https://wybucha.pl/feed/' => 'throw',
		'https://psy.pl/feed/'     => k2z_ok( 'XML-A' ),
	);
	$GLOBALS['__parsed'] = array( 'XML-A' => k2z_kanal( array( k2z_pozycja( 'https://psy.pl/1/' ) ) ) );

	$w = Runner::collect( array( 'https://wybucha.pl/feed/', 'https://psy.pl/feed/' ) );

	k2z_check( 1 === $w['added'], 'wyjatek w zrodle nie przerywa przebiegu' );
	k2z_check(
		false !== strpos( $w['errors']['https://wybucha.pl/feed/'], 'siec padla' ),
		'wyjatek zapisany jako blad zrodla, z komunikatem'
	);

	// Wyjatek przy zapisie pojedynczej pozycji.
	$wpdb                = k2z_reset();
	$wpdb->rzuca         = true;
	$GLOBALS['__feeds']  = array( 'https://psy.pl/feed/' => k2z_ok( 'XML-A' ) );
	$GLOBALS['__parsed'] = array(
		'XML-A' => k2z_kanal( array( k2z_pozycja( 'https://psy.pl/1/' ), k2z_pozycja( 'https://psy.pl/2/' ) ) ),
	);
	$w = Runner::collect( array( 'https://psy.pl/feed/' ) );

	k2z_check( 2 === $w['failed'], 'wyjatek przy zapisie: obie pozycje policzone jako nieudane' );
	k2z_check( 2 === count( $wpdb->queries ), 'wyjatek przy pierwszej pozycji nie przerywa petli' );
	k2z_check( 0 === $w['invalid'], 'blad bazy NIE jest liczony jako pozycja bez adresu' );

	// -----------------------------------------------------------------------
	echo "\n-- Sufit pozycji z jednego kanalu --\n";
	// -----------------------------------------------------------------------
	$wpdb    = k2z_reset();
	$pozycje = array();
	for ( $i = 0; $i < 105; $i++ ) {
		$pozycje[] = k2z_pozycja( 'https://psy.pl/' . $i . '/' );
	}
	$GLOBALS['__feeds']  = array( 'https://psy.pl/feed/' => k2z_ok( 'XML-DUZY' ) );
	$GLOBALS['__parsed'] = array( 'XML-DUZY' => k2z_kanal( $pozycje ) );

	$w = Runner::collect( array( 'https://psy.pl/feed/' ) );

	k2z_check( 100 === $w['added'], 'z kanalu wziete dokladnie 100 pozycji' );
	k2z_check( 5 === $w['dropped'], 'nadmiarowe 5 policzone, nie zgubione po cichu' );
	k2z_check( 100 === count( $wpdb->queries ), 'sufit dziala PRZED zapytaniem, nie po' );
	k2z_check( 100 === Runner::MAX_ITEMS_PER_SOURCE, 'sufit wynosi 100' );

	// -----------------------------------------------------------------------
	echo "\n-- Zrodla z ustawien --\n";
	// -----------------------------------------------------------------------
	$wpdb                            = k2z_reset();
	$GLOBALS['__opt']['ainp_sources'] = array(
		'https://psy.pl/feed/',
		'  https://psy.pl/feed/  ',   // to samo po przycieciu spacji
		'https://psy.pl/feed/',       // powtorka
		'nie-adres',
		'ftp://psy.pl/feed',
		'',
		123,
		// Zagniezdzona tablica w opcji — tak wyglada popsuty zapis. Bez
		// sprawdzenia typu `trim()` dostalby tablice i wywrocil caly przebieg.
		array( 'https://zagniezdzone.pl/feed/' ),
		'https://drugi.pl/feed/',
	);

	$zrodla = Runner::sources();

	k2z_check( 2 === count( $zrodla ), 'z dziewieciu wpisow zostaja dwa uzyteczne zrodla' );
	k2z_check( 'https://psy.pl/feed/' === $zrodla[0], 'pierwsze zrodlo zachowane' );
	k2z_check( 'https://drugi.pl/feed/' === $zrodla[1], 'drugie zrodlo zachowane' );

	$GLOBALS['__opt']['ainp_sources'] = 'nie-tablica';
	k2z_check( 0 === count( Runner::sources() ), 'opcja w zlym typie: pusta lista zamiast bledu' );

	/*
	 * Brak opcji (swieza instalacja) to co innego niz opcja zapisana jako pusta
	 * tablica (klient swiadomie wyczyscil liste). Pierwsze daje kanaly domyslne,
	 * drugie zostaje puste.
	 */
	unset( $GLOBALS['__opt']['ainp_sources'] );
	k2z_check( 2 === count( Runner::sources() ), 'brak opcji: kanaly domyslne' );

	$GLOBALS['__opt']['ainp_sources'] = array();
	k2z_check( 0 === count( Runner::sources() ), 'lista wyczyszczona swiadomie: zostaje pusta' );

	$wpdb = k2z_reset();
	$w    = Runner::collect( array() );
	k2z_check( 0 === $w['sources'] && 0 === count( $wpdb->queries ), 'brak zrodel: przebieg nic nie robi' );

	/*
	 * Lista podana WPROST (tak wola ja przycisk „Pobierz teraz") przechodzi
	 * przez to samo czyszczenie co lista z ustawien. Inaczej powtorzony adres
	 * oznaczalby dwa pelne pobrania tego samego kanalu.
	 */
	$wpdb                = k2z_reset();
	$GLOBALS['__feeds']  = array( 'https://psy.pl/feed/' => k2z_ok( 'XML-A' ) );
	$GLOBALS['__parsed'] = array( 'XML-A' => k2z_kanal( array( k2z_pozycja( 'https://psy.pl/1/' ) ) ) );

	$w = Runner::collect(
		array( 'https://psy.pl/feed/', 'https://psy.pl/feed/', 'nie-adres', 'ftp://psy.pl/feed' )
	);

	k2z_check( 1 === $w['sources'], 'lista z parametru tez jest czyszczona' );
	k2z_check( 1 === count( $GLOBALS['__pobrania'] ), 'powtorzony adres pobrany dokladnie raz' );

	// -----------------------------------------------------------------------
	echo "\n-- Kontrola kodu (po tokenach, nie po tekscie) --\n";
	// -----------------------------------------------------------------------
	$tokeny = token_get_all( file_get_contents( $root . '/src/Runner.php' ) );
	$nazwy  = array();
	$teksty = array();
	foreach ( $tokeny as $t ) {
		if ( is_array( $t ) && T_STRING === $t[0] ) {
			$nazwy[ $t[1] ] = true;
		}
		if ( is_array( $t ) && ( T_CONSTANT_ENCAPSED_STRING === $t[0] || T_ENCAPSED_AND_WHITESPACE === $t[0] ) ) {
			$teksty[] = $t[1];
		}
	}
	$sql_w_kodzie = implode( ' ', $teksty );

	k2z_check( false !== strpos( $sql_w_kodzie, 'INSERT IGNORE' ), 'w kodzie jest INSERT IGNORE' );
	k2z_check(
		false === stripos( $sql_w_kodzie, 'SELECT' ),
		'kod NIE pyta bazy o duplikat przed zapisem (wzorzec „przeczytaj, potem zapisz")'
	);
	k2z_check( isset( $nazwy['prepare'] ), 'kod uzywa $wpdb->prepare()' );
	k2z_check( isset( $nazwy['wp_encode_emoji'] ), 'kod uzywa wp_encode_emoji()' );
	k2z_check( ! isset( $nazwy['last_error'] ), 'kod NIE opiera sie na last_error (INSERT IGNORE zostawia je puste)' );

	// -----------------------------------------------------------------------
	echo "\n====================================================\n";
	echo 'Asercji: ' . $ran . ' | Niezaliczonych: ' . $fail . "\n";
	if ( 0 === $fail ) {
		echo "WYNIK: WSZYSTKIE OK\n";
		exit( 0 );
	}
	echo "WYNIK: SĄ BŁĘDY\n";
	exit( 1 );
}
