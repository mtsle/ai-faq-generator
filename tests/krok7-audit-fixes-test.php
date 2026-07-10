<?php
/**
 * Testy regresyjne poprawek z AUDYTU (Krok 7, v0.7.0).
 *
 * Pokrywają zachowania naprawione po audycie Kroków 0–5:
 *  - H1: pusty wynik źródła NIE kasuje bazy wiedzy (Indexer + delete_missing).
 *  - H3: błąd zapisu fragmentów → błąd w raporcie, NIE „indexed".
 *  - M1: zmiana podpisu embeddingów (model/wymiary) wymusza ponowne embedowanie.
 *  - M5: CacheRepository::put() NADPISUJE przy tym samym pytaniu (upsert), bez duplikatu.
 *  - M11: Settings::sanitize() zachowuje bieżące wartości przy braku/niepoprawnym polu.
 *  - M3: pusty submit klucza API zachowuje zapisany klucz; niepusty go zmienia.
 *  - M7: generate() zwraca WP_Error przy blockReason / finishReason=SAFETY; MAX_TOKENS przechodzi.
 *  - M10: GeminiProvider wysyła timeout; WpHttpClient klampuje 0/za duży.
 *  - M13: GeminiProvider::verify() → true przy 200, WP_Error przy błędzie.
 *
 * URUCHOMIENIE:  php -d extension=mbstring tests/krok7-audit-fixes-test.php
 * Kod wyjścia: 0 = OK, 1 = błędy.
 *
 * @package AI_FAQ_Generator
 */

// --- shimy WP (wspólne) ---
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code; private $message;
		public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
		public function get_error_message() { return $this->message; }
		public function get_error_code() { return $this->code; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $t ) { return $t instanceof WP_Error; } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $d ) { return json_encode( $d ); } }
if ( ! function_exists( 'current_time' ) ) { function current_time( $t ) { return '2026-07-10 00:00:00'; } }
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ) { return trim( (string) preg_replace( '/[\r\n\t]+/', ' ', wp_strip_all_tags_shim( (string) $s ) ) ); } }
if ( ! function_exists( 'wp_strip_all_tags_shim' ) ) { function wp_strip_all_tags_shim( $s ) { return (string) preg_replace( '/<[^>]*>/', '', $s ); } }
if ( ! function_exists( 'wp_unslash' ) ) { function wp_unslash( $s ) { return is_string( $s ) ? stripslashes( $s ) : $s; } }
if ( ! function_exists( 'sanitize_title' ) ) { function sanitize_title( $s ) { $s = strtolower( trim( (string) $s ) ); $s = preg_replace( '/[^a-z0-9]+/', '-', $s ); return trim( (string) $s, '-' ); } }

$fail = 0;
function check( $cond, $label ) {
	global $fail;
	echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n";
	if ( ! $cond ) { $fail++; }
}

require __DIR__ . '/../src/Data/Schema.php';
require __DIR__ . '/../src/Data/Repository.php';
require __DIR__ . '/../src/Data/KnowledgeRepository.php';
require __DIR__ . '/../src/Data/CacheRepository.php';
require __DIR__ . '/../src/Http/HttpClient.php';
require __DIR__ . '/../src/Providers/ProviderInterface.php';
require __DIR__ . '/../src/Providers/GeminiProvider.php';
require __DIR__ . '/../src/Index/ContentSource.php';
require __DIR__ . '/../src/Index/Chunker.php';
require __DIR__ . '/../src/Index/EmbeddingBatcher.php';
require __DIR__ . '/../src/Index/Indexer.php';
require __DIR__ . '/../src/Core/Settings.php';

use AIFAQ\Data\KnowledgeRepository;
use AIFAQ\Data\CacheRepository;
use AIFAQ\Index\Chunker;
use AIFAQ\Index\ContentSource;
use AIFAQ\Index\EmbeddingBatcher;
use AIFAQ\Index\Indexer;
use AIFAQ\Providers\ProviderInterface;
use AIFAQ\Providers\GeminiProvider;
use AIFAQ\Core\Settings;

/**
 * Atrapa $wpdb dla bazy wiedzy (tabela w pamięci). Dodatkowo tryb „fail_insert".
 */
class AF_Wpdb {
	public $prefix = 'wp_';
	public $insert_id = 0;
	public $rows = array();
	public $fail_insert = false;
	private $auto = 0;
	private $lastArgs = array();
	public function insert( $table, $data ) {
		if ( $this->fail_insert ) { return false; }
		$this->rows[] = array_merge( array( 'id' => ++$this->auto ), $data );
		$this->insert_id = $this->auto;
		return 1;
	}
	public function delete( $table, $where, $fmt = null ) {
		$before = count( $this->rows );
		$this->rows = array_values( array_filter( $this->rows, function ( $r ) use ( $where ) {
			foreach ( $where as $k => $v ) { if ( (string) ( $r[ $k ] ?? null ) !== (string) $v ) { return true; } }
			return false;
		} ) );
		return $before - count( $this->rows );
	}
	public function query( $sql ) {
		if ( false !== stripos( $sql, 'NOT IN' ) ) {
			$keep = array_map( 'intval', $this->lastArgs );
			$before = count( $this->rows );
			$this->rows = array_values( array_filter( $this->rows, function ( $r ) use ( $keep ) {
				return in_array( (int) $r['post_id'], $keep, true );
			} ) );
			return $before - count( $this->rows );
		}
		if ( false !== stripos( $sql, 'DELETE FROM' ) ) { $n = count( $this->rows ); $this->rows = array(); return $n; }
		return 0; // START TRANSACTION / COMMIT / ROLLBACK — no-op.
	}
	public function prepare( $sql, ...$args ) { $this->lastArgs = $args; return $sql; }
	public function get_results( $sql, $o = null ) {
		if ( false !== stripos( $sql, 'chunk_index, content_hash' ) ) {
			$pid = (int) ( $this->lastArgs[0] ?? 0 ); $out = array();
			foreach ( $this->rows as $r ) { if ( (int) $r['post_id'] === $pid ) { $out[] = array( 'chunk_index' => $r['chunk_index'], 'content_hash' => $r['content_hash'] ); } }
			return $out;
		}
		if ( false !== stripos( $sql, 'embedding IS NOT NULL' ) ) {
			$out = array();
			foreach ( $this->rows as $r ) { if ( null !== ( $r['embedding'] ?? null ) ) { $out[] = array( 'id' => $r['id'], 'post_id' => $r['post_id'], 'embedding' => $r['embedding'] ); } }
			return $out;
		}
		return array();
	}
	public function get_row( $sql, $o = null ) { return null; }
	public function get_var( $sql ) { return 0; }
}

/** Atrapa źródła treści. */
class AF_Source implements ContentSource {
	public $docs = array();
	public function documents(): array { return $this->docs; }
}

/** Atrapa providera: liczy embed; wektor = [długość tekstu]. */
class AF_Provider implements ProviderInterface {
	public $embed_calls = 0;
	public function generate( string $prompt, array $options = array() ) { return ''; }
	public function verify() { return true; }
	public function embed( array $texts ) {
		++$this->embed_calls;
		$v = array();
		foreach ( $texts as $t ) { $v[] = array( (float) strlen( $t ) ); }
		return $v;
	}
}

// ===========================================================================
echo "=== H1. Pusty wynik źródła NIE kasuje bazy wiedzy ===\n";
global $wpdb;
$wpdb = new AF_Wpdb();
$repo = new KnowledgeRepository();
$src  = new AF_Source();
$prov = new AF_Provider();
$idx  = new Indexer( $src, new Chunker( 60, 15 ), new EmbeddingBatcher( $prov, 100 ), $repo );

$src->docs = array( array( 'post_id' => 1, 'title' => 'A', 'url' => 'u', 'text' => 'Krowa daje mleko rano i wieczorem w oborze każdego dnia.' ) );
$idx->run();
$before = count( $repo->all_with_embeddings() );
check( $before > 0, "przygotowano bazę ($before fragm.)" );

$src->docs = array(); // źródło nagle puste (np. wszystko w szkicach / filtr wtyczki).
$rEmpty = $idx->run();
check( 0 === $rEmpty['pruned'], "pruned=0 przy pustym źródle (brak wipe)" );
check( ! empty( $rEmpty['warnings'] ), "raport ma ostrzeżenie o pominiętym pruningu" );
check( $before === count( $repo->all_with_embeddings() ), "baza wiedzy NIETKNIĘTA (nie skasowana)" );
check( 0 === $repo->delete_missing( array() ), "delete_missing([]) = no-op (0), nie clear_all" );

echo "\n=== H3. Błąd zapisu fragmentów → błąd w raporcie, nie 'indexed' ===\n";
$wpdb = new AF_Wpdb();
$repo2 = new KnowledgeRepository();
$src2  = new AF_Source();
$src2->docs = array( array( 'post_id' => 5, 'title' => 'B', 'url' => 'u', 'text' => 'Byk jest bardzo silny i groźny dla całego stada krów.' ) );
$idx2  = new Indexer( $src2, new Chunker( 40, 10 ), new EmbeddingBatcher( new AF_Provider(), 100 ), $repo2 );
$wpdb->fail_insert = true; // każdy insert pada → replace_for_post = 0 (ROLLBACK).
$rFail = $idx2->run();
check( 0 === $rFail['indexed'], "indexed=0 przy nieudanym zapisie" );
check( ! empty( $rFail['errors'] ), "błąd zapisu trafia do errors (nie cichy sukces)" );
check( 0 === count( $repo2->all_with_embeddings() ), "nic nie zapisane w bazie" );

echo "\n=== M1. Zmiana podpisu embeddingów wymusza ponowne embedowanie ===\n";
$wpdb = new AF_Wpdb();
$repoM = new KnowledgeRepository();
$srcM  = new AF_Source();
$srcM->docs = array( array( 'post_id' => 1, 'title' => 'C', 'url' => 'u', 'text' => 'Krowa rasy HF daje dużo mleka i dobrze znosi warunki obory.' ) );
$provM = new AF_Provider();
$idxA  = new Indexer( $srcM, new Chunker( 50, 10 ), new EmbeddingBatcher( $provM, 100 ), $repoM, 'gemini|gemini-embedding-001|768' );
$idxB  = new Indexer( $srcM, new Chunker( 50, 10 ), new EmbeddingBatcher( $provM, 100 ), $repoM, 'gemini|inny-model|1536' );
$idxA->run();
$callsA = $provM->embed_calls;
$idxA->run(); // ten sam podpis → skip.
check( $provM->embed_calls === $callsA, "ten sam podpis → skip (bez ponownego embed)" );
$idxB->run(); // inny podpis → mimo tej samej treści re-embed.
check( $provM->embed_calls > $callsA, "inny podpis modelu/wymiarów → ponowne embedowanie" );

echo "\n=== M5. CacheRepository::put() nadpisuje (upsert), bez duplikatu ===\n";
/** Atrapa $wpdb dla cache: egzekwuje UNIQUE(question_hash) + ON DUPLICATE KEY UPDATE. */
class AF_CacheWpdb {
	public $prefix = 'wp_';
	public $insert_id = 0;
	public $store = array(); // hash => row
	private $auto = 0;
	private $lastArgs = array();
	private $lastSql = '';
	public function prepare( $sql, ...$args ) { $this->lastSql = $sql; $this->lastArgs = $args; return $sql; }
	public function query( $sql ) {
		if ( false !== stripos( $sql, 'ON DUPLICATE KEY' ) ) {
			list( $hash, $question, $answer, $created ) = $this->lastArgs;
			if ( isset( $this->store[ $hash ] ) ) {
				$this->store[ $hash ]['answer'] = $answer; // UPDATE answer=VALUES(answer)
				$this->insert_id = $this->store[ $hash ]['id'];
			} else {
				$id = ++$this->auto;
				$this->store[ $hash ] = array( 'id' => $id, 'question_hash' => $hash, 'question' => $question, 'answer' => $answer, 'hits' => 0, 'created_at' => $created );
				$this->insert_id = $id;
			}
			return 1;
		}
		return 0;
	}
	public function get_row( $sql, $o = null ) {
		$hash = (string) ( $this->lastArgs[0] ?? '' );
		return $this->store[ $hash ] ?? null;
	}
}
$wpdb = new AF_CacheWpdb();
$cache = new CacheRepository();
$cache->put( 'Ile mleka daje krowa?', 'Około 25 litrów dziennie.' );
$row1 = $cache->get_by_question( 'Ile mleka daje krowa?' );
check( is_array( $row1 ) && 'Około 25 litrów dziennie.' === $row1['answer'], "pierwszy put() zapisany i odczytany" );
$cache->put( 'ile   MLEKA   daje krowa?', 'Nawet 30 litrów dziennie.' ); // ten sam hash (normalizacja).
$row2 = $cache->get_by_question( 'Ile mleka daje krowa?' );
check( 'Nawet 30 litrów dziennie.' === $row2['answer'], "drugi put() NADPISAŁ odpowiedź (upsert)" );
check( 1 === count( $wpdb->store ), "brak duplikatu — dokładnie 1 wiersz dla tego pytania" );

echo "\n=== M11 + M3. Settings::sanitize() zachowuje bieżące wartości ===\n";
global $__aifaq_options;
$__aifaq_options = array();
if ( ! function_exists( 'get_option' ) ) { function get_option( $k, $d = false ) { global $__aifaq_options; return $__aifaq_options[ $k ] ?? $d; } }
if ( ! function_exists( 'update_option' ) ) { function update_option( $k, $v, $a = null ) { global $__aifaq_options; $__aifaq_options[ $k ] = $v; return true; } }
// „Bieżące" ustawienia: niedomyślny model + zapisany klucz.
$__aifaq_options[ Settings::OPTION ] = array(
	'model'     => 'gemini-2.5-pro', // niedomyślny (default = gemini-2.5-flash)
	'api_key'   => 'ZAPISANY-KLUCZ',
	'language'  => 'en',
	'page_slug' => 'wiedza',
);
// Submit BEZ pola model i z pustym kluczem (jak po zamaskowaniu pola).
$out = Settings::sanitize( array( 'temperature' => 0.5, 'api_key' => '' ) );
check( 'gemini-2.5-pro' === $out['model'], "brak pola model → zachowana bieżąca wartość (nie reset do default)" );
check( 'en' === $out['language'], "brak pola language → zachowany bieżący język" );
check( 'ZAPISANY-KLUCZ' === $out['api_key'], "pusty submit klucza → zachowany zapisany klucz (M3)" );
// Submit z niepoprawnym modelem → też zachowuje bieżącą wartość.
$out2 = Settings::sanitize( array( 'model' => 'model-widmo', 'embed_model' => 'x' ) );
check( 'gemini-2.5-pro' === $out2['model'], "niepoprawny model → zachowana bieżąca wartość" );
// Niepusty klucz → zmiana.
$out3 = Settings::sanitize( array( 'api_key' => 'NOWY-KLUCZ' ) );
check( 'NOWY-KLUCZ' === $out3['api_key'], "niepusty submit klucza → klucz zmieniony" );
// on_settings_updated: zmiana slugu ustawia flagę flush.
$s = new Settings();
delete_flush_flag();
$s->on_settings_updated( array( 'page_slug' => 'stary' ), array( 'page_slug' => 'nowy' ) );
check( '1' === (string) get_option( Settings::FLUSH_FLAG ), "zmiana slugu → flaga flush ustawiona (H2)" );
delete_flush_flag();
$s->on_settings_updated( array( 'page_slug' => 'ten-sam' ), array( 'page_slug' => 'ten-sam' ) );
check( false === get_option( Settings::FLUSH_FLAG ), "slug bez zmian → brak flagi flush" );
function delete_flush_flag() { global $__aifaq_options; unset( $__aifaq_options[ AIFAQ\Core\Settings::FLUSH_FLAG ] ); }

echo "\n=== M7 + M13. GeminiProvider: blockReason / finishReason / verify ===\n";
/** Atrapa transportu: oddaje zaprogramowaną odpowiedź, zapisuje ostatnie żądanie. */
class AF_Http implements \AIFAQ\Http\HttpClient {
	public $last = null;
	private $resp;
	public function __construct( $resp ) { $this->resp = $resp; }
	public function request( string $method, string $url, array $options = array() ) { $this->last = compact( 'method', 'url', 'options' ); return $this->resp; }
}
// M7: blockReason → WP_Error blocked.
$hpB = new AF_Http( array( 'status' => 200, 'body' => json_encode( array( 'promptFeedback' => array( 'blockReason' => 'SAFETY' ) ) ) ) );
$pB  = new GeminiProvider( $hpB, 'K', 'm', 'e' );
$rB  = $pB->generate( 'x' );
check( is_wp_error( $rB ) && 'aifaq_gemini_blocked' === $rB->get_error_code(), "promptFeedback.blockReason → WP_Error 'blocked'" );
// M7: finishReason=SAFETY bez parts → blocked (nie 'parse').
$hpS = new AF_Http( array( 'status' => 200, 'body' => json_encode( array( 'candidates' => array( array( 'finishReason' => 'SAFETY' ) ) ) ) ) );
$rS  = ( new GeminiProvider( $hpS, 'K', 'm', 'e' ) )->generate( 'x' );
check( is_wp_error( $rS ) && 'aifaq_gemini_blocked' === $rS->get_error_code(), "finishReason=SAFETY → WP_Error 'blocked'" );
// M7: MAX_TOKENS z tekstem → zwraca (częściowy) tekst.
$hpM = new AF_Http( array( 'status' => 200, 'body' => json_encode( array( 'candidates' => array( array( 'finishReason' => 'MAX_TOKENS', 'content' => array( 'parts' => array( array( 'text' => 'czesc' ) ) ) ) ) ) ) ) );
$rM  = ( new GeminiProvider( $hpM, 'K', 'm', 'e' ) )->generate( 'x' );
check( 'czesc' === $rM, "finishReason=MAX_TOKENS z tekstem → zwraca tekst" );
// M10: żądanie niesie timeout > 0.
check( isset( $hpM->last['options']['timeout'] ) && $hpM->last['options']['timeout'] > 0, "generate() wysyła jawny timeout" );
// M13: verify() 200 → true.
$hpV = new AF_Http( array( 'status' => 200, 'body' => '{}' ) );
$pV  = new GeminiProvider( $hpV, 'K', 'm', 'e' );
check( true === $pV->verify(), "verify() przy 200 → true" );
check( 'GET' === $hpV->last['method'], "verify() używa GET (lista modeli)" );
check( false === strpos( $hpV->last['url'], 'K' ), "verify(): klucz NIE w URL" );
check( 'K' === ( $hpV->last['options']['headers']['x-goog-api-key'] ?? null ), "verify(): klucz w nagłówku" );
// M13: verify() 401 → WP_Error z komunikatem API.
$hpV2 = new AF_Http( array( 'status' => 401, 'body' => json_encode( array( 'error' => array( 'message' => 'API key not valid' ) ) ) ) );
$rV2  = ( new GeminiProvider( $hpV2, 'BAD', 'm', 'e' ) )->verify();
check( is_wp_error( $rV2 ) && 'API key not valid' === $rV2->get_error_message(), "verify() przy 401 → WP_Error z komunikatem API" );

echo "\n=== M10. WpHttpClient klampuje timeout ===\n";
global $__af_last_args;
$__af_last_args = null;
if ( ! function_exists( 'wp_remote_request' ) ) { function wp_remote_request( $url, $args ) { global $__af_last_args; $__af_last_args = $args; return array( 'ok' => 1 ); } }
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) { function wp_remote_retrieve_response_code( $r ) { return 200; } }
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) { function wp_remote_retrieve_body( $r ) { return ''; } }
require __DIR__ . '/../src/Http/WpHttpClient.php';
$client = new \AIFAQ\Http\WpHttpClient();
$client->request( 'POST', 'https://example.test', array( 'timeout' => 0 ) );
check( 15 === $__af_last_args['timeout'], "timeout=0 → domyślny 15 (nie 'w nieskończoność')" );
$client->request( 'POST', 'https://example.test', array( 'timeout' => 9999 ) );
check( 120 === $__af_last_args['timeout'], "timeout=9999 → przycięty do 120" );
$client->request( 'POST', 'https://example.test', array() );
check( 15 === $__af_last_args['timeout'], "brak timeout → domyślny 15" );

echo "\n=== PODSUMOWANIE ===\n";
echo ( 0 === $fail ) ? "TEST KROK 7 (poprawki audytu): WSZYSTKIE ASERCJE OK\n" : "TEST KROK 7 (poprawki audytu): $fail ASERCJI NIE PRZESZŁO\n";
exit( $fail === 0 ? 0 : 1 );
