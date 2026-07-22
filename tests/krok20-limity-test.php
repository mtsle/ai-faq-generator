<?php
/**
 * Testy Kroku 20 — sekcja C/F/G: LIMITY (KONTRAKT k20-v3 §7 i §8).
 *
 * PISANE W CIEMNO (Etap 9b). Autor NIE otwierał `src/Providers/GeminiProvider.php`,
 * `src/Providers/ProviderFactory.php`, `src/Rag/RateLimiter.php` ani `src/Rag/RagService.php`
 * — asercje pochodzą WYŁĄCZNIE z `KONTRAKT.md` (§7.1-§7.4, §8.1-§8.6, §13.9-§13.14)
 * oraz z `plany/krok20/ODCHYLENIA.md` (O-51, O-52, O-53, O-61, O-62, O-63).
 * Rozbieżność test↔implementacja jest dowodem, że kontrakt był nieprecyzyjny.
 *
 * ⚠ TEN PLIK ŚWIADOMIE **NIE DEFINIUJE** `AIFAQ_TESTING`.
 * §7.4 (poprawka FZ32) wyłącza dobowy sufit witryny, gdy ta stała jest zdefiniowana —
 * zdefiniowanie jej tutaj wyłączyłoby DOKŁADNIE to, co sekcja H ma sprawdzać
 * (ostrzeżenie z ODCHYLENIA **O-61**).
 *
 * ⚠ ZERO WYWOŁAŃ DO ŻYWEGO API (§10 pkt 7). Parser 429 stoi wyłącznie na fixture'ach
 * przepisanych DOSŁOWNIE z `plany/krok19/ROZPOZNANIE-DOSTAWCY.md` §6a/6b/6c.
 *
 * Pokrywa:
 *  - A. parser `quotaId` (§8.1): trzy surowe ciała 429 + przypadki syntetyczne;
 *  - B. zachowanie wg `quota_scope` (§8.2): próby, opóźnienie, TTL cooldownu;
 *  - C. GWARANCJA WSTECZNA (§8.2-bis): ciało bez `quotaId` działa jak v0.22.0;
 *  - D. NAPRAWA WYŁĄCZNIKA OBWODU (§8.3) — najważniejsza sekcja pliku;
 *  - E. rozdzielność pul `embed` / `generate` (§8.4, §13.13);
 *  - F. `last_meta()` = 13 kluczy (§8.5, §13.14);
 *  - G. `RateLimiter`: okno, klucz, dziedziczenie, `refund()` (§7.1, §7.2, §13.10, §13.11);
 *  - H. dobowy sufit witryny (§7.4, §13.12) — przez `RagService::make()` (O-61);
 *  - I. D3 best-effort bez kłamstwa + ścieżka cache obiektowego (§7.3, O-62).
 *
 * Podłoga pokrycia: >= 60 asercji (ETAP-9b).
 *
 * URUCHOMIENIE:  php -d extension=mbstring tests/krok20-limity-test.php
 * Kod wyjścia: 0 = OK, 1 = błędy.
 *
 * @package AI_FAQ_Generator
 */

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
if ( ! defined( 'AIFAQ_PLUGIN_DIR' ) ) { define( 'AIFAQ_PLUGIN_DIR', dirname( __DIR__ ) . '/' ); }
if ( ! defined( 'AIFAQ_VERSION' ) ) { define( 'AIFAQ_VERSION', '0.23.0-test' ); }
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
if ( ! defined( 'OBJECT' ) ) { define( 'OBJECT', 'OBJECT' ); }
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
if ( ! defined( 'WEEK_IN_SECONDS' ) ) { define( 'WEEK_IN_SECONDS', 604800 ); }

// ---------------------------------------------------------------------------
// Rejestry harnessu.
// ---------------------------------------------------------------------------
$GLOBALS['__opt']       = array();
$GLOBALS['__autoload']  = array();
$GLOBALS['__opt_reads'] = array();          // licznik odczytów per klucz (§7.4: „zero odczytów”)
$GLOBALS['__tr']        = array();          // transienty
$GLOBALS['__tr_ttl']    = array();          // TTL per klucz (§8.2)
$GLOBALS['__filters']   = array();
$GLOBALS['__caps']      = array();          // cap-świadoma atrapa (§11.5)
$GLOBALS['__now']       = 1784000000;       // zegar sterowany (current_time)
$GLOBALS['__objcache']  = array();          // cache obiektowy (§7.3)
$GLOBALS['__ext_cache'] = false;            // wp_using_ext_object_cache()

// ---------------------------------------------------------------------------
// Shimy WP.
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
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return (string) $s; } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $s, $d = null ) { return (string) $s; } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return (string) $s; } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $s ) { return (string) $s; } }
if ( ! function_exists( 'esc_url_raw' ) ) { function esc_url_raw( $s ) { return (string) $s; } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $d, $f = 0, $depth = 512 ) { return json_encode( $d, $f, $depth ); } }
if ( ! function_exists( 'wp_unslash' ) ) { function wp_unslash( $s ) { return is_string( $s ) ? stripslashes( $s ) : $s; } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ) { return trim( (string) preg_replace( '/<[^>]*>/', '', (string) $s ) ); } }
if ( ! function_exists( 'sanitize_textarea_field' ) ) { function sanitize_textarea_field( $s ) { return trim( (string) preg_replace( '/<[^>]*>/', '', (string) $s ) ); } }
if ( ! function_exists( 'wp_salt' ) ) { function wp_salt( $scheme = 'auth' ) { return 'aifaq-test-salt'; } }
if ( ! function_exists( 'get_bloginfo' ) ) { function get_bloginfo( $s = 'name' ) { return 'Witryna Testowa'; } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . ltrim( (string) $p, '/' ); } }
if ( ! function_exists( 'home_url' ) ) { function home_url( $p = '/' ) { return 'https://example.test' . $p; } }

/** Zegar witryny — sterowany z testu ($GLOBALS['__now']). */
if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type = 'mysql', $gmt = 0 ) {
		$ts = (int) $GLOBALS['__now'];
		if ( 'mysql' === $type ) { return gmdate( 'Y-m-d H:i:s', $ts ); }
		if ( 'timestamp' === $type || 'U' === $type ) { return $ts; }
		return gmdate( (string) $type, $ts );
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = false ) {
		$GLOBALS['__opt_reads'][ $key ] = ( $GLOBALS['__opt_reads'][ $key ] ?? 0 ) + 1;
		return array_key_exists( $key, $GLOBALS['__opt'] ) ? $GLOBALS['__opt'][ $key ] : $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $key, $value, $autoload = null ) {
		$GLOBALS['__opt'][ $key ]      = $value;
		$GLOBALS['__autoload'][ $key ] = $autoload;
		return true;
	}
}
if ( ! function_exists( 'add_option' ) ) {
	function add_option( $key, $value = '', $dep = '', $autoload = 'yes' ) {
		if ( array_key_exists( $key, $GLOBALS['__opt'] ) ) { return false; }
		$GLOBALS['__opt'][ $key ]      = $value;
		$GLOBALS['__autoload'][ $key ] = $autoload;
		return true;
	}
}
if ( ! function_exists( 'delete_option' ) ) { function delete_option( $k ) { unset( $GLOBALS['__opt'][ $k ] ); return true; } }

if ( ! function_exists( 'get_transient' ) ) { function get_transient( $k ) { return $GLOBALS['__tr'][ $k ] ?? false; } }
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $k, $v, $ttl = 0 ) {
		$GLOBALS['__tr'][ $k ]     = $v;
		$GLOBALS['__tr_ttl'][ $k ] = (int) $ttl;
		return true;
	}
}
if ( ! function_exists( 'delete_transient' ) ) { function delete_transient( $k ) { unset( $GLOBALS['__tr'][ $k ], $GLOBALS['__tr_ttl'][ $k ] ); return true; } }

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) {
		$args = func_get_args();
		array_shift( $args );
		if ( isset( $GLOBALS['__filters'][ $tag ] ) ) { return call_user_func_array( $GLOBALS['__filters'][ $tag ], $args ); }
		return $value;
	}
}

/** Cap-świadoma atrapa (§11.5) — właściciel vs gość rozstrzyga się po LIŚCIE capów. */
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $cap ) { return in_array( (string) $cap, (array) $GLOBALS['__caps'], true ); }
}

// Cache obiektowy (§7.3) — domyślnie WYŁĄCZONY, sekcja I go włącza.
if ( ! function_exists( 'wp_using_ext_object_cache' ) ) { function wp_using_ext_object_cache( $using = null ) { return (bool) $GLOBALS['__ext_cache']; } }
if ( ! function_exists( 'wp_cache_add' ) ) {
	function wp_cache_add( $k, $v, $g = '', $e = 0 ) {
		$key = $g . '|' . $k;
		if ( array_key_exists( $key, $GLOBALS['__objcache'] ) ) { return false; }
		$GLOBALS['__objcache'][ $key ] = $v;
		return true;
	}
}
if ( ! function_exists( 'wp_cache_get' ) ) {
	function wp_cache_get( $k, $g = '', $force = false, &$found = null ) {
		$key   = $g . '|' . $k;
		$found = array_key_exists( $key, $GLOBALS['__objcache'] );
		return $found ? $GLOBALS['__objcache'][ $key ] : false;
	}
}
if ( ! function_exists( 'wp_cache_incr' ) ) {
	function wp_cache_incr( $k, $offset = 1, $g = '' ) {
		$key = $g . '|' . $k;
		if ( ! array_key_exists( $key, $GLOBALS['__objcache'] ) || ! is_numeric( $GLOBALS['__objcache'][ $key ] ) ) { return false; }
		$GLOBALS['__objcache'][ $key ] = (int) $GLOBALS['__objcache'][ $key ] + (int) $offset;
		return $GLOBALS['__objcache'][ $key ];
	}
}
if ( ! function_exists( 'wp_cache_decr' ) ) {
	function wp_cache_decr( $k, $offset = 1, $g = '' ) {
		$key = $g . '|' . $k;
		if ( ! array_key_exists( $key, $GLOBALS['__objcache'] ) || ! is_numeric( $GLOBALS['__objcache'][ $key ] ) ) { return false; }
		$GLOBALS['__objcache'][ $key ] = max( 0, (int) $GLOBALS['__objcache'][ $key ] - (int) $offset );
		return $GLOBALS['__objcache'][ $key ];
	}
}
if ( ! function_exists( 'wp_cache_delete' ) ) { function wp_cache_delete( $k, $g = '' ) { unset( $GLOBALS['__objcache'][ $g . '|' . $k ] ); return true; } }

// Transport WP (nieużywany — provider dostaje własną atrapę; szyna musi jednak istnieć).
if ( ! function_exists( 'wp_remote_request' ) ) { function wp_remote_request( $u, $a = array() ) { return new WP_Error( 'aifaq_no_http', 'brak transportu w harnessie' ); } }
if ( ! function_exists( 'wp_remote_post' ) ) { function wp_remote_post( $u, $a = array() ) { return wp_remote_request( $u, $a ); } }
if ( ! function_exists( 'wp_remote_get' ) ) { function wp_remote_get( $u, $a = array() ) { return wp_remote_request( $u, $a ); } }
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) { function wp_remote_retrieve_response_code( $r ) { return (int) ( $r['response']['code'] ?? 0 ); } }
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) { function wp_remote_retrieve_body( $r ) { return (string) ( $r['body'] ?? '' ); } }
if ( ! function_exists( 'wp_remote_retrieve_headers' ) ) { function wp_remote_retrieve_headers( $r ) { return $r['headers'] ?? array(); } }

// ---------------------------------------------------------------------------
// Atrapa $wpdb — repozytoria budowane przez RagService::make() nie mogą paść.
// Wzorzec: krok19-rag-test.php (jeden wiersz z wektorem 3D pod ścieżkę make()).
// ---------------------------------------------------------------------------
class K20_Wpdb {
	public $prefix    = 'wp_';
	public $insert_id = 0;
	public $queries   = array();
	public $row       = null;   // gdy ustawione, get_row oddaje TO (np. trafienie cache)
	public function insert( $t, $d ) { $this->insert_id = 1; return 1; }
	public function delete( $t, $w, $f = null ) { return 1; }
	public function query( $sql ) { $this->queries[] = $sql; return 0; }
	public function prepare( $sql, ...$a ) { return $sql; }
	public function get_results( $sql, $o = null ) {
		$this->queries[] = $sql;
		return array( array( 'id' => 1, 'post_id' => 11, 'content' => 'Czesne wynosi 800 zl.', 'embedding' => json_encode( array( 0.1, 0.2, 0.3 ) ) ) );
	}
	public function get_row( $sql, $o = null ) {
		$this->queries[] = $sql;
		if ( null !== $this->row ) { return $this->row; }
		return array( 'chunks' => 1, 'posts' => 1, 'embedded' => 1 );
	}
	public function get_var( $sql ) { $this->queries[] = $sql; return 1; }
}
$GLOBALS['wpdb'] = new K20_Wpdb();

// ---------------------------------------------------------------------------
// Aparat asercji.
// ---------------------------------------------------------------------------
$fail = 0;
$ran  = 0;
function check( $cond, $label ) {
	global $fail, $ran;
	++$ran;
	echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n";
	if ( ! $cond ) { ++$fail; }
}

// ---------------------------------------------------------------------------
// Ładowanie kodu — ręczne require, każdy pod file_exists().
// ---------------------------------------------------------------------------
$k20_files = array(
	'src/Http/HttpClient.php',
	'src/Http/WpHttpClient.php',
	'src/Providers/ProviderInterface.php',
	'src/Providers/GeminiProvider.php',
	'src/Core/Settings.php',
	'src/Providers/ProviderFactory.php',
	'src/Data/Schema.php',
	'src/Data/Repository.php',
	'src/Data/KnowledgeRepository.php',
	'src/Data/CacheRepository.php',
	'src/Data/QaLogRepository.php',
	'src/Rag/Retriever.php',
	'src/Rag/RateLimiter.php',
	'src/Rag/TopicGuard.php',
	'src/Rag/Answerer.php',
	'src/Rag/RagService.php',
);
foreach ( $k20_files as $rel ) {
	$abs = AIFAQ_PLUGIN_DIR . $rel;
	if ( is_file( $abs ) ) { require_once $abs; }
}

$has_iface   = interface_exists( 'AIFAQ\Http\HttpClient' );
$has_prov    = $has_iface && class_exists( 'AIFAQ\Providers\GeminiProvider' );
$has_limiter = class_exists( 'AIFAQ\Rag\RateLimiter' );
$has_service = class_exists( 'AIFAQ\Rag\RagService' ) && class_exists( 'AIFAQ\Rag\Retriever' );
$has_factory = class_exists( 'AIFAQ\Providers\ProviderFactory' );

// ---------------------------------------------------------------------------
// Atrapy transportu i uśpienia (wzorzec K19_HttpSeq / K19_Sleeper).
// ---------------------------------------------------------------------------
if ( $has_iface ) {
	/** Transport z KOLEJKĄ odpowiedzi; po wyczerpaniu oddaje ostatnią. */
	class K20_HttpSeq implements \AIFAQ\Http\HttpClient {
		public $calls  = 0;
		public $urls   = array();
		private $queue;
		public function __construct( array $queue ) { $this->queue = $queue; }
		public function request( string $method, string $url, array $options = array() ) {
			++$this->calls;
			$this->urls[] = $url;
			$i            = min( $this->calls - 1, count( $this->queue ) - 1 );
			return $this->queue[ $i ];
		}
	}
}
/** Uśpienie wstrzykiwane 6. argumentem konstruktora — zapisuje, nie śpi. */
class K20_Sleeper {
	public $slept = array();
	public function __invoke( int $s ) { $this->slept[] = $s; }
}

function k20_resp( $status, $body ) { return array( 'status' => $status, 'body' => $body ); }
function k20_cand( $text = 'ok' ) {
	return json_encode( array( 'candidates' => array( array( 'finishReason' => 'STOP', 'content' => array( 'parts' => array( array( 'text' => $text ) ) ) ) ) ) );
}
function k20_embed_body( $dims = 3 ) {
	$v = array_slice( array( 0.1, 0.2, 0.3 ), 0, $dims );
	return json_encode( array( 'embeddings' => array( array( 'values' => $v ) ) ) );
}
function k20_reset_env() {
	$GLOBALS['__tr']      = array();
	$GLOBALS['__tr_ttl']  = array();
	$GLOBALS['__filters'] = array();
}
/** Provider „ścieżki gościa" (max_wait 5) albo „admina/reindeksu" (60). */
function k20_provider( $http, $model = 'gemini-2.5-flash', $embed_model = 'gemini-embedding-001', $sleeper = null, $max_wait = 60 ) {
	return new \AIFAQ\Providers\GeminiProvider( $http, 'K', $model, $embed_model, '', $sleeper, $max_wait );
}
/** quota_scope z last_meta() po zakończonym żądaniu. */
function k20_scope( $p ) {
	if ( ! method_exists( $p, 'last_meta' ) ) { return '__BRAK_last_meta__'; }
	$m = $p->last_meta();
	return array_key_exists( 'quota_scope', $m ) ? (string) $m['quota_scope'] : '__BRAK_KLUCZA__';
}

// ---------------------------------------------------------------------------
// FIXTURE'Y 429 — przepisane DOSŁOWNIE z `plany/krok19/ROZPOZNANIE-DOSTAWCY.md`.
// ---------------------------------------------------------------------------

/** §6c — 429 DOBOWY, `gemini-2.5-flash` (E0b): JEDNO naruszenie, `quotaValue` OBECNE. */
$K20_BODY_6C = <<<'JSON'
{
  "error": {
    "code": 429,
    "message": "You exceeded your current quota, please check your plan and billing details. For more information on this error, head to: https://ai.google.dev/gemini-api/docs/rate-limits. To monitor your current usage, head to: https://ai.dev/rate-limit. \n* Quota exceeded for metric: generativelanguage.googleapis.com/generate_content_free_tier_requests, limit: 20, model: gemini-2.5-flash\nPlease retry in 8.168268792s.",
    "status": "RESOURCE_EXHAUSTED",
    "details": [
      {
        "@type": "type.googleapis.com/google.rpc.Help",
        "links": [
          {
            "description": "Learn more about Gemini API quotas",
            "url": "https://ai.google.dev/gemini-api/docs/rate-limits"
          }
        ]
      },
      {
        "@type": "type.googleapis.com/google.rpc.QuotaFailure",
        "violations": [
          {
            "quotaMetric": "generativelanguage.googleapis.com/generate_content_free_tier_requests",
            "quotaId": "GenerateRequestsPerDayPerProjectPerModel-FreeTier",
            "quotaDimensions": {
              "model": "gemini-2.5-flash",
              "location": "global"
            },
            "quotaValue": "20"
          }
        ]
      },
      {
        "@type": "type.googleapis.com/google.rpc.RetryInfo",
        "retryDelay": "8s"
      }
    ]
  }
}
JSON;

/** §6a — `gemini-2.5-pro`, CZTERY naruszenia, BRAK `quotaValue`, PerMinute i PerDay razem. */
$K20_BODY_6A = <<<'JSON'
{
  "error": {
    "code": 429,
    "message": "You exceeded your current quota, please check your plan and billing details. For more information on this error, head to: https://ai.google.dev/gemini-api/docs/rate-limits. To monitor your current usage, head to: https://ai.dev/rate-limit. \n* Quota exceeded for metric: generativelanguage.googleapis.com/generate_content_free_tier_input_token_count, limit: 0, model: gemini-2.5-pro\nPlease retry in 32.594798742s.",
    "status": "RESOURCE_EXHAUSTED",
    "details": [
      {
        "@type": "type.googleapis.com/google.rpc.Help",
        "links": [
          {
            "description": "Learn more about Gemini API quotas",
            "url": "https://ai.google.dev/gemini-api/docs/rate-limits"
          }
        ]
      },
      {
        "@type": "type.googleapis.com/google.rpc.QuotaFailure",
        "violations": [
          {
            "quotaMetric": "generativelanguage.googleapis.com/generate_content_free_tier_input_token_count",
            "quotaId": "GenerateContentInputTokensPerModelPerDay-FreeTier",
            "quotaDimensions": {
              "location": "global",
              "model": "gemini-2.5-pro"
            }
          },
          {
            "quotaMetric": "generativelanguage.googleapis.com/generate_content_free_tier_input_token_count",
            "quotaId": "GenerateContentInputTokensPerModelPerMinute-FreeTier",
            "quotaDimensions": {
              "model": "gemini-2.5-pro",
              "location": "global"
            }
          },
          {
            "quotaMetric": "generativelanguage.googleapis.com/generate_content_free_tier_requests",
            "quotaId": "GenerateRequestsPerMinutePerProjectPerModel-FreeTier",
            "quotaDimensions": {
              "location": "global",
              "model": "gemini-2.5-pro"
            }
          },
          {
            "quotaMetric": "generativelanguage.googleapis.com/generate_content_free_tier_requests",
            "quotaId": "GenerateRequestsPerDayPerProjectPerModel-FreeTier",
            "quotaDimensions": {
              "model": "gemini-2.5-pro",
              "location": "global"
            }
          }
        ]
      },
      {
        "@type": "type.googleapis.com/google.rpc.RetryInfo",
        "retryDelay": "32s"
      }
    ]
  }
}
JSON;

/** §6b — `gemini-2.0-flash`, TRZY naruszenia, INNY porządek. */
$K20_BODY_6B = <<<'JSON'
{
  "error": {
    "code": 429,
    "message": "You exceeded your current quota, please check your plan and billing details. For more information on this error, head to: https://ai.google.dev/gemini-api/docs/rate-limits. To monitor your current usage, head to: https://ai.dev/rate-limit. \n* Quota exceeded for metric: generativelanguage.googleapis.com/generate_content_free_tier_requests, limit: 0, model: gemini-2.0-flash\nPlease retry in 52.447975594s.",
    "status": "RESOURCE_EXHAUSTED",
    "details": [
      {
        "@type": "type.googleapis.com/google.rpc.Help",
        "links": [
          {
            "description": "Learn more about Gemini API quotas",
            "url": "https://ai.google.dev/gemini-api/docs/rate-limits"
          }
        ]
      },
      {
        "@type": "type.googleapis.com/google.rpc.QuotaFailure",
        "violations": [
          {
            "quotaMetric": "generativelanguage.googleapis.com/generate_content_free_tier_input_token_count",
            "quotaId": "GenerateContentInputTokensPerModelPerMinute-FreeTier",
            "quotaDimensions": {
              "location": "global",
              "model": "gemini-2.0-flash"
            }
          },
          {
            "quotaMetric": "generativelanguage.googleapis.com/generate_content_free_tier_requests",
            "quotaId": "GenerateRequestsPerMinutePerProjectPerModel-FreeTier",
            "quotaDimensions": {
              "location": "global",
              "model": "gemini-2.0-flash"
            }
          },
          {
            "quotaMetric": "generativelanguage.googleapis.com/generate_content_free_tier_requests",
            "quotaId": "GenerateRequestsPerDayPerProjectPerModel-FreeTier",
            "quotaDimensions": {
              "model": "gemini-2.0-flash",
              "location": "global"
            }
          }
        ]
      },
      {
        "@type": "type.googleapis.com/google.rpc.RetryInfo",
        "retryDelay": "52s"
      }
    ]
  }
}
JSON;

/**
 * Ciała SYNTETYCZNE (O-51: fixture'a 429 MINUTOWEGO w projekcie nie ma i nie może być —
 * limitu minutowego nie zarejestrowano, ROZPOZNANIE-DOSTAWCY.md:232).
 */
function k20_quota_body( array $quota_ids, $retry = null, $with_value = false ) {
	$violations = array();
	foreach ( $quota_ids as $qid ) {
		$v = array( 'quotaMetric' => 'generativelanguage.googleapis.com/generate_content_free_tier_requests', 'quotaId' => $qid );
		if ( $with_value ) { $v['quotaValue'] = '20'; }
		$violations[] = $v;
	}
	$details = array(
		array( '@type' => 'type.googleapis.com/google.rpc.Help', 'links' => array() ),
		array( '@type' => 'type.googleapis.com/google.rpc.QuotaFailure', 'violations' => $violations ),
	);
	if ( null !== $retry ) { $details[] = array( '@type' => 'type.googleapis.com/google.rpc.RetryInfo', 'retryDelay' => $retry ); }
	return json_encode( array( 'error' => array( 'code' => 429, 'message' => 'limit', 'status' => 'RESOURCE_EXHAUSTED', 'details' => $details ) ) );
}
/** Ciało 429 BEZ QuotaFailure — gwarancja wsteczna (§8.2-bis). */
function k20_plain_body( $msg = 'limit', $retry = null ) {
	$e = array( 'code' => 429, 'message' => $msg );
	if ( null !== $retry ) {
		$e['details'] = array( array( '@type' => 'type.googleapis.com/google.rpc.RetryInfo', 'retryDelay' => $retry ) );
	}
	return json_encode( array( 'error' => $e ) );
}

$K20_META_KEYS = array(
	'finish_reason', 'truncated', 'empty_text', 'prompt_tokens', 'thoughts_tokens',
	'output_tokens', 'total_tokens', 'http_status', 'error_code', 'thinking_sent',
	'retries', 'model', 'quota_scope',
);

// ===========================================================================
echo "=== A. Parser quotaId — trzy surowe ciała 429 + przypadki brzegowe (§8.1) ===\n";
// ===========================================================================
if ( $has_prov ) {
	$scope_of = static function ( $body, $model = 'gemini-2.5-flash', $max_wait = 60 ) {
		k20_reset_env();
		$sl  = new K20_Sleeper();
		$seq = new K20_HttpSeq( array( k20_resp( 429, $body ), k20_resp( 200, k20_cand( 'ok' ) ) ) );
		$p   = k20_provider( $seq, $model, 'gemini-embedding-001', $sl, $max_wait );
		$p->generate( 'x', array( 'max_tokens' => 500 ) );
		return array( k20_scope( $p ), $seq, $sl, $p );
	};

	list( $s6c ) = $scope_of( $K20_BODY_6C );
	check( 'day' === $s6c, 'A1 (§6c, ciało DOSŁOWNE): quota_scope === day (jest: ' . $s6c . ')' );

	list( $s6a ) = $scope_of( $K20_BODY_6A, 'gemini-2.5-pro' );
	check( 'day' === $s6a, 'A2 (§6a, 4 naruszenia, PerMinute OBECNE): PerDay ma PIERWSZEŃSTWO → day (jest: ' . $s6a . ')' );

	list( $s6b ) = $scope_of( $K20_BODY_6B, 'gemini-2.0-flash' );
	check( 'day' === $s6b, 'A3 (§6b, 3 naruszenia, inny porządek): day (jest: ' . $s6b . ') — patrz O-51: ETAP-9b obiecywał tu „minute”, wszystkie trzy fixture’y są DOBOWE' );

	// Ścieżka `minute` — wyłącznie na ciele SYNTETYCZNYM (O-51).
	list( $s_min ) = $scope_of( k20_quota_body( array( 'GenerateRequestsPerMinutePerProjectPerModel-FreeTier' ), '7s' ) );
	check( 'minute' === $s_min, 'A4 (syntetyk, sam PerMinute): quota_scope === minute (jest: ' . $s_min . ')' );

	// Kolejność NIE decyduje — decyduje reguła pierwszeństwa.
	list( $s_mix1 ) = $scope_of( k20_quota_body( array( 'GenerateRequestsPerMinutePerProjectPerModel-FreeTier', 'GenerateRequestsPerDayPerProjectPerModel-FreeTier' ), '7s' ) );
	list( $s_mix2 ) = $scope_of( k20_quota_body( array( 'GenerateRequestsPerDayPerProjectPerModel-FreeTier', 'GenerateRequestsPerMinutePerProjectPerModel-FreeTier' ), '7s' ) );
	check( 'day' === $s_mix1, 'A5: PerMinute PRZED PerDay → nadal day (o werdykcie decyduje REGUŁA, nie kolejność)' );
	check( $s_mix1 === $s_mix2, 'A5-bis: odwrócona kolejność naruszeń → identyczny werdykt' );

	// 1, 3 i 4 naruszenia — ten sam werdykt.
	list( $s_1 ) = $scope_of( k20_quota_body( array( 'GenerateRequestsPerDayPerProjectPerModel-FreeTier' ) ) );
	list( $s_3 ) = $scope_of( k20_quota_body( array( 'GenerateContentInputTokensPerModelPerMinute-FreeTier', 'GenerateRequestsPerMinutePerProjectPerModel-FreeTier', 'GenerateRequestsPerDayPerProjectPerModel-FreeTier' ) ) );
	list( $s_4 ) = $scope_of( k20_quota_body( array( 'GenerateRequestsPerDayPerProjectPerModel-FreeTier', 'GenerateContentInputTokensPerModelPerMinute-FreeTier', 'GenerateRequestsPerMinutePerProjectPerModel-FreeTier', 'GenerateContentInputTokensPerModelPerDay-FreeTier' ) ) );
	check( 'day' === $s_1 && $s_1 === $s_3 && $s_3 === $s_4, 'A6: 1, 3 i 4 naruszenia w dowolnej kolejności → TEN SAM werdykt (day)' );

	// Brak `quotaValue` nie wywraca parsera (§6a/§6b go nie mają).
	list( $s_noval ) = $scope_of( k20_quota_body( array( 'GenerateRequestsPerDayPerProjectPerModel-FreeTier' ), null, false ) );
	list( $s_val )   = $scope_of( k20_quota_body( array( 'GenerateRequestsPerDayPerProjectPerModel-FreeTier' ), null, true ) );
	check( 'day' === $s_noval && $s_noval === $s_val, 'A7: BRAK pola quotaValue nie zmienia werdyktu (parser go nie potrzebuje)' );

	// Ciało bez quotaId → ''.
	list( $s_plain ) = $scope_of( k20_plain_body( 'limit', '7s' ) );
	check( '' === $s_plain, 'A8: ciało 429 BEZ quotaId → quota_scope === \'\' (jest: „' . $s_plain . '”)' );

	// Śmieciowe kształty — brak fatala.
	$ok_garbage = true;
	foreach ( array( '{"error":{"details":"nie-tablica"}}', '{"error":{"details":[{"@type":123}]}}', '{"error":{"details":[{"@type":"type.googleapis.com/google.rpc.QuotaFailure","violations":"x"}]}}', 'to nie jest JSON' ) as $bad ) {
		try { list( $sx ) = $scope_of( $bad ); } catch ( \Throwable $e ) { $ok_garbage = false; }
	}
	check( $ok_garbage, 'A9: śmieciowe/niepełne kształty error.details → brak fatala' );
} else {
	check( false, 'sekcja A pominięta — brak klasy AIFAQ\Providers\GeminiProvider' );
}

// ===========================================================================
echo "\n=== B. Zachowanie wg quota_scope (§8.2) ===\n";
// ===========================================================================
if ( $has_prov ) {
	// day → ZERO ponowień, retryDelay ZIGNOROWANY, cooldown TTL 3600.
	k20_reset_env();
	$sl  = new K20_Sleeper();
	$seq = new K20_HttpSeq( array( k20_resp( 429, $K20_BODY_6C ), k20_resp( 200, k20_cand( 'ok' ) ) ) );
	$p   = k20_provider( $seq, 'gemini-2.5-flash', 'gemini-embedding-001', $sl, 60 );
	$r   = $p->generate( 'x', array( 'max_tokens' => 500 ) );
	check( 1 === $seq->calls, 'B1 (day): DOKŁADNIE 1 próba, ZERO ponowień (jest: ' . $seq->calls . ')' );
	check( array() === $sl->slept, 'B2 (day): retryDelay „8s” ZIGNOROWANY — zero uśpień (jest: ' . json_encode( $sl->slept ) . ')' );
	check( is_wp_error( $r ) && 'aifaq_gemini_rate' === $r->get_error_code(), 'B3 (day): zwraca WP_Error aifaq_gemini_rate (kod bez zmian)' );
	$key_gen = 'aifaq_cooldown_generate_gemini-2.5-flash';
	check( isset( $GLOBALS['__tr'][ $key_gen ] ), 'B4 (day, §8.4): uzbrojony transient „' . $key_gen . '” (klucze: ' . implode( ',', array_keys( $GLOBALS['__tr'] ) ) . ')' );
	check( 3600 === (int) ( $GLOBALS['__tr_ttl'][ $key_gen ] ?? 0 ), 'B5 (day): TTL cooldownu === 3600 s (jest: ' . (int) ( $GLOBALS['__tr_ttl'][ $key_gen ] ?? 0 ) . ')' );
	$seq->calls = 0;
	$p->generate( 'y', array( 'max_tokens' => 500 ) );
	check( 0 === $seq->calls, 'B6 (day): drugie generate() w cooldownie NIE woła API (jest: ' . $seq->calls . ')' );

	// minute → do 3 prób, opóźnienie z ciała, TTL 60.
	k20_reset_env();
	$sl  = new K20_Sleeper();
	$min = k20_quota_body( array( 'GenerateRequestsPerMinutePerProjectPerModel-FreeTier' ), '7s' );
	$seq = new K20_HttpSeq( array( k20_resp( 429, $min ), k20_resp( 429, $min ), k20_resp( 429, $min ) ) );
	$p   = k20_provider( $seq, 'gemini-2.5-flash', 'gemini-embedding-001', $sl, 60 );
	$p->generate( 'x', array( 'max_tokens' => 500 ) );
	check( 3 === $seq->calls, 'B7 (minute): do 3 prób — DOKŁADNIE 3 (jest: ' . $seq->calls . ')' );
	check( array( 7, 7 ) === $sl->slept, 'B8 (minute): opóźnienie Z CIAŁA (7s), dwa uśpienia (jest: ' . json_encode( $sl->slept ) . ')' );
	check( 60 === (int) ( $GLOBALS['__tr_ttl'][ $key_gen ] ?? 0 ), 'B9 (minute): TTL cooldownu === 60 s (jest: ' . (int) ( $GLOBALS['__tr_ttl'][ $key_gen ] ?? 0 ) . ')' );

	// minute → 200: sukces po ponowieniu nie zostawia cooldownu.
	k20_reset_env();
	$sl  = new K20_Sleeper();
	$seq = new K20_HttpSeq( array( k20_resp( 429, $min ), k20_resp( 200, k20_cand( 'udalo sie' ) ) ) );
	$p   = k20_provider( $seq, 'gemini-2.5-flash', 'gemini-embedding-001', $sl, 60 );
	$r   = $p->generate( 'x', array( 'max_tokens' => 500 ) );
	check( 'udalo sie' === $r, 'B10 (minute): 429→200 zwraca tekst' );
	check( array() === $GLOBALS['__tr'], 'B11 (minute): sukces po ponowieniu → ZERO transientów cooldownu' );
} else {
	check( false, 'sekcja B pominięta — brak GeminiProvider' );
}

// ===========================================================================
echo "\n=== C. GWARANCJA WSTECZNA — ciało bez quotaId (§8.2-bis) ===\n";
// ===========================================================================
if ( $has_prov ) {
	// A25 — trzy próby.
	k20_reset_env();
	$sl  = new K20_Sleeper();
	$seq = new K20_HttpSeq( array( k20_resp( 429, k20_plain_body( 'a' ) ), k20_resp( 429, k20_plain_body( 'b' ) ), k20_resp( 429, k20_plain_body( 'c' ) ) ) );
	$p   = k20_provider( $seq, 'gemini-2.5-flash', 'gemini-embedding-001', $sl, 60 );
	$p->generate( 'x', array( 'max_tokens' => 500 ) );
	check( 3 === $seq->calls, 'C1 (A25): 429 bez quotaId ×3 → DOKŁADNIE 3 próby, jak w v0.22.0 (jest: ' . $seq->calls . ')' );
	check( 2 === (int) ( $p->last_meta()['retries'] ?? -1 ), 'C2 (A25): retries === 2' );
	check( array( 5, 15 ) === $sl->slept, 'C3 (A46): brak wskazówki → backoff array(5,15) (jest: ' . json_encode( $sl->slept ) . ')' );

	// A44 — RetryInfo "7s".
	k20_reset_env();
	$sl  = new K20_Sleeper();
	$seq = new K20_HttpSeq( array( k20_resp( 429, k20_plain_body( 'limit', '7s' ) ), k20_resp( 200, k20_cand( 'ok' ) ) ) );
	k20_provider( $seq, 'gemini-2.5-flash', 'gemini-embedding-001', $sl, 60 )->generate( 'x', array( 'max_tokens' => 500 ) );
	check( array( 7 ) === $sl->slept, 'C4 (A44): RetryInfo „7s” → uśpienie array(7) (jest: ' . json_encode( $sl->slept ) . ')' );

	// A45 — zdanie w komunikacie.
	k20_reset_env();
	$sl  = new K20_Sleeper();
	$seq = new K20_HttpSeq( array( k20_resp( 429, k20_plain_body( 'Please retry in 56.458591106s' ) ), k20_resp( 200, k20_cand( 'ok' ) ) ) );
	k20_provider( $seq, 'gemini-2.5-flash', 'gemini-embedding-001', $sl, 60 )->generate( 'x', array( 'max_tokens' => 500 ) );
	check( array( 56 ) === $sl->slept, 'C5 (A45): „Please retry in 56.458591106s” → array(56) (jest: ' . json_encode( $sl->slept ) . ')' );

	// A47 — bezsensowne opóźnienia.
	foreach ( array( '0s', 'abc' ) as $badd ) {
		k20_reset_env();
		$sl  = new K20_Sleeper();
		$seq = new K20_HttpSeq( array( k20_resp( 429, k20_plain_body( 'limit', $badd ) ), k20_resp( 200, k20_cand( 'ok' ) ) ) );
		k20_provider( $seq, 'gemini-2.5-flash', 'gemini-embedding-001', $sl, 60 )->generate( 'x', array( 'max_tokens' => 500 ) );
		check( array( 5 ) === $sl->slept, 'C6 (A47): RetryInfo „' . $badd . '” → backoff array(5) (jest: ' . json_encode( $sl->slept ) . ')' );
	}
} else {
	check( false, 'sekcja C pominięta — brak GeminiProvider' );
}

// ===========================================================================
echo "\n=== D. NAPRAWA WYŁĄCZNIKA OBWODU (§8.3) — NAJWAŻNIEJSZA SEKCJA PLIKU ===\n";
// ===========================================================================
// Sytuacja z §0.7, realna u gościa: sufit czekania 5 s (ProviderFactory ścieżki gościa),
// a KAŻDE zmierzone `retryDelay` (6..52 s) jest większe. Na v0.22.0 kod wychodził
// „nie warto czekać” PRZED `set_transient` → cooldown NIGDY się nie uzbrajał, więc każdy
// kolejny gość płacił jedno żądanie do wyczerpanej puli.
//
// MUTACJA DLA E10 (§10 pkt 3 „cooldown nieuzbrojony na ścieżce »nie warto czekać«”):
// przenieś `return` przed uzbrojenie cooldownu — pierwsza czerwona asercja: D2.
if ( $has_prov ) {
	k20_reset_env();
	$sl  = new K20_Sleeper();
	$seq = new K20_HttpSeq( array( k20_resp( 429, k20_plain_body( 'limit', '8s' ) ), k20_resp( 200, k20_cand( 'ok' ) ) ) );
	$p   = k20_provider( $seq, 'gemini-2.5-flash', 'gemini-embedding-001', $sl, 5 );   // ŚCIEŻKA GOŚCIA
	$r   = $p->generate( 'x', array( 'max_tokens' => 500 ) );
	$kg  = 'aifaq_cooldown_generate_gemini-2.5-flash';

	check( array() === $sl->slept, 'D1: max_wait=5 + retryDelay=8s → ZERO uśpień (regresja A46-bis)' );
	check( isset( $GLOBALS['__tr'][ $kg ] ), 'D2 (§8.3, SEDNO): po „nie warto czekać” cooldown MUSI ISTNIEĆ (klucze: ' . ( $GLOBALS['__tr'] ? implode( ',', array_keys( $GLOBALS['__tr'] ) ) : 'BRAK' ) . ')' );
	check( 60 === (int) ( $GLOBALS['__tr_ttl'][ $kg ] ?? 0 ), 'D3 (§8.2-bis): quota_scope=\'\' → TTL 60 s (jest: ' . (int) ( $GLOBALS['__tr_ttl'][ $kg ] ?? 0 ) . ')' );
	check( is_wp_error( $r ) && 'aifaq_gemini_rate' === $r->get_error_code(), 'D4: natychmiastowy aifaq_gemini_rate zamiast czekania' );
	$seq->calls = 0;
	$p->generate( 'y', array( 'max_tokens' => 500 ) );
	check( 0 === $seq->calls, 'D5: DRUGI gość w cooldownie → ZERO żądań do API (jest: ' . $seq->calls . ') — to jest cała wartość naprawy' );

	// Ta sama ścieżka, ale z quotaId PerDay → TTL godzinny.
	k20_reset_env();
	$sl  = new K20_Sleeper();
	$seq = new K20_HttpSeq( array( k20_resp( 429, $K20_BODY_6C ), k20_resp( 200, k20_cand( 'ok' ) ) ) );
	k20_provider( $seq, 'gemini-2.5-flash', 'gemini-embedding-001', $sl, 5 )->generate( 'x', array( 'max_tokens' => 500 ) );
	check( 3600 === (int) ( $GLOBALS['__tr_ttl'][ $kg ] ?? 0 ), 'D6: ścieżka gościa + limit DOBOWY → TTL 3600 s (jest: ' . (int) ( $GLOBALS['__tr_ttl'][ $kg ] ?? 0 ) . ')' );

	// O-53: ta sama ścieżka odpala się także po 503 — i to jest ZGODNE z §8.2-bis
	// („uzbrojenie obowiązuje dla KAŻDEGO quota_scope, w tym ''”). Asercja odwrotna
	// („503 → brak transientu”) zaczerwieniłaby POPRAWNY kod — patrz ODCHYLENIA O-53.
	k20_reset_env();
	$sl  = new K20_Sleeper();
	$seq = new K20_HttpSeq( array( k20_resp( 503, k20_plain_body( 'busy', '30s' ) ), k20_resp( 200, k20_cand( 'ok' ) ) ) );
	$r3  = k20_provider( $seq, 'gemini-2.5-flash', 'gemini-embedding-001', $sl, 5 )->generate( 'x', array( 'max_tokens' => 500 ) );
	check( is_wp_error( $r3 ) && 'aifaq_gemini_busy' === $r3->get_error_code(), 'D7 (O-53): 503 → kod aifaq_gemini_busy bez zmian' );
	check( isset( $GLOBALS['__tr'][ $kg ] ) && 60 === (int) $GLOBALS['__tr_ttl'][ $kg ], 'D8 (O-53, §8.2-bis): 503 na ścieżce gościa też uzbraja cooldown, TTL 60' );
} else {
	check( false, 'sekcja D pominięta — brak GeminiProvider' );
}

// ===========================================================================
echo "\n=== E. Rozdzielność pul embed / generate (§8.4, §13.13) ===\n";
// ===========================================================================
// E0b dowiódł POMIAREM: 429 na generateContent o 17:57:09, embed() OK o 17:57:10.
// Jeden globalny transient wyłączałby indeksowanie, które DZIAŁA.
if ( $has_prov ) {
	$k_gen = 'aifaq_cooldown_generate_gemini-2.5-flash';
	$k_emb = 'aifaq_cooldown_embed_gemini-embedding-001';

	// 1. 429 (day) na generate → embed() PRZECHODZI.
	k20_reset_env();
	$sl  = new K20_Sleeper();
	$seq = new K20_HttpSeq( array( k20_resp( 429, $K20_BODY_6C ), k20_resp( 200, k20_embed_body() ) ) );
	$p   = k20_provider( $seq, 'gemini-2.5-flash', 'gemini-embedding-001', $sl, 60 );
	$p->generate( 'x', array( 'max_tokens' => 500 ) );
	$calls_before = $seq->calls;
	$vec          = $p->embed( array( 'tekst' ) );
	check( isset( $GLOBALS['__tr'][ $k_gen ] ), 'E1: cooldown puli GENERATE uzbrojony pod kluczem z modelem generacji' );
	check( ! isset( $GLOBALS['__tr'][ $k_emb ] ), 'E2: pula EMBED pozostaje WOLNA (brak klucza „' . $k_emb . '”)' );
	check( $seq->calls === $calls_before + 1, 'E3: embed() realnie wyszedł do API mimo cooldownu generacji (jest: ' . ( $seq->calls - $calls_before ) . ' żądań)' );
	check( is_array( $vec ) && ! is_wp_error( $vec ), 'E4: embed() zwrócił wektor, nie błąd — klient z wyczerpaną pulą ODPOWIEDZI nadal indeksuje' );

	// 2. Odwrotnie: 429 (day) na embed → generate() PRZECHODZI.
	k20_reset_env();
	$sl  = new K20_Sleeper();
	$seq = new K20_HttpSeq( array( k20_resp( 429, $K20_BODY_6C ), k20_resp( 200, k20_cand( 'odpowiedz' ) ) ) );
	$p   = k20_provider( $seq, 'gemini-2.5-flash', 'gemini-embedding-001', $sl, 60 );
	$p->embed( array( 'tekst' ) );
	check( isset( $GLOBALS['__tr'][ $k_emb ] ), 'E5: cooldown puli EMBED uzbrojony pod kluczem z modelem EMBEDDINGÓW (§13.13)' );
	check( ! isset( $GLOBALS['__tr'][ $k_gen ] ), 'E6: pula GENERATE pozostaje wolna po 429 z embed()' );
	check( 'odpowiedz' === $p->generate( 'x', array( 'max_tokens' => 500 ) ), 'E7: generate() przechodzi mimo cooldownu embeddingów' );

	// 3. Model W NAZWIE klucza — dwa modele generacji nie dzielą wyłącznika.
	k20_reset_env();
	$sl  = new K20_Sleeper();
	$seq = new K20_HttpSeq( array( k20_resp( 429, $K20_BODY_6C ) ) );
	k20_provider( $seq, 'gemini-2.5-pro', 'gemini-embedding-001', $sl, 60 )->generate( 'x', array( 'max_tokens' => 500 ) );
	check( isset( $GLOBALS['__tr']['aifaq_cooldown_generate_gemini-2.5-pro'] ), 'E8: klucz zawiera MODEL — „aifaq_cooldown_generate_gemini-2.5-pro”' );
	check( ! isset( $GLOBALS['__tr'][ $k_gen ] ), 'E9: wyczerpanie 2.5-pro NIE blokuje 2.5-flash' );
	check( ! isset( $GLOBALS['__tr']['aifaq_provider_cooldown'] ), 'E10 (§8.4): STARY, globalny klucz aifaq_provider_cooldown już nie powstaje' );
} else {
	check( false, 'sekcja E pominięta — brak GeminiProvider' );
}

// ===========================================================================
echo "\n=== F. last_meta() — 13 kluczy (§8.5, §13.14) ===\n";
// ===========================================================================
if ( $has_prov && method_exists( 'AIFAQ\Providers\GeminiProvider', 'last_meta' ) ) {
	k20_reset_env();
	$seq = new K20_HttpSeq( array( k20_resp( 200, k20_cand( 'ok' ) ) ) );
	$p   = k20_provider( $seq );
	$m   = $p->last_meta();
	check( 13 === count( $m ), 'F1: last_meta() ma DOKŁADNIE 13 kluczy PRZED pierwszym żądaniem (jest: ' . count( $m ) . ')' );
	check( array_key_exists( 'quota_scope', $m ), 'F2: klucz quota_scope obecny' );
	check( '' === (string) $m['quota_scope'], 'F3 (§13.14): domyślna wartość quota_scope === \'\'' );
	check( array() === array_diff( array_keys( $m ), $K20_META_KEYS ), 'F4: brak kluczy NADMIAROWYCH (porównanie w obie strony)' );
	check( array() === array_diff( $K20_META_KEYS, array_keys( $m ) ), 'F5: brak kluczy BRAKUJĄCYCH (porównanie w obie strony)' );

	// Reset na starcie każdego żądania — także embed() (§13.14).
	k20_reset_env();
	$seq = new K20_HttpSeq( array( k20_resp( 429, $K20_BODY_6C ), k20_resp( 200, k20_embed_body() ) ) );
	$p   = k20_provider( $seq, 'gemini-2.5-flash', 'gemini-embedding-002', new K20_Sleeper(), 60 );
	$p->generate( 'x', array( 'max_tokens' => 500 ) );
	check( 'day' === (string) $p->last_meta()['quota_scope'], 'F6: po 429 dobowym quota_scope === day' );
	$p->embed( array( 'tekst' ) );
	check( '' === (string) $p->last_meta()['quota_scope'], 'F7 (§13.14): udany embed() ZERUJE quota_scope (reset na starcie KAŻDEGO żądania)' );
	check( 13 === count( $p->last_meta() ), 'F8: liczba kluczy nie rośnie po żądaniach (nadal 13)' );
} else {
	check( false, 'sekcja F pominięta — brak GeminiProvider::last_meta()' );
}

// ===========================================================================
echo "\n=== G. RateLimiter: okno, klucz, dziedziczenie, refund() (§7.1, §7.2) ===\n";
// ===========================================================================
if ( $has_limiter ) {
	$ip   = str_repeat( 'a', 64 );
	$key  = 'aifaq_rl_' . $ip;
	$base = 1784000000 - ( 1784000000 % 86400 ) + 600;   // 00:10:00 UTC — daleko od granicy doby
	$t    = $base;
	$clk  = static function () use ( &$t ) { return $t; };

	// G1 — konstrukcja BEZ argumentu okna (regresja: krok6-rag-test.php:189 podaje dwa argumenty).
	k20_reset_env();
	$ok_ctor = true;
	try { $rl = new \AIFAQ\Rag\RateLimiter( 3, $clk ); } catch ( \Throwable $e ) { $ok_ctor = false; }
	check( $ok_ctor, 'G1 (FZ27): konstruktor DWUARGUMENTOWY nadal działa (okno jest TRZECIM parametrem)' );

	check( true === $rl->allow( $ip ), 'G2: przed pierwszym trafieniem → allow' );
	$rl->hit( $ip ); $rl->hit( $ip ); $rl->hit( $ip );
	check( false === $rl->allow( $ip ), 'G3: po 3 trafieniach przy limicie 3 → blokada' );

	// G4 — kształt zapisu: JEDEN wiersz, identyfikator okna w WARTOŚCI (FZ28).
	$keys = array_keys( $GLOBALS['__tr'] );
	check( array( $key ) === $keys, 'G4 (FZ28): DOKŁADNIE JEDEN transient o STAŁYM kluczu „aifaq_rl_<ip_hash>” (jest: ' . implode( ',', $keys ) . ')' );
	$val = $GLOBALS['__tr'][ $key ] ?? null;
	check( is_array( $val ) && array_key_exists( 'window', $val ) && array_key_exists( 'count', $val ), 'G5 (FZ28): wartość to tablica z kluczami window i count' );
	check( 3 === (int) ( $val['count'] ?? -1 ), 'G6: count === 3 (jest: ' . var_export( $val['count'] ?? null, true ) . ')' );
	check( is_string( $val['window'] ?? null ) && '' !== (string) $val['window'], 'G7: identyfikator okna jest niepustym łańcuchem (kotwica kalendarzowa)' );

	// G8 — przeskok okna zeruje licznik, ale NIE mnoży wierszy.
	$t += 3601;
	check( true === $rl->allow( $ip ), 'G8: po przekroczeniu okna godzinnego → znów allow (licznik wyzerowany)' );
	$rl->hit( $ip );
	check( array( $key ) === array_keys( $GLOBALS['__tr'] ), 'G9 (FZ28): nowe okno NIE tworzy drugiego wiersza wp_options (klucz nadal jeden)' );
	check( 1 === (int) $GLOBALS['__tr'][ $key ]['count'], 'G10: w nowym oknie count === 1' );

	// G11 — okno DOBOWE (86400): +3601 s nie resetuje, zmiana doby resetuje.
	k20_reset_env();
	$t     = $base;
	$rl_d  = new \AIFAQ\Rag\RateLimiter( 2, $clk, 86400 );
	$rl_d->hit( $ip ); $rl_d->hit( $ip );
	check( false === $rl_d->allow( $ip ), 'G11 (okno dobowe): po 2 trafieniach przy limicie 2 → blokada' );
	$t += 3601;
	check( false === $rl_d->allow( $ip ), 'G12 (okno dobowe): +1 godzina NIE resetuje licznika (to nie jest okno godzinne)' );
	$t += 86400;
	check( true === $rl_d->allow( $ip ), 'G13 (okno dobowe): zmiana DOBY zeruje licznik' );

	// G14 — klasa DZIEDZICZĄCA nadpisująca allow() (krok6-rag-test.php:110-112) nadal działa.
	if ( ! class_exists( 'K20_DenyLimiter' ) ) {
		class K20_DenyLimiter extends \AIFAQ\Rag\RateLimiter {
			public function __construct() { parent::__construct( 1 ); }
			public function allow( string $ip_hash ): bool { return false; }
		}
	}
	if ( ! class_exists( 'K20_ClockLimiter' ) ) {
		class K20_ClockLimiter extends \AIFAQ\Rag\RateLimiter {
			public function __construct( $clock ) { parent::__construct( 5, $clock ); }
			public function allow( string $ip_hash ): bool { return true; }
		}
	}
	k20_reset_env();
	$ok_sub = true;
	$deny   = null;
	try { $deny = new K20_DenyLimiter(); } catch ( \Throwable $e ) { $ok_sub = false; }
	check( $ok_sub, 'G14 (§7.1): podklasa z parent::__construct( 1 ) konstruuje się bez TypeError' );
	check( $deny instanceof \AIFAQ\Rag\RateLimiter && false === $deny->allow( $ip ), 'G15 (§7.1): nadpisane allow() działa (scalenie w consume() byłoby Fatalem)' );
	$ok_sub2 = true;
	try { $c2 = new K20_ClockLimiter( $clk ); $c2->hit( $ip ); } catch ( \Throwable $e ) { $ok_sub2 = false; }
	check( $ok_sub2, 'G16 (§7.1): podklasa z parent::__construct( int, callable ) też działa (wzorzec krok19-rag-test.php:288-291)' );

	// G17 — refund(): zwraca jednostkę, nigdy poniżej zera, nie tworzy okna.
	check( method_exists( 'AIFAQ\Rag\RateLimiter', 'refund' ), 'G17 (§7.1): metoda refund() istnieje' );
	if ( method_exists( 'AIFAQ\Rag\RateLimiter', 'refund' ) ) {
		k20_reset_env();
		$t   = $base;
		$rl2 = new \AIFAQ\Rag\RateLimiter( 3, $clk );
		$rl2->hit( $ip ); $rl2->hit( $ip ); $rl2->hit( $ip );
		check( false === $rl2->allow( $ip ), 'G18: warunek wstępny — po 3 trafieniach blokada' );
		$rl2->refund( $ip );
		check( true === $rl2->allow( $ip ), 'G19 (§7.1): refund() przywraca jednostkę → znów allow' );
		check( 2 === (int) $GLOBALS['__tr'][ $key ]['count'], 'G20: count po zwrocie === 2 (jest: ' . var_export( $GLOBALS['__tr'][ $key ]['count'] ?? null, true ) . ')' );

		$rl2->refund( $ip ); $rl2->refund( $ip ); $rl2->refund( $ip ); $rl2->refund( $ip );
		check( 0 === (int) $GLOBALS['__tr'][ $key ]['count'], 'G21 (§13.11): refund() przy zerze jest NO-OPem — nigdy wartość ujemna (jest: ' . var_export( $GLOBALS['__tr'][ $key ]['count'] ?? null, true ) . ')' );

		k20_reset_env();
		$rl3 = new \AIFAQ\Rag\RateLimiter( 3, $clk );
		$rl3->refund( $ip );
		check( array() === $GLOBALS['__tr'], 'G22 (§13.11): refund() BEZ istniejącego wpisu NIE tworzy nowego okna (zero transientów)' );
	}

	// G23 — limit 0 = wyłącznik (regresja K6).
	k20_reset_env();
	$rl0 = new \AIFAQ\Rag\RateLimiter( 0, $clk );
	$rl0->hit( $ip ); $rl0->hit( $ip ); $rl0->hit( $ip );
	check( true === $rl0->allow( $ip ), 'G23: limit 0 = limiter wyłączony → zawsze allow' );

	// G24 — WINDOW zostaje wartością domyślną (§13.10).
	check( ! defined( 'AIFAQ\Rag\RateLimiter::WINDOW' ) || 3600 === (int) constant( 'AIFAQ\Rag\RateLimiter::WINDOW' ), 'G24 (§13.10): stała WINDOW nadal 3600 (jeśli istnieje)' );
} else {
	check( false, 'sekcja G pominięta — brak klasy AIFAQ\Rag\RateLimiter' );
}

// ===========================================================================
echo "\n=== H. Dobowy sufit witryny (§7.4, §13.12) ===\n";
// ===========================================================================
// O-61: sufit czyta wartość z konfiguracji budowanej w `RagService::make()`, więc
// testujemy PRZEZ `make()`. Serwis zbudowany wprost konstruktorem dostaje
// `daily_budget` nieustawiony → sufit nieaktywny (i asercja odwrotna byłaby fałszywa).
if ( $has_service && $has_factory && method_exists( 'AIFAQ\Rag\RagService', 'make' ) && method_exists( 'AIFAQ\Providers\ProviderFactory', 'set_override' ) ) {

	/** Provider-atrapa z `last_meta()` (warunek zwrotu jednostki, FZ26). */
	if ( ! class_exists( 'K20_Provider' ) ) {
		class K20_Provider implements \AIFAQ\Providers\ProviderInterface {
			public $calls       = 0;
			public $embed_calls = 0;
			public $reply;
			public $embed_reply;
			public $status      = 200;
			public function __construct( $reply = 'odpowiedz modelu', $embed_reply = null ) {
				$this->reply       = $reply;
				$this->embed_reply = ( null === $embed_reply ) ? array( array( 0.1, 0.2, 0.3 ) ) : $embed_reply;
			}
			public function generate( string $prompt, array $options = array() ) { ++$this->calls; return $this->reply; }
			public function embed( array $texts ) { ++$this->embed_calls; return $this->embed_reply; }
			public function verify() { return true; }
			public function model() { return 'gemini-2.5-flash'; }
			public function last_meta() {
				return array(
					'finish_reason' => 'STOP', 'truncated' => false, 'empty_text' => false,
					'prompt_tokens' => 1, 'thoughts_tokens' => 0, 'output_tokens' => 1, 'total_tokens' => 2,
					'http_status' => (int) $this->status, 'error_code' => '', 'thinking_sent' => -1,
					'retries' => 0, 'model' => 'gemini-2.5-flash', 'quota_scope' => '',
				);
			}
		}
	}

	$ask = static function ( array $settings, array $caps, $ip = 'iphashA', $provider = null ) {
		$GLOBALS['__caps'] = $caps;
		$GLOBALS['__opt']['aifaq_settings'] = array_merge(
			array(
				'api_key'        => 'K',
				'model'          => 'gemini-2.5-flash',
				'embed_model'    => 'gemini-embedding-001',
				'language'       => 'pl',
				'rag_top_k'      => 5,
				'rag_rate_limit' => 100,   // limiter gościa świadomie ODSUNIĘTY — badamy SUFIT
			),
			$settings
		);
		$prov = $provider ?: new K20_Provider( 'ok' );
		\AIFAQ\Providers\ProviderFactory::set_override( $prov );
		$out = \AIFAQ\Rag\RagService::make()->ask( 'Ile kosztuje czesne?', $ip );
		\AIFAQ\Providers\ProviderFactory::set_override( null );
		return array( $out, $prov );
	};

	// --- H1..H6: gość, budżet 2 ---
	k20_reset_env();
	unset( $GLOBALS['__opt']['aifaq_daily_usage'], $GLOBALS['__opt']['aifaq_budget_hit'] );
	$GLOBALS['__now'] = 1784000000;

	list( $o1, $p1 ) = $ask( array( 'rag_daily_budget' => 2 ), array() );
	check( 'rate_limit' !== ( $o1['source'] ?? '' ), 'H1: pierwsze pytanie gościa przy budżecie 2 → PRZECHODZI (source: ' . ( $o1['source'] ?? '?' ) . ')' );
	$usage1 = $GLOBALS['__opt']['aifaq_daily_usage'] ?? null;
	check( is_array( $usage1 ) && 1 === (int) ( $usage1['n'] ?? 0 ), 'H2 (§2.2): aifaq_daily_usage n === 1 po jednym pytaniu (jest: ' . var_export( $usage1['n'] ?? null, true ) . ')' );
	check( is_array( $usage1 ) && (string) current_time( 'Y-m-d' ) === (string) ( $usage1['d'] ?? '' ), 'H3 (§2.2): klucz d === bieżąca data z current_time( Y-m-d )' );
	check( false === ( $GLOBALS['__autoload']['aifaq_daily_usage'] ?? null ), 'H4 (§2.2): licznik zapisany z autoload = false' );

	list( $o2 ) = $ask( array( 'rag_daily_budget' => 2 ), array() );
	check( 'rate_limit' !== ( $o2['source'] ?? '' ), 'H5: drugie pytanie (budżet 2) → nadal przechodzi' );
	check( 2 === (int) ( $GLOBALS['__opt']['aifaq_daily_usage']['n'] ?? 0 ), 'H6: n === 2' );

	// Trzecie — sufit zamyka.
	$tr_before = $GLOBALS['__tr'];
	list( $o3, $p3 ) = $ask( array( 'rag_daily_budget' => 2 ), array() );
	check( 'rate_limit' === ( $o3['source'] ?? '' ), 'H7 (§7.4): przekroczenie sufitu → source === rate_limit (ISTNIEJĄCA wartość, nie nowa; jest: ' . ( $o3['source'] ?? '?' ) . ')' );
	check( 'error' === ( $o3['status'] ?? '' ), 'H8 (§7.4): status === error (mapowanie na HTTP 429 bez zmian w REST)' );
	check( 0 === (int) $p3->embed_calls, 'H9 (§7.4): po odbiciu na sufcie embed() NIE jest wołany (bramka stoi PRZED embed)' );
	check( 2 === (int) ( $GLOBALS['__opt']['aifaq_daily_usage']['n'] ?? 0 ), 'H10 (§7.4): odbicie NIE zwiększa licznika witryny (nadal 2)' );
	$ipk = 'aifaq_rl_iphashA';
	check( (int) ( $tr_before[ $ipk ]['count'] ?? 0 ) === (int) ( $GLOBALS['__tr'][ $ipk ]['count'] ?? 0 ), 'H11 (O-63): odbicie na SUFICIE nie zjada jednostki GOŚCIA (licznik gościa bez zmian)' );

	// Właściciel przechodzi mimo wyczerpania, ale INKREMENTUJE licznik (§13.12).
	list( $o4 ) = $ask( array( 'rag_daily_budget' => 2 ), array( 'manage_options' ), 'iphashOwner' );
	check( 'rate_limit' !== ( $o4['source'] ?? '' ), 'H12 (§2.2): właściciel (manage_options) przechodzi MIMO wyczerpania sufitu (source: ' . ( $o4['source'] ?? '?' ) . ')' );
	check( 3 === (int) ( $GLOBALS['__opt']['aifaq_daily_usage']['n'] ?? 0 ), 'H13 (§13.12): pytanie właściciela ZWIĘKSZA licznik witryny (n === 3; jest: ' . var_export( $GLOBALS['__opt']['aifaq_daily_usage']['n'] ?? null, true ) . ') — „licznik, który ich nie widzi, kłamie”' );

	// Sygnalizacja dla właściciela (FZ30, format wg O-22).
	check( (string) current_time( 'Y-m-d' ) === (string) ( $GLOBALS['__opt']['aifaq_budget_hit'] ?? '' ), 'H14 (FZ30, O-22): aifaq_budget_hit === data RRRR-MM-DD z current_time (jest: ' . var_export( $GLOBALS['__opt']['aifaq_budget_hit'] ?? null, true ) . ')' );
	check( false === ( $GLOBALS['__autoload']['aifaq_budget_hit'] ?? null ), 'H15 (FZ30): aifaq_budget_hit zapisane z autoload = false' );

	// Zmiana doby zeruje licznik.
	k20_reset_env();
	$GLOBALS['__now'] += 86400;
	list( $o5 ) = $ask( array( 'rag_daily_budget' => 2 ), array(), 'iphashB' );
	check( 'rate_limit' !== ( $o5['source'] ?? '' ), 'H16 (§2.2): nowa doba → licznik startuje od zera, gość znów przechodzi' );
	check( 1 === (int) ( $GLOBALS['__opt']['aifaq_daily_usage']['n'] ?? 0 ), 'H17: po zmianie doby n === 1' );

	// Budżet 0 → sufit nieaktywny i ZERO odczytów opcji.
	k20_reset_env();
	unset( $GLOBALS['__opt']['aifaq_daily_usage'] );
	$GLOBALS['__opt_reads'] = array();
	list( $o6 ) = $ask( array( 'rag_daily_budget' => 0 ), array(), 'iphashC' );
	check( 'rate_limit' !== ( $o6['source'] ?? '' ), 'H18 (§7.4): rag_daily_budget = 0 → sufit wyłączony' );
	check( 0 === (int) ( $GLOBALS['__opt_reads']['aifaq_daily_usage'] ?? 0 ), 'H19 (§7.4): przy budżecie 0 — ZERO odczytów opcji aifaq_daily_usage (jest: ' . (int) ( $GLOBALS['__opt_reads']['aifaq_daily_usage'] ?? 0 ) . ')' );
	check( ! isset( $GLOBALS['__opt']['aifaq_daily_usage'] ), 'H20: przy budżecie 0 licznik w ogóle nie powstaje' );

	// Trafienie cache nie zjada ani jednostki gościa, ani witryny (§7.4, kolejność bramek).
	k20_reset_env();
	unset( $GLOBALS['__opt']['aifaq_daily_usage'] );
	$GLOBALS['wpdb']->row = array( 'id' => 1, 'question' => 'Ile kosztuje czesne?', 'answer' => 'Odpowiedz z cache' );
	list( $o7, $p7 )      = $ask( array( 'rag_daily_budget' => 2 ), array(), 'iphashD' );
	$GLOBALS['wpdb']->row = null;
	check( 'cache' === ( $o7['source'] ?? '' ), 'H21 (warunek wstępny): trafienie w cache (source: ' . ( $o7['source'] ?? '?' ) . ')' );
	check( 0 === (int) $p7->embed_calls, 'H22: trafienie cache nie woła embed()' );
	check( ! isset( $GLOBALS['__opt']['aifaq_daily_usage'] ), 'H23 (§7.4): trafienie cache NIE zjada jednostki witryny (licznik nie powstaje)' );
	check( ! isset( $GLOBALS['__tr']['aifaq_rl_iphashD'] ), 'H24 (§7.4): trafienie cache NIE zjada jednostki gościa (brak wpisu limitera)' );

	// -----------------------------------------------------------------------
	// H25..H30 — BRAMKA ZWROTU JEDNOSTKI (FZ26): `http_status !== 0`.
	//
	// DOPISANE PRZEZ E10 przy weryfikacji negatywnej. Mutacja obowiązkowa §10 pkt 3
	// „refund() wołany przy błędzie z wyłącznika obwodu" (usunięcie warunku
	// `0 === $meta['http_status']` w `RagService::refund_unit()`) NIE ZACZERWIENIŁA
	// ani jednej asercji w całym runnerze — czyli była to dziura w pokryciu, nie
	// nadmiarowy wymóg. Stawka jest realna: po naprawie wyłącznika obwodu (obszar F)
	// dostawca oddaje WP_Error BEZ wyjścia do sieci nawet przez 3600 s. Gdyby przy tym
	// błędzie zwracać jednostkę, żaden licznik nie rośnie, a każde żądanie i tak robi
	// SELECT cache'u i INSERT do qa_log — bot dostaje nielimitowany endpoint piszący
	// do bazy klienta.
	if ( ! class_exists( 'K20_FailProvider' ) ) {
		class K20_FailProvider extends K20_Provider {
			/** @var int Status HTTP raportowany w last_meta(); 0 = żądanie NIE opuściło procesu. */
			public $fail_status = 0;
			public function __construct( $fail_status = 0 ) {
				parent::__construct( 'ok' );
				$this->fail_status = (int) $fail_status;
				$this->status      = (int) $fail_status;
			}
			public function embed( array $texts ) {
				++$this->embed_calls;
				return new WP_Error( 'aifaq_gemini_rate', 'Wylacznik obwodu / limit dostawcy.' );
			}
		}
	}

	// H25..H27 — wyłącznik obwodu: http_status = 0, żądanie NIE opuściło procesu.
	k20_reset_env();
	unset( $GLOBALS['__opt']['aifaq_daily_usage'], $GLOBALS['__opt']['aifaq_budget_hit'] );
	list( $o8, $p8 ) = $ask( array( 'rag_daily_budget' => 5, 'rag_rate_limit' => 3 ), array(), 'iphashE', new K20_FailProvider( 0 ) );
	check( 1 === (int) $p8->embed_calls, 'H25 (warunek wstępny): embed() wołany raz i oddał WP_Error' );
	check( 1 === (int) ( $GLOBALS['__tr']['aifaq_rl_iphashE']['count'] ?? 0 ), 'H26 (FZ26): błąd z wyłącznika obwodu (http_status = 0) NIE zwraca jednostki GOŚCIA — licznik zostaje 1 (jest: ' . var_export( $GLOBALS['__tr']['aifaq_rl_iphashE']['count'] ?? null, true ) . ')' );
	check( 1 === (int) ( $GLOBALS['__opt']['aifaq_daily_usage']['n'] ?? 0 ), 'H27 (FZ26): ten sam błąd NIE zwraca jednostki WITRYNY — n zostaje 1 (jest: ' . var_export( $GLOBALS['__opt']['aifaq_daily_usage']['n'] ?? null, true ) . ')' );

	// H28..H30 — para dodatnia: realny błąd sieci (żądanie WYSZŁO) zwraca jednostkę.
	// Bez tej pary H26/H27 przechodziłyby także wtedy, gdyby zwrot nie działał NIGDY.
	k20_reset_env();
	unset( $GLOBALS['__opt']['aifaq_daily_usage'], $GLOBALS['__opt']['aifaq_budget_hit'] );
	list( $o9, $p9 ) = $ask( array( 'rag_daily_budget' => 5, 'rag_rate_limit' => 3 ), array(), 'iphashF', new K20_FailProvider( 502 ) );
	check( 1 === (int) $p9->embed_calls, 'H28 (warunek wstępny): embed() wołany raz i oddał WP_Error (http_status 502)' );
	check( 0 === (int) ( $GLOBALS['__tr']['aifaq_rl_iphashF']['count'] ?? -1 ), 'H29 (FZ26, para dodatnia): błąd po REALNYM żądaniu (502) ZWRACA jednostkę gościa — licznik wraca do 0 (jest: ' . var_export( $GLOBALS['__tr']['aifaq_rl_iphashF']['count'] ?? null, true ) . ')' );
	check( 0 === (int) ( $GLOBALS['__opt']['aifaq_daily_usage']['n'] ?? -1 ), 'H30 (FZ26, para dodatnia): błąd po REALNYM żądaniu ZWRACA jednostkę witryny — n wraca do 0 (jest: ' . var_export( $GLOBALS['__opt']['aifaq_daily_usage']['n'] ?? null, true ) . ')' );

	$GLOBALS['__caps'] = array();
} else {
	check( false, 'sekcja H pominięta — brak RagService::make() albo ProviderFactory::set_override()' );
}

// ===========================================================================
echo "\n=== I. D3 — best-effort bez kłamstwa + cache obiektowy (§7.3) ===\n";
// ===========================================================================
$rl_src = AIFAQ_PLUGIN_DIR . 'src/Rag/RateLimiter.php';
if ( is_file( $rl_src ) ) {
	$src = (string) file_get_contents( $rl_src );
	check( false === strpos( $src, 'Fail-closed' ), 'I1 (§7.3): docblock NIE zawiera już deklaracji „Fail-closed” (dzisiejsze kłamstwo usunięte)' );
	check( false !== stripos( $src, 'best-effort' ), 'I2 (§7.3): docblock mówi „best-effort”' );
} else {
	check( false, 'I1/I2 pominięte — brak pliku src/Rag/RateLimiter.php' );
}

if ( $has_limiter ) {
	$ip2  = str_repeat( 'b', 64 );
	$base = 1784000000 - ( 1784000000 % 86400 ) + 600;
	$t2   = $base;
	$clk2 = static function () use ( &$t2 ) { return $t2; };

	// Ścieżka TRANSIENTOWA (bez cache obiektowego) — kontrola dodatnia.
	k20_reset_env();
	$GLOBALS['__ext_cache'] = false;
	$GLOBALS['__objcache']  = array();
	$rlt = new \AIFAQ\Rag\RateLimiter( 2, $clk2 );
	$rlt->hit( $ip2 );
	check( isset( $GLOBALS['__tr'][ 'aifaq_rl_' . $ip2 ] ), 'I3: przy wp_using_ext_object_cache() === false używany jest TRANSIENT' );
	check( array() === $GLOBALS['__objcache'], 'I4: przy false cache obiektowy NIE jest dotykany' );

	// Ścieżka CACHE OBIEKTOWEGO.
	k20_reset_env();
	$GLOBALS['__ext_cache'] = true;
	$GLOBALS['__objcache']  = array();
	$rlc = new \AIFAQ\Rag\RateLimiter( 2, $clk2 );
	$rlc->hit( $ip2 );
	$rlc->hit( $ip2 );
	check( array() !== $GLOBALS['__objcache'], 'I5 (§7.3): przy wp_using_ext_object_cache() === true używana jest szybka ścieżka wp_cache_*' );
	check( array() === $GLOBALS['__tr'], 'I6 (§7.3): na ścieżce cache obiektowego transient NIE powstaje' );
	$ck = array_keys( $GLOBALS['__objcache'] );
	$ck_ok = false;
	foreach ( $ck as $k ) { if ( false !== strpos( (string) $k, 'aifaq_rl_' . $ip2 ) ) { $ck_ok = true; } }
	// O-62: na TEJ ścieżce identyfikator okna JEST w kluczu (wp_cache_incr nie działa na tablicy),
	// więc asertujemy PREFIKS, nie równość — asercja „klucz === aifaq_rl_<hash>” zaczerwieniłaby poprawny kod.
	check( $ck_ok, 'I7 (O-62): klucz cache obiektowego zaczyna się od aifaq_rl_<ip_hash> (jest: ' . implode( ',', $ck ) . ')' );
	check( false === $rlc->allow( $ip2 ), 'I8: limit działa także na ścieżce cache obiektowego (2 trafienia przy limicie 2 → blokada)' );
	if ( method_exists( 'AIFAQ\Rag\RateLimiter', 'refund' ) ) {
		$rlc->refund( $ip2 );
		check( true === $rlc->allow( $ip2 ), 'I9: refund() działa także na ścieżce cache obiektowego' );
	} else {
		check( false, 'I9 pominięta — brak refund()' );
	}
	$GLOBALS['__ext_cache'] = false;
	$GLOBALS['__objcache']  = array();
} else {
	check( false, 'sekcja I (limiter) pominięta — brak klasy RateLimiter' );
}

// ===========================================================================
echo "\n=== Z. Podłoga pokrycia i wartownik końca pliku ===\n";
// ===========================================================================
check( ! defined( 'AIFAQ_TESTING' ), 'Z0 (O-61): plik NIE definiuje AIFAQ_TESTING — inaczej szew §7.4 wyłączyłby cały sufit z sekcji H' );
check( $ran >= 60, 'Z1: podłoga pokrycia — wykonano co najmniej 60 asercji (jest: ' . $ran . ')' );
check( true, 'Z2 WARTOWNIK: wykonanie doszło do końca pliku (brak cichego Fatala w środku)' );

echo "\nAsercje: " . $ran . ', niezaliczone: ' . $fail . "\n";
exit( 0 === $fail ? 0 : 1 );
