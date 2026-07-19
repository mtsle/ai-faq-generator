<?php
/**
 * Testy: niezawodność podstrony /generator-faq/ — stany, zamek, ponowienia,
 * bramka slugu, komunikaty kokpitu i handle_fix() (Krok 18, Etap 5).
 *
 * NAPISANE W CIEMNO wyłącznie z `plany/krok18/KONTRAKT.md` (wersja k18-v3), bez zaglądania
 * w kod Etapów 1-4. Rozbieżność test<->implementacja jest dowodem, że kontrakt był
 * nieprecyzyjny — idzie do `plany/krok18/ODCHYLENIA.md`, nie do cichej korekty asercji.
 *
 * Pokrycie: KONTRAKT §8.3 #1-#67 + §8.4 (liczniki „czegoś ZABRANIA”).
 * Etykiety ról: [NOWE] = pokrycie Kroku 18; [REGRESJA]/[PARA-DODATNIA] = przechodzi także
 * na NIEZMIENIONYM kodzie, więc NIE jest dowodem, że Krok 18 działa.
 *
 * URUCHOMIENIE:  php tests/krok18-pageguard-test.php
 * Kod wyjścia: 0 = OK, 1 = błędy.
 *
 * @package AI_FAQ_Generator
 */

// ---------------------------------------------------------------------------
// PREAMBUŁA — stałe PRZED pierwszym `require` (§8.1 pkt 14).
// Brak ABSPATH => `exit;` z kodem 0. Brak AIFAQ_TESTING => handle_fix() robi `exit`
// w środku sekcji notice'a => runner raportuje SUKCES na urwanym pliku.
// ---------------------------------------------------------------------------
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
if ( ! defined( 'AIFAQ_PLUGIN_URL' ) ) { define( 'AIFAQ_PLUGIN_URL', 'http://test.local/wp-content/plugins/ai-faq-generator/' ); }
if ( ! defined( 'AIFAQ_VERSION' ) ) { define( 'AIFAQ_VERSION', '0.0.0-test' ); }
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
if ( ! defined( 'OBJECT' ) ) { define( 'OBJECT', 'OBJECT' ); }
if ( ! defined( 'AIFAQ_TESTING' ) ) { define( 'AIFAQ_TESTING', true ); }

// ---------------------------------------------------------------------------
// Klasy WP — kształt ZAMROŻONY (§3.6.1). stdClass ani atrapa bez post_status/post_name
// NIE zadziała: PageGuard sprawdza `instanceof \WP_Post`.
// ---------------------------------------------------------------------------
if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public $ID = 0;
		public $post_type = 'page';
		public $post_status = 'publish';
		public $post_name = '';
		public $post_content = '';
		public function __construct( $f = array() ) {
			foreach ( $f as $k => $v ) { $this->$k = $v; }
		}
	}
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $msg;
		public function __construct( $c = '', $m = '' ) { $this->msg = (string) $m; }
		public function get_error_message() { return $this->msg; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $t ) { return $t instanceof WP_Error; } }

// ---------------------------------------------------------------------------
// Magazyny stanu (§8.1 pkt 3).
// ---------------------------------------------------------------------------
$GLOBALS['__opt']            = array();
$GLOBALS['__autoload']       = array();
$GLOBALS['__posts']          = array();
$GLOBALS['__posts_by_slug']  = array();
$GLOBALS['__postdata']       = array();
$GLOBALS['__inserted']       = array();
$GLOBALS['__updated']        = array();
$GLOBALS['__deleted_args']   = array();
$GLOBALS['__nonce_calls']    = array();
$GLOBALS['__repair_calls']   = array();
$GLOBALS['__died']           = array();
$GLOBALS['__redirect']       = array();
$GLOBALS['__referer_calls']  = array();
$GLOBALS['__cnt']            = array();
$GLOBALS['__can']            = true;
$GLOBALS['__uid']            = 0;
$GLOBALS['__users']          = array();
$GLOBALS['__screen']         = 'dashboard';
$GLOBALS['__insert_error']   = null;
$GLOBALS['__update_error']   = null;
$GLOBALS['__untrash_result'] = null;
$GLOBALS['__force_post_name'] = null;
$GLOBALS['__on_insert']      = null;
$GLOBALS['__next_id']        = 100;
$GLOBALS['__url_to_postid']  = 0;
$GLOBALS['__doing_ajax']     = false;
$GLOBALS['__doing_cron']     = false;

function aifaq_cnt_reset() {
	$GLOBALS['__cnt'] = array(
		'update_option' => 0, 'update_page_id' => 0, 'wp_insert_post' => 0, 'wp_delete_post' => 0,
		'wp_update_post' => 0, 'wp_untrash_post' => 0, 'get_post' => 0, 'get_page_by_path' => 0,
		'flush_rewrite_rules' => 0, 'add_settings_error' => 0, 'check_admin_referer' => 0,
		'wp_die' => 0, 'wp_safe_redirect' => 0, 'get_permalink' => 0,
	);
}
aifaq_cnt_reset();

// ---------------------------------------------------------------------------
// Shimy WP — WSZYSTKIE przed pierwszym `require` (§8.1 pkt 2).
// Komplet z tabel §3.6.1 i §3.9.1. CELOWO NIE shimujemy `wp_cache_add`,
// `wp_cache_delete`, `wp_using_ext_object_cache` — kod ma iść ścieżką
// `function_exists() === false` (§3.6.1, §3.6.4 krok 1).
// ---------------------------------------------------------------------------
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $s, $d = null ) { return (string) $s; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $s ) { return (string) $s; } }
if ( ! function_exists( 'esc_url_raw' ) ) { function esc_url_raw( $s ) { return (string) $s; } }
if ( ! function_exists( 'esc_textarea' ) ) { function esc_textarea( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'get_bloginfo' ) ) { function get_bloginfo( $k = '' ) { return 'charset' === $k ? 'UTF-8' : 'Test Site'; } }
if ( ! function_exists( 'home_url' ) ) { function home_url( $p = '/' ) { return 'http://test.local' . $p; } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $p = '' ) { return 'http://test.local/wp-admin/' . $p; } }
if ( ! function_exists( 'rest_url' ) ) { function rest_url( $p = '' ) { return 'http://test.local/wp-json/' . ltrim( (string) $p, '/' ); } }
if ( ! function_exists( 'wp_create_nonce' ) ) { function wp_create_nonce( $a = '' ) { return 'TESTNONCE'; } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $d, $o = 0 ) { return json_encode( $d, $o ); } }
if ( ! function_exists( 'selected' ) ) { function selected( $a, $b = true, $e = true ) { $r = ( (string) $a === (string) $b ) ? ' selected="selected"' : ''; if ( $e ) { echo $r; } return $r; } }
if ( ! function_exists( 'number_format_i18n' ) ) { function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, (int) $d ); } }

// --- opcje: semantyka ZAMROŻONA (§8.1 pkt 9). Atrapa add_option zwracająca zawsze
// --- `true` unieważnia WSZYSTKIE asercje zamka.
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__opt'] ) ? $GLOBALS['__opt'][ $k ] : $d; }
}
if ( ! function_exists( 'add_option' ) ) {
	function add_option( $k, $v = '', $dep = '', $autoload = 'yes' ) {
		if ( array_key_exists( $k, $GLOBALS['__opt'] ) ) { return false; }
		$GLOBALS['__opt'][ $k ]      = $v;
		$GLOBALS['__autoload'][ $k ] = $autoload;
		return true;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $k, $v, $autoload = null ) {
		$GLOBALS['__cnt']['update_option']++;
		if ( 'aifaq_page_id' === $k ) { $GLOBALS['__cnt']['update_page_id']++; }
		$GLOBALS['__opt'][ $k ] = $v;
		if ( null !== $autoload ) {
			if ( false === $autoload ) { $autoload = 'no'; }
			if ( true === $autoload ) { $autoload = 'yes'; }
			$GLOBALS['__autoload'][ $k ] = $autoload;
		}
		return true;
	}
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $k ) { unset( $GLOBALS['__opt'][ $k ], $GLOBALS['__autoload'][ $k ] ); return true; }
}

// --- posty ---
function aifaq_store_post( $p ) {
	$GLOBALS['__posts'][ (int) $p->ID ] = $p;
	if ( '' !== (string) $p->post_name && ! isset( $GLOBALS['__posts_by_slug'][ $p->post_name ] ) ) {
		$GLOBALS['__posts_by_slug'][ $p->post_name ] = $p;
	}
	return $p;
}
function aifaq_make_post( $id, $status, $content, $slug = 'generator-faq', $type = 'page' ) {
	return aifaq_store_post( new WP_Post( array( 'ID' => (int) $id, 'post_type' => $type, 'post_status' => $status, 'post_name' => $slug, 'post_content' => $content ) ) );
}
if ( ! function_exists( 'get_post' ) ) {
	function get_post( $id = null ) {
		$GLOBALS['__cnt']['get_post']++;
		return $GLOBALS['__posts'][ (int) $id ] ?? null;
	}
}
if ( ! function_exists( 'get_page_by_path' ) ) {
	// Kluczowana SLUGIEM (§8.1 pkt 11) — atrapa zwracająca jeden post dla każdego slugu
	// zaczerwieniłaby §8.3 #45 na poprawnym kodzie.
	function get_page_by_path( $slug, $out = null, $type = 'page' ) {
		$GLOBALS['__cnt']['get_page_by_path']++;
		return $GLOBALS['__posts_by_slug'][ (string) $slug ] ?? null;
	}
}
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $id, $key = '', $single = false ) { return $GLOBALS['__postdata'][ (int) $id ][ $key ] ?? ''; }
}
if ( ! function_exists( 'wp_insert_post' ) ) {
	// Przy sukcesie MUSI dopisać nowy WP_Post do magazynu czytanego przez get_post()
	// i get_page_by_path() (§8.1 pkt 10) — inaczej ensure() krok 9 przeliczy `missing`
	// mimo sukcesu i każdy scenariusz łańcuchowy się rozjedzie.
	function wp_insert_post( $args, $wp_error = false ) {
		$GLOBALS['__cnt']['wp_insert_post']++;
		$GLOBALS['__inserted'][] = $args;
		if ( $GLOBALS['__insert_error'] instanceof WP_Error ) { return $GLOBALS['__insert_error']; }
		$id   = $GLOBALS['__next_id']++;
		$name = (string) ( $args['post_name'] ?? '' );
		if ( null !== $GLOBALS['__force_post_name'] ) {
			$name = (string) $GLOBALS['__force_post_name'];
		} elseif ( '' !== $name && isset( $GLOBALS['__posts_by_slug'][ $name ] ) ) {
			$name .= '-2';   // wp_unique_post_slug() nie filtruje po statusie
		}
		aifaq_store_post( new WP_Post( array(
			'ID'           => $id,
			'post_type'    => (string) ( $args['post_type'] ?? 'page' ),
			'post_status'  => (string) ( $args['post_status'] ?? 'publish' ),
			'post_name'    => $name,
			'post_content' => (string) ( $args['post_content'] ?? '' ),
		) ) );
		if ( is_callable( $GLOBALS['__on_insert'] ) ) { call_user_func( $GLOBALS['__on_insert'], $id ); }
		return $id;
	}
}
if ( ! function_exists( 'wp_delete_post' ) ) {
	function wp_delete_post( $id, $force = false ) {
		$GLOBALS['__cnt']['wp_delete_post']++;
		$GLOBALS['__deleted_args'][] = array( (int) $id, $force );
		$p = $GLOBALS['__posts'][ (int) $id ] ?? null;
		if ( $p instanceof WP_Post ) { unset( $GLOBALS['__posts_by_slug'][ $p->post_name ] ); }
		unset( $GLOBALS['__posts'][ (int) $id ] );
		return true;
	}
}
if ( ! function_exists( 'wp_update_post' ) ) {
	function wp_update_post( $args, $wp_error = false ) {
		$GLOBALS['__cnt']['wp_update_post']++;
		$GLOBALS['__updated'][] = $args;
		if ( $GLOBALS['__update_error'] instanceof WP_Error ) { return $GLOBALS['__update_error']; }
		$id = (int) ( $args['ID'] ?? 0 );
		$p  = $GLOBALS['__posts'][ $id ] ?? null;
		if ( $p instanceof WP_Post ) {
			foreach ( array( 'post_status', 'post_name', 'post_content' ) as $f ) {
				if ( isset( $args[ $f ] ) ) { $p->$f = $args[ $f ]; }
			}
			aifaq_store_post( $p );
		}
		return $id;
	}
}
if ( ! function_exists( 'wp_untrash_post' ) ) {
	function wp_untrash_post( $id ) { $GLOBALS['__cnt']['wp_untrash_post']++; return $GLOBALS['__untrash_result']; }
}
if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( $id = 0 ) { $GLOBALS['__cnt']['get_permalink']++; return 'http://test.local/?page_id=' . (int) $id; }
}
if ( ! function_exists( 'get_edit_post_link' ) ) {
	function get_edit_post_link( $id = 0, $ctx = 'display' ) { return 'http://test.local/wp-admin/post.php?post=' . (int) $id . '&action=edit'; }
}
if ( ! function_exists( 'flush_rewrite_rules' ) ) {
	function flush_rewrite_rules( $hard = true ) { $GLOBALS['__cnt']['flush_rewrite_rules']++; }
}

// --- tożsamość i uprawnienia ---
if ( ! function_exists( 'is_user_logged_in' ) ) { function is_user_logged_in() { return $GLOBALS['__uid'] > 0; } }
if ( ! function_exists( 'current_user_can' ) ) { function current_user_can( $c ) { return (bool) $GLOBALS['__can']; } }
if ( ! function_exists( 'get_current_user_id' ) ) { function get_current_user_id() { return (int) $GLOBALS['__uid']; } }
if ( ! function_exists( 'get_users' ) ) { function get_users( $a = array() ) { return $GLOBALS['__users']; } }

// --- slug / sanityzacja / ustawienia ---
if ( ! function_exists( 'sanitize_title' ) ) { function sanitize_title( $s ) { return strtolower( trim( preg_replace( '/[^A-Za-z0-9\-_]+/', '-', (string) $s ), '-' ) ); } }
if ( ! function_exists( 'sanitize_key' ) ) { function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $s ) ); } }
if ( ! function_exists( 'wp_unslash' ) ) { function wp_unslash( $s ) { return is_string( $s ) ? stripslashes( $s ) : $s; } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); } }
if ( ! function_exists( 'sanitize_textarea_field' ) ) { function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); } }
if ( ! function_exists( 'url_to_postid' ) ) { function url_to_postid( $u ) { return (int) $GLOBALS['__url_to_postid']; } }
if ( ! function_exists( 'add_settings_error' ) ) { function add_settings_error( $s, $c, $m, $t = 'error' ) { $GLOBALS['__cnt']['add_settings_error']++; } }

// --- ekran kokpitu, nonce, przekierowania (§3.9.1) ---
if ( ! function_exists( 'get_current_screen' ) ) {
	function get_current_screen() {
		if ( null === $GLOBALS['__screen'] ) { return null; }
		return (object) array( 'id' => (string) $GLOBALS['__screen'] );
	}
}
if ( ! function_exists( 'wp_nonce_url' ) ) {
	function wp_nonce_url( $url, $action = -1, $name = '_wpnonce' ) {
		$GLOBALS['__nonce_calls'][] = array( $url, $action );
		return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . '_wpnonce=TESTNONCE';
	}
}
if ( ! function_exists( 'check_admin_referer' ) ) {
	function check_admin_referer( $action = -1, $name = '_wpnonce' ) {
		$GLOBALS['__cnt']['check_admin_referer']++;
		$GLOBALS['__referer_calls'][] = $action;
		return true;
	}
}
// wp_die / wp_safe_redirect BEZ `exit` i BEZ wyjątku — dlatego kod produkcyjny MUSI
// mieć jawny `return` po każdym wp_die (§3.9 krok 1, §8.3 #56/#60).
if ( ! function_exists( 'wp_die' ) ) { function wp_die( $m = '', $t = '', $a = array() ) { $GLOBALS['__cnt']['wp_die']++; $GLOBALS['__died'][] = (string) $m; } }
if ( ! function_exists( 'wp_safe_redirect' ) ) { function wp_safe_redirect( $l, $s = 302 ) { $GLOBALS['__cnt']['wp_safe_redirect']++; $GLOBALS['__redirect'][] = (string) $l; return true; } }
if ( ! function_exists( 'wp_get_referer' ) ) { function wp_get_referer() { return 'http://test.local/wp-admin/index.php'; } }

// --- rewrite (Router.php ładowany CELOWO, §8.1 pkt 12) ---
if ( ! function_exists( 'add_rewrite_rule' ) ) { function add_rewrite_rule( $r, $q, $a = 'bottom' ) {} }
if ( ! function_exists( 'add_filter' ) ) { function add_filter( $h, $cb, $p = 10, $n = 1 ) { return true; } }
if ( ! function_exists( 'add_action' ) ) { function add_action( $h, $cb, $p = 10, $n = 1 ) { return true; } }
if ( ! function_exists( 'add_shortcode' ) ) { function add_shortcode( $t, $cb ) { return true; } }
if ( ! function_exists( 'shortcode_atts' ) ) { function shortcode_atts( $pairs, $atts, $sc = '' ) { return array_merge( (array) $pairs, (array) $atts ); } }
if ( ! function_exists( 'is_admin' ) ) { function is_admin() { return true; } }

// --- bramki Plugin::audit_page() (§3.6.1, NOWE w v3) ---
if ( ! function_exists( 'wp_doing_ajax' ) ) { function wp_doing_ajax() { return (bool) $GLOBALS['__doing_ajax']; } }
if ( ! function_exists( 'wp_doing_cron' ) ) { function wp_doing_cron() { return (bool) $GLOBALS['__doing_cron']; } }

if ( ! class_exists( 'FakeWpdb' ) ) {
	class FakeWpdb {
		public $prefix = 'wp_';
		public function get_row( $sql, $out = null ) { return array( 'chunks' => 7, 'posts' => 3, 'embedded' => 7 ); }
		public function prepare( $q, ...$a ) { return $q; }
		public function get_var( $q ) { return 0; }
		public function get_results( $q, $o = null ) { return array(); }
		public function query( $q ) { return 0; }
	}
}
$GLOBALS['wpdb'] = new FakeWpdb();

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

/** Pełny reset izolacji — globale przypisywane OD NOWA przed każdą sekcją (§8.1 pkt 8). */
function aifaq_reset( $opts = array() ) {
	$GLOBALS['__opt']             = array_merge( array( 'aifaq_settings' => array( 'page_slug' => 'faqgenerator', 'language' => 'pl' ) ), $opts );
	$GLOBALS['__autoload']        = array();
	$GLOBALS['__posts']           = array();
	$GLOBALS['__posts_by_slug']   = array();
	$GLOBALS['__postdata']        = array();
	$GLOBALS['__inserted']        = array();
	$GLOBALS['__updated']         = array();
	$GLOBALS['__deleted_args']    = array();
	$GLOBALS['__nonce_calls']     = array();
	$GLOBALS['__repair_calls']    = array();
	$GLOBALS['__died']            = array();
	$GLOBALS['__redirect']        = array();
	$GLOBALS['__referer_calls']   = array();
	$GLOBALS['__can']             = true;
	$GLOBALS['__uid']             = 0;
	$GLOBALS['__users']           = array();
	$GLOBALS['__screen']          = 'dashboard';
	$GLOBALS['__insert_error']    = null;
	$GLOBALS['__update_error']    = null;
	$GLOBALS['__untrash_result']  = null;
	$GLOBALS['__force_post_name'] = null;
	$GLOBALS['__on_insert']       = null;
	$GLOBALS['__next_id']         = 100;
	$GLOBALS['__url_to_postid']   = 0;
	$GLOBALS['__doing_ajax']      = false;
	$GLOBALS['__doing_cron']      = false;
	aifaq_cnt_reset();
}

/** Zeruje backoff w ZAPISANYM stanie, nie ruszając liczników update_option. */
function aifaq_zero_backoff() {
	$s = $GLOBALS['__opt']['aifaq_page_state'] ?? array();
	if ( is_array( $s ) ) { $s['last'] = 0; $GLOBALS['__opt']['aifaq_page_state'] = $s; }
}
function aifaq_saved_state() {
	$s = $GLOBALS['__opt']['aifaq_page_state'] ?? array();
	return is_array( $s ) ? $s : array();
}
function aifaq_call( $class, $method, $args = array() ) {
	$r = new ReflectionMethod( $class, $method );
	$r->setAccessible( true );
	if ( $r->isStatic() ) { return $r->invokeArgs( null, $args ); }
	return $r->invokeArgs( new $class(), $args );
}

// ---------------------------------------------------------------------------
// Ładowanie kodu — kolejność ZAMROŻONA (§8.1 pkt 12), Plugin.php ostatni.
// Każdy require pod file_exists(): PageGuard/PageNotice mogą jeszcze nie istnieć.
// ---------------------------------------------------------------------------
$aifaq_files = array(
	'src/Core/Settings.php',
	'src/Core/Router.php',
	'src/Rest/RestController.php',
	'src/PublicUi/GeneratorPage.php',
	'src/PublicUi/Shortcode.php',
	'src/PublicUi/PageGuard.php',
	'src/Admin/PageNotice.php',
	'src/Core/Plugin.php',
);
foreach ( $aifaq_files as $aifaq_rel ) {
	$aifaq_p = __DIR__ . '/../' . $aifaq_rel;
	if ( file_exists( $aifaq_p ) ) { require_once $aifaq_p; }
}

$has_guard  = class_exists( 'AIFAQ\PublicUi\PageGuard' );
$has_notice = class_exists( 'AIFAQ\Admin\PageNotice' );
$has_short  = class_exists( 'AIFAQ\PublicUi\Shortcode' );
$has_set    = class_exists( 'AIFAQ\Core\Settings' );
$has_plugin = class_exists( 'AIFAQ\Core\Plugin' );

$GUARD     = 'AIFAQ\PublicUi\PageGuard';
$SHORTCODE = '[aifaq_generator]';
$MAX_TRIES = $has_guard ? AIFAQ\PublicUi\PageGuard::MAX_TRIES : 3;
$LOCK_TTL  = $has_guard ? AIFAQ\PublicUi\PageGuard::LOCK_TTL : 30;

// ---------------------------------------------------------------------------
// Podklasy-szwy (§8.3 #28, §3.9.1). Deklarowane warunkowo — klasy bazowe mogą
// jeszcze nie istnieć. Kontrakt wymaga od PageGuard wywołań przez `static::`;
// jeżeli stub NIE przejmie sterowania, to JEST znalezisko, nie powód do
// zmiękczenia asercji.
// ---------------------------------------------------------------------------
class AifaqGuardCounter {
	public static function repair( string $a ): array { $GLOBALS['__repair_calls'][] = $a; return array(); }

	// ODCHYLENIE (zgłoszone w ODCHYLENIA.md, poz. 1): §3.9.1 zamraża tę atrapę z SAMYM
	// repair(), ale §3.9 wymaga, by WSZYSTKIE odwołania do PageGuard wewnątrz render()
	// i handle_fix() szły przez szew `guard()` — w tym odczyt statusu w kroku 4
	// (`dismiss`). Atrapa z samym repair() mierzyłaby więc własną niekompletność, a nie
	// kod. Delegujemy resztę do prawdziwego PageGuard; licznik repair() zostaje.
	public static function page_state(): array {
		return class_exists( 'AIFAQ\PublicUi\PageGuard' ) ? AIFAQ\PublicUi\PageGuard::page_state() : array( 'status' => 'missing', 'id' => 0, 'tries' => 0, 'last' => 0, 'error' => '', 'deleted' => 0 );
	}
	public static function state(): array {
		return class_exists( 'AIFAQ\PublicUi\PageGuard' ) ? AIFAQ\PublicUi\PageGuard::state() : array( 'status' => 'missing', 'id' => 0, 'tries' => 0, 'last' => 0, 'error' => '', 'deleted' => 0 );
	}
	public static function refresh(): array {
		return class_exists( 'AIFAQ\PublicUi\PageGuard' ) ? AIFAQ\PublicUi\PageGuard::refresh() : self::state();
	}
	public static function page_url(): string {
		return class_exists( 'AIFAQ\PublicUi\PageGuard' ) ? AIFAQ\PublicUi\PageGuard::page_url() : '';
	}
}
if ( $has_guard ) {
	class AifaqGuardStub extends AIFAQ\PublicUi\PageGuard {
		protected static function acquire_lock(): bool { return false; }
	}
	// Zamek po udanym ensure() jest zwalniany (delete_option kasuje OBA wpisy), więc
	// autoload dla `aifaq_page_lock` (§8.3 #33) jest mierzalny wyłącznie na szwie
	// wstrzymującym zwolnienie. Zgłoszone w ODCHYLENIA.md.
	class AifaqGuardNoRelease extends AIFAQ\PublicUi\PageGuard {
		protected static function release_lock(): void {}
	}
}
if ( $has_notice ) {
	class AifaqNoticeStub extends AIFAQ\Admin\PageNotice {
		protected static function guard(): string { return 'AifaqGuardCounter'; }
	}
}

// ===========================================================================
// SEKCJA A — Stałe i kształt stanu.  §8.3 #1, #36, #37.
// ===========================================================================
echo "== A. Stałe STATE_* i kształt stanu ==\n";
if ( $has_guard ) {
	$expected_states = array(
		'STATE_OK' => 'ok', 'STATE_MISSING' => 'missing', 'STATE_FAILED' => 'failed',
		'STATE_TRASHED' => 'trashed', 'STATE_NOT_PUBLIC' => 'not_public',
		'STATE_NO_SHORTCODE' => 'no_shortcode', 'STATE_SLUG_TAKEN' => 'slug_taken',
		'STATE_DELETED' => 'deleted',
	);
	foreach ( $expected_states as $const => $val ) {
		check( defined( $GUARD . '::' . $const ) && constant( $GUARD . '::' . $const ) === $val, '#1 ' . $const . ' === "' . $val . '" [NOWE]' );
	}

	aifaq_reset( array( 'aifaq_page_state' => 'ŚMIECI-nie-tablica' ) );
	$st = AIFAQ\PublicUi\PageGuard::state();
	check( 6 === count( $st ), '#36 state() przy śmieciach zwraca pełne 6 kluczy [NOWE]' );
	check( array_keys( $st ) === array( 'status', 'id', 'tries', 'last', 'error', 'deleted' ), '#36 state() — zamrożony zestaw kluczy (§2.4) [NOWE]' );
	check( 'missing' === $st['status'], '#36 state() przy śmieciach → status missing [NOWE]' );
	unset( $st, $expected_states );
} else {
	skip( 11, '#1/#36 sekcja A pominięta — brak klasy PageGuard [NOWE]' );
}

// ===========================================================================
// SEKCJA B — page_state(): DIAGNOZA, zero zapisów.  §8.3 #2-#17.
// ===========================================================================
echo "\n== B. page_state() — diagnoza bez zapisów ==\n";
if ( $has_guard ) {
	// #2
	aifaq_reset( array( 'aifaq_page_id' => 10 ) );
	aifaq_make_post( 10, 'publish', 'tekst ' . $SHORTCODE . ' tekst' );
	$s = AIFAQ\PublicUi\PageGuard::page_state();
	check( 'ok' === $s['status'], '#2 publish + page + shortcode → ok [NOWE]' );
	check( 10 === $s['id'], '#2 id === ID strony [NOWE]' );

	// #3, #4
	foreach ( array( 'draft' => '#3', 'private' => '#4', 'pending' => '#4' ) as $status => $tag ) {
		aifaq_reset( array( 'aifaq_page_id' => 11 ) );
		aifaq_make_post( 11, $status, $SHORTCODE );
		$s = AIFAQ\PublicUi\PageGuard::page_state();
		check( 'not_public' === $s['status'], $tag . ' ' . $status . ' → not_public (NIE ok) [NOWE]' );
	}

	// #5
	aifaq_reset( array( 'aifaq_page_id' => 12 ) );
	aifaq_make_post( 12, 'trash', $SHORTCODE );
	$s = AIFAQ\PublicUi\PageGuard::page_state();
	check( 'trashed' === $s['status'], '#5 post_status trash → trashed [NOWE]' );

	// #6
	aifaq_reset( array( 'aifaq_page_id' => 13 ) );
	aifaq_make_post( 13, 'publish', 'zwykła treść bez znacznika' );
	$s = AIFAQ\PublicUi\PageGuard::page_state();
	check( 'no_shortcode' === $s['status'], '#6 własne ID, publish, brak shortcode\'u → no_shortcode [NOWE]' );

	// #7 — blok wielokrotnego użytku.
	aifaq_reset( array( 'aifaq_page_id' => 14 ) );
	aifaq_make_post( 14, 'publish', '<!-- wp:block {"ref":9} /-->' );
	$s = AIFAQ\PublicUi\PageGuard::page_state();
	check( 'ok' === $s['status'], '#7 treść z <!-- wp:block → ok (fallback bloku wielokrotnego użytku) [NOWE]' );

	// #8 — Elementor.
	aifaq_reset( array( 'aifaq_page_id' => 15 ) );
	aifaq_make_post( 15, 'publish', 'pusto' );
	$GLOBALS['__postdata'][15] = array( '_elementor_data' => '[{"widgetType":"shortcode","settings":{"shortcode":"[aifaq_generator]"}}]' );
	$s = AIFAQ\PublicUi\PageGuard::page_state();
	check( 'ok' === $s['status'], '#8 _elementor_data z aifaq_generator → ok [NOWE]' );

	// #9, #10
	aifaq_reset();
	$s = AIFAQ\PublicUi\PageGuard::page_state();
	check( 'missing' === $s['status'], '#9 brak ID i brak strony po slugu, tries=0 → missing [NOWE]' );

	aifaq_reset( array( 'aifaq_page_state' => array( 'status' => 'failed', 'id' => 0, 'tries' => 2, 'last' => 0, 'error' => 'x', 'deleted' => 0 ) ) );
	$s = AIFAQ\PublicUi\PageGuard::page_state();
	check( 'failed' === $s['status'], '#10 brak strony + zapisane tries=2 → failed [NOWE]' );

	// #11 — get_post() null przy zapisanym ID.
	aifaq_reset( array( 'aifaq_page_id' => 999 ) );
	$s = AIFAQ\PublicUi\PageGuard::page_state();
	check( 'missing' === $s['status'], '#11 get_post()=null przy zapisanym ID → missing (nie fatal) [NOWE]' );

	// #12 — post_type !== page.
	aifaq_reset( array( 'aifaq_page_id' => 16 ) );
	aifaq_make_post( 16, 'publish', $SHORTCODE, 'jakis-wpis', 'post' );
	$s = AIFAQ\PublicUi\PageGuard::page_state();
	check( 'missing' === $s['status'], '#12 post_type !== page → traktowane jak brak ID [NOWE]' );

	// #13 — private po slugu NIE jest przejmowane.
	aifaq_reset();
	aifaq_make_post( 17, 'private', $SHORTCODE );
	$s = AIFAQ\PublicUi\PageGuard::page_state();
	check( 'ok' !== $s['status'], '#13 nieopublikowana strona po slugu NIE jest przejmowana (status != ok) [NOWE]' );
	check( 0 === (int) ( $GLOBALS['__opt']['aifaq_page_id'] ?? 0 ), '#13 aifaq_page_id pozostaje 0 [NOWE]' );

	// #14 — przejęcie po slugu (publish + shortcode).
	aifaq_reset();
	aifaq_make_post( 18, 'publish', $SHORTCODE );
	$s = AIFAQ\PublicUi\PageGuard::page_state();
	check( 'ok' === $s['status'], '#14 publish + shortcode po slugu → ok [NOWE]' );
	check( 18 === $s['id'], '#14 id === ID przejętej strony [NOWE]' );
	AIFAQ\PublicUi\PageGuard::ensure();
	check( 18 === ( $GLOBALS['__opt']['aifaq_page_id'] ?? null ), '#14 po ensure() aifaq_page_id === 18 [NOWE]' );
	check( true === is_int( $GLOBALS['__opt']['aifaq_page_id'] ?? null ), '#14 aifaq_page_id jest INTEM (czyta go WpContentSource::is_own_page) [NOWE]' );

	// #15 — obca opublikowana strona BEZ shortcode'u na slugu.
	aifaq_reset();
	aifaq_make_post( 42, 'publish', 'Generator FAQ — o narzędziu. Własny landing klienta.' );
	aifaq_cnt_reset();
	$s = AIFAQ\PublicUi\PageGuard::page_state();
	check( 'missing' === $s['status'], '#15 obca publish BEZ shortcode\'u → NIE przejmujemy, status missing [NOWE]' );
	check( 0 === $s['id'], '#15 id === 0 [NOWE]' );
	check( 0 === $GLOBALS['__cnt']['update_page_id'], '#15 update_option(aifaq_page_id) === 0 [NOWE]' );
	check( false !== strpos( (string) $s['error'], 'ID 42' ), '#15 komunikat zawiera "ID 42" [NOWE]' );
	check( false === strpos( (string) $s['error'], '%d' ), '#15 komunikat NIE zawiera surowego %d (dowód, że sprintf się wykonał) [NOWE]' );

	// #16 — kolizja slugu wygrywa NIEZALEŻNIE od stanu strony (krok 1 algorytmu).
	aifaq_reset( array( 'aifaq_settings' => array( 'page_slug' => 'generator-faq', 'language' => 'pl' ), 'aifaq_page_id' => 19 ) );
	aifaq_make_post( 19, 'publish', $SHORTCODE );
	$s = AIFAQ\PublicUi\PageGuard::page_state();
	check( 'slug_taken' === $s['status'], '#16 page_slug=generator-faq → slug_taken mimo idealnej strony [NOWE]' );
	aifaq_reset( array( 'aifaq_settings' => array( 'page_slug' => 'generator-faq', 'language' => 'pl' ) ) );
	$s = AIFAQ\PublicUi\PageGuard::page_state();
	check( 'slug_taken' === $s['status'], '#16 page_slug=generator-faq → slug_taken także bez strony [NOWE]' );

	// #17 — page_state() NIE zapisuje (§8.4).
	aifaq_reset( array( 'aifaq_page_id' => 20 ) );
	aifaq_make_post( 20, 'publish', $SHORTCODE );
	aifaq_cnt_reset();
	AIFAQ\PublicUi\PageGuard::page_state();
	check( 0 === $GLOBALS['__cnt']['update_option'], '#17/§8.4 licznik update_option po samym page_state() === 0 [NOWE]' );
	unset( $s );
} else {
	skip( 25, '#2-#17 sekcja B pominięta — brak klasy PageGuard [NOWE]' );
}

// ===========================================================================
// SEKCJA C — ensure(): tworzenie, ponowienia, backoff.  §8.3 #18-#20, #23-#27 + §8.4.
// ===========================================================================
echo "\n== C. ensure() — tworzenie, ponowienia, backoff ==\n";
if ( $has_guard ) {
	// #18 — para dodatnia dla WSZYSTKICH liczników `wp_insert_post === 0`.
	aifaq_reset();
	$s = AIFAQ\PublicUi\PageGuard::ensure();
	check( 1 === $GLOBALS['__cnt']['wp_insert_post'], '#18 missing → wp_insert_post dokładnie RAZ (para dodatnia do §8.4) [NOWE]' );
	check( isset( $GLOBALS['__opt']['aifaq_page_id'] ) && $GLOBALS['__opt']['aifaq_page_id'] > 0, '#18 ensure() zapisuje aifaq_page_id [NOWE]' );
	check( 'ok' === aifaq_saved_state()['status'], '#18 zapisany stan === ok [NOWE]' );
	check( 0 === aifaq_saved_state()['tries'], '#18 zapisany tries === 0 [NOWE]' );
	check( 0 === $GLOBALS['__cnt']['flush_rewrite_rules'], '§8.4 flush_rewrite_rules() w PageGuard === 0 [NOWE]' );

	// #19, #20 + §8.4 dla pozostałych statusów terminalnych.
	$no_insert = array(
		'ok'           => array( 'id' => 21, 'status' => 'publish', 'content' => '[aifaq_generator]', 'tag' => '#19' ),
		'trashed'      => array( 'id' => 22, 'status' => 'trash', 'content' => '[aifaq_generator]', 'tag' => '#20' ),
		'not_public'   => array( 'id' => 23, 'status' => 'draft', 'content' => '[aifaq_generator]', 'tag' => '§8.4' ),
		'no_shortcode' => array( 'id' => 24, 'status' => 'publish', 'content' => 'bez znacznika', 'tag' => '§8.4' ),
	);
	foreach ( $no_insert as $name => $cfg ) {
		aifaq_reset( array( 'aifaq_page_id' => $cfg['id'] ) );
		aifaq_make_post( $cfg['id'], $cfg['status'], $cfg['content'] );
		aifaq_cnt_reset();
		AIFAQ\PublicUi\PageGuard::ensure();
		check( 0 === $GLOBALS['__cnt']['wp_insert_post'], $cfg['tag'] . ' ensure() przy ' . $name . ' → wp_insert_post === 0 [NOWE]' );
	}
	aifaq_reset( array( 'aifaq_settings' => array( 'page_slug' => 'generator-faq', 'language' => 'pl' ) ) );
	aifaq_cnt_reset();
	AIFAQ\PublicUi\PageGuard::ensure();
	check( 0 === $GLOBALS['__cnt']['wp_insert_post'], '§8.4 ensure() przy slug_taken → wp_insert_post === 0 [NOWE]' );

	// #23 — WP_Error.
	aifaq_reset();
	$GLOBALS['__insert_error'] = new WP_Error( 'db', 'Baza odmówiła zapisu.' );
	AIFAQ\PublicUi\PageGuard::ensure();
	$saved = aifaq_saved_state();
	check( 'failed' === $saved['status'], '#23 wp_insert_post → WP_Error → status failed [NOWE]' );
	check( 1 === $saved['tries'], '#23 tries === 1 [NOWE]' );
	check( '' !== (string) $saved['error'], '#23 error niepuste [NOWE]' );
	check( 0 === (int) ( $GLOBALS['__opt']['aifaq_page_id'] ?? 0 ), '#23 aifaq_page_id nietknięte [NOWE]' );

	// #26 — backoff ZARAZ po porażce (bez zerowania `last`).
	aifaq_cnt_reset();
	AIFAQ\PublicUi\PageGuard::ensure();
	check( 0 === $GLOBALS['__cnt']['wp_insert_post'], '#26 backoff: ensure() zaraz po porażce → wp_insert_post === 0 [NOWE]' );

	// #24 — NAPRAWA BŁĘDU 0.B: kolejna próba po porażce NASTĘPUJE.
	aifaq_zero_backoff();
	aifaq_cnt_reset();
	AIFAQ\PublicUi\PageGuard::ensure();
	check( 1 === $GLOBALS['__cnt']['wp_insert_post'], '#24 po wyzerowaniu backoffu ensure() PONAWIA próbę (naprawa 0.B) [NOWE]' );
	check( 2 === aifaq_saved_state()['tries'], '#24 tries === 2 [NOWE]' );

	// #25 — auto-stop po MAX_TRIES.
	aifaq_reset();
	$GLOBALS['__insert_error'] = new WP_Error( 'db', 'Baza odmówiła zapisu.' );
	for ( $i = 0; $i < $MAX_TRIES + 3; $i++ ) {
		AIFAQ\PublicUi\PageGuard::ensure();
		aifaq_zero_backoff();
	}
	check( $MAX_TRIES === $GLOBALS['__cnt']['wp_insert_post'], '#25 licznik prób zatrzymuje się na MAX_TRIES === ' . $MAX_TRIES . ' [NOWE]' );

	// #27 — repair('create') zeruje tries i próbuje mimo tries >= MAX_TRIES.
	$GLOBALS['__insert_error'] = null;
	$GLOBALS['__can']          = true;
	aifaq_cnt_reset();
	AIFAQ\PublicUi\PageGuard::repair( 'create' );
	check( 1 === $GLOBALS['__cnt']['wp_insert_post'], '#27 repair(create) próbuje mimo tries >= MAX_TRIES [NOWE]' );
	check( 0 === aifaq_saved_state()['tries'], '#27 repair(create) wyzerował tries [NOWE]' );
	unset( $s, $saved, $no_insert );
} else {
	skip( 21, '#18-#27 sekcja C pominięta — brak klasy PageGuard [NOWE]' );
}

// ===========================================================================
// SEKCJA D — Świadome usunięcie i adopcja.  §8.3 #21, #22, #66, #67.
// ===========================================================================
echo "\n== D. Świadome usunięcie (deleted) i adopcja ==\n";
if ( $has_guard ) {
	// #21 — deleted_post na naszym ID; automat NIE odtwarza.
	aifaq_reset( array( 'aifaq_page_id' => 30 ) );
	aifaq_make_post( 30, 'publish', $SHORTCODE );
	unset( $GLOBALS['__posts'][30], $GLOBALS['__posts_by_slug']['generator-faq'] );   // strona znika trwale
	AIFAQ\PublicUi\PageGuard::on_post_deleted( 30 );
	check( 'deleted' === aifaq_saved_state()['status'], '#21 deleted_post na naszym ID → status deleted [NOWE]' );
	aifaq_cnt_reset();
	AIFAQ\PublicUi\PageGuard::ensure();
	check( 0 === $GLOBALS['__cnt']['wp_insert_post'], '#21/§8.4 ensure() po deleted → wp_insert_post === 0 (świadome usunięcie nie wraca) [NOWE]' );

	// #67 — martwe ID nie zostaje dla is_own_page().
	check( 0 === ( $GLOBALS['__opt']['aifaq_page_id'] ?? null ), '#67 po on_post_deleted() aifaq_page_id === 0 [NOWE]' );
	check( true === is_int( $GLOBALS['__opt']['aifaq_page_id'] ?? null ), '#67 aifaq_page_id jest INTEM [NOWE]' );

	// #22 — jedyna droga powrotu: świadome kliknięcie właściciela.
	$GLOBALS['__can'] = true;
	aifaq_cnt_reset();
	AIFAQ\PublicUi\PageGuard::repair( 'create' );
	check( 1 === $GLOBALS['__cnt']['wp_insert_post'], '#22 repair(create) po deleted → wp_insert_post dokładnie raz [NOWE]' );
	check( 0 === aifaq_saved_state()['deleted'], '#22 repair(create) zeruje znacznik deleted [NOWE]' );

	// #66 — adopcja strony odtworzonej RĘCZNIE przez użytkownika.
	aifaq_reset( array(
		'aifaq_page_id'    => 0,
		'aifaq_page_state' => array( 'status' => 'deleted', 'id' => 0, 'tries' => 0, 'last' => 0, 'error' => '', 'deleted' => time() - 100 ),
	) );
	aifaq_make_post( 55, 'publish', 'Ręcznie odtworzona: ' . $SHORTCODE );
	$s = AIFAQ\PublicUi\PageGuard::page_state();
	check( 'ok' === $s['status'] && 55 === $s['id'], '#66 stan deleted + ręcznie odtworzona strona z shortcode\'em → ok z jej ID [NOWE]' );
	aifaq_cnt_reset();
	AIFAQ\PublicUi\PageGuard::ensure();
	check( 0 === $GLOBALS['__cnt']['wp_insert_post'], '#66 ensure() po adopcji → wp_insert_post === 0 [NOWE]' );
	check( 55 === ( $GLOBALS['__opt']['aifaq_page_id'] ?? null ), '#66 aifaq_page_id === 55 (indekser znów rozpoznaje własną podstronę) [NOWE]' );
	check( 0 === aifaq_saved_state()['deleted'], '#66 zapisany deleted === 0 [NOWE]' );
	unset( $s );
} else {
	skip( 10, '#21/#22/#66/#67 sekcja D pominięta — brak klasy PageGuard [NOWE]' );
}

// ===========================================================================
// SEKCJA E — Zamek.  §8.3 #28-#31, #33.
// ===========================================================================
echo "\n== E. Zamek (add_option + static::) ==\n";
if ( $has_guard ) {
	// #28 — podklasa przejmuje sterowanie TYLKO przy `static::` (§3.6).
	aifaq_reset();
	AifaqGuardStub::ensure();
	check( 0 === $GLOBALS['__cnt']['wp_insert_post'], '#28 acquire_lock()=false w podklasie → wp_insert_post === 0 (dowód, że kod używa static::) [NOWE]' );

	// #29 — świeży zamek w opcjach.
	aifaq_reset( array( 'aifaq_page_lock' => (string) time() ) );
	AIFAQ\PublicUi\PageGuard::ensure();
	check( 0 === $GLOBALS['__cnt']['wp_insert_post'], '#29 świeży zamek → ensure() nie woła wp_insert_post [NOWE]' );

	// #30 — zamek przeterminowany jest przejmowany.
	aifaq_reset( array( 'aifaq_page_lock' => (string) ( time() - $LOCK_TTL - 10 ) ) );
	AIFAQ\PublicUi\PageGuard::ensure();
	check( 1 === $GLOBALS['__cnt']['wp_insert_post'], '#30 zamek przeterminowany przejęty → ensure() działa [NOWE]' );

	// #31 — po udanym ensure() zamek jest zwolniony.
	check( false === array_key_exists( 'aifaq_page_lock', $GLOBALS['__opt'] ), '#31 po udanym ensure() opcja aifaq_page_lock NIE istnieje [NOWE]' );

	// #33 — autoload per NAZWA opcji.
	check( 'no' === ( $GLOBALS['__autoload']['aifaq_page_state'] ?? null ), '#33 autoload[aifaq_page_state] === "no" [NOWE]' );
	aifaq_reset();
	AifaqGuardNoRelease::ensure();   // szew wstrzymujący release_lock — patrz ODCHYLENIA
	check( 'no' === ( $GLOBALS['__autoload']['aifaq_page_lock'] ?? null ), '#33 autoload[aifaq_page_lock] === "no" (mierzone przy NIEZWOLNIONYM zamku, nie po udanym ensure) [NOWE]' );
} else {
	skip( 6, '#28-#33 sekcja E pominięta — brak klasy PageGuard [NOWE]' );
}

// ===========================================================================
// SEKCJA F — Idempotencja slugu (krok 8b).  §8.3 #32 — PARA.
// ===========================================================================
echo "\n== F. Idempotencja slugu (ensure krok 8b) — para (a)/(b) ==\n";
if ( $has_guard ) {
	// (a) PRZEGRANY WYŚCIG o WŁASNĄ stronę: rywal pojawia się DOPIERO po insercie.
	aifaq_reset();
	$GLOBALS['__force_post_name'] = 'generator-faq-2';
	$GLOBALS['__on_insert']       = function ( $id ) {
		aifaq_make_post( 60, 'publish', 'Nasza strona: [aifaq_generator]' );
	};
	AIFAQ\PublicUi\PageGuard::ensure();
	check( 1 === $GLOBALS['__cnt']['wp_delete_post'], '#32a przegrany wyścig o WŁASNĄ stronę → wp_delete_post === 1 [NOWE]' );
	$del = $GLOBALS['__deleted_args'][0] ?? array( 0, false );
	check( true === $del[1], '#32a wp_delete_post wołane z force === true [NOWE]' );

	// (b) slug trzyma OBCA strona BEZ shortcode'u → generator-faq-2 jest POPRAWNYM wynikiem.
	aifaq_reset();
	aifaq_make_post( 42, 'publish', 'Obcy landing klienta, bez znacznika.' );
	AIFAQ\PublicUi\PageGuard::ensure();
	$new_id = (int) ( $GLOBALS['__opt']['aifaq_page_id'] ?? 0 );
	check( 0 === $GLOBALS['__cnt']['wp_delete_post'], '#32b obca strona bez shortcode\'u → wp_delete_post === 0 (brak pętli tworzenia i kasowania) [NOWE]' );
	check( $new_id > 0 && 42 !== $new_id, '#32b aifaq_page_id === ID nowo utworzonej strony (nie 42) [NOWE]' );
	aifaq_cnt_reset();
	AIFAQ\PublicUi\PageGuard::ensure();
	check( 0 === $GLOBALS['__cnt']['wp_insert_post'], '#32b DRUGIE ensure() → wp_insert_post === 0 (dowód braku pętli) [NOWE]' );
	unset( $del, $new_id );
} else {
	skip( 5, '#32 sekcja F pominięta — brak klasy PageGuard [NOWE]' );
}

// ===========================================================================
// SEKCJA G — Trójstan bramki aifaq_page_ok.  §8.3 #34, #35, #65.
// ===========================================================================
echo "\n== G. Trójstan taniej bramki aifaq_page_ok ==\n";
if ( $has_guard ) {
	// #34 — wartość flagi per status.
	$flag_cases = array(
		array( 'ok', '1', 70, 'publish', '[aifaq_generator]', array() ),
		array( 'missing', '', 0, '', '', array() ),
		array( 'failed', '', 0, '', '', array( 'aifaq_page_state' => array( 'status' => 'failed', 'id' => 0, 'tries' => 2, 'last' => 0, 'error' => 'x', 'deleted' => 0 ) ) ),
		array( 'trashed', '0', 71, 'trash', '[aifaq_generator]', array() ),
		array( 'not_public', '0', 72, 'draft', '[aifaq_generator]', array() ),
		array( 'no_shortcode', '0', 73, 'publish', 'bez znacznika', array() ),
	);
	foreach ( $flag_cases as $c ) {
		list( $name, $want, $pid, $pstatus, $pcontent, $extra ) = $c;
		$opts = $extra;
		if ( $pid > 0 ) { $opts['aifaq_page_id'] = $pid; }
		aifaq_reset( $opts );
		if ( $pid > 0 ) { aifaq_make_post( $pid, $pstatus, $pcontent ); }
		AIFAQ\PublicUi\PageGuard::refresh();
		check( $want === ( $GLOBALS['__opt']['aifaq_page_ok'] ?? null ), '#34 status ' . $name . ' → aifaq_page_ok === "' . $want . '" [NOWE]' );
	}
	// deleted
	aifaq_reset( array( 'aifaq_page_state' => array( 'status' => 'deleted', 'id' => 0, 'tries' => 0, 'last' => 0, 'error' => '', 'deleted' => time() - 50 ) ) );
	AIFAQ\PublicUi\PageGuard::refresh();
	check( '0' === ( $GLOBALS['__opt']['aifaq_page_ok'] ?? null ), '#34 status deleted → aifaq_page_ok === "0" [NOWE]' );
	// slug_taken
	aifaq_reset( array( 'aifaq_settings' => array( 'page_slug' => 'generator-faq', 'language' => 'pl' ) ) );
	AIFAQ\PublicUi\PageGuard::refresh();
	check( '0' === ( $GLOBALS['__opt']['aifaq_page_ok'] ?? null ), '#34 status slug_taken → aifaq_page_ok === "0" [NOWE]' );
	check( 'yes' === ( $GLOBALS['__autoload']['aifaq_page_ok'] ?? null ), '#34 autoload[aifaq_page_ok] === "yes" literalnie [NOWE]' );

	// #35 / #65 — tania bramka: stan zwarty nie kosztuje ANI JEDNEGO zapytania.
	if ( $has_short && method_exists( 'AIFAQ\PublicUi\Shortcode', 'maybe_ensure_page' ) ) {
		$gate_cases = array( '#35 flaga "1" (ok)' => '1', '#65 flaga "0" (deleted)' => '0', '#65 flaga "0" (trashed)' => '0' );
		foreach ( $gate_cases as $tag => $flagval ) {
			aifaq_reset( array( 'aifaq_page_ok' => $flagval ) );
			aifaq_cnt_reset();
			// Metoda INSTANCYJNA — wywołanie statyczne daje Error i wywala plik.
			aifaq_call( 'AIFAQ\PublicUi\Shortcode', 'maybe_ensure_page' );
			check( 0 === $GLOBALS['__cnt']['get_post'], $tag . ': maybe_ensure_page() nie woła get_post [NOWE]' );
			check( 0 === $GLOBALS['__cnt']['get_page_by_path'], $tag . ': maybe_ensure_page() nie woła get_page_by_path [NOWE]' );
		}
	} else {
		skip( 6, '#35/#65 pominięte — brak Shortcode::maybe_ensure_page() [NOWE]' );
	}
	unset( $flag_cases );
} else {
	skip( 15, '#34/#35/#65 sekcja G pominięta — brak klasy PageGuard [NOWE]' );
}

// ===========================================================================
// SEKCJA H — Autor, uprawnienia, repair().  §8.3 #38-#43.
// ===========================================================================
echo "\n== H. post_author, uprawnienia, repair() ==\n";
if ( $has_guard ) {
	// #38 — zalogowany BEZ manage_options nie zostaje autorem publicznej strony wtyczki.
	aifaq_reset();
	$GLOBALS['__can'] = false;
	$GLOBALS['__uid'] = 9;
	AIFAQ\PublicUi\PageGuard::ensure();
	$args = $GLOBALS['__inserted'][0] ?? array();
	check( ! isset( $args['post_author'] ) || 9 !== (int) $args['post_author'], '#38 Subskrybent bez manage_options NIE zostaje post_author [NOWE]' );

	// #39 — fallback na pierwszego administratora.
	aifaq_reset();
	$GLOBALS['__can']   = false;
	$GLOBALS['__uid']   = 0;
	$GLOBALS['__users'] = array( 7 );
	AIFAQ\PublicUi\PageGuard::ensure();
	$args = $GLOBALS['__inserted'][0] ?? array();
	check( 7 === (int) ( $args['post_author'] ?? 0 ), '#39 brak zalogowanego + get_users=[7] → post_author === 7 [NOWE]' );

	// #40 — repair() broni się SAMA, niezależnie od wołającego.
	aifaq_reset( array( 'aifaq_page_id' => 80 ) );
	aifaq_make_post( 80, 'draft', $SHORTCODE );
	$GLOBALS['__can'] = false;
	aifaq_cnt_reset();
	AIFAQ\PublicUi\PageGuard::repair( 'create' );
	AIFAQ\PublicUi\PageGuard::repair( 'publish' );
	check( 0 === $GLOBALS['__cnt']['wp_insert_post'], '#40 repair() bez manage_options → wp_insert_post === 0 [NOWE]' );
	check( 0 === $GLOBALS['__cnt']['wp_update_post'], '#40 repair() bez manage_options → wp_update_post === 0 [NOWE]' );

	// #41 — nie publikujemy cudzego szkicu, który przestał być naszą podstroną.
	aifaq_reset( array( 'aifaq_page_id' => 81 ) );
	aifaq_make_post( 81, 'draft', 'Klient przerobił to na własny szkic — bez znacznika.' );
	$GLOBALS['__can'] = true;
	aifaq_cnt_reset();
	AIFAQ\PublicUi\PageGuard::repair( 'publish' );
	check( 0 === $GLOBALS['__cnt']['wp_update_post'], '#41 repair(publish) na wpisie BEZ shortcode\'u → wp_update_post === 0 [NOWE]' );
	check( 0 === (int) ( $GLOBALS['__opt']['aifaq_page_id'] ?? -1 ), '#41 repair(publish) zeruje aifaq_page_id [NOWE]' );

	// #42 — porzucenie wyniku wp_untrash_post() jest ZAKAZANE (§1 pkt 5).
	aifaq_reset( array( 'aifaq_page_id' => 82 ) );
	aifaq_make_post( 82, 'trash', $SHORTCODE );
	$GLOBALS['__can']            = true;
	$GLOBALS['__untrash_result'] = false;
	AIFAQ\PublicUi\PageGuard::repair( 'restore' );
	$err = (string) ( aifaq_saved_state()['error'] ?? '' );
	check( '' !== $err, '#42 wp_untrash_post()=false → zapisany NIEPUSTY error [NOWE]' );
	if ( $has_notice ) {
		$GLOBALS['__screen'] = 'dashboard';
		ob_start();
		AIFAQ\Admin\PageNotice::render();
		$out = (string) ob_get_clean();
		check( false !== strpos( $out, htmlspecialchars( $err, ENT_QUOTES ) ) || false !== strpos( $out, $err ), '#42 PageNotice::render() WYPISUJE treść błędu [NOWE]' );
		unset( $out );
	} else {
		check( false, '#42 pominięte — brak klasy PageNotice [NOWE]' );
	}

	// #43 — post_name obowiązkowy przy restore (WP dokleja __trashed).
	aifaq_reset( array( 'aifaq_page_id' => 83 ) );
	aifaq_make_post( 83, 'trash', $SHORTCODE, 'generator-faq__trashed' );
	$GLOBALS['__can']            = true;
	$GLOBALS['__untrash_result'] = new WP_Post( array( 'ID' => 83, 'post_type' => 'page', 'post_status' => 'draft', 'post_name' => 'generator-faq__trashed', 'post_content' => $SHORTCODE ) );
	AIFAQ\PublicUi\PageGuard::repair( 'restore' );
	$upd = $GLOBALS['__updated'][0] ?? array();
	check( 'generator-faq' === ( $upd['post_name'] ?? null ), '#43 repair(restore) przekazuje post_name === "generator-faq" [NOWE]' );
	unset( $args, $err, $upd );
} else {
	skip( 9, '#38-#43 sekcja H pominięta — brak klasy PageGuard [NOWE]' );
}

// ===========================================================================
// SEKCJA I — Settings::sanitize(): bramka slugu.  §8.3 #44-#48.
// ===========================================================================
echo "\n== I. Settings::sanitize() — bramka slugu ==\n";
if ( $has_set && method_exists( 'AIFAQ\Core\Settings', 'sanitize' ) ) {
	aifaq_reset();
	$out = AIFAQ\Core\Settings::sanitize( array( 'page_slug' => 'generator-faq' ) );
	check( 'faqgenerator' === ( $out['page_slug'] ?? null ), '#44 page_slug="generator-faq" ODRZUCONY → zostaje faqgenerator [NOWE]' );
	check( 1 === $GLOBALS['__cnt']['add_settings_error'], '#48 odrzucenie rejestruje DOKŁADNIE 1 add_settings_error [NOWE]' );

	aifaq_reset();
	$out = AIFAQ\Core\Settings::sanitize( array( 'page_slug' => 'moj-generator' ) );
	check( 'moj-generator' === ( $out['page_slug'] ?? null ), '#45 page_slug="moj-generator" PRZYJĘTY [PARA-DODATNIA]' );
	check( 0 === $GLOBALS['__cnt']['add_settings_error'], '#48 przyjęcie → add_settings_error === 0 [PARA-DODATNIA]' );

	aifaq_reset();
	$out = AIFAQ\Core\Settings::sanitize( array() );
	check( 'faqgenerator' === ( $out['page_slug'] ?? null ), '#46 pole nieprzysłane → wartość nietknięta [PARA-DODATNIA]' );

	aifaq_reset();
	$GLOBALS['__url_to_postid'] = 7;   // adres zajęty przez wpis/kategorię/archiwum
	$out = AIFAQ\Core\Settings::sanitize( array( 'page_slug' => 'oferta' ) );
	check( 'faqgenerator' === ( $out['page_slug'] ?? null ), '#47 slug zajęty wg url_to_postid → ODRZUCONY [NOWE]' );
	unset( $out );
} else {
	skip( 6, '#44-#48 sekcja I pominięta — brak Settings::sanitize() [NOWE]' );
}

// ===========================================================================
// SEKCJA J — PageNotice::render().  §8.3 #49-#55.
// ===========================================================================
echo "\n== J. PageNotice::render() ==\n";
if ( $has_notice ) {
	$render_out = function () {
		ob_start();
		AIFAQ\Admin\PageNotice::render();
		return (string) ob_get_clean();
	};

	// #49 — bramka capa.
	aifaq_reset();
	$GLOBALS['__can'] = false;
	check( '' === $render_out(), '#49 render() bez manage_options → zero wyjścia [NOWE]' );

	// #50 — get_current_screen() = null: zero wyjścia I ZERO ostrzeżeń PHP.
	aifaq_reset();
	$GLOBALS['__can']    = true;
	$GLOBALS['__screen'] = null;
	$warn = 0;
	set_error_handler( function ( $no, $str, $f = '', $l = 0 ) use ( &$warn ) {
		if ( E_WARNING === $no || E_DEPRECATED === $no || E_NOTICE === $no ) { $warn++; }
		return true;
	} );
	$out = $render_out();
	restore_error_handler();
	check( '' === $out, '#50 get_current_screen()=null → zero wyjścia [NOWE]' );
	check( 0 === $warn, '#50 get_current_screen()=null → zero ostrzeżeń PHP (===0) [NOWE]' );

	// #51 — para ekranu.
	aifaq_reset();
	$GLOBALS['__can']    = true;
	$GLOBALS['__screen'] = 'dashboard';
	$out_dash = $render_out();
	check( strlen( $out_dash ) > 0, '#51 missing + cap + ekran dashboard → wyjście niepuste (jedyny dozwolony > 0) [NOWE]' );
	$GLOBALS['__screen'] = 'edit-post';
	check( '' === $render_out(), '#51 ten sam stan, ekran edit-post → wyjście === "" [PARA-DODATNIA]' );

	// #52 — missing.
	$GLOBALS['__screen'] = 'dashboard';
	$out = $render_out();
	check( false !== strpos( $out, 'notice-warning' ), '#52 missing → notice-warning [NOWE]' );
	check( false !== strpos( $out, 'aifaq_fix=create' ), '#52 missing → akcja aifaq_fix=create [NOWE]' );

	// #53 — deleted.
	aifaq_reset( array( 'aifaq_page_state' => array( 'status' => 'deleted', 'id' => 0, 'tries' => 0, 'last' => 0, 'error' => '', 'deleted' => time() - 10 ) ) );
	$GLOBALS['__can']    = true;
	$GLOBALS['__screen'] = 'dashboard';
	$out = $render_out();
	check( false !== strpos( $out, 'notice-info' ), '#53 deleted → notice-info [NOWE]' );
	check( false !== strpos( $out, 'aifaq_fix=create' ), '#53 deleted → akcja aifaq_fix=create [NOWE]' );

	// #54-bis — DROGA KLIKNIĘCIA do zamknięcia musi ISTNIEĆ W UI (§3.9: link `dismiss`
	// w KAŻDYM wierszu poza `slug_taken`). Asercja DOPISANA przez E5: mutacja 16/§8.7 nr 12
	// („usuń link dismiss z wiersza no_shortcode") NIE czerwieniła żadnej asercji z §8.3,
	// bo #54 bada wyłącznie skutek bezpośredniego wywołania handle_fix() — czyli dokładnie
	// tę dziurę, którą §11.B poz. 6 uznała za zamkniętą. Zgłoszone w ODCHYLENIA.md, poz. 3.
	aifaq_reset( array(
		'aifaq_page_id'    => 92,
		'aifaq_page_state' => array( 'status' => 'no_shortcode', 'id' => 92, 'tries' => 0, 'last' => 0, 'error' => '', 'deleted' => 0 ),
	) );
	aifaq_make_post( 92, 'publish', 'bez znacznika' );
	$GLOBALS['__can']    = true;
	$GLOBALS['__screen'] = 'dashboard';
	check( false !== strpos( $render_out(), 'aifaq_fix=dismiss' ), '#54-bis wiersz no_shortcode NIESIE link aifaq_fix=dismiss (droga kliknięcia istnieje) [NOWE]' );

	aifaq_reset( array( 'aifaq_settings' => array( 'page_slug' => 'generator-faq', 'language' => 'pl' ) ) );
	if ( $has_guard ) { AIFAQ\PublicUi\PageGuard::refresh(); }
	$GLOBALS['__can']    = true;
	$GLOBALS['__screen'] = 'dashboard';
	check( false === strpos( $render_out(), 'aifaq_fix=dismiss' ), '#54-bis wiersz slug_taken NIE ma dismiss (alarmu nie wolno wyłączyć) [PARA-DODATNIA]' );

	// #54 — zamknięcie PER STATUS: zmiana stanu unieważnia zamknięcie.
	aifaq_reset( array(
		'aifaq_page_id'                => 90,
		'aifaq_page_notice_dismissed'  => 'no_shortcode',
	) );
	aifaq_make_post( 90, 'publish', 'bez znacznika' );
	$GLOBALS['__can']    = true;
	$GLOBALS['__screen'] = 'dashboard';
	check( '' === $render_out(), '#54 po dismiss przy no_shortcode → cisza [NOWE]' );
	$GLOBALS['__posts'][90]->post_status = 'trash';
	check( '' !== $render_out(), '#54 po zmianie stanu na trashed → komunikat WRACA [NOWE]' );

	// #55 — action_url(): asercja po $GLOBALS['__nonce_calls'], NIGDY po samym _wpnonce.
	aifaq_reset();
	$url = AIFAQ\Admin\PageNotice::action_url( 'create' );
	check( 1 === count( $GLOBALS['__nonce_calls'] ), '#55 action_url(create) → dokładnie 1 wywołanie wp_nonce_url [NOWE]' );
	$nc = $GLOBALS['__nonce_calls'][0] ?? array( '', '' );
	check( ( $nc[1] ?? null ) === AIFAQ\Admin\PageNotice::ACTION, '#55 wp_nonce_url wołane z PageNotice::ACTION [NOWE]' );
	check( false !== strpos( (string) $nc[0], 'admin-post.php' ), '#55 URL wejściowy zawiera admin-post.php [NOWE]' );
	check( false !== strpos( (string) $nc[0], 'action=aifaq_page_fix' ), '#55 URL wejściowy zawiera action=aifaq_page_fix [NOWE]' );
	check( false !== strpos( (string) $nc[0], 'aifaq_fix=create' ), '#55 URL wejściowy zawiera aifaq_fix=create [NOWE]' );
	unset( $out, $out_dash, $url, $nc, $warn );
} else {
	skip( 16, '#49-#55 sekcja J pominięta — brak klasy PageNotice [NOWE]' );
}

// ===========================================================================
// SEKCJA K — PageNotice::handle_fix().  §8.3 #56-#60.
// Jedyna ścieżka Kroku 18 zmieniająca stan z przeglądarki i jedyna powierzchnia CSRF.
// ===========================================================================
echo "\n== K. PageNotice::handle_fix() — cap, nonce, routing akcji ==\n";
if ( $has_notice ) {
	// #56 + #60b — bramka capa. Wymaga jawnego `return` po wp_die w kodzie produkcyjnym:
	// atrapa wp_die NIE przerywa wykonania (§3.9.1).
	aifaq_reset();
	$GLOBALS['__can'] = false;
	$_GET[ AIFAQ\Admin\PageNotice::PARAM ] = 'create';
	AifaqNoticeStub::handle_fix();
	check( 1 === $GLOBALS['__cnt']['wp_die'], '#56 handle_fix() bez capa → wp_die === 1 [NOWE]' );
	check( 0 === count( $GLOBALS['__repair_calls'] ), '#56 handle_fix() bez capa → licznik repair === 0 [NOWE]' );
	check( 0 === $GLOBALS['__cnt']['wp_safe_redirect'], '#60 ścieżka odrzucona brakiem capa → wp_safe_redirect === 0 [NOWE]' );

	// #57 — bramka nonce'a.
	aifaq_reset();
	$GLOBALS['__can'] = true;
	$_GET[ AIFAQ\Admin\PageNotice::PARAM ] = 'create';
	AifaqNoticeStub::handle_fix();
	check( 1 === $GLOBALS['__cnt']['check_admin_referer'], '#57 handle_fix() z capem → check_admin_referer === 1 (bez tego CSRF) [NOWE]' );
	check( ( $GLOBALS['__referer_calls'][0] ?? null ) === AIFAQ\Admin\PageNotice::ACTION, '#57 check_admin_referer wołane z PageNotice::ACTION [NOWE]' );

	// #59a + #60a — routing akcji `create`.
	check( 1 === count( $GLOBALS['__repair_calls'] ), '#59 aifaq_fix=create → licznik repair === 1 [NOWE]' );
	check( 'create' === ( $GLOBALS['__repair_calls'][0] ?? null ), '#59 repair() wołane z argumentem "create" [NOWE]' );
	check( 1 === $GLOBALS['__cnt']['wp_safe_redirect'], '#60 ścieżka sukcesu → wp_safe_redirect === 1 [NOWE]' );

	// #59b — nieznana akcja nic nie robi.
	aifaq_reset();
	$GLOBALS['__can'] = true;
	$_GET[ AIFAQ\Admin\PageNotice::PARAM ] = 'cokolwiek';
	AifaqNoticeStub::handle_fix();
	check( 0 === count( $GLOBALS['__repair_calls'] ), '#59 aifaq_fix=cokolwiek → licznik repair === 0 [NOWE]' );

	// #58 — dismiss zapisuje LITERAŁ bieżącego statusu, nie "1".
	aifaq_reset( array( 'aifaq_page_id' => 91 ) );
	aifaq_make_post( 91, 'publish', 'bez znacznika' );
	$GLOBALS['__can'] = true;
	$_GET[ AIFAQ\Admin\PageNotice::PARAM ] = 'dismiss';
	AifaqNoticeStub::handle_fix();
	if ( $has_guard ) {
		$cur = (string) AIFAQ\PublicUi\PageGuard::page_state()['status'];
		check( $cur === (string) ( $GLOBALS['__opt']['aifaq_page_notice_dismissed'] ?? null ), '#58 dismiss zapisuje bieżący status ("' . $cur . '") [NOWE]' );
		unset( $cur );
	} else {
		check( false, '#58 pominięte — brak klasy PageGuard (nie da się ustalić bieżącego statusu) [NOWE]' );
	}
	check( 0 === count( $GLOBALS['__repair_calls'] ), '#58 dismiss → licznik repair === 0 [NOWE]' );
	unset( $_GET[ AIFAQ\Admin\PageNotice::PARAM ] );
} else {
	skip( 11, '#56-#60 sekcja K pominięta — brak klasy PageNotice [NOWE]' );
}

// ===========================================================================
// SEKCJA L — Plugin: wpięcie hooków.  §8.3 #61-#64.
// `method_exists` jest z definicji fałszywie zielone, więc rejestrację
// weryfikujemy LITERAŁAMI w źródle i ich POZYCJĄ względem if ( is_admin() ).
// ===========================================================================
echo "\n== L. Plugin — wpięcie hooków ==\n";
if ( $has_plugin ) {
	// #61 — Plugin NIGDY nie jest instancjonowany (konstruktor private + dbDelta).
	foreach ( array( 'render_page_notice', 'handle_page_fix', 'audit_page', 'on_page_event', 'on_page_deleted' ) as $mth ) {
		check( true === method_exists( 'AIFAQ\Core\Plugin', $mth ), '#61 Plugin::' . $mth . '() istnieje [NOWE]' );
	}

	// #62 — literały hooków w źródle.
	$src_path = __DIR__ . '/../src/Core/Plugin.php';
	$src      = file_exists( $src_path ) ? (string) file_get_contents( $src_path ) : '';
	foreach ( array( "'admin_notices'", "'admin_post_aifaq_page_fix'", "'trashed_post'", "'untrashed_post'", "'deleted_post'" ) as $lit ) {
		check( 1 === substr_count( $src, $lit ), '#62 Plugin.php zawiera ' . $lit . ' dokładnie raz [NOWE]' );
	}

	// #63 — pozycja: trashed_post POZA is_admin(), admin_notices WEWNĄTRZ.
	// Osłona `false !==` na KAŻDYM strpos: w PHP 8 `false < 12345` jest PRAWDĄ,
	// więc bez niej asercja jest zielona także na kodzie bez tych hooków.
	$p1 = strpos( $src, "'trashed_post'" );
	$p2 = strpos( $src, 'if ( is_admin() )' );
	$p3 = strpos( $src, "'admin_notices'" );
	check( false !== $p1 && false !== $p2 && false !== $p3 && $p1 < $p2 && $p3 > $p2, '#63 trashed_post PRZED if ( is_admin() ), admin_notices PO (osłony false !==) [NOWE]' );

	// #64 — bramki taniości admin_init (metoda STATYCZNA, wołana wprost).
	if ( method_exists( 'AIFAQ\Core\Plugin', 'audit_page' ) ) {
		$gates = array(
			'current_user_can=false' => array( 'can' => false, 'ajax' => false, 'cron' => false ),
			'wp_doing_ajax()=true'   => array( 'can' => true, 'ajax' => true, 'cron' => false ),
			'wp_doing_cron()=true'   => array( 'can' => true, 'ajax' => false, 'cron' => true ),
		);
		foreach ( $gates as $tag => $g ) {
			aifaq_reset();
			$GLOBALS['__can']        = $g['can'];
			$GLOBALS['__doing_ajax'] = $g['ajax'];
			$GLOBALS['__doing_cron'] = $g['cron'];
			aifaq_cnt_reset();
			AIFAQ\Core\Plugin::audit_page();
			check( 0 === $GLOBALS['__cnt']['get_page_by_path'], '#64 audit_page() przy ' . $tag . ' → get_page_by_path === 0 [NOWE]' );
		}
		unset( $gates );
	} else {
		skip( 3, '#64 pominięte — brak Plugin::audit_page() [NOWE]' );
	}
	unset( $src, $src_path, $p1, $p2, $p3 );
} else {
	skip( 14, '#61-#64 sekcja L pominięta — brak klasy Plugin [NOWE]' );
}

// ===========================================================================
// PODŁOGA POKRYCIA (§8.1 pkt 4) — liczona PRZED własnym check().
// ===========================================================================
echo "\n== Podłoga pokrycia ==\n";
$floor = $ran;
check( $floor >= 90, 'wykonano co najmniej 90 asercji (było ' . $floor . ')' );

// Wartownik końca pliku (§8.1 pkt 15).
check( true, 'plik dobiegł końca' );

echo "\n=== " . ( 0 === $fail ? 'WSZYSTKIE OK' : "BŁĘDÓW: {$fail}" ) . " (asercji: {$ran}) ===\n";
exit( $fail > 0 ? 1 : 0 );
