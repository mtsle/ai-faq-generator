<?php
/**
 * Krok 5, etap 5.2 — przejecie pozycji: `new` → `processing` → `new`.
 *
 * Ten zestaw jako jedyny trzyma PRAWDZIWY STAN WIERSZY w atrapie bazy i kazda
 * asercja dotyczy stanu tabeli po zapytaniu, a nie tekstu zapytania. Powod jest
 * ten sam, co przy tescie 15 z Kroku 4: mechanizm, ktorego cala trescia jest
 * warunek `WHERE`, sprawdzony asercja tekstowa mowi tylko tyle, ze warunek jest
 * ZAPISANY. Nie mowi, czy dziala — a przy przejeciu pozycji to jest cala rzecz.
 *
 * Atrapa rozumie DOKLADNIE te zapytania, ktore wysyla `Runner`, i kazde inne
 * odklada na liste nierozpoznanych. Asercja na te liste jest siecia
 * asekuracyjna wlasnego testu: gdy ksztalt zapytania sie zmieni, atrapa
 * przestanie je stosowac i zamiast cichej zieleni bedzie czerwony wiersz.
 *
 * Co jest sprawdzane:
 *   1. Przejecie udaje sie RAZ. Drugi proces dostaje odmowe i nie zmienia nic.
 *   2. Pozycja zamknieta (`done`, `skipped`, `failed`) nie da sie przejac.
 *   3. `processing` starsze niz 15 minut wraca do gry, mlodsze — nie.
 *   4. Oddanie pozycji nie wskrzesza statusow koncowych.
 *   5. Odzysk zbiorczy rusza wylacznie porzucone wiersze.
 *   6. Granica czasu liczona jest z `current_time()`, nie z `time()`.
 *   7. Partia przygotowania: wiersz zajety przez kogos innego jest pomijany
 *      BEZ zadania sieciowego, a wiersz przygotowany wraca do kolejki.
 *   8. Partia publikacji: wyscig o wiersz konczy sie przed modelem, czyli
 *      przed wydaniem slotu z dobowej puli.
 *
 * Prawdziwe klasy: `Runner`, `Filter`, `Settings`, `Dedup`, `Article`.
 * Atrapy: `$wpdb` ze stanem, `Http`, `Plugin`, funkcje WordPressa.
 *
 * URUCHOMIENIE:  php tests/krok5-przejecie-test.php
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
	function k5p_check( $cond, $label ) {
		global $fail, $ran;
		$ran++;
		echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n";
		if ( ! $cond ) {
			$fail++;
		}
	}

	$GLOBALS['__opt']            = array();
	$GLOBALS['__teraz']          = '2026-08-09 12:00:00';
	$GLOBALS['__nierozpoznane']  = array();   // Zbierane przez CALY zestaw, nie per atrapa.

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
				'Ą' => 'A', 'Ć' => 'C', 'Ę' => 'E', 'Ł' => 'L', 'Ń' => 'N',
				'Ó' => 'O', 'Ś' => 'S', 'Ź' => 'Z', 'Ż' => 'Z',
			)
		);
	}

	/**
	 * Atrapa `$wpdb` ze STANEM TABELI.
	 *
	 * Rozpoznaje cztery zapytania `Runnera` i stosuje je do tablicy `$tabela`
	 * dokladnie tak, jak zrobilaby to baza — razem z warunkami `WHERE`, bo to
	 * one sa przedmiotem testu. Zapytanie, ktorego nie rozpozna, laduje
	 * w `$nierozpoznane` i przewraca osobna asercje na koncu zestawu.
	 */
	class AINP_Fake_WPDB_K5P {

		public $prefix        = 'wp_';
		public $last_error    = '';
		public $queries       = array();
		public $nierozpoznane = array();

		/** Stan tabeli: [ id => [ status, updated_at, content_hash ] ]. */
		public $tabela = array();

		/** Kolejka zestawow wynikow `get_results()`. */
		public $partie = array();

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

		public function get_results( $sql ) {
			$this->queries[] = $sql;

			return $this->partie ? array_shift( $this->partie ) : array();
		}

		public function get_var( $sql ) {
			$this->queries[] = $sql;

			if ( preg_match( '/SELECT content_hash FROM \S+ WHERE id = (\d+)/', $sql, $m ) ) {
				return $this->tabela[ (int) $m[1] ]['content_hash'] ?? '';
			}

			return '';
		}

		public function query( $sql ) {
			$this->queries[] = $sql;

			// 1. PRZEJECIE: id + (`new` albo porzucone `processing`).
			if ( preg_match(
				"/SET status = '(\w+)', updated_at = '([^']*)' WHERE id = (\d+) AND \( status = '(\w+)' OR \( status = '(\w+)' AND updated_at < '([^']*)' \) \)/",
				$sql,
				$m
			) ) {
				$id = (int) $m[3];

				if ( ! isset( $this->tabela[ $id ] ) ) {
					return 0;
				}

				$wiersz  = $this->tabela[ $id ];
				$pasuje  = ( $m[4] === $wiersz['status'] );
				$pasuje  = $pasuje || ( $m[5] === $wiersz['status'] && $wiersz['updated_at'] < $m[6] );

				if ( ! $pasuje ) {
					return 0;
				}

				$this->tabela[ $id ]['status']     = $m[1];
				$this->tabela[ $id ]['updated_at'] = $m[2];

				return 1;
			}

			// 2. ODDANIE: id + status `processing`.
			if ( preg_match(
				"/SET status = '(\w+)', updated_at = '([^']*)' WHERE id = (\d+) AND status = '(\w+)'/",
				$sql,
				$m
			) ) {
				$id = (int) $m[3];

				if ( ! isset( $this->tabela[ $id ] ) || $m[4] !== $this->tabela[ $id ]['status'] ) {
					return 0;
				}

				$this->tabela[ $id ]['status']     = $m[1];
				$this->tabela[ $id ]['updated_at'] = $m[2];

				return 1;
			}

			// 3. ODZYSK ZBIORCZY: bez `id`, po statusie i wieku.
			if ( preg_match(
				"/SET status = '(\w+)', updated_at = '([^']*)' WHERE status = '(\w+)' AND updated_at < '([^']*)'/",
				$sql,
				$m
			) ) {
				$zmienione = 0;

				foreach ( $this->tabela as $id => $wiersz ) {
					if ( $m[3] !== $wiersz['status'] || $wiersz['updated_at'] >= $m[4] ) {
						continue;
					}

					$this->tabela[ $id ]['status']     = $m[1];
					$this->tabela[ $id ]['updated_at'] = $m[2];
					$zmienione++;
				}

				return $zmienione;
			}

			// 4. Zapis tresci i odcisku (`claim_content`).
			if ( 0 === strpos( $sql, 'UPDATE IGNORE' )
				&& preg_match( "/content_hash = '([0-9a-f]{64})'/", $sql, $h )
				&& preg_match( '/WHERE id = (\d+)\s*$/', $sql, $i ) ) {
				$id = (int) $i[1];

				if ( isset( $this->tabela[ $id ] ) ) {
					$this->tabela[ $id ]['content_hash'] = $h[1];
				}

				return 1;
			}

			// 5. Zamkniecie pozycji (`finish`, `mark`, `after_failure`).
			if ( preg_match( "/SET status = '(\w+)'/", $sql, $m )
				&& preg_match( '/WHERE id = (\d+)\s*$/', $sql, $i ) ) {
				$id = (int) $i[1];

				if ( isset( $this->tabela[ $id ] ) ) {
					$this->tabela[ $id ]['status']     = $m[1];
					$this->tabela[ $id ]['updated_at'] = $GLOBALS['__teraz'];
				}

				return 1;
			}

			// Adres kanoniczny i inne `UPDATE IGNORE` bez odcisku — bez skutku
			// dla statusu, ale rozpoznane, zeby nie zaklamac listy ponizej.
			if ( 0 === strpos( $sql, 'UPDATE IGNORE' ) ) {
				return 1;
			}

			$this->nierozpoznane[]           = $sql;
			$GLOBALS['__nierozpoznane'][]    = $sql;

			return 0;
		}
	}
}

namespace AINP {

	/** Atrapa warstwy sieciowej — liczy KAZDE zadanie. */
	class Http {

		public static function get_feed( string $url, ?float $remaining = null ): array {  // Sygnatura jak w produkcji (Http.php:135).
			$GLOBALS['__zadania'][] = 'feed:' . $url;

			return array( 'ok' => false, 'code' => 0, 'body' => '', 'error' => 'brak planu', 'reason' => 'transport', 'truncated' => false );
		}

		public static function get_article( string $url, ?float $remaining = null ): array {  // Sygnatura jak w produkcji (Http.php:149).
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
	use AINP\Settings;

	/**
	 * Ustawia atrapy w stan poczatkowy.
	 *
	 * @return AINP_Fake_WPDB_K5P
	 */
	function k5p_reset() {
		$GLOBALS['__opt']     = array();
		$GLOBALS['__zadania'] = array();
		$GLOBALS['__teraz']   = '2026-08-09 12:00:00';
		$GLOBALS['wpdb']      = new AINP_Fake_WPDB_K5P();

		return $GLOBALS['wpdb'];
	}

	/**
	 * Wiersz w atrapie tabeli.
	 *
	 * @param AINP_Fake_WPDB_K5P $wpdb    Atrapa.
	 * @param int                $id      Identyfikator.
	 * @param string             $status  Status.
	 * @param string             $czas    `updated_at`.
	 *
	 * @return void
	 */
	function k5p_wiersz_w_bazie( $wpdb, $id, $status, $czas = '2026-08-09 11:59:00' ) {
		$wpdb->tabela[ $id ] = array(
			'status'       => $status,
			'updated_at'   => $czas,
			'content_hash' => '',
		);
	}

	/**
	 * Wiersz oddawany przez `get_results()`.
	 *
	 * @param int    $id      Identyfikator.
	 * @param string $title   Tytul.
	 * @param string $content Tresc z kanalu.
	 *
	 * @return object
	 */
	function k5p_wiersz( $id, $title, $content = '' ) {
		return (object) array(
			'id'           => $id,
			'url'          => 'https://psy.pl/' . $id . '/',
			'url_hash'     => str_repeat( 'a', 64 ),
			'title'        => $title,
			'excerpt'      => 'Zajawka o karmie',
			'content'      => $content,
			'content_hash' => '',
			'status'       => 'new',
			'note'         => '',
			'attempts'     => 0,
		);
	}

	// -----------------------------------------------------------------------
	echo "\n=== 1. Przejecie udaje sie DOKLADNIE raz ===\n";
	// -----------------------------------------------------------------------
	$wpdb = k5p_reset();
	k5p_wiersz_w_bazie( $wpdb, 7, 'new' );

	$pierwszy = Runner::claim( 7 );

	k5p_check( true === $pierwszy, 'wiersz `new` zostaje przejety' );
	k5p_check( 'processing' === $wpdb->tabela[7]['status'], 'status w TABELI to `processing` (jest: ' . $wpdb->tabela[7]['status'] . ')' );
	k5p_check( '2026-08-09 12:00:00' === $wpdb->tabela[7]['updated_at'], '`updated_at` przestawione na teraz — od tego liczy sie prog porzucenia' );

	/*
	 * Sedno atomowosci: drugi proces pyta o ten sam wiersz sekunde pozniej.
	 * Bez warunku `status = 'new'` w samym `UPDATE` dostalby zgode, bo wiersz
	 * istnieje i ma poprawne `id`.
	 */
	$drugi = Runner::claim( 7 );

	k5p_check( false === $drugi, 'drugi proces dostaje ODMOWE' );
	k5p_check( 'processing' === $wpdb->tabela[7]['status'], 'i niczego nie zmienil' );

	// -----------------------------------------------------------------------
	echo "\n=== 2. Pozycji zamknietej nie da sie przejac ===\n";
	// -----------------------------------------------------------------------
	foreach ( array( 'done', 'skipped', 'failed' ) as $status ) {
		$wpdb = k5p_reset();
		k5p_wiersz_w_bazie( $wpdb, 3, $status );

		$wynik = Runner::claim( 3 );

		k5p_check( false === $wynik && $status === $wpdb->tabela[3]['status'], 'status `' . $status . '` nie zostaje przejety ani zmieniony' );
	}

	$wpdb = k5p_reset();
	k5p_check( false === Runner::claim( 0 ), 'przejecie wiersza o id = 0 to odmowa' );
	k5p_check( 0 === count( $wpdb->queries ), 'i ZERO zapytan do bazy (jest: ' . count( $wpdb->queries ) . ')' );

	// -----------------------------------------------------------------------
	echo "\n=== 3. Porzucone `processing` wraca do gry, swieze nie ===\n";
	// -----------------------------------------------------------------------
	$wpdb = k5p_reset();
	// 16 minut temu — powyzej progu 15 minut.
	k5p_wiersz_w_bazie( $wpdb, 11, 'processing', '2026-08-09 11:44:00' );

	k5p_check( true === Runner::claim( 11 ), 'porzucone `processing` (16 min) daje sie przejac' );
	k5p_check( '2026-08-09 12:00:00' === $wpdb->tabela[11]['updated_at'], 'i dostaje swiezy znacznik czasu' );

	$wpdb = k5p_reset();
	// 14 minut temu — ponizej progu; przebieg moze wciaz pracowac.
	k5p_wiersz_w_bazie( $wpdb, 12, 'processing', '2026-08-09 11:46:00' );

	k5p_check( false === Runner::claim( 12 ), 'swieze `processing` (14 min) NIE daje sie przejac' );
	k5p_check( '2026-08-09 11:46:00' === $wpdb->tabela[12]['updated_at'], 'i zostaje nietkniete' );

	// -----------------------------------------------------------------------
	echo "\n=== 4. Granica progu liczona z current_time(), nie z time() ===\n";
	// -----------------------------------------------------------------------
	$wpdb = k5p_reset();
	k5p_wiersz_w_bazie( $wpdb, 1, 'new' );
	Runner::claim( 1 );

	k5p_check(
		false !== strpos( $wpdb->queries[0], "updated_at < '2026-08-09 11:45:00'" ),
		'prog to dokladnie 15 minut wstecz od zegara witryny'
	);

	/*
	 * Zegar witryny przestawiony o cztery lata. Gdyby prog liczyl sie z `time()`,
	 * granica zostalaby przy dzisiejszej dacie, a kazde `processing` witryny
	 * z przesunietym zegarem byloby albo natychmiast porzucone, albo wieczne.
	 */
	$wpdb               = k5p_reset();
	$GLOBALS['__teraz'] = '2030-01-01 08:30:00';
	k5p_wiersz_w_bazie( $wpdb, 1, 'new' );
	Runner::claim( 1 );

	k5p_check(
		false !== strpos( $wpdb->queries[0], "updated_at < '2030-01-01 08:15:00'" ),
		'granica idzie za zegarem witryny, a nie za zegarem procesu'
	);

	// -----------------------------------------------------------------------
	echo "\n=== 5. Oddanie pozycji nie wskrzesza statusow koncowych ===\n";
	// -----------------------------------------------------------------------
	// `release()` jest prywatne: sprawdzamy je przez partie, ktora je wola.
	$wpdb         = k5p_reset();
	$wpdb->partie = array( array( k5p_wiersz( 31, 'Pies i karma bytowa', '<p>' . str_repeat( 'Treść o psach i karmie. ', 120 ) . '</p>' ) ) );
	k5p_wiersz_w_bazie( $wpdb, 31, 'new' );

	$podsumowanie = Runner::prepare_batch( 10 );

	k5p_check( 1 === $podsumowanie['taken'], 'partia wziela jedna pozycje' );
	k5p_check( 1 === $podsumowanie['ready'], 'i doprowadzila ja do stanu `ready`' );
	k5p_check( 'new' === $wpdb->tabela[31]['status'], 'pozycja gotowa WROCILA do kolejki jako `new` (jest: ' . $wpdb->tabela[31]['status'] . ')' );
	k5p_check( 0 === count( $GLOBALS['__zadania'] ), 'tresc z kanalu wystarczyla — zero zadan HTTP' );

	// -----------------------------------------------------------------------
	echo "\n=== 6. Pozycja odsiana zostaje odsiana, mimo oddania ===\n";
	// -----------------------------------------------------------------------
	$wpdb                                       = k5p_reset();
	$GLOBALS['__opt'][ Settings::OPTION ]       = array( 'excluded_words' => array( 'kot' ) );
	$wpdb->partie                               = array( array( k5p_wiersz( 41, 'Kot i pies pod jednym dachem', '<p>krótko</p>' ) ) );
	k5p_wiersz_w_bazie( $wpdb, 41, 'new' );

	$podsumowanie = Runner::prepare_batch( 10 );

	k5p_check( 1 === $podsumowanie['skipped'], 'pozycja ze slowem wykluczajacym konczy jako `skipped`' );
	k5p_check( 'skipped' === $wpdb->tabela[41]['status'], 'i takim statusem ZOSTAJE — `release()` go nie cofa (jest: ' . $wpdb->tabela[41]['status'] . ')' );

	// -----------------------------------------------------------------------
	echo "\n=== 7. Wiersz zajety przez inny przebieg jest pomijany przed praca ===\n";
	// -----------------------------------------------------------------------
	$wpdb         = k5p_reset();
	$wpdb->partie = array( array( k5p_wiersz( 51, 'Pies i karma', '<p>za krótka treść</p>' ) ) );
	// W tabeli wiersz jest juz WZIETY przez kogos innego, i to swiezo — dokladnie
	// ten wyscig, ktorego `SELECT` sprzed chwili nie mogl zobaczyc.
	k5p_wiersz_w_bazie( $wpdb, 51, 'processing', '2026-08-09 11:59:30' );

	$podsumowanie = Runner::prepare_batch( 10 );

	k5p_check( 1 === $podsumowanie['busy'], 'partia liczy pozycje zajete osobno (jest: ' . $podsumowanie['busy'] . ')' );
	k5p_check( 0 === $podsumowanie['taken'], 'i NIE liczy jej jako wzietej' );
	k5p_check( 0 === count( $GLOBALS['__zadania'] ), 'ZERO zadan HTTP — przejecie stoi PRZED scrapingiem (jest: ' . count( $GLOBALS['__zadania'] ) . ')' );
	k5p_check( 'processing' === $wpdb->tabela[51]['status'], 'cudza pozycja nietknieta' );

	// -----------------------------------------------------------------------
	echo "\n=== 8. Wyscig o wiersz konczy sie PRZED modelem ===\n";
	// -----------------------------------------------------------------------
	$wpdb         = k5p_reset();
	$wpdb->partie = array( array( k5p_wiersz( 61, 'Pies i karma bytowa', 'treść gotowa' ) ) );
	k5p_wiersz_w_bazie( $wpdb, 61, 'processing', '2026-08-09 11:59:30' );

	$podsumowanie = Runner::publish_batch( 3 );

	k5p_check( 1 === $podsumowanie['busy'], 'partia publikacji tez liczy zajete osobno (jest: ' . $podsumowanie['busy'] . ')' );
	k5p_check( 0 === $podsumowanie['taken'], 'i nie bierze pozycji' );
	k5p_check( 0 === $podsumowanie['calls'], 'ZERO wywolan modelu — slot z dobowej puli nie zostal wydany' );

	// -----------------------------------------------------------------------
	echo "\n=== 9. Odzysk zbiorczy rusza wylacznie porzucone wiersze ===\n";
	// -----------------------------------------------------------------------
	$wpdb = k5p_reset();
	k5p_wiersz_w_bazie( $wpdb, 71, 'processing', '2026-08-09 11:30:00' );   // porzucone
	k5p_wiersz_w_bazie( $wpdb, 72, 'processing', '2026-08-09 11:40:00' );   // porzucone
	k5p_wiersz_w_bazie( $wpdb, 73, 'processing', '2026-08-09 11:59:00' );   // pracuje
	k5p_wiersz_w_bazie( $wpdb, 74, 'new', '2026-08-09 10:00:00' );          // stary, ale wolny
	k5p_wiersz_w_bazie( $wpdb, 75, 'done', '2026-08-09 09:00:00' );         // zamkniety

	$odzyskane = Runner::recover_stalled();

	k5p_check( 2 === $odzyskane, 'odzyskane DOKLADNIE dwa wiersze (jest: ' . $odzyskane . ')' );
	k5p_check( 'new' === $wpdb->tabela[71]['status'] && 'new' === $wpdb->tabela[72]['status'], 'oba porzucone wrocily do kolejki' );
	k5p_check( 'processing' === $wpdb->tabela[73]['status'], 'wiersz mlodszy niz prog zostaje przy pracujacym przebiegu' );
	k5p_check( 'new' === $wpdb->tabela[74]['status'] && '2026-08-09 10:00:00' === $wpdb->tabela[74]['updated_at'], 'wiersz `new` nietkniety, takze jego znacznik czasu' );
	k5p_check( 'done' === $wpdb->tabela[75]['status'], 'wiersz zamkniety nietkniety' );

	// -----------------------------------------------------------------------
	echo "\n=== 10. Atrapa rozumiala kazde wyslane zapytanie ===\n";
	// -----------------------------------------------------------------------
	/*
	 * Sieć asekuracyjna tego zestawu. Gdyby ksztalt ktoregokolwiek `UPDATE`-a
	 * sie zmienil, atrapa przestalaby go stosowac do stanu tabeli, a wszystkie
	 * asercje powyzej zrobilyby sie zielone z powodu, ktorego nikt nie chcial.
	 */
	$nieznane = $GLOBALS['__nierozpoznane'];

	k5p_check(
		0 === count( $nieznane ),
		'zero zapytan nierozpoznanych w CALYM zestawie (jest: ' . count( $nieznane ) . ( $nieznane ? ' — ' . $nieznane[0] : '' ) . ')'
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
