<?php
/**
 * Testy Kroku 19 — sekcja A: GeminiProvider + ProviderFactory + WpHttpClient.
 *
 * PISANE W CIEMNO (Etap 4, KONTRAKT k19-v3 §8.3 sekcja A). Autor NIE widział kodu
 * etapów E1–E3b-2. Rozbieżność test↔implementacja jest dowodem, że KONTRAKT był
 * nieprecyzyjny — idzie do `plany/krok19/ODCHYLENIA.md`, nie do cichej korekty testu.
 *
 * Pokrywa: budżet myślenia (`thinkingConfig` + clamp {-1,0} ∪ [128,24576]), kaskadę
 * po HTTP 400 (DOKŁADNIE trzy próby), `systemInstruction`, `last_meta()` (12 kluczy,
 * podział FZ1/FZ1-bis), `finishReason` (MAX_TOKENS zachowuje typ zwrotny), retry F3
 * (429/503, `retryDelay` z CIAŁA, backoff, regułę „nie warto czekać”, wyłącznik obwodu),
 * `taskType` w `embed()` oraz flavoury fabryki (FZ4: `make()` bez `taskType`).
 *
 * Podłoga pokrycia: >= 48 asercji (§8.1 pkt 7).
 *
 * URUCHOMIENIE:  php -d extension=mbstring tests/krok19-provider-test.php
 * Kod wyjścia: 0 = OK, 1 = błędy.
 *
 * @package AI_FAQ_Generator
 */

// --- Stałe PRZED pierwszym require (§8.1 pkt 2) ---
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
if ( ! defined( 'OBJECT' ) ) { define( 'OBJECT', 'OBJECT' ); }
if ( ! defined( 'AIFAQ_VERSION' ) ) { define( 'AIFAQ_VERSION', '0.22.0-test' ); }
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
if ( ! defined( 'WEEK_IN_SECONDS' ) ) { define( 'WEEK_IN_SECONDS', 604800 ); }

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code; private $message;
		public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $t ) { return $t instanceof WP_Error; } }

// --- Shimy WP (JEDEN dialekt opcji na plik: $GLOBALS['__opt'], §8.1 pkt 3) ---
$GLOBALS['__opt']              = array();
$GLOBALS['__autoload']         = array();
$GLOBALS['__aifaq_transients'] = array();
$GLOBALS['__filters']          = array();
$GLOBALS['__filters_args']     = array();
$GLOBALS['__remote']           = array( 'reply' => null, 'args' => null );

if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return $s; } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return $s; } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $s ) { return $s; } }
if ( ! function_exists( 'esc_url_raw' ) ) { function esc_url_raw( $s ) { return $s; } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $d ) { return json_encode( $d ); } }
if ( ! function_exists( 'wp_unslash' ) ) { function wp_unslash( $s ) { return is_string( $s ) ? stripslashes( $s ) : $s; } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ) { return is_string( $s ) ? trim( $s ) : ''; } }
if ( ! function_exists( 'sanitize_textarea_field' ) ) { function sanitize_textarea_field( $s ) { return trim( (string) $s ); } }
if ( ! function_exists( 'current_time' ) ) { function current_time( $t ) { return '2026-07-20 00:00:00'; } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; } }
if ( ! function_exists( 'get_bloginfo' ) ) { function get_bloginfo( $show = 'name' ) { return $GLOBALS['__blogname'] ?? 'Witryna Testowa'; } }

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = false ) {
		return array_key_exists( $key, $GLOBALS['__opt'] ) ? $GLOBALS['__opt'][ $key ] : $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $key, $value, $autoload = null ) { $GLOBALS['__opt'][ $key ] = $value; return true; }
}
if ( ! function_exists( 'add_option' ) ) {
	function add_option( $key, $value = '', $dep = '', $autoload = 'yes' ) {
		if ( array_key_exists( $key, $GLOBALS['__opt'] ) ) { return false; }
		$GLOBALS['__opt'][ $key ]      = $value;
		$GLOBALS['__autoload'][ $key ] = $autoload;
		return true;
	}
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $key ) { unset( $GLOBALS['__opt'][ $key ] ); return true; }
}
if ( ! function_exists( 'get_transient' ) ) { function get_transient( $k ) { return $GLOBALS['__aifaq_transients'][ $k ] ?? false; } }
if ( ! function_exists( 'set_transient' ) ) { function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['__aifaq_transients'][ $k ] = $v; return true; } }
if ( ! function_exists( 'delete_transient' ) ) { function delete_transient( $k ) { unset( $GLOBALS['__aifaq_transients'][ $k ] ); return true; } }

// Shim filtrów (§8.2, wiersz „filtry”) — zapisuje też argumenty.
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) {
		$args = func_get_args();
		array_shift( $args );                       // $args[0] === $value
		if ( isset( $GLOBALS['__filters'][ $tag ] ) ) {
			$GLOBALS['__filters_args'][ $tag ][] = $args;
			return call_user_func_array( $GLOBALS['__filters'][ $tag ], $args );
		}
		return $value;
	}
}

// Shim wp_remote_* (A48/A49 — WpHttpClient).
if ( ! function_exists( 'wp_remote_request' ) ) {
	function wp_remote_request( $url, $args = array() ) { $GLOBALS['__remote']['args'] = $args; return $GLOBALS['__remote']['reply']; }
}
if ( ! function_exists( 'wp_remote_post' ) ) {
	function wp_remote_post( $url, $args = array() ) { return wp_remote_request( $url, $args ); }
}
if ( ! function_exists( 'wp_remote_get' ) ) {
	function wp_remote_get( $url, $args = array() ) { return wp_remote_request( $url, $args ); }
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $r ) { return (int) ( $r['response']['code'] ?? 0 ); }
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $r ) { return (string) ( $r['body'] ?? '' ); }
}
if ( ! function_exists( 'wp_remote_retrieve_headers' ) ) {
	function wp_remote_retrieve_headers( $r ) { return $r['headers'] ?? array(); }
}

// --- Aparat asercji ---
$fail = 0;
$ran  = 0;
function check( $cond, $label ) {
	global $fail, $ran;
	++$ran;
	echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n";
	if ( ! $cond ) { $fail++; }
}
function approx( $a, $b, $eps = 1e-9 ) { return abs( (float) $a - (float) $b ) < $eps; }

// --- Ładowanie kodu: KAŻDY require pod file_exists() (§8.1 pkt 5) ---
$aifaq_files = array(
	'src/Http/HttpClient.php',
	'src/Http/WpHttpClient.php',
	'src/Providers/ProviderInterface.php',
	'src/Providers/GeminiProvider.php',
	'src/Core/Settings.php',
	'src/Providers/ProviderFactory.php',
);
foreach ( $aifaq_files as $aifaq_rel ) {
	$aifaq_p = __DIR__ . '/../' . $aifaq_rel;
	if ( file_exists( $aifaq_p ) ) { require_once $aifaq_p; }
}

$has_iface   = interface_exists( 'AIFAQ\Http\HttpClient' );
$has_prov    = $has_iface && class_exists( 'AIFAQ\Providers\GeminiProvider' );
$has_factory = class_exists( 'AIFAQ\Providers\ProviderFactory' );
$has_wphttp  = class_exists( 'AIFAQ\Http\WpHttpClient' );

// --- Atrapy (WYŁĄCZNIE z tabeli §8.2) ---
if ( $has_iface ) {
	/** Transport oddający JEDNĄ zaprogramowaną odpowiedź; zapamiętuje ostatnie żądanie. */
	class K19_Http implements \AIFAQ\Http\HttpClient {
		public $last = null;
		public $calls = 0;
		private $resp;
		public function __construct( $resp ) { $this->resp = $resp; }
		public function request( string $method, string $url, array $options = array() ) {
			++$this->calls;
			$this->last = compact( 'method', 'url', 'options' );
			return $this->resp;   // DOKŁADNIE to, co podano — w tym tablica BEZ klucza 'headers' (A43).
		}
		/** Ciało ostatniego żądania jako tablica. */
		public function body() { return json_decode( (string) ( $this->last['options']['body'] ?? '' ), true ); }
	}

	/** Transport z KOLEJKĄ odpowiedzi — retry F3 i kaskada 400. */
	class K19_HttpSeq implements \AIFAQ\Http\HttpClient {
		public $calls = 0;
		public $bodies = array();     // wszystkie wysłane payloady (zdekodowane)
		public $opts_list = array();  // wszystkie przekazane $options (A54)
		public $last = null;
		private $queue;
		public function __construct( array $queue ) { $this->queue = $queue; }
		public function request( string $method, string $url, array $options = array() ) {
			++$this->calls;
			$this->last        = compact( 'method', 'url', 'options' );
			$this->bodies[]    = json_decode( (string) ( $options['body'] ?? '' ), true );
			$this->opts_list[] = $options;
			// Po wyczerpaniu kolejki: OSTATNIA odpowiedź, nigdy null (§8.2).
			$i = min( $this->calls - 1, count( $this->queue ) - 1 );
			return $this->queue[ $i ];
		}
	}
}

/** Uśpienie wstrzykiwane 6. argumentem konstruktora — zapisuje, nie śpi. */
class K19_Sleeper {
	public $slept = array();
	public function __invoke( int $s ) { $this->slept[] = $s; }
}

// --- Pomocnicy budujący odpowiedzi API ---
function k19_cand( $text = 'ok', $finish = 'STOP', $usage = null ) {
	$cand = array( 'finishReason' => $finish );
	if ( null !== $text ) { $cand['content'] = array( 'parts' => array( array( 'text' => $text ) ) ); }
	$out = array( 'candidates' => array( $cand ) );
	if ( null !== $usage ) { $out['usageMetadata'] = $usage; }
	return json_encode( $out );
}
function k19_resp( $status, $body, $headers = null ) {
	$r = array( 'status' => $status, 'body' => $body );
	if ( null !== $headers ) { $r['headers'] = $headers; }
	return $r;   // brak $headers → tablica DWUKLUCZOWA (A43)
}
function k19_err_body( $msg = 'boom', $details = null ) {
	$e = array( 'message' => $msg );
	if ( null !== $details ) { $e['details'] = $details; }
	return json_encode( array( 'error' => $e ) );
}
function k19_retryinfo( $delay ) {
	return array( array( '@type' => 'type.googleapis.com/google.rpc.RetryInfo', 'retryDelay' => $delay ) );
}
function k19_reset_env() {
	$GLOBALS['__aifaq_transients'] = array();
	$GLOBALS['__filters']          = array();
	$GLOBALS['__filters_args']     = array();
}
/** Nowy provider „ścieżki gościa” (sufit 5 s) albo „admina” (60 s). */
function k19_provider( $http, $model = 'gemini-2.5-flash', $task = '', $sleeper = null, $max_wait = 60 ) {
	return new \AIFAQ\Providers\GeminiProvider( $http, 'K', $model, 'e', $task, $sleeper, $max_wait );
}

// K20 (obszar F): trzynasty klucz `quota_scope` — rodzaj limitu odczytany z `quotaId`
// (`day` | `minute` | `''`). Bez niego retry nie odróżnia limitu dobowego od minutowego.
$META_KEYS = array(
	'finish_reason', 'truncated', 'empty_text', 'prompt_tokens', 'thoughts_tokens',
	'output_tokens', 'total_tokens', 'http_status', 'error_code', 'thinking_sent',
	'retries', 'model', 'quota_scope',
);

// ===========================================================================
echo "=== A. thinkingConfig w payloadzie (A3-A8-ter) ===\n";
// ===========================================================================
if ( $has_prov ) {
	k19_reset_env();
	$h = new K19_Http( k19_resp( 200, k19_cand( 'ok' ) ) );
	$p = k19_provider( $h );
	$p->generate( 'x', array( 'max_tokens' => 500 ) );
	$b = $h->body();
	check( ! isset( $b['generationConfig']['thinkingConfig'] ), 'NOWE — A3: bez thinking_budget → brak klucza thinkingConfig' );

	$cases = array(
		array( 'A4', 0, 0 ),
		array( 'A5', 5000, 5000 ),       // wartość LEGALNA, clamp jej NIE rusza
		array( 'A5-bis', 100, 128 ),
		array( 'A5-ter', 100000, 24576 ),
		array( 'A6', -7, -1 ),
	);
	foreach ( $cases as $c ) {
		k19_reset_env();
		$h = new K19_Http( k19_resp( 200, k19_cand( 'ok' ) ) );
		$p = k19_provider( $h );
		$p->generate( 'x', array( 'max_tokens' => 500, 'thinking_budget' => $c[1] ) );
		$b   = $h->body();
		$got = $b['generationConfig']['thinkingConfig']['thinkingBudget'] ?? 'BRAK';
		check( $c[2] === $got, 'NOWE — ' . $c[0] . ': thinking_budget ' . $c[1] . ' → thinkingBudget ' . $c[2] . ' (jest: ' . var_export( $got, true ) . ')' );
	}

	k19_reset_env();
	$h = new K19_Http( k19_resp( 200, k19_cand( 'ok' ) ) );
	$p = k19_provider( $h );
	$p->generate( 'x', array( 'max_tokens' => 500, 'thinking_budget' => 'abc' ) );
	$b = $h->body();
	check( ! isset( $b['generationConfig']['thinkingConfig'] ), 'NOWE — A7: thinking_budget nienumeryczny → klucz nieobecny, brak fatala' );

	if ( method_exists( 'AIFAQ\Providers\GeminiProvider', 'last_meta' ) ) {
		k19_reset_env();
		$h = new K19_Http( k19_resp( 200, k19_cand( 'ok' ) ) );
		$p = k19_provider( $h );
		$p->generate( 'x', array( 'max_tokens' => 500, 'thinking_budget' => 0 ) );
		check( 0 === $p->last_meta()['thinking_sent'], 'NOWE — A8: budżet 0 → thinking_sent === 0' );

		k19_reset_env();
		$h = new K19_Http( k19_resp( 200, k19_cand( 'ok' ) ) );
		$p = k19_provider( $h );
		$p->generate( 'x', array() );
		check( -2 === $p->last_meta()['thinking_sent'], 'NOWE — A8-bis: BEZ thinking_budget → thinking_sent === -2 („nie wyslano” != „wyslano 0”)' );

		k19_reset_env();
		$h = new K19_Http( k19_resp( 200, k19_cand( 'ok' ) ) );
		$p = k19_provider( $h );
		$p->generate( 'x', array( 'max_tokens' => 500, 'thinking_budget' => -1 ) );
		$b = $h->body();
		check( -1 === ( $b['generationConfig']['thinkingConfig']['thinkingBudget'] ?? 'BRAK' ), 'NOWE — A8-ter: budżet -1 → thinkingBudget -1 w payloadzie' );
		check( -1 === $p->last_meta()['thinking_sent'], 'NOWE — A8-ter: budżet -1 → thinking_sent === -1' );
	} else {
		check( false, 'NOWE — A8/A8-bis/A8-ter pominięte: brak metody GeminiProvider::last_meta()' );
	}
} else {
	check( false, 'NOWE — sekcja A pominięta: brak klasy GeminiProvider albo interfejsu HttpClient' );
}

// ===========================================================================
echo "\n=== B. systemInstruction + regresja payloadu (A9-A13) ===\n";
// ===========================================================================
if ( $has_prov ) {
	k19_reset_env();
	$h = new K19_Http( k19_resp( 200, k19_cand( 'ok' ) ) );
	$p = k19_provider( $h );
	$p->generate( 'hej', array( 'temperature' => 0.2, 'max_tokens' => 500 ) );
	$b = $h->body();
	check( 2 === count( $b ), 'REGRESJA — A9: payload ma DOKŁADNIE 2 klucze najwyższego poziomu (jest: ' . count( (array) $b ) . ')' );
	check( 2 === count( $b['generationConfig'] ?? array() ), 'REGRESJA — A9: generationConfig ma DOKŁADNIE 2 klucze (temperature, maxOutputTokens)' );

	k19_reset_env();
	$sys = "ZASADY\n1. Pierwsza.";
	$h   = new K19_Http( k19_resp( 200, k19_cand( 'ok' ) ) );
	$p   = k19_provider( $h );
	$p->generate( 'hej', array( 'max_tokens' => 500, 'system' => $sys ) );
	$b = $h->body();
	check( $sys === ( $b['systemInstruction']['parts'][0]['text'] ?? null ), 'NOWE — A10: system → systemInstruction.parts[0].text identyczny co do znaku' );

	k19_reset_env();
	$h = new K19_Http( k19_resp( 200, k19_cand( 'ok' ) ) );
	$p = k19_provider( $h );
	$p->generate( 'hej', array( 'max_tokens' => 500 ) );
	check( ! isset( $h->body()['systemInstruction'] ), 'NOWE — A11: bez system → brak klucza systemInstruction' );

	foreach ( array( '', '   ' ) as $empty ) {
		k19_reset_env();
		$h = new K19_Http( k19_resp( 200, k19_cand( 'ok' ) ) );
		$p = k19_provider( $h );
		$p->generate( 'hej', array( 'max_tokens' => 500, 'system' => $empty ) );
		check( ! isset( $h->body()['systemInstruction'] ), 'NOWE — A12: system = ' . var_export( $empty, true ) . ' → klucz nieobecny' );
	}

	k19_reset_env();
	$h = new K19_Http( k19_resp( 200, k19_cand( 'ok' ) ) );
	$p = k19_provider( $h );
	$p->generate( 'hej', array( 'max_tokens' => 500, 'system' => array( 'a', 'b' ) ) );
	check( ! isset( $h->body()['systemInstruction'] ), 'NOWE — A13: system jako tablica → klucz nieobecny, brak fatala' );
} else {
	check( false, 'NOWE — sekcja B pominięta: brak klasy GeminiProvider' );
}

// ===========================================================================
echo "\n=== C. last_meta(): kształt, reset, podział FZ1/FZ1-bis (A1,A2,A19-A21,A31-A33-bis,A50) ===\n";
// ===========================================================================
if ( $has_prov && method_exists( 'AIFAQ\Providers\GeminiProvider', 'last_meta' ) ) {
	k19_reset_env();
	$h = new K19_Http( k19_resp( 200, k19_cand( 'ok' ) ) );
	$p = k19_provider( $h );
	$m = $p->last_meta();
	check( 13 === count( $m ), 'NOWE — A1: last_meta() ma 13 kluczy PRZED pierwszym generate() (K20: +quota_scope; jest: ' . count( $m ) . ')' );
	check( array() === array_diff( array_keys( $m ), $META_KEYS ), 'NOWE — A2: brak kluczy NADMIAROWYCH wobec §2.1' );
	check( array() === array_diff( $META_KEYS, array_keys( $m ) ), 'NOWE — A2: brak kluczy BRAKUJĄCYCH wobec §2.1' );

	// A19 — usageMetadata przepisane co do wartości.
	k19_reset_env();
	$usage = array( 'promptTokenCount' => 11, 'thoughtsTokenCount' => 22, 'candidatesTokenCount' => 33, 'totalTokenCount' => 66 );
	$h     = new K19_Http( k19_resp( 200, k19_cand( 'ok', 'STOP', $usage ) ) );
	$p     = k19_provider( $h );
	$p->generate( 'x', array( 'max_tokens' => 500 ) );
	$m = $p->last_meta();
	check( 11 === $m['prompt_tokens'], 'NOWE — A19: prompt_tokens = 11' );
	check( 22 === $m['thoughts_tokens'], 'NOWE — A19: thoughts_tokens = 22 (dowód przyczyny 0.1)' );
	check( 33 === $m['output_tokens'], 'NOWE — A19: output_tokens = 33' );
	check( 66 === $m['total_tokens'], 'NOWE — A19: total_tokens = 66' );
	check( 200 === $m['http_status'], 'NOWE — A21: http_status = 200 przy sukcesie' );

	// A20 — brak CAŁEGO usageMetadata → cztery zera.
	k19_reset_env();
	$h = new K19_Http( k19_resp( 200, k19_cand( 'ok' ) ) );
	$p = k19_provider( $h );
	$p->generate( 'x', array( 'max_tokens' => 500 ) );
	$m = $p->last_meta();
	check( 0 === $m['prompt_tokens'], 'NOWE — A20: brak usageMetadata → prompt_tokens === 0' );
	check( 0 === $m['thoughts_tokens'], 'NOWE — A20: brak usageMetadata → thoughts_tokens === 0' );
	check( 0 === $m['output_tokens'], 'NOWE — A20: brak usageMetadata → output_tokens === 0' );
	check( 0 === $m['total_tokens'], 'NOWE — A20: brak usageMetadata → total_tokens === 0' );

	// A20 (wariant): usageMetadata BEZ thoughtsTokenCount — brak pola != brak sekcji (§2.1, C2-S13).
	k19_reset_env();
	$h = new K19_Http( k19_resp( 200, k19_cand( 'ok', 'STOP', array( 'promptTokenCount' => 7, 'candidatesTokenCount' => 9, 'totalTokenCount' => 16 ) ) ) );
	$p = k19_provider( $h );
	$p->generate( 'x', array( 'max_tokens' => 500 ) );
	$m = $p->last_meta();
	check( 0 === $m['thoughts_tokens'] && 7 === $m['prompt_tokens'], 'NOWE — A20: usageMetadata bez thoughtsTokenCount → thoughts=0, reszta przepisana' );

	// A31 — WP_Error z transportu.
	k19_reset_env();
	$h = new K19_Http( new WP_Error( 'http_request_failed', 'transport padł' ) );
	$p = k19_provider( $h );
	$p->generate( 'x', array( 'max_tokens' => 500 ) );
	check( 0 === $p->last_meta()['http_status'], 'NOWE — A31: WP_Error z transportu → http_status === 0' );

	// A32 — reset pól generacyjnych na pierwszej linii generate().
	k19_reset_env();
	$sl  = new K19_Sleeper();
	$seq = new K19_HttpSeq( array( k19_resp( 200, k19_cand( 'ok', 'STOP' ) ), k19_resp( 429, k19_err_body( 'limit' ) ) ) );
	$p   = k19_provider( $seq, 'gemini-2.5-flash', '', $sl );
	$p->generate( 'x', array( 'max_tokens' => 500 ) );
	$p->generate( 'y', array( 'max_tokens' => 500 ) );
	check( '' === $p->last_meta()['finish_reason'], 'NOWE — A32: sukces, potem 429 → finish_reason zresetowane do \'\'' );

	// A33 — embed() NIE tyka pól generacyjnych.
	k19_reset_env();
	$h = new K19_Http( k19_resp( 200, k19_cand( 'czesc', 'MAX_TOKENS' ) ) );
	$p = k19_provider( $h );
	$p->generate( 'x', array( 'max_tokens' => 500 ) );
	$he = new K19_Http( k19_resp( 200, json_encode( array( 'embeddings' => array( array( 'values' => array( 0.1, 0.2 ) ) ) ) ) ) );
	$p2 = $p;
	$p2->embed( array( 'tekst' ) );
	check( true === $p2->last_meta()['truncated'], 'NOWE — A33: generate(MAX_TOKENS) → embed() → truncated NADAL true' );

	// A33-bis — `model` jest polem GENERACYJNYM (FZ1-bis).
	k19_reset_env();
	$h = new K19_Http( k19_resp( 200, k19_cand( 'ok' ) ) );
	$p = new \AIFAQ\Providers\GeminiProvider( $h, 'K', 'm', 'e' );
	$p->generate( 'x', array( 'max_tokens' => 500 ) );
	$p->embed( array( 'tekst' ) );
	check( 'm' === $p->last_meta()['model'], 'NOWE — A33-bis: generate()→embed() → model zostaje \'m\' (nie model embeddingów)' );
} else {
	check( false, 'NOWE — sekcja C pominięta: brak metody GeminiProvider::last_meta()' );
}

if ( $has_prov && method_exists( 'AIFAQ\Providers\GeminiProvider', 'model' ) ) {
	$h = new K19_Http( k19_resp( 200, k19_cand( 'ok' ) ) );
	$p = new \AIFAQ\Providers\GeminiProvider( $h, 'K', 'm', 'e' );
	check( 'm' === $p->model(), 'NOWE — A50: model() === \'m\' na konstruktorze 4-argumentowym' );
} else {
	check( false, 'NOWE — A50 pominięta: brak metody GeminiProvider::model()' );
}

// ===========================================================================
echo "\n=== D. finishReason (A14-A18) ===\n";
// ===========================================================================
if ( $has_prov ) {
	k19_reset_env();
	$h = new K19_Http( k19_resp( 200, k19_cand( 'pelna odpowiedz', 'STOP' ) ) );
	$p = k19_provider( $h );
	$r = $p->generate( 'x', array( 'max_tokens' => 500 ) );
	check( 'pelna odpowiedz' === $r, 'NOWE — A14: STOP + tekst → zwraca string' );
	if ( method_exists( $p, 'last_meta' ) ) {
		check( false === $p->last_meta()['truncated'], 'NOWE — A14: STOP → truncated === false' );
		check( 'STOP' === $p->last_meta()['finish_reason'], 'NOWE — A14: finish_reason === \'STOP\'' );
	} else {
		check( false, 'NOWE — A14 (meta) pominięta: brak last_meta()' );
		check( false, 'NOWE — A14 (finish_reason) pominięta: brak last_meta()' );
	}

	k19_reset_env();
	$h = new K19_Http( k19_resp( 200, k19_cand( 'czesc', 'MAX_TOKENS' ) ) );
	$p = k19_provider( $h );
	$r = $p->generate( 'x', array( 'max_tokens' => 500 ) );
	check( 'czesc' === $r, 'REGRESJA — A15: MAX_TOKENS z tekstem → NADAL string \'czesc\' (kontrakt audytu K7 nietknięty)' );
	if ( method_exists( $p, 'last_meta' ) ) {
		check( true === $p->last_meta()['truncated'], 'NOWE — A15: MAX_TOKENS → truncated === true' );
	} else {
		check( false, 'NOWE — A15 (truncated) pominięta: brak last_meta()' );
	}

	k19_reset_env();
	$h = new K19_Http( k19_resp( 200, k19_cand( null, 'MAX_TOKENS' ) ) );
	$r = k19_provider( $h )->generate( 'x', array( 'max_tokens' => 500 ) );
	check( is_wp_error( $r ) && 'aifaq_gemini_truncated' === $r->get_error_code(), 'NOWE — A16: MAX_TOKENS BEZ parts → aifaq_gemini_truncated' );

	k19_reset_env();
	$h = new K19_Http( k19_resp( 200, k19_cand( null, 'SAFETY' ) ) );
	$r = k19_provider( $h )->generate( 'x', array( 'max_tokens' => 500 ) );
	check( is_wp_error( $r ) && 'aifaq_gemini_blocked' === $r->get_error_code(), 'REGRESJA — A17: SAFETY bez parts → aifaq_gemini_blocked' );

	k19_reset_env();
	$h = new K19_Http( k19_resp( 200, json_encode( array( 'promptFeedback' => array( 'blockReason' => 'SAFETY' ) ) ) ) );
	$r = k19_provider( $h )->generate( 'x', array( 'max_tokens' => 500 ) );
	check( is_wp_error( $r ) && 'aifaq_gemini_blocked' === $r->get_error_code(), 'REGRESJA — A18: promptFeedback.blockReason → aifaq_gemini_blocked' );
} else {
	check( false, 'NOWE — sekcja D pominięta: brak klasy GeminiProvider' );
}

// ===========================================================================
echo "\n=== E. Kody HTTP i retry F3 (A22-A27, A44-A47, A51, A54, A25-bis) ===\n";
// ===========================================================================
if ( $has_prov ) {
	// A22 / A23 / A24 — rozgałęzienie kodów.
	$codes = array(
		array( 'A22', 429, 'aifaq_gemini_rate', 'NOWE' ),
		array( 'A23', 503, 'aifaq_gemini_busy', 'NOWE' ),
		array( 'A24', 404, 'aifaq_gemini_http', 'REGRESJA' ),
	);
	foreach ( $codes as $c ) {
		k19_reset_env();
		$sl = new K19_Sleeper();
		$h  = new K19_Http( k19_resp( $c[1], k19_err_body( 'blad' ) ) );
		$p  = k19_provider( $h, 'gemini-2.5-flash', '', $sl );
		$r  = $p->generate( 'x', array( 'max_tokens' => 500 ) );
		check( is_wp_error( $r ) && $c[2] === $r->get_error_code(), $c[3] . ' — ' . $c[0] . ': HTTP ' . $c[1] . ' → ' . $c[2] );
		if ( 429 === $c[1] && method_exists( $p, 'last_meta' ) ) {
			check( 429 === $p->last_meta()['http_status'], 'NOWE — A22: http_status === 429' );
		}
	}

	// A25 — 429 x3 → dokładnie trzy próby, dwa ponowienia.
	k19_reset_env();
	$sl  = new K19_Sleeper();
	$seq = new K19_HttpSeq( array( k19_resp( 429, k19_err_body( 'a' ) ), k19_resp( 429, k19_err_body( 'b' ) ), k19_resp( 429, k19_err_body( 'c' ) ) ) );
	$p   = k19_provider( $seq, 'gemini-2.5-flash', '', $sl );
	$r   = $p->generate( 'x', array( 'max_tokens' => 500 ) );
	check( 3 === $seq->calls, 'NOWE — A25: 429 x3 → DOKŁADNIE 3 próby (jest: ' . $seq->calls . ')' );
	if ( method_exists( $p, 'last_meta' ) ) {
		check( 2 === $p->last_meta()['retries'], 'NOWE — A25: retries === 2' );
	} else {
		check( false, 'NOWE — A25 (retries) pominięta: brak last_meta()' );
	}
	// A54 — ZADNA pojedyncza proba nie deklaruje timeoutu ponad budzet (odchylenie O-8).
	//
	// Pierwotna postac („suma options[timeout] <= REQUEST_RETRY_BUDGET”) jest NIESPELNIALNA
	// razem z A25 na dowolnym POPRAWNYM kodzie: A25 wymaga DOKLADNIE 3 prob, REQUEST_TIMEOUT
	// to 60 s, wiec suma deklaracji wynosi 180 > 100. Budzet jest ZEGAROWY (remaining_budget()
	// liczy microtime), a w tescie czas nie plynie — kazda proba legalnie deklaruje min(60, ~100).
	// Kod nie moglby tego naprawic REZERWUJAC bud zet: proba 3 dostalaby 0 s, nie wystartowalaby
	// przy MIN_ATTEMPT_SECONDS = 5 i pekloby A25. Kontrakt sam to przewidzial (§4.8: „A54 bylaby
	// czerwona na poprawnym kodzie”), ale podniesienie bud zetu 90 -> 100 problemu nie usunelo.
	// Mierzymy wiec wlasnosc, o ktora naprawde chodzilo: zadna proba nie wybiega poza bud zet.
	if ( defined( 'AIFAQ\Providers\GeminiProvider::REQUEST_RETRY_BUDGET' ) ) {
		$budget = (int) constant( 'AIFAQ\Providers\GeminiProvider::REQUEST_RETRY_BUDGET' );
		$over   = array();
		foreach ( $seq->opts_list as $o ) {
			$t = (int) ( $o['timeout'] ?? 0 );
			if ( $t > $budget ) { $over[] = $t; }
		}
		check( array() === $over, 'NOWE — A54 (O-8): KAZDA proba deklaruje options[timeout] <= REQUEST_RETRY_BUDGET (' . $budget . '; przekroczenia: ' . ( $over ? implode( ',', $over ) : 'brak' ) . ')' );
	} else {
		check( false, 'NOWE — A54 pominięta: brak stałej GeminiProvider::REQUEST_RETRY_BUDGET' );
	}

	// A26 — 429 → 429 → 200 → tekst.
	k19_reset_env();
	$sl  = new K19_Sleeper();
	$seq = new K19_HttpSeq( array( k19_resp( 429, k19_err_body( 'a' ) ), k19_resp( 429, k19_err_body( 'b' ) ), k19_resp( 200, k19_cand( 'udalo sie' ) ) ) );
	$p   = k19_provider( $seq, 'gemini-2.5-flash', '', $sl );
	$r   = $p->generate( 'x', array( 'max_tokens' => 500 ) );
	check( 'udalo sie' === $r, 'NOWE — A26: 429→429→200 → zwraca tekst' );
	if ( method_exists( $p, 'last_meta' ) ) {
		check( 2 === $p->last_meta()['retries'], 'NOWE — A26: retries === 2' );
	} else {
		check( false, 'NOWE — A26 (retries) pominięta: brak last_meta()' );
	}

	// A27 — filtr wyłączający retry.
	k19_reset_env();
	$GLOBALS['__filters']['aifaq_http_retry'] = static function () { return false; };
	$sl  = new K19_Sleeper();
	$seq = new K19_HttpSeq( array( k19_resp( 429, k19_err_body( 'a' ) ) ) );
	$p   = k19_provider( $seq, 'gemini-2.5-flash', '', $sl );
	$p->generate( 'x', array( 'max_tokens' => 500 ) );
	check( 1 === $seq->calls, 'NOWE — A27: aifaq_http_retry=false → 1 próba przy 429 (jest: ' . $seq->calls . ')' );

	// A44 — retryDelay z RetryInfo (CIAŁO odpowiedzi, nie nagłówek).
	k19_reset_env();
	$sl  = new K19_Sleeper();
	$seq = new K19_HttpSeq( array( k19_resp( 429, k19_err_body( 'limit', k19_retryinfo( '7s' ) ) ), k19_resp( 200, k19_cand( 'ok' ) ) ) );
	k19_provider( $seq, 'gemini-2.5-flash', '', $sl )->generate( 'x', array( 'max_tokens' => 500 ) );
	check( array( 7 ) === $sl->slept, 'NOWE — A44: RetryInfo.retryDelay "7s" → uśpienie array(7) (jest: ' . json_encode( $sl->slept ) . ')' );

	// A45 — opóźnienie ze zdania w error.message.
	k19_reset_env();
	$sl  = new K19_Sleeper();
	$seq = new K19_HttpSeq( array( k19_resp( 429, k19_err_body( 'Please retry in 56.458591106s' ) ), k19_resp( 200, k19_cand( 'ok' ) ) ) );
	k19_provider( $seq, 'gemini-2.5-flash', '', $sl )->generate( 'x', array( 'max_tokens' => 500 ) );
	check( array( 56 ) === $sl->slept, 'NOWE — A45: "Please retry in 56.458591106s" → uśpienie array(56) (jest: ' . json_encode( $sl->slept ) . ')' );

	// A46 — ŚCIEŻKA ADMINA (sufit 60 s): backoff 5 s, 15 s.
	k19_reset_env();
	$sl  = new K19_Sleeper();
	$seq = new K19_HttpSeq( array( k19_resp( 429, k19_err_body( 'a' ) ), k19_resp( 429, k19_err_body( 'b' ) ), k19_resp( 429, k19_err_body( 'c' ) ) ) );
	k19_provider( $seq, 'gemini-2.5-flash', '', $sl, 60 )->generate( 'x', array( 'max_tokens' => 500 ) );
	check( array( 5, 15 ) === $sl->slept, 'NOWE — A46: ścieżka ADMINA, 429 bez wskazówki → backoff array(5,15) (jest: ' . json_encode( $sl->slept ) . ')' );
	check( 3 === $seq->calls, 'NOWE — A46: 3 próby' );

	// A47 — bezsensowne opóźnienia → backoff, brak fatala.
	foreach ( array( array( 'RetryInfo "0s"', k19_err_body( 'limit', k19_retryinfo( '0s' ) ) ), array( 'RetryInfo "abc"', k19_err_body( 'limit', k19_retryinfo( 'abc' ) ) ) ) as $bad ) {
		k19_reset_env();
		$sl  = new K19_Sleeper();
		$seq = new K19_HttpSeq( array( k19_resp( 429, $bad[1] ), k19_resp( 200, k19_cand( 'ok' ) ) ) );
		k19_provider( $seq, 'gemini-2.5-flash', '', $sl, 60 )->generate( 'x', array( 'max_tokens' => 500 ) );
		check( array( 5 ) === $sl->slept, 'NOWE — A47: ' . $bad[0] . ' → backoff array(5), brak fatala (jest: ' . json_encode( $sl->slept ) . ')' );
	}

	// A51 — wyłącznik obwodu (cooldown) po wyczerpaniu ponowień.
	k19_reset_env();
	$sl  = new K19_Sleeper();
	$seq = new K19_HttpSeq( array( k19_resp( 429, k19_err_body( 'a' ) ), k19_resp( 429, k19_err_body( 'b' ) ), k19_resp( 429, k19_err_body( 'c' ) ) ) );
	$p   = k19_provider( $seq, 'gemini-2.5-flash', '', $sl, 60 );
	$p->generate( 'x', array( 'max_tokens' => 500 ) );
	$seq->calls = 0;
	$r2         = $p->generate( 'y', array( 'max_tokens' => 500 ) );
	check( 0 === $seq->calls, 'NOWE — A51: wyłącznik obwodu → drugie generate() nie woła API (jest: ' . $seq->calls . ' żądań)' );

	// A25-bis — akumulacja `retries` przez kaskadę 400 (FZ1-bis).
	k19_reset_env();
	$sl  = new K19_Sleeper();
	$seq = new K19_HttpSeq( array( k19_resp( 400, k19_err_body( 'zly thinkingConfig' ) ), k19_resp( 400, k19_err_body( 'nadal zly' ) ), k19_resp( 200, k19_cand( 'ok' ) ) ) );
	$p   = k19_provider( $seq, 'gemini-2.5-flash', '', $sl, 60 );
	$p->generate( 'x', array( 'max_tokens' => 500, 'thinking_budget' => 0 ) );
	if ( method_exists( $p, 'last_meta' ) ) {
		check( 2 === $p->last_meta()['retries'], 'NOWE — A25-bis: kaskada 400→400→200 → retries === 2 (akumulacja między wejściami do request_json)' );
	} else {
		check( false, 'NOWE — A25-bis pominięta: brak last_meta()' );
	}
} else {
	check( false, 'NOWE — sekcja E pominięta: brak klasy GeminiProvider' );
}

// ===========================================================================
echo "\n=== F. Kaskada 400 — DOKŁADNIE TRZY PRÓBY (A28, A29, A29-bis, A52, A53) ===\n";
// ===========================================================================
if ( $has_prov ) {
	// A28 — 400 BEZ thinkingConfig w payloadzie → bez ponowienia.
	k19_reset_env();
	$sl  = new K19_Sleeper();
	$seq = new K19_HttpSeq( array( k19_resp( 400, k19_err_body( 'zle zapytanie' ) ) ) );
	k19_provider( $seq, 'gemini-2.5-flash', '', $sl, 60 )->generate( 'x', array( 'max_tokens' => 500 ) );
	check( 1 === $seq->calls, 'NOWE — A28: HTTP 400 bez thinkingConfig → 1 próba, zero kaskady (jest: ' . $seq->calls . ')' );

	// A29 — 400 → 200: druga próba z budżetem 128.
	k19_reset_env();
	$sl  = new K19_Sleeper();
	$seq = new K19_HttpSeq( array( k19_resp( 400, k19_err_body( 'thinking nieobslugiwane' ) ), k19_resp( 200, k19_cand( 'ok' ) ) ) );
	$p   = k19_provider( $seq, 'gemini-2.5-flash', '', $sl, 60 );
	$p->generate( 'x', array( 'max_tokens' => 500, 'thinking_budget' => 0 ) );
	check( 2 === $seq->calls, 'NOWE — A29: 400→200 → 2 próby (jest: ' . $seq->calls . ')' );
	check( 128 === ( $seq->bodies[1]['generationConfig']['thinkingConfig']['thinkingBudget'] ?? 'BRAK' ), 'NOWE — A29: DRUGI payload ma thinkingBudget === 128' );
	if ( method_exists( $p, 'last_meta' ) ) {
		check( 128 === $p->last_meta()['thinking_sent'], 'NOWE — A29: thinking_sent === 128 po próbie 2' );
	} else {
		check( false, 'NOWE — A29 (thinking_sent) pominięta: brak last_meta()' );
	}

	// A29-bis / A53 — 400 x3: trzecia próba bez pola, sufit podniesiony, BRAK czwartej.
	k19_reset_env();
	$sl  = new K19_Sleeper();
	$seq = new K19_HttpSeq( array( k19_resp( 400, k19_err_body( 'a' ) ), k19_resp( 400, k19_err_body( 'b' ) ), k19_resp( 400, k19_err_body( 'c' ) ) ) );
	$p   = k19_provider( $seq, 'gemini-2.5-flash', '', $sl, 60 );
	$p->generate( 'x', array( 'max_tokens' => 500, 'thinking_budget' => 0 ) );
	check( 3 === $seq->calls, 'NOWE — A29-bis: 400x3 → DOKŁADNIE 3 próby, BRAK czwartej (jest: ' . $seq->calls . ')' );
	check( ! isset( $seq->bodies[2]['generationConfig']['thinkingConfig'] ), 'NOWE — A29-bis: TRZECI payload nie ma thinkingConfig' );
	check( (int) ( $seq->bodies[2]['generationConfig']['maxOutputTokens'] ?? 0 ) >= 2048, 'NOWE — A29-bis: TRZECI payload ma maxOutputTokens >= 2048' );
	if ( method_exists( $p, 'last_meta' ) ) {
		check( -2 === $p->last_meta()['thinking_sent'], 'NOWE — A29-bis: thinking_sent === -2 po zdjęciu pola' );
	} else {
		check( false, 'NOWE — A29-bis (thinking_sent) pominięta: brak last_meta()' );
	}
	check( 128 === ( $seq->bodies[1]['generationConfig']['thinkingConfig']['thinkingBudget'] ?? 'BRAK' ), 'NOWE — A53: próba 2 ma thinkingBudget = 128' );
	check( ! isset( $seq->bodies[2]['generationConfig']['thinkingConfig'] ) && (int) ( $seq->bodies[2]['generationConfig']['maxOutputTokens'] ?? 0 ) >= 2048, 'NOWE — A53: próba 3 bez thinkingConfig i z podniesionym sufitem' );
	check( 3 === $seq->calls, 'NOWE — A53: kształt trzech payloadów — 3 wywołania' );

	// A52 — PAMIĘĆ ODRZUCENIA na tej samej instancji (gemini-2.0-flash).
	k19_reset_env();
	$sl  = new K19_Sleeper();
	$seq = new K19_HttpSeq( array( k19_resp( 400, k19_err_body( 'a' ) ), k19_resp( 400, k19_err_body( 'b' ) ), k19_resp( 400, k19_err_body( 'c' ) ), k19_resp( 200, k19_cand( 'ok' ) ) ) );
	$p   = k19_provider( $seq, 'gemini-2.0-flash', '', $sl, 60 );
	$p->generate( 'x', array( 'max_tokens' => 500, 'thinking_budget' => 0 ) );
	$before = count( $seq->bodies );
	$p->generate( 'y', array( 'max_tokens' => 500, 'thinking_budget' => 0 ) );
	$second = $seq->bodies[ $before ] ?? array();
	check( ! isset( $second['generationConfig']['thinkingConfig'] ), 'NOWE — A52: gemini-2.0-flash → DRUGIE generate() nie wysyła thinkingConfig (pamięć odrzucenia)' );
} else {
	check( false, 'NOWE — sekcja F pominięta: brak klasy GeminiProvider' );
}

// ===========================================================================
echo "\n=== G. embed() i taskType (A34-A38, A41-A43) ===\n";
// ===========================================================================
if ( $has_prov ) {
	$emb_body = json_encode( array( 'embeddings' => array( array( 'values' => array( 0.1, 0.2 ) ) ) ) );

	k19_reset_env();
	$h  = new K19_Http( k19_resp( 200, $emb_body ) );
	$ok = true;
	try { $p4 = new \AIFAQ\Providers\GeminiProvider( $h, 'K', 'm', 'gemini-embedding-001' ); } catch ( \Throwable $e ) { $ok = false; }
	check( $ok, 'REGRESJA — A34: konstruktor 4-argumentowy działa (brak ArgumentCountError)' );

	$p4->embed( array( 'tekst' ) );
	$be = $h->body();
	check( ! isset( $be['requests'][0]['taskType'] ), 'NOWE — A35: 4 argumenty → brak taskType w payloadzie embed' );
	check( 768 === ( $be['requests'][0]['outputDimensionality'] ?? null ), 'REGRESJA — A38: outputDimensionality === 768' );
	check( 'models/gemini-embedding-001' === ( $be['requests'][0]['model'] ?? null ), 'REGRESJA — A38: model === models/gemini-embedding-001' );
	check( false === strpos( (string) $h->last['url'], 'K' ), 'REGRESJA — A42: klucz API NIE w URL' );
	check( 'K' === ( $h->last['options']['headers']['x-goog-api-key'] ?? null ), 'REGRESJA — A42: klucz API wyłącznie w nagłówku x-goog-api-key' );

	k19_reset_env();
	$h = new K19_Http( k19_resp( 200, $emb_body ) );
	k19_provider( $h, 'm', 'RETRIEVAL_DOCUMENT' )->embed( array( 'tekst' ) );
	check( 'RETRIEVAL_DOCUMENT' === ( $h->body()['requests'][0]['taskType'] ?? null ), 'NOWE — A36: 5. argument RETRIEVAL_DOCUMENT → taskType w payloadzie' );

	k19_reset_env();
	$emb3 = json_encode( array( 'embeddings' => array( array( 'values' => array( 0.1 ) ), array( 'values' => array( 0.2 ) ), array( 'values' => array( 0.3 ) ) ) ) );
	$h    = new K19_Http( k19_resp( 200, $emb3 ) );
	k19_provider( $h, 'm', 'RETRIEVAL_QUERY' )->embed( array( 'a', 'b', 'c' ) );
	$be = $h->body();
	check( 3 === count( $be['requests'] ?? array() ), 'NOWE — A37: 3 teksty → 3 elementy requests' );
	$all_tagged = ! empty( $be['requests'] );
	foreach ( (array) ( $be['requests'] ?? array() ) as $req ) {
		if ( 'RETRIEVAL_QUERY' !== ( $req['taskType'] ?? null ) ) { $all_tagged = false; }
	}
	check( $all_tagged, 'NOWE — A37: KAŻDY element requests ma taskType = RETRIEVAL_QUERY' );

	// A43 — odpowiedź BEZ klucza `headers` nie wywołuje warninga (odczyt przez ?? array()).
	k19_reset_env();
	$GLOBALS['__warn'] = array();
	$h = new K19_Http( k19_resp( 200, k19_cand( 'ok' ) ) );   // DWA klucze, bez 'headers'
	set_error_handler( static function ( $no, $str ) { $GLOBALS['__warn'][] = $str; return true; } );
	k19_provider( $h )->generate( 'x', array( 'max_tokens' => 500 ) );
	restore_error_handler();
	check( 0 === count( $GLOBALS['__warn'] ), 'NOWE — A43: odpowiedź bez klucza headers → ZERO warningów (jest: ' . count( $GLOBALS['__warn'] ) . ')' );
} else {
	check( false, 'NOWE — sekcja G pominięta: brak klasy GeminiProvider' );
}

// A41 — filtr aifaq_embed_task → '' (filtr żyje w ProviderFactory::build(), §5.4 poz. 9).
if ( $has_factory && $has_iface && method_exists( 'AIFAQ\Providers\ProviderFactory', 'make_for_index' ) ) {
	k19_reset_env();
	$GLOBALS['__opt']['aifaq_settings']       = array( 'api_key' => 'K', 'model' => 'gemini-2.5-flash', 'embed_model' => 'gemini-embedding-001' );
	$GLOBALS['__filters']['aifaq_embed_task'] = static function () { return ''; };
	$h = new K19_Http( k19_resp( 200, json_encode( array( 'embeddings' => array( array( 'values' => array( 0.1 ) ) ) ) ) ) );
	\AIFAQ\Providers\ProviderFactory::make_for_index( $h )->embed( array( 'tekst' ) );
	check( ! isset( $h->body()['requests'][0]['taskType'] ), 'NOWE — A41: aifaq_embed_task → \'\' → brak taskType w payloadzie' );
} else {
	check( false, 'NOWE — A41 pominięta: brak metody ProviderFactory::make_for_index()' );
}

// ===========================================================================
echo "\n=== H. ProviderFactory — flavoury i reguła „nie warto czekać” (A39, A40, A46-bis, A55) ===\n";
// ===========================================================================
$fac_ready = $has_factory && $has_iface
	&& method_exists( 'AIFAQ\Providers\ProviderFactory', 'make_for_query' )
	&& method_exists( 'AIFAQ\Providers\ProviderFactory', 'make_for_index' );

if ( $fac_ready ) {
	k19_reset_env();
	$GLOBALS['__opt']['aifaq_settings'] = array( 'api_key' => 'K', 'model' => 'gemini-2.5-flash', 'embed_model' => 'gemini-embedding-001' );
	$emb = json_encode( array( 'embeddings' => array( array( 'values' => array( 0.1 ) ) ) ) );

	$h = new K19_Http( k19_resp( 200, $emb ) );
	$q = \AIFAQ\Providers\ProviderFactory::make_for_query( $h );
	check( $q instanceof \AIFAQ\Providers\ProviderInterface, 'NOWE — A39: make_for_query() zwraca ProviderInterface' );

	$spy_override = new class implements \AIFAQ\Providers\ProviderInterface {
		public function generate( string $prompt, array $options = array() ) { return 'OVERRIDE'; }
		public function embed( array $texts ) { return array(); }
		public function verify() { return true; }
	};
	\AIFAQ\Providers\ProviderFactory::set_override( $spy_override );
	check( $spy_override === \AIFAQ\Providers\ProviderFactory::make_for_query( $h ), 'NOWE — A39: make_for_query() honoruje set_override()' );
	\AIFAQ\Providers\ProviderFactory::set_override( null );

	// A40 — FZ4: make() embeduje BEZ taskType.
	k19_reset_env();
	$GLOBALS['__opt']['aifaq_settings'] = array( 'api_key' => 'K', 'model' => 'gemini-2.5-flash', 'embed_model' => 'gemini-embedding-001' );
	$h = new K19_Http( k19_resp( 200, $emb ) );
	\AIFAQ\Providers\ProviderFactory::make( $h )->embed( array( 't' ) );
	check( ! isset( $h->body()['requests'][0]['taskType'] ), 'NOWE — A40 (FZ4): make() embeduje BEZ taskType' );

	$h = new K19_Http( k19_resp( 200, $emb ) );
	\AIFAQ\Providers\ProviderFactory::make_for_query( $h )->embed( array( 't' ) );
	check( 'RETRIEVAL_QUERY' === ( $h->body()['requests'][0]['taskType'] ?? null ), 'NOWE — A40: make_for_query() → RETRIEVAL_QUERY' );

	$h = new K19_Http( k19_resp( 200, $emb ) );
	\AIFAQ\Providers\ProviderFactory::make_for_index( $h )->embed( array( 't' ) );
	check( 'RETRIEVAL_DOCUMENT' === ( $h->body()['requests'][0]['taskType'] ?? null ), 'NOWE — A40: make_for_index() → RETRIEVAL_DOCUMENT' );
} else {
	check( false, 'NOWE — A39/A40 pominięte: brak metod ProviderFactory::make_for_query()/make_for_index()' );
}

// A46-bis / A55 — reguła „nie warto czekać”. Sufity 5 (gość) i 60 (reindeks) — §3.2.
// UWAGA: fabryka NIE wstrzykuje $sleepera (§3.2), więc uśpienie mierzymy na providerze
// zbudowanym DOKŁADNIE tak, jak buduje go dana metoda fabryki. Przebieg przez samą fabrykę
// realnie uśpiłby runner na 56 s (A55). Odchylenie opisane w ODCHYLENIA.md.
if ( $has_prov ) {
	k19_reset_env();
	$sl  = new K19_Sleeper();
	$seq = new K19_HttpSeq( array( k19_resp( 429, k19_err_body( 'Please retry in 56.458591106s' ) ), k19_resp( 200, k19_cand( 'ok' ) ) ) );
	$r   = k19_provider( $seq, 'gemini-2.5-flash', '', $sl, 5 )->generate( 'x', array( 'max_tokens' => 500 ) );
	check( array() === $sl->slept, 'NOWE — A46-bis: ścieżka GOŚCIA (sufit 5 s) + 56 s → ZERO uśpień (jest: ' . json_encode( $sl->slept ) . ')' );
	check( is_wp_error( $r ) && 'aifaq_gemini_rate' === $r->get_error_code(), 'NOWE — A46-bis: natychmiastowe aifaq_gemini_rate zamiast czekania' );

	k19_reset_env();
	$sl  = new K19_Sleeper();
	$seq = new K19_HttpSeq( array( k19_resp( 429, k19_err_body( 'Please retry in 56.458591106s' ) ), k19_resp( 200, k19_cand( 'ok' ) ) ) );
	k19_provider( $seq, 'gemini-2.5-flash', '', $sl, 60 )->generate( 'x', array( 'max_tokens' => 500 ) );
	check( array( 56 ) === $sl->slept, 'NOWE — A55: ścieżka REINDEKSU (sufit 60 s) + 56 s → uśpienie array(56) (jest: ' . json_encode( $sl->slept ) . ')' );
} else {
	check( false, 'NOWE — A46-bis/A55 pominięte: brak klasy GeminiProvider' );
}

// ===========================================================================
echo "\n=== I. WpHttpClient — trzeci klucz zwrotu (A48, A49) ===\n";
// ===========================================================================
if ( $has_wphttp ) {
	$GLOBALS['__remote']['reply'] = array(
		'response' => array( 'code' => 200 ),
		'body'     => '{"ok":1}',
		'headers'  => array( 'Retry-After' => '7', 'Content-Type' => 'application/json' ),
	);
	$resp = ( new \AIFAQ\Http\WpHttpClient() )->request( 'POST', 'https://example.test/x', array( 'body' => '{}' ) );
	check( is_array( $resp ) && 3 === count( $resp ), 'NOWE — A48: zwrot ma DOKŁADNIE 3 klucze (jest: ' . ( is_array( $resp ) ? count( $resp ) : 'nie-tablica' ) . ')' );
	check( is_array( $resp ) && array() === array_diff( array( 'status', 'body', 'headers' ), array_keys( $resp ) ), 'NOWE — A48: klucze status, body, headers' );
	$hk        = is_array( $resp ) ? array_keys( (array) ( $resp['headers'] ?? array() ) ) : array();
	$lowercase = ! empty( $hk );
	foreach ( $hk as $k ) { if ( (string) $k !== strtolower( (string) $k ) ) { $lowercase = false; } }
	check( $lowercase, 'NOWE — A48: WSZYSTKIE klucze headers lowercase (jest: ' . implode( ',', $hk ) . ')' );

	// A49 — nagłówki jako OBIEKT (Requests_Utility_CaseInsensitiveDictionary w realnym WP).
	$GLOBALS['__remote']['reply'] = array(
		'response' => array( 'code' => 429 ),
		'body'     => '{}',
		'headers'  => (object) array( 'Retry-After' => '3' ),
	);
	$ok2   = true;
	$resp2 = null;
	try { $resp2 = ( new \AIFAQ\Http\WpHttpClient() )->request( 'POST', 'https://example.test/x', array( 'body' => '{}' ) ); } catch ( \Throwable $e ) { $ok2 = false; }
	check( $ok2, 'NOWE — A49: nagłówki jako obiekt → brak fatala' );
	check( is_array( $resp2 ) && isset( $resp2['headers']['retry-after'] ) && '3' === (string) $resp2['headers']['retry-after'], 'NOWE — A49: obiekt rzutowany na (array) + klucz lowercase' );
} else {
	check( false, 'NOWE — sekcja I pominięta: brak klasy AIFAQ\Http\WpHttpClient' );
}

// ===========================================================================
echo "\n=== Z. Podłoga pokrycia i wartownik ===\n";
// ===========================================================================
$floor = $ran;
check( $floor >= 48, 'NOWE — wykonano co najmniej 48 asercji (było ' . $floor . ')' );

echo "\nplik dobiegł końca\n";
echo 'Asercje: ' . $ran . ', niezaliczone: ' . $fail . "\n";
exit( 0 === $fail ? 0 : 1 );
