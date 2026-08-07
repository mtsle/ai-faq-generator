<?php
/**
 * Krok 4, etap 4.2 — panel: klucz API i prompt w Ustawieniach.
 *
 * Dwa pola, ktore dochodza w tym etapie, maja po jednej wlasnosci psujacej sie
 * po cichu:
 *
 *   KLUCZ API. Formularz NIGDY nie pokazuje go z powrotem, wiec puste pole musi
 *   znaczyc „nie zmieniam". Gdyby znaczylo „usun", to kazdy zapis Ustawien —
 *   zmiana jednego slowa wykluczajacego — kasowalby klucz i wtyczka przestawalaby
 *   dzialac bez jednego bledu w logu. Kasowanie jest wiec jawne, osobnym polem
 *   wyboru. Do tego klucz siedzi w opcji BEZ autoladowania: `update_option()`
 *   na istniejacej opcji autoladowania nie zmienia, dlatego przy zmianie klucza
 *   opcja jest kasowana i zakladana od nowa.
 *
 *   PROMPT. Idzie przez `sanitize_textarea_field()`, a nie przez `wp_kses_post()`:
 *   to instrukcja dla modelu, a nie tresc do wyswietlenia. Nazwy dozwolonych
 *   znacznikow HTML sa w niej trescia i kses wycialby je razem ze znaczeniem.
 *   Puste pole zapisuje sie jako puste i znaczy „wroc do domyslnego" — tego
 *   pilnuje juz `Gemini::prompt()`.
 *
 * Prawdziwe klasy: `Admin`, `Settings`, `Gemini`.
 * Atrapy: funkcje WordPressa, `$wpdb`, `Plugin`, `Runner`, `Http`.
 *
 * URUCHOMIENIE:  php tests/krok4-ustawienia-test.php
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
	function k4u_check( $cond, $label ) {
		global $fail, $ran;
		$ran++;
		echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n";
		if ( ! $cond ) {
			$fail++;
		}
	}

	/** Przerwanie zamiast przekierowania. */
	class AINP_Redirect extends \Exception {}

	$GLOBALS['__opt']      = array();
	$GLOBALS['__autoload'] = array();

	function current_user_can( $cap ) {
		return true;
	}

	function check_admin_referer( $action, $pole = '_wpnonce' ) {
		return true;
	}

	function wp_die( $tekst = '' ) {
		throw new \Exception( 'die' );
	}

	function wp_safe_redirect( $url ) {
		throw new AINP_Redirect( (string) $url );
	}

	function admin_url( $sciezka = '' ) {
		return 'https://example.test/wp-admin/' . $sciezka;
	}

	function add_query_arg( $args, $url = '' ) {
		return $url . '?' . http_build_query( (array) $args );
	}

	function get_option( $k, $default = false ) {
		return array_key_exists( $k, $GLOBALS['__opt'] ) ? $GLOBALS['__opt'][ $k ] : $default;
	}

	function update_option( $k, $v, $autoload = null ) {
		$GLOBALS['__opt'][ $k ] = $v;
		return true;
	}

	/**
	 * Atrapa `add_option()` — zapamietuje TAKZE flage autoladowania.
	 *
	 * Bez zapamietania tej flagi test nie odroznilby klucza w `alloptions`
	 * (ladowanego przy kazdym zadaniu, takze na stronie publicznej) od klucza
	 * lezacego spokojnie w tabeli.
	 *
	 * @param string $k        Nazwa opcji.
	 * @param mixed  $v        Wartosc.
	 * @param string $depr     Nieuzywane.
	 * @param bool   $autoload Flaga autoladowania.
	 *
	 * @return bool
	 */
	function add_option( $k, $v = '', $depr = '', $autoload = true ) {
		if ( array_key_exists( $k, $GLOBALS['__opt'] ) ) {
			return false;
		}
		$GLOBALS['__opt'][ $k ]      = $v;
		$GLOBALS['__autoload'][ $k ] = $autoload;
		return true;
	}

	function delete_option( $k ) {
		unset( $GLOBALS['__opt'][ $k ], $GLOBALS['__autoload'][ $k ] );
		return true;
	}

	function set_transient( $k, $v, $ttl = 0 ) {
		return true;
	}

	function get_transient( $k ) {
		return false;
	}

	function delete_transient( $k ) {
		return true;
	}

	function wp_unslash( $v ) {
		return $v;
	}

	function sanitize_text_field( $v ) {
		return trim( strip_tags( (string) $v ) );
	}

	/**
	 * Atrapa `sanitize_textarea_field()` — jak w WordPressie ZOSTAWIA nowe linie.
	 *
	 * @param string $v Wejscie.
	 *
	 * @return string
	 */
	function sanitize_textarea_field( $v ) {
		return trim( strip_tags( (string) $v ) );
	}

	function wp_kses_post( $v ) {
		return (string) $v;
	}

	function esc_url_raw( $url ) {
		return trim( (string) $url );
	}

	function esc_url( $url ) {
		return trim( (string) $url );
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

	function wp_nonce_field( $action, $pole = '_wpnonce', $referer = true, $echo = true ) {
		echo '<input type="hidden" name="' . htmlspecialchars( (string) $pole ) . '" value="nonce" />';
	}

	function submit_button( $tekst = '', $typ = 'primary', $nazwa = 'submit', $wrap = true ) {
		echo '<button type="submit">' . htmlspecialchars( (string) $tekst ) . '</button>';
	}

	function checked( $a, $b = true, $echo = true ) {
		return ( $a === $b ) ? 'checked="checked"' : '';
	}

	function selected( $a, $b = true, $echo = true ) {
		return ( $a === $b ) ? 'selected="selected"' : '';
	}

	function add_action( $hook, $cb, $prio = 10, $args = 1 ) {
		return true;
	}

	function add_menu_page() {
		return '';
	}

	function add_submenu_page() {
		return '';
	}

	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}

	function current_time( $typ, $gmt = 0 ) {
		return ( 'Y-m-d' === $typ ) ? '2026-08-07' : '2026-08-07 12:00:00';
	}

	function remove_accents( $t ) {
		return (string) $t;
	}

	function number_format_i18n( $n, $d = 0 ) {
		return (string) $n;
	}

	function wp_encode_emoji( $t ) {
		return $t;
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

	/** Atrapa bazy — na tym ekranie i tak nic z niej nie czytamy. */
	class AINP_Fake_WPDB_K4U {

		public $prefix     = 'wp_';
		public $last_error = '';

		public function prepare( $sql, ...$args ) {
			return $sql;
		}

		public function get_results( $sql ) {
			return array();
		}

		public function get_var( $sql ) {
			return '';
		}

		public function query( $sql ) {
			return 0;
		}
	}

	$GLOBALS['wpdb'] = new AINP_Fake_WPDB_K4U();
}

namespace AINP {

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
	require_once $root . '/src/Http.php';
	require_once $root . '/src/Feed.php';
	require_once $root . '/src/Article.php';
	require_once $root . '/src/Gemini.php';
	require_once $root . '/src/Runner.php';
	require_once $root . '/src/Admin.php';

	use AINP\Admin;
	use AINP\Gemini;
	use AINP\Settings;

	/**
	 * Uruchamia zapis Ustawien, przechwytujac przekierowanie.
	 *
	 * @return bool Czy zapis doszedl do konca.
	 */
	function k4u_zapisz() {
		try {
			Admin::handle_save_settings();
		} catch ( AINP_Redirect $e ) {
			return true;
		}
		return false;
	}

	/**
	 * Stan poczatkowy.
	 *
	 * @return void
	 */
	function k4u_reset() {
		$GLOBALS['__opt']      = array();
		$GLOBALS['__autoload'] = array();
		$_POST                 = array(
			'ainp_sources'    => 'https://psy.pl/feed/',
			'ainp_model'      => 'gemini-2.5-flash',
			'ainp_daily_cap'  => '20',
			'ainp_categories' => "Żywienie\nZdrowie",
		);
	}

	echo "=== KROK 4, ETAP 4.2: panel — klucz API i prompt ===\n\n";

	// ------------------------------------------------------------------
	echo "-- Klucz API: zapis --\n";

	k4u_reset();
	$_POST['ainp_key'] = '  AIzaKLUCZ_TESTOWY  ';
	k4u_check( k4u_zapisz(), 'zapis konczy sie przekierowaniem' );
	k4u_check( 'AIzaKLUCZ_TESTOWY' === get_option( Settings::OPTION_KEY, '' ), 'klucz zapisany bez otaczajacych spacji' );
	k4u_check( false === $GLOBALS['__autoload'][ Settings::OPTION_KEY ], 'klucz zalozony z autoload = no' );
	k4u_check( ! array_key_exists( 'ainp_key', Settings::all() ), 'klucz NIE trafia do wspolnej tablicy ustawien' );

	// ------------------------------------------------------------------
	echo "\n-- Klucz API: puste pole NIE kasuje zapisanego klucza --\n";

	$_POST['ainp_key']          = '';
	$_POST['ainp_excluded_words'] = 'kot, kotka';
	k4u_zapisz();
	k4u_check( 'AIzaKLUCZ_TESTOWY' === get_option( Settings::OPTION_KEY, '' ), 'zwykly zapis Ustawien zostawia klucz nietkniety' );

	unset( $_POST['ainp_key'] );
	k4u_zapisz();
	k4u_check( 'AIzaKLUCZ_TESTOWY' === get_option( Settings::OPTION_KEY, '' ), 'brak pola w formularzu tez nie kasuje klucza' );

	// ------------------------------------------------------------------
	echo "\n-- Klucz API: podmiana i jawne kasowanie --\n";

	$_POST['ainp_key'] = 'AIzaNOWY_KLUCZ';
	k4u_zapisz();
	k4u_check( 'AIzaNOWY_KLUCZ' === get_option( Settings::OPTION_KEY, '' ), 'nowa wartosc podmienia klucz' );
	k4u_check( false === $GLOBALS['__autoload'][ Settings::OPTION_KEY ], 'po podmianie klucz NADAL jest bez autoladowania' );

	unset( $_POST['ainp_key'] );
	$_POST['ainp_key_clear'] = '1';
	k4u_zapisz();
	k4u_check( '' === (string) get_option( Settings::OPTION_KEY, '' ), 'zaznaczone pole wyboru kasuje klucz' );
	k4u_check( ! array_key_exists( Settings::OPTION_KEY, $GLOBALS['__opt'] ), 'opcja znika z bazy, nie zostaje pusta' );

	// Kasowanie ma pierwszenstwo takze wtedy, gdy w polu cos wpisano.
	$_POST['ainp_key']       = 'AIzaJESZCZE_INNY';
	$_POST['ainp_key_clear'] = '1';
	k4u_zapisz();
	k4u_check( '' === (string) get_option( Settings::OPTION_KEY, '' ), 'zaznaczone kasowanie wygrywa z wpisana wartoscia' );
	unset( $_POST['ainp_key_clear'], $_POST['ainp_key'] );

	// ------------------------------------------------------------------
	echo "\n-- Klucz API nigdy nie wraca do formularza --\n";

	k4u_reset();
	$GLOBALS['__opt'][ Settings::OPTION_KEY ] = 'AIzaSEKRET_W_BAZIE';

	ob_start();
	Admin::render_settings();
	$html = (string) ob_get_clean();

	k4u_check( false === strpos( $html, 'AIzaSEKRET_W_BAZIE' ), 'zapisany klucz NIE pojawia sie w HTML-u formularza' );
	k4u_check( false !== strpos( $html, 'name="ainp_key"' ), 'pole na klucz jest w formularzu' );
	k4u_check( false !== strpos( $html, 'type="password"' ), 'pole na klucz jest typu password' );
	k4u_check( false !== strpos( $html, 'name="ainp_key_clear"' ), 'przy zapisanym kluczu jest pole wyboru „usuń”' );

	unset( $GLOBALS['__opt'][ Settings::OPTION_KEY ] );
	ob_start();
	Admin::render_settings();
	$html_bez = (string) ob_get_clean();
	k4u_check( false === strpos( $html_bez, 'name="ainp_key_clear"' ), 'bez zapisanego klucza nie ma czego kasowac' );

	// ------------------------------------------------------------------
	echo "\n-- Prompt: zapis z formularza --\n";

	k4u_reset();
	$_POST['ainp_prompt'] = "Napisz artykuł.\nKategorie: {kategorie}\n{tresc}";
	k4u_zapisz();
	$zapisany = (string) Settings::get( 'prompt', '' );

	k4u_check( false !== strpos( $zapisany, '{kategorie}' ), 'znaczniki przezywaja zapis' );
	k4u_check( false !== strpos( $zapisany, "\n" ), 'nowe linie przezywaja zapis — to nie jest pole jednoliniowe' );
	k4u_check( 0 === strpos( $zapisany, 'Napisz artykuł.' ), 'tresc promptu zapisana bez zmian' );

	$prompt = Gemini::prompt(
		array(
			'title'   => 'Tytuł',
			'url'     => 'https://psy.pl/a/',
			'content' => 'Materiał.',
		),
		array( 'Rasy' )
	);
	k4u_check( 0 === strpos( $prompt, 'Napisz artykuł.' ), 'model dostaje szablon klienta, nie domyslny' );

	// ------------------------------------------------------------------
	echo "\n-- Prompt: puste pole wraca do domyslnego --\n";

	$_POST['ainp_prompt'] = '   ';
	k4u_zapisz();
	k4u_check( '' === (string) Settings::get( 'prompt', 'x' ), 'puste pole zapisuje sie jako puste' );

	$prompt = Gemini::prompt(
		array(
			'title'   => 'Tytuł',
			'url'     => 'https://psy.pl/a/',
			'content' => 'Materiał.',
		),
		array( 'Rasy' )
	);
	k4u_check( false !== strpos( $prompt, 'redaktorem' ), 'model mimo to dostaje prompt domyslny' );
	k4u_check( false !== strpos( $prompt, 'Rasy' ), 'i podstawione kategorie' );

	unset( $_POST['ainp_prompt'] );
	k4u_zapisz();
	k4u_check( Settings::defaults()['prompt'] === Settings::get( 'prompt', '' ), 'brak pola w formularzu zapisuje prompt domyslny' );

	// ------------------------------------------------------------------
	echo "\n-- Prompt w formularzu --\n";

	k4u_reset();
	update_option( Settings::OPTION, array( 'prompt' => 'SZABLON <strong>KLIENTA</strong> {tresc}' ) );

	ob_start();
	Admin::render_settings();
	$html = (string) ob_get_clean();

	k4u_check( false !== strpos( $html, 'name="ainp_prompt"' ), 'pole na prompt jest w formularzu' );
	k4u_check( false !== strpos( $html, 'SZABLON' ), 'formularz pokazuje zapisany prompt' );
	k4u_check( false === strpos( $html, '<strong>KLIENTA</strong>' ), 'zawartosc pola jest escapowana, nie wstrzykuje HTML-u' );
	k4u_check( false !== strpos( $html, '{kategorie}, {tytul}, {zrodlo}, {tresc}' ), 'opis wymienia wszystkie cztery znaczniki' );

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
