<?php
/**
 * RagService — publiczna fasada rdzenia RAG.
 *
 * Spina cały potok pytania gościa: sanityzacja → cache → limiter gościa → dobowy
 * sufit witryny → embedding pytania → retrieval → bramka tematu → generacja
 * odpowiedzi → zapis cache i dziennika. Kolejność egzekwuje koszt (GR5): cache
 * PRZED generacją, obie bramki limitów PRZED API. Zwraca ustrukturyzowany wynik;
 * zero wyjątków (GR7).
 *
 * To jest de-facto kontrakt konsumowany przez Krok 7 (REST `/ask`).
 *
 * Krok 19 (kamienie M0, M3, M4):
 *  - `debug` — 9-kluczowa diagnostyka za capem `manage_options` (bez niej nie dało się
 *    orzec, czy winny jest prompt, próg czy pusty kontekst);
 *  - kolejność trafności odtwarzana U WYWOŁUJĄCEGO, bo `contents_for()` oddaje mapę
 *    bez `ORDER BY` — dawne `array_values()` gubiło ranking w jednej linii;
 *  - dwa progi (twardy = podłoga dopuszczalności, miękki = pełne pokrycie);
 *  - 429 dostawcy przestaje udawać awarię, a blokada bezpieczeństwa — odmowę techniczną.
 *
 * Krok 20 (obszar C):
 *  - dobowy sufit CAŁEJ witryny (`rag_daily_budget`) — jedna jednostka na pytanie
 *    gościa, nie na wywołanie dostawcy; właściciel z sufitu wyłączony;
 *  - nieudane żądanie nie kradnie jednostki: gość i witryna dostają zwrot, ale
 *    WYŁĄCZNIE gdy żądanie realnie opuściło proces (`http_status !== 0`).
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

	/**
	 * Podłoga progu miękkiego dla ścieżki /ask.
	 *
	 * Zapisana w bazie wartość niższa (np. 0,35 z czasów strojenia) czyniła oba progi
	 * martwymi: `coverage` byłoby zawsze `'full'`, ścieżka częściowego pokrycia nie
	 * wykonałaby się ani razu, a filtr top-K przepuszczałby balast. Właściciel, który
	 * świadomie chce niżej, zdejmuje podłogę filtrem `aifaq_min_threshold`.
	 */
	const ASK_MIN_THRESHOLD = 0.70;

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
	 * Konfiguracja RAG: threshold, threshold_hard, top_k, temperature, max_tokens,
	 * thinking_budget, language, refusals[].
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
		// Wariant providera dla ZAPYTAŃ wolno włączyć dopiero, gdy baza wektorów została
		// policzona tym samym podpisem indeksu — inaczej pytanie i dokumenty żyłyby
		// w dwóch różnych przestrzeniach. Dopóki podpis się nie zgadza, ścieżka pytania
		// jest bit w bit dzisiejsza.
		$sig_now = '';
		if ( class_exists( '\AIFAQ\Admin\IndexController' ) ) {
			$sig_now = (string) \AIFAQ\Admin\IndexController::index_signature();
		}
		$sig_saved = function_exists( 'get_option' ) ? (string) get_option( 'aifaq_index_signature', '' ) : '';

		$provider = ProviderFactory::make();
		if ( '' !== $sig_saved && '' !== $sig_now && $sig_saved === $sig_now
			&& method_exists( '\AIFAQ\Providers\ProviderFactory', 'make_for_query' ) ) {
			$provider = ProviderFactory::make_for_query();
		}

		$knowledge = new KnowledgeRepository();

		$hard = (float) Settings::get_field( 'rag_threshold_hard', 0.55 );
		if ( function_exists( 'apply_filters' ) ) {
			$hard = (float) apply_filters( 'aifaq_threshold_hard', $hard );
		}

		// Okno limitera gościa: 'godzina' (domyślnie) albo 'doba'. Domyślną zostawiamy
		// godzinę świadomie — instalacje sprzed K20 mają zapisane `rag_rate_limit = 30`,
		// które przy oknie dobowym oznaczałoby 24-krotne zaostrzenie bez jednego słowa
		// ostrzeżenia. Pulę u dostawcy chroni dobowy sufit witryny, nie to okno.
		$window = ( 'doba' === (string) Settings::get_field( 'rag_rate_window', 'godzina' ) ) ? 86400 : 3600;

		$config = array(
			'threshold'       => self::soft_threshold( (float) Settings::get_field( 'rag_threshold', 0.7 ) ),
			'threshold_hard'  => $hard,
			'top_k'           => (int) Settings::get_field( 'rag_top_k', 5 ),
			'temperature'     => (float) Settings::get_field( 'rag_temperature', 0.2 ),
			'max_tokens'      => (int) Settings::get_field( 'rag_max_tokens', 500 ),
			'thinking_budget' => (int) Settings::get_field( 'rag_thinking_budget', 0 ),
			'language'        => (string) Settings::get_field( 'language', 'pl' ),
			// Dobowy sufit witryny. Czytany TUTAJ, jak każde inne ustawienie RAG —
			// `ask()` nie sięga do Settings, więc serwis zbudowany wprost (harnessy,
			// integracje) ma sufit wyłączony i nie może paść na braku shimów.
			'daily_budget'    => (int) Settings::get_field( 'rag_daily_budget', 12 ),
			'refusals'        => array(
				'pl' => (string) Settings::get_field( 'rag_refusal_message_pl', '' ),
				'en' => (string) Settings::get_field( 'rag_refusal_message_en', '' ),
				'de' => (string) Settings::get_field( 'rag_refusal_message_de', '' ),
			),
		);

		return new self(
			$provider,
			new Retriever( $knowledge ),
			new TopicGuard(),
			new RateLimiter( (int) Settings::get_field( 'rag_rate_limit', 10 ), null, $window ),
			new Answerer( $provider ),
			$knowledge,
			new CacheRepository(),
			new QaLogRepository(),
			$config
		);
	}

	/**
	 * Nakłada podłogę na próg miękki. Operacja idempotentna — wolno ją wywołać
	 * zarówno przy budowie konfiguracji, jak i przy jej odczycie.
	 *
	 * @param float $soft Próg z ustawień albo z konfiguracji.
	 * @return float
	 */
	private static function soft_threshold( float $soft ): float {
		$min = self::ASK_MIN_THRESHOLD;
		if ( function_exists( 'apply_filters' ) ) {
			$min = (float) apply_filters( 'aifaq_min_threshold', $min );
		}
		return max( $soft, $min );
	}

	/**
	 * Odpowiada na pytanie gościa (lub odmawia, gdy poza tematem strony).
	 *
	 * @param string $question Pytanie gościa (surowe).
	 * @param string $ip_hash  Identyfikator gościa (sha256; hashowanie po stronie wywołującego, GR7).
	 * @return array{status:string,answer:string,score:float,source:string,debug:array<string,mixed>}
	 *         status = 'answered'|'refused'|'error';
	 *         source = 'ai'|'cache'|'rate_limit'|'provider_rate_limit';
	 *         debug  = diagnostyka §2.5 dla właściciela, array() dla gościa.
	 */
	public function ask( string $question, string $ip_hash ): array {
		$q = $this->sanitize_question( $question );
		if ( '' === $q ) {
			return $this->result( 'error', '', 0.0, 'ai' );
		}

		$threshold = self::soft_threshold( (float) $this->config['threshold'] );
		$hard      = (float) ( $this->config['threshold_hard'] ?? 0.0 );

		$debug = array(
			'stage'          => 'ok',
			'threshold'      => $threshold,
			'threshold_hard' => $hard,
			'coverage'       => '',
			'top_k'          => array(),
			'used_ids'       => array(),
			'context_chars'  => 0,
			'provider'       => array(),
			'answer_raw'     => '',
		);

		// 1) Cache PRZED generacją (GR5) — identyczne pytanie nie płaci drugi raz.
		$cached = $this->cache->get_by_question( $q );
		if ( is_array( $cached ) && '' !== (string) ( $cached['answer'] ?? '' ) ) {
			$answer          = (string) $cached['answer'];
			$debug['stage']  = 'cache';
			$this->log( $q, $answer, 'answered', 'cache', 1.0, $ip_hash );
			return $this->finish( 'answered', $answer, 1.0, 'cache', $debug );
		}

		// 2) Limiter gościa PRZED API (GR5) — ochrona kosztu/klucza.
		if ( ! $this->limiter->allow( $ip_hash ) ) {
			$debug['stage'] = 'rate_limit';
			$this->log( $q, '', 'error', 'rate_limit', 0.0, $ip_hash );
			return $this->finish( 'error', '', 0.0, 'rate_limit', $debug );
		}

		// 3) Dobowy sufit CAŁEJ witryny — po limicie gościa, PRZED inkrementacją
		//    czegokolwiek: odbicie na sufcie nie ma prawa zjeść jednostki gościa.
		//    Przekroczenie mapuje się na ISTNIEJĄCY `rate_limit` (→ HTTP 429);
		//    nowa wartość `source` rozjechałaby REST i front, które w tym Kroku
		//    nie zmieniają się o ani jeden bajt.
		if ( ! $this->budget_allows() ) {
			$debug['stage'] = 'rate_limit';
			$this->log( $q, '', 'error', 'rate_limit', 0.0, $ip_hash );
			return $this->finish( 'error', '', 0.0, 'rate_limit', $debug );
		}

		// Obie jednostki (gościa i witryny) pobieramy w JEDNYM miejscu — dopiero za
		// obiema bramkami i wciąż przed pierwszym wyjściem do dostawcy.
		$this->limiter->hit( $ip_hash );
		$this->budget_hit();

		// 4) Embedding pytania — ten sam provider, model i wymiar co dokumenty (GR3).
		//    Od Kroku 19 różnić się może wyłącznie ZADANIE embeddingu (taskType), i tylko
		//    wtedy, gdy podpis indeksu potwierdza, że baza policzona jest tą samą metodą.
		$embed = $this->provider->embed( array( $q ) );
		if ( is_wp_error( $embed ) || ! isset( $embed[0] ) || ! is_array( $embed[0] ) ) {
			$debug['stage']    = 'embed';
			$debug['provider'] = $this->provider_meta();
			$this->refund_unit( $ip_hash );

			// Embedding idzie PIERWSZY w każdym żądaniu, więc to najczęstsze miejsce
			// trafienia w limit dostawcy. Kod błędu jest tu WYŁĄCZNIE z WP_Error.
			$embed_err = is_wp_error( $embed ) ? (string) $embed->get_error_code() : '';
			if ( in_array( $embed_err, array( 'aifaq_gemini_rate', 'aifaq_gemini_busy' ), true ) ) {
				$this->log( $q, '', 'error', 'provider_rate_limit', 0.0, $ip_hash );
				return $this->finish( 'error', '', 0.0, 'provider_rate_limit', $debug );
			}

			$this->log( $q, '', 'error', 'ai', 0.0, $ip_hash );
			return $this->finish( 'error', '', 0.0, 'ai', $debug );
		}
		$vector = $embed[0];

		// 5) Retrieval + bramka tematu (dwa progi, filtr balastu per fragment).
		$results = $this->retriever->retrieve( $vector, (int) $this->config['top_k'] );

		$filter_ids = true;
		if ( function_exists( 'apply_filters' ) ) {
			$filter_ids = (bool) apply_filters( 'aifaq_topk_filter', $filter_ids, $hard );
		}

		$decision = $this->guard->evaluate( $results, $threshold, $hard, $filter_ids );
		$score    = (float) $decision['score'];

		$debug['coverage'] = (string) ( $decision['coverage'] ?? '' );
		$debug['top_k']    = $this->top_k_debug( $results, (array) $decision['ids'] );

		if ( 'pass' !== $decision['decision'] ) {
			$debug['stage']    = 'guard';
			$debug['provider'] = $this->provider_meta();
			$msg               = $this->refusal_message();
			$this->log( $q, $msg, 'refused', 'ai', $score, $ip_hash );
			return $this->finish( 'refused', $msg, $score, 'ai', $debug );
		}

		// 6) Kolejność trafności odtworzona TUTAJ — contents_for() zwraca mapę id => treść
		//    bez ORDER BY, więc bez tej pętli najtrafniejszy fragment lądował w środku
		//    kontekstu. Ta sama pętla buduje nagłówki źródeł, tym samym licznikiem.
		$order_on = true;
		if ( function_exists( 'apply_filters' ) ) {
			$order_on = (bool) apply_filters( 'aifaq_context_order', $order_on );
		}

		$contents = $this->knowledge->contents_for( $decision['ids'] );
		$ordered  = array();
		$sources  = array();
		$used_ids = array();

		if ( $order_on ) {
			foreach ( $decision['ids'] as $id ) {
				// Wiersz skasowany między retrievalem a odczytem daje BRAK klucza w mapie.
				if ( ! isset( $contents[ $id ] ) ) {
					continue;
				}
				$ordered[]  = (string) $contents[ $id ];
				$used_ids[] = (int) $id;
				$pid        = (int) ( $decision['post_ids'][ $id ] ?? 0 );
				if ( $pid > 0 && function_exists( 'get_the_title' ) ) {
					$sources[ count( $ordered ) - 1 ] = array(
						'title' => (string) get_the_title( $pid ),
						'url'   => function_exists( 'get_permalink' ) ? (string) get_permalink( $pid ) : '',
					);
				}
			}
		} else {
			$ordered  = array_values( $contents );
			$used_ids = array_map( 'intval', array_keys( $contents ) );
		}

		$debug['used_ids']      = $used_ids;
		$debug['context_chars'] = $this->context_chars( $ordered );

		$answer = $this->answerer->answer(
			$q,
			$ordered,
			array(
				'temperature'     => (float) $this->config['temperature'],
				'max_tokens'      => (int) $this->config['max_tokens'],
				'language'        => (string) $this->config['language'],
				'thinking_budget' => (int) ( $this->config['thinking_budget'] ?? 0 ),
				'sources'         => $sources,
			)
		);

		$debug['provider']   = ( isset( $answer['meta'] ) && is_array( $answer['meta'] ) ) ? $answer['meta'] : array();
		$debug['answer_raw'] = $this->answer_raw();

		$guard_on = true;
		if ( function_exists( 'apply_filters' ) ) {
			$guard_on = (bool) apply_filters( 'aifaq_truncation_guard', $guard_on );
		}
		$truncated = $guard_on && ! empty( $answer['meta']['truncated'] );

		if ( 'answered' === $answer['status'] && '' !== $answer['answer'] ) {
			// Odpowiedzi uciętej nie UTRWALAMY — cache nie ma TTL, więc połowa zdania
			// zostałaby na stałe. Zwracamy ją mimo to: bywa użyteczna.
			if ( ! $truncated ) {
				$this->cache->put( $q, $answer['answer'] );
			}
			$this->log( $q, $answer['answer'], 'answered', 'ai', $score, $ip_hash );
			return $this->finish( 'answered', $answer['answer'], $score, 'ai', $debug );
		}

		$debug['stage'] = 'answer';
		$gen_err        = (string) ( $answer['meta']['error_code'] ?? '' );

		// Blokada bezpieczeństwa to NIE awaria — gość dostaje uprzejmą odmowę, nie 502.
		$blocked_as_refusal = true;
		if ( function_exists( 'apply_filters' ) ) {
			$blocked_as_refusal = (bool) apply_filters( 'aifaq_blocked_as_refusal', $blocked_as_refusal );
		}
		if ( 'aifaq_gemini_blocked' === $gen_err && $blocked_as_refusal ) {
			$msg = $this->refusal_message();
			$this->log( $q, $msg, 'refused', 'ai', $score, $ip_hash );
			return $this->finish( 'refused', $msg, $score, 'ai', $debug );
		}

		if ( 'refused' === $answer['status'] ) {
			$msg = $this->refusal_message();
			$this->log( $q, $msg, 'refused', 'ai', $score, $ip_hash );
			return $this->finish( 'refused', $msg, $score, 'ai', $debug );
		}

		if ( in_array( $gen_err, array( 'aifaq_gemini_rate', 'aifaq_gemini_busy' ), true ) ) {
			$this->refund_unit( $ip_hash );
			$this->log( $q, '', 'error', 'provider_rate_limit', $score, $ip_hash );
			return $this->finish( 'error', '', $score, 'provider_rate_limit', $debug );
		}

		// Błąd generacji — nie zmyślamy (GR4).
		$this->refund_unit( $ip_hash );
		$this->log( $q, '', 'error', 'ai', $score, $ip_hash );
		return $this->finish( 'error', '', $score, 'ai', $debug );
	}

	/**
	 * Zwraca jednostkę gościa i witryny po nieudanym żądaniu.
	 *
	 * WARUNEK JEST ZAMKNIĘTY: zwracamy wyłącznie wtedy, gdy żądanie realnie
	 * opuściło proces. Po naprawie wyłącznika obwodu (K20, obszar F) dostawca
	 * oddaje `WP_Error` bez wyjścia do sieci przez cały cooldown — przy limicie
	 * dobowym nawet 3600 s. Reguła „zwracaj przy każdym błędzie" oznaczałaby, że
	 * przez ten czas żaden licznik nie rośnie, a każde żądanie i tak robi SELECT
	 * cache'u i INSERT do `qa_log`: bot dostałby nielimitowany endpoint piszący
	 * do bazy klienta. Brak `last_meta()` → NIE zwracamy (bezpieczna strona).
	 *
	 * @param string $ip_hash Identyfikator gościa.
	 */
	private function refund_unit( string $ip_hash ): void {
		if ( ! method_exists( $this->provider, 'last_meta' ) ) {
			return;
		}
		$meta = $this->provider->last_meta();
		if ( ! is_array( $meta ) || 0 === (int) ( $meta['http_status'] ?? 0 ) ) {
			return;
		}
		$this->limiter->refund( $ip_hash );
		$this->budget_refund();
	}

	/**
	 * Czy MECHANIZM dobowego sufitu jest w ogóle włączony w tym żądaniu.
	 *
	 * Kolejność warunków jest istotna: przy `rag_daily_budget = 0` (sufit
	 * wyłączony, np. klucz płatny) NIE wykonuje się ani jeden odczyt opcji.
	 * Brak którejkolwiek funkcji WordPressa oznacza „sufit nieaktywny", nigdy
	 * błąd krytyczny — `ask()` bywa wykonywane w czystym PHP CLI.
	 *
	 * ROZDZIELENIE OD BRAMKI jest celowe (§13.12). Licznik zużycia ma widzieć
	 * KAŻDE pytanie, które realnie zjada pulę u dostawcy — także pytanie
	 * właściciela zadane z kokpitu. Licznik, który tych pytań nie widzi, kłamie:
	 * sufit dla gości zamyka się za późno, a ślad `aifaq_budget_hit` zapala się
	 * po czasie. Wyłączenie właściciela dotyczy WYŁĄCZNIE decyzji o odbiciu
	 * i mieszka w {@see budget_active()}.
	 *
	 * @return bool
	 */
	private function budget_enabled(): bool {
		$budget = (int) ( $this->config['daily_budget'] ?? 0 );
		if ( $budget <= 0 ) {
			return false;
		}
		if ( defined( 'AIFAQ_TESTING' ) ) {
			return false;
		}
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' )
			|| ! function_exists( 'current_time' ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Czy BRAMKA sufitu obowiązuje tego pytającego.
	 *
	 * Właściciel jest z odbijania JAWNIE wyłączony — inaczej klient, który po
	 * wyczerpaniu puli testuje własną wtyczkę, zgłosi to jako awarię. Jednostkę
	 * mimo to płaci: patrz {@see budget_enabled()} i §13.12.
	 *
	 * @return bool
	 */
	private function budget_active(): bool {
		if ( ! $this->budget_enabled() ) {
			return false;
		}
		return ! ( function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ) );
	}

	/**
	 * Zużycie doby (kształt `array( 'd' => 'RRRR-MM-DD', 'n' => int )`).
	 * Inna data w opcji → licznik startuje od zera.
	 *
	 * @return array{d:string,n:int}
	 */
	private function budget_usage(): array {
		$today = (string) current_time( 'Y-m-d' );
		$raw   = get_option( 'aifaq_daily_usage', array() );

		if ( ! is_array( $raw ) || (string) ( $raw['d'] ?? '' ) !== $today ) {
			return array(
				'd' => $today,
				'n' => 0,
			);
		}
		return array(
			'd' => $today,
			'n' => max( 0, (int) ( $raw['n'] ?? 0 ) ),
		);
	}

	/**
	 * Bramka sufitu. Przy przekroczeniu zostawia właścicielowi ślad `aifaq_budget_hit`
	 * (data `RRRR-MM-DD`, ten sam zegar co licznik) — bez niego sufit byłby
	 * niewidzialną awarią: gość widzi 429, a klient nie wie dlaczego.
	 *
	 * @return bool
	 */
	private function budget_allows(): bool {
		if ( ! $this->budget_active() ) {
			return true;
		}
		$usage = $this->budget_usage();
		if ( $usage['n'] < (int) $this->config['daily_budget'] ) {
			return true;
		}
		update_option( 'aifaq_budget_hit', (string) current_time( 'Y-m-d' ), false );
		return false;
	}

	/**
	 * Pobiera jednostkę witryny (jedno pytanie gościa = jedna jednostka,
	 * niezależnie od tego, ile wywołań dostawcy się za nim kryje).
	 */
	private function budget_hit(): void {
		// `budget_enabled()`, nie `budget_active()` — pytanie właściciela też zjada
		// pulę dostawcy, więc też musi zwiększyć licznik (§13.12, znalezisko O-92).
		if ( ! $this->budget_enabled() ) {
			return;
		}
		$usage = $this->budget_usage();
		update_option(
			'aifaq_daily_usage',
			array(
				'd' => $usage['d'],
				'n' => $usage['n'] + 1,
			),
			false
		);
	}

	/**
	 * Oddaje jednostkę witryny. Nigdy poniżej zera; przy zerze — no-op.
	 */
	private function budget_refund(): void {
		// Symetrycznie do `budget_hit()`: skoro jednostkę pobrano także właścicielowi,
		// to przy błędzie dostawcy trzeba mu ją oddać.
		if ( ! $this->budget_enabled() ) {
			return;
		}
		$usage = $this->budget_usage();
		if ( $usage['n'] <= 0 ) {
			return;
		}
		update_option(
			'aifaq_daily_usage',
			array(
				'd' => $usage['d'],
				'n' => $usage['n'] - 1,
			),
			false
		);
	}

	/**
	 * Jedno wyjście z potoku: log diagnostyczny + bramka widoczności `debug`.
	 *
	 * Dwie bramki są NIEZALEŻNE: `error_log()` zależy od WP_DEBUG i filtra, a widoczność
	 * w odpowiedzi HTTP — wyłącznie od uprawnień. Struktura budowana jest zawsze, bo na
	 * produkcji żądanie `/ask` idzie od gościa i inaczej nie byłoby czego zalogować.
	 *
	 * @param string              $status answered|refused|error.
	 * @param string              $answer Odpowiedź.
	 * @param float               $score  Wynik podobieństwa.
	 * @param string              $source ai|cache|rate_limit|provider_rate_limit.
	 * @param array<string,mixed> $debug  Diagnostyka §2.5.
	 * @return array<string,mixed>
	 */
	private function finish( string $status, string $answer, float $score, string $source, array $debug ): array {
		$log_on = defined( 'WP_DEBUG' ) && WP_DEBUG;
		if ( function_exists( 'apply_filters' ) ) {
			$log_on = (bool) apply_filters( 'aifaq_rag_debug', $log_on );
		}
		if ( $log_on && array() !== $debug ) {
			error_log( self::debug_line( $debug ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		$visible = ( function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ) )
			? $debug
			: array();

		return $this->result( $status, $answer, $score, $source, $visible );
	}

	/**
	 * Jedna linia diagnostyki do `error_log()` — parsowalna, bez PII, bez klucza API.
	 *
	 * Dokładnie 15 par `klucz=`. `think=` to tokeny, które model zużył na rozumowanie,
	 * `sent=` to budżet, który MY wysłaliśmy (-2 = pola nie wysłano) — dopiero obie
	 * wartości razem odróżniają „myślenie wyłączone" od „pole odrzucone przez model".
	 * Treść pytania, odpowiedzi ani `answer_raw` NIE trafiają tutaj nigdy.
	 *
	 * @param array<string,mixed> $debug Struktura §2.5.
	 * @return string
	 */
	public static function debug_line( array $debug ): string {
		$prov  = ( isset( $debug['provider'] ) && is_array( $debug['provider'] ) ) ? $debug['provider'] : array();
		$top_k = ( isset( $debug['top_k'] ) && is_array( $debug['top_k'] ) ) ? $debug['top_k'] : array();
		$used  = ( isset( $debug['used_ids'] ) && is_array( $debug['used_ids'] ) ) ? $debug['used_ids'] : array();

		$best = 0.0;
		foreach ( $top_k as $hit ) {
			$s = (float) ( $hit['score'] ?? 0.0 );
			if ( $s > $best ) {
				$best = $s;
			}
		}

		return 'AIFAQ/RAG'
			. ' stage=' . self::scrub( $debug['stage'] ?? '' )
			. ' cov=' . self::scrub( $debug['coverage'] ?? '' )
			. ' best=' . sprintf( '%.4f', $best )
			. ' hard=' . sprintf( '%.4f', (float) ( $debug['threshold_hard'] ?? 0.0 ) )
			. ' soft=' . sprintf( '%.4f', (float) ( $debug['threshold'] ?? 0.0 ) )
			. ' topk=' . count( $top_k )
			. ' used=' . count( $used )
			. ' ctx=' . (int) ( $debug['context_chars'] ?? 0 )
			. ' finish=' . self::scrub( $prov['finish_reason'] ?? '' )
			. ' think=' . (int) ( $prov['thoughts_tokens'] ?? 0 )
			. ' sent=' . (int) ( $prov['thinking_sent'] ?? -2 )
			. ' out=' . (int) ( $prov['output_tokens'] ?? 0 )
			. ' http=' . (int) ( $prov['http_status'] ?? 0 )
			. ' err=' . self::scrub( $prov['error_code'] ?? '' )
			. ' retries=' . (int) ( $prov['retries'] ?? 0 );
	}

	/**
	 * Czyści wartość z jedynych znaków, które mogłyby rozjechać licznik par `klucz=`.
	 *
	 * @param mixed $value Wartość.
	 * @return string
	 */
	private static function scrub( $value ): string {
		return str_replace( array( '=', "\n", "\r" ), '', (string) $value );
	}

	/**
	 * Pełne top-K do diagnostyki — z flagą, czy fragment realnie wszedł do promptu.
	 *
	 * @param array<int,array<string,mixed>> $results Trafienia Retrievera (przed filtrem).
	 * @param array<int,int>                 $ids     Id, które przeszły bramkę.
	 * @return array<int,array{id:int,post_id:int,score:float,used:bool}>
	 */
	private function top_k_debug( array $results, array $ids ): array {
		$out = array();
		foreach ( $results as $r ) {
			$id    = (int) ( $r['id'] ?? 0 );
			$out[] = array(
				'id'      => $id,
				'post_id' => (int) ( $r['post_id'] ?? 0 ),
				'score'   => (float) ( $r['score'] ?? 0.0 ),
				'used'    => in_array( $id, $ids, true ),
			);
		}
		return $out;
	}

	/**
	 * Długość bloku kontekstu przekazanego do Answerera (znaki, nie bajty).
	 *
	 * @param array<int,string> $ordered Fragmenty w kolejności trafności.
	 * @return int
	 */
	private function context_chars( array $ordered ): int {
		$blob = implode( "\n\n", $ordered );
		return function_exists( 'mb_strlen' ) ? (int) mb_strlen( $blob ) : strlen( $blob );
	}

	/**
	 * Metadane ostatniego wywołania providera (kanał boczny, poza ProviderInterface).
	 *
	 * @return array<string,mixed>
	 */
	private function provider_meta(): array {
		if ( ! method_exists( $this->provider, 'last_meta' ) ) {
			return array();
		}
		$m = $this->provider->last_meta();
		return is_array( $m ) ? $m : array();
	}

	/**
	 * Surowy tekst modelu, przycięty do 500 znaków. Jedno miejsce cięcia.
	 *
	 * @return string
	 */
	private function answer_raw(): string {
		if ( ! method_exists( $this->answerer, 'last_raw' ) ) {
			return '';
		}
		$raw = (string) $this->answerer->last_raw();
		return function_exists( 'mb_substr' ) ? mb_substr( $raw, 0, 500 ) : substr( $raw, 0, 500 );
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
	 * @param string $source   ai|cache|rate_limit|provider_rate_limit.
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
	 * @param string              $status answered|refused|error.
	 * @param string              $answer Odpowiedź.
	 * @param float               $score  Wynik podobieństwa.
	 * @param string              $source ai|cache|rate_limit|provider_rate_limit.
	 * @param array<string,mixed> $debug  Diagnostyka widoczna dla wywołującego (array() = brak).
	 * @return array{status:string,answer:string,score:float,source:string,debug:array<string,mixed>}
	 */
	private function result( string $status, string $answer, float $score, string $source, array $debug = array() ): array {
		return array(
			'status' => $status,
			'answer' => $answer,
			'score'  => $score,
			'source' => $source,
			'debug'  => $debug,
		);
	}
}
