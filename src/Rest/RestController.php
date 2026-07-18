<?php
/**
 * Kontroler REST `aifaq/v1` — warstwa HTTP nad rdzeniem RAG i indekserem.
 *
 * NIE zawiera logiki biznesowej: opakowuje gotowe kontrakty w HTTP, uprawnienia
 * i mapowanie wyniku na kody stanu.
 *  - `POST /aifaq/v1/ask` — publiczne, woła {@see RagService::ask()} (cache,
 *    rate-limit, odmowa off-topic i dziennik są już w środku). Rate-limit → 429,
 *    błąd generacji → 502 (bez surowych komunikatów providera, GR4).
 *  - `GET  /aifaq/v1/admin/status`  — stan bazy wiedzy (cap `manage_options`).
 *  - `POST /aifaq/v1/admin/reindex` — indeksowanie (rdzeń {@see IndexController::run_reindex()}).
 *  - `POST /aifaq/v1/admin/clear`   — czyszczenie bazy wiedzy.
 *  - `GET  /aifaq/v1/admin/history` — dziennik pytań gości (strona + podsumowanie).
 *  - `POST /aifaq/v1/admin/history/clear` — kasowanie całego dziennika.
 *
 * Uwierzytelnianie panelu: REST cookie-auth WordPressa wymaga ważnego
 * `X-WP-Nonce` (akcja `wp_rest`), by `current_user_can()` przeszło — nonce jest
 * więc egzekwowany realnie na `/admin/*` przez rdzeń WP. Publiczne `/ask` jest
 * chronione rate-limitem i walidacją wejścia (nonce na publicznej stronie nie
 * dodaje realnej ochrony — gość i tak pobiera go razem ze stroną).
 *
 * @package AI_FAQ_Generator
 */

namespace AIFAQ\Rest;

use AIFAQ\Admin\IndexController;
use AIFAQ\App\HistoryPanel;
use AIFAQ\Core\Settings;
use AIFAQ\Data\GenerationRepository;
use AIFAQ\Data\QaLogRepository;
use AIFAQ\Faq\Exporter;
use AIFAQ\Faq\FaqGenerator;
use AIFAQ\Providers\ProviderFactory;
use AIFAQ\Rag\RagService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rejestracja i obsługa tras REST wtyczki.
 */
class RestController {

	/**
	 * Przestrzeń nazw REST (wersjonowana).
	 */
	const REST_NAMESPACE = 'aifaq/v1';

	/**
	 * Uprawnienie wymagane dla tras panelu.
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Podpina rejestrację tras pod `rest_api_init` (wywoływane z Plugin::init_hooks).
	 *
	 * Rejestracja musi działać także dla gości — dlatego montowana jest POZA
	 * gałęzią `is_admin()` w loaderze.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Rejestruje wszystkie trasy przestrzeni `aifaq/v1`.
	 */
	public function register_routes(): void {
		// Publiczne: pytanie gościa → odpowiedź zawężona do tematu strony.
		register_rest_route(
			self::REST_NAMESPACE,
			'/ask',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_ask' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'question' => array(
						'required'          => true,
						'type'              => 'string',
						'description'       => __( 'Pytanie gościa dotyczące treści strony.', 'ai-faq-generator' ),
						'validate_callback' => array( $this, 'validate_question' ),
						'sanitize_callback' => 'sanitize_textarea_field',
					),
				),
			)
		);

		// Panel: stan bazy wiedzy.
		register_rest_route(
			self::REST_NAMESPACE,
			'/admin/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_status' ),
				'permission_callback' => array( $this, 'require_admin' ),
			)
		);

		// Panel: indeksowanie treści.
		register_rest_route(
			self::REST_NAMESPACE,
			'/admin/reindex',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_reindex' ),
				'permission_callback' => array( $this, 'require_admin' ),
			)
		);

		// Panel: czyszczenie bazy wiedzy.
		register_rest_route(
			self::REST_NAMESPACE,
			'/admin/clear',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_clear' ),
				'permission_callback' => array( $this, 'require_admin' ),
			)
		);

		// Panel: zapis ustawień (front dzieli kontrakt sanityzacji z kokpitem).
		register_rest_route(
			self::REST_NAMESPACE,
			'/admin/settings',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_settings_save' ),
				'permission_callback' => array( $this, 'require_admin' ),
			)
		);

		// Panel: test połączenia (realny ping klucza).
		register_rest_route(
			self::REST_NAMESPACE,
			'/admin/verify',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_verify' ),
				'permission_callback' => array( $this, 'require_admin' ),
			)
		);

		// Panel: dziennik pytań gości (strona + podsumowanie).
		register_rest_route(
			self::REST_NAMESPACE,
			'/admin/history',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_history' ),
				'permission_callback' => array( $this, 'require_admin' ),
				'args'                => array(
					'page'     => array(
						'type'    => 'integer',
						'default' => 1,
					),
					'per_page' => array(
						'type'    => 'integer',
						'default' => HistoryPanel::PER_PAGE,
					),
					'status'   => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);

		// Panel: kasowanie całego dziennika (dane gości — RODO).
		register_rest_route(
			self::REST_NAMESPACE,
			'/admin/history/clear',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_history_clear' ),
				'permission_callback' => array( $this, 'require_admin' ),
			)
		);

		// Panel: generowanie par FAQ z tematu (narzędzie generatora, Krok 12).
		register_rest_route(
			self::REST_NAMESPACE,
			'/admin/generate-faq',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_generate_faq' ),
				'permission_callback' => array( $this, 'require_admin' ),
				'args'                => array(
					'topic'       => array(
						'required'          => true,
						'type'              => 'string',
						'description'       => __( 'Temat, na który wygenerować FAQ.', 'ai-faq-generator' ),
						'validate_callback' => array( $this, 'validate_topic' ),
						'sanitize_callback' => 'sanitize_text_field',
					),
					'description' => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'count'       => array(
						'type'    => 'integer',
						'default' => 0,
					),
					'language'    => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);

		// Panel: lista historii generowań (Krok 12).
		register_rest_route(
			self::REST_NAMESPACE,
			'/admin/generations',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_generations' ),
				'permission_callback' => array( $this, 'require_admin' ),
				'args'                => array(
					'page'     => array(
						'type'    => 'integer',
						'default' => 1,
					),
					'per_page' => array(
						'type'    => 'integer',
						'default' => 20,
					),
				),
			)
		);

		// Panel: szczegół jednego wpisu historii generowań — z parami (Krok 15).
		register_rest_route(
			self::REST_NAMESPACE,
			'/admin/generations/detail',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_generation_detail' ),
				'permission_callback' => array( $this, 'require_admin' ),
				'args'                => array(
					'id' => array(
						'required' => true,
						'type'     => 'integer',
					),
				),
			)
		);

		// Panel: usunięcie jednego wpisu historii generowań (Krok 12).
		register_rest_route(
			self::REST_NAMESPACE,
			'/admin/generations/delete',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_generations_delete' ),
				'permission_callback' => array( $this, 'require_admin' ),
				'args'                => array(
					'id' => array(
						'required' => true,
						'type'     => 'integer',
					),
				),
			)
		);

		// Panel: eksport bieżących par do 5 formatów (Krok 14).
		register_rest_route(
			self::REST_NAMESPACE,
			'/admin/export',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_export' ),
				'permission_callback' => array( $this, 'require_admin' ),
			)
		);
	}

	/**
	 * Bramka uprawnień tras panelu.
	 *
	 * Przy uwierzytelnianiu ciasteczkami WordPress przepuści to tylko z ważnym
	 * nonce `wp_rest` (`X-WP-Nonce`), więc nonce jest tu egzekwowany realnie.
	 *
	 * @return bool
	 */
	public function require_admin(): bool {
		return current_user_can( self::CAPABILITY );
	}

	/**
	 * Waliduje pytanie gościa (niepuste po sanityzacji, w granicy długości).
	 *
	 * Uruchamiane na surowej wartości PRZED `sanitize_callback`, dlatego stosuje
	 * tę samą sanityzację co {@see RagService}, by odrzucić wejście „samo HTML/
	 * białe znaki" kodem 400 zamiast przepuszczać je do potoku jako błąd 502.
	 *
	 * @param mixed           $value   Surowa wartość parametru.
	 * @param WP_REST_Request $request Żądanie (nieużywane).
	 * @param string          $param   Nazwa parametru (nieużywana).
	 * @return true|WP_Error
	 */
	public function validate_question( $value, $request = null, $param = '' ) {
		$clean = trim( sanitize_textarea_field( wp_unslash( (string) $value ) ) );

		if ( '' === $clean ) {
			return new WP_Error(
				'aifaq_empty_question',
				__( 'Pytanie nie może być puste.', 'ai-faq-generator' ),
				array( 'status' => 400 )
			);
		}

		$len = function_exists( 'mb_strlen' ) ? mb_strlen( $clean ) : strlen( $clean );
		if ( $len > RagService::MAX_QUESTION_LEN ) {
			return new WP_Error(
				'aifaq_question_too_long',
				sprintf(
					/* translators: %d = maksymalna liczba znaków. */
					__( 'Pytanie jest za długie (maksymalnie %d znaków).', 'ai-faq-generator' ),
					RagService::MAX_QUESTION_LEN
				),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Waliduje temat generacji: niepusty po sanityzacji (inaczej 400 zamiast 502).
	 *
	 * @param mixed           $value   Surowa wartość parametru.
	 * @param WP_REST_Request $request Żądanie (nieużywane).
	 * @param string          $param   Nazwa parametru (nieużywana).
	 * @return true|WP_Error
	 */
	public function validate_topic( $value, $request = null, $param = '' ) {
		$clean = trim( sanitize_text_field( wp_unslash( (string) $value ) ) );

		if ( '' === $clean ) {
			return new WP_Error(
				'aifaq_empty_topic',
				__( 'Temat nie może być pusty.', 'ai-faq-generator' ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * `POST /ask` — odpowiada na pytanie gościa (lub odmawia poza tematem).
	 *
	 * @param WP_REST_Request $request Żądanie.
	 * @return WP_REST_Response
	 */
	public function handle_ask( WP_REST_Request $request ): WP_REST_Response {
		$question = (string) $request->get_param( 'question' );
		$result   = RagService::make()->ask( $question, $this->ip_hash() );

		return $this->ask_response( is_array( $result ) ? $result : array() );
	}

	/**
	 * Mapuje wynik {@see RagService::ask()} na odpowiedź HTTP.
	 *
	 * answered|refused → 200; rate-limit → 429; błąd → 502 (komunikat ogólny,
	 * bez surowego błędu providera).
	 *
	 * @param array<string,mixed> $result Wynik potoku RAG.
	 * @return WP_REST_Response
	 */
	private function ask_response( array $result ): WP_REST_Response {
		$status = (string) ( $result['status'] ?? 'error' );
		$source = (string) ( $result['source'] ?? 'ai' );

		if ( 'error' === $status && 'rate_limit' === $source ) {
			return new WP_REST_Response(
				array(
					'status'  => 'rate_limited',
					'message' => __( 'Za dużo zapytań. Spróbuj ponownie za chwilę.', 'ai-faq-generator' ),
				),
				429
			);
		}

		if ( 'error' === $status ) {
			return new WP_REST_Response(
				array(
					'status'  => 'error',
					'message' => __( 'Nie udało się teraz wygenerować odpowiedzi. Spróbuj ponownie później.', 'ai-faq-generator' ),
				),
				502
			);
		}

		return new WP_REST_Response(
			array(
				'status' => $status, // answered | refused
				'answer' => (string) ( $result['answer'] ?? '' ),
				'score'  => round( (float) ( $result['score'] ?? 0 ), 4 ),
				'source' => $source, // ai | cache
				'cached' => ( 'cache' === $source ),
			),
			200
		);
	}

	/**
	 * `GET /admin/status` — statystyki bazy wiedzy i gotowość do indeksowania.
	 *
	 * @param WP_REST_Request $request Żądanie (nieużywane).
	 * @return WP_REST_Response
	 */
	public function handle_status( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'status'      => 'ok',
				'stats'       => IndexController::stats(),
				'indexing'    => (bool) get_transient( IndexController::LOCK ),
				'api_key_set' => '' !== (string) Settings::get_field( 'api_key', '' ),
			),
			200
		);
	}

	/**
	 * `POST /admin/reindex` — indeksuje treść (rdzeń wspólny z akcją AJAX).
	 *
	 * @param WP_REST_Request $request Żądanie (nieużywane).
	 * @return WP_REST_Response
	 */
	public function handle_reindex( WP_REST_Request $request ): WP_REST_Response {
		$result = ( new IndexController() )->run_reindex();

		if ( empty( $result['ok'] ) ) {
			return new WP_REST_Response(
				array(
					'status'  => 'error',
					'message' => (string) ( $result['message'] ?? '' ),
				),
				(int) ( $result['status'] ?? 500 )
			);
		}

		return new WP_REST_Response(
			array(
				'status' => 'ok',
				'report' => $result['report'] ?? array(),
				'stats'  => $result['stats'] ?? array(),
			),
			200
		);
	}

	/**
	 * `POST /admin/clear` — czyści bazę wiedzy (rdzeń wspólny z akcją AJAX).
	 *
	 * @param WP_REST_Request $request Żądanie (nieużywane).
	 * @return WP_REST_Response
	 */
	public function handle_clear( WP_REST_Request $request ): WP_REST_Response {
		$result = ( new IndexController() )->run_clear();

		if ( empty( $result['ok'] ) ) {
			return new WP_REST_Response(
				array(
					'status'  => 'error',
					'message' => (string) ( $result['message'] ?? '' ),
				),
				(int) ( $result['status'] ?? 500 )
			);
		}

		return new WP_REST_Response(
			array(
				'status'  => 'ok',
				'removed' => (int) ( $result['removed'] ?? 0 ),
				'stats'   => $result['stats'] ?? array(),
			),
			200
		);
	}

	/**
	 * `POST /admin/settings` — zapisuje ustawienia (whitelistowany podzbiór z frontu).
	 *
	 * Front (apka `/faqgenerator`) edytuje tylko rdzeń: klucz, model, temperatura,
	 * język. Przekazujemy wyłącznie te pola do {@see Settings::save()} — reszta
	 * (RAG, slug) zostaje nietknięta, a sanityzacja i clamp są wspólne z kokpitem.
	 *
	 * @param WP_REST_Request $request Żądanie.
	 * @return WP_REST_Response
	 */
	public function handle_settings_save( WP_REST_Request $request ): WP_REST_Response {
		$input = array();
		foreach ( array( 'api_key', 'model', 'temperature', 'language' ) as $field ) {
			$value = $request->get_param( $field );
			if ( null !== $value ) {
				$input[ $field ] = $value;
			}
		}

		$saved = Settings::save( $input );

		// Odsyłamy tylko bezpieczne pola (NIGDY klucza) — do potwierdzenia w UI.
		return new WP_REST_Response(
			array(
				'status'   => 'ok',
				'settings' => array(
					'model'       => (string) ( $saved['model'] ?? '' ),
					'temperature' => (float) ( $saved['temperature'] ?? 0 ),
					'language'    => (string) ( $saved['language'] ?? '' ),
					'has_key'     => '' !== (string) ( $saved['api_key'] ?? '' ),
				),
			),
			200
		);
	}

	/**
	 * `POST /admin/verify` — test połączenia (realny ping klucza do Gemini).
	 *
	 * Pusty `api_key` → sprawdzany jest klucz zapisany (pole bywa zamaskowane).
	 * Wynik informacyjny: zawsze HTTP 200 z `status` ok|error + komunikatem
	 * (bramka uprawnień i tak odcina niezalogowanych kodem 401).
	 *
	 * @param WP_REST_Request $request Żądanie.
	 * @return WP_REST_Response
	 */
	public function handle_verify( WP_REST_Request $request ): WP_REST_Response {
		$api_key = (string) $request->get_param( 'api_key' );
		$result  = Settings::verify_key( $api_key );

		if ( is_wp_error( $result ) ) {
			$message = ( 'aifaq_no_key' === $result->get_error_code() )
				? $result->get_error_message()
				/* translators: %s: komunikat błędu z providera */
				: sprintf( __( 'Błąd: %s', 'ai-faq-generator' ), $result->get_error_message() );
			return new WP_REST_Response( array( 'status' => 'error', 'message' => $message ), 200 );
		}

		return new WP_REST_Response(
			array( 'status' => 'ok', 'message' => __( 'Połączenie OK — klucz działa.', 'ai-faq-generator' ) ),
			200
		);
	}

	/**
	 * `GET /admin/history` — strona dziennika pytań + podsumowanie.
	 *
	 * Odsyłamy TYLKO to, co panel pokazuje: treść, status, źródło, trafność i datę.
	 * `ip_hash` i `user_id` zostają w bazie — nie ma powodu wypuszczać
	 * pseudonimowego identyfikatora gościa do przeglądarki (GR7, minimalizacja).
	 *
	 * @param WP_REST_Request $request Żądanie.
	 * @return WP_REST_Response
	 */
	public function handle_history( WP_REST_Request $request ): WP_REST_Response {
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = max( 1, min( 100, (int) $request->get_param( 'per_page' ) ) );
		$status   = (string) $request->get_param( 'status' );
		$status   = in_array( $status, QaLogRepository::STATUSES, true ) ? $status : '';

		$repo  = new QaLogRepository();
		$total = $repo->count_by( $status );
		$pages = (int) ceil( $total / $per_page );

		// Strona poza zakresem (np. po wyczyszczeniu) → cofamy do ostatniej istniejącej.
		if ( $pages > 0 && $page > $pages ) {
			$page = $pages;
		}

		$rows   = $repo->page( $per_page, ( $page - 1 ) * $per_page, $status );
		$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

		$items = array();
		foreach ( $rows as $row ) {
			$items[] = array(
				'id'      => (int) ( $row['id'] ?? 0 ),
				'date'    => mysql2date( $format, (string) ( $row['created_at'] ?? '' ) ),
				'iso'     => (string) ( $row['created_at'] ?? '' ),
				'question' => (string) ( $row['question'] ?? '' ),
				'answer'  => (string) ( $row['answer'] ?? '' ),
				'status'  => (string) ( $row['status'] ?? '' ),
				'source'  => (string) ( $row['source'] ?? '' ),
				'score'   => round( (float) ( $row['score'] ?? 0 ), 2 ),
			);
		}

		return new WP_REST_Response(
			array(
				'status'   => 'ok',
				'items'    => $items,
				'total'    => $total,
				'page'     => $page,
				'pages'    => $pages,
				'per_page' => $per_page,
				'stats'    => $repo->stats(),
			),
			200
		);
	}

	/**
	 * `POST /admin/history/clear` — kasuje cały dziennik pytań.
	 *
	 * @param WP_REST_Request $request Żądanie (nieużywane).
	 * @return WP_REST_Response
	 */
	public function handle_history_clear( WP_REST_Request $request ): WP_REST_Response {
		$repo    = new QaLogRepository();
		$removed = $repo->purge();

		return new WP_REST_Response(
			array(
				'status'  => 'ok',
				'removed' => $removed,
				'stats'   => $repo->stats(),
			),
			200
		);
	}

	/**
	 * `POST /admin/generate-faq` — generuje pary FAQ z tematu i zapisuje historię.
	 *
	 * Kreatywna generacja przez {@see FaqGenerator} (NIE przez RAG). Liczba pytań
	 * klampowana do reguły produktu 5..20 (domyślnie z ustawień). Błąd providera →
	 * 502 (bez surowego komunikatu); brak użytecznych par → 200 ze statusem `empty`;
	 * sukces → 200 z parami + zapis snapshotu w `wp_aifaq_generations`.
	 *
	 * @param WP_REST_Request $request Żądanie.
	 * @return WP_REST_Response
	 */
	public function handle_generate_faq( WP_REST_Request $request ): WP_REST_Response {
		$topic = trim( (string) $request->get_param( 'topic' ) );
		$desc  = (string) $request->get_param( 'description' );
		$count = (int) $request->get_param( 'count' );
		$lang  = (string) $request->get_param( 'language' );

		// Liczba pytań: brak/0 → domyślna z ustawień; potem twardy clamp 5..20.
		if ( $count <= 0 ) {
			$count = (int) Settings::get_field( 'max_questions', 20 );
		}
		$count = max( 5, min( 20, $count ) );

		// Język: tylko z whitelisty; w innym razie z ustawień.
		if ( ! in_array( $lang, array( 'pl', 'en', 'de' ), true ) ) {
			$lang = (string) Settings::get_field( 'language', 'pl' );
		}

		$temperature = (float) Settings::get_field( 'temperature', 0.7 );

		$generator = new FaqGenerator( ProviderFactory::make() );
		$result    = $generator->generate( $topic, $desc, $count, $lang, array( 'temperature' => $temperature ) );

		$status = (string) ( $result['status'] ?? 'error' );
		$pairs  = ( isset( $result['pairs'] ) && is_array( $result['pairs'] ) ) ? $result['pairs'] : array();

		// Błąd providera — nie ujawniamy surowego komunikatu (jak /ask).
		if ( 'error' === $status ) {
			return new WP_REST_Response(
				array(
					'status'  => 'error',
					'message' => __( 'Nie udało się teraz wygenerować FAQ. Spróbuj ponownie później.', 'ai-faq-generator' ),
				),
				502
			);
		}

		// Model nie zwrócił użytecznych par — to nie błąd, informujemy łagodnie.
		if ( 'ok' !== $status || empty( $pairs ) ) {
			return new WP_REST_Response(
				array(
					'status'  => 'empty',
					'message' => __( 'Model nie zwrócił par dla tego tematu. Doprecyzuj temat lub opis.', 'ai-faq-generator' ),
					'pairs'   => array(),
				),
				200
			);
		}

		// Zapis snapshotu generowania (historia + „Ponownie wygeneruj").
		$repo = new GenerationRepository();
		$id   = $repo->log(
			array(
				'topic'         => $topic,
				'extra_desc'    => $desc,
				'num_questions' => count( $pairs ),
				'language'      => $lang,
				'user_id'       => get_current_user_id(),
				'pairs'         => $pairs,
			)
		);

		return new WP_REST_Response(
			array(
				'status' => 'ok',
				'id'     => $id,
				'topic'  => $topic,
				'count'  => count( $pairs ),
				'pairs'  => $pairs,
			),
			200
		);
	}

	/**
	 * `GET /admin/generations` — strona historii generowań (metadane, bez par).
	 *
	 * Lista pokazuje tylko metadane (data/temat/liczba/język/użytkownik) + `extra_desc`
	 * (potrzebny do „Ponownie wygeneruj"). Same pary zostają w snapshotcie i doczytuje
	 * je dopiero widok szczegółu — nie pompujemy ich do każdego wiersza listy.
	 *
	 * @param WP_REST_Request $request Żądanie.
	 * @return WP_REST_Response
	 */
	public function handle_generations( WP_REST_Request $request ): WP_REST_Response {
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = max( 1, min( 100, (int) $request->get_param( 'per_page' ) ) );

		$repo  = new GenerationRepository();
		$total = $repo->count();
		$pages = (int) ceil( $total / $per_page );

		// Strona poza zakresem (np. po usunięciu) → cofamy do ostatniej istniejącej.
		if ( $pages > 0 && $page > $pages ) {
			$page = $pages;
		}

		$rows   = $repo->page( $per_page, ( $page - 1 ) * $per_page );
		$format = $this->datetime_format();

		$items = array();
		foreach ( $rows as $row ) {
			$items[] = $this->generation_item( $row, $format );
		}

		return new WP_REST_Response(
			array(
				'status'   => 'ok',
				'items'    => $items,
				'total'    => $total,
				'page'     => $page,
				'pages'    => $pages,
				'per_page' => $per_page,
			),
			200
		);
	}

	/**
	 * `GET /admin/generations/detail` — jeden wpis historii generowań RAZEM z parami.
	 *
	 * Kształt `item` jest IDENTYCZNY z elementem `items[]` z {@see handle_generations()}
	 * (wspólny builder {@see generation_item()}) plus dodatkowy klucz `pairs` — dzięki
	 * temu front renderuje wiersz listy i wiersz szczegółu jednym kodem.
	 * Brak/niepoprawne `id` → 400, brak wiersza → 404 (bez ujawniania czegokolwiek
	 * o zawartości bazy poza samym faktem nieistnienia wpisu).
	 *
	 * @param WP_REST_Request $request Żądanie.
	 * @return WP_REST_Response
	 */
	public function handle_generation_detail( WP_REST_Request $request ): WP_REST_Response {
		$id = (int) $request->get_param( 'id' );

		if ( $id <= 0 ) {
			return new WP_REST_Response(
				array(
					'status'  => 'error',
					'message' => __( 'Brak poprawnego identyfikatora.', 'ai-faq-generator' ),
				),
				400
			);
		}

		// find() z GenerationRepository dokłada zdekodowany klucz `pairs`.
		$row = ( new GenerationRepository() )->find( $id );

		if ( null === $row ) {
			return new WP_REST_Response(
				array(
					'status'  => 'error',
					'message' => __( 'Nie znaleziono tego wpisu historii.', 'ai-faq-generator' ),
				),
				404
			);
		}

		$item          = $this->generation_item( $row, $this->datetime_format() );
		$item['pairs'] = $this->normalize_pairs( $row['pairs'] ?? array() );

		return new WP_REST_Response(
			array(
				'status' => 'ok',
				'item'   => $item,
			),
			200
		);
	}

	/**
	 * `POST /admin/generations/delete` — usuwa jeden wpis historii generowań.
	 *
	 * @param WP_REST_Request $request Żądanie.
	 * @return WP_REST_Response
	 */
	public function handle_generations_delete( WP_REST_Request $request ): WP_REST_Response {
		$id = (int) $request->get_param( 'id' );

		if ( $id <= 0 ) {
			return new WP_REST_Response(
				array(
					'status'  => 'error',
					'message' => __( 'Brak poprawnego identyfikatora.', 'ai-faq-generator' ),
				),
				400
			);
		}

		$deleted = ( new GenerationRepository() )->delete( $id );

		return new WP_REST_Response(
			array(
				'status'  => 'ok',
				'deleted' => $deleted,
			),
			200
		);
	}

	/**
	 * `POST /admin/export` — formatuje bieżące pary Q&A do 5 formatów eksportu.
	 *
	 * Pary przychodzą z UI (stan lokalny po edycjach/usunięciach). Walidujemy i
	 * sanityzujemy je tutaj (kontrola po stronie klienta ma temu tylko zapobiegać),
	 * a formatowanie robi czysta klasa {@see Exporter}. Pusta/niepoprawna lista →
	 * 400 (bez zgadywania). Sukces → 200 z pięcioma stringami gotowymi do wyświetlenia.
	 *
	 * @param WP_REST_Request $request Żądanie.
	 * @return WP_REST_Response
	 */
	public function handle_export( WP_REST_Request $request ): WP_REST_Response {
		$raw = $request->get_param( 'pairs' );

		$pairs = array();
		if ( is_array( $raw ) ) {
			foreach ( $raw as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}

				$q = $item['question'] ?? '';
				$a = $item['answer'] ?? '';
				if ( ! is_scalar( $q ) || ! is_scalar( $a ) ) {
					continue;
				}

				$q = trim( sanitize_textarea_field( wp_unslash( (string) $q ) ) );
				$a = trim( sanitize_textarea_field( wp_unslash( (string) $a ) ) );
				if ( '' === $q || '' === $a ) {
					continue;
				}

				$pairs[] = array(
					'question' => $q,
					'answer'   => $a,
				);

				if ( count( $pairs ) >= Exporter::MAX_PAIRS ) {
					break;
				}
			}
		}

		if ( empty( $pairs ) ) {
			return new WP_REST_Response(
				array(
					'status'  => 'error',
					'message' => __( 'Brak par do eksportu.', 'ai-faq-generator' ),
				),
				400
			);
		}

		$formats = ( new Exporter() )->export( $pairs );

		return new WP_REST_Response(
			array(
				'status'    => 'ok',
				'html'      => (string) ( $formats['html'] ?? '' ),
				'gutenberg' => (string) ( $formats['gutenberg'] ?? '' ),
				'elementor' => (string) ( $formats['elementor'] ?? '' ),
				'json'      => (string) ( $formats['json'] ?? '' ),
				'jsonld'    => (string) ( $formats['jsonld'] ?? '' ),
			),
			200
		);
	}

	/**
	 * Format daty i godziny wg ustawień WordPressa (jedno miejsce dla obu tras).
	 *
	 * @return string
	 */
	private function datetime_format(): string {
		return get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
	}

	/**
	 * Buduje element historii generowań w kształcie oczekiwanym przez front.
	 *
	 * Wspólny dla listy (`/admin/generations`) i szczegółu (`/admin/generations/detail`)
	 * — jeden kształt, jedno miejsce (wzorzec DRY z `run_reindex`/`run_clear`, K7).
	 * Świadomie NIE odsyłamy `user_id` ani surowego `pairs_json` (minimalizacja, GR7):
	 * front dostaje gotową etykietę `user`, a pary tylko tam, gdzie są potrzebne.
	 *
	 * @param array<string,mixed> $row    Surowy wiersz z repozytorium.
	 * @param string              $format Format daty (patrz {@see datetime_format()}).
	 * @return array<string,mixed>
	 */
	private function generation_item( array $row, string $format ): array {
		return array(
			'id'            => (int) ( $row['id'] ?? 0 ),
			'date'          => mysql2date( $format, (string) ( $row['created_at'] ?? '' ) ),
			'iso'           => (string) ( $row['created_at'] ?? '' ),
			'topic'         => (string) ( $row['topic'] ?? '' ),
			'extra_desc'    => (string) ( $row['extra_desc'] ?? '' ),
			'num_questions' => (int) ( $row['num_questions'] ?? 0 ),
			'language'      => (string) ( $row['language'] ?? '' ),
			'user'          => $this->user_label( (int) ( $row['user_id'] ?? 0 ) ),
		);
	}

	/**
	 * Normalizuje snapshot par ze zdekodowanego `pairs_json` do listy {question, answer}.
	 *
	 * Snapshot to JSON sprzed wielu wersji — nie zakładamy nic o jego kształcie.
	 * Elementy niebędące tablicą oraz nieskalarne `question`/`answer` odrzucamy
	 * (rzutowanie tablicy na string dałoby ostrzeżenie „Array to string conversion"
	 * i śmieci w odpowiedzi — realny błąd złapany w K11), a listę klampujemy do
	 * {@see Exporter::MAX_PAIRS}, tak samo jak eksport.
	 *
	 * @param mixed $raw Zdekodowana zawartość `pairs_json`.
	 * @return array<int,array<string,string>>
	 */
	private function normalize_pairs( $raw ): array {
		$pairs = array();

		if ( ! is_array( $raw ) ) {
			return $pairs;
		}

		foreach ( $raw as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$q = $item['question'] ?? '';
			$a = $item['answer'] ?? '';
			if ( ! is_scalar( $q ) || ! is_scalar( $a ) ) {
				continue;
			}

			// Puste po przycięciu odrzucamy tak samo jak Exporter::normalize() —
			// inaczej para bez pytania wyrenderowałaby się w podglądzie jako pusty wiersz.
			$q = trim( (string) $q );
			$a = trim( (string) $a );
			if ( '' === $q || '' === $a ) {
				continue;
			}

			$pairs[] = array(
				'question' => $q,
				'answer'   => $a,
			);

			if ( count( $pairs ) >= Exporter::MAX_PAIRS ) {
				break;
			}
		}

		return $pairs;
	}

	/**
	 * Etykieta autora generacji do listy (nazwa wyświetlana albo ID, '' dla gościa).
	 *
	 * @param int $user_id Identyfikator użytkownika.
	 * @return string
	 */
	private function user_label( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return '';
		}
		$user = get_userdata( $user_id );
		return ( $user && '' !== $user->display_name ) ? $user->display_name : (string) $user_id;
	}

	/**
	 * Identyfikator gościa: sha256(sól | REMOTE_ADDR) — nie przechowujemy IP (GR7).
	 *
	 * Używa wyłącznie `REMOTE_ADDR` (nagłówki proxy typu X-Forwarded-For są
	 * podszywalne, więc pominięte).
	 *
	 * @return string
	 */
	private function ip_hash(): string {
		$ip   = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$salt = function_exists( 'wp_salt' ) ? wp_salt( 'nonce' ) : 'aifaq';
		return hash( 'sha256', $salt . '|' . $ip );
	}
}
