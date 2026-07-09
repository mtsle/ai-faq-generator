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
	 * Wymagane uprawnienie.
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * AJAX: uruchamia indeksowanie całej treści i zwraca raport.
	 */
	public function ajax_reindex(): void {
		$this->guard();

		if ( '' === (string) Settings::get_field( 'api_key', '' ) ) {
			wp_send_json_error( array( 'message' => __( 'Najpierw zapisz klucz API w Ustawieniach.', 'ai-faq-generator' ) ) );
		}

		// Indeksowanie może potrwać (embeddingi) — zdejmujemy limit czasu, jeśli wolno.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		$indexer = new Indexer(
			new WpContentSource(),
			new Chunker(),
			new EmbeddingBatcher( ProviderFactory::make() ),
			new KnowledgeRepository()
		);

		$report = $indexer->run();

		wp_send_json_success(
			array(
				'report' => $report,
				'stats'  => ( new KnowledgeRepository() )->stats(),
			)
		);
	}

	/**
	 * AJAX: czyści całą bazę wiedzy.
	 */
	public function ajax_clear(): void {
		$this->guard();

		$removed = ( new KnowledgeRepository() )->clear_all();

		wp_send_json_success(
			array(
				'removed' => $removed,
				'stats'   => ( new KnowledgeRepository() )->stats(),
			)
		);
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
