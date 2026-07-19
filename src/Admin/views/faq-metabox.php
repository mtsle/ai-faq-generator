<?php
/**
 * Widok: metabox „AI FAQ" w edytorze wpisu/strony.
 *
 * Statyczna, PUSTA powłoka wg KONTRAKT k16-v2 §3: opis, pole liczby pytań,
 * przycisk „Generuj z treści wpisu", linia statusu oraz ukryty kontener wyników
 * z listą par i akcjami „Wstaw do wpisu" / „Kopiuj wszystko". Listę par i całą
 * logikę dokłada `assets/js/faq-metabox.js`, wygląd `assets/css/faq-metabox.css`.
 * Konfiguracja (window.aifaqMetabox) i assety wpięte
 * w {@see \AIFAQ\Admin\PostMetaBox::enqueue()}.
 *
 * TWARDE reguły DOM (§3b): każdy przycisk ma jawny typ `button` (w edytorze
 * klasycznym metabox siedzi w formularzu `post.php` bez guardu — brak typu
 * zapisałby wpis), żaden element nie ma atrybutu identyfikującego pole formularza
 * (Gutenberg serializuje cały `form.metabox-location-*` przy zapisie), a liczby
 * 5/20/10 pochodzą ze stałych PHP, nie z literałów.
 *
 * @package AI_FAQ_Generator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$aifaq_lang = (string) \AIFAQ\Core\Settings::get_field( 'language', 'pl' );
$aifaq_lang = in_array( $aifaq_lang, array( 'pl', 'en', 'de' ), true ) ? $aifaq_lang : 'pl';
$t          = \AIFAQ\Admin\PostMetaBox::strings( $aifaq_lang );
?>
<div class="aifaq-mb">
	<?php // Klasa `aifaq` niesie zmienne --aifaq-* (generator.css); faq-metabox.css przypina jasny motyw. ?>
	<div class="aifaq">
		<p class="aifaq-mb__lead"><?php echo esc_html( $t['mbLead'] ); ?></p>

		<div class="aifaq-mb__bar">
			<label class="aifaq-mb__label" for="aifaq-mb-count"><?php echo esc_html( $t['mbCount'] ); ?></label>
			<input
				type="number"
				id="aifaq-mb-count"
				class="aifaq-mb__count"
				min="<?php echo esc_attr( \AIFAQ\Admin\PostMetaBox::MIN_COUNT ); ?>"
				max="<?php echo esc_attr( \AIFAQ\Admin\PostMetaBox::MAX_COUNT ); ?>"
				step="1"
				value="<?php echo esc_attr( \AIFAQ\Admin\PostMetaBox::DEFAULT_COUNT ); ?>"
				autocomplete="off"
			>
			<button type="button" class="button button-primary aifaq-mb__gen" id="aifaq-mb-generate"><?php echo esc_html( $t['mbGenerate'] ); ?></button>
		</div>

		<p class="aifaq-mb__status" id="aifaq-mb-status" role="status" aria-live="polite"></p>

		<div class="aifaq-mb__results" id="aifaq-mb-results" hidden>
			<p class="aifaq-mb__summary" id="aifaq-mb-summary"></p>
			<p class="aifaq-mb__note" id="aifaq-mb-note" hidden></p>
			<div class="aifaq-mb__list" id="aifaq-mb-list"></div>
			<div class="aifaq-mb__acts">
				<button type="button" class="button button-primary aifaq-mb__insert" id="aifaq-mb-insert"><?php echo esc_html( $t['mbInsert'] ); ?></button>
				<button type="button" class="button aifaq-mb__copy" id="aifaq-mb-copy"><?php echo esc_html( $t['mbCopyAll'] ); ?></button>
			</div>
			<p class="aifaq-mb__hint"><?php echo esc_html( $t['mbHint'] ); ?></p>
		</div>
	</div>
</div>
