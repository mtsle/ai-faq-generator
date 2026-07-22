<?php
/**
 * Testy Kroku 19 — sekcja C: podpis indeksu, IndexNotice, flush cache'u, budżet reindeksu.
 *
 * PISANE W CIEMNO (Etap 4, KONTRAKT k19-v3 §8.3 sekcja C). Autor NIE widział kodu
 * etapów E1–E3b-2.
 *
 * Migracja jest najgroźniejszą częścią Kroku: klient ma starą bazę wektorów i nigdy nie
 * kliknie reindeksu. Ten plik pilnuje, żeby (a) podpis NIE kłamał o przestrzeni wektorów,
 * (b) `partial:` był OSIĄGALNY (parsowanie trzysegmentowe — podpis sam zawiera `q:`),
 * (c) pruning NIE skasował bazy wiedzy po przebiegu uciętym budżetem czasu,
 * (d) cache NIE był czyszczony po przebiegu niepełnym.
 *
 * Podłoga pokrycia: >= 28 asercji (§8.1 pkt 7).
 *
 * URUCHOMIENIE:  php -d extension=mbstring tests/krok19-migracja-test.php
 * Kod wyjścia: 0 = OK, 1 = błędy.
 *
 * @package AI_FAQ_Generator
 */

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

/** Licznik zapisów/kasowań opcji — C9 („render() nic nie zapisuje”) i C24 („16 delete_option”). */
class K19_OptCounter {
	public $writes  = 0;
	public $deletes = 0;
	public $deleted = array();
}
$GLOBALS['__optc']             = new K19_OptCounter();
$GLOBALS['__opt']              = array();
$GLOBALS['__autoload']         = array();
$GLOBALS['__aifaq_transients'] = array();
$GLOBALS['__filters']          = array();
$GLOBALS['__cap']              = true;
$GLOBALS['__screen']           = null;
$GLOBALS['__posts']            = array();
$GLOBALS['__remote']           = array( 'bodies' => array(), 'reply' => null );

if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( '_n' ) ) { function _n( $a, $b, $n, $d = null ) { return 1 === (int) $n ? $a : $b; } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return $s; } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return $s; } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $s ) { return $s; } }
if ( ! function_exists( 'esc_url_raw' ) ) { function esc_url_raw( $s ) { return $s; } }
if ( ! function_exists( 'wp_kses_post' ) ) { function wp_kses_post( $s ) { return $s; } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $d ) { return json_encode( $d ); } }
if ( ! function_exists( 'wp_unslash' ) ) { function wp_unslash( $s ) { return is_string( $s ) ? stripslashes( $s ) : $s; } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ) { return is_string( $s ) ? trim( $s ) : ''; } }
if ( ! function_exists( 'sanitize_textarea_field' ) ) { function sanitize_textarea_field( $s ) { return trim( (string) $s ); } }
if ( ! function_exists( 'current_time' ) ) { function current_time( $t ) { return '2026-07-20 00:00:00'; } }
if ( ! function_exists( 'admin_url' ) ) { function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; } }
if ( ! function_exists( 'get_bloginfo' ) ) { function get_bloginfo( $s = 'name' ) { return 'Witryna'; } }
if ( ! function_exists( 'current_user_can' ) ) { function current_user_can( $c ) { return (bool) $GLOBALS['__cap']; } }
if ( ! function_exists( 'get_current_screen' ) ) { function get_current_screen() { return $GLOBALS['__screen']; } }
if ( ! function_exists( 'get_posts' ) ) { function get_posts( $a = array() ) { return $GLOBALS['__posts']; } }
if ( ! function_exists( 'get_the_title' ) ) { function get_the_title( $id = 0 ) { return 'Tytul ' . (int) $id; } }
if ( ! function_exists( 'get_permalink' ) ) { function get_permalink( $id = 0 ) { return 'https://example.test/?p=' . (int) $id; } }
if ( ! function_exists( 'set_time_limit' ) ) { function set_time_limit( $s ) { return true; } }
if ( ! function_exists( 'register_shutdown_function' ) ) { function register_shutdown_function( $cb ) { return true; } }

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $k, $default = false ) { return array_key_exists( $k, $GLOBALS['__opt'] ) ? $GLOBALS['__opt'][ $k ] : $default; }
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $k, $v, $a = null ) { ++$GLOBALS['__optc']->writes; $GLOBALS['__opt'][ $k ] = $v; return true; }
}
if ( ! function_exists( 'add_option' ) ) {
	function add_option( $k, $v = '', $d = '', $a = 'yes' ) {
		++$GLOBALS['__optc']->writes;
		if ( array_key_exists( $k, $GLOBALS['__opt'] ) ) { return false; }
		$GLOBALS['__opt'][ $k ] = $v; $GLOBALS['__autoload'][ $k ] = $a; return true;
	}
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $k ) {
		++$GLOBALS['__optc']->deletes;
		$GLOBALS['__optc']->deleted[] = $k;
		unset( $GLOBALS['__opt'][ $k ] );
		return true;
	}
}
if ( ! function_exists( 'get_transient' ) ) { function get_transient( $k ) { return $GLOBALS['__aifaq_transients'][ $k ] ?? false; } }
if ( ! function_exists( 'set_transient' ) ) { function set_transient( $k, $v, $t = 0 ) { $GLOBALS['__aifaq_transients'][ $k ] = $v; return true; } }
if ( ! function_exists( 'delete_transient' ) ) { function delete_transient( $k ) { unset( $GLOBALS['__aifaq_transients'][ $k ] ); return true; } }
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) {
		$args = func_get_args(); array_shift( $args );
		if ( isset( $GLOBALS['__filters'][ $tag ] ) ) { return call_user_func_array( $GLOBALS['__filters'][ $tag ], $args ); }
		return $value;
	}
}
// Transport WP — C21/C22 czytają payload embeddingu zbudowany przez PRAWDZIWĄ fabrykę.
if ( ! function_exists( 'wp_remote_request' ) ) {
	function wp_remote_request( $url, $args = array() ) {
		$GLOBALS['__remote']['bodies'][] = json_decode( (string) ( $args['body'] ?? '' ), true );
		return $GLOBALS['__remote']['reply'] ?? array( 'response' => array( 'code' => 200 ), 'body' => '{}', 'headers' => array() );
	}
}
if ( ! function_exists( 'wp_remote_post' ) ) { function wp_remote_post( $u, $a = array() ) { return wp_remote_request( $u, $a ); } }
if ( ! function_exists( 'wp_remote_get' ) ) { function wp_remote_get( $u, $a = array() ) { return wp_remote_request( $u, $a ); } }
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) { function wp_remote_retrieve_response_code( $r ) { return (int) ( $r['response']['code'] ?? 0 ); } }
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) { function wp_remote_retrieve_body( $r ) { return (string) ( $r['body'] ?? '' ); } }
if ( ! function_exists( 'wp_remote_retrieve_headers' ) ) { function wp_remote_retrieve_headers( $r ) { return $r['headers'] ?? array(); } }

/** Atrapa $wpdb z listą wykonanych SQL i metodą found(). */
class FakeWpdb {
	public $prefix = 'wp_';
	public $insert_id = 0;
	public $queries = array();
	public $throw_on_truncate = false;
	public $rows = array();
	public function insert( $t, $d ) { $this->rows[] = $d; return 1; }
	public function delete( $t, $w, $f = null ) { return 1; }
	public function query( $sql ) {
		$this->queries[] = $sql;
		if ( $this->throw_on_truncate && false !== stripos( (string) $sql, 'TRUNCATE' ) ) {
			throw new \RuntimeException( 'TRUNCATE odmowa hostingu' );
		}
		return 0;
	}
	public function prepare( $sql, ...$a ) { return $sql; }
	public function get_results( $sql, $o = null ) { $this->queries[] = $sql; return array(); }
	public function get_row( $sql, $o = null ) { $this->queries[] = $sql; return array( 'chunks' => 0, 'posts' => 0, 'embedded' => 0 ); }
	public function get_var( $sql ) { $this->queries[] = $sql; return 0; }
	public function found( $needle ) {
		foreach ( $this->queries as $q ) { if ( false !== stripos( (string) $q, $needle ) ) { return true; } }
		return false;
	}
	public function count_found( $needle ) {
		$n = 0;
		foreach ( $this->queries as $q ) { if ( false !== stripos( (string) $q, $needle ) ) { ++$n; } }
		return $n;
	}
}
$GLOBALS['wpdb'] = new FakeWpdb();

$fail = 0;
$ran  = 0;
function check( $cond, $label ) {
	global $fail, $ran;
	++$ran;
	echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n";
	if ( ! $cond ) { $fail++; }
}
function approx( $a, $b, $eps = 1e-9 ) { return abs( (float) $a - (float) $b ) < $eps; }
function k19_reset_env() {
	$GLOBALS['__filters']          = array();
	$GLOBALS['__aifaq_transients'] = array();
	$GLOBALS['__cap']              = true;
	$GLOBALS['__screen']           = null;
	$GLOBALS['__optc']             = new K19_OptCounter();
	$GLOBALS['__remote']['bodies'] = array();
	$GLOBALS['wpdb']               = new FakeWpdb();
}

// ---------------------------------------------------------------------------
// ETAP 1 ładowania: WSZYSTKO POZA IndexController — C23 wymaga stanu, w którym
// klasa NIE istnieje (RagService::make() ma wtedy zrobić fallback na make()).
// Po teście C23 doczytujemy IndexController, IndexNotice i Plugin.
// ---------------------------------------------------------------------------
$aifaq_first = array(
	'src/Data/Schema.php',
	'src/Data/Repository.php',
	'src/Data/KnowledgeRepository.php',
	'src/Data/CacheRepository.php',
	'src/Data/QaLogRepository.php',
	'src/Http/HttpClient.php',
	'src/Http/WpHttpClient.php',
	'src/Providers/ProviderInterface.php',
	'src/Providers/GeminiProvider.php',
	'src/Core/Settings.php',
	'src/Providers/ProviderFactory.php',
	// SPOZA listy §8.3 (zgłoszone w ODCHYLENIA.md): Indexer type-hintuje ContentSource,
	// jego konstruktor wymaga Chunkera, a run_reindex() buduje WpContentSource i spółkę —
	// bez tych plików C38/C35 kończą się Fatal errorem, a §8.1 pkt 2 zakazuje fatali.
	'src/Index/ContentSource.php',
	'src/Index/Chunker.php',
	'src/Index/BoilerplateFilter.php',
	'src/Index/WpContentSource.php',
	'src/Index/CrawlQueue.php',
	'src/Index/RenderedContentSource.php',
	'src/Index/PostMetaContentSource.php',
	'src/Index/CompositeContentSource.php',
	'src/Index/EmbeddingBatcher.php',
	'src/Index/Indexer.php',
	'src/Rag/Retriever.php',
	'src/Rag/RateLimiter.php',
	'src/Rag/TopicGuard.php',
	'src/Rag/Answerer.php',
	'src/Rag/RagService.php',
);
foreach ( $aifaq_first as $rel ) {
	$p = __DIR__ . '/../' . $rel;
	if ( file_exists( $p ) ) { require_once $p; }
}

// ===========================================================================
echo "=== E1. Fallback bez IndexController (C23) — MUSI iść PRZED jego załadowaniem ===\n";
// ===========================================================================
if ( class_exists( 'AIFAQ\Rag\RagService' ) && method_exists( 'AIFAQ\Rag\RagService', 'make' ) && ! class_exists( 'AIFAQ\Admin\IndexController', false ) ) {
	k19_reset_env();
	$GLOBALS['__opt']['aifaq_settings']       = array( 'api_key' => 'K', 'model' => 'gemini-2.5-flash', 'embed_model' => 'gemini-embedding-001', 'language' => 'pl' );
	$GLOBALS['__opt']['aifaq_index_signature'] = 'cokolwiek';
	$ok = true;
	try { \AIFAQ\Rag\RagService::make(); } catch ( \Throwable $e ) { $ok = false; }
	check( $ok, 'NOWE — C23: brak klasy IndexController → RagService::make() robi fallback na make(), BRAK fatala' );
} else {
	check( false, 'NOWE — C23 pominięta: brak RagService::make() albo IndexController już załadowany' );
}

// ETAP 2 ładowania.
foreach ( array( 'src/Admin/IndexController.php', 'src/Admin/IndexNotice.php', 'src/Core/Plugin.php' ) as $rel ) {
	$p = __DIR__ . '/../' . $rel;
	if ( file_exists( $p ) ) { require_once $p; }
}

$has_ic     = class_exists( 'AIFAQ\Admin\IndexController' );
$has_notice = class_exists( 'AIFAQ\Admin\IndexNotice' );
$has_plugin = class_exists( 'AIFAQ\Core\Plugin' );
$has_idx    = class_exists( 'AIFAQ\Index\Indexer' ) && class_exists( 'AIFAQ\Index\Chunker' );

// --- Atrapy (§8.2) ---
if ( class_exists( 'AIFAQ\Index\EmbeddingBatcher' ) ) {
	class K19_Batcher extends \AIFAQ\Index\EmbeddingBatcher {
		public $calls   = 0;
		public $burn_us = 0;         // wypalanie czasu — jedyny sposób na wyczerpanie budżetu int-owego
		public $mode    = 'ok';
		public function __construct() {}
		public function embed_all( array $texts ) {
			++$this->calls;
			if ( $this->burn_us > 0 ) { usleep( $this->burn_us ); }
			if ( 'error' === $this->mode ) { return new WP_Error( 'aifaq_gemini_rate', 'limit' ); }
			$out = array();
			foreach ( $texts as $t ) { $out[] = array( 0.1, 0.2, 0.3 ); }
			return $out;
		}
	}
}
if ( interface_exists( 'AIFAQ\Index\ContentSource' ) ) {
	/** Źródło treści: ContentSource + is_complete()/stats() z §8.2. */
	class K19_Source implements \AIFAQ\Index\ContentSource {
		public $docs     = array();
		public $complete = true;
		public function documents(): array { return $this->docs; }
		public function is_complete(): bool { return $this->complete; }
		public function stats(): array { return array( 'total' => count( $this->docs ), 'rendered' => count( $this->docs ) ); }
	}
}
if ( class_exists( 'AIFAQ\Data\KnowledgeRepository' ) ) {
	/** DRUGA, NIEZALEŻNA deklaracja (pliki chodzą osobno — §8.2). Tu w roli licznika pruningu. */
	class K19_Knowledge extends \AIFAQ\Data\KnowledgeRepository {
		public $delete_missing_calls = 0;
		public $replaced             = array();
		public $before_chunks        = 0;
		public function __construct( int $before_chunks = 0 ) { $this->before_chunks = $before_chunks; }
		public function delete_missing( array $keep_post_ids ): int { ++$this->delete_missing_calls; return 0; }
		public function replace_for_post( int $post_id, array $chunks ): int { $this->replaced[ $post_id ] = $chunks; return count( $chunks ); }
		public function hashes_for_post( int $post_id ): array { return array(); }
		public function delete_by_post( int $post_id ): int { return 0; }
		/**
		 * PRZED przebiegiem raportuje `before_chunks`, PO — liczbę realnie zapisanych fragmentów.
		 * Bez tego DROP_ALERT (porównanie „ile było” vs „ile jest”) nie ma czego porównać
		 * i C28b byłoby czerwone z powodu atrapy, a nie z powodu zgaszonego bezpiecznika.
		 */
		private function current_chunks(): int {
			if ( array() === $this->replaced ) { return $this->before_chunks; }
			$n = 0;
			foreach ( $this->replaced as $chunks ) { $n += count( $chunks ); }
			return $n;
		}
		public function count_embedded(): int { return $this->current_chunks(); }
		public function count(): int { return $this->current_chunks(); }
		public function stats(): array {
			$n = $this->current_chunks();
			return array( 'chunks' => $n, 'posts' => 1, 'embedded' => $n );
		}
		/** Ile fragmentów NIE dostało wektora (do C31). */
		public function null_vectors(): int {
			$n = 0;
			foreach ( $this->replaced as $chunks ) {
				foreach ( $chunks as $c ) { if ( ! isset( $c['embedding'] ) || null === $c['embedding'] ) { ++$n; } }
			}
			return $n;
		}
	}
}

/** Korpus 1100 krótkich wpisów = 1100 fragmentów = DOKŁADNIE 3 fale po 500. */
function k19_corpus( int $n = 1100 ) {
	$docs = array();
	for ( $i = 1; $i <= $n; $i++ ) {
		$docs[] = array( 'post_id' => $i, 'title' => 'Wpis ' . $i, 'url' => 'u' . $i, 'text' => 'Zdanie numer ' . $i . ' o krowach.' );
	}
	return $docs;
}
function k19_indexer( $source, $batcher, $knowledge ) {
	return new \AIFAQ\Index\Indexer( $source, new \AIFAQ\Index\Chunker( 60, 15 ), $batcher, $knowledge );
}

// ===========================================================================
echo "\n=== A. Podpis indeksu (C1-C4, C36, C2-bis, C37) ===\n";
// ===========================================================================
if ( $has_ic && method_exists( 'AIFAQ\Admin\IndexController', 'index_signature' ) ) {
	k19_reset_env();
	$GLOBALS['__opt']['aifaq_settings'] = array( 'provider' => 'gemini', 'embed_model' => 'gemini-embedding-001', 'api_key' => 'K', 'model' => 'gemini-2.5-flash' );
	$sig = (string) \AIFAQ\Admin\IndexController::index_signature();

	$suffix = '|src2|q:RETRIEVAL_DOCUMENT';
	check( substr( $sig, -strlen( $suffix ) ) === $suffix, 'NOWE — C1: podpis kończy się na „|src2|q:RETRIEVAL_DOCUMENT” (jest: ' . $sig . ')' );
	check( 5 === count( explode( '|', $sig ) ), 'NOWE — C2: podpis ma DOKŁADNIE 5 członów rozdzielonych | (jest: ' . count( explode( '|', $sig ) ) . ')' );
	check( false === strpos( $sig, AIFAQ_VERSION . '|' ), 'NOWE — C2-bis (strażnik FZ10): AIFAQ_VERSION NIE występuje w podpisie — inaczej każdy hotfix unieważniałby content_hash' );
	check( false !== strpos( $sig, '768' ) && false !== strpos( $sig, 'gemini-embedding-001' ), 'REGRESJA — C3: podpis zawiera 768 i gemini-embedding-001' );
	check( 'gemini|gemini-embedding-001|768|src2' !== $sig, 'NOWE — C4: podpis RÓŻNY od literału v0.21.1' );

	k19_reset_env();
	$GLOBALS['__opt']['aifaq_settings']       = array( 'provider' => 'gemini', 'embed_model' => 'gemini-embedding-001' );
	$GLOBALS['__filters']['aifaq_embed_task'] = static function () { return ''; };
	$sig_off = (string) \AIFAQ\Admin\IndexController::index_signature();
	check( 'q:' === substr( $sig_off, -3 ) || 'q:' === substr( $sig_off, strrpos( $sig_off, '|' ) + 1 ), 'NOWE — C36: filtr aifaq_embed_task=\'\' → człon „q:” BEZ sufiksu (podpis nie kłamie o przestrzeni; jest: ' . $sig_off . ')' );

	$ic_src = (string) file_get_contents( __DIR__ . '/../src/Admin/IndexController.php' );
	check( 1 === preg_match( '/ProviderFactory::make_for_index\(\s*\)/', $ic_src ), 'NOWE — C37: IndexController woła ProviderFactory::make_for_index() (FZ4 — bez tego trzecia przestrzeń wektorów)' );
	check( 1 === preg_match( '/skipped_no_vector\'\s*\]\s*\?\?\s*1/', $ic_src ), 'NOWE — C32: warunek §6.3 czyta skipped_no_vector przez „?? 1” (BEZPIECZNA strona: brak klucza = niepełny)' );
} else {
	check( false, 'NOWE — sekcja A pominięta: brak metody IndexController::index_signature()' );
}

// ===========================================================================
echo "\n=== B. IndexNotice::state() / reason() (C5-C8, C34, C34a, C34b) ===\n";
// ===========================================================================
if ( $has_notice && $has_ic && method_exists( 'AIFAQ\Admin\IndexNotice', 'state' ) ) {
	$set = static function ( $saved, $embedded ) {
		k19_reset_env();
		$GLOBALS['__opt']['aifaq_settings'] = array( 'provider' => 'gemini', 'embed_model' => 'gemini-embedding-001' );
		$GLOBALS['__opt']['aifaq_index_signature'] = $saved;
		$GLOBALS['wpdb'] = new class extends FakeWpdb {
			public $embedded = 0;
			public function get_row( $sql, $o = null ) { $this->queries[] = $sql; return array( 'chunks' => $this->embedded, 'posts' => 1, 'embedded' => $this->embedded ); }
			public function get_var( $sql ) { $this->queries[] = $sql; return $this->embedded; }
		};
		$GLOBALS['wpdb']->embedded = $embedded;
		return (string) \AIFAQ\Admin\IndexNotice::state();
	};
	// E5/O-7: $now MUSI byc liczony w TYM SAMYM srodowisku, ktorego uzywa $set().
	// Sekcja A zostawia aktywny filtr aifaq_embed_task => '' (:336), a $set() czysci
	// filtry przez k19_reset_env() — bez tego resetu $now ma czlon „q:” BEZ sufiksu,
	// a podpis liczony wewnatrz $set() ma „q:RETRIEVAL_DOCUMENT”. Porownanie bylo wiec
	// zawsze falszywe i C5/C34/C34a czerwienily sie na POPRAWNYM kodzie.
	k19_reset_env();
	$GLOBALS['__opt']['aifaq_settings'] = array( 'provider' => 'gemini', 'embed_model' => 'gemini-embedding-001' );
	$now = (string) \AIFAQ\Admin\IndexController::index_signature();

	check( 'ok' === $set( $now, 5 ), 'NOWE — C5: zapisany === bieżący → ok' );
	check( 'stale' === $set( '', 5 ), 'NOWE — C6: podpis pusty + baza NIEpusta → stale' );
	check( 'ok' === $set( '', 0 ), 'NOWE — C7: podpis pusty + baza PUSTA → ok (nie ma czego migrować)' );
	check( 'stale' === $set( 'gemini|gemini-embedding-001|768|src2', 5 ), 'NOWE — C8: podpis niepusty, niezgodny, bez prefiksu partial: → stale' );

	check( 'partial' === $set( 'partial:budget:' . $now, 5 ), 'NOWE — C34: znacznik partial:budget:<podpis> → partial (przechodzi WYŁĄCZNIE przy explode(\':\', $saved, 3), bo podpis zawiera „q:”)' );
	if ( method_exists( 'AIFAQ\Admin\IndexNotice', 'reason' ) ) {
		$set( 'partial:budget:' . $now, 5 );
		check( 'budget' === (string) \AIFAQ\Admin\IndexNotice::reason(), 'NOWE — C34: reason() === budget (ŚRODKOWY segment, nie sufiks)' );
		check( 'partial' === $set( 'partial:cokolwiek:' . $now, 5 ), 'NOWE — C34a: powód spoza whitelisty → nadal partial' );
		$set( 'partial:cokolwiek:' . $now, 5 );
		check( '' === (string) \AIFAQ\Admin\IndexNotice::reason(), 'NOWE — C34a: powód spoza whitelisty crawl|errors|budget → reason() === \'\'' );
	} else {
		check( false, 'NOWE — C34/C34a (reason) pominięte: brak metody IndexNotice::reason()' );
		check( false, 'NOWE — C34a pominięta: brak metody IndexNotice::reason()' );
		check( false, 'NOWE — C34a (whitelista) pominięta: brak metody IndexNotice::reason()' );
	}
	check( 'stale' === $set( 'partial:budget:INNY|PODPIS|768|src2|q:X', 5 ), 'NOWE — C34b: partial z INNYM podpisem w 3. segmencie → stale, NIE partial' );
} else {
	check( false, 'NOWE — sekcja B pominięta: brak klasy IndexNotice albo metody state()' );
}

// ===========================================================================
echo "\n=== C. IndexNotice::render() — bramki i teksty (C9-C17) ===\n";
// ===========================================================================
if ( $has_notice && $has_ic && method_exists( 'AIFAQ\Admin\IndexNotice', 'render' ) ) {
	$render = static function ( $saved, $screen_id, $cap = true, $embedded = 5 ) {
		k19_reset_env();
		$GLOBALS['__cap'] = $cap;
		$GLOBALS['__opt']['aifaq_settings'] = array( 'provider' => 'gemini', 'embed_model' => 'gemini-embedding-001' );
		$GLOBALS['__opt']['aifaq_index_signature'] = $saved;
		$GLOBALS['__screen'] = ( null === $screen_id ) ? null : (object) array( 'id' => $screen_id );
		$GLOBALS['wpdb'] = new class extends FakeWpdb {
			public function get_row( $sql, $o = null ) { $this->queries[] = $sql; return array( 'chunks' => 5, 'posts' => 1, 'embedded' => 5 ); }
			public function get_var( $sql ) { $this->queries[] = $sql; return 5; }
		};
		ob_start();
		try { \AIFAQ\Admin\IndexNotice::render(); } catch ( \Throwable $e ) { ob_end_clean(); return '__FATAL__ ' . $e->getMessage(); }
		return (string) ob_get_clean();
	};
	$now = (string) \AIFAQ\Admin\IndexController::index_signature();

	check( ! defined( 'AIFAQ\Admin\IndexNotice::DISMISSED' ), 'NOWE — C9: IndexNotice NIE definiuje stałej DISMISSED (§2.8: DOKŁADNIE 2 nowe opcje, zero zamykania)' );
	$out = $render( '', 'plugins' );
	check( 0 === $GLOBALS['__optc']->writes, 'NOWE — C9: render() nie zapisuje ŻADNEJ opcji (jest: ' . $GLOBALS['__optc']->writes . ' zapisów)' );
	check( '' === $render( '', 'plugins', false ), 'NOWE — C10: bez capa manage_options → wypis PUSTY' );
	check( '' === $render( '', 'edit-post' ), 'NOWE — C11: ekran edit-post → wypis PUSTY' );

	foreach ( array( 'plugins', 'dashboard', 'toplevel_page_ai-faq-generator' ) as $sid ) {
		check( strlen( $render( '', $sid ) ) > 0, 'NOWE — C12: stan stale na ekranie ' . $sid . ' → wypis NIEPUSTY' );
	}
	check( '' === $render( $now, 'plugins' ), 'NOWE — C13: stan ok → wypis PUSTY' );
	$nullout = $render( '', null );
	check( '' === $nullout, 'NOWE — C14: get_current_screen() === null → brak fatala, wypis pusty (jest: ' . substr( $nullout, 0, 40 ) . ')' );

	$stale = $render( '', 'plugins' );
	check( false !== strpos( $stale, 'policzona starą metodą' ), 'NOWE — C15: tekst stale zawiera „policzona starą metodą”' );
	check( false !== strpos( $stale, 'page=ai-faq-generator' ), 'NOWE — C15: komunikat linkuje do Kokpitu (page=ai-faq-generator)' );
	check( false === strpos( $stale, 'aifaq_fix=' ) && false === strpos( $stale, '_wpnonce' ), 'NOWE — C17: zero akcji naprawczej i zero zamykania (brak aifaq_fix= i _wpnonce)' );

	$texts = array();
	foreach ( array( 'crawl', 'errors', 'budget' ) as $why ) {
		$texts[ $why ] = $render( 'partial:' . $why . ':' . $now, 'plugins' );
	}
	check( '' !== $texts['crawl'] && $texts['crawl'] !== $texts['errors'], 'NOWE — C16: powód crawl daje WŁASNY tekst, różny od errors' );
	check( '' !== $texts['errors'] && $texts['errors'] !== $texts['budget'], 'NOWE — C16: powód errors daje WŁASNY tekst, różny od budget' );
	check( '' !== $texts['budget'] && $texts['budget'] !== $texts['crawl'], 'NOWE — C16: powód budget daje WŁASNY tekst, różny od crawl' );
} else {
	check( false, 'NOWE — sekcja C pominięta: brak metody IndexNotice::render()' );
}

// ===========================================================================
echo "\n=== D. Wpięcie w Plugin.php — przez file_get_contents (C18, C19, C20) ===\n";
// ===========================================================================
$plugin_file = __DIR__ . '/../src/Core/Plugin.php';
if ( file_exists( $plugin_file ) ) {
	$src = (string) file_get_contents( $plugin_file );
	check( 1 === substr_count( $src, "'admin_notices'" ), 'REGRESJA — C18: DOKŁADNIE jeden literał \'admin_notices\' (drugi add_action = FAIL krok18-pageguard-test.php:1086; jest: ' . substr_count( $src, "'admin_notices'" ) . ')' );
	check( 1 === preg_match( '/IndexNotice::render\(\s*\)/', $src ), 'NOWE — C19: wpięcie jest WYKONYWALNE — wywołanie IndexNotice::render()' );
	// §8.3 C19 żąda literału z PODWÓJNYMI backslashami ("\\\\AIFAQ\\\\Admin..."), a §3.9 podaje
	// wzorcowy kod z POJEDYNCZYMI ('\AIFAQ\Admin\PageNotice'). Te dwa zapisy wykluczają się —
	// asercja dosłowna z §8.3 jest czerwona na kodzie zgodnym z §3.9. Sprzeczność zgłoszona
	// w ODCHYLENIA.md; tu przyjmuję OBIE formy, bo mierzony jest fakt osłony, nie liczba znaków.
	check( 1 === preg_match( '/class_exists\(\s*\'\\\\{1,2}AIFAQ\\\\{1,2}Admin\\\\{1,2}IndexNotice\'\s*\)/', $src ), 'NOWE — C19: wywołanie osłonięte class_exists( \'\\AIFAQ\\Admin\\IndexNotice\' )' );
	check( 1 === substr_count( $src, "'admin_post_aifaq_page_fix'" ), 'REGRESJA — C20: literał \'admin_post_aifaq_page_fix\' dokładnie raz (dorobek K18 nietknięty)' );
} else {
	check( false, 'REGRESJA — sekcja D pominięta: brak pliku src/Core/Plugin.php' );
}

// ===========================================================================
echo "\n=== E2. Flavour wg podpisu (C21, C22) ===\n";
// ===========================================================================
if ( class_exists( 'AIFAQ\Rag\RagService' ) && method_exists( 'AIFAQ\Rag\RagService', 'make' ) && $has_ic ) {
	$flavour = static function ( $sig_saved ) {
		k19_reset_env();
		$GLOBALS['__opt']['aifaq_settings'] = array( 'provider' => 'gemini', 'api_key' => 'K', 'model' => 'gemini-2.5-flash', 'embed_model' => 'gemini-embedding-001', 'language' => 'pl' );
		$GLOBALS['__opt']['aifaq_index_signature'] = $sig_saved;
		$GLOBALS['__remote']['reply'] = array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'embeddings' => array( array( 'values' => array( 0.1, 0.2, 0.3 ) ) ) ) ), 'headers' => array() );
		\AIFAQ\Rag\RagService::make()->ask( 'Ile kosztuje czesne?', 'iphash' );
		return $GLOBALS['__remote']['bodies'][0]['requests'][0] ?? array();
	};
	$now = (string) \AIFAQ\Admin\IndexController::index_signature();

	$req = $flavour( 'STARY|PODPIS|768|src2' );
	check( ! isset( $req['taskType'] ), 'NOWE — C21: podpis NIEZGODNY → embed pytania BEZ taskType (niezmiennik §6.2: bot nie gorszy niż v0.21.1)' );
	$req = $flavour( $now );
	check( 'RETRIEVAL_QUERY' === ( $req['taskType'] ?? null ), 'NOWE — C22: podpis ZGODNY → embed pytania z RETRIEVAL_QUERY (jest: ' . var_export( $req['taskType'] ?? null, true ) . ')' );
} else {
	check( false, 'NOWE — C21/C22 pominięte: brak RagService::make() albo IndexController' );
}

// ===========================================================================
echo "\n=== F. Flush cache'u po aktualizacji (C25, C25a, C26, C26a, C26b) ===\n";
// ===========================================================================
if ( $has_plugin && method_exists( 'AIFAQ\Core\Plugin', 'should_flush_cache' ) ) {
	check( true === \AIFAQ\Core\Plugin::should_flush_cache( '', '0.22.0' ), 'NOWE — C25: should_flush_cache(\'\', \'0.22.0\') → true (czysta funkcja, zero I/O)' );
	check( false === \AIFAQ\Core\Plugin::should_flush_cache( '0.22.0', '0.22.0' ), 'NOWE — C25: should_flush_cache(\'0.22.0\', \'0.22.0\') → false' );
	check( false === \AIFAQ\Core\Plugin::should_flush_cache( '', '' ), 'NOWE — C25a: pusta WERSJA → false (odwrócony warunek dałby true; mutacja #32)' );
} else {
	check( false, 'NOWE — C25/C25a pominięte: brak metody Plugin::should_flush_cache()' );
}

if ( $has_plugin && method_exists( 'AIFAQ\Core\Plugin', 'maybe_flush_cache' ) ) {
	// K19_Cache NIE jest wstrzykiwalny do Plugin::maybe_flush_cache() (klasa robi `new CacheRepository()`),
	// więc czyszczenie obserwujemy przez TRUNCATE w atrapie $wpdb — jak krok9-audit-cache-test.php:58.
	k19_reset_env();
	$GLOBALS['__opt']['aifaq_cache_flushed_for'] = '';
	\AIFAQ\Core\Plugin::maybe_flush_cache();
	check( 1 === $GLOBALS['wpdb']->count_found( 'TRUNCATE TABLE wp_aifaq_cache' ), 'NOWE — C26: opcja pusta → DOKŁADNIE jeden TRUNCATE cache (jest: ' . $GLOBALS['wpdb']->count_found( 'TRUNCATE TABLE wp_aifaq_cache' ) . ')' );
	check( AIFAQ_VERSION === (string) get_option( 'aifaq_cache_flushed_for', '' ), 'NOWE — C26: po udanym flushu opcja === AIFAQ_VERSION' );
	$GLOBALS['__aifaq_transients'] = array();          // zdejmij zamek, żeby zmierzyć samą flagę
	\AIFAQ\Core\Plugin::maybe_flush_cache();
	check( 1 === $GLOBALS['wpdb']->count_found( 'TRUNCATE TABLE wp_aifaq_cache' ), 'NOWE — C26: drugie wywołanie → BEZ zmian (flaga trzyma)' );

	k19_reset_env();
	$GLOBALS['__opt']['aifaq_cache_flushed_for'] = '';
	$GLOBALS['wpdb']->throw_on_truncate = true;
	$okc = true;
	try { \AIFAQ\Core\Plugin::maybe_flush_cache(); } catch ( \Throwable $e ) { $okc = false; }
	check( $okc, 'NOWE — C26a: wyjątek z clear_all() nie wychodzi na zewnątrz' );
	check( '' === (string) get_option( 'aifaq_cache_flushed_for', '' ), 'NOWE — C26a: TRUNCATE padł → flaga NIE zapisana, będzie druga szansa (cicha porażka zakazana, §1 pkt 10)' );

	k19_reset_env();
	$GLOBALS['__opt']['aifaq_cache_flushed_for'] = '';
	$GLOBALS['__aifaq_transients']['aifaq_cache_flush_lock'] = 1;
	\AIFAQ\Core\Plugin::maybe_flush_cache();
	check( 0 === $GLOBALS['wpdb']->count_found( 'TRUNCATE' ), 'NOWE — C26b: żywy transient-zamek → ZERO TRUNCATE (N równoległych żądań po aktualizacji nie robi N x TRUNCATE)' );
} else {
	check( false, 'NOWE — C26/C26a/C26b pominięte: brak metody Plugin::maybe_flush_cache()' );
}

// ===========================================================================
echo "\n=== G. Indexer — budżet, gwarancja postępu, klucze raportu, pruning (C27-C31b, C28a, C28b) ===\n";
// ===========================================================================
if ( $has_idx && class_exists( 'K19_Batcher' ) && class_exists( 'K19_Source' ) && class_exists( 'K19_Knowledge' ) ) {
	// C27 — budżet wyłączony: wszystkie trzy fale przetworzone.
	k19_reset_env();
	$GLOBALS['__filters']['aifaq_index_budget'] = static function () { return 0; };
	$GLOBALS['__filters']['aifaq_index_pace']   = static function () { return 0; };
	$src27 = new K19_Source();
	$src27->docs = k19_corpus( 1100 );
	$b27  = new K19_Batcher();
	$k27  = new K19_Knowledge();
	$rep  = k19_indexer( $src27, $b27, $k27 )->run();
	check( 3 === $b27->calls, 'NOWE — C27: aifaq_index_budget=0 → WSZYSTKIE 3 fale przetworzone (1100 fragmentów / WAVE 500; jest: ' . $b27->calls . ' przy ' . (int) ( $rep['chunks'] ?? 0 ) . ' fragmentach)' );

	// C31b — kształt raportu.
	check( 14 === count( $rep ), 'NOWE — C31b: raport ma DOKŁADNIE 14 kluczy (10 istniejących + 4 nowe; jest: ' . count( $rep ) . ' → ' . implode( ',', array_keys( $rep ) ) . ')' );
	foreach ( array( 'incomplete', 'budget_hit', 'skipped_no_vector', 'chunks_missing_vector' ) as $key ) {
		check( array_key_exists( $key, $rep ), 'NOWE — C31b: klucz ' . $key . ' obecny ZAWSZE (także przy przebiegu pełnym)' );
	}

	// C30 — GWARANCJA POSTĘPU: pierwsza fala wykonuje się zawsze, nawet gdy sama wyczerpie budżet.
	// Filtr aifaq_index_budget jest `int` (§5.4), a `0` znaczy „wyłączony”, więc najmniejszy
	// realny budżet to 1 s — wypala go atrapa batchera. (Kontrakt mówi „0.001 s” — nieosiągalne
	// przez int-owy filtr; zgłoszone w ODCHYLENIA.md.)
	k19_reset_env();
	$GLOBALS['__filters']['aifaq_index_budget'] = static function () { return 1; };
	$GLOBALS['__filters']['aifaq_index_pace']   = static function () { return 0; };
	$src30 = new K19_Source();
	$src30->docs = k19_corpus( 1100 );
	$b30 = new K19_Batcher();
	$b30->burn_us = 1200000;      // 1,2 s > budżet 1 s — po pierwszej fali budżet jest już przekroczony
	$k30 = new K19_Knowledge();
	$rep30 = k19_indexer( $src30, $b30, $k30 )->run();
	check( 1 === $b30->calls, 'NOWE — C30: gwarancja postępu → DOKŁADNIE jedna fala, deterministycznie (jest: ' . $b30->calls . ')' );

	// C28, C28a, C29, C31, C31a — skutki przebiegu uciętego budżetem.
	$warn_budget = 0;
	foreach ( (array) ( $rep30['warnings'] ?? array() ) as $w ) {
		if ( false !== stripos( $w, 'budzet' ) || false !== stripos( $w, 'budżet' ) ) { ++$warn_budget; }
	}
	check( 1 === $warn_budget, 'NOWE — C28: budżet wyczerpany → DOKŁADNIE jedno ostrzeżenie o budżecie (jest: ' . $warn_budget . ')' );
	check( 0 === (int) ( $rep30['pruned'] ?? -1 ), 'NOWE — C28: budżet wyczerpany → pruned === 0' );
	check( false === stripos( implode( ' ', (array) ( $rep30['warnings'] ?? array() ) ), 'Pobieranie tresci' ), 'NOWE — C28a: ZERO komunikatów o crawlu, którego nie ma (dwie rozdzielne flagi, nie jedna zOR-owana)' );
	check( 0 === $k30->delete_missing_calls, 'NOWE — C29: budżet wyczerpany → delete_missing() NIE wołane (inaczej pruning kasuje bazę wiedzy!)' );
	check( 0 === $k30->null_vectors(), 'NOWE — C31: ZERO zapisanych fragmentów z embedding === null' );
	check( array_key_exists( 'skipped_no_vector', $rep30 ) && (int) $rep30['skipped_no_vector'] > 0, 'NOWE — C31a: skipped_no_vector policzone i > 0 przy budżecie wyczerpanym (jest: ' . var_export( $rep30['skipped_no_vector'] ?? null, true ) . ')' );
	check( true === (bool) ( $rep30['budget_hit'] ?? false ), 'NOWE — C28: flaga budget_hit zapalona' );
	check( true === (bool) ( $rep30['incomplete'] ?? false ), 'NOWE — C28: flaga incomplete zapalona (steruje pruningiem)' );

	// C28b — DROP_ALERT gaśnie WYŁĄCZNIE przy budget_hit, nie przy niekompletnym crawlu.
	$drop = static function ( $complete, $budget ) {
		k19_reset_env();
		$GLOBALS['__filters']['aifaq_index_budget'] = static function () use ( $budget ) { return $budget; };
		$GLOBALS['__filters']['aifaq_index_pace']   = static function () { return 0; };
		$s = new K19_Source();
		$s->docs     = k19_corpus( 1100 );
		$s->complete = $complete;
		$b = new K19_Batcher();
		if ( $budget > 0 ) { $b->burn_us = 1200000; }
		$rep = k19_indexer( $s, $b, new K19_Knowledge( 5000 ) )->run();   // 5000 „przed” → spadek >40 %
		$out = array();
		foreach ( (array) ( $rep['warnings'] ?? array() ) as $w ) {
			// DROP_ALERT to JEDYNE ostrzeżenie mówiące o spadku LICZBY fragmentów; komunikaty
			// budżetu i crawla nie zawierają liczby „5000”, bo nie znają stanu sprzed przebiegu.
			if ( false !== strpos( (string) $w, '5000' ) ) { $out[] = $w; }
		}
		return $out;
	};
	$d_budget = $drop( true, 1 );
	$d_crawl  = $drop( false, 0 );
	check( array() === $d_budget, 'NOWE — C28b: budget_hit + źródło KOMPLETNE → DROP_ALERT NIEOBECNE (jest: ' . json_encode( $d_budget, JSON_UNESCAPED_UNICODE ) . ')' );
	check( array() !== $d_crawl, 'NOWE — C28b: źródło NIEKOMPLETNE → DROP_ALERT OBECNE — K19 nie gasi bezpiecznika z Kroku 5 (jest: ' . json_encode( $d_crawl, JSON_UNESCAPED_UNICODE ) . ')' );
} else {
	check( false, 'NOWE — sekcja G pominięta: brak klasy Indexer/Chunker albo atrap K19_Batcher/K19_Source/K19_Knowledge' );
}

// ===========================================================================
echo "\n=== H. run_clear() i furtka aifaq_index_complete (C33, C38) ===\n";
// ===========================================================================
if ( $has_ic && method_exists( 'AIFAQ\Admin\IndexController', 'run_clear' ) ) {
	k19_reset_env();
	$GLOBALS['__opt']['aifaq_settings']        = array( 'provider' => 'gemini', 'embed_model' => 'gemini-embedding-001' );
	$GLOBALS['__opt']['aifaq_index_signature'] = 'jakis|podpis|768|src2|q:RETRIEVAL_DOCUMENT';
	( new \AIFAQ\Admin\IndexController() )->run_clear();
	check( false === get_option( 'aifaq_index_signature', false ), 'NOWE — C33: run_clear() KASUJE aifaq_index_signature (inaczej „Wyczyść bazę” zostawia podpis twierdzący „zmigrowane” przy pustej bazie)' );
} else {
	check( false, 'NOWE — C33 pominięta: brak metody IndexController::run_clear()' );
}

if ( $has_ic && method_exists( 'AIFAQ\Admin\IndexController', 'run_reindex' ) ) {
	// Źródło puste → przebieg PEŁNY. Filtr aifaq_index_complete => false ma go zdegradować.
	k19_reset_env();
	$GLOBALS['__posts'] = array();
	$GLOBALS['__opt']['aifaq_settings']           = array( 'provider' => 'gemini', 'api_key' => 'K', 'model' => 'gemini-2.5-flash', 'embed_model' => 'gemini-embedding-001', 'crawl_enabled' => '0' );
	$GLOBALS['__filters']['aifaq_index_complete'] = static function () { return false; };
	( new \AIFAQ\Admin\IndexController() )->run_reindex();
	$saved = (string) get_option( 'aifaq_index_signature', '' );
	$now   = (string) \AIFAQ\Admin\IndexController::index_signature();
	check( $saved !== $now, 'NOWE — C38: aifaq_index_complete=false przy przebiegu PEŁNYM → podpis pełny NIE zapisany (jest: ' . ( '' === $saved ? '(pusty)' : $saved ) . ')' );
	check( 0 === $GLOBALS['wpdb']->count_found( 'TRUNCATE TABLE wp_aifaq_cache' ), 'NOWE — C35: przebieg zdegradowany do niepełnego → cache NIE czyszczony (§3.8, §6.4 pkt 1)' );

	k19_reset_env();
	$GLOBALS['__posts'] = array();
	$GLOBALS['__opt']['aifaq_settings']           = array( 'provider' => 'gemini', 'api_key' => 'K', 'model' => 'gemini-2.5-flash', 'embed_model' => 'gemini-embedding-001', 'crawl_enabled' => '0' );
	$GLOBALS['__filters']['aifaq_index_complete'] = static function () { return true; };
	( new \AIFAQ\Admin\IndexController() )->run_reindex();
	check( (string) \AIFAQ\Admin\IndexController::index_signature() === (string) get_option( 'aifaq_index_signature', '' ), 'NOWE — C38: aifaq_index_complete=true → podpis pełny zapisany (furtka awaryjna właściciela)' );
} else {
	check( false, 'NOWE — C38/C35 pominięte: brak metody IndexController::run_reindex()' );
}

// ===========================================================================
echo "\n=== I. uninstall.php — WYKONANY, nie zgrepowany (C24) ===\n";
// ===========================================================================
$uninstall = __DIR__ . '/../uninstall.php';
if ( file_exists( $uninstall ) ) {
	k19_reset_env();
	if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { define( 'WP_UNINSTALL_PLUGIN', 'ai-faq-generator/ai-faq-generator.php' ); }
	include $uninstall;
	$deleted = $GLOBALS['__optc']->deleted;
	check( array() === array_diff( array( 'aifaq_index_signature', 'aifaq_cache_flushed_for' ), $deleted ), 'NOWE — C24: obie nowe opcje faktycznie SKASOWANE przez uninstall.php (nie substr_count na źródle)' );
	check( 25 === $GLOBALS['__optc']->deletes, 'NOWE — C24: DOKŁADNIE 25 wywołań delete_option (16 + 9 nowych opcji K20; jest: ' . $GLOBALS['__optc']->deletes . ')' );
} else {
	check( false, 'NOWE — C24 pominięta: brak pliku uninstall.php' );
}

// ===========================================================================
echo "\n=== Z. Podłoga pokrycia i wartownik ===\n";
// ===========================================================================
$floor = $ran;
check( $floor >= 28, 'NOWE — wykonano co najmniej 28 asercji (było ' . $floor . ')' );

echo "\nplik dobiegł końca\n";
echo 'Asercje: ' . $ran . ', niezaliczone: ' . $fail . "\n";
exit( 0 === $fail ? 0 : 1 );
