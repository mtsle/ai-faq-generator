<?php
/**
 * Logika tras operujących na bieżących parach: eksport i publikacja na podstronie.
 *
 * Wydzielone z {@see RestController} (Krok 23). Trzy trasy trzymają się razem, bo
 * łączy je JEDNO wejście — lista par przysłana przez UI ({@see PairsInput}).
 *
 * @package AI_FAQ_Generator
 */

namespace AIFAQ\Rest;

use AIFAQ\Data\GenerationRepository;
use AIFAQ\Faq\Exporter;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Warstwa biznesowa tras `/admin/export` i `/admin/faq/{publish,unpublish}`.
 */
class PublishService {

	/**
	 * `POST /admin/export` — formatuje bieżące pary Q&A do 5 formatów eksportu.
	 *
	 * Pary przychodzą z UI (stan lokalny po edycjach/usunięciach); walidacja i
	 * sanityzacja są w {@see PairsInput::from_request()}, a formatowanie robi czysta
	 * klasa {@see Exporter}. Pusta/niepoprawna lista → 400 (bez zgadywania).
	 * Sukces → 200 z pięcioma stringami gotowymi do wyświetlenia.
	 *
	 * @param WP_REST_Request $request Żądanie.
	 * @return WP_REST_Response
	 */
	public function export( WP_REST_Request $request ): WP_REST_Response {
		$pairs = PairsInput::from_request( $request->get_param( 'pairs' ) );

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
	 * `POST /admin/faq/publish` — pokazuje pary na podstronie generatora.
	 *
	 * Przyjmuje `pairs` (bieżąca tabela w narzędziu, także po ręcznych poprawkach
	 * właściciela) albo `id` zapisanej generacji. Pierwszeństwo ma `pairs`: to,
	 * co właściciel widzi na ekranie, jest tym, co publikuje.
	 *
	 * @param \WP_REST_Request $request Żądanie.
	 * @return \WP_REST_Response
	 */
	public function publish( $request ) {
		// K23 etap 1, znalezisko A: pary z przeglądarki idą PROSTO na publiczną
		// podstronę i do JSON-LD FAQPage, więc dostają sanityzację (jak eksport)
		// — `from_snapshot()` niżej zakłada dane już zapisane w bazie i celowo jej
		// nie robi.
		$pairs = PairsInput::from_request_for_publish( $request->get_param( 'pairs' ) );
		$id    = (int) $request->get_param( 'id' );

		// Sięgnięcie po ZAPISANĄ generację jest zawężone do administratora.
		// Trasa stoi na capie NARZĘDZIA (Redaktor/Autor), a cała historia generowań
		// — `/admin/generations`, `/admin/generations/detail` — jest świadomie
		// admin-only. Bez tego warunku Autor odczytywał cudze pary przez samo `id`
		// (kolejne liczby całkowite), publikując je sobie na podstronie: obejście
		// bramki historii i jednocześnie podmiana publicznej treści i `FAQPage`.
		// UI ZAWSZE wysyła `pairs` (assets/js/faq-tool.js), więc dla produktu
		// to zawężenie jest niewidoczne.
		if ( array() === $pairs && $id > 0 && current_user_can( RestController::CAPABILITY ) ) {
			$row = ( new GenerationRepository() )->find( $id );

			if ( null === $row ) {
				return new \WP_REST_Response(
					array(
						'status'  => 'error',
						'message' => __( 'Nie znaleziono generacji.', 'ai-faq-generator' ),
					),
					404
				);
			}

			$pairs = PairsInput::from_snapshot( $row['pairs'] ?? array() );
		}

		if ( array() === $pairs ) {
			return new \WP_REST_Response(
				array(
					'status'  => 'error',
					'message' => __( 'Brak par do opublikowania.', 'ai-faq-generator' ),
				),
				400
			);
		}

		// K23 etap 5, znalezisko S3: `$id` jest zapisywane jako `generation_id`
		// opublikowanej wersji, ale sprawdzenie istnienia wiersza siedziało WYŁĄCZNIE
		// w gałęzi wyżej (ta odpala się tylko, gdy `pairs` jest puste). Główna ścieżka
		// UI zawsze wysyła `pairs`, więc dowolne `id` z żądania trafiało do bazy bez
		// weryfikacji — metadana wskazująca na nieistniejącą generację. Nie jest to
		// dziura bezpieczeństwa (sama liczba nic nie odblokowuje), tylko trwały rozjazd
		// danych: „opublikowano z generacji #9999", której nigdy nie było.
		// Nieznane `id` zerujemy zamiast odrzucać żądanie — pary są poprawne,
		// a `generation_id` to informacja pomocnicza, nie warunek publikacji.
		if ( $id > 0 && null === ( new GenerationRepository() )->find( $id ) ) {
			$id = 0;
		}

		// K23 etap 5, znalezisko D1: publikacja to sekwencja ODCZYT→ZAPIS wewnątrz
		// `PublicFaq` (snapshot poprzedniej wersji, potem nadpisanie). Bez zamka
		// dwie równoczesne publikacje gubiły pracę jednej z nich BEZ ŚLADU — także
		// z kopii zapasowej, bo snapshot nadpisywał się razem z danymi.
		// Świadomie ODRZUCAMY drugie żądanie (409) zamiast cicho nadpisywać:
		// właściciel ma się dowiedzieć, że ktoś publikuje w tej samej chwili.
		if ( ! \AIFAQ\Faq\PublicFaq::acquire_lock() ) {
			return new \WP_REST_Response(
				array(
					'status'  => 'error',
					'message' => __( 'Ktoś właśnie publikuje FAQ. Odśwież stronę i spróbuj ponownie.', 'ai-faq-generator' ),
				),
				409
			);
		}

		try {
			$count = \AIFAQ\Faq\PublicFaq::publish( $pairs, $id );
		} finally {
			// `finally`, nie zwykła kolejność: wyjątek w trakcie zapisu zostawiłby
			// zamek zajęty do wygaśnięcia TTL, blokując właściciela bez powodu.
			\AIFAQ\Faq\PublicFaq::release_lock();
		}

		if ( $count < 1 ) {
			return new \WP_REST_Response(
				array(
					'status'  => 'error',
					'message' => __( 'Nie udało się zapisać par.', 'ai-faq-generator' ),
				),
				500
			);
		}

		return new \WP_REST_Response(
			array(
				'status' => 'ok',
				'count'  => $count,
				'url'    => esc_url_raw( \AIFAQ\PublicUi\PageGuard::page_url() ),
			),
			200
		);
	}

	/**
	 * `POST /admin/faq/unpublish` — zdejmuje pary z podstrony.
	 *
	 * @return \WP_REST_Response
	 */
	public function unpublish() {
		// Ten sam zamek co przy publikacji (D1): `unpublish()` też robi snapshot
		// przed skasowaniem, więc przeplot z równoczesną publikacją niszczy kopię.
		if ( ! \AIFAQ\Faq\PublicFaq::acquire_lock() ) {
			return new \WP_REST_Response(
				array(
					'status'  => 'error',
					'message' => __( 'Ktoś właśnie zmienia FAQ. Odśwież stronę i spróbuj ponownie.', 'ai-faq-generator' ),
				),
				409
			);
		}

		try {
			\AIFAQ\Faq\PublicFaq::unpublish();
		} finally {
			\AIFAQ\Faq\PublicFaq::release_lock();
		}

		return new \WP_REST_Response(
			array(
				'status' => 'ok',
				'count'  => 0,
			),
			200
		);
	}
}
