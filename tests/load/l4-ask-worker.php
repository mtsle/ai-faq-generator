<?php
/**
 * L4 worker — JEDNO wywołanie `RagService::ask()` w osobnym procesie OS,
 * z czasem ROZBITYM na składowe: retrieval (Retriever, CPU cosinus+sort) /
 * DB (symulowane opóźnienie I/O w repozytoriach cache/qa_log/knowledge —
 * PLACEHOLDER, realne liczby dopiero w L5 na prawdziwym MySQL, tu tylko
 * żeby uwidocznić PROPORCJE) / provider (mock Gemini, symulowana latencja
 * konfigurowalna, zero sieci) / total (cały `ask()`).
 *
 * Wypisuje JEDNĄ linię CSV na stdout:
 *   status,total_ms,local_ms,db_ms,provider_ms
 * local_ms = total - provider (wszystko poza czekaniem na Gemini: DB + PHP
 * retrievera); db_ms to SUROWA suma symulowanych opóźnień I/O — na Windows
 * zawyżona ziarnistością usleep(), traktować orientacyjnie (patrz L5 dla
 * realnych liczb MySQL). status = answered|refused|error|http429|http500|timeout
 *
 * Argumenty: [provider_latency_ms] [db_latency_ms] [fail_mode]
 * fail_mode: none|429|500|timeout|random10 (10% losowych błędów)
 *
 * @package AI_FAQ_Generator
 */

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
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'wp_unslash' ) ) { function wp_unslash( $s ) { return is_string( $s ) ? stripslashes( $s ) : $s; } }
if ( ! function_exists( 'sanitize_textarea_field' ) ) { function sanitize_textarea_field( $s ) { return trim( (string) preg_replace( '/<[^>]*>/', '', (string) $s ) ); } }
if ( ! function_exists( 'current_time' ) ) { function current_time( $t ) { return '2026-07-30 12:00:00'; } }
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $tag, $value ) { return $value; } }

require __DIR__ . '/../../src/Data/Schema.php';
require __DIR__ . '/../../src/Data/Repository.php';
require __DIR__ . '/../../src/Data/KnowledgeRepository.php';
require __DIR__ . '/../../src/Data/CacheRepository.php';
require __DIR__ . '/../../src/Data/QaLogRepository.php';
require __DIR__ . '/../../src/Providers/ProviderInterface.php';
require __DIR__ . '/../../src/Rag/Retriever.php';
require __DIR__ . '/../../src/Rag/TopicGuard.php';
require __DIR__ . '/../../src/Rag/RateLimiter.php';
require __DIR__ . '/../../src/Rag/Answerer.php';
require __DIR__ . '/../../src/Rag/RagService.php';

use AIFAQ\Data\KnowledgeRepository;
use AIFAQ\Data\CacheRepository;
use AIFAQ\Data\QaLogRepository;
use AIFAQ\Providers\ProviderInterface;
use AIFAQ\Rag\Retriever;
use AIFAQ\Rag\TopicGuard;
use AIFAQ\Rag\RateLimiter;
use AIFAQ\Rag\Answerer;
use AIFAQ\Rag\RagService;

$provider_latency_ms = (float) ( $argv[1] ?? 400 );
$db_latency_ms       = (float) ( $argv[2] ?? 2 );
$fail_mode           = (string) ( $argv[3] ?? 'none' );

$TIME = array( 'db' => 0.0 ); // µs skumulowane przez repozytoria (referencja przez use (&$TIME)).

/** N_CHUNKS fragmentów, wektor pytania trafia dokładnie w id=1 (score=1.0, na pewno "pass" bramki tematu). */
const N_CHUNKS = 800;

class L4Knowledge extends KnowledgeRepository {
	public $db_latency_us;
	public $time_ref;
	public function count_embedded(): int { return N_CHUNKS; }
	public function embeddings_page( int $limit, int $offset ): array {
		$t0 = microtime( true );
		usleep( (int) $this->db_latency_us );
		$this->time_ref['db'] += ( microtime( true ) - $t0 ) * 1000;
		if ( $offset >= N_CHUNKS ) { return array(); }
		$rows = array();
		$end  = min( $offset + $limit, N_CHUNKS );
		for ( $i = $offset; $i < $end; $i++ ) {
			$rows[] = array(
				'id'        => $i + 1,
				'post_id'   => intdiv( $i, 2 ) + 1,
				'content'   => 'Fragment ' . $i . ' o ofercie i cenniku.',
				'embedding' => 0 === $i ? array( 1.0, 0.0, 0.0 ) : array( 0.1, 0.2 * ( $i % 5 ), 0.05 ),
			);
		}
		return $rows;
	}
	public function contents_for( array $ids ): array {
		$out = array();
		foreach ( $ids as $id ) { $out[ $id ] = 'Fragment ' . ( $id - 1 ) . ' o ofercie i cenniku.'; }
		return $out;
	}
}
class L4Cache extends CacheRepository {
	public $db_latency_us;
	public $time_ref;
	public function get_by_question( string $q ): ?array {
		$t0 = microtime( true );
		usleep( (int) $this->db_latency_us );
		$this->time_ref['db'] += ( microtime( true ) - $t0 ) * 1000;
		return null; // zero trafień cache — mierzymy koszt PEŁNEJ ścieżki (najgorszy, ale najczęstszy przy zróżnicowanych pytaniach).
	}
	public function put( string $q, string $a, float $score = 0.0 ): int {
		$t0 = microtime( true );
		usleep( (int) $this->db_latency_us );
		$this->time_ref['db'] += ( microtime( true ) - $t0 ) * 1000;
		return 1;
	}
}
class L4QaLog extends QaLogRepository {
	public $db_latency_us;
	public $time_ref;
	public function log( array $entry ): int {
		$t0 = microtime( true );
		usleep( (int) $this->db_latency_us );
		$this->time_ref['db'] += ( microtime( true ) - $t0 ) * 1000;
		return 1;
	}
}

/** Mock providera: symuluje latencję Gemini + tryby błędów, zero sieci. */
class L4Provider implements ProviderInterface {
	public $latency_us;
	public $fail_mode;
	public $time_provider = 0.0;
	public function __construct( $latency_us, $fail_mode ) { $this->latency_us = $latency_us; $this->fail_mode = $fail_mode; }
	private function should_fail(): bool {
		if ( 'random10' === $this->fail_mode ) { return ( mt_rand( 1, 10 ) === 1 ); }
		return in_array( $this->fail_mode, array( '429', '500', 'timeout' ), true );
	}
	public function generate( string $prompt, array $options = array() ) {
		$t0 = microtime( true );
		usleep( (int) $this->latency_us );
		$this->time_provider += ( microtime( true ) - $t0 ) * 1000;
		if ( $this->should_fail() ) {
			$code = ( '429' === $this->fail_mode ) ? 'aifaq_gemini_rate' : ( ( '500' === $this->fail_mode ) ? 'aifaq_gemini_error' : 'aifaq_gemini_timeout' );
			return new WP_Error( $code, 'symulowany błąd ' . $this->fail_mode );
		}
		return 'Odpowiedź modelu na pytanie testowe.';
	}
	public function embed( array $texts ) {
		$t0 = microtime( true );
		usleep( (int) $this->latency_us );
		$this->time_provider += ( microtime( true ) - $t0 ) * 1000;
		if ( $this->should_fail() ) {
			return new WP_Error( 'aifaq_gemini_embed', 'symulowany błąd embed ' . $this->fail_mode );
		}
		return array( array( 1.0, 0.0, 0.0 ) ); // trafia dokładnie w chunk id=1.
	}
	public function verify() { return true; }
}

$knowledge = new L4Knowledge();
$knowledge->db_latency_us = $db_latency_ms * 1000;
$knowledge->time_ref      = &$TIME;

$cache = new L4Cache();
$cache->db_latency_us = $db_latency_ms * 1000;
$cache->time_ref      = &$TIME;

$qa_log = new L4QaLog();
$qa_log->db_latency_us = $db_latency_ms * 1000;
$qa_log->time_ref      = &$TIME;

$provider = new L4Provider( $provider_latency_ms * 1000, $fail_mode );

$service = new RagService(
	$provider,
	new Retriever( $knowledge ),
	new TopicGuard(),
	new RateLimiter( 0 ), // limiter WYŁĄCZONY — L4 mierzy koszt requestu, nie odbicia limitera (to L3/L6).
	new Answerer( $provider ),
	$knowledge,
	$cache,
	$qa_log,
	array(
		'threshold'      => 0.5,
		'threshold_hard' => 0.3,
		'top_k'          => 5,
		'temperature'    => 0.2,
		'max_tokens'     => 500,
		'language'       => 'pl',
		'refusals'       => array( 'pl' => 'ODMOWA-PL' ),
		'daily_budget'   => 0, // sufit witryny WYŁĄCZONY — izolowany osobno w L3/L6.
	)
);

$t_total0 = microtime( true );
$result   = $service->ask( 'Jaka jest oferta i cennik?', hash( 'sha256', (string) getmypid() ) );
$t_total  = ( microtime( true ) - $t_total0 ) * 1000;

$provider_ms = $provider->time_provider;
// UWAGA metodyczna (Windows): `usleep()` ma tu ziarnistość ~15ms (rozdzielczość
// timera systemowego), więc `db_ms` (zbudowane z KILKU małych usleep) jest
// SYSTEMATYCZNIE zawyżone — nie traktować jako realny czas MySQL (te liczby
// dają L5, na prawdziwej bazie). `local_ms` = total - provider (wszystko poza
// czekaniem na Gemini: DB + czysta praca PHP retrievera) jest bardziej odporne,
// bo to JEDNO odjęcie, nie suma wielu zaszumionych pomiarów. Czysty koszt PHP
// samego retrievera (bez I/O) przy tej skali (800 fragmentów) jest ZANIEDBYWALNY
// — patrz L1 (≈0,1 ms przy N=800, ekstrapolacja z ~115 µs/rekord, potwierdzone
// pomiarem O(N)) — więc local_ms tutaj to w praktyce koszt DB (symulowany).
$db_ms    = $TIME['db'];
$local_ms = max( 0.0, $t_total - $provider_ms );

$status = is_array( $result ) ? (string) ( $result['status'] ?? 'error' ) : 'error';
if ( 'error' === $status && in_array( $fail_mode, array( '429', '500', 'timeout' ), true ) ) {
	$status = 'http' . $fail_mode;
}

echo implode( ',', array( $status, round( $t_total, 3 ), round( $local_ms, 3 ), round( $db_ms, 3 ), round( $provider_ms, 3 ) ) ) . "\n";
