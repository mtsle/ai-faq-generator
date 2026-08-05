<?php
/**
 * Krok 1 — cykl zycia wtyczki AI News Portal.
 *
 * POKRYWA TEST 16 z tabeli planu („aktywacja dwa razy pod rzad -> 1 artykul
 * demo, nie 2") oraz warunki ODBIORU Kroku 1, ktorych nie da sie sprawdzic
 * inaczej niz przez zachowanie kodu:
 *   - tabela powstaje z DWOMA nazwanymi kluczami UNIQUE i kolumna LONGTEXT,
 *   - CPT i taksonomia sa rejestrowane PRZED `flush_rewrite_rules()`,
 *   - CPT ma `show_ui => true`, `show_in_menu => false`, wlasny `rewrite`,
 *   - menu ma dokladnie dwie pozycje, obie za `manage_options`,
 *   - kolizja slugu daje jednorazowy komunikat,
 *   - dezaktywacja czysci cron i wyrejestrowuje typy PRZED flushem.
 *
 * Zero WordPressa i zero sieci — WordPress jest podstawiony atrapami, a
 * `dbDelta()` przychodzi z atrapy w `tests/atrapy/wp/`. Asercje ilosciowe
 * sa zawsze `=== N`, nigdy `> 0`.
 *
 * URUCHOMIENIE:  php tests/krok1-cykl-zycia-test.php
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
function k1_check( $cond, $label ) {
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

$GLOBALS['__ainp_dbdelta']  = array();   // SQL przekazany do dbDelta().
$GLOBALS['__log']           = array();   // Kolejnosc wywolan (rejestracja vs flush).
$GLOBALS['__opt']           = array();   // Magazyn opcji.
$GLOBALS['__posts']         = array();   // Wstawione wpisy.
$GLOBALS['__terms']         = array();   // Terminy taksonomii.
$GLOBALS['__obj_terms']     = array();   // Przypisania terminow do wpisow.
$GLOBALS['__ptypes']        = array();   // Argumenty register_post_type().
$GLOBALS['__taxes']         = array();   // Argumenty register_taxonomy().
$GLOBALS['__cron_cleared']  = array();   // wp_clear_scheduled_hook().
$GLOBALS['__menu']          = array();   // add_menu_page().
$GLOBALS['__submenu']       = array();   // add_submenu_page().
$GLOBALS['__actions']       = array();   // add_action().
$GLOBALS['__page_by_path']  = null;      // Wynik get_page_by_path().
$GLOBALS['__insert_error']  = false;     // Wymuszenie bledu wp_insert_post().
$GLOBALS['__next_post_id']  = 100;
$GLOBALS['__uid']           = 0;         // Biezacy uzytkownik (0 = brak, np. WP-CLI).
$GLOBALS['__admini']        = array( 7 );// ID administratorow zwracane przez get_users().

class WP_Post {
	public $ID = 0;
	public $post_type = 'page';
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

class AINP_Fake_WPDB {
	public $prefix    = 'wp_';
	public $posts     = 'wp_posts';
	public $postmeta  = 'wp_postmeta';
	public function get_charset_collate() {
		return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci';
	}
}
$GLOBALS['wpdb'] = new AINP_Fake_WPDB();

function get_option( $k, $default = false ) {
	return array_key_exists( $k, $GLOBALS['__opt'] ) ? $GLOBALS['__opt'][ $k ] : $default;
}
function update_option( $k, $v, $autoload = null ) {
	$GLOBALS['__opt'][ $k ] = $v;
	return true;
}
function delete_option( $k ) {
	unset( $GLOBALS['__opt'][ $k ] );
	return true;
}
function add_action( $hook, $cb, $prio = 10, $args = 1 ) {
	$GLOBALS['__actions'][] = $hook;
	return true;
}
function register_post_type( $type, $args = array() ) {
	$GLOBALS['__log'][]             = 'register_post_type';
	$GLOBALS['__ptypes'][ $type ]   = $args;
	return true;
}
function register_taxonomy( $tax, $object_type, $args = array() ) {
	$GLOBALS['__log'][]        = 'register_taxonomy';
	$GLOBALS['__taxes'][ $tax ] = array(
		'object_type' => (array) $object_type,
		'args'        => $args,
	);
	return true;
}
function unregister_post_type( $type ) {
	$GLOBALS['__log'][] = 'unregister_post_type';
	return true;
}
function unregister_taxonomy( $tax ) {
	$GLOBALS['__log'][] = 'unregister_taxonomy';
	return true;
}
function flush_rewrite_rules( $hard = true ) {
	$GLOBALS['__log'][] = 'flush_rewrite_rules';
	return true;
}
function wp_clear_scheduled_hook( $hook, $args = array() ) {
	$GLOBALS['__cron_cleared'][] = $hook;
	return true;
}
function get_page_by_path( $path, $output = null, $post_type = 'page' ) {
	return $GLOBALS['__page_by_path'];
}
function wp_insert_post( $data, $wp_error = false ) {
	if ( $GLOBALS['__insert_error'] ) {
		return $wp_error ? new WP_Error( 'db_insert_error', 'atrapa: zapis odrzucony' ) : 0;
	}
	$id                        = $GLOBALS['__next_post_id']++;
	$data['ID']                = $id;
	$GLOBALS['__posts'][ $id ] = $data;
	return $id;
}
function term_exists( $term, $taxonomy = '', $parent = null ) {
	foreach ( $GLOBALS['__terms'] as $id => $t ) {
		if ( $t['name'] === $term && $t['taxonomy'] === $taxonomy ) {
			return array(
				'term_id'          => $id,
				'term_taxonomy_id' => $id,
			);
		}
	}
	return null;
}
function wp_insert_term( $term, $taxonomy, $args = array() ) {
	$id                        = count( $GLOBALS['__terms'] ) + 1;
	$GLOBALS['__terms'][ $id ] = array(
		'name'     => $term,
		'taxonomy' => $taxonomy,
	);
	return array(
		'term_id'          => $id,
		'term_taxonomy_id' => $id,
	);
}
function wp_set_object_terms( $object_id, $terms, $taxonomy, $append = false ) {
	$GLOBALS['__obj_terms'][ (int) $object_id ] = array(
		'terms'    => (array) $terms,
		'taxonomy' => $taxonomy,
	);
	return (array) $terms;
}
function current_user_can( $cap ) {
	return true;
}
function get_current_user_id() {
	return (int) $GLOBALS['__uid'];
}
function get_users( $args = array() ) {
	return $GLOBALS['__admini'];
}
function __( $s, $domain = null ) {
	return $s;
}
function esc_html__( $s, $domain = null ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
}
function get_edit_post_link( $id = 0, $context = 'display' ) {
	return 'https://example.test/wp-admin/post.php?post=' . (int) $id . '&action=edit';
}
function admin_url( $path = '' ) {
	return 'https://example.test/wp-admin/' . $path;
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
function wp_kses_post( $s ) {
	return (string) $s;
}
function wp_die( $msg = '' ) {
	throw new RuntimeException( 'wp_die: ' . $msg );
}
function add_menu_page( $page_title, $menu_title, $cap, $slug, $cb = '', $icon = '', $pos = null ) {
	$GLOBALS['__menu'][] = array(
		'title' => $menu_title,
		'cap'   => $cap,
		'slug'  => $slug,
	);
	return $slug;
}
function add_submenu_page( $parent, $page_title, $menu_title, $cap, $slug, $cb = '' ) {
	$GLOBALS['__submenu'][] = array(
		'parent' => $parent,
		'title'  => $menu_title,
		'cap'    => $cap,
		'slug'   => $slug,
	);
	return $slug;
}

require_once $root . '/src/Settings.php';
require_once $root . '/src/Plugin.php';
require_once $root . '/src/Admin.php';

use AINP\Admin;
use AINP\Plugin;
use AINP\Settings;

// ---------------------------------------------------------------------------
// 1. Aktywacja: tabela.
// ---------------------------------------------------------------------------
echo "\n=== 1. Aktywacja — tabela ainp_items ===\n";

Plugin::activate();

k1_check( 1 === count( $GLOBALS['__ainp_dbdelta'] ), 'dbDelta wolane dokladnie raz (jest: ' . count( $GLOBALS['__ainp_dbdelta'] ) . ')' );

$sql = $GLOBALS['__ainp_dbdelta'][0] ?? '';

k1_check( false !== strpos( $sql, 'CREATE TABLE wp_ainp_items' ), 'tabela nazwana wp_ainp_items (prefiks witryny + ainp_)' );
k1_check( 2 === substr_count( $sql, 'UNIQUE KEY' ), 'dokladnie DWA klucze UNIQUE (jest: ' . substr_count( $sql, 'UNIQUE KEY' ) . ')' );
k1_check( false !== strpos( $sql, 'UNIQUE KEY ainp_url_hash (url_hash)' ), 'UNIQUE na url_hash jest NAZWANY (wymog dbDelta)' );
k1_check( false !== strpos( $sql, 'UNIQUE KEY ainp_content_hash (content_hash)' ), 'UNIQUE na content_hash jest NAZWANY' );
k1_check( false !== strpos( $sql, 'content longtext NOT NULL' ), 'kolumna content jest LONGTEXT' );
k1_check( false !== strpos( $sql, 'content_hash char(64) NULL DEFAULT NULL' ), 'content_hash dopuszcza NULL (wiele NULL-i w UNIQUE jest dozwolone)' );
k1_check( false !== strpos( $sql, 'PRIMARY KEY  (id)' ), 'PRIMARY KEY z DWOMA spacjami (dbDelta inaczej nie rozpozna klucza)' );
k1_check( false !== strpos( $sql, 'DEFAULT CHARACTER SET utf8mb4' ), 'uzyty get_charset_collate(), nie zaszyty charset' );
k1_check( false !== strpos( $sql, 'ENGINE=InnoDB' ), 'silnik InnoDB' );

// ---------------------------------------------------------------------------
// 2. Aktywacja: kolejnosc rejestracja -> flush.
// ---------------------------------------------------------------------------
echo "\n=== 2. Aktywacja — rejestracja PRZED flushem ===\n";

$poz_cpt   = array_search( 'register_post_type', $GLOBALS['__log'], true );
$poz_tax   = array_search( 'register_taxonomy', $GLOBALS['__log'], true );
$poz_flush = array_search( 'flush_rewrite_rules', $GLOBALS['__log'], true );

k1_check( false !== $poz_cpt && false !== $poz_tax && false !== $poz_flush, 'aktywacja rejestruje CPT, taksonomie i przeladowuje reguly' );
k1_check( $poz_cpt < $poz_flush, 'CPT zarejestrowany PRZED flush_rewrite_rules()' );
k1_check( $poz_tax < $poz_flush, 'taksonomia zarejestrowana PRZED flush_rewrite_rules() (inaczej 404 na kategoriach)' );
/*
 * Kolejnosc taksonomia -> CPT jest wymogiem, ktorego nie widac w kodzie:
 * WordPress generuje reguly przepisywania w kolejnosci rejestracji, a CPT
 * dokłada regule zalacznikow `centrum-wiedzy/[^/]+/([^/]+)/?$`, ktora pasuje
 * takze do adresu kategorii. Przy odwrotnej kolejnosci /centrum-wiedzy/kategoria/zywienie/
 * zwracalo 404 na dworek.local — zmierzone, nie teoretyczne.
 */
k1_check( $poz_tax < $poz_cpt, 'taksonomia rejestrowana PRZED CPT (regula zalacznikow CPT przeslania adres kategorii)' );
k1_check( 1 === substr_count( implode( ',', $GLOBALS['__log'] ), 'flush_rewrite_rules' ), 'flush dokladnie raz na aktywacje' );

// ---------------------------------------------------------------------------
// 3. CPT i taksonomia — argumenty.
// ---------------------------------------------------------------------------
echo "\n=== 3. CPT ainp_article i taksonomia ainp_topic ===\n";

$cpt = $GLOBALS['__ptypes']['ainp_article'] ?? array();
$tax = $GLOBALS['__taxes']['ainp_topic'] ?? array();

k1_check( array() !== $cpt, 'zarejestrowany CPT o nazwie ainp_article' );
k1_check( true === ( $cpt['public'] ?? null ), 'CPT public => true' );
k1_check( true === ( $cpt['show_ui'] ?? null ), 'CPT show_ui => true (ekrany edycji dzialaja)' );
k1_check( false === ( $cpt['show_in_menu'] ?? null ), 'CPT show_in_menu => false (brak trzeciej pozycji w menu)' );
k1_check( 'centrum-wiedzy' === ( $cpt['has_archive'] ?? null ), 'has_archive => centrum-wiedzy' );
k1_check( 'centrum-wiedzy' === ( $cpt['rewrite']['slug'] ?? null ), 'rewrite[slug] => centrum-wiedzy (bez tego single ladowalby pod /ainp_article/)' );
k1_check( false === ( $cpt['rewrite']['with_front'] ?? null ), 'rewrite[with_front] => false (archiwum nie wedruje pod czlon struktury odnosnikow)' );

k1_check( array() !== $tax, 'zarejestrowana taksonomia ainp_topic' );
k1_check( array( 'ainp_article' ) === ( $tax['object_type'] ?? array() ), 'taksonomia podpieta wylacznie pod ainp_article' );
k1_check( true === ( $tax['args']['hierarchical'] ?? null ), 'taksonomia hierarchiczna (kategorie, nie tagi)' );
k1_check( 'centrum-wiedzy/kategoria' === ( $tax['args']['rewrite']['slug'] ?? null ), 'archiwa kategorii pod /centrum-wiedzy/kategoria/<kategoria>/ — dodatkowy czlon rozdziela wzorce regul' );

// ---------------------------------------------------------------------------
// 4. TEST 16 — artykul demo, aktywacja dwa razy pod rzad.
// ---------------------------------------------------------------------------
echo "\n=== 4. TEST 16 — demo wstawione DOKLADNIE raz ===\n";

k1_check( 1 === count( $GLOBALS['__posts'] ), 'po pierwszej aktywacji jest 1 wpis (jest: ' . count( $GLOBALS['__posts'] ) . ')' );

$demo = reset( $GLOBALS['__posts'] );

k1_check( 'ainp_article' === ( $demo['post_type'] ?? '' ), 'demo jest artykulem ainp_article' );
k1_check( 'publish' === ( $demo['post_status'] ?? '' ), 'demo opublikowane (portal nie jest pusty od pierwszej chwili)' );
k1_check( 1 === ( $demo['meta_input']['_ainp_demo'] ?? 0 ), 'demo oznaczone meta _ainp_demo' );
k1_check( strlen( (string) ( $demo['post_content'] ?? '' ) ) >= 800, 'tresc demo ma co najmniej 800 znakow (jest: ' . strlen( (string) ( $demo['post_content'] ?? '' ) ) . ')' );
k1_check( '' !== ( $demo['post_excerpt'] ?? '' ), 'demo ma lead' );
k1_check( 1 === count( $GLOBALS['__obj_terms'] ), 'demo dostalo kategorie (archiwum kategorii ma co pokazac)' );
/*
 * Autor: aktywacja z WP-CLI albo programowa nie ma zalogowanego uzytkownika.
 * Bez jawnego `post_author` wpis dostawal wtedy autora 0 — zmierzone na zywej
 * witrynie przed poprawka.
 */
k1_check( 7 === (int) ( $demo['post_author'] ?? 0 ), 'bez zalogowanego uzytkownika demo dostaje pierwszego administratora, nie autora 0 (jest: ' . (int) ( $demo['post_author'] ?? 0 ) . ')' );
k1_check( 'ainp_topic' === ( $GLOBALS['__obj_terms'][ $demo['ID'] ]['taxonomy'] ?? '' ), 'kategoria demo jest z taksonomii ainp_topic' );
k1_check( true === Settings::get( Settings::KEY_DEMO_DONE, false ), 'znacznik ainp_demo_done ustawiony po udanym wstawieniu' );

Plugin::activate();

k1_check( 1 === count( $GLOBALS['__posts'] ), 'TEST 16: po DRUGIEJ aktywacji nadal 1 artykul demo, nie 2 (jest: ' . count( $GLOBALS['__posts'] ) . ')' );
k1_check( 2 === count( $GLOBALS['__ainp_dbdelta'] ), 'dbDelta wolane przy kazdej aktywacji (idempotentne)' );

// ---------------------------------------------------------------------------
// 5. Demo: nieudany zapis NIE pali znacznika.
// ---------------------------------------------------------------------------
echo "\n=== 5. Demo — blad zapisu nie odbiera demo na zawsze ===\n";

$GLOBALS['__opt']          = array();
$GLOBALS['__posts']        = array();
$GLOBALS['__obj_terms']    = array();
$GLOBALS['__insert_error'] = true;

Plugin::activate();

k1_check( 0 === count( $GLOBALS['__posts'] ), 'przy bledzie zapisu nie powstal zaden wpis' );
k1_check( false === Settings::get( Settings::KEY_DEMO_DONE, false ), 'znacznik NIE ustawiony — kolejna aktywacja sprobuje ponownie' );

$GLOBALS['__insert_error'] = false;
$GLOBALS['__uid']          = 3;
Plugin::activate();

k1_check( 1 === count( $GLOBALS['__posts'] ), 'po ustaniu bledu demo wstawilo sie za drugim podejsciem' );

$demo2 = reset( $GLOBALS['__posts'] );
k1_check( 3 === (int) ( $demo2['post_author'] ?? 0 ), 'gdy ktos jest zalogowany, autorem demo jest ON, nie zastepczy administrator' );

$GLOBALS['__uid'] = 0;

// ---------------------------------------------------------------------------
// 6. Kolizja slugu `centrum-wiedzy`.
// ---------------------------------------------------------------------------
echo "\n=== 6. Kolizja slugu — komunikat jednorazowy ===\n";

$GLOBALS['__opt']          = array();
$GLOBALS['__page_by_path'] = new WP_Post( 77 );

Plugin::activate();

k1_check( 77 === (int) get_option( Plugin::OPTION_SLUG_COLLISION, 0 ), 'kolizja wykryta: zapisany ID istniejacej Strony' );

ob_start();
Plugin::render_admin_notices();
$notice = (string) ob_get_clean();

k1_check( false !== strpos( $notice, 'notice-warning' ), 'komunikat renderuje sie jako ostrzezenie' );
k1_check( false !== strpos( $notice, 'centrum-wiedzy' ), 'komunikat podaje adres, o ktory chodzi' );
k1_check( false !== strpos( $notice, 'post=77' ), 'komunikat linkuje do edycji kolidujacej Strony' );
k1_check( 0 === (int) get_option( Plugin::OPTION_SLUG_COLLISION, 0 ), 'sygnal skasowany po pokazaniu' );

ob_start();
Plugin::render_admin_notices();
$drugi = (string) ob_get_clean();

k1_check( '' === $drugi, 'drugie wejscie do kokpitu nie powtarza komunikatu' );

$GLOBALS['__page_by_path'] = null;
$GLOBALS['__opt']          = array();
Plugin::activate();

k1_check( 0 === (int) get_option( Plugin::OPTION_SLUG_COLLISION, 0 ), 'bez kolidujacej Strony sygnal nie powstaje' );

// ---------------------------------------------------------------------------
// 7. Dezaktywacja.
// ---------------------------------------------------------------------------
echo "\n=== 7. Dezaktywacja ===\n";

$GLOBALS['__log']          = array();
$GLOBALS['__cron_cleared'] = array();

Plugin::deactivate();

k1_check( array( 'ainp_tick' ) === $GLOBALS['__cron_cleared'], 'dezaktywacja czysci zdarzenie ainp_tick (i tylko je)' );

$poz_unreg_cpt = array_search( 'unregister_post_type', $GLOBALS['__log'], true );
$poz_unreg_tax = array_search( 'unregister_taxonomy', $GLOBALS['__log'], true );
$poz_flush2    = array_search( 'flush_rewrite_rules', $GLOBALS['__log'], true );

k1_check( false !== $poz_unreg_cpt && $poz_unreg_cpt < $poz_flush2, 'CPT wyrejestrowany PRZED flushem (inaczej reguly wylaczanej wtyczki zostalyby zapisane)' );
k1_check( false !== $poz_unreg_tax && $poz_unreg_tax < $poz_flush2, 'taksonomia wyrejestrowana PRZED flushem' );

// ---------------------------------------------------------------------------
// 8. Menu — DOKLADNIE dwie pozycje.
// ---------------------------------------------------------------------------
echo "\n=== 8. Panel — dwie pozycje menu ===\n";

Admin::register_menu();

k1_check( 1 === count( $GLOBALS['__menu'] ), 'jedna pozycja nadrzedna (jest: ' . count( $GLOBALS['__menu'] ) . ')' );
k1_check( 2 === count( $GLOBALS['__submenu'] ), 'DOKLADNIE dwie pozycje podrzedne (jest: ' . count( $GLOBALS['__submenu'] ) . ')' );

$tytuly = array_column( $GLOBALS['__submenu'], 'title' );
k1_check( array( 'Materiały', 'Ustawienia' ) === $tytuly, 'pozycje to Materialy i Ustawienia (sa: ' . implode( ', ', $tytuly ) . ')' );
k1_check( $GLOBALS['__menu'][0]['slug'] === $GLOBALS['__submenu'][0]['slug'], 'pierwsze podmenu dzieli slug z pozycja nadrzedna (bez tego powstaje trzecia pozycja)' );

$capy = array_unique( array_merge( array_column( $GLOBALS['__menu'], 'cap' ), array_column( $GLOBALS['__submenu'], 'cap' ) ) );
k1_check( array( 'manage_options' ) === array_values( $capy ), 'kazda pozycja za uprawnieniem manage_options' );

// ---------------------------------------------------------------------------
// 9. Ekrany panelu.
// ---------------------------------------------------------------------------
echo "\n=== 9. Ekrany panelu ===\n";

ob_start();
Admin::render_items();
$ekran_materialy = (string) ob_get_clean();

k1_check( false !== strpos( $ekran_materialy, 'edit.php?post_type=ainp_article' ), 'ekran Materialy linkuje do hurtowego zarzadzania artykulami' );

ob_start();
Admin::render_settings();
$ekran_ustawienia = (string) ob_get_clean();

k1_check( false !== strpos( $ekran_ustawienia, 'gemini-2.5-flash' ), 'ekran Ustawien pokazuje domyslny model' );
k1_check( 7 === count( Settings::get( 'categories', array() ) ), 'siedem domyslnych kategorii (jest: ' . count( Settings::get( 'categories', array() ) ) . ')' );
k1_check( 20 === Settings::get( 'daily_cap', 0 ), 'domyslny sufit dobowy wywolan AI = 20' );
k1_check( false === Settings::get( 'save_as_draft', true ), 'przelacznik „zapisuj jako szkice" domyslnie WYLACZONY' );

$slowa = Settings::get( 'excluded_words', array() );
k1_check( in_array( 'kot', $slowa, true ) && in_array( 'promocja', $slowa, true ) && in_array( 'schronisko', $slowa, true ), 'slowa wykluczajace obejmuja wszystkie trzy warstwy' );

// ---------------------------------------------------------------------------
// 10. Hooki startowe.
// ---------------------------------------------------------------------------
echo "\n=== 10. Start wtyczki ===\n";

$GLOBALS['__actions'] = array();
Plugin::boot();

k1_check( in_array( 'init', $GLOBALS['__actions'], true ), 'rejestracja CPT podpieta pod init' );
k1_check( in_array( 'admin_menu', $GLOBALS['__actions'], true ), 'menu podpiete pod admin_menu' );
k1_check( in_array( 'admin_notices', $GLOBALS['__actions'], true ), 'komunikaty podpiete pod admin_notices' );

// ---------------------------------------------------------------------------
echo "\n===================================================================\n";
echo 'Asercji: ' . $ran . ' | Niezaliczonych: ' . $fail . "\n";
if ( 0 === $fail ) {
	echo "WYNIK: WSZYSTKIE OK\n";
	exit( 0 );
}
echo "WYNIK: SA BLEDY\n";
exit( 1 );
