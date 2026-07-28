<?php
/**
 * Rejestracja 15 tras przestrzeni `aifaq/v1` — sam routing, zero logiki.
 *
 * Wydzielone z {@see RestController} (Krok 23). Wywołania zwrotne i bramki
 * uprawnień CELOWO wskazują na przekazany {@see RestController}, a nie na tę
 * klasę: kontroler pozostaje publicznym API wtyczki (a testy tras sprawdzają
 * `permission_callback[0] instanceof RestController`).
 *
 * @package AI_FAQ_Generator
 */

namespace AIFAQ\Rest;

use AIFAQ\App\HistoryPanel;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deklaracja tras REST wtyczki.
 */
class RouteRegistrar {

	/**
	 * Kontroler pełniący rolę celu wywołań zwrotnych i bramek.
	 *
	 * @var RestController
	 */
	private $controller;

	/**
	 * @param RestController $controller Kontroler-fasada.
	 */
	public function __construct( RestController $controller ) {
		$this->controller = $controller;
	}

	/**
	 * Rejestruje wszystkie trasy przestrzeni `aifaq/v1`.
	 */
	public function register(): void {
		$c = $this->controller;

		// Publiczne: pytanie gościa → odpowiedź zawężona do tematu strony.
		register_rest_route(
			RestController::REST_NAMESPACE,
			'/ask',
			array(
				'methods'             => 'POST',
				'callback'            => array( $c, 'handle_ask' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'question' => array(
						'required'          => true,
						'type'              => 'string',
						'description'       => __( 'Pytanie gościa dotyczące treści strony.', 'ai-faq-generator' ),
						'validate_callback' => array( $c, 'validate_question' ),
						'sanitize_callback' => 'sanitize_textarea_field',
					),
				),
			)
		);

		// Panel: stan bazy wiedzy.
		register_rest_route(
			RestController::REST_NAMESPACE,
			'/admin/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $c, 'handle_status' ),
				'permission_callback' => array( $c, 'require_admin' ),
			)
		);

		// Panel: indeksowanie treści.
		register_rest_route(
			RestController::REST_NAMESPACE,
			'/admin/reindex',
			array(
				'methods'             => 'POST',
				'callback'            => array( $c, 'handle_reindex' ),
				'permission_callback' => array( $c, 'require_admin' ),
			)
		);

		// Panel: czyszczenie bazy wiedzy.
		register_rest_route(
			RestController::REST_NAMESPACE,
			'/admin/clear',
			array(
				'methods'             => 'POST',
				'callback'            => array( $c, 'handle_clear' ),
				'permission_callback' => array( $c, 'require_admin' ),
			)
		);

		// Panel: zapis ustawień (front dzieli kontrakt sanityzacji z kokpitem).
		register_rest_route(
			RestController::REST_NAMESPACE,
			'/admin/settings',
			array(
				'methods'             => 'POST',
				'callback'            => array( $c, 'handle_settings_save' ),
				'permission_callback' => array( $c, 'require_admin' ),
			)
		);

		// Panel: test połączenia (realny ping klucza).
		register_rest_route(
			RestController::REST_NAMESPACE,
			'/admin/verify',
			array(
				'methods'             => 'POST',
				'callback'            => array( $c, 'handle_verify' ),
				'permission_callback' => array( $c, 'require_admin' ),
			)
		);

		// Panel: dziennik pytań gości (strona + podsumowanie).
		register_rest_route(
			RestController::REST_NAMESPACE,
			'/admin/history',
			array(
				'methods'             => 'GET',
				'callback'            => array( $c, 'handle_history' ),
				'permission_callback' => array( $c, 'require_admin' ),
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
			RestController::REST_NAMESPACE,
			'/admin/history/clear',
			array(
				'methods'             => 'POST',
				'callback'            => array( $c, 'handle_history_clear' ),
				'permission_callback' => array( $c, 'require_admin' ),
			)
		);

		// Panel: generowanie par FAQ z tematu (narzędzie generatora, Krok 12).
		// Krok 20: jedna z DWÓCH tras dostępnych także dla Redaktora/Autora.
		register_rest_route(
			RestController::REST_NAMESPACE,
			'/admin/generate-faq',
			array(
				'methods'             => 'POST',
				'callback'            => array( $c, 'handle_generate_faq' ),
				'permission_callback' => array( $c, 'require_tool_user' ),
				'args'                => array(
					'topic'       => array(
						'required'          => true,
						'type'              => 'string',
						'description'       => __( 'Temat, na który wygenerować FAQ.', 'ai-faq-generator' ),
						'validate_callback' => array( $c, 'validate_topic' ),
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
			RestController::REST_NAMESPACE,
			'/admin/generations',
			array(
				'methods'             => 'GET',
				'callback'            => array( $c, 'handle_generations' ),
				'permission_callback' => array( $c, 'require_admin' ),
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
			RestController::REST_NAMESPACE,
			'/admin/generations/detail',
			array(
				'methods'             => 'GET',
				'callback'            => array( $c, 'handle_generation_detail' ),
				'permission_callback' => array( $c, 'require_admin' ),
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
			RestController::REST_NAMESPACE,
			'/admin/generations/delete',
			array(
				'methods'             => 'POST',
				'callback'            => array( $c, 'handle_generations_delete' ),
				'permission_callback' => array( $c, 'require_admin' ),
				'args'                => array(
					'id' => array(
						'required' => true,
						'type'     => 'integer',
					),
				),
			)
		);

		// Panel: eksport bieżących par do 5 formatów (Krok 14).
		// Krok 20: druga z DWÓCH tras narzędzia — `handle_export()` jest czysta
		// (nie czyta bazy, ustawień ani klucza API), więc poluzowanie jest bezpieczne.
		register_rest_route(
			RestController::REST_NAMESPACE,
			'/admin/export',
			array(
				'methods'             => 'POST',
				'callback'            => array( $c, 'handle_export' ),
				'permission_callback' => array( $c, 'require_tool_user' ),
			)
		);

		// Publikacja par na podstronie generatora — jedyna treść tej strony, którą
		// widzi wyszukiwarka. Cap PUBLIKACJI (Krok 23), wyżej niż cap narzędzia:
		// generowanie/eksport zostaje dla Autora, samo pokazanie treści gościom
		// wymaga Redaktora — patrz {@see RestController::CAPABILITY_PUBLISH}.
		register_rest_route(
			RestController::REST_NAMESPACE,
			'/admin/faq/publish',
			array(
				'methods'             => 'POST',
				'callback'            => array( $c, 'handle_faq_publish' ),
				'permission_callback' => array( $c, 'require_publish_user' ),
			)
		);

		register_rest_route(
			RestController::REST_NAMESPACE,
			'/admin/faq/unpublish',
			array(
				'methods'             => 'POST',
				'callback'            => array( $c, 'handle_faq_unpublish' ),
				'permission_callback' => array( $c, 'require_publish_user' ),
			)
		);
	}
}
