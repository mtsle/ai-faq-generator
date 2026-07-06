<?php
/**
 * Widok: Dashboard (generator FAQ).
 *
 * Na tym etapie (szkielet) to placeholder. Właściwy generator
 * — formularz, tabela wyników, eksport, podgląd JSON-LD — dojdzie
 * w kolejnych krokach.
 *
 * @package AI_FAQ_Generator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap aifaq-wrap">
	<h1 class="aifaq-title">
		<span class="dashicons dashicons-format-chat" aria-hidden="true"></span>
		<?php esc_html_e( 'AI FAQ Generator', 'ai-faq-generator' ); ?>
		<span class="aifaq-sub"><?php esc_html_e( 'Dashboard', 'ai-faq-generator' ); ?></span>
	</h1>

	<div class="aifaq-card">
		<span class="aifaq-badge"><?php esc_html_e( 'W budowie', 'ai-faq-generator' ); ?></span>
		<h2><?php esc_html_e( 'Generator FAQ', 'ai-faq-generator' ); ?></h2>
		<p><?php esc_html_e( 'Tutaj pojawi się formularz generowania: temat artykułu, dodatkowy opis, liczba pytań (5–20) oraz przycisk „Generuj FAQ". Po wygenerowaniu — tabela pytań i odpowiedzi z akcjami, eksport i podgląd JSON-LD.', 'ai-faq-generator' ); ?></p>
	</div>
</div>
