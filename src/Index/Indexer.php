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
	 * Konstruktor (wszystkie zależności wstrzykiwane — pełna testowalność).
	 *
	 * @param ContentSource       $source  Źródło treści.
	 * @param Chunker             $chunker Chunker.
	 * @param EmbeddingBatcher    $batcher Batcher embeddingów.
	 * @param KnowledgeRepository $repo    Repozytorium bazy wiedzy.
	 */
	public function __construct( ContentSource $source, Chunker $chunker, EmbeddingBatcher $batcher, KnowledgeRepository $repo ) {
		$this->source  = $source;
		$this->chunker = $chunker;
		$this->batcher = $batcher;
		$this->repo    = $repo;
	}

	/**
	 * Uruchamia indeksowanie całej treści.
	 *
	 * @return array{posts:int,indexed:int,skipped:int,cleared:int,pruned:int,chunks:int,errors:array<int,string>}
	 *         Raport: liczba wpisów, zaindeksowanych, pominiętych (bez zmian),
	 *         wyczyszczonych (utraciły treść), usuniętych osieroconych, zapisanych
	 *         fragmentów i błędów.
	 */
	public function run(): array {
		$report = array(
			'posts'   => 0,
			'indexed' => 0,
			'skipped' => 0,
			'cleared' => 0,
			'pruned'  => 0,
			'chunks'  => 0,
			'errors'  => array(),
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

			$hashes = array_map( array( KnowledgeRepository::class, 'hash' ), $pieces );

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

			$saved             = $this->repo->replace_for_post( $post_id, $chunks );
			$report['chunks'] += $saved;
			++$report['indexed'];
		}

		// Pruning: usuń z bazy wiedzy fragmenty wpisów, których już nie ma w źródle
		// (usunięte, odpublikowane, w koszu) — inaczej Retriever serwowałby je dalej.
		$report['pruned'] = $this->repo->delete_missing( array_keys( $seen ) );

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
