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
			preg_match_all( "/'((?:[^'\\\\]|\\\\.)*)'/", $sql, $m );
			$key = $m[1][0] ?? '';
			return array_key_exists( $key, $GLOBALS['__opt'] ) ? (string) $GLOBALS['__opt'][ $key ] : null;
		}

		public function query( $sql ) {
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
	require_once $root . '/src/Dedup.php';
	require_once $root . '/src/Filter.php';
	require_once $root . '/src/Http.php';
	require_once $root . '/src/Feed.php';
	require_once $root . '/src/Article.php';
	require_once $root . '/src/Gemini.php';
	require_once $root . '/src/Validator.php';
	require_once $root . '/src/Publisher.php';
	require_once $root . '/src/Runner.php';
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

	foreach ( array( 'handle_publish', 'handle_save_settings', 'handle_prepare', 'handle_fetch' ) as $akcja ) {
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

	foreach ( array( 'handle_publish', 'handle_save_settings' ) as $akcja ) {
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
	echo "\n-- Zamek: dwuklik w „Opublikuj teraz” --\n";

	k4a_reset();
	$los = k4a_akcja( 'handle_publish' );
	k4a_check( 'redirect' === $los, 'pierwsze klikniecie konczy sie przekierowaniem' );
	k4a_check( ! isset( $GLOBALS['__transient'][ Admin::TRANSIENT_LOCK ] ), 'zamek zdjety po skonczonej partii' );
	k4a_check( isset( $GLOBALS['__transient'][ Admin::TRANSIENT_PUB . 3 ] ), 'podsumowanie zapisane dla tego uzytkownika' );

	// Zamek trzymany przez inne zadanie.
	k4a_reset();
	$GLOBALS['__transient'][ Admin::TRANSIENT_LOCK ] = time();
	$przed_opt                                       = $GLOBALS['__opt'];

	$los = k4a_akcja( 'handle_publish' );

	k4a_check( 'redirect' === $los, 'drugie klikniecie tez konczy sie przekierowaniem' );
	k4a_check( ! isset( $GLOBALS['__transient'][ Admin::TRANSIENT_PUB . 3 ] ), 'ale NIE zapisuje podsumowania — partia sie nie odbyla' );
	k4a_check( $przed_opt === $GLOBALS['__opt'], 'i nie rusza licznika wywolan AI' );
	k4a_check( isset( $GLOBALS['__transient'][ Admin::TRANSIENT_LOCK ] ), 'cudzy zamek zostaje nietkniety' );

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
	k4a_check( false === strpos( $html, 'disabled' ), 'przy zapisanym kluczu przycisk jest aktywny' );
	k4a_check( false === strpos( $html, 'AIzaTESTOWY' ), 'klucz NIE wycieka na ekran Materiałów' );

	// Bez klucza przycisk jest wylaczony i jest o tym slowo.
	k4a_reset( false );
	ob_start();
	Admin::render_items();
	$html = (string) ob_get_clean();

	k4a_check( false !== strpos( $html, 'disabled="disabled"' ), 'bez klucza przycisk publikacji jest wylaczony' );
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
	echo "\n";
	echo '=== Asercji: ' . $ran . ' | bledow: ' . $fail . " ===\n";
	if ( 0 === $fail ) {
		echo "WSZYSTKIE OK\n";
		exit( 0 );
	}
	echo "BŁĘDY\n";
	exit( 1 );
}
