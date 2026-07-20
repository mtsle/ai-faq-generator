<?php
/**
 * Kontroler indeksowania (panel administracyjny).
 *
 * Obsługuje akcje AJAX Dashboardu: „Zaindeksuj treść" i „Wyczyść bazę wiedzy".
 * Składa {@see Indexer} z realnych elementów (WpContentSource, Chunker,
 * EmbeddingBatcher nad providerem z fabryki, KnowledgeRepository), uruchamia go
 * i zwraca raport. Klucz API bierze z {@see Settings}; przy jego braku odmawia.
 *
 * @package AI_FAQ_Generator
 */

namespace AIFAQ\Admin;

use AIFAQ\Core\Settings;
use AIFAQ\Data\CacheRepository;
use AIFAQ\Data\KnowledgeRepository;
use AIFAQ\Index\Chunker;
use AIFAQ\Index\CompositeContentSource;
use AIFAQ\Index\CrawlQueue;
use AIFAQ\Index\EmbeddingBatcher;
use AIFAQ\Index\Indexer;
use AIFAQ\Index\PostMetaContentSource;
use AIFAQ\Index\RenderedContentSource;
use AIFAQ\Index\WpContentSource;
use AIFAQ\Providers\ProviderFactory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Kontroler AJAX indeksowania.
 */
class IndexController {

	/**
	 * Akcje AJAX i wspólny nonce.
	 */
	const AJAX_REINDEX = 'aifaq_reindex';
	const AJAX_CLEAR   = 'aifaq_clear_index';
	const NONCE        = 'aifaq_index';

	/**
	 * Transient-lock chroniący przed równoczesnym reindeksem/czyszczeniem (F5).
	 */
	const LOCK = 'aifaq_indexing_lock';

	/**
	 * Wymagane uprawnienie.
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * AJAX: uruchamia indeksowanie całej treści i zwraca raport.
	 */
	public function ajax_reindex(): void {
		$this->guard();

		$result = $this->run_reindex();
		if ( empty( $result['ok'] ) ) {
			wp_send_json_error( array( 'message' => (string) ( $result['message'] ?? '' ) ), (int) ( $result['status'] ?? 500 ) );
		}

		wp_send_json_success(
			array(
				'report' => $result['report'] ?? array(),
				'stats'  => $result['stats'] ?? array(),
			)
		);
	}

	/**
	 * AJAX: czyści całą bazę wiedzy.
	 */
	public function ajax_clear(): void {
		$this->guard();

		$result = $this->run_clear();
		if ( empty( $result['ok'] ) ) {
			wp_send_json_error( array( 'message' => (string) ( $result['message'] ?? '' ) ), (int) ( $result['status'] ?? 500 ) );
		}

		wp_send_json_success(
			array(
				'removed' => (int) ( $result['removed'] ?? 0 ),
				'stats'   => $result['stats'] ?? array(),
			)
		);
	}

	/**
	 * Rdzeń indeksowania — bez transportu (współdzielony przez AJAX i REST).
	 *
	 * NIE sprawdza nonce/uprawnień (to robi warstwa transportu: `guard()` w AJAX,
	 * `permission_callback` w REST). Egzekwuje logikę biznesową: klucz API + lock
	 * F5. Zwraca ustrukturyzowany wynik zamiast kończyć żądanie.
	 *
	 * Krok 17: przed przebiegiem uruchamia kaskadę źródeł treści — `post_content`
	 * (WpContentSource), pola własne (PostMetaContentSource) i treść wyrenderowaną
	 * przez crawl własnej witryny (RenderedContentSource). KOLEJNOŚĆ operacji jest
	 * wiążąca (KONTRAKT k17-v3 §4): najpierw kontrola „czy crawl trwa”, dopiero
	 * potem `seed()`. Odwrotna kolejność sprawiała, że `seed()` zapełniał kolejkę,
	 * a chwilę później ta sama metoda odmawiała startu — pierwszy klik nigdy nie
	 * indeksował.
	 *
	 * @return array{ok:bool,status:int,message?:string,report?:array,stats?:array}
	 */
	public function run_reindex(): array {
		if ( '' === (string) Settings::get_field( 'api_key', '' ) ) {
			return array(
				'ok'      => false,
				'status'  => 400,
				'message' => __( 'Najpierw zapisz klucz API w Ustawieniach.', 'ai-faq-generator' ),
			);
		}

		// F5: lock na równoczesny reindeks — drugie żądanie (inna karta, drugi
		// admin, bezpośredni POST/REST) nie odpali drugiego, płatnego przebiegu
		// ani nie przeplecie się z czyszczeniem bazy.
		if ( get_transient( self::LOCK ) ) {
			return array(
				'ok'      => false,
				'status'  => 409,
				'message' => __( 'Indeksowanie już trwa — poczekaj na zakończenie.', 'ai-faq-generator' ),
			);
		}
		set_transient( self::LOCK, 1, 15 * MINUTE_IN_SECONDS );
		// Backstop: zwolnij lock nawet przy fatalu w trakcie run() (exit pomija finally).
		$lock = self::LOCK;
		register_shutdown_function(
			static function () use ( $lock ) {
				delete_transient( $lock );
			}
		);

		// Indeksowanie może potrwać (embeddingi) — zdejmujemy limit czasu, jeśli wolno.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		// --- Krok 17: kaskada źródeł treści. ---

		// 1. NAJPIERW kontrola: czy crawl już trwa? Indeksowanie w trakcie pobierania
		//    dałoby połowiczną treść zwektoryzowaną za realne pieniądze.
		$crawl_on = ( '1' === (string) Settings::get_field( 'crawl_enabled', '1' ) );
		$queue    = class_exists( CrawlQueue::class ) ? new CrawlQueue() : null;

		if ( $crawl_on && null !== $queue ) {
			$progress = $queue->progress();
			if ( ! empty( $progress['running'] ) ) {
				delete_transient( self::LOCK );
				return array(
					'ok'      => false,
					'status'  => 409,
					'message' => sprintf(
						/* translators: 1: liczba pobranych stron, 2: liczba wszystkich stron */
						__( 'Trwa pobieranie stron: %1$d z %2$d. Poczekaj na zakończenie albo wyłącz crawl w ustawieniach.', 'ai-faq-generator' ),
						(int) ( $progress['done'] ?? 0 ),
						(int) ( $progress['total'] ?? 0 )
					),
				);
			}
		}

		// 2. Rozruch kolejki (przyrostowy) — dopiero teraz. Bez tego cała kaskada
		//    jest martwa: crawl nigdy by nie ruszył, a źródło renderowane byłoby puste.
		if ( $crawl_on && null !== $queue && class_exists( RenderedContentSource::class ) ) {
			$loopback = RenderedContentSource::loopback_ok();
			if ( ! empty( $loopback['ok'] ) ) {
				$added = $queue->seed();
				if ( $added > 0 ) {
					$queue->schedule();
				}
			}
		}

		// 3. Źródła. `meta_keys`/`meta_post_types` z ustawień trafiają realnie do
		//    konstruktora — inaczej właściciel wypełniałby pola, których nic nie czyta.
		$meta_keys  = array_filter( array_map( 'trim', explode( ',', (string) Settings::get_field( 'meta_keys', '' ) ) ), 'strlen' );
		$meta_types = array_filter( array_map( 'trim', explode( ',', (string) Settings::get_field( 'meta_post_types', 'post,page' ) ) ), 'strlen' );

		$sources = array( new WpContentSource() );
		if ( class_exists( PostMetaContentSource::class ) ) {
			$sources[] = new PostMetaContentSource( array_values( $meta_types ), array_values( $meta_keys ) );
		}
		if ( $crawl_on && class_exists( RenderedContentSource::class ) ) {
			$sources[] = new RenderedContentSource( $queue );
		}

		if ( function_exists( 'apply_filters' ) ) {
			$sources = apply_filters( 'aifaq_content_sources', $sources );
		}

		// Bramka `is_indexable()` jest wymuszana STRUKTURALNIE w kompozycie — także
		// dla źródeł wstrzykniętych filtrem powyżej (KONTRAKT §1 reguła 4).
		$source = class_exists( CompositeContentSource::class )
			? new CompositeContentSource( is_array( $sources ) ? $sources : array() )
			: new WpContentSource();

		// 4. Dotychczasowy przebieg.

		// Krok 19 (M5): indeksowanie liczy wektory w trybie DOKUMENTU. `make()` jest
		// świadomie zostawione ścieżce PYTANIA (§6.2 — „bez zmian, jak dziś”), więc
		// użycie go tutaj dałoby trzecią przestrzeń wektorów (FZ4).
		$batcher = method_exists( ProviderFactory::class, 'make_for_index' )
			? new EmbeddingBatcher( ProviderFactory::make_for_index() )
			: new EmbeddingBatcher( ProviderFactory::make() );

		// Podpis liczony RAZ: ten sam ciąg wchodzi do hashy fragmentów, do zapisanej
		// opcji i jako trzeci segment znacznika `partial:`. Dwa wyliczenia mogłyby się
		// rozjechać (np. gdy filtr `aifaq_embed_task` zwróci co innego za drugim razem).
		$sig = self::index_signature();

		$indexer = new Indexer(
			$source,
			new Chunker(),
			$batcher,
			new KnowledgeRepository(),
			$sig
		);

		$report = $indexer->run();

		// KONTRAKT k19-v3 §6.3. BEZPIECZNA STRONA: brak klucza => traktuj jak NIEPEŁNY
		// (`?? 1`, nigdy `?? 0`) — inaczej niezaimplementowany licznik wygląda jak sukces
		// i podpis zapisuje się po przebiegu, który zostawił część bazy w starej przestrzeni.
		$complete = ! (bool) ( $report['incomplete'] ?? true )
			&& 0 === (int) ( $report['skipped_no_vector'] ?? 1 )
			&& 0 === (int) ( $report['chunks_missing_vector'] ?? 1 );

		if ( function_exists( 'apply_filters' ) ) {
			$complete = (bool) apply_filters( 'aifaq_index_complete', $complete, $report );
		}

		if ( $complete ) {
			self::save_index_signature( $sig );

			// Treść się zmieniła → unieważnij cache odpowiedzi, inaczej `/ask` serwuje
			// stare odpowiedzi (z pominięciem retrievera i bramki tematu). WYŁĄCZNIE po
			// przebiegu PEŁNYM (§6.4 pkt 1): czyszczenie po przebiegu przerwanym zabierałoby
			// nawet dobre odpowiedzi i pogarszało bota dokładnie wtedy, gdy właściciel go naprawia.
			( new CacheRepository() )->clear_all();
		} else {
			self::save_index_signature( 'partial:' . self::partial_reason( $report ) . ':' . $sig );
		}

		$stats = ( new KnowledgeRepository() )->stats();

		delete_transient( self::LOCK );

		return array(
			'ok'     => true,
			'status' => 200,
			'report' => $report,
			'stats'  => $stats,
		);
	}

	/**
	 * Rdzeń czyszczenia bazy wiedzy — bez transportu (AJAX i REST).
	 *
	 * @return array{ok:bool,status:int,message?:string,removed?:int,stats?:array}
	 */
	public function run_clear(): array {
		// Nie czyść w trakcie indeksowania — inaczej clear i zapis przeplotą się (F5).
		if ( get_transient( self::LOCK ) ) {
			return array(
				'ok'      => false,
				'status'  => 409,
				'message' => __( 'Indeksowanie w toku — spróbuj wyczyścić po jego zakończeniu.', 'ai-faq-generator' ),
			);
		}

		$removed = ( new KnowledgeRepository() )->clear_all();

		// Cache odpowiedzi to funkcja bazy wiedzy — znika razem z nią.
		( new CacheRepository() )->clear_all();

		// Krok 19 (M5): baza wektorów przestała istnieć, więc podpis nie ma czego opisywać.
		// Bez tego po „Wyczyść bazę" komunikat migracji dawał stan 'ok', a ścieżka pytania
		// włączała tryb asymetryczny przy PUSTEJ bazie.
		if ( function_exists( 'delete_option' ) ) {
			delete_option( 'aifaq_index_signature' );
		}

		return array(
			'ok'      => true,
			'status'  => 200,
			'removed' => $removed,
			'stats'   => ( new KnowledgeRepository() )->stats(),
		);
	}

	/**
	 * Podpis przestrzeni embeddingów (dostawca|model|wymiary) dla skip-unchanged (M1).
	 *
	 * Zmiana modelu embeddingów lub liczby wymiarów zmienia ten podpis, co wymusza
	 * ponowne zwektoryzowanie treści zamiast pozostawienia wektorów z innej przestrzeni.
	 *
	 * Token `src2` (Krok 17) należy do tej samej rodziny: kaskada źródeł zmienia
	 * TREŚĆ karmiącą embeddingi, więc stare wektory opisują już co innego. Świadomie
	 * wymusza pełny re-embed przy pierwszym reindeksie po aktualizacji.
	 *
	 * Token `q:<zadanie>` (Krok 19) należy do tej samej rodziny: `taskType` embeddingu
	 * zmienia PRZESTRZEŃ wektorów, więc wektory policzone bez niego opisują co innego.
	 * Wartość pochodzi z {@see ProviderFactory::doc_task()} — jedynego źródła prawdy —
	 * a NIE z literału, bo filtr `aifaq_embed_task` potrafi to zadanie zmienić i podpis
	 * nie ma prawa wtedy kłamać.
	 *
	 * ŚWIADOMIE BEZ `AIFAQ_VERSION` (KONTRAKT k19-v3, FZ10): podpis wchodzi do content_hash
	 * każdego fragmentu, a każde wydanie wtyczki wymuszałoby wtedy pełny, płatny re-embed.
	 *
	 * @return string
	 */
	public static function index_signature(): string {
		return (string) Settings::get_field( 'provider', 'gemini' ) . '|'
			. (string) Settings::get_field( 'embed_model', '' ) . '|'
			. \AIFAQ\Providers\GeminiProvider::EMBED_DIMENSIONS . '|src2|'
			. 'q:' . self::embed_doc_task();
	}

	/**
	 * Efektywne zadanie embeddingu dokumentów — token przestrzeni wektorów do podpisu.
	 *
	 * Źródłem prawdy jest fabryka (KONTRAKT k19-v3 §3.8): podpis MUSI mówić prawdę o tym,
	 * jak realnie policzono wektory. Gdy fabryka nie zna jeszcze zadania dokumentu,
	 * indeksowanie idzie ścieżką `make()` — czyli BEZ `taskType` — i podpis odzwierciedla
	 * to PUSTYM tokenem, nigdy zmyślonym literałem. Inaczej twierdziłby „baza policzona
	 * asymetrycznie” przy bazie policzonej symetrycznie, a ścieżka pytania włączyłaby tryb
	 * asymetryczny do zupełnie innej przestrzeni wektorów.
	 *
	 * @return string
	 */
	private static function embed_doc_task(): string {
		if ( method_exists( ProviderFactory::class, 'doc_task' ) ) {
			return (string) ProviderFactory::doc_task();
		}

		return '';
	}

	/**
	 * Zapisuje podpis przestrzeni wektorów (albo znacznik `partial:`) — autoload 'no'.
	 *
	 * Dwutakt `add_option()` + `update_option()` jest obowiązkowy (KONTRAKT §2.8, wzorce
	 * `CrawlQueue.php:405-415`, `PageNotice.php:427-432`): opcja utworzona samym
	 * `update_option()` domyślnie się autoładuje, a ta ma być czytana wyłącznie w `/ask`
	 * i w kokpicie. `function_exists()` nie jest ozdobą — testy chodzą bez WordPressa.
	 *
	 * @param string $value Podpis albo znacznik `partial:<powod>:<podpis>`.
	 */
	private static function save_index_signature( string $value ): void {
		if ( function_exists( 'add_option' ) ) {
			add_option( 'aifaq_index_signature', '', '', 'no' );
		}
		if ( function_exists( 'update_option' ) ) {
			update_option( 'aifaq_index_signature', $value, false );
		}
	}

	/**
	 * Powód niepełnego przebiegu — środkowy segment znacznika `partial:<powod>:<podpis>`.
	 *
	 * Kolejność jest wiążąca: budżet czasu zawsze pociąga za sobą pominięte wektory,
	 * więc sprawdzany jest PIERWSZY — inaczej klient dostawałby diagnozę „błędy” zamiast
	 * jedynej użytecznej informacji „kliknij drugi raz”. `incomplete` bez `budget_hit`
	 * znaczy niekompletne źródło, czyli trwający crawl (§4.9 — trzy rozdzielne flagi).
	 *
	 * Zbiór zwracanych wartości jest ZAMKNIĘTY (`crawl|errors|budget`) — wartość spoza
	 * niego komunikat migracji zmapuje na pusty powód i klient straci diagnozę.
	 *
	 * @param array $report Raport z {@see Indexer::run()}.
	 * @return string 'crawl'|'errors'|'budget'
	 */
	private static function partial_reason( array $report ): string {
		if ( ! empty( $report['budget_hit'] ) ) {
			return 'budget';
		}
		if ( ! empty( $report['incomplete'] ) ) {
			return 'crawl';
		}

		return 'errors';
	}

	/**
	 * Statystyki bazy wiedzy (dla widoku Dashboardu).
	 *
	 * @return array{chunks:int,posts:int,embedded:int}
	 */
	public static function stats(): array {
		return ( new KnowledgeRepository() )->stats();
	}

	/**
	 * Wspólna bramka: nonce + uprawnienia. Kończy żądanie przy braku dostępu.
	 */
	private function guard(): void {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Brak uprawnień.', 'ai-faq-generator' ) ), 403 );
		}
	}
}
