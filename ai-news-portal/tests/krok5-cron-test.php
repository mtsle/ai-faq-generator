<?php
/**
 * Krok 5, etap 5.1 — harmonogram crona i tick.
 *
 * POKRYWA TEST 18 z tabeli planu w polowie dotyczacej cyklu zycia:
 * „po aktywacji `ainp_tick` zaplanowany z powtarzalnoscia `hourly`;
 * po dezaktywacji — ZERO zaplanowanych zdarzen". Druga polowa tego wiersza
 * („po uninstall — zero") jest dowiedziona w `krok1-uninstall-test.php`
 * (test 13): tamta fikstura ma w harmonogramie POWTARZALNY `ainp_tick`
 * i asercja liczy zdarzenia `ainp_*` po odinstalowaniu. Powtarzanie tego tutaj
 * wymagaloby drugiej atrapy calego `$wpdb` i niczego by nie dodalo.
 *
 * Poza tabela planu ten zestaw pilnuje czterech rzeczy, ktore w Kroku 5 sa
 * nowe i latwo je zepsuc po cichu:
 *
 *   1. POWTARZALNOSC, NIE SAMO ISTNIENIE. `wp_next_scheduled()` tylko sprawdza,
 *      czy cokolwiek jest zaplanowane. Kod, ktory na tym poprzestaje, uzna
 *      POJEDYNCZE zdarzenie z etapu 4.6 za harmonogram i wtyczka zostanie
 *      bez cyklu — dziala, a portal milczy.
 *   2. SLUCHACZ. Zaplanowane zdarzenie bez `add_action()` odpala sie i znika
 *      bez sladu; do Kroku 5 tak wlasnie bylo i bylo to swiadome.
 *   3. KOLEJNOSC FAZ w ticku: pobierz → przygotuj → opublikuj. Odwrotna
 *      kolejnosc odsuwa pierwszy artykul o dwie godziny.
 *   4. ZAMEK. Ten sam co w panelu, zdejmowany w `finally`, a CUDZEGO zamka
 *      tick nie rusza.
 *
 * Prawdziwe klasy: `Plugin`, `Runner`, `Admin`, `Settings`, `Filter`, `Http`.
 * Atrapy: funkcje WordPressa, harmonogram, `$wpdb`, transport HTTP.
 *
 * URUCHOMIENIE:  php tests/krok5-cron-test.php
 * Kod wyjscia: 0 = OK, 1 = bledy.
 *
 * @package AI_News_Portal
 */

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
function k5_check( $cond, $label ) {
	global $fail, $ran;
	$ran++;
	echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n";
	if ( ! $cond ) {
		$fail++;
	}
}

// ---------------------------------------------------------------------------
// Atrapy WordPressa.
// ---------------------------------------------------------------------------
define( 'ABSPATH', __DIR__ . '/atrapy/wp/' );

$GLOBALS['__ainp_dbdelta'] = array();
$GLOBALS['__opt']          = array();
$GLOBALS['__transient']    = array();
$GLOBALS['__cron']         = array();   // [ hook => [ [ time, powtor ], ... ] ]
$GLOBALS['__cron_cleared'] = array();
$GLOBALS['__actions']      = array();   // [ [ hook, cb ], ... ]
$GLOBALS['__slad']         = array();   // Kolejnosc faz ticku.
$GLOBALS['__zapytania']    = array();
$GLOBALS['__wysadz_prepare'] = false;   // Wymuszenie wyjatku w fazie „prepare".
$GLOBALS['__posts']        = array();
$GLOBALS['__zadania']      = array();   // Adresy, o ktore poprosila faza zbierania.
$GLOBALS['__http_sleep']   = 0.0;       // Sztuczne spowolnienie transportu.
$GLOBALS['__db_sleep']     = 0.0;       // Sztuczne spowolnienie odzysku.
$GLOBALS['__lock']         = null;      // Zamek jako wiersz w `options` (A5).
$GLOBALS['__timeouty']     = array();   // Timeouty, z jakimi ruszyly zadania (A2).
$GLOBALS['__lock_wyscig']  = null;      // Podmiana zamka miedzy odczytem a CAS-em.
$GLOBALS['__ttl']          = array();   // Zycie zapisanych transientow (A3).
$GLOBALS['__next_post_id'] = 100;

class WP_Post {
	public $ID = 0;
	public function __construct( $id = 0 ) {
		$this->ID = (int) $id;
	}
}

class WP_Error {
	private $code;
	private $message;
	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}
	public function get_error_code() {
		return $this->code;
	}
	public function get_error_message() {
		return $this->message;
	}
}

function is_wp_error( $t ) {
	return $t instanceof WP_Error;
}

/**
 * Atrapa `$wpdb` — pusta tabela i log zapytan.
 *
 * `get_results()` rozpoznaje, KTORA faza ticku pyta, po ksztalcie warunku:
 * przygotowanie tresci szuka pozycji BEZ odcisku (`content_hash IS NULL`),
 * a wybor kandydata dla modelu — pozycji Z odciskiem (`IS NOT NULL`). Na tym
 * stoi asercja o kolejnosci faz: bez niej „trzy fazy" znaczyloby tylko tyle,
 * ze funkcja nie wywalila sie po drodze.
 */

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
function ainp_zamek_wartosci( $sql ) {
	preg_match_all( "/'((?:[^'\\\\]|\\\\.)*)'/", $sql, $m );

	return $m[1];
}

function ainp_zamek_query( $sql ) {
	if ( false === strpos( $sql, 'option_name' ) ) {
		return null;
	}

	$wartosci = ainp_zamek_wartosci( $sql );

	if ( false !== strpos( $sql, 'INSERT IGNORE INTO' ) ) {
		// Kolejnosc wartosci w `VALUES`: nazwa opcji, wartosc, autoload.
		if ( ( $wartosci[0] ?? '' ) !== \AINP\Admin::OPTION_LOCK ) {
			return null;
		}

		if ( null !== $GLOBALS['__lock'] ) {
			return 0;
		}

		$GLOBALS['__lock'] = (string) ( $wartosci[1] ?? time() );

		return 1;
	}

	if ( false !== strpos( $sql, 'DELETE FROM' ) ) {
		/*
		 * Atrapa czyta NAZWE z zapytania zamiast zakladac, ze skoro to `DELETE`
		 * po `option_name`, to na pewno o zamek chodzi. Poprzednia wersja
		 * kasowala zamek przy KAZDYM takim zapytaniu, wiec `release_lock()`
		 * celujacy w cudzy klucz wygladal na poprawny (GOTCHA 9: atrapa, ktora
		 * zaklada wartosc, przechwytuje mutacje na siebie).
		 */
		if ( ( $wartosci[0] ?? '' ) !== \AINP\Admin::OPTION_LOCK ) {
			return 0;
		}

		/*
		 * Zdjecie zamka jest WARUNKOWE: druga wartosc to znacznik wlasciciela.
		 * Atrapa, ktora kasuje wiersz mimo niepasujacego znacznika, przechwytuje
		 * cala naprawe na siebie — asercja swiecilaby na zielono przy kodzie
		 * kasujacym cudzy zamek (GOTCHA 9 i 51).
		 */
		$znacznik = $wartosci[1] ?? null;

		if ( null !== $znacznik && (string) $GLOBALS['__lock'] !== (string) $znacznik ) {
			return 0;
		}

		$GLOBALS['__lock'] = null;

		return 1;
	}

	if ( false !== strpos( $sql, 'SET option_value' ) ) {
		/*
		 * CAS przejmujacy zamek po trupie: `SET option_value = <nowa>
		 * WHERE option_name = <nazwa> AND option_value = <stara>`. Przejecie
		 * udaje sie DOKLADNIE wtedy, gdy zamek nadal ma te wartosc, ktora
		 * proces odczytal — na tym stoi obietnica „przejmie go jeden".
		 */
		$nowa  = $wartosci[0] ?? '';
		$nazwa = $wartosci[1] ?? '';
		$stara = $wartosci[2] ?? '';

		if ( $nazwa !== \AINP\Admin::OPTION_LOCK || (string) $GLOBALS['__lock'] !== (string) $stara ) {
			return 0;
		}

		$GLOBALS['__lock'] = (string) $nowa;

		return 1;
	}

	return null;
}

class AINP_Fake_WPDB {
	public $prefix   = 'wp_';
	public $options  = 'wp_options';
	public $posts    = 'wp_posts';
	public $postmeta = 'wp_postmeta';
	public $last_error = '';

	public function get_charset_collate() {
		return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci';
	}

	/**
	 * Podstawia wartosci, zamiast oddawac zapytanie ze wzorcami.
	 *
	 * Bez podstawienia atrapa zamka nie widzi ANI nazwy opcji, ANI wartosci
	 * porownywanej w CAS-ie — a to jedyne dwie rzeczy, na ktorych stoi
	 * naprawa A5. `%d` zostaje NIECYTOWANE (GOTCHA 19).
	 *
	 * @param string $sql  Zapytanie ze wzorcami.
	 * @param mixed  $args Wartosci.
	 *
	 * @return string
	 */
	public function prepare( $sql, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}

		$sql = str_replace( '%s', "'%s'", $sql );

		foreach ( $args as $arg ) {
			$z   = is_int( $arg ) ? (string) $arg : addslashes( (string) $arg );
			$sql = preg_replace( '/%[sd]/', str_replace( '$', '\\$', $z ), $sql, 1 );
		}

		return $sql;
	}

	public function get_results( $sql ) {
		$GLOBALS['__zapytania'][] = $sql;

		if ( false !== strpos( $sql, 'content_hash IS NULL' ) ) {
			if ( $GLOBALS['__wysadz_prepare'] ) {
				throw new RuntimeException( 'atrapa: baza padla w fazie przygotowania' );
			}
			$GLOBALS['__slad'][] = 'prepare';
		} elseif ( false !== strpos( $sql, 'content_hash IS NOT NULL' ) ) {
			$GLOBALS['__slad'][] = 'publish';
		}

		return array();
	}

	public function get_var( $sql ) {
		if ( false !== strpos( $sql, 'SELECT option_value' ) ) {
			$stara = $GLOBALS['__lock'];

			/*
			 * Wyscig o WYGASLY zamek. `__lock_wyscig` podmienia wartosc zamka
			 * DOKLADNIE miedzy odczytem a CAS-em — czyli w jedynym oknie, dla
			 * ktorego CAS w ogole istnieje. Bez tego okna galaz „przejecie sie
			 * nie udalo" jest nieosiagalna i nie da sie jej asercjonowac.
			 */
			if ( null !== $GLOBALS['__lock_wyscig'] ) {
				$GLOBALS['__lock']        = $GLOBALS['__lock_wyscig'];
				$GLOBALS['__lock_wyscig'] = null;
			}

			return $stara;
		}

		return null;
	}

	public function query( $sql ) {
		$GLOBALS['__zapytania'][] = $sql;

		$zamek = ainp_zamek_query( $sql );

		if ( null !== $zamek ) {
			return $zamek;
		}

		// Odzysk porzuconych pozycji (etap 5.2) — jedyne zapytanie ticku, ktore
		// nie nalezy do zadnej z trzech faz. Musi paść PRZED nimi, bo pozycja
		// `processing` jest niewidoczna dla obu zapytan wybierajacych.
		// Marker bez wzorcow — od kiedy `prepare()` podstawia wartosci, `%s`
		// w zapytaniu juz nie ma.
		if ( false !== strpos( $sql, 'updated_at <' ) ) {
			$GLOBALS['__slad'][] = 'recover';

			// Odzysk jest jedyna czescia ticku BEZ wlasnego timeoutu — na nim
			// da sie uczciwie sprawdzic galaz „faza bez czasu nie startuje".
			if ( $GLOBALS['__db_sleep'] > 0 ) {
				usleep( (int) ( $GLOBALS['__db_sleep'] * 1000000 ) );
			}
		}

		return 0;
	}
}
$GLOBALS['wpdb'] = new AINP_Fake_WPDB();

// --- Opcje i transienty ----------------------------------------------------

function get_option( $k, $default = false ) {
	return array_key_exists( $k, $GLOBALS['__opt'] ) ? $GLOBALS['__opt'][ $k ] : $default;
}
function update_option( $k, $v, $autoload = null ) {
	$GLOBALS['__opt'][ $k ] = $v;
	return true;
}
function add_option( $k, $v, $dep = '', $autoload = 'yes' ) {
	if ( array_key_exists( $k, $GLOBALS['__opt'] ) ) {
		return false;
	}
	$GLOBALS['__opt'][ $k ] = $v;
	return true;
}
function delete_option( $k ) {
	unset( $GLOBALS['__opt'][ $k ] );
	return true;
}
function get_transient( $k ) {
	return array_key_exists( $k, $GLOBALS['__transient'] ) ? $GLOBALS['__transient'][ $k ] : false;
}
function set_transient( $k, $v, $ttl = 0 ) {
	$GLOBALS['__transient'][ $k ] = $v;
	// Zapamietujemy TTL osobno: bez tego zycie sladu po ticku byloby
	// niesprawdzalne, a to ono decyduje, czy „automat milczy od doby"
	// da sie odroznic od „automat wlasnie chodzil".
	$GLOBALS['__ttl'][ $k ] = $ttl;
	return true;
}
function delete_transient( $k ) {
	unset( $GLOBALS['__transient'][ $k ] );
	return true;
}

// --- Harmonogram -----------------------------------------------------------

/*
 * Atrapa harmonogramu — LISTA zdarzen na uchwyt, tak jak w tescie akcji Kroku 4.
 * Atrapa trzymajaca JEDNO zdarzenie na uchwyt nadpisywalaby duplikat i asercja
 * „aktywacja nie dokłada drugiego harmonogramu" bylaby zawsze zielona.
 *
 * `wp_next_scheduled()` i `wp_get_scheduled_event()` oddaja zdarzenie
 * NAJWCZESNIEJSZE, a nie pierwsze wstawione — to nie jest ozdobnik, na tym
 * stoi sekcja 3: pojedyncze zdarzenie „na juz" wyprzedza harmonogram godzinny.
 */
function ainp5_earliest( $hook ) {
	if ( empty( $GLOBALS['__cron'][ $hook ] ) ) {
		return null;
	}
	$lista = $GLOBALS['__cron'][ $hook ];
	usort(
		$lista,
		function ( $a, $b ) {
			return $a['time'] <=> $b['time'];
		}
	);
	return $lista[0];
}

function wp_next_scheduled( $hook, $args = array() ) {
	$zdarzenie = ainp5_earliest( $hook );
	return ( null === $zdarzenie ) ? false : (int) $zdarzenie['time'];
}

function wp_get_scheduled_event( $hook, $args = array(), $timestamp = null ) {
	$zdarzenie = ainp5_earliest( $hook );

	if ( null === $zdarzenie ) {
		return false;
	}

	return (object) array(
		'hook'      => $hook,
		'timestamp' => (int) $zdarzenie['time'],
		'schedule'  => $zdarzenie['powtor'],
		'args'      => array(),
	);
}

function wp_schedule_event( $time, $recurrence, $hook, $args = array() ) {
	$GLOBALS['__cron'][ $hook ][] = array(
		'time'   => (int) $time,
		'powtor' => $recurrence,
	);
	return true;
}

function wp_schedule_single_event( $time, $hook, $args = array() ) {
	$GLOBALS['__cron'][ $hook ][] = array(
		'time'   => (int) $time,
		'powtor' => false,
	);
	return true;
}

function wp_clear_scheduled_hook( $hook, $args = array() ) {
	$GLOBALS['__cron_cleared'][] = $hook;
	unset( $GLOBALS['__cron'][ $hook ] );
	return true;
}

/*
 * Ksztalt WIERNY rdzeniowi: $crons[ znacznik ][ uchwyt ][ md5(serialize(args)) ]
 * = [ 'schedule' => powtarzalnosc|false, 'args' => [] ]. Na tym ksztalcie stoi
 * naprawa K1 — `has_recurring_tick()` skanuje CALA tablice, a nie tylko
 * zdarzenie najblizsze, wiec atrapa oddajaca inny ksztalt maskowalaby regresje.
 */
function _get_cron_array() {
	$crons = array();

	foreach ( $GLOBALS['__cron'] as $hook => $lista ) {
		foreach ( $lista as $z ) {
			$crons[ (int) $z['time'] ][ $hook ][ md5( serialize( array() ) ) ] = array(
				'schedule' => $z['powtor'],
				'args'     => array(),
			);
		}
	}

	ksort( $crons );

	return $crons;
}

// --- Reszta rdzenia --------------------------------------------------------

function add_action( $hook, $cb, $prio = 10, $args = 1 ) {
	$GLOBALS['__actions'][] = array(
		'hook' => $hook,
		'cb'   => $cb,
	);
	return true;
}
// Od etapu 6.1 `boot()` wola `Portal::register()`, ktory podpina filtr
// `template_include`. Filtry zbieramy osobno, zeby asercje o `__actions`
// (sluchacz ticku, domkniecie harmonogramu) liczyly dalej to samo co wczesniej.
function add_filter( $hook, $cb, $prio = 10, $args = 1 ) {
	$GLOBALS['__filters'][] = array(
		'hook' => $hook,
		'cb'   => $cb,
	);
	return true;
}
function register_post_type( $type, $args = array() ) {
	return true;
}
function register_taxonomy( $tax, $object_type, $args = array() ) {
	return true;
}
function unregister_post_type( $type ) {
	return true;
}
function unregister_taxonomy( $tax ) {
	return true;
}
function flush_rewrite_rules( $hard = true ) {
	return true;
}
function get_page_by_path( $path, $output = null, $post_type = 'page' ) {
	return null;
}
function wp_insert_post( $data, $wp_error = false ) {
	$id                        = $GLOBALS['__next_post_id']++;
	$data['ID']                = $id;
	$GLOBALS['__posts'][ $id ] = $data;
	return $id;
}
// Od 2026-08-09 `Plugin::ensure_topics()` liczy odcisk listy kategorii.
function wp_json_encode( $dane, $flagi = 0, $glebokosc = 512 ) {
	return json_encode( $dane, $flagi, $glebokosc );
}
function term_exists( $term, $taxonomy = '', $parent = null ) {
	return null;
}
function wp_insert_term( $term, $taxonomy, $args = array() ) {
	return array(
		'term_id'          => 5,
		'term_taxonomy_id' => 5,
	);
}
function wp_set_object_terms( $object_id, $terms, $taxonomy, $append = false ) {
	return (array) $terms;
}
function get_current_user_id() {
	return 1;
}
function get_users( $args = array() ) {
	return array( 1 );
}
function current_user_can( $cap ) {
	return true;
}
function current_time( $typ, $gmt = 0 ) {
	return ( 'Y-m-d' === $typ ) ? '2026-08-09' : '2026-08-09 12:00:00';
}
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
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
function __( $s, $domain = null ) {
	return $s;
}
function esc_html__( $s, $domain = null ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
}
function esc_html( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
}
function esc_attr( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
}
function esc_url( $s ) {
	return (string) $s;
}
function esc_url_raw( $s ) {
	return (string) $s;
}
function wp_kses_post( $s ) {
	return (string) $s;
}
function sanitize_text_field( $s ) {
	return trim( strip_tags( (string) $s ) );
}
function remove_accents( $s ) {
	return (string) $s;
}
function get_edit_post_link( $id = 0, $context = 'display' ) {
	return '';
}
function admin_url( $path = '' ) {
	return 'https://example.test/wp-admin/' . $path;
}
function wp_encode_emoji( $s ) {
	return (string) $s;
}

/**
 * Atrapa transportu — kazde zadanie sieciowe konczy sie bledem transportu.
 *
 * Tick ma sie NIE ZATRZYMAC na padnietym kanale (zabezpieczenie nienaruszalne
 * numer trzy), a przy okazji daje to fazie „collect" slad w tym samym logu,
 * co dwie pozostale — bez niego kolejnosc faz bylaby nie do sprawdzenia.
 *
 * @param string $url  Adres.
 * @param array  $args Argumenty.
 *
 * @return WP_Error
 */
function wp_safe_remote_get( $url, $args = array() ) {
	$GLOBALS['__slad'][]    = 'collect';
	$GLOBALS['__zadania'][] = $url;

	/*
	 * NAPRAWA A2 — atrapa musi ZAPAMIETAC timeout, z jakim ruszylo zadanie.
	 * Bez tego cala teza naprawy („timeout jest przycinany do pozostalego
	 * budzetu") jest niesprawdzalna: widac tylko, ze zadanie poszlo albo nie
	 * poszlo, a nie z jakim limitem. Mutacja znoszaca przyciecie przechodzila
	 * przez to caly runner.
	 */
	$GLOBALS['__timeouty'][] = isset( $args['timeout'] ) ? (int) $args['timeout'] : null;

	// Spowolnienie na zadanie — inaczej caly tick trwa mniej niz milisekunde
	// i budzet nigdy nie ma szans zadzialac (etap 5.3).
	if ( $GLOBALS['__http_sleep'] > 0 ) {
		usleep( (int) ( $GLOBALS['__http_sleep'] * 1000000 ) );
	}

	return new WP_Error( 'http_request_failed', 'atrapa: brak sieci' );
}
function wp_remote_retrieve_response_code( $r ) {
	return 0;
}
function wp_remote_retrieve_body( $r ) {
	return '';
}

require_once $root . '/src/Settings.php';
require_once $root . '/src/Http.php';
require_once $root . '/src/Feed.php';
require_once $root . '/src/Dedup.php';
require_once $root . '/src/Filter.php';
require_once $root . '/src/Article.php';
require_once $root . '/src/Gemini.php';
require_once $root . '/src/Validator.php';
require_once $root . '/src/Publisher.php';
require_once $root . '/src/Runner.php';
// Od etapu 6.1 `Plugin::boot()` wola `Portal::register()`, a od 7.1
// takze `Security::register()`.
require_once $root . '/src/Portal.php';
require_once $root . '/src/Security.php';
require_once $root . '/src/Plugin.php';
// Trait z ekranami MUSI byc zaladowany przed klasa, ktora go uzywa (etap 8.1).
require_once $root . '/src/Admin_Screen.php';
require_once $root . '/src/Admin.php';

use AINP\Admin;
use AINP\Article;
use AINP\Gemini;
use AINP\Http;
use AINP\Plugin;
use AINP\Runner;
use AINP\Settings;

// Jeden kanal zamiast czterech domyslnych — log faz ma byc czytelny.
update_option( Settings::OPTION_SOURCES, array( 'https://kanal.test/feed/' ) );

// ---------------------------------------------------------------------------
// 1. Aktywacja planuje POWTARZALNY tick.
// ---------------------------------------------------------------------------
echo "\n=== 1. TEST 18 — aktywacja planuje ainp_tick z powtarzalnoscia hourly ===\n";

$przed = time();
Plugin::activate();

$zdarzenia = $GLOBALS['__cron'][ Plugin::CRON_HOOK ] ?? array();

k5_check( 1 === count( $zdarzenia ), 'po aktywacji DOKLADNIE jedno zdarzenie ainp_tick (jest: ' . count( $zdarzenia ) . ')' );
k5_check( 'hourly' === ( $zdarzenia[0]['powtor'] ?? null ), 'zdarzenie jest POWTARZALNE, powtarzalnosc hourly (jest: ' . var_export( $zdarzenia[0]['powtor'] ?? null, true ) . ')' );
k5_check( 'hourly' === Plugin::CRON_RECURRENCE, 'powtarzalnosc bierze sie ze stalej Plugin::CRON_RECURRENCE, nie z literalu w wywolaniu' );
/*
 * Pierwszy przebieg ma byc „od zaraz", nie za godzine: `wp_schedule_event()`
 * planuje PIERWSZE wykonanie na podany znacznik, wiec `time() + HOUR` odsunaloby
 * start portalu o godzine od aktywacji i wygladalo jak wtyczka, ktora nie dziala.
 */
k5_check( ( $zdarzenia[0]['time'] ?? 0 ) >= $przed && ( $zdarzenia[0]['time'] ?? 0 ) <= time(), 'pierwsze wykonanie zaplanowane na „juz", nie za godzine' );

// ---------------------------------------------------------------------------
// 2. Druga aktywacja nie dokłada drugiego harmonogramu.
// ---------------------------------------------------------------------------
echo "\n=== 2. Druga aktywacja — zero duplikatow harmonogramu ===\n";

$czas_pierwszego = $zdarzenia[0]['time'];

Plugin::activate();

$zdarzenia = $GLOBALS['__cron'][ Plugin::CRON_HOOK ] ?? array();

k5_check( 1 === count( $zdarzenia ), 'nadal DOKLADNIE jedno zdarzenie (jest: ' . count( $zdarzenia ) . ')' );
k5_check( $czas_pierwszego === $zdarzenia[0]['time'], 'istniejacy harmonogram NIE zostal przesuniety' );

// ---------------------------------------------------------------------------
// 3. Pojedyncze zdarzenie nie przeslania BRAKU harmonogramu.
// ---------------------------------------------------------------------------
echo "\n=== 3. Pojedyncze zdarzenie z etapu 4.6 nie udaje harmonogramu ===\n";

/*
 * Sedno sekcji: gdyby warunek brzmial „czy cokolwiek jest zaplanowane"
 * (samo `wp_next_scheduled()`), to zdarzenie POJEDYNCZE — zaplanowane po
 * zapisaniu Ustawien — zablokowaloby zalozenie harmonogramu i wtyczka
 * przetworzylaby material dokladnie raz, a potem zamilkla.
 */
$GLOBALS['__cron'] = array();
wp_schedule_single_event( time(), Plugin::CRON_HOOK );

Plugin::activate();

$zdarzenia   = $GLOBALS['__cron'][ Plugin::CRON_HOOK ] ?? array();
$powtarzalne = array_values(
	array_filter(
		$zdarzenia,
		function ( $z ) {
			return 'hourly' === $z['powtor'];
		}
	)
);

k5_check( 2 === count( $zdarzenia ), 'zdarzenia sa dwa: pojedyncze i harmonogram (jest: ' . count( $zdarzenia ) . ')' );
k5_check( 1 === count( $powtarzalne ), 'DOKLADNIE jedno z nich jest powtarzalne' );

// ---------------------------------------------------------------------------
// 3b. K1 — singiel stojacy PRZED harmonogramem nie wywoluje duplikatu.
// ---------------------------------------------------------------------------
echo "\n=== 3b. K1 — zapis Ustawien nie dokłada drugiego harmonogramu ===\n";

/*
 * Scenariusz K1 z audytu 8.10, ODWROTNOSC sekcji 3: harmonogram godzinny JUZ
 * WISI, a zapis Ustawien (schedule_first_run) postawil zdarzenie POJEDYNCZE
 * wczesniejsze niz najblizsze wykonanie harmonogramu — tak jest przez ~5/6
 * kazdej godziny. `wp_get_scheduled_event()` oddaje zdarzenie NAJBLIZSZE,
 * wiec kod pytajacy tylko o nie widzi singla (schedule=false), uznaje ze
 * harmonogramu brak i doklada DRUGI — duplikaty kumuluja sie przy kazdym
 * zapisie Ustawien i mnoza zuzycie dobowej puli AI. Naprawa: skan CALEJ
 * tablicy `_get_cron_array()` za jakimkolwiek powtarzalnym `ainp_tick`.
 */
$GLOBALS['__cron'] = array();
wp_schedule_event( time() + 900, Plugin::CRON_RECURRENCE, Plugin::CRON_HOOK );
wp_schedule_single_event( time(), Plugin::CRON_HOOK );

Plugin::ensure_schedule();

$zdarzenia   = $GLOBALS['__cron'][ Plugin::CRON_HOOK ] ?? array();
$powtarzalne = array_values(
	array_filter(
		$zdarzenia,
		function ( $z ) {
			return 'hourly' === $z['powtor'];
		}
	)
);

k5_check( 2 === count( $zdarzenia ), 'zdarzenia nadal dwa — singiel i JEDEN harmonogram (jest: ' . count( $zdarzenia ) . ')' );
k5_check( 1 === count( $powtarzalne ), 'harmonogram NIE zostal zdublowany przez singla stojacego przed nim (powtarzalnych: ' . count( $powtarzalne ) . ')' );

// ---------------------------------------------------------------------------
// 4. Dezaktywacja — zero zaplanowanych zdarzen.
// ---------------------------------------------------------------------------
echo "\n=== 4. TEST 18 — dezaktywacja czysci harmonogram ===\n";

$GLOBALS['__cron_cleared'] = array();

Plugin::deactivate();

k5_check( array() === ( $GLOBALS['__cron'][ Plugin::CRON_HOOK ] ?? array() ), 'po dezaktywacji ZERO zdarzen ainp_tick' );
k5_check( array( 'ainp_tick' ) === $GLOBALS['__cron_cleared'], 'wyczyszczony dokladnie ten jeden uchwyt, nic wiecej' );

// ---------------------------------------------------------------------------
// 5. Sluchacz uchwytu.
// ---------------------------------------------------------------------------
echo "\n=== 5. Uchwyt ma sluchacza (do Kroku 5 nie mial) ===\n";

$GLOBALS['__actions'] = array();

Plugin::boot();

$sluchacze = array_values(
	array_filter(
		$GLOBALS['__actions'],
		function ( $a ) {
			return Plugin::CRON_HOOK === $a['hook'];
		}
	)
);

k5_check( 1 === count( $sluchacze ), 'boot() podpina DOKLADNIE jednego sluchacza pod ainp_tick (jest: ' . count( $sluchacze ) . ')' );
k5_check( array( Runner::class, 'tick' ) === ( $sluchacze[0]['cb'] ?? null ), 'sluchaczem jest Runner::tick — wykonanie mieszka w Runnerze' );
k5_check( is_callable( $sluchacze[0]['cb'] ?? null ), 'i ten sluchacz naprawde istnieje (zaplanowane zdarzenie bez sluchacza znika bez sladu)' );

// ---------------------------------------------------------------------------
// 5b. NAPRAWA A1 — harmonogram domykany takze poza aktywacja.
// ---------------------------------------------------------------------------
echo "
=== 5b. A1: aktualizacja wtyczki tez dostaje harmonogram ===
";

$domykacze = array_values(
	array_filter(
		$GLOBALS['__actions'],
		function ( $a ) {
			return 'init' === $a['hook'] && array( Plugin::class, 'ensure_schedule' ) === $a['cb'];
		}
	)
);

k5_check( 1 === count( $domykacze ), 'boot() podpina domkniecie harmonogramu pod `init`' );

/*
 * SEDNO USTALENIA A1: tak wyglada instalacja, ktora chodzila na wersji sprzed
 * Kroku 5 i dostala nowe pliki. Wtyczka JEST aktywna, wiec `activate()` sie nie
 * odpali — a bez tego zdarzenia portal nigdy nie ruszy sam. Panel dziala
 * normalnie, wiec nic by tego nie zdradzilo.
 */
$GLOBALS['__cron'] = array();

Plugin::ensure_schedule();

$zdarzenia = $GLOBALS['__cron'][ Plugin::CRON_HOOK ] ?? array();

k5_check( 1 === count( $zdarzenia ), 'instalacja po aktualizacji dostaje harmonogram bez reaktywacji' );
k5_check( 'hourly' === ( $zdarzenia[0]['powtor'] ?? null ), 'i jest to zdarzenie POWTARZALNE' );

$czas_domkniecia = $zdarzenia[0]['time'];

Plugin::ensure_schedule();
Plugin::ensure_schedule();

$zdarzenia = $GLOBALS['__cron'][ Plugin::CRON_HOOK ] ?? array();

k5_check( 1 === count( $zdarzenia ), 'domkniecie jest IDEMPOTENTNE — `init` chodzi przy kazdym zadaniu (jest: ' . count( $zdarzenia ) . ')' );
k5_check( $czas_domkniecia === $zdarzenia[0]['time'], 'i nie przesuwa istniejacego harmonogramu' );

// ---------------------------------------------------------------------------
// 6. Tick — trzy fazy, w tej kolejnosci, pod zamkiem.
// ---------------------------------------------------------------------------
echo "\n=== 6. Tick — pobierz, przygotuj, opublikuj ===\n";

$GLOBALS['__slad']      = array();
$GLOBALS['__transient'] = array();

$wynik = Runner::tick();

k5_check( array( 'recover', 'collect', 'prepare', 'publish' ) === $GLOBALS['__slad'], 'odzysk PRZED fazami, potem pobierz → przygotuj → opublikuj (jest: ' . implode( ' → ', $GLOBALS['__slad'] ) . ')' );
k5_check( 0 === $wynik['recovered'], 'nic nie bylo do odzyskania — licznik zero' );
k5_check( false === $wynik['locked'], 'przebieg ruszyl (locked = false)' );
k5_check( isset( $wynik['collect']['sources'] ) && 1 === (int) $wynik['collect']['sources'], 'podsumowanie fazy zbierania widzi jeden kanal' );
k5_check( isset( $wynik['prepare']['taken'] ), 'podsumowanie fazy przygotowania ma wlasny ksztalt' );
k5_check( isset( $wynik['publish']['published'] ), 'podsumowanie fazy publikacji ma wlasny ksztalt' );
k5_check( array() === $wynik['errors'], 'zaden blad transportu nie zatrzymal przebiegu' );
k5_check( null === $GLOBALS['__lock'], 'zamek ZDJETY po przebiegu' );

// --- NAPRAWA A3: przebieg zostawia slad ------------------------------------
$slad = get_transient( Admin::TRANSIENT_TICK );

k5_check( is_array( $slad ), 'automatyczny przebieg ZOSTAWIA slad w transiencie' );
k5_check( '' !== (string) ( $slad['time'] ?? '' ), 'slad ma znacznik czasu — inaczej nie wiadomo, czy cron w ogole chodzi' );
k5_check( isset( $slad['collect']['sources'] ), 'slad niesie podsumowanie faz, nie samo „bylo OK”' );
/*
 * Slad NIE jest kasowany po odczycie: to nie komunikat o akcji, tylko stan
 * automatu. Skasowany po pierwszym wejsciu na ekran znikalby dokladnie temu,
 * kto zaglada drugi raz, zeby sprawdzic, czy cos sie zmienilo.
 */
k5_check( is_array( get_transient( Admin::TRANSIENT_TICK ) ), 'i przezywa odczyt — to stan, nie jednorazowy komunikat' );
k5_check( 0 === strpos( Admin::TRANSIENT_TICK, 'ainp_' ), 'nazwa zaczyna sie od `ainp_` — inaczej `uninstall.php` jej nie zamiecie' );
/*
 * ZYCIE SLADU to doba, i to nie jest liczba dowolna. Tick chodzi co godzine,
 * wiec slad krotszy niz godzina znika, zanim ktokolwiek zajrzy na ekran,
 * a ekran wraca do komunikatu „automat nie zglosil zadnego przebiegu" — czyli
 * klamie o wtyczce, ktora dziala. Slad starszy niz doba znaczy z kolei, ze cron
 * NIE chodzi, i wlasnie to ma wtedy zobaczyc klient.
 */
k5_check( 86400 === Runner::TICK_LOG_TTL, 'slad po ticku zyje dobe (jest: ' . Runner::TICK_LOG_TTL . ' s)' );
k5_check( Runner::TICK_LOG_TTL === ( $GLOBALS['__ttl'][ Admin::TRANSIENT_TICK ] ?? 0 ), 'i zapis naprawde uzywa tej stalej, a nie wlasnej liczby' );
k5_check( Runner::TICK_LOG_TTL > 3600, 'zycie sladu jest DLUZSZE niz odstep miedzy tickami — inaczej ekran gubilby ostatni przebieg' );

// ---------------------------------------------------------------------------
// 6b. NAPRAWA A5 — zamek rozstrzyga BAZA, nie kod.
// ---------------------------------------------------------------------------
echo "
=== 6b. A5: zamek atomowy ===
";

$GLOBALS['__lock'] = null;

k5_check( true === Admin::claim_lock(), 'pierwszy proces bierze zamek' );
/*
 * SEDNO A5. Poprzednia wersja czytala transient i dopiero potem go zapisywala;
 * dwa procesy, ktore weszly w okno miedzy tymi krokami, dostawaly zgode OBA.
 * Teraz o wynik pyta `INSERT IGNORE` na kluczu UNIQUE kolumny `option_name`,
 * wiec drugi dostaje zero zmienionych wierszy — tak samo, jak przy dedupie
 * pozycji o duplikat pyta baza, nie kod.
 */
k5_check( false === Admin::claim_lock(), 'drugi dostaje ODMOWE, mimo ze pyta w tej samej sekundzie' );

Admin::release_lock();

k5_check( null === $GLOBALS['__lock'], 'zdjecie zamka kasuje wiersz, nie zostawia pustej wartosci' );
k5_check( true === Admin::claim_lock(), 'po zdjeciu zamek znowu da sie wziac' );

Admin::release_lock();

k5_check( 0 === strpos( Admin::OPTION_LOCK, 'ainp_' ), 'nazwa opcji zaczyna sie od `ainp_` — inaczej `uninstall.php` zostawilby ja w bazie' );

/*
 * ZAMEK PO TRUPIE. Proces zabity przez `max_execution_time` nie zdazyl zdjac
 * zamka. Bez przejmowania takiego zamka wtyczka blokowalaby sie sama: przyciski
 * i tick czekalyby na proces, ktorego juz nie ma. Ale przejecie ma dotyczyc
 * WYLACZNIE zamka wygaslego — zywy nalezy do kogos, kto wlasnie pracuje.
 */
$GLOBALS['__lock'] = (string) ( time() - Admin::LOCK_TTL - 10 );

k5_check( true === Admin::claim_lock(), 'zamek starszy niz LOCK_TTL zostaje PRZEJETY — inaczej trup blokuje wtyczke na zawsze' );
k5_check( (int) $GLOBALS['__lock'] >= time() - 2, 'przejety zamek dostaje SWIEZY znacznik czasu, nie zostaje przy starym' );

Admin::release_lock();

$GLOBALS['__lock'] = (string) ( time() - 5 );

k5_check( false === Admin::claim_lock(), 'zamek MLODSZY niz LOCK_TTL nie jest przejmowany — ktos przy nim pracuje' );
k5_check( (string) ( time() - 5 ) === $GLOBALS['__lock'] || ( time() - (int) $GLOBALS['__lock'] ) >= 4, 'i zostaje z wartoscia wlasciciela' );

/*
 * Dwa procesy czekajace na ten sam wygasly zamek. Jeden przejmie, drugi ma
 * dostac odmowe — o tym rozstrzyga warunek `option_value = <stara wartosc>`
 * w CAS-ie, nie kolejnosc wywolan. Tu odgrywamy proces PRZEGRYWAJACY: zamek
 * zmienia wartosc miedzy jego odczytem a jego CAS-em.
 */
$GLOBALS['__lock']        = (string) ( time() - Admin::LOCK_TTL - 10 );
$GLOBALS['__lock_wyscig'] = (string) time();

k5_check( false === Admin::claim_lock(), 'przy dwoch procesach na jednym wygaslym zamku przegrywajacy dostaje ODMOWE, a nie zgode' );

$GLOBALS['__lock_wyscig'] = null;
$GLOBALS['__lock']        = null;

/*
 * ZAMEK ZDEJMUJE SIE TYLKO SWOJ — naprawa etapu 8.1.
 *
 * Scenariusz z pomiaru: proces A bierze zamek i wisi dluzej niz `LOCK_TTL`.
 * Inny proces przejmuje wygasly zamek po trupie (zachowanie ZAMIERZONE, wyzej).
 * A dochodzi w koncu do swojego `finally` i wola `release_lock()` — i przed
 * naprawa kasowal wtedy wiersz NALEZACY DO KOGOS INNEGO. Trzeci przebieg wchodzil
 * w partie, ktora tamten wlasnie mielil: dwa razy ten sam scraping i dwa sloty
 * z dobowej puli 20 na jeden artykul.
 *
 * Asercja jest na ZACHOWANIU, nie na mechanizmie: nie pyta, jak wyglada warunek
 * w zapytaniu, tylko czy cudzy zamek przezyl. Przejecia nie odgrywamy drugim
 * `claim_lock()`, bo znacznik wlasciciela jest polem statycznym i w jednym
 * procesie PHP oba „procesy" dzielilyby to samo pole — podstawiamy wiec do
 * wiersza wartosc cudza, dokladnie taka, jaka zostawilby tam CAS.
 */
k5_check( true === Admin::claim_lock(), 'A bierze zamek' );

$GLOBALS['__lock'] = ( time() + 1 ) . '.znacznik-innego-procesu';

Admin::release_lock();

k5_check( null !== $GLOBALS['__lock'], 'A NIE zdejmuje zamka, ktory w miedzyczasie przejal kto inny' );
k5_check( false === Admin::claim_lock(), 'wiec kolejny przebieg nadal dostaje odmowe — nie ma dwoch partii naraz' );

$GLOBALS['__lock'] = null;

k5_check( true === Admin::claim_lock(), 'swiezy zamek da sie wziac' );

Admin::release_lock();

k5_check( null === $GLOBALS['__lock'], 'a SWOJ zamek zdejmuje sie normalnie' );

$GLOBALS['__lock'] = null;

// ---------------------------------------------------------------------------
// 7. Zajety zamek — tick nie wchodzi w cudza partie.
// ---------------------------------------------------------------------------
echo "\n=== 7. Zamek panelu wstrzymuje tick ===\n";

$GLOBALS['__slad'] = array();
$GLOBALS['__lock'] = (string) time();   // ktos inny wlasnie pracuje

$wynik = Runner::tick();

k5_check( true === $wynik['locked'], 'tick zglasza, ze przebieg trwa gdzie indziej' );
k5_check( array() === $GLOBALS['__slad'], 'ZERO faz wykonanych (jest: ' . count( $GLOBALS['__slad'] ) . ')' );
/*
 * Cudzy zamek ma przezyc odbicie sie ticku. Gdyby tick zdjal go w `finally`
 * mimo tego, ze go nie wzial, klient klikajacy „Opublikuj teraz" traciłby
 * ochrone dokladnie w chwili, gdy cron probuje wejsc — czyli wtedy, kiedy
 * jest ona potrzebna.
 */
k5_check( null !== $GLOBALS['__lock'], 'CUDZY zamek nietkniety' );

$GLOBALS['__lock'] = null;

// ---------------------------------------------------------------------------
// 8. Padnieta faza nie zabiera dwoch pozostalych ani nie zostawia zamka.
// ---------------------------------------------------------------------------
echo "\n=== 8. Wyjatek w fazie: reszta przebiegu idzie dalej ===\n";

$GLOBALS['__slad']           = array();
$GLOBALS['__wysadz_prepare'] = true;

$wynik = Runner::tick();

$GLOBALS['__wysadz_prepare'] = false;

k5_check( isset( $wynik['errors']['prepare'] ), 'blad fazy zapisany pod jej nazwa' );
k5_check( array( 'recover', 'collect', 'publish' ) === $GLOBALS['__slad'], 'pozostale dwie fazy wykonane mimo wyjatku (jest: ' . implode( ' → ', $GLOBALS['__slad'] ) . ')' );
k5_check( null === $GLOBALS['__lock'], 'zamek zdjety w finally, mimo wyjatku w srodku' );

// ---------------------------------------------------------------------------
// 9. Budzet czasu ticku (etap 5.3).
// ---------------------------------------------------------------------------
echo "
=== 9. Budzet czasu: dzialki faz i faza pominieta ===
";

// 25, nie 20 — audyt 8.10: zmierzone 20,58 s na poprawnej odpowiedzi
// (wariancja thoughts) przepalalo slot przy starym budzecie.
k5_check( 25 === Runner::TICK_BUDGET, 'zalozony budzet ticku to 25 sekund' );

$stary_limit = ini_get( 'max_execution_time' );

ini_set( 'max_execution_time', '0' );
k5_check( 25.0 === Runner::tick_budget(), 'bez limitu wykonania obowiazuje samo zalozenie' );

/*
 * Hosting z limitem 10 s: tick liczacy na 20 zginalby przed oddaniem pozycji,
 * i to co godzine. Bierzemy 80% limitu — ta sama proporcja, co przy pamieci.
 */
ini_set( 'max_execution_time', '10' );
/*
 * ZMIANA KONTRAKTU (audyt przebieg-2, RAU-R13-001). Ta asercja wymagala do tej
 * pory 8,0 s — czyli DOKLADNIE tyle, ile wychodzilo `publish_budget()` przy tym
 * samym limicie. Utrwalala wiec zrownanie obu budzetow, ktore TECH-73 opisuje
 * jako niedopuszczalne. Przyciecie do 80% limitu obowiazuje teraz budzet
 * publikacji, a budzet ticku jest z niego wyprowadzany w proporcji stalych:
 * 8,0 * 25/30 = 6,67 s. Publikacja nadal dostaje 8,0 — asercja nizej.
 */
k5_check( abs( Runner::tick_budget() - 6.666666 ) < 0.001, 'limit 10 s: budzet ticku to 6,67 s (jest: ' . round( Runner::tick_budget(), 3 ) . ')' );

/*
 * ETAP 8.7 — publikacja z PANELU ma wlasny budzet.
 *
 * Do 8.7 `publish_batch()` bez argumentu bralo `PREPARE_BUDGET`, czyli stala
 * od INNEJ fazy: przycisk „Opublikuj teraz" dawal modelowi 15 s, a cron
 * ~19,99 s. Reczna publikacja miala wiec MNIEJSZE szanse niz automatyczna,
 * odwrotnie do tego, czego spodziewa sie czlowiek. Zmierzone przy okazji:
 * model potrafi liczyc 32-36 s na pelnej tresci, a nawet po sufitcie 8.7
 * odpowiedz waha sie 9,7-20,6 s (rozne `thoughtsTokenCount`), wiec zapas ma
 * znaczenie.
 */
k5_check( 30 === Runner::PUBLISH_BUDGET, 'zalozony budzet publikacji z panelu to 30 s — tyle, ile TIMEOUT_MAX modelu' );
k5_check( Runner::PUBLISH_BUDGET > Runner::PREPARE_BUDGET, 'i jest WIEKSZY niz budzet przygotowania, ktory tu obowiazywal przez pomylke' );
k5_check( 8.0 === Runner::publish_budget(), 'ten sam hosting z limitem 10 s przycina publikacje do 8 s (80%)' );

ini_set( 'max_execution_time', '0' );
k5_check( 30.0 === Runner::publish_budget(), 'bez limitu wykonania publikacja dostaje pelne 30 s' );
k5_check( Runner::publish_budget() !== Runner::tick_budget(), 'budzet panelu i budzet ticku to DWIE rozne liczby, nie jedna stala' );

/*
 * Asercje na samej stalej i na `publish_budget()` NIE WYSTARCZA — sprawdzone
 * mutacja: przywrocenie `PREPARE_BUDGET` w `publish_batch()` przeszlo przez
 * nie bez jednej czerwonej linii. Dowodem jest dopiero to, ktora liczba
 * wychodzi z PRZEBIEGU wolanego tak, jak wola go przycisk panelu.
 */
$pod_panelu = Runner::publish_batch( 1 );
k5_check( Runner::publish_budget() === (float) $pod_panelu['budget'], 'publish_batch() BEZ argumentu bierze budzet panelu (jest: ' . $pod_panelu['budget'] . ')' );
k5_check( (float) Runner::PREPARE_BUDGET !== (float) $pod_panelu['budget'], 'i na pewno nie budzet przygotowania' );

/*
 * STRAZNIK RAU-R13-001 + UZUP-04. Nierownosc TICK < PUBLISH jest mechanizmem
 * (TECH-73), a nie ozdoba: zadanie panelu, przy ktorym czeka czlowiek, ma dostawac
 * wiecej czasu niz przebieg w tle. Przed naprawa kazdy budzet szedl przez wlasne
 * przyciecie do 0,8 * max_execution_time, wiec na KAZDYM hostingu z limitem do 31 s
 * wychodzily rowne co do sekundy — takze przy 30 s, ktore docblock TICK_BUDGET
 * sam nazywa typowym. Jedyna asercja nierownosci stala przy limicie 0, czyli
 * dokladnie tam, gdzie przyciecie nie zachodzi i wada nie wystepuje.
 */
echo "
-- Nierownosc budzetow na WSZYSTKICH limitach hostingu (RAU-R13-001) --
";

foreach ( array( 10, 15, 20, 30, 31, 40 ) as $k5_limit ) {
	ini_set( 'max_execution_time', (string) $k5_limit );

	$k5_tick    = Runner::tick_budget();
	$k5_publish = Runner::publish_budget();

	k5_check( $k5_tick < $k5_publish, "limit {$k5_limit} s: budzet ticku < budzet publikacji (jest: " . round( $k5_tick, 2 ) . ' < ' . round( $k5_publish, 2 ) . ')' );
	k5_check( $k5_publish <= $k5_limit * 0.8 + 0.0001, "limit {$k5_limit} s: publikacja miesci sie w 80% limitu hostingu" );
	k5_check( $k5_tick <= $k5_limit * 0.8 + 0.0001, "limit {$k5_limit} s: tick miesci sie w 80% limitu hostingu" );
}

ini_set( 'max_execution_time', '0' );

/*
 * STRAZNIK RAU-R13-003. Dzialka fazy liczona od budzetu JUZ przycietego spychala
 * publikacje pod prog wejscia modelu: przy limicie 1-19 s zadanie do modelu nie
 * wychodzilo ANI RAZU, mimo pelnej kolejki. Rachunek wyjety z `tick()` do czystej
 * decyzji, zeby dalo sie go sprawdzic wprost.
 */
echo "
-- Dzialka fazy chroni prog wejscia modelu (RAU-R13-003) --
";

foreach ( array( 10.0, 12.0, 15.0, 20.0, 24.0, 25.0 ) as $k5_budzet ) {
	$k5_dzialka = Runner::phase_share( $k5_budzet );
	$k5_dla_pub = $k5_budzet - 2 * $k5_dzialka;

	k5_check( $k5_dla_pub >= Gemini::TIMEOUT_MIN - 0.0001, "budzet {$k5_budzet} s: publikacji zostaje co najmniej TIMEOUT_MIN (jest: " . round( $k5_dla_pub, 2 ) . ' s)' );
}

k5_check( 6.25 === Runner::phase_share( 25.0 ), 'bez ograniczenia hostingu dzialka to nadal cwiartka budzetu (brak regresji)' );
k5_check( 1.0 === Runner::phase_share( 9.0 ), 'budzet ledwie nad progiem: dzialka spada do podlogi 1 s, zeby zbieranie nie zgaslo' );
k5_check( 0.25 === Runner::phase_share( 1.0 ), 'budzet PONIZEJ progu: podloga NIE podnosi dzialki (kontrakt sprzed naprawy zachowany)' );

/*
 * I kontrola zachowaniowa: przy budzecie ponizej progu faza publikacji jest
 * POMIJANA, a nie odpalana „na chwile" z zerowym timeoutem.
 */
$GLOBALS['__zadania']    = array();
$GLOBALS['__http_sleep'] = 0.0;

$k5_maly = Runner::tick( 5.0 );
k5_check( in_array( 'publish', $k5_maly['skipped'], true ), 'budzet 5 s (ponizej TIMEOUT_MIN): faza publikacji POMINIETA (skipped: ' . implode( ',', $k5_maly['skipped'] ) . ')' );

$pod_ticku = Runner::publish_batch( 1, 7.5 );
k5_check( 7.5 === (float) $pod_ticku['budget'], 'budzet podany jawnie (tak robi tick) obowiazuje bez zmian' );

ini_set( 'max_execution_time', (string) $stary_limit );

/*
 * Budzet ponizej sekundy nie jest budzetem — jest zaproszeniem do przebiegu,
 * ktory nic nie zdazy, a mimo to zalozy i zdejmie zamek. Podloga 1 s pilnuje,
 * ze dzialka fazy zostaje wielkoscia, o ktorej da sie cokolwiek powiedziec.
 */
$GLOBALS['__transient']  = array();
$GLOBALS['__zadania']    = array();
$GLOBALS['__http_sleep'] = 0.0;

$wynik = Runner::tick( 0.2 );

k5_check( 1.0 === $wynik['budget'], 'budzet ponizej sekundy podnoszony do 1 s (jest: ' . var_export( $wynik['budget'], true ) . ')' );

// --- Budzet za maly na UCZCIWA probe: zadanie nie startuje wcale -----------
/*
 * NAPRAWA A2. Do audytu budzet byl sprawdzany dopiero MIEDZY kanalami, wiec
 * pierwszy kanal wchodzil zawsze i mogl isc az do wlasnego timeoutu (10 s).
 * Teraz timeout zadania jest przycinany do pozostalego budzetu, a ponizej
 * `Http::MIN_SECONDS` zadanie nie rusza w ogole — bo porazka po dwoch sekundach
 * nie jest wina serwisu, a mimo to podbijalaby licznik prob.
 */
update_option(
	Settings::OPTION_SOURCES,
	array( 'https://a.test/feed/', 'https://b.test/feed/', 'https://c.test/feed/' )
);

$GLOBALS['__zadania']    = array();
$GLOBALS['__transient']  = array();
$GLOBALS['__http_sleep'] = 0.4;

$wynik = Runner::tick( 1.0 );

k5_check( 0 === count( $GLOBALS['__zadania'] ), 'przy budzecie 0,25 s faza zbierania NIE wysyla zadania (jest: ' . count( $GLOBALS['__zadania'] ) . ')' );
k5_check( true === ( $wynik['collect']['budget_hit'] ?? false ), 'i zglasza brak budzetu, a nie awarie kanalu' );
k5_check( array() === ( $wynik['collect']['errors'] ?? array() ), 'kanal, ktorego nie zapytano, NIE trafia na liste bledow' );

// --- Dzialka ogranicza liczbe kanalow w jednym przebiegu -------------------
$GLOBALS['__zadania']    = array();
$GLOBALS['__transient']  = array();
$GLOBALS['__http_sleep'] = 4.0;

$wynik = Runner::tick( 20.0 );

/*
 * Dzialka zbierania to 5 s. Pierwszy kanal miesci sie w progu (timeout przyciety
 * do 5 s), po nim zostaje okolo sekundy — mniej niz `Http::MIN_SECONDS`, wiec
 * drugi kanal nie startuje. Asercja jest na LICZBIE zadan, nie na czasie.
 */
k5_check( 1 === count( $GLOBALS['__zadania'] ), 'dzialka przepuszcza jeden kanal, drugiego juz nie (zadan: ' . count( $GLOBALS['__zadania'] ) . ')' );
k5_check( true === ( $wynik['collect']['budget_hit'] ?? false ), 'faza zbierania zglasza wyczerpanie dzialki' );
k5_check( 20.0 === $wynik['budget'], 'tick zapamietal budzet, z ktorym pracowal' );

// --- Faza, na ktora nie starczylo czasu, NIE jest odpalana -----------------
$GLOBALS['__slad']       = array();
$GLOBALS['__zadania']    = array();
$GLOBALS['__transient']  = array();
$GLOBALS['__http_sleep'] = 0.0;
$GLOBALS['__db_sleep']   = 1.2;

$wynik = Runner::tick( 1.0 );

$GLOBALS['__db_sleep'] = 0.0;

k5_check( array( 'collect', 'prepare', 'publish' ) === $wynik['skipped'], 'zjedzony budzet pomija WSZYSTKIE trzy fazy, nie odpala ich „na chwile" (jest: ' . implode( ', ', $wynik['skipped'] ) . ')' );
k5_check( array( 'recover' ) === $GLOBALS['__slad'], 'i zadna z nich nie ruszyla ani bazy, ani sieci' );
k5_check( null === $GLOBALS['__lock'], 'zamek zdjety mimo wyczerpanego budzetu' );

// --- Podzial budzetu miedzy fazy -------------------------------------------
$GLOBALS['__zadania']    = array();
$GLOBALS['__transient']  = array();
$GLOBALS['__http_sleep'] = 0.0;

update_option( Settings::OPTION_SOURCES, array( 'https://a.test/feed/' ) );

$wynik   = Runner::tick( 20.0 );
$dzialka = 20.0 * Runner::TICK_SHARE;

/*
 * Podzial jest asymetryczny CELOWO i to jest jedyne miejsce, ktore tego pilnuje.
 * Przy rownym podziale faza publikacji dostawalaby okolo szesciu sekund,
 * a `Gemini` nie startuje ponizej osmiu — portal zbieralby material bez konca
 * i nie opublikowal ani jednego artykulu. Zadna asercja o liczbie wywolan tego
 * nie zlapie, bo model w tescie i tak nie jest wolany.
 */
k5_check( $wynik['prepare']['budget'] <= $dzialka + 0.01, 'przygotowanie dostaje NAJWYZEJ dzialke (jest: ' . round( $wynik['prepare']['budget'], 2 ) . ' z ' . $dzialka . ')' );
k5_check( $wynik['publish']['budget'] > $dzialka, 'publikacja dostaje CALA reszte budzetu, nie dzialke (jest: ' . round( $wynik['publish']['budget'], 2 ) . ')' );
k5_check( $wynik['publish']['budget'] >= 8.0, 'i nie mniej, niz `Gemini` potrzebuje, zeby w ogole wystartowac' );

// ---------------------------------------------------------------------------
// 10. NAPRAWA A2 — timeout zadania PRZYCIETY do pozostalego budzetu.
// ---------------------------------------------------------------------------
echo "\n=== 10. A2: Http::timeout_for — przyciecie, nie samo zerowanie ===\n";

/*
 * Do audytu budzet byl sprawdzany MIEDZY pozycjami, a pozycja w trakcie szla
 * az do wlasnego timeoutu. Zmierzone: tick z budzetem 20 s trwal 30,02 s.
 * Sedno naprawy nie polega na tym, ze przy pustym budzecie zadanie nie rusza —
 * to jest tylko skrajny przypadek. Polega na tym, ze zadanie DOSTAJE tyle
 * czasu, ile go zostalo. Ta roznica przechodzila caly runner niezauwazona.
 */
k5_check( 3 === Http::MIN_SECONDS, 'prog „nie zaczynaj zadania" to 3 s, nie tyle co dzialka fazy' );
k5_check( 10 === Http::timeout_for( 10, null ), 'bez budzetu obowiazuje timeout wlasciwy dla rodzaju zadania' );
k5_check( 0 === Http::timeout_for( 10, 2.9 ), 'ponizej progu zadanie nie startuje wcale (zwrot 0)' );
k5_check( 3 === Http::timeout_for( 10, 3.0 ), 'dokladnie na progu jeszcze startuje — prog jest granica dolna, nie wykluczajaca' );
k5_check( 4 === Http::timeout_for( 10, 4.7 ), 'timeout przyciety W DOL do pelnych sekund budzetu (jest: ' . Http::timeout_for( 10, 4.7 ) . ')' );
k5_check( 10 === Http::timeout_for( 10, 30.0 ), 'i NIGDY nie rosnie ponad timeout wlasciwy dla zadania' );

// ---------------------------------------------------------------------------
// 11. NAPRAWA A2 — `robots.txt` tez kosztuje budzet.
// ---------------------------------------------------------------------------
echo "\n=== 11. A2: robots.txt pod budzetem ===\n";

$GLOBALS['__transient']  = array();
$GLOBALS['__zadania']    = array();
$GLOBALS['__timeouty']   = array();
$GLOBALS['__http_sleep'] = 0.0;

$odpowiedz = Http::get_article( 'https://budzet.test/artykul/', 2.0 );

k5_check( false === $odpowiedz['ok'], 'przy budzecie ponizej progu pobranie strony nie dochodzi do skutku' );
/*
 * Powod musi brzmiec `budget`, a NIE `robots`. Bramka budzetu stoi PRZED
 * pytaniem o `robots.txt` wlasnie dlatego: „robots" jest powodem TRWALYM
 * i odeslaloby zdrowa pozycje na `failed` za brak czasu, a nie za cudzy zakaz.
 */
k5_check( 'budget' === $odpowiedz['reason'], 'i powodem jest brak budzetu, nie rzekomy zakaz w robots.txt (jest: ' . $odpowiedz['reason'] . ')' );
k5_check( 0 === count( $GLOBALS['__zadania'] ), 'zadne zadanie nie poszlo — ani po strone, ani po robots.txt' );

/*
 * GOTCHA: brak budzetu na `robots.txt` NIE moze znaczyc „wolno pobierac".
 * To byloby obchodzenie cudzego zakazu zegarkiem. Zwracamy „nie pobieraj"
 * i — co wazniejsze — NIE zapisujemy tego werdyktu do cache'u, zeby nastepny
 * przebieg zapytal uczciwie, zamiast przez dobe pamietac wymuszona odmowe.
 */
$GLOBALS['__transient'] = array();
$GLOBALS['__zadania']   = array();

k5_check( false === Http::allowed( 'https://robots.test/artykul/', 1.0 ), 'bez budzetu na robots.txt odpowiedz brzmi „nie pobieraj", nie „wolno"' );
k5_check( 0 === count( $GLOBALS['__zadania'] ), 'i nie kosztuje to zadania sieciowego' );

k5_check( true === Http::allowed( 'https://robots.test/artykul/', null ), 'nastepny przebieg z budzetem pyta normalnie' );
k5_check( 1 === count( $GLOBALS['__zadania'] ), 'wymuszona odmowa NIE trafila do cache’u — host zostal zapytany (zadan: ' . count( $GLOBALS['__zadania'] ) . ')' );

// --- Koszt robots.txt odejmowany od budzetu strony -------------------------
/*
 * Pierwszy kontakt z hostem kosztuje DWA zadania: `robots.txt`, potem strone.
 * Bez odjecia czasu pierwszego od budzetu drugiego strona dostawalaby budzet,
 * ktorego czesc juz nie istnieje — i tick znowu przekraczalby wlasny limit.
 * Asercja jest ZAKRESEM, nie rownoscia: mierzymy czas rzeczywisty (GOTCHA 21).
 */
$GLOBALS['__transient']  = array();
$GLOBALS['__zadania']    = array();
$GLOBALS['__timeouty']   = array();
$GLOBALS['__http_sleep'] = 2.0;

Http::get_article( 'https://koszt.test/artykul/', 12.0 );

$GLOBALS['__http_sleep'] = 0.0;

k5_check( 2 === count( $GLOBALS['__timeouty'] ), 'pierwszy kontakt z hostem to dwa zadania: robots.txt i strona (jest: ' . count( $GLOBALS['__timeouty'] ) . ')' );
k5_check( Http::ROBOTS_TIMEOUT === $GLOBALS['__timeouty'][0], 'robots.txt dostaje swoj wlasny, krotszy timeout' );
k5_check(
	isset( $GLOBALS['__timeouty'][1] ) && $GLOBALS['__timeouty'][1] < 12 && $GLOBALS['__timeouty'][1] >= 8,
	'a strona dostaje budzet POMNIEJSZONY o czas robots.txt, nie pelne 12 s (jest: ' . var_export( $GLOBALS['__timeouty'][1] ?? null, true ) . ')'
);

// ---------------------------------------------------------------------------
// 12. NAPRAWA A2 — budzet dochodzi az do transportu.
// ---------------------------------------------------------------------------
echo "\n=== 12. A2: Article::fetch niesie budzet dalej ===\n";

/*
 * Bramka pamieci z etapu 3.5 odsyla pozycje na `retry`, ZANIM scraping ruszy,
 * wiec bez podniesienia limitu ten fragment mierzylby cos innego, niz deklaruje.
 */
$stara_pamiec = ini_get( 'memory_limit' );
ini_set( 'memory_limit', '-1' );

$GLOBALS['__transient'] = array();
$GLOBALS['__zadania']   = array();

$pobrane = Article::fetch( 'https://przekaz.test/artykul/', 2.0 );

ini_set( 'memory_limit', (string) $stara_pamiec );

k5_check( false === $pobrane['ok'], 'budzet ponizej progu zatrzymuje pobranie juz na poziomie Article' );
k5_check( 'budget' === ( $pobrane['reason'] ?? '' ), 'powod przechodzi w gore niezmieniony (jest: ' . var_export( $pobrane['reason'] ?? null, true ) . ')' );
k5_check( 0 === count( $GLOBALS['__zadania'] ), 'i ZERO zadan sieciowych — budzet nie zgubil sie miedzy Article a Http' );

// ---------------------------------------------------------------------------
// Podsumowanie.
// ---------------------------------------------------------------------------
echo "\n--------------------------------------------------\n";
echo 'Asercji: ' . $ran . ' | bledow: ' . $fail . "\n";

if ( 0 === $fail ) {
	echo "WSZYSTKIE ASERCJE OK\n";
	exit( 0 );
}

echo "BŁĘDY: " . $fail . "\n";
exit( 1 );
