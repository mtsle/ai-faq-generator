<?php
/**
 * Rdzen wtyczki: start, rejestracja typu tresci i taksonomii, cykl zycia.
 *
 * ETAPY 1.3-1.5. Tabela, CPT + taksonomia i aktywacja mieszkaja w jednym
 * pliku swiadomie — to caly „szkielet" wtyczki, a rozbijanie go na cztery
 * klasy po kilkanascie linii utrudnialoby czytanie kolejnosci zdarzen przy
 * aktywacji, ktora jest tu najwazniejsza.
 *
 * @package AI_News_Portal
 */

namespace AINP;

// Blokada bezposredniego wywolania pliku.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bootstrap i cykl zycia wtyczki.
 */
final class Plugin {

	/** Wlasny typ tresci — artykul portalu. */
	public const CPT = 'ainp_article';

	/** Wlasna taksonomia — kategoria tematyczna artykulu. */
	public const TAX = 'ainp_topic';

	/** Czlon adresu: archiwum CPT, single i archiwa kategorii. */
	public const ARCHIVE_SLUG = 'centrum-wiedzy';

	/** Zdarzenie crona (jedyne). Planowane w Kroku 5. */
	public const CRON_HOOK = 'ainp_tick';

	/** Nazwa tabeli bez prefiksu witryny. */
	public const TABLE = 'ainp_items';

	/** Meta wpisu: powiazanie z wierszem tabeli (indeksowana). */
	public const META_ITEM = '_ainp_item_id';

	/** Meta wpisu: adres zrodla. Link do zrodla jest obowiazkowy i nieusuwalny. */
	public const META_SOURCE = '_ainp_source_url';

	/** Meta wpisu: znacznik artykulu demo. */
	public const META_DEMO = '_ainp_demo';

	/** Opcja-sygnal: przy aktywacji istniala Strona o slugu `centrum-wiedzy`. */
	public const OPTION_SLUG_COLLISION = 'ainp_slug_collision';

	/**
	 * Pelna nazwa tabeli z prefiksem witryny.
	 *
	 * @return string
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Podpiecie hookow. Wolane na `plugins_loaded`.
	 *
	 * Hooki panelu podpinamy BEZWARUNKOWO, bez owijania w `is_admin()`:
	 * `admin_menu` i `admin_notices` i tak nie odpala sie poza kokpitem,
	 * a warunek `is_admin()` wokol rejestracji hooka to bledy, ktore we
	 * wtyczce 1 kosztowaly osobny etap napraw (skutki uboczne nie odpalaly
	 * sie dla WP-CLI i wywolan programowych).
	 *
	 * @return void
	 */
	public static function boot(): void {
		add_action( 'init', array( self::class, 'register_content_types' ) );
		add_action( 'admin_menu', array( Admin::class, 'register_menu' ) );
		add_action( 'admin_notices', array( self::class, 'render_admin_notices' ) );

		// Akcje formularzy panelu (`admin_post_ainp_*`) — etap 2.5.
		Admin::register_actions();
	}

	/**
	 * Rejestracja typu tresci i taksonomii — JEDNA funkcja.
	 *
	 * Jedna, bo wola ja rowniez aktywacja, tuz PRZED przeladowaniem regul
	 * przepisywania. `flush_rewrite_rules()` bez wczesniejszej rejestracji
	 * CPT *i taksonomii* daje 404 na kategoriach i wymusza na kliencie
	 * reczne zapisanie Bezposrednich odnosnikow.
	 *
	 * KOLEJNOSC W SRODKU TEZ NIE JEST DOWOLNA: taksonomia idzie PIERWSZA.
	 * WordPress generuje reguly przepisywania w kolejnosci rejestracji, a CPT
	 * z wlasnym slugiem dokłada regule zalacznikow `centrum-wiedzy/[^/]+/([^/]+)/?$`,
	 * ktora pasuje rowniez do adresu kategorii i — stojac wyzej — przesloniłaby go.
	 * Zmierzone na dworek.local: przy odwrotnej kolejnosci /centrum-wiedzy/kategoria/zywienie/
	 * zwracalo 404 z zapytaniem o ZALACZNIK.
	 *
	 * @return void
	 */
	public static function register_content_types(): void {
		register_taxonomy(
			self::TAX,
			array( self::CPT ),
			array(
				'labels'            => array(
					'name'          => __( 'Kategorie', 'ai-news-portal' ),
					'singular_name' => __( 'Kategoria', 'ai-news-portal' ),
					'menu_name'     => __( 'Kategorie', 'ai-news-portal' ),
					'all_items'     => __( 'Wszystkie kategorie', 'ai-news-portal' ),
					'edit_item'     => __( 'Edytuj kategorię', 'ai-news-portal' ),
					'add_new_item'  => __( 'Dodaj kategorię', 'ai-news-portal' ),
					'search_items'  => __( 'Szukaj kategorii', 'ai-news-portal' ),
					'not_found'     => __( 'Nie znaleziono kategorii', 'ai-news-portal' ),
				),
				'public'            => true,
				'hierarchical'      => true,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				/*
				 * Wlasny `rewrite` z DODATKOWYM czlonem `kategoria`.
				 *
				 * Plan zakladal /centrum-wiedzy/zywienie/ obok
				 * /centrum-wiedzy/tytul-artykulu/ i to jest w WordPressie
				 * niewykonalne: obie reguly maja identyczny wzorzec
				 * `centrum-wiedzy/([^/]+)/?$`, a regula pojedynczego artykulu
				 * stoi wyzej — zmierzone na dworek.local, archiwum kategorii
				 * zwracalo 404, bo WordPress szukal ARTYKULU o slugu „zywienie".
				 * Dodatkowy czlon rozdziela wzorce po liczbie segmentow
				 * i zostawia adresy artykulow krotkie i niezmienne przy
				 * zmianie kategorii.
				 */
				'rewrite'           => array(
					'slug'         => self::ARCHIVE_SLUG . '/kategoria',
					'with_front'   => false,
					'hierarchical' => false,
				),
			)
		);

		register_post_type(
			self::CPT,
			array(
				'labels'             => array(
					'name'               => __( 'Artykuły', 'ai-news-portal' ),
					'singular_name'      => __( 'Artykuł', 'ai-news-portal' ),
					'menu_name'          => __( 'Artykuły', 'ai-news-portal' ),
					'all_items'          => __( 'Wszystkie artykuły', 'ai-news-portal' ),
					'add_new'            => __( 'Dodaj nowy', 'ai-news-portal' ),
					'add_new_item'       => __( 'Dodaj nowy artykuł', 'ai-news-portal' ),
					'edit_item'          => __( 'Edytuj artykuł', 'ai-news-portal' ),
					'new_item'           => __( 'Nowy artykuł', 'ai-news-portal' ),
					'view_item'          => __( 'Zobacz artykuł', 'ai-news-portal' ),
					'view_items'         => __( 'Zobacz artykuły', 'ai-news-portal' ),
					'search_items'       => __( 'Szukaj artykułów', 'ai-news-portal' ),
					'not_found'          => __( 'Nie znaleziono artykułów', 'ai-news-portal' ),
					'not_found_in_trash' => __( 'Brak artykułów w koszu', 'ai-news-portal' ),
					'archives'           => __( 'Centrum Wiedzy', 'ai-news-portal' ),
				),
				'public'             => true,
				'publicly_queryable' => true,
				/*
				 * Ekrany edycji DZIALAJA (`show_ui`), ale CPT nie ma wlasnej
				 * pozycji w menu (`show_in_menu`): wtyczka ma dokladnie dwie
				 * pozycje. Hurtowe zarzadzanie artykulami idzie przez adres
				 * `edit.php?post_type=ainp_article` — musi trafic do instrukcji.
				 */
				'show_ui'            => true,
				'show_in_menu'       => false,
				'show_in_nav_menus'  => true,
				/*
				 * Edytor blokowy dla klienta. Bez `show_in_rest` WordPress 6.5
				 * otwiera te artykuly w edytorze klasycznym, co przy reszcie
				 * kokpitu wyglada jak usterka.
				 */
				'show_in_rest'       => true,
				'has_archive'        => self::ARCHIVE_SLUG,
				/*
				 * Bez `rewrite['slug']` pojedynczy artykul wyladowalby pod
				 * `/ainp_article/…`, a bez `with_front => false` archiwum
				 * powedrowaloby pod czlon struktury odnosnikow witryny.
				 */
				'rewrite'            => array(
					'slug'       => self::ARCHIVE_SLUG,
					'with_front' => false,
				),
				'supports'           => array( 'title', 'editor', 'excerpt' ),
				'capability_type'    => 'post',
				'map_meta_cap'       => true,
				// Bez `menu_icon` — przy `show_in_menu => false` nie ma go gdzie pokazac.
			)
		);
	}

	/**
	 * Aktywacja wtyczki.
	 *
	 * KOLEJNOSC JEST TRESCIA TEGO ETAPU: najpierw tabela, potem rejestracja
	 * CPT i taksonomii, i DOPIERO POTEM przeladowanie regul przepisywania.
	 * Odwrotna kolejnosc konczy sie 404 na `/centrum-wiedzy/` do czasu, az
	 * ktos recznie zapisze Bezposrednie odnosniki.
	 *
	 * @return void
	 */
	public static function activate(): void {
		self::create_table();

		self::register_content_types();
		flush_rewrite_rules();

		self::note_slug_collision();
		self::maybe_insert_demo();
	}

	/**
	 * Dezaktywacja wtyczki.
	 *
	 * Wyrejestrowanie PRZED przeladowaniem regul jest istotne: w chwili, gdy
	 * odpala sie ten hook, `init` juz przebieglo i nasz CPT jest zarejestrowany.
	 * Samo `flush_rewrite_rules()` zapisaloby wiec do bazy reguly wtyczki,
	 * ktora wlasnie jest wylaczana.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );

		unregister_post_type( self::CPT );
		unregister_taxonomy( self::TAX );
		flush_rewrite_rules();
	}

	/**
	 * Tabela `ainp_items` — jedyna tabela wtyczki.
	 *
	 * Dwa NAZWANE klucze `UNIQUE`: `url_hash` (dedup po adresie) i
	 * `content_hash` (dedup po tresci). `dbDelta` wymaga nazwanych kluczy
	 * i `get_charset_collate()`. `content_hash` dopuszcza NULL — MySQL
	 * pozwala na wiele NULL-i w kluczu UNIQUE, wiec pozycje przed
	 * ekstrakcja tresci nie kolidowaly ze soba.
	 *
	 * @return void
	 */
	private static function create_table(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			url varchar(2048) NOT NULL DEFAULT '',
			url_hash char(64) NOT NULL DEFAULT '',
			content_hash char(64) NULL DEFAULT NULL,
			title text NOT NULL,
			excerpt text NOT NULL,
			content longtext NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'new',
			note text NOT NULL,
			attempts smallint(5) unsigned NOT NULL DEFAULT 0,
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY ainp_url_hash (url_hash),
			UNIQUE KEY ainp_content_hash (content_hash),
			KEY ainp_status (status,updated_at)
		) ENGINE=InnoDB {$collate};";

		dbDelta( $sql );
	}

	/**
	 * Wykrycie kolizji slugu przy aktywacji.
	 *
	 * Wtyczka NIE tworzy zadnej Strony — archiwum CPT powstaje samo. Jesli
	 * jednak klient ma juz Strone o slugu `centrum-wiedzy`, oba twory walcza
	 * o ten sam adres. Zapisujemy sygnal, a komunikat pokazuje `render_admin_notices()`.
	 *
	 * @return void
	 */
	private static function note_slug_collision(): void {
		$page = get_page_by_path( self::ARCHIVE_SLUG );

		if ( $page instanceof \WP_Post ) {
			update_option( self::OPTION_SLUG_COLLISION, (int) $page->ID );
		}
	}

	/**
	 * Komunikaty w kokpicie.
	 *
	 * Jednorazowy: po pokazaniu sygnal jest kasowany. Miejsce docelowe to
	 * ekran zaraz po aktywacji, na ktory WordPress i tak przekierowuje.
	 *
	 * @return void
	 */
	public static function render_admin_notices(): void {
		$page_id = (int) get_option( self::OPTION_SLUG_COLLISION, 0 );

		if ( $page_id <= 0 ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		delete_option( self::OPTION_SLUG_COLLISION );

		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> %s</p><p>%s</p></div>',
			esc_html__( 'AI News Portal:', 'ai-news-portal' ),
			esc_html(
				sprintf(
					/* translators: %s: czlon adresu archiwum, np. centrum-wiedzy */
					__( 'na witrynie istnieje już Strona o adresie /%s/, a wtyczka umieszcza pod tym samym adresem Centrum Wiedzy.', 'ai-news-portal' ),
					self::ARCHIVE_SLUG
				)
			),
			wp_kses_post(
				sprintf(
					/* translators: %s: adres edycji kolidujacej Strony */
					__( 'Od teraz pod tym adresem odpowiada Centrum Wiedzy, a Twoja dotychczasowa strona przestała być osiągalna. Zmień jej adres bezpośredni albo ją usuń: <a href="%s">edytuj stronę</a>.', 'ai-news-portal' ),
					esc_url( (string) get_edit_post_link( $page_id ) )
				)
			)
		);
	}

	/**
	 * Artykul demo — zeby portal nigdy nie byl pusty.
	 *
	 * Tekst jest wpisany w kod: ZERO wywolan API, dziala bez klucza i bez
	 * internetu. Wstawiany dokladnie RAZ — znacznik w `ainp_settings` pilnuje,
	 * ze ponowna aktywacja nie zrobi drugiej kopii. Skasowany recznie nie
	 * wraca, bo znacznik zostaje; wraca dopiero po pelnej reinstalacji,
	 * bo `uninstall.php` czysci rowniez znacznik.
	 *
	 * Znika sam przy pierwszym prawdziwym artykule — to juz robota
	 * `Publisher`a w Kroku 4.
	 *
	 * @return void
	 */
	private static function maybe_insert_demo(): void {
		if ( true === Settings::get( Settings::KEY_DEMO_DONE, false ) ) {
			return;
		}

		$post_id = wp_insert_post(
			array(
				'post_type'    => self::CPT,
				'post_status'  => 'publish',
				'post_author'  => self::demo_author(),
				'post_title'   => 'Jak czytać skład karmy dla psa',
				'post_excerpt' => 'Skład na opakowaniu mówi więcej niż zdjęcie psa i hasło reklamowe. Pokazujemy, na co patrzeć w pierwszej kolejności i które sformułowania nic nie znaczą.',
				'post_content' => self::demo_content(),
				'meta_input'   => array(
					self::META_DEMO => 1,
				),
			),
			true
		);

		/*
		 * Znacznika NIE ustawiamy, gdy wstawienie sie nie powiodlo — inaczej
		 * jeden nieudany zapis odbieralby demo na zawsze.
		 */
		if ( is_wp_error( $post_id ) || $post_id <= 0 ) {
			return;
		}

		$term = term_exists( 'Żywienie', self::TAX );
		if ( ! $term ) {
			$term = wp_insert_term( 'Żywienie', self::TAX );
		}
		if ( ! is_wp_error( $term ) && isset( $term['term_id'] ) ) {
			wp_set_object_terms( $post_id, array( (int) $term['term_id'] ), self::TAX );
		}

		Settings::update( array( Settings::KEY_DEMO_DONE => true ) );
	}

	/**
	 * Autor artykulu demo.
	 *
	 * `wp_insert_post()` bez `post_author` bierze biezacego uzytkownika, a przy
	 * aktywacji spoza kokpitu — z WP-CLI albo programowo — nie ma zadnego
	 * i wpis dostaje autora **0**. Taki artykul pokazuje pusta pozycje „Autor"
	 * na liscie wpisow, a szablony wolajace `the_author()` renderuja nic.
	 * Zmierzone: aktywacja w procesie CLI dawala `post_author=0`.
	 *
	 * @return int
	 */
	private static function demo_author(): int {
		$autor = get_current_user_id();

		if ( $autor > 0 ) {
			return $autor;
		}

		$administratorzy = get_users(
			array(
				'role'    => 'administrator',
				'number'  => 1,
				'orderby' => 'ID',
				'order'   => 'ASC',
				'fields'  => 'ID',
			)
		);

		return $administratorzy ? (int) $administratorzy[0] : 1;
	}

	/**
	 * Tresc artykulu demo.
	 *
	 * @return string
	 */
	private static function demo_content(): string {
		return '<p>Opakowanie karmy sprzedaje emocje: zdjęcie zadowolonego psa, słowo „naturalna", góry w tle. Informacja, za którą naprawdę płacisz, jest z tyłu, drobnym drukiem — w składzie i analizie składników pokarmowych.</p>'
			. '<h2>Kolejność składników nie jest przypadkowa</h2>'
			. '<p>Producent ma obowiązek wymienić składniki od największej masy do najmniejszej, przy czym masa liczy się przed obróbką. To dlatego świeże mięso potrafi otwierać listę, choć po wysuszeniu zostaje z niego kilkakrotnie mniej. Mączka mięsna, która brzmi gorzej, jest już odwodniona — pod względem zawartości białka bywa uczciwszym wyborem niż efektowne „świeże mięso 30%".</p>'
			. '<h2>Czego szukać w pierwszej kolejności</h2>'
			. '<ul>'
			. '<li><strong>Nazwane źródło białka</strong> — „kurczak" niesie informację, „mięso i produkty pochodzenia zwierzęcego" nie niesie żadnej.</li>'
			. '<li><strong>Udział procentowy</strong> przy głównym składniku, a nie samo jego wymienienie.</li>'
			. '<li><strong>Krótka lista zbóż i wypełniaczy</strong>, jeśli pies ma wrażliwy przewód pokarmowy.</li>'
			. '<li><strong>Analiza składników pokarmowych</strong>: białko, tłuszcz, włókno, popiół, wilgotność.</li>'
			. '</ul>'
			. '<h2>Sformułowania, które nic nie znaczą</h2>'
			. '<p>„Premium", „holistyczna", „naturalna" i „weterynaryjna" to określenia marketingowe bez definicji prawnej — może ich użyć każdy producent. Podobnie z ilustracją warzyw na froncie: obecność marchewki w składzie na poziomie ułamka procenta nie zmienia wartości odżywczej posiłku, a jedynie wygląd opakowania.</p>'
			. '<h2>Porównuj w przeliczeniu na suchą masę</h2>'
			. '<p>Karma mokra ma około 80% wilgotności, sucha około 10%. Zestawianie wartości białka wprost z etykiet obu rodzajów prowadzi więc do fałszywego wniosku, że mokra jest uboższa. Żeby porównanie miało sens, przelicz zawartość na suchą masę — dopiero wtedy liczby mówią o tym samym.</p>'
			. '<p>Ostatnia uwaga: najlepsza karma to ta, na której konkretny pies dobrze wygląda i dobrze się czuje. Skład zawęża wybór do kilku sensownych propozycji, ale nie zastąpi obserwacji zwierzęcia ani rozmowy z lekarzem weterynarii, zwłaszcza przy chorobach przewlekłych i alergiach.</p>';
	}
}
