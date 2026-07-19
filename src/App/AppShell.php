<?php
/**
 * Powłoka apki `/faqgenerator` — świadoma roli (rola-aware).
 *
 * Gość widzi TYLKO generator (zero zakładek, zero paska) — identycznie jak
 * dotąd. Zalogowany właściciel (`manage_options`) dostaje zakładki:
 * Generator · Indeksowanie · Historia · Narzędzie FAQ · Historia generowań ·
 * Ustawienia. Każda funkcja zarządzania
 * jest bramkowana serwerowo (render tylko dla admina; akcje przez REST `/admin/*`
 * z uprawnieniem + nonce), więc gość nie widzi ani nie wywoła niczego z panelu.
 *
 * Generator jako komponent zostaje w {@see GeneratorPage} (widget + /ask);
 * AppShell tylko go osadza obok pozostałych zakładek i dokłada konfigurację
 * panelu ({@see config()}).
 *
 * @package AI_FAQ_Generator
 */

namespace AIFAQ\App;

use AIFAQ\Admin\IndexController;
use AIFAQ\Core\Settings;
use AIFAQ\PublicUi\GeneratorPage;
use AIFAQ\Rest\RestController;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renderowanie powłoki apki i konfiguracja panelu dla zalogowanego właściciela.
 */
class AppShell {

	/**
	 * Czy bieżący użytkownik to właściciel z dostępem do panelu.
	 *
	 * @return bool
	 */
	public static function is_owner(): bool {
		return is_user_logged_in() && current_user_can( RestController::CAPABILITY );
	}

	/**
	 * Teksty UI panelu (pl/en/de wg Settings).
	 *
	 * @param string $lang Kod języka.
	 * @return array<string,string>
	 */
	public static function strings( string $lang ): array {
		$all = array(
			'pl' => array(
				'tabGenerator'  => __( 'Generator', 'ai-faq-generator' ),
				'tabIndex'      => __( 'Indeksowanie', 'ai-faq-generator' ),
				'tabHistory'    => __( 'Historia', 'ai-faq-generator' ),
				'tabSettings'   => __( 'Ustawienia', 'ai-faq-generator' ),
				'ftTab'         => __( 'Narzędzie FAQ', 'ai-faq-generator' ),
				'ownerBadge'    => __( 'Panel właściciela', 'ai-faq-generator' ),
				'idxTitle'      => __( 'Baza wiedzy (RAG)', 'ai-faq-generator' ),
				'idxDesc'       => __( 'Indeksowanie zamienia treść strony na fragmenty z embeddingami — na tej bazie generator odpowiada wyłącznie w temacie strony.', 'ai-faq-generator' ),
				'idxReindex'    => __( 'Zaindeksuj treść', 'ai-faq-generator' ),
				'idxClear'      => __( 'Wyczyść bazę', 'ai-faq-generator' ),
				'idxRunning'    => __( 'Indeksuję treść…', 'ai-faq-generator' ),
				'idxClearing'   => __( 'Czyszczę bazę…', 'ai-faq-generator' ),
				'idxConfirm'    => __( 'Na pewno wyczyścić całą bazę wiedzy? Trzeba będzie zaindeksować treść od nowa.', 'ai-faq-generator' ),
				'idxError'      => __( 'Wystąpił błąd. Spróbuj ponownie.', 'ai-faq-generator' ),
				'idxDone'       => __( 'Gotowe.', 'ai-faq-generator' ),
				'idxStatsFmt'   => __( 'W bazie: %1$s fragmentów z %2$s wpisów (%3$s z embeddingiem).', 'ai-faq-generator' ),
				'setSaving'     => __( 'Zapisuję…', 'ai-faq-generator' ),
				'setSaved'      => __( 'Zapisano.', 'ai-faq-generator' ),
				'setSaveErr'    => __( 'Nie udało się zapisać. Spróbuj ponownie.', 'ai-faq-generator' ),
				'setTesting'    => __( 'Sprawdzam połączenie…', 'ai-faq-generator' ),
				'setShow'       => __( 'Pokaż', 'ai-faq-generator' ),
				'setHide'       => __( 'Ukryj', 'ai-faq-generator' ),
				'setTitle'      => __( 'Ustawienia API', 'ai-faq-generator' ),
				'setDesc'       => __( 'Rdzeń generatora. Zaawansowane pokrętła RAG i adres publicznej strony pozostają w panelu wp-admin.', 'ai-faq-generator' ),
				'setLabelKey'   => __( 'Klucz API', 'ai-faq-generator' ),
				'setKeySaved'   => __( 'Klucz zapisany — wklej nowy, aby zmienić', 'ai-faq-generator' ),
				'setKeyNew'     => __( 'Wklej klucz API Gemini', 'ai-faq-generator' ),
				'setTest'       => __( 'Test połączenia', 'ai-faq-generator' ),
				'setLabelModel' => __( 'Model', 'ai-faq-generator' ),
				'setLabelTemp'  => __( 'Temperatura', 'ai-faq-generator' ),
				'setTempHint'   => __( '0 = zwięźle i przewidywalnie · 1 = swobodniej.', 'ai-faq-generator' ),
				'setLabelLang'  => __( 'Język', 'ai-faq-generator' ),
				'setSave'       => __( 'Zapisz', 'ai-faq-generator' ),
				'setAdvNote'    => __( 'Zaawansowane ustawienia (RAG, adres strony):', 'ai-faq-generator' ),
				'setAdvLink'    => __( 'Otwórz w wp-admin', 'ai-faq-generator' ),
			),
			'en' => array(
				'tabGenerator'  => __( 'Generator', 'ai-faq-generator' ),
				'tabIndex'      => __( 'Indexing', 'ai-faq-generator' ),
				'tabHistory'    => __( 'History', 'ai-faq-generator' ),
				'tabSettings'   => __( 'Settings', 'ai-faq-generator' ),
				'ftTab'         => __( 'FAQ tool', 'ai-faq-generator' ),
				'ownerBadge'    => __( 'Owner panel', 'ai-faq-generator' ),
				'idxTitle'      => __( 'Knowledge base (RAG)', 'ai-faq-generator' ),
				'idxDesc'       => __( 'Indexing turns your site content into embedded chunks — the generator answers only within the site’s topic.', 'ai-faq-generator' ),
				'idxReindex'    => __( 'Index content', 'ai-faq-generator' ),
				'idxClear'      => __( 'Clear base', 'ai-faq-generator' ),
				'idxRunning'    => __( 'Indexing content…', 'ai-faq-generator' ),
				'idxClearing'   => __( 'Clearing base…', 'ai-faq-generator' ),
				'idxConfirm'    => __( 'Really clear the whole knowledge base? You will need to index content again.', 'ai-faq-generator' ),
				'idxError'      => __( 'Something went wrong. Please try again.', 'ai-faq-generator' ),
				'idxDone'       => __( 'Done.', 'ai-faq-generator' ),
				'idxStatsFmt'   => __( 'In base: %1$s chunks from %2$s posts (%3$s embedded).', 'ai-faq-generator' ),
				'setSaving'     => __( 'Saving…', 'ai-faq-generator' ),
				'setSaved'      => __( 'Saved.', 'ai-faq-generator' ),
				'setSaveErr'    => __( 'Could not save. Please try again.', 'ai-faq-generator' ),
				'setTesting'    => __( 'Checking connection…', 'ai-faq-generator' ),
				'setShow'       => __( 'Show', 'ai-faq-generator' ),
				'setHide'       => __( 'Hide', 'ai-faq-generator' ),
				'setTitle'      => __( 'API settings', 'ai-faq-generator' ),
				'setDesc'       => __( 'Generator core. Advanced RAG knobs and the public page URL stay in the wp-admin panel.', 'ai-faq-generator' ),
				'setLabelKey'   => __( 'API key', 'ai-faq-generator' ),
				'setKeySaved'   => __( 'Key saved — paste a new one to change', 'ai-faq-generator' ),
				'setKeyNew'     => __( 'Paste your Gemini API key', 'ai-faq-generator' ),
				'setTest'       => __( 'Test connection', 'ai-faq-generator' ),
				'setLabelModel' => __( 'Model', 'ai-faq-generator' ),
				'setLabelTemp'  => __( 'Temperature', 'ai-faq-generator' ),
				'setTempHint'   => __( '0 = concise and predictable · 1 = freer.', 'ai-faq-generator' ),
				'setLabelLang'  => __( 'Language', 'ai-faq-generator' ),
				'setSave'       => __( 'Save', 'ai-faq-generator' ),
				'setAdvNote'    => __( 'Advanced settings (RAG, page URL):', 'ai-faq-generator' ),
				'setAdvLink'    => __( 'Open in wp-admin', 'ai-faq-generator' ),
			),
			'de' => array(
				'tabGenerator'  => __( 'Generator', 'ai-faq-generator' ),
				'tabIndex'      => __( 'Indexierung', 'ai-faq-generator' ),
				'tabHistory'    => __( 'Verlauf', 'ai-faq-generator' ),
				'tabSettings'   => __( 'Einstellungen', 'ai-faq-generator' ),
				'ftTab'         => __( 'FAQ-Werkzeug', 'ai-faq-generator' ),
				'ownerBadge'    => __( 'Inhaber-Panel', 'ai-faq-generator' ),
				'idxTitle'      => __( 'Wissensbasis (RAG)', 'ai-faq-generator' ),
				'idxDesc'       => __( 'Die Indexierung wandelt Ihre Inhalte in eingebettete Abschnitte um — der Generator antwortet nur zum Thema der Website.', 'ai-faq-generator' ),
				'idxReindex'    => __( 'Inhalt indexieren', 'ai-faq-generator' ),
				'idxClear'      => __( 'Basis leeren', 'ai-faq-generator' ),
				'idxRunning'    => __( 'Inhalt wird indexiert…', 'ai-faq-generator' ),
				'idxClearing'   => __( 'Basis wird geleert…', 'ai-faq-generator' ),
				'idxConfirm'    => __( 'Die gesamte Wissensbasis wirklich leeren? Sie müssen den Inhalt neu indexieren.', 'ai-faq-generator' ),
				'idxError'      => __( 'Etwas ist schiefgelaufen. Bitte erneut versuchen.', 'ai-faq-generator' ),
				'idxDone'       => __( 'Fertig.', 'ai-faq-generator' ),
				'idxStatsFmt'   => __( 'In der Basis: %1$s Abschnitte aus %2$s Beiträgen (%3$s eingebettet).', 'ai-faq-generator' ),
				'setSaving'     => __( 'Wird gespeichert…', 'ai-faq-generator' ),
				'setSaved'      => __( 'Gespeichert.', 'ai-faq-generator' ),
				'setSaveErr'    => __( 'Speichern fehlgeschlagen. Bitte erneut versuchen.', 'ai-faq-generator' ),
				'setTesting'    => __( 'Verbindung wird geprüft…', 'ai-faq-generator' ),
				'setShow'       => __( 'Anzeigen', 'ai-faq-generator' ),
				'setHide'       => __( 'Verbergen', 'ai-faq-generator' ),
				'setTitle'      => __( 'API-Einstellungen', 'ai-faq-generator' ),
				'setDesc'       => __( 'Kern des Generators. Erweiterte RAG-Regler und die öffentliche Seiten-URL bleiben im wp-admin-Panel.', 'ai-faq-generator' ),
				'setLabelKey'   => __( 'API-Schlüssel', 'ai-faq-generator' ),
				'setKeySaved'   => __( 'Schlüssel gespeichert — neuen einfügen zum Ändern', 'ai-faq-generator' ),
				'setKeyNew'     => __( 'Gemini-API-Schlüssel einfügen', 'ai-faq-generator' ),
				'setTest'       => __( 'Verbindung testen', 'ai-faq-generator' ),
				'setLabelModel' => __( 'Modell', 'ai-faq-generator' ),
				'setLabelTemp'  => __( 'Temperatur', 'ai-faq-generator' ),
				'setTempHint'   => __( '0 = knapp und vorhersehbar · 1 = freier.', 'ai-faq-generator' ),
				'setLabelLang'  => __( 'Sprache', 'ai-faq-generator' ),
				'setSave'       => __( 'Speichern', 'ai-faq-generator' ),
				'setAdvNote'    => __( 'Erweiterte Einstellungen (RAG, Seiten-URL):', 'ai-faq-generator' ),
				'setAdvLink'    => __( 'In wp-admin öffnen', 'ai-faq-generator' ),
			),
		);

		$base = $all[ $lang ] ?? $all['pl'];

		// Teksty Historii mieszkają przy swoim komponencie (HistoryPanel), bo ten
		// sam panel renderuje się też w kokpicie — dokładamy je do wspólnego i18n.
		// Tak samo Historia generowań (GenerationsPanel, prefiks `gh*` — rozłączny
		// z `hist*`/`idx*`/`set*`, więc merge niczego nie nadpisuje).
		return array_merge( $base, HistoryPanel::strings( $lang ), GenerationsPanel::strings( $lang ) );
	}

	/**
	 * Konfiguracja panelu dla JS (tylko dla właściciela; bez sekretów).
	 *
	 * Endpointy `/admin/*` i nonce trafiają do JS wyłącznie, gdy render dzieje się
	 * dla zalogowanego właściciela — gość nie dostaje ich w ogóle.
	 *
	 * @return array<string,mixed>
	 */
	public static function config(): array {
		$lang = self::lang();
		$base = rest_url( RestController::REST_NAMESPACE . '/admin/' );

		return array(
			'isOwner'    => true,
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'perPage'    => HistoryPanel::PER_PAGE,
			'genPerPage' => GenerationsPanel::PER_PAGE,
			// Adres ekranu „Narzędzie FAQ" + nazwa parametru — z nich JS składa
			// link „Ponownie wygeneruj". Nazwa parametru pochodzi ze stałej PHP
			// (jedno źródło prawdy dzielone z FaqToolPage::config()).
			'faqToolUrl' => esc_url_raw( admin_url( 'admin.php?page=' . \AIFAQ\Admin\Menu::SLUG_FAQ_TOOL ) ),
			'regenParam' => GenerationsPanel::REGEN_PARAM,
			'endpoints'  => array(
				'status'            => esc_url_raw( $base . 'status' ),
				'reindex'           => esc_url_raw( $base . 'reindex' ),
				'clear'             => esc_url_raw( $base . 'clear' ),
				'settings'          => esc_url_raw( $base . 'settings' ),
				'verify'            => esc_url_raw( $base . 'verify' ),
				'history'           => esc_url_raw( $base . 'history' ),
				'historyClear'      => esc_url_raw( $base . 'history/clear' ),
				'generations'       => esc_url_raw( $base . 'generations' ),
				'generationsDelete' => esc_url_raw( $base . 'generations/delete' ),
				'generationDetail'  => esc_url_raw( $base . 'generations/detail' ),
			),
			'i18n'       => self::strings( $lang ),
		);
	}

	/**
	 * Renderuje ciało apki (do wstawienia w standalone {@see GeneratorPage}).
	 *
	 * Gość → sam generator. Właściciel → zakładki + panele.
	 *
	 * @return string
	 */
	public static function render_body(): string {
		if ( ! self::is_owner() ) {
			return GeneratorPage::widget();
		}

		$lang = self::lang();
		$t    = self::strings( $lang );
		// Teksty narzędzia FAQ liczone LOKALNIE i trzymane osobno od `$t` — ich klucze
		// są bez prefiksu i generyczne (`title`, `save`, `copy`, `export`), więc merge
		// z i18n powłoki nadpisałby ją cicho, bez błędu (do `$t` wchodzi tylko `ftTab`).
		$ft = FaqToolPanel::strings( $lang );

		// `generator` MUSI zostać pierwszy: `is-active`/`aria-selected="true"` dostaje
		// pierwszy element pętli, a aktywny panel jest zahardkodowany na `generator`.
		$tabs = array(
			'generator' => $t['tabGenerator'],
			'index'     => $t['tabIndex'],
			'history'   => $t['tabHistory'],
			'ft'        => $t['ftTab'],
			'gh'        => $t['ghTab'],
			'settings'  => $t['tabSettings'],
		);

		ob_start();
		?>
		<div class="aifaq-app" data-tab="generator">
			<div class="aifaq-app__bar" role="tablist" aria-label="<?php echo esc_attr( $t['ownerBadge'] ); ?>">
				<span class="aifaq-app__badge"><?php echo esc_html( $t['ownerBadge'] ); ?></span>
				<div class="aifaq-app__tabs">
					<?php $first = true; ?>
					<?php foreach ( $tabs as $key => $label ) : ?>
						<button
							type="button"
							class="aifaq-app__tab<?php echo $first ? ' is-active' : ''; ?>"
							role="tab"
							id="aifaq-tab-<?php echo esc_attr( $key ); ?>"
							aria-controls="aifaq-panel-<?php echo esc_attr( $key ); ?>"
							aria-selected="<?php echo $first ? 'true' : 'false'; ?>"
							data-tab-target="<?php echo esc_attr( $key ); ?>"
						><?php echo esc_html( $label ); ?></button>
						<?php $first = false; ?>
					<?php endforeach; ?>
				</div>
			</div>

			<div class="aifaq-app__panel is-active" id="aifaq-panel-generator" role="tabpanel" aria-labelledby="aifaq-tab-generator">
				<?php echo GeneratorPage::widget(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — markup z esc_* w widget(). ?>
			</div>

			<div class="aifaq-app__panel" id="aifaq-panel-index" role="tabpanel" aria-labelledby="aifaq-tab-index" hidden>
				<?php echo self::index_panel( $t ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — markup z esc_* poniżej. ?>
			</div>

			<div class="aifaq-app__panel" id="aifaq-panel-history" role="tabpanel" aria-labelledby="aifaq-tab-history" hidden>
				<?php echo HistoryPanel::widget( $t ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — markup z esc_* w widget(). ?>
			</div>

			<?php // Klasa `aifaq-ftp` należy do POWŁOKI (nie do widgetu) — to jedyny hak, którym CSS odróżnia kontekst frontowy od kokpitu. ?>
			<div class="aifaq-app__panel" id="aifaq-panel-ft" role="tabpanel" aria-labelledby="aifaq-tab-ft" hidden>
				<div class="aifaq-card aifaq-ftp">
					<h2 class="aifaq-card__h"><?php echo esc_html( $ft['title'] ); ?></h2>
					<p class="aifaq-card__p"><?php echo esc_html( $ft['lead'] ); ?></p>
					<?php echo FaqToolPanel::widget( $ft ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — markup z esc_* w widget(). ?>
				</div>
			</div>

			<div class="aifaq-app__panel" id="aifaq-panel-gh" role="tabpanel" aria-labelledby="aifaq-tab-gh" hidden>
				<?php echo GenerationsPanel::widget( $t ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — markup z esc_* w widget(). ?>
			</div>

			<div class="aifaq-app__panel" id="aifaq-panel-settings" role="tabpanel" aria-labelledby="aifaq-tab-settings" hidden>
				<?php echo self::settings_panel(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — markup z esc_* poniżej. ?>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Markup zakładki „Indeksowanie" (statystyki + akcje przez REST /admin/*).
	 *
	 * @param array<string,string> $t Teksty UI.
	 * @return string
	 */
	private static function index_panel( array $t ): string {
		$stats = IndexController::stats();

		ob_start();
		?>
		<div class="aifaq-card">
			<h2 class="aifaq-card__h"><?php echo esc_html( $t['idxTitle'] ); ?></h2>
			<p class="aifaq-card__p"><?php echo esc_html( $t['idxDesc'] ); ?></p>

			<p class="aifaq-card__stats" id="aifaq-index-stats">
				<?php
				printf(
					/* translators: 1: liczba fragmentów, 2: liczba wpisów, 3: liczba z embeddingiem */
					esc_html( $t['idxStatsFmt'] ),
					'<strong id="aifaq-stat-chunks">' . esc_html( (string) $stats['chunks'] ) . '</strong>',
					'<strong id="aifaq-stat-posts">' . esc_html( (string) $stats['posts'] ) . '</strong>',
					'<strong id="aifaq-stat-embedded">' . esc_html( (string) $stats['embedded'] ) . '</strong>'
				);
				?>
			</p>

			<div class="aifaq-card__actions">
				<button type="button" class="aifaq-btn2 aifaq-btn2--primary" id="aifaq-reindex"><?php echo esc_html( $t['idxReindex'] ); ?></button>
				<button type="button" class="aifaq-btn2" id="aifaq-clear"><?php echo esc_html( $t['idxClear'] ); ?></button>
				<span class="aifaq-card__status" id="aifaq-index-status" role="status" aria-live="polite"></span>
			</div>

			<div class="aifaq-card__report" id="aifaq-index-report" hidden></div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Markup zakładki „Ustawienia" (rdzeń: klucz/model/temperatura/język).
	 *
	 * Klucz renderowany jako puste pole password (nigdy nie wypisujemy zapisanego
	 * klucza do HTML) — placeholder sygnalizuje tylko, czy klucz istnieje. Zapis
	 * i test idą przez REST `/admin/{settings,verify}` (uprawnienie + nonce).
	 *
	 * @return string
	 */
	private static function settings_panel(): string {
		$lang     = self::lang();
		$t        = self::strings( $lang );
		$s        = Settings::get();
		$models   = Settings::models();
		$langs    = Settings::languages();
		$has_key  = '' !== (string) ( $s['api_key'] ?? '' );
		$temp     = (float) ( $s['temperature'] ?? 0.4 );
		$adv_url  = admin_url( 'admin.php?page=' . \AIFAQ\Admin\Menu::SLUG_SETTINGS );

		ob_start();
		?>
		<div class="aifaq-card">
			<h2 class="aifaq-card__h"><?php echo esc_html( $t['setTitle'] ); ?></h2>
			<p class="aifaq-card__p"><?php echo esc_html( $t['setDesc'] ); ?></p>

			<form class="aifaq-set" id="aifaq-set-form" novalidate>
				<div class="aifaq-set__row">
					<label class="aifaq-set__label" for="aifaq-set-key"><?php echo esc_html( $t['setLabelKey'] ); ?></label>
					<div class="aifaq-set__key">
						<input
							type="password"
							id="aifaq-set-key"
							class="aifaq-set__input"
							value=""
							autocomplete="off"
							spellcheck="false"
							placeholder="<?php echo esc_attr( $has_key ? $t['setKeySaved'] : $t['setKeyNew'] ); ?>"
						>
						<button type="button" class="aifaq-btn2" id="aifaq-set-key-toggle" data-show="<?php echo esc_attr( $t['setShow'] ); ?>" data-hide="<?php echo esc_attr( $t['setHide'] ); ?>"><?php echo esc_html( $t['setShow'] ); ?></button>
						<button type="button" class="aifaq-btn2" id="aifaq-set-verify"><?php echo esc_html( $t['setTest'] ); ?></button>
					</div>
					<span class="aifaq-set__status" id="aifaq-set-verify-status" role="status" aria-live="polite"></span>
				</div>

				<div class="aifaq-set__row">
					<label class="aifaq-set__label" for="aifaq-set-model"><?php echo esc_html( $t['setLabelModel'] ); ?></label>
					<select id="aifaq-set-model" class="aifaq-set__select">
						<?php foreach ( $models as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( (string) ( $s['model'] ?? '' ), $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>

				<div class="aifaq-set__row">
					<label class="aifaq-set__label" for="aifaq-set-temp">
						<?php echo esc_html( $t['setLabelTemp'] ); ?> · <span id="aifaq-set-temp-val"><?php echo esc_html( number_format( $temp, 1 ) ); ?></span>
					</label>
					<input type="range" id="aifaq-set-temp" min="0" max="1" step="0.1" value="<?php echo esc_attr( (string) $temp ); ?>" class="aifaq-set__range">
					<p class="aifaq-set__hint"><?php echo esc_html( $t['setTempHint'] ); ?></p>
				</div>

				<div class="aifaq-set__row">
					<label class="aifaq-set__label" for="aifaq-set-lang"><?php echo esc_html( $t['setLabelLang'] ); ?></label>
					<select id="aifaq-set-lang" class="aifaq-set__select">
						<?php foreach ( $langs as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( (string) ( $s['language'] ?? '' ), $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>

				<div class="aifaq-set__actions">
					<button type="submit" class="aifaq-btn2 aifaq-btn2--primary" id="aifaq-set-save"><?php echo esc_html( $t['setSave'] ); ?></button>
					<span class="aifaq-set__status" id="aifaq-set-save-status" role="status" aria-live="polite"></span>
				</div>
			</form>

			<p class="aifaq-set__adv">
				<?php echo esc_html( $t['setAdvNote'] ); ?>
				<a href="<?php echo esc_url( $adv_url ); ?>"><?php echo esc_html( $t['setAdvLink'] ); ?> &rarr;</a>
			</p>
		</div>
		<?php
		return (string) ob_get_clean();
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
