<?php
/**
 * Widok: Narzędzie FAQ (kokpit).
 *
 * Statyczna powłoka ekranu generatora par Q&A wg KONTRAKT §4: formularz
 * (Temat / Opis / Liczba + „Generuj FAQ") oraz pusty kontener wyników z tabelą.
 * Wiersze tabeli i całą logikę dokłada `assets/js/faq-tool.js` (Etap 2),
 * wygląd `assets/css/faq-tool.css` (Etap 3). Konfiguracja (window.aifaqFaqTool)
 * i assety wpięte w {@see \AIFAQ\Admin\Menu::enqueue_assets()}.
 *
 * @package AI_FAQ_Generator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$aifaq_lang = (string) \AIFAQ\Core\Settings::get_field( 'language', 'pl' );
$aifaq_lang = in_array( $aifaq_lang, array( 'pl', 'en', 'de' ), true ) ? $aifaq_lang : 'pl';
$t          = \AIFAQ\Admin\FaqToolPage::strings( $aifaq_lang );
?>
<div class="wrap aifaq-wrap aifaq-ft">
	<h1 class="aifaq-title">
		<span class="dashicons dashicons-editor-help" aria-hidden="true"></span>
		<?php esc_html_e( 'AI FAQ Generator', 'ai-faq-generator' ); ?>
		<span class="aifaq-sub"><?php echo esc_html( $t['title'] ); ?></span>
	</h1>

	<p class="aifaq-lead"><?php echo esc_html( $t['lead'] ); ?></p>

	<?php // Klasa `aifaq` niesie zmienne --aifaq-* (generator.css); faq-tool.css przypina jasny motyw. ?>
	<div class="aifaq">
		<?php
		// Krok 18: markup narzędzia ma DOKŁADNIE JEDNO źródło prawdy. Ten sam string
		// konsumuje zakładka „Narzędzie FAQ" na podstronie generatora — nikt nie
		// utrzymuje dwóch kopii tych samych identyfikatorów.
		if ( class_exists( '\AIFAQ\App\FaqToolPanel' ) ) {
			echo \AIFAQ\App\FaqToolPanel::widget( $t ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup przeszedł esc_* w widget().
		} else {
			// Brak komponentu ma dać czytelny komunikat, nie pustą stronę.
			?>
			<div class="notice notice-error inline">
				<p><?php esc_html_e( 'Nie udało się wczytać narzędzia FAQ — brakuje komponentu panelu. Zgłoś to administratorowi witryny.', 'ai-faq-generator' ); ?></p>
			</div>
			<?php
		}
		?>
	</div>
</div>
