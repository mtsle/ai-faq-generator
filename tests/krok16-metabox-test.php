<?php
/**
 * Testy Kroku 16 — metabox „AI FAQ" w edytorze wpisu.
 *
 * Pisane W CIEMNO wobec KONTRAKT k16-v2 (Etap 4): kod produkcyjny Etapów 1–3 powstaje
 * równolegle i w chwili pisania tego pliku mógł jeszcze nie istnieć. Asercje pochodzą
 * WYŁĄCZNIE z kontraktu — test zielony na kodzie napisanym niezależnie dowodzi, że
 * kontrakt był kompletny.
 *
 * Pokrywa (bez pełnego środowiska WP — shimy + rejestry wywołań):
 *  - A. `PostMetaBox`: 6 stałych co do wartości + 5 metod publicznych (§2a/§2b);
 *  - B. `config()`: zestaw kluczy po sort(), sufiksy endpointów, `defaults`, ZERO sekretów (§2c);
 *  - C. i18n: parytet pl/en/de, DOKŁADNIE 23 klucze z §6, zero pustych, fallback na pl;
 *  - D. `register_box()`: post/page → 1 metabox, attachment → 0, brak capa → 0 (R5);
 *  - E. `enqueue()`: bramka hooka/ekranu/capa, 2 style + 1 skrypt w stopce + 1 localize,
 *       deps edytora blokowego (§2b);
 *  - F/G/H. statyka widoku, JS i CSS czytane jako TEKST — każda sekcja z kotwicą rozmiaru,
 *       bo asercja negatywna na pustym pliku jest zawsze prawdziwa;
 *  - I. rejestracja w `Plugin.php` — `preg_match`, nigdy `strpos` (plik ma już hook
 *       `admin_enqueue_scripts` dla `Menu`, więc gołe szukanie przeszłoby na NIEZMIENIONYM pliku).
 *
 * Asercje ilościowe celowo jako `=== N`, nigdy `> 0` — słaba asercja („są jakieś pary")
 * przepuściła w tym projekcie realny błąd na dwa wydania (fix w v0.18.0).
 *
 * URUCHOMIENIE:  php tests/krok16-metabox-test.php
 * Kod wyjścia: 0 = OK, 1 = błędy.
 *
 * @package AI_FAQ_Generator
 */

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
if ( ! defined( 'AIFAQ_PLUGIN_DIR' ) ) { define( 'AIFAQ_PLUGIN_DIR', dirname( __DIR__ ) . '/' ); }
if ( ! defined( 'AIFAQ_PLUGIN_URL' ) ) { define( 'AIFAQ_PLUGIN_URL', 'https://example.test/wp-content/plugins/ai-faq-generator/' ); }
if ( ! defined( 'AIFAQ_VERSION' ) ) { define( 'AIFAQ_VERSION', '0.19.0-test' ); }

// ---------------------------------------------------------------------------
// Rejestry wywołań (shimy zbierają, sekcje D/E asertują).
// ---------------------------------------------------------------------------
$GLOBALS['__opt']              = array();     // magazyn get_option()
$GLOBALS['__aifaq_can']        = true;        // czy ktokolwiek jest zalogowany (wyłącznik zbiorczy)
// K20 (§11.5, RYZYKO NR 1): atrapa `current_user_can()` musi być CAP-ŚWIADOMA. Globalny bool
// przepuszczał każdy cap, więc sekcja D była zielona NIEZALEŻNIE od tego, jakiego capa
// sprawdza kod — dało się wpisać `edit_posts` i nie zobaczyć ani jednej czerwieni.
// Domyślna tożsamość: administrator.
$GLOBALS['__aifaq_caps']       = array( 'manage_options', 'publish_posts', 'edit_posts', 'edit_others_posts', 'read' );
$GLOBALS['__aifaq_screen']     = null;        // wynik get_current_screen()
$GLOBALS['__aifaq_boxes']      = array();
$GLOBALS['__aifaq_styles']     = array();
$GLOBALS['__aifaq_scripts']    = array();
$GLOBALS['__aifaq_localized']  = array();

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
if ( ! function_exists( 'register_rest_route' ) ) { function register_rest_route( $ns, $r, $a = array() ) { return true; } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . ltrim( (string) $p, '/' ); } }
if ( ! function_exists( 'rest_url' ) ) { function rest_url( $p = '' ) { return 'https://example.test/wp-json/' . ltrim( (string) $p, '/' ); } }
if ( ! function_exists( 'wp_create_nonce' ) ) { function wp_create_nonce( $a = '' ) { return 'nonce-' . (string) $a; } }

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = false ) {
		return array_key_exists( $key, $GLOBALS['__opt'] ) ? $GLOBALS['__opt'][ $key ] : $default;
	}
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $cap ) {
		if ( ! (bool) $GLOBALS['__aifaq_can'] ) {
			return false;   // nikt nie jest zalogowany — żaden cap nie przechodzi
		}
		return in_array( (string) $cap, (array) $GLOBALS['__aifaq_caps'], true );
	}
}
if ( ! function_exists( 'get_current_screen' ) ) {
	function get_current_screen() { return $GLOBALS['__aifaq_screen']; }
}
if ( ! function_exists( 'add_meta_box' ) ) {
	function add_meta_box( $id, $title, $callback, $screen = null, $context = 'advanced', $priority = 'default', $args = null ) {
		$GLOBALS['__aifaq_boxes'][] = array(
			'id'       => $id,
			'title'    => $title,
			'callback' => $callback,
			'screen'   => $screen,
			'context'  => $context,
			'priority' => $priority,
		);
		return true;
	}
}
if ( ! function_exists( 'wp_enqueue_style' ) ) {
	function wp_enqueue_style( $handle, $src = '', $deps = array(), $ver = false, $media = 'all' ) {
		$GLOBALS['__aifaq_styles'][] = array( 'handle' => $handle, 'src' => $src, 'deps' => $deps, 'ver' => $ver );
		return true;
	}
}
if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script( $handle, $src = '', $deps = array(), $ver = false, $in_footer = false ) {
		$GLOBALS['__aifaq_scripts'][] = array( 'handle' => $handle, 'src' => $src, 'deps' => $deps, 'ver' => $ver, 'footer' => $in_footer );
		return true;
	}
}
if ( ! function_exists( 'wp_localize_script' ) ) {
	function wp_localize_script( $handle, $object_name, $data ) {
		$GLOBALS['__aifaq_localized'][] = array( 'handle' => $handle, 'object' => $object_name, 'data' => $data );
		return true;
	}
}

// ---------------------------------------------------------------------------
// Shimy klas WP + atrapa ekranu (§2b woła method_exists( $screen, 'is_block_editor' )).
// ---------------------------------------------------------------------------
if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public $ID = 1;
		public $post_type = 'post';
		public $post_title = 'Tytul testowy';
		public $post_content = 'Tresc testowa';
	}
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code; private $message;
		public function __construct( $code = '', $message = '', $data = '' ) { $this->code = $code; $this->message = $message; }
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
	}
}

/** Atrapa ekranu wp-admin. */
class AifaqTestScreen {
	public $post_type = 'post';
	public $base      = 'post';
	private $block    = false;
	public function __construct( $post_type = 'post', $block = false ) {
		$this->post_type = $post_type;
		$this->block     = (bool) $block;
	}
	public function is_block_editor() { return $this->block; }
}

// ---------------------------------------------------------------------------
// DOKŁADNIE cztery require (§7a). Warunkowo — brak pliku Etapu 1 ma dać CZERWONE
// asercje, nie fatala: test musi się uruchomić także zanim etapy się zejdą.
// ---------------------------------------------------------------------------
$aifaq_req = array(
	'src/Core/Settings.php',
	'src/Rest/RestController.php',
	'src/Admin/Menu.php',
	'src/Admin/PostMetaBox.php',
);
$aifaq_missing = array();
foreach ( $aifaq_req as $aifaq_rel ) {
	$aifaq_abs = AIFAQ_PLUGIN_DIR . $aifaq_rel;
	if ( is_file( $aifaq_abs ) ) {
		require_once $aifaq_abs;
	} else {
		$aifaq_missing[] = $aifaq_rel;
	}
}

use AIFAQ\Admin\Menu;
use AIFAQ\Admin\PostMetaBox;
use AIFAQ\Core\Settings;
use AIFAQ\Rest\RestController;

// ---------------------------------------------------------------------------
// Harness.
// ---------------------------------------------------------------------------
$fail = 0;
function check( $cond, $label ) {
	global $fail;
	echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n";
	if ( ! $cond ) { $fail++; }
}

/** Bezpieczny odczyt stałej PostMetaBox (brak stałej nie może wywalić testu). */
function mb_const( $name ) {
	$full = 'AIFAQ\\Admin\\PostMetaBox::' . $name;
	return defined( $full ) ? constant( $full ) : null;
}
/** Bezpieczne wywołanie statycznej metody PostMetaBox. */
function mb_call( $method, $args = array() ) {
	if ( ! class_exists( 'AIFAQ\\Admin\\PostMetaBox' ) || ! method_exists( 'AIFAQ\\Admin\\PostMetaBox', $method ) ) {
		return null;
	}
	return call_user_func_array( array( 'AIFAQ\\Admin\\PostMetaBox', $method ), $args );
}
function mb_reset_boxes() { $GLOBALS['__aifaq_boxes'] = array(); }
function mb_reset_assets() {
	$GLOBALS['__aifaq_styles']    = array();
	$GLOBALS['__aifaq_scripts']   = array();
	$GLOBALS['__aifaq_localized'] = array();
}
function mb_handles( $rows ) {
	$out = array();
	foreach ( $rows as $r ) { $out[] = $r['handle']; }
	return $out;
}
function mb_read( $rel ) {
	$abs = AIFAQ_PLUGIN_DIR . $rel;
	return is_file( $abs ) ? (string) file_get_contents( $abs ) : '';
}

$has_mb = class_exists( 'AIFAQ\\Admin\\PostMetaBox' );

// ===========================================================================
echo "=== A. Klasa PostMetaBox — stale i sygnatury (KONTRAKT §2a/§2b) ===\n";
check( empty( $aifaq_missing ), 'wszystkie 4 wymagane pliki zrodlowe istnieja' . ( empty( $aifaq_missing ) ? '' : ' — brakuje: ' . implode( ', ', $aifaq_missing ) ) );
check( $has_mb, 'klasa AIFAQ\Admin\PostMetaBox istnieje (PSR-4: src/Admin/PostMetaBox.php)' );

if ( $has_mb ) {
	check( 'aifaq_faq' === mb_const( 'BOX_ID' ), 'BOX_ID = aifaq_faq' );
	check( array( 'post', 'page' ) === mb_const( 'POST_TYPES' ), 'POST_TYPES = array( post, page )' );
	check( 6000 === mb_const( 'MAX_CONTENT_CHARS' ), 'MAX_CONTENT_CHARS = 6000' );
	check( 5 === mb_const( 'MIN_COUNT' ), 'MIN_COUNT = 5' );
	check( 20 === mb_const( 'MAX_COUNT' ), 'MAX_COUNT = 20' );
	check( 10 === mb_const( 'DEFAULT_COUNT' ), 'DEFAULT_COUNT = 10' );

	foreach ( array( 'register_box', 'render', 'enqueue', 'config', 'strings' ) as $m ) {
		check( method_exists( 'AIFAQ\\Admin\\PostMetaBox', $m ), "metoda publiczna {$m}() istnieje" );
	}
} else {
	check( false, 'sekcja A pominieta — brak klasy PostMetaBox' );
}

// ===========================================================================
echo "\n=== B. config() → window.aifaqMetabox (KONTRAKT §2c) ===\n";
if ( $has_mb && method_exists( 'AIFAQ\\Admin\\PostMetaBox', 'config' ) ) {
	$GLOBALS['__opt']['aifaq_settings'] = array( 'language' => 'pl' );

	$cfg  = mb_call( 'config' );
	check( is_array( $cfg ), 'config() zwraca tablice' );

	$keys = is_array( $cfg ) ? array_keys( $cfg ) : array();
	sort( $keys );
	check(
		array( 'defaults', 'endpoint', 'exportEndpoint', 'i18n', 'maxContentChars', 'nonce' ) === $keys,
		'config() ma DOKLADNIE 6 kluczy (po sort): defaults, endpoint, exportEndpoint, i18n, maxContentChars, nonce'
	);

	$ep  = isset( $cfg['endpoint'] ) ? (string) $cfg['endpoint'] : '';
	$xep = isset( $cfg['exportEndpoint'] ) ? (string) $cfg['exportEndpoint'] : '';
	check( '' !== $ep && '/admin/generate-faq' === substr( $ep, -19 ), 'endpoint konczy sie /admin/generate-faq' );
	check( '' !== $xep && '/admin/export' === substr( $xep, -13 ), 'exportEndpoint konczy sie /admin/export' );
	check( class_exists( 'AIFAQ\\Rest\\RestController' ) && false !== strpos( $ep, RestController::REST_NAMESPACE ), 'endpoint zawiera namespace RestController::REST_NAMESPACE' );
	check( class_exists( 'AIFAQ\\Rest\\RestController' ) && false !== strpos( $xep, RestController::REST_NAMESPACE ), 'exportEndpoint zawiera namespace RestController::REST_NAMESPACE' );

	check( isset( $cfg['nonce'] ) && is_string( $cfg['nonce'] ) && '' !== $cfg['nonce'], 'nonce jest niepustym stringiem' );
	check( isset( $cfg['maxContentChars'] ) && 6000 === (int) $cfg['maxContentChars'], 'maxContentChars = MAX_CONTENT_CHARS (6000)' );

	$def = ( isset( $cfg['defaults'] ) && is_array( $cfg['defaults'] ) ) ? $cfg['defaults'] : array();
	$dk  = array_keys( $def );
	sort( $dk );
	check( array( 'count', 'language', 'max', 'min' ) === $dk, 'defaults ma DOKLADNIE klucze count, language, max, min' );
	check( 10 === ( isset( $def['count'] ) ? (int) $def['count'] : 0 ), 'defaults.count = 10' );
	check( 5 === ( isset( $def['min'] ) ? (int) $def['min'] : 0 ), 'defaults.min = 5' );
	check( 20 === ( isset( $def['max'] ) ? (int) $def['max'] : 0 ), 'defaults.max = 20' );
	check( 'pl' === ( isset( $def['language'] ) ? $def['language'] : '' ), 'defaults.language = pl (ustawienia: pl)' );

	check( isset( $cfg['i18n'] ) && is_array( $cfg['i18n'] ) && 23 === count( $cfg['i18n'] ), 'config().i18n niesie DOKLADNIE 23 klucze' );

	// Whitelista jezyka pl|en|de, fallback pl.
	$GLOBALS['__opt']['aifaq_settings'] = array( 'language' => 'de' );
	$cfg_de = mb_call( 'config' );
	check( 'de' === ( $cfg_de['defaults']['language'] ?? '' ), 'jezyk de z ustawien trafia do defaults.language' );

	$GLOBALS['__opt']['aifaq_settings'] = array( 'language' => 'xx' );
	$cfg_xx = mb_call( 'config' );
	check( 'pl' === ( $cfg_xx['defaults']['language'] ?? '' ), 'jezyk spoza whitelisty → fallback pl' );

	// ZERO SEKRETOW — kanarek o NIEPUSTEJ wartosci (strpos( $json, '' ) zwraca 0 → falszywy FAIL).
	$GLOBALS['__opt']['aifaq_settings'] = array( 'api_key' => 'SEKRET-KANAREK-12345', 'language' => 'pl' );
	$cfg_sec = mb_call( 'config' );
	$json    = wp_json_encode( $cfg_sec );
	check( is_string( $json ) && false === strpos( $json, 'SEKRET-KANAREK-12345' ), 'config() NIE wypuszcza klucza API do JS' );
	check( is_array( $cfg_sec ) && ! array_key_exists( 'api_key', $cfg_sec ), 'config() nie ma klucza api_key' );

	$GLOBALS['__opt']['aifaq_settings'] = array( 'language' => 'pl' );
} else {
	check( false, 'sekcja B pominieta — brak PostMetaBox::config()' );
}

// ===========================================================================
echo "\n=== C. i18n strings() — lista ZAMKNIETA 23 kluczy (KONTRAKT §6) ===\n";
$wymagane = array(
	'mbLead', 'mbCount', 'mbGenerate', 'mbGenerating', 'mbNeedTitle', 'mbNeedContent',
	'mbTrimmed', 'mbDescFrame', 'mbEmptyMsg', 'mbErrMsg', 'mbDoneFmt', 'mbCountFmt',
	'mbDelete', 'mbConfirmDel', 'mbInsert', 'mbInserting', 'mbInserted', 'mbInsertErr',
	'mbNoEditor', 'mbCopyAll', 'mbCopied', 'mbCopyErr', 'mbHint',
);
check( 23 === count( $wymagane ), 'lista referencyjna z §6 ma 23 klucze' );

if ( $has_mb && method_exists( 'AIFAQ\\Admin\\PostMetaBox', 'strings' ) ) {
	$pl = mb_call( 'strings', array( 'pl' ) );
	$en = mb_call( 'strings', array( 'en' ) );
	$de = mb_call( 'strings', array( 'de' ) );
	$pl = is_array( $pl ) ? $pl : array();
	$en = is_array( $en ) ? $en : array();
	$de = is_array( $de ) ? $de : array();

	check( 23 === count( $pl ), 'strings(pl) ma DOKLADNIE 23 klucze (jest: ' . count( $pl ) . ')' );
	check( 23 === count( $en ), 'strings(en) ma DOKLADNIE 23 klucze (jest: ' . count( $en ) . ')' );
	check( 23 === count( $de ), 'strings(de) ma DOKLADNIE 23 klucze (jest: ' . count( $de ) . ')' );

	$brak   = array_diff( $wymagane, array_keys( $pl ) );
	$nadmiar = array_diff( array_keys( $pl ), $wymagane );
	check( array() === array_values( $brak ), 'pl ma komplet kluczy z §6' . ( empty( $brak ) ? '' : ' — brakuje: ' . implode( ', ', $brak ) ) );
	check( array() === array_values( $nadmiar ), 'pl nie ma kluczy SPOZA §6' . ( empty( $nadmiar ) ? '' : ' — nadmiar: ' . implode( ', ', $nadmiar ) ) );

	check( array_keys( $pl ) === array_keys( $en ), 'en ma DOKLADNIE ten sam zestaw kluczy co pl' );
	check( array_keys( $pl ) === array_keys( $de ), 'de ma DOKLADNIE ten sam zestaw kluczy co pl' );

	$puste = array();
	foreach ( array( 'pl' => $pl, 'en' => $en, 'de' => $de ) as $lang => $set ) {
		foreach ( $set as $k => $v ) {
			if ( ! is_string( $v ) || '' === trim( $v ) ) { $puste[] = "$lang.$k"; }
		}
	}
	check( empty( $puste ), 'zaden string nie jest pusty' . ( empty( $puste ) ? '' : ' — puste: ' . implode( ', ', $puste ) ) );

	check( mb_call( 'strings', array( 'xx' ) ) === $pl, 'nieznany jezyk → fallback na pl' );
	check( isset( $pl['mbDescFrame'] ) && strlen( (string) $pl['mbDescFrame'] ) > 40, 'mbDescFrame to pelna instrukcja dla modelu (nie zaslepka)' );
	check( isset( $pl['mbTrimmed'] ) && false !== strpos( (string) $pl['mbTrimmed'], '%s' ), 'mbTrimmed ma placeholder %s' );
	check( isset( $pl['mbDoneFmt'] ) && false !== strpos( (string) $pl['mbDoneFmt'], '%s' ), 'mbDoneFmt ma placeholder %s' );
	check( isset( $pl['mbCountFmt'] ) && false !== strpos( (string) $pl['mbCountFmt'], '%s' ), 'mbCountFmt ma placeholder %s' );
} else {
	check( false, 'sekcja C pominieta — brak PostMetaBox::strings()' );
}

// ===========================================================================
echo "\n=== D. register_box() — typy wpisow i cap (KONTRAKT §2b, R5) ===\n";
if ( $has_mb && method_exists( 'AIFAQ\\Admin\\PostMetaBox', 'register_box' ) ) {
	$box = new PostMetaBox();

	$GLOBALS['__aifaq_can'] = true;
	mb_reset_boxes();
	$box->register_box( 'post' );
	$boxes = $GLOBALS['__aifaq_boxes'];
	check( 1 === count( $boxes ), 'post → DOKLADNIE 1 add_meta_box (jest: ' . count( $boxes ) . ')' );
	check( isset( $boxes[0]['id'] ) && mb_const( 'BOX_ID' ) === $boxes[0]['id'], 'post → metabox o id BOX_ID' );
	check( isset( $boxes[0]['context'] ) && 'normal' === $boxes[0]['context'], 'post → kontekst normal' );
	check( isset( $boxes[0]['screen'] ) && 'post' === $boxes[0]['screen'], 'post → ekran post' );
	check( isset( $boxes[0]['callback'] ) && is_array( $boxes[0]['callback'] ) && 'render' === ( $boxes[0]['callback'][1] ?? '' ), 'post → callback wskazuje na render()' );

	mb_reset_boxes();
	$box->register_box( 'page' );
	check( 1 === count( $GLOBALS['__aifaq_boxes'] ), 'page → DOKLADNIE 1 add_meta_box' );

	mb_reset_boxes();
	$box->register_box( 'attachment' );
	check( 0 === count( $GLOBALS['__aifaq_boxes'] ), 'attachment → 0 add_meta_box' );

	mb_reset_boxes();
	$box->register_box( 'product' );
	check( 0 === count( $GLOBALS['__aifaq_boxes'] ), 'product (typ spoza POST_TYPES) → 0 add_meta_box' );

	$GLOBALS['__aifaq_can'] = false;
	mb_reset_boxes();
	$box->register_box( 'post' );
	check( 0 === count( $GLOBALS['__aifaq_boxes'] ), 'gosc (zero capow) → 0 add_meta_box (R5)' );
	$GLOBALS['__aifaq_can'] = true;

	// --- K20 (§5.1): cap metaboksa POLUZOWANY z `manage_options` do capa narzedzia. ---
	// Kontrola samej atrapy PRZED czymkolwiek innym — inaczej cala reszta sekcji stoi
	// na niesprawdzonym narzedziu (wzorzec z krok20-capy-test.php).
	$mb_caps_backup = $GLOBALS['__aifaq_caps'];
	$GLOBALS['__aifaq_caps'] = array( 'edit_posts', 'read' );
	check( false === current_user_can( 'manage_options' ), 'atrapa cap-swiadoma: brak manage_options na liscie → false' );
	check( true === current_user_can( 'edit_posts' ), 'atrapa cap-swiadoma: edit_posts na liscie → true' );
	check( false === current_user_can( 'publish_posts' ), 'atrapa cap-swiadoma: publish_posts poza lista → false' );

	// Wspolpracownik: ma `edit_posts`, NIE ma `publish_posts`. Metabox ma go ODBIC.
	// To jest asercja, ktora wykrywa podmiane capa narzedzia na luzniejszy `edit_posts`.
	mb_reset_boxes();
	$box->register_box( 'post' );
	check( 0 === count( $GLOBALS['__aifaq_boxes'] ), 'Wspolpracownik (edit_posts bez publish_posts) → 0 add_meta_box' );

	// Redaktor: ma `publish_posts`, nie ma `manage_options`. Metabox ma go WPUSCIC (K20).
	$GLOBALS['__aifaq_caps'] = array( 'publish_posts', 'edit_posts', 'read' );
	mb_reset_boxes();
	$box->register_box( 'post' );
	check( 1 === count( $GLOBALS['__aifaq_boxes'] ), 'Redaktor (publish_posts bez manage_options) → 1 add_meta_box (K20 §5.1)' );

	$GLOBALS['__aifaq_caps'] = $mb_caps_backup;

	// Zrodlem capa metaboksa NIE jest juz `Menu::CAPABILITY` (K16), tylko jedna metoda
	// wspolna z trasa REST — inaczej UI i trasa moglyby sie rozjechac (FZ19).
	check( class_exists( 'AIFAQ\\Rest\\RestController' ) && method_exists( 'AIFAQ\\Rest\\RestController', 'tool_capability' ), 'zrodlem capa metaboksa jest RestController::tool_capability()' );
	check( 'publish_posts' === RestController::tool_capability(), 'tool_capability() = publish_posts (domyslnie, bez filtra)' );
	check( 0 === substr_count( (string) file_get_contents( dirname( __DIR__ ) . '/src/Admin/PostMetaBox.php' ), 'Menu::CAPABILITY' ), 'PostMetaBox NIE czyta juz Menu::CAPABILITY (0 trafien)' );
} else {
	check( false, 'sekcja D pominieta — brak PostMetaBox::register_box()' );
}

// ===========================================================================
echo "\n=== E. enqueue() — bramka + 2 style, 1 skrypt, 1 localize (KONTRAKT §2b) ===\n";
if ( $has_mb && method_exists( 'AIFAQ\\Admin\\PostMetaBox', 'enqueue' ) ) {
	$box = new PostMetaBox();
	$GLOBALS['__aifaq_can'] = true;

	foreach ( array( 'index.php', 'edit.php', 'options-general.php' ) as $hook ) {
		mb_reset_assets();
		$GLOBALS['__aifaq_screen'] = new AifaqTestScreen( 'post', false );
		$box->enqueue( $hook );
		check( 0 === count( $GLOBALS['__aifaq_styles'] ), "hook {$hook} → 0 stylow" );
		check( 0 === count( $GLOBALS['__aifaq_scripts'] ), "hook {$hook} → 0 skryptow" );
	}

	// post.php + ekran wpisu + cap = pelne ladowanie.
	mb_reset_assets();
	$GLOBALS['__aifaq_screen'] = new AifaqTestScreen( 'post', false );
	$box->enqueue( 'post.php' );
	$styles = $GLOBALS['__aifaq_styles'];
	$scripts = $GLOBALS['__aifaq_scripts'];
	$loc     = $GLOBALS['__aifaq_localized'];

	check( 2 === count( $styles ), 'post.php + post → DOKLADNIE 2 style (jest: ' . count( $styles ) . ')' );
	check( 1 === count( $scripts ), 'post.php + post → DOKLADNIE 1 skrypt (jest: ' . count( $scripts ) . ')' );
	check( 1 === count( $loc ), 'post.php + post → DOKLADNIE 1 wp_localize_script (jest: ' . count( $loc ) . ')' );

	$sh = mb_handles( $styles );
	check( in_array( 'aifaq-generator', $sh, true ), 'style: handle aifaq-generator' );
	check( in_array( 'aifaq-faq-metabox', $sh, true ), 'style: handle aifaq-faq-metabox' );
	check( isset( $styles[0]['handle'] ) && 'aifaq-generator' === $styles[0]['handle'], 'kolejnosc: aifaq-generator ladowany PRZED aifaq-faq-metabox' );
	check( isset( $styles[1]['deps'] ) && in_array( 'aifaq-generator', (array) $styles[1]['deps'], true ), 'aifaq-faq-metabox zalezy od aifaq-generator' );
	check( isset( $styles[1]['src'] ) && false !== strpos( (string) $styles[1]['src'], 'assets/css/faq-metabox.css' ), 'style wskazuje assets/css/faq-metabox.css' );
	check( isset( $styles[1]['src'] ) && 0 === strpos( (string) $styles[1]['src'], AIFAQ_PLUGIN_URL ), 'baza URL stylu to AIFAQ_PLUGIN_URL' );
	check( isset( $styles[1]['ver'] ) && AIFAQ_VERSION === $styles[1]['ver'], 'style wersjonowane AIFAQ_VERSION' );

	check( isset( $scripts[0]['handle'] ) && 'aifaq-faq-metabox' === $scripts[0]['handle'], 'skrypt: handle aifaq-faq-metabox' );
	check( isset( $scripts[0]['src'] ) && false !== strpos( (string) $scripts[0]['src'], 'assets/js/faq-metabox.js' ), 'skrypt wskazuje assets/js/faq-metabox.js' );
	check( isset( $scripts[0]['src'] ) && 0 === strpos( (string) $scripts[0]['src'], AIFAQ_PLUGIN_URL ), 'baza URL skryptu to AIFAQ_PLUGIN_URL' );
	check( isset( $scripts[0]['footer'] ) && true === $scripts[0]['footer'], 'skrypt ladowany w STOPCE ($in_footer === true)' );
	check( isset( $scripts[0]['ver'] ) && AIFAQ_VERSION === $scripts[0]['ver'], 'skrypt wersjonowany AIFAQ_VERSION' );
	check( isset( $scripts[0]['deps'] ) && array() === (array) $scripts[0]['deps'], 'edytor klasyczny → PUSTE deps skryptu' );

	check( isset( $loc[0]['object'] ) && 'aifaqMetabox' === $loc[0]['object'], 'localize: globalna nazwa aifaqMetabox' );
	check( isset( $loc[0]['handle'] ) && 'aifaq-faq-metabox' === $loc[0]['handle'], 'localize: podpiete pod handle aifaq-faq-metabox' );
	check( isset( $loc[0]['data'] ) && is_array( $loc[0]['data'] ) && isset( $loc[0]['data']['i18n'], $loc[0]['data']['endpoint'] ), 'localize: dane to config() (endpoint + i18n)' );

	// post-new.php + strona.
	mb_reset_assets();
	$GLOBALS['__aifaq_screen'] = new AifaqTestScreen( 'page', false );
	$box->enqueue( 'post-new.php' );
	check( 2 === count( $GLOBALS['__aifaq_styles'] ), 'post-new.php + page → 2 style' );
	check( 1 === count( $GLOBALS['__aifaq_scripts'] ), 'post-new.php + page → 1 skrypt' );

	// Ekran spoza POST_TYPES.
	mb_reset_assets();
	$GLOBALS['__aifaq_screen'] = new AifaqTestScreen( 'attachment', false );
	$box->enqueue( 'post.php' );
	check( 0 === count( $GLOBALS['__aifaq_styles'] ), 'post.php + attachment → 0 stylow' );
	check( 0 === count( $GLOBALS['__aifaq_scripts'] ), 'post.php + attachment → 0 skryptow' );

	// Brak ekranu.
	mb_reset_assets();
	$GLOBALS['__aifaq_screen'] = null;
	$box->enqueue( 'post.php' );
	check( 0 === count( $GLOBALS['__aifaq_styles'] ), 'brak ekranu → 0 stylow' );
	check( 0 === count( $GLOBALS['__aifaq_scripts'] ), 'brak ekranu → 0 skryptow' );

	// Brak capa.
	mb_reset_assets();
	$GLOBALS['__aifaq_can']    = false;
	$GLOBALS['__aifaq_screen'] = new AifaqTestScreen( 'post', false );
	$box->enqueue( 'post.php' );
	check( 0 === count( $GLOBALS['__aifaq_styles'] ), 'brak manage_options → 0 stylow (R5)' );
	check( 0 === count( $GLOBALS['__aifaq_scripts'] ), 'brak manage_options → 0 skryptow (R5)' );
	$GLOBALS['__aifaq_can'] = true;

	// Edytor blokowy → zaleznosci pakietow.
	mb_reset_assets();
	$GLOBALS['__aifaq_screen'] = new AifaqTestScreen( 'post', true );
	$box->enqueue( 'post.php' );
	$deps = isset( $GLOBALS['__aifaq_scripts'][0]['deps'] ) ? (array) $GLOBALS['__aifaq_scripts'][0]['deps'] : array();
	check( in_array( 'wp-blocks', $deps, true ), 'blokowy → deps zawiera wp-blocks' );
	check( in_array( 'wp-block-editor', $deps, true ), 'blokowy → deps zawiera wp-block-editor (store core/block-editor)' );
	check( in_array( 'wp-data', $deps, true ), 'blokowy → deps zawiera wp-data' );

	$GLOBALS['__aifaq_screen'] = null;
} else {
	check( false, 'sekcja E pominieta — brak PostMetaBox::enqueue()' );
}

// ===========================================================================
echo "\n=== F. Widok src/Admin/views/faq-metabox.php (KONTRAKT §3) ===\n";
$view = mb_read( 'src/Admin/views/faq-metabox.php' );
check( is_string( $view ) && strlen( $view ) > 200, 'widok istnieje i nie jest zaslepka' ); // KOTWICA ROZMIARU

if ( strlen( $view ) > 200 ) {
	$ids = array(
		'aifaq-mb-count', 'aifaq-mb-generate', 'aifaq-mb-status', 'aifaq-mb-results',
		'aifaq-mb-summary', 'aifaq-mb-note', 'aifaq-mb-list', 'aifaq-mb-insert', 'aifaq-mb-copy',
	);
	$brak_id = array();
	foreach ( $ids as $id ) {
		if ( false === strpos( $view, 'id="' . $id . '"' ) ) { $brak_id[] = $id; }
	}
	check( empty( $brak_id ), 'widok ma komplet 9 ID z §3' . ( empty( $brak_id ) ? '' : ' — brakuje: ' . implode( ', ', $brak_id ) ) );

	check( 3 === substr_count( $view, '<button' ), 'widok ma DOKLADNIE 3 przyciski (jest: ' . substr_count( $view, '<button' ) . ')' );
	check(
		substr_count( $view, '<button' ) === substr_count( $view, 'type="button"' ),
		'KAZDY <button> ma jawne type="button" (klasyczny edytor: brak type = zapis wpisu)'
	);
	check( false === strpos( $view, 'name="' ), 'ZERO atrybutow name= (Gutenberg serializuje caly form metaboksow)' );

	$res_tag = array();
	$note_tag = array();
	preg_match( '/<[a-z]+\b[^>]*id="aifaq-mb-results"[^>]*>/i', $view, $res_tag );
	preg_match( '/<[a-z]+\b[^>]*id="aifaq-mb-note"[^>]*>/i', $view, $note_tag );
	check( isset( $res_tag[0] ) && false !== strpos( $res_tag[0], 'hidden' ), '#aifaq-mb-results startuje z atrybutem hidden' );
	check( isset( $note_tag[0] ) && false !== strpos( $note_tag[0], 'hidden' ), '#aifaq-mb-note startuje z atrybutem hidden' );

	check( false === strpos( $view, 'min="5"' ), 'brak literalu min="5" (idzie ze stalej MIN_COUNT)' );
	check( false === strpos( $view, 'max="20"' ), 'brak literalu max="20" (idzie ze stalej MAX_COUNT)' );
	check( false === strpos( $view, 'value="10"' ), 'brak literalu value="10" (idzie ze stalej DEFAULT_COUNT)' );

	// Zagniezdzenie .aifaq-mb > .aifaq sprawdzamy przez KOLEJNOSC wystapien, nie przez sztywny
	// regex na formatowaniu — kontrakt zamraza strukture (§3b.5), nie wciecia i lamania linii.
	$p_mb    = strpos( $view, 'class="aifaq-mb"' );
	$p_aifaq = strpos( $view, 'class="aifaq"' );
	check(
		false !== $p_mb && false !== $p_aifaq && $p_mb < $p_aifaq,
		'struktura .aifaq-mb > .aifaq (przypiecie jasnego motywu, §3b.5)'
	);
	check( false !== strpos( $view, 'type="number"' ), 'pole liczby pytan to input type="number"' );
	check( false !== strpos( $view, 'aria-live="polite"' ), '#aifaq-mb-status ma aria-live="polite"' );
	check( false === strpos( $view, 'innerHTML' ), 'widok nie zawiera JS-owego innerHTML' );
} else {
	check( false, 'sekcja F pominieta — brak widoku faq-metabox.php' );
}

// ===========================================================================
echo "\n=== G. JS assets/js/faq-metabox.js (KONTRAKT §4) ===\n";
$mb = mb_read( 'assets/js/faq-metabox.js' );
check( is_string( $mb ) && strlen( $mb ) > 200, 'faq-metabox.js istnieje i nie jest zaslepka' ); // KOTWICA ROZMIARU

if ( strlen( $mb ) > 200 ) {
	// Asercje NEGATYWNE liczymy na kodzie PO wycieciu komentarzy: repo dokumentuje trasy
	// w komentarzach, a ETAP-2 nakazuje komentarz anti-XSS ze slowem innerHTML.
	$code = preg_replace( '#/\*.*?\*/#s', '', $mb );
	$code = preg_replace( '#(^|[\s;{}()])//[^\n]*#', '$1', $code );

	check( false === strpos( $code, 'innerHTML' ), 'ZERO innerHTML w kodzie (anti-XSS, §8)' );
	check( false === strpos( $code, 'insertAdjacentHTML' ), 'ZERO insertAdjacentHTML' );
	check( false === strpos( $code, 'outerHTML' ), 'ZERO outerHTML' );
	check( false === strpos( $code, 'document.write' ), 'ZERO document.write' );
	check( false === strpos( $code, 'editPost(' ), 'brak editPost( — wstawka idzie przez insertBlocks (R3)' );

	check( false === strpos( $code, '=>' ), 'czysty ES5: zero arrow functions' );
	check( false === strpos( $code, '`' ), 'czysty ES5: zero template literals' );
	check( 0 === preg_match( '/\bconst\b/', $code ), 'czysty ES5: zero const' );
	check( 0 === preg_match( '/\blet\b/', $code ), 'czysty ES5: zero let' );
	check( false === strpos( $code, 'Promise.finally' ), 'zero Promise.finally (§4a)' );

	check( false === strpos( $code, 'aifaq/v1' ), 'brak zaszytego namespace aifaq/v1 (URL idzie z configu)' );
	check( false === strpos( $code, '/admin/generate-faq' ), 'brak zaszytej sciezki /admin/generate-faq' );
	check( false === strpos( $code, '/admin/export' ), 'brak zaszytej sciezki /admin/export' );

	check( 0 === preg_match( "/setAttribute\(\s*['\"]name['\"]/", $code ), "zero setAttribute( 'name' (§3b.2)" );
	check( 0 === preg_match( '/\.name\s*=[^=]/', $code ), 'zero przypisania .name = (§3b.2)' );
	check( false === strpos( $code, 'wp.domReady' ), 'zero wp.domReady (w klasycznym edytorze wp nie istnieje, §4a)' );

	// Obecnosc — na PELNYM pliku.
	foreach ( array( 'cfg.endpoint', 'cfg.exportEndpoint', 'cfg.maxContentChars', 'insertBlocks', 'getBlockCount', 'mbDescFrame' ) as $needle ) {
		check( false !== strpos( $mb, $needle ), "JS uzywa {$needle}" );
	}
	check( false !== strpos( $mb, 'X-WP-Nonce' ), 'JS wysyla naglowek X-WP-Nonce' );
	check( false !== strpos( $mb, 'same-origin' ), "JS uzywa credentials: 'same-origin'" );
	check( false !== strpos( $mb, 'aifaq-mb-generate' ), 'JS ma bramke startowa na #aifaq-mb-generate' );
	check( false !== strpos( $mb, 'textContent' ), 'JS pisze tresc przez textContent' );
	check( false !== strpos( $mb, 'wpApiSettings' ), 'nonce czytany z wpApiSettings z fallbackiem na cfg.nonce (§4b)' );
	check( false !== strpos( $mb, 'DOMParser' ), 'cleanContent parsuje tresc przez DOMParser (§4c)' );
	check( false !== strpos( $mb, 'getEditedPostContent' ), 'tresc z edytora blokowego przez getEditedPostContent() (R2)' );

	// Kazdy <button> tworzony w JS dostaje type = 'button'.
	$made    = substr_count( $code, "createElement( 'button'" );
	$typed   = substr_count( $code, ".type = 'button'" );
	check( $made <= $typed, "kazdy createElement( 'button' ) ma .type = 'button' (utworzone: {$made}, otypowane: {$typed})" );
	check( $made >= 1, "JS tworzy przycisk Usun przez createElement( 'button' )" );
} else {
	check( false, 'sekcja G pominieta — brak pliku faq-metabox.js' );
}

// ===========================================================================
echo "\n=== H. CSS assets/css/faq-metabox.css (KONTRAKT §5) ===\n";
$css = mb_read( 'assets/css/faq-metabox.css' );
check( is_string( $css ) && strlen( $css ) > 200, 'CSS istnieje i nie jest zaslepka' ); // KOTWICA ROZMIARU

if ( strlen( $css ) > 200 ) {
	check( 1 === preg_match( '/\.aifaq-mb\s+\.aifaq\s*\{[^}]*--aifaq-bg/s', $css ), 'przypiecie jasnego motywu: .aifaq-mb .aifaq { --aifaq-bg… }' );
	check( false === strpos( $css, 'aifaq-wrap' ), 'zakaz .aifaq-wrap (metabox nie jest ekranem wtyczki)' );
	check( 0 === preg_match( '/@media[^{]*(?:max-width|min-width)/i', $css ), 'brak @media po szerokosci okna (metabox zyje w kontenerze)' );
	check( false !== strpos( $css, '.aifaq-mb__results[hidden]' ), 'jawny reset .aifaq-mb__results[hidden] { display:none }' );
	check( false !== strpos( $css, '.aifaq-mb__note[hidden]' ), 'jawny reset .aifaq-mb__note[hidden]' );
	check( 0 === preg_match( '/^\s*\.is-/m', $css ), 'brak golych klas stanu .is-* (koliduja z rdzeniem wp-admin)' );
	check( 1 === preg_match( '/\.aifaq-mb__status\.is-(loading|error|ok)/', $css ), 'klasy stanu wylacznie zlozone: .aifaq-mb__status.is-*' );
	check( false === strpos( $css, '#poststuff' ), 'nie stylizujemy #poststuff' );
	check( false === strpos( $css, '.postbox' ), 'nie stylizujemy .postbox' );

	$brak_sel = array();
	foreach ( array( '.aifaq-mb__lead', '.aifaq-mb__bar', '.aifaq-mb__count', '.aifaq-mb__list', '.aifaq-mb__pair', '.aifaq-mb__q', '.aifaq-mb__a', '.aifaq-mb__del', '.aifaq-mb__acts', '.aifaq-mb__hint' ) as $sel ) {
		if ( false === strpos( $css, $sel ) ) { $brak_sel[] = $sel; }
	}
	check( empty( $brak_sel ), 'CSS stylizuje komplet selektorow z §5' . ( empty( $brak_sel ) ? '' : ' — brakuje: ' . implode( ', ', $brak_sel ) ) );
} else {
	check( false, 'sekcja H pominieta — brak pliku faq-metabox.css' );
}

// ===========================================================================
echo "\n=== I. Rejestracja w src/Core/Plugin.php (KONTRAKT §2d) ===\n";
$plugin = mb_read( 'src/Core/Plugin.php' );
check( is_string( $plugin ) && strlen( $plugin ) > 200, 'Plugin.php istnieje i nie jest zaslepka' ); // KOTWICA ROZMIARU

if ( strlen( $plugin ) > 200 ) {
	// preg_match, NIE strpos: gole `admin_enqueue_scripts` jest juz w pliku (hook Menu),
	// wiec naiwna asercja przeszlaby na NIEZMIENIONYM pliku (falszywa zielonosc).
	check( 1 === preg_match( '/use\s+AIFAQ\\\\Admin\\\\PostMetaBox\s*;/', $plugin ), 'Plugin.php importuje AIFAQ\Admin\PostMetaBox' );
	check( 1 === preg_match( '/private\s+\?PostMetaBox\s+\$post_metabox/', $plugin ), 'Plugin.php ma pole private ?PostMetaBox $post_metabox' );
	check(
		1 === preg_match( '/add_action\(\s*[\'"]add_meta_boxes[\'"]\s*,\s*array\(\s*\$this->post_metabox\s*,\s*[\'"]register_box[\'"]/', $plugin ),
		'Plugin.php podpina register_box() pod add_meta_boxes'
	);
	check(
		1 === preg_match( '/add_action\(\s*[\'"]admin_enqueue_scripts[\'"]\s*,\s*array\(\s*\$this->post_metabox\s*,\s*[\'"]enqueue[\'"]/', $plugin ),
		'Plugin.php podpina enqueue() pod admin_enqueue_scripts (wzorzec z $this->post_metabox, nie gole szukanie hooka)'
	);
} else {
	check( false, 'sekcja I pominieta — brak src/Core/Plugin.php' );
}

// ===========================================================================
echo "\n=== PODSUMOWANIE ===\n";
echo ( 0 === $fail ) ? "TEST KROK 16 (metabox): WSZYSTKIE ASERCJE OK\n" : "TEST KROK 16 (metabox): $fail ASERCJI NIE PRZESZLO\n";
exit( 0 === $fail ? 0 : 1 );
