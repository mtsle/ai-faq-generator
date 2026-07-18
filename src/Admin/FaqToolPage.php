<?php
/**
 * Konfiguracja i teksty ekranu „Narzędzie FAQ" (kokpit).
 *
 * Ekran w kokpicie, gdzie właściciel generuje pary Q&A z tematu: formularz
 * (Temat / Opis / Liczba) → REST `POST /admin/generate-faq` → tabela par z
 * akcjami Edytuj/Usuń/Skopiuj. PHP dostarcza tylko powłokę i konfigurację;
 * logikę pisze `assets/js/faq-tool.js`, wygląd `assets/css/faq-tool.css`.
 *
 * Wzorzec „config()→wp_localize_script" jak {@see \AIFAQ\App\AppShell::config()}.
 * ZERO sekretów w konfiguracji (żadnego klucza API) — patrz KONTRAKT §2.
 *
 * @package AI_FAQ_Generator
 */

namespace AIFAQ\Admin;

use AIFAQ\App\GenerationsPanel;
use AIFAQ\Core\Settings;
use AIFAQ\Rest\RestController;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dostawca konfiguracji (window.aifaqFaqTool) i tekstów UI dla ekranu narzędzia.
 */
class FaqToolPage {

	/**
	 * Konfiguracja dla JS (bez sekretów). Trafia do `window.aifaqFaqTool`.
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
	 * Teksty UI ekranu (pl/en/de wg Settings). Klucze wg KONTRAKT §5.
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
	 * Bieżący język UI (z Settings, fallback pl).
	 *
	 * @return string
	 */
	private static function lang(): string {
		$lang = (string) Settings::get_field( 'language', 'pl' );
		return in_array( $lang, array( 'pl', 'en', 'de' ), true ) ? $lang : 'pl';
	}
}
