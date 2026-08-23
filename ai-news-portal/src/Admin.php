<?php
/**
 * Panel wtyczki: dokladnie DWIE pozycje w menu.
 *
 * ETAP 1.6 dal szkielet nawigacji, ETAP 2.5 zapelnia oba ekrany: tabela
 * Materialow, przycisk „Pobierz teraz" i formularz Ustawien ze zrodlami RSS.
 * Przycisk „Przetworz teraz" i licznik wywolan AI dochodza w Kroku 4, bo
 * dopiero tam pojawia sie model.
 *
 * CPT nie ma wlasnej pozycji w menu (`show_in_menu => false`), ale jego ekrany
 * dzialaja. Hurtowe zarzadzanie artykulami idzie przez `edit.php?post_type=ainp_article`
 * i link do tego adresu jest tu od poczatku — bez niego nie da sie usunac
 * kilkunastu slabych artykulow naraz.
 *
 * Kazda akcja panelu przechodzi przez `guard()`: najpierw uprawnienie, potem
 * nonce. Akcja bez poprawnego nonce'a ma zostac odrzucona BEZ ANI JEDNEJ
 * zmiany w bazie — to jeden z testow odbiorczych planu.
 *
 * @package AI_News_Portal
 */

namespace AINP;

// Blokada bezposredniego wywolania pliku.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Menu, ekrany i akcje kokpitu.
 */
final class Admin {

	/*
	 * Ekrany kokpitu — `src/Admin_Screen.php` (etap 8.1). Metody `render_*`
	 * i `recent_items()` sa nadal metodami TEJ klasy: `Admin::render_items()`
	 * dziala tak samo jak przed podzialem i tak samo jest wpisana w menu.
	 */
	use Admin_Screen;

	/** Uprawnienie wymagane przez KAZDY ekran i KAZDA akcje wtyczki. */
	public const CAP = 'manage_options';

	/** Slug ekranu „Materialy" — zarazem slug pozycji nadrzednej. */
	public const SLUG_ITEMS = 'ainp-items';

	/** Slug ekranu „Ustawienia". */
	public const SLUG_SETTINGS = 'ainp-settings';

	/** Akcja `admin_post_`: pobranie kanalow na zadanie. */
	public const ACTION_FETCH = 'ainp_fetch';

	/** Akcja `admin_post_`: zapis ustawien. */
	public const ACTION_SAVE = 'ainp_save_settings';

	/**
	 * Akcja `admin_post_`: przygotowanie tresci na zadanie (etap 3.6).
	 *
	 * Przycisk jest tymczasowy w tym sensie, ze w Kroku 5 te sama prace
	 * odpali cron. Zostaje jednak w panelu na stale: bez niego na stronie
	 * bez ruchu nie da sie ruszyc przetwarzania z reki.
	 */
	public const ACTION_PREPARE = 'ainp_prepare';

	/**
	 * Akcja `admin_post_`: wyslanie partii do modelu i publikacja (etap 4.6).
	 *
	 * Tak jak „Przygotuj treści" — w Kroku 5 te sama prace odpali cron,
	 * ale przycisk zostaje: na stronie bez ruchu WP-Cron nie chodzi, wiec
	 * bez niego nie da sie ruszyc publikacji z reki.
	 */
	public const ACTION_PUBLISH = 'ainp_publish';

	/**
	 * Akcja `admin_post_`: wznowienie pozycji `failed` (etap 5.4).
	 *
	 * Osobna akcja, a nie parametr istniejacej: wznowienie zmienia stan tabeli
	 * i musi miec wlasny nonce. Nonce wystawiony na „Opublikuj teraz" nie ma
	 * prawa przepuszczac niczego innego.
	 */
	public const ACTION_RETRY = 'ainp_retry';

	/** Nazwa pola z nonce'em — wspolna dla wszystkich akcji. */
	public const NONCE_FIELD = '_ainp_nonce';

	/** Ile pozycji pokazuje tabela Materialow. */
	public const ITEMS_LIMIT = 50;

	/**
	 * Prefiks transientu z podsumowaniem ostatniego pobrania.
	 *
	 * MUSI zaczynac sie od `ainp_` — `uninstall.php` zamiata transienty
	 * wzorcem `_transient_ainp_%`.
	 */
	public const TRANSIENT_RUN = 'ainp_last_run_';

	/** Prefiks transientu z podsumowaniem ostatniego przygotowania tresci. */
	public const TRANSIENT_PREP = 'ainp_last_prep_';

	/** Prefiks transientu z podsumowaniem ostatniej publikacji. */
	public const TRANSIENT_PUB = 'ainp_last_pub_';

	/**
	 * Slad po ostatnim AUTOMATYCZNYM przebiegu — bez sufiksu uzytkownika.
	 *
	 * Pozostale transienty sa per uzytkownik, bo dotycza akcji, ktora ktos
	 * kliknal. Tick nie nalezy do nikogo — i ma go zobaczyc kazdy, kto wejdzie
	 * na ekran, a nie tylko ten, kto akurat byl zalogowany o pelnej godzinie.
	 */
	public const TRANSIENT_TICK = 'ainp_last_tick';

	/** Prefiks transientu z wynikiem ostatniego wznowienia. */
	public const TRANSIENT_RETRY = 'ainp_last_retry_';

	/**
	 * Zamek na czas przebiegu — dawny DLUG D-8, domkniety naprawa A5.
	 *
	 * Zamek jest WSPOLNY dla calej witryny, bez identyfikatora uzytkownika:
	 * chodzi o to, ze ta sama partia wierszy nie ma byc scrapowana dwa razy,
	 * a wiersze nie naleza do nikogo.
	 *
	 * ZAKRES TEGO ROZWIAZANIA JEST SWIADOMIE MALY. Zamyka najczestszy przypadek
	 * z zycia — dwuklik w „Przygotuj treści" i odswiezenie strony w trakcie —
	 * i tylko tyle. Nie jest to przejecie atomowe: miedzy odczytem a zapisem
	 * transientu jest okno, w ktore da sie wejsc dwoma zadaniami rownoczesnie.
	 * Prawdziwe przejecie pozycji (`UPDATE … SET status='taken' WHERE
	 * status='new'`) nalezy do etapu 5.2, razem z tickiem crona, ktory jest
	 * jedynym miejscem, gdzie rownoczesnosc powstaje sama z siebie.
	 */
	public const OPTION_LOCK = 'ainp_run_lock';

	/**
	 * Zycie zamka w sekundach.
	 *
	 * Musi przezyc najdluzsza mozliwa partie, ale nie wiecej: proces zabity
	 * przez `max_execution_time` nie zdazy zamka zdjac, a zamek, ktory zostal
	 * po trupie, blokuje przycisk do konca swojego zycia. 120 s to z zapasem
	 * ponad `Runner::PREPARE_BUDGET` (15 s) plus jedno pobranie strony.
	 */
	public const LOCK_TTL = 120;

	/**
	 * Znacznik zamka, ktory trzyma TEN przebieg. Pusty = nie trzymamy nic.
	 *
	 * Istnieje po to, zeby `release_lock()` zdejmowal WYLACZNIE swoj zamek —
	 * patrz opis tamtej metody. Wlasnosc procesu, nie witryny, wiec zyje
	 * w pamieci zadania, a nie w bazie.
	 *
	 * @var string
	 */
	private static $lock_token = '';

	/**
	 * Okno, w ktorym „tick jest juz nalezny" — 10 minut (etap 5.1).
	 *
	 * Tyle samo, ile wynosi wlasna ochrona WordPressa przed duplikatem
	 * w `wp_schedule_single_event()`: zdarzenie zaplanowane blizej niz 10 minut
	 * od istniejacego i tak zostaloby odrzucone przez rdzen. Trzymanie tej samej
	 * liczby po naszej stronie sprawia, ze warunek w kodzie mowi to samo,
	 * co zrobi WordPress — zamiast planowac zdarzenie, ktore ginie po cichu.
	 */
	public const FIRST_RUN_WINDOW = 600;

	// -----------------------------------------------------------------------
	// Rejestracja
	// -----------------------------------------------------------------------

	/**
	 * Rejestracja menu. Wolane na `admin_menu`.
	 *
	 * Pozycja nadrzedna dzieli slug z pierwszym podmenu — bez tego WordPress
	 * dolozylby trzecia pozycje, powtarzajaca nazwe wtyczki.
	 *
	 * @return void
	 */
	public static function register_menu(): void {
		add_menu_page(
			'AI News Portal',
			'AI News Portal',
			self::CAP,
			self::SLUG_ITEMS,
			array( self::class, 'render_items' ),
			'dashicons-rss',
			26
		);

		add_submenu_page(
			self::SLUG_ITEMS,
			__( 'Materiały', 'ai-news-portal' ),
			__( 'Materiały', 'ai-news-portal' ),
			self::CAP,
			self::SLUG_ITEMS,
			array( self::class, 'render_items' )
		);

		add_submenu_page(
			self::SLUG_ITEMS,
			__( 'Ustawienia', 'ai-news-portal' ),
			__( 'Ustawienia', 'ai-news-portal' ),
			self::CAP,
			self::SLUG_SETTINGS,
			array( self::class, 'render_settings' )
		);
	}

	/**
	 * Podpiecie akcji formularzy. Wolane z `Plugin::boot()`.
	 *
	 * @return void
	 */
	public static function register_actions(): void {
		add_action( 'admin_post_' . self::ACTION_FETCH, array( self::class, 'handle_fetch' ) );
		add_action( 'admin_post_' . self::ACTION_PREPARE, array( self::class, 'handle_prepare' ) );
		add_action( 'admin_post_' . self::ACTION_PUBLISH, array( self::class, 'handle_publish' ) );
		add_action( 'admin_post_' . self::ACTION_RETRY, array( self::class, 'handle_retry' ) );
		add_action( 'admin_post_' . self::ACTION_SAVE, array( self::class, 'handle_save_settings' ) );
	}

	// -----------------------------------------------------------------------
	// Akcje
	// -----------------------------------------------------------------------

	/**
	 * „Pobierz teraz" — jeden przebieg zbierania na zadanie czlowieka.
	 *
	 * @return void
	 */
	public static function handle_fetch(): void {
		self::guard( self::ACTION_FETCH );

		$podsumowanie = Runner::collect();

		/*
		 * Podsumowanie idzie do transientu, nie do adresu. Adres z osmioma
		 * licznikami jest brzydki, a przy dluzszej liscie bledow po prostu
		 * sie nie miesci.
		 */
		set_transient( self::TRANSIENT_RUN . get_current_user_id(), $podsumowanie, 300 );

		self::redirect_back( self::SLUG_ITEMS, 'pobrano' );
	}

	/**
	 * „Przygotuj treści" — jedna partia pozycji na zadanie czlowieka.
	 *
	 * ETAP 3.6. Praca jest ta sama, ktora w Kroku 5 przejmie cron: filtr,
	 * scraping przy zbyt krotkiej tresci, ekstrakcja, oczyszczenie i odcisk
	 * tresci. Partia jest maleńka (10 pozycji), bo kazda moze oznaczac jedno
	 * zadanie sieciowe, a przegladarka nie ma na to czekac w nieskonczonosc.
	 *
	 * @return void
	 */
	public static function handle_prepare(): void {
		self::guard( self::ACTION_PREPARE );

		/*
		 * Zamek PRZED praca, zdejmowany w `finally`. Bez `finally` wyjatek
		 * w srodku partii zostawialby zamek na cale `LOCK_TTL` i klient
		 * mialby przycisk martwy przez dwie minuty bez zadnego wyjasnienia.
		 */
		if ( ! self::claim_lock() ) {
			self::redirect_back( self::SLUG_ITEMS, 'zajete' );
		}

		try {
			/*
			 * ODZYSK PORZUCONYCH POZYCJI — ustalenie audytowe P2.
			 *
			 * Do tej pory odzysk zyl wylacznie w ticku, wiec wiersz zostawiony
			 * w `processing` przez zabity proces byl niewidoczny dla OBU zapytan
			 * wybierajacych az do nastepnej godziny — a klient, ktory wlasnie klika,
			 * widzial pusty przebieg bez jednego slowa wyjasnienia.
			 *
			 * Po zamku, nie przed: proces, ktory zamka nie dostal, i tak nic nie
			 * zrobi, wiec nie ma po co ruszac bazy. Po `guard()`, wiec zadanie bez
			 * uprawnienia albo bez nonce'a nadal nie wysyla ANI JEDNEGO zapytania.
			 */
			Runner::recover_stalled();

			$podsumowanie = Runner::prepare_batch();
		} finally {
			self::release_lock();
		}

		set_transient( self::TRANSIENT_PREP . get_current_user_id(), $podsumowanie, 300 );

		self::redirect_back( self::SLUG_ITEMS, 'przygotowano' );
	}

	/**
	 * „Opublikuj teraz" — partia pozycji idzie do modelu i na portal.
	 *
	 * Ten sam zamek co przy przygotowaniu tresci, i tu jest wazniejszy niz tam:
	 * dwuklik w przygotowanie kosztuje zadania sieciowe, a dwuklik TUTAJ
	 * kosztuje wywolania z dobowej puli 20. Atomowa rezerwacja slotu (etap 4.1)
	 * pilnuje, ze pula nie zostanie przekroczona, ale nie zatrzyma dwoch partii
	 * naraz — od tego jest zamek.
	 *
	 * @return void
	 */
	public static function handle_publish(): void {
		self::guard( self::ACTION_PUBLISH );

		if ( ! self::claim_lock() ) {
			self::redirect_back( self::SLUG_ITEMS, 'zajete' );
		}

		try {
			/*
			 * ODZYSK PORZUCONYCH POZYCJI — ustalenie audytowe P2.
			 *
			 * Do tej pory odzysk zyl wylacznie w ticku, wiec wiersz zostawiony
			 * w `processing` przez zabity proces byl niewidoczny dla OBU zapytan
			 * wybierajacych az do nastepnej godziny — a klient, ktory wlasnie klika,
			 * widzial pusty przebieg bez jednego slowa wyjasnienia.
			 *
			 * Po zamku, nie przed: proces, ktory zamka nie dostal, i tak nic nie
			 * zrobi, wiec nie ma po co ruszac bazy. Po `guard()`, wiec zadanie bez
			 * uprawnienia albo bez nonce'a nadal nie wysyla ANI JEDNEGO zapytania.
			 */
			Runner::recover_stalled();

			$podsumowanie = Runner::publish_batch();
		} finally {
			// `finally`, bo wyjatek w polowie partii zostawilby zamek na cale
			// `LOCK_TTL` i przycisk bylby martwy przez dwie minuty bez slowa.
			self::release_lock();
		}

		set_transient( self::TRANSIENT_PUB . get_current_user_id(), $podsumowanie, 300 );

		self::redirect_back( self::SLUG_ITEMS, 'opublikowano' );
	}

	/**
	 * Bierze zamek na przebieg — ATOMOWO. Patrz `OPTION_LOCK`.
	 *
	 * @return bool `true`, gdy zamek zostal wziety; `false`, gdy trzyma go
	 *              inne zadanie.
	 */
	public static function claim_lock(): bool {
		global $wpdb;

		$teraz = self::stamp();

		/*
		 * O ZAMEK PYTA BAZA, NIE KOD — ta sama zasada, ktora rzadzi dedupem
		 * pozycji. `INSERT IGNORE` na kolumnie z kluczem UNIQUE (`option_name`)
		 * przechodzi dla DOKLADNIE jednego procesu; pozostale dostaja zero
		 * zmienionych wierszy. Poprzednia wersja czytala transient i dopiero
		 * potem go zapisywala — w okno miedzy tymi dwoma krokami cron wchodzil
		 * SAM, co godzine i bez niczyjego udzialu (ustalenie audytowe A5).
		 *
		 * Nie uzywamy `add_option()` mimo pozornego podobienstwa: broni sie
		 * NIEATOMOWO (odczyt, potem zapis), co jest osobnym, znanym dlugiem U6.
		 * Zapis wprost do tabeli omija tez cache opcji, ktory przy zamku bylby
		 * czysta szkoda.
		 */
		$wziety = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'no' )",
				self::OPTION_LOCK,
				$teraz
			)
		); // phpcs:ignore WordPress.DB

		if ( 1 === (int) $wziety ) {
			self::$lock_token = $teraz;

			return true;
		}

		/*
		 * Zamek juz jest. Zostaje pytanie, czy trzyma go proces, ktory zyje —
		 * proces zabity przez `max_execution_time` nie zdazyl go zdjac, a zamek
		 * po trupie blokowalby przyciski i tick az do konca swiata.
		 *
		 * Przejecie idzie przez CAS: warunek `option_value = <stara wartosc>`
		 * sprawia, ze przy dwoch procesach czekajacych na ten sam wygasly zamek
		 * przejmie go dokladnie jeden. To ta sama konstrukcja, co przy rezerwacji
		 * slotu z dobowej puli AI.
		 */
		$stara = $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPTION_LOCK )
		); // phpcs:ignore WordPress.DB

		if ( null === $stara || ( time() - (int) $stara ) < self::LOCK_TTL ) {
			return false;
		}

		$przejety = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$teraz,
				self::OPTION_LOCK,
				(string) $stara
			)
		); // phpcs:ignore WordPress.DB

		if ( 1 === (int) $przejety ) {
			self::$lock_token = $teraz;

			return true;
		}

		return false;
	}

	/**
	 * Znacznik jednego przebiegu: czas plus czesc niepowtarzalna.
	 *
	 * Czas MUSI stac z przodu — `claim_lock()` czyta wiek zamka przez
	 * `(int) $stara`, wiec rzutowanie ma odczytac sekundy i nic wiecej. Reszta
	 * jest po to, zeby dwa przebiegi startujace w tej samej sekundzie mialy
	 * ROZNE znaczniki: bez tego ochrona z `release_lock()` bylaby pozorna
	 * dokladnie w tej sytuacji, dla ktorej powstala.
	 *
	 * Zamek zapisany przez starsza wersje wtyczki jest golym znacznikiem czasu
	 * i nadal daje sie odczytac — `(int)` zachowuje sie tak samo.
	 *
	 * @return string
	 */
	private static function stamp(): string {
		return time() . '.' . uniqid( '', true );
	}

	/**
	 * Zdejmuje zamek — WYLACZNIE swoj wlasny.
	 *
	 * WARUNEK `option_value` JEST TU CALYM MECHANIZMEM, nie ostroznoscia.
	 * Bez niego dzialo sie tak: proces A bral zamek i wisial dluzej niz
	 * `LOCK_TTL`; proces B przejmowal zamek po trupie (to jest zachowanie
	 * zamierzone); A koncowo dochodzil do swojego `finally` i kasowal wiersz —
	 * czyli ZAMEK PROCESU B. Trzeci przebieg wchodzil wtedy w partie, ktora
	 * B wlasnie mielil: dwa razy ten sam scraping i dwa sloty z dobowej puli 20
	 * na jeden artykul. Zmierzone na prawdziwym kodzie zanim powstala ta linia.
	 *
	 * Pusty znacznik znaczy „nie mamy zamka" i konczy sprawe bez zapytania.
	 * Zamek po trupie nie zostaje przez to na zawsze — od tego jest przejecie
	 * po `LOCK_TTL` w `claim_lock()`.
	 *
	 * @return void
	 */
	public static function release_lock(): void {
		global $wpdb;

		if ( '' === self::$lock_token ) {
			return;
		}

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				self::OPTION_LOCK,
				self::$lock_token
			)
		); // phpcs:ignore WordPress.DB

		self::$lock_token = '';
	}

	/**
	 * Zapis Ustawien.
	 *
	 * @return void
	 */
	public static function handle_retry(): void {
		self::guard( self::ACTION_RETRY );

		$wznowione = Runner::revive_failed();

		set_transient( self::TRANSIENT_RETRY . get_current_user_id(), $wznowione, 300 );

		self::redirect_back( self::SLUG_ITEMS, 'wznowiono' );
	}

	/**
	 * Zapis Ustawien.
	 *
	 * @return void
	 */
	public static function handle_save_settings(): void {
		self::guard( self::ACTION_SAVE );

		// `$_POST` przychodzi ze slashami dodanymi przez WordPressa.
		$dane = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce sprawdza guard().

		update_option(
			Settings::OPTION_SOURCES,
			self::parse_sources( isset( $dane['ainp_sources'] ) ? (string) $dane['ainp_sources'] : '' )
		);

		self::save_key( isset( $dane['ainp_key'] ) ? (string) $dane['ainp_key'] : '' );

		Settings::update( self::sanitize_settings( is_array( $dane ) ? $dane : array() ) );

		self::schedule_first_run();

		self::redirect_back( self::SLUG_SETTINGS, 'zapisano' );
	}

	/**
	 * Planuje pierwszy przebieg na JUZ, zaraz po zapisaniu Ustawien.
	 *
	 * Trzy linie, a dzieki nim pierwszy prawdziwy artykul powstaje bez klikania
	 * „Pobierz teraz" i „Opublikuj teraz" — klient wpisuje klucz, zapisuje
	 * i portal zaczyna sam.
	 *
	 * TRZY WARUNKI, KAZDY Z POWODU:
	 *
	 *   1. Klucz i zrodla MUSZA byc. Przebieg bez klucza konczy sie odmowa
	 *      przy pierwszej pozycji, a bez zrodel nie ma czego pobierac —
	 *      planowanie go byloby samym halasem w harmonogramie.
	 *   2. `wp_next_scheduled()` przed planowaniem, zeby dziesiec zapisow
	 *      Ustawien pod rzad nie zrobilo dziesieciu zdarzen. Ta funkcja TYLKO
	 *      SPRAWDZA — sama niczego nie planuje.
	 *   3. `wp_schedule_single_event()`, nie `wp_schedule_event()`: to jest
	 *      pojedynczy start, a nie harmonogram. Powtarzalny `ainp_tick` zaklada
	 *      aktywacja (etap 5.1) i to `Plugin` jest jego wlascicielem.
	 *
	 * ZMIANA Z ETAPU 5.1: warunek pyta o tick NALEZNY W CIAGU `FIRST_RUN_WINDOW`,
	 * nie o „jakiekolwiek zaplanowane zdarzenie". Od chwili, gdy aktywacja
	 * planuje powtarzalny `ainp_tick`, dawny warunek byl spelniony ZAWSZE —
	 * pierwszy przebieg po zapisaniu Ustawien przestalby powstawac po cichu,
	 * a klient czekalby na artykul do konca biezacej godziny zamiast sekundy.
	 * Nowy warunek nadal blokuje dziesiec zapisow pod rzad: pojedyncze zdarzenie
	 * zaplanowane na „juz" samo miesci sie w oknie.
	 *
	 * @return void
	 */
	private static function schedule_first_run(): void {
		if ( ! function_exists( 'wp_schedule_single_event' ) || ! function_exists( 'wp_next_scheduled' ) ) {
			return;
		}

		$klucz = trim( (string) get_option( Settings::OPTION_KEY, '' ) );
		if ( '' === $klucz || array() === Runner::sources() ) {
			return;
		}

		$najblizszy = wp_next_scheduled( Plugin::CRON_HOOK );

		if ( false !== $najblizszy && ( (int) $najblizszy - time() ) <= self::FIRST_RUN_WINDOW ) {
			return;
		}

		wp_schedule_single_event( time(), Plugin::CRON_HOOK );
	}

	/**
	 * Zapisuje klucz API — w osobnej opcji, ZAWSZE bez autoladowania.
	 *
	 * Trzy wlasnosci, ktorych nie wolno zgubic:
	 *
	 *   1. PUSTE POLE NIE KASUJE KLUCZA. Formularz nigdy nie pokazuje klucza
	 *      z powrotem, wiec puste pole znaczy „nie zmieniam", a nie „usun".
	 *      Bez tego kazdy zapis Ustawien kasowalby klucz i wtyczka przestawalaby
	 *      dzialac po zmianie czegokolwiek innego.
	 *   2. KASOWANIE JEST JAWNE — przez zaznaczenie pola wyboru.
	 *   3. `autoload = no`. Klucz nie ma prawa siedziec w `alloptions`
	 *      ladowanych przy KAZDYM zadaniu, takze na stronie publicznej.
	 *      `update_option()` na istniejacej opcji autoladowania NIE zmienia,
	 *      dlatego przy zmianie klucza kasujemy opcje i zakladamy ja od nowa.
	 *
	 * @param string $klucz Wartosc z formularza.
	 *
	 * @return void
	 */
	private static function save_key( string $klucz ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce sprawdza guard() w handle_save_settings().
		if ( ! empty( $_POST['ainp_key_clear'] ) ) {
			delete_option( Settings::OPTION_KEY );
			return;
		}

		$klucz = trim( sanitize_text_field( $klucz ) );
		if ( '' === $klucz ) {
			return;
		}

		delete_option( Settings::OPTION_KEY );
		add_option( Settings::OPTION_KEY, $klucz, '', false );
	}

	/**
	 * Uprawnienie i nonce. Wolane PRZED jakimkolwiek zapisem.
	 *
	 * @param string $action Nazwa akcji.
	 *
	 * @return void
	 */
	private static function guard( string $action ): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Brak uprawnień do tej operacji.', 'ai-news-portal' ) );
		}

		check_admin_referer( $action, self::NONCE_FIELD );
	}

	/**
	 * Powrot na ekran wtyczki z informacja o wyniku.
	 *
	 * @param string $slug   Slug ekranu.
	 * @param string $status Wartosc parametru `ainp_status`.
	 *
	 * @return void
	 */
	private static function redirect_back( string $slug, string $status ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => $slug,
					'ainp_status' => $status,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	// -----------------------------------------------------------------------
	// Sanityzacja wejscia
	// -----------------------------------------------------------------------

	/**
	 * Zamienia zawartosc pola tekstowego na liste adresow kanalow.
	 *
	 * Jeden adres na linie. Cokolwiek nie jest adresem http(s), wypada —
	 * `Runner::clean_sources()` jest tu jedynym sedzia, zeby zapisana lista
	 * nie mogla roznic sie od tej, ktora naprawde zostanie pobrana.
	 *
	 * @param string $tekst Zawartosc pola.
	 *
	 * @return array<int,string>
	 */
	public static function parse_sources( string $tekst ): array {
		$linie   = preg_split( '/\r\n|\r|\n/', $tekst );
		$adresy  = array();

		foreach ( (array) $linie as $linia ) {
			$linia = trim( (string) $linia );

			if ( '' === $linia ) {
				continue;
			}

			$adresy[] = esc_url_raw( $linia );
		}

		return Runner::clean_sources( $adresy );
	}

	/**
	 * Buduje latke do `ainp_settings` z danych formularza.
	 *
	 * DLUG Z KROKU 1, SPLACANY TUTAJ. Znacznik `ainp_demo_done` mieszka
	 * wewnatrz `ainp_settings`, a `Plugin::maybe_insert_demo()` porownuje go
	 * SCISLE (`true ===`). Dlatego:
	 *
	 *   - zapis idzie przez `Settings::update()`, ktory SCALA latke z calascia,
	 *     zamiast nadpisywac cala opcje goły `update_option()`,
	 *   - latka zawiera wylacznie pola z formularza, a znacznik jest z niej
	 *     jawnie usuwany, zeby nie dalo sie go podstawic z zewnatrz,
	 *   - przelacznik „zapisuj jako szkice" wraca jako `true`/`false`, nigdy
	 *     jako `1` z pola wyboru.
	 *
	 * Bez tego przy najblizszej aktywacji powstalby DRUGI artykul demo.
	 *
	 * @param array<string,mixed> $dane Odslashowane `$_POST`.
	 *
	 * @return array<string,mixed>
	 */
	public static function sanitize_settings( array $dane ): array {
		$domyslne = Settings::defaults();

		$model = isset( $dane['ainp_model'] ) ? sanitize_text_field( (string) $dane['ainp_model'] ) : '';
		$sufit = isset( $dane['ainp_daily_cap'] ) ? (int) $dane['ainp_daily_cap'] : 0;

		$latka = array(
			'model'          => ( '' !== $model ) ? $model : (string) $domyslne['model'],
			// Sufit ponizej 1 wylaczylby przetwarzanie po cichu; gorna granica
			// chroni przed literowka w rodzaju „200" przy darmowej puli 20.
			'daily_cap'      => max( 1, min( 1000, $sufit ) ),
			'save_as_draft'  => ! empty( $dane['ainp_save_as_draft'] ),
			'excluded_words' => isset( $dane['ainp_excluded_words'] )
				? self::parse_list( (string) $dane['ainp_excluded_words'] )
				: $domyslne['excluded_words'],
			/*
			 * Pusta lista slow wymaganych jest DOZWOLONA i znaczy „bez bramki" —
			 * inaczej niz pusta lista kategorii nizej, ktora unieruchomilaby
			 * walidator. Dlatego nie ma tu podstawienia domyslnych po fakcie:
			 * klient, ktory swiadomie wyczyscil pole, ma dostac filtr sprzed
			 * wariantu C, a nie ciche przywrocenie listy.
			 */
			'required_words' => isset( $dane['ainp_required_words'] )
				? self::parse_list( (string) $dane['ainp_required_words'] )
				: $domyslne['required_words'],
			'categories'     => isset( $dane['ainp_categories'] )
				? self::parse_list( (string) $dane['ainp_categories'] )
				: $domyslne['categories'],
			/*
			 * Prompt idzie przez `sanitize_textarea_field()`, a NIE przez
			 * `wp_kses_post()`: to nie jest tresc do wyswietlenia, tylko tekst
			 * do wyslania do modelu, a znaczniki HTML w promptcie sa czescia
			 * instrukcji o dozwolonych znacznikach odpowiedzi. Kses wyciąłby
			 * je razem ze znaczeniem.
			 *
			 * Pusty prompt jest dozwolony przy zapisie i znaczy „wroc do
			 * domyslnego" — `Gemini::prompt()` sam po niego siega. Podstawianie
			 * domyslnego juz tutaj zamroziloby dzisiejsza tresc szablonu
			 * w bazie klienta i odcieloby go od poprawek w kolejnych wersjach.
			 */
			'prompt'         => isset( $dane['ainp_prompt'] )
				? sanitize_textarea_field( (string) $dane['ainp_prompt'] )
				: (string) $domyslne['prompt'],
		);

		// Pusta lista kategorii unieruchomilaby walidator odpowiedzi AI —
		// zaden `topic` nie pasowalby do niczego i kazdy artykul szedlby
		// na `failed`, marnujac wywolania z dobowej puli.
		if ( ! $latka['categories'] ) {
			$latka['categories'] = $domyslne['categories'];
		}

		unset( $latka[ Settings::KEY_DEMO_DONE ] );

		return $latka;
	}

	/**
	 * Lista z pola tekstowego: jedna pozycja na linie albo po przecinku.
	 *
	 * @param string $tekst Zawartosc pola.
	 *
	 * @return array<int,string>
	 */
	private static function parse_list( string $tekst ): array {
		$czesci = preg_split( '/[\r\n,]+/', $tekst );
		$lista  = array();

		foreach ( (array) $czesci as $czesc ) {
			$czesc = sanitize_text_field( trim( (string) $czesc ) );

			if ( '' !== $czesc && ! in_array( $czesc, $lista, true ) ) {
				$lista[] = $czesc;
			}
		}

		return $lista;
	}

}
