<?php
/**
 * Krok 4, etap 4.4 — ponowienie zepsutego JSON-a: RAZ i tylko w tym przebiegu.
 *
 * Po wymuszeniu schematu (etap 4.1) niepoprawny JSON jest praktycznie mozliwy
 * tylko przez obciecie odpowiedzi w transporcie. Ponowienie zostaje jako galaz
 * OBRONNA — i wlasnie dlatego jego granice musza byc pilnowane asercjami:
 * kazde wywolanie kosztuje jeden slot z dobowej puli 20, wiec ponowienie „na
 * wszelki wypadek" przy kazdym bledzie zjadaloby pule w kwadrans.
 *
 * Ten zestaw pilnuje czterech granic:
 *
 *   1. ZEPSUTY JSON ponawiany DOKLADNIE raz — dwa wywolania, nie trzy.
 *   2. KAZDY INNY powod konczy sprawe po pierwszym wywolaniu: brak klucza,
 *      wyczerpany sufit, budzet czasu, transport, kod inny niz 200, pusta
 *      odpowiedz. Zadne z nich nie naprawi sie powtorzeniem w tym samym ticku.
 *   3. ODPOWIEDZ NIEZGODNA Z KONTRAKTEM tez NIE jest ponawiana — model dostal
 *      ten sam material i te sama instrukcje, wiec odda to samo.
 *   4. BUDZET CZASU odliczany MIEDZY probami, inaczej dwie proby po 30 s
 *      mieszcza sie w 20 s budzetu wylacznie na papierze.
 *
 * Test 7 z tabeli planu: zepsuty JSON → 0 wpisow, ponowienie dokladnie raz.
 *
 * Prawdziwe klasy: `Runner::ask_model()`, `Gemini`, `Validator`, `Settings`.
 * Atrapy: transport HTTP, `$wpdb`, `Filter`, `Article`, `Http`, `Plugin`.
 *
 * URUCHOMIENIE:  php tests/krok4-ponowienie-test.php
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
	function k4p_check( $cond, $label ) {
		global $fail, $ran;
		$ran++;
		echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n";
		if ( ! $cond ) {
			$fail++;
		}
	}

	$GLOBALS['__opt']       = array();
	$GLOBALS['__zadania']   = array();
	$GLOBALS['__plan']      = array();
	$GLOBALS['__timeouty']  = array();

	function current_time( $type, $gmt = 0 ) {
		return ( 'Y-m-d' === $type ) ? '2026-08-07' : '2026-08-07 12:00:00';
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

	function get_option( $k, $default = false ) {
		if ( ! array_key_exists( $k, $GLOBALS['__opt'] ) ) {
			return $default;
		}
		return maybe_unserialize( $GLOBALS['__opt'][ $k ] );
	}

	function add_option( $k, $v = '', $depr = '', $autoload = true ) {
		if ( array_key_exists( $k, $GLOBALS['__opt'] ) ) {
			return false;
		}
		$GLOBALS['__opt'][ $k ] = maybe_serialize( $v );
		return true;
	}

	function update_option( $k, $v, $autoload = null ) {
		$GLOBALS['__opt'][ $k ] = maybe_serialize( $v );
		return true;
	}

	function wp_cache_delete( $k, $g = '' ) {
		return true;
	}

	function wp_json_encode( $d, $o = 0 ) {
		return json_encode( $d, $o | JSON_UNESCAPED_UNICODE );
	}

	function is_wp_error( $t ) {
		return ( $t instanceof AINP_Fake_WP_Error_K4P );
	}

	function wp_remote_retrieve_response_code( $r ) {
		return is_array( $r ) ? ( $r['response']['code'] ?? 0 ) : 0;
	}

	function wp_remote_retrieve_body( $r ) {
		return is_array( $r ) ? (string) ( $r['body'] ?? '' ) : '';
	}

	function remove_accents( $t ) {
		return strtr(
			(string) $t,
			array(
				'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n',
				'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
				'Ą' => 'A', 'Ć' => 'C', 'Ę' => 'E', 'Ł' => 'L', 'Ń' => 'N',
				'Ó' => 'O', 'Ś' => 'S', 'Ź' => 'Z', 'Ż' => 'Z',
			)
		);
	}

	function wp_strip_all_tags( $t, $breaks = false ) {
		$t = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $t );
		return trim( strip_tags( (string) $t ) );
	}

	function wp_kses_post( $t ) {
		$t = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $t );
		return strip_tags( (string) $t, '<p><h2><h3><ul><ol><li><strong><em><a><br>' );
	}

	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}

	/** Atrapa `WP_Error`. */
	class AINP_Fake_WP_Error_K4P {

		private $msg;

		public function __construct( $msg ) {
			$this->msg = $msg;
		}

		public function get_error_message() {
			return $this->msg;
		}
	}

	/**
	 * Transport oddaje ZAPLANOWANE odpowiedzi, jedna po drugiej.
	 *
	 * Kolejka, a nie jedna stala odpowiedz — inaczej nie da sie sprawdzic
	 * scenariusza „pierwsze wywolanie urwane, drugie poprawne", czyli tego,
	 * po co ponowienie w ogole istnieje.
	 *
	 * @param string              $url  Adres.
	 * @param array<string,mixed> $args Argumenty.
	 *
	 * @return mixed
	 */
	function wp_remote_post( $url, $args = array() ) {
		$GLOBALS['__zadania'][]  = $args['body'] ?? '';
		$GLOBALS['__timeouty'][] = $args['timeout'] ?? 0;

		if ( array() === $GLOBALS['__plan'] ) {
			return array( 'response' => array( 'code' => 200 ), 'body' => '{}' );
		}

		return array_shift( $GLOBALS['__plan'] );
	}

	/** Atrapa bazy — `ask_model()` nie ma prawa jej dotknac. */
	class AINP_Fake_WPDB_K4P {

		public $options    = 'wp_options';
		public $prefix     = 'wp_';
		public $last_error = '';
		public $zapytania  = array();

		public function prepare( $sql, ...$a ) {
			if ( 1 === count( $a ) && is_array( $a[0] ) ) {
				$a = $a[0];
			}
			$sql = str_replace( '%s', "'%s'", $sql );
			foreach ( $a as $arg ) {
				$z   = is_int( $arg ) ? (string) $arg : addslashes( (string) $arg );
				$sql = preg_replace( '/%[sd]/', str_replace( '$', '\\$', $z ), $sql, 1 );
			}
			return $sql;
		}

		private function literaly( $sql ) {
			preg_match_all( "/'((?:[^'\\\\]|\\\\.)*)'/", $sql, $m );
			return array_map( 'stripslashes', $m[1] );
		}

		public function get_var( $sql ) {
			$lit = $this->literaly( $sql );
			$key = $lit[0] ?? '';
			return array_key_exists( $key, $GLOBALS['__opt'] ) ? (string) $GLOBALS['__opt'][ $key ] : null;
		}

		public function query( $sql ) {
			$this->zapytania[] = $sql;
			$lit               = $this->literaly( $sql );
			if ( count( $lit ) < 3 ) {
				return 0;
			}
			list( $nowa, $klucz, $stara ) = $lit;
			$zastana                      = array_key_exists( $klucz, $GLOBALS['__opt'] ) ? (string) $GLOBALS['__opt'][ $klucz ] : null;
			if ( (string) $zastana !== (string) $stara ) {
				return 0;
			}
			$GLOBALS['__opt'][ $klucz ] = $nowa;
			return 1;
		}

		public function get_results( $sql ) {
			return array();
		}
	}

	$GLOBALS['wpdb'] = new AINP_Fake_WPDB_K4P();
}

namespace AINP {

	/** Atrapa warstwy sieciowej. */
	final class Http {

		public const ENCODING = 'identity';

		public static function user_agent(): string {
			return 'AI News Portal/0.3.0';
		}
	}

	/** Atrapa bootstrapu. */
	class Plugin {

		public static function table(): string {
			return 'wp_ainp_items';
		}
	}
}

namespace {

	require_once $root . '/src/Settings.php';
	require_once $root . '/src/Dedup.php';
	require_once $root . '/src/Filter.php';
	require_once $root . '/src/Gemini.php';
	require_once $root . '/src/Validator.php';
	require_once $root . '/src/Runner.php';

	use AINP\Gemini;
	use AINP\Runner;
	use AINP\Settings;

	$kategorie = array( 'Żywienie', 'Zdrowie', 'Rasy' );

	/**
	 * Stan poczatkowy: klucz, sufit, pusta kolejka odpowiedzi.
	 *
	 * @return void
	 */
	function k4p_reset() {
		$GLOBALS['__opt']      = array(
			Settings::OPTION     => serialize( array( 'daily_cap' => 20 ) ),
			Settings::OPTION_KEY => 'AIzaTESTOWY',
		);
		$GLOBALS['__zadania']  = array();
		$GLOBALS['__plan']     = array();
		$GLOBALS['__timeouty'] = array();
		$GLOBALS['wpdb']       = new AINP_Fake_WPDB_K4P();
	}

	/**
	 * Odpowiedz transportu.
	 *
	 * @param int    $kod  Kod HTTP.
	 * @param string $body Cialo.
	 *
	 * @return array<string,mixed>
	 */
	function k4p_odp( $kod, $body ) {
		return array(
			'response' => array( 'code' => $kod ),
			'body'     => $body,
		);
	}

	/**
	 * Koperta Gemini z zadanym tekstem czesci.
	 *
	 * @param string $tekst Tekst.
	 *
	 * @return string
	 */
	function k4p_koperta( $tekst ) {
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

	/**
	 * Poprawna odpowiedz modelu jako JSON.
	 *
	 * @return string
	 */
	function k4p_dobra() {
		return json_encode(
			array(
				'title'   => 'Jak karmić psa latem',
				'lead'    => 'Krótki poradnik o żywieniu psa w czasie upałów i o pilnowaniu wody.',
				'content' => '<p>' . str_repeat( 'Pies potrzebuje regularnych spacerów i stałych pór karmienia. ', 20 ) . '</p>',
				'topic'   => 'Żywienie',
			)
		);
	}

	/**
	 * Wiersz gotowy do wyslania.
	 *
	 * @return object
	 */
	function k4p_wiersz() {
		return (object) array(
			'id'      => 5,
			'url'     => 'https://psy.pl/karma/',
			'title'   => 'Karma dla psa latem',
			'excerpt' => 'Zajawka',
			'content' => 'Materiał źródłowy o karmieniu psa w upały.',
		);
	}

	/**
	 * Licznik zuzytych wywolan AI.
	 *
	 * @return int
	 */
	function k4p_licznik() {
		$v = maybe_unserialize( $GLOBALS['__opt'][ Settings::OPTION_USAGE ] ?? '' );
		return is_array( $v ) ? (int) ( $v['count'] ?? 0 ) : 0;
	}

	echo "=== KROK 4, ETAP 4.4: ponowienie zepsutego JSON-a ===\n\n";

	// ------------------------------------------------------------------
	echo "-- Sciezka normalna: jedno wywolanie --\n";

	k4p_reset();
	$GLOBALS['__plan'] = array( k4p_odp( 200, k4p_koperta( k4p_dobra() ) ) );
	$r                 = Runner::ask_model( k4p_wiersz(), null, $kategorie );

	k4p_check( true === $r['ok'], 'poprawna odpowiedz przechodzi' );
	k4p_check( 1 === $r['calls'], 'poprawna odpowiedz to DOKLADNIE jedno wywolanie' );
	k4p_check( 1 === k4p_licznik(), 'zuzyty dokladnie jeden slot z puli' );
	k4p_check( 'Żywienie' === $r['data']['topic'], 'werdykt niesie pola po walidacji' );
	k4p_check( false === $r['terminal'], 'powodzenie nie jest terminalne' );
	k4p_check( array() === $GLOBALS['wpdb']->zapytania || 1 === count( $GLOBALS['wpdb']->zapytania ), 'baza ruszana wylacznie przez licznik' );

	// Prompt naprawde zawiera material pozycji.
	$cialo = json_decode( (string) $GLOBALS['__zadania'][0], true );
	$tekst = $cialo['contents'][0]['parts'][0]['text'] ?? '';
	k4p_check( false !== strpos( $tekst, 'Materiał źródłowy o karmieniu psa' ), 'prompt niesie tresc pozycji' );
	k4p_check( false !== strpos( $tekst, 'https://psy.pl/karma/' ), 'prompt niesie adres zrodla' );

	// ------------------------------------------------------------------
	echo "\n-- TEST 7: zepsuty JSON, ponowienie DOKLADNIE raz --\n";

	k4p_reset();
	$GLOBALS['__plan'] = array(
		k4p_odp( 200, k4p_koperta( '{"title":"Urwany artyk' ) ),
		k4p_odp( 200, k4p_koperta( '{"title":"Znowu urwan' ) ),
		k4p_odp( 200, k4p_koperta( k4p_dobra() ) ),
	);
	$r = Runner::ask_model( k4p_wiersz(), null, $kategorie );

	k4p_check( false === $r['ok'], 'dwa razy urwany JSON konczy sie porazka' );
	k4p_check( 2 === $r['calls'], 'DOKLADNIE dwa wywolania: pierwsze i jedno ponowienie' );
	k4p_check( 2 === count( $GLOBALS['__zadania'] ), 'do transportu poszly dokladnie dwa zadania' );
	k4p_check( 2 === k4p_licznik(), 'ponowienie zuzylo drugi slot z puli — i to jest jego cena' );
	k4p_check( 'bad_json' === $r['reason'], 'powod: niepoprawny JSON' );
	k4p_check( true === $r['terminal'], 'po ponowieniu sprawa jest terminalna — pozycja idzie na failed' );
	k4p_check( array() === $r['data'], 'zero pol do publikacji, czyli ZERO wpisow' );

	// ------------------------------------------------------------------
	echo "\n-- Ponowienie, ktore SIE UDAJE --\n";

	k4p_reset();
	$GLOBALS['__plan'] = array(
		k4p_odp( 200, k4p_koperta( '{"title":"Urwan' ) ),
		k4p_odp( 200, k4p_koperta( k4p_dobra() ) ),
	);
	$r = Runner::ask_model( k4p_wiersz(), null, $kategorie );

	k4p_check( true === $r['ok'], 'druga proba ratuje pozycje' );
	k4p_check( 2 === $r['calls'], 'i kosztuje dokladnie dwa wywolania' );
	k4p_check( 'Jak karmić psa latem' === $r['data']['title'], 'tresc z drugiej proby idzie dalej' );

	// ------------------------------------------------------------------
	echo "\n-- Czego NIE ponawiamy --\n";

	$przypadki = array(
		'429 (wyczerpany limit Google)' => array( k4p_odp( 429, '{"error":{"status":"RESOURCE_EXHAUSTED"}}' ), 'status' ),
		'500 po stronie modelu'         => array( k4p_odp( 500, 'blad' ), 'status' ),
		'pusta odpowiedz'               => array( k4p_odp( 200, k4p_koperta( '   ' ) ), 'empty' ),
	);

	foreach ( $przypadki as $opis => $plan ) {
		k4p_reset();
		$GLOBALS['__plan'] = array( $plan[0], k4p_odp( 200, k4p_koperta( k4p_dobra() ) ) );
		$r                 = Runner::ask_model( k4p_wiersz(), null, $kategorie );

		k4p_check( false === $r['ok'], $opis . ' — porazka' );
		k4p_check( 1 === $r['calls'], $opis . ' — bez ponowienia, jedno wywolanie' );
		k4p_check( $plan[1] === $r['reason'], $opis . ' — powod rozpoznany jako `' . $plan[1] . '`' );
	}

	k4p_reset();
	$GLOBALS['__plan'] = array( new AINP_Fake_WP_Error_K4P( 'cURL error 28' ), k4p_odp( 200, k4p_koperta( k4p_dobra() ) ) );
	$r                 = Runner::ask_model( k4p_wiersz(), null, $kategorie );
	k4p_check( 'transport' === $r['reason'] && 1 === $r['calls'], 'blad transportu bez ponowienia' );
	k4p_check( false === $r['terminal'], 'blad transportu NIE jest terminalny — pozycja ma prawo wrocic' );

	// Brak klucza i wyczerpany sufit: zero wywolan, zero ponowien.
	k4p_reset();
	unset( $GLOBALS['__opt'][ Settings::OPTION_KEY ] );
	$r = Runner::ask_model( k4p_wiersz(), null, $kategorie );
	k4p_check( 'no_key' === $r['reason'] && 1 === $r['calls'], 'brak klucza konczy sprawe od razu' );
	k4p_check( 0 === count( $GLOBALS['__zadania'] ), 'i nie wysyla ani jednego zadania' );
	k4p_check( false === $r['terminal'], 'brak klucza nie skazuje pozycji na failed' );

	k4p_reset();
	$GLOBALS['__opt'][ Settings::OPTION_USAGE ] = serialize( array( 'date' => '2026-08-07', 'count' => 20 ) );
	$r = Runner::ask_model( k4p_wiersz(), null, $kategorie );
	k4p_check( 'cap' === $r['reason'] && 0 === count( $GLOBALS['__zadania'] ), 'wyczerpany sufit: zero zadan' );
	k4p_check( false === $r['terminal'], 'pozycja czeka na jutro, nie idzie na failed' );

	// ------------------------------------------------------------------
	echo "\n-- Odpowiedz niezgodna z kontraktem NIE jest ponawiana --\n";

	k4p_reset();
	$GLOBALS['__plan'] = array(
		k4p_odp(
			200,
			k4p_koperta(
				json_encode(
					array(
						'title'   => 'Tytuł artykułu o psach',
						'lead'    => 'Zajawka odpowiedniej długości, żeby przeszła przez próg walidatora.',
						'content' => '<p>Za krótka treść.</p>',
						'topic'   => 'Żywienie',
					)
				)
			)
		),
		k4p_odp( 200, k4p_koperta( k4p_dobra() ) ),
	);
	$r = Runner::ask_model( k4p_wiersz(), null, $kategorie );

	k4p_check( false === $r['ok'], 'odpowiedz poza kontraktem odrzucona' );
	k4p_check( 1 === $r['calls'], 'i NIE ponawiana — ten sam material da ten sam wynik' );
	k4p_check( 'contract' === $r['reason'], 'powod odrozniony od bledu JSON-a' );
	k4p_check( true === $r['terminal'], 'kontrakt to porazka terminalna' );
	k4p_check( false !== strpos( $r['note'], 'znaków' ), 'notatka mowi, co konkretnie nie pasowalo' );

	k4p_reset();
	$GLOBALS['__plan'] = array(
		k4p_odp(
			200,
			k4p_koperta(
				json_encode(
					array(
						'title'   => 'Tytuł artykułu o psach',
						'lead'    => 'Zajawka odpowiedniej długości, żeby przeszła przez próg walidatora.',
						'content' => '<p>' . str_repeat( 'Pies potrzebuje ruchu i stałych pór karmienia każdego dnia. ', 20 ) . '</p>',
						'topic'   => 'Koty',
					)
				)
			)
		),
	);
	$r = Runner::ask_model( k4p_wiersz(), null, $kategorie );
	k4p_check( 'contract' === $r['reason'] && 1 === $r['calls'], 'obca kategoria: odrzucone bez ponowienia (test 8)' );

	// ------------------------------------------------------------------
	echo "\n-- Budzet czasu odliczany MIEDZY probami --\n";

	k4p_reset();
	$GLOBALS['__plan'] = array(
		k4p_odp( 200, k4p_koperta( '{"urwan' ) ),
		k4p_odp( 200, k4p_koperta( k4p_dobra() ) ),
	);
	$r = Runner::ask_model( k4p_wiersz(), 12.0, $kategorie );

	k4p_check( 2 === count( $GLOBALS['__timeouty'] ), 'poszly dwa wywolania' );
	/*
	 * ZAKRES, nie rownosc. Timeout to `floor(budzet - czas, ktory juz uplynal)`,
	 * a miedzy ustawieniem punktu odniesienia a wyslaniem zadania mija ulamek
	 * sekundy — raz nizej, raz powyzej granicy calej sekundy. Asercja na „rowne
	 * 12" przechodzila i padala na przemian, czyli mierzyla zegar, nie kod.
	 */
	k4p_check(
		$GLOBALS['__timeouty'][0] >= 11 && $GLOBALS['__timeouty'][0] <= 12,
		'pierwsza proba dostaje praktycznie caly pozostaly budzet (11–12 s)'
	);
	k4p_check( $GLOBALS['__timeouty'][1] <= $GLOBALS['__timeouty'][0], 'ponowienie NIE dostaje wiecej czasu niz pierwsza proba' );

	// Budzet ledwie na jedno wywolanie: ponowienia nie ma z czego zrobic.
	k4p_reset();
	$GLOBALS['__plan'] = array( k4p_odp( 200, k4p_koperta( '{"urwan' ) ) );
	$r                 = Runner::ask_model( k4p_wiersz(), 4.0, $kategorie );
	k4p_check( 'time' === $r['reason'], 'przy budzecie ponizej progu nie zaczynamy nawet pierwszej proby' );
	k4p_check( 0 === count( $GLOBALS['__zadania'] ), 'i nie idzie zadne zadanie' );
	k4p_check( 0 === k4p_licznik(), 'slot pozostaje nietkniety' );

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
