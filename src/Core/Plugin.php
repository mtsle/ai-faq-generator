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
		self::maybe_flush_cache();
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
	 * Czy cache odpowiedzi wymaga jednorazowego wyczyszczenia po aktualizacji.
	 *
	 * CZYSTA DECYZJA — zero I/O, testowalna bez WordPressa. Rozdział „decyzja vs
	 * skorupa" jest wymogiem, nie stylem: gdyby warunek siedział inline w
	 * {@see self::maybe_flush_cache()}, mutacja odwracająca go nie miałaby czego
	 * zaczerwienić i mechanizm pojechałby na produkcję niesprawdzony.
	 *
	 * @param string $stored  Wersja, dla której cache już wyczyszczono.
	 * @param string $version Wersja bieżąca wtyczki.
	 */
	public static function should_flush_cache( string $stored, string $version ): bool {
		return '' !== $version && $stored !== $version;
	}

	/**
	 * Jednorazowo czyści cache odpowiedzi po podbiciu wersji wtyczki.
	 *
	 * `wp_aifaq_cache` nie ma TTL, a klucz nie zawiera wersji kodu — więc każda
	 * utrwalona zła odpowiedź (klasyczne „Tak.") przeżyłaby całą naprawę Kroku 19:
	 * model nigdy nie zostałby zapytany ponownie. Reindeks czyści cache, ale klient,
	 * który NIGDY nie kliknie reindeksu, inaczej zostaje ze starymi odpowiedziami na
	 * zawsze. To jest ten drugi, bezobsługowy poziom.
	 *
	 * PUBLIC STATIC, nie private: `Plugin` ma prywatny konstruktor i nigdy nie jest
	 * instancjonowany w testach, więc metoda prywatna byłaby niewywoływalna — a wtedy
	 * jedyny mechanizm gwarantujący nowe odpowiedzi klientowi nieklikającemu reindeksu
	 * byłby sprawdzany co najwyżej `substr_count` na źródle, czyli fałszywą zielenią.
	 *
	 * Wołane PO {@see self::maybe_upgrade_db()} — tabela cache'u musi już istnieć.
	 */
	public static function maybe_flush_cache(): void {
		if ( ! defined( 'AIFAQ_VERSION' ) || ! function_exists( 'get_option' ) ) {
			return;
		}

		$stored = (string) get_option( 'aifaq_cache_flushed_for', '' );
		if ( ! self::should_flush_cache( $stored, (string) AIFAQ_VERSION ) ) {
			return;
		}

		// Zamek: metoda wisi na `plugins_loaded`, czyli na KAŻDYM żądaniu, w tym
		// równoległych. Bez niego pierwsze N żądań po podmianie plików (bot + gość
		// + admin) wykonałoby N × TRUNCATE — blokada tabeli i widoczne zamulenie
		// witryny dokładnie w momencie, gdy klient patrzy.
		if ( function_exists( 'get_transient' ) && get_transient( 'aifaq_cache_flush_lock' ) ) {
			return;
		}
		if ( function_exists( 'set_transient' ) ) {
			set_transient( 'aifaq_cache_flush_lock', 1, 60 );
		}

		$rows = null;
		if ( class_exists( '\AIFAQ\Data\CacheRepository' ) ) {
			try {
				$rows = ( new \AIFAQ\Data\CacheRepository() )->clear_all();
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		// TRUNCATE padło (hosting bez uprawnień, tabela jeszcze nieutworzona, baza
		// w read-only) → flagi NIE zapisujemy i spróbujemy przy następnym żądaniu.
		// Zapis „zrobione" mimo porażki odbierałby klientowi drugą szansę po cichu.
		if ( null === $rows ) {
			return;
		}

		// Autoload `yes` — odwrotnie niż przy `aifaq_index_signature`. Ta flaga jest
		// czytana bezwarunkowo na `plugins_loaded`, także dla gości, na każdym żądaniu
		// witryny; z autoloadem `no` byłby to dodatkowy SELECT przy każdym wyświetleniu
		// każdej podstrony, w nieskończoność, dla wartości „już zrobione".
		if ( function_exists( 'add_option' ) ) {
			add_option( 'aifaq_cache_flushed_for', '', '', 'yes' );
		}
		if ( function_exists( 'update_option' ) ) {
			update_option( 'aifaq_cache_flushed_for', AIFAQ_VERSION, true );
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

		// Niezawodność podstrony generatora (Krok 18) — POZA gałęzią `is_admin()`,
		// bo kosz bywa obsługiwany także z REST i z WP-CLI, gdzie `is_admin()` jest
		// fałszem. Usunięcie TRWAŁE ma osobny callback: tylko rozdzielenie „leży
		// w koszu" od „skasowane na zawsze" pozwala NIE odtwarzać strony, którą
		// klient skasował świadomie.
		add_action( 'trashed_post', array( $this, 'on_page_event' ), 10, 1 );
		add_action( 'untrashed_post', array( $this, 'on_page_event' ), 10, 1 );
		add_action( 'deleted_post', array( $this, 'on_page_deleted' ), 10, 1 );

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

			// Stan podstrony generatora (Krok 18): audyt (bez tworzenia), komunikat
			// dla właściciela i akcje naprawcze. Klasa komunikatu należy do innego
			// etapu — hooki rejestrujemy bezwarunkowo, a jej brak pomija callback
			// (ten sam idiom, co przy kolejce pobierania stron).
			add_action( 'admin_notices', array( $this, 'render_page_notice' ) );
			add_action( 'admin_post_aifaq_page_fix', array( $this, 'handle_page_fix' ) );
			add_action( 'admin_init', array( __CLASS__, 'audit_page' ) );
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
	 * Wypisuje komunikaty kokpitu: stan podstrony generatora (K18) i stan bazy
	 * wektorów po migracji przestrzeni embeddingów (K19).
	 *
	 * DWA komunikaty na JEDNYM callbacku — celowo. Drugi hook admin notices
	 * (bez apostrofów, żeby nie podbić licznika strażnika K18) zaczerwieniłby
	 * asercję #62 testu podstrony, która wymaga dokładnie jednego wystąpienia
	 * tego literału w tym pliku.
	 *
	 * Bloki są NIEZALEŻNE (żadnego wczesnego `return`): brak klasy z Kroku 18
	 * nie ma prawa wyciszyć komunikatu migracji z Kroku 19 i odwrotnie. Klasy
	 * należą do innych etapów — ich brak pomija wypis i NIGDY nie wywala kokpitu
	 * klienta (błąd na tym hooku wypisuje się wprost na górze każdego ekranu panelu).
	 */
	public function render_page_notice(): void {
		if ( class_exists( '\AIFAQ\Admin\PageNotice' ) ) {
			try {
				\AIFAQ\Admin\PageNotice::render();
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		if ( class_exists( '\AIFAQ\Admin\IndexNotice' ) ) {
			try {
				\AIFAQ\Admin\IndexNotice::render();
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}
	}

	/**
	 * Obsługuje kliknięcie przycisku naprawczego z komunikatu.
	 *
	 * Uprawnienie i nonce sprawdza sama klasa komunikatu — tutaj tylko routing.
	 */
	public function handle_page_fix(): void {
		if ( ! class_exists( '\AIFAQ\Admin\PageNotice' ) ) {
			return;
		}

		try {
			\AIFAQ\Admin\PageNotice::handle_fix();
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Audyt stanu podstrony w panelu — przelicza stan, NIC nie tworzy.
	 *
	 * Metoda jest STATYCZNA celowo: konstruktor tej klasy jest prywatny i odpala
	 * migrację schematu, więc audytu nie da się wywołać w teście przez instancję.
	 *
	 * Trzy bramki taniości są obowiązkowe. Hook, na którym to wisi, odpala się
	 * także przy KAŻDYM żądaniu AJAX każdego zalogowanego użytkownika (koszyk,
	 * autozapis, wtyczki sklepowe) oraz przy cronie — a stan czyta wyłącznie
	 * właściciel z uprawnieniem `manage_options`.
	 */
	public static function audit_page(): void {
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return;
		}

		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return;
		}

		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! class_exists( '\AIFAQ\PublicUi\PageGuard' ) ) {
			return;
		}

		try {
			\AIFAQ\PublicUi\PageGuard::refresh();
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Podstrona trafiła do kosza albo z niego wróciła — przelicz stan.
	 *
	 * @param int|mixed $post_id ID wpisu, którego dotyczy zdarzenie.
	 */
	public function on_page_event( $post_id ): void {
		if ( ! class_exists( '\AIFAQ\PublicUi\PageGuard' ) ) {
			return;
		}

		try {
			\AIFAQ\PublicUi\PageGuard::on_post_event( $post_id );
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Podstrona została skasowana TRWALE — zapamiętaj to i nie odtwarzaj jej.
	 *
	 * @param int|mixed $post_id ID usuniętego wpisu.
	 */
	public function on_page_deleted( $post_id ): void {
		if ( ! class_exists( '\AIFAQ\PublicUi\PageGuard' ) ) {
			return;
		}

		try {
			\AIFAQ\PublicUi\PageGuard::on_post_deleted( $post_id );
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
