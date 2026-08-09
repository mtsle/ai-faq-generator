<?php
/**
 * Krok 5, etap 5.4 — ponowienia sieciowe i reczne wznowienie pozycji `failed`.
 *
 * POKRYWA TEST 14 z tabeli planu: „trzy kolejne bledy transportu HTTP →
 * `attempts === 3`, status `failed`, NASTEPNA pozycja przetworzona".
 *
 * Test 14 jest tu, a nie w Kroku 3, mimo ze sam mechanizm ponowien powstal
 * w etapie 3.4 — bo Krok 3 dowodzil go SKOKIEM: ustawial `attempts = 2` recznie
 * i sprawdzal, ze trzecia proba konczy pozycje. To sprawdza reakcje na licznik,
 * nie sam licznik. Tutaj licznik naprawde narasta przez trzy przebiegi, kazdy
 * czytajacy stan poprzedniego z tabeli.
 *
 * Druga polowa zestawu to WZNOWIENIE — domkniecie wariantu B z audytu Kroku 4.
 * Od tamtej decyzji tresc pozycji `failed` zostaje w tabeli jako material do
 * ponowienia, ale do etapu 5.4 nie istniala droga, ktora by z niego skorzystala.
 *
 * Atrapa bazy trzyma PRAWDZIWY stan wierszy — razem z `attempts` i `content`,
 * bo obie te kolumny sa tu przedmiotem sporu. Zapytanie nierozpoznane przez
 * atrape przewraca osobna asercje na koncu.
 *
 * Prawdziwe klasy: `Runner`, `Article`, `Filter`, `Settings`, `Dedup`.
 * Atrapy: `$wpdb` ze stanem, `Http`, `Plugin`, funkcje WordPressa.
 *
 * URUCHOMIENIE:  php tests/krok5-ponowienia-test.php
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
	function k5r_check( $cond, $label ) {
		global $fail, $ran;
		$ran++;
		echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n";
		if ( ! $cond ) {
			$fail++;
		}
	}

	/*
	 * BRAMKA PAMIECI Z ETAPU 3.5 odsyla pozycje na `retry`, ZANIM scraping
	 * ruszy — a wtedy test 14 mierzylby cos innego, niz deklaruje: licznik prob
	 * rosnie, ale zadne zadanie HTTP nie pada. Ten zestaw sprawdza sciezke
	 * transportu, wiec prog pamieci musi byc zdjety.
	 */
	ini_set( 'memory_limit', '-1' );

	$GLOBALS['__opt']           = array();
	$GLOBALS['__teraz']         = '2026-08-09 12:00:00';
	$GLOBALS['__zadania']       = array();
	$GLOBALS['__strony']        = array();
	$GLOBALS['__nierozpoznane'] = array();

	function current_time( $type, $gmt = 0 ) {
		return ( 'Y-m-d' === $type ) ? substr( $GLOBALS['__teraz'], 0, 10 ) : $GLOBALS['__teraz'];
	}

	function get_option( $key, $default = false ) {
		return array_key_exists( $key, $GLOBALS['__opt'] ) ? $GLOBALS['__opt'][ $key ] : $default;
	}

	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}

	function wp_encode_emoji( $tekst ) {
		return $tekst;
	}

	function esc_url_raw( $url ) {
		return trim( (string) $url );
	}

	function wp_kses_post( $tekst ) {
		return $tekst;
	}

	function remove_accents( $tekst ) {
		return strtr(
			(string) $tekst,
			array(
				'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n',
				'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
			)
		);
	}

	/**
	 * Atrapa `$wpdb` ze stanem tabeli: status, `attempts`, `note`, `content`.
	 *
	 * Wiersze oddawane przez `get_results()` sa TYMI SAMYMI obiektami, ktore
	 * siedza w `$tabela` — dzieki temu kolejny przebieg czyta stan zostawiony
	 * przez poprzedni, tak jak czytalby go z prawdziwej bazy. Gdyby atrapa
	 * oddawala kopie, licznik prob zaczynalby od zera przy kazdym przebiegu
	 * i test 14 nie mialby czego mierzyc.
	 */
	class AINP_Fake_WPDB_K5R {

		public $prefix     = 'wp_';
		public $last_error = '';
		public $queries    = array();

		/** Wiersze jako obiekty, po `id`. */
		public $tabela = array();

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

		/**
		 * Oddaje wiersze pasujace do warunku partii przygotowania.
		 *
		 * Warunek jest odwzorowany, a nie zignorowany: pozycja `failed` albo
		 * `processing` NIE ma prawa trafic do partii, i to jest cala tresc
		 * sekcji o wznowieniu.
		 *
		 * @param string $sql Zapytanie.
		 *
		 * @return array
		 */
		public function get_results( $sql ) {
			$this->queries[] = $sql;

			if ( false === strpos( $sql, "status = 'new'" ) ) {
				return array();
			}

			$bez_odcisku = ( false !== strpos( $sql, 'content_hash IS NULL' ) );
			$wynik       = array();

			foreach ( $this->tabela as $wiersz ) {
				if ( 'new' !== $wiersz->status ) {
					continue;
				}

				$ma_odcisk = ( '' !== (string) $wiersz->content_hash );

				if ( $bez_odcisku === $ma_odcisk ) {
					continue;
				}

				$wynik[] = $wiersz;
			}

			return $wynik;
		}

		public function get_var( $sql ) {
			$this->queries[] = $sql;

			if ( preg_match( '/SELECT content_hash FROM \S+ WHERE id = (\d+)/', $sql, $m ) ) {
				$id = (int) $m[1];
				return isset( $this->tabela[ $id ] ) ? (string) $this->tabela[ $id ]->content_hash : '';
			}

			// Liczniki pozycji `failed` (A4) — liczone ze stanu tabeli, zeby
			// asercja o koszcie wznowienia nie sprawdzala wlasnego zalozenia.
			if ( preg_match( "/SELECT COUNT\(\*\) FROM \S+ WHERE status = '(\w+)'(.*)$/", $sql, $m ) ) {
				$tylko_z_odciskiem = ( false !== strpos( $m[2], 'content_hash IS NOT NULL' ) );
				$ile               = 0;

				foreach ( $this->tabela as $wiersz ) {
					if ( $m[1] !== $wiersz->status ) {
						continue;
					}

					if ( $tylko_z_odciskiem && '' === (string) $wiersz->content_hash ) {
						continue;
					}

					$ile++;
				}

				return (string) $ile;
			}

			return '';
		}

		public function query( $sql ) {
			$this->queries[] = $sql;

			// Przejecie i oddanie pozycji (etap 5.2).
			if ( preg_match( "/SET status = '(\w+)', updated_at = '[^']*' WHERE id = (\d+) AND/", $sql, $m ) ) {
				$id = (int) $m[2];

				if ( ! isset( $this->tabela[ $id ] ) ) {
					return 0;
				}

				$oczekiwany = ( 'processing' === $m[1] ) ? 'new' : 'processing';

				if ( $oczekiwany !== $this->tabela[ $id ]->status ) {
					return 0;
				}

				$this->tabela[ $id ]->status = $m[1];

				return 1;
			}

			// Wznowienie zbiorcze: `failed` → `new`, licznik prob na zero.
			if ( preg_match( "/SET status = '(\w+)', attempts = (\d+), note = '([^']*)', updated_at = '[^']*' WHERE status = '(\w+)'/", $sql, $m ) ) {
				$zmienione = 0;

				foreach ( $this->tabela as $wiersz ) {
					if ( $m[4] !== $wiersz->status ) {
						continue;
					}

					$wiersz->status = $m[1];
					// Wartosc licznika bierzemy Z ZAPYTANIA, nie z zalozenia — inaczej
					// mutacja podmieniajaca `attempts = 0` na cokolwiek innego ginelaby
					// na sieci asekuracyjnej zamiast na asercji o zerowaniu.
					$wiersz->attempts = (int) $m[2];
					$wiersz->note     = $m[3];
					$zmienione++;
				}

				return $zmienione;
			}

			// Ponowienie po bledzie przejsciowym: status, powod, licznik prob.
			if ( preg_match( "/SET status = '(\w+)', note = '(.*)', attempts = (\d+), updated_at = '[^']*' WHERE id = (\d+)$/", $sql, $m ) ) {
				$id = (int) $m[4];

				if ( isset( $this->tabela[ $id ] ) ) {
					$this->tabela[ $id ]->status   = $m[1];
					$this->tabela[ $id ]->note     = $m[2];
					$this->tabela[ $id ]->attempts = (int) $m[3];
				}

				return 1;
			}

			// Zapis tresci i odcisku.
			if ( 0 === strpos( $sql, 'UPDATE IGNORE' )
				&& preg_match( "/content_hash = '([0-9a-f]{64})'/", $sql, $h )
				&& preg_match( '/WHERE id = (\d+)\s*$/', $sql, $i ) ) {
				$id = (int) $i[1];

				if ( isset( $this->tabela[ $id ] ) ) {
					$this->tabela[ $id ]->content_hash = $h[1];
				}

				return 1;
			}

			// `mark()` — status i powod, TRESC ZOSTAJE (wariant B).
			if ( preg_match( "/SET status = '(\w+)', note = '(.*)', updated_at = '[^']*' WHERE id = (\d+)$/", $sql, $m ) ) {
				$id = (int) $m[3];

				if ( isset( $this->tabela[ $id ] ) ) {
					$this->tabela[ $id ]->status = $m[1];
					$this->tabela[ $id ]->note   = $m[2];
				}

				return 1;
			}

			// `finish()` — status, powod i WYZEROWANA tresc.
			if ( preg_match( "/SET status = '(\w+)', note = '(.*)', content = '', updated_at = '[^']*' WHERE id = (\d+)$/", $sql, $m ) ) {
				$id = (int) $m[3];

				if ( isset( $this->tabela[ $id ] ) ) {
					$this->tabela[ $id ]->status  = $m[1];
					$this->tabela[ $id ]->note    = $m[2];
					$this->tabela[ $id ]->content = '';
				}

				return 1;
			}

			if ( 0 === strpos( $sql, 'UPDATE IGNORE' ) ) {
				return 1;
			}

			$GLOBALS['__nierozpoznane'][] = $sql;

			return 0;
		}
	}
}

namespace AINP {

	/** Atrapa warstwy sieciowej — plan odpowiedzi na adres. */
	class Http {

		public static function get_feed( string $url ): array {
			return array( 'ok' => false, 'code' => 0, 'body' => '', 'error' => 'brak planu', 'reason' => 'transport', 'truncated' => false );
		}

		public static function get_article( string $url ): array {
			$GLOBALS['__zadania'][] = $url;

			return $GLOBALS['__strony'][ $url ] ?? array( 'ok' => true, 'code' => 200, 'body' => '<html><body><article><p>Domyślna treść.</p></article></body></html>', 'error' => '', 'reason' => '', 'truncated' => false );
		}

		/**
		 * Ta sama regula, co w prawdziwym `Http` — i to jest tu istotne.
		 * Atrapa, ktora uznaje KAZDY blad za ponawialny, zamienilaby test 14
		 * w test wlasnej atrapy: blad trwaly konczy pozycje od razu i licznik
		 * prob nigdy nie doszedlby do trzech.
		 *
		 * @param array $result Wynik pobrania.
		 *
		 * @return bool
		 */
		public static function is_retryable( array $result ): bool {
			if ( ! empty( $result['ok'] ) ) {
				return false;
			}

			$reason = (string) ( $result['reason'] ?? '' );
			$code   = (int) ( $result['code'] ?? 0 );

			if ( 'transport' === $reason ) {
				return true;
			}

			if ( 'status' === $reason ) {
				return ( 429 === $code || $code >= 500 );
			}

			return false;
		}

		public static function is_http_url( string $url ): bool {
			$parts = parse_url( $url );

			if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
				return false;
			}

			return in_array( strtolower( (string) $parts['scheme'] ), array( 'http', 'https' ), true );
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
	require_once $root . '/src/Article.php';
	require_once $root . '/src/Runner.php';

	use AINP\Runner;

	/**
	 * Ustawia atrapy w stan poczatkowy.
	 *
	 * @return AINP_Fake_WPDB_K5R
	 */
	function k5r_reset() {
		$GLOBALS['__opt']     = array();
		$GLOBALS['__zadania'] = array();
		$GLOBALS['__strony']  = array();
		$GLOBALS['wpdb']      = new AINP_Fake_WPDB_K5R();

		return $GLOBALS['wpdb'];
	}

	/**
	 * Wiersz w atrapie tabeli.
	 *
	 * @param AINP_Fake_WPDB_K5R $wpdb    Atrapa.
	 * @param int                $id      Identyfikator.
	 * @param string             $url     Adres.
	 * @param string             $content Tresc z kanalu.
	 * @param string             $status  Status.
	 *
	 * @return object
	 */
	function k5r_wiersz( $wpdb, $id, $url, $content = '', $status = 'new' ) {
		$wiersz = (object) array(
			'id'           => $id,
			'url'          => $url,
			'url_hash'     => str_repeat( (string) $id, 64 ),
			'title'        => 'Pies i karma bytowa',
			'excerpt'      => 'Zajawka o psach',
			'content'      => $content,
			'content_hash' => '',
			'status'       => $status,
			'note'         => '',
			'attempts'     => 0,
		);

		$wpdb->tabela[ $id ] = $wiersz;

		return $wiersz;
	}

	// -----------------------------------------------------------------------
	echo "\n=== 1. TEST 14 — trzy kolejne bledy transportu ===\n";
	// -----------------------------------------------------------------------
	$wpdb = k5r_reset();

	// Pozycja bez tresci z kanalu — wiec partia probuje ja pobrac.
	k5r_wiersz( $wpdb, 1, 'https://psy.pl/timeout/', '' );

	// Blad PRZEJSCIOWY: timeout transportu. Trwaly (404) konczylby od razu.
	$GLOBALS['__strony']['https://psy.pl/timeout/'] = array(
		'ok'        => false,
		'code'      => 0,
		'body'      => '',
		'error'     => 'Operation timed out',
		'reason'    => 'transport',
		'truncated' => false,
	);

	$pierwszy = Runner::prepare_batch( 10 );

	k5r_check( 1 === (int) $pierwszy['retry'], 'przebieg 1: pozycja wraca do kolejki' );
	k5r_check( 1 === (int) $wpdb->tabela[1]->attempts, 'przebieg 1: licznik prob = 1 (jest: ' . $wpdb->tabela[1]->attempts . ')' );
	k5r_check( 'new' === $wpdb->tabela[1]->status, 'przebieg 1: status nadal `new`' );

	$drugi = Runner::prepare_batch( 10 );

	k5r_check( 1 === (int) $drugi['retry'], 'przebieg 2: znowu do kolejki' );
	k5r_check( 2 === (int) $wpdb->tabela[1]->attempts, 'przebieg 2: licznik prob = 2 (jest: ' . $wpdb->tabela[1]->attempts . ')' );

	$trzeci = Runner::prepare_batch( 10 );

	k5r_check( 1 === (int) $trzeci['failed'], 'przebieg 3: pozycja konczy jako nieudana' );
	k5r_check( 'failed' === $wpdb->tabela[1]->status, 'przebieg 3: status `failed` (jest: ' . $wpdb->tabela[1]->status . ')' );
	k5r_check( false !== strpos( (string) $wpdb->tabela[1]->note, 'prób: 3' ), 'powod podaje liczbe prob — trzy, nie „kilka"' );
	k5r_check( 3 === count( $GLOBALS['__zadania'] ), 'trzy przebiegi to DOKLADNIE trzy zadania HTTP (jest: ' . count( $GLOBALS['__zadania'] ) . ')' );

	$czwarty = Runner::prepare_batch( 10 );

	k5r_check( 0 === (int) $czwarty['taken'], 'czwarty przebieg juz jej NIE bierze — `failed` wypada z warunku partii' );
	k5r_check( 3 === count( $GLOBALS['__zadania'] ), 'i nie kosztuje czwartego zadania HTTP' );

	// -----------------------------------------------------------------------
	echo "\n=== 2. Pozycja padnieta nie zatrzymuje NASTEPNEJ ===\n";
	// -----------------------------------------------------------------------
	$wpdb = k5r_reset();

	k5r_wiersz( $wpdb, 1, 'https://psy.pl/pada/', '' );
	k5r_wiersz( $wpdb, 2, 'https://psy.pl/dziala/', '<p>' . str_repeat( 'Treść o psach i karmie bytowej. ', 60 ) . '</p>' );

	$GLOBALS['__strony']['https://psy.pl/pada/'] = array(
		'ok'        => false,
		'code'      => 0,
		'body'      => '',
		'error'     => 'Operation timed out',
		'reason'    => 'transport',
		'truncated' => false,
	);

	$podsumowanie = Runner::prepare_batch( 10 );

	k5r_check( 2 === (int) $podsumowanie['taken'], 'partia wziela obie pozycje' );
	k5r_check( 1 === (int) $podsumowanie['retry'], 'pierwsza wrocila do kolejki' );
	k5r_check( 1 === (int) $podsumowanie['ready'], 'a NASTEPNA zostala przygotowana mimo bledu poprzedniej' );
	k5r_check( 64 === strlen( (string) $wpdb->tabela[2]->content_hash ), 'druga pozycja ma odcisk tresci — naprawde przeszla' );

	// -----------------------------------------------------------------------
	echo "\n=== 3. Wznowienie: `failed` wraca do kolejki ===\n";
	// -----------------------------------------------------------------------
	$wpdb = k5r_reset();

	$padnieta = k5r_wiersz( $wpdb, 1, 'https://psy.pl/1/', '<p>treść zachowana</p>', 'failed' );
	$padnieta->attempts = 3;
	$padnieta->note     = 'Operation timed out (prób: 3)';

	$druga           = k5r_wiersz( $wpdb, 2, 'https://psy.pl/2/', '', 'failed' );
	$druga->attempts = 3;

	k5r_wiersz( $wpdb, 3, 'https://psy.pl/3/', '', 'done' );
	k5r_wiersz( $wpdb, 4, 'https://psy.pl/4/', '', 'skipped' );
	k5r_wiersz( $wpdb, 5, 'https://psy.pl/5/', '', 'new' );

	// Jedna z nieudanych padla dopiero przy modelu — ma odcisk tresci.
	$padnieta->content_hash = str_repeat( 'f', 64 );

	$przed = Runner::failed_counts();

	k5r_check( 2 === $przed['total'], 'licznik widzi obie nieudane pozycje (jest: ' . $przed['total'] . ')' );
	/*
	 * A4: odcisk tresci jest granica miedzy „ponowienie kosztuje zadanie HTTP"
	 * a „ponowienie kosztuje slot z dobowej puli 20". Bez tej liczby klient
	 * klikal w ciemno i potrafil oddac polowe doby za pozycje, ktore i tak
	 * padna ponownie na tym samym warunku.
	 */
	k5r_check( 1 === $przed['ai'], 'i rozpoznaje, ze TYLKO jedna wroci do modelu (jest: ' . $przed['ai'] . ')' );

	$wznowione = Runner::revive_failed();

	k5r_check( 2 === $wznowione['revived'], 'wznowione DOKLADNIE dwie pozycje (jest: ' . $wznowione['revived'] . ')' );
	k5r_check( 1 === $wznowione['ai'], 'i wynik niesie koszt: jedna z nich zajmie wywolanie AI' );
	k5r_check( 0 === Runner::failed_counts()['total'], 'po wznowieniu nie ma juz czego wznawiac' );
	k5r_check( 'new' === $wpdb->tabela[1]->status && 'new' === $wpdb->tabela[2]->status, 'obie wrocily jako `new`' );
	/*
	 * Zerowanie licznika nie jest kosmetyka: bez niego pozycja wraca z licznikiem
	 * na `MAX_ATTEMPTS` i PIERWSZY blad przejsciowy odsyla ja z powrotem
	 * na `failed`. Wznowienie byloby wtedy warte jedno podejscie, nie trzy.
	 */
	k5r_check( 0 === (int) $wpdb->tabela[1]->attempts, 'licznik prob wyzerowany (jest: ' . $wpdb->tabela[1]->attempts . ')' );
	k5r_check( '' === (string) $wpdb->tabela[1]->note, 'stary powod skasowany — na `new` opisywalby stan, ktorego juz nie ma' );
	k5r_check( '<p>treść zachowana</p>' === (string) $wpdb->tabela[1]->content, 'TRESC zachowana przy `failed` jest tym, po co wznowienie istnieje (wariant B)' );
	k5r_check( 'done' === $wpdb->tabela[3]->status, 'pozycja `done` nietknieta' );
	k5r_check( 'skipped' === $wpdb->tabela[4]->status, 'pozycja `skipped` nietknieta — wznowienie nie cofa decyzji filtra' );

	// -----------------------------------------------------------------------
	echo "\n=== 4. Wznowiona pozycja naprawde wraca do obiegu ===\n";
	// -----------------------------------------------------------------------
	$wpdb = k5r_reset();

	$padnieta           = k5r_wiersz( $wpdb, 1, 'https://psy.pl/wraca/', '', 'failed' );
	$padnieta->attempts = 3;

	$przed = Runner::prepare_batch( 10 );

	k5r_check( 0 === (int) $przed['taken'], 'przed wznowieniem partia jej nie widzi' );

	Runner::revive_failed();

	$GLOBALS['__strony']['https://psy.pl/wraca/'] = array(
		'ok'        => true,
		'code'      => 200,
		'body'      => '<html><body><article><p>' . str_repeat( 'Treść o psach po naprawie przyczyny. ', 60 ) . '</p></article></body></html>',
		'error'     => '',
		'reason'    => '',
		'truncated' => false,
	);

	$po = Runner::prepare_batch( 10 );

	k5r_check( 1 === (int) $po['taken'], 'po wznowieniu partia bierze ja normalnie' );
	k5r_check( 1 === (int) $po['ready'], 'i doprowadza do konca, skoro przyczyna zniknela' );
	k5r_check( 'new' === $wpdb->tabela[1]->status, 'pozycja czeka teraz na model jako `new`' );

	// -----------------------------------------------------------------------
	echo "\n=== 5. Atrapa rozumiala kazde wyslane zapytanie ===\n";
	// -----------------------------------------------------------------------
	$nieznane = $GLOBALS['__nierozpoznane'];

	k5r_check(
		0 === count( $nieznane ),
		'zero zapytan nierozpoznanych w calym zestawie (jest: ' . count( $nieznane ) . ( $nieznane ? ' — ' . $nieznane[0] : '' ) . ')'
	);

	// -----------------------------------------------------------------------
	echo "\n--------------------------------------------------\n";
	echo 'Asercji: ' . $ran . ' | bledow: ' . $fail . "\n";

	if ( 0 === $fail ) {
		echo "WSZYSTKIE ASERCJE OK\n";
		exit( 0 );
	}

	echo 'BŁĘDY: ' . $fail . "\n";
	exit( 1 );
}
