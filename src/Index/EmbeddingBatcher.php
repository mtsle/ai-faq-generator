<?php
/**
 * Dzielenie embeddingów na paczki (batch) w granicach limitu API.
 *
 * Gemini `batchEmbedContents` przyjmuje ograniczoną liczbę pozycji na żądanie
 * (~100). Ta klasa opakowuje {@see ProviderInterface::embed()} i woła go w
 * paczkach, sklejając wyniki w JEDNĄ listę wektorów w kolejności wejścia.
 * Błędy providera propaguje bez zmian; pilnuje też, by liczba zwróconych
 * wektorów zgadzała się z liczbą tekstów w paczce.
 *
 * @package AI_FAQ_Generator
 */

namespace AIFAQ\Index;

use AIFAQ\Providers\ProviderInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Batcher embeddingów.
 */
class EmbeddingBatcher {

	/**
	 * Twardy limit pozycji na jedno żądanie embed (bezpieczny dla Gemini).
	 */
	const MAX_BATCH = 100;

	/**
	 * Dostawca AI (wstrzyknięty).
	 *
	 * @var ProviderInterface
	 */
	private ProviderInterface $provider;

	/**
	 * Rozmiar paczki (1..MAX_BATCH).
	 *
	 * @var int
	 */
	private int $batch_size;

	/**
	 * Konstruktor.
	 *
	 * @param ProviderInterface $provider   Dostawca AI.
	 * @param int               $batch_size Rozmiar paczki (przycinany do 1..MAX_BATCH).
	 */
	public function __construct( ProviderInterface $provider, int $batch_size = self::MAX_BATCH ) {
		$this->provider   = $provider;
		$this->batch_size = max( 1, min( $batch_size, self::MAX_BATCH ) );
	}

	/**
	 * Embeduje wszystkie teksty, dzieląc je na paczki.
	 *
	 * @param array<int,string> $texts Lista tekstów do zwektoryzowania.
	 *
	 * @return array<int,array<int,float>>|\WP_Error
	 *         Lista wektorów w kolejności wejścia lub `\WP_Error` przy błędzie
	 *         providera albo niezgodnej liczbie zwróconych wektorów.
	 */
	public function embed_all( array $texts ) {
		if ( array() === $texts ) {
			return array();
		}

		$out = array();

		foreach ( array_chunk( $texts, $this->batch_size ) as $batch ) {
			$vectors = $this->provider->embed( $batch );

			if ( is_wp_error( $vectors ) ) {
				return $vectors;
			}

			if ( ! is_array( $vectors ) || count( $vectors ) !== count( $batch ) ) {
				return new \WP_Error(
					'aifaq_embed_count',
					__( 'Dostawca zwrócił niepełny zestaw wektorów.', 'ai-faq-generator' )
				);
			}

			foreach ( $vectors as $vector ) {
				$out[] = $vector;
			}
		}

		return $out;
	}
}
