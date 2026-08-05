<?php
/**
 * Panel wtyczki: dokladnie DWIE pozycje w menu.
 *
 * ETAP 1.6. Powstaje szkielet nawigacji — same ekrany zapelniaja sie pozniej:
 * tabela Materialow i przyciski „Pobierz teraz" / „Przetworz teraz" w Kroku 2
 * (etap 2.5), formularz Ustawien tamze, licznik wywolan AI w Kroku 4.
 *
 * CPT nie ma wlasnej pozycji w menu (`show_in_menu => false`), ale jego ekrany
 * dzialaja. Hurtowe zarzadzanie artykulami idzie przez `edit.php?post_type=ainp_article`
 * i link do tego adresu jest tu od poczatku — bez niego nie da sie usunac
 * kilkunastu slabych artykulow naraz.
 *
 * @package AI_News_Portal
 */

namespace AINP;

// Blokada bezposredniego wywolania pliku.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Menu i ekrany kokpitu.
 */
final class Admin {

	/** Uprawnienie wymagane przez KAZDY ekran i KAZDA akcje wtyczki. */
	public const CAP = 'manage_options';

	/** Slug ekranu „Materialy" — zarazem slug pozycji nadrzednej. */
	public const SLUG_ITEMS = 'ainp-items';

	/** Slug ekranu „Ustawienia". */
	public const SLUG_SETTINGS = 'ainp-settings';

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
	 * Ekran „Materialy".
	 *
	 * @return void
	 */
	public static function render_items(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Brak uprawnień do tego ekranu.', 'ai-news-portal' ) );
		}

		$lista_artykulow = admin_url( 'edit.php?post_type=' . Plugin::CPT );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'AI News Portal — Materiały', 'ai-news-portal' ) . '</h1>';
		echo '<p>' . esc_html__( 'Tu wyląduje tabela pobranych materiałów: tytuł, źródło, status, powód, data i akcje, a nad nią licznik wywołań AI oraz przyciski „Pobierz teraz" i „Przetwórz teraz". Pobieranie powstaje w Kroku 2, przetwarzanie w Kroku 4.', 'ai-news-portal' ) . '</p>';
		echo '<p><a href="' . esc_url( $lista_artykulow ) . '">' . esc_html__( 'Zarządzaj opublikowanymi artykułami', 'ai-news-portal' ) . '</a> ' . esc_html__( '— tam usuwa się i edytuje artykuły hurtem.', 'ai-news-portal' ) . '</p>';
		echo '</div>';
	}

	/**
	 * Ekran „Ustawienia".
	 *
	 * @return void
	 */
	public static function render_settings(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Brak uprawnień do tego ekranu.', 'ai-news-portal' ) );
		}

		$ustawienia = Settings::all();
		$kategorie  = is_array( $ustawienia['categories'] ) ? $ustawienia['categories'] : array();
		$slowa      = is_array( $ustawienia['excluded_words'] ) ? $ustawienia['excluded_words'] : array();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'AI News Portal — Ustawienia', 'ai-news-portal' ) . '</h1>';
		echo '<p>' . esc_html__( 'Formularz źródeł RSS, klucza API, modelu, promptu i przełącznika „zapisuj jako szkice" powstaje w Kroku 2. Poniżej wartości domyślne, na których wtyczka wystartuje.', 'ai-news-portal' ) . '</p>';

		echo '<h2>' . esc_html__( 'Kategorie', 'ai-news-portal' ) . ' (' . count( $kategorie ) . ')</h2>';
		echo '<p>' . esc_html( implode( ' · ', $kategorie ) ) . '</p>';

		echo '<h2>' . esc_html__( 'Model', 'ai-news-portal' ) . '</h2>';
		echo '<p><code>' . esc_html( (string) $ustawienia['model'] ) . '</code> ' . esc_html__( '— sufit dobowy wywołań: ', 'ai-news-portal' ) . (int) $ustawienia['daily_cap'] . '</p>';

		echo '<h2>' . esc_html__( 'Słowa wykluczające', 'ai-news-portal' ) . ' (' . count( $slowa ) . ')</h2>';
		echo '<p>' . esc_html( implode( ', ', $slowa ) ) . '</p>';

		echo '</div>';
	}
}
