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
class AINP_Fake_WPDB {
	public $prefix   = 'wp_';
	public $posts    = 'wp_posts';
	public $postmeta = 'wp_postmeta';
	public $last_error = '';

	public function get_charset_collate() {
		return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci';
	}

	public function prepare( $sql, ...$args ) {
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
		return null;
	}

	public function query( $sql ) {
		$GLOBALS['__zapytania'][] = $sql;

		// Odzysk porzuconych pozycji (etap 5.2) — jedyne zapytanie ticku, ktore
		// nie nalezy do zadnej z trzech faz. Musi paść PRZED nimi, bo pozycja
		// `processing` jest niewidoczna dla obu zapytan wybierajacych.
		if ( false !== strpos( $sql, 'WHERE status = %s AND updated_at < %s' ) ) {
			$GLOBALS['__slad'][] = 'recover';
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

// --- Reszta rdzenia --------------------------------------------------------

function add_action( $hook, $cb, $prio = 10, $args = 1 ) {
	$GLOBALS['__actions'][] = array(
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
require_once $root . '/src/Plugin.php';
require_once $root . '/src/Admin.php';

use AINP\Admin;
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
k5_check( false === get_transient( Admin::TRANSIENT_LOCK ), 'zamek ZDJETY po przebiegu' );

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

// ---------------------------------------------------------------------------
// 7. Zajety zamek — tick nie wchodzi w cudza partie.
// ---------------------------------------------------------------------------
echo "\n=== 7. Zamek panelu wstrzymuje tick ===\n";

$GLOBALS['__slad'] = array();
set_transient( Admin::TRANSIENT_LOCK, 1234, Admin::LOCK_TTL );

$wynik = Runner::tick();

k5_check( true === $wynik['locked'], 'tick zglasza, ze przebieg trwa gdzie indziej' );
k5_check( array() === $GLOBALS['__slad'], 'ZERO faz wykonanych (jest: ' . count( $GLOBALS['__slad'] ) . ')' );
/*
 * Cudzy zamek ma przezyc odbicie sie ticku. Gdyby tick zdjal go w `finally`
 * mimo tego, ze go nie wzial, klient klikajacy „Opublikuj teraz" traciłby
 * ochrone dokladnie w chwili, gdy cron probuje wejsc — czyli wtedy, kiedy
 * jest ona potrzebna.
 */
k5_check( 1234 === get_transient( Admin::TRANSIENT_LOCK ), 'CUDZY zamek nietkniety' );

delete_transient( Admin::TRANSIENT_LOCK );

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
k5_check( false === get_transient( Admin::TRANSIENT_LOCK ), 'zamek zdjety w finally, mimo wyjatku w srodku' );

// ---------------------------------------------------------------------------
// 9. Budzet czasu ticku (etap 5.3).
// ---------------------------------------------------------------------------
echo "
=== 9. Budzet czasu: dzialki faz i faza pominieta ===
";

k5_check( 20 === Runner::TICK_BUDGET, 'zalozony budzet ticku to 20 sekund' );

$stary_limit = ini_get( 'max_execution_time' );

ini_set( 'max_execution_time', '0' );
k5_check( 20.0 === Runner::tick_budget(), 'bez limitu wykonania obowiazuje samo zalozenie' );

/*
 * Hosting z limitem 10 s: tick liczacy na 20 zginalby przed oddaniem pozycji,
 * i to co godzine. Bierzemy 80% limitu — ta sama proporcja, co przy pamieci.
 */
ini_set( 'max_execution_time', '10' );
k5_check( 8.0 === Runner::tick_budget(), 'limit 10 s przycina budzet do 8 s (80%)' );

ini_set( 'max_execution_time', (string) $stary_limit );

// --- Dzialka fazy zbierania ------------------------------------------------
update_option(
	Settings::OPTION_SOURCES,
	array( 'https://a.test/feed/', 'https://b.test/feed/', 'https://c.test/feed/' )
);

$GLOBALS['__zadania']    = array();
$GLOBALS['__transient']  = array();
$GLOBALS['__http_sleep'] = 0.4;

$wynik = Runner::tick( 1.0 );

/*
 * Trzy kanaly po 0,4 s przy dzialce 0,25 s: pierwszy kanal wchodzi bez pytania
 * (budzet sprawdzany PRZED kanalem, a przed pierwszym nic sie jeszcze nie
 * zuzylo), drugi juz nie. Asercja jest na LICZBIE zadan, nie na czasie —
 * pomiar czasu w asercji bywa raz zielony, raz czerwony.
 */
k5_check( 1 === count( $GLOBALS['__zadania'] ), 'faza zbierania stanela po pierwszym kanale (zadan: ' . count( $GLOBALS['__zadania'] ) . ')' );
k5_check( true === ( $wynik['collect']['budget_hit'] ?? false ), 'i zglosila to w podsumowaniu' );
k5_check( 1.0 === $wynik['budget'], 'tick zapamietal budzet, z ktorym pracowal' );

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

// --- Faza, na ktora nie starczylo czasu, NIE jest odpalana -----------------
$GLOBALS['__slad']       = array();
$GLOBALS['__zadania']    = array();
$GLOBALS['__transient']  = array();
$GLOBALS['__http_sleep'] = 1.2;

update_option( Settings::OPTION_SOURCES, array( 'https://a.test/feed/' ) );

$wynik = Runner::tick( 1.0 );

k5_check( array( 'prepare', 'publish' ) === $wynik['skipped'], 'obie pozostale fazy pominiete, nie odpalone „na chwile" (jest: ' . implode( ', ', $wynik['skipped'] ) . ')' );
k5_check( array( 'recover', 'collect' ) === $GLOBALS['__slad'], 'i zadna z nich nie ruszyla bazy' );
k5_check( false === get_transient( Admin::TRANSIENT_LOCK ), 'zamek zdjety mimo wyczerpanego budzetu' );

$GLOBALS['__http_sleep'] = 0.0;

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
