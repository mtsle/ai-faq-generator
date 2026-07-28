<?php
/**
 * Logika tras panelu: stan bazy wiedzy, indeksowanie, ustawienia, dziennik gości.
 *
 * Wydzielone z {@see RestController} (Krok 23). Kontroler po tej zmianie tylko
 * rejestruje trasy i pilnuje uprawnień; całe składanie odpowiedzi tras
 * `/admin/{status,reindex,clear,settings,verify,history,history/clear}` jest tutaj.
 *
 * @package AI_FAQ_Generator
 */

namespace AIFAQ\Rest;

use AIFAQ\Admin\IndexController;
use AIFAQ\Core\Settings;
use AIFAQ\Data\QaLogRepository;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Warstwa biznesowa tras administracyjnych.
 */
class AdminService {

	/**
	 * `GET /admin/status` — statystyki bazy wiedzy i gotowość do indeksowania.
	 *
	 * Krok 17: dokłada klucz `crawl` z postępem pobierania stron. Świadomie ROZSZERZA
	 * istniejącą trasę zamiast dokładać nową.
	 *
	 * @return WP_REST_Response
	 */
	public function status(): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'status'      => 'ok',
				'stats'       => IndexController::stats(),
				'indexing'    => (bool) get_transient( IndexController::LOCK ),
				'api_key_set' => '' !== (string) Settings::get_field( 'api_key', '' ),
				'crawl'       => self::crawl_progress(),
			),
			200
		);
	}

	/**
	 * Postęp pobierania stron — bezpieczny odczyt z kolejki innego etapu.
	 *
	 * Brak klasy albo wyjątek w kolejce nie może wywalić statusu panelu (to jedyne
	 * miejsce, w którym właściciel widzi stan bazy wiedzy), więc degradujemy do
	 * stanu „nic nie trwa”.
	 *
	 * @return array{total:int,done:int,running:bool,needs_reindex:bool,warnings:array<int,string>}
	 */
	private static function crawl_progress(): array {
		$out = array(
			'total'         => 0,
			'done'          => 0,
			'running'       => false,
			'needs_reindex' => false,
			'warnings'      => array(),
		);

		if ( ! class_exists( '\AIFAQ\Index\CrawlQueue' ) ) {
			return $out;
		}

		try {
			$progress = ( new \AIFAQ\Index\CrawlQueue() )->progress();
		} catch ( \Throwable $e ) {
			return $out;
		}

		if ( ! is_array( $progress ) ) {
			return $out;
		}

		return array(
			'total'         => (int) ( $progress['total'] ?? 0 ),
			'done'          => (int) ( $progress['done'] ?? 0 ),
			'running'       => (bool) ( $progress['running'] ?? false ),
			'needs_reindex' => (bool) ( $progress['needs_reindex'] ?? false ),
			'warnings'      => is_array( $progress['warnings'] ?? null ) ? array_values( $progress['warnings'] ) : array(),
		);
	}

	/**
	 * `POST /admin/reindex` — indeksuje treść (rdzeń wspólny z akcją AJAX).
	 *
	 * @return WP_REST_Response
	 */
	public function reindex(): WP_REST_Response {
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
	 * @return WP_REST_Response
	 */
	public function clear(): WP_REST_Response {
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
	public function save_settings( WP_REST_Request $request ): WP_REST_Response {
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
	public function verify( WP_REST_Request $request ): WP_REST_Response {
		$api_key = (string) $request->get_param( 'api_key' );
		$result  = Settings::verify_key( $api_key );

		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array( 'status' => 'error', 'message' => Settings::verify_error_message( $result ) ), 200 );
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
	public function history( WP_REST_Request $request ): WP_REST_Response {
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
	 * @return WP_REST_Response
	 */
	public function history_clear(): WP_REST_Response {
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
}
