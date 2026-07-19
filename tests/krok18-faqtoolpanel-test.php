<?php
/**
 * Testy: panel narzędzia FAQ na froncie — markup, config, i18n, szósta zakładka,
 * WPIĘCIE ASSETÓW i kontekst renderowania (Krok 18, Etap 5).
 *
 * NAPISANE W CIEMNO wyłącznie z `plany/krok18/KONTRAKT.md` (wersja k18-v3), bez zaglądania
 * w kod Etapów 1-4. Rozbieżność test<->implementacja jest dowodem, że kontrakt był
 * nieprecyzyjny — idzie do `plany/krok18/ODCHYLENIA.md`, nie do cichej korekty asercji.
 *
 * Pokrycie: KONTRAKT §8.2 #1-#38 + §8.4 (licznik `wp_enqueue_script('aifaq-faq-tool')` dla gościa).
 * Etykiety ról: [NOWE] = pokrycie Kroku 18; [REGRESJA]/[PARA-DODATNIA] = przechodzi także
 * na NIEZMIENIONYM kodzie, więc NIE jest dowodem, że Krok 18 działa.
 *
 * URUCHOMIENIE:  php tests/krok18-faqtoolpanel-test.php
 * Kod wyjścia: 0 = OK, 1 = błędy.
 *
 * @package AI_FAQ_Generator
 */

// ---------------------------------------------------------------------------
// PREAMBUŁA — stałe PRZED pierwszym `require` (§8.1 pkt 14).
// Brak ABSPATH => pierwszy require robi `exit;` z kodem 0 => runner zielony na zerze asercji.
// ---------------------------------------------------------------------------
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
if ( ! defined( 'AIFAQ_PLUGIN_URL' ) ) { define( 'AIFAQ_PLUGIN_URL', 'http://test.local/wp-content/plugins/ai-faq-generator/' ); }
if ( ! defined( 'AIFAQ_VERSION' ) ) { define( 'AIFAQ_VERSION', '0.0.0-test' ); }
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
if ( ! defined( 'OBJECT' ) ) { define( 'OBJECT', 'OBJECT' ); }
if ( ! defined( 'AIFAQ_TESTING' ) ) { define( 'AIFAQ_TESTING', true ); }

// ---------------------------------------------------------------------------
// Klasy WP (§3.6.1 — kształt ZAMROŻONY).
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
$GLOBALS['__opt']       = array();
$GLOBALS['__logged']    = false;
$GLOBALS['__can']       = false;
$GLOBALS['__uid']       = 0;
$GLOBALS['__enq']       = array();
$GLOBALS['__posts']     = array();
$GLOBALS['__cnt']       = array( 'nocache_headers' => 0 );
$GLOBALS['__ctx']       = array( 'is_singular' => true, 'doing_filter' => '', 'is_feed' => false, 'is_main_query' => true, 'in_the_loop' => true );

// ---------------------------------------------------------------------------
// Shimy WP — WSZYSTKIE przed pierwszym `require` (§8.1 pkt 2), komplet z §3.1.1.
// UWAGA: `esc_html_e`/`esc_attr_e` CELOWO NIE SĄ shimowane — §3.1 pkt 8 zabrania ich
// w kodzie widgetu, a ich brak w teście jest właśnie tą kontrolą.
// ---------------------------------------------------------------------------
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $s ) { return (string) $s; } }
if ( ! function_exists( 'esc_url_raw' ) ) { function esc_url_raw( $s ) { return (string) $s; } }
if ( ! function_exists( 'esc_textarea' ) ) { function esc_textarea( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'get_bloginfo' ) ) { function get_bloginfo( $k = '' ) { return 'charset' === $k ? 'UTF-8' : 'Test Site'; } }
if ( ! function_exists( 'home_url' ) ) { function home_url( $p = '/' ) { return 'http://test.local' . $p; } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $p = '' ) { return 'http://test.local/wp-admin/' . $p; } }
if ( ! function_exists( 'rest_url' ) ) { function rest_url( $p = '' ) { return 'http://test.local/wp-json/' . ltrim( (string) $p, '/' ); } }
if ( ! function_exists( 'wp_create_nonce' ) ) { function wp_create_nonce( $a = '' ) { return 'TESTNONCE'; } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $d, $o = 0 ) { return json_encode( $d, $o ); } }
if ( ! function_exists( 'selected' ) ) { function selected( $a, $b = true, $e = true ) { $r = ( (string) $a === (string) $b ) ? ' selected="selected"' : ''; if ( $e ) { echo $r; } return $r; } }
if ( ! function_exists( 'number_format_i18n' ) ) { function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, (int) $d ); } }

if ( ! function_exists( 'get_option' ) ) { function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__opt'] ) ? $GLOBALS['__opt'][ $k ] : $d; } }
if ( ! function_exists( 'update_option' ) ) { function update_option( $k, $v, $a = null ) { $GLOBALS['__opt'][ $k ] = $v; return true; } }
if ( ! function_exists( 'add_option' ) ) { function add_option( $k, $v = '', $dep = '', $a = 'yes' ) { if ( array_key_exists( $k, $GLOBALS['__opt'] ) ) { return false; } $GLOBALS['__opt'][ $k ] = $v; return true; } }
if ( ! function_exists( 'delete_option' ) ) { function delete_option( $k ) { unset( $GLOBALS['__opt'][ $k ] ); return true; } }

if ( ! function_exists( 'is_user_logged_in' ) ) { function is_user_logged_in() { return (bool) $GLOBALS['__logged']; } }
if ( ! function_exists( 'current_user_can' ) ) { function current_user_can( $c ) { return (bool) $GLOBALS['__can']; } }
if ( ! function_exists( 'get_current_user_id' ) ) { function get_current_user_id() { return (int) $GLOBALS['__uid']; } }

// Kolejka assetów — zapis wywołań do $GLOBALS['__enq'] (uchwyt, argumenty, pozycja).
if ( ! function_exists( 'wp_enqueue_style' ) ) { function wp_enqueue_style( $h, $src = '', $deps = array(), $ver = false, $media = 'all' ) { $GLOBALS['__enq'][] = array( 'type' => 'style', 'handle' => (string) $h, 'src' => (string) $src, 'deps' => $deps, 'ver' => $ver ); } }
if ( ! function_exists( 'wp_enqueue_script' ) ) { function wp_enqueue_script( $h, $src = '', $deps = array(), $ver = false, $footer = false ) { $GLOBALS['__enq'][] = array( 'type' => 'script', 'handle' => (string) $h, 'src' => (string) $src, 'deps' => $deps, 'ver' => $ver ); } }
if ( ! function_exists( 'wp_register_script' ) ) { function wp_register_script( $h, $src = '', $deps = array(), $ver = false, $footer = false ) { $GLOBALS['__enq'][] = array( 'type' => 'register', 'handle' => (string) $h, 'src' => (string) $src ); return true; } }
if ( ! function_exists( 'wp_add_inline_script' ) ) { function wp_add_inline_script( $h, $data, $pos = 'after' ) { $GLOBALS['__enq'][] = array( 'type' => 'inline', 'handle' => (string) $h, 'data' => (string) $data, 'position' => (string) $pos ); return true; } }
if ( ! function_exists( 'wp_localize_script' ) ) { function wp_localize_script( $h, $name, $data ) { $GLOBALS['__enq'][] = array( 'type' => 'localize', 'handle' => (string) $h, 'object' => (string) $name, 'data' => $data ); return true; } }

// Nagłówki i kontekst pętli.
if ( ! function_exists( 'nocache_headers' ) ) { function nocache_headers() { $GLOBALS['__cnt']['nocache_headers']++; } }
if ( ! function_exists( 'headers_sent' ) ) { function headers_sent( &$f = null, &$l = null ) { return false; } }
if ( ! function_exists( 'is_singular' ) ) { function is_singular( $t = '' ) { return (bool) $GLOBALS['__ctx']['is_singular']; } }
if ( ! function_exists( 'has_shortcode' ) ) { function has_shortcode( $c, $t ) { return false !== strpos( (string) $c, '[' . $t ); } }
if ( ! function_exists( 'get_post' ) ) { function get_post( $id = null ) { if ( null === $id ) { return $GLOBALS['__posts']['current'] ?? null; } return $GLOBALS['__posts'][ (int) $id ] ?? null; } }
if ( ! function_exists( 'doing_filter' ) ) { function doing_filter( $h = null ) { return (string) $GLOBALS['__ctx']['doing_filter'] === (string) $h; } }
if ( ! function_exists( 'is_feed' ) ) { function is_feed( $t = '' ) { return (bool) $GLOBALS['__ctx']['is_feed']; } }
if ( ! function_exists( 'is_main_query' ) ) { function is_main_query() { return (bool) $GLOBALS['__ctx']['is_main_query']; } }
if ( ! function_exists( 'in_the_loop' ) ) { function in_the_loop() { return (bool) $GLOBALS['__ctx']['in_the_loop']; } }

// Rejestracja hooków (Shortcode::register() bywa wołane pośrednio).
if ( ! function_exists( 'add_action' ) ) { function add_action( $h, $cb, $p = 10, $a = 1 ) { return true; } }
if ( ! function_exists( 'add_filter' ) ) { function add_filter( $h, $cb, $p = 10, $a = 1 ) { return true; } }
if ( ! function_exists( 'add_shortcode' ) ) { function add_shortcode( $t, $cb ) { return true; } }
if ( ! function_exists( 'shortcode_atts' ) ) { function shortcode_atts( $pairs, $atts, $sc = '' ) { return array_merge( (array) $pairs, (array) $atts ); } }

// $wpdb — AppShell::render_body() w gałęzi właściciela woła IndexController::stats() (§8.1 pkt 3).
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

/**
 * Wywołuje metodę niezależnie od tego, czy jest statyczna, czy instancyjna.
 * Kontrakt raz pisze `Shortcode::render()` (§8.2 #38), a raz wymaga instancji
 * (`maybe_nocache` jako `array( $this, … )`, §3.3 A0) — statyczne wywołanie metody
 * instancyjnej to Error wywalający cały plik bez ani jednej asercji.
 */
function aifaq_call( $class, $method, $args = array() ) {
	$r = new ReflectionMethod( $class, $method );
	$r->setAccessible( true );
	if ( $r->isStatic() ) { return $r->invokeArgs( null, $args ); }
	return $r->invokeArgs( new $class(), $args );
}

function aifaq_reset_enq() { $GLOBALS['__enq'] = array(); }
function aifaq_enq_by( $type, $handle ) {
	$out = array();
	foreach ( $GLOBALS['__enq'] as $e ) {
		if ( $e['type'] === $type && $e['handle'] === $handle ) { $out[] = $e; }
	}
	return $out;
}
function aifaq_set_role( $logged, $can, $uid = 0 ) {
	$GLOBALS['__logged'] = $logged;
	$GLOBALS['__can']    = $can;
	$GLOBALS['__uid']    = $uid;
}

// ---------------------------------------------------------------------------
// Ładowanie kodu — kolejność ZAMROŻONA (§8.1 pkt 13), FaqToolPanel PRZED AppShell.
// Każdy require pod file_exists(): kod Etapów 1-3 może jeszcze nie istnieć (E1-E5
// idą równolegle), a goły require = Fatal error = zero asercji.
// ---------------------------------------------------------------------------
$aifaq_files = array(
	'src/Data/Schema.php',
	'src/Data/Repository.php',
	'src/Data/KnowledgeRepository.php',
	'src/Data/CacheRepository.php',
	'src/Core/Settings.php',
	'src/Rag/RagService.php',
	'src/Rest/RestController.php',
	'src/Admin/Menu.php',
	'src/Admin/IndexController.php',
	'src/PublicUi/GeneratorPage.php',
	'src/App/HistoryPanel.php',
	'src/App/GenerationsPanel.php',
	'src/App/FaqToolPanel.php',
	'src/App/AppShell.php',
	'src/Admin/FaqToolPage.php',
	'src/PublicUi/Shortcode.php',
);
foreach ( $aifaq_files as $aifaq_rel ) {
	$aifaq_p = __DIR__ . '/../' . $aifaq_rel;
	if ( file_exists( $aifaq_p ) ) { require_once $aifaq_p; }
}

$aifaq_settings_base = array( 'api_key' => 'SECRET-K18', 'language' => 'pl', 'page_slug' => 'faqgenerator', 'model' => 'gemini-2.5-flash' );
$GLOBALS['__opt']['aifaq_settings'] = $aifaq_settings_base;

$has_panel     = class_exists( 'AIFAQ\App\FaqToolPanel' );
$has_shell     = class_exists( 'AIFAQ\App\AppShell' );
$has_shortcode = class_exists( 'AIFAQ\PublicUi\Shortcode' );
$has_genpage   = class_exists( 'AIFAQ\PublicUi\GeneratorPage' );
$has_ftpage    = class_exists( 'AIFAQ\Admin\FaqToolPage' );

// Lista 34 kluczy i18n (§2.3) — kolejność zamrożona.
$aifaq_i18n_keys = array(
	'title', 'lead', 'labelTopic', 'phTopic', 'labelDesc', 'phDesc', 'labelCount', 'generate',
	'generating', 'needTopic', 'emptyMsg', 'errMsg', 'doneFmt', 'colQ', 'colA', 'colActions',
	'edit', 'save', 'cancel', 'del', 'copy', 'copyAll', 'copied', 'confirmDel', 'export', 'expHint',
	'expCopy', 'expDownload', 'expCopied', 'expDownloaded', 'expEmpty', 'regenLoading',
	'regenLoaded', 'regenErr',
);
// 15 identyfikatorów (§2.1) — kolejność zamrożona.
$aifaq_ids = array(
	'aifaq-ft-panel', 'aifaq-ft-form', 'aifaq-ft-topic', 'aifaq-ft-desc', 'aifaq-ft-count',
	'aifaq-ft-generate', 'aifaq-ft-status', 'aifaq-ft-results', 'aifaq-ft-copyall',
	'aifaq-ft-tbody', 'aifaq-ft-export', 'aifaq-ft-exp-copy', 'aifaq-ft-exp-download',
	'aifaq-ft-exp-status', 'aifaq-ft-exp-output',
);

// ===========================================================================
// SEKCJA A — GOŚĆ: maybe_nocache().  §8.2 #35, człon pierwszy.
// MUSI wykonać się jako PIERWSZA w pliku: `define('DONOTCACHEPAGE')` jest
// NIEODWRACALNE w obrębie procesu (§8.1 pkt 8), więc gość przed właścicielem.
// ===========================================================================
echo "== A. GOŚĆ: maybe_nocache() — sekcja PIERWSZA w pliku (define() nieodwracalne) ==\n";
if ( $has_shortcode && method_exists( 'AIFAQ\PublicUi\Shortcode', 'maybe_nocache' ) ) {
	aifaq_set_role( false, false );
	$GLOBALS['__ctx']['is_singular'] = true;
	$GLOBALS['__posts']['current']   = new WP_Post( array( 'ID' => 5, 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'generator-faq', 'post_content' => '[aifaq_generator]' ) );
	$GLOBALS['__cnt']['nocache_headers'] = 0;
	aifaq_call( 'AIFAQ\PublicUi\Shortcode', 'maybe_nocache' );
	check( 0 === $GLOBALS['__cnt']['nocache_headers'], '#35a GOŚĆ (sekcja 1. w pliku): licznik nocache_headers === 0 [NOWE]' );
	check( ! defined( 'DONOTCACHEPAGE' ), '#35b GOŚĆ: DONOTCACHEPAGE nie zdefiniowane przed przebiegiem właściciela [NOWE]' );
} else {
	check( false, '#35a sekcja A pominięta — brak Shortcode::maybe_nocache() [NOWE]' );
	check( false, '#35b sekcja A pominięta — brak Shortcode::maybe_nocache() [NOWE]' );
}
unset( $GLOBALS['__posts']['current'] );

// ===========================================================================
// SEKCJA B — GOŚĆ: enqueue_assets().  §8.2 #34 + §8.4 (licznik ZABRANIA).
// ===========================================================================
echo "\n== B. GOŚĆ: enqueue_assets() — zero narzędzia FAQ, zero wycieku nonce'a ==\n";
if ( $has_shortcode && method_exists( 'AIFAQ\PublicUi\Shortcode', 'enqueue_assets' ) ) {
	aifaq_set_role( false, false );
	aifaq_reset_enq();
	aifaq_call( 'AIFAQ\PublicUi\Shortcode', 'enqueue_assets' );
	$g_style  = aifaq_enq_by( 'style', 'aifaq-faq-tool' );
	$g_script = aifaq_enq_by( 'script', 'aifaq-faq-tool' );
	$g_inline = aifaq_enq_by( 'inline', 'aifaq-faq-tool' );
	check( 0 === count( $g_style ), '#34 GOŚĆ: wp_enqueue_style(aifaq-faq-tool) === 0 [NOWE]' );
	check( 0 === count( $g_script ), '#34/§8.4 GOŚĆ: wp_enqueue_script(aifaq-faq-tool) === 0 [NOWE]' );
	check( 0 === count( $g_inline ), '#34 GOŚĆ: wp_add_inline_script(aifaq-faq-tool) === 0 [NOWE]' );

	$leak_ft = false;
	$leak_app = false;
	$leak_regen = false;
	$gen_inline = '';
	foreach ( $GLOBALS['__enq'] as $e ) {
		if ( 'inline' !== $e['type'] ) { continue; }
		if ( false !== strpos( $e['data'], 'window.aifaqFaqTool' ) ) { $leak_ft = true; }
		if ( false !== strpos( $e['data'], 'window.aifaqApp' ) ) { $leak_app = true; }
		if ( false !== strpos( $e['data'], 'aifaq_regen' ) ) { $leak_regen = true; }
		if ( 'aifaq-generator' === $e['handle'] ) { $gen_inline .= $e['data']; }
	}
	check( false === $leak_ft, '#34 GOŚĆ: żaden inline-script nie zawiera window.aifaqFaqTool [NOWE]' );
	check( false === $leak_app, '#34 GOŚĆ: żaden inline-script nie zawiera window.aifaqApp [REGRESJA]' );
	check( false === $leak_regen, '#34 GOŚĆ: żaden inline-script nie zawiera aifaq_regen [REGRESJA]' );
	check( false !== strpos( $gen_inline, '"nonce":""' ), '#34 GOŚĆ: inline aifaq-generator ma dokładnie "nonce":"" (badamy WARTOŚĆ, nie obecność klucza) [REGRESJA]' );
} else {
	for ( $i = 0; $i < 7; $i++ ) { check( false, '#34 sekcja B pominięta — brak Shortcode::enqueue_assets() [NOWE]' ); }
}

// ===========================================================================
// SEKCJA C — GOŚĆ: render_standalone().  §8.2 #37 (kontrola wycieku).
// ===========================================================================
echo "\n== C. GOŚĆ: GeneratorPage::render_standalone() — brak narzędzia FAQ ==\n";
if ( $has_genpage && method_exists( 'AIFAQ\PublicUi\GeneratorPage', 'render_standalone' ) ) {
	aifaq_set_role( false, false );
	ob_start();
	aifaq_call( 'AIFAQ\PublicUi\GeneratorPage', 'render_standalone' );
	$doc_guest = (string) ob_get_clean();
	check( false === strpos( $doc_guest, 'faq-tool.js' ), '#37 GOŚĆ: standalone bez assets/js/faq-tool.js [REGRESJA]' );
	check( false === strpos( $doc_guest, 'faq-tool.css' ), '#37 GOŚĆ: standalone bez assets/css/faq-tool.css [REGRESJA]' );
	check( false === strpos( $doc_guest, 'window.aifaqFaqTool' ), '#37 GOŚĆ: standalone bez window.aifaqFaqTool [REGRESJA]' );
	unset( $doc_guest );
} else {
	for ( $i = 0; $i < 3; $i++ ) { check( false, '#37 sekcja C pominięta — brak GeneratorPage::render_standalone() [REGRESJA]' ); }
}

// ===========================================================================
// SEKCJA D — GOŚĆ: AppShell::render_body().  §8.2 #29.
// ===========================================================================
echo "\n== D. GOŚĆ: AppShell::render_body() — bez powłoki ==\n";
if ( $has_shell ) {
	aifaq_set_role( false, false );
	$guest_body = (string) AIFAQ\App\AppShell::render_body();
	check( false === strpos( $guest_body, 'aifaq-app__bar' ), '#29 GOŚĆ: brak aifaq-app__bar [REGRESJA]' );
	check( false === strpos( $guest_body, 'data-tab' ), '#29 GOŚĆ: brak data-tab [REGRESJA]' );
	check( false === strpos( $guest_body, 'aifaq-panel-ft' ), '#29 GOŚĆ: brak aifaq-panel-ft [NOWE]' );
	check( 0 === substr_count( $guest_body, 'aifaq-app__tab"' ) + substr_count( $guest_body, 'aifaq-app__tab ' ), '#29 GOŚĆ: liczba zakładek === 0 [REGRESJA]' );
	unset( $guest_body );
} else {
	for ( $i = 0; $i < 4; $i++ ) { check( false, '#29 sekcja D pominięta — brak klasy AppShell [REGRESJA]' ); }
}

// ===========================================================================
// SEKCJA E — WŁAŚCICIEL: maybe_nocache().  §8.2 #35, człon drugi.
// ===========================================================================
echo "\n== E. WŁAŚCICIEL: maybe_nocache() — nagłówki przed wyjściem ==\n";
if ( $has_shortcode && method_exists( 'AIFAQ\PublicUi\Shortcode', 'maybe_nocache' ) ) {
	aifaq_set_role( true, true, 1 );
	$GLOBALS['__ctx']['is_singular'] = true;
	$GLOBALS['__posts']['current']   = new WP_Post( array( 'ID' => 5, 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'generator-faq', 'post_content' => '[aifaq_generator]' ) );
	$GLOBALS['__cnt']['nocache_headers'] = 0;
	aifaq_call( 'AIFAQ\PublicUi\Shortcode', 'maybe_nocache' );
	check( 1 === $GLOBALS['__cnt']['nocache_headers'], '#35c WŁAŚCICIEL: licznik nocache_headers === 1 (para dodatnia do #35a) [NOWE]' );
	check( defined( 'DONOTCACHEPAGE' ), '#35d WŁAŚCICIEL: DONOTCACHEPAGE zdefiniowane [NOWE]' );
} else {
	check( false, '#35c sekcja E pominięta — brak Shortcode::maybe_nocache() [NOWE]' );
	check( false, '#35d sekcja E pominięta — brak Shortcode::maybe_nocache() [NOWE]' );
}
unset( $GLOBALS['__posts']['current'] );

// ===========================================================================
// SEKCJA F — WŁAŚCICIEL: enqueue_assets().  §8.2 #32, #33.
// KRYTYCZNA: niewpięty faq-tool.js daje ten sam objaw co brakujące id —
// markup poprawny, testy zielone, przycisk nic nie robi.
// ===========================================================================
echo "\n== F. WŁAŚCICIEL: enqueue_assets() — wpięcie narzędzia FAQ ==\n";
if ( $has_shortcode && method_exists( 'AIFAQ\PublicUi\Shortcode', 'enqueue_assets' ) ) {
	aifaq_set_role( true, true, 1 );
	aifaq_reset_enq();
	aifaq_call( 'AIFAQ\PublicUi\Shortcode', 'enqueue_assets' );
	$o_style  = aifaq_enq_by( 'style', 'aifaq-faq-tool' );
	$o_script = aifaq_enq_by( 'script', 'aifaq-faq-tool' );
	$o_inline = aifaq_enq_by( 'inline', 'aifaq-faq-tool' );
	check( 1 === count( $o_style ), '#32 WŁAŚCICIEL: wp_enqueue_style(aifaq-faq-tool) === 1 [NOWE]' );
	check( 1 === count( $o_script ), '#32 WŁAŚCICIEL: wp_enqueue_script(aifaq-faq-tool) === 1 [NOWE]' );
	check( 1 === count( $o_inline ), '#33 WŁAŚCICIEL: wp_add_inline_script(aifaq-faq-tool) === 1 [NOWE]' );

	$prefix = 'window.aifaqFaqTool = ';
	$inline = ( 1 === count( $o_inline ) ) ? $o_inline[0] : array( 'data' => '', 'position' => '' );
	check( 'before' === $inline['position'], '#33 WŁAŚCICIEL: pozycja inline-scriptu === before [NOWE]' );
	check( 0 === strpos( $inline['data'], $prefix ), '#33 WŁAŚCICIEL: inline zaczyna się od "window.aifaqFaqTool = " [NOWE]' );

	// rtrim(…, ';') OBOWIĄZKOWY — kod produkcyjny dokleja średnik, a json_decode('{…};')
	// zwraca null, po czym count(null) rzuca TypeError i wywala plik bez FAIL-a.
	$payload = rtrim( trim( substr( $inline['data'], strlen( $prefix ) ) ), ';' );
	$ftcfg   = json_decode( $payload, true );
	check( true === is_array( $ftcfg ), '#33 WŁAŚCICIEL: inline dekoduje się do tablicy (po rtrim średnika) [NOWE]' );
	$ftcfg_a = is_array( $ftcfg ) ? $ftcfg : array();
	check( 7 === count( $ftcfg_a ), '#33 WŁAŚCICIEL: config w inline ma 7 kluczy [NOWE]' );
	check( array_keys( $ftcfg_a ) === array( 'endpoint', 'exportEndpoint', 'detailEndpoint', 'regenParam', 'nonce', 'defaults', 'i18n' ), '#33 WŁAŚCICIEL: zamrożona kolejność kluczy configu w inline [NOWE]' );
	check( true === is_int( $ftcfg_a['defaults']['count'] ?? null ), '#33 WŁAŚCICIEL: defaults.count jest INTEM (dowód, że NIE użyto wp_localize_script) [NOWE]' );
	unset( $payload, $ftcfg, $ftcfg_a, $inline, $prefix );
} else {
	for ( $i = 0; $i < 9; $i++ ) { check( false, '#32/#33 sekcja F pominięta — brak Shortcode::enqueue_assets() [NOWE]' ); }
}

// ===========================================================================
// SEKCJA G — WŁAŚCICIEL: render_standalone().  §8.2 #36.
// ===========================================================================
echo "\n== G. WŁAŚCICIEL: GeneratorPage::render_standalone() — kolejność skryptów ==\n";
if ( $has_genpage && method_exists( 'AIFAQ\PublicUi\GeneratorPage', 'render_standalone' ) ) {
	aifaq_set_role( true, true, 1 );
	if ( $has_panel && method_exists( 'AIFAQ\App\FaqToolPanel', 'reset_render_flag' ) ) { AIFAQ\App\FaqToolPanel::reset_render_flag(); }
	ob_start();
	aifaq_call( 'AIFAQ\PublicUi\GeneratorPage', 'render_standalone' );
	$doc = (string) ob_get_clean();
	check( 1 === substr_count( $doc, 'assets/js/faq-tool.js' ), '#36 WŁAŚCICIEL: assets/js/faq-tool.js dokładnie raz [NOWE]' );
	check( 1 === substr_count( $doc, 'assets/css/faq-tool.css' ), '#36 WŁAŚCICIEL: assets/css/faq-tool.css dokładnie raz [NOWE]' );
	check( false !== strpos( $doc, 'window.aifaqFaqTool' ), '#36 WŁAŚCICIEL: standalone niesie window.aifaqFaqTool [NOWE]' );
	$p_cfg = strpos( $doc, 'window.aifaqFaqTool' );
	$p_js  = strpos( $doc, 'assets/js/faq-tool.js' );
	check( false !== $p_cfg && false !== $p_js && $p_cfg < $p_js, '#36 WŁAŚCICIEL: window.aifaqFaqTool PRZED <script src=…faq-tool.js> (osłona false !== na obu strpos) [NOWE]' );
	unset( $doc, $p_cfg, $p_js );
} else {
	for ( $i = 0; $i < 4; $i++ ) { check( false, '#36 sekcja G pominięta — brak GeneratorPage::render_standalone() [NOWE]' ); }
}

// ===========================================================================
// SEKCJA H — MARKUP FaqToolPanel::widget().  §8.2 #1-#14.
// ===========================================================================
echo "\n== H. Markup FaqToolPanel::widget( \$t ) ==\n";
if ( $has_panel ) {
	AIFAQ\App\FaqToolPanel::reset_render_flag();
	$t    = AIFAQ\App\FaqToolPanel::strings( 'pl' );
	$html = (string) AIFAQ\App\FaqToolPanel::widget( $t );

	// #1
	check( 15 === substr_count( $html, 'id="' ), '#1 markup ma dokładnie 15 atrybutów id=" [NOWE]' );

	// #2 — 15 identyfikatorów imiennie. Surowe substr_count($html,$id) ZABRONIONE:
	// dla topic/desc/count dałoby 2 (raz id=, raz for=) i zaczerwieniło poprawny markup.
	foreach ( $aifaq_ids as $id ) {
		check( 1 === substr_count( $html, 'id="' . $id . '"' ), '#2 id="' . $id . '" dokładnie raz [NOWE]' );
	}
	foreach ( array( 'aifaq-ft-topic', 'aifaq-ft-desc', 'aifaq-ft-count' ) as $id ) {
		check( 1 === substr_count( $html, 'for="' . $id . '"' ), '#2 for="' . $id . '" dokładnie raz [NOWE]' );
	}

	// #3
	check( 5 === substr_count( $html, 'data-format="' ), '#3 data-format=" dokładnie 5 razy [NOWE]' );
	foreach ( array( 'html', 'gutenberg', 'elementor', 'json', 'jsonld' ) as $fmt ) {
		check( 1 === substr_count( $html, 'data-format="' . $fmt . '"' ), '#3 data-format="' . $fmt . '" obecny raz [NOWE]' );
	}

	// #4 — is-active TYLKO na html.
	check( 1 === substr_count( $html, 'is-active' ), '#4 is-active występuje dokładnie raz [NOWE]' );
	$m = array();
	$ok4 = ( 1 === preg_match( '/<button[^>]*data-format="html"[^>]*>/', $html, $m ) ) && false !== strpos( $m[0], 'is-active' );
	check( $ok4, '#4 preset is-active leży na przycisku data-format="html" [NOWE]' );

	// #5, #6 — bilans przycisków.
	check( 9 === substr_count( $html, '<button' ), '#5 dokładnie 9 elementów <button [NOWE]' );
	check( 8 === substr_count( $html, 'type="button"' ), '#6 dokładnie 8 × type="button" [NOWE]' );
	check( 1 === substr_count( $html, 'type="submit"' ), '#6 dokładnie 1 × type="submit" [NOWE]' );

	// #7 — type badany WEWNĄTRZ dopasowanego znacznika, nigdy na całym $html.
	$m = array();
	$ok7 = ( 1 === preg_match( '/<button[^>]*\bid="aifaq-ft-generate"[^>]*>/', $html, $m ) ) && false !== strpos( $m[0], 'type="submit"' );
	check( $ok7, '#7 #aifaq-ft-generate ma type="submit" WEWNĄTRZ swojego znacznika [NOWE]' );
	foreach ( array( 'aifaq-ft-copyall', 'aifaq-ft-exp-copy', 'aifaq-ft-exp-download' ) as $bid ) {
		$m = array();
		$okb = ( 1 === preg_match( '/<button[^>]*\bid="' . preg_quote( $bid, '/' ) . '"[^>]*>/', $html, $m ) ) && false !== strpos( $m[0], 'type="button"' );
		check( $okb, '#7 #' . $bid . ' ma type="button" WEWNĄTRZ swojego znacznika [NOWE]' );
	}

	// #8 — form.
	check( 1 === substr_count( $html, '<form' ), '#8 dokładnie jeden <form [NOWE]' );
	$m = array();
	$ok8 = ( 1 === preg_match( '/<form[^>]*>/', $html, $m ) )
		&& false !== strpos( $m[0], 'id="aifaq-ft-form"' )
		&& false !== strpos( $m[0], 'novalidate' );
	check( $ok8, '#8 <form> ma id="aifaq-ft-form" i novalidate [NOWE]' );

	// #9 — hidden na results.
	$m = array();
	$ok9 = ( 1 === preg_match( '/<div[^>]*\bid="aifaq-ft-results"[^>]*>/', $html, $m ) ) && false !== strpos( $m[0], 'hidden' );
	check( $ok9, '#9 #aifaq-ft-results ma atrybut hidden w markupie startowym [NOWE]' );

	// #10 — literały pola liczby (NIE renderowane z config()).
	foreach ( array( 'min="5"', 'max="20"', 'step="1"', 'value="10"', 'inputmode="numeric"' ) as $lit ) {
		check( false !== strpos( $html, $lit ), '#10 literał ' . $lit . ' obecny [NOWE]' );
	}

	// #11 — ARIA i semantyka.
	check( 2 === substr_count( $html, 'role="status"' ), '#11 role="status" === 2 [NOWE]' );
	check( 2 === substr_count( $html, 'aria-live="polite"' ), '#11 aria-live="polite" === 2 [NOWE]' );
	check( 1 === substr_count( $html, 'role="tablist"' ), '#11 role="tablist" === 1 [NOWE]' );
	check( 3 === substr_count( $html, 'scope="col"' ), '#11 scope="col" === 3 [NOWE]' );
	check( 3 === substr_count( $html, '<label' ), '#11 <label === 3 [NOWE]' );

	// #12 — widget NIE emituje chrome'u (należy do kontekstu, §3.3/§3.11).
	foreach ( array( 'class="wrap', 'aifaq-wrap', 'aifaq-ft"', 'aifaq-card', 'aifaq-ftp', 'dashicons', '<h1', 'aifaq-lead' ) as $bad ) {
		check( false === strpos( $html, $bad ), '#12 markup NIE zawiera ' . $bad . ' [NOWE]' );
	}

	// #13 — korzeń; trim() OBOWIĄZKOWY (ob_start niesie wcięcie szablonu).
	$m = array();
	$ok13 = ( 1 === preg_match( '/^<div\b[^>]*\bid="aifaq-ft-panel"[^>]*>/', trim( $html ), $m ) );
	check( $ok13, '#13 markup zaczyna się korzeniem <div id="aifaq-ft-panel"> (po trim) [NOWE]' );
	check( $ok13 && false !== strpos( $m[0], 'aifaq-ft__panel' ), '#13 korzeń niesie klasę aifaq-ft__panel [NOWE]' );

	// #14 — nowe opakowanie tabeli.
	check( 1 === substr_count( $html, 'aifaq-ft__tablewrap' ), '#14 aifaq-ft__tablewrap dokładnie raz [NOWE]' );
	check( 1 === substr_count( $html, '<table' ), '#14 dokładnie jeden <table [NOWE]' );
	check( 1 === preg_match( '/<div[^>]*aifaq-ft__tablewrap[^>]*>\s*<table/', $html ), '#14 <table leży wewnątrz .aifaq-ft__tablewrap [NOWE]' );

	unset( $html, $t, $m, $ok4, $ok7, $ok8, $ok9, $ok13 );
} else {
	// Sekcja bramkowana class_exists MA else z czerwoną asercją — `if` bez `else`
	// wykonuje ZERO asercji i kończy się sukcesem (§8.1 pkt 5).
	for ( $i = 0; $i < 59; $i++ ) { check( false, '#1-#14 sekcja H pominięta — brak klasy FaqToolPanel [NOWE]' ); }
}

// ===========================================================================
// SEKCJA I — config() i strings().  §8.2 #15-#21.
// ===========================================================================
echo "\n== I. FaqToolPanel::config() i ::strings() ==\n";
if ( $has_panel ) {
	$cfg = AIFAQ\App\FaqToolPanel::config();
	check( 7 === count( $cfg ), '#15 config() ma 7 kluczy [NOWE]' );
	check( array_keys( $cfg ) === array( 'endpoint', 'exportEndpoint', 'detailEndpoint', 'regenParam', 'nonce', 'defaults', 'i18n' ), '#15 config() — zamrożony zestaw i kolejność kluczy (§2.2) [NOWE]' );

	$def = isset( $cfg['defaults'] ) && is_array( $cfg['defaults'] ) ? $cfg['defaults'] : array();
	check( 4 === count( $def ), '#16 config()[defaults] ma 4 klucze [NOWE]' );
	check( true === is_int( $def['count'] ?? null ), '#16 defaults.count jest INTEM [NOWE]' );
	check( 10 === ( $def['count'] ?? null ), '#16 defaults.count === 10 [NOWE]' );
	check( 5 === ( $def['min'] ?? null ), '#16 defaults.min === 5 [NOWE]' );
	check( 20 === ( $def['max'] ?? null ), '#16 defaults.max === 20 [NOWE]' );

	$i18n = isset( $cfg['i18n'] ) && is_array( $cfg['i18n'] ) ? $cfg['i18n'] : array();
	check( 34 === count( $i18n ), '#17 config()[i18n] ma 34 klucze [NOWE]' );

	$pl = AIFAQ\App\FaqToolPanel::strings( 'pl' );
	$en = AIFAQ\App\FaqToolPanel::strings( 'en' );
	$de = AIFAQ\App\FaqToolPanel::strings( 'de' );
	check( array_keys( $pl ) === $aifaq_i18n_keys, '#17 strings(pl) — 34 klucze w zamrożonej kolejności (§2.3) [NOWE]' );
	check( array_keys( $en ) === array_keys( $pl ), '#18 array_keys(strings(en)) === array_keys(strings(pl)) [NOWE]' );
	check( array_keys( $de ) === array_keys( $pl ), '#18 array_keys(strings(de)) === array_keys(strings(pl)) [NOWE]' );
	check( AIFAQ\App\FaqToolPanel::strings( 'xx' ) === $pl, '#19 fallback: strings("xx") === strings("pl") [NOWE]' );

	if ( $has_ftpage ) {
		check( AIFAQ\Admin\FaqToolPage::strings( 'pl' ) === $pl, '#20 proxy: FaqToolPage::strings(pl) === FaqToolPanel::strings(pl) [NOWE]' );
		check( AIFAQ\Admin\FaqToolPage::config() === $cfg, '#20 proxy: FaqToolPage::config() === FaqToolPanel::config() [NOWE]' );
	} else {
		check( false, '#20 pominięte — brak klasy FaqToolPage [NOWE]' );
		check( false, '#20 pominięte — brak klasy FaqToolPage [NOWE]' );
	}

	if ( $has_shell ) {
		check( array() === array_intersect( array_keys( $pl ), array_keys( AIFAQ\App\AppShell::strings( 'pl' ) ) ), '#21 pusty przekrój kluczy FaqToolPanel::strings(pl) i AppShell::strings(pl) [NOWE]' );
	} else {
		check( false, '#21 pominięte — brak klasy AppShell [NOWE]' );
	}
	unset( $cfg, $def, $i18n, $pl, $en, $de );
} else {
	for ( $i = 0; $i < 15; $i++ ) { check( false, '#15-#21 sekcja I pominięta — brak klasy FaqToolPanel [NOWE]' ); }
}

// ===========================================================================
// SEKCJA J — Powłoka AppShell.  §8.2 #22-#28, #30.
// ===========================================================================
echo "\n== J. AppShell — szósta zakładka ==\n";
if ( $has_shell ) {
	$s_pl = AIFAQ\App\AppShell::strings( 'pl' );
	$s_en = AIFAQ\App\AppShell::strings( 'en' );
	$s_de = AIFAQ\App\AppShell::strings( 'de' );
	check( array_key_exists( 'ftTab', $s_pl ), '#22 AppShell::strings(pl) zawiera ftTab [NOWE]' );
	check( array_key_exists( 'ftTab', $s_en ), '#22 AppShell::strings(en) zawiera ftTab [NOWE]' );
	check( array_key_exists( 'ftTab', $s_de ), '#22 AppShell::strings(de) zawiera ftTab [NOWE]' );
	$pos_pl = array_search( 'ftTab', array_keys( $s_pl ), true );
	$pos_en = array_search( 'ftTab', array_keys( $s_en ), true );
	$pos_de = array_search( 'ftTab', array_keys( $s_de ), true );
	check( false !== $pos_pl && $pos_pl === $pos_en, '#22 ftTab na tej samej POZYCJI w pl i en [NOWE]' );
	check( false !== $pos_pl && $pos_pl === $pos_de, '#22 ftTab na tej samej POZYCJI w pl i de [NOWE]' );

	aifaq_set_role( true, true, 1 );
	if ( $has_panel ) { AIFAQ\App\FaqToolPanel::reset_render_flag(); }
	$body = (string) AIFAQ\App\AppShell::render_body();

	check( 6 === substr_count( $body, 'aifaq-app__tab"' ) + substr_count( $body, 'aifaq-app__tab ' ), '#23 WŁAŚCICIEL: dokładnie 6 zakładek [NOWE]' );
	check( 6 === substr_count( $body, 'aifaq-app__panel' ), '#24 WŁAŚCICIEL: dokładnie 6 paneli [NOWE]' );

	foreach ( array( 'id="aifaq-tab-ft"', 'aria-controls="aifaq-panel-ft"', 'data-tab-target="ft"', 'id="aifaq-panel-ft"', 'aria-labelledby="aifaq-tab-ft"' ) as $attr ) {
		check( false !== strpos( $body, $attr ), '#25 WŁAŚCICIEL: obecne ' . $attr . ' [NOWE]' );
	}

	$m = array();
	$ok26 = ( 1 === preg_match( '/<div[^>]*\bid="aifaq-panel-ft"[^>]*>/', $body, $m ) ) && false !== strpos( $m[0], 'hidden' );
	check( $ok26, '#26 WŁAŚCICIEL: panel ft ma atrybut hidden [NOWE]' );
	check( false !== strpos( $body, 'data-tab="generator"' ), '#26 WŁAŚCICIEL: data-tab="generator" bez zmian [REGRESJA]' );
	$m = array();
	$ok26b = ( 1 === preg_match( '/<div[^>]*\bid="aifaq-panel-generator"[^>]*>/', $body, $m ) ) && false !== strpos( $m[0], 'is-active' );
	check( $ok26b, '#26 WŁAŚCICIEL: panel generator nadal aktywny (is-active) [REGRESJA]' );

	check( 1 === substr_count( $body, 'aifaq-card aifaq-ftp' ), '#27 WŁAŚCICIEL: "aifaq-card aifaq-ftp" dokładnie raz [NOWE]' );

	$ph = strpos( $body, 'aifaq-tab-history' );
	$pf = strpos( $body, 'aifaq-tab-ft' );
	$pg = strpos( $body, 'aifaq-tab-gh' );
	check( false !== $ph && false !== $pf && false !== $pg && $ph < $pf && $pf < $pg, '#28 kolejność DOM: history < ft < gh (osłona false !== na KAŻDYM strpos) [NOWE]' );

	$acfg = AIFAQ\App\AppShell::config();
	check( 8 === count( $acfg ), '#30 AppShell::config() ma 8 kluczy [REGRESJA]' );
	check( array_keys( $acfg ) === array( 'isOwner', 'nonce', 'perPage', 'genPerPage', 'faqToolUrl', 'regenParam', 'endpoints', 'i18n' ), '#30 AppShell::config() — zamrożona kolejność 8 kluczy [REGRESJA]' );
	check( 10 === count( $acfg['endpoints'] ?? array() ), '#30 AppShell::config()[endpoints] ma 10 kluczy (ZAKAZ generate-faq/export) [REGRESJA]' );

	unset( $body, $s_pl, $s_en, $s_de, $acfg, $m, $ph, $pf, $pg, $ok26, $ok26b );
} else {
	for ( $i = 0; $i < 19; $i++ ) { check( false, '#22-#30 sekcja J pominięta — brak klasy AppShell [NOWE]' ); }
}

// ===========================================================================
// SEKCJA K — Jednokrotność widget().  §8.2 #31.
// ===========================================================================
echo "\n== K. Jednokrotność FaqToolPanel::widget() ==\n";
if ( $has_panel ) {
	AIFAQ\App\FaqToolPanel::reset_render_flag();
	$t = AIFAQ\App\FaqToolPanel::strings( 'pl' );
	$a = (string) AIFAQ\App\FaqToolPanel::widget( $t );
	$b = (string) AIFAQ\App\FaqToolPanel::widget( $t );
	check( '' === $b, '#31 drugie wywołanie widget() zwraca pusty string [NOWE]' );
	check( 1 === substr_count( $a . $b, 'id="aifaq-ft-form"' ), '#31 w sumie dokładnie jeden id="aifaq-ft-form" [NOWE]' );
	unset( $a, $b, $t );
} else {
	check( false, '#31 sekcja K pominięta — brak klasy FaqToolPanel [NOWE]' );
	check( false, '#31 sekcja K pominięta — brak klasy FaqToolPanel [NOWE]' );
}

// ===========================================================================
// SEKCJA L — Kontekst renderowania.  §8.2 #38 (§3.1 reguła 9a).
// Dowód, że ODRZUCONY przebieg the_content nie WYPALA flagi jednokrotności.
// ===========================================================================
echo "\n== L. Kontekst renderowania — odrzucony przebieg nie wypala flagi ==\n";
if ( $has_shortcode && method_exists( 'AIFAQ\PublicUi\Shortcode', 'render' ) && method_exists( 'AIFAQ\PublicUi\Shortcode', 'reset_panel_flag' ) ) {
	aifaq_set_role( true, true, 1 );
	AIFAQ\PublicUi\Shortcode::reset_panel_flag();

	// (a) wyciąg — wp_trim_excerpt()/get_the_excerpt() stosuje filtr the_content w wp_head.
	$GLOBALS['__ctx']['doing_filter']  = 'get_the_excerpt';
	$GLOBALS['__ctx']['is_feed']       = false;
	$GLOBALS['__ctx']['is_main_query'] = true;
	$GLOBALS['__ctx']['in_the_loop']   = true;
	$out_a = (string) aifaq_call( 'AIFAQ\PublicUi\Shortcode', 'render', array( array(), '', 'aifaq_generator' ) );
	check( '' === $out_a, '#38a doing_filter(get_the_excerpt) → render() zwraca "" [NOWE]' );

	// (c) kanał RSS.
	$GLOBALS['__ctx']['doing_filter'] = '';
	$GLOBALS['__ctx']['is_feed']      = true;
	$out_c = (string) aifaq_call( 'AIFAQ\PublicUi\Shortcode', 'render', array( array(), '', 'aifaq_generator' ) );
	check( '' === $out_c, '#38c is_feed() → render() zwraca "" [NOWE]' );

	// (b) właściwy przebieg pętli PO odrzuconych — flaga nie może być wypalona.
	AIFAQ\PublicUi\Shortcode::reset_panel_flag();
	$GLOBALS['__ctx']['doing_filter']  = '';
	$GLOBALS['__ctx']['is_feed']       = false;
	$GLOBALS['__ctx']['is_main_query'] = true;
	$GLOBALS['__ctx']['in_the_loop']   = true;
	$out_b = (string) aifaq_call( 'AIFAQ\PublicUi\Shortcode', 'render', array( array(), '', 'aifaq_generator' ) );
	check( false !== strpos( $out_b, 'id="aifaq-ft-form"' ), '#38b właściwy przebieg pętli → markup z id="aifaq-ft-form" [NOWE]' );
	check( 1 === substr_count( $out_b, 'id="aifaq-ft-form"' ), '#38b dokładnie jeden id="aifaq-ft-form" we właściwym przebiegu [NOWE]' );

	unset( $out_a, $out_b, $out_c );
} else {
	for ( $i = 0; $i < 4; $i++ ) { check( false, '#38 sekcja L pominięta — brak Shortcode::render()/reset_panel_flag() [NOWE]' ); }
}

// ===========================================================================
// PODŁOGA POKRYCIA (§8.1 pkt 4) — liczona PRZED własnym check().
// ===========================================================================
echo "\n== Podłoga pokrycia ==\n";
$floor = $ran;
check( $floor >= 65, 'wykonano co najmniej 65 asercji (było ' . $floor . ')' );

// Wartownik końca pliku (§8.1 pkt 15) — brak tej etykiety w wyjściu = dowód urwania procesu.
check( true, 'plik dobiegł końca' );

echo "\n=== " . ( 0 === $fail ? 'WSZYSTKIE OK' : "BŁĘDÓW: {$fail}" ) . " (asercji: {$ran}) ===\n";
exit( $fail > 0 ? 1 : 0 );
