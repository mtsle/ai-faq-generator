<?php
/**
 * Mapowanie wyniku potoku RAG na odpowiedź HTTP trasy `POST /ask`.
 *
 * Wydzielone z {@see RestController} (Krok 23) — kontroler zostaje przy routingu
 * i bramkach uprawnień, tu mieszka reguła „który wynik daje który kod stanu".
 *
 * @package AI_FAQ_Generator
 */

namespace AIFAQ\Rest;

use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Warstwa odpowiedzi publicznej trasy pytań.
 */
class AskService {

	/**
	 * Mapuje wynik {@see \AIFAQ\Rag\RagService::ask()} na odpowiedź HTTP.
	 *
	 * answered|refused → 200; limit własny LUB limit dostawcy → 429; błąd → 502
	 * (komunikat ogólny, bez surowego błędu providera).
	 *
	 * KOLEJNOŚĆ GAŁĘZI JEST CZĘŚCIĄ KONTRAKTU: gałąź 429 stoi PRZED ogólną gałęzią 502.
	 * Postawiona po niej byłaby martwym kodem — gość dalej dostawałby 502 „awaria"
	 * zamiast 429 „odczekaj chwilę", a testy niższych warstw świeciłyby na zielono.
	 *
	 * @param array<string,mixed> $result Wynik potoku RAG.
	 * @return WP_REST_Response
	 */
	public function map_result( array $result ): WP_REST_Response {
		$status = (string) ( $result['status'] ?? 'error' );
		$source = (string) ( $result['source'] ?? 'ai' );

		if ( 'error' === $status
			&& in_array( $source, array( 'rate_limit', 'provider_rate_limit' ), true ) ) {
			return new WP_REST_Response(
				$this->with_debug(
					array(
						'status'  => 'rate_limited',
						'message' => __( 'Za dużo zapytań. Spróbuj ponownie za chwilę.', 'ai-faq-generator' ),
					),
					$result
				),
				429
			);
		}

		if ( 'error' === $status ) {
			return new WP_REST_Response(
				$this->with_debug(
					array(
						'status'  => 'error',
						'message' => __( 'Nie udało się teraz wygenerować odpowiedzi. Spróbuj ponownie później.', 'ai-faq-generator' ),
					),
					$result
				),
				502
			);
		}

		return new WP_REST_Response(
			$this->with_debug(
				array(
					'status' => $status, // answered | refused
					'answer' => (string) ( $result['answer'] ?? '' ),
					'score'  => round( (float) ( $result['score'] ?? 0 ), 4 ),
					'source' => $source, // ai | cache
					'cached' => ( 'cache' === $source ),
				),
				$result
			),
			200
		);
	}

	/**
	 * Dokleja diagnostykę do body — wyłącznie dla właściciela i wyłącznie gdy jest co doklejać.
	 *
	 * Gość nie zobaczy jej NIGDY: `top_k` niesie id, post_id i wyniki podobieństwa
	 * wszystkich fragmentów, czyli strukturę bazy wiedzy. Doklejamy do wszystkich trzech
	 * gałęzi (200, 429, 502), bo właściciel diagnozujący awarię potrzebuje jej najbardziej
	 * właśnie wtedy, gdy odpowiedzi nie ma.
	 *
	 * @param array<string,mixed> $body   Body odpowiedzi.
	 * @param array<string,mixed> $result Wynik RagService::ask().
	 * @return array<string,mixed>
	 */
	private function with_debug( array $body, array $result ): array {
		$debug = ( isset( $result['debug'] ) && is_array( $result['debug'] ) ) ? $result['debug'] : array();
		if ( array() === $debug ) {
			return $body;
		}
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
			return $body;
		}
		$body['debug'] = $debug;
		return $body;
	}
}
