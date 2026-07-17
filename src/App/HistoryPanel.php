<?php
/**
 * Panel „Historia" — dziennik pytań gości (wspólny komponent).
 *
 * Ten sam markup renderuje się w DWÓCH miejscach: w zakładce „Historia" apki
 * `/faqgenerator` ({@see \AIFAQ\App\AppShell}) i na podstronie „Historia"
 * w kokpicie (`src/Admin/views/history.php`) — dokładnie tak, jak generator
 * dzieli {@see \AIFAQ\PublicUi\GeneratorPage::widget()} między front a wp-admin.
 * Zero duplikacji: jedna karta, jeden zestaw identyfikatorów, jeden kawałek JS.
 *
 * Wiersze wypełnia JS z REST `GET /aifaq/v1/admin/history` (stronicowanie i filtr
 * bez przeładowania). Panel jest WYŁĄCZNIE dla właściciela — trasa REST wymaga
 * `manage_options`, a oba miejsca renderu są bramkowane uprawnieniem.
 *
 * BEZPIECZEŃSTWO: wiersze to treść wpisana przez gościa (pytanie i odpowiedź
 * modelu). JS wstawia je wyłącznie przez `textContent` — nigdy `innerHTML` —
 * więc żaden HTML gościa nie ma jak się wykonać. To domyka dług XSS zgłoszony
 * w audycie Kroków 0–5 („render danych gości w Kroku 10").
 *
 * @package AI_FAQ_Generator
 */

namespace AIFAQ\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Markup i teksty panelu Historii.
 */
class HistoryPanel {

	/**
	 * Domyślny rozmiar strony dziennika.
	 */
	const PER_PAGE = 20;

	/**
	 * Teksty panelu (pl/en/de) — dokładane do i18n powłoki apki i kokpitu.
	 *
	 * @param string $lang Kod języka.
	 * @return array<string,string>
	 */
	public static function strings( string $lang ): array {
		$all = array(
			'pl' => array(
				'histTitle'     => __( 'Dziennik pytań', 'ai-faq-generator' ),
				'histDesc'      => __( 'Co goście pytają i jak generator odpowiada. Kliknij pytanie, aby zobaczyć pełną odpowiedź.', 'ai-faq-generator' ),
				'histTotal'     => __( 'Wszystkich pytań', 'ai-faq-generator' ),
				'histToday'     => __( 'Dziś', 'ai-faq-generator' ),
				'histWeek'      => __( 'Ostatnie 7 dni', 'ai-faq-generator' ),
				'histRefused'   => __( 'Odmów (poza tematem)', 'ai-faq-generator' ),
				'histCached'    => __( 'Z cache (bez kosztu)', 'ai-faq-generator' ),
				'histAvg'       => __( 'Średnia trafność', 'ai-faq-generator' ),
				'histFilterAll' => __( 'Wszystkie', 'ai-faq-generator' ),
				'histLoading'   => __( 'Wczytuję…', 'ai-faq-generator' ),
				'histEmpty'     => __( 'Nie ma tu jeszcze nic. Wpisy pojawią się, gdy goście zaczną zadawać pytania.', 'ai-faq-generator' ),
				'histError'     => __( 'Nie udało się wczytać dziennika. Spróbuj ponownie.', 'ai-faq-generator' ),
				'histNoAnswer'  => __( '(brak odpowiedzi — pytanie zakończyło się błędem)', 'ai-faq-generator' ),
				'histPurge'     => __( 'Wyczyść dziennik', 'ai-faq-generator' ),
				'histPurgeConf' => __( 'Na pewno usunąć CAŁY dziennik pytań? Tej operacji nie da się cofnąć.', 'ai-faq-generator' ),
				'histPurging'   => __( 'Czyszczę dziennik…', 'ai-faq-generator' ),
				'histPurged'    => __( 'Dziennik wyczyszczony.', 'ai-faq-generator' ),
				'histPrev'      => __( 'Poprzednie', 'ai-faq-generator' ),
				'histNext'      => __( 'Następne', 'ai-faq-generator' ),
				'histPageFmt'   => __( 'Strona %1$s z %2$s', 'ai-faq-generator' ),
				'histCountFmt'  => __( '%s wpisów', 'ai-faq-generator' ),
				'stAnswered'    => __( 'Odpowiedziano', 'ai-faq-generator' ),
				'stRefused'     => __( 'Odmowa', 'ai-faq-generator' ),
				'stError'       => __( 'Błąd', 'ai-faq-generator' ),
				'srcAi'         => __( 'AI', 'ai-faq-generator' ),
				'srcCache'      => __( 'cache', 'ai-faq-generator' ),
				'srcRateLimit'  => __( 'limit zapytań', 'ai-faq-generator' ),
			),
			'en' => array(
				'histTitle'     => __( 'Question log', 'ai-faq-generator' ),
				'histDesc'      => __( 'What visitors ask and how the generator answers. Click a question to see the full answer.', 'ai-faq-generator' ),
				'histTotal'     => __( 'Questions total', 'ai-faq-generator' ),
				'histToday'     => __( 'Today', 'ai-faq-generator' ),
				'histWeek'      => __( 'Last 7 days', 'ai-faq-generator' ),
				'histRefused'   => __( 'Refusals (off-topic)', 'ai-faq-generator' ),
				'histCached'    => __( 'From cache (no cost)', 'ai-faq-generator' ),
				'histAvg'       => __( 'Average relevance', 'ai-faq-generator' ),
				'histFilterAll' => __( 'All', 'ai-faq-generator' ),
				'histLoading'   => __( 'Loading…', 'ai-faq-generator' ),
				'histEmpty'     => __( 'Nothing here yet. Entries appear once visitors start asking questions.', 'ai-faq-generator' ),
				'histError'     => __( 'Could not load the log. Please try again.', 'ai-faq-generator' ),
				'histNoAnswer'  => __( '(no answer — the question ended with an error)', 'ai-faq-generator' ),
				'histPurge'     => __( 'Clear log', 'ai-faq-generator' ),
				'histPurgeConf' => __( 'Really delete the WHOLE question log? This cannot be undone.', 'ai-faq-generator' ),
				'histPurging'   => __( 'Clearing log…', 'ai-faq-generator' ),
				'histPurged'    => __( 'Log cleared.', 'ai-faq-generator' ),
				'histPrev'      => __( 'Previous', 'ai-faq-generator' ),
				'histNext'      => __( 'Next', 'ai-faq-generator' ),
				'histPageFmt'   => __( 'Page %1$s of %2$s', 'ai-faq-generator' ),
				'histCountFmt'  => __( '%s entries', 'ai-faq-generator' ),
				'stAnswered'    => __( 'Answered', 'ai-faq-generator' ),
				'stRefused'     => __( 'Refused', 'ai-faq-generator' ),
				'stError'       => __( 'Error', 'ai-faq-generator' ),
				'srcAi'         => __( 'AI', 'ai-faq-generator' ),
				'srcCache'      => __( 'cache', 'ai-faq-generator' ),
				'srcRateLimit'  => __( 'rate limit', 'ai-faq-generator' ),
			),
			'de' => array(
				'histTitle'     => __( 'Fragen-Protokoll', 'ai-faq-generator' ),
				'histDesc'      => __( 'Was Besucher fragen und wie der Generator antwortet. Klicken Sie eine Frage an, um die ganze Antwort zu sehen.', 'ai-faq-generator' ),
				'histTotal'     => __( 'Fragen gesamt', 'ai-faq-generator' ),
				'histToday'     => __( 'Heute', 'ai-faq-generator' ),
				'histWeek'      => __( 'Letzte 7 Tage', 'ai-faq-generator' ),
				'histRefused'   => __( 'Ablehnungen (Thema verfehlt)', 'ai-faq-generator' ),
				'histCached'    => __( 'Aus dem Cache (ohne Kosten)', 'ai-faq-generator' ),
				'histAvg'       => __( 'Durchschnittliche Relevanz', 'ai-faq-generator' ),
				'histFilterAll' => __( 'Alle', 'ai-faq-generator' ),
				'histLoading'   => __( 'Wird geladen…', 'ai-faq-generator' ),
				'histEmpty'     => __( 'Noch nichts vorhanden. Einträge erscheinen, sobald Besucher Fragen stellen.', 'ai-faq-generator' ),
				'histError'     => __( 'Protokoll konnte nicht geladen werden. Bitte erneut versuchen.', 'ai-faq-generator' ),
				'histNoAnswer'  => __( '(keine Antwort — die Frage endete mit einem Fehler)', 'ai-faq-generator' ),
				'histPurge'     => __( 'Protokoll leeren', 'ai-faq-generator' ),
				'histPurgeConf' => __( 'Das GESAMTE Fragen-Protokoll wirklich löschen? Das kann nicht rückgängig gemacht werden.', 'ai-faq-generator' ),
				'histPurging'   => __( 'Protokoll wird geleert…', 'ai-faq-generator' ),
				'histPurged'    => __( 'Protokoll geleert.', 'ai-faq-generator' ),
				'histPrev'      => __( 'Zurück', 'ai-faq-generator' ),
				'histNext'      => __( 'Weiter', 'ai-faq-generator' ),
				'histPageFmt'   => __( 'Seite %1$s von %2$s', 'ai-faq-generator' ),
				'histCountFmt'  => __( '%s Einträge', 'ai-faq-generator' ),
				'stAnswered'    => __( 'Beantwortet', 'ai-faq-generator' ),
				'stRefused'     => __( 'Abgelehnt', 'ai-faq-generator' ),
				'stError'       => __( 'Fehler', 'ai-faq-generator' ),
				'srcAi'         => __( 'KI', 'ai-faq-generator' ),
				'srcCache'      => __( 'Cache', 'ai-faq-generator' ),
				'srcRateLimit'  => __( 'Anfragelimit', 'ai-faq-generator' ),
			),
		);

		return $all[ $lang ] ?? $all['pl'];
	}

	/**
	 * Markup panelu (pusta powłoka — dane dociąga JS z `/admin/history`).
	 *
	 * @param array<string,string> $t Teksty UI (z {@see strings()}).
	 * @return string
	 */
	public static function widget( array $t ): string {
		$tiles = array(
			'total'    => $t['histTotal'],
			'today'    => $t['histToday'],
			'week'     => $t['histWeek'],
			'refused'  => $t['histRefused'],
			'cached'   => $t['histCached'],
			'avgscore' => $t['histAvg'],
		);

		$filters = array(
			''         => $t['histFilterAll'],
			'answered' => $t['stAnswered'],
			'refused'  => $t['stRefused'],
			'error'    => $t['stError'],
		);

		ob_start();
		?>
		<div class="aifaq-card aifaq-hist" id="aifaq-hist">
			<h2 class="aifaq-card__h"><?php echo esc_html( $t['histTitle'] ); ?></h2>
			<p class="aifaq-card__p"><?php echo esc_html( $t['histDesc'] ); ?></p>

			<div class="aifaq-hist__tiles">
				<?php foreach ( $tiles as $key => $label ) : ?>
					<div class="aifaq-hist__tile">
						<span class="aifaq-hist__tile-n" id="aifaq-hist-<?php echo esc_attr( $key ); ?>">–</span>
						<span class="aifaq-hist__tile-l"><?php echo esc_html( $label ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>

			<div class="aifaq-hist__bar">
				<label class="aifaq-hist__filter">
					<span class="screen-reader-text aifaq-sr"><?php echo esc_html( $t['histTitle'] ); ?></span>
					<select id="aifaq-hist-status" class="aifaq-set__select">
						<?php foreach ( $filters as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<span class="aifaq-hist__count" id="aifaq-hist-count" role="status" aria-live="polite"></span>
				<button type="button" class="aifaq-btn2 aifaq-hist__purge" id="aifaq-hist-purge"><?php echo esc_html( $t['histPurge'] ); ?></button>
			</div>

			<div class="aifaq-hist__list" id="aifaq-hist-list" aria-busy="false"></div>

			<div class="aifaq-hist__pager" id="aifaq-hist-pager" hidden>
				<button type="button" class="aifaq-btn2" id="aifaq-hist-prev"><?php echo esc_html( $t['histPrev'] ); ?></button>
				<span class="aifaq-hist__page" id="aifaq-hist-page"></span>
				<button type="button" class="aifaq-btn2" id="aifaq-hist-next"><?php echo esc_html( $t['histNext'] ); ?></button>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
