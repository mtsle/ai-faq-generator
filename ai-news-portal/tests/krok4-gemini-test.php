<?php
/**
 * Krok 4, etap 4.1 — klient Gemini: rezerwacja slotu, budzet czasu, schemat.
 *
 * Ten zestaw pilnuje rzeczy, ktore w tej klasie psuja sie PO CICHU, czyli bez
 * jednego bledu w logu, a kosztuja darmowa pule 20 wywolan na dobe:
 *
 *   1. LICZNIK LICZONY PO ODPOWIEDZI zamiast przed zadaniem. Timeout w polowie
 *      wywolania zuzywa pule po stronie Google i nie zwieksza licznika po
 *      naszej — po kilku takich pula konczy sie „za wczesnie", a wtyczka nadal
 *      uwaza, ze ma zapas (test 11, test 20).
 *   2. REZERWACJA BEZ ATOMOWOSCI. `get_option()` → `update_option()` ma miedzy
 *      odczytem a zapisem okno, w ktorym drugi przebieg czyta te sama wartosc.
 *      Przy stanie 19/20 dwa przebiegi robia wtedy DWA zadania i oba zapisuja
 *      20 (test 20 i „ktos byl szybszy" nizej).
 *   3. ZLA NAZWA POLA SCHEMATU. Sonda na zywym API 2026-08-07 rozstrzygnela,
 *      ze w `v1beta` obowiazuje `generationConfig.responseMimeType`
 *      + `responseSchema`, a `response_format` konczy sie HTTP 400. Gdyby
 *      jednak schemat wypadl z zadania, odpowiedz wraca jako zwykly tekst
 *      i NIC tego nie zglasza — stad asercje na sam ksztalt zadania.
 *   4. NAZWA MODELU Z USTAWIEN W SCIEZCE ADRESU. To pole klienta, wiec
 *      ukosnik albo dwukropek zmienialyby cel zadania.
 *
 * Testy z tabeli planu: **11** (licznik na 20/20 → zero zadan) i **20** (dwie
 * proby rezerwacji przy 19/20 → dokladnie jedno zadanie, licznik na 20).
 *
 * Prawdziwe klasy: `Gemini`, `Settings`.
 * Atrapy: `$wpdb` (z prawdziwym porownaniem starej wartosci w `UPDATE`),
 * `Http`, funkcje WordPressa, transport HTTP. Zero WordPressa i zero sieci.
 *
 * URUCHOMIENIE:  php tests/krok4-gemini-test.php
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
	function k4g_check( $cond, $label ) {
		global $fail, $ran;
		$ran++;
		echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n";
		if ( ! $cond ) {
			$fail++;
		}
	}

	// --- Atrapy funkcji WordPressa -----------------------------------------

	$GLOBALS['__opt']      = array();
	$GLOBALS['__dzis']     = '2026-08-07';
	$GLOBALS['__zadania']  = array();
	$GLOBALS['__odpowiedz'] = null;

	function current_time( $type, $gmt = 0 ) {
		if ( 'Y-m-d' === $type ) {
			return $GLOBALS['__dzis'];
		}
		return $GLOBALS['__dzis'] . ' 12:00:00';
	}

	function is_serialized( $data ) {
		return is_string( $data ) && (bool) preg_match( '/^[aOs]:\d+:/', $data );
	}

	function maybe_serialize( $data ) {
		return ( is_array( $data ) || is_object( $data ) ) ? serialize( $data ) : $data;
	}

	function maybe_unserialize( $data ) {
		return is_serialized( $data ) ? unserialize( $data ) : $data;
	}

	function get_option( $key, $default = false ) {
		if ( ! array_key_exists( $key, $GLOBALS['__opt'] ) ) {
			return $default;
		}
		return maybe_unserialize( $GLOBALS['__opt'][ $key ] );
	}

	/**
	 * Atrapa `add_option()` — jak w WordPressie, NIE nadpisuje istniejacej opcji.
	 *
	 * Ta wlasnosc jest tu istotna: na niej stoi pierwsza rezerwacja w zyciu
	 * instalacji. Atrapa, ktora by nadpisywala, zamieniala test w potwierdzenie
	 * wlasnych wyobrazen.
	 *
	 * @param string $key      Nazwa.
	 * @param mixed  $value    Wartosc.
	 * @param string $depr     Nieuzywane.
	 * @param bool   $autoload Nieuzywane w atrapie.
	 *
	 * @return bool
	 */
	function add_option( $key, $value = '', $depr = '', $autoload = true ) {
		if ( array_key_exists( $key, $GLOBALS['__opt'] ) ) {
			return false;
		}
		$GLOBALS['__opt'][ $key ] = maybe_serialize( $value );
		return true;
	}

	function update_option( $key, $value, $autoload = null ) {
		$GLOBALS['__opt'][ $key ] = maybe_serialize( $value );
		return true;
	}

	function wp_cache_delete( $key, $group = '' ) {
		return true;
	}

	function wp_json_encode( $data, $options = 0 ) {
		return json_encode( $data, $options | JSON_UNESCAPED_UNICODE );
	}

	function is_wp_error( $thing ) {
		return ( $thing instanceof AINP_Fake_WP_Error );
	}

	function wp_remote_retrieve_response_code( $response ) {
		return is_array( $response ) ? ( $response['response']['code'] ?? 0 ) : 0;
	}

	function wp_remote_retrieve_body( $response ) {
		return is_array( $response ) ? (string) ( $response['body'] ?? '' ) : '';
	}

	/**
	 * Atrapa transportu: zapisuje KAZDE zadanie i oddaje zaplanowana odpowiedz.
	 *
	 * @param string              $url  Adres.
	 * @param array<string,mixed> $args Argumenty.
	 *
	 * @return mixed
	 */
	function wp_remote_post( $url, $args = array() ) {
		$GLOBALS['__zadania'][] = array(
			'url'  => $url,
			'args' => $args,
		);
		$odp = $GLOBALS['__odpowiedz'];
		return ( null === $odp ) ? array( 'response' => array( 'code' => 200 ), 'body' => '{}' ) : $odp;
	}

	/** Atrapa `WP_Error`. */
	class AINP_Fake_WP_Error {

		private $msg;

		public function __construct( $msg ) {
			$this->msg = $msg;
		}

		public function get_error_message() {
			return $this->msg;
		}
	}

	/**
	 * Atrapa tabeli `options` z PRAWDZIWYM porownaniem starej wartosci.
	 *
	 * `UPDATE ... WHERE option_value = <stara>` zmienia wiersz TYLKO wtedy, gdy
	 * zastana wartosc jest identyczna — na tym stoi cala atomowosc rezerwacji.
	 * Atrapa, ktora by tego nie pilnowala, przepuscilaby mutacje wycinajaca ten
	 * warunek, czyli dokladnie ten blad, ktorego szukamy.
	 */
	class AINP_Fake_WPDB_K4G {

		public $options    = 'wp_options';
		public $last_error = '';

		/** Liczniki zapytan. */
		public $selects = 0;
		public $updates = 0;

		/** Wywolywane PRZED porownaniem w `UPDATE` — symuluje drugi przebieg. */
		public $intruz = null;

		/**
		 * Odpowiednik `wpdb::prepare()`: `%s` w apostrofach, `%d` jako liczba.
		 *
		 * @param string $sql  Zapytanie.
		 * @param mixed  ...$a Argumenty.
		 *
		 * @return string
		 */
		public function prepare( $sql, ...$a ) {
			if ( 1 === count( $a ) && is_array( $a[0] ) ) {
				$a = $a[0];
			}
			$sql = str_replace( '%s', "'%s'", $sql );
			foreach ( $a as $arg ) {
				$zamiennik = is_int( $arg ) ? (string) $arg : addslashes( (string) $arg );
				$sql       = preg_replace( '/%[sd]/', str_replace( '$', '\\$', $zamiennik ), $sql, 1 );
			}
			return $sql;
		}

		/**
		 * Wyciaga literaly z gotowego zapytania.
		 *
		 * @param string $sql Zapytanie.
		 *
		 * @return array<int,string>
		 */
		private function literaly( $sql ) {
			preg_match_all( "/'((?:[^'\\\\]|\\\\.)*)'/", $sql, $m );
			return array_map( 'stripslashes', $m[1] );
		}

		public function get_var( $sql ) {
			$this->selects++;
			$lit = $this->literaly( $sql );
			$key = $lit[0] ?? '';
			return array_key_exists( $key, $GLOBALS['__opt'] ) ? (string) $GLOBALS['__opt'][ $key ] : null;
		}

		public function query( $sql ) {
			$this->updates++;

			if ( is_callable( $this->intruz ) ) {
				$cb           = $this->intruz;
				$this->intruz = null;
				$cb();
			}

			$lit = $this->literaly( $sql );
			if ( count( $lit ) < 3 ) {
				return 0;
			}
			list( $nowa, $klucz, $stara ) = $lit;

			$zastana = array_key_exists( $klucz, $GLOBALS['__opt'] ) ? (string) $GLOBALS['__opt'][ $klucz ] : null;
			if ( (string) $zastana !== (string) $stara ) {
				return 0;
			}

			$GLOBALS['__opt'][ $klucz ] = $nowa;
			return 1;
		}
	}

	$GLOBALS['wpdb'] = new AINP_Fake_WPDB_K4G();
}

namespace AINP {

	/** Atrapa `Http` — potrzebne sa tylko dwie rzeczy z jej interfejsu. */
	final class Http {

		public const ENCODING = 'identity';

		public static function user_agent(): string {
			return 'AI News Portal/0.3.0';
		}
	}
}

namespace {

	require_once $root . '/src/Settings.php';
	require_once $root . '/src/Gemini.php';

	use AINP\Gemini;
	use AINP\Settings;

	/**
	 * Czysci stan miedzy scenariuszami.
	 *
	 * @param array<string,mixed> $ustawienia Nadpisania `ainp_settings`.
	 * @param string              $klucz      Klucz API.
	 *
	 * @return void
	 */
	function k4g_reset( array $ustawienia = array(), $klucz = 'AIzaTESTOWY_KLUCZ' ) {
		$GLOBALS['__opt']       = array();
		$GLOBALS['__zadania']   = array();
		$GLOBALS['__odpowiedz'] = null;
		$GLOBALS['__dzis']      = '2026-08-07';
		$GLOBALS['wpdb']        = new AINP_Fake_WPDB_K4G();

		if ( array() !== $ustawienia ) {
			$GLOBALS['__opt'][ Settings::OPTION ] = serialize( $ustawienia );
		}
		if ( '' !== $klucz ) {
			$GLOBALS['__opt'][ Settings::OPTION_KEY ] = $klucz;
		}
	}

	/**
	 * Ustawia licznik na zadany stan.
	 *
	 * @param int    $ile  Liczba zuzytych wywolan.
	 * @param string $data Data zapisu.
	 *
	 * @return void
	 */
	function k4g_licznik( $ile, $data = '2026-08-07' ) {
		$GLOBALS['__opt'][ Settings::OPTION_USAGE ] = serialize(
			array(
				'date'  => $data,
				'count' => $ile,
			)
		);
	}

	/**
	 * Odczytuje licznik z atrapy bazy.
	 *
	 * @return int
	 */
	function k4g_stan() {
		$raw = $GLOBALS['__opt'][ Settings::OPTION_USAGE ] ?? '';
		$val = maybe_unserialize( $raw );
		return is_array( $val ) ? (int) ( $val['count'] ?? -1 ) : -1;
	}

	/**
	 * Planuje odpowiedz transportu.
	 *
	 * @param int    $kod  Kod HTTP.
	 * @param string $body Cialo.
	 *
	 * @return void
	 */
	function k4g_odpowiedz( $kod, $body ) {
		$GLOBALS['__odpowiedz'] = array(
			'response' => array( 'code' => $kod ),
			'body'     => $body,
		);
	}

	/**
	 * Poprawna koperta odpowiedzi Gemini.
	 *
	 * @param string $tekst Tresc czesci `text`.
	 *
	 * @return string
	 */
	function k4g_koperta( $tekst ) {
		return json_encode(
			array(
				'candidates' => array(
					array(
						'content'      => array( 'parts' => array( array( 'text' => $tekst ) ) ),
						'finishReason' => 'STOP',
					),
				),
			)
		);
	}

	echo "=== KROK 4, ETAP 4.1: Gemini — slot, budzet, schemat ===\n\n";

	// ------------------------------------------------------------------
	echo "-- Ksztalt zadania: wymuszony schemat --\n";

	k4g_reset();
	$req  = Gemini::build_request( 'Napisz artykul.', array( 'Żywienie', 'Zdrowie' ) );
	$plaski = json_encode( $req );

	k4g_check( isset( $req['generationConfig']['responseSchema'] ), 'schemat lezy w generationConfig.responseSchema' );
	k4g_check( 'application/json' === ( $req['generationConfig']['responseMimeType'] ?? '' ), 'responseMimeType = application/json' );
	k4g_check( ! isset( $req['response_format'] ), 'zadanie NIE uzywa response_format (sonda: HTTP 400)' );
	k4g_check( false === strpos( $plaski, 'response_format' ), 'nazwa response_format nie wystepuje nigdzie w zadaniu' );
	k4g_check( 'Napisz artykul.' === ( $req['contents'][0]['parts'][0]['text'] ?? '' ), 'prompt trafia do contents[0].parts[0].text' );

	$schemat = $req['generationConfig']['responseSchema'];
	k4g_check( array( 'title', 'lead', 'content', 'topic' ) === array_keys( $schemat['properties'] ), 'schemat ma dokladnie cztery pola we wlasciwej kolejnosci' );
	k4g_check( array( 'title', 'lead', 'content', 'topic' ) === $schemat['required'], 'wszystkie cztery pola sa wymagane' );
	k4g_check( array( 'Żywienie', 'Zdrowie' ) === ( $schemat['properties']['topic']['enum'] ?? array() ), 'topic to enum z podanych kategorii' );
	k4g_check( 'string' === $schemat['properties']['content']['type'], 'content jest typu string' );

	$duble = Gemini::schema( array( 'Zdrowie', 'Zdrowie', ' ', 'Rasy' ) );
	k4g_check( array( 'Zdrowie', 'Rasy' ) === $duble['properties']['topic']['enum'], 'enum bez powtorek i bez pustych wpisow' );

	$pusty = Gemini::schema( array() );
	k4g_check( ! isset( $pusty['properties']['topic']['enum'] ), 'pusta lista kategorii NIE wysyla pustego enum (bledne zadanie)' );
	k4g_check( 'string' === $pusty['properties']['topic']['type'], 'przy pustej liscie topic zostaje zwyklym stringiem' );

	/*
	 * SCHEMAT MOZE NIESC WYLACZNIE POLA, KTORE API ZNA.
	 *
	 * `Schema` w Gemini nie jest pelnym JSON Schema — kazde nieznane pole
	 * wywraca CALE zadanie na HTTP 400, razem z poprawna reszta. Zmierzone
	 * na zywym API 2026-08-07 przy odbiorze Kroku 4: `additionalProperties`
	 * dopisane „na wszelki wypadek" dalo „Unknown name additionalProperties
	 * at 'generation_config.response_schema'" i zaden artykul nie mogl powstac.
	 * Atrapy tego nie zlapaly, bo atrapa przyjmuje kazde cialo zadania.
	 *
	 * Stad biala lista: nowe pole w schemacie wymaga POTWIERDZENIA na zywym
	 * API i dopisania tutaj — asercja jest tansza niz kolejny nieudany odbior.
	 */
	$dozwolone_schemat = array( 'type', 'properties', 'required', 'propertyOrdering' );
	$dozwolone_pole    = array( 'type', 'enum' );

	k4g_check(
		array() === array_diff( array_keys( $schemat ), $dozwolone_schemat ),
		'schemat nie niesie zadnego pola spoza bialej listy API'
	);
	k4g_check( ! isset( $schemat['additionalProperties'] ), 'w szczegolnosci NIE ma additionalProperties (HTTP 400 na zywym API)' );

	$obce = array();
	foreach ( $schemat['properties'] as $nazwa => $opis ) {
		$obce = array_merge( $obce, array_diff( array_keys( $opis ), $dozwolone_pole ) );
	}
	k4g_check( array() === $obce, 'opis kazdego pola tez trzyma sie bialej listy' );

	k4g_check( Gemini::MAX_OUTPUT_TOKENS === ( $req['generationConfig']['maxOutputTokens'] ?? 0 ), 'sufit tokenow wyjscia jest w zadaniu' );
	k4g_check( Gemini::MAX_OUTPUT_TOKENS >= 4096, 'sufit tokenow hojny — model zjada czesc budzetu na thoughts' );

	// ------------------------------------------------------------------
	echo "\n-- Nazwa modelu z Ustawien trafia do sciezki adresu --\n";

	k4g_reset( array( 'model' => 'gemini-2.5-flash' ) );
	k4g_check( 'gemini-2.5-flash' === Gemini::model(), 'poprawna nazwa modelu przechodzi bez zmian' );

	k4g_reset( array( 'model' => 'gemini-2.5-flash:generateContent?key=x' ) );
	k4g_check( false === strpos( Gemini::model(), '?' ), 'znak zapytania wyciety z nazwy modelu' );
	k4g_check( false === strpos( Gemini::model(), ':' ), 'dwukropek wyciety z nazwy modelu' );

	k4g_reset( array( 'model' => '../../v1/models/inny' ) );
	k4g_check( false === strpos( Gemini::model(), '/' ), 'ukosnik wyciety — nie da sie przekierowac zadania' );

	k4g_reset( array( 'model' => '   ' ) );
	k4g_check( Gemini::FALLBACK_MODEL === Gemini::model(), 'pusta nazwa modelu wraca do domyslnej' );

	// ------------------------------------------------------------------
	echo "\n-- Budzet czasu: timeout dobrany do tego, co zostalo --\n";

	k4g_check( Gemini::TIMEOUT_MAX === Gemini::timeout_for( null ), 'brak budzetu (panel) = pelny timeout' );
	k4g_check( 12 === Gemini::timeout_for( 12.9 ), 'timeout przyciety do pozostalego czasu' );
	k4g_check( Gemini::TIMEOUT_MAX === Gemini::timeout_for( 120.0 ), 'timeout nigdy nie przekracza TIMEOUT_MAX' );
	k4g_check( 0 === Gemini::timeout_for( 7.9 ), 'ponizej TIMEOUT_MIN wywolania NIE wolno zaczynac' );
	k4g_check( Gemini::TIMEOUT_MIN === Gemini::timeout_for( (float) Gemini::TIMEOUT_MIN ), 'dokladnie na progu wywolanie jest dozwolone' );
	k4g_check( 0 === Gemini::timeout_for( 0.0 ), 'zerowy budzet = odmowa' );

	// ------------------------------------------------------------------
	echo "\n-- Rezerwacja slotu: pierwsza w zyciu instalacji --\n";

	k4g_reset( array( 'daily_cap' => 20 ) );
	$r = Gemini::reserve_slot();
	k4g_check( true === $r['ok'], 'pierwsza rezerwacja przechodzi' );
	k4g_check( 1 === $r['used'], 'licznik po pierwszej rezerwacji = 1' );
	k4g_check( 1 === k4g_stan(), 'stan zapisany w bazie = 1' );
	k4g_check( 20 === $r['cap'], 'sufit wziety z Ustawien' );

	$r = Gemini::reserve_slot();
	k4g_check( 2 === $r['used'], 'druga rezerwacja podnosi licznik do 2' );
	k4g_check( 2 === k4g_stan(), 'stan w bazie = 2' );

	// ------------------------------------------------------------------
	echo "\n-- TEST 20: dwie proby przy 19/20 --\n";

	k4g_reset( array( 'daily_cap' => 20 ) );
	k4g_licznik( 19 );
	k4g_odpowiedz( 200, k4g_koperta( '{"title":"T","lead":"L","content":"C","topic":"Zdrowie"}' ) );

	$a = Gemini::generate( 'prompt', array( 'Zdrowie' ) );
	$b = Gemini::generate( 'prompt', array( 'Zdrowie' ) );

	k4g_check( true === $a['ok'], 'pierwsze wywolanie przy 19/20 przechodzi' );
	k4g_check( false === $b['ok'], 'drugie wywolanie przy 20/20 jest odrzucone' );
	k4g_check( 'cap' === $b['reason'], 'powod odmowy to wyczerpany sufit' );
	k4g_check( Gemini::NOTE_CAP === $b['error'], 'komunikat odmowy mowi o suficie dobowym' );
	k4g_check( 1 === count( $GLOBALS['__zadania'] ), 'dokladnie JEDNO zadanie do Gemini' );
	k4g_check( 20 === k4g_stan(), 'licznik konczy na 20, nie na 21' );

	// ------------------------------------------------------------------
	echo "\n-- TEST 11: licznik na 20/20 --\n";

	k4g_reset( array( 'daily_cap' => 20 ) );
	k4g_licznik( 20 );
	$r = Gemini::generate( 'prompt', array( 'Zdrowie' ) );
	k4g_check( false === $r['ok'], 'przy 20/20 wywolanie nie dochodzi do skutku' );
	k4g_check( 0 === count( $GLOBALS['__zadania'] ), 'ZERO zadan do Gemini przy wyczerpanym suficie' );
	k4g_check( 20 === k4g_stan(), 'licznik nietkniety' );
	k4g_check( 0 === $GLOBALS['wpdb']->updates, 'przy wyczerpanym suficie nie ma nawet proby zapisu' );

	// ------------------------------------------------------------------
	echo "\n-- Sufit z Ustawien, nie ze stalej --\n";

	k4g_reset( array( 'daily_cap' => 2 ) );
	Gemini::reserve_slot();
	Gemini::reserve_slot();
	$r = Gemini::reserve_slot();
	k4g_check( false === $r['ok'], 'sufit 2 z Ustawien odmawia trzeciej rezerwacji' );
	k4g_check( 2 === $r['cap'], 'odmowa raportuje sufit z Ustawien' );

	k4g_reset( array( 'daily_cap' => 0 ) );
	$r = Gemini::reserve_slot();
	k4g_check( false === $r['ok'], 'sufit 0 wylacza wywolania calkowicie' );
	k4g_check( 0 === $GLOBALS['wpdb']->selects, 'sufit 0 nie dotyka nawet bazy' );

	k4g_reset( array( 'daily_cap' => 50 ) );
	k4g_licznik( 30 );
	$r = Gemini::reserve_slot();
	k4g_check( true === $r['ok'], 'podniesiony sufit przepuszcza dalej' );
	k4g_check( 31 === k4g_stan(), 'licznik idzie dalej od zastanej wartosci' );

	// ------------------------------------------------------------------
	echo "\n-- Nowa doba zeruje licznik --\n";

	k4g_reset( array( 'daily_cap' => 20 ) );
	k4g_licznik( 20, '2026-08-06' );
	$r = Gemini::reserve_slot();
	k4g_check( true === $r['ok'], 'wpis z wczoraj nie blokuje dzisiejszych wywolan' );
	k4g_check( 1 === $r['used'], 'licznik startuje od 1 w nowej dobie' );
	k4g_check( 1 === k4g_stan(), 'w bazie zostaje stan nowej doby' );

	$u = Gemini::usage();
	k4g_check( '2026-08-07' === $u['date'], 'usage() raportuje dzisiejsza date' );
	k4g_check( 1 === $u['count'], 'usage() raportuje biezacy licznik' );
	k4g_check( 0 === $GLOBALS['wpdb']->updates - 1, 'usage() samo niczego nie rezerwuje' );

	// ------------------------------------------------------------------
	echo "\n-- Atomowosc: ktos byl szybszy miedzy odczytem a zapisem --\n";

	k4g_reset( array( 'daily_cap' => 20 ) );
	k4g_licznik( 5 );

	// Drugi przebieg podbija licznik dokladnie w oknie miedzy SELECT-em a UPDATE-em.
	$GLOBALS['wpdb']->intruz = function () {
		k4g_licznik( 6 );
	};

	$r = Gemini::reserve_slot();
	k4g_check( true === $r['ok'], 'po przegranym wyscigu rezerwacja udaje sie za drugim podejsciem' );
	k4g_check( 7 === $r['used'], 'licznik uwzglednia cudzy zapis (5 → 6 → 7), nie nadpisuje go' );
	k4g_check( 7 === k4g_stan(), 'w bazie zostaje 7 — nikt nie zgubil wywolania' );
	k4g_check( 2 === $GLOBALS['wpdb']->updates, 'byly dwie proby UPDATE: przegrana i wygrana' );

	// Intruz nie odpuszcza nigdy: rezerwacja MUSI sie poddac, a nie zapetlic.
	k4g_reset( array( 'daily_cap' => 20 ) );
	k4g_licznik( 5 );
	$licz = 5;
	$GLOBALS['wpdb']->intruz = null;
	$wpdb_uparty = new class() extends AINP_Fake_WPDB_K4G {
		public function query( $sql ) {
			$this->updates++;
			return 0;
		}
	};
	$GLOBALS['wpdb'] = $wpdb_uparty;
	$r = Gemini::reserve_slot();
	k4g_check( false === $r['ok'], 'przy stalym przegrywaniu wyscigu slot NIE jest brany' );
	k4g_check( 'busy' === $r['reason'], 'powod odmowy odrozniony od wyczerpanego sufitu' );
	k4g_check( Gemini::RESERVE_ATTEMPTS === $wpdb_uparty->updates, 'liczba prob ograniczona — brak petli bez konca' );

	// ------------------------------------------------------------------
	echo "\n-- Kolejnosc bram: klucz i czas przed slotem --\n";

	k4g_reset( array( 'daily_cap' => 20 ), '' );
	$r = Gemini::generate( 'prompt', array( 'Zdrowie' ), 20.0 );
	k4g_check( 'no_key' === $r['reason'], 'brak klucza konczy sie odmowa' );
	k4g_check( Gemini::NOTE_NO_KEY === $r['error'], 'komunikat mowi o braku klucza' );
	k4g_check( 0 === count( $GLOBALS['__zadania'] ), 'brak klucza = zero zadan' );
	k4g_check( -1 === k4g_stan(), 'brak klucza NIE zuzywa slotu' );

	k4g_reset( array( 'daily_cap' => 20 ) );
	$r = Gemini::generate( 'prompt', array( 'Zdrowie' ), 3.0 );
	k4g_check( 'time' === $r['reason'], 'za maly budzet czasu konczy sie odmowa' );
	k4g_check( Gemini::NOTE_TIME === $r['error'], 'komunikat mowi o budzecie czasu' );
	k4g_check( 0 === count( $GLOBALS['__zadania'] ), 'za maly budzet = zero zadan' );
	k4g_check( -1 === k4g_stan(), 'za maly budzet NIE zuzywa slotu' );

	// ------------------------------------------------------------------
	echo "\n-- Zadanie: naglowki, timeout, adres --\n";

	k4g_reset( array( 'daily_cap' => 20, 'model' => 'gemini-2.5-flash' ) );
	k4g_odpowiedz( 200, k4g_koperta( '{"title":"T","lead":"L","content":"C","topic":"Rasy"}' ) );
	$r = Gemini::generate( 'prompt', array( 'Rasy' ), 12.0 );

	$z = $GLOBALS['__zadania'][0];
	k4g_check( true === $r['ok'], 'poprawna odpowiedz konczy sie powodzeniem' );
	k4g_check( 'Rasy' === ( $r['json']['topic'] ?? '' ), 'zdekodowany JSON wraca w polu json' );
	k4g_check( 1 === $r['used'], 'wynik raportuje stan licznika' );
	k4g_check( false !== strpos( $z['url'], '/v1beta/models/gemini-2.5-flash:generateContent' ), 'adres zbudowany z modelu z Ustawien' );
	k4g_check( 'AIzaTESTOWY_KLUCZ' === ( $z['args']['headers']['x-goog-api-key'] ?? '' ), 'klucz idzie w naglowku x-goog-api-key' );
	k4g_check( false === strpos( $z['url'], 'AIzaTESTOWY_KLUCZ' ), 'klucza NIE ma w adresie (logi, Referer)' );
	k4g_check( 12 === ( $z['args']['timeout'] ?? 0 ), 'timeout zadania rowny dobranemu budzetowi' );
	k4g_check( 'identity' === ( $z['args']['headers']['Accept-Encoding'] ?? '' ), 'Accept-Encoding: identity — sufit liczy te same bajty' );
	k4g_check( 0 === ( $z['args']['redirection'] ?? -1 ), 'zero przekierowan dla API' );
	k4g_check( Gemini::LIMIT_RESPONSE === ( $z['args']['limit_response_size'] ?? 0 ), 'sufit odpowiedzi ustawiony' );

	$cialo = json_decode( (string) ( $z['args']['body'] ?? '' ), true );
	k4g_check( isset( $cialo['generationConfig']['responseSchema'] ), 'wyslane cialo naprawde niesie schemat' );

	// ------------------------------------------------------------------
	echo "\n-- Bledy po stronie modelu: slot zuzyty, ale wynik uczciwy --\n";

	k4g_reset( array( 'daily_cap' => 20 ) );
	k4g_odpowiedz( 429, '{"error":{"status":"RESOURCE_EXHAUSTED"}}' );
	$r = Gemini::generate( 'prompt', array( 'Zdrowie' ), 20.0 );
	k4g_check( false === $r['ok'], '429 to porazka' );
	k4g_check( 'status' === $r['reason'], 'powod: kod odpowiedzi' );
	k4g_check( 429 === $r['code'], 'kod HTTP wraca w wyniku' );
	k4g_check( 1 === k4g_stan(), '429 ZUZYWA slot — zadanie doszlo do Google' );

	k4g_reset( array( 'daily_cap' => 20 ) );
	$GLOBALS['__odpowiedz'] = new AINP_Fake_WP_Error( 'cURL error 28' );
	$r = Gemini::generate( 'prompt', array( 'Zdrowie' ), 20.0 );
	k4g_check( 'transport' === $r['reason'], 'blad transportu rozpoznany' );
	k4g_check( 1 === k4g_stan(), 'timeout ZUZYWA slot — to jest caly powod rezerwacji z gory' );

	k4g_reset( array( 'daily_cap' => 20 ) );
	k4g_odpowiedz( 200, k4g_koperta( 'to nie jest JSON' ) );
	$r = Gemini::generate( 'prompt', array( 'Zdrowie' ), 20.0 );
	k4g_check( false === $r['ok'], 'niepoprawny JSON to porazka' );
	k4g_check( 'bad_json' === $r['reason'], 'powod: bad_json (galaz obronna dla etapu 4.4)' );
	k4g_check( 'to nie jest JSON' === $r['text'], 'surowy tekst wraca — ponowienie ma na czym stanac' );

	k4g_reset( array( 'daily_cap' => 20 ) );
	k4g_odpowiedz( 200, k4g_koperta( '   ' ) );
	$r = Gemini::generate( 'prompt', array( 'Zdrowie' ), 20.0 );
	k4g_check( 'empty' === $r['reason'], 'pusta odpowiedz rozpoznana osobno' );

	k4g_reset( array( 'daily_cap' => 20 ) );
	k4g_odpowiedz( 200, '{"candidates":[]}' );
	$r = Gemini::generate( 'prompt', array( 'Zdrowie' ), 20.0 );
	k4g_check( 'empty' === $r['reason'], 'koperta bez kandydatow nie wywraca sie bledem PHP' );

	// Odpowiedz sklejana z kilku czesci — model bywa dzieli tekst.
	k4g_reset( array( 'daily_cap' => 20 ) );
	k4g_odpowiedz(
		200,
		json_encode(
			array(
				'candidates' => array(
					array(
						'content' => array(
							'parts' => array(
								array( 'text' => '{"title":"T","lead":"L",' ),
								array( 'text' => '"content":"C","topic":"Rasy"}' ),
							),
						),
					),
				),
			)
		)
	);
	$r = Gemini::generate( 'prompt', array( 'Rasy' ), 20.0 );
	k4g_check( true === $r['ok'], 'tekst rozbity na kilka czesci jest sklejany' );
	k4g_check( 'C' === ( $r['json']['content'] ?? '' ), 'sklejony JSON dekoduje sie poprawnie' );

	// ------------------------------------------------------------------
	echo "\n";
	echo '=== Asercji: ' . $ran . ' | bledow: ' . $fail . " ===\n";
	if ( 0 === $fail ) {
		echo "WSZYSTKIE OK\n";
		exit( 0 );
	}
	echo "BŁĘDY\n";
	exit( 1 );
}
