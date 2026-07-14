<?php
/**
 * Testy: front-app AppShell — bramka rola-aware + brak wycieku sekretów (Krok 9).
 *
 * Broni najważniejszej gwarancji front-appki: GOŚĆ nie dostaje panelu.
 *  - is_owner(): gość=false; zalogowany bez manage_options=false; zalogowany+cap=true.
 *  - config(): zero sekretów (brak api_key), komplet 5 endpointów /admin/*, isOwner, nonce.
 *  - render_body(gość): dokładnie GeneratorPage::widget() — bez paska zakładek, bez data-tab,
 *    bez panelu ustawień (gość nie widzi panelu).
 *  - render_body(właściciel): pasek zakładek + 4 zakładki + panel ustawień.
 *  - settings_panel(): zapisany klucz NIE występuje w markupie (pole password value="").
 *  - strings()/lang(): nieznany język → pl; komplet kluczy per język.
 *
 * URUCHOMIENIE:  php tests/krok9-appshell-test.php
 * Kod wyjścia: 0 = OK, 1 = błędy.
 *
 * @package AI_FAQ_Generator
 */

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
if ( ! defined( 'AIFAQ_PLUGIN_URL' ) ) { define( 'AIFAQ_PLUGIN_URL', 'http://test.local/wp-content/plugins/ai-faq-generator/' ); }
if ( ! defined( 'AIFAQ_VERSION' ) ) { define( 'AIFAQ_VERSION', '0.0.0-test' ); }
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
if ( ! defined( 'OBJECT' ) ) { define( 'OBJECT', 'OBJECT' ); }

// --- shimy funkcji WP ---
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $s ) { return (string) $s; } }
if ( ! function_exists( 'esc_url_raw' ) ) { function esc_url_raw( $s ) { return (string) $s; } }
if ( ! function_exists( 'esc_textarea' ) ) { function esc_textarea( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'get_bloginfo' ) ) { function get_bloginfo( $k = '' ) { return 'charset' === $k ? 'UTF-8' : 'Test Site'; } }
if ( ! function_exists( 'home_url' ) ) { function home_url( $p = '/' ) { return 'http://test.local' . $p; } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $p = '' ) { return 'http://test.local/wp-admin/' . $p; } }
if ( ! function_exists( 'rest_url' ) ) { function rest_url( $p = '' ) { return 'http://test.local/wp-json/' . ltrim( (string) $p, '/' ); } }
if ( ! function_exists( 'wp_create_nonce' ) ) { function wp_create_nonce( $a = '' ) { return 'nonce-abc123'; } }
if ( ! function_exists( 'selected' ) ) { function selected( $a, $b = true ) { if ( (string) $a === (string) $b ) { echo ' selected="selected"'; } } }

// Flagi roli.
$GLOBALS['__logged'] = false;
$GLOBALS['__can']    = false;
if ( ! function_exists( 'is_user_logged_in' ) ) { function is_user_logged_in() { return (bool) $GLOBALS['__logged']; } }
if ( ! function_exists( 'current_user_can' ) ) { function current_user_can( $c ) { return (bool) $GLOBALS['__can']; } }

// Magazyn opcji.
$GLOBALS['__opt'] = array();
if ( ! function_exists( 'get_option' ) ) { function get_option( $k, $d = false ) { return $GLOBALS['__opt'][ $k ] ?? $d; } }
if ( ! function_exists( 'update_option' ) ) { function update_option( $k, $v ) { $GLOBALS['__opt'][ $k ] = $v; return true; } }

// $wpdb stub (dla IndexController::stats w renderze właściciela).
class FakeWpdb {
	public $prefix = 'wp_';
	public function get_row( $sql, $out = null ) { return array( 'chunks' => 7, 'posts' => 3, 'embedded' => 7 ); }
	public function prepare( $q, ...$a ) { return $q; }
	public function get_var( $q ) { return 0; }
	public function get_results( $q, $o = null ) { return array(); }
	public function query( $q ) { return 0; }
}
$GLOBALS['wpdb'] = new FakeWpdb();

// --- harness ---
$fail = 0;
function check( $cond, $label ) { global $fail; echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n"; if ( ! $cond ) { $fail++; } }

// --- ładowanie kodu ---
require __DIR__ . '/../src/Data/Schema.php';
require __DIR__ . '/../src/Data/Repository.php';
require __DIR__ . '/../src/Data/KnowledgeRepository.php';
require __DIR__ . '/../src/Data/CacheRepository.php';
require __DIR__ . '/../src/Core/Settings.php';
require __DIR__ . '/../src/Rag/RagService.php';
require __DIR__ . '/../src/Rest/RestController.php';
require __DIR__ . '/../src/Admin/Menu.php';
require __DIR__ . '/../src/Admin/IndexController.php';
require __DIR__ . '/../src/PublicUi/GeneratorPage.php';
require __DIR__ . '/../src/App/AppShell.php';

use AIFAQ\App\AppShell;
use AIFAQ\PublicUi\GeneratorPage;

function set_role( $logged, $can ) { $GLOBALS['__logged'] = $logged; $GLOBALS['__can'] = $can; }

echo "== is_owner() ==\n";
set_role( false, false );
check( false === AppShell::is_owner(), 'gość → false' );
set_role( true, false );
check( false === AppShell::is_owner(), 'zalogowany bez manage_options → false' );
set_role( true, true );
check( true === AppShell::is_owner(), 'zalogowany + manage_options → true' );

echo "\n== config() — bez sekretów, komplet endpointów ==\n";
$GLOBALS['__opt']['aifaq_settings'] = array( 'api_key' => 'SECRET-123', 'language' => 'pl' );
set_role( true, true );
$cfg  = AppShell::config();
$json = json_encode( $cfg );
check( false === strpos( $json, 'SECRET-123' ), 'ANTI-LEAK: klucz NIE w config()' );
check( false === strpos( $json, 'api_key' ), 'config() nie zawiera pola api_key' );
check( true === $cfg['isOwner'], 'isOwner = true' );
check( ! empty( $cfg['nonce'] ), 'nonce obecny' );
$eps = $cfg['endpoints'] ?? array();
check( isset( $eps['status'], $eps['reindex'], $eps['clear'], $eps['settings'], $eps['verify'] ), 'komplet 5 endpointów' );
$all_admin = true;
foreach ( $eps as $u ) { if ( false === strpos( (string) $u, 'aifaq/v1/admin/' ) ) { $all_admin = false; } }
check( $all_admin, 'każdy endpoint wskazuje aifaq/v1/admin/' );

echo "\n== render_body(GOŚĆ) — brak panelu ==\n";
set_role( false, false );
$guest = AppShell::render_body();
check( $guest === GeneratorPage::widget(), 'gość: render_body == widget()' );
check( false === strpos( $guest, 'aifaq-app__bar' ), 'gość: brak paska zakładek' );
check( false === strpos( $guest, 'data-tab' ), 'gość: brak atrybutu data-tab' );
check( false === strpos( $guest, 'aifaq-panel-settings' ), 'gość: brak panelu ustawień' );

echo "\n== render_body(WŁAŚCICIEL) — zakładki + panele ==\n";
set_role( true, true );
$owner = AppShell::render_body();
check( false !== strpos( $owner, 'aifaq-app__bar' ), 'właściciel: pasek zakładek jest' );
check( 4 === substr_count( $owner, 'aifaq-app__tab"' ) + substr_count( $owner, 'aifaq-app__tab ' ), 'właściciel: 4 zakładki' );
check( false !== strpos( $owner, 'aifaq-panel-settings' ), 'właściciel: panel ustawień jest' );
check( false !== strpos( $owner, 'aifaq-panel-index' ), 'właściciel: panel indeksowania jest' );

echo "\n== settings_panel() — klucz NIE w HTML ==\n";
$GLOBALS['__opt']['aifaq_settings'] = array( 'api_key' => 'SECRET-999', 'model' => 'gemini-2.5-flash', 'temperature' => 0.4, 'language' => 'pl' );
$ref = new ReflectionMethod( AppShell::class, 'settings_panel' );
$ref->setAccessible( true );
$panel = (string) $ref->invoke( null );
check( false === strpos( $panel, 'SECRET-999' ), 'ANTI-LEAK: zapisany klucz NIE w markupie' );
check( false !== strpos( $panel, 'type="password"' ), 'pole klucza jest typu password' );
check( false !== strpos( $panel, 'value=""' ), 'pole klucza ma puste value' );

echo "\n== strings()/lang() ==\n";
$pl = AppShell::strings( 'pl' );
$en = AppShell::strings( 'en' );
$de = AppShell::strings( 'de' );
check( AppShell::strings( 'xx' ) === $pl, 'nieznany język → pl' );
$keys = array_keys( $pl );
check( array_keys( $en ) === $keys && array_keys( $de ) === $keys, 'komplet kluczy dla pl/en/de' );
check( isset( $pl['tabGenerator'], $pl['setTitle'], $pl['idxReindex'] ), 'obecne kluczowe stringi' );

echo "\n=== " . ( 0 === $fail ? 'WSZYSTKIE OK' : "BŁĘDÓW: {$fail}" ) . " ===\n";
exit( $fail > 0 ? 1 : 0 );
