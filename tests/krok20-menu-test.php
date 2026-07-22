<?php
/**
 * Testy Kroku 20 — link w menu nawigacji: `MenuGuard`, cykl życia (aktywacja/deaktywacja/
 * odinstalowanie) oraz komunikaty kokpitu (`MenuNotice`, `EditorNotice`).
 *
 * NAPISANE W CIEMNO wyłącznie z `plany/krok20/KONTRAKT.md` (wersja k20-v3) oraz
 * `plany/krok20/ODCHYLENIA.md`, bez zaglądania w `src/PublicUi/MenuGuard.php`,
 * `src/Admin/MenuNotice.php`, `src/Admin/EditorNotice.php`, `src/Core/Plugin.php`,
 * `src/Core/Activator.php` i `src/Core/Deactivator.php`. Rozbieżność test <-> implementacja
 * jest dowodem, że kontrakt był nieprecyzyjny — idzie do `ODCHYLENIA.md`, nie do cichej
 * korekty asercji.
 *
 * Pokrycie: §3.1 (API), §3.2 (dziesięć stanów + bramka), §3.3 (dziewięć reguł twardych),
 * §3.4 (ścieżki powstawania), §3.5 (opcje), §3.6 (deaktywacja), §4 (komunikaty),
 * §13.1-§13.7, §13.26 oraz pięć kryteriów akceptacji obszaru A.
 *
 * URUCHOMIENIE:  php tests/krok20-menu-test.php
 * Kod wyjścia: 0 = OK, 1 = błędy.
 *
 * @package AI_FAQ_Generator
 */

// ---------------------------------------------------------------------------
// PREAMBUŁA — stałe PRZED pierwszym `require`.
// Brak AIFAQ_TESTING => handle_fix() robi `exit` (§4.3 pkt 4) i runner zaraportuje
// SUKCES na urwanym pliku. To ta sama pułapka, co w K18.
// ---------------------------------------------------------------------------
// ABSPATH wskazuje KATALOG TYMCZASOWY z atrapą `wp-admin/includes/upgrade.php`.
// Powód: `Schema::install()` wciąga ten plik po `dbDelta()`, a bez niego `Activator::activate()`
// wywraca się na `require_once` — czyli kryterium akceptacji 4 („aktywacja bez menu nie pada")
// byłoby nietestowalne. Katalog powstaje poza repozytorium i niczego w nim nie rusza.
if ( ! defined( 'ABSPATH' ) ) {
	$k20m_root = rtrim( str_replace( '\\', '/', sys_get_temp_dir() ), '/' ) . '/aifaq-k20-menu-root/';
	if ( ! is_dir( $k20m_root . 'wp-admin/includes' ) ) { @mkdir( $k20m_root . 'wp-admin/includes', 0777, true ); }
	if ( ! file_exists( $k20m_root . 'wp-admin/includes/upgrade.php' ) ) {
		file_put_contents(
			$k20m_root . 'wp-admin/includes/upgrade.php',
			"<?php\n// Atrapa na potrzeby tests/krok20-menu-test.php — `dbDelta()` shimuje sam test.\n"
		);
	}
	define( 'ABSPATH', $k20m_root );
}
if ( ! defined( 'AIFAQ_TESTING' ) ) { define( 'AIFAQ_TESTING', true ); }
if ( ! defined( 'AIFAQ_VERSION' ) ) { define( 'AIFAQ_VERSION', '0.0.0-test' ); }
if ( ! defined( 'AIFAQ_PLUGIN_URL' ) ) { define( 'AIFAQ_PLUGIN_URL', 'http://test.local/wp-content/plugins/ai-faq-generator/' ); }
if ( ! defined( 'AIFAQ_PLUGIN_DIR' ) ) { define( 'AIFAQ_PLUGIN_DIR', dirname( __DIR__ ) . '/' ); }
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
if ( ! defined( 'OBJECT' ) ) { define( 'OBJECT', 'OBJECT' ); }
// Wersja schematu — §10 pkt 4 zamraża ją na '4'. Bez tej stałej `Activator::activate()`
// pada na `Undefined constant`, zanim w ogóle dojdzie do kroku menu.
if ( ! defined( 'AIFAQ_DB_VERSION' ) ) { define( 'AIFAQ_DB_VERSION', '4' ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); }

// Stała identyfikatora podstrony — używana w scenariuszach.
if ( ! defined( 'K20_PAGE_ID' ) ) { define( 'K20_PAGE_ID', 500 ); }

// ---------------------------------------------------------------------------
// Klasy WP. WSZYSTKIE pola nav-menu ZADEKLAROWANE jawnie — dynamiczne właściwości
// są w PHP 8.2 przestarzałe, a łapacz niżej liczy każde E_DEPRECATED.
// ---------------------------------------------------------------------------
if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public $ID = 0;
		public $post_type = 'page';
		public $post_status = 'publish';
		public $post_name = '';
		public $post_title = '';
		public $post_content = '';
		public $post_parent = 0;
		public $menu_order = 0;
		// --- pola pozycji menu (wp_setup_nav_menu_item) ---
		public $db_id = 0;
		public $object_id = 0;
		public $object = '';
		public $type = '';
		public $type_label = '';
		public $title = '';
		public $url = '';
		public $target = '';
		public $attr_title = '';
		public $description = '';
		public $classes = array();
		public $xfn = '';
		public $menu_item_parent = 0;
		public function __construct( $f = array() ) {
			foreach ( $f as $k => $v ) { $this->$k = $v; }
		}
	}
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code;
		private $msg;
		public function __construct( $c = '', $m = '' ) { $this->code = (string) $c; $this->msg = (string) $m; }
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->msg; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $t ) { return $t instanceof WP_Error; } }

// ---------------------------------------------------------------------------
// Magazyny stanu.
// ---------------------------------------------------------------------------
$GLOBALS['__opt']             = array();
$GLOBALS['__autoload']        = array();
$GLOBALS['__posts']           = array();
$GLOBALS['__usermeta']        = array();
$GLOBALS['__nav_registered']  = array();   // lokalizacja => etykieta
$GLOBALS['__nav_locations']   = array();   // lokalizacja => menu_id
$GLOBALS['__menu_items']      = array();   // menu_id => WP_Post[]
$GLOBALS['__nav_item_args']   = array();
$GLOBALS['__nav_item_error']  = false;
$GLOBALS['__next_item_id']    = 900;
$GLOBALS['__block_theme']     = false;
$GLOBALS['__caps']            = array( 'manage_options', 'publish_posts', 'edit_posts', 'edit_published_posts' );
$GLOBALS['__uid']             = 7;
$GLOBALS['__screen']          = 'dashboard';
$GLOBALS['__screen_base']     = 'dashboard';
$GLOBALS['__screen_ptype']    = '';
$GLOBALS['__died']            = array();
$GLOBALS['__redirect']        = array();
$GLOBALS['__referer_calls']   = array();
$GLOBALS['__nonce_ok']        = true;
$GLOBALS['__doing_ajax']      = false;
$GLOBALS['__doing_cron']      = false;
$GLOBALS['__deleted_posts']   = array();
$GLOBALS['__forbidden']       = array( 'wp_create_nav_menu' => false, 'set_theme_mod' => false );
$GLOBALS['__cnt']             = array();
// Zegar atrapy `current_time()` USTAWIONY NA ZEGAR SYSTEMOWY. §3.3 pkt 5 mówi o `last + 21600`,
// ale NIE zamraża, czy backoff mierzy czas przez `time()`, czy przez `current_time()`
// (patrz ODCHYLENIA O-105). Zrównanie obu zegarów czyni asercje backoffu niezależnymi od wyboru:
// wiek liczymy, cofając zapisane `last`, a nie przesuwając zegar.
$GLOBALS['__now']             = time();

function k20m_cnt_reset() {
	$GLOBALS['__cnt'] = array(
		'update_option' => 0, 'add_option' => 0, 'delete_option' => 0,
		'wp_update_nav_menu_item' => 0, 'wp_delete_post' => 0, 'get_post' => 0,
		'check_admin_referer' => 0, 'wp_die' => 0, 'wp_safe_redirect' => 0,
		'update_user_meta' => 0,
	);
}
k20m_cnt_reset();

// Łapacz warningów — cicha awaria w ścieżce nawigacji jest gorsza niż głośna.
$GLOBALS['aifaq_warnings'] = 0;
set_error_handler(
	function ( $errno, $errstr ) {
		$GLOBALS['aifaq_warnings']++;
		echo "  [PHP WARNING] $errstr\n";
		return true;
	},
	E_WARNING | E_NOTICE | E_DEPRECATED
);

// ---------------------------------------------------------------------------
// Shimy WP — wszystkie PRZED `require`.
// ---------------------------------------------------------------------------
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( '_e' ) ) { function _e( $s, $d = null ) { echo $s; } }
if ( ! function_exists( '_x' ) ) { function _x( $s, $c = '', $d = null ) { return $s; } }
if ( ! function_exists( '_n' ) ) { function _n( $a, $b, $n, $d = null ) { return 1 === (int) $n ? $a : $b; } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $s, $d = null ) { return (string) $s; } }
if ( ! function_exists( 'esc_html_e' ) ) { function esc_html_e( $s, $d = null ) { echo (string) $s; } }
if ( ! function_exists( 'esc_attr__' ) ) { function esc_attr__( $s, $d = null ) { return (string) $s; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $s ) { return (string) $s; } }
if ( ! function_exists( 'esc_url_raw' ) ) { function esc_url_raw( $s ) { return (string) $s; } }
if ( ! function_exists( 'wp_kses_post' ) ) { function wp_kses_post( $s ) { return (string) $s; } }
if ( ! function_exists( 'wp_kses' ) ) { function wp_kses( $s, $a = array() ) { return (string) $s; } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $p = '' ) { return 'http://test.local/wp-admin/' . $p; } }
if ( ! function_exists( 'home_url' ) ) { function home_url( $p = '/' ) { return 'http://test.local' . $p; } }
if ( ! function_exists( 'site_url' ) ) { function site_url( $p = '/' ) { return 'http://test.local' . $p; } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $d, $o = 0 ) { return json_encode( $d, $o ); } }
if ( ! function_exists( 'wp_unslash' ) ) { function wp_unslash( $s ) { return is_string( $s ) ? stripslashes( $s ) : $s; } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ) { return trim( preg_replace( '/[\r\n\t]+/', ' ', strip_tags( (string) $s ) ) ); } }
if ( ! function_exists( 'sanitize_textarea_field' ) ) { function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); } }
if ( ! function_exists( 'sanitize_key' ) ) { function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $s ) ); } }
if ( ! function_exists( 'sanitize_title' ) ) { function sanitize_title( $s ) { return strtolower( trim( preg_replace( '/[^A-Za-z0-9\-_]+/', '-', (string) $s ), '-' ) ); } }
if ( ! function_exists( 'absint' ) ) { function absint( $n ) { return abs( (int) $n ); } }
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $h, $v = null, ...$a ) { return $v; } }
if ( ! function_exists( 'add_filter' ) ) { function add_filter( $h, $cb, $p = 10, $n = 1 ) { return true; } }
if ( ! function_exists( 'add_action' ) ) { function add_action( $h, $cb, $p = 10, $n = 1 ) { return true; } }
if ( ! function_exists( 'do_action' ) ) { function do_action( $h, ...$a ) { return null; } }
if ( ! function_exists( 'add_shortcode' ) ) { function add_shortcode( $t, $cb ) { return true; } }
if ( ! function_exists( 'is_admin' ) ) { function is_admin() { return true; } }
if ( ! function_exists( 'add_rewrite_rule' ) ) { function add_rewrite_rule( $r, $q, $a = 'bottom' ) {} }
if ( ! function_exists( 'flush_rewrite_rules' ) ) { function flush_rewrite_rules( $hard = true ) {} }
if ( ! function_exists( 'number_format_i18n' ) ) { function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, (int) $d ); } }
if ( ! function_exists( 'checked' ) ) { function checked( $a, $b = true, $e = true ) { $r = ( (string) $a === (string) $b ) ? ' checked="checked"' : ''; if ( $e ) { echo $r; } return $r; } }
if ( ! function_exists( 'selected' ) ) { function selected( $a, $b = true, $e = true ) { $r = ( (string) $a === (string) $b ) ? ' selected="selected"' : ''; if ( $e ) { echo $r; } return $r; } }
if ( ! function_exists( 'add_settings_error' ) ) { function add_settings_error( $s, $c, $m, $t = 'error' ) {} }
if ( ! function_exists( 'wp_doing_ajax' ) ) { function wp_doing_ajax() { return (bool) $GLOBALS['__doing_ajax']; } }
if ( ! function_exists( 'wp_doing_cron' ) ) { function wp_doing_cron() { return (bool) $GLOBALS['__doing_cron']; } }
if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type = 'mysql', $gmt = 0 ) {
		if ( 'timestamp' === $type || 'U' === $type ) { return (int) $GLOBALS['__now']; }
		if ( 'mysql' === $type ) { return date( 'Y-m-d H:i:s', (int) $GLOBALS['__now'] ); }
		return date( (string) $type, (int) $GLOBALS['__now'] );
	}
}

// --- opcje: semantyka ZAMROŻONA. add_option zwracające zawsze `true` unieważnia asercje zamka. ---
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__opt'] ) ? $GLOBALS['__opt'][ $k ] : $d; }
}
if ( ! function_exists( 'add_option' ) ) {
	function add_option( $k, $v = '', $dep = '', $autoload = 'yes' ) {
		$GLOBALS['__cnt']['add_option']++;
		if ( array_key_exists( $k, $GLOBALS['__opt'] ) ) { return false; }
		$GLOBALS['__opt'][ $k ]      = $v;
		$GLOBALS['__autoload'][ $k ] = $autoload;
		return true;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $k, $v, $autoload = null ) {
		$GLOBALS['__cnt']['update_option']++;
		$GLOBALS['__opt'][ $k ] = $v;
		if ( null !== $autoload ) { $GLOBALS['__autoload'][ $k ] = ( false === $autoload ? 'no' : ( true === $autoload ? 'yes' : $autoload ) ); }
		return true;
	}
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $k ) { $GLOBALS['__cnt']['delete_option']++; unset( $GLOBALS['__opt'][ $k ], $GLOBALS['__autoload'][ $k ] ); return true; }
}
if ( ! function_exists( 'get_transient' ) ) { function get_transient( $k ) { return $GLOBALS['__opt'][ '_t_' . $k ] ?? false; } }
if ( ! function_exists( 'set_transient' ) ) { function set_transient( $k, $v, $t = 0 ) { $GLOBALS['__opt'][ '_t_' . $k ] = $v; return true; } }
if ( ! function_exists( 'delete_transient' ) ) { function delete_transient( $k ) { unset( $GLOBALS['__opt'][ '_t_' . $k ] ); return true; } }

// --- posty ---
if ( ! function_exists( 'get_post' ) ) {
	function get_post( $id = null, $out = OBJECT ) {
		$GLOBALS['__cnt']['get_post']++;
		$id = (int) ( is_object( $id ) ? ( $id->ID ?? 0 ) : $id );
		return $GLOBALS['__posts'][ $id ] ?? null;
	}
}
if ( ! function_exists( 'get_post_status' ) ) {
	function get_post_status( $id = null ) { $p = get_post( $id ); return $p ? $p->post_status : false; }
}
if ( ! function_exists( 'get_post_type' ) ) {
	function get_post_type( $id = null ) { $p = get_post( $id ); return $p ? $p->post_type : false; }
}
if ( ! function_exists( 'get_permalink' ) ) { function get_permalink( $id = 0 ) { return 'http://test.local/?p=' . (int) $id; } }
if ( ! function_exists( 'get_edit_post_link' ) ) { function get_edit_post_link( $id = 0, $c = 'display' ) { return 'http://test.local/wp-admin/post.php?post=' . (int) $id; } }
if ( ! function_exists( 'get_page_by_path' ) ) { function get_page_by_path( $p, $o = OBJECT, $t = 'page' ) { return null; } }
if ( ! function_exists( 'wp_insert_post' ) ) {
	function wp_insert_post( $data, $wp_error = false ) {
		$id = $GLOBALS['__next_item_id']++;
		$GLOBALS['__posts'][ $id ] = new WP_Post(
			array(
				'ID'          => $id,
				'post_type'   => (string) ( $data['post_type'] ?? 'page' ),
				'post_status' => (string) ( $data['post_status'] ?? 'publish' ),
				'post_name'   => (string) ( $data['post_name'] ?? '' ),
				'post_title'  => (string) ( $data['post_title'] ?? '' ),
			)
		);
		return $id;
	}
}
if ( ! function_exists( 'wp_update_post' ) ) { function wp_update_post( $data, $wp_error = false ) { return (int) ( is_array( $data ) ? ( $data['ID'] ?? 0 ) : 0 ); } }
if ( ! function_exists( 'wp_delete_post' ) ) {
	function wp_delete_post( $id = 0, $force = false ) {
		$GLOBALS['__cnt']['wp_delete_post']++;
		$GLOBALS['__deleted_posts'][] = array( 'id' => (int) $id, 'force' => (bool) $force );
		$p = $GLOBALS['__posts'][ (int) $id ] ?? null;
		unset( $GLOBALS['__posts'][ (int) $id ] );
		foreach ( $GLOBALS['__menu_items'] as $m => $items ) {
			foreach ( $items as $i => $it ) {
				if ( (int) $it->ID === (int) $id ) { unset( $GLOBALS['__menu_items'][ $m ][ $i ] ); }
			}
			$GLOBALS['__menu_items'][ $m ] = array_values( $GLOBALS['__menu_items'][ $m ] );
		}
		return $p ? $p : false;
	}
}

// --- NAWIGACJA. wp_create_nav_menu i set_theme_mod są ZAKAZANE (§3.3 pkt 1, decyzja usera nr 1):
// --- podnoszą flagę, której sekcja C wymaga jako `false`. Złamanie kasuje nawigację klienta.
if ( ! function_exists( 'wp_create_nav_menu' ) ) {
	function wp_create_nav_menu( $name ) { $GLOBALS['__forbidden']['wp_create_nav_menu'] = true; return new WP_Error( 'zakaz', 'ZAKAZ ABSOLUTNY §3.3 pkt 1' ); }
}
if ( ! function_exists( 'set_theme_mod' ) ) {
	function set_theme_mod( $name, $value ) { $GLOBALS['__forbidden']['set_theme_mod'] = true; return false; }
}
if ( ! function_exists( 'get_theme_mod' ) ) { function get_theme_mod( $n, $d = false ) { return 'nav_menu_locations' === $n ? $GLOBALS['__nav_locations'] : $d; } }
if ( ! function_exists( 'get_registered_nav_menus' ) ) { function get_registered_nav_menus() { return (array) $GLOBALS['__nav_registered']; } }
if ( ! function_exists( 'get_nav_menu_locations' ) ) { function get_nav_menu_locations() { return (array) $GLOBALS['__nav_locations']; } }
if ( ! function_exists( 'has_nav_menu' ) ) { function has_nav_menu( $loc ) { return ! empty( $GLOBALS['__nav_locations'][ $loc ] ); } }
if ( ! function_exists( 'wp_is_block_theme' ) ) { function wp_is_block_theme() { return (bool) $GLOBALS['__block_theme']; } }
if ( ! function_exists( 'wp_get_nav_menus' ) ) {
	function wp_get_nav_menus( $args = array() ) {
		$out = array();
		foreach ( array_keys( $GLOBALS['__menu_items'] ) as $id ) { $out[] = (object) array( 'term_id' => (int) $id, 'name' => 'Menu ' . $id, 'slug' => 'menu-' . $id ); }
		return $out;
	}
}
if ( ! function_exists( 'wp_get_nav_menu_object' ) ) {
	function wp_get_nav_menu_object( $menu ) {
		$id = (int) ( is_object( $menu ) ? ( $menu->term_id ?? 0 ) : $menu );
		if ( $id <= 0 ) { return false; }
		return (object) array( 'term_id' => $id, 'name' => 'Menu ' . $id, 'slug' => 'menu-' . $id );
	}
}
if ( ! function_exists( 'is_nav_menu' ) ) { function is_nav_menu( $menu ) { return (int) $menu > 0; } }
if ( ! function_exists( 'wp_get_nav_menu_items' ) ) {
	function wp_get_nav_menu_items( $menu, $args = array() ) {
		$id = (int) ( is_object( $menu ) ? ( $menu->term_id ?? 0 ) : $menu );
		return isset( $GLOBALS['__menu_items'][ $id ] ) ? array_values( $GLOBALS['__menu_items'][ $id ] ) : array();
	}
}
/**
 * Wierne odwzorowanie FZ5: rdzeń tworzy pozycję domyślnie jako `draft`,
 * a `wp_get_nav_menu_items()` filtruje po `post_status = 'publish'`. Pozycja bez
 * `menu-item-status => 'publish'` NIE staje się widoczna — i o to w tej mutacji chodzi.
 */
if ( ! function_exists( 'wp_update_nav_menu_item' ) ) {
	function wp_update_nav_menu_item( $menu_id = 0, $menu_item_db_id = 0, $menu_item_data = array(), $fire_after = true ) {
		$GLOBALS['__cnt']['wp_update_nav_menu_item']++;
		$GLOBALS['__nav_item_args'][] = array( 'menu' => (int) $menu_id, 'item' => (int) $menu_item_db_id, 'data' => (array) $menu_item_data );
		if ( $GLOBALS['__nav_item_error'] ) { return new WP_Error( 'nav_fail', 'nie udalo sie utworzyc pozycji' ); }
		$id     = $menu_item_db_id > 0 ? (int) $menu_item_db_id : $GLOBALS['__next_item_id']++;
		$status = (string) ( $menu_item_data['menu-item-status'] ?? 'draft' );
		$item   = new WP_Post(
			array(
				'ID'          => $id,
				'db_id'       => $id,
				'post_type'   => 'nav_menu_item',
				'post_status' => $status,
				'object_id'   => (int) ( $menu_item_data['menu-item-object-id'] ?? 0 ),
				'object'      => (string) ( $menu_item_data['menu-item-object'] ?? '' ),
				'type'        => (string) ( $menu_item_data['menu-item-type'] ?? '' ),
				'title'       => (string) ( $menu_item_data['menu-item-title'] ?? '' ),
				'post_title'  => (string) ( $menu_item_data['menu-item-title'] ?? '' ),
			)
		);
		$GLOBALS['__posts'][ $id ] = $item;
		if ( 'publish' === $status ) {
			if ( ! isset( $GLOBALS['__menu_items'][ (int) $menu_id ] ) ) { $GLOBALS['__menu_items'][ (int) $menu_id ] = array(); }
			$GLOBALS['__menu_items'][ (int) $menu_id ][] = $item;
		}
		return $id;
	}
}

// --- uprawnienia i użytkownik (atrapa CAP-ŚWIADOMA — §11.5 piętnuje atrapy ignorujące $cap) ---
if ( ! function_exists( 'current_user_can' ) ) { function current_user_can( $c ) { return in_array( (string) $c, (array) $GLOBALS['__caps'], true ); } }
if ( ! function_exists( 'is_user_logged_in' ) ) { function is_user_logged_in() { return (int) $GLOBALS['__uid'] > 0; } }
if ( ! function_exists( 'get_current_user_id' ) ) { function get_current_user_id() { return (int) $GLOBALS['__uid']; } }
if ( ! function_exists( 'get_user_meta' ) ) {
	function get_user_meta( $uid, $key = '', $single = false ) {
		$v = $GLOBALS['__usermeta'][ (int) $uid ][ $key ] ?? ( $single ? '' : array() );
		return $v;
	}
}
if ( ! function_exists( 'update_user_meta' ) ) {
	function update_user_meta( $uid, $key, $val, $prev = '' ) {
		$GLOBALS['__cnt']['update_user_meta']++;
		$GLOBALS['__usermeta'][ (int) $uid ][ $key ] = $val;
		return true;
	}
}
if ( ! function_exists( 'delete_user_meta' ) ) { function delete_user_meta( $uid, $key, $val = '' ) { unset( $GLOBALS['__usermeta'][ (int) $uid ][ $key ] ); return true; } }

// --- ekran kokpitu (O-23: EditorNotice rozpoznaje ekran po WP_Screen, nie po $hook_suffix) ---
if ( ! function_exists( 'get_current_screen' ) ) {
	function get_current_screen() {
		if ( null === $GLOBALS['__screen'] ) { return null; }
		return (object) array(
			'id'        => (string) $GLOBALS['__screen'],
			'base'      => (string) $GLOBALS['__screen_base'],
			'post_type' => (string) $GLOBALS['__screen_ptype'],
		);
	}
}

// --- nonce / przekierowania. wp_die BEZ `exit` => kod produkcyjny MUSI mieć jawny `return`. ---
if ( ! function_exists( 'wp_nonce_url' ) ) {
	function wp_nonce_url( $url, $action = -1, $name = '_wpnonce' ) {
		return $url . ( false === strpos( (string) $url, '?' ) ? '?' : '&' ) . '_wpnonce=TESTNONCE';
	}
}
if ( ! function_exists( 'wp_create_nonce' ) ) { function wp_create_nonce( $a = '' ) { return 'TESTNONCE'; } }
/**
 * Wyjątek udający ZAKOŃCZENIE ŻĄDANIA przez rdzeń.
 *
 * `check_admin_referer()` w prawdziwym WordPressie woła `wp_die()`, które **kończy proces** —
 * wzorzec z §4.3 (i `PageNotice::handle_fix()`) nie sprawdza jego wartości zwracanej i nie ma
 * po nim `return`. Atrapa zwracająca `false` testowałaby więc kod, którego w WordPressie nie ma,
 * i kazałaby poprawnej implementacji wyglądać na dziurawą. Dla braku CAPA jest odwrotnie:
 * tam wzorzec MA jawny `return`, więc `wp_die()` niżej celowo NIE przerywa wykonania.
 */
if ( ! class_exists( 'K20MenuDie' ) ) {
	class K20MenuDie extends \RuntimeException {}
}
if ( ! function_exists( 'check_admin_referer' ) ) {
	function check_admin_referer( $action = -1, $name = '_wpnonce' ) {
		$GLOBALS['__cnt']['check_admin_referer']++;
		$GLOBALS['__referer_calls'][] = (string) $action;
		if ( ! $GLOBALS['__nonce_ok'] ) {
			$GLOBALS['__cnt']['wp_die']++;
			$GLOBALS['__died'][] = 'nonce';
			throw new K20MenuDie( 'wp_die: nieprawidlowy nonce' );
		}
		return true;
	}
}
if ( ! function_exists( 'wp_verify_nonce' ) ) { function wp_verify_nonce( $n, $a = -1 ) { return $GLOBALS['__nonce_ok'] ? 1 : false; } }
if ( ! function_exists( 'wp_die' ) ) { function wp_die( $m = '', $t = '', $a = array() ) { $GLOBALS['__cnt']['wp_die']++; $GLOBALS['__died'][] = (string) $m; } }
if ( ! function_exists( 'wp_safe_redirect' ) ) { function wp_safe_redirect( $l, $s = 302 ) { $GLOBALS['__cnt']['wp_safe_redirect']++; $GLOBALS['__redirect'][] = (string) $l; return true; } }
if ( ! function_exists( 'wp_get_referer' ) ) { function wp_get_referer() { return 'http://test.local/wp-admin/index.php'; } }

if ( ! class_exists( 'FakeWpdb' ) ) {
	class FakeWpdb {
		public $prefix = 'wp_';
		public $insert_id = 0;
		public function get_charset_collate() { return ''; }
		public function prepare( $q, ...$a ) { return $q; }
		public function get_row( $q, $o = null ) { return null; }
		public function get_var( $q ) { return 0; }
		public function get_col( $q, $x = 0 ) { return array(); }
		public function get_results( $q, $o = null ) { return array(); }
		public function query( $q ) { return 0; }
		public function insert( $t, $d, $f = null ) { $this->insert_id = 1; return 1; }
		public function update( $t, $d, $w, $f = null, $wf = null ) { return 1; }
		public function delete( $t, $w, $f = null ) { return 1; }
	}
}
$GLOBALS['wpdb'] = new FakeWpdb();
if ( ! function_exists( 'dbDelta' ) ) { function dbDelta( $q, $exec = true ) { return array(); } }

// ---------------------------------------------------------------------------
// Harness.
// ---------------------------------------------------------------------------
$fail = 0;
$ran  = 0;
function check( $cond, $label ) {
	global $fail, $ran;
	$ran++;
	echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n";
	if ( ! $cond ) { $fail++; }
}
function skip( $n, $label ) { for ( $i = 0; $i < $n; $i++ ) { check( false, $label ); } }

// ---------------------------------------------------------------------------
// Ładowanie kodu. Mini-autoloader PSR-4-lite `AIFAQ\` => `src/` — ten sam układ,
// co autoloader wtyczki. Powód użycia zamiast łańcucha `require_once`: klasy Kroku 20
// sięgają po siebie nawzajem pod `class_exists`, a brakujący plik ma dać FAIL asercji,
// nie PHP Fatal w połowie zestawu.
// ---------------------------------------------------------------------------
spl_autoload_register(
	function ( $class ) {
		if ( 0 !== strpos( $class, 'AIFAQ\\' ) ) { return; }
		$rel  = str_replace( '\\', '/', substr( $class, 6 ) );
		$path = __DIR__ . '/../src/' . $rel . '.php';
		if ( file_exists( $path ) ) { require_once $path; }
	}
);

$MG = 'AIFAQ\PublicUi\MenuGuard';
$MN = 'AIFAQ\Admin\MenuNotice';
$EN = 'AIFAQ\Admin\EditorNotice';
$ST = 'AIFAQ\Core\Settings';

$has_mg = class_exists( $MG );
$has_mn = class_exists( $MN );
$has_en = class_exists( $EN );
$has_st = class_exists( $ST );

// ---------------------------------------------------------------------------
// Scenariusze — jedno miejsce budowy środowiska.
// ---------------------------------------------------------------------------

/** Ustawienia wtyczki: defaults() + nadpisania (klucze §2 tworzy E3). */
function k20m_settings( $over = array() ) {
	$base = class_exists( 'AIFAQ\Core\Settings' ) && method_exists( 'AIFAQ\Core\Settings', 'defaults' )
		? (array) \AIFAQ\Core\Settings::defaults()
		: array( 'menu_link_enabled' => '1', 'menu_location' => '', 'menu_label' => 'Generator FAQ' );
	return array_merge( $base, $over );
}

/**
 * Pełny reset środowiska.
 *
 * @param array $cfg registered/locations/items/block/page/settings/opts
 */
function k20m_env( $cfg = array() ) {
	$cfg = array_merge(
		array(
			'registered' => array( 'primary' => 'Główne', 'footer' => 'Stopka' ),
			'locations'  => array( 'primary' => 10 ),
			'items'      => array(),
			'block'      => false,
			'page'       => K20_PAGE_ID,
			'page_status' => 'publish',
			'settings'   => array(),
			'opts'       => array(),
			'fail_item'  => false,
		),
		$cfg
	);

	$GLOBALS['__posts']          = array();
	$GLOBALS['__menu_items']     = $cfg['items'];
	$GLOBALS['__nav_registered'] = $cfg['registered'];
	$GLOBALS['__nav_locations']  = $cfg['locations'];
	$GLOBALS['__block_theme']    = (bool) $cfg['block'];
	$GLOBALS['__nav_item_error'] = (bool) $cfg['fail_item'];
	$GLOBALS['__nav_item_args']  = array();
	$GLOBALS['__deleted_posts']  = array();
	$GLOBALS['__died']           = array();
	$GLOBALS['__redirect']       = array();
	$GLOBALS['__referer_calls']  = array();
	$GLOBALS['__nonce_ok']       = true;
	$GLOBALS['__caps']           = array( 'manage_options', 'publish_posts', 'edit_posts', 'edit_published_posts' );
	$GLOBALS['__uid']            = 7;
	$GLOBALS['__usermeta']       = array();
	$GLOBALS['__screen']         = 'dashboard';
	$GLOBALS['__screen_base']    = 'dashboard';
	$GLOBALS['__screen_ptype']   = '';
	$GLOBALS['__doing_ajax']     = false;
	$GLOBALS['__doing_cron']     = false;
	$GLOBALS['__next_item_id']   = 900;

	// Podstrona generatora.
	$opts = array( 'aifaq_settings' => k20m_settings( $cfg['settings'] ) );
	if ( $cfg['page'] > 0 ) {
		$GLOBALS['__posts'][ (int) $cfg['page'] ] = new WP_Post(
			array( 'ID' => (int) $cfg['page'], 'post_type' => 'page', 'post_status' => (string) $cfg['page_status'], 'post_name' => 'generator-faq' )
		);
		$opts['aifaq_page_id'] = (int) $cfg['page'];
		$opts['aifaq_page_ok'] = 'publish' === (string) $cfg['page_status'] ? '1' : '0';
	}
	$GLOBALS['__opt']      = array_merge( $opts, $cfg['opts'] );
	$GLOBALS['__autoload'] = array();

	// Pozycje menu podane wprost muszą też być odnajdywalne przez get_post().
	foreach ( $GLOBALS['__menu_items'] as $items ) {
		foreach ( $items as $it ) { $GLOBALS['__posts'][ (int) $it->ID ] = $it; }
	}
	k20m_cnt_reset();
}

/** Buduje pozycję menu wskazującą podstronę. */
function k20m_item( $id, $object_id = K20_PAGE_ID, $type = 'post_type', $object = 'page' ) {
	return new WP_Post(
		array(
			'ID' => (int) $id, 'db_id' => (int) $id, 'post_type' => 'nav_menu_item', 'post_status' => 'publish',
			'object_id' => (int) $object_id, 'object' => (string) $object, 'type' => (string) $type,
			'title' => 'Generator FAQ', 'post_title' => 'Generator FAQ',
		)
	);
}

function k20m_state() { return (array) \AIFAQ\PublicUi\MenuGuard::menu_state(); }
function k20m_ensure() { return (array) \AIFAQ\PublicUi\MenuGuard::ensure(); }
function k20m_gate() { return (string) get_option( 'aifaq_menu_ok', 'BRAK' ); }
function k20m_saved() { $s = get_option( 'aifaq_menu_state', array() ); return is_array( $s ) ? $s : array(); }
function k20m_creates() { return (int) $GLOBALS['__cnt']['wp_update_nav_menu_item']; }

/**
 * Cofa zapisane `last` o podaną liczbę sekund — tak testujemy backoff bez zakładania,
 * którym zegarem mierzy go implementacja (oba zegary w tym pliku są zrównane).
 */
function k20m_set_last( $seconds_ago ) {
	$s = k20m_saved();
	$s['last'] = (int) $GLOBALS['__now'] - (int) $seconds_ago;
	update_option( 'aifaq_menu_state', $s );
}

/** Nazwa parametru akcji — z publicznej stałej klasy; kontrakt §4.3 jej nie zamraża (O-106). */
function k20m_param( $class ) {
	if ( defined( $class . '::PARAM' ) ) { return (string) constant( $class . '::PARAM' ); }
	return 'aifaq_fix';
}

/**
 * Buduje żądanie do handlera `admin_post_*`. Wzorzec z §4.3 czyta `$_GET`, więc wypełniamy
 * `$_GET` ORAZ `$_REQUEST`, a wartość wkładamy pod stałą klasy i pod nazwę wzorcową.
 */
function k20m_request( $class, $value ) {
	$p              = k20m_param( $class );
	$req            = array( $p => $value, 'aifaq_fix' => $value, '_wpnonce' => 'TESTNONCE' );
	$_GET           = $req;
	$_POST          = array();
	$_REQUEST       = $req;
}
/** Zapisy łącznie — do asercji „zero zapisów". */
function k20m_writes() { return (int) $GLOBALS['__cnt']['update_option'] + (int) $GLOBALS['__cnt']['delete_option'] + (int) $GLOBALS['__cnt']['add_option']; }

// ===========================================================================
echo "=== A. API i kształt (§3.1) ===\n";
if ( $has_mg ) {
	foreach ( array( 'menu_state', 'ensure', 'maybe_ensure', 'repair', 'remove' ) as $m ) {
		$ok = method_exists( $MG, $m );
		if ( $ok ) {
			$rm = new ReflectionMethod( $MG, $m );
			$ok = $rm->isPublic() && $rm->isStatic();
		}
		check( $ok, "MenuGuard::$m() istnieje i jest publiczną metodą statyczną (§3.1)" );
	}

	k20m_env();
	$s    = k20m_state();
	$keys = array( 'state', 'item_id', 'owned', 'location', 'menu_id', 'label', 'tries', 'last', 'error' );
	check( 9 === count( $s ), 'menu_state() zwraca DOKŁADNIE dziewięć kluczy (było ' . count( $s ) . ')' );
	check( array() === array_diff( $keys, array_keys( $s ) ), 'menu_state(): żaden z dziewięciu nazwanych kluczy nie brakuje' );
	check( array() === array_diff( array_keys( $s ), $keys ), 'menu_state(): zero kluczy NADMIAROWYCH' );

	k20m_env();
	k20m_cnt_reset();
	k20m_state();
	check( 0 === k20m_writes(), 'menu_state() to CZYSTA diagnoza — zero zapisów do opcji (§3.1)' );
	check( 0 === k20m_creates(), 'menu_state() nie tworzy pozycji menu' );

	$e = k20m_ensure();
	check( 9 === count( $e ), 'ensure() zwraca ten sam kształt — dziewięć kluczy (§3.1)' );
	unset( $s, $e, $keys, $rm, $ok );
} else {
	skip( 10, 'sekcja A pominięta — brak klasy MenuGuard' );
}

// ===========================================================================
echo "\n=== B. Dziesięć stanów i bramka aifaq_menu_ok (§3.2, FZ3, FZ4, FZ14, O-11) ===\n";
if ( $has_mg ) {
	$seen = array();

	// --- disabled (przełącznik właściciela; O-11: wygrywa nad wszystkim) ---
	k20m_env( array( 'settings' => array( 'menu_link_enabled' => '0' ) ) );
	$s = k20m_ensure();
	$seen[] = $s['state'];
	check( 'disabled' === $s['state'], "menu_link_enabled '0' → stan `disabled`" );
	check( '0' === k20m_gate(), '`disabled` zamyka bramkę na `0` (terminalny)' );

	// --- no_location: motyw blokowy MIMO zarejestrowanych lokalizacji (reguła zerowa FZ14) ---
	k20m_env( array( 'block' => true ) );
	$s = k20m_ensure();
	$seen[] = $s['state'];
	check( 'no_location' === $s['state'], 'motyw BLOKOWY → `no_location` niezależnie od get_registered_nav_menus() (FZ14)' );
	check( '' === k20m_gate(), '`no_location` NIE jest terminalny — bramka `` (FZ3)' );

	// --- no_location: motyw bez zarejestrowanych lokalizacji ---
	k20m_env( array( 'registered' => array(), 'locations' => array() ) );
	$s = k20m_ensure();
	check( 'no_location' === $s['state'], 'brak zarejestrowanych lokalizacji → `no_location`' );

	// --- no_menu: są lokalizacje, żadne menu nieprzypięte ---
	k20m_env( array( 'locations' => array() ) );
	$s = k20m_ensure();
	$seen[] = $s['state'];
	check( 'no_menu' === $s['state'], 'lokalizacje bez przypiętego menu → `no_menu`' );
	check( '' === k20m_gate(), '`no_menu` NIE jest terminalny — bramka `` (FZ3: inaczej odbiór §10 pkt 5 nieosiągalny)' );

	// --- location_ambiguous: menu przypięte, ale w lokalizacji spoza listy preferencji ---
	// UWAGA: klucze dobrane tak, by NIE kolidowały z listą preferencji przy dopasowaniu
	// `stripos` (§3.3 pkt 4). Polskie `stopka` zawiera `top` — patrz ODCHYLENIA O-107 i sekcja H.
	k20m_env(
		array(
			'registered' => array( 'social' => 'Social', 'footer' => 'Stopka' ),
			'locations'  => array( 'social' => 10, 'footer' => 11 ),
			'items'      => array( 10 => array(), 11 => array() ),
		)
	);
	$s = k20m_ensure();
	$seen[] = $s['state'];
	check( 'location_ambiguous' === $s['state'], 'menu tylko w `social`/`footer` → `location_ambiguous` (FZ12 — nie zgadujemy); było: ' . $s['state'] );
	check( '0' === k20m_gate(), '`location_ambiguous` zamyka bramkę na `0`' );

	// --- page_missing: brak podstrony ---
	k20m_env( array( 'page' => 0 ) );
	$s = k20m_ensure();
	$seen[] = $s['state'];
	check( 'page_missing' === $s['state'], 'brak `aifaq_page_id` → `page_missing`' );
	check( '0' === k20m_gate(), '`page_missing` JEST terminalny — bramka `0` (FZ4: kosz leży 30 dni)' );

	// --- page_missing: podstrona w koszu ---
	k20m_env( array( 'page_status' => 'trash' ) );
	$s = k20m_ensure();
	check( 'page_missing' === $s['state'], 'podstrona nieopublikowana (kosz) → `page_missing`' );

	// --- missing: wszystko gotowe, pozycji jeszcze nie ma (diagnoza przed próbą) ---
	k20m_env();
	$s = k20m_state();
	$seen[] = $s['state'];
	check( 'missing' === $s['state'], 'środowisko gotowe, brak pozycji → `missing`' );

	// --- ok: pozycja istnieje w przypiętym menu i wskazuje podstronę ---
	k20m_env( array( 'items' => array( 10 => array( k20m_item( 901 ) ) ), 'opts' => array( 'aifaq_menu_item_id' => 901, 'aifaq_menu_state' => array( 'state' => 'ok', 'item_id' => 901, 'owned' => '1', 'tries' => 0, 'last' => 0, 'error' => '' ) ) ) );
	$s = k20m_state();
	$seen[] = $s['state'];
	check( 'ok' === $s['state'], 'pozycja w przypiętym menu wskazująca podstronę → `ok`' );
	$s = k20m_ensure();
	check( '1' === k20m_gate(), '`ok` otwiera bramkę na `1`' );

	// --- menu_changed: pozycja żyje, ale w menu INNYM niż aktualnie przypięte (FZ11) ---
	k20m_env(
		array(
			'locations' => array( 'primary' => 20 ),
			'items'     => array( 10 => array( k20m_item( 901 ) ), 20 => array() ),
			'opts'      => array( 'aifaq_menu_item_id' => 901, 'aifaq_menu_state' => array( 'state' => 'ok', 'item_id' => 901, 'owned' => '1', 'menu_id' => 10, 'tries' => 0, 'last' => 0, 'error' => '' ) ),
		)
	);
	$s = k20m_state();
	$seen[] = $s['state'];
	check( 'menu_changed' === $s['state'], 'pozycja w menu innym niż przypięte → `menu_changed` (FZ11)' );
	check( '' === k20m_gate() || '1' !== k20m_gate(), '`menu_changed` NIE otwiera bramki na `1`' );

	// --- removed_by_user: zapisane ID, pozycji nie ma ---
	k20m_env( array( 'opts' => array( 'aifaq_menu_item_id' => 901, 'aifaq_menu_state' => array( 'state' => 'ok', 'item_id' => 901, 'owned' => '1', 'menu_id' => 10, 'tries' => 0, 'last' => 0, 'error' => '' ) ) ) );
	$s = k20m_state();
	$seen[] = $s['state'];
	check( 'removed_by_user' === $s['state'], 'zapisane ID + get_post() === null → `removed_by_user` (FZ11)' );
	k20m_ensure();
	check( '0' === k20m_gate(), '`removed_by_user` zamyka bramkę na `0`' );

	// --- failed: próba utworzenia padła ---
	k20m_env( array( 'fail_item' => true ) );
	$s = k20m_ensure();
	$seen[] = $s['state'];
	check( 'failed' === $s['state'], 'nieudane wp_update_nav_menu_item() → `failed`' );
	check( '' === k20m_gate(), '`failed` przy tries < 3 NIE zamyka bramki' );

	// --- failed po trzech próbach: bramka na `0` ---
	k20m_env( array( 'fail_item' => true ) );
	for ( $i = 0; $i < 3; $i++ ) {
		k20m_ensure();
		k20m_set_last( 21601 );   // cofamy `last`, żeby backoff 6 h nie blokował kolejnej próby
	}
	check( 3 === (int) ( k20m_saved()['tries'] ?? -1 ), 'trzy nieudane próby → `tries` === 3 (było ' . (int) ( k20m_saved()['tries'] ?? -1 ) . ')' );
	check( '0' === k20m_gate(), 'po TRZECH porażkach bramka zamyka się na `0` (poddajemy się do decyzji właściciela)' );

	$uniq = array_values( array_unique( $seen ) );
	check( 10 === count( $uniq ), 'osiągnięto DZIESIĘĆ różnych literałów stanu (było ' . count( $uniq ) . ': ' . implode( ',', $uniq ) . ')' );

	unset( $s, $seen, $uniq, $i );
} else {
	skip( 24, 'sekcja B pominięta — brak klasy MenuGuard' );
}

// ===========================================================================
echo "\n=== C. ZAKAZ ABSOLUTNY (§3.3 pkt 1, decyzja usera nr 1) ===\n";
check( false === $GLOBALS['__forbidden']['wp_create_nav_menu'], 'w ŻADNYM scenariuszu nie padło wp_create_nav_menu() — najważniejsza asercja pliku' );
check( false === $GLOBALS['__forbidden']['set_theme_mod'], 'w ŻADNYM scenariuszu nie padło set_theme_mod() — nawigacja klienta nietknięta' );

// ===========================================================================
echo "\n=== D. Flaga PO próbie, nigdy PRZED (§3.3 pkt 2) ===\n";
if ( $has_mg ) {
	// Porażka: stan zapisany PO próbie musi nieść ślad porażki, a bramka nie może stać na `1`.
	k20m_env( array( 'fail_item' => true ) );
	$s = k20m_ensure();
	check( 1 === (int) ( $s['tries'] ?? -1 ), 'po JEDNEJ nieudanej próbie `tries` === 1 (licznik rośnie PO próbie)' );
	check( '' !== (string) ( $s['error'] ?? '' ), 'nieudana próba zostawia komunikat błędu w stanie' );
	check( 1 === (int) ( k20m_saved()['tries'] ?? -1 ), 'ślad porażki jest ZAPISANY w aifaq_menu_state (zapis PO próbie)' );
	check( '1' !== k20m_gate(), 'po porażce bramka NIE stoi na `1` (mutacja „flaga PRZED próbą bez korekty po niej" musi tu czerwienić)' );
	check( 0 === (int) ( $s['item_id'] ?? -1 ), 'nieudana próba nie zapisuje item_id' );

	// Sukces.
	k20m_env();
	$s = k20m_ensure();
	check( 'ok' === $s['state'], 'udana próba → stan `ok`' );
	check( (int) ( $s['item_id'] ?? 0 ) > 0, 'udana próba zapisuje item_id' );
	check( 0 === (int) ( $s['tries'] ?? -1 ), 'po sukcesie `tries` === 0' );
	check( '1' === k20m_gate(), 'po sukcesie bramka === `1`' );
	check( '1' === (string) ( $s['owned'] ?? '' ), "pozycja utworzona przez wtyczkę ma owned === '1' (FZ7)" );
	check( (int) get_option( 'aifaq_menu_item_id', 0 ) === (int) $s['item_id'], 'ID pozycji zapisane w opcji aifaq_menu_item_id' );

	// FZ5 — argumenty tworzenia ZAMROŻONE.
	$args = $GLOBALS['__nav_item_args'][0]['data'] ?? array();
	check( 'publish' === (string) ( $args['menu-item-status'] ?? '' ), "menu-item-status === 'publish' — OBOWIĄZKOWE (FZ5: draft = cichy fałszywy sukces)" );
	check( K20_PAGE_ID === (int) ( $args['menu-item-object-id'] ?? 0 ), 'menu-item-object-id wskazuje aifaq_page_id' );
	check( 'page' === (string) ( $args['menu-item-object'] ?? '' ), "menu-item-object === 'page'" );
	check( 'post_type' === (string) ( $args['menu-item-type'] ?? '' ), "menu-item-type === 'post_type'" );
	check( 'Generator FAQ' === (string) ( $args['menu-item-title'] ?? '' ), 'menu-item-title bierze się z ustawienia menu_label' );

	// Etykieta z ustawień idzie do pozycji.
	k20m_env( array( 'settings' => array( 'menu_label' => 'Zapytaj o FAQ' ) ) );
	k20m_ensure();
	$args = $GLOBALS['__nav_item_args'][0]['data'] ?? array();
	check( 'Zapytaj o FAQ' === (string) ( $args['menu-item-title'] ?? '' ), 'zmiana menu_label zmienia tytuł tworzonej pozycji' );

	unset( $s, $args );
} else {
	skip( 17, 'sekcja D pominięta — brak klasy MenuGuard' );
}

// ===========================================================================
echo "\n=== E. Idempotencja i proweniencja (§3.3 pkt 5, FZ7) ===\n";
if ( $has_mg ) {
	// W menu JEST już pozycja wskazująca podstronę — dodana ręką klienta.
	k20m_env( array( 'items' => array( 10 => array( k20m_item( 555 ) ) ) ) );
	$s = k20m_ensure();
	check( 0 === k20m_creates(), 'istniejąca pozycja wskazująca podstronę → ensure() NIE tworzy drugiej' );
	check( 555 === (int) ( $s['item_id'] ?? 0 ), 'ensure() zapisuje ID pozycji ISTNIEJĄCEJ' );
	check( '0' === (string) ( $s['owned'] ?? '' ), "pozycja zaadoptowana ma owned === '0' (FZ7 — to treść klienta)" );
	check( 'ok' === $s['state'], 'adopcja daje stan `ok`' );

	// Trzykrotne ensure() na czystym środowisku = dokładnie JEDNA pozycja.
	k20m_env();
	k20m_ensure();
	k20m_ensure();
	k20m_ensure();
	check( 1 === k20m_creates(), 'trzy wywołania ensure() tworzą DOKŁADNIE jedną pozycję' );
	check( 1 === count( $GLOBALS['__menu_items'][10] ), 'w menu jest DOKŁADNIE jedna pozycja generatora' );

	unset( $s );
} else {
	skip( 6, 'sekcja E pominięta — brak klasy MenuGuard' );
}

// ===========================================================================
echo "\n=== F. removed_by_user i whitelista repair() (§3.3 pkt 3, §3.1, FZ10, O-15) ===\n";
if ( $has_mg ) {
	$removed = array( 'aifaq_menu_item_id' => 901, 'aifaq_menu_state' => array( 'state' => 'removed_by_user', 'item_id' => 901, 'owned' => '1', 'menu_id' => 10, 'tries' => 0, 'last' => 0, 'error' => '' ) );

	k20m_env( array( 'opts' => $removed ) );
	$s = k20m_ensure();
	check( 'removed_by_user' === $s['state'], 'ręcznie usunięta pozycja → `removed_by_user`' );
	check( 0 === k20m_creates(), 'automat NIE wskrzesza ręcznie usuniętej pozycji' );
	k20m_ensure();
	k20m_ensure();
	check( 0 === k20m_creates(), 'kolejne wywołania ensure() też jej nie wskrzeszają (stan jednokierunkowy)' );

	// Wyjście WYŁĄCZNIE przez repair( 'create' ).
	k20m_cnt_reset();
	$r = (array) \AIFAQ\PublicUi\MenuGuard::repair( 'create' );
	check( 1 === k20m_creates(), "repair( 'create' ) jest JEDYNYM wyjściem z removed_by_user (prób: " . k20m_creates() . ", stan: " . (string) ( $r['state'] ?? '?' ) . ', błąd: ' . (string) ( $r['error'] ?? '' ) . ')' );
	check( 'ok' === (string) ( $r['state'] ?? '' ), "repair( 'create' ) kończy się stanem `ok` (było: " . (string) ( $r['state'] ?? '?' ) . ')' );
	check( 0 === (int) ( $r['tries'] ?? -1 ), "repair( 'create' ) zeruje licznik prób" );

	// repair( 'dismiss' ) — NIE ZAPISUJE NICZEGO (FZ10).
	k20m_env( array( 'opts' => $removed ) );
	k20m_cnt_reset();
	$r = (array) \AIFAQ\PublicUi\MenuGuard::repair( 'dismiss' );
	check( 0 === k20m_writes(), "repair( 'dismiss' ) nie zapisuje NICZEGO (FZ10 — wyciszenie należy do MenuNotice)" );
	check( 'removed_by_user' === (string) ( $r['state'] ?? '' ), "repair( 'dismiss' ) zwraca bieżący menu_state()" );

	// Whitelista ZAMKNIĘTA.
	k20m_env( array( 'opts' => $removed ) );
	k20m_cnt_reset();
	$r = (array) \AIFAQ\PublicUi\MenuGuard::repair( 'cokolwiek_innego' );
	check( 'failed' === (string) ( $r['state'] ?? '' ), "repair( 'cokolwiek_innego' ) → state `failed`" );
	check( 'unknown_action' === (string) ( $r['error'] ?? '' ), "repair( 'cokolwiek_innego' ) → error `unknown_action`" );
	check( 0 === k20m_writes(), 'nieznana akcja repair() → ZERO zapisów' );
	check( 0 === k20m_creates(), 'nieznana akcja repair() → zero prób utworzenia pozycji' );
	// O-15: dla nieznanej akcji kontrakt podaje literał DWUELEMENTOWY — nie żądamy dziewięciu kluczy.
	check( 2 === count( $r ), 'nieznana akcja zwraca dokładnie dwa klucze: state + error (O-15)' );

	// §13.2 — żywa pozycja BIJE zapisany removed_by_user (idempotencja jest silniejsza).
	k20m_env( array( 'items' => array( 10 => array( k20m_item( 901 ) ) ), 'opts' => $removed ) );
	$s = k20m_state();
	check( 'ok' === $s['state'], 'żywa pozycja wskazująca podstronę BIJE zapisany `removed_by_user` (§13.2)' );

	unset( $s, $r, $removed );
} else {
	skip( 15, 'sekcja F pominięta — brak klasy MenuGuard' );
}

// ===========================================================================
echo "\n=== G. Limit prób i backoff 6 h (§3.3 pkt 5, O-12) ===\n";
if ( $has_mg ) {
	// Czwarta próba po trzech porażkach — nie próbujemy w ogóle.
	k20m_env( array( 'fail_item' => true ) );
	for ( $i = 0; $i < 3; $i++ ) { k20m_ensure(); k20m_set_last( 21601 ); }
	check( 3 === k20m_creates(), 'trzy porażki = trzy realne próby (było ' . k20m_creates() . ')' );
	k20m_cnt_reset();
	k20m_ensure();
	check( 0 === k20m_creates(), 'CZWARTE wywołanie po trzech porażkach NIE próbuje ponownie (MAX_TRIES = 3)' );

	// Backoff: próba przed upływem 6 h od `last`.
	k20m_env( array( 'fail_item' => true ) );
	k20m_ensure();
	check( 1 === k20m_creates(), 'pierwsza próba wykonana' );
	k20m_set_last( 100 );   // dużo mniej niż 21600 s
	k20m_cnt_reset();
	k20m_ensure();
	check( 0 === k20m_creates(), 'próba przed upływem 6 h od `last` NIE jest podejmowana (odstęp >= 21600 s)' );
	k20m_set_last( 21601 );
	k20m_cnt_reset();
	k20m_ensure();
	check( 1 === k20m_creates(), 'po upływie 6 h próba jest podejmowana ponownie (było ' . k20m_creates() . ')' );

	// O-12: backoff należy do `failed`, NIE do `menu_changed`. Klient przepiął nawigację —
	// pozycja ma trafić do nowego menu NATYCHMIAST, mimo świeżego `last`.
	k20m_env(
		array(
			'locations' => array( 'primary' => 20 ),
			'items'     => array( 10 => array( k20m_item( 901 ) ), 20 => array() ),
			'opts'      => array(
				'aifaq_menu_item_id' => 901,
				'aifaq_menu_state'   => array( 'state' => 'ok', 'item_id' => 901, 'owned' => '1', 'menu_id' => 10, 'tries' => 0, 'last' => (int) $GLOBALS['__now'], 'error' => '' ),
			),
		)
	);
	$s = k20m_ensure();
	check( 1 === k20m_creates(), '`menu_changed` przy tries === 0 NIE czeka na backoff — pozycja idzie do nowego menu od razu (O-12)' );
	check( 'ok' === $s['state'], 'po dołożeniu do nowego menu stan wraca do `ok`' );
	check( 20 === (int) ( $s['menu_id'] ?? 0 ), 'stan zapisuje menu_id AKTUALNIE przypiętego menu' );

	unset( $s, $i );
} else {
	skip( 9, 'sekcja G pominięta — brak klasy MenuGuard' );
}

// ===========================================================================
echo "\n=== H. Wybór lokalizacji — lista preferencji (§3.3 pkt 4, FZ12, §13.3) ===\n";
if ( $has_mg ) {
	// primary wygrywa, choć `footer` też ma menu i stoi wcześniej w tablicy.
	k20m_env(
		array(
			'registered' => array( 'footer' => 'Stopka', 'primary' => 'Główne' ),
			'locations'  => array( 'footer' => 11, 'primary' => 10 ),
			'items'      => array( 10 => array(), 11 => array() ),
		)
	);
	$s = k20m_ensure();
	check( 'primary' === (string) ( $s['location'] ?? '' ), '`primary` wygrywa, mimo że `footer` stoi wcześniej w tablicy lokalizacji' );
	check( 10 === (int) ( $s['menu_id'] ?? 0 ), 'pozycja trafia do menu przypiętego do `primary`' );

	// Brak primary → kolejna z listy preferencji (main przed header).
	k20m_env(
		array(
			'registered' => array( 'header' => 'Nagłówek', 'main' => 'Główne' ),
			'locations'  => array( 'header' => 11, 'main' => 12 ),
			'items'      => array( 11 => array(), 12 => array() ),
		)
	);
	$s = k20m_ensure();
	check( 'main' === (string) ( $s['location'] ?? '' ), 'brak `primary` → `main` (kolejność listy preferencji, nie kolejność tablicy)' );

	// Zostaje sam `header`.
	k20m_env( array( 'registered' => array( 'header' => 'Nagłówek' ), 'locations' => array( 'header' => 11 ), 'items' => array( 11 => array() ) ) );
	$s = k20m_ensure();
	check( 'header' === (string) ( $s['location'] ?? '' ), 'zostaje sam `header` → wybrany jako trzeci z listy preferencji' );

	// Żadna nie pasuje → location_ambiguous (NIE „pierwsza z brzegu").
	k20m_env( array( 'registered' => array( 'social' => 'Social' ), 'locations' => array( 'social' => 11 ), 'items' => array( 11 => array() ) ) );
	$s = k20m_ensure();
	check( 'location_ambiguous' === $s['state'], 'żadna lokalizacja z listy preferencji → `location_ambiguous`, NIE „pierwsza z brzegu" (FZ12)' );
	check( 0 === k20m_creates(), 'przy `location_ambiguous` nie powstaje żadna pozycja (link nie ląduje w menu ikon)' );

	// ZNALEZISKO O-107 — PUŁAPKA CELOWA. §3.3 pkt 4 nakazuje dopasowanie przez `stripos`,
	// a polskie `stopka` ZAWIERA `top` (s-TOP-ka). Na motywie z polskimi kluczami lokalizacji
	// link ląduje w STOPCE — czyli dokładnie tam, przed czym miało bronić FZ12.
	// Asercja pilnuje stanu ZASTANEGO (zgodnego z kontraktem). Jeśli E10 zawęzi regułę
	// (dopasowanie dokładne albo po granicy słowa), ta asercja zaczerwieni się CELOWO —
	// to sygnał, że decyzja została podjęta świadomie, a nie że coś się zepsuło.
	k20m_env( array( 'registered' => array( 'stopka' => 'Stopka' ), 'locations' => array( 'stopka' => 11 ), 'items' => array( 11 => array() ) ) );
	$s = k20m_ensure();
	check( 'stopka' === (string) ( $s['location'] ?? '' ), 'O-107: `stopka` dopasowuje się do preferencji `top` przez stripos — stan zastany wg §3.3 pkt 4, do decyzji E10' );

	// Wskazanie klienta ma pierwszeństwo przed listą preferencji.
	k20m_env(
		array(
			'registered' => array( 'primary' => 'Główne', 'footer' => 'Stopka' ),
			'locations'  => array( 'primary' => 10, 'footer' => 11 ),
			'items'      => array( 10 => array(), 11 => array() ),
			'settings'   => array( 'menu_location' => 'footer' ),
		)
	);
	$s = k20m_ensure();
	check( 'footer' === (string) ( $s['location'] ?? '' ), 'jawne menu_location wygrywa z listą preferencji' );
	check( 11 === (int) ( $s['menu_id'] ?? 0 ), 'pozycja trafia do menu wskazanej lokalizacji' );

	// §13.3 — wskazana lokalizacja BEZ przypiętego menu → no_menu, bez cichej podmiany.
	k20m_env(
		array(
			'registered' => array( 'primary' => 'Główne', 'footer' => 'Stopka' ),
			'locations'  => array( 'primary' => 10 ),
			'items'      => array( 10 => array() ),
			'settings'   => array( 'menu_location' => 'footer' ),
		)
	);
	$s = k20m_ensure();
	check( 'no_menu' === $s['state'], '§13.3: wskazana lokalizacja bez menu → `no_menu`' );
	check( 0 === k20m_creates(), '§13.3: NIE podmieniamy wyboru klienta po cichu na `primary`' );

	unset( $s );
} else {
	skip( 11, 'sekcja H pominięta — brak klasy MenuGuard' );
}

// ===========================================================================
echo "\n=== I. Cykl życia: aktywacja, tania bramka, deaktywacja (§3.4, §3.6, §13.4, §13.5) ===\n";
if ( $has_mg ) {
	// --- I1. Aktywacja bez menu NIE MOŻE paść (kryterium akceptacji 4). ---
	k20m_env( array( 'locations' => array() ) );
	$thrown = '';
	try {
		if ( class_exists( 'AIFAQ\Core\Activator' ) && method_exists( 'AIFAQ\Core\Activator', 'activate' ) ) {
			\AIFAQ\Core\Activator::activate();
		} else {
			$thrown = 'BRAK KLASY Activator';
		}
	} catch ( \Throwable $e ) {
		$thrown = get_class( $e ) . ': ' . $e->getMessage();
	}
	check( '' === $thrown, 'aktywacja bez przypiętego menu NIE rzuca wyjątku (' . ( '' === $thrown ? 'ok' : $thrown ) . ')' );
	check( false === $GLOBALS['__forbidden']['wp_create_nav_menu'], 'aktywacja bez menu nie próbuje utworzyć menu za klienta' );

	// --- I2. Kryterium akceptacji 5: menu_link_enabled='0' respektowane PRZY AKTYWACJI. ---
	k20m_env( array( 'settings' => array( 'menu_link_enabled' => '0' ) ) );
	try {
		if ( class_exists( 'AIFAQ\Core\Activator' ) ) { \AIFAQ\Core\Activator::activate(); }
	} catch ( \Throwable $e ) {
		echo '  [activate rzucilo] ' . $e->getMessage() . "\n";
	}
	check( 0 === k20m_creates(), "kryterium 5: aktywacja przy menu_link_enabled = '0' NIE tworzy pozycji" );

	// --- I3. Tania bramka maybe_ensure() — bramki wejściowe FZ6. ---
	k20m_env();
	$GLOBALS['__doing_ajax'] = true;
	k20m_cnt_reset();
	\AIFAQ\PublicUi\MenuGuard::maybe_ensure();
	check( 0 === k20m_creates() && 0 === k20m_writes(), 'maybe_ensure() w wp_doing_ajax() → natychmiastowe wyjście (FZ6: admin-ajax dla NIEZALOGOWANYCH)' );

	k20m_env();
	$GLOBALS['__doing_cron'] = true;
	k20m_cnt_reset();
	\AIFAQ\PublicUi\MenuGuard::maybe_ensure();
	check( 0 === k20m_creates(), 'maybe_ensure() w wp_doing_cron() → natychmiastowe wyjście (FZ6)' );

	k20m_env();
	$GLOBALS['__caps'] = array( 'read' );   // gość / subskrybent
	k20m_cnt_reset();
	\AIFAQ\PublicUi\MenuGuard::maybe_ensure();
	check( 0 === k20m_creates(), 'maybe_ensure() bez manage_options → natychmiastowe wyjście (FZ6)' );

	k20m_env();
	k20m_cnt_reset();
	\AIFAQ\PublicUi\MenuGuard::maybe_ensure();
	check( 1 === k20m_creates(), 'maybe_ensure() z manage_options na czystym środowisku tworzy pozycję (ścieżka aktualizacji plików)' );
	k20m_cnt_reset();
	\AIFAQ\PublicUi\MenuGuard::maybe_ensure();
	check( 0 === k20m_creates(), "maybe_ensure() przy bramce '1' wychodzi bez pracy (tania bramka §3.4 pkt 2)" );

	// --- I4. Deaktywacja: kasujemy WYŁĄCZNIE własną pozycję. ---
	k20m_env( array( 'items' => array( 10 => array( k20m_item( 901 ) ) ), 'opts' => array( 'aifaq_menu_item_id' => 901, 'aifaq_menu_ok' => '1', 'aifaq_menu_state' => array( 'state' => 'ok', 'item_id' => 901, 'owned' => '1', 'menu_id' => 10, 'tries' => 0, 'last' => 0, 'error' => '' ) ) ) );
	$ok = (bool) \AIFAQ\PublicUi\MenuGuard::remove();
	check( true === $ok, "remove() zwraca true po faktycznym usunięciu pozycji (owned '1')" );
	check( 1 === (int) $GLOBALS['__cnt']['wp_delete_post'], 'deaktywacja kasuje pozycję, gdy ID zgadza się z opcją' );
	check( 901 === (int) ( $GLOBALS['__deleted_posts'][0]['id'] ?? 0 ), 'skasowano DOKŁADNIE zapisaną pozycję' );
	check( false === array_key_exists( 'aifaq_menu_item_id', $GLOBALS['__opt'] ), 'po skasowaniu opcja aifaq_menu_item_id znika (§3.6)' );
	check( '1' !== k20m_gate(), 'po skasowaniu bramka aifaq_menu_ok wyczyszczona' );
	check( true === array_key_exists( 'aifaq_menu_state', $GLOBALS['__opt'] ), '§13.4: aifaq_menu_state PRZEŻYWA deaktywację' );

	// ID wskazujące NIEISTNIEJĄCĄ pozycję = no-op.
	k20m_env( array( 'opts' => array( 'aifaq_menu_item_id' => 4242, 'aifaq_menu_state' => array( 'state' => 'ok', 'item_id' => 4242, 'owned' => '1', 'menu_id' => 10, 'tries' => 0, 'last' => 0, 'error' => '' ) ) ) );
	$ok = (bool) \AIFAQ\PublicUi\MenuGuard::remove();
	check( false === $ok, 'remove() przy ID wskazującym nieistniejącą pozycję → false' );
	check( 0 === (int) $GLOBALS['__cnt']['wp_delete_post'], 'nieistniejąca pozycja → wp_delete_post() NIE jest wołane (no-op)' );

	// Pozycja ZAADOPTOWANA (owned '0') — cudza treść, nie kasujemy (FZ7).
	k20m_env( array( 'items' => array( 10 => array( k20m_item( 555 ) ) ), 'opts' => array( 'aifaq_menu_item_id' => 555, 'aifaq_menu_state' => array( 'state' => 'ok', 'item_id' => 555, 'owned' => '0', 'menu_id' => 10, 'tries' => 0, 'last' => 0, 'error' => '' ) ) ) );
	$ok = (bool) \AIFAQ\PublicUi\MenuGuard::remove();
	check( false === $ok, "remove() przy owned === '0' → false (pozycja klienta)" );
	check( 0 === (int) $GLOBALS['__cnt']['wp_delete_post'], 'zaadoptowanej pozycji NIE kasujemy (FZ7 — to treść klienta)' );

	// O-16: brak klucza `owned` znaczy „cudza".
	k20m_env( array( 'items' => array( 10 => array( k20m_item( 555 ) ) ), 'opts' => array( 'aifaq_menu_item_id' => 555, 'aifaq_menu_state' => array( 'state' => 'ok', 'item_id' => 555, 'menu_id' => 10, 'tries' => 0, 'last' => 0, 'error' => '' ) ) ) );
	$ok = (bool) \AIFAQ\PublicUi\MenuGuard::remove();
	check( false === $ok, 'O-16: stan BEZ klucza owned traktowany jak cudza pozycja → remove() === false' );
	check( 0 === (int) $GLOBALS['__cnt']['wp_delete_post'], 'O-16: brak owned → zero kasowania (błąd „skasowaliśmy cudze" jest nieodwracalny)' );

	// FZ15 — deaktywacja przy removed_by_user zapisuje trwały opt-out.
	k20m_env( array( 'opts' => array( 'aifaq_menu_item_id' => 901, 'aifaq_menu_state' => array( 'state' => 'removed_by_user', 'item_id' => 901, 'owned' => '1', 'menu_id' => 10, 'tries' => 0, 'last' => 0, 'error' => '' ) ) ) );
	\AIFAQ\PublicUi\MenuGuard::remove();
	check( '1' === (string) get_option( 'aifaq_menu_optout', '' ), 'FZ15: remove() przy `removed_by_user` zapisuje aifaq_menu_optout = 1' );
	check( true === array_key_exists( 'aifaq_menu_state', $GLOBALS['__opt'] ), 'FZ15: aifaq_menu_state NIE jest kasowane przy removed_by_user' );

	// Kryterium akceptacji 3: po opt-oucie ponowna aktywacja NIE przywraca linku.
	$optout = $GLOBALS['__opt'];
	k20m_env( array( 'opts' => array( 'aifaq_menu_optout' => '1' ) ) );
	k20m_ensure();
	check( 0 === k20m_creates(), 'kryterium 3: link NIE wraca po reaktywacji, gdy stoi znacznik aifaq_menu_optout (§13.4)' );
	$r = (array) \AIFAQ\PublicUi\MenuGuard::repair( 'create' );
	check( 1 === k20m_creates(), "znacznik opt-out zdejmuje WYŁĄCZNIE repair( 'create' )" );
	check( '1' !== (string) get_option( 'aifaq_menu_optout', '' ), "repair( 'create' ) czyści znacznik aifaq_menu_optout" );

	// §13.5 — wyłączenie przełącznika NIE kasuje istniejącej pozycji.
	k20m_env( array( 'items' => array( 10 => array( k20m_item( 901 ) ) ), 'settings' => array( 'menu_link_enabled' => '0' ), 'opts' => array( 'aifaq_menu_item_id' => 901, 'aifaq_menu_state' => array( 'state' => 'ok', 'item_id' => 901, 'owned' => '1', 'menu_id' => 10, 'tries' => 0, 'last' => 0, 'error' => '' ) ) ) );
	$s = k20m_ensure();
	check( 'disabled' === $s['state'], '§13.5: wyłączony przełącznik → stan `disabled`' );
	check( 0 === (int) $GLOBALS['__cnt']['wp_delete_post'], '§13.5: pozycja ZOSTAJE w menu — remove() woła wyłącznie Deactivator' );

	unset( $thrown, $ok, $s, $r, $optout );
} else {
	skip( 30, 'sekcja I pominięta — brak klasy MenuGuard' );
}

// ===========================================================================
echo "\n=== K. Komunikaty kokpitu: MenuNotice i EditorNotice (§4, §13.7, O-21, O-22, O-23) ===\n";

/** Renderuje komunikat i zwraca wypisany HTML. */
function k20m_render( $class ) {
	if ( ! class_exists( $class ) || ! method_exists( $class, 'render' ) ) { return null; }
	ob_start();
	try {
		call_user_func( array( $class, 'render' ) );
	} catch ( \Throwable $e ) {
		ob_end_clean();
		echo '  [render rzucilo] ' . get_class( $e ) . ': ' . $e->getMessage() . "\n";
		return null;
	}
	return (string) ob_get_clean();
}

if ( $has_mn ) {
	// Stany, przy których komunikat MILCZY (§4.2).
	$silent = array(
		'ok'       => array( 'items' => array( 10 => array( k20m_item( 901 ) ) ), 'opts' => array( 'aifaq_menu_item_id' => 901, 'aifaq_menu_state' => array( 'state' => 'ok', 'item_id' => 901, 'owned' => '1', 'menu_id' => 10, 'tries' => 0, 'last' => 0, 'error' => '' ) ) ),
		'disabled' => array( 'settings' => array( 'menu_link_enabled' => '0' ) ),
		'missing'  => array(),
	);
	foreach ( $silent as $name => $cfg ) {
		k20m_env( $cfg );
		$html = k20m_render( $MN );
		check( '' === (string) $html, "MenuNotice MILCZY przy stanie `$name` (§4.2)" );
	}

	// Stany, przy których komunikat MÓWI — wszystkie pięć pozostałych + page_missing + menu_changed.
	$loud = array(
		'no_menu'            => array( 'locations' => array() ),
		'no_location'        => array( 'block' => true ),
		'location_ambiguous' => array( 'registered' => array( 'social' => 'Social' ), 'locations' => array( 'social' => 11 ), 'items' => array( 11 => array() ) ),
		'menu_changed'       => array( 'locations' => array( 'primary' => 20 ), 'items' => array( 10 => array( k20m_item( 901 ) ), 20 => array() ), 'opts' => array( 'aifaq_menu_item_id' => 901, 'aifaq_menu_state' => array( 'state' => 'ok', 'item_id' => 901, 'owned' => '1', 'menu_id' => 10, 'tries' => 0, 'last' => 0, 'error' => '' ) ) ),
		'removed_by_user'    => array( 'opts' => array( 'aifaq_menu_item_id' => 901, 'aifaq_menu_state' => array( 'state' => 'removed_by_user', 'item_id' => 901, 'owned' => '1', 'menu_id' => 10, 'tries' => 0, 'last' => 0, 'error' => '' ) ) ),
		'page_missing'       => array( 'page' => 0 ),
	);
	foreach ( $loud as $name => $cfg ) {
		k20m_env( $cfg );
		$html = k20m_render( $MN );
		check( '' !== (string) $html, "MenuNotice ODZYWA SIĘ przy stanie `$name` (§4.2 — zero cichej porażki)" );
	}

	// `failed` — siódmy stan mówiący.
	k20m_env( array( 'fail_item' => true ) );
	k20m_ensure();
	$html = k20m_render( $MN );
	check( '' !== (string) $html, 'MenuNotice ODZYWA SIĘ przy stanie `failed`' );

	// Bramka uprawnień — komunikat wyłącznie dla manage_options.
	k20m_env( array( 'locations' => array() ) );
	$GLOBALS['__caps'] = array( 'publish_posts', 'edit_posts' );
	$html = k20m_render( $MN );
	check( '' === (string) $html, 'MenuNotice milczy dla roli BEZ manage_options (§4.2)' );

	// Bramka ekranów — poza listą (plugins/dashboard/ekrany wtyczki) milczy.
	k20m_env( array( 'locations' => array() ) );
	$GLOBALS['__screen']      = 'edit-post';
	$GLOBALS['__screen_base'] = 'edit';
	$html = k20m_render( $MN );
	check( '' === (string) $html, 'MenuNotice milczy na ekranie spoza listy (§4.2)' );

	// FZ30 / O-22 — sufit dobowy: komunikat TYLKO gdy data == dziś i budżet > 0.
	k20m_env( array( 'opts' => array( 'aifaq_budget_hit' => current_time( 'Y-m-d' ) ) ) );
	$html_today = (string) k20m_render( $MN );
	k20m_env( array( 'opts' => array( 'aifaq_budget_hit' => date( 'Y-m-d', (int) $GLOBALS['__now'] - 86400 ) ) ) );
	$html_yest = (string) k20m_render( $MN );
	check( strlen( $html_today ) > strlen( $html_yest ), 'FZ30/O-22: flaga aifaq_budget_hit z DZIŚ dokłada komunikat o sufcie, wczorajsza nie' );
	k20m_env( array( 'settings' => array( 'rag_daily_budget' => 0 ), 'opts' => array( 'aifaq_budget_hit' => current_time( 'Y-m-d' ) ) ) );
	$html_off = (string) k20m_render( $MN );
	check( strlen( $html_off ) < strlen( $html_today ), 'O-22: przy rag_daily_budget = 0 komunikat o sufcie MILCZY (inaczej by kłamał)' );

	unset( $silent, $loud, $html, $html_today, $html_yest, $html_off, $name, $cfg );
} else {
	skip( 15, 'sekcja K/MenuNotice pominięta — brak klasy MenuNotice' );
}

echo "\n--- K2. handle_fix(): CAP -> NONCE -> WHITELISTA (§4.3, FZ18, O-21) ---\n";
if ( $has_mn && method_exists( $MN, 'handle_fix' ) ) {
	// Brak capa → wp_die 403, ZERO skutków.
	k20m_env( array( 'locations' => array() ) );
	k20m_request( $MN, 'create' );
	$GLOBALS['__caps'] = array( 'read' );
	k20m_cnt_reset();
	call_user_func( array( $MN, 'handle_fix' ) );
	check( (int) $GLOBALS['__cnt']['wp_die'] >= 1, 'handle_fix() bez manage_options → wp_die( 403 ) (FZ18: inaczej endpoint dla każdego zalogowanego)' );
	check( 0 === k20m_creates(), 'handle_fix() bez capa nie dotyka nawigacji' );

	// Zły nonce → wp_die, ZERO skutków (CSRF).
	k20m_env();
	k20m_request( $MN, 'create' );
	$GLOBALS['__nonce_ok'] = false;
	k20m_cnt_reset();
	try {
		call_user_func( array( $MN, 'handle_fix' ) );
	} catch ( K20MenuDie $e ) {
		unset( $e );   // rdzeń zakończyłby tu żądanie
	}
	$GLOBALS['__nonce_ok'] = true;
	check( (int) $GLOBALS['__cnt']['check_admin_referer'] >= 1, 'handle_fix() woła check_admin_referer() (mutacja „usunięty nonce" musi tu czerwienić)' );
	check( 0 === k20m_creates(), 'handle_fix() przy złym nonce nie tworzy pozycji (CSRF zamknięty)' );

	// Komplet cap + nonce + akcja z whitelisty → działa.
	k20m_env();
	k20m_request( $MN, 'create' );
	k20m_cnt_reset();
	call_user_func( array( $MN, 'handle_fix' ) );
	check( 1 === k20m_creates(), "handle_fix( 'create' ) z kompletem cap+nonce tworzy pozycję (parametr: " . k20m_param( $MN ) . ', prób: ' . k20m_creates() . ')' );
	check( (int) $GLOBALS['__cnt']['wp_safe_redirect'] >= 1, 'handle_fix() kończy się wp_safe_redirect()' );

	// Whitelista ZAMKNIĘTA.
	k20m_env();
	k20m_request( $MN, 'usun_wszystko' );
	k20m_cnt_reset();
	call_user_func( array( $MN, 'handle_fix' ) );
	check( 0 === k20m_creates(), 'handle_fix() z akcją spoza whitelisty (create|dismiss) nic nie robi' );
	check( 0 === (int) $GLOBALS['__cnt']['wp_delete_post'], 'akcja spoza whitelisty niczego nie kasuje' );

	// ŚCIEŻKA UŻYTKOWNIKA DO ZNALEZISKA O-108: klient usunął link ręcznie i klika „utwórz ponownie"
	// w komunikacie. §3.3 pkt 3 obiecuje, że to JEDYNE wyjście z `removed_by_user` — więc musi działać.
	k20m_env(
		array(
			'opts' => array(
				'aifaq_menu_item_id' => 901,
				'aifaq_menu_state'   => array( 'state' => 'removed_by_user', 'item_id' => 901, 'owned' => '1', 'menu_id' => 10, 'tries' => 0, 'last' => 0, 'error' => '' ),
			),
		)
	);
	k20m_request( $MN, 'create' );
	k20m_cnt_reset();
	call_user_func( array( $MN, 'handle_fix' ) );
	$_GET = $_REQUEST = array();
	check( 1 === k20m_creates(), 'O-108: kliknięcie „utwórz ponownie” po ręcznym usunięciu linku REALNIE tworzy pozycję (§3.3 pkt 3); prób: ' . k20m_creates() );

	// Wyciszenie zapisane WRAZ ZE STATUSEM i unieważnione po zmianie stanu.
	k20m_env( array( 'locations' => array() ) );
	$html_before = (string) k20m_render( $MN );
	k20m_request( $MN, 'dismiss' );
	call_user_func( array( $MN, 'handle_fix' ) );
	$_GET = $_REQUEST = array();
	$html_after = (string) k20m_render( $MN );
	check( '' !== $html_before && '' === $html_after, "po `dismiss` MenuNotice milczy dla TEGO stanu" );

	// Zmiana stanu: no_menu -> location_ambiguous (wyciszenie musi stracić ważność).
	$dismissed = get_option( 'aifaq_menu_notice_dismissed', null );
	k20m_env(
		array(
			'registered' => array( 'social' => 'Social' ),
			'locations'  => array( 'social' => 11 ),
			'items'      => array( 11 => array() ),
			'opts'       => array( 'aifaq_menu_notice_dismissed' => $dismissed ),
		)
	);
	$html_other = (string) k20m_render( $MN );
	check( '' !== $html_other, 'wyciszenie zapisane WRAZ ZE STATUSEM — zmiana stanu je unieważnia (§4.2)' );

	$_REQUEST = $_GET = array();
	unset( $html_before, $html_after, $html_other, $dismissed );
} else {
	skip( 10, 'sekcja K2 pominięta — brak MenuNotice::handle_fix()' );
}

echo "\n--- K3. EditorNotice: ekrany, cap narzędzia, wyciszenie per użytkownik (§4.2, FZ16, §13.7, O-23) ---\n";
if ( $has_en ) {
	// Widoczny na post.php / post-new.php (O-23: rozpoznanie po WP_Screen).
	k20m_env();
	$GLOBALS['__screen']       = 'post';
	$GLOBALS['__screen_base']  = 'post';
	$GLOBALS['__screen_ptype'] = 'post';
	$GLOBALS['pagenow']        = 'post.php';
	$html = (string) k20m_render( $EN );
	check( '' !== $html, 'EditorNotice odzywa się na ekranie edytora wpisu (base = post)' );

	k20m_env();
	$GLOBALS['__screen']       = 'page';
	$GLOBALS['__screen_base']  = 'post';
	$GLOBALS['__screen_ptype'] = 'page';
	$GLOBALS['pagenow']        = 'post-new.php';
	$html = (string) k20m_render( $EN );
	check( '' !== $html, 'EditorNotice odzywa się także dla typu `page` (POST_TYPES metaboksu)' );

	// Milczy poza edytorem — lista wpisów i pulpit.
	k20m_env();
	$GLOBALS['__screen']       = 'edit-post';
	$GLOBALS['__screen_base']  = 'edit';
	$GLOBALS['__screen_ptype'] = 'post';
	$GLOBALS['pagenow']        = 'edit.php';
	$html = (string) k20m_render( $EN );
	check( '' === $html, 'EditorNotice MILCZY na liście wpisów (edit.php)' );

	k20m_env();
	$GLOBALS['pagenow'] = 'index.php';
	$html = (string) k20m_render( $EN );
	check( '' === $html, 'EditorNotice MILCZY na pulpicie' );

	// Cap narzędzia — rola bez publish_posts nie widzi podpowiedzi.
	k20m_env();
	$GLOBALS['__screen']       = 'post';
	$GLOBALS['__screen_base']  = 'post';
	$GLOBALS['__screen_ptype'] = 'post';
	$GLOBALS['pagenow']        = 'post.php';
	$GLOBALS['__caps']         = array( 'read', 'edit_posts' );   // Współpracownik
	$html = (string) k20m_render( $EN );
	check( '' === $html, 'EditorNotice milczy dla roli bez capa narzędzia (publish_posts) — §4.2' );

	// §13.7 + FZ16 — wyciszenie PER UŻYTKOWNIK, na stałe.
	k20m_env();
	$GLOBALS['__screen']       = 'post';
	$GLOBALS['__screen_base']  = 'post';
	$GLOBALS['__screen_ptype'] = 'post';
	$GLOBALS['pagenow']        = 'post.php';
	if ( method_exists( $EN, 'handle_fix' ) ) {
		k20m_request( $EN, 'dismiss' );
		k20m_cnt_reset();
		call_user_func( array( $EN, 'handle_fix' ) );
		$_GET = $_REQUEST = array();
		check( (int) $GLOBALS['__cnt']['check_admin_referer'] >= 1, 'EditorNotice::handle_fix() sprawdza nonce (FZ18)' );
		check( (int) $GLOBALS['__cnt']['update_user_meta'] >= 1, 'FZ16: wyciszenie zapisane w METADANYCH UŻYTKOWNIKA, nie w opcji globalnej (parametr: ' . k20m_param( $EN ) . ')' );
		check( '1' === (string) get_user_meta( 7, 'aifaq_editor_hint_done', true ), 'klucz user meta === aifaq_editor_hint_done' );

		$html = (string) k20m_render( $EN );
		check( '' === $html, '§13.7: po `dismiss` podpowiedź NIGDY nie wraca dla tego użytkownika' );

		// Inny użytkownik podpowiedź nadal widzi (FZ16 — sedno poprawki).
		$GLOBALS['__uid'] = 9;
		$html = (string) k20m_render( $EN );
		check( '' !== $html, 'FZ16: INNY użytkownik nadal widzi podpowiedź (jeden Autor nie gasi jej całej redakcji)' );

		// Brak capa → wp_die, zero zapisu.
		k20m_env();
		$GLOBALS['__caps'] = array( 'read' );
		k20m_request( $EN, 'dismiss' );
		k20m_cnt_reset();
		call_user_func( array( $EN, 'handle_fix' ) );
		check( (int) $GLOBALS['__cnt']['wp_die'] >= 1, 'EditorNotice::handle_fix() bez capa narzędzia → wp_die (FZ18)' );
		check( 0 === (int) $GLOBALS['__cnt']['update_user_meta'], 'brak capa → zero zapisu do metadanych' );
		$_REQUEST = $_GET = array();
	} else {
		skip( 8, 'EditorNotice::handle_fix() nie istnieje' );
	}
	unset( $html );
} else {
	skip( 13, 'sekcja K3 pominięta — brak klasy EditorNotice' );
}

// ===========================================================================
// SEKCJA J NA KOŃCU: wykonanie uninstall.php kasuje opcje, więc musi biec po wszystkim.
// ===========================================================================
echo "\n=== J. uninstall.php — dziewięć nowych opcji + osierocona pozycja (§3.5, §13.26, O-13/O-44) ===\n";
$uninstall = __DIR__ . '/../uninstall.php';
if ( file_exists( $uninstall ) ) {
	if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { define( 'WP_UNINSTALL_PLUGIN', 'ai-faq-generator/ai-faq-generator.php' ); }

	$new_opts = array(
		'aifaq_menu_item_id', 'aifaq_menu_state', 'aifaq_menu_ok', 'aifaq_menu_lock',
		'aifaq_menu_notice_dismissed', 'aifaq_menu_optout', 'aifaq_daily_usage',
		'aifaq_budget_hit', 'aifaq_proxy_seen',
	);

	k20m_env( array( 'items' => array( 10 => array( k20m_item( 901 ) ) ) ) );
	foreach ( $new_opts as $o ) { $GLOBALS['__opt'][ $o ] = 'x'; }
	$GLOBALS['__opt']['aifaq_menu_item_id'] = 901;
	$GLOBALS['__opt']['aifaq_menu_state']   = array( 'state' => 'ok', 'item_id' => 901, 'owned' => '1', 'menu_id' => 10, 'tries' => 0, 'last' => 0, 'error' => '' );
	k20m_cnt_reset();

	$uthrown = '';
	try {
		require $uninstall;
	} catch ( \Throwable $e ) {
		$uthrown = get_class( $e ) . ': ' . $e->getMessage();
	}
	check( '' === $uthrown, 'uninstall.php wykonuje się bez wyjątku (' . ( '' === $uthrown ? 'ok' : $uthrown ) . ')' );

	// Asercja po NAZWACH — liczbę delete_option() bumpuje E10 w krok19-migracja-test.php.
	foreach ( $new_opts as $o ) {
		check( false === array_key_exists( $o, $GLOBALS['__opt'] ), "uninstall.php kasuje opcję `$o`" );
	}

	// §13.26 — osierocona pozycja menu kasowana tylko gdy owned === '1'.
	check( 1 === (int) $GLOBALS['__cnt']['wp_delete_post'], "§13.26: uninstall.php kasuje osieroconą pozycję (owned '1')" );
	check( 901 === (int) ( $GLOBALS['__deleted_posts'][0]['id'] ?? 0 ), '§13.26: skasowano dokładnie zapisaną pozycję' );
	check( true === (bool) ( $GLOBALS['__deleted_posts'][0]['force'] ?? false ), '§13.26: kasowanie czyste — wp_delete_post( $id, true )' );

	unset( $new_opts, $o, $uthrown );
} else {
	skip( 13, 'sekcja J pominięta — brak uninstall.php' );
}

// ===========================================================================
echo "\n== Higiena ==\n";
check( false === $GLOBALS['__forbidden']['wp_create_nav_menu'], 'ZAKAZ ABSOLUTNY utrzymany do końca pliku: zero wp_create_nav_menu()' );
check( false === $GLOBALS['__forbidden']['set_theme_mod'], 'ZAKAZ ABSOLUTNY utrzymany do końca pliku: zero set_theme_mod()' );
check( 0 === $GLOBALS['aifaq_warnings'], 'zero warningów/notice PHP w całym pliku (było: ' . $GLOBALS['aifaq_warnings'] . ')' );

echo "\n== Podłoga pokrycia ==\n";
$floor = $ran;
check( $floor >= 55, 'wykonano co najmniej 55 asercji (było ' . $floor . ')' );

// Wartownik końca pliku — chroni przed cichym Fatalem w środku.
check( true, 'plik dobiegł końca' );

echo "\n=== " . ( 0 === $fail ? 'WSZYSTKIE OK' : "BŁĘDÓW: {$fail}" ) . " (asercji: {$ran}) ===\n";
exit( $fail > 0 ? 1 : 0 );
