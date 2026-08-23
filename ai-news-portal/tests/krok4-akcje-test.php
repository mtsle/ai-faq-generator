<?php
/**
 * Krok 4, etap 4.6 — akcje panelu: uprawnienia, nonce, zamek, pierwszy przebieg.
 *
 * Akcja „Opublikuj teraz" jest jedynym miejscem we wtyczce, ktore na jedno
 * klikniecie wydaje pieniadze klienta — kazde wywolanie zabiera slot z dobowej
 * puli 20. Dlatego bramki wokol niej sa sprawdzane osobno:
 *
 *   1. UPRAWNIENIE I NONCE PRZED PRACA. Zaden zapis, zadne wywolanie modelu
 *      nie ma prawa wyprzedzic `guard()` (test 12 z tabeli planu).
 *   2. ZAMEK. Atomowa rezerwacja slotu (4.1) pilnuje, ze pula nie zostanie
 *      przekroczona, ale nie zatrzyma dwoch partii naraz. Dwuklik w przycisk
 *      ma nie odpalic drugiego przebiegu.
 *   3. ZAMEK ZDEJMOWANY W `finally`. Wyjatek w polowie partii nie moze zostawic
 *      przycisku martwego na cale `LOCK_TTL`.
 *   4. PIERWSZY PRZEBIEG PLANOWANY PO ZAPISIE USTAWIEN — ale tylko wtedy, gdy
 *      jest czym pracowac, i tylko RAZ, bo `wp_next_scheduled()` sam niczego
 *      nie planuje i dziesiec zapisow dalo by dziesiec zdarzen.
 *
 * Prawdziwe klasy: `Admin`, `Settings`, `Gemini`, `Runner`.
 * Atrapy: funkcje WordPressa, harmonogram, `$wpdb`.
 *
 * URUCHOMIENIE:  php tests/krok4-akcje-test.php
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
	function k4a_check( $cond, $label ) {
		global $fail, $ran;
		$ran++;
		echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n";
		if ( ! $cond ) {
			$fail++;
		}
	}

	/** Przerwanie zamiast przekierowania. */
	class AINP_Redirect extends \Exception {}

	/** Przerwanie zamiast `wp_die()`. */
	class AINP_Died extends \Exception {}

	$GLOBALS['__opt']        = array();
	$GLOBALS['__transient']  = array();
	$GLOBALS['__cron']       = array();
	$GLOBALS['__cap']        = true;
	$GLOBALS['__nonce_ok']   = true;
	$GLOBALS['__nonce_akcje'] = array();
	$GLOBALS['__zadania']    = array();
	$GLOBALS['__plan']       = array();
	$GLOBALS['__lock']       = null;   // Zamek jako wiersz w `options` (A5).
	$GLOBALS['__nieudane']    = 0;     // Ile pozycji ma status `failed` (A4).
	$GLOBALS['__nieudane_ai'] = 0;     // Ile z nich wroci do modelu (A4).

	function current_user_can( $cap ) {
		return (bool) $GLOBALS['__cap'];
	}

	function get_current_user_id() {
		return 3;
	}

	/**
	 * Atrapa `check_admin_referer()` — zapamietuje, DLA JAKIEJ akcji pytano.
	 *
	 * Sama zgodnosc nazwy akcji jest tu istotna: nonce wystawiony na „pobierz"
	 * nie moze przepuszczac akcji „opublikuj".
	 *
	 * @param string $action Akcja.
	 * @param string $pole   Pole formularza.
	 *
	 * @return bool
	 */
	function check_admin_referer( $action, $pole = '_wpnonce' ) {
		$GLOBALS['__nonce_akcje'][] = $action;

		if ( ! $GLOBALS['__nonce_ok'] ) {
			throw new AINP_Died( 'nonce' );
		}

		return true;
	}

	function wp_die( $t = '' ) {
		throw new AINP_Died( (string) $t );
	}

	function wp_safe_redirect( $url ) {
		throw new AINP_Redirect( (string) $url );
	}

	function admin_url( $s = '' ) {
		return 'https://example.test/wp-admin/' . $s;
	}

	function add_query_arg( $args, $url = '' ) {
		return $url . '?' . http_build_query( (array) $args );
	}

	function is_serialized( $d ) {
		return is_string( $d ) && (bool) preg_match( '/^[aOs]:\d+:/', $d );
	}

	function maybe_serialize( $d ) {
		return ( is_array( $d ) || is_object( $d ) ) ? serialize( $d ) : $d;
	}

	function maybe_unserialize( $d ) {
		return is_serialized( $d ) ? unserialize( $d ) : $d;
	}

	function get_option( $k, $default = false ) {
		return array_key_exists( $k, $GLOBALS['__opt'] ) ? maybe_unserialize( $GLOBALS['__opt'][ $k ] ) : $default;
	}

	function update_option( $k, $v, $autoload = null ) {
		$GLOBALS['__opt'][ $k ] = maybe_serialize( $v );
		return true;
	}

	function add_option( $k, $v = '', $d = '', $autoload = true ) {
		if ( array_key_exists( $k, $GLOBALS['__opt'] ) ) {
			return false;
		}
		$GLOBALS['__opt'][ $k ] = maybe_serialize( $v );
		return true;
	}

	function delete_option( $k ) {
		unset( $GLOBALS['__opt'][ $k ] );
		return true;
	}

	function wp_cache_delete( $k, $g = '' ) {
		return true;
	}

	function set_transient( $k, $v, $ttl = 0 ) {
		$GLOBALS['__transient'][ $k ] = $v;
		return true;
	}

	function get_transient( $k ) {
		return array_key_exists( $k, $GLOBALS['__transient'] ) ? $GLOBALS['__transient'][ $k ] : false;
	}

	function delete_transient( $k ) {
		unset( $GLOBALS['__transient'][ $k ] );
		return true;
	}

	// --- Harmonogram -------------------------------------------------------

	/**
	 * Atrapa harmonogramu — LISTA zdarzen na uchwyt, nie jedno.
	 *
	 * To nie jest szczegol: pierwsza wersja tej atrapy trzymala jedno zdarzenie
	 * na uchwyt, wiec drugie zaplanowanie po prostu je nadpisywalo i mutacja
	 * zdejmujaca `wp_next_scheduled()` PRZECHODZILA. Prawdziwy harmonogram
	 * WordPressa trzyma zdarzenia po znaczniku czasu, wiec duplikat jest w nim
	 * widoczny — i atrapa musi to odwzorowac, inaczej test sprawdza wlasne
	 * wyobrazenie o cronie.
	 *
	 * @param string $hook Uchwyt.
	 * @param array  $args Argumenty.
	 *
	 * @return int|false
	 */
	function wp_next_scheduled( $hook, $args = array() ) {
		if ( empty( $GLOBALS['__cron'][ $hook ] ) ) {
			return false;
		}
		return (int) $GLOBALS['__cron'][ $hook ][0]['time'];
	}

	function wp_schedule_single_event( $time, $hook, $args = array() ) {
		$GLOBALS['__cron'][ $hook ][] = array(
			'time'   => $time,
			'powtor' => false,
		);
		return true;
	}

	function wp_schedule_event( $time, $recurrence, $hook, $args = array() ) {
		$GLOBALS['__cron'][ $hook ][] = array(
			'time'   => $time,
			'powtor' => $recurrence,
		);
		return true;
	}

	function wp_unslash( $v ) {
		return $v;
	}

	function sanitize_text_field( $v ) {
		return trim( strip_tags( (string) $v ) );
	}

	function sanitize_textarea_field( $v ) {
		return trim( strip_tags( (string) $v ) );
	}

	function esc_url_raw( $u ) {
		return trim( (string) $u );
	}

	function esc_url( $u ) {
		return trim( (string) $u );
	}

	function esc_html( $t ) {
		return htmlspecialchars( (string) $t, ENT_QUOTES );
	}

	function esc_attr( $t ) {
		return htmlspecialchars( (string) $t, ENT_QUOTES );
	}

	function esc_textarea( $t ) {
		return htmlspecialchars( (string) $t, ENT_QUOTES );
	}

	function __( $t, $d = '' ) {
		return $t;
	}

	function esc_html__( $t, $d = '' ) {
		return htmlspecialchars( (string) $t, ENT_QUOTES );
	}

	function wp_nonce_field( $a, $p = '_wpnonce', $r = true, $e = true ) {
		echo '<input type="hidden" name="' . htmlspecialchars( (string) $p ) . '" value="nonce" />';
	}

	/**
	 * Atrapa `submit_button()` — oddaje takze atrybuty, na tym stoi asercja
	 * o wylaczonym przycisku przy braku klucza.
	 *
	 * @param string       $tekst Etykieta.
	 * @param string       $typ   Klasa.
	 * @param string       $nazwa Nazwa pola.
	 * @param bool         $wrap  Owijac w akapit.
	 * @param array|string $atrs  Dodatkowe atrybuty.
	 *
	 * @return void
	 */
	function submit_button( $tekst = '', $typ = 'primary', $nazwa = 'submit', $wrap = true, $atrs = '' ) {
		$dodatki = '';
		foreach ( (array) $atrs as $k => $v ) {
			$dodatki .= ' ' . $k . '="' . htmlspecialchars( (string) $v ) . '"';
		}
		echo '<button type="submit"' . $dodatki . '>' . htmlspecialchars( (string) $tekst ) . '</button>';
	}

	function checked( $a, $b = true, $e = true ) {
		return ( $a === $b ) ? 'checked="checked"' : '';
	}

	function add_action( $h, $cb, $p = 10, $a = 1 ) {
		return true;
	}

	function add_menu_page() {
		return '';
	}

	function add_submenu_page() {
		return '';
	}

	function current_time( $t, $gmt = 0 ) {
		return ( 'Y-m-d' === $t ) ? '2026-08-07' : '2026-08-07 12:00:00';
	}

	function remove_accents( $t ) {
		return (string) $t;
	}

	function wp_parse_url( $u, $c = -1 ) {
		return parse_url( $u, $c );
	}

	function wp_json_encode( $d, $o = 0 ) {
		return json_encode( $d, $o | JSON_UNESCAPED_UNICODE );
	}

	function is_wp_error( $t ) {
		return false;
	}

	function wp_remote_retrieve_response_code( $r ) {
		return is_array( $r ) ? ( $r['response']['code'] ?? 0 ) : 0;
	}

	function wp_remote_retrieve_body( $r ) {
		return is_array( $r ) ? (string) ( $r['body'] ?? '' ) : '';
	}

	function wp_remote_post( $url, $args = array() ) {
		$GLOBALS['__zadania'][] = $url;
		return array( 'response' => array( 'code' => 200 ), 'body' => '{}' );
	}

	function wp_strip_all_tags( $t, $b = false ) {
		return trim( strip_tags( (string) $t ) );
	}

	function wp_kses_post( $t ) {
		return (string) $t;
	}

	function get_posts( $args = array() ) {
		return array();
	}

	/** Atrapa bazy: tabela pozycji pusta, opcje dzialaja. */
	/**
	 * Emulacja ATOMOWEGO zamka z naprawy A5.
	 *
	 * Zamek nie jest juz transientem, tylko wierszem w tabeli `options` zakladanym
	 * przez `INSERT IGNORE`. Atrapa musi odwzorowac dokladnie to, na czym stoi cala
	 * naprawa: drugi proces dostaje ZERO zmienionych wierszy, a nie wyjatek i nie
	 * ciche nadpisanie.
	 *
	 * @param string $sql Zapytanie po podstawieniu wartosci albo z `%s`.
	 *
	 * @return int|null Liczba zmienionych wierszy albo `null`, gdy to nie zamek.
	 */
	function ainp_zamek_query( $sql ) {
		if ( false !== strpos( $sql, 'INSERT IGNORE INTO' ) && false !== strpos( $sql, 'option_name' ) ) {
			if ( null !== $GLOBALS['__lock'] ) {
				return 0;
			}
	
			$GLOBALS['__lock'] = (string) time();
	
			return 1;
		}
	
		if ( false !== strpos( $sql, 'DELETE FROM' ) && false !== strpos( $sql, 'option_name' ) ) {
			$GLOBALS['__lock'] = null;
	
			return 1;
		}
	
		if ( false !== strpos( $sql, 'SET option_value' ) ) {
			// CAS przejmujacy zamek po trupie — w tescie zawsze nieudany, bo atrapa
			// nie stawia zamkow starszych niz `LOCK_TTL`.
			return 0;
		}
	
		return null;
	}
	class AINP_Fake_WPDB_K4A {

		public $options   = 'wp_options';
		public $prefix    = 'wp_';
		public $last_error = '';
		public $zapytania = array();

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

		public function get_results( $sql ) {
			$this->zapytania[] = $sql;
			return array();
		}

		public function get_var( $sql ) {
			if ( false !== strpos( $sql, 'SELECT option_value' ) ) {
				return $GLOBALS['__lock'];
			}

			/*
			 * Liczniki z naprawy A4. Atrapa ROZROZNIA oba zapytania po warunku
			 * `content_hash`, zamiast oddawac jedna liczbe na oba — inaczej
			 * pomylenie „nieudanych" z „wracajacymi do modelu" byloby dla testu
			 * nieodrozninalne od poprawnego zachowania.
			 */
			if ( false !== strpos( $sql, 'COUNT(*)' ) ) {
				return false !== strpos( $sql, 'content_hash' )
					? (string) $GLOBALS['__nieudane_ai']
					: (string) $GLOBALS['__nieudane'];
			}

			preg_match_all( "/'((?:[^'\\\\]|\\\\.)*)'/", $sql, $m );
			$key = $m[1][0] ?? '';
			return array_key_exists( $key, $GLOBALS['__opt'] ) ? (string) $GLOBALS['__opt'][ $key ] : null;
		}

		public function query( $sql ) {
			$zamek = ainp_zamek_query( $sql );

			if ( null !== $zamek ) {
				return $zamek;
			}

			$this->zapytania[] = $sql;
			return 0;
		}
	}

	$GLOBALS['wpdb'] = new AINP_Fake_WPDB_K4A();
}

namespace AINP {

	/** Atrapa bootstrapu. */
	class Plugin {

		public const CPT       = 'ainp_article';
		public const TAX       = 'ainp_topic';
		public const CRON_HOOK = 'ainp_tick';
		public const META_ITEM = '_ainp_item_id';

		public static function table(): string {
			return 'wp_ainp_items';
		}
	}
}

namespace {

	require_once $root . '/src/Settings.php';
	require_once $root . '/src/Demo.php';
	require_once $root . '/src/Dedup.php';
	require_once $root . '/src/Filter.php';
	require_once $root . '/src/Http.php';
	require_once $root . '/src/Feed.php';
	require_once $root . '/src/Article.php';
	require_once $root . '/src/Gemini.php';
	require_once $root . '/src/Validator.php';
	require_once $root . '/src/Publisher.php';
	require_once $root . '/src/Runner.php';
	// Trait z ekranami MUSI byc zaladowany przed klasa, ktora go uzywa (etap 8.1).
	require_once $root . '/src/Admin_Screen.php';
	require_once $root . '/src/Admin.php';

	use AINP\Admin;
	use AINP\Settings;

	/**
	 * Stan poczatkowy.
	 *
	 * @param bool $klucz Czy klucz API jest zapisany.
	 *
	 * @return void
	 */
	function k4a_reset( $klucz = true ) {
		$GLOBALS['__opt'] = array(
			Settings::OPTION         => serialize( array( 'daily_cap' => 20 ) ),
			Settings::OPTION_SOURCES => array( 'https://psy.pl/feed/' ),
		);
		if ( $klucz ) {
			$GLOBALS['__opt'][ Settings::OPTION_KEY ] = 'AIzaTESTOWY';
		}
		$GLOBALS['__transient']   = array();
		$GLOBALS['__cron']        = array();
		$GLOBALS['__cap']         = true;
		$GLOBALS['__nonce_ok']    = true;
		$GLOBALS['__nonce_akcje'] = array();
		$GLOBALS['__zadania']     = array();
		$GLOBALS['__nieudane']    = 0;
		$GLOBALS['__nieudane_ai'] = 0;
		$GLOBALS['wpdb']          = new AINP_Fake_WPDB_K4A();
		$_POST                    = array();
		$_GET                     = array();
	}

	/**
	 * Uruchamia akcje, oddajac sposob jej zakonczenia.
	 *
	 * @param string $metoda Nazwa metody `Admin`.
	 *
	 * @return string `redirect`, `died` albo `brak`.
	 */
	function k4a_akcja( $metoda ) {
		try {
			Admin::$metoda();
		} catch ( AINP_Redirect $e ) {
			return 'redirect';
		} catch ( AINP_Died $e ) {
			return 'died';
		}
		return 'brak';
	}

	echo "=== KROK 4, ETAP 4.6: akcje panelu ===\n\n";

	// ------------------------------------------------------------------
	echo "-- TEST 12: akcja bez poprawnego nonce'a --\n";

	foreach ( array( 'handle_publish', 'handle_save_settings', 'handle_prepare', 'handle_fetch', 'handle_retry' ) as $akcja ) {
		k4a_reset();
		$GLOBALS['__nonce_ok'] = false;
		$przed                 = $GLOBALS['__opt'];

		$los = k4a_akcja( $akcja );

		k4a_check( 'died' === $los, $akcja . ': bez nonce\'a akcja jest odrzucona' );
		k4a_check( $przed === $GLOBALS['__opt'], $akcja . ': ZERO zmian w opcjach' );
		k4a_check( array() === $GLOBALS['__zadania'], $akcja . ': zero zadan sieciowych' );
		k4a_check( array() === $GLOBALS['__cron'], $akcja . ': zero zaplanowanych zdarzen' );
	}

	// ------------------------------------------------------------------
	echo "\n-- Uprawnienie sprawdzane PRZED nonce'em --\n";

	/*
	 * WSZYSTKIE piec akcji, ta sama lista co w TESCIE 12 (D5, audyt 8.10):
	 * do domkniecia audytu petla obejmowala trzy z pieciu handlerow, wiec
	 * zamiana kolejnosci w guard() dla `fetch`/`prepare` byla niewykrywalna.
	 */
	foreach ( array( 'handle_publish', 'handle_save_settings', 'handle_prepare', 'handle_fetch', 'handle_retry' ) as $akcja ) {
		k4a_reset();
		$GLOBALS['__cap'] = false;

		$los = k4a_akcja( $akcja );

		k4a_check( 'died' === $los, $akcja . ': bez uprawnienia akcja jest odrzucona' );
		k4a_check( array() === $GLOBALS['__nonce_akcje'], $akcja . ': do sprawdzania nonce\'a nawet nie dochodzi' );
	}

	// ------------------------------------------------------------------
	echo "\n-- Nonce jest sprawdzany DLA WLASCIWEJ akcji --\n";

	k4a_reset();
	k4a_akcja( 'handle_publish' );
	k4a_check( array( Admin::ACTION_PUBLISH ) === $GLOBALS['__nonce_akcje'], 'publikacja pyta o nonce akcji `ainp_publish`' );

	k4a_reset();
	k4a_akcja( 'handle_prepare' );
	k4a_check( array( Admin::ACTION_PREPARE ) === $GLOBALS['__nonce_akcje'], 'przygotowanie pyta o swoj wlasny nonce' );

	// ------------------------------------------------------------------
	echo "
-- P2: przycisk odzyskuje pozycje porzucone przez zabity przebieg --
";

	/*
	 * Do naprawy P2 odzysk zyl wylacznie w ticku, wiec wiersz zostawiony
	 * w `processing` byl niewidoczny dla obu zapytan wybierajacych az do
	 * nastepnej godziny — a klient, ktory wlasnie klikal, widzial pusty
	 * przebieg bez slowa wyjasnienia.
	 */
	foreach ( array( 'handle_prepare', 'handle_publish' ) as $akcja ) {
		k4a_reset();
		k4a_akcja( $akcja );

		$odzysk = array_values(
			array_filter(
				$GLOBALS['wpdb']->zapytania,
				function ( $sql ) {
					return false !== strpos( $sql, "SET status = 'new'" )
						&& false !== strpos( $sql, "WHERE status = 'processing'" );
				}
			)
		);

		k4a_check( 1 === count( $odzysk ), $akcja . ': odzysk porzuconych pozycji wykonany DOKLADNIE raz (jest: ' . count( $odzysk ) . ')' );
	}

	/*
	 * Bramka jest wczesniej niz odzysk: zadanie bez nonce'a ma nadal nie wysylac
	 * ANI JEDNEGO zapytania. Ta asercja pilnuje kolejnosci, ktorej sam odzysk
	 * juz nie widzi.
	 */
	k4a_reset();
	$GLOBALS['__nonce_ok'] = false;
	k4a_akcja( 'handle_prepare' );

	k4a_check( array() === $GLOBALS['wpdb']->zapytania, 'bez poprawnego nonce odzysk NIE rusza bazy (zapytan: ' . count( $GLOBALS['wpdb']->zapytania ) . ')' );
	$GLOBALS['__nonce_ok'] = true;

	// ------------------------------------------------------------------
	echo "\n-- Zamek: dwuklik w „Opublikuj teraz” --\n";

	k4a_reset();
	$los = k4a_akcja( 'handle_publish' );
	k4a_check( 'redirect' === $los, 'pierwsze klikniecie konczy sie przekierowaniem' );
	k4a_check( null === $GLOBALS['__lock'], 'zamek zdjety po skonczonej partii' );
	k4a_check( isset( $GLOBALS['__transient'][ Admin::TRANSIENT_PUB . 3 ] ), 'podsumowanie zapisane dla tego uzytkownika' );

	// Zamek trzymany przez inne zadanie.
	k4a_reset();
	$GLOBALS['__lock'] = (string) time();
	$przed_opt                                       = $GLOBALS['__opt'];

	$los = k4a_akcja( 'handle_publish' );

	k4a_check( 'redirect' === $los, 'drugie klikniecie tez konczy sie przekierowaniem' );
	k4a_check( ! isset( $GLOBALS['__transient'][ Admin::TRANSIENT_PUB . 3 ] ), 'ale NIE zapisuje podsumowania — partia sie nie odbyla' );
	k4a_check( $przed_opt === $GLOBALS['__opt'], 'i nie rusza licznika wywolan AI' );
	k4a_check( null !== $GLOBALS['__lock'], 'cudzy zamek zostaje nietkniety' );

	// ------------------------------------------------------------------
	echo "\n-- Pierwszy przebieg planowany po zapisaniu Ustawien --\n";

	k4a_reset();
	$_POST = array(
		'ainp_sources'   => 'https://psy.pl/feed/',
		'ainp_model'     => 'gemini-2.5-flash',
		'ainp_daily_cap' => '20',
	);
	k4a_akcja( 'handle_save_settings' );

	k4a_check( isset( $GLOBALS['__cron']['ainp_tick'] ), 'po zapisie Ustawien tick jest zaplanowany' );
	k4a_check( 1 === count( $GLOBALS['__cron']['ainp_tick'] ), 'dokladnie JEDNO zdarzenie' );
	k4a_check( false === $GLOBALS['__cron']['ainp_tick'][0]['powtor'], 'to POJEDYNCZE zdarzenie, nie harmonogram — ten nalezy do etapu 5.1' );
	k4a_check( $GLOBALS['__cron']['ainp_tick'][0]['time'] <= time(), 'zaplanowany na „już”, nie w przyszlosci' );

	// Drugi i trzeci zapis nie dokladaja kolejnych zdarzen.
	$czas = $GLOBALS['__cron']['ainp_tick'][0]['time'];
	k4a_akcja( 'handle_save_settings' );
	k4a_akcja( 'handle_save_settings' );
	k4a_check( 1 === count( $GLOBALS['__cron']['ainp_tick'] ), 'kolejne zapisy NIE dokładają zdarzeń' );
	k4a_check( $czas === $GLOBALS['__cron']['ainp_tick'][0]['time'], 'i nie przesuwaja istniejacego' );

	// Bez klucza nie ma czego planowac.
	k4a_reset( false );
	$_POST = array(
		'ainp_sources'   => 'https://psy.pl/feed/',
		'ainp_daily_cap' => '20',
	);
	k4a_akcja( 'handle_save_settings' );
	k4a_check( array() === $GLOBALS['__cron'], 'bez klucza API tick NIE jest planowany' );

	// Bez zrodel tez nie.
	k4a_reset();
	$_POST = array(
		'ainp_sources'   => '   ',
		'ainp_daily_cap' => '20',
	);
	k4a_akcja( 'handle_save_settings' );
	k4a_check( array() === $GLOBALS['__cron'], 'bez kanalow RSS tick NIE jest planowany' );

	// ------------------------------------------------------------------
	echo "\n-- Ekran Materialow: przycisk i licznik --\n";

	k4a_reset();
	ob_start();
	Admin::render_items();
	$html = (string) ob_get_clean();

	k4a_check( false !== strpos( $html, 'value="ainp_publish"' ), 'formularz publikacji jest na ekranie' );
	k4a_check( false !== strpos( $html, 'Opublikuj teraz' ), 'przycisk ma etykiete' );
	k4a_check( false !== strpos( $html, 'Wywołań AI dziś: 0 z 20' ), 'ekran pokazuje stan dobowej puli' );
	/*
	 * Asercja zawezona przy naprawie A4. Do etapu 5.4 na ekranie byl tylko JEDEN
	 * przycisk, ktory dalo sie wygasic, wiec szukanie slowa `disabled` w calym
	 * HTML-u wystarczalo. Od kiedy „Wznow nieudane" tez sie wygasza — przy zerze
	 * nieudanych pozycji — takie szukanie mowi o przypadkowym przycisku.
	 * Sprawdzamy wiec fragment formularza PUBLIKACJI, nie caly ekran.
	 */
	/**
	 * Wycina z ekranu SAM formularz publikacji.
	 *
	 * RAU-R08-001: wyciecie bylo tu wpisane w miejscu, a druga asercja o wygaszeniu
	 * przycisku publikacji (bez klucza API) pytala o caly ekran. Skoro `Wznow nieudane`
	 * tez sie wygasza, spelnial ja atrybut CUDZEGO przycisku. Jedno wyciecie w funkcji
	 * obsluguje oba pytania i nie da sie go pominac przez nieuwage.
	 *
	 * @param string $html Wyrenderowany ekran.
	 *
	 * @return string Fragment formularza albo pusty string, gdy formularza nie ma.
	 */
	function k4a_formularz_publikacji( $html ) {
		$od = strpos( $html, 'value="ainp_publish"' );

		if ( false === $od ) {
			return '';
		}

		$kawalek = substr( $html, (int) $od );

		return substr( $kawalek, 0, (int) strpos( $kawalek, '</form>' ) );
	}

	k4a_check( false === strpos( k4a_formularz_publikacji( $html ), 'disabled' ), 'przy zapisanym kluczu przycisk publikacji jest aktywny' );
	k4a_check( false !== strpos( $html, 'Wznów nieudane' ), 'obok stoi przycisk wznowienia (etap 5.4)' );
	k4a_check( false === strpos( $html, 'AIzaTESTOWY' ), 'klucz NIE wycieka na ekran Materiałów' );

	// ------------------------------------------------------------------
	echo "\n-- NAPRAWA A4: przycisk wznowienia zna swoj koszt --\n";

	/**
	 * Wycina formularz wznowienia z calego ekranu.
	 *
	 * Asercja „w calym HTML-u nie ma slowa `disabled`" starzeje sie po cichu:
	 * byla poprawna, dopoki na ekranie stal jeden przycisk, ktory dalo sie
	 * wygasic. Kazde pytanie o wygaszenie zawezamy wiec do formularza, ktorego
	 * dotyczy.
	 *
	 * @param string $html Caly ekran.
	 *
	 * @return string
	 */
	function k4a_formularz_wznowienia( $html ) {
		$od = strpos( $html, 'value="ainp_retry"' );

		if ( false === $od ) {
			return '';
		}

		$kawalek = substr( $html, (int) $od );

		return substr( $kawalek, 0, (int) strpos( $kawalek, '</form>' ) );
	}

	// Zero nieudanych: nie ma czego wznawiac, wiec przycisk jest wygaszony.
	k4a_reset();
	ob_start();
	Admin::render_items();
	$html = (string) ob_get_clean();

	k4a_check( false !== strpos( k4a_formularz_wznowienia( $html ), 'disabled' ), 'przy ZERZE nieudanych przycisk wznowienia jest wygaszony' );
	k4a_check( false !== strpos( $html, 'Nieudanych pozycji: 0' ), 'i ekran mowi wprost, ze nie ma czego wznawiac' );

	/*
	 * SEDNO A4. Wznowienie nie odroznialo bledu trwalego od przejsciowego,
	 * a klient nie mial jak zobaczyc ceny kliknięcia. Dziesiec pozycji, ktore
	 * padly dopiero przy modelu, zjada dziesiec slotow z dobowej puli DWUDZIESTU
	 * i pada ponownie na tym samym warunku. Rozroznienie jest w danych: odcisk
	 * tresci oddziela „ponowienie kosztuje zadanie HTTP" od „kosztuje slot".
	 */
	k4a_reset();
	$GLOBALS['__nieudane']    = 7;
	$GLOBALS['__nieudane_ai'] = 2;

	ob_start();
	Admin::render_items();
	$html = (string) ob_get_clean();

	k4a_check( false === strpos( k4a_formularz_wznowienia( $html ), 'disabled' ), 'przy siedmiu nieudanych przycisk wznowienia jest aktywny' );
	k4a_check( false !== strpos( $html, 'Nieudanych pozycji: 7' ), 'ekran podaje liczbe nieudanych pozycji' );
	k4a_check( false !== strpos( $html, 'w tym 2' ), 'i osobno te, ktore padly dopiero przy modelu — czyli cene kliknięcia w slotach' );
	k4a_check( false === strpos( $html, 'w tym 7' ), 'obu liczb NIE myli ze soba' );

	// ------------------------------------------------------------------
	echo "\n-- NAPRAWA A3: ekran pokazuje ostatni AUTOMATYCZNY przebieg --\n";

	/*
	 * Do naprawy `tick()` oddawal komplet danych, a `add_action()` je wyrzucal.
	 * Panel pokazywal wylacznie wyniki akcji KLIKNIETYCH, wiec automat, ktory
	 * od tygodnia niczego nie publikuje — bo skonczyla sie pula, bo padl kanal,
	 * bo zabraklo budzetu — byl nieodrozninalny od automatu, ktory nie dziala.
	 */
	k4a_reset();
	ob_start();
	Admin::render_items();
	$html = (string) ob_get_clean();

	k4a_check( false !== strpos( $html, 'Automat nie zgłosił' ), 'bez sladu ekran mowi wprost, ze automat sie nie odezwal' );
	k4a_check( false !== strpos( $html, 'zadania cykliczne ruchem, nie zegarem' ), 'i tlumaczy, ze WordPress potrzebuje ruchu na stronie' );

	k4a_reset();
	$GLOBALS['__transient'][ Admin::TRANSIENT_TICK ] = array(
		'time'      => '2026-08-09 14:00:00',
		'collect'   => array( 'added' => 5 ),
		'prepare'   => array( 'ready' => 3 ),
		'publish'   => array( 'published' => 1, 'calls' => 2, 'note' => 'Wyczerpany sufit dobowy wywołań AI' ),
		'skipped'   => array( 'publish' ),
		'recovered' => 4,
		'errors'    => array( 'collect' => 'kanał nie odpowiada' ),
	);

	ob_start();
	Admin::render_items();
	$html = (string) ob_get_clean();

	k4a_check( false !== strpos( $html, 'Ostatni automatyczny przebieg' ), 'ze sladem ekran pokazuje ostatni przebieg automatu' );
	k4a_check( false !== strpos( $html, '2026-08-09 14:00:00' ), 'razem ze znacznikiem czasu — inaczej nie wiadomo, czy cron chodzi' );
	k4a_check( false !== strpos( $html, 'Nowych pozycji: 5' ), 'i liczbami z faz, nie samym „bylo OK”' );
	k4a_check( false !== strpos( $html, 'Wywołań AI: 2' ), 'w tym zuzyciem dobowej puli' );
	k4a_check( false !== strpos( $html, 'Wyczerpany sufit' ), 'wyczerpana pula pokazana jako WSTRZYMANIE, nie jako blad' );
	k4a_check( false !== strpos( $html, 'Zabrakło czasu na etapy: publish' ), 'faza pominieta z braku czasu jest nazwana' );
	k4a_check( false !== strpos( $html, 'Odzyskanych pozycji' ), 'odzysk po przerwanym przebiegu tez widac' );
	k4a_check( false !== strpos( $html, 'kanał nie odpowiada' ), 'a bledy faz trafiaja na ekran pod nazwa fazy' );
	/*
	 * Slad NIE jest kasowany po pokazaniu: to stan automatu, nie komunikat
	 * o akcji. Skasowany po pierwszym wejsciu znikalby dokladnie temu, kto
	 * zaglada drugi raz, zeby sprawdzic, czy cos sie ruszylo.
	 */
	k4a_check( isset( $GLOBALS['__transient'][ Admin::TRANSIENT_TICK ] ), 'i PRZEZYWA wyswietlenie — to stan, nie jednorazowy komunikat' );

	// ------------------------------------------------------------------
	echo "\n-- NAPRAWA A4: komunikat po wznowieniu --\n";

	k4a_reset();
	$GLOBALS['__transient'][ Admin::TRANSIENT_RETRY . 3 ] = array(
		'revived' => 5,
		'ai'      => 2,
	);

	ob_start();
	Admin::render_items();
	$html = (string) ob_get_clean();

	k4a_check( false !== strpos( $html, 'Wznowionych pozycji: 5' ), 'komunikat podaje liczbe WZNOWIONYCH, nie liczbe wracajacych do modelu' );
	k4a_check( false !== strpos( $html, 'W tym 2' ), 'i osobno koszt w slotach dobowej puli' );

	// Bez klucza przycisk jest wylaczony i jest o tym slowo.
	k4a_reset( false );
	ob_start();
	Admin::render_items();
	$html = (string) ob_get_clean();

	// RAU-R08-001: pytanie ZAWEZONE do formularza publikacji. `k4a_reset( false )` zeruje
	// takze liczbe nieudanych, wiec przycisk „Wznow nieudane" ma wtedy WLASNE `disabled`
	// i szukanie po calym ekranie przechodzilo takze po wycieciu warunku z Admin_Screen.
	$formularz_bez_klucza = k4a_formularz_publikacji( $html );
	k4a_check( '' !== $formularz_bez_klucza, 'formularz publikacji da sie wyciac z ekranu bez klucza' );
	k4a_check( false !== strpos( $formularz_bez_klucza, 'disabled="disabled"' ), 'bez klucza przycisk publikacji jest wylaczony' );
	k4a_check( false !== strpos( $html, 'Klucz API nie jest zapisany' ), 'i klient wie dlaczego' );

	// Licznik na ekranie idzie za stanem puli.
	k4a_reset();
	$GLOBALS['__opt'][ Settings::OPTION_USAGE ] = serialize( array( 'date' => '2026-08-07', 'count' => 14 ) );
	ob_start();
	Admin::render_items();
	$html = (string) ob_get_clean();
	k4a_check( false !== strpos( $html, 'Wywołań AI dziś: 14 z 20' ), 'licznik pokazuje realny stan puli' );

	// ------------------------------------------------------------------
	echo "\n-- Komunikat po przebiegu --\n";

	k4a_reset();
	$GLOBALS['__transient'][ Admin::TRANSIENT_PUB . 3 ] = array(
		'published'  => 2,
		'failed'     => 1,
		'offtopic'   => 3,
		'calls'      => 4,
		'note'       => 'Wyczerpany sufit dobowy wywołań AI',
		'budget_hit' => true,
		'errors'     => array( 7 => 'Coś poszło nie tak' ),
	);

	ob_start();
	Admin::render_items();
	$html = (string) ob_get_clean();

	k4a_check( false !== strpos( $html, 'Opublikowano artykułów: 2' ), 'komunikat podaje liczbe artykulow' );
	k4a_check( false !== strpos( $html, 'Odsianych poza tematem: 3' ), 'i liczbe pozycji odsianych bramka' );
	k4a_check( false !== strpos( $html, 'Wywołań AI w tym przebiegu: 4' ), 'i zuzycie puli' );
	k4a_check( false !== strpos( $html, 'Wyczerpany sufit' ), 'oraz powod wstrzymania' );
	k4a_check( false !== strpos( $html, 'budżet czasu' ), 'i informacje o wyczerpanym budzecie czasu' );
	k4a_check( ! isset( $GLOBALS['__transient'][ Admin::TRANSIENT_PUB . 3 ] ), 'komunikat pokazany RAZ — transient skasowany' );

	// Escapowanie: powod z bazy nie ma prawa wniesc HTML-u.
	k4a_reset();
	$GLOBALS['__transient'][ Admin::TRANSIENT_PUB . 3 ] = array(
		'published'  => 0,
		'failed'     => 1,
		'offtopic'   => 0,
		'calls'      => 1,
		'note'       => '<script>alert(1)</script>',
		'budget_hit' => false,
		'errors'     => array( 1 => '<img src=x onerror=alert(2)>' ),
	);

	ob_start();
	Admin::render_items();
	$html = (string) ob_get_clean();

	k4a_check( false === strpos( $html, '<script>alert(1)</script>' ), 'powod jest escapowany' );
	k4a_check( false === strpos( $html, '<img src=x' ), 'komunikat bledu tez' );
	k4a_check( false !== strpos( $html, '&lt;script&gt;' ), 'i widac go jako tekst, nie jako znacznik' );

	// ------------------------------------------------------------------
	echo "\n-- Etap 8.3: trzy pozostale komunikaty kokpitu --\n";
	// ------------------------------------------------------------------
	/*
	 * Do etapu 8.3 wywolywany byl WYLACZNIE `render_pub_notice` (wyzej).
	 * Trzy pozostale komunikaty — zamek, pobranie i przygotowanie — nie
	 * wykonaly sie ani razu, mimo ze dwa z nich wypisuja tekst pochodzacy
	 * z CUDZEJ strony (bledy pobrania i przygotowania). Mutacja kasujaca
	 * tam `esc_html()` przechodzila przez caly runner.
	 */

	// 1. Zamek: komunikat wychodzi TYLKO przy wlasciwym parametrze adresu.
	k4a_reset();
	$_GET['ainp_status'] = 'zajete';
	ob_start();
	Admin::render_items();
	$html = (string) ob_get_clean();
	k4a_check( false !== strpos( $html, 'Przebieg już trwa' ), 'ainp_status=zajete pokazuje ostrzezenie o trwajacym przebiegu' );
	k4a_check( false !== strpos( $html, 'notice-warning' ), 'i jest to ostrzezenie, nie komunikat sukcesu' );

	k4a_reset();
	$_GET['ainp_status'] = 'cokolwiek';
	ob_start();
	Admin::render_items();
	$html = (string) ob_get_clean();
	k4a_check( false === strpos( $html, 'Przebieg już trwa' ), 'obcy status w adresie NIE wyswietla komunikatu zamka' );
	unset( $_GET['ainp_status'] );

	// 2. Pobranie: liczby z podsumowania i skasowanie transientu po pokazaniu.
	k4a_reset();
	$GLOBALS['__transient'][ Admin::TRANSIENT_RUN . 3 ] = array(
		'sources'    => 4,
		'added'      => 9,
		'skipped'    => 2,
		'duplicates' => 5,
		'invalid'    => 1,
		'failed'     => 0,
	);
	ob_start();
	Admin::render_items();
	$html = (string) ob_get_clean();
	k4a_check( false !== strpos( $html, 'Pobrano z 4 kanałów: 9 nowych' ), 'komunikat pobrania podaje kanaly i nowe pozycje' );
	k4a_check( false !== strpos( $html, '5 duplikatów' ), 'oraz liczbe duplikatow' );
	k4a_check( ! isset( $GLOBALS['__transient'][ Admin::TRANSIENT_RUN . 3 ] ), 'komunikat pobrania pokazany RAZ — transient skasowany' );

	// 3. Przygotowanie: liczby, budzet czasu i ESCAPOWANIE bledu ze scrapowania.
	k4a_reset();
	$GLOBALS['__transient'][ Admin::TRANSIENT_PREP . 3 ] = array(
		'taken'      => 6,
		'ready'      => 4,
		'skipped'    => 1,
		'retry'      => 1,
		'failed'     => 0,
		'budget_hit' => true,
		'errors'     => array( 42 => '<img src=x onerror=alert(9)>' ),
	);
	ob_start();
	Admin::render_items();
	$html = (string) ob_get_clean();
	k4a_check( false !== strpos( $html, 'Przygotowano 6 pozycji: 4 z treścią gotową' ), 'komunikat przygotowania podaje wziete i gotowe' );
	k4a_check( false !== strpos( $html, 'żeby nie przekroczyć limitu czasu serwera' ), 'przy wyczerpanym budzecie dopisuje, ze reszta czeka' );
	k4a_check( false === strpos( $html, '<img src=x' ), 'blad przygotowania — tekst z CUDZEJ strony — jest escapowany' );
	k4a_check( false !== strpos( $html, '&lt;img src=x' ), 'i widac go jako tekst' );
	k4a_check( false !== strpos( $html, '<strong>#42</strong>' ), 'przy bledzie stoi numer pozycji, zeby dalo sie ja znalezc w tabeli' );
	k4a_check( ! isset( $GLOBALS['__transient'][ Admin::TRANSIENT_PREP . 3 ] ), 'komunikat przygotowania pokazany RAZ — transient skasowany' );

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
