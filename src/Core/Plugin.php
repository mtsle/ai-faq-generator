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
	 * Flaga „jednorazowe utwardzenie opcji wykonane" ({@see maybe_harden_options()}).
	 */
	const HARDEN_FLAG = 'aifaq_autoload_hardened';

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
		self::maybe_harden_options();
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

		// Wartością nagłówka jest TOKEN witryny, nie stałe „1". Nagłówek przychodzi
		// z zewnątrz i nikt go nie uwierzytelnia, a wyłącza rdzenny mechanizm
		// WordPressa: przy stałej „1" dowolny odwiedzający (albo bot dobijający się
		// do witryny w kółko) gasił spawn crona na swoim żądaniu, a na witrynie
		// o małym ruchu cron to jedyne źródło zadań w tle — łącznie z publikacją
		// zaplanowanych wpisów klienta. Porównanie stałoczasowe, bo token jest sekretem.
		$flag  = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_AIFAQ_CRAWL'] ) );
		$token = self::crawl_token();

		if ( '' !== $token && hash_equals( $token, $flag ) ) {
			define( 'DISABLE_WP_CRON', true );
		}
	}

	/**
	 * Token nagłówka `X-AIFAQ-Crawl` — jedno źródło prawdy dla obu stron.
	 *
	 * Wysyła go crawl ({@see \AIFAQ\Index\CrawlQueue::request_args()} oraz
	 * {@see \AIFAQ\Index\RenderedContentSource::request_args()}), a czyta
	 * {@see guard_crawl_request()}. Wyprowadzany z soli witryny, więc nie
	 * wymaga własnego magazynu i jest inny na każdej instalacji.
	 *
	 * Bez `wp_salt()` (czyste PHP CLI w testach) zwracamy `''` — a wtedy bramka
	 * NIE wyłącza crona. To bezpieczny kierunek: tracimy optymalizację, nigdy ochronę.
	 *
	 * @return string Token albo `''`, gdy nie da się go wyprowadzić.
	 */
	public static function crawl_token(): string {
		if ( ! function_exists( 'wp_salt' ) ) {
			return '';
		}

		return substr( hash_hmac( 'sha256', 'aifaq-crawl', (string) wp_salt( 'nonce' ) ), 0, 32 );
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
	 * Jednorazowo zdejmuje autoload z opcji niosącej KLUCZ API.
	 *
	 * `aifaq_settings` powstawała przez `update_option()` bez trzeciego argumentu,
	 * a WordPress tworzy tak opcję z `autoload = yes` — czyli klucz API lądował
	 * w `alloptions` przy KAŻDYM żądaniu witryny, także żądaniu gościa. Sam zapis
	 * jest już naprawiony ({@see Settings::save()}), ale instalacje, które opcję
	 * mają, zostałyby z autoloadem na zawsze: `update_option()` przestawia flagę
	 * tylko wtedy, gdy zmienia się WARTOŚĆ.
	 *
	 * Zmieniamy WYŁĄCZNIE kolumnę `autoload` — wartość opcji nie jest ani kasowana,
	 * ani przepisywana, więc nie ma tu ścieżki utraty ustawień klienta. Wariant
	 * `delete_option()` + `add_option()` byłby prostszy, ale przerwanie procesu
	 * między nimi kasowałoby klucz API.
	 */
	public static function maybe_harden_options(): void {
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
			return;
		}

		if ( '1' === (string) get_option( self::HARDEN_FLAG, '' ) ) {
			return;
		}

		self::set_option_autoload_no( Settings::OPTION );

		// Flaga autoładowana świadomie (jak `aifaq_cache_flushed_for`): czyta ją
		// każde żądanie, a wartość „już zrobione" nie ma prawa kosztować SELECT-a.
		if ( function_exists( 'add_option' ) ) {
			add_option( self::HARDEN_FLAG, '', '', 'yes' );
		}
		update_option( self::HARDEN_FLAG, '1', true );
	}

	/**
	 * Przestawia flagę autoload opcji na `no` (bez ruszania jej wartości).
	 *
	 * `wp_set_option_autoload()` istnieje dopiero od WordPressa 6.7, a wtyczka
	 * deklaruje 6.4 — dlatego jest fallback wprost na kolumnę. Po zmianie trzeba
	 * zrzucić cache `alloptions`, inaczej bieżące żądanie (i kolejne, przy trwałym
	 * cache obiektowym) dalej widziałoby opcję jako autoładowaną.
	 *
	 * @param string $name Nazwa opcji.
	 */
	private static function set_option_autoload_no( string $name ): void {
		if ( function_exists( 'wp_set_option_autoload' ) ) {
			wp_set_option_autoload( $name, false );
			return;
		}

		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'update' ) ) {
			return;
		}

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->options,
			array( 'autoload' => 'no' ),
			array( 'option_name' => $name ),
			array( '%s' ),
			array( '%s' )
		);

		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( $name, 'options' );
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

		// Dane strukturalne podstrony generatora — POZA gałęzią `is_admin()`,
		// bo SEO dotyczy wyłącznie żądań gościa i bota.
		( new \AIFAQ\PublicUi\PageSchema() )->register();

		// Nagłówki bezpieczeństwa podstrony generatora (Krok 21) — POZA gałęzią
		// `is_admin()`, bo obie trasy, które osłania, są publiczne.
		( new \AIFAQ\PublicUi\SecurityHeaders() )->register();

		// Crawl własnej witryny (Krok 17) — POZA gałęzią `is_admin()`, bo cron
		// nie jest kontekstem admina (`wp-cron.php` to zwykłe żądanie frontowe).
		//
		// Harmonogram MUSI być zarejestrowany własnym filtrem: `wp_schedule_event()`
		// z nieznanym interwałem zwraca `false` PO CICHU i kolejka nigdy nie rusza.
		add_filter( 'cron_schedules', array( $this, 'register_cron_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected
		add_action( self::CRAWL_HOOK, array( $this, 'run_crawl_tick' ) );

		// F1: wznowienie reindeksu przerwanego budżetem czasu — POZA `is_admin()`
		// z tego samego powodu co crawl (`wp-cron.php` nie jest kontekstem admina).
		add_action( IndexController::CRON_CONTINUE_HOOK, array( $this, 'run_reindex_continue_tick' ) );

		// Niezawodność podstrony generatora (Krok 18) — POZA gałęzią `is_admin()`,
		// bo kosz bywa obsługiwany także z REST i z WP-CLI, gdzie `is_admin()` jest
		// fałszem. Usunięcie TRWAŁE ma osobny callback: tylko rozdzielenie „leży
		// w koszu" od „skasowane na zawsze" pozwala NIE odtwarzać strony, którą
		// klient skasował świadomie.
		add_action( 'trashed_post', array( $this, 'on_page_event' ), 10, 1 );
		add_action( 'untrashed_post', array( $this, 'on_page_event' ), 10, 1 );
		add_action( 'deleted_post', array( $this, 'on_page_deleted' ), 10, 1 );

		// K23 etap 1, znalezisko A2: kosz/skasowanie wpisu MUSI usunąć jego
		// fragmenty z bazy wiedzy RAG — inaczej Retriever dalej serwuje gościom
		// treść (np. cenę), której na stronie już nie ma, z linkiem do 404.
		// Bez `untrashed_post`: przywrócony wpis wraca BEZ embeddingów do
		// najbliższego RĘCZNEGO reindeksu — to ten sam koszt co dodanie nowej
		// treści, nie automatyczne (płatne) wywołanie API bez zgody właściciela.
		add_action( 'trashed_post', array( $this, 'on_knowledge_post_removed' ), 10, 1 );
		add_action( 'deleted_post', array( $this, 'on_knowledge_post_removed' ), 10, 1 );

		// Skutki uboczne ZAPISU ustawień rejestrujemy BEZWARUNKOWO, poza bramką
		// `is_admin()` niżej. Powód (K23 etap 5, dowód na żywo): `Settings::save()`
		// ma świadomie wiele ścieżek — ekran Ustawień, REST, ale też WP-CLI
		// (`wp option update`), przywracanie kopii bazy i każde wywołanie programowe.
		// Rejestracja wewnątrz `is_admin()` znaczyła, że zmiana sluga poza kokpitem
		// NIE podnosi flagi flush, a `Router::maybe_flush_rewrite()` reaguje wyłącznie
		// na nią (zero samonaprawy) — nowy adres zwracał 404 do najbliższego ręcznego
		// przebudowania reguł. To samo dotyczyło unieważniania bramki MenuGuarda
		// i czyszczenia kolejki crawla.
		//
		// Koszt jest zerowy: `Settings` nie ma konstruktora, a callback odpala się
		// wyłącznie przy faktycznej zmianie TEJ opcji.
		$this->settings = new Settings();
		// H2: po zmianie sluga trasy przebuduj reguły rewrite (inaczej nowy slug = 404).
		add_action( 'update_option_' . Settings::OPTION, array( $this->settings, 'on_settings_updated' ), 10, 2 );

		if ( is_admin() ) {
			add_action( 'admin_init', array( $this->settings, 'register' ) );
			add_action( 'wp_ajax_' . Settings::AJAX_TEST, array( $this->settings, 'ajax_test_connection' ) );

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

			// Link do generatora w menu nawigacji (Krok 20). TANIA BRAMKA na
			// `admin_init` jest tu jedyną ścieżką ratującą klienta, który wtyczkę
			// AKTUALIZUJE (podmiana plików): `register_activation_hook` wtedy się
			// NIE odpala — dokładnie ta wada, którą dla bazy zamknęliśmy w K11
			// przez `maybe_upgrade_db()` (patrz jej docblock wyżej). Bez tego
			// wiersza taki klient nie dostałby pozycji w menu NIGDY.
			//
			// `admin_init`, nie `init`: gość nie ma płacić za diagnozę nawigacji.
			// Bramki taniości (`wp_doing_ajax`/`wp_doing_cron`/`current_user_can`)
			// siedzą WEWNĄTRZ `maybe_ensure()` — tam, gdzie ich miejsce, bo
			// `admin_init` odpala się także w `admin-ajax.php` dla niezalogowanych.
			if ( class_exists( '\AIFAQ\PublicUi\MenuGuard' ) ) {
				add_action( 'admin_init', array( '\AIFAQ\PublicUi\MenuGuard', 'maybe_ensure' ) );
			}

			add_action( 'admin_post_aifaq_menu_fix', array( $this, 'handle_menu_fix' ) );
			add_action( 'admin_post_aifaq_editor_hint', array( $this, 'handle_editor_hint' ) );
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
	 * Callback crona: wznawia reindeks przerwany budżetem czasu (F1).
	 *
	 * `run_reindex()` samo zaplanuje KOLEJNE wznowienie, jeśli budżet znów
	 * zostanie wyczerpany — pętla kończy się dopiero, gdy przebieg wyjdzie
	 * `complete` albo padnie na crawlu/błędach (te dwa nie planują niczego).
	 * Klasa kontrolera należy do innego etapu — jej brak (albo dowolny
	 * wyjątek w środku) NIE ma prawa wywalić crona witryny klienta.
	 */
	public function run_reindex_continue_tick(): void {
		if ( ! class_exists( '\AIFAQ\Admin\IndexController' ) ) {
			return;
		}

		try {
			( new IndexController() )->run_reindex();
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Wypisuje komunikaty kokpitu: stan podstrony generatora (K18), stan bazy
	 * wektorów po migracji przestrzeni embeddingów (K19) oraz stan linku w menu
	 * nawigacji i podpowiedź o panelu w edytorze (K20).
	 *
	 * CZTERY komunikaty na JEDNYM callbacku — celowo. Drugi hook admin notices
	 * (bez apostrofów, żeby nie podbić licznika strażnika K18) zaczerwieniłby
	 * asercję #62 testu podstrony, która wymaga dokładnie jednego wystąpienia
	 * tego literału w tym pliku.
	 *
	 * Bloki są NIEZALEŻNE (żadnego wczesnego `return`): brak klasy z Kroku 18
	 * nie ma prawa wyciszyć komunikatu migracji z Kroku 19 ani komunikatu menu
	 * z Kroku 20 — i odwrotnie. Klasy należą do innych etapów, więc ich brak
	 * pomija wypis i NIGDY nie wywala kokpitu klienta (błąd na tym hooku
	 * wypisuje się wprost na górze każdego ekranu panelu).
	 *
	 * PIĄTY blok (K23, audyt RWA — znalezisko F1): świeżość treści względem
	 * ostatniego indeksowania. Ta sama zasada niezależności — brak klasy albo
	 * wyjątek w środku nie ma prawa wyciszyć pozostałych czterech komunikatów.
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

		if ( class_exists( '\AIFAQ\Admin\MenuNotice' ) ) {
			try {
				\AIFAQ\Admin\MenuNotice::render();
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		if ( class_exists( '\AIFAQ\Admin\EditorNotice' ) ) {
			try {
				\AIFAQ\Admin\EditorNotice::render();
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		if ( class_exists( '\AIFAQ\Admin\FreshnessNotice' ) ) {
			try {
				\AIFAQ\Admin\FreshnessNotice::render();
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
	 * Obsługuje kliknięcie w akcję naprawczą komunikatu o menu (K20).
	 *
	 * Uprawnienie (`manage_options`), nonce i whitelistę wartości sprawdza sama
	 * klasa komunikatu — dokładnie tak, jak przy podstronie z Kroku 18: blok
	 * bezpieczeństwa mieszka w `handle_fix()`, tutaj jest wyłącznie routing.
	 * Trzymanie go w JEDNYM miejscu jest wymogiem, nie stylem — zdublowany cap
	 * przeżyłby mutację „podmieniony cap w handlerze" (§10 pkt 3) i uczyniłby
	 * ją niewykrywalną.
	 */
	public function handle_menu_fix(): void {
		if ( ! class_exists( '\AIFAQ\Admin\MenuNotice' ) ) {
			return;
		}

		try {
			\AIFAQ\Admin\MenuNotice::handle_fix();
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Obsługuje zamknięcie podpowiedzi o panelu „AI FAQ" w edytorze (K20).
	 *
	 * Cap NARZĘDZIA (nie `manage_options`), nonce i whitelistę sprawdza
	 * {@see \AIFAQ\Admin\EditorNotice::handle_fix()} — tutaj tylko routing.
	 */
	public function handle_editor_hint(): void {
		if ( ! class_exists( '\AIFAQ\Admin\EditorNotice' ) ) {
			return;
		}

		try {
			\AIFAQ\Admin\EditorNotice::handle_fix();
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
	 * Wpis trafił do kosza albo został skasowany na trwałe — jego fragmenty
	 * w bazie wiedzy RAG znikają razem z nim (K23 etap 1, znalezisko A2).
	 *
	 * Kasujemy też CAŁY cache odpowiedzi: `CacheRepository` nie wie, z jakich
	 * postów pochodzi zapamiętana odpowiedź (klucz to hash pytania, nie
	 * post_id), więc częściowa inwalidacja nie jest możliwa — a bez tego gość,
	 * który wcześniej trafił w cache, dostawałby nieaktualną odpowiedź mimo
	 * usuniętego embeddingu. `clear_all()` to tania operacja (TRUNCATE, zero
	 * wywołań API) — koszt to utracone trafienia cache, nie pieniądze.
	 *
	 * @param int|mixed $post_id ID wpisu, którego dotyczy zdarzenie.
	 */
	public function on_knowledge_post_removed( $post_id ): void {
		if ( ! class_exists( '\AIFAQ\Data\KnowledgeRepository' ) ) {
			return;
		}

		try {
			$removed = ( new \AIFAQ\Data\KnowledgeRepository() )->delete_by_post( (int) $post_id );
			if ( $removed > 0 && class_exists( '\AIFAQ\Data\CacheRepository' ) ) {
				( new \AIFAQ\Data\CacheRepository() )->clear_all();
			}
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
