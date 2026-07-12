<?php
/**
 * RagService — publiczna fasada rdzenia RAG.
 *
 * Spina cały potok pytania gościa: sanityzacja → cache → rate-limit → embedding
 * pytania → retrieval → bramka tematu → generacja odpowiedzi → zapis cache i
 * dziennika. Kolejność egzekwuje koszt (GR5): cache PRZED generacją, rate-limit
 * PRZED API. Zwraca ustrukturyzowany wynik; zero wyjątków (GR7).
 *
 * To jest de-facto kontrakt konsumowany przez Krok 7 (REST `/ask`).
 *
 * @package AI_FAQ_Generator
 */

namespace AIFAQ\Rag;

use AIFAQ\Core\Settings;
use AIFAQ\Data\CacheRepository;
use AIFAQ\Data\KnowledgeRepository;
use AIFAQ\Data\QaLogRepository;
use AIFAQ\Providers\ProviderFactory;
use AIFAQ\Providers\ProviderInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Orkiestracja: pytanie gościa → odpowiedź zawężona do tematu strony lub odmowa.
 */
class RagService {

	/**
	 * Górny limit długości pytania (znaki) — ochrona przed nadużyciem.
	 */
	const MAX_QUESTION_LEN = 2000;

	/** @var ProviderInterface */
	private $provider;

	/** @var Retriever */
	private $retriever;

	/** @var TopicGuard */
	private $guard;

	/** @var RateLimiter */
	private $limiter;

	/** @var Answerer */
	private $answerer;

	/** @var KnowledgeRepository */
	private $knowledge;

	/** @var CacheRepository */
	private $cache;

	/** @var QaLogRepository */
	private $qa_log;

	/**
	 * Konfiguracja RAG: threshold, top_k, temperature, max_tokens, language, refusals[].
	 *
	 * @var array<string,mixed>
	 */
	private $config;

	/**
	 * @param ProviderInterface   $provider  Dostawca AI.
	 * @param Retriever           $retriever Wyszukiwarka fragmentów.
	 * @param TopicGuard          $guard     Bramka tematu.
	 * @param RateLimiter         $limiter   Limiter zapytań.
	 * @param Answerer            $answerer  Generator odpowiedzi.
	 * @param KnowledgeRepository $knowledge Repozytorium wiedzy (dociąganie treści).
	 * @param CacheRepository     $cache     Cache odpowiedzi.
	 * @param QaLogRepository     $qa_log    Dziennik pytań.
	 * @param array<string,mixed> $config    Konfiguracja RAG.
	 */
	public function __construct(
		ProviderInterface $provider,
		Retriever $retriever,
		TopicGuard $guard,
		RateLimiter $limiter,
		Answerer $answerer,
		KnowledgeRepository $knowledge,
		CacheRepository $cache,
		QaLogRepository $qa_log,
		array $config
	) {
		$this->provider  = $provider;
		$this->retriever = $retriever;
		$this->guard     = $guard;
		$this->limiter   = $limiter;
		$this->answerer  = $answerer;
		$this->knowledge = $knowledge;
		$this->cache     = $cache;
		$this->qa_log    = $qa_log;
		$this->config    = $config;
	}

	/**
	 * Buduje serwis z realnych zależności (Settings + ProviderFactory + repozytoria).
	 *
	 * @return self
	 */
	public static function make(): self {
		$provider  = ProviderFactory::make();
		$knowledge = new KnowledgeRepository();

		$config = array(
			'threshold'   => (float) Settings::get_field( 'rag_threshold', 0.7 ),
			'top_k'       => (int) Settings::get_field( 'rag_top_k', 5 ),
			'temperature' => (float) Settings::get_field( 'rag_temperature', 0.2 ),
			'max_tokens'  => (int) Settings::get_field( 'rag_max_tokens', 500 ),
			'language'    => (string) Settings::get_field( 'language', 'pl' ),
			'refusals'    => array(
				'pl' => (string) Settings::get_field( 'rag_refusal_message_pl', '' ),
				'en' => (string) Settings::get_field( 'rag_refusal_message_en', '' ),
				'de' => (string) Settings::get_field( 'rag_refusal_message_de', '' ),
			),
		);

		return new self(
			$provider,
			new Retriever( $knowledge ),
			new TopicGuard(),
			new RateLimiter( (int) Settings::get_field( 'rag_rate_limit', 30 ) ),
			new Answerer( $provider ),
			$knowledge,
			new CacheRepository(),
			new QaLogRepository(),
			$config
		);
	}

	/**
	 * Odpowiada na pytanie gościa (lub odmawia, gdy poza tematem strony).
	 *
	 * @param string $question Pytanie gościa (surowe).
	 * @param string $ip_hash  Identyfikator gościa (sha256; hashowanie po stronie wywołującego, GR7).
	 * @return array{status:string,answer:string,score:float,source:string}
	 *         status = 'answered'|'refused'|'error'; source = 'ai'|'cache'|'rate_limit'.
	 */
	public function ask( string $question, string $ip_hash ): array {
		$q = $this->sanitize_question( $question );
		if ( '' === $q ) {
			return $this->result( 'error', '', 0.0, 'ai' );
		}

		// 1) Cache PRZED generacją (GR5) — identyczne pytanie nie płaci drugi raz.
		$cached = $this->cache->get_by_question( $q );
		if ( is_array( $cached ) && '' !== (string) ( $cached['answer'] ?? '' ) ) {
			$answer = (string) $cached['answer'];
			$this->log( $q, $answer, 'answered', 'cache', 1.0, $ip_hash );
			return $this->result( 'answered', $answer, 1.0, 'cache' );
		}

		// 2) Rate-limit PRZED API (GR5) — ochrona kosztu/klucza.
		if ( ! $this->limiter->allow( $ip_hash ) ) {
			$this->log( $q, '', 'error', 'rate_limit', 0.0, $ip_hash );
			return $this->result( 'error', '', 0.0, 'rate_limit' );
		}
		$this->limiter->hit( $ip_hash );

		// 3) Embedding pytania — ten sam provider/model/768 co dokumenty (GR3).
		$embed = $this->provider->embed( array( $q ) );
		if ( is_wp_error( $embed ) || ! isset( $embed[0] ) || ! is_array( $embed[0] ) ) {
			$this->log( $q, '', 'error', 'ai', 0.0, $ip_hash );
			return $this->result( 'error', '', 0.0, 'ai' );
		}
		$vector = $embed[0];

		// 4) Retrieval + bramka tematu.
		$results  = $this->retriever->retrieve( $vector, (int) $this->config['top_k'] );
		$decision = $this->guard->evaluate( $results, (float) $this->config['threshold'] );
		$score    = (float) $decision['score'];

		if ( 'pass' !== $decision['decision'] ) {
			$msg = $this->refusal_message();
			$this->log( $q, $msg, 'refused', 'ai', $score, $ip_hash );
			return $this->result( 'refused', $msg, $score, 'ai' );
		}

		// 5) Odpowiedź osadzona w kontekście trafnych fragmentów.
		$contents = $this->knowledge->contents_for( $decision['ids'] );
		$answer   = $this->answerer->answer(
			$q,
			array_values( $contents ),
			array(
				'temperature' => (float) $this->config['temperature'],
				'max_tokens'  => (int) $this->config['max_tokens'],
				'language'    => (string) $this->config['language'],
			)
		);

		if ( 'answered' === $answer['status'] && '' !== $answer['answer'] ) {
			$this->cache->put( $q, $answer['answer'] );
			$this->log( $q, $answer['answer'], 'answered', 'ai', $score, $ip_hash );
			return $this->result( 'answered', $answer['answer'], $score, 'ai' );
		}

		if ( 'refused' === $answer['status'] ) {
			$msg = $this->refusal_message();
			$this->log( $q, $msg, 'refused', 'ai', $score, $ip_hash );
			return $this->result( 'refused', $msg, $score, 'ai' );
		}

		// Błąd generacji — nie zmyślamy (GR4).
		$this->log( $q, '', 'error', 'ai', $score, $ip_hash );
		return $this->result( 'error', '', $score, 'ai' );
	}

	/**
	 * Sanityzuje i przycina pytanie gościa (GR7).
	 *
	 * @param string $question Surowe pytanie.
	 * @return string
	 */
	private function sanitize_question( string $question ): string {
		$q = trim( sanitize_textarea_field( wp_unslash( $question ) ) );
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $q, 0, self::MAX_QUESTION_LEN );
		}
		return substr( $q, 0, self::MAX_QUESTION_LEN );
	}

	/**
	 * Komunikat odmowy dla bieżącego języka (fallback pl → dowolny niepusty → sztywny).
	 *
	 * @return string
	 */
	private function refusal_message(): string {
		$lang     = (string) $this->config['language'];
		$refusals = isset( $this->config['refusals'] ) && is_array( $this->config['refusals'] )
			? $this->config['refusals']
			: array();

		if ( '' !== (string) ( $refusals[ $lang ] ?? '' ) ) {
			return (string) $refusals[ $lang ];
		}
		if ( '' !== (string) ( $refusals['pl'] ?? '' ) ) {
			return (string) $refusals['pl'];
		}
		foreach ( $refusals as $msg ) {
			if ( '' !== (string) $msg ) {
				return (string) $msg;
			}
		}
		return 'Przepraszam, potrafię odpowiadać wyłącznie na pytania dotyczące tej strony.';
	}

	/**
	 * Zapis wpisu do dziennika (GR7 — ip_hash, nie surowe IP).
	 *
	 * @param string $question Pytanie.
	 * @param string $answer   Odpowiedź (lub pusta).
	 * @param string $status   answered|refused|error.
	 * @param string $source   ai|cache|rate_limit.
	 * @param float  $score    Wynik podobieństwa.
	 * @param string $ip_hash  Identyfikator gościa.
	 */
	private function log( string $question, string $answer, string $status, string $source, float $score, string $ip_hash ): void {
		$this->qa_log->log(
			array(
				'question' => $question,
				'answer'   => $answer,
				'status'   => $status,
				'source'   => $source,
				'score'    => $score,
				'ip_hash'  => $ip_hash,
			)
		);
	}

	/**
	 * Buduje ustrukturyzowany wynik.
	 *
	 * @param string $status answered|refused|error.
	 * @param string $answer Odpowiedź.
	 * @param float  $score  Wynik podobieństwa.
	 * @param string $source ai|cache|rate_limit.
	 * @return array{status:string,answer:string,score:float,source:string}
	 */
	private function result( string $status, string $answer, float $score, string $source ): array {
		return array(
			'status' => $status,
			'answer' => $answer,
			'score'  => $score,
			'source' => $source,
		);
	}
}
