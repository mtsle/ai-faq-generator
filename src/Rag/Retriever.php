<?php
/**
 * Retriever — wybór najbardziej podobnych fragmentów treści.
 *
 * Liczy podobieństwo COSINUSOWE między wektorem pytania a wektorami fragmentów
 * z bazy wiedzy. Embeddingi (768D) NIE są znormalizowane, więc dzielimy przez
 * iloczyn norm (GR2). Iteruje bazę STRONICOWANO (`embeddings_page`), nigdy nie
 * ładuje jej całej naraz (GR6). Zwraca top-K trafień, bez dociągania treści
 * (treść dobiera dopiero RagService przez `contents_for()`).
 *
 * @package AI_FAQ_Generator
 */

namespace AIFAQ\Rag;

use AIFAQ\Data\KnowledgeRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wyszukiwarka fragmentów po podobieństwie znaczeniowym.
 */
class Retriever {

	/**
	 * Domyślny rozmiar strony przy iteracji bazy embeddingów.
	 */
	const PAGE_SIZE = 200;

	/**
	 * @var KnowledgeRepository
	 */
	private $repo;

	/**
	 * @var int
	 */
	private $page_size;

	/**
	 * @param KnowledgeRepository $repo      Repozytorium bazy wiedzy.
	 * @param int                 $page_size Rozmiar strony (clamp min 1).
	 */
	public function __construct( KnowledgeRepository $repo, int $page_size = self::PAGE_SIZE ) {
		$this->repo      = $repo;
		$this->page_size = max( 1, $page_size );
	}

	/**
	 * Zwraca top-K fragmentów najbardziej podobnych do wektora pytania.
	 *
	 * @param array<int,float> $query_vector Wektor pytania (oczekiwane 768 wymiarów).
	 * @param int              $top_k        Ile trafień zwrócić (clamp min 1).
	 * @return array<int,array{id:int,post_id:int,score:float}> Malejąco po `score`.
	 */
	public function retrieve( array $query_vector, int $top_k ): array {
		$top_k  = max( 1, $top_k );
		$dim    = count( $query_vector );
		$q_norm = self::norm( $query_vector );

		// Zerowy/pusty wektor pytania → brak sensownego podobieństwa (GR2).
		if ( 0 === $dim || $q_norm <= 0.0 ) {
			return array();
		}

		$total = $this->repo->count_embedded();
		if ( $total <= 0 ) {
			return array();
		}

		$results = array();
		for ( $offset = 0; $offset < $total; $offset += $this->page_size ) {
			$page = $this->repo->embeddings_page( $this->page_size, $offset );
			if ( empty( $page ) ) {
				break;
			}
			foreach ( $page as $row ) {
				$emb = $row['embedding'] ?? null;
				// Pomiń wpisy o niezgodnym wymiarze (różny model/przestrzeń).
				if ( ! is_array( $emb ) || count( $emb ) !== $dim ) {
					continue;
				}
				$results[] = array(
					'id'      => (int) ( $row['id'] ?? 0 ),
					'post_id' => (int) ( $row['post_id'] ?? 0 ),
					'score'   => self::cosine( $query_vector, $emb, $q_norm ),
				);
			}
		}

		usort(
			$results,
			static function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);

		return array_slice( $results, 0, $top_k );
	}

	/**
	 * Długość (norma euklidesowa) wektora.
	 *
	 * @param array<int,float> $v Wektor.
	 * @return float
	 */
	private static function norm( array $v ): float {
		$sum = 0.0;
		foreach ( $v as $x ) {
			$f    = (float) $x;
			$sum += $f * $f;
		}
		return sqrt( $sum );
	}

	/**
	 * Podobieństwo cosinusowe dla wektorów NIEznormalizowanych.
	 *
	 * `dot(a,b) / (‖a‖·‖b‖)`; norma 0 → 0.0 (brak dzielenia przez zero, GR2).
	 * Norma `a` (pytania) jest liczona raz i wstrzykiwana, by nie powtarzać jej per fragment.
	 *
	 * @param array<int,float> $a      Wektor pytania.
	 * @param array<int,float> $b      Wektor fragmentu.
	 * @param float            $a_norm Norma wektora pytania (policzona wcześniej).
	 * @return float Wartość w zakresie [-1, 1] (0.0 gdy któraś norma = 0).
	 */
	private static function cosine( array $a, array $b, float $a_norm ): float {
		$dot    = 0.0;
		$b_sqsum = 0.0;
		foreach ( $a as $i => $ai ) {
			$bi       = (float) ( $b[ $i ] ?? 0.0 );
			$dot     += (float) $ai * $bi;
			$b_sqsum += $bi * $bi;
		}
		$b_norm = sqrt( $b_sqsum );
		if ( $a_norm <= 0.0 || $b_norm <= 0.0 ) {
			return 0.0;
		}
		return $dot / ( $a_norm * $b_norm );
	}
}
