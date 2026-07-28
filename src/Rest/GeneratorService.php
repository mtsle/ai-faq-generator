<?php
/**
 * Logika tras generatora FAQ: generowanie par i historia generowań.
 *
 * Wydzielone z {@see RestController} (Krok 23). Bezpiecznik godzinowy i przycinanie
 * parametrów siedziały dotąd WEWNĄTRZ metody trasy — tu są osobnymi krokami
 * ({@see self::throttle()}, {@see self::read_params()}), więc regułę limitu da się
 * czytać bez przedzierania się przez składanie odpowiedzi.
 *
 * @package AI_FAQ_Generator
 */

namespace AIFAQ\Rest;

use AIFAQ\Core\Settings;
use AIFAQ\Data\GenerationRepository;
use AIFAQ\Faq\FaqGenerator;
use AIFAQ\Providers\ProviderFactory;
use AIFAQ\Rag\RateLimiter;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Warstwa biznesowa tras `/admin/generate-faq` i `/admin/generations*`.
 */
class GeneratorService {

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
	public function generate( WP_REST_Request $request ): WP_REST_Response {
		$limited = $this->throttle();
		if ( null !== $limited ) {
			return $limited;
		}

		$params = $this->read_params( $request );
		$topic  = $params['topic'];
		$desc   = $params['description'];
		$count  = $params['count'];
		$lang   = $params['language'];

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
	 * Bezpiecznik godzinowy generatora — zwraca odpowiedź 429 albo null (przepuść).
	 *
	 * Pula dostawcy wspólna z `/ask`, więc rwąca sesja klikania „Generuj" (dubel,
	 * skrypt, pomyłka) nie może jej wyczerpać samodzielnie.
	 *
	 * Kubełek PER-UŻYTKOWNIK (K23 etap 1, znalezisko B7) — kubełek wspólny dla
	 * całej witryny pozwalał najniżej uprawnionemu Autorowi (capability tego
	 * narzędzia to `publish_posts`) wyczerpać limit i zablokować generator
	 * administratorowi na godzinę. Każdy użytkownik dostaje własną pulę 10/h.
	 *
	 * @return WP_REST_Response|null
	 */
	private function throttle(): ?WP_REST_Response {
		$gen_bucket  = RestController::GENERATE_FAQ_LIMIT_BUCKET . '-' . get_current_user_id();
		$gen_limiter = new RateLimiter( RestController::GENERATE_FAQ_HOURLY_LIMIT, null, 3600 );
		if ( ! $gen_limiter->allow( $gen_bucket ) ) {
			return new WP_REST_Response(
				array(
					'status'  => 'error',
					'message' => __( 'Zbyt wiele generacji w tej godzinie — poczekaj chwilę (chroni to wspólny dzienny limit API).', 'ai-faq-generator' ),
				),
				429
			);
		}
		$gen_limiter->hit( $gen_bucket );

		return null;
	}

	/**
	 * Parametry generacji po przycięciu do reguł produktu.
	 *
	 * @param WP_REST_Request $request Żądanie.
	 * @return array{topic:string,description:string,count:int,language:string}
	 */
	private function read_params( WP_REST_Request $request ): array {
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

		return array(
			'topic'       => $topic,
			'description' => $desc,
			'count'       => $count,
			'language'    => $lang,
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
	public function listing( WP_REST_Request $request ): WP_REST_Response {
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
	 * Kształt `item` jest IDENTYCZNY z elementem `items[]` z {@see self::listing()}
	 * (wspólny builder {@see self::generation_item()}) plus dodatkowy klucz `pairs` —
	 * dzięki temu front renderuje wiersz listy i wiersz szczegółu jednym kodem.
	 * Brak/niepoprawne `id` → 400, brak wiersza → 404 (bez ujawniania czegokolwiek
	 * o zawartości bazy poza samym faktem nieistnienia wpisu).
	 *
	 * @param WP_REST_Request $request Żądanie.
	 * @return WP_REST_Response
	 */
	public function detail( WP_REST_Request $request ): WP_REST_Response {
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
		$item['pairs'] = PairsInput::from_snapshot( $row['pairs'] ?? array() );

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
	public function delete( WP_REST_Request $request ): WP_REST_Response {
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
	 * @param string              $format Format daty (patrz {@see self::datetime_format()}).
	 * @return array<string,mixed>
	 */
	public function generation_item( array $row, string $format ): array {
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
}
