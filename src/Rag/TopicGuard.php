<?php
/**
 * TopicGuard — bramka tematu (off-topic → odmowa).
 *
 * Czysta polityka decyzji, bez I/O. Na podstawie trafień Retrievera i progu
 * podobieństwa decyduje: PASS (pytanie mieści się w temacie strony) albo REFUSE
 * (najlepsze trafienie za słabe → grzeczna odmowa). Fail-closed (GR4): brak
 * trafień lub wynik poniżej progu = odmowa.
 *
 * @package AI_FAQ_Generator
 */

namespace AIFAQ\Rag;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Decyzja: odpowiadać czy odmówić (na podstawie podobieństwa).
 */
class TopicGuard {

	/**
	 * Ocena trafień względem progu.
	 *
	 * @param array<int,array{id:int,post_id:int,score:float}> $results   Trafienia Retrievera.
	 * @param float                                            $threshold Próg podobieństwa (0.0–1.0).
	 * @return array{decision:string,ids:array<int,int>,score:float} decision = 'pass'|'refuse'.
	 */
	public function evaluate( array $results, float $threshold ): array {
		if ( empty( $results ) ) {
			return array(
				'decision' => 'refuse',
				'ids'      => array(),
				'score'    => 0.0,
			);
		}

		$best = 0.0;
		$ids  = array();
		foreach ( $results as $r ) {
			$score = (float) ( $r['score'] ?? 0.0 );
			if ( $score > $best ) {
				$best = $score;
			}
			$ids[] = (int) ( $r['id'] ?? 0 );
		}

		if ( $best >= $threshold ) {
			return array(
				'decision' => 'pass',
				'ids'      => $ids,
				'score'    => $best,
			);
		}

		// Fail-closed: poniżej progu nie ujawniamy fragmentów (GR4).
		return array(
			'decision' => 'refuse',
			'ids'      => array(),
			'score'    => $best,
		);
	}
}
