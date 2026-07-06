<?php
/**
 * Menu wtyczki w panelu administracyjnym.
 *
 * Rejestruje pozycję „AI FAQ Generator" z trzema podstronami:
 * Dashboard, Ustawienia, Historia.
 *
 * @package AI_FAQ_Generator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Obsługa menu i ładowania widoków podstron.
 */
class AIFAQ_Admin_Menu {

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
	const SLUG_SETTINGS = 'ai-faq-generator-settings';
	const SLUG_HISTORY  = 'ai-faq-generator-history';

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
	 * Wczytuje plik widoku z katalogu admin/views.
	 *
	 * @param string $view Nazwa widoku (bez rozszerzenia).
	 */
	private function render_view( string $view ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Brak uprawnień do tej strony.', 'ai-faq-generator' ) );
		}

		$path = AIFAQ_PLUGIN_DIR . 'admin/views/' . $view . '.php';
		if ( is_readable( $path ) ) {
			require $path;
		}
	}

	/**
	 * Ładuje zasoby (CSS) tylko na ekranach wtyczki.
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
	}
}
