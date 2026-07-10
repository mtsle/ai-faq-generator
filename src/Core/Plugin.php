<?php
/**
 * Główny loader wtyczki (singleton) — orkiestrator modułów.
 *
 * Spina wtyczkę w całość: montuje router (front + rewrite `/faqgenerator`),
 * a w panelu administracyjnym ustawienia i menu. Klasy ładują się leniwie
 * przez autoloader, więc nie ma tu ręcznych `require`.
 *
 * @package AI_FAQ_Generator
 */

namespace AIFAQ\Core;

use AIFAQ\Admin\Menu;
use AIFAQ\Admin\IndexController;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Klasa spinająca wtyczkę w całość.
 */
final class Plugin {

	/**
	 * Jedyna instancja (singleton).
	 */
	private static ?Plugin $instance = null;

	/**
	 * Router publicznej trasy `/faqgenerator`.
	 */
	private ?Router $router = null;

	/**
	 * Ustawienia / konfiguracja API.
	 */
	private ?Settings $settings = null;

	/**
	 * Menu panelu administracyjnego.
	 */
	private ?Menu $admin_menu = null;

	/**
	 * Kontroler indeksowania (akcje AJAX Dashboardu).
	 */
	private ?IndexController $index_controller = null;

	/**
	 * Zwraca (i przy pierwszym wywołaniu tworzy) instancję wtyczki.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Konstruktor prywatny — inicjalizacja wtyczki.
	 */
	private function __construct() {
		$this->init_hooks();
	}

	/**
	 * Rejestruje hooki WordPressa.
	 */
	private function init_hooks(): void {
		add_action( 'init', array( $this, 'load_textdomain' ) );

		// Router działa też dla gości (publiczna trasa `/faqgenerator`).
		$this->router = new Router();
		$this->router->register();

		if ( is_admin() ) {
			$this->settings = new Settings();
			add_action( 'admin_init', array( $this->settings, 'register' ) );
			add_action( 'wp_ajax_' . Settings::AJAX_TEST, array( $this->settings, 'ajax_test_connection' ) );
			// H2: po zmianie sluga trasy przebuduj reguły rewrite (inaczej nowy slug = 404).
			add_action( 'update_option_' . Settings::OPTION, array( $this->settings, 'on_settings_updated' ), 10, 2 );

			$this->admin_menu = new Menu();
			add_action( 'admin_menu', array( $this->admin_menu, 'register_menu' ) );
			add_action( 'admin_enqueue_scripts', array( $this->admin_menu, 'enqueue_assets' ) );

			$this->index_controller = new IndexController();
			add_action( 'wp_ajax_' . IndexController::AJAX_REINDEX, array( $this->index_controller, 'ajax_reindex' ) );
			add_action( 'wp_ajax_' . IndexController::AJAX_CLEAR, array( $this->index_controller, 'ajax_clear' ) );
		}
	}

	/**
	 * Ładuje tłumaczenia wtyczki.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'ai-faq-generator',
			false,
			dirname( AIFAQ_PLUGIN_BASENAME ) . '/languages'
		);
	}
}
