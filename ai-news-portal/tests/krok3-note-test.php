<?php
/**
 * Krok 3, etap 3.2 — werdykt filtra w tabeli: status `skipped` i POWOD w `note`.
 *
 * Etap 3.1 dal odpowiedz „czy odsiac". Tutaj sprawdzamy, czy ta odpowiedz
 * faktycznie ląduje w bazie w postaci, ktora widac na ekranie Materialy:
 * status `skipped` i czytelny powod w kolumnie `note`. Bez powodu pominieta
 * pozycja wyglada identycznie jak zgubiona i nikt nie zauwazy, ze lista
 * wykluczen jest za ostra.
 *
 * Drugi cel: pozycja odsiana ma trafic do tabeli, a nie zostac pominieta przy
 * zapisie. Wiersz z kluczem `UNIQUE` na `url_hash` sprawia, ze przy kolejnym
 * przebiegu ten sam adres jest duplikatem — bez niego kanal podawalby ten sam
 * odsiany artykul w kolko.
 *
 * Prawdziwe klasy: `Runner`, `Filter`, `Dedup`, `Settings`. Atrapy: `$wpdb`,
 * `Http`, `Feed`, `Plugin`. Zero WordPressa i zero sieci.
 *
 * URUCHOMIENIE:  php tests/krok3-note-test.php
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
	function k3n_check( $cond, $label ) {
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
		return '2026-08-06 12:00:00';
	}

	function wp_encode_emoji( $tekst ) {
		return $tekst;
	}

	/**
	 * Atrapa `remove_accents()` dla alfabetu polskiego.
	 *
	 * @param string $tekst Wejscie.
	 *
	 * @return string
	 */
	function remove_accents( $tekst ) {
		return strtr(
			(string) $tekst,
			array(
				'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n',
				'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
				'Ą' => 'A', 'Ć' => 'C', 'Ę' => 'E', 'Ł' => 'L', 'Ń' => 'N',
				'Ó' => 'O', 'Ś' => 'S', 'Ź' => 'Z', 'Ż' => 'Z',
			)
		);
	}

	$GLOBALS['__opt']     = array();
	$GLOBALS['__odczyty'] = array();

	function get_option( $key, $default = false ) {
		$GLOBALS['__odczyty'][] = $key;

		return array_key_exists( $key, $GLOBALS['__opt'] ) ? $GLOBALS['__opt'][ $key ] : $default;
	}

	/**
	 * Atrapa `$wpdb` — zapamietuje zapytania, oddaje zaplanowane wyniki.
	 */
	class AINP_Fake_WPDB_K3 {

		public $prefix     = 'wp_';
		public $last_error = '';
		public $queries    = array();

		/** Kolejka wynikow `query()`; gdy pusta, uzywany jest `$domyslny`. */
		public $wyniki = array();

		/** Wynik `query()` poza kolejka. */
		public $domyslny = 1;

		public function prepare( $sql, ...$args ) {
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

			if ( $this->wyniki ) {
				return array_shift( $this->wyniki );
			}

			return $this->domyslny;
		}
	}
}

namespace AINP {

	/** Atrapa warstwy sieciowej. Liczy KAZDE zadanie — o to chodzi w tescie 4. */
	class Http {

		public static function get_feed( string $url ): array {
			$GLOBALS['__zadania'][] = 'feed:' . $url;

			return isset( $GLOBALS['__feeds'][ $url ] )
				? $GLOBALS['__feeds'][ $url ]
				: array( 'ok' => false, 'code' => 0, 'body' => '', 'error' => 'brak planu', 'reason' => 'transport', 'truncated' => false );
		}

		public static function get_article( string $url ): array {
			$GLOBALS['__zadania'][] = 'article:' . $url;

			return array( 'ok' => false, 'code' => 0, 'body' => '', 'error' => 'nie powinno paść', 'reason' => 'transport', 'truncated' => false );
		}

		public static function is_http_url( string $url ): bool {
			$parts = parse_url( $url );

			if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
				return false;
			}

			return in_array( strtolower( (string) $parts['scheme'] ), array( 'http', 'https' ), true );
		}
	}

	/** Atrapa parsera kanalow. */
	class Feed {

		public static function parse( string $xml ): array {
			return isset( $GLOBALS['__parsed'][ $xml ] )
				? $GLOBALS['__parsed'][ $xml ]
				: array( 'ok' => false, 'error' => 'brak planu', 'format' => '', 'title' => '', 'items' => array(), 'skipped' => 0 );
		}
	}

	/** Atrapa bootstrapu — potrzebna jest tylko nazwa tabeli. */
	class Plugin {

		public static function table(): string {
			return 'wp_ainp_items';
		}
	}
}

namespace {

	require_once $root . '/src/Dedup.php';
	require_once $root . '/src/Settings.php';
	require_once $root . '/src/Filter.php';
	// Od naprawy poaudytowej `insert_item()` potwierdza trafienie filtra na
	// tresci BEZ boksow serwisu, wiec droga zapisu zalezy juz od `Article`.
	require_once $root . '/src/Article.php';
	require_once $root . '/src/Runner.php';

	use AINP\Runner;

	/**
	 * Ustawia atrapy w stan poczatkowy.
	 *
	 * @return AINP_Fake_WPDB_K3
	 */
	function k3n_reset() {
		$GLOBALS['__opt']      = array();
		$GLOBALS['__odczyty']  = array();
		$GLOBALS['__zadania']  = array();
		$GLOBALS['__feeds']    = array();
		$GLOBALS['__parsed']   = array();
		$GLOBALS['wpdb']       = new AINP_Fake_WPDB_K3();

		return $GLOBALS['wpdb'];
	}

	/**
	 * Pozycja z kanalu.
	 *
	 * @param string $url     Adres.
	 * @param string $title   Tytul.
	 * @param string $summary Zajawka.
	 * @param string $content Tresc.
	 *
	 * @return array
	 */
	function k3n_pozycja( $url, $title = 'Tytul', $summary = 'Zajawka', $content = 'Tresc artykulu o psach' ) {
		return array(
			'url'       => $url,
			'title'     => $title,
			'summary'   => $summary,
			'content'   => $content,
			'guid'      => '',
			'published' => 0,
		);
	}

	echo "=== KROK 3 / ETAP 3.2 — powod pominiecia w kolumnie `note` ===\n\n";

	// -----------------------------------------------------------------------
	echo "-- Pozycja ze slowem wykluczajacym --\n";
	// -----------------------------------------------------------------------
	$wpdb = k3n_reset();

	$los = Runner::insert_item(
		k3n_pozycja( 'https://psy.pl/kot-w-domu/', 'Kot w domu z psem', 'O kotach', 'Tresc o kocie' ),
		array( 'kot', 'kocie' )
	);
	$sql = $wpdb->queries[0];

	k3n_check( 'skipped' === $los, 'wynik zapisu to `skipped`, nie `added`' );
	k3n_check( 1 === count( $wpdb->queries ), 'dokladnie JEDNO zapytanie (jest ' . count( $wpdb->queries ) . ')' );
	k3n_check( false !== strpos( $sql, 'INSERT IGNORE' ), 'pozycja odsiana JEST zapisywana (INSERT IGNORE)' );
	k3n_check( false !== strpos( $sql, "'skipped'" ), 'status w zapytaniu to `skipped`' );
	k3n_check( false === strpos( $sql, "'new'" ), 'w zapytaniu NIE MA statusu `new`' );
	k3n_check( false !== strpos( $sql, 'Słowo wykluczające: kot' ), 'powod z nazwa slowa trafil do `note`' );
	k3n_check( false !== strpos( $sql, "'Kot w domu z psem'" ), 'tytul zapisany — inaczej tabela pokazuje sam adres' );
	k3n_check( false !== strpos( $sql, "'O kotach'" ), 'zajawka zapisana' );
	k3n_check( false === strpos( $sql, 'Tresc o kocie' ), 'TRESC odsianej pozycji nie jest zapisywana' );
	k3n_check( 0 === count( $GLOBALS['__zadania'] ), 'zero zadan HTTP przy odsianiu (jest ' . count( $GLOBALS['__zadania'] ) . ')' );

	// -----------------------------------------------------------------------
	echo "\n-- Pozycja czysta --\n";
	// -----------------------------------------------------------------------
	$wpdb = k3n_reset();

	$los = Runner::insert_item(
		k3n_pozycja( 'https://psy.pl/karma/', 'Karma dla psa', 'O karmie', 'Pelna tresc o karmie' ),
		array( 'kot' )
	);
	$sql = $wpdb->queries[0];

	k3n_check( 'added' === $los, 'wynik zapisu to `added`' );
	k3n_check( false !== strpos( $sql, "'new'" ), 'status w zapytaniu to `new`' );
	k3n_check( false === strpos( $sql, 'Słowo wykluczające' ), 'kolumna `note` zostaje pusta' );
	k3n_check( false !== strpos( $sql, 'Pelna tresc o karmie' ), 'tresc z kanalu zapisana w calosci' );

	// -----------------------------------------------------------------------
	echo "\n-- Odsiana pozycja, ktora juz jest w tabeli --\n";
	// -----------------------------------------------------------------------
	$wpdb           = k3n_reset();
	$wpdb->domyslny = 0;   // `INSERT IGNORE` nie zmienil zadnego wiersza.

	$los = Runner::insert_item( k3n_pozycja( 'https://psy.pl/kot/', 'Kot', 'x', 'y' ), array( 'kot' ) );

	k3n_check( 'duplicate' === $los, 'zero zmienionych wierszy to `duplicate`, nie `skipped`' );

	// -----------------------------------------------------------------------
	echo "\n-- Liczniki calego przebiegu --\n";
	// -----------------------------------------------------------------------
	$wpdb = k3n_reset();

	$GLOBALS['__feeds']['https://psy.pl/feed/']  = array( 'ok' => true, 'code' => 200, 'body' => 'XML', 'error' => '', 'reason' => '', 'truncated' => false );
	$GLOBALS['__parsed']['XML']                  = array(
		'ok'      => true,
		'error'   => '',
		'format'  => 'rss',
		'title'   => 'Kanal',
		'items'   => array(
			k3n_pozycja( 'https://psy.pl/a/', 'Karma bytowa', 'o karmie', 'tresc' ),
			k3n_pozycja( 'https://psy.pl/b/', 'Kot rudy', 'o kocie', 'tresc' ),
			k3n_pozycja( 'https://psy.pl/c/', 'Szkolenie szczeniaka', 'komendy', 'tresc' ),
			k3n_pozycja( 'https://psy.pl/d/', 'Wielki konkurs', 'zapisy trwaja', 'tresc' ),
		),
		'skipped' => 0,
	);

	$podsumowanie = Runner::collect( array( 'https://psy.pl/feed/' ) );

	k3n_check( array_key_exists( 'skipped', $podsumowanie ), 'podsumowanie ma licznik `skipped`' );
	k3n_check( 2 === (int) $podsumowanie['added'], 'nowych: 2 (jest ' . (int) $podsumowanie['added'] . ')' );
	k3n_check( 2 === (int) $podsumowanie['skipped'], 'odsianych: 2 — kot i konkurs (jest ' . (int) $podsumowanie['skipped'] . ')' );
	k3n_check( 0 === (int) $podsumowanie['duplicates'], 'duplikatow: 0' );
	k3n_check( 0 === (int) $podsumowanie['failed'], 'nieudanych zapisow: 0' );
	k3n_check( 2 === (int) $podsumowanie['per_source']['https://psy.pl/feed/']['skipped'], 'licznik `skipped` jest tez per zrodlo' );
	k3n_check(
		1 === count( $GLOBALS['__zadania'] ),
		'zadan HTTP dokladnie tyle, ile kanalow: 1 (jest ' . count( $GLOBALS['__zadania'] ) . ')'
	);

	// Lista slow czytana raz na KANAL, nie raz na pozycje — inaczej cztery
	// pozycje to cztery odczyty opcji i cztery scalenia z domyslnymi.
	$odczyty_ustawien = count(
		array_filter(
			$GLOBALS['__odczyty'],
			function ( $k ) {
				return 'ainp_settings' === $k;
			}
		)
	);
	k3n_check( 1 === $odczyty_ustawien, 'opcja ustawien czytana RAZ na kanal (jest ' . $odczyty_ustawien . ')' );

	// -----------------------------------------------------------------------
	echo "\n-- Lista slow z ustawien dziala tez bez jawnego argumentu --\n";
	// -----------------------------------------------------------------------
	$wpdb                                = k3n_reset();
	$GLOBALS['__opt']['ainp_settings']   = array( 'excluded_words' => array( 'żółw' ) );

	$los = Runner::insert_item( k3n_pozycja( 'https://psy.pl/zolw/', 'Zolw w akwarium', 'x', 'y' ) );

	k3n_check( 'skipped' === $los, 'slowo z ustawien klienta odsiewa bez podania listy w wywolaniu' );
	k3n_check(
		false !== strpos( $wpdb->queries[0], 'Słowo wykluczające: zolw' ),
		'powod zapisany po normalizacji (bez ogonkow), tak jak porownanie'
	);

	// -----------------------------------------------------------------------
	echo "\n-- Kolejnosc kolumn w zapytaniu --\n";
	// -----------------------------------------------------------------------
	// Kolumna `note` musi stac tam, gdzie ja czyta ekran Materialy. Zamiana
	// miejscami `status` i `note` daje wiersz, ktory wyglada poprawnie
	// w zapytaniu i klamie na ekranie.
	$sql = $wpdb->queries[0];
	k3n_check(
		1 === preg_match( '/\(\s*url,\s*url_hash,\s*title,\s*excerpt,\s*content,\s*status,\s*note,/', $sql ),
		'kolumny w zapytaniu ida w kolejnosci: url, url_hash, title, excerpt, content, status, note'
	);

	echo "\n";
	echo '=== WYNIK: ' . ( $ran - $fail ) . ' / ' . $ran . " asercji ===\n";

	if ( $fail > 0 ) {
		echo "BŁĘDY: $fail\n";
		exit( 1 );
	}

	echo "WSZYSTKIE OK\n";
	exit( 0 );
}
