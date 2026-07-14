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
use AIFAQ\Index\EmbeddingBatcher;
use AIFAQ\Index\Indexer;
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

		$indexer = new Indexer(
			new WpContentSource(),
			new Chunker(),
			new EmbeddingBatcher( ProviderFactory::make() ),
			new KnowledgeRepository(),
			self::index_signature()
		);

		$report = $indexer->run();

		// Treść się zmieniła → unieważnij cache odpowiedzi, inaczej `/ask` serwuje
		// stare odpowiedzi (z pominięciem retrievera i bramki tematu).
		( new CacheRepository() )->clear_all();

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
	 * @return string
	 */
	private static function index_signature(): string {
		return (string) Settings::get_field( 'provider', 'gemini' ) . '|'
			. (string) Settings::get_field( 'embed_model', '' ) . '|'
			. \AIFAQ\Providers\GeminiProvider::EMBED_DIMENSIONS;
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
