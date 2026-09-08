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

	/** Zdarzenie crona (jedyne). Planowane przy aktywacji — etap 5.1. */
	public const CRON_HOOK = 'ainp_tick';

	/**
	 * Powtarzalnosc `ainp_tick` — nazwa harmonogramu WordPressa.
	 *
	 * `hourly` jest wbudowane, wiec wtyczka nie dokłada wlasnego wpisu do
	 * `cron_schedules`. Czestsze bieganie nie ma sensu: dobowa pula wywolan AI
	 * to 20, a jeden przebieg publikacji bierze do trzech pozycji.
	 */
	public const CRON_RECURRENCE = 'hourly';

	/** Nazwa tabeli bez prefiksu witryny. */
	public const TABLE = 'ainp_items';

	/** Meta wpisu: powiazanie z wierszem tabeli (indeksowana). */
	public const META_ITEM = '_ainp_item_id';

	/** Meta wpisu: adres zrodla. Link do zrodla jest obowiazkowy i nieusuwalny. */
	public const META_SOURCE = '_ainp_source_url';

	/** Meta wpisu: znacznik artykulu demo. */
	public const META_DEMO = '_ainp_demo';

	/**
	 * Opcja-sygnal kolizji adresow.
	 *
	 * Dwa przypadki w jednym sygnale: `page_id` — przy aktywacji istniala
	 * Strona o slugu `centrum-wiedzy`; `articles` — artykul dostal adres
	 * z przyrostkiem, bo adres z tytulu byl zajety (RAU-R07-006). Stara,
	 * gola liczba jest nadal rozumiana — patrz `collision_shape()`.
	 */
	public const OPTION_SLUG_COLLISION = 'ainp_slug_collision';

	/** Ile ostatnich kolizji slugu artykulu trzyma sygnal. */
	private const SLUG_COLLISION_KEEP = 10;

	/**
	 * Opcja-odcisk: dla jakiej listy kategorii zalozono juz terminy.
	 *
	 * Trzyma skrot listy z Ustawien, nie flage — dzieki temu dopisanie przez
	 * klienta osmej kategorii samo doklada brakujacy termin, bez zadnego
	 * przycisku „odswiez". Uninstall kasuje ja razem z reszta opcji
	 * (wzorzec `ainp_%`).
	 */
	public const OPTION_TOPICS_SEEDED = 'ainp_topics_seeded';

	/**
	 * Opcja-wersja struktury tabeli `ainp_items`.
	 *
	 * Do wersji 1.0.0 wtyczka NIE MIALA jej wcale — `grep db_version` po calym
	 * drzewie dawal zero trafien — a `create_table()` mialo dokladnie jednego
	 * wywolujacego: aktywacje. `register_activation_hook` nie odpala sie przy
	 * podmianie plikow dzialajacej wtyczki, wiec przy pierwszej zmianie
	 * definicji tabeli miedzy wydaniami struktura zostawalaby stara i ZADEN
	 * warunek nie mogl tego wykryc. Ten sam plik kompensowal juz ten sam brak
	 * dwa razy — dla harmonogramu i dla kategorii.
	 *
	 * Uninstall kasuje ja jawna lista ORAZ wzorcem `ainp_%`.
	 */
	public const OPTION_DB_VERSION = 'ainp_db_version';

	/**
	 * Numer struktury tabeli — podnoszony przy KAZDEJ zmianie definicji.
	 *
	 * Lancuch, nie liczba: `get_option()` oddaje lancuch, a porownanie `===`
	 * z liczba bylo by wtedy zawsze falszem i przebudowa chodzilaby co zadanie.
	 *
	 * `'1'` to struktura z Kroku 2, niezmieniona do wydania 1.0.0.
	 */
	public const DB_VERSION = '1';

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
		/*
		 * TLUMACZENIA — ustalenie audytowe D3.
		 *
		 * Naglowek wtyczki deklaruje `Text Domain`, a kazdy napis widoczny
		 * dla uzytkownika przechodzi przez `__()` z ta domena — ale domena
		 * nie byla nigdzie ladowana, wiec zaden plik `.mo` nie mial jak
		 * wejsc. Kod obiecywal tlumaczalnosc, ktorej nie bylo.
		 *
		 * NA `init`, NIE WCZESNIEJ: od WordPressa 6.7 ladowanie domeny przed
		 * `init` konczy sie ostrzezeniem `_load_textdomain_just_in_time`.
		 */
		add_action( 'init', array( self::class, 'load_textdomain' ) );

		add_action( 'init', array( self::class, 'register_content_types' ) );

		/*
		 * KATEGORIE ISTNIEJA OD PIERWSZEGO DNIA — decyzja usera z 2026-08-09.
		 *
		 * Bez tego taksonomia zapelniala sie dopiero przy publikacji, bo termin
		 * tworzy `wp_set_object_terms()` w locie. Klient po wlaczeniu wtyczki
		 * widzial JEDEN przycisk kategorii zamiast siedmiu i pusty portal, ktory
		 * niczego o sobie nie mowil.
		 *
		 * PRIORYTET 20, nie domyslny: taksonomia musi byc juz zarejestrowana,
		 * inaczej `wp_insert_term()` nie ma do czego wstawiac.
		 *
		 * Domkniecie na `init`, a nie tylko przy aktywacji — z tego samego
		 * powodu co harmonogram (naprawa A1): `register_activation_hook` nie
		 * odpala sie przy podmianie plikow dzialajacej wtyczki, wiec kazda
		 * istniejaca instalacja zostalaby bez kategorii. Koszt jest zerowy,
		 * bo `ensure_topics()` wychodzi po porownaniu dwoch autoladowanych
		 * opcji, zanim dotknie bazy.
		 */
		add_action( 'init', array( self::class, 'ensure_topics' ), 20 );
		add_action( 'admin_menu', array( Admin::class, 'register_menu' ) );
		add_action( 'admin_notices', array( self::class, 'render_admin_notices' ) );

		/*
		 * SLUCHACZ TICKU — etap 5.1. Podpiety BEZWARUNKOWO, tak jak hooki panelu:
		 * cron odpala sie w zadaniu, ktore nie jest kokpitem, wiec kazde owiniecie
		 * w `is_admin()` uczynioloby harmonogram martwym. Harmonogram nalezy do
		 * tego pliku, wykonanie do `Runner::tick()` — jeden wlasciciel kazdej
		 * z tych dwoch rzeczy.
		 */
		add_action( self::CRON_HOOK, array( Runner::class, 'tick' ) );

		/*
		 * DOMKNIECIE HARMONOGRAMU POZA AKTYWACJA — ustalenie audytowe A1.
		 *
		 * `register_activation_hook` NIE odpala sie przy podmianie plikow
		 * dzialajacej wtyczki, a tak wlasnie wyglada kazda aktualizacja: klient
		 * wgrywa nowy ZIP, my podmieniamy pliki przez junction. Instalacja, ktora
		 * chodzila na wersji sprzed Kroku 5, nie dostalaby harmonogramu NIGDY —
		 * panel dzialalby normalnie, wiec nic by tego nie zdradzilo.
		 *
		 * Koszt jest zerowy: `has_recurring_tick()` czyta opcje `cron`, ktora
		 * WordPress i tak trzyma autoladowana, a planowanie wykonuje sie tylko
		 * wtedy, gdy powtarzalnego zdarzenia naprawde nie ma.
		 */
		add_action( 'init', array( self::class, 'ensure_schedule' ) );

		/*
		 * DOMKNIECIE STRUKTURY TABELI POZA AKTYWACJA (RAU-R06-002). Trzecia
		 * rzecz w tym pliku domykana na `init` z tego samego powodu, co
		 * harmonogram i kategorie: podmiana plikow nie odpala aktywacji.
		 *
		 * PRIORYTET 5, czyli PRZED `ensure_topics` (20) i przed sluchaczem
		 * ticku: kategorie i tick pisza do tabeli, wiec struktura musi byc
		 * gotowa wczesniej.
		 */
		add_action( 'init', array( self::class, 'ensure_schema' ), 5 );

		// Akcje formularzy panelu (`admin_post_ainp_*`) — etap 2.5.
		Admin::register_actions();

		/*
		 * FRONT CENTRUM WIEDZY — etap 6.1. Wybor szablonu i przekierowanie
		 * golego `/centrum-wiedzy/kategoria/`. Wlasciciel jest jeden: hooki
		 * frontu mieszkaja w `Portal`, tak jak wykonanie ticku mieszka
		 * w `Runner`, a nie tutaj.
		 */
		Portal::register();

		/*
		 * NAGLOWKI BEZPIECZENSTWA — etap 7.1. Ten sam wlasciciel co wyzej:
		 * `Security` sam wie, ktore zadania sa nasze i czego NIE ruszac.
		 * Rejestracja bezwarunkowa; `template_redirect` nie chodzi w kokpicie,
		 * wiec panel zostaje poza zasiegiem bez zadnego `is_admin()` tutaj.
		 */
		Security::register();
	}

	/**
	 * Ladowanie tlumaczen wtyczki. Etap 6.1+, naprawa D3.
	 *
	 * Katalog `languages/` w paczce jeszcze nie istnieje i to jest w porzadku:
	 * `load_plugin_textdomain()` szuka najpierw w `wp-content/languages/plugins/`,
	 * gdzie trafiaja tlumaczenia wgrywane przez wlasciciela witryny. Wpis
	 * `Domain Path` w naglowku mowi, gdzie szukac w SAMEJ paczce, gdy
	 * kiedykolwiek dolozymy tam plik `.mo`.
	 *
	 * @return void
	 */
	public static function load_textdomain(): void {
		load_plugin_textdomain(
			'ai-news-portal',
			false,
			dirname( AINP_PLUGIN_BASENAME ) . '/languages'
		);
	}

	/**
	 * Zaklada terminy taksonomii dla kategorii z Ustawien.
	 *
	 * IDEMPOTENTNA I TANIA. Odcisk listy w opcji `ainp_topics_seeded` sprawia,
	 * ze typowe zadanie konczy sie na porownaniu dwoch napisow — do bazy
	 * siegamy wylacznie wtedy, gdy lista kategorii naprawde sie zmienila.
	 *
	 * CZEGO TA METODA NIE ROBI: nie kasuje terminow, ktorych nie ma juz
	 * w Ustawieniach. Termin usuniety z listy moze mieć przypisane artykuly,
	 * a ciche skasowanie go zabraloby im kategorie. Osierocony przycisk
	 * z zerem artykulow klient usuwa sam w kokpicie — to jego decyzja,
	 * nie nasza.
	 *
	 * @return void
	 */
	public static function ensure_topics(): void {
		$kategorie = Settings::get( 'categories' );

		if ( ! is_array( $kategorie ) || array() === $kategorie ) {
			return;
		}

		$odcisk = md5( wp_json_encode( $kategorie ) );

		if ( get_option( self::OPTION_TOPICS_SEEDED ) === $odcisk ) {
			return;
		}

		$wszystkie_powstaly = true;

		foreach ( $kategorie as $nazwa ) {
			$nazwa = trim( (string) $nazwa );

			if ( '' === $nazwa || term_exists( $nazwa, self::TAX ) ) {
				continue;
			}

			$wynik = wp_insert_term( $nazwa, self::TAX );

			/*
			 * Wynik SPRAWDZANY — ten sam plik sprawdza go 400 linii nizej,
			 * w `maybe_insert_demo()`. Kolizja slugu, filtr `pre_insert_term`
			 * innej wtyczki albo blad zapisu daja `WP_Error`, a bez tej galezi
			 * odcisk i tak szedl do opcji: kategoria nie powstawala NIGDY,
			 * a jedyna droga powrotna byla zmiana listy w Ustawieniach,
			 * dajaca inny skrot md5.
			 */
			if ( is_wp_error( $wynik ) ) {
				$wszystkie_powstaly = false;
			}
		}

		/*
		 * ODCISK ZAPISYWANY TYLKO WTEDY, GDY POWSTALY WSZYSTKIE. Inaczej jedna
		 * nieudana proba zamienialaby sie w stan terminalny bez automatycznego
		 * wyjscia. Przy niepowodzeniu nastepny `init` sprobuje jeszcze raz —
		 * i kosztuje to tyle samo co dzis, bo `term_exists()` odsiewa te
		 * kategorie, ktore juz sa.
		 */
		if ( ! $wszystkie_powstaly ) {
			return;
		}

		update_option( self::OPTION_TOPICS_SEEDED, $odcisk );
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
		// `ensure_schema()`, nie samo `create_table()` — aktywacja ma ZAPISAC
		// wersje struktury, inaczej pierwszy `init` przebudowywalby tabele
		// jeszcze raz, tuz po jej zalozeniu.
		self::ensure_schema();

		self::register_content_types();
		flush_rewrite_rules();

		// Kategorie od pierwszego dnia — PO rejestracji taksonomii.
		self::ensure_topics();

		self::note_slug_collision();
		self::maybe_insert_demo();

		/*
		 * Harmonogram NA KONCU aktywacji, nie na poczatku. Tick czyta i zapisuje
		 * tabele `ainp_items`, wiec zdarzenie zaplanowane przed `create_table()`
		 * moze odpalic na wpol zainstalowanej wtyczce, gdyby aktywacja przerwala
		 * sie w polowie.
		 */
		self::schedule_tick();
	}

	/**
	 * Pilnuje, ze powtarzalny tick istnieje — takze po aktualizacji wtyczki.
	 *
	 * Idempotentne: cala robota siedzi w warunku `has_recurring_tick()`, wiec
	 * wolanie tego przy kazdym zadaniu nie planuje niczego drugi raz.
	 *
	 * @return void
	 */
	public static function ensure_schedule(): void {
		self::schedule_tick();
	}

	/**
	 * Powtarzalne zdarzenie `ainp_tick` — cale zrodlo automatyzacji.
	 *
	 * DWIE FUNKCJE, KTORYCH NIE WOLNO POMYLIC: `wp_next_scheduled()` TYLKO
	 * SPRAWDZA, czy zdarzenie o tym uchwycie jest w harmonogramie, i sama
	 * niczego nie planuje. Powtarzalnosc ustawia wylacznie `wp_schedule_event()`.
	 * Pomylenie ich daje cron, ktory nigdy nie zatyka — wtyczka wyglada na
	 * dzialajaca, a portal milczy.
	 *
	 * Warunek wejscia jest MOCNIEJSZY niz samo „cos jest zaplanowane": pytamy
	 * o zdarzenie POWTARZALNE. Inaczej pojedyncze zdarzenie z etapu 4.6
	 * (pierwszy przebieg po zapisaniu Ustawien) przeslanialoby brak harmonogramu
	 * i wtyczka zostalaby bez cyklu az do nastepnej reaktywacji.
	 *
	 * @return void
	 */
	private static function schedule_tick(): void {
		if ( ! function_exists( 'wp_schedule_event' ) || ! function_exists( 'wp_next_scheduled' ) ) {
			return;
		}

		if ( self::has_recurring_tick() ) {
			return;
		}

		wp_schedule_event( time(), self::CRON_RECURRENCE, self::CRON_HOOK );
	}

	/**
	 * Czy w harmonogramie stoi juz POWTARZALNY `ainp_tick`.
	 *
	 * Skan CALEJ tablicy crona, nie zdarzenia najblizszego: `wp_get_scheduled_event()`
	 * oddaje zdarzenie NAJBLIZSZE, a zapis Ustawien stawia zdarzenie POJEDYNCZE
	 * „na juz" (pierwszy przebieg po zapisie). Przez ~5/6 kazdej godziny ten
	 * singiel stoi PRZED powtarzalnym — pytanie o najblizsze widzialoby wtedy
	 * `schedule=false` i kazdy zapis Ustawien dokladalby KOLEJNY harmonogram,
	 * a duplikaty mnoza zuzycie dobowej puli wywolan AI (ustalenie K1, audyt 8.10).
	 *
	 * Sciezka odwrotu jest zachowawcza: gdy tablicy nie da sie obejrzec
	 * (`_get_cron_array()` nie istnieje albo oddaje nie-tablice, jak stare WP),
	 * zostaje sama odpowiedz „cos jest zaplanowane" i wtedy NIE planujemy drugi
	 * raz. Duplikat harmonogramu jest gorszy niz jego brak — brak widac od razu
	 * na portalu.
	 *
	 * @return bool
	 */
	private static function has_recurring_tick(): bool {
		if ( false === wp_next_scheduled( self::CRON_HOOK ) ) {
			return false;
		}

		if ( ! function_exists( '_get_cron_array' ) ) {
			return true;
		}

		$crony = _get_cron_array();

		if ( ! is_array( $crony ) ) {
			return true;
		}

		foreach ( $crony as $zdarzenia ) {
			if ( ! is_array( $zdarzenia ) || ! isset( $zdarzenia[ self::CRON_HOOK ] ) || ! is_array( $zdarzenia[ self::CRON_HOOK ] ) ) {
				continue;
			}

			foreach ( $zdarzenia[ self::CRON_HOOK ] as $wpis ) {
				if ( is_array( $wpis ) && ! empty( $wpis['schedule'] ) ) {
					return true;
				}
			}
		}

		return false;
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
	private static function create_table(): bool {
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

		/*
		 * `dbDelta()` nie zwraca powodzenia — oddaje liste wykonanych zmian
		 * i milczy o bledach. Jedyny uczciwy dowod, ze struktura istnieje, to
		 * zapytanie o nia bazy. Bez tego `ensure_schema()` podnosiloby wersje
		 * po nieudanej przebudowie i wiecej by jej nie ponowilo — dokladnie ta
		 * wada, ktora naprawialismy we wtyczce 1 (RAU-R05-002, faza F2).
		 */
		$istnieje = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB

		return ( (string) $istnieje === (string) $table );
	}

	/**
	 * Domkniecie struktury tabeli POZA aktywacja — ustalenie audytowe A1.
	 *
	 * Ten sam powod, co przy harmonogramie i kategoriach:
	 * `register_activation_hook` NIE odpala sie przy podmianie plikow, a tak
	 * wyglada kazda aktualizacja u klienta. Bez tej sciezki zmiana definicji
	 * tabeli miedzy wydaniami nigdy nie dotarlaby do dzialajacej instalacji.
	 *
	 * Koszt przy zgodnej wersji to odczyt JEDNEJ autoladowanej opcji — tabela
	 * nie jest dotykana.
	 *
	 * WERSJA ROSNIE DOPIERO PO UDANEJ PRZEBUDOWIE. Zapis wersji przed
	 * sprawdzeniem wyniku zamienilby jedna nieudana przebudowe w stan
	 * terminalny: kolejne zadania wychodzilyby na porownaniu wersji i nigdy
	 * nie sprobowaly ponownie.
	 *
	 * @return void
	 */
	public static function ensure_schema(): void {
		if ( get_option( self::OPTION_DB_VERSION ) === self::DB_VERSION ) {
			return;
		}

		if ( ! self::create_table() ) {
			return;
		}

		update_option( self::OPTION_DB_VERSION, self::DB_VERSION );
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
	 * Slad po artykule, ktory dostal adres z przyrostkiem (RAU-R07-006).
	 *
	 * Rdzen WordPressa rozstrzyga kolizje sam, przez `wp_unique_post_slug()` —
	 * zakaz AWA-W2-23 („nie wolno utworzyc drugiego wpisu o tym samym slugu")
	 * jest wiec dotrzymany i wpis publikuje sie poprawnie. Brakowalo wylacznie
	 * SLADU: wlasciciel nie dostawal zadnego sygnalu, ze artykul mieszka pod
	 * adresem `tytul-2`, a nie `tytul`.
	 *
	 * Kolizje poznajemy po tym, ze zapisany `post_name` jest DLUZSZY od slugu
	 * wyliczonego z tytulu i zaczyna sie od niego. Sam brak zgodnosci nie
	 * wystarcza: tytul z samych znakow specjalnych daje pusty slug, a rdzen
	 * podstawia wtedy identyfikator wpisu — i to nie jest kolizja.
	 *
	 * @param int    $post_id Identyfikator wpisu.
	 * @param string $tytul   Tytul, z ktorego powstal slug.
	 *
	 * @return bool Czy odnotowano kolizje.
	 */
	public static function note_article_slug_collision( int $post_id, string $tytul ): bool {
		$oczekiwany = sanitize_title( $tytul );

		if ( '' === $oczekiwany ) {
			return false;
		}

		$faktyczny = (string) get_post_field( 'post_name', $post_id );

		if ( '' === $faktyczny || $faktyczny === $oczekiwany ) {
			return false;
		}

		if ( 0 !== strpos( $faktyczny, $oczekiwany . '-' ) ) {
			return false;
		}

		$sygnal = get_option( self::OPTION_SLUG_COLLISION );
		$sygnal = self::collision_shape( $sygnal );

		$sygnal['articles'][] = array(
			'post_id' => $post_id,
			'slug'    => $faktyczny,
		);

		// Sygnal diagnostyczny, nie dziennik: trzymamy kilka ostatnich kolizji,
		// zeby opcja nie rosla bez konca na witrynie z setkami artykulow.
		$sygnal['articles'] = array_slice( $sygnal['articles'], -self::SLUG_COLLISION_KEEP );

		update_option( self::OPTION_SLUG_COLLISION, $sygnal );

		return true;
	}

	/**
	 * Sprowadza sygnal kolizji do jednego ksztaltu.
	 *
	 * Do wersji 1.0.0 opcja trzymala GOLA LICZBE — identyfikator kolidujacej
	 * Strony. Instalacje, ktore ja tam maja, musza dalej dzialac, wiec stara
	 * wartosc jest tlumaczona, a nie odrzucana.
	 *
	 * @param mixed $sygnal Wartosc z opcji.
	 *
	 * @return array{page_id:int,articles:array<int,array{post_id:int,slug:string}>}
	 */
	private static function collision_shape( $sygnal ): array {
		if ( is_array( $sygnal ) ) {
			return array(
				'page_id'  => isset( $sygnal['page_id'] ) ? (int) $sygnal['page_id'] : 0,
				'articles' => isset( $sygnal['articles'] ) && is_array( $sygnal['articles'] )
					? array_values( $sygnal['articles'] )
					: array(),
			);
		}

		return array(
			'page_id'  => (int) $sygnal,
			'articles' => array(),
		);
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
		$sygnal   = self::collision_shape( get_option( self::OPTION_SLUG_COLLISION, 0 ) );
		$page_id  = $sygnal['page_id'];
		$artykuly = $sygnal['articles'];

		if ( $page_id <= 0 && array() === $artykuly ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		delete_option( self::OPTION_SLUG_COLLISION );

		/*
		 * Kolizja slugu ARTYKULU (RAU-R07-006) — osobny komunikat, bo to inna
		 * sytuacja niz kolizja archiwum: wpis powstal i publikuje sie poprawnie,
		 * tylko pod adresem z przyrostkiem. Do wersji 1.0.0 wlasciciel nie
		 * dostawal o tym zadnego sygnalu.
		 */
		if ( array() !== $artykuly ) {
			$lista = array();

			foreach ( $artykuly as $wpis ) {
				$lista[] = sprintf(
					'<li><a href="%s">%s</a></li>',
					esc_url( (string) get_edit_post_link( (int) ( $wpis['post_id'] ?? 0 ) ) ),
					esc_html( (string) ( $wpis['slug'] ?? '' ) )
				);
			}

			printf(
				'<div class="notice notice-warning"><p><strong>%s</strong> %s</p><ul>%s</ul></div>',
				esc_html__( 'AI News Portal:', 'ai-news-portal' ),
				esc_html(
					_n(
						'jeden artykuł dostał adres z przyrostkiem, bo adres wynikający z tytułu był już zajęty:',
						'artykuły poniżej dostały adresy z przyrostkiem, bo adresy wynikające z ich tytułów były już zajęte:',
						count( $artykuly ),
						'ai-news-portal'
					)
				),
				wp_kses_post( implode( '', $lista ) )
			);
		}

		if ( $page_id <= 0 ) {
			return;
		}

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
