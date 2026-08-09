<?php
/**
 * Centrum Wiedzy — wybor szablonu i adresy frontu.
 *
 * ETAP 6.1. Klasa robi dwie rzeczy i nic wiecej:
 *
 *   1. Podstawia szablony wtyczki pod trzy gałezie zapytania (archiwum CPT,
 *      archiwum taksonomii, pojedynczy artykul) — ale DOPIERO wtedy, gdy motyw
 *      nie ma wlasnego szablonu dla tego samego przypadku.
 *   2. Zamiast 404 na golym `/centrum-wiedzy/kategoria/` odsyla na archiwum.
 *
 * Rysowanie tresci nalezy do plikow w `src/templates/` (etapy 6.2 i 6.5).
 * Ta klasa ma tylko WYBRAC plik.
 *
 * @package AI_News_Portal
 */

namespace AINP;

// Blokada bezposredniego wywolania pliku.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Front Centrum Wiedzy: wybor szablonu i przekierowania.
 */
final class Portal {

	/** Katalog szablonow wtyczki, wzgledem `AINP_PLUGIN_DIR`. */
	public const TEMPLATE_DIR = 'src/templates/';

	/**
	 * Czlon adresu miedzy archiwum a nazwa kategorii.
	 *
	 * Rowny temu, co `Plugin::register_content_types()` daje taksonomii jako
	 * `rewrite['slug']` (`centrum-wiedzy/kategoria`). Stala zyje tutaj, bo to
	 * jedyne miejsce, ktore porownuje ten czlon ze sciezka z przegladarki.
	 */
	public const TAX_BASE = 'kategoria';

	/**
	 * Podpiecie hookow frontu. Wolane z `Plugin::boot()`.
	 *
	 * Bezwarunkowo, bez `is_admin()` — z tego samego powodu, co hooki panelu
	 * w `Plugin::boot()`: warunek wokol REJESTRACJI hooka to blad, ktory we
	 * wtyczce 1 kosztowal osobny etap napraw. `template_include` i tak nie
	 * odpala sie w kokpicie.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'template_include', array( self::class, 'filter_template' ) );
		add_action( 'template_redirect', array( self::class, 'redirect_taxonomy_base' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	/**
	 * Arkusz stylow portalu — TYLKO na widokach Centrum Wiedzy. Etap 6.2.
	 *
	 * Warunek nie jest oszczednoscia na bajtach, tylko uprzejmoscia wobec
	 * motywu: styl wtyczki ustawia siatke i typografie kart, a na cudzej
	 * stronie nie ma czego ustawiac. Wersja z `AINP_VERSION` daje przegladarce
	 * sygnal do odswiezenia cache'u przy kazdym wydaniu.
	 *
	 * @return void
	 */
	public static function enqueue(): void {
		if ( ! self::is_portal_view() ) {
			return;
		}

		wp_enqueue_style(
			'ainp-portal',
			AINP_PLUGIN_URL . self::TEMPLATE_DIR . 'portal.css',
			array(),
			AINP_VERSION
		);
	}

	/**
	 * Czy biezace zapytanie nalezy do Centrum Wiedzy.
	 *
	 * @return bool
	 */
	public static function is_portal_view(): bool {
		return null !== self::resolve();
	}

	/**
	 * Podstawia szablon wtyczki, gdy motyw nie ma wlasnego.
	 *
	 * KOLEJNOSC PIERWSZENSTWA — i to jest cala tresc etapu 6.1:
	 *
	 *   motyw potomny  >  motyw nadrzedny  >  szablony wtyczki  >  to, co dal WordPress
	 *
	 * Dwa pierwsze zalatwia `locate_template()`: przeszukuje katalog motywu
	 * potomnego, potem nadrzednego, i zwraca pelna sciezke albo pusty ciag.
	 * Dopiero gdy oddal pusty ciag, wchodzi plik wtyczki.
	 *
	 * DLACZEGO NIE WYSTARCZY SAMA HIERARCHIA WORDPRESSA: gdy motyw nie ma
	 * `archive-ainp_article.php`, WordPress i tak poda tu swoj `archive.php`
	 * albo `index.php`. To jest poprawny szablon — tylko rysuje nasze artykuly
	 * jak zwykle wpisy bloga, bez kart, kategorii i wyszukiwarki. Filtr ma
	 * przejac wlasnie ten przypadek, a nie kazdy.
	 *
	 * OSTATNIA LINIA OBRONY: gdy pliku wtyczki nie ma (a w etapie 6.1 jeszcze
	 * go nie ma — powstaje w 6.2 i 6.5), zwracamy szablon bez zmian. `include`
	 * nieistniejacej sciezki to blad krytyczny, ktory wywraca CALY front,
	 * a nie samo Centrum Wiedzy.
	 *
	 * @param string $template Sciezka szablonu wybrana przez WordPressa.
	 *
	 * @return string
	 */
	public static function filter_template( $template ): string {
		$template = (string) $template;

		$wybor = self::resolve();
		if ( null === $wybor ) {
			return $template;
		}

		list( $kandydaci, $wlasny ) = $wybor;

		$z_motywu = locate_template( $kandydaci );
		if ( is_string( $z_motywu ) && '' !== $z_motywu ) {
			return $z_motywu;
		}

		$sciezka = self::own_template( $wlasny );

		return file_exists( $sciezka ) ? $sciezka : $template;
	}

	/**
	 * Rozstrzyga gałaz zapytania: nazwy szablonow motywu i plik wtyczki.
	 *
	 * Kandydaci dla motywu to WYLACZNIE nazwy SZCZEGOLOWE z hierarchii
	 * WordPressa (`archive-ainp_article.php`, `taxonomy-ainp_topic.php`,
	 * `single-ainp_article.php`). Ogolnych — `archive.php`, `taxonomy.php`,
	 * `single.php`, `index.php` — na tej liscie byc NIE MOZE: to sa wlasnie
	 * te, ktore przegrywaja z szablonem wtyczki. Wpisanie ich tutaj cofneloby
	 * caly etap, bo `locate_template()` znajduje je w kazdym motywie.
	 *
	 * Archiwum taksonomii dostaje TEN SAM plik wtyczki co archiwum CPT.
	 * To jest decyzja z etapu 6.0: lista kart, wyszukiwarka i paginacja sa
	 * identyczne, rozni sie tylko naglowek i zaznaczony przycisk kategorii.
	 * Drugi, prawie identyczny plik znaczylby dwa miejsca do poprawiania
	 * przy kazdej zmianie karty.
	 *
	 * @return array{0:array<int,string>,1:string}|null Para `[kandydaci motywu, plik wtyczki]`
	 *                                                  albo `null`, gdy to nie nasze zapytanie.
	 */
	private static function resolve(): ?array {
		if ( is_singular( Plugin::CPT ) ) {
			$kandydaci = array( 'single-' . Plugin::CPT . '.php' );

			$wpis = get_queried_object();
			if ( is_object( $wpis ) && isset( $wpis->post_name ) && '' !== (string) $wpis->post_name ) {
				array_unshift( $kandydaci, 'single-' . Plugin::CPT . '-' . (string) $wpis->post_name . '.php' );
			}

			return array( $kandydaci, 'single.php' );
		}

		if ( is_tax( Plugin::TAX ) ) {
			$kandydaci = array( 'taxonomy-' . Plugin::TAX . '.php' );

			$termin = get_queried_object();
			if ( is_object( $termin ) && isset( $termin->slug ) && '' !== (string) $termin->slug ) {
				array_unshift( $kandydaci, 'taxonomy-' . Plugin::TAX . '-' . (string) $termin->slug . '.php' );
			}

			return array( $kandydaci, 'archive.php' );
		}

		if ( is_post_type_archive( Plugin::CPT ) ) {
			return array( array( 'archive-' . Plugin::CPT . '.php' ), 'archive.php' );
		}

		return null;
	}

	/**
	 * Pelna sciezka do szablonu wtyczki.
	 *
	 * @param string $plik Nazwa pliku w `src/templates/`.
	 *
	 * @return string
	 */
	public static function own_template( string $plik ): string {
		return AINP_PLUGIN_DIR . self::TEMPLATE_DIR . $plik;
	}

	/**
	 * Wstawia fragment szablonu — z motywu, jesli motyw go ma. Etap 6.2.
	 *
	 * Ta sama kolejnosc pierwszenstwa co przy calych szablonach, tylko
	 * katalogiem motywu jest `ai-news-portal/`. Motyw, ktory chce inaczej
	 * rysowac karte, kladzie u siebie `ai-news-portal/card.php` i nie musi
	 * podmieniac calego archiwum.
	 *
	 * @param string $plik Nazwa pliku w `src/templates/`.
	 *
	 * @return void
	 */
	public static function part( string $plik ): void {
		$z_motywu = locate_template( array( 'ai-news-portal/' . $plik ) );

		$sciezka = ( is_string( $z_motywu ) && '' !== $z_motywu ) ? $z_motywu : self::own_template( $plik );

		if ( file_exists( $sciezka ) ) {
			require $sciezka;
		}
	}

	/**
	 * Naglowek archiwum: nazwa kategorii albo nazwa portalu. Etap 6.2.
	 *
	 * @return string
	 */
	public static function archive_title(): string {
		if ( is_tax( Plugin::TAX ) ) {
			$termin = get_queried_object();
			if ( is_object( $termin ) && isset( $termin->name ) && '' !== (string) $termin->name ) {
				return (string) $termin->name;
			}
		}

		return __( 'Centrum Wiedzy', 'ai-news-portal' );
	}

	/**
	 * Kategoria artykulu. Etap 6.2.
	 *
	 * Artykul ma DOKLADNIE jedna kategorie — `Publisher` przypisuje ja jednym
	 * `wp_set_object_terms()` z jednoelementowa tablica, a walidator odrzuca
	 * odpowiedz modelu z kategoria spoza listy. Bierzemy wiec pierwszy termin
	 * i nie udajemy, ze moze ich byc wiecej.
	 *
	 * @param int $post_id Identyfikator wpisu.
	 *
	 * @return object|null
	 */
	public static function primary_term( int $post_id ): ?object {
		$terminy = get_the_terms( $post_id, Plugin::TAX );

		if ( ! is_array( $terminy ) || array() === $terminy ) {
			return null;
		}

		$pierwszy = reset( $terminy );

		return is_object( $pierwszy ) ? $pierwszy : null;
	}

	/**
	 * Adres archiwum kategorii. Pusty ciag, gdy WordPress go nie zna.
	 *
	 * @param object $termin Termin taksonomii.
	 *
	 * @return string
	 */
	public static function term_link( object $termin ): string {
		$link = get_term_link( $termin );

		return is_string( $link ) ? $link : '';
	}

	/**
	 * Adres zdjecia kategorii albo pusty ciag. Etap 6.2.
	 *
	 * Zdjecia jada w paczce wtyczki, po jednym na kategorie domyslna, a nazwa
	 * pliku jest slugiem kategorii. Klient moze w Ustawieniach dopisac wlasna
	 * kategorie — wtedy pliku nie ma i karta rysuje kafelek z inicjalem.
	 * To nie jest sytuacja wyjatkowa, tylko drugi normalny stan karty.
	 *
	 * SITO NA SLUG jest tu mimo tego, ze slug z WordPressa jest juz
	 * oczyszczony: ta funkcja sklada SCIEZKE PLIKU, a jedyny sensowny moment
	 * na sprawdzenie skladnika sciezki to chwila tuz przed jej zlozeniem.
	 * Slug spoza `[a-z0-9-]` konczy sie odmowa bez dotykania dysku.
	 *
	 * @param string $slug Slug kategorii.
	 *
	 * @return string
	 */
	public static function category_image_url( string $slug ): string {
		if ( 1 !== preg_match( '/^[a-z0-9-]+$/', $slug ) ) {
			return '';
		}

		$wzgledna = 'assets/kategorie/' . $slug . '.jpg';

		if ( ! file_exists( AINP_PLUGIN_DIR . $wzgledna ) ) {
			return '';
		}

		return AINP_PLUGIN_URL . $wzgledna;
	}

	/**
	 * Pierwsza litera nazwy kategorii — na kafelek zapasowy. Etap 6.2.
	 *
	 * `mb_*`, bo nazwy sa polskie: `substr('Żywienie', 0, 1)` oddaje POLOWE
	 * dwubajtowej litery i przegladarka rysuje w tym miejscu znak zapytania.
	 *
	 * @param string $nazwa Nazwa kategorii.
	 *
	 * @return string
	 */
	public static function initial( string $nazwa ): string {
		$nazwa = trim( $nazwa );

		if ( '' === $nazwa ) {
			return '';
		}

		return mb_strtoupper( mb_substr( $nazwa, 0, 1 ) );
	}

	/**
	 * Zajawka artykulu na karte. Etap 6.2.
	 *
	 * Bierzemy `post_excerpt` — pole, ktore `Publisher` wypelnia leadem
	 * z modelu. Swiadomie NIE siegamy po `get_the_excerpt()`: ta funkcja przy
	 * pustym polu tnie tresc artykulu na 55 slow i doklada wielokropek, czyli
	 * podstawia surowy poczatek tekstu tam, gdzie ma stac napisany lead.
	 * Lepiej pokazac sama tytul niz obciety akapit.
	 *
	 * @param int $post_id Identyfikator wpisu.
	 *
	 * @return string
	 */
	public static function lead( int $post_id ): string {
		$wpis = get_post( $post_id );

		if ( ! is_object( $wpis ) || ! isset( $wpis->post_excerpt ) ) {
			return '';
		}

		return trim( (string) $wpis->post_excerpt );
	}

	/**
	 * Goly `/centrum-wiedzy/kategoria/` → 301 na archiwum. DLUG Z KROKU 1.
	 *
	 * Ten adres nie pasuje do zadnej reguly przepisywania: czlon `kategoria`
	 * jest tylko przedrostkiem archiwow taksonomii i sam z siebie niczego nie
	 * opisuje. WordPress oddaje wiec 404 — poprawnie z jego strony, glupio
	 * z punktu widzenia kogos, kto skrocil adres w pasku przegladarki.
	 *
	 * DLACZEGO 301, A NIE 302: to jest rozstrzygniecie trwale, nie tymczasowy
	 * objazd. Cena jest znana i przyjeta: przegladarki i posrednicy zapamietuja
	 * 301 na dlugo, wiec gdyby kiedys pod tym adresem miala stanac prawdziwa
	 * strona (spis kategorii), stali bywalcy zobaczyliby ja dopiero po
	 * wyczyszczeniu cache'u. Przy adresie, ktory dzis zwraca blad, to zaden
	 * koszt.
	 *
	 * @return void
	 */
	public static function redirect_taxonomy_base(): void {
		if ( ! is_404() ) {
			return;
		}

		if ( self::request_path() !== Plugin::ARCHIVE_SLUG . '/' . self::TAX_BASE ) {
			return;
		}

		$cel = get_post_type_archive_link( Plugin::CPT );
		if ( ! is_string( $cel ) || '' === $cel ) {
			$cel = home_url( '/' );
		}

		wp_safe_redirect( $cel, 301 );
		exit;
	}

	/**
	 * Sciezka biezacego zadania, bez katalogu instalacji i bez ukosnikow z brzegow.
	 *
	 * Odejmowanie sciezki `home_url()` jest tu konieczne, a nie ozdobne:
	 * WordPress w podkatalogu (`example.com/blog/`) dostaje w `REQUEST_URI`
	 * `/blog/centrum-wiedzy/kategoria/`, wiec porownanie z golym
	 * `centrum-wiedzy/kategoria` nigdy by nie zaskoczylo.
	 *
	 * @return string Na przyklad `centrum-wiedzy/kategoria`.
	 */
	private static function request_path(): string {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( '' === $uri ) {
			return '';
		}

		$sciezka = trim( (string) wp_parse_url( $uri, PHP_URL_PATH ), '/' );
		$baza    = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );

		if ( '' !== $baza && str_starts_with( $sciezka . '/', $baza . '/' ) ) {
			$sciezka = trim( substr( $sciezka, strlen( $baza ) ), '/' );
		}

		return $sciezka;
	}
}
