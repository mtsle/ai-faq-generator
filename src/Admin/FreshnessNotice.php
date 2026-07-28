<?php
/**
 * Komunikat „treść strony zmieniła się od ostatniego indeksowania" (RWA audyt K23, F1).
 *
 * {@see \AIFAQ\Admin\IndexNotice} pilnuje WYŁĄCZNIE zgodności PRZESTRZENI embeddingów
 * (model/taskType) — nie mówi nic o ŚWIEŻOŚCI treści. Właściciel, który edytuje cenę
 * albo godziny otwarcia na stronie, nie dostawał dotąd ŻADNEGO przypomnienia, że baza
 * wiedzy RAG jest nieaktualna dopóki sam nie pomyśli o ręcznym reindeksie — bot mógł
 * cicho serwować gościom stare dane (a cache odpowiedzi bez TTL to utrwala).
 *
 * ZERO wywołań API: licznik ({@see \AIFAQ\Data\KnowledgeRepository::stale_post_count()})
 * to porównanie dwóch znaczników czasu już obecnych w bazie. Komunikat gaśnie SAM po
 * najbliższym przebiegu indeksowania (pełnym albo takim, który dla tych wpisów trafi
 * w skip-unchanged i tylko odświeży `updated_at`) — bez dismiss/nonce, tym samym
 * wzorcem co {@see IndexNotice}: wyciszenie na stałe ukryłoby jedyne przypomnienie.
 *
 * @package AI_FAQ_Generator
 */

namespace AIFAQ\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Komunikat o nieświeżej treści w bazie wiedzy (hook `admin_notices`).
 */
class FreshnessNotice {

	/**
	 * Wypisuje komunikat, gdy co najmniej jeden wpis zmienił się od indeksowania.
	 */
	public static function render(): void {
		// 1. Komunikat jest dla właściciela witryny, nie dla redakcji.
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// 2. Tylko ekrany, na których komunikat ma sens (ten sam komplet co IndexNotice).
		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}
		$screen = get_current_screen();

		if ( ! is_object( $screen ) || ! isset( $screen->id ) ) {
			return;
		}
		$sid = (string) $screen->id;
		if ( 'plugins' !== $sid && 'dashboard' !== $sid && false === strpos( $sid, 'ai-faq-generator' ) ) {
			return;
		}

		if ( ! class_exists( '\AIFAQ\Data\KnowledgeRepository' ) ) {
			return;
		}

		try {
			$count = ( new \AIFAQ\Data\KnowledgeRepository() )->stale_post_count();
		} catch ( \Throwable $e ) {
			unset( $e );
			return;
		}

		if ( $count <= 0 ) {
			return;
		}

		$url = function_exists( 'admin_url' )
			? admin_url( 'admin.php?page=ai-faq-generator' )
			: 'admin.php?page=ai-faq-generator';

		/* translators: %d: liczba wpisów zmienionych od ostatniego indeksowania */
		$msg = sprintf(
			_n(
				'%d strona zmieniła się od ostatniego indeksowania — baza wiedzy asystenta może być nieaktualna.',
				'%d stron zmieniło się od ostatniego indeksowania — baza wiedzy asystenta może być nieaktualna.',
				$count,
				'ai-faq-generator'
			),
			$count
		);

		printf(
			'<div class="notice notice-info"><p><strong>AI FAQ Generator:</strong> %1$s</p><p><a class="button" href="%2$s">%3$s</a></p></div>',
			esc_html( $msg ),
			esc_url( $url ),
			esc_html__( 'Przejdź do Kokpitu', 'ai-faq-generator' )
		);
	}
}
