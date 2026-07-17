<?php
/**
 * Widok: Historia (kokpit).
 *
 * „Panel = ta sama apka" — renderuje DOKŁADNIE ten sam komponent, co zakładka
 * „Historia" w apce `/faqgenerator`: {@see \AIFAQ\App\HistoryPanel::widget()}.
 * Jeden markup, jedno źródło danych (REST `/admin/history`), jeden kawałek JS
 * (app.js). Assety i konfiguracja (window.aifaqApp) wpięte w
 * {@see \AIFAQ\Admin\Menu::enqueue_assets()}.
 *
 * @package AI_FAQ_Generator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$aifaq_lang = (string) \AIFAQ\Core\Settings::get_field( 'language', 'pl' );
$aifaq_t    = \AIFAQ\App\HistoryPanel::strings( in_array( $aifaq_lang, array( 'pl', 'en', 'de' ), true ) ? $aifaq_lang : 'pl' );
?>
<div class="wrap aifaq-wrap">
	<h1 class="aifaq-title">
		<span class="dashicons dashicons-backup" aria-hidden="true"></span>
		<?php esc_html_e( 'AI FAQ Generator', 'ai-faq-generator' ); ?>
		<span class="aifaq-sub"><?php esc_html_e( 'Historia', 'ai-faq-generator' ); ?></span>
	</h1>

	<p class="aifaq-lead">
		<?php esc_html_e( 'Ten sam dziennik widzisz w zakładce „Historia" na stronie generatora — to jeden i ten sam panel.', 'ai-faq-generator' ); ?>
	</p>

	<div class="aifaq-embed">
		<?php // Klasa `aifaq` niesie zmienne --aifaq-* (generator.css), a `.aifaq-embed .aifaq` w admin.css przypina jasny motyw — pole treści wp-admin jest zawsze jasne. ?>
		<div class="aifaq">
			<?php
			// Markup zbudowany z esc_* wewnątrz widget() — bezpieczny do wypisania.
			echo \AIFAQ\App\HistoryPanel::widget( $aifaq_t ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			?>
		</div>
	</div>
</div>
