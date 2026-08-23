<?php
/**
 * Tryb demo — ograniczenia publicznej wystawy.
 *
 * Demo stoi pod OTWARTYM kokpitem i darmowym kluczem Gemini z pula 20 wywolan
 * na dobe. Kazde z ponizszych zabezpieczen psuje sie po cichu, wiec kazde ma
 * tu wlasna asercje:
 *
 *   1. KLUCZ API JEST NIENARUSZALNY. Ani zapis, ani podmiana, ani jawne
 *      kasowanie polem wyboru. Blokada siedzi w `Admin::save_key()`, a NIE
 *      w formularzu: ukryte pole zatrzymuje przegladarke, ale nie recznie
 *      zlozony POST. Dlatego test wysyla POST-a wprost, z pominieciem HTML-u.
 *   2. MODEL I SUFIT DOBOWY SA NIENARUSZALNE. Podniesiony sufit albo model
 *      spoza darmowej puli zuzylyby cudzy klucz rownie skutecznie, co podmiana.
 *   3. ODSTEP NA ADRES IP. Odpowiedz „wolno" REZERWUJE odstep — bez rezerwacji
 *      limit bylby pytaniem bez skutku.
 *   4. DOBOWY LIMIT PUBLIKACJI NA ADRES IP, niezalezny od wspolnego `daily_cap`,
 *      zeby jeden gosc nie zjadl puli wszystkim.
 *   5. POZA TRYBEM DEMO WSZYSTKO DZIALA JAK DOTAD. To jest polowa tego pliku:
 *      wtyczka u klienta nie moze zauwazyc, ze klasa `Demo` w ogole istnieje.
 *
 * MECHANIZM TEGO PLIKU: `AINP_DEMO` jest STALA, wiec jednego procesu nie da sie
 * przelaczyc z powrotem. Kolejnosc jest wiec czescia konstrukcji — najpierw
 * komplet asercji BEZ trybu demo, potem `define()` w polowie pliku i komplet
 * asercji Z trybem demo. Odwrocenie kolejnosci uniemozliwiloby polowe testu.
 *
 * Prawdziwe klasy: `Demo`, `Admin`, `Settings`, `Gemini`, `Runner`.
 * Atrapy: funkcje WordPressa, `$wpdb`, `Plugin`.
 *
 * URUCHOMIENIE:  php tests/demo-tryb-test.php
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
	function dm_check( $cond, $label ) {
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

	$GLOBALS['__opt']       = array();
	$GLOBALS['__transient'] = array();
	$GLOBALS['__cron']      = array();
	$GLOBALS['__cap']       = true;
	$GLOBALS['__nonce_ok']  = true;
	$GLOBALS['__lock']      = null;

	// Adres IP goscia. Tryb demo liczy limity po nim, wiec test go przestawia.
	$_SERVER['REMOTE_ADDR'] = '198.51.100.7';

	function current_user_can( $cap ) {
		return (bool) $GLOBALS['__cap'];
	}

	function get_current_user_id() {
		return 3;
	}

	function check_admin_referer( $action, $pole = '_wpnonce' ) {
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

	function wp_next_scheduled( $hook, $args = array() ) {
		if ( empty( $GLOBALS['__cron'][ $hook ] ) ) {
			return false;
		}
		return (int) $GLOBALS['__cron'][ $hook ][0]['time'];
	}

	function wp_schedule_single_event( $time, $hook, $args = array() ) {
		$GLOBALS['__cron'][ $hook ][] = array( 'time' => $time );
		return true;
	}

	function wp_schedule_event( $time, $recurrence, $hook, $args = array() ) {
		$GLOBALS['__cron'][ $hook ][] = array( 'time' => $time );
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
		return ( 'Y-m-d' === $t ) ? '2026-08-23' : '2026-08-23 12:00:00';
	}

	function remove_accents( $t ) {
		return (string) $t;
	}

	function number_format_i18n( $n, $d = 0 ) {
		return (string) $n;
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

	function wp_strip_all_tags( $t, $b = false ) {
		return trim( strip_tags( (string) $t ) );
	}

	function wp_kses_post( $t ) {
		return (string) $t;
	}

	function wp_encode_emoji( $t ) {
		return $t;
	}

	function get_posts( $args = array() ) {
		return array();
	}

	/** Zamek przebiegu — jak w zestawie akcji Kroku 4 (naprawa A5). */
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
			return 0;
		}

		return null;
	}

	/** Atrapa bazy: tabela pozycji pusta, opcje dzialaja. */
	class AINP_Fake_WPDB_DM {

		public $options    = 'wp_options';
		public $prefix     = 'wp_';
		public $last_error = '';

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
			return array();
		}

		public function get_var( $sql ) {
			if ( false !== strpos( $sql, 'SELECT option_value' ) ) {
				return $GLOBALS['__lock'];
			}

			if ( false !== strpos( $sql, 'COUNT(*)' ) ) {
				return '0';
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

			return 0;
		}
	}

	$GLOBALS['wpdb'] = new AINP_Fake_WPDB_DM();
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
	use AINP\Demo;
	use AINP\Settings;

	/**
	 * Uruchamia zapis Ustawien i oddaje parametr `ainp_status` z przekierowania.
	 *
	 * @return string
	 */
	function dm_zapisz() {
		try {
			Admin::handle_save_settings();
		} catch ( AINP_Redirect $e ) {
			return dm_status( $e->getMessage() );
		}
		return '';
	}

	/**
	 * Uruchamia akcje panelu i oddaje parametr `ainp_status` z przekierowania.
	 *
	 * @param string $metoda Nazwa metody `Admin`.
	 *
	 * @return string Pusty ciag znaczy „nie doszlo do przekierowania".
	 */
	function dm_akcja( $metoda ) {
		try {
			Admin::$metoda();
		} catch ( AINP_Redirect $e ) {
			return dm_status( $e->getMessage() );
		}
		return '';
	}

	/**
	 * Wyluskuje `ainp_status` z adresu przekierowania.
	 *
	 * @param string $url Adres.
	 *
	 * @return string
	 */
	function dm_status( $url ) {
		$pytajnik = strpos( $url, '?' );

		if ( false === $pytajnik ) {
			return '';
		}

		parse_str( substr( $url, $pytajnik + 1 ), $args );

		return isset( $args['ainp_status'] ) ? (string) $args['ainp_status'] : '';
	}

	/**
	 * Kasuje transienty odstepu, zostawiajac liczniki dobowe.
	 *
	 * Odwzorowuje UPLYW CZASU: odstep wygasa sam po `*_ODSTEP` sekundach,
	 * a licznik dobowy zyje dobe. Test celowo NIE zna prywatnej nazwy
	 * transientu — dopasowuje po nazwie akcji, wiec zmiana prefiksu w klasie
	 * nie unieruchomi tego zestawu po cichu.
	 *
	 * @param string $akcja Nazwa akcji.
	 *
	 * @return void
	 */
	function dm_minal_odstep( $akcja ) {
		foreach ( array_keys( $GLOBALS['__transient'] ) as $klucz ) {
			if ( false !== strpos( (string) $klucz, $akcja . '_' ) && false === strpos( (string) $klucz, 'pubcnt' ) ) {
				unset( $GLOBALS['__transient'][ $klucz ] );
			}
		}
	}

	/**
	 * Stan poczatkowy formularza Ustawien.
	 *
	 * @return void
	 */
	function dm_reset_post() {
		$_POST = array(
			'ainp_sources'    => '',
			'ainp_model'      => 'gemini-2.5-flash',
			'ainp_daily_cap'  => '20',
			'ainp_categories' => "Żywienie\nZdrowie",
		);
	}

	// Pusta lista zrodel: „Pobierz teraz" nie ma czego pobierac, wiec zaden
	// test nie wychodzi do sieci. Limitow trybu demo to nie dotyka — stoja
	// PRZED praca.
	update_option( Settings::OPTION_SOURCES, array() );

	echo "=== TRYB DEMO: ograniczenia publicznej wystawy ===\n\n";

	// ===================================================================
	echo "-- BEZ trybu demo: wtyczka nie zauwaza, ze klasa Demo istnieje --\n";
	// ===================================================================

	dm_check( false === Demo::active(), 'Demo::active() falszywe, gdy stalej nie ma' );

	dm_check( Demo::allow( 'fetch', 600 ), 'pierwszy przebieg dozwolony' );
	dm_check( Demo::allow( 'fetch', 600 ), 'drugi przebieg TEZ dozwolony — bez demo nie ma odstepu' );
	dm_check( array() === $GLOBALS['__transient'], 'bez demo nie powstaje ZADEN transient limitu' );

	$wolno = 0;
	for ( $i = 0; $i < 6; $i++ ) {
		if ( Demo::allow_publish() ) {
			$wolno++;
		}
	}
	dm_check( 6 === $wolno, 'bez demo publikacja nie ma limitu dobowego (6 z 6)' );

	dm_reset_post();
	$_POST['ainp_key'] = 'AIzaKLUCZ_KLIENTA';
	dm_check( 'zapisano' === dm_zapisz(), 'zapis Ustawien konczy sie przekierowaniem' );
	dm_check( 'AIzaKLUCZ_KLIENTA' === get_option( Settings::OPTION_KEY, '' ), 'klient ZAPISUJE klucz' );

	$_POST['ainp_key'] = 'AIzaINNY_KLUCZ';
	dm_zapisz();
	dm_check( 'AIzaINNY_KLUCZ' === get_option( Settings::OPTION_KEY, '' ), 'klient PODMIENIA klucz' );

	unset( $_POST['ainp_key'] );
	$_POST['ainp_key_clear'] = '1';
	dm_zapisz();
	dm_check( '' === (string) get_option( Settings::OPTION_KEY, '' ), 'klient KASUJE klucz polem wyboru' );
	unset( $_POST['ainp_key_clear'] );

	dm_reset_post();
	$_POST['ainp_model']     = 'gemini-3-pro';
	$_POST['ainp_daily_cap'] = '77';
	$latka                   = Admin::sanitize_settings( $_POST );
	dm_check( 'gemini-3-pro' === $latka['model'], 'klient zmienia model' );
	dm_check( 77 === $latka['daily_cap'], 'klient zmienia sufit dobowy' );

	dm_reset_post();
	update_option( Settings::OPTION_KEY, 'AIzaSEKRET' );
	ob_start();
	Admin::render_settings();
	$html_bez_demo = (string) ob_get_clean();

	dm_check( false !== strpos( $html_bez_demo, 'name="ainp_key"' ), 'formularz klienta MA pole na klucz' );
	dm_check( false !== strpos( $html_bez_demo, 'name="ainp_key_clear"' ), 'formularz klienta ma pole „usuń klucz”' );
	dm_check( false === strpos( $html_bez_demo, 'disabled="disabled"' ), 'zadne pole Ustawien nie jest zablokowane' );

	ob_start();
	Admin::render_items();
	$items_bez_demo = (string) ob_get_clean();
	dm_check( false === strpos( $items_bez_demo, 'demonstracyjna' ), 'ekran Materialow nie mowi o demo' );

	// ===================================================================
	// PUNKT ZWROTNY. Od tej linii instalacja jest wystawa i nie ma odwrotu:
	// stalej nie da sie odlaczyc, wiec kolejnosc w tym pliku jest czescia
	// konstrukcji testu, nie porzadkiem opowiadania.
	// ===================================================================
	define( 'AINP_DEMO', true );

	$GLOBALS['__transient'] = array();

	echo "\n-- Z trybem demo: klucz API jest nienaruszalny --\n";

	dm_check( true === Demo::active(), 'Demo::active() prawdziwe po zdefiniowaniu stalej' );

	update_option( Settings::OPTION_KEY, 'AIzaKLUCZ_WYSTAWY' );

	dm_reset_post();
	$_POST['ainp_key'] = 'AIzaKLUCZ_GOSCIA';
	dm_check( 'zapisano' === dm_zapisz(), 'zapis Ustawien nadal dziala — blokada nie wywala formularza' );
	dm_check( 'AIzaKLUCZ_WYSTAWY' === get_option( Settings::OPTION_KEY, '' ), 'POST z nowym kluczem NIE podmienia klucza wystawy' );

	unset( $_POST['ainp_key'] );
	$_POST['ainp_key_clear'] = '1';
	dm_zapisz();
	dm_check( 'AIzaKLUCZ_WYSTAWY' === get_option( Settings::OPTION_KEY, '' ), 'recznie zlozony POST z „usuń klucz” TEZ nie kasuje' );
	unset( $_POST['ainp_key_clear'] );

	delete_option( Settings::OPTION_KEY );
	dm_reset_post();
	$_POST['ainp_key'] = 'AIzaPROBA_ZALOZENIA';
	dm_zapisz();
	dm_check( '' === (string) get_option( Settings::OPTION_KEY, '' ), 'na pustym miejscu klucza tez nie da sie zalozyc' );
	update_option( Settings::OPTION_KEY, 'AIzaKLUCZ_WYSTAWY' );

	echo "\n-- Z trybem demo: model i sufit dobowy nienaruszalne --\n";

	dm_reset_post();
	$_POST['ainp_model']     = 'gemini-3-pro';
	$_POST['ainp_daily_cap'] = '900';
	$latka                   = Admin::sanitize_settings( $_POST );

	dm_check( ! array_key_exists( 'model', $latka ), 'model wypada z latki, wiec Settings::update() go nie rusza' );
	dm_check( ! array_key_exists( 'daily_cap', $latka ), 'sufit dobowy wypada z latki' );
	dm_check( array_key_exists( 'categories', $latka ), 'reszta ustawien NADAL sie zapisuje — demo nie zamraza calego panelu' );

	update_option( Settings::OPTION, array( 'model' => 'gemini-2.5-flash', 'daily_cap' => 20 ) );
	dm_zapisz();
	dm_check( 'gemini-2.5-flash' === (string) Settings::get( 'model', '' ), 'po zapisie model wystawy jest bez zmian' );
	dm_check( 20 === (int) Settings::get( 'daily_cap', 0 ), 'po zapisie sufit wystawy jest bez zmian' );

	echo "\n-- Z trybem demo: odstep na adres IP --\n";

	$GLOBALS['__transient'] = array();

	dm_check( Demo::allow( 'fetch', 600 ), 'pierwszy przebieg z danego IP dozwolony' );
	dm_check( ! Demo::allow( 'fetch', 600 ), 'drugi przebieg z tego samego IP ODRZUCONY' );
	dm_check( ! Demo::allow( 'fetch', 600 ), 'i trzeci — odmowa nie zuzywa sie po jednym pytaniu' );

	$_SERVER['REMOTE_ADDR'] = '203.0.113.55';
	dm_check( Demo::allow( 'fetch', 600 ), 'INNY adres IP ma wlasny odstep' );
	$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
	dm_check( ! Demo::allow( 'fetch', 600 ), 'pierwszy adres nadal odrzucany — liczniki sa rozlaczne' );

	dm_minal_odstep( 'fetch' );
	dm_check( Demo::allow( 'fetch', 600 ), 'po wygasnieciu odstepu przebieg znow dozwolony' );

	dm_check( Demo::allow( 'prepare', 600 ), 'odstep „przygotuj” jest NIEZALEZNY od „pobierz”' );
	dm_check( ! Demo::allow( 'prepare', 600 ), 'i sam tez blokuje powtorke' );

	echo "\n-- Z trybem demo: dobowy limit publikacji na adres IP --\n";

	$GLOBALS['__transient'] = array();

	$wolno = 0;
	for ( $i = 0; $i < Demo::PUBLISH_NA_DOBE + 2; $i++ ) {
		if ( Demo::allow_publish() ) {
			$wolno++;
		}
		dm_minal_odstep( 'publish' );
	}
	dm_check( Demo::PUBLISH_NA_DOBE === $wolno, 'dobowy limit publikacji trzyma MIMO wygasajacych odstepow' );

	$_SERVER['REMOTE_ADDR'] = '203.0.113.55';
	dm_check( Demo::allow_publish(), 'inny gosc ma wlasna pule publikacji' );
	$_SERVER['REMOTE_ADDR'] = '198.51.100.7';

	/*
	 * Odmowa z wyczerpanego limitu dobowego NIE ma rezerwowac odstepu — inaczej
	 * kazde bezskuteczne klikniecie przedluzaloby blokade o kolejne minuty.
	 * Miara jest liczba transientow: gdyby odmowa cos zapisywala, przybylby
	 * wpis odstepu.
	 */
	dm_minal_odstep( 'publish' );
	$przed = count( $GLOBALS['__transient'] );
	dm_check( ! Demo::allow_publish(), 'po wyczerpaniu limitu publikacja odrzucona' );
	dm_check( $przed === count( $GLOBALS['__transient'] ), 'odmowa z limitu NIE rezerwuje odstepu' );

	echo "\n-- Z trybem demo: akcje panelu --\n";

	$GLOBALS['__transient'] = array();

	dm_check( 'pobrano' === dm_akcja( 'handle_fetch' ), 'pierwsze „Pobierz teraz” przechodzi' );
	dm_check( 'demo' === dm_akcja( 'handle_fetch' ), 'drugie wraca ze statusem „demo”' );

	dm_check( 'przygotowano' === dm_akcja( 'handle_prepare' ), 'pierwsze „Przygotuj treści” przechodzi' );
	dm_check( 'demo' === dm_akcja( 'handle_prepare' ), 'drugie wraca ze statusem „demo”' );

	dm_check( 'opublikowano' === dm_akcja( 'handle_publish' ), 'pierwsze „Opublikuj teraz” przechodzi' );
	dm_check( 'demo' === dm_akcja( 'handle_publish' ), 'drugie wraca ze statusem „demo”' );

	dm_check( null === $GLOBALS['__lock'], 'zamek przebiegu zdjety — odmowa demo nie zostawia go wzietego' );

	/*
	 * Limit stoi PO `guard()`. Zadanie bez nonce'a ma odpasc na uprawnieniu,
	 * a nie zjadac komus odstepu: inaczej wystarczyloby zasypac panel POST-ami
	 * bez nonce'a, zeby zablokowac przyciski wszystkim gosciom z tego IP.
	 */
	$GLOBALS['__transient'] = array();
	$GLOBALS['__nonce_ok']  = false;
	$zmarl                  = false;
	try {
		Admin::handle_fetch();
	} catch ( AINP_Died $e ) {
		$zmarl = true;
	} catch ( AINP_Redirect $e ) {
		$zmarl = false;
	}
	$GLOBALS['__nonce_ok'] = true;

	dm_check( $zmarl, 'zadanie bez nonce\'a odpada na guard()' );
	dm_check( array() === $GLOBALS['__transient'], 'i NIE rezerwuje odstepu trybu demo' );

	echo "\n-- Z trybem demo: panel --\n";

	dm_reset_post();
	ob_start();
	Admin::render_settings();
	$html_demo = (string) ob_get_clean();

	dm_check( false === strpos( $html_demo, 'name="ainp_key"' ), 'pole na klucz ZNIKA z formularza' );
	dm_check( false === strpos( $html_demo, 'name="ainp_key_clear"' ), 'pole „usuń klucz” tez znika' );
	dm_check( false !== strpos( $html_demo, 'demonstracyjna' ), 'formularz mowi, czemu klucza nie da sie zmienic' );
	dm_check( false === strpos( $html_demo, 'AIzaKLUCZ_WYSTAWY' ), 'klucz wystawy NIE wycieka do HTML-u' );

	preg_match( '/<input[^>]*name="ainp_model"[^>]*>/', $html_demo, $m_model );
	preg_match( '/<input[^>]*name="ainp_daily_cap"[^>]*>/', $html_demo, $m_sufit );

	dm_check( ! empty( $m_model ) && false !== strpos( $m_model[0], 'disabled' ), 'pole modelu jest zablokowane' );
	dm_check( ! empty( $m_sufit ) && false !== strpos( $m_sufit[0], 'disabled' ), 'pole sufitu dobowego jest zablokowane' );

	preg_match( '/<textarea[^>]*name="ainp_categories"[^>]*>/', $html_demo, $m_kat );
	dm_check( ! empty( $m_kat ) && false === strpos( $m_kat[0], 'disabled' ), 'pola, ktore wolno ruszac, zostaja aktywne' );

	ob_start();
	Admin::render_items();
	$items_demo = (string) ob_get_clean();
	dm_check( false !== strpos( $items_demo, 'demonstracyjna' ), 'ekran Materialow mowi, ze to wystawa' );

	$_GET['ainp_status'] = 'demo';
	ob_start();
	Admin::render_items();
	$items_odmowa = (string) ob_get_clean();
	unset( $_GET['ainp_status'] );
	dm_check( false !== strpos( $items_odmowa, 'Limit trybu demo' ), 'odmowa z limitu ma wlasny komunikat, nie cisze' );

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
