<?php
/**
 * Metabox „AI FAQ" w edytorze wpisu/strony (Krok 16).
 *
 * Klasyczny `add_meta_box` w edytorze wpisu: właściciel pisze wpis, klika
 * „Generuj z treści wpisu" i dostaje pary Q&A ułożone z tytułu i treści,
 * a przyciskiem „Wstaw do wpisu" dokleja gotowe FAQ na koniec treści.
 *
 * PHP dostarcza WYŁĄCZNIE powłokę (widok `views/faq-metabox.php`), konfigurację
 * i teksty; całą logikę pisze `assets/js/faq-metabox.js`, wygląd
 * `assets/css/faq-metabox.css`. Metabox nie dokłada tras REST — konsumuje
 * istniejące `POST /admin/generate-faq` (K12) i `POST /admin/export` (K14).
 *
 * ZERO sekretów w konfiguracji (żadnego klucza API) — patrz KONTRAKT k16-v2 §2c.
 * Metabox nie zapisuje żadnych metadanych wpisu (brak `post_meta`, brak `save_post`).
 *
 * @package AI_FAQ_Generator
 */

namespace AIFAQ\Admin;

use AIFAQ\Core\Settings;
use AIFAQ\Rest\RestController;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rejestracja metaboksa „AI FAQ", jego assetów i konfiguracji dla JS.
 */
class PostMetaBox {

	/**
	 * Identyfikator metaboksa (`add_meta_box`).
	 */
	const BOX_ID = 'aifaq_faq';

	/**
	 * Typy wpisów, na których metabox się pojawia.
	 */
	const POST_TYPES = array( 'post', 'page' );

	/**
	 * Limit znaków treści wpisu wysyłanej do modelu.
	 */
	const MAX_CONTENT_CHARS = 6000;

	/**
	 * Dolna granica liczby pytań.
	 */
	const MIN_COUNT = 5;

	/**
	 * Górna granica liczby pytań.
	 */
	const MAX_COUNT = 20;

	/**
	 * Domyślna liczba pytań.
	 */
	const DEFAULT_COUNT = 10;

	/**
	 * Rejestruje metabox na obsługiwanych typach wpisów.
	 *
	 * Hook `add_meta_boxes` — WordPress podaje typ bieżącego wpisu.
	 * Metabox istnieje tylko dla użytkowników z uprawnieniem `manage_options`,
	 * bo tego wymagają konsumowane trasy `/admin/*` (KONTRAKT R5).
	 *
	 * @param string $post_type Typ bieżącego wpisu.
	 */
	public function register_box( string $post_type ): void {
		if ( ! in_array( $post_type, self::POST_TYPES, true ) ) {
			return;
		}

		if ( ! current_user_can( Menu::CAPABILITY ) ) {
			return;
		}

		add_meta_box(
			self::BOX_ID,
			__( 'AI FAQ', 'ai-faq-generator' ),
			array( $this, 'render' ),
			$post_type,
			'normal',
			'default'
		);
	}

	/**
	 * Renderuje powłokę metaboksa (cały DOM mieszka w widoku).
	 *
	 * @param \WP_Post $post Bieżący wpis (nieużywany — treść czyta JS z edytora).
	 */
	public function render( \WP_Post $post ): void {
		require AIFAQ_PLUGIN_DIR . 'src/Admin/views/faq-metabox.php';
	}

	/**
	 * Ładuje zasoby metaboksa wyłącznie na ekranach edycji wpisu/strony.
	 *
	 * `Menu::enqueue_assets()` nie obsługuje tych ekranów (bramkuje po slugu
	 * wtyczki w `$hook_suffix`), więc metabox wpina swoje assety sam.
	 *
	 * @param string $hook_suffix Identyfikator bieżącego ekranu admina.
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( 'post.php' !== $hook_suffix && 'post-new.php' !== $hook_suffix ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->post_type, self::POST_TYPES, true ) ) {
			return;
		}

		if ( ! current_user_can( Menu::CAPABILITY ) ) {
			return;
		}

		wp_enqueue_style(
			'aifaq-generator',
			AIFAQ_PLUGIN_URL . 'assets/css/generator.css',
			array(),
			AIFAQ_VERSION
		);
		wp_enqueue_style(
			'aifaq-faq-metabox',
			AIFAQ_PLUGIN_URL . 'assets/css/faq-metabox.css',
			array( 'aifaq-generator' ),
			AIFAQ_VERSION
		);

		// W edytorze blokowym JS sięga po magazyny `core/editor` i `core/block-editor`
		// oraz `wp.blocks.parse()`. Store `core/block-editor` rejestruje pakiet
		// `wp-block-editor`, dlatego jest w zależnościach. W edytorze klasycznym
		// `wp` nie istnieje i zależności muszą być puste.
		$deps = ( method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor() )
			? array( 'wp-blocks', 'wp-block-editor', 'wp-data', 'wp-dom-ready' )
			: array();

		wp_enqueue_script(
			'aifaq-faq-metabox',
			AIFAQ_PLUGIN_URL . 'assets/js/faq-metabox.js',
			$deps,
			AIFAQ_VERSION,
			true
		);
		wp_localize_script( 'aifaq-faq-metabox', 'aifaqMetabox', self::config() );
	}

	/**
	 * Konfiguracja dla JS (bez sekretów). Trafia do `window.aifaqMetabox`.
	 *
	 * @return array<string,mixed>
	 */
	public static function config(): array {
		$lang = self::lang();

		return array(
			'endpoint'        => esc_url_raw( rest_url( RestController::REST_NAMESPACE . '/admin/generate-faq' ) ),
			'exportEndpoint'  => esc_url_raw( rest_url( RestController::REST_NAMESPACE . '/admin/export' ) ),
			'nonce'           => wp_create_nonce( 'wp_rest' ),
			'defaults'        => array(
				'count'    => self::DEFAULT_COUNT,
				'min'      => self::MIN_COUNT,
				'max'      => self::MAX_COUNT,
				'language' => $lang,
			),
			'maxContentChars' => self::MAX_CONTENT_CHARS,
			'i18n'            => self::strings( $lang ),
		);
	}

	/**
	 * Teksty UI metaboksa (pl/en/de wg Settings). Klucze wg KONTRAKT k16-v2 §6.
	 *
	 * Uwaga: `mbDescFrame` NIE jest tekstem interfejsu — to instrukcja doklejana
	 * przed treścią wpisu i wysyłana DO MODELU. Tłumaczenie musi zachować sens
	 * polecenia „układaj pytania wyłącznie na podstawie tej treści".
	 *
	 * @param string $lang Kod języka.
	 * @return array<string,string>
	 */
	public static function strings( string $lang ): array {
		$all = array(
			'pl' => array(
				'mbLead'        => __( 'Ułożę pytania i odpowiedzi na podstawie tytułu i treści tego wpisu.', 'ai-faq-generator' ),
				'mbCount'       => __( 'Liczba pytań', 'ai-faq-generator' ),
				'mbGenerate'    => __( 'Generuj z treści wpisu', 'ai-faq-generator' ),
				'mbGenerating'  => __( 'Generuję…', 'ai-faq-generator' ),
				'mbNeedTitle'   => __( 'Najpierw nadaj wpisowi tytuł — to z niego biorę temat FAQ.', 'ai-faq-generator' ),
				'mbNeedContent' => __( 'Wpis nie ma jeszcze treści, z której można ułożyć pytania.', 'ai-faq-generator' ),
				'mbTrimmed'     => __( 'Treść jest długa — do generowania użyłem pierwszych %s znaków.', 'ai-faq-generator' ),
				'mbDescFrame'   => __( 'Poniżej pełna treść artykułu. Ułóż pytania i odpowiedzi WYŁĄCZNIE na podstawie tej treści, nie dodawaj informacji spoza niej.', 'ai-faq-generator' ),
				'mbEmptyMsg'    => __( 'Nie udało się ułożyć pytań z tej treści. Spróbuj ją rozbudować.', 'ai-faq-generator' ),
				'mbErrMsg'      => __( 'Wystąpił błąd generowania. Spróbuj ponownie.', 'ai-faq-generator' ),
				'mbDoneFmt'     => __( 'Gotowe — %s par.', 'ai-faq-generator' ),
				'mbCountFmt'    => __( '%s par do wstawienia.', 'ai-faq-generator' ),
				'mbDelete'      => __( 'Usuń', 'ai-faq-generator' ),
				'mbConfirmDel'  => __( 'Usunąć tę parę?', 'ai-faq-generator' ),
				'mbInsert'      => __( 'Wstaw do wpisu', 'ai-faq-generator' ),
				'mbInserting'   => __( 'Wstawiam…', 'ai-faq-generator' ),
				'mbInserted'    => __( 'Wstawiono na końcu treści wpisu.', 'ai-faq-generator' ),
				'mbInsertErr'   => __( 'Nie udało się wstawić FAQ do treści wpisu.', 'ai-faq-generator' ),
				'mbNoEditor'    => __( 'Nie rozpoznaję edytora treści — skopiuj pary i wklej ręcznie.', 'ai-faq-generator' ),
				'mbCopyAll'     => __( 'Kopiuj wszystko', 'ai-faq-generator' ),
				'mbCopied'      => __( 'Skopiowano.', 'ai-faq-generator' ),
				'mbCopyErr'     => __( 'Nie udało się skopiować.', 'ai-faq-generator' ),
				'mbHint'        => __( 'Pary trafią na koniec treści wpisu. Zapis wpisu zostaje po Twojej stronie.', 'ai-faq-generator' ),
			),
			'en' => array(
				'mbLead'        => __( 'I will build questions and answers from the title and content of this post.', 'ai-faq-generator' ),
				'mbCount'       => __( 'Number of questions', 'ai-faq-generator' ),
				'mbGenerate'    => __( 'Generate from post content', 'ai-faq-generator' ),
				'mbGenerating'  => __( 'Generating…', 'ai-faq-generator' ),
				'mbNeedTitle'   => __( 'Give the post a title first — the FAQ topic comes from it.', 'ai-faq-generator' ),
				'mbNeedContent' => __( 'The post has no content yet to build questions from.', 'ai-faq-generator' ),
				'mbTrimmed'     => __( 'The content is long — I used the first %s characters for generation.', 'ai-faq-generator' ),
				'mbDescFrame'   => __( 'Below is the full content of the article. Build questions and answers SOLELY on the basis of this content, do not add information from outside it.', 'ai-faq-generator' ),
				'mbEmptyMsg'    => __( 'Could not build questions from this content. Try to expand it.', 'ai-faq-generator' ),
				'mbErrMsg'      => __( 'Generation error. Please try again.', 'ai-faq-generator' ),
				'mbDoneFmt'     => __( 'Done — %s pairs.', 'ai-faq-generator' ),
				'mbCountFmt'    => __( '%s pairs ready to insert.', 'ai-faq-generator' ),
				'mbDelete'      => __( 'Delete', 'ai-faq-generator' ),
				'mbConfirmDel'  => __( 'Delete this pair?', 'ai-faq-generator' ),
				'mbInsert'      => __( 'Insert into post', 'ai-faq-generator' ),
				'mbInserting'   => __( 'Inserting…', 'ai-faq-generator' ),
				'mbInserted'    => __( 'Inserted at the end of the post content.', 'ai-faq-generator' ),
				'mbInsertErr'   => __( 'Could not insert the FAQ into the post content.', 'ai-faq-generator' ),
				'mbNoEditor'    => __( 'I cannot detect the content editor — copy the pairs and paste them manually.', 'ai-faq-generator' ),
				'mbCopyAll'     => __( 'Copy all', 'ai-faq-generator' ),
				'mbCopied'      => __( 'Copied.', 'ai-faq-generator' ),
				'mbCopyErr'     => __( 'Could not copy.', 'ai-faq-generator' ),
				'mbHint'        => __( 'The pairs go to the end of the post content. Saving the post is up to you.', 'ai-faq-generator' ),
			),
			'de' => array(
				'mbLead'        => __( 'Ich erstelle Fragen und Antworten aus dem Titel und dem Inhalt dieses Beitrags.', 'ai-faq-generator' ),
				'mbCount'       => __( 'Anzahl der Fragen', 'ai-faq-generator' ),
				'mbGenerate'    => __( 'Aus Beitragsinhalt generieren', 'ai-faq-generator' ),
				'mbGenerating'  => __( 'Wird generiert…', 'ai-faq-generator' ),
				'mbNeedTitle'   => __( 'Gib dem Beitrag zuerst einen Titel — daraus nehme ich das FAQ-Thema.', 'ai-faq-generator' ),
				'mbNeedContent' => __( 'Der Beitrag hat noch keinen Inhalt, aus dem Fragen erstellt werden können.', 'ai-faq-generator' ),
				'mbTrimmed'     => __( 'Der Inhalt ist lang — für die Generierung habe ich die ersten %s Zeichen verwendet.', 'ai-faq-generator' ),
				'mbDescFrame'   => __( 'Unten steht der vollständige Inhalt des Artikels. Erstelle Fragen und Antworten AUSSCHLIESSLICH auf Grundlage dieses Inhalts, füge keine Informationen von außerhalb hinzu.', 'ai-faq-generator' ),
				'mbEmptyMsg'    => __( 'Aus diesem Inhalt konnten keine Fragen erstellt werden. Bitte erweitere ihn.', 'ai-faq-generator' ),
				'mbErrMsg'      => __( 'Fehler bei der Generierung. Bitte erneut versuchen.', 'ai-faq-generator' ),
				'mbDoneFmt'     => __( 'Fertig — %s Paare.', 'ai-faq-generator' ),
				'mbCountFmt'    => __( '%s Paare zum Einfügen.', 'ai-faq-generator' ),
				'mbDelete'      => __( 'Löschen', 'ai-faq-generator' ),
				'mbConfirmDel'  => __( 'Dieses Paar löschen?', 'ai-faq-generator' ),
				'mbInsert'      => __( 'In den Beitrag einfügen', 'ai-faq-generator' ),
				'mbInserting'   => __( 'Wird eingefügt…', 'ai-faq-generator' ),
				'mbInserted'    => __( 'Am Ende des Beitragsinhalts eingefügt.', 'ai-faq-generator' ),
				'mbInsertErr'   => __( 'Das FAQ konnte nicht in den Beitragsinhalt eingefügt werden.', 'ai-faq-generator' ),
				'mbNoEditor'    => __( 'Ich erkenne den Inhaltseditor nicht — kopiere die Paare und füge sie manuell ein.', 'ai-faq-generator' ),
				'mbCopyAll'     => __( 'Alle kopieren', 'ai-faq-generator' ),
				'mbCopied'      => __( 'Kopiert.', 'ai-faq-generator' ),
				'mbCopyErr'     => __( 'Kopieren nicht möglich.', 'ai-faq-generator' ),
				'mbHint'        => __( 'Die Paare kommen ans Ende des Beitragsinhalts. Das Speichern des Beitrags bleibt bei dir.', 'ai-faq-generator' ),
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
