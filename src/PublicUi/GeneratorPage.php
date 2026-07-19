<?php
/**
 * Publiczny generator FAQ (front) — strona `/faqgenerator`.
 *
 * Renderuje samodzielny widget pytań-odpowiedzi, który konsumuje REST
 * `POST /aifaq/v1/ask` (Krok 7). Komponent jest self-contained: markup tutaj,
 * styl w `assets/css/generator.css`, logika w `assets/js/generator.js` — dzięki
 * temu ten sam widget ({@see widget()}) zamontujemy w kokpicie w Kroku 9.
 *
 * Strona jest STANDALONE (własny dokument HTML, poza motywem klienta) —
 * przewidywalny wygląd niezależnie od aktywnego motywu witryny.
 *
 * @package AI_FAQ_Generator
 */

namespace AIFAQ\PublicUi;

use AIFAQ\App\AppShell;
use AIFAQ\Core\Settings;
use AIFAQ\Rag\RagService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Widok publicznego generatora (strona standalone + reużywalny widget).
 */
class GeneratorPage {

	/**
	 * Teksty UI dla obsługiwanych języków (pl/en/de wg Settings).
	 *
	 * @param string $lang Kod języka.
	 * @return array<string,string>
	 */
	public static function strings( string $lang ): array {
		$all = array(
			'pl' => array(
				'title'       => __( 'Generator FAQ', 'ai-faq-generator' ),
				'subtitle'    => __( 'Zadaj pytanie — odpowiem na podstawie treści tej strony.', 'ai-faq-generator' ),
				'placeholder' => __( 'Wpisz pytanie…', 'ai-faq-generator' ),
				'ask'         => __( 'Zapytaj', 'ai-faq-generator' ),
				'thinking'    => __( 'Szukam odpowiedzi…', 'ai-faq-generator' ),
				'errGeneric'  => __( 'Coś poszło nie tak. Spróbuj ponownie za chwilę.', 'ai-faq-generator' ),
				'errRate'     => __( 'Za dużo pytań naraz. Odczekaj chwilę i spróbuj ponownie.', 'ai-faq-generator' ),
				'errTooLong'  => __( 'Pytanie jest za długie.', 'ai-faq-generator' ),
				'errEmpty'    => __( 'Najpierw wpisz pytanie.', 'ai-faq-generator' ),
				'cached'      => __( 'odpowiedź z pamięci', 'ai-faq-generator' ),
				'back'        => __( 'Strona główna', 'ai-faq-generator' ),
			),
			'en' => array(
				'title'       => __( 'FAQ Generator', 'ai-faq-generator' ),
				'subtitle'    => __( 'Ask a question — I answer based on this site’s content.', 'ai-faq-generator' ),
				'placeholder' => __( 'Type your question…', 'ai-faq-generator' ),
				'ask'         => __( 'Ask', 'ai-faq-generator' ),
				'thinking'    => __( 'Looking for an answer…', 'ai-faq-generator' ),
				'errGeneric'  => __( 'Something went wrong. Please try again in a moment.', 'ai-faq-generator' ),
				'errRate'     => __( 'Too many questions at once. Please wait a moment and try again.', 'ai-faq-generator' ),
				'errTooLong'  => __( 'The question is too long.', 'ai-faq-generator' ),
				'errEmpty'    => __( 'Please type a question first.', 'ai-faq-generator' ),
				'cached'      => __( 'answer from cache', 'ai-faq-generator' ),
				'back'        => __( 'Home', 'ai-faq-generator' ),
			),
			'de' => array(
				'title'       => __( 'FAQ-Generator', 'ai-faq-generator' ),
				'subtitle'    => __( 'Stellen Sie eine Frage — ich antworte anhand der Inhalte dieser Website.', 'ai-faq-generator' ),
				'placeholder' => __( 'Frage eingeben…', 'ai-faq-generator' ),
				'ask'         => __( 'Fragen', 'ai-faq-generator' ),
				'thinking'    => __( 'Antwort wird gesucht…', 'ai-faq-generator' ),
				'errGeneric'  => __( 'Etwas ist schiefgelaufen. Bitte versuchen Sie es gleich noch einmal.', 'ai-faq-generator' ),
				'errRate'     => __( 'Zu viele Fragen auf einmal. Bitte warten Sie einen Moment.', 'ai-faq-generator' ),
				'errTooLong'  => __( 'Die Frage ist zu lang.', 'ai-faq-generator' ),
				'errEmpty'    => __( 'Bitte geben Sie zuerst eine Frage ein.', 'ai-faq-generator' ),
				'cached'      => __( 'Antwort aus dem Cache', 'ai-faq-generator' ),
				'back'        => __( 'Startseite', 'ai-faq-generator' ),
			),
		);

		return $all[ $lang ] ?? $all['pl'];
	}

	/**
	 * Konfiguracja przekazywana do JS (bez sekretów).
	 *
	 * @return array<string,mixed>
	 */
	public static function config(): array {
		$lang = self::lang();

		return array(
			'endpoint' => esc_url_raw( rest_url( 'aifaq/v1/ask' ) ),
			// Nonce ma sens tylko dla zalogowanego (cookie-auth REST); gość go nie potrzebuje.
			'nonce'    => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
			'lang'     => $lang,
			'maxLen'   => (int) RagService::MAX_QUESTION_LEN,
			'i18n'     => self::strings( $lang ),
		);
	}

	/**
	 * Tytuł generatora w języku UI — nazwa automatycznie tworzonej podstrony.
	 *
	 * @return string
	 */
	public static function page_title(): string {
		return self::strings( self::lang() )['title'];
	}

	/**
	 * Markup samego widgetu (reużywalny — Krok 9 montuje go w kokpicie).
	 *
	 * @return string
	 */
	public static function widget(): string {
		$lang = self::lang();
		$t    = self::strings( $lang );
		$site = (string) get_bloginfo( 'name' );

		ob_start();
		?>
		<div class="aifaq" data-state="idle">
			<header class="aifaq__head">
				<?php if ( '' !== $site ) : ?>
					<p class="aifaq__eyebrow"><?php echo esc_html( $site ); ?></p>
				<?php endif; ?>
				<h1 class="aifaq__title"><?php echo esc_html( $t['title'] ); ?></h1>
				<p class="aifaq__subtitle"><?php echo esc_html( $t['subtitle'] ); ?></p>
			</header>

			<form class="aifaq__form" id="aifaq-form" novalidate>
				<div class="aifaq__field">
					<textarea
						id="aifaq-q"
						class="aifaq__input"
						rows="1"
						maxlength="<?php echo (int) RagService::MAX_QUESTION_LEN; ?>"
						placeholder="<?php echo esc_attr( $t['placeholder'] ); ?>"
						aria-label="<?php echo esc_attr( $t['placeholder'] ); ?>"
						autocomplete="off"
					></textarea>
					<div class="aifaq__meta" id="aifaq-meta" hidden>
						<span class="aifaq__counter" id="aifaq-counter">0/<?php echo (int) RagService::MAX_QUESTION_LEN; ?></span>
					</div>
				</div>
				<p class="aifaq__hint" id="aifaq-hint" role="alert" aria-live="assertive"></p>
				<button type="submit" class="aifaq__btn" id="aifaq-btn">
					<span class="aifaq__btn-label"><?php echo esc_html( $t['ask'] ); ?></span>
					<svg class="aifaq__btn-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
				</button>
			</form>

			<div class="aifaq__answer" id="aifaq-answer" aria-live="polite" hidden>
				<p class="aifaq__answer-q" id="aifaq-answer-q" hidden></p>
				<div class="aifaq__answer-body" id="aifaq-answer-body"></div>
				<div class="aifaq__answer-foot" id="aifaq-answer-foot" hidden></div>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Renderuje pełną stronę standalone (Krok 8) — wołane z {@see \AIFAQ\Core\Router}.
	 */
	public static function render_standalone(): void {
		$lang     = self::lang();
		$t        = self::strings( $lang );
		$site     = (string) get_bloginfo( 'name' );
		$css_url  = AIFAQ_PLUGIN_URL . 'assets/css/generator.css';
		$js_url   = AIFAQ_PLUGIN_URL . 'assets/js/generator.js';
		$app_css  = AIFAQ_PLUGIN_URL . 'assets/css/app.css';
		$app_js   = AIFAQ_PLUGIN_URL . 'assets/js/app.js';
		// Krok 18 — zakładka „Narzędzie FAQ" pojawia się też na tej trasie, więc jej
		// assety muszą tu polecieć ręcznie (dokument jest poza kolejką WP).
		$ft_css   = AIFAQ_PLUGIN_URL . 'assets/css/faq-tool.css';
		$ft_js    = AIFAQ_PLUGIN_URL . 'assets/js/faq-tool.js';
		$ver      = AIFAQ_VERSION;
		$config   = wp_json_encode( self::config() );
		$is_owner = AppShell::is_owner();
		$app_cfg  = $is_owner ? wp_json_encode( AppShell::config() ) : '';
		$ft_cfg   = $is_owner ? wp_json_encode( \AIFAQ\App\FaqToolPanel::config() ) : '';
		$doc_title = $t['title'] . ( '' !== $site ? ' — ' . $site : '' );
		?>
<!doctype html>
<html lang="<?php echo esc_attr( $lang ); ?>">
<head>
	<meta charset="<?php echo esc_attr( get_bloginfo( 'charset' ) ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex,follow">
	<title><?php echo esc_html( $doc_title ); ?></title>
	<link rel="stylesheet" href="<?php echo esc_url( $css_url ); ?>?ver=<?php echo esc_attr( $ver ); ?>">
	<?php if ( $is_owner ) : ?>
	<link rel="stylesheet" href="<?php echo esc_url( $app_css ); ?>?ver=<?php echo esc_attr( $ver ); ?>">
	<link rel="stylesheet" href="<?php echo esc_url( $ft_css ); ?>?ver=<?php echo esc_attr( $ver ); ?>">
	<?php endif; ?>
</head>
<body class="aifaq-body">
	<main class="aifaq-page">
		<?php echo AppShell::render_body(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — markup zbudowany z esc_* w AppShell/widget(). ?>
		<footer class="aifaq__foot">
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>">&larr; <?php echo esc_html( $t['back'] ); ?></a>
		</footer>
	</main>
	<script>window.aifaqFront = <?php echo $config; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — wp_json_encode. ?>;</script>
	<?php if ( $is_owner ) : ?>
	<script>window.aifaqApp = <?php echo $app_cfg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — wp_json_encode. ?>;</script>
	<script>window.aifaqFaqTool = <?php echo $ft_cfg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — wp_json_encode. ?>;</script>
	<?php endif; ?>
	<script src="<?php echo esc_url( $js_url ); ?>?ver=<?php echo esc_attr( $ver ); ?>"></script>
	<?php if ( $is_owner ) : ?>
	<script src="<?php echo esc_url( $app_js ); ?>?ver=<?php echo esc_attr( $ver ); ?>"></script>
	<script src="<?php echo esc_url( $ft_js ); ?>?ver=<?php echo esc_attr( $ver ); ?>"></script>
	<?php endif; ?>
</body>
</html>
		<?php
	}

	/**
	 * Bieżący język UI (z Settings, z fallbackiem do pl).
	 *
	 * @return string
	 */
	private static function lang(): string {
		$lang = (string) Settings::get_field( 'language', 'pl' );
		return in_array( $lang, array( 'pl', 'en', 'de' ), true ) ? $lang : 'pl';
	}
}
