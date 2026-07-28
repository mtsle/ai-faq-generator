<?php
/**
 * Kontroler REST `aifaq/v1` — warstwa HTTP nad rdzeniem RAG i indekserem.
 *
 * NIE zawiera logiki biznesowej. Po Kroku 23 zostały tu wyłącznie trzy rzeczy:
 * uprawnienia (stałe capów + bramki), walidacja parametrów wejściowych i cienkie
 * wywołania zwrotne tras. Deklaracja samych tras siedzi w {@see RouteRegistrar},
 * a składanie odpowiedzi w klasach usługowych tej samej przestrzeni nazw
 * ({@see AskService}, {@see AdminService}, {@see GeneratorService},
 * {@see PublishService}). Kontroler pozostaje publicznym API wtyczki — kod i testy
 * z poprzednich kroków wołają go bez zmian.
 *
 * Zarejestrowanych jest 15 tras (stan po Kroku 23; pełna lista w `RouteRegistrar`):
 *  - `POST /aifaq/v1/ask` — publiczne, woła {@see RagService::ask()} (cache,
 *    rate-limit, odmowa off-topic i dziennik są już w środku). Rate-limit → 429,
 *    błąd generacji → 502 (bez surowych komunikatów providera, GR4).
 *  - `/aifaq/v1/admin/{status,reindex,clear,settings,verify,history,history/clear}`
 *    — panel właściciela (cap `manage_options`).
 *  - `/aifaq/v1/admin/{generate-faq,export}` — narzędzie generatora (cap `publish_posts`).
 *  - `/aifaq/v1/admin/generations{,/detail,/delete}` — historia generowań (admin).
 *  - `/aifaq/v1/admin/faq/{publish,unpublish}` — publikacja na podstronie
 *    (cap `edit_others_posts`).
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
	 * Uprawnienie wymagane dla tras samego NARZĘDZIA generatora (Krok 20).
	 *
	 * `publish_posts` obejmuje Redaktora i Autora, świadomie bez Współpracownika:
	 * darmowa pula dostawcy to 20 żądań na dobę, więc każda dodatkowa rola to realne
	 * ryzyko jej wyczerpania. Stała NIE zastępuje {@see self::CAPABILITY} — trasy
	 * dotykające klucza API, indeksu i dziennika gości zostają na `manage_options`.
	 */
	const CAPABILITY_TOOL = 'publish_posts';

	/**
	 * Uprawnienie wymagane do publikacji/cofnięcia publikacji FAQ na publicznej
	 * podstronie generatora (Krok 23, decyzja usera po audycie etapu 1).
	 *
	 * Wyżej niż {@see self::CAPABILITY_TOOL}: publikacja pokazuje treść
	 * wyszukiwarce i jest nieodwracalna bez ręcznego przywrócenia snapshotu,
	 * więc Autor (ma `publish_posts`, nie ma `edit_others_posts`) generuje i
	 * eksportuje pary, ale ich nie publikuje — potrzebny Redaktor.
	 */
	const CAPABILITY_PUBLISH = 'edit_others_posts';

	/**
	 * Bezpiecznik generatora FAQ: max wywołań na godzinę (dług sprzed Kroku 22
	 * — „brak limitowania ścieżki admina"). Pula dostawcy jest wspólna z `/ask`
	 * (ta sama darmowa doba, ~20 żądań), więc bez tego jedna testowa sesja
	 * klikania „Generuj” mogłaby ją wyczerpać samodzielnie, zanim jakikolwiek
	 * gość zadał pytanie. Osobny od `rag_daily_budget` (ten liczy WYŁĄCZNIE
	 * `/ask`) i od per-gość RateLimitera w RagService — to jest bramka dla
	 * NARZĘDZIA, nie dla gościa.
	 *
	 * Egzekwuje go {@see GeneratorService}.
	 */
	const GENERATE_FAQ_HOURLY_LIMIT = 10;

	/**
	 * Klucz-kubełek limitera generatora FAQ — stały (jedno narzędzie, nie per-gość).
	 */
	const GENERATE_FAQ_LIMIT_BUCKET = 'admin-generate-faq';

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
		( new RouteRegistrar( $this ) )->register();
	}

	// -----------------------------------------------------------------------
	// Bramki uprawnień — zostają na kontrolerze, bo to one są `permission_callback`
	// każdej trasy (i jedyne źródło capa narzędzia dla UI kokpitu).
	// -----------------------------------------------------------------------

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
	 * Uprawnienie narzędzia generatora — JEDYNE źródło prawdy (KONTRAKT k20-v3 §5.1).
	 *
	 * Czyta je zarówno bramka obu tras narzędzia, jak i obie bramki metaboksa
	 * ({@see \AIFAQ\Admin\PostMetaBox}). Gdyby UI czytał surową stałą,
	 * a trasa wartość po filtrze, witryna zawężająca cap filtrem pokazywałaby panel roli,
	 * która przy kliknięciu „Generuj" dostaje 403 — czyli gorzej niż stan sprzed Kroku 20.
	 *
	 * @return string
	 */
	public static function tool_capability(): string {
		$cap = self::CAPABILITY_TOOL;
		if ( function_exists( 'apply_filters' ) ) {
			$cap = (string) apply_filters( 'aifaq_tool_capability', $cap );
		}
		return $cap;
	}

	/**
	 * Bramka uprawnień DWÓCH tras narzędzia (`/admin/generate-faq`, `/admin/export`).
	 *
	 * Administrator przechodzi zawsze — także wtedy, gdy filtr `aifaq_tool_capability`
	 * zwróci uprawnienie, którego administrator nie posiada.
	 *
	 * @return bool
	 */
	public function require_tool_user(): bool {
		return current_user_can( self::CAPABILITY ) || current_user_can( self::tool_capability() );
	}

	/**
	 * Bramka uprawnień DWÓCH tras publikacji (`/admin/faq/publish`, `/admin/faq/unpublish`).
	 *
	 * Cap wyższy niż {@see self::require_tool_user()} — Krok 23, patrz
	 * {@see self::CAPABILITY_PUBLISH}. Administrator przechodzi zawsze.
	 *
	 * @return bool
	 */
	public function require_publish_user(): bool {
		return current_user_can( self::CAPABILITY ) || current_user_can( self::CAPABILITY_PUBLISH );
	}

	// -----------------------------------------------------------------------
	// Walidacja parametrów — `validate_callback` tras, uruchamiana przez rdzeń WP
	// PRZED wywołaniem `callback`, więc nie schodzi do warstwy usługowej.
	// -----------------------------------------------------------------------

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

	// -----------------------------------------------------------------------
	// Wywołania zwrotne tras — cienkie, cała robota w klasach usługowych.
	// -----------------------------------------------------------------------

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
	 * `GET /admin/status` — statystyki bazy wiedzy i gotowość do indeksowania.
	 *
	 * @param WP_REST_Request $request Żądanie (nieużywane).
	 * @return WP_REST_Response
	 */
	public function handle_status( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		return ( new AdminService() )->status();
	}

	/**
	 * `POST /admin/reindex` — indeksuje treść (rdzeń wspólny z akcją AJAX).
	 *
	 * @param WP_REST_Request $request Żądanie (nieużywane).
	 * @return WP_REST_Response
	 */
	public function handle_reindex( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		return ( new AdminService() )->reindex();
	}

	/**
	 * `POST /admin/clear` — czyści bazę wiedzy (rdzeń wspólny z akcją AJAX).
	 *
	 * @param WP_REST_Request $request Żądanie (nieużywane).
	 * @return WP_REST_Response
	 */
	public function handle_clear( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		return ( new AdminService() )->clear();
	}

	/**
	 * `POST /admin/settings` — zapisuje ustawienia (whitelistowany podzbiór z frontu).
	 *
	 * @param WP_REST_Request $request Żądanie.
	 * @return WP_REST_Response
	 */
	public function handle_settings_save( WP_REST_Request $request ): WP_REST_Response {
		return ( new AdminService() )->save_settings( $request );
	}

	/**
	 * `POST /admin/verify` — test połączenia (realny ping klucza do Gemini).
	 *
	 * @param WP_REST_Request $request Żądanie.
	 * @return WP_REST_Response
	 */
	public function handle_verify( WP_REST_Request $request ): WP_REST_Response {
		return ( new AdminService() )->verify( $request );
	}

	/**
	 * `GET /admin/history` — strona dziennika pytań + podsumowanie.
	 *
	 * @param WP_REST_Request $request Żądanie.
	 * @return WP_REST_Response
	 */
	public function handle_history( WP_REST_Request $request ): WP_REST_Response {
		return ( new AdminService() )->history( $request );
	}

	/**
	 * `POST /admin/history/clear` — kasuje cały dziennik pytań.
	 *
	 * @param WP_REST_Request $request Żądanie (nieużywane).
	 * @return WP_REST_Response
	 */
	public function handle_history_clear( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		return ( new AdminService() )->history_clear();
	}

	/**
	 * `POST /admin/generate-faq` — generuje pary FAQ z tematu i zapisuje historię.
	 *
	 * @param WP_REST_Request $request Żądanie.
	 * @return WP_REST_Response
	 */
	public function handle_generate_faq( WP_REST_Request $request ): WP_REST_Response {
		return ( new GeneratorService() )->generate( $request );
	}

	/**
	 * `GET /admin/generations` — strona historii generowań (metadane, bez par).
	 *
	 * @param WP_REST_Request $request Żądanie.
	 * @return WP_REST_Response
	 */
	public function handle_generations( WP_REST_Request $request ): WP_REST_Response {
		return ( new GeneratorService() )->listing( $request );
	}

	/**
	 * `GET /admin/generations/detail` — jeden wpis historii generowań RAZEM z parami.
	 *
	 * @param WP_REST_Request $request Żądanie.
	 * @return WP_REST_Response
	 */
	public function handle_generation_detail( WP_REST_Request $request ): WP_REST_Response {
		return ( new GeneratorService() )->detail( $request );
	}

	/**
	 * `POST /admin/generations/delete` — usuwa jeden wpis historii generowań.
	 *
	 * @param WP_REST_Request $request Żądanie.
	 * @return WP_REST_Response
	 */
	public function handle_generations_delete( WP_REST_Request $request ): WP_REST_Response {
		return ( new GeneratorService() )->delete( $request );
	}

	/**
	 * `POST /admin/export` — formatuje bieżące pary Q&A do 5 formatów eksportu.
	 *
	 * @param WP_REST_Request $request Żądanie.
	 * @return WP_REST_Response
	 */
	public function handle_export( WP_REST_Request $request ): WP_REST_Response {
		return ( new PublishService() )->export( $request );
	}

	/**
	 * `POST /admin/faq/publish` — pokazuje pary na podstronie generatora.
	 *
	 * @param \WP_REST_Request $request Żądanie.
	 * @return \WP_REST_Response
	 */
	public function handle_faq_publish( $request ) {
		return ( new PublishService() )->publish( $request );
	}

	/**
	 * `POST /admin/faq/unpublish` — zdejmuje pary z podstrony.
	 *
	 * @param \WP_REST_Request $request Żądanie (bez parametrów).
	 * @return \WP_REST_Response
	 */
	public function handle_faq_unpublish( $request ) {
		unset( $request );

		return ( new PublishService() )->unpublish();
	}

	/**
	 * Mapuje wynik {@see RagService::ask()} na odpowiedź HTTP.
	 *
	 * Reguła kodów stanu mieszka w {@see AskService::map_result()}.
	 *
	 * @param array<string,mixed> $result Wynik potoku RAG.
	 * @return WP_REST_Response
	 */
	private function ask_response( array $result ): WP_REST_Response {
		return ( new AskService() )->map_result( $result );
	}

	/**
	 * Identyfikator gościa dla kubełka limitera — patrz {@see GuestIdentity::ip_hash()}.
	 *
	 * @return string
	 */
	private function ip_hash(): string {
		return ( new GuestIdentity() )->ip_hash();
	}
}
