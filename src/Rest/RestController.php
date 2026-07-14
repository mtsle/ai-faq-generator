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
use AIFAQ\Core\Settings;
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
