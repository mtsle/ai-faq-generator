<?php
/**
 * Widok: Historia generacji.
 *
 * Placeholder na etapie szkieletu. Właściwa lista — data, temat,
 * liczba pytań, użytkownik oraz akcje „Usuń" / „Wygeneruj ponownie" —
 * powstanie w kroku 7 (dane trafiają do tabeli utworzonej przy aktywacji).
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
		<h2><?php esc_html_e( 'Historia generacji', 'ai-faq-generator' ); ?></h2>
		<p><?php esc_html_e( 'Tutaj pojawi się lista wcześniejszych generacji: data, temat, liczba pytań i autor, z możliwością usunięcia lub ponownego wygenerowania. Tabela w bazie została już utworzona przy aktywacji wtyczki.', 'ai-faq-generator' ); ?></p>
	</div>
</div>
