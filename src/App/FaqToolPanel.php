<?php
/**
 * Panel „Narzędzie FAQ" — JEDYNE źródło markupu, konfiguracji i tekstów (Krok 18).
 *
 * Bliźniak {@see HistoryPanel} i {@see GenerationsPanel}: ten sam markup renderuje
 * się w DWÓCH miejscach — w zakładce „Narzędzie FAQ" apki (`/generator-faq/` przez
 * shortcode oraz `/faqgenerator` przez trasę wirtualną, {@see AppShell::render_body()})
 * i na ekranie „Narzędzie FAQ" w kokpicie (`src/Admin/views/faq-tool.php`).
 * Jeden zestaw 15 identyfikatorów, jeden kawałek JS, zero duplikacji — do Kroku 18
 * markup mieszkał wyłącznie w widoku kokpitu, więc na froncie narzędzia NIE BYŁO.
 *
 * `config()` i `strings()` przeprowadziły się tutaj z {@see \AIFAQ\Admin\FaqToolPage},
 * które zostało cienkim proxy (Menu i widok kokpitu wołają je bez zmian).
 *
 * Kontekstowy chrome (nagłówek, karta, klasy `.aifaq-ft`/`.aifaq`/`.aifaq-ftp`)
 * należy do miejsca osadzenia — `widget()` emituje wyłącznie neutralny środek.
 *
 * BEZPIECZEŃSTWO: pary Q&A i temat to treść usera/modelu — JS wstawia je wyłącznie
 * przez `textContent`, nigdy `innerHTML` (ta sama reguła co w Krokach 10/13/14).
 *
 * @package AI_FAQ_Generator
 */

namespace AIFAQ\App;

use AIFAQ\Core\Settings;
use AIFAQ\Rest\RestController;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Markup, konfiguracja (window.aifaqFaqTool) i teksty narzędzia FAQ.
 */
class FaqToolPanel {

	/**
	 * ID korzenia widgetu — hak dla CSS i testów E2E.
	 */
	public const PANEL_ID = 'aifaq-ft-panel';

	/**
	 * Klucz zakładki w powłoce apki ({@see AppShell::render_body()}).
	 */
	public const TAB_KEY = 'ft';

	/**
	 * Czy widget został już wyrenderowany w tym żądaniu.
	 *
	 * `add_shortcode()` wykonuje handler dla KAŻDEGO wystąpienia znacznika, a motywy
	 * i page buildery potrafią wołać `the_content` dwa razy. Drugi zestaw pól byłby
	 * martwy (`getElementById` zwraca pierwszy), etykiety wiązałyby się z cudzymi
	 * polami, a dokument miałby 15 zduplikowanych `id` — cicha awaria, czyli dokładnie
	 * to, co ten Krok likwiduje.
	 *
	 * @var bool
	 */
	private static bool $rendered = false;

	/**
	 * Konfiguracja dla JS (bez sekretów). Trafia do `window.aifaqFaqTool`.
	 *
	 * Kształt ZAMROŻONY: 7 kluczy najwyższego poziomu, adresy PŁASKIE
	 * (`faq-tool.js` czyta `cfg.endpoint`/`cfg.exportEndpoint`/`cfg.detailEndpoint`,
	 * a nie zagnieżdżone `endpoints` jak {@see AppShell::config()}).
	 *
	 * @return array<string,mixed>
	 */
	public static function config(): array {
		$lang = self::lang();

		return array(
			'endpoint'       => esc_url_raw( rest_url( RestController::REST_NAMESPACE . '/admin/generate-faq' ) ),
			'exportEndpoint' => esc_url_raw( rest_url( RestController::REST_NAMESPACE . '/admin/export' ) ),
			// Krok 15 — prefill formularza z historii („Ponownie wygeneruj").
			'detailEndpoint' => esc_url_raw( rest_url( RestController::REST_NAMESPACE . '/admin/generations/detail' ) ),
			// Nazwa parametru URL pochodzi ZE STAŁEJ, nie z literału: ten sam
			// identyfikator czytają dwa różne pliki JS (app.js buduje link,
			// faq-tool.js go odczytuje) — literał w dwóch miejscach rozjechałby się.
			'regenParam'     => GenerationsPanel::REGEN_PARAM,
			'nonce'          => wp_create_nonce( 'wp_rest' ),
			'defaults'       => array(
				'count'    => 10,
				'min'      => 5,
				'max'      => 20,
				'language' => $lang,
			),
			'i18n'           => self::strings( $lang ),
		);
	}

	/**
	 * Teksty UI narzędzia (pl/en/de wg Settings) — 34 klucze, ta sama kolejność.
	 *
	 * Treści przeniesione 1:1 z dawnego `FaqToolPage::strings()`; Krok 18 nie jest
	 * krokiem redakcyjnym. Klucze są BEZ PREFIKSU i generyczne (`title`, `save`,
	 * `copy`, `export`), więc NIE WOLNO dokładać ich do `array_merge()` w
	 * {@see AppShell::strings()} — nadpisałyby teksty powłoki cicho, bez błędu.
	 *
	 * @param string $lang Kod języka.
	 * @return array<string,string>
	 */
	public static function strings( string $lang ): array {
		$all = array(
			'pl' => array(
				'title'      => __( 'Narzędzie FAQ', 'ai-faq-generator' ),
				'lead'       => __( 'Wpisz temat, opcjonalny opis i liczbę pytań — wygeneruję gotowe pary pytanie–odpowiedź, które możesz edytować, usuwać i kopiować.', 'ai-faq-generator' ),
				'labelTopic' => __( 'Temat', 'ai-faq-generator' ),
				'phTopic'    => __( 'np. Zwroty i reklamacje w sklepie', 'ai-faq-generator' ),
				'labelDesc'  => __( 'Dodatkowy opis (opcjonalnie)', 'ai-faq-generator' ),
				'phDesc'     => __( 'Doprecyzuj kontekst, ton, szczegóły…', 'ai-faq-generator' ),
				'labelCount' => __( 'Liczba pytań', 'ai-faq-generator' ),
				'generate'   => __( 'Generuj FAQ', 'ai-faq-generator' ),
				'generating' => __( 'Generuję…', 'ai-faq-generator' ),
				'needTopic'  => __( 'Podaj temat.', 'ai-faq-generator' ),
				'emptyMsg'   => __( 'Nie udało się ułożyć pytań dla tego tematu. Spróbuj doprecyzować.', 'ai-faq-generator' ),
				'errMsg'     => __( 'Wystąpił błąd generowania. Spróbuj ponownie.', 'ai-faq-generator' ),
				'doneFmt'    => __( 'Gotowe — %s par.', 'ai-faq-generator' ),
				'colQ'       => __( 'Pytanie', 'ai-faq-generator' ),
				'colA'       => __( 'Odpowiedź', 'ai-faq-generator' ),
				'colActions' => __( 'Akcje', 'ai-faq-generator' ),
				'edit'       => __( 'Edytuj', 'ai-faq-generator' ),
				'save'       => __( 'Zapisz', 'ai-faq-generator' ),
				'cancel'     => __( 'Anuluj', 'ai-faq-generator' ),
				'del'        => __( 'Usuń', 'ai-faq-generator' ),
				'copy'       => __( 'Kopiuj', 'ai-faq-generator' ),
				'copyAll'    => __( 'Kopiuj wszystko', 'ai-faq-generator' ),
				'copied'     => __( 'Skopiowano.', 'ai-faq-generator' ),
				'confirmDel' => __( 'Na pewno usunąć tę parę?', 'ai-faq-generator' ),
				// Krok 14 — sekcja eksportu.
				'export'        => __( 'Eksport', 'ai-faq-generator' ),
				'expHint'       => __( 'Wybierz format, a potem skopiuj lub pobierz gotowy kod.', 'ai-faq-generator' ),
				'expCopy'       => __( 'Kopiuj', 'ai-faq-generator' ),
				'expDownload'   => __( 'Pobierz', 'ai-faq-generator' ),
				'expCopied'     => __( 'Skopiowano.', 'ai-faq-generator' ),
				'expDownloaded' => __( 'Pobrano.', 'ai-faq-generator' ),
				'expEmpty'      => __( 'Brak par do eksportu.', 'ai-faq-generator' ),
				// Krok 15 — prefill formularza z historii generowań.
				'regenLoading'  => __( 'Wczytuję ustawienia z historii…', 'ai-faq-generator' ),
				'regenLoaded'   => __( 'Wczytano z historii — sprawdź i kliknij Generuj FAQ.', 'ai-faq-generator' ),
				'regenErr'      => __( 'Nie udało się wczytać wpisu historii.', 'ai-faq-generator' ),
			),
			'en' => array(
				'title'      => __( 'FAQ tool', 'ai-faq-generator' ),
				'lead'       => __( 'Enter a topic, an optional description and the number of questions — I will generate ready question–answer pairs you can edit, delete and copy.', 'ai-faq-generator' ),
				'labelTopic' => __( 'Topic', 'ai-faq-generator' ),
				'phTopic'    => __( 'e.g. Returns and complaints in the store', 'ai-faq-generator' ),
				'labelDesc'  => __( 'Additional description (optional)', 'ai-faq-generator' ),
				'phDesc'     => __( 'Add context, tone, details…', 'ai-faq-generator' ),
				'labelCount' => __( 'Number of questions', 'ai-faq-generator' ),
				'generate'   => __( 'Generate FAQ', 'ai-faq-generator' ),
				'generating' => __( 'Generating…', 'ai-faq-generator' ),
				'needTopic'  => __( 'Enter a topic.', 'ai-faq-generator' ),
				'emptyMsg'   => __( 'Could not build questions for this topic. Try to be more specific.', 'ai-faq-generator' ),
				'errMsg'     => __( 'Generation error. Please try again.', 'ai-faq-generator' ),
				'doneFmt'    => __( 'Done — %s pairs.', 'ai-faq-generator' ),
				'colQ'       => __( 'Question', 'ai-faq-generator' ),
				'colA'       => __( 'Answer', 'ai-faq-generator' ),
				'colActions' => __( 'Actions', 'ai-faq-generator' ),
				'edit'       => __( 'Edit', 'ai-faq-generator' ),
				'save'       => __( 'Save', 'ai-faq-generator' ),
				'cancel'     => __( 'Cancel', 'ai-faq-generator' ),
				'del'        => __( 'Delete', 'ai-faq-generator' ),
				'copy'       => __( 'Copy', 'ai-faq-generator' ),
				'copyAll'    => __( 'Copy all', 'ai-faq-generator' ),
				'copied'     => __( 'Copied.', 'ai-faq-generator' ),
				'confirmDel' => __( 'Delete this pair?', 'ai-faq-generator' ),
				// Krok 14 — sekcja eksportu.
				'export'        => __( 'Export', 'ai-faq-generator' ),
				'expHint'       => __( 'Pick a format, then copy or download the ready-made code.', 'ai-faq-generator' ),
				'expCopy'       => __( 'Copy', 'ai-faq-generator' ),
				'expDownload'   => __( 'Download', 'ai-faq-generator' ),
				'expCopied'     => __( 'Copied.', 'ai-faq-generator' ),
				'expDownloaded' => __( 'Downloaded.', 'ai-faq-generator' ),
				'expEmpty'      => __( 'No pairs to export.', 'ai-faq-generator' ),
				// Krok 15 — prefill formularza z historii generowań.
				'regenLoading'  => __( 'Loading settings from history…', 'ai-faq-generator' ),
				'regenLoaded'   => __( 'Loaded from history — review it and click Generate FAQ.', 'ai-faq-generator' ),
				'regenErr'      => __( 'Could not load the history entry.', 'ai-faq-generator' ),
			),
			'de' => array(
				'title'      => __( 'FAQ-Werkzeug', 'ai-faq-generator' ),
				'lead'       => __( 'Thema, optionale Beschreibung und Anzahl der Fragen eingeben — ich erstelle fertige Frage-Antwort-Paare, die du bearbeiten, löschen und kopieren kannst.', 'ai-faq-generator' ),
				'labelTopic' => __( 'Thema', 'ai-faq-generator' ),
				'phTopic'    => __( 'z. B. Rückgaben und Reklamationen im Shop', 'ai-faq-generator' ),
				'labelDesc'  => __( 'Zusätzliche Beschreibung (optional)', 'ai-faq-generator' ),
				'phDesc'     => __( 'Kontext, Ton, Details ergänzen…', 'ai-faq-generator' ),
				'labelCount' => __( 'Anzahl der Fragen', 'ai-faq-generator' ),
				'generate'   => __( 'FAQ generieren', 'ai-faq-generator' ),
				'generating' => __( 'Wird generiert…', 'ai-faq-generator' ),
				'needTopic'  => __( 'Bitte ein Thema angeben.', 'ai-faq-generator' ),
				'emptyMsg'   => __( 'Für dieses Thema konnten keine Fragen erstellt werden. Bitte präzisieren.', 'ai-faq-generator' ),
				'errMsg'     => __( 'Fehler bei der Generierung. Bitte erneut versuchen.', 'ai-faq-generator' ),
				'doneFmt'    => __( 'Fertig — %s Paare.', 'ai-faq-generator' ),
				'colQ'       => __( 'Frage', 'ai-faq-generator' ),
				'colA'       => __( 'Antwort', 'ai-faq-generator' ),
				'colActions' => __( 'Aktionen', 'ai-faq-generator' ),
				'edit'       => __( 'Bearbeiten', 'ai-faq-generator' ),
				'save'       => __( 'Speichern', 'ai-faq-generator' ),
				'cancel'     => __( 'Abbrechen', 'ai-faq-generator' ),
				'del'        => __( 'Löschen', 'ai-faq-generator' ),
				'copy'       => __( 'Kopieren', 'ai-faq-generator' ),
				'copyAll'    => __( 'Alle kopieren', 'ai-faq-generator' ),
				'copied'     => __( 'Kopiert.', 'ai-faq-generator' ),
				'confirmDel' => __( 'Dieses Paar löschen?', 'ai-faq-generator' ),
				// Krok 14 — sekcja eksportu.
				'export'        => __( 'Export', 'ai-faq-generator' ),
				'expHint'       => __( 'Format wählen, dann den fertigen Code kopieren oder herunterladen.', 'ai-faq-generator' ),
				'expCopy'       => __( 'Kopieren', 'ai-faq-generator' ),
				'expDownload'   => __( 'Herunterladen', 'ai-faq-generator' ),
				'expCopied'     => __( 'Kopiert.', 'ai-faq-generator' ),
				'expDownloaded' => __( 'Heruntergeladen.', 'ai-faq-generator' ),
				'expEmpty'      => __( 'Keine Paare zum Exportieren.', 'ai-faq-generator' ),
				// Krok 15 — prefill formularza z historii generowań.
				'regenLoading'  => __( 'Einstellungen aus dem Verlauf werden geladen…', 'ai-faq-generator' ),
				'regenLoaded'   => __( 'Aus dem Verlauf geladen — prüfen und auf „FAQ generieren" klicken.', 'ai-faq-generator' ),
				'regenErr'      => __( 'Der Verlaufseintrag konnte nicht geladen werden.', 'ai-faq-generator' ),
			),
		);

		return $all[ $lang ] ?? $all['pl'];
	}

	/**
	 * Markup narzędzia — neutralny środek bez chrome'u kontekstu.
	 *
	 * Wzorzec `HistoryPanel`/`GenerationsPanel`: przyjmuje GOTOWE teksty i sam nie
	 * woła `strings()`, dzięki czemu osadzający decyduje o języku raz dla całego
	 * ekranu. NIE emituje `.wrap`, `.aifaq-wrap`, `.aifaq-ft`, `.aifaq`, `<h1>`,
	 * `.aifaq-card` ani `.aifaq-ftp` — to zakłada kontekst (kokpit: widok
	 * `views/faq-tool.php`, front: {@see AppShell::render_body()}).
	 *
	 * Brak `.aifaq-ft` jest ŚWIADOMY: to rodzic, którego szuka pin jasnej palety
	 * `.aifaq-ft .aifaq` (`faq-tool.css`) — panel frontowy go nie wnosi, więc pin nie
	 * ma czego dopasować i front zostaje auto jasny/ciemny.
	 *
	 * Renderuje się RAZ na żądanie (15 unikatowych `id` + 3 `label[for]` nie zniosą
	 * duplikatu); drugie wywołanie zwraca pusty string.
	 *
	 * @param array<string,string> $t Teksty UI ({@see strings()}).
	 * @return string
	 */
	public static function widget( array $t ): string {
		if ( self::$rendered ) {
			return ''; // 15 unikatowych id + 3 label[for] nie zniosą duplikatu.
		}

		self::$rendered = true;

		ob_start();
		?>
		<div id="aifaq-ft-panel" class="aifaq-ft__panel">
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
					<?php // `type="submit"` jest WYMAGANE — JS słucha `form.submit`, nie `click`. ?>
					<button type="submit" id="aifaq-ft-generate" class="aifaq-ft__btn aifaq-ft__btn--primary"><?php echo esc_html( $t['generate'] ); ?></button>
					<span id="aifaq-ft-status" class="aifaq-ft__status" role="status" aria-live="polite"></span>
				</div>
			</form>

			<div id="aifaq-ft-results" class="aifaq-ft__results" hidden>
				<div class="aifaq-ft__toolbar">
					<button type="button" id="aifaq-ft-copyall" class="aifaq-ft__btn"><?php echo esc_html( $t['copyAll'] ); ?></button>
				</div>

				<?php // Przewijanie w poziomie MUSI obejmować samą tabelę — inaczej w wąskiej kolumnie frontu wywoziłoby pasek eksportu poza ekran. ?>
				<div class="aifaq-ft__tablewrap">
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
				</div>

				<?php // Krok 14 — sekcja eksportu (JS ożywia, CSS stylizuje). ?>
				<div id="aifaq-ft-export" class="aifaq-ft__export">
					<h3 class="aifaq-ft__exp-h"><?php echo esc_html( $t['export'] ); ?></h3>
					<p class="aifaq-ft__exp-hint"><?php echo esc_html( $t['expHint'] ); ?></p>
					<div class="aifaq-ft__exp-formats" role="tablist">
						<button type="button" class="aifaq-ft__exp-btn is-active" data-format="html">HTML</button>
						<button type="button" class="aifaq-ft__exp-btn" data-format="gutenberg">Gutenberg</button>
						<button type="button" class="aifaq-ft__exp-btn" data-format="elementor">Elementor</button>
						<button type="button" class="aifaq-ft__exp-btn" data-format="json">JSON</button>
						<button type="button" class="aifaq-ft__exp-btn" data-format="jsonld">JSON-LD</button>
					</div>
					<div class="aifaq-ft__exp-bar">
						<button type="button" id="aifaq-ft-exp-copy" class="aifaq-ft__btn"><?php echo esc_html( $t['expCopy'] ); ?></button>
						<button type="button" id="aifaq-ft-exp-download" class="aifaq-ft__btn"><?php echo esc_html( $t['expDownload'] ); ?></button>
						<span id="aifaq-ft-exp-status" class="aifaq-ft__status" role="status" aria-live="polite"></span>
					</div>
					<pre id="aifaq-ft-exp-output" class="aifaq-ft__exp-output" tabindex="0"></pre>
				</div>
			</div>
		</div>
		<?php
		// `trim` jest obowiązkowy — bufor niesie wcięcie szablonu, a testy asertują
		// początek stringa (korzeń `#aifaq-ft-panel`).
		return trim( (string) ob_get_clean() );
	}

	/**
	 * Zeruje flagę jednokrotnego renderu.
	 *
	 * NIE jest to metoda „tylko dla testów" — należy do mechanizmu PRODUKCYJNEGO:
	 * woła ją {@see \AIFAQ\PublicUi\Shortcode::reset_panel_flag()} na `loop_start`.
	 * Bez tego odrzucony przebieg filtra `the_content` (wyciąg `wp_trim_excerpt()`
	 * wołany przez wtyczki SEO w `wp_head`, kanały RSS, podglądy page builderów)
	 * wypaliłby slot i właściciel zobaczyłby PUSTĄ kartę — bez śladu w konsoli.
	 */
	public static function reset_render_flag(): void {
		self::$rendered = false;
	}

	/**
	 * Bieżący język UI (z Settings, fallback pl).
	 *
	 * @return string
	 */
	private static function lang(): string {
		$lang = (string) Settings::get_field( 'language', 'pl' );
		return in_array( $lang, array( 'pl', 'en', 'de' ), true ) ? $lang : 'pl';
	}
}
