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
		add_action( 'pre_get_posts', array( self::class, 'filter_query' ) );
	}

	/**
	 * Artykulow na stronie archiwum. Decyzja usera z etapu 6.0. Etap 6.4.
	 *
	 * Wartosc jest wlasna, nie brana z Ustawien czytania WordPressa: tamta
	 * ustawia klient pod swojego bloga, a tutaj rzadzi uklad — dwie kolumny
	 * kart, wiec liczba parzysta wypelnia siatke bez dziury w ostatnim rzedzie.
	 */
	public const PER_PAGE = 10;

	/**
	 * Nazwa parametru wyszukiwania. WLASNA, nie `s`. Etap 6.3.
	 *
	 * Zwykle `?s=` byloby tu bledem, ktorego nie widac po samym adresie:
	 * w hierarchii szablonow `is_search()` jest sprawdzane PRZED
	 * `is_post_type_archive()`, wiec WordPress zaladowalby `search.php`
	 * motywu i wynik szukania w Centrum Wiedzy wygladalby jak wyszukiwarka
	 * bloga. Przy `ainp_s` flaga `is_search()` zostaje falszywa, routing nie
	 * ucieka z archiwum, a adres pozostaje `/centrum-wiedzy/?ainp_s=karma`.
	 */
	public const SEARCH_VAR = 'ainp_s';

	/**
	 * Sufit dlugosci frazy wyszukiwania w znakach. Etap 7.5.
	 *
	 * Powod jest kosztowy, nie skladniowy — patrz `search_term()`.
	 */
	public const SEARCH_MAX = 120;

	/**
	 * Poprawki glownego zapytania archiwum: fraza (6.3) i strona (6.4).
	 *
	 * PRZEPISANIE `ainp_s` NA `s`: `set('s', ...)` w `pre_get_posts` NIE
	 * wlacza flagi `is_search()` — flagi ustawia `parse_query()`, ktore juz
	 * sie wykonalo. To nie jest efekt uboczny, tylko caly powod, dla ktorego
	 * mechanizm wyglada wlasnie tak: dostajemy wyszukiwanie w zapytaniu, nie
	 * zmieniajac tego, czym zapytanie jest dla hierarchii szablonow.
	 *
	 * OBIE poprawki maja te same bramki wejscia, wiec mieszkaja w jednej
	 * metodzie. Rozbicie na dwa hooki znaczyloby dwie kopie tych samych
	 * czterech warunkow i drugie miejsce, w ktorym trzeba pamietac o
	 * `is_main_query()`.
	 *
	 * @param object $zapytanie Obiekt `WP_Query`.
	 *
	 * @return void
	 */
	public static function filter_query( $zapytanie ): void {
		if ( is_admin() || ! is_object( $zapytanie ) ) {
			return;
		}

		if ( ! method_exists( $zapytanie, 'is_main_query' ) || ! $zapytanie->is_main_query() ) {
			return;
		}

		if ( ! self::is_archive_view() ) {
			return;
		}

		/*
		 * FEED ARCHIWUM ZOSTAJE PRZY USTAWIENIU WITRYNY — ustalenie audytowe D1.
		 *
		 * `/centrum-wiedzy/feed/` ma `is_post_type_archive()` rowne PRAWDA,
		 * wiec bramka wyzej go przepuszczala i `posts_per_page` nadpisywalo
		 * `posts_per_rss`. Klient, ktory w Ustawieniach → Czytanie wpisze
		 * „pokaz ostatnio 25 elementow", dostawalby w kanale 10 — i nie
		 * mialby jak zgadnac, dlaczego. Nasza liczba na stronie jest decyzja
		 * o UKLADZIE (dwie kolumny kart), a kanal RSS zadnego ukladu nie ma.
		 *
		 * Fraza wyszukiwania nizej dotyczy feedu tak samo jak strony, wiec
		 * wychodzimy dopiero TUTAJ, po ustawieniu liczby, a nie na wejsciu.
		 */
		if ( ! $zapytanie->is_feed() ) {
			$zapytanie->set( 'posts_per_page', self::PER_PAGE );
		}

		$fraza = self::search_term();
		if ( '' === $fraza ) {
			return;
		}

		$zapytanie->set( 's', $fraza );
	}

	/**
	 * Paginacja numeryczna archiwum. Pusty ciag przy jednej stronie. Etap 6.4.
	 *
	 * `add_args` z frazą jest tu KONIECZNE, a nie ozdobne: `paginate_links()`
	 * sklada adres od nowa i bez tego druga strona wynikow wyszukiwania
	 * prowadzilaby do drugiej strony CALEGO archiwum. Wyglada to niewinnie —
	 * strona sie otwiera, ma artykuly — i wlasnie dlatego latwo tego nie
	 * zauwazyc.
	 *
	 * @return string Gotowy HTML albo pusty ciag.
	 */
	public static function pagination(): string {
		$argumenty = array(
			'mid_size'  => 1,
			'prev_text' => __( '← Poprzednia', 'ai-news-portal' ),
			'next_text' => __( 'Następna →', 'ai-news-portal' ),
		);

		$fraza = self::search_term();
		if ( '' !== $fraza ) {
			$argumenty['add_args'] = array( self::SEARCH_VAR => rawurlencode( $fraza ) );
		}

		$linki = paginate_links( $argumenty );

		if ( ! is_string( $linki ) || '' === trim( $linki ) ) {
			return '';
		}

		return $linki;
	}

	/**
	 * Szukana fraza z adresu. Pusty ciag, gdy nie szukamy. Etap 6.3.
	 *
	 * Nonce'a tu nie ma i byc nie powinno: to jest odczyt publicznej listy
	 * przez GET, bez zadnego skutku ubocznego. Nonce chroni przed wykonaniem
	 * AKCJI w cudzym imieniu, a nie przed czytaniem archiwum — dokladanie go
	 * do wyszukiwarki zepsuloby adresy do udostepniania i zakladki.
	 *
	 * @return string
	 */
	public static function search_term(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- publiczny odczyt archiwum, patrz opis.
		if ( ! isset( $_GET[ self::SEARCH_VAR ] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- j.w.
		$fraza = sanitize_text_field( wp_unslash( $_GET[ self::SEARCH_VAR ] ) );
		$fraza = trim( (string) $fraza );

		/*
		 * SUFIT DLUGOSCI FRAZY — etap 7.5.
		 *
		 * Fraza idzie wprost do `WP_Query::set( 's' )`, a WordPress rozbija ja
		 * na slowa i buduje z nich osobne warunki `LIKE '%…%'`. Napis
		 * z adresu jest wiec mnoznikiem kosztu zapytania: kilka kilobajtow
		 * w pasku przegladarki to kilkaset warunkow LIKE na tabeli wpisow,
		 * bez logowania i bez zadnego limitu. Zapytanie pozostaje bezpieczne
		 * (WordPress je escapuje), ale jego KOSZT nie ma gornej granicy.
		 *
		 * 120 znakow to okolo pietnastu slow — wiecej nie wpisuje sie
		 * w wyszukiwarke z zamiarem znalezienia czegokolwiek.
		 *
		 * Ciecie idzie po ZNAKACH, nie bajtach: `substr()` rozcielby polska
		 * litere w polowie i zostawil w zapytaniu polamany UTF-8.
		 */
		if ( function_exists( 'mb_substr' ) ) {
			$fraza = mb_substr( $fraza, 0, self::SEARCH_MAX );
		} else {
			$fraza = substr( $fraza, 0, self::SEARCH_MAX );
		}

		return trim( $fraza );
	}

	/**
	 * Czy to widok listy (archiwum CPT albo archiwum kategorii).
	 *
	 * @return bool
	 */
	public static function is_archive_view(): bool {
		return is_post_type_archive( Plugin::CPT ) || is_tax( Plugin::TAX );
	}

	/**
	 * Kategorie na przyciski — tylko te, ktore maja artykuly. Etap 6.3.
	 *
	 * POKAZUJEMY TAKZE PUSTE — decyzja usera z 2026-08-09, zmiana wobec 6.3.
	 *
	 * Pierwotnie stalo tu `hide_empty => true` z uzasadnieniem, ze przycisk
	 * prowadzacy do pustego archiwum to obietnica bez pokrycia. W praktyce
	 * wyszlo odwrotnie: swiezo wlaczona wtyczka pokazywala JEDEN przycisk
	 * i portal nie mowil o sobie nic — ani jakie tematy obejmuje, ani ze
	 * bedzie ich siedem. Puste archiwum odpowiada zrozumialym komunikatem,
	 * a zdjecie kategorii niesie tresc samo z siebie.
	 *
	 * Terminy zaklada `Plugin::ensure_topics()`; bez tego samo przelaczenie
	 * flagi niczego by nie dalo, bo `get_terms()` nie mialoby czego zwrocic.
	 *
	 * @return array<int,object>
	 */
	public static function categories(): array {
		$terminy = get_terms(
			array(
				'taxonomy'   => Plugin::TAX,
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);

		if ( ! is_array( $terminy ) ) {
			return array();
		}

		return array_values( array_filter( $terminy, 'is_object' ) );
	}

	/**
	 * Adres archiwum Centrum Wiedzy. Etap 6.3.
	 *
	 * @return string
	 */
	public static function archive_link(): string {
		$link = get_post_type_archive_link( Plugin::CPT );

		return ( is_string( $link ) && '' !== $link ) ? $link : home_url( '/' );
	}

	/**
	 * Slug ogladanej kategorii albo pusty ciag. Etap 6.3.
	 *
	 * @return string
	 */
	public static function current_term_slug(): string {
		if ( ! is_tax( Plugin::TAX ) ) {
			return '';
		}

		$termin = get_queried_object();

		return ( is_object( $termin ) && isset( $termin->slug ) ) ? (string) $termin->slug : '';
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
		/*
		 * EMBED MA WLASNA GALAZ HIERARCHII — ustalenie audytowe P1.
		 *
		 * Przy zadaniu `/centrum-wiedzy/tytul/embed/` WordPress ustawia OBIE
		 * flagi: `is_embed()` i `is_singular()`. Warunek nizej lapal wiec
		 * takze embed i podstawial pelnostronicowy szablon z `get_header()`
		 * i `get_footer()` — w ramce oEmbed ladowala cala strona portalu
		 * z menu i stopka motywu zamiast karty. Zmierzone przed naprawa:
		 * 39 736 bajtow i zero znacznikow `wp-embed` (poprawny embed strony
		 * WordPressa na tej samej witrynie: 19 729 bajtow).
		 *
		 * `is_embed()` musi stac PRZED `is_singular()`, bo embed jest
		 * przypadkiem szczegolnym singla, nie odwrotnie.
		 */
		if ( is_embed() ) {
			return null;
		}

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

		if ( ! file_exists( $sciezka ) ) {
			return;
		}

		/*
		 * `load_template()`, a NIE `require` — ustalenie audytowe D2.
		 *
		 * Gole `require` wykonywalo sie w zasiegu tej metody statycznej, wiec
		 * szablon nie widzial ani `$post`, ani zmiennych zapytania. Nasz
		 * `card.php` tego nie zauwazyl, bo siega po `get_the_ID()`
		 * i `the_permalink()` — te czytaja globalny `$post` same. Ale motyw,
		 * ktory skorzysta z reklamowanej tu podmiany i napisze karte
		 * po WordPressowemu, przez `$post->post_title`, dostawalby ostrzezenie
		 * o nieistniejacej zmiennej i pusta karte.
		 *
		 * DRUGI ARGUMENT MUSI BYC `false`: karta jest wstawiana raz na wpis
		 * w petli, a `require_once` narysowalby wylacznie pierwsza.
		 */
		load_template( $sciezka, false );
	}

	/**
	 * Naglowek archiwum: nazwa kategorii albo nazwa portalu. Etap 6.2.
	 *
	 * @return string
	 */
	public static function archive_title(): string {
		$fraza = self::search_term();
		if ( '' !== $fraza ) {
			/* translators: %s: szukana fraza. */
			return sprintf( __( 'Wyniki wyszukiwania: „%s”', 'ai-news-portal' ), $fraza );
		}

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
	 * Adres zrodla artykulu. Pusty ciag, gdy go nie ma. Etap 6.5.
	 *
	 * Link do zrodla jest obowiazkowy i nieusuwalny — portal przepisuje cudze
	 * teksty modelem, wiec wskazanie oryginalu to minimum przyzwoitosci wobec
	 * autora i jedyne, co odroznia ten portal od skrobaczki tresci.
	 *
	 * SITO NA SCHEMAT: przepuszczamy wylacznie `http` i `https`. Wartosc
	 * bierze sie z meta wpisu, a te da sie ustawic z kokpitu — `javascript:`
	 * w atrybucie `href` byloby wtedy wykonywalnym kodem na froncie.
	 * `esc_url()` w szablonie odsiewa to samo, ale bramka stoi w miejscu,
	 * ktore decyduje, CZY ramke zrodla w ogole rysowac: adres odrzucony ma
	 * nie zostawiac pustej ramki z napisem „Zrodlo:".
	 *
	 * @param int $post_id Identyfikator wpisu.
	 *
	 * @return string
	 */
	public static function source_url( int $post_id ): string {
		$adres = trim( (string) get_post_meta( $post_id, Plugin::META_SOURCE, true ) );

		if ( '' === $adres ) {
			return '';
		}

		$schemat = strtolower( (string) wp_parse_url( $adres, PHP_URL_SCHEME ) );

		if ( 'http' !== $schemat && 'https' !== $schemat ) {
			return '';
		}

		return $adres;
	}

	/**
	 * Host adresu zrodla, do pokazania w ramce. Etap 6.5.
	 *
	 * Sam adres bywa dlugi na trzy linie i na telefonie rozpycha uklad.
	 * W ramce stoi wiec nazwa serwisu, a pelny adres zostaje w `href`.
	 *
	 * @param string $adres Adres zrodla.
	 *
	 * @return string
	 */
	public static function source_host( string $adres ): string {
		$host = strtolower( (string) wp_parse_url( $adres, PHP_URL_HOST ) );

		if ( '' === $host ) {
			return '';
		}

		return ( 0 === strpos( $host, 'www.' ) ) ? substr( $host, 4 ) : $host;
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
