<?php
/**
 * Panel „Historia generowań" — zapisane generowania FAQ (wspólny komponent).
 *
 * Bliźniak {@see \AIFAQ\App\HistoryPanel}: ten sam markup renderuje się w DWÓCH
 * miejscach — w zakładce „Historia generowań" apki `/faqgenerator`
 * ({@see \AIFAQ\App\AppShell}) i na podstronie „Historia generowań" w kokpicie
 * (`src/Admin/views/faq-history.php`). Jedna karta, jeden zestaw identyfikatorów,
 * jeden kawałek JS — zero duplikacji.
 *
 * Różnica wobec HistoryPanel: tam mieszka dziennik pytań GOŚCI (`/admin/history`),
 * tutaj historia generowań WŁAŚCICIELA z tabeli `wp_aifaq_generations` (Krok 12),
 * czyli to, co powstało w „Narzędziu FAQ". Wiersze dociąga JS z REST
 * `GET /aifaq/v1/admin/generations`, a pary Q&A dopiero po rozwinięciu wiersza
 * z `GET /aifaq/v1/admin/generations/detail` (leniwie, z cache po stronie JS).
 *
 * Panel jest WYŁĄCZNIE dla właściciela — trasy REST wymagają `manage_options`,
 * a oba miejsca renderu są bramkowane uprawnieniem.
 *
 * BEZPIECZEŃSTWO: temat, opis i pary Q&A to treść wpisana przez usera i wygenerowana
 * przez model. JS wstawia je wyłącznie przez `textContent` — nigdy `innerHTML` —
 * ta sama reguła co w Krokach 10/13/14.
 *
 * @package AI_FAQ_Generator
 */

namespace AIFAQ\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Markup i teksty panelu Historii generowań.
 */
class GenerationsPanel {

	/**
	 * Domyślny rozmiar strony listy generowań.
	 *
	 * Osobna stała od {@see HistoryPanel::PER_PAGE} — to dwie niezależne listy,
	 * które mogą się rozjechać rozmiarem strony bez psucia się nawzajem.
	 */
	const PER_PAGE = 20;

	/**
	 * Nazwa parametru URL, którym „Ponownie wygeneruj" przekazuje ID wpisu
	 * do ekranu „Narzędzie FAQ" (prefill formularza).
	 *
	 * Jedno źródło prawdy: stała jest wystawiana do OBU configów JS
	 * (`aifaqApp.regenParam` i `aifaqFaqTool.regenParam`), żeby nazwa nie była
	 * wpisana literalnie w dwóch plikach JS i nie mogła się rozjechać.
	 */
	const REGEN_PARAM = 'aifaq_regen';

	/**
	 * Teksty panelu (pl/en/de) — dokładane do i18n powłoki apki i kokpitu.
	 *
	 * Prefiks `gh*` (generation history) jest rozłączny z `hist*`/`idx*`/`set*`,
	 * więc `array_merge()` w {@see AppShell::strings()} niczego nie nadpisuje.
	 *
	 * @param string $lang Kod języka.
	 * @return array<string,string>
	 */
	public static function strings( string $lang ): array {
		$all = array(
			'pl' => array(
				'ghTab'        => __( 'Historia generowań', 'ai-faq-generator' ),
				'ghTitle'      => __( 'Historia generowań', 'ai-faq-generator' ),
				'ghDesc'       => __( 'Wszystko, co wygenerowałeś w Narzędziu FAQ. Kliknij temat, aby zobaczyć zapisane pary pytanie–odpowiedź.', 'ai-faq-generator' ),
				'ghLoading'    => __( 'Wczytuję…', 'ai-faq-generator' ),
				'ghEmpty'      => __( 'Nie ma tu jeszcze nic — wygeneruj FAQ w Narzędziu FAQ.', 'ai-faq-generator' ),
				'ghError'      => __( 'Nie udało się wczytać historii. Spróbuj ponownie.', 'ai-faq-generator' ),
				'ghCountFmt'   => __( '%s generowań', 'ai-faq-generator' ),
				'ghPairsFmt'   => __( '%s par', 'ai-faq-generator' ),
				'ghDescFmt'    => __( 'Opis: %s', 'ai-faq-generator' ),
				'ghNoUser'     => __( '—', 'ai-faq-generator' ),
				'ghPrev'       => __( 'Poprzednie', 'ai-faq-generator' ),
				'ghNext'       => __( 'Następne', 'ai-faq-generator' ),
				'ghPageFmt'    => __( 'Strona %1$s z %2$s', 'ai-faq-generator' ),
				'ghRegen'      => __( 'Ponownie wygeneruj', 'ai-faq-generator' ),
				'ghDelete'     => __( 'Usuń', 'ai-faq-generator' ),
				'ghDeleteConf' => __( 'Na pewno usunąć ten wpis? Zapisane pary znikną bezpowrotnie.', 'ai-faq-generator' ),
				'ghDeleting'   => __( 'Usuwam…', 'ai-faq-generator' ),
				'ghDeleted'    => __( 'Usunięto.', 'ai-faq-generator' ),
				'ghDeleteErr'  => __( 'Nie udało się usunąć wpisu.', 'ai-faq-generator' ),
				'ghPairsLoad'  => __( 'Wczytuję pary…', 'ai-faq-generator' ),
				'ghPairsEmpty' => __( 'Ten wpis nie ma zapisanych par.', 'ai-faq-generator' ),
				'ghPairsErr'   => __( 'Nie udało się wczytać par.', 'ai-faq-generator' ),
				'ghCopyAll'    => __( 'Kopiuj wszystko', 'ai-faq-generator' ),
				'ghCopied'     => __( 'Skopiowano.', 'ai-faq-generator' ),
			),
			'en' => array(
				'ghTab'        => __( 'Generation history', 'ai-faq-generator' ),
				'ghTitle'      => __( 'Generation history', 'ai-faq-generator' ),
				'ghDesc'       => __( 'Everything you generated in the FAQ Tool. Click a topic to see the saved question–answer pairs.', 'ai-faq-generator' ),
				'ghLoading'    => __( 'Loading…', 'ai-faq-generator' ),
				'ghEmpty'      => __( 'Nothing here yet — generate a FAQ in the FAQ Tool.', 'ai-faq-generator' ),
				'ghError'      => __( 'Could not load the history. Please try again.', 'ai-faq-generator' ),
				'ghCountFmt'   => __( '%s generations', 'ai-faq-generator' ),
				'ghPairsFmt'   => __( '%s pairs', 'ai-faq-generator' ),
				'ghDescFmt'    => __( 'Description: %s', 'ai-faq-generator' ),
				'ghNoUser'     => __( '—', 'ai-faq-generator' ),
				'ghPrev'       => __( 'Previous', 'ai-faq-generator' ),
				'ghNext'       => __( 'Next', 'ai-faq-generator' ),
				'ghPageFmt'    => __( 'Page %1$s of %2$s', 'ai-faq-generator' ),
				'ghRegen'      => __( 'Generate again', 'ai-faq-generator' ),
				'ghDelete'     => __( 'Delete', 'ai-faq-generator' ),
				'ghDeleteConf' => __( 'Really delete this entry? The saved pairs will be gone for good.', 'ai-faq-generator' ),
				'ghDeleting'   => __( 'Deleting…', 'ai-faq-generator' ),
				'ghDeleted'    => __( 'Deleted.', 'ai-faq-generator' ),
				'ghDeleteErr'  => __( 'Could not delete the entry.', 'ai-faq-generator' ),
				'ghPairsLoad'  => __( 'Loading pairs…', 'ai-faq-generator' ),
				'ghPairsEmpty' => __( 'This entry has no saved pairs.', 'ai-faq-generator' ),
				'ghPairsErr'   => __( 'Could not load the pairs.', 'ai-faq-generator' ),
				'ghCopyAll'    => __( 'Copy all', 'ai-faq-generator' ),
				'ghCopied'     => __( 'Copied.', 'ai-faq-generator' ),
			),
			'de' => array(
				'ghTab'        => __( 'Generierungsverlauf', 'ai-faq-generator' ),
				'ghTitle'      => __( 'Generierungsverlauf', 'ai-faq-generator' ),
				'ghDesc'       => __( 'Alles, was Sie im FAQ-Werkzeug generiert haben. Klicken Sie ein Thema an, um die gespeicherten Frage-Antwort-Paare zu sehen.', 'ai-faq-generator' ),
				'ghLoading'    => __( 'Wird geladen…', 'ai-faq-generator' ),
				'ghEmpty'      => __( 'Noch nichts vorhanden — generieren Sie ein FAQ im FAQ-Werkzeug.', 'ai-faq-generator' ),
				'ghError'      => __( 'Verlauf konnte nicht geladen werden. Bitte erneut versuchen.', 'ai-faq-generator' ),
				'ghCountFmt'   => __( '%s Generierungen', 'ai-faq-generator' ),
				'ghPairsFmt'   => __( '%s Paare', 'ai-faq-generator' ),
				'ghDescFmt'    => __( 'Beschreibung: %s', 'ai-faq-generator' ),
				'ghNoUser'     => __( '—', 'ai-faq-generator' ),
				'ghPrev'       => __( 'Zurück', 'ai-faq-generator' ),
				'ghNext'       => __( 'Weiter', 'ai-faq-generator' ),
				'ghPageFmt'    => __( 'Seite %1$s von %2$s', 'ai-faq-generator' ),
				'ghRegen'      => __( 'Erneut generieren', 'ai-faq-generator' ),
				'ghDelete'     => __( 'Löschen', 'ai-faq-generator' ),
				'ghDeleteConf' => __( 'Diesen Eintrag wirklich löschen? Die gespeicherten Paare sind dann endgültig weg.', 'ai-faq-generator' ),
				'ghDeleting'   => __( 'Wird gelöscht…', 'ai-faq-generator' ),
				'ghDeleted'    => __( 'Gelöscht.', 'ai-faq-generator' ),
				'ghDeleteErr'  => __( 'Eintrag konnte nicht gelöscht werden.', 'ai-faq-generator' ),
				'ghPairsLoad'  => __( 'Paare werden geladen…', 'ai-faq-generator' ),
				'ghPairsEmpty' => __( 'Dieser Eintrag hat keine gespeicherten Paare.', 'ai-faq-generator' ),
				'ghPairsErr'   => __( 'Paare konnten nicht geladen werden.', 'ai-faq-generator' ),
				'ghCopyAll'    => __( 'Alles kopieren', 'ai-faq-generator' ),
				'ghCopied'     => __( 'Kopiert.', 'ai-faq-generator' ),
			),
		);

		return $all[ $lang ] ?? $all['pl'];
	}

	/**
	 * Markup panelu (pusta powłoka — wiersze dociąga JS z `/admin/generations`).
	 *
	 * Identyczny w obu miejscach renderu (front i kokpit), więc JS i CSS mają
	 * jeden zestaw identyfikatorów do trafienia. Bramka JS: `#aifaq-gh`.
	 *
	 * @param array<string,string> $t Teksty UI (z {@see strings()}).
	 * @return string
	 */
	public static function widget( array $t ): string {
		ob_start();
		?>
		<div class="aifaq-card aifaq-gh" id="aifaq-gh">
			<h2 class="aifaq-card__h"><?php echo esc_html( $t['ghTitle'] ); ?></h2>
			<p class="aifaq-card__p"><?php echo esc_html( $t['ghDesc'] ); ?></p>

			<div class="aifaq-gh__bar">
				<span class="aifaq-gh__count" id="aifaq-gh-count" role="status" aria-live="polite"></span>
			</div>

			<div class="aifaq-gh__list" id="aifaq-gh-list" aria-busy="false"></div>

			<div class="aifaq-gh__pager" id="aifaq-gh-pager" hidden>
				<button type="button" class="aifaq-btn2" id="aifaq-gh-prev"><?php echo esc_html( $t['ghPrev'] ); ?></button>
				<span class="aifaq-gh__page" id="aifaq-gh-page"></span>
				<button type="button" class="aifaq-btn2" id="aifaq-gh-next"><?php echo esc_html( $t['ghNext'] ); ?></button>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
