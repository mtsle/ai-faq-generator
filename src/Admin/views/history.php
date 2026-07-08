<?php
/**
 * Widok: Historia (dziennik pytań).
 *
 * Placeholder na etapie szkieletu. Właściwa lista — data, pytanie,
 * status (odpowiedziano/odmowa/błąd), źródło (cache/AI) i score — powstanie
 * w kroku 10 (dane z tabeli wp_aifaq_qa_log).
 *
 * @package AI_FAQ_Generator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap aifaq-wrap">
	<h1 class="aifaq-title">
		<span class="dashicons dashicons-backup" aria-hidden="true"></span>
		<?php esc_html_e( 'AI FAQ Generator', 'ai-faq-generator' ); ?>
		<span class="aifaq-sub"><?php esc_html_e( 'Historia', 'ai-faq-generator' ); ?></span>
	</h1>

	<div class="aifaq-card">
		<span class="aifaq-badge"><?php esc_html_e( 'W budowie', 'ai-faq-generator' ); ?></span>
		<h2><?php esc_html_e( 'Dziennik pytań', 'ai-faq-generator' ); ?></h2>
		<p><?php esc_html_e( 'Tutaj pojawi się lista pytań gości: data, treść pytania, status (odpowiedziano / odmowa / błąd), źródło (cache lub AI) oraz trafność. Tabela w bazie została już utworzona przy aktywacji wtyczki.', 'ai-faq-generator' ); ?></p>
	</div>
</div>
