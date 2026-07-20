<?php
/**
 * TopicGuard — bramka tematu (off-topic → odmowa).
 *
 * Czysta polityka decyzji, bez I/O. Na podstawie trafień Retrievera i DWÓCH progów
 * decyduje: PASS (pytanie mieści się w temacie strony) albo REFUSE (najlepsze
 * trafienie za słabe → grzeczna odmowa). Fail-closed (GR4): brak trafień lub wynik
 * poniżej progu twardego = odmowa.
 *
 * Krok 19 (kamień M3): próg twardy jest podłogą dopuszczalności i filtruje balast
 * PER FRAGMENT (dawniej do promptu wchodziły WSZYSTKIE trafienia, także te o wyniku
 * ~0,42 — zatrucie kontekstu, dług D2). Próg miękki mówi już tylko o tym, czy
 * pokrycie jest pełne (`coverage`), a nie o tym, czy w ogóle odpowiadamy.
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
	 * Ocena trafień względem progów.
	 *
	 * Gwarancja: przy `decision = 'pass'` lista `ids` NIGDY nie jest pusta. Skoro `pass`
	 * wymaga `$best >= $hard`, a `$best` jest wynikiem któregoś fragmentu, to co najmniej
	 * jeden fragment zawsze przetrwa filtr. Filtrowanie progiem MIĘKKIM dałoby `pass`
	 * z pustym `ids` → pusty kontekst w `Answerer` → odmowa omijająca mechanizm
	 * częściowego pokrycia.
	 *
	 * @param array<int,array{id:int,post_id:int,score:float}> $results        Trafienia Retrievera (posortowane malejąco).
	 * @param float                                            $threshold      Próg „pełnego pokrycia” (soft).
	 * @param float                                            $threshold_hard Próg twardy (podłoga). `0.0` = zachowanie sprzed Kroku 19.
	 * @param bool                                             $filter_ids     `false` = zwróć WSZYSTKIE ids (tryb legacy do benchu).
	 * @return array{decision:string,ids:array<int,int>,post_ids:array<int,int>,score:float,coverage:string}
	 *         decision = 'pass'|'refuse'; ids = LISTA (0..n-1); post_ids = MAPA id => post_id;
	 *         coverage = 'full'|'weak'|'none'.
	 */
	public function evaluate( array $results, float $threshold, float $threshold_hard = 0.0, bool $filter_ids = true ): array {
		// min() gwarantuje hard <= soft niezależnie od tego, co przyszło z ustawień.
		$hard = ( $threshold_hard > 0.0 ) ? min( $threshold_hard, $threshold ) : $threshold;

		if ( empty( $results ) ) {
			return array(
				'decision' => 'refuse',
				'ids'      => array(),
				'post_ids' => array(),
				'score'    => 0.0,
				'coverage' => 'none',
			);
		}

		$best = 0.0;
		foreach ( $results as $r ) {
			$score = (float) ( $r['score'] ?? 0.0 );
			if ( $score > $best ) {
				$best = $score;
			}
		}

		if ( $best < $hard ) {
			// Fail-closed: poniżej progu twardego nie ujawniamy fragmentów (GR4).
			return array(
				'decision' => 'refuse',
				'ids'      => array(),
				'post_ids' => array(),
				'score'    => $best,
				'coverage' => 'none',
			);
		}

		$ids      = array();
		$post_ids = array();
		foreach ( $results as $r ) {
			$score = (float) ( $r['score'] ?? 0.0 );
			if ( $filter_ids && $score < $hard ) {
				continue;
			}
			$id              = (int) ( $r['id'] ?? 0 );
			$ids[]           = $id;
			$post_ids[ $id ] = (int) ( $r['post_id'] ?? 0 );
		}

		return array(
			'decision' => 'pass',
			'ids'      => array_values( $ids ),
			'post_ids' => $post_ids,
			'score'    => $best,
			'coverage' => ( $best >= $threshold ) ? 'full' : 'weak',
		);
	}
}
