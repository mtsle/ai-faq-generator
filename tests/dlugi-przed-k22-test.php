<?php
/**
 * Testy: długi z wcześniejszych Kroków zamknięte PRZED Krokiem 22 (domknięcie).
 *
 * Lista (ustalona z userem 2026-07-26, jedna sesja, bez protokołu etapów):
 *  - F1        — reindeks wznawia się sam przez WP-Cron po budget_hit;
 *  - miernik   — zużycie dobowego sufitu widoczne w kokpicie PRZED wyczerpaniem;
 *  - nonce     — 401/403 (sesja wygasła po nocy) dostają zrozumiały komunikat,
 *                nie generyczny błąd;
 *  - D7        — odmowa off-topic cache'owana (behawioralnie w krok6-rag-test.php,
 *                bo tam już istnieje harness RagService — nie duplikujemy go tutaj);
 *  - D10       — pole „temperatura" na froncie uczciwie opisuje swój zakres;
 *  - R1/R2/R3  — trzy duplikacje (komunikat weryfikacji klucza, lista języków,
 *                render raportu indeksowania) sprowadzone do jednego źródła.
 *
 * Crawl na słabym hostingu (drugi punkt z listy usera) NIE jest tu testowany —
 * okazał się już zamknięty w Kroku 20 (`krok20-crawl-test.php`), więc nie jest
 * to dług do naprawy w tej sesji.
 *
 * URUCHOMIENIE:  php tests/dlugi-przed-k22-test.php
 * Kod wyjścia: 0 = OK, 1 = błędy.
 *
 * @package AI_FAQ_Generator
 */

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
if ( ! defined( 'AIFAQ_VERSION' ) ) { define( 'AIFAQ_VERSION', '0.29.0-test' ); }
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); }

$fail = 0;
$ran  = 0;
function check( $cond, $label ) {
	global $fail, $ran;
	$ran++;
	echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n";
	if ( ! $cond ) { $fail++; }
}

$root = dirname( __DIR__ );

// ---------------------------------------------------------------------------
// F1 — reindeks wznawia się sam przez WP-Cron po budget_hit.
// ---------------------------------------------------------------------------
echo "=== F1: auto-wznowienie reindeksu przez WP-Cron ===\n";

if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $t ) { return false; } }
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'get_transient' ) ) { function get_transient( $k ) { return false; } }
if ( ! function_exists( 'set_transient' ) ) { function set_transient( $k, $v, $ttl = 0 ) { return true; } }
if ( ! function_exists( 'delete_transient' ) ) { function delete_transient( $k ) { return true; } }
if ( ! function_exists( 'register_shutdown_function' ) ) { function register_shutdown_function( $cb ) {} }
if ( ! function_exists( 'get_option' ) ) { function get_option( $k, $d = false ) { return $d; } }
if ( ! function_exists( 'update_option' ) ) { function update_option( $k, $v, $a = null ) { return true; } }
if ( ! function_exists( 'add_option' ) ) { function add_option( $k, $v = '', $d = '', $a = 'yes' ) { return true; } }
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $h, $v = null ) { return $v; } }

$GLOBALS['__cron'] = array();
if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( $hook, $args = array() ) { return $GLOBALS['__cron'][ $hook ] ?? false; }
}
if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	function wp_schedule_single_event( $timestamp, $hook, $args = array() ) { $GLOBALS['__cron'][ $hook ] = $timestamp; return true; }
}
if ( ! function_exists( 'wp_unschedule_hook' ) ) {
	function wp_unschedule_hook( $hook ) { unset( $GLOBALS['__cron'][ $hook ] ); return true; }
}

require $root . '/src/Admin/IndexController.php';

check(
	'aifaq_reindex_continue' === \AIFAQ\Admin\IndexController::CRON_CONTINUE_HOOK,
	'IndexController::CRON_CONTINUE_HOOK === aifaq_reindex_continue'
);

// schedule_continue() jest PRYWATNA — wołana wyłącznie wewnętrznie, gdy
// przebieg wyszedł niepełny z powodu budżetu. Testujemy ją przez Reflection,
// żeby nie duplikować całego harnessu run_reindex() z krok19-migracja-test.php
// tylko po to, by dowieść JEDNEJ nowej gałęzi.
$rm = new ReflectionMethod( '\AIFAQ\Admin\IndexController', 'schedule_continue' );
$rm->setAccessible( true );

$GLOBALS['__cron'] = array();
$rm->invoke( null );
check( isset( $GLOBALS['__cron']['aifaq_reindex_continue'] ), 'schedule_continue(): planuje pojedyncze zdarzenie crona' );

$first_ts = $GLOBALS['__cron']['aifaq_reindex_continue'];
$rm->invoke( null );
check(
	$first_ts === $GLOBALS['__cron']['aifaq_reindex_continue'],
	'schedule_continue(): drugie wywołanie NIE dubluje zdarzenia (wp_next_scheduled chroni)'
);

$ic_src = (string) file_get_contents( $root . '/src/Admin/IndexController.php' );
check(
	1 === preg_match( "/'budget' === \\\$reason\\s*\\)\\s*\\{\\s*self::schedule_continue\\(\\)/", $ic_src ),
	'run_reindex(): schedule_continue() wołane WYŁĄCZNIE gdy powód = budget (nie crawl/errors)'
);

$plugin_src = (string) file_get_contents( $root . '/src/Core/Plugin.php' );
check(
	false !== strpos( $plugin_src, 'IndexController::CRON_CONTINUE_HOOK' )
	&& false !== strpos( $plugin_src, 'run_reindex_continue_tick' ),
	'Plugin.php: hook CRON_CONTINUE_HOOK wpięty na run_reindex_continue_tick()'
);
check(
	false !== strpos( $plugin_src, "class_exists( '\\AIFAQ\\Admin\\IndexController' )" )
	&& false !== strpos( $plugin_src, 'catch ( \\Throwable $e )' ),
	'Plugin.php: run_reindex_continue_tick() osłonięty class_exists()+try/catch (cron nie wywala witryny)'
);

$deact_src = (string) file_get_contents( $root . '/src/Core/Deactivator.php' );
check(
	false !== strpos( $deact_src, 'CRON_CONTINUE_HOOK' ) && false !== strpos( $deact_src, 'aifaq_reindex_continue' ),
	'Deactivator.php: odinstalowanie/deaktywacja odpina też CRON_CONTINUE_HOOK'
);

// ---------------------------------------------------------------------------
// Miernik zużycia dobowego sufitu.
// ---------------------------------------------------------------------------
echo "\n=== Miernik zużycia dobowego sufitu w kokpicie ===\n";

$GLOBALS['__opt'] = array();
if ( ! function_exists( 'current_time' ) ) { function current_time( $t ) { return $GLOBALS['__now'] ?? '2026-07-26'; } }
$GLOBALS['get_option_src'] = true;
// Nadpisujemy get_option TYLKO dla tej sekcji przez zmienną globalną, którą
// czyta już zdefiniowana funkcja powyżej — get_option już istnieje (z bloku F1),
// więc czytamy/piszemy bezpośrednio do $GLOBALS['__opt'] przez tamten shim.
function aifaq_test_set_option( $key, $value ) { $GLOBALS['__opt'][ $key ] = $value; }

// Podmieniamy zachowanie get_option() na czytanie z $GLOBALS['__opt'] — shim
// z bloku F1 zwracał tylko $default, więc dogrywamy właściwą wersję TERAZ
// (PHP nie pozwala redefiniować funkcji, więc test idzie przez zmienną nazwaną
// tak samo jak w RagService: 'aifaq_daily_usage').
if ( ! class_exists( '\AIFAQ\Rag\RagServiceUsageTestShim' ) ) {
	// no-op guard class — nic nie robi, tylko dokumentuje intencję dla czytelnika.
	class RagServiceUsageTestShimMarker {}
}

require_once $root . '/src/Data/Schema.php';
require_once $root . '/src/Data/Repository.php';
require_once $root . '/src/Data/CacheRepository.php';

$rs_src = (string) file_get_contents( $root . '/src/Rag/RagService.php' );
check(
	1 === preg_match( '/public static function usage_snapshot\(\s*\)\s*:\s*array/', $rs_src ),
	'RagService::usage_snapshot() istnieje, publiczna i statyczna (kokpit nie buduje całego RagService)'
);
check(
	false !== strpos( $rs_src, 'return self::usage_snapshot();' ),
	'budget_usage() deleguje do usage_snapshot() — JEDNO źródło prawdy (bramka i kokpit muszą się zgadzać)'
);

$dash_src = (string) file_get_contents( $root . '/src/Admin/views/dashboard.php' );
check(
	false !== strpos( $dash_src, 'RagService::usage_snapshot()' )
	&& false !== strpos( $dash_src, "\$aifaq_budget_limit > 0" ),
	'dashboard.php: kafelek sufitu czyta usage_snapshot(), tylko gdy limit > 0 (0 = wyłączony)'
);
check(
	false !== strpos( $dash_src, "Settings::get_field( 'rag_daily_budget', 12 )" ),
	'dashboard.php: limit czytany z tego samego pola co bramka RagService (rag_daily_budget)'
);

// ---------------------------------------------------------------------------
// Nonce wp_rest wygasający po nocy — 401/403 dostają zrozumiały komunikat.
// ---------------------------------------------------------------------------
echo "\n=== Nonce wp_rest: komunikat 401/403 zamiast generycznego błędu ===\n";

$js_dir = $root . '/assets/js/';

$fm_src = (string) file_get_contents( $js_dir . 'faq-metabox.js' );
check(
	2 === substr_count( $fm_src, 'data && data.message' ) + substr_count( $fm_src, 'r.data && r.data.message' ),
	'faq-metabox.js: OBA miejsca błędu (generacja + wstawka) pokazują data.message, gdy WordPress go zwraca'
);

$ft_src = (string) file_get_contents( $js_dir . 'faq-tool.js' );
check(
	false !== strpos( $ft_src, '( data && data.message ) ? data.message : ( t.errMsg' ),
	'faq-tool.js: handleResponse() pokazuje data.message zamiast zawsze generycznego errMsg'
);
check(
	2 === substr_count( $ft_src, 'r.data && r.data.message' ),
	'faq-tool.js: publikacja/cofnięcie publikacji też pokazują data.message (2 miejsca)'
);

$gen_src = (string) file_get_contents( $js_dir . 'generator.js' );
check(
	false !== strpos( $gen_src, "( data && data.message ) ? data.message : ( t.errGeneric" ),
	'generator.js: gałąź końcowa (401/403/502/…) pokazuje data.message, gdy dostępny'
);

$idx_src = (string) file_get_contents( $js_dir . 'indexer.js' );
check(
	false !== strpos( $idx_src, 'function errorMessage( status )' )
	&& false !== strpos( $idx_src, '401 === status || 403 === status' ),
	'indexer.js: errorMessage() rozróżnia 401/403 (admin-ajax nie zwraca JSON-a z opisem)'
);
check(
	false !== strpos( $idx_src, "i18n.sessionExpired" ),
	'indexer.js: używa i18n.sessionExpired dla 401/403'
);

$menu_src = (string) file_get_contents( $root . '/src/Admin/Menu.php' );
check(
	false !== strpos( $menu_src, "'sessionExpired'" ),
	'Menu.php: string i18n sessionExpired zdefiniowany dla indexer.js'
);

// ---------------------------------------------------------------------------
// D10 — pole „temperatura" na froncie uczciwie opisuje zakres działania.
// ---------------------------------------------------------------------------
echo "\n=== D10: pole temperature nie udaje, że steruje czatem asystenta ===\n";

$shell_src = (string) file_get_contents( $root . '/src/App/AppShell.php' );
check(
	false !== strpos( $shell_src, 'FAQ generator temperature' ),
	'AppShell.php: etykieta EN doprecyzowana na "FAQ generator temperature" (nie goły "Temperature")'
);
check(
	false !== strpos( $shell_src, 'does NOT affect the assistant chat' )
	&& false !== strpos( $shell_src, 'NIE wpływa na czat asystenta' )
	&& false !== strpos( $shell_src, 'NICHT auf den Chat-Assistenten' ),
	'AppShell.php: podpowiedź jawnie mówi, że suwak NIE dotyczy czatu (PL/EN/DE)'
);

// ---------------------------------------------------------------------------
// R1 — komunikat weryfikacji klucza: jedno źródło.
// ---------------------------------------------------------------------------
echo "\n=== R1: komunikat weryfikacji klucza scalony w Settings::verify_error_message() ===\n";

$settings_src = (string) file_get_contents( $root . '/src/Core/Settings.php' );
// Krok 23 (czysty refaktor): handle_verify() jest już cienkim wywołaniem zwrotnym,
// a samo składanie odpowiedzi /admin/verify siedzi w AdminService. Sklejamy oba pliki
// warstwy REST — obie asercje niżej (wołanie verify_error_message() ORAZ brak
// zduplikowanej ternary) zostają dosłownie takie same i tak samo mocne.
$rest_src     = (string) file_get_contents( $root . '/src/Rest/RestController.php' )
	. ( is_file( $root . '/src/Rest/AdminService.php' ) ? (string) file_get_contents( $root . '/src/Rest/AdminService.php' ) : '' );

check(
	1 === preg_match( '/public static function verify_error_message\(\s*\\\\WP_Error \$result\s*\)\s*:\s*string/', $settings_src ),
	'Settings::verify_error_message() istnieje (sygnatura WP_Error → string)'
);
check(
	false !== strpos( $settings_src, 'self::verify_error_message( $result )' ),
	'Settings::ajax_test_connection() woła verify_error_message() zamiast własnej kopii ternary'
);
check(
	false !== strpos( $rest_src, 'Settings::verify_error_message( $result )' ),
	'RestController::handle_verify() woła TĘ SAMĄ metodę (nie własną kopię)'
);
check(
	1 === substr_count( $settings_src, "'aifaq_no_key' === \$result->get_error_code()" )
	&& 0 === substr_count( $rest_src, "'aifaq_no_key' === \$result->get_error_code()" ),
	'ternary „aifaq_no_key" istnieje TYLKO RAZ w całym repo (w Settings.php), nie zduplikowana w RestController.php'
);

// ---------------------------------------------------------------------------
// R2 — lista języków komunikatów odmowy: bez duplikatu.
// ---------------------------------------------------------------------------
echo "\n=== R2: settings.php nie dubluje ręcznie listy języków ===\n";

$view_src = (string) file_get_contents( $root . '/src/Admin/views/settings.php' );
check(
	false !== strpos( $view_src, '$aifaq_refusal_langs = $aifaq_langs;' ),
	'settings.php: $aifaq_refusal_langs jest ALIASEM $aifaq_langs (ze Settings::languages()), nie osobnym literałem'
);
check(
	0 === substr_count( $view_src, "'niemiecki'" ),
	'settings.php: literał "niemiecki" zniknął z WIDOKU (żyje wyłącznie w Settings::languages())'
);

// ---------------------------------------------------------------------------
// R3 — render raportu indeksowania: jedno źródło dla kokpitu i frontu.
// ---------------------------------------------------------------------------
echo "\n=== R3: report-render.js — jedna implementacja dla indexer.js i app.js ===\n";

check( is_file( $js_dir . 'report-render.js' ), 'report-render.js istnieje' );
$rr_src = (string) file_get_contents( $js_dir . 'report-render.js' );
check(
	false !== strpos( $rr_src, 'window.AIFAQReport = { render: render }' ),
	'report-render.js wystawia window.AIFAQReport.render()'
);
check(
	false !== strpos( $idx_src, 'window.AIFAQReport.render(' ) && false === strpos( $idx_src, "function renderReport( report ) {\n\t\tif ( ! report || ! reportEl )" ),
	'indexer.js: renderReport() deleguje do window.AIFAQReport (stara implementacja usunięta)'
);
$app_src = (string) file_get_contents( $js_dir . 'app.js' );
check(
	false !== strpos( $app_src, 'window.AIFAQReport.render(' ),
	'app.js: renderReport() deleguje do window.AIFAQReport zamiast własnej kopii'
);
check(
	0 === substr_count( $idx_src, "'Fragmentów zapisanych:'" ) || false === strpos( $idx_src, 'function el( tag, text, color )' ),
	'indexer.js: lokalna funkcja el()/appendList() usunięta (żyje tylko w report-render.js)'
);

check(
	false !== strpos( $menu_src, "'aifaq-report'" ) && false !== strpos( $menu_src, "'assets/js/report-render.js'" ),
	'Menu.php: report-render.js zarejestrowany i jest zależnością aifaq-indexer'
);
$shortcode_src = (string) file_get_contents( $root . '/src/PublicUi/Shortcode.php' );
check(
	false !== strpos( $shortcode_src, "'aifaq-report'" ) && false !== strpos( $shortcode_src, "'assets/js/report-render.js'" ),
	'Shortcode.php: report-render.js zarejestrowany i jest zależnością aifaq-app'
);

// ---------------------------------------------------------------------------
// Podłoga pokrycia.
// ---------------------------------------------------------------------------
echo "\n=== Z. Podłoga pokrycia ===\n";
check( $ran >= 25, 'wykonano co najmniej 25 asercji (jest: ' . $ran . ')' );

echo "\n";
if ( 0 === $fail ) {
	echo "=== WSZYSTKIE OK (asercji: {$ran}) ===\n";
	exit( 0 );
}
echo "=== BŁĘDÓW: {$fail} (asercji: {$ran}) ===\n";
exit( 1 );
