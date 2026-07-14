<?php
/**
 * Menu wtyczki w panelu administracyjnym.
 *
 * Rejestruje pozycję „AI FAQ Generator" z trzema podstronami:
 * Dashboard, Ustawienia, Historia. Widoki leżą w src/Admin/views.
 *
 * @package AI_FAQ_Generator
 */

namespace AIFAQ\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Obsługa menu i ładowania widoków podstron.
 */
class Menu {

	/**
	 * Uprawnienie wymagane do wszystkich ekranów wtyczki.
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Slug pozycji głównej (i zarazem Dashboardu).
	 */
	const SLUG = 'ai-faq-generator';

	/**
	 * Slugi podstron.
	 */
	const SLUG_GENERATOR = 'ai-faq-generator-generator';
	const SLUG_SETTINGS  = 'ai-faq-generator-settings';
	const SLUG_HISTORY   = 'ai-faq-generator-history';

	/**
	 * Rejestruje menu i podmenu.
	 */
	public function register_menu(): void {
		add_menu_page(
			__( 'AI FAQ Generator', 'ai-faq-generator' ),
			__( 'AI FAQ Generator', 'ai-faq-generator' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render_dashboard' ),
			'dashicons-format-chat',
			58
		);

		add_submenu_page(
			self::SLUG,
			__( 'Dashboard', 'ai-faq-generator' ),
			__( 'Dashboard', 'ai-faq-generator' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render_dashboard' )
		);

		add_submenu_page(
			self::SLUG,
			__( 'Generator', 'ai-faq-generator' ),
			__( 'Generator', 'ai-faq-generator' ),
			self::CAPABILITY,
			self::SLUG_GENERATOR,
			array( $this, 'render_generator' )
		);

		add_submenu_page(
			self::SLUG,
			__( 'Ustawienia', 'ai-faq-generator' ),
			__( 'Ustawienia', 'ai-faq-generator' ),
			self::CAPABILITY,
			self::SLUG_SETTINGS,
			array( $this, 'render_settings' )
		);

		add_submenu_page(
			self::SLUG,
			__( 'Historia', 'ai-faq-generator' ),
			__( 'Historia', 'ai-faq-generator' ),
			self::CAPABILITY,
			self::SLUG_HISTORY,
			array( $this, 'render_history' )
		);
	}

	/**
	 * Renderuje widok Dashboardu.
	 */
	public function render_dashboard(): void {
		$this->render_view( 'dashboard' );
	}

	/**
	 * Renderuje ekran Generatora (ten sam widget Q&A co na froncie).
	 */
	public function render_generator(): void {
		$this->render_view( 'generator' );
	}

	/**
	 * Renderuje widok Ustawień.
	 */
	public function render_settings(): void {
		$this->render_view( 'settings' );
	}

	/**
	 * Renderuje widok Historii.
	 */
	public function render_history(): void {
		$this->render_view( 'history' );
	}

	/**
	 * Wczytuje plik widoku z katalogu src/Admin/views.
	 *
	 * @param string $view Nazwa widoku (bez rozszerzenia).
	 */
	private function render_view( string $view ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Brak uprawnień do tej strony.', 'ai-faq-generator' ) );
		}

		$path = AIFAQ_PLUGIN_DIR . 'src/Admin/views/' . $view . '.php';
		if ( is_readable( $path ) ) {
			require $path;
		}
	}

	/**
	 * Ładuje zasoby (CSS/JS) tylko na ekranach wtyczki.
	 *
	 * @param string $hook_suffix Identyfikator bieżącego ekranu admina.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( false === strpos( $hook_suffix, self::SLUG ) ) {
			return;
		}

		wp_enqueue_style(
			'aifaq-admin',
			AIFAQ_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			AIFAQ_VERSION
		);

		// Skrypt indeksowania tylko na Dashboardzie (nie Generator/Ustawienia/Historia).
		$is_generator = false !== strpos( $hook_suffix, self::SLUG_GENERATOR );
		$is_settings  = false !== strpos( $hook_suffix, self::SLUG_SETTINGS );
		$is_history   = false !== strpos( $hook_suffix, self::SLUG_HISTORY );
		if ( ! $is_generator && ! $is_settings && ! $is_history ) {
			wp_enqueue_script(
				'aifaq-indexer',
				AIFAQ_PLUGIN_URL . 'assets/js/indexer.js',
				array(),
				AIFAQ_VERSION,
				true
			);
			wp_localize_script(
				'aifaq-indexer',
				'aifaqIndexer',
				array(
					'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
					'nonce'         => wp_create_nonce( \AIFAQ\Admin\IndexController::NONCE ),
					'actionReindex' => \AIFAQ\Admin\IndexController::AJAX_REINDEX,
					'actionClear'   => \AIFAQ\Admin\IndexController::AJAX_CLEAR,
					'i18n'          => array(
						'running'      => __( 'Indeksuję treść…', 'ai-faq-generator' ),
						'clearing'     => __( 'Czyszczę bazę…', 'ai-faq-generator' ),
						'confirmClear' => __( 'Na pewno wyczyścić całą bazę wiedzy? Trzeba będzie zaindeksować treść od nowa.', 'ai-faq-generator' ),
						'error'        => __( 'Wystąpił błąd. Spróbuj ponownie.', 'ai-faq-generator' ),
						'done'         => __( 'Gotowe.', 'ai-faq-generator' ),
					),
				)
			);
		}

		// Skrypt tylko na podstronie Ustawienia.
		if ( false !== strpos( $hook_suffix, self::SLUG_SETTINGS ) ) {
			wp_enqueue_script(
				'aifaq-settings',
				AIFAQ_PLUGIN_URL . 'assets/js/settings.js',
				array(),
				AIFAQ_VERSION,
				true
			);
			wp_localize_script(
				'aifaq-settings',
				'aifaqSettings',
				array(
					'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
					'checking' => __( 'Sprawdzam połączenie…', 'ai-faq-generator' ),
					'error'    => __( 'Błąd połączenia.', 'ai-faq-generator' ),
					'show'     => __( 'Pokaż', 'ai-faq-generator' ),
					'hide'     => __( 'Ukryj', 'ai-faq-generator' ),
				)
			);
		}

		// Ekran Generatora: ten sam widget co front → te same assety.
		// Skrypt czyta window.aifaqFront (jak na stronie standalone), więc
		// wystawiamy tę samą konfigurację przez wp_localize_script.
		if ( $is_generator ) {
			wp_enqueue_style(
				'aifaq-generator',
				AIFAQ_PLUGIN_URL . 'assets/css/generator.css',
				array(),
				AIFAQ_VERSION
			);
			wp_enqueue_script(
				'aifaq-generator',
				AIFAQ_PLUGIN_URL . 'assets/js/generator.js',
				array(),
				AIFAQ_VERSION,
				true
			);
			wp_localize_script(
				'aifaq-generator',
				'aifaqFront',
				\AIFAQ\PublicUi\GeneratorPage::config()
			);
		}
	}
}
