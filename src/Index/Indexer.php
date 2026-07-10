<?php
/**
 * Indexer — buduje bazę wiedzy RAG z treści strony.
 *
 * Spina cały potok Kroku 5: {@see ContentSource} (skąd treść) →
 * {@see Chunker} (podział na fragmenty) → {@see EmbeddingBatcher} (wektory
 * w paczkach) → {@see KnowledgeRepository} (zapis atomowy per wpis).
 *
 * Optymalizacja kosztu: wpis, którego zestaw hashy fragmentów się nie zmienił,
 * jest pomijany BEZ wołania embeddingów (drogie API). Sam nie rzuca wyjątków —
 * błędy per wpis zbiera w raporcie i kontynuuje z kolejnymi.
 *
 * @package AI_FAQ_Generator
 */

namespace AIFAQ\Index;

use AIFAQ\Data\KnowledgeRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Orkiestrator indeksowania.
 */
class Indexer {

	/**
	 * Źródło treści.
	 *
	 * @var ContentSource
	 */
	private ContentSource $source;

	/**
	 * Chunker.
	 *
	 * @var Chunker
	 */
	private Chunker $chunker;

	/**
	 * Batcher embeddingów.
	 *
	 * @var EmbeddingBatcher
	 */
	private EmbeddingBatcher $batcher;

	/**
	 * Repozytorium bazy wiedzy.
	 *
	 * @var KnowledgeRepository
	 */
	private KnowledgeRepository $repo;

	/**
	 * Podpis przestrzeni embeddingów (dostawca|model|wymiary).
	 *
	 * Wchodzi do hasha fragmentu, więc zmiana modelu/wymiarów embeddingu unieważnia
	 * skip-unchanged i wymusza ponowne zwektoryzowanie — inaczej w bazie zostałyby
	 * wektory z innej przestrzeni, a Retriever liczyłby podobieństwo do śmieci.
	 *
	 * @var string
	 */
	private string $index_signature;

	/**
	 * Konstruktor (wszystkie zależności wstrzykiwane — pełna testowalność).
	 *
	 * @param ContentSource       $source          Źródło treści.
	 * @param Chunker             $chunker         Chunker.
	 * @param EmbeddingBatcher    $batcher         Batcher embeddingów.
	 * @param KnowledgeRepository $repo            Repozytorium bazy wiedzy.
	 * @param string              $index_signature Podpis przestrzeni embeddingów (patrz {@see $index_signature}).
	 */
	public function __construct( ContentSource $source, Chunker $chunker, EmbeddingBatcher $batcher, KnowledgeRepository $repo, string $index_signature = '' ) {
		$this->source          = $source;
		$this->chunker         = $chunker;
		$this->batcher         = $batcher;
		$this->repo            = $repo;
		$this->index_signature = $index_signature;
	}

	/**
	 * Uruchamia indeksowanie całej treści.
	 *
	 * @return array{posts:int,indexed:int,skipped:int,cleared:int,pruned:int,chunks:int,errors:array<int,string>,warnings:array<int,string>}
	 *         Raport: liczba wpisów, zaindeksowanych, pominiętych (bez zmian),
	 *         wyczyszczonych (utraciły treść), usuniętych osieroconych, zapisanych
	 *         fragmentów, błędów oraz ostrzeżeń (rzeczy pominiętych świadomie).
	 */
	public function run(): array {
		$report = array(
			'posts'    => 0,
			'indexed'  => 0,
			'skipped'  => 0,
			'cleared'  => 0,
			'pruned'   => 0,
			'chunks'   => 0,
			'errors'   => array(),
			'warnings' => array(),
		);

		$seen = array();

		foreach ( $this->source->documents() as $doc ) {
			++$report['posts'];
			$post_id        = (int) $doc['post_id'];
			$seen[ $post_id ] = true;

			$pieces = $this->chunker->chunk( (string) $doc['text'] );

			// Wpis stracił treść tekstową — usuwamy jego stare fragmenty.
			if ( array() === $pieces ) {
				$this->repo->delete_by_post( $post_id );
				++$report['cleared'];
				continue;
			}

			// M1: podpis przestrzeni embeddingów wchodzi do hasha — zmiana modelu/
			// wymiarów unieważnia skip-unchanged i wymusza ponowne embedowanie.
			$sig    = $this->index_signature;
			$hashes = array_map(
				static function ( $piece ) use ( $sig ) {
					return KnowledgeRepository::hash( '' !== $sig ? $sig . "\n" . $piece : $piece );
				},
				$pieces
			);

			// Pomiń, jeśli zestaw fragmentów identyczny jak w bazie (zero kosztu API).
			if ( $this->unchanged( $hashes, $this->repo->hashes_for_post( $post_id ) ) ) {
				++$report['skipped'];
				continue;
			}

			$vectors = $this->batcher->embed_all( $pieces );
			if ( is_wp_error( $vectors ) ) {
				/* translators: 1: ID wpisu, 2: komunikat błędu */
				$report['errors'][] = sprintf( __( 'Wpis %1$d: %2$s', 'ai-faq-generator' ), $post_id, $vectors->get_error_message() );
				continue;
			}

			$chunks = array();
			foreach ( $pieces as $i => $content ) {
				$chunks[] = array(
					'chunk_index'  => $i,
					'content'      => $content,
					'content_hash' => $hashes[ $i ],
					'embedding'    => $vectors[ $i ] ?? null,
					'tokens'       => $this->estimate_tokens( $content ),
				);
			}

			// H3: zapis atomowy zwraca 0 przy ROLLBACK (błąd/lock) — NIE licz tego
			// jako sukces. Embeddingi zostały opłacone, więc sygnalizujemy błąd.
			$saved = $this->repo->replace_for_post( $post_id, $chunks );
			if ( $saved > 0 ) {
				$report['chunks'] += $saved;
				++$report['indexed'];
			} else {
				/* translators: %d: ID wpisu */
				$report['errors'][] = sprintf( __( 'Wpis %d: zapis fragmentów nie powiódł się (zmiany wycofane).', 'ai-faq-generator' ), $post_id );
			}
		}

		// H1: pruning tylko, gdy źródło COKOLWIEK zwróciło. Pusty wynik to prawie
		// zawsze bug/wyścig/filtr wtyczki, więc NIE kasujemy całej bazy — chronimy
		// (drogie) embeddingi. Pełny reset zostaje jawną akcją „Wyczyść bazę".
		if ( array() !== $seen ) {
			$report['pruned'] = $this->repo->delete_missing( array_keys( $seen ) );
		} else {
			$report['warnings'][] = __( 'Źródło nie zwróciło żadnych wpisów — pominięto usuwanie osieroconych. Baza wiedzy nietknięta.', 'ai-faq-generator' );
		}

		return $report;
	}

	/**
	 * Czy nowy zestaw hashy jest identyczny z zapisanym (ta sama liczba i kolejność).
	 *
	 * @param array<int,string> $new_hashes Hashe nowych fragmentów (indeks 0..n).
	 * @param array<int,string> $existing   Mapa chunk_index => hash z bazy.
	 * @return bool
	 */
	private function unchanged( array $new_hashes, array $existing ): bool {
		if ( count( $new_hashes ) !== count( $existing ) ) {
			return false;
		}
		foreach ( $new_hashes as $i => $hash ) {
			if ( ( $existing[ $i ] ?? null ) !== $hash ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Zgrubne oszacowanie liczby tokenów fragmentu (~4 znaki na token).
	 *
	 * @param string $content Treść fragmentu.
	 * @return int
	 */
	private function estimate_tokens( string $content ): int {
		$len = function_exists( 'mb_strlen' ) ? mb_strlen( $content ) : strlen( $content );
		return (int) ceil( $len / 4 );
	}
}
