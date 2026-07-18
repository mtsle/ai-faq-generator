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
		<form id="aifaq-ft-form" class="aifaq-ft__form" novalidate>
			<div class="aifaq-ft__field">
				<label class="aifaq-ft__label" for="aifaq-ft-topic"><?php echo esc_html( $t['labelTopic'] ); ?></label>
				<input
					type="text"
					id="aifaq-ft-topic"
					class="aifaq-ft__input"
					placeholder="<?php echo esc_attr( $t['phTopic'] ); ?>"
					autocomplete="off"
					required
				>
			</div>

			<div class="aifaq-ft__field">
				<label class="aifaq-ft__label" for="aifaq-ft-desc"><?php echo esc_html( $t['labelDesc'] ); ?></label>
				<textarea
					id="aifaq-ft-desc"
					class="aifaq-ft__area"
					rows="2"
					placeholder="<?php echo esc_attr( $t['phDesc'] ); ?>"
				></textarea>
			</div>

			<div class="aifaq-ft__field aifaq-ft__field--count">
				<label class="aifaq-ft__label" for="aifaq-ft-count"><?php echo esc_html( $t['labelCount'] ); ?></label>
				<input
					type="number"
					id="aifaq-ft-count"
					class="aifaq-ft__count"
					min="5"
					max="20"
					step="1"
					value="10"
					inputmode="numeric"
				>
			</div>

			<div class="aifaq-ft__actions">
				<button type="submit" id="aifaq-ft-generate" class="aifaq-ft__btn aifaq-ft__btn--primary"><?php echo esc_html( $t['generate'] ); ?></button>
				<span id="aifaq-ft-status" class="aifaq-ft__status" role="status" aria-live="polite"></span>
			</div>
		</form>

		<div id="aifaq-ft-results" class="aifaq-ft__results" hidden>
			<div class="aifaq-ft__toolbar">
				<button type="button" id="aifaq-ft-copyall" class="aifaq-ft__btn"><?php echo esc_html( $t['copyAll'] ); ?></button>
			</div>
			<table class="aifaq-ft__table">
				<thead>
					<tr>
						<th scope="col"><?php echo esc_html( $t['colQ'] ); ?></th>
						<th scope="col"><?php echo esc_html( $t['colA'] ); ?></th>
						<th scope="col" class="aifaq-ft__th-acts"><?php echo esc_html( $t['colActions'] ); ?></th>
					</tr>
				</thead>
				<tbody id="aifaq-ft-tbody"></tbody>
			</table>

			<?php // Krok 14 — sekcja eksportu (JS ożywia, CSS stylizuje); wg KONTRAKT §3. ?>
			<div id="aifaq-ft-export" class="aifaq-ft__export">
				<h3 class="aifaq-ft__exp-h"><?php echo esc_html( $t['export'] ); ?></h3>
				<p class="aifaq-ft__exp-hint"><?php echo esc_html( $t['expHint'] ); ?></p>
				<div class="aifaq-ft__exp-formats" role="tablist">
					<button class="aifaq-ft__exp-btn is-active" data-format="html"      type="button">HTML</button>
					<button class="aifaq-ft__exp-btn"           data-format="gutenberg" type="button">Gutenberg</button>
					<button class="aifaq-ft__exp-btn"           data-format="elementor" type="button">Elementor</button>
					<button class="aifaq-ft__exp-btn"           data-format="json"      type="button">JSON</button>
					<button class="aifaq-ft__exp-btn"           data-format="jsonld"    type="button">JSON-LD</button>
				</div>
				<div class="aifaq-ft__exp-bar">
					<button id="aifaq-ft-exp-copy"     class="aifaq-ft__btn" type="button"><?php echo esc_html( $t['expCopy'] ); ?></button>
					<button id="aifaq-ft-exp-download" class="aifaq-ft__btn" type="button"><?php echo esc_html( $t['expDownload'] ); ?></button>
					<span   id="aifaq-ft-exp-status"   class="aifaq-ft__status" role="status" aria-live="polite"></span>
				</div>
				<pre id="aifaq-ft-exp-output" class="aifaq-ft__exp-output" tabindex="0"></pre>
			</div>
		</div>
	</div>
</div>
