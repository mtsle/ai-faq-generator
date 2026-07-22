<?php
/**
 * Testy Kroku 20 — sekcja B1: UPRAWNIENIA (cap narzędzia) i TOŻSAMOŚĆ GOŚCIA (D1).
 *
 * PISANE W CIEMNO (Etap 9b, KONTRAKT k20-v3 §5). Autor NIE otwierał
 * `src/Rest/RestController.php` ani `src/Admin/PostMetaBox.php` — asercje pochodzą
 * WYŁĄCZNIE z kontraktu. Rozbieżność test↔implementacja jest dowodem, że kontrakt
 * był nieprecyzyjny, i idzie do `plany/krok20/ODCHYLENIA.md`, nie do cichej korekty testu.
 *
 * DLACZEGO TEN PLIK JEST BRAMKĄ ODBIORU RYZYKA NR 1 (KONTRAKT §11.5):
 * wszystkie jedenaście atrap `current_user_can()` w `tests/` IGNORUJE argument `$cap`
 * i zwraca globalną flagę (trzy zwracają bezwarunkowe `true`). Na takich atrapach można
 * podmienić `manage_options` na `edit_posts` przy trasie `/admin/settings`, uruchomić cały
 * runner i zobaczyć samą zieleń — a klient odda klucz API Redaktorowi.
 * Sekcja A buduje atrapę CAP-ŚWIADOMĄ i asertuje ją samą, ZANIM cokolwiek na niej stanie.
 *
 * Pokrywa:
 *  - A. cap-świadoma atrapa `current_user_can()` (fundament — bez niej reszta to teatr);
 *  - B. macierz TOŻSAMOŚĆ × TRASA: 13 tras × 4 tożsamości, imiennie, trasa po trasie (§5.2);
 *  - C. `register_rest_route` wywołane DOKŁADNIE 13 razy (§5.2 „ZERO nowych tras REST”);
 *  - D. jedno źródło capa: rola widząca metabox PRZECHODZI na obu trasach narzędzia (§5.1/FZ19);
 *  - E. filtr `aifaq_tool_capability` + administrator przechodzi ZAWSZE (§5.1);
 *  - F. `Menu::CAPABILITY` nietknięte — `manage_options` (§5.2);
 *  - G. D1: `ip_hash()` za proxy — CF-Connecting-IP, OSTATNI element XFF, walidacja IP (§5.3).
 *
 * ODCHYLENIA uwzględnione: O-41 (XFF = OSTATNI element wg KONTRAKTU, karta `ETAP-4.md`
 * i karta `ETAP-9b.md` mówią „pierwszy” — obie zalegają za k20-v3), O-42 (metabox czyta
 * `RestController::CAPABILITY` || `tool_capability()`), O-43 (bez `Core/Settings.php`
 * w harnessie `ip_hash()` cicho testuje ścieżkę „OFF”).
 *
 * URUCHOMIENIE:  php -d extension=mbstring tests/krok20-capy-test.php
 * Kod wyjścia: 0 = OK, 1 = błędy.
 *
 * @package AI_FAQ_Generator
 */

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
if ( ! defined( 'AIFAQ_PLUGIN_DIR' ) ) { define( 'AIFAQ_PLUGIN_DIR', dirname( __DIR__ ) . '/' ); }
if ( ! defined( 'AIFAQ_PLUGIN_URL' ) ) { define( 'AIFAQ_PLUGIN_URL', 'https://example.test/wp-content/plugins/ai-faq-generator/' ); }
if ( ! defined( 'AIFAQ_VERSION' ) ) { define( 'AIFAQ_VERSION', '0.23.0-test' ); }
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }

// ---------------------------------------------------------------------------
// Rejestry (shimy zbierają, sekcje asertują).
// ---------------------------------------------------------------------------
$GLOBALS['__opt']             = array();
$GLOBALS['__caps']            = array();   // <-- SERCE PLIKU: lista capów bieżącej tożsamości
$GLOBALS['__cap_calls']       = array();   // każde zapytanie o cap (dowód, że pytano o właściwy)
$GLOBALS['__routes']          = array();
$GLOBALS['__filters']         = array();
$GLOBALS['__boxes']           = array();
$GLOBALS['__styles']          = array();
$GLOBALS['__scripts']         = array();
$GLOBALS['__localized']       = array();
$GLOBALS['__screen']          = null;

// ---------------------------------------------------------------------------
// Shimy funkcji WP.
// ---------------------------------------------------------------------------
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); } }
if ( ! function_exists( 'esc_attr__' ) ) { function esc_attr__( $s, $d = null ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $s ) { return (string) $s; } }
if ( ! function_exists( 'esc_url_raw' ) ) { function esc_url_raw( $s ) { return (string) $s; } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $d, $f = 0, $depth = 512 ) { return json_encode( $d, $f, $depth ); } }
if ( ! function_exists( 'wp_unslash' ) ) { function wp_unslash( $s ) { return is_string( $s ) ? stripslashes( $s ) : $s; } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ) { return trim( (string) preg_replace( '/<[^>]*>/', '', (string) $s ) ); } }
if ( ! function_exists( 'sanitize_textarea_field' ) ) { function sanitize_textarea_field( $s ) { return trim( (string) preg_replace( '/<[^>]*>/', '', (string) $s ) ); } }
if ( ! function_exists( 'wp_salt' ) ) { function wp_salt( $scheme = 'auth' ) { return 'aifaq-test-salt'; } }
if ( ! function_exists( '__return_true' ) ) { function __return_true() { return true; } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . ltrim( (string) $p, '/' ); } }
if ( ! function_exists( 'rest_url' ) ) { function rest_url( $p = '' ) { return 'https://example.test/wp-json/' . ltrim( (string) $p, '/' ); } }
if ( ! function_exists( 'wp_create_nonce' ) ) { function wp_create_nonce( $a = '' ) { return 'nonce-' . (string) $a; } }
if ( ! function_exists( 'current_time' ) ) { function current_time( $t = 'mysql' ) { return 'mysql' === $t ? '2026-07-22 10:00:00' : gmdate( (string) $t, 1784000000 ); } }
if ( ! function_exists( 'get_current_user_id' ) ) { function get_current_user_id() { return 1; } }
if ( ! function_exists( 'is_user_logged_in' ) ) { function is_user_logged_in() { return array() !== $GLOBALS['__caps']; } }
if ( ! function_exists( 'get_bloginfo' ) ) { function get_bloginfo( $s = 'name' ) { return 'Witryna Testowa'; } }

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = false ) {
		return array_key_exists( $key, $GLOBALS['__opt'] ) ? $GLOBALS['__opt'][ $key ] : $default;
	}
}
if ( ! function_exists( 'update_option' ) ) { function update_option( $k, $v, $a = null ) { $GLOBALS['__opt'][ $k ] = $v; return true; } }
if ( ! function_exists( 'delete_option' ) ) { function delete_option( $k ) { unset( $GLOBALS['__opt'][ $k ] ); return true; } }
if ( ! function_exists( 'get_transient' ) ) { function get_transient( $k ) { return $GLOBALS['__tr'][ $k ] ?? false; } }
if ( ! function_exists( 'set_transient' ) ) { function set_transient( $k, $v, $t = 0 ) { $GLOBALS['__tr'][ $k ] = $v; return true; } }
if ( ! function_exists( 'delete_transient' ) ) { function delete_transient( $k ) { unset( $GLOBALS['__tr'][ $k ] ); return true; } }

// ---------------------------------------------------------------------------
// SEKCJA A — ATRAPA CAP-ŚWIADOMA (KONTRAKT §11.5, poprawka FZ39).
//
// Wszystkie jedenaście istniejących atrap w `tests/` ma postać
//     function current_user_can( $cap ) { return (bool) $GLOBALS['__flaga']; }
// czyli IGNORUJE `$cap`. Ta czyta z TABLICY dozwolonych capów bieżącej tożsamości.
// ---------------------------------------------------------------------------
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $cap ) {
		$c                        = (string) $cap;
		$GLOBALS['__cap_calls'][] = $c;
		return in_array( $c, (array) $GLOBALS['__caps'], true );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) {
		$args = func_get_args();
		array_shift( $args );
		if ( isset( $GLOBALS['__filters'][ $tag ] ) ) {
			return call_user_func_array( $GLOBALS['__filters'][ $tag ], $args );
		}
		return $value;
	}
}
if ( ! function_exists( 'register_rest_route' ) ) {
	function register_rest_route( $ns, $route, $args = array() ) {
		$GLOBALS['__routes'][] = array( 'ns' => $ns, 'route' => $route, 'args' => $args );
		return true;
	}
}
if ( ! function_exists( 'get_current_screen' ) ) { function get_current_screen() { return $GLOBALS['__screen']; } }
if ( ! function_exists( 'add_meta_box' ) ) {
	function add_meta_box( $id, $title, $cb, $screen = null, $context = 'advanced', $priority = 'default', $args = null ) {
		$GLOBALS['__boxes'][] = array( 'id' => $id, 'screen' => $screen, 'context' => $context );
		return true;
	}
}
if ( ! function_exists( 'wp_enqueue_style' ) ) {
	function wp_enqueue_style( $h, $s = '', $d = array(), $v = false, $m = 'all' ) { $GLOBALS['__styles'][] = $h; return true; }
}
if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script( $h, $s = '', $d = array(), $v = false, $f = false ) { $GLOBALS['__scripts'][] = $h; return true; }
}
if ( ! function_exists( 'wp_localize_script' ) ) {
	function wp_localize_script( $h, $o, $d ) { $GLOBALS['__localized'][] = $o; return true; }
}

// ---------------------------------------------------------------------------
// Shimy klas WP.
// ---------------------------------------------------------------------------
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code; private $message; private $data;
		public function __construct( $code = '', $message = '', $data = '' ) { $this->code = $code; $this->message = $message; $this->data = $data; }
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
		public function get_error_data() { return $this->data; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $t ) { return $t instanceof WP_Error; } }
if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		private $params = array();
		public function set_param( $k, $v ) { $this->params[ $k ] = $v; }
		public function get_param( $k ) { return $this->params[ $k ] ?? null; }
	}
}
if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		private $data; private $status;
		public function __construct( $data = null, $status = 200 ) { $this->data = $data; $this->status = (int) $status; }
		public function get_data() { return $this->data; }
		public function get_status() { return $this->status; }
	}
}
if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public $ID = 1;
		public $post_type = 'post';
		public $post_title = 'Tytul';
		public $post_content = 'Tresc';
	}
}
/** Atrapa ekranu wp-admin (metabox pyta `method_exists( $screen, 'is_block_editor' )`). */
class K20_Screen {
	public $post_type = 'post';
	public $base      = 'post';
	private $block    = false;
	public function __construct( $post_type = 'post', $block = false ) { $this->post_type = $post_type; $this->block = (bool) $block; }
	public function is_block_editor() { return $this->block; }
}

// ---------------------------------------------------------------------------
// Harness.
// ---------------------------------------------------------------------------
$fail = 0;
$ran  = 0;
function check( $cond, $label ) {
	global $fail, $ran;
	++$ran;
	echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n";
	if ( ! $cond ) { ++$fail; }
}

/** Ustawia tożsamość: lista capów, jakie ma bieżący użytkownik. */
function k20_identity( array $caps ) {
	$GLOBALS['__caps']      = $caps;
	$GLOBALS['__cap_calls'] = array();
}

// Cztery tożsamości z §5.1/§5.2.
$ADMIN       = array( 'manage_options', 'publish_posts', 'edit_posts', 'edit_others_posts', 'read' );
$REDAKTOR    = array( 'publish_posts', 'edit_posts', 'edit_others_posts', 'read' );   // Redaktor/Autor
$WSPOLPRAC   = array( 'edit_posts', 'read' );                                          // Współpracownik — ŚWIADOMIE BEZ dostępu
$GOSC        = array();

// ---------------------------------------------------------------------------
// Ładowanie kodu — ręczne require w kolejności, każdy pod file_exists()
// (brak pliku ma dać CZERWONE asercje, nie PHP Fatal).
// ---------------------------------------------------------------------------
$k20_files = array(
	'src/Core/Settings.php',       // O-43: bez tego ip_hash() cicho testuje ścieżkę „OFF”
	'src/Rag/RagService.php',      // stała MAX_QUESTION_LEN
	'src/App/HistoryPanel.php',
	'src/Rest/RestController.php',
	'src/Admin/Menu.php',
	'src/Admin/PostMetaBox.php',
);
foreach ( $k20_files as $rel ) {
	$abs = AIFAQ_PLUGIN_DIR . $rel;
	if ( is_file( $abs ) ) { require_once $abs; }
}

$has_rest     = class_exists( 'AIFAQ\Rest\RestController' );
$has_menu     = class_exists( 'AIFAQ\Admin\Menu' );
$has_metabox  = class_exists( 'AIFAQ\Admin\PostMetaBox' );
$has_settings = class_exists( 'AIFAQ\Core\Settings' );

// ===========================================================================
echo "=== A. CAP-ŚWIADOMA ATRAPA current_user_can() (§11.5, FZ39) ===\n";
// ===========================================================================
// Bez tych czterech asercji cały plik jest teatrem — dokładnie takim, jakim jest
// dziś macierz uprawnień w jedenastu istniejących zestawach.
k20_identity( array( 'publish_posts' ) );
check( false === current_user_can( 'manage_options' ), 'ATRAPA: zestaw [publish_posts] → current_user_can( manage_options ) === false (atrapa HONORUJE argument)' );
check( true === current_user_can( 'publish_posts' ), 'ATRAPA: zestaw [publish_posts] → current_user_can( publish_posts ) === true' );
k20_identity( array() );
check( false === current_user_can( 'read' ), 'ATRAPA: pusty zestaw → dowolny cap === false' );
k20_identity( array( 'manage_options' ) );
check( false === current_user_can( 'publish_posts' ), 'ATRAPA: [manage_options] NIE implikuje publish_posts na poziomie atrapy (implikację ma dać KOD, nie shim)' );

// ===========================================================================
echo "\n=== B/C. Rejestracja tras + MACIERZ TOŻSAMOŚĆ × TRASA (§5.2) ===\n";
// ===========================================================================
$TOOL_ROUTES  = array( '/admin/generate-faq', '/admin/export' );
$ADMIN_ROUTES = array(
	'/admin/status', '/admin/reindex', '/admin/clear', '/admin/settings', '/admin/verify',
	'/admin/history', '/admin/history/clear', '/admin/generations', '/admin/generations/detail',
	'/admin/generations/delete',
);
$by_route = array();

if ( $has_rest ) {
	k20_identity( $ADMIN );
	$GLOBALS['__routes'] = array();
	$controller          = new \AIFAQ\Rest\RestController();
	$controller->register_routes();
	$routes = $GLOBALS['__routes'];
	foreach ( $routes as $r ) { $by_route[ $r['route'] ] = $r; }

	// Sekcja C — liczba tras.
	check( 13 === count( $routes ), 'C: register_rest_route wywołane DOKŁADNIE 13 razy (jest: ' . count( $routes ) . ') — §5.2 „ZERO nowych tras REST”' );
	check( 13 === count( $by_route ), 'C: 13 RÓŻNYCH ścieżek (żadna nie zarejestrowana dwa razy)' );
	check( 2 === count( array_intersect( $TOOL_ROUTES, array_keys( $by_route ) ) ), 'C: obie trasy narzędzia obecne: /admin/generate-faq, /admin/export' );
	check( 10 === count( array_intersect( $ADMIN_ROUTES, array_keys( $by_route ) ) ), 'C: dziesięć tras wyłącznie administracyjnych obecnych' );
	check( isset( $by_route['/ask'] ), 'C: trasa publiczna /ask obecna' );

	/** Woła permission_callback trasy przy bieżącej tożsamości. */
	$verdict = static function ( $route ) use ( $by_route ) {
		$pc = $by_route[ $route ]['args']['permission_callback'] ?? null;
		if ( null === $pc ) { return null; }
		return (bool) call_user_func( $pc );
	};

	// --- /ask: publiczne dla KAŻDEJ tożsamości (§5.2 „POST /ask bez zmian”) ---
	foreach ( array( 'administrator' => $ADMIN, 'Redaktor' => $REDAKTOR, 'Współpracownik' => $WSPOLPRAC, 'gość' => $GOSC ) as $name => $caps ) {
		k20_identity( $caps );
		check( true === $verdict( '/ask' ), 'B: /ask + ' . $name . ' → PRZEPUSZCZA (trasa publiczna, §5.2)' );
	}

	// --- administrator: WSZYSTKIE 12 tras /admin/* przepuszczają ---
	k20_identity( $ADMIN );
	$adm_pass = 0;
	foreach ( array_merge( $TOOL_ROUTES, $ADMIN_ROUTES ) as $r ) {
		if ( true === $verdict( $r ) ) { ++$adm_pass; }
	}
	check( 12 === $adm_pass, 'B: administrator (manage_options) → 12 z 12 tras /admin/* PRZEPUSZCZA (jest: ' . $adm_pass . ')' );

	// --- Redaktor/Autor: 2 x true (narzędzie), 10 x false (reszta) ---
	k20_identity( $REDAKTOR );
	foreach ( $TOOL_ROUTES as $r ) {
		check( true === $verdict( $r ), 'B: ' . $r . ' + Redaktor (publish_posts, BEZ manage_options) → PRZEPUSZCZA (§5.2)' );
	}
	$red_block = 0;
	foreach ( $ADMIN_ROUTES as $r ) {
		$v = $verdict( $r );
		check( false === $v, 'B: ' . $r . ' + Redaktor → ODRZUCA (trasa NIETYKALNA, §5.2)' );
		if ( false === $v ) { ++$red_block; }
	}
	check( 10 === $red_block, 'B: Redaktor odbity na DOKŁADNIE 10 trasach administracyjnych (jest: ' . $red_block . ')' );
	// Trasy najgroźniejsze — nazwane imiennie, bo ich rozszczelnienie oddaje klucz API i dane gości.
	check( false === $verdict( '/admin/settings' ), 'B (KLUCZ API): /admin/settings + Redaktor → ODRZUCA — zapis ustawień z api_key' );
	check( false === $verdict( '/admin/verify' ), 'B (KLUCZ API): /admin/verify + Redaktor → ODRZUCA — test klucza' );
	check( false === $verdict( '/admin/history' ), 'B (RODO): /admin/history + Redaktor → ODRZUCA — dziennik pytań gości' );
	check( false === $verdict( '/admin/history/clear' ), 'B (RODO): /admin/history/clear + Redaktor → ODRZUCA — kasowanie dziennika gości' );
	check( false === $verdict( '/admin/reindex' ), 'B: /admin/reindex + Redaktor → ODRZUCA — reindeks zjada pulę embeddingów' );
	check( false === $verdict( '/admin/clear' ), 'B: /admin/clear + Redaktor → ODRZUCA — kasowanie bazy wiedzy' );

	// --- Współpracownik (edit_posts, BEZ publish_posts): odbity WSZĘDZIE ---
	// To jest asercja przeciw wyborowi `edit_posts` zamiast `publish_posts` (§5.1,
	// „świadomie bez Współpracownika — decyzja o pieniądzach: 20 żądań/dobę”).
	k20_identity( $WSPOLPRAC );
	foreach ( $TOOL_ROUTES as $r ) {
		check( false === $verdict( $r ), 'B: ' . $r . ' + Współpracownik (edit_posts, BEZ publish_posts) → ODRZUCA (§5.1)' );
	}

	// --- gość: /ask tak, wszystko inne nie ---
	k20_identity( $GOSC );
	$guest_block = 0;
	foreach ( array_merge( $TOOL_ROUTES, $ADMIN_ROUTES ) as $r ) {
		if ( false === $verdict( $r ) ) { ++$guest_block; }
	}
	check( 12 === $guest_block, 'B: gość (zero capów) → 12 z 12 tras /admin/* ODRZUCA (jest: ' . $guest_block . ')' );

	// --- Stałe i metoda źródłowa (§5.1) ---
	k20_identity( $ADMIN );
	check( defined( 'AIFAQ\Rest\RestController::CAPABILITY_TOOL' ), '§5.1: stała RestController::CAPABILITY_TOOL istnieje' );
	if ( defined( 'AIFAQ\Rest\RestController::CAPABILITY_TOOL' ) ) {
		check( 'publish_posts' === (string) constant( 'AIFAQ\Rest\RestController::CAPABILITY_TOOL' ), '§5.1: CAPABILITY_TOOL === publish_posts (NIE edit_posts)' );
	} else {
		check( false, '§5.1: CAPABILITY_TOOL === publish_posts — pominięte, brak stałej' );
	}
	check( defined( 'AIFAQ\Rest\RestController::CAPABILITY' ) && 'manage_options' === (string) constant( 'AIFAQ\Rest\RestController::CAPABILITY' ), '§5.2: RestController::CAPABILITY nadal manage_options' );
	check( method_exists( 'AIFAQ\Rest\RestController', 'tool_capability' ), '§5.1: publiczna metoda tool_capability() istnieje (JEDYNE źródło capa)' );
	check( method_exists( 'AIFAQ\Rest\RestController', 'require_tool_user' ), '§5.1: metoda require_tool_user() istnieje' );
	check( method_exists( 'AIFAQ\Rest\RestController', 'require_admin' ), '§5.2: require_admin() bez zmian' );
} else {
	check( false, 'sekcje B i C pominięte — brak klasy AIFAQ\Rest\RestController' );
}

// ===========================================================================
echo "\n=== D. JEDNO ŹRÓDŁO CAPA — metabox vs trasy narzędzia (§5.1, FZ19) ===\n";
// ===========================================================================
// Asercja przeciw rozjazdowi „UI widoczne, klik daje 403”: rola, która WIDZI metabox,
// MUSI przejść na obu trasach narzędzia — i odwrotnie.
if ( $has_rest && $has_metabox ) {
	$verdict2 = static function ( $route ) use ( $by_route ) {
		$pc = $by_route[ $route ]['args']['permission_callback'] ?? null;
		return null === $pc ? null : (bool) call_user_func( $pc );
	};
	$box = new \AIFAQ\Admin\PostMetaBox();

	// Redaktor: metabox JEST i obie trasy przepuszczają.
	k20_identity( $REDAKTOR );
	$GLOBALS['__boxes'] = array();
	$box->register_box( 'post' );
	$red_box = count( $GLOBALS['__boxes'] );
	check( 1 === $red_box, 'D: Redaktor → DOKŁADNIE 1 add_meta_box (metabox widoczny; jest: ' . $red_box . ')' );
	check( true === $verdict2( '/admin/generate-faq' ) && true === $verdict2( '/admin/export' ), 'D: Redaktor widzi metabox ⇒ PRZECHODZI na /admin/generate-faq ORAZ /admin/export' );

	// Administrator: metabox JEST (nie odebraliśmy go adminowi przy okazji).
	k20_identity( $ADMIN );
	$GLOBALS['__boxes'] = array();
	$box->register_box( 'post' );
	check( 1 === count( $GLOBALS['__boxes'] ), 'D: administrator → DOKŁADNIE 1 add_meta_box (§5.1 „administrator przechodzi zawsze”)' );

	// Współpracownik: metaboksu NIE MA i obie trasy odrzucają.
	k20_identity( $WSPOLPRAC );
	$GLOBALS['__boxes'] = array();
	$box->register_box( 'post' );
	check( 0 === count( $GLOBALS['__boxes'] ), 'D: Współpracownik → 0 add_meta_box (jest: ' . count( $GLOBALS['__boxes'] ) . ')' );
	check( false === $verdict2( '/admin/generate-faq' ) && false === $verdict2( '/admin/export' ), 'D: Współpracownik nie widzi metaboksu ⇒ ODBITY na obu trasach narzędzia' );

	// Gość (np. żądanie bez sesji): zero metaboksów.
	k20_identity( $GOSC );
	$GLOBALS['__boxes'] = array();
	$box->register_box( 'post' );
	check( 0 === count( $GLOBALS['__boxes'] ), 'D: brak capów → 0 add_meta_box' );

	// enqueue() czyta ten sam cap co register_box() (§5.1: „wszystkie trzy miejsca”).
	if ( method_exists( $box, 'enqueue' ) ) {
		$GLOBALS['__screen'] = new K20_Screen( 'post', false );

		k20_identity( $ADMIN );
		$GLOBALS['__scripts'] = array();
		$box->enqueue( 'post.php' );
		$adm_scripts = count( $GLOBALS['__scripts'] );

		k20_identity( $REDAKTOR );
		$GLOBALS['__scripts'] = array();
		$box->enqueue( 'post.php' );
		$red_scripts = count( $GLOBALS['__scripts'] );

		k20_identity( $WSPOLPRAC );
		$GLOBALS['__scripts'] = array();
		$box->enqueue( 'post.php' );
		$wsp_scripts = count( $GLOBALS['__scripts'] );

		// Celowo BEZ hardkodowania liczby skryptów (to zakres K16) — mierzymy RÓWNE traktowanie.
		check( $adm_scripts === $red_scripts, 'D: enqueue() traktuje Redaktora DOKŁADNIE jak administratora (' . $red_scripts . ' vs ' . $adm_scripts . ' skryptów)' );
		check( 0 === $wsp_scripts, 'D: enqueue() + Współpracownik → 0 skryptów (jest: ' . $wsp_scripts . ')' );
		check( 1 === $adm_scripts, 'D: enqueue() + administrator → 1 skrypt metaboksu (kotwica: bez tego równość wyżej byłaby 0 === 0)' );
		$GLOBALS['__screen'] = null;
	} else {
		check( false, 'D: enqueue() pominięte — brak metody PostMetaBox::enqueue()' );
	}
} else {
	check( false, 'sekcja D pominięta — brak RestController albo PostMetaBox' );
}

// ===========================================================================
echo "\n=== E. Filtr aifaq_tool_capability + administrator ZAWSZE (§5.1) ===\n";
// ===========================================================================
if ( $has_rest && method_exists( 'AIFAQ\Rest\RestController', 'tool_capability' ) ) {
	$verdict3 = static function ( $route ) use ( $by_route ) {
		$pc = $by_route[ $route ]['args']['permission_callback'] ?? null;
		return null === $pc ? null : (bool) call_user_func( $pc );
	};

	$GLOBALS['__filters'] = array();
	k20_identity( $ADMIN );
	check( 'publish_posts' === (string) \AIFAQ\Rest\RestController::tool_capability(), 'E: bez filtra tool_capability() === publish_posts' );

	// Filtr zawężający do capa EGZOTYCZNEGO.
	$GLOBALS['__filters']['aifaq_tool_capability'] = static function () { return 'aifaq_egzotyczny_cap'; };
	check( 'aifaq_egzotyczny_cap' === (string) \AIFAQ\Rest\RestController::tool_capability(), 'E: filtr aifaq_tool_capability ZMIENIA cap narzędzia' );

	k20_identity( array( 'aifaq_egzotyczny_cap', 'read' ) );
	check( true === $verdict3( '/admin/generate-faq' ), 'E: rola z capem NADANYM przez filtr → PRZECHODZI na /admin/generate-faq' );
	check( true === $verdict3( '/admin/export' ), 'E: rola z capem NADANYM przez filtr → PRZECHODZI na /admin/export' );

	k20_identity( $REDAKTOR );
	check( false === $verdict3( '/admin/generate-faq' ), 'E: filtr ZAWĘŻAJĄCY odbija dotychczasowego Redaktora (publish_posts już nie wystarcza)' );

	// Administrator BEZ egzotycznego capa — musi przejść mimo filtra (§5.1, zdanie ostatnie).
	k20_identity( array( 'manage_options' ) );
	check( true === $verdict3( '/admin/generate-faq' ), 'E: administrator przechodzi ZAWSZE, także przy filtrze zwracającym cap egzotyczny' );
	check( true === $verdict3( '/admin/export' ), 'E: administrator przechodzi ZAWSZE — /admin/export' );

	// PostMetaBox czyta cap TĄ SAMĄ drogą (FZ19: inaczej UI widoczne, klik = 403).
	if ( $has_metabox ) {
		$box2 = new \AIFAQ\Admin\PostMetaBox();
		k20_identity( array( 'aifaq_egzotyczny_cap', 'read' ) );
		$GLOBALS['__boxes'] = array();
		$box2->register_box( 'post' );
		check( 1 === count( $GLOBALS['__boxes'] ), 'E (FZ19): metabox WIDOCZNY dla roli z capem z filtra — metabox NIE czyta surowej stałej' );

		k20_identity( $REDAKTOR );
		$GLOBALS['__boxes'] = array();
		$box2->register_box( 'post' );
		check( 0 === count( $GLOBALS['__boxes'] ), 'E (FZ19): metabox NIEWIDOCZNY dla Redaktora przy filtrze zawężającym (zero rozjazdu UI↔trasa)' );
	} else {
		check( false, 'E (FZ19): część metaboksowa pominięta — brak PostMetaBox' );
	}
	$GLOBALS['__filters'] = array();
} else {
	check( false, 'sekcja E pominięta — brak RestController::tool_capability()' );
}

// ===========================================================================
echo "\n=== F. Menu::CAPABILITY NIETKNIĘTE (§5.2) ===\n";
// ===========================================================================
if ( $has_menu ) {
	check( defined( 'AIFAQ\Admin\Menu::CAPABILITY' ), 'F: stała Menu::CAPABILITY istnieje' );
	check( defined( 'AIFAQ\Admin\Menu::CAPABILITY' ) && 'manage_options' === (string) constant( 'AIFAQ\Admin\Menu::CAPABILITY' ), 'F: Menu::CAPABILITY === manage_options (obniżenie odsłania Ustawienia z kluczem API)' );
} else {
	check( false, 'sekcja F pominięta — brak klasy AIFAQ\Admin\Menu' );
}

// ===========================================================================
echo "\n=== G. D1 — tożsamość gościa za proxy (§5.3, poprawka FZ20) ===\n";
// ===========================================================================
// UWAGA (O-41): karty `ETAP-4.md` i `ETAP-9b.md` mówią „PIERWSZY element X-Forwarded-For”.
// KONTRAKT k20-v3 §5.3 (poprawka FZ20) mówi OSTATNI — bo Cloudflare i
// `$proxy_add_x_forwarded_for` doklejają obserwowany adres na KONIEC, a pierwszy element
// pochodzi OD KLIENTA (czyli dawałby gościowi świeży kubełek limitera co żądanie).
// Asertujemy KONTRAKT.
if ( $has_rest && $has_settings ) {
	$rc     = new \AIFAQ\Rest\RestController();
	$ref    = new ReflectionMethod( 'AIFAQ\Rest\RestController', 'ip_hash' );
	$ref->setAccessible( true );
	$hash   = static function () use ( $ref, $rc ) { return (string) $ref->invoke( $rc ); };
	$set_px = static function ( $on ) {
		$GLOBALS['__opt']['aifaq_settings'] = array( 'rag_trusted_proxy' => $on ? '1' : '0' );
	};
	$clear_headers = static function () {
		unset( $_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_X_FORWARDED_FOR'] );
	};
	k20_identity( $GOSC );

	// Punkty odniesienia: czysty REMOTE_ADDR, przełącznik WYŁĄCZONY.
	$set_px( false );
	$clear_headers();
	$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
	$ref_remote             = $hash();
	$_SERVER['REMOTE_ADDR'] = '198.51.100.42';
	$ref_cf                 = $hash();
	$_SERVER['REMOTE_ADDR'] = '192.0.2.55';
	$ref_xff_last           = $hash();

	check( 1 === preg_match( '/^[0-9a-f]{64}$/', $ref_remote ), 'G: ip_hash to nadal 64 znaki hex (sha256)' );
	check( $ref_remote !== $ref_cf, 'G: dwa różne adresy → RÓŻNE hashe' );

	// 1. Przełącznik WYŁĄCZONY + nagłówki obecne → hash IDENTYCZNY jak dla samego REMOTE_ADDR.
	$set_px( false );
	$_SERVER['REMOTE_ADDR']           = '203.0.113.7';
	$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.42';
	$_SERVER['HTTP_X_FORWARDED_FOR']  = '10.0.0.1, 192.0.2.55';
	check( $ref_remote === $hash(), 'G: rag_trusted_proxy=0 + oba nagłówki → hash IDENTYCZNY jak dla samego REMOTE_ADDR (domyślnie „bit w bit jak dziś”)' );

	// 1-bis. Sygnalizacja FZ21 — wykryte proxy przy wyłączonym przełączniku.
	check( '1' === (string) get_option( 'aifaq_proxy_seen', '' ), 'G (FZ21): przełącznik OFF + nagłówek proxy → aifaq_proxy_seen = 1 (właściciel ma się dowiedzieć)' );
	delete_option( 'aifaq_proxy_seen' );
	$set_px( false );
	$clear_headers();
	$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
	$hash();
	check( false === get_option( 'aifaq_proxy_seen', false ), 'G (FZ21): brak nagłówków proxy → aifaq_proxy_seen NIE powstaje' );

	// 2. Przełącznik WŁĄCZONY: CF-Connecting-IP wygrywa.
	$set_px( true );
	$clear_headers();
	$_SERVER['REMOTE_ADDR']           = '203.0.113.7';
	$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.42';
	$_SERVER['HTTP_X_FORWARDED_FOR']  = '10.0.0.1, 192.0.2.55';
	check( $ref_cf === $hash(), 'G: rag_trusted_proxy=1 → CF-Connecting-IP WYGRYWA (hash = hash samego 198.51.100.42)' );

	// 3. Bez CF: OSTATNI element X-Forwarded-For (FZ20).
	$set_px( true );
	$clear_headers();
	$_SERVER['REMOTE_ADDR']          = '203.0.113.7';
	$_SERVER['HTTP_X_FORWARDED_FOR'] = '10.0.0.1, 172.16.0.9, 192.0.2.55';
	$h_xff                           = $hash();
	check( $ref_xff_last === $h_xff, 'G (FZ20): bez CF → OSTATNI element XFF (192.0.2.55), nie pierwszy — proxy dokleja na KONIEC' );
	check( $ref_remote !== $h_xff, 'G: XFF realnie zmienia hash (nie zostaje przy REMOTE_ADDR)' );

	// 4. Oba nagłówki nieobecne → REMOTE_ADDR.
	$set_px( true );
	$clear_headers();
	$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
	check( $ref_remote === $hash(), 'G: przełącznik ON, brak obu nagłówków → REMOTE_ADDR' );

	// 5. Nieprawidłowy IP w nagłówku → REMOTE_ADDR (filter_var FILTER_VALIDATE_IP).
	$set_px( true );
	$clear_headers();
	$_SERVER['REMOTE_ADDR']           = '203.0.113.7';
	$_SERVER['HTTP_CF_CONNECTING_IP'] = 'nie-jest-adresem';
	check( $ref_remote === $hash(), 'G: nieprawidłowy CF-Connecting-IP → cofka do REMOTE_ADDR' );

	$set_px( true );
	$clear_headers();
	$_SERVER['REMOTE_ADDR']          = '203.0.113.7';
	$_SERVER['HTTP_X_FORWARDED_FOR'] = 'abc, 999.999.999.999';
	check( $ref_remote === $hash(), 'G: same śmieci w XFF → cofka do REMOTE_ADDR' );

	// 6. Determinizm i brak surowego IP.
	$set_px( true );
	$clear_headers();
	$_SERVER['REMOTE_ADDR']           = '203.0.113.7';
	$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.42';
	$a = $hash();
	$b = $hash();
	check( $a === $b, 'G: hash deterministyczny przy niezmienionym wejściu' );
	check( false === strpos( $a, '198.51.100.42' ) && false === strpos( $a, '203.0.113.7' ), 'G: hash nie zawiera surowego IP (GR7)' );
	check( 1 === preg_match( '/^[0-9a-f]{64}$/', $a ), 'G: hash z proxy też ma 64 znaki hex' );

	$clear_headers();
	unset( $GLOBALS['__opt']['aifaq_settings'] );
} else {
	check( false, 'sekcja G pominięta — brak RestController albo Core/Settings (O-43: bez Settings sekcja cicho testowałaby ścieżkę OFF)' );
}

// ===========================================================================
echo "\n=== Z. Podłoga pokrycia i wartownik końca pliku ===\n";
// ===========================================================================
check( $ran >= 40, 'podłoga pokrycia: wykonano co najmniej 40 asercji (jest: ' . $ran . ')' );
check( true, 'WARTOWNIK: wykonanie doszło do końca pliku (brak cichego Fatala w środku)' );

echo "\nAsercje: " . $ran . ', niezaliczone: ' . $fail . "\n";
exit( 0 === $fail ? 0 : 1 );
