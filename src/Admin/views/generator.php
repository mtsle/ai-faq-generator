<?php
/**
 * Widok: Generator (kokpit).
 *
 * „Panel = ta sama apka" (Krok 9): renderuje DOKŁADNIE ten sam widget Q&A co
 * publiczna strona `/faqgenerator` — {@see \AIFAQ\PublicUi\GeneratorPage::widget()}.
 * Front i admin dzielą jeden komponent (markup + generator.css + generator.js).
 * Assety i konfiguracja (window.aifaqFront) wpięte w {@see \AIFAQ\Admin\Menu::enqueue_assets()}.
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
		<span class="aifaq-sub"><?php esc_html_e( 'Generator', 'ai-faq-generator' ); ?></span>
	</h1>

	<p class="aifaq-lead">
		<?php esc_html_e( 'To dokładnie ten sam generator, który widzą goście na publicznej stronie. Zadaj pytanie, aby sprawdzić odpowiedzi na bazie zaindeksowanej treści.', 'ai-faq-generator' ); ?>
	</p>

	<div class="aifaq-embed">
		<?php
		// Markup zbudowany z esc_* wewnątrz widget() — bezpieczny do wypisania.
		echo \AIFAQ\PublicUi\GeneratorPage::widget(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
	</div>
</div>
