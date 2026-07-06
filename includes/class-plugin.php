<?php
/**
 * Główny loader wtyczki (singleton).
 *
 * Ładuje zależności i rejestruje hooki. W kolejnych krokach dojdą tu
 * kolejne moduły (ustawienia, provider AI, REST, schema, edytor…).
 *
 * @package AI_FAQ_Generator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Klasa spinająca wtyczkę w całość.
 */
final class AIFAQ_Plugin {

	/**
	 * Jedyna instancja (singleton).
	 */
	private static ?AIFAQ_Plugin $instance = null;

	/**
	 * Menu panelu administracyjnego.
	 */
	private ?AIFAQ_Admin_Menu $admin_menu = null;

	/**
	 * Zwraca (i przy pierwszym wywołaniu tworzy) instancję wtyczki.
	 */
	public static function instance(): AIFAQ_Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Konstruktor prywatny — inicjalizacja wtyczki.
	 */
	private function __construct() {
		$this->load_dependencies();
		$this->init_hooks();
	}

	/**
	 * Wczytuje pliki klas.
	 */
	private function load_dependencies(): void {
		if ( is_admin() ) {
			require_once AIFAQ_PLUGIN_DIR . 'admin/class-menu.php';
		}
	}

	/**
	 * Rejestruje hooki WordPressa.
	 */
	private function init_hooks(): void {
		add_action( 'init', array( $this, 'load_textdomain' ) );

		if ( is_admin() ) {
			$this->admin_menu = new AIFAQ_Admin_Menu();
			add_action( 'admin_menu', array( $this->admin_menu, 'register_menu' ) );
			add_action( 'admin_enqueue_scripts', array( $this->admin_menu, 'enqueue_assets' ) );
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
