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
 * Krok 17 dokłada trzy rzeczy: batchowanie embeddingów PONAD wpisami (jedno
 * żądanie na falę zamiast jednego na wpis), pomijanie pruningu przy niekompletnym
 * źródle oraz bezpiecznik ilościowy sygnalizujący nagły ubytek fragmentów.
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
	 * Maksymalna liczba fragmentów w jednej fali embeddingów.
	 *
	 * Ochrona pamięci: przy `memory_limit = 128M` i witrynie z setkami wpisów
	 * płaska lista wszystkich fragmentów naraz jest zbyt kosztowna. Fale są
	 * niezależne — błąd jednej nie przerywa pozostałych.
	 */
	public const WAVE_SIZE = 500;

	/**
	 * Próg bezpiecznika ilościowego (spadek liczby fragmentów o 30%).
	 */
	public const DROP_ALERT = 0.30;

	/**
	 * Budżet czasu jednego przebiegu (sekundy) — poniżej typowego 60 s reverse proxy.
	 *
	 * Mierzony WYŁĄCZNIE w pętli fal embeddingów: tam idzie cały czas przebiegu.
	 * Po jego wyczerpaniu przebieg kończy się kulturalnie i prosi o ponowne kliknięcie
	 * (wznowienie jest RĘCZNE — skip-unchanged jest hashowy, więc idempotentne).
	 */
	public const BUDGET_SECONDS = 45;

	/**
	 * Odstęp między falami embeddingów (sekundy) — ≈4,6 żądania/min, poniżej limitu 5/min.
	 *
	 * Bez tego reindeks 1500 fragmentów wystrzeliwuje 15 żądań batch w kilka sekund; od
	 * szóstego lecą 429, a `embed_all()` porzuca CAŁĄ falę (do 500 fragmentów) na pierwszym
	 * błędnym batchu — łącznie z paczkami już opłaconymi.
	 */
	public const INDEX_PACE_SECONDS = 13;

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
	 * @return array{posts:int,indexed:int,skipped:int,cleared:int,pruned:int,chunks:int,errors:array<int,string>,warnings:array<int,string>,sources:array<string,array{docs:int,chars:int}>,filtered_lines:int,incomplete:bool,budget_hit:bool,skipped_no_vector:int,chunks_missing_vector:int}
	 *         Raport: liczba wpisów, zaindeksowanych, pominiętych (bez zmian),
	 *         wyczyszczonych (utraciły treść), usuniętych osieroconych, zapisanych
	 *         fragmentów, błędów, ostrzeżeń (rzeczy pominiętych świadomie), wkładu
	 *         poszczególnych źródeł oraz liczby przeniesionych linii balastu.
	 *
	 *         Cztery ostatnie klucze (Krok 19) są WEWNĘTRZNE — czyta je
	 *         {@see \AIFAQ\Admin\IndexController::run_reindex()}, żeby odróżnić przebieg
	 *         pełny od częściowego; UI ich nie renderuje (JS czyta stałą listę kluczy),
	 *         a jedynym kanałem do właściciela pozostaje `warnings`. Obecne ZAWSZE,
	 *         także wartościami zerowymi — brak klucza po stronie czytającej znaczy
	 *         „przebieg niepełny”, więc milczenie nigdy nie udaje sukcesu.
	 */
	public function run(): array {
		$report = array(
			'posts'                 => 0,
			'indexed'               => 0,
			'skipped'               => 0,
			'cleared'               => 0,
			'pruned'                => 0,
			'chunks'                => 0,
			'errors'                => array(),
			'warnings'              => array(),
			'sources'               => array(),
			'filtered_lines'        => 0,
			// Krok 19 — klucze WEWNĘTRZNE (JS renderuje stałą listę i po prostu je ignoruje).
			'incomplete'            => false,
			'budget_hit'            => false,
			'skipped_no_vector'     => 0,
			'chunks_missing_vector' => 0,
		);

		// Bezpiecznik ilościowy — stan PRZED przebiegiem.
		$chunks_before = $this->count_chunks();

		$seen    = array();
		$flat    = array();   // Płaska lista fragmentów do zwektoryzowania.
		$map     = array();   // Równoległa mapa: indeks w $flat => post_id + chunk_index.
		$pending = array();   // post_id => array{pieces, hashes} — wpisy do zapisania.

		foreach ( $this->source->documents() as $doc ) {
			++$report['posts'];
			$post_id          = (int) ( $doc['post_id'] ?? 0 );
			$seen[ $post_id ] = true;

			$text  = (string) ( $doc['text'] ?? '' );
			$title = trim( (string) ( $doc['title'] ?? '' ) );

			// Prepend tytułu WARUNKOWY: pusty tekst musi dalej dawać `cleared`,
			// inaczej sam tytuł udawałby treść i wpis nigdy nie zostałby wyczyszczony.
			$source_text = ( '' === trim( $text ) || '' === $title ) ? $text : $title . "\n" . $text;

			$pieces = $this->chunker->chunk( $source_text );

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

			// NAJPIERW skip-unchanged, DOPIERO POTEM zbieranie do paczki. Odwrotna
			// kolejność kasowałaby optymalizację M1 i czyniła każdy reindeks
			// pełnopłatnym — czyli odwrotnie do celu batchowania.
			if ( $this->unchanged( $hashes, $this->repo->hashes_for_post( $post_id ) ) ) {
				++$report['skipped'];
				continue;
			}

			foreach ( $pieces as $i => $piece ) {
				$map[ count( $flat ) ] = array(
					'post_id'     => $post_id,
					'chunk_index' => (int) $i,
				);
				$flat[] = $piece;
			}

			$pending[ $post_id ] = array(
				'pieces' => $pieces,
				'hashes' => $hashes,
			);
		}

		// Wektoryzacja falami — jedno wywołanie embed_all() na falę, niezależnie
		// od liczby wpisów w niej zawartych.
		$vectors_by_post = array();
		$failed_posts    = array();
		$total           = count( $flat );

		// F1-lite (§4.9): budżet czasu przebiegu. Rzutowany na FLOAT, bo budżety
		// ułamkowe muszą działać, a `(int)` ścinałby je do 0, czyli do „wyłączony”.
		$budget = self::BUDGET_SECONDS;
		if ( function_exists( 'apply_filters' ) ) {
			$budget = apply_filters( 'aifaq_index_budget', $budget );
		}
		$budget = (float) $budget;      // 0 (albo mniej) = budżet wyłączony

		// Tempo (§4.9-bis): odstęp między falami wobec limitu 5 żądań/min u dostawcy.
		$pace = self::INDEX_PACE_SECONDS;
		if ( function_exists( 'apply_filters' ) ) {
			$pace = apply_filters( 'aifaq_index_pace', $pace );
		}
		$pace = (int) $pace;            // 0 = tempo wyłączone

		$budget_hit = false;
		$started_at = microtime( true );

		for ( $start = 0; $start < $total; $start += self::WAVE_SIZE ) {
			// Gwarancja postępu (wzorzec CrawlQueue.php:229-231): pierwsza fala leci
			// ZAWSZE, dopiero kolejne podlegają budżetowi. Bez tego przebieg mógłby nie
			// wykonać ani jednej jednostki pracy i reindeks stanąłby na zawsze.
			// Mierzone `microtime()`, nie `time()` — sekundowa rozdzielczość nie wystarcza.
			if ( $start > 0 && $budget > 0 && ( microtime( true ) - $started_at ) >= $budget ) {
				$budget_hit = true;
				break;
			}

			// Odstęp MIĘDZY falami. Na początku kolejnej iteracji, więc po ostatniej fali
			// nie ma go z definicji, a przy przerwaniu budżetem nie zdąży się wykonać
			// (break jest wyżej). Legalny sen: kontekst admina, nie żądanie gościa.
			if ( $start > 0 && $pace > 0 ) {
				sleep( $pace );
			}

			$wave = array_slice( $flat, $start, self::WAVE_SIZE );

			$vectors = $this->batcher->embed_all( $wave );

			if ( self::is_error( $vectors ) ) {
				// Polityka błędu: jeden błąd fali → po jednym wpisie w raporcie na
				// każdy post_id z tej fali; pozostałe fale lecą normalnie.
				$message = method_exists( $vectors, 'get_error_message' ) ? (string) $vectors->get_error_message() : '';
				foreach ( $this->post_ids_in_wave( $map, $start, count( $wave ) ) as $pid ) {
					$failed_posts[ $pid ] = true;
					/* translators: 1: ID wpisu, 2: komunikat błędu */
					$report['errors'][] = sprintf( __( 'Wpis %1$d: %2$s', 'ai-faq-generator' ), $pid, $message );
				}
				continue;
			}

			foreach ( array_keys( $wave ) as $offset ) {
				$i = $start + (int) $offset;
				if ( ! isset( $map[ $i ] ) ) {
					continue;
				}
				$vectors_by_post[ $map[ $i ]['post_id'] ][ $map[ $i ]['chunk_index'] ] = is_array( $vectors ) ? ( $vectors[ $offset ] ?? null ) : null;
			}
		}

		// Zapis per wpis (atomowy) — dopiero po zebraniu wektorów.
		$skipped_no_vector     = 0;
		$chunks_missing_vector = 0;

		foreach ( $pending as $post_id => $data ) {
			// Wpis, którego fala padła, NIE jest zapisywany częściowo — błąd już
			// trafił do raportu, a stare (opłacone) fragmenty zostają nietknięte.
			if ( isset( $failed_posts[ $post_id ] ) ) {
				++$skipped_no_vector;
				$chunks_missing_vector += count( $data['pieces'] );
				continue;
			}

			// TAK SAMO wpis, dla którego brakuje CHOĆBY JEDNEGO wektora — bo fale go nie
			// objęły (budżet czasu) albo objęły tylko część jego fragmentów (wpis leżący
			// na styku dwóch fal). Zapis z `embedding => null` byłby gorszy niż pominięcie:
			// fragment dostałby NOWY content_hash przy ŻADNYM wektorze, więc `unchanged()`
			// (porównuje wyłącznie hasze) pomijałby ten wpis przy każdym kolejnym przebiegu
			// — dziura nie do naprawienia reindeksem, wyłącznie przez „Wyczyść bazę".
			$post_vectors = $vectors_by_post[ $post_id ] ?? array();
			$missing      = 0;
			foreach ( array_keys( $data['pieces'] ) as $i ) {
				if ( null === ( $post_vectors[ $i ] ?? null ) ) {
					++$missing;
				}
			}
			if ( $missing > 0 ) {
				++$skipped_no_vector;
				$chunks_missing_vector += count( $data['pieces'] );
				continue;
			}

			$chunks = array();
			foreach ( $data['pieces'] as $i => $content ) {
				$chunks[] = array(
					'chunk_index'  => $i,
					'content'      => $content,
					'content_hash' => $data['hashes'][ $i ],
					'embedding'    => $post_vectors[ $i ],
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

		// Duck typing, nigdy `instanceof` — Indexer nie zna klas kaskady źródeł.
		$source_incomplete = method_exists( $this->source, 'is_complete' ) && false === $this->source->is_complete();

		// TRZY ROZDZIELNE FLAGI, nie jedna zOR-owana (§4.9): $source_incomplete steruje
		// komunikatami o crawlu, $budget_hit komunikatem o budżecie i wyciszeniem
		// DROP_ALERT, a $prune_blocked WYŁĄCZNIE pruningiem. Po zOR-owaniu klient
		// z wyłączonym crawlem dostawał diagnozę „crawl się zawiesił” zamiast jedynej
		// użytecznej informacji „kliknij drugi raz”.
		$prune_blocked = $source_incomplete || $budget_hit;

		if ( $source_incomplete ) {
			$report['warnings'][] = __( 'Pobieranie treści renderowanej trwa — po zakończeniu uruchom indeksowanie ponownie.', 'ai-faq-generator' );
			// Źródło nie zwróciło jeszcze wszystkiego — pruning skasowałby fragmenty
			// stron, które po prostu nie zdążyły się pobrać.
			$report['warnings'][] = __( 'Źródło treści jest niekompletne — pominięto usuwanie osieroconych fragmentów.', 'ai-faq-generator' );
		}

		if ( $budget_hit ) {
			$report['warnings'][] = __( 'Indeksowanie przerwane po wyczerpaniu budżetu czasu — uruchom je ponownie, żeby dokończyć.', 'ai-faq-generator' );
		}

		if ( $prune_blocked ) {
			// Pruning pominięty: $seen jest NIEPUSTE także przy przebiegu częściowym
			// (buduje je pętla po dokumentach, która przechodzi cała), więc zabezpieczenie
			// pustej listy w KnowledgeRepository NIE zadziała, a delete_missing()
			// skasowałby fragmenty wszystkich wpisów, których fale nie zdążyły objąć.
			$report['pruned'] = 0;
		} elseif ( array() !== $seen ) {
			// H1: pruning tylko, gdy źródło COKOLWIEK zwróciło. Pusty wynik to prawie
			// zawsze bug/wyścig/filtr wtyczki, więc NIE kasujemy całej bazy — chronimy
			// (drogie) embeddingi. Pełny reset zostaje jawną akcją „Wyczyść bazę".
			$report['pruned'] = $this->repo->delete_missing( array_keys( $seen ) );
		} else {
			$report['warnings'][] = __( 'Źródło nie zwróciło żadnych wpisów — pominięto usuwanie osieroconych. Baza wiedzy nietknięta.', 'ai-faq-generator' );
		}

		// Raport rozszerzony (§3.7 pkt 6).
		if ( method_exists( $this->source, 'stats' ) ) {
			$stats             = $this->source->stats();
			$report['sources'] = is_array( $stats ) ? $stats : array();
		}
		$filter = __NAMESPACE__ . '\BoilerplateFilter';
		if ( class_exists( $filter ) && property_exists( $filter, 'last_filtered' ) ) {
			$report['filtered_lines'] = (int) BoilerplateFilter::$last_filtered;
		}

		// Bezpiecznik ilościowy — porównanie stanu bazy po przebiegu.
		// Wyciszany TYLKO przy przerwaniu budżetem: tam spadek jest spodziewany i normalny.
		// Przy niekompletnym ŹRÓDLE bezpiecznik ma strzelać — chroni od Kroku 5 przed
		// niezauważonym zjazdem bazy i Krok 19 nie ma prawa gasić go przy okazji.
		$chunks_after = $this->count_chunks();
		if ( ! $budget_hit && $chunks_before > 0 && $chunks_after < $chunks_before
			&& ( ( $chunks_before - $chunks_after ) / $chunks_before ) >= self::DROP_ALERT ) {
			/* translators: 1: liczba fragmentów przed, 2: liczba fragmentów po */
			$report['warnings'][] = sprintf(
				__( 'UWAGA: liczba fragmentów spadła z %1$d do %2$d — sprawdź źródła treści, zanim uznasz indeks za poprawny.', 'ai-faq-generator' ),
				$chunks_before,
				$chunks_after
			);
		}

		// Klucze wewnętrzne — ustawiane ZAWSZE, także zerami. Na nich `run_reindex()`
		// warunkuje zapis podpisu przestrzeni wektorów (§6.3): brak któregokolwiek
		// znaczy po stronie czytającej „przebieg niepełny”, więc migracja nigdy nie
		// domknęłaby się po cichu na przebiegu, który zostawił fragmenty bez wektorów.
		$report['incomplete']            = $prune_blocked;
		$report['budget_hit']            = $budget_hit;
		$report['skipped_no_vector']     = $skipped_no_vector;
		$report['chunks_missing_vector'] = $chunks_missing_vector;

		return $report;
	}

	/**
	 * Zbiera `post_id` obecne w danej fali fragmentów (bez powtórzeń, w kolejności).
	 *
	 * @param array<int,array{post_id:int,chunk_index:int}> $map   Mapa indeksów fragmentów.
	 * @param int                                           $start Indeks początkowy fali.
	 * @param int                                           $size  Rozmiar fali.
	 * @return array<int,int>
	 */
	private function post_ids_in_wave( array $map, int $start, int $size ): array {
		$ids = array();
		for ( $i = $start; $i < $start + $size; $i++ ) {
			if ( isset( $map[ $i ] ) ) {
				$ids[ (int) $map[ $i ]['post_id'] ] = true;
			}
		}

		return array_keys( $ids );
	}

	/**
	 * Liczba fragmentów w bazie (0, gdy repozytorium nie potrafi tego podać).
	 *
	 * @return int
	 */
	private function count_chunks(): int {
		if ( ! method_exists( $this->repo, 'stats' ) ) {
			return 0;
		}

		try {
			$stats = $this->repo->stats();
		} catch ( \Throwable $e ) {
			return 0;
		}

		return is_array( $stats ) ? (int) ( $stats['chunks'] ?? 0 ) : 0;
	}

	/**
	 * Czy wartość jest błędem WordPressa (odporne na brak WP w CLI).
	 *
	 * @param mixed $value Wynik wywołania.
	 * @return bool
	 */
	private static function is_error( $value ): bool {
		if ( function_exists( 'is_wp_error' ) ) {
			return is_wp_error( $value );
		}

		return $value instanceof \WP_Error;
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
