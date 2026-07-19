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
use AIFAQ\Admin\PostMetaBox;
use AIFAQ\Rest\RestController;
use AIFAQ\PublicUi\Shortcode;
use AIFAQ\Data\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Klasa spinająca wtyczkę w całość.
 */
final class Plugin {

	/**
	 * Nazwa hooka crona pobierającego strony (== `CrawlQueue::CRON_HOOK`).
	 *
	 * Świadomie literał, nie stała klasy: `add_action()` odpala się na KAŻDYM żądaniu,
	 * a odwołanie do stałej wciągnęłoby przez autoloader całą klasę kolejki tam, gdzie
	 * i tak nic jej nie użyje. Klasa ładuje się dopiero w callbacku.
	 */
	const CRAWL_HOOK = 'aifaq_crawl_tick';

	/**
	 * Nazwa harmonogramu crona (== `CrawlQueue::CRON_SCHEDULE`), interwał 60 s.
	 */
	const CRAWL_SCHEDULE = 'aifaq_minute';

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
	 * Metabox „AI FAQ" w edytorze wpisu/strony.
	 */
	private ?PostMetaBox $post_metabox = null;

	/**
	 * Kontroler REST `aifaq/v1` (front + panel).
	 */
	private ?RestController $rest = null;

	/**
	 * Shortcode `[aifaq_generator]` + podstrona generatora.
	 */
	private ?Shortcode $shortcode = null;

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
		$this->guard_crawl_request();
		$this->maybe_upgrade_db();
		$this->init_hooks();
	}

	/**
	 * Wyłącza spawn crona dla żądań pochodzących z NASZEGO crawla.
	 *
	 * Crawler puka we własną witrynę, a każde zwykłe żądanie WP potrafi odpalić
	 * kolejny tick crona. Bez tego bezpiecznika crawl pobierający stronę spawnowałby
	 * kolejne pobieranie — rekurencja obciążająca serwer klienta. Nagłówek
	 * `X-AIFAQ-Crawl` ustawia {@see \AIFAQ\Index\CrawlQueue::tick()}.
	 *
	 * Musi zadziałać na `plugins_loaded` (tutaj), bo `wp_cron()` wisi dopiero na `init`.
	 */
	private function guard_crawl_request(): void {
		if ( defined( 'DISABLE_WP_CRON' ) ) {
			return;
		}

		if ( ! isset( $_SERVER['HTTP_X_AIFAQ_CRAWL'] ) ) {
			return;
		}

		$flag = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_AIFAQ_CRAWL'] ) );
		if ( '1' === $flag ) {
			define( 'DISABLE_WP_CRON', true );
		}
	}

	/**
	 * Auto-migracja schematu bazy przy podbiciu wersji (bez reaktywacji wtyczki).
	 *
	 * `Activator::activate()` odpala się TYLKO przy aktywacji — klient, który
	 * zaktualizuje wtyczkę (podmiana plików), nigdy jej nie reaktywuje, więc nowe
	 * tabele/kolumny by u niego nie powstały. Tutaj, na `plugins_loaded`, porównujemy
	 * zapisaną wersję bazy z {@see AIFAQ_DB_VERSION} i przy różnicy uruchamiamy
	 * `dbDelta` (addytywne, idempotentne). W normalnym wywołaniu to tylko odczyt
	 * jednej opcji — koszt pomijalny.
	 */
	private function maybe_upgrade_db(): void {
		$stored = (string) get_option( 'aifaq_db_version', '' );

		if ( version_compare( $stored, AIFAQ_DB_VERSION, '<' ) ) {
			Schema::install();
			update_option( 'aifaq_db_version', AIFAQ_DB_VERSION );
		}
	}

	/**
	 * Rejestruje hooki WordPressa.
	 */
	private function init_hooks(): void {
		add_action( 'init', array( $this, 'load_textdomain' ) );

		// Router działa też dla gości (publiczna trasa `/faqgenerator`).
		$this->router = new Router();
		$this->router->register();

		// REST `aifaq/v1` — musi rejestrować się także dla gości (`/ask` publiczne),
		// dlatego montowany POZA gałęzią `is_admin()`.
		$this->rest = new RestController();
		$this->rest->register();

		// Shortcode `[aifaq_generator]` + podstrona generatora — też dla gości.
		$this->shortcode = new Shortcode();
		$this->shortcode->register();

		// Crawl własnej witryny (Krok 17) — POZA gałęzią `is_admin()`, bo cron
		// nie jest kontekstem admina (`wp-cron.php` to zwykłe żądanie frontowe).
		//
		// Harmonogram MUSI być zarejestrowany własnym filtrem: `wp_schedule_event()`
		// z nieznanym interwałem zwraca `false` PO CICHU i kolejka nigdy nie rusza.
		add_filter( 'cron_schedules', array( $this, 'register_cron_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected
		add_action( self::CRAWL_HOOK, array( $this, 'run_crawl_tick' ) );

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

			$this->post_metabox = new PostMetaBox();
			add_action( 'add_meta_boxes', array( $this->post_metabox, 'register_box' ) );
			add_action( 'admin_enqueue_scripts', array( $this->post_metabox, 'enqueue' ) );
		}
	}

	/**
	 * Dokłada harmonogram `aifaq_minute` (60 s) do listy WordPressa.
	 *
	 * @param mixed $schedules Lista harmonogramów crona.
	 * @return array<string,array{interval:int,display:string}>
	 */
	public function register_cron_schedule( $schedules ): array {
		if ( ! is_array( $schedules ) ) {
			$schedules = array();
		}

		$schedules[ self::CRAWL_SCHEDULE ] = array(
			'interval' => 60,
			'display'  => __( 'Co minutę (AI FAQ — pobieranie stron)', 'ai-faq-generator' ),
		);

		return $schedules;
	}

	/**
	 * Callback crona: jedna paczka pobierania stron.
	 *
	 * Klasa kolejki należy do innego etapu — jej brak pomija zadanie, nigdy nie
	 * wywala crona (padnięty cron zatrzymałby całą witrynę klienta, nie tylko nas).
	 */
	public function run_crawl_tick(): void {
		if ( ! class_exists( '\AIFAQ\Index\CrawlQueue' ) ) {
			return;
		}

		try {
			( new \AIFAQ\Index\CrawlQueue() )->tick();
		} catch ( \Throwable $e ) {
			unset( $e );
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
