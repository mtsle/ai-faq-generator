<?php
/**
 * Widok: Ustawienia (konfiguracja API).
 *
 * Placeholder na etapie szkieletu. Właściwy formularz — klucz API,
 * wybór modelu, temperatura, maks. liczba pytań, „Test połączenia" —
 * powstanie w kroku 2.
 *
 * @package AI_FAQ_Generator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap aifaq-wrap">
	<h1 class="aifaq-title">
		<span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>
		<?php esc_html_e( 'AI FAQ Generator', 'ai-faq-generator' ); ?>
		<span class="aifaq-sub"><?php esc_html_e( 'Ustawienia', 'ai-faq-generator' ); ?></span>
	</h1>

	<div class="aifaq-card">
		<span class="aifaq-badge"><?php esc_html_e( 'W budowie', 'ai-faq-generator' ); ?></span>
		<h2><?php esc_html_e( 'Konfiguracja API', 'ai-faq-generator' ); ?></h2>
		<p><?php esc_html_e( 'Tutaj skonfigurujesz dostawcę AI (domyślnie Google Gemini): klucz API, model, temperatura, maksymalna liczba pytań oraz przycisk „Test połączenia".', 'ai-faq-generator' ); ?></p>
	</div>
</div>
