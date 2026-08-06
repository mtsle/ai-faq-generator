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

	/** Nazwa pola z nonce'em — wspolna dla obu akcji. */
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

		Settings::update( self::sanitize_settings( is_array( $dane ) ? $dane : array() ) );

		self::redirect_back( self::SLUG_SETTINGS, 'zapisano' );
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
			'categories'     => isset( $dane['ainp_categories'] )
				? self::parse_list( (string) $dane['ainp_categories'] )
				: $domyslne['categories'],
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

	// -----------------------------------------------------------------------
	// Odczyt do wyswietlenia
	// -----------------------------------------------------------------------

	/**
	 * Ostatnie pozycje z tabeli.
	 *
	 * Odczyt siedzi TUTAJ, a nie w `Runner`ze: tam kazde zapytanie `SELECT`
	 * przed zapisem bylo wzorcem „przeczytaj, potem zapisz", ktorego caly
	 * odsiew duplikatow ma unikac. To jest odczyt do pokazania na ekranie
	 * i z zapisem nie ma nic wspolnego.
	 *
	 * @param int $limit Ile pozycji.
	 *
	 * @return array<int,object>
	 */
	public static function recent_items( int $limit = self::ITEMS_LIMIT ): array {
		global $wpdb;

		$sql = 'SELECT id, url, title, status, note, created_at FROM ' . Plugin::table()
			. ' ORDER BY id DESC LIMIT %d';

		$wiersze = $wpdb->get_results( $wpdb->prepare( $sql, $limit ) ); // phpcs:ignore WordPress.DB

		return is_array( $wiersze ) ? $wiersze : array();
	}

	// -----------------------------------------------------------------------
	// Ekrany
	// -----------------------------------------------------------------------

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
		$pozycje         = self::recent_items();
		$zrodla          = Runner::sources();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'AI News Portal — Materiały', 'ai-news-portal' ) . '</h1>';

		self::render_run_notice();

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_FETCH ) . '" />';
		wp_nonce_field( self::ACTION_FETCH, self::NONCE_FIELD );
		echo '<p>';
		submit_button( __( 'Pobierz teraz', 'ai-news-portal' ), 'primary', 'submit', false );
		echo ' <span class="description">'
			. esc_html(
				sprintf(
					/* translators: %d: liczba kanalow RSS */
					__( 'Kanałów na liście: %d. Pobieranie nie tworzy jeszcze artykułów — te powstają w kroku z modelem AI.', 'ai-news-portal' ),
					count( $zrodla )
				)
			)
			. '</span>';
		echo '</p>';
		echo '</form>';

		if ( ! $zrodla ) {
			echo '<div class="notice notice-warning"><p>'
				. esc_html__( 'Lista kanałów RSS jest pusta — dopisz adresy w Ustawieniach.', 'ai-news-portal' )
				. '</p></div>';
		}

		self::render_items_table( $pozycje );

		echo '<p><a href="' . esc_url( $lista_artykulow ) . '">' . esc_html__( 'Zarządzaj opublikowanymi artykułami', 'ai-news-portal' ) . '</a> ' . esc_html__( '— tam usuwa się i edytuje artykuły hurtem.', 'ai-news-portal' ) . '</p>';
		echo '</div>';
	}

	/**
	 * Podsumowanie ostatniego pobrania.
	 *
	 * @return void
	 */
	private static function render_run_notice(): void {
		$klucz        = self::TRANSIENT_RUN . get_current_user_id();
		$podsumowanie = get_transient( $klucz );

		if ( ! is_array( $podsumowanie ) ) {
			return;
		}

		delete_transient( $klucz );

		echo '<div class="notice notice-success"><p>' . esc_html(
			sprintf(
				/* translators: 1: zrodla, 2: nowe, 3: odsiane filtrem, 4: duplikaty, 5: bez adresu, 6: bledy */
				__( 'Pobrano z %1$d kanałów: %2$d nowych, %3$d odsianych filtrem, %4$d duplikatów, %5$d bez adresu, %6$d nieudanych zapisów.', 'ai-news-portal' ),
				(int) $podsumowanie['sources'],
				(int) $podsumowanie['added'],
				isset( $podsumowanie['skipped'] ) ? (int) $podsumowanie['skipped'] : 0,
				(int) $podsumowanie['duplicates'],
				(int) $podsumowanie['invalid'],
				(int) $podsumowanie['failed']
			)
		) . '</p>';

		if ( ! empty( $podsumowanie['dropped'] ) ) {
			echo '<p>' . esc_html(
				sprintf(
					/* translators: %d: liczba pozycji ponad sufitem */
					__( 'Ponad sufitem jednego przebiegu zostało pominiętych pozycji: %d. Zostaną wzięte przy kolejnym pobraniu.', 'ai-news-portal' ),
					(int) $podsumowanie['dropped']
				)
			) . '</p>';
		}

		foreach ( (array) $podsumowanie['errors'] as $zrodlo => $blad ) {
			echo '<p><strong>' . esc_html( (string) $zrodlo ) . '</strong>: ' . esc_html( (string) $blad ) . '</p>';
		}

		echo '</div>';
	}

	/**
	 * Tabela pobranych materialow.
	 *
	 * @param array<int,object> $pozycje Wiersze z bazy.
	 *
	 * @return void
	 */
	private static function render_items_table( array $pozycje ): void {
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Tytuł', 'ai-news-portal' ) . '</th>';
		echo '<th>' . esc_html__( 'Źródło', 'ai-news-portal' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'ai-news-portal' ) . '</th>';
		echo '<th>' . esc_html__( 'Powód', 'ai-news-portal' ) . '</th>';
		echo '<th>' . esc_html__( 'Data', 'ai-news-portal' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( ! $pozycje ) {
			echo '<tr><td colspan="5">' . esc_html__( 'Nie ma jeszcze żadnych materiałów. Kliknij „Pobierz teraz".', 'ai-news-portal' ) . '</td></tr>';
		}

		foreach ( $pozycje as $pozycja ) {
			$url  = isset( $pozycja->url ) ? (string) $pozycja->url : '';
			$host = (string) wp_parse_url( $url, PHP_URL_HOST );

			echo '<tr>';
			echo '<td><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">'
				. esc_html( self::title_or_url( $pozycja ) ) . '</a></td>';
			echo '<td>' . esc_html( $host ) . '</td>';
			echo '<td>' . esc_html( self::status_label( (string) $pozycja->status ) ) . '</td>';
			echo '<td>' . esc_html( (string) $pozycja->note ) . '</td>';
			echo '<td>' . esc_html( (string) $pozycja->created_at ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Tytul pozycji albo jej adres, gdy kanal tytulu nie podal.
	 *
	 * @param object $pozycja Wiersz.
	 *
	 * @return string
	 */
	private static function title_or_url( $pozycja ): string {
		$tytul = isset( $pozycja->title ) ? trim( (string) $pozycja->title ) : '';

		return ( '' !== $tytul ) ? $tytul : (string) $pozycja->url;
	}

	/**
	 * Nazwa statusu po polsku.
	 *
	 * @param string $status Status z bazy.
	 *
	 * @return string
	 */
	private static function status_label( string $status ): string {
		$nazwy = array(
			'new'        => __( 'nowy', 'ai-news-portal' ),
			'processing' => __( 'w trakcie', 'ai-news-portal' ),
			'done'       => __( 'opublikowany', 'ai-news-portal' ),
			'skipped'    => __( 'pominięty', 'ai-news-portal' ),
			'failed'     => __( 'nieudany', 'ai-news-portal' ),
		);

		return isset( $nazwy[ $status ] ) ? $nazwy[ $status ] : $status;
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
		$zrodla     = Runner::sources();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'AI News Portal — Ustawienia', 'ai-news-portal' ) . '</h1>';

		if ( isset( $_GET['ainp_status'] ) && 'zapisano' === $_GET['ainp_status'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Ustawienia zapisane.', 'ai-news-portal' ) . '</p></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_SAVE ) . '" />';
		wp_nonce_field( self::ACTION_SAVE, self::NONCE_FIELD );

		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="ainp_sources">' . esc_html__( 'Kanały RSS', 'ai-news-portal' ) . '</label></th><td>';
		echo '<textarea name="ainp_sources" id="ainp_sources" rows="6" cols="70" class="large-text code">'
			. esc_textarea( implode( "\n", $zrodla ) ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'Jeden adres na linię. Adresy, które nie są adresem http(s), są odrzucane przy zapisie.', 'ai-news-portal' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="ainp_categories">' . esc_html__( 'Kategorie', 'ai-news-portal' ) . '</label></th><td>';
		echo '<textarea name="ainp_categories" id="ainp_categories" rows="4" cols="70" class="large-text">'
			. esc_textarea( implode( "\n", $kategorie ) ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'Jedna na linię. Model dostaje dokładnie tę listę i musi wybrać jedną pozycję.', 'ai-news-portal' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="ainp_excluded_words">' . esc_html__( 'Słowa wykluczające', 'ai-news-portal' ) . '</label></th><td>';
		echo '<textarea name="ainp_excluded_words" id="ainp_excluded_words" rows="6" cols="70" class="large-text">'
			. esc_textarea( implode( ', ', $slowa ) ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'Po przecinku albo po jednym na linię, bez polskich znaków diakrytycznych.', 'ai-news-portal' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="ainp_model">' . esc_html__( 'Model', 'ai-news-portal' ) . '</label></th><td>';
		echo '<input type="text" name="ainp_model" id="ainp_model" class="regular-text" value="' . esc_attr( (string) $ustawienia['model'] ) . '" />';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="ainp_daily_cap">' . esc_html__( 'Sufit wywołań AI na dobę', 'ai-news-portal' ) . '</label></th><td>';
		echo '<input type="number" name="ainp_daily_cap" id="ainp_daily_cap" min="1" max="1000" value="' . esc_attr( (string) (int) $ustawienia['daily_cap'] ) . '" />';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Publikacja', 'ai-news-portal' ) . '</th><td>';
		echo '<label><input type="checkbox" name="ainp_save_as_draft" value="1" ' . checked( ! empty( $ustawienia['save_as_draft'] ), true, false ) . ' /> '
			. esc_html__( 'Zapisuj jako szkice zamiast publikować', 'ai-news-portal' ) . '</label>';
		echo '</td></tr>';

		echo '</tbody></table>';

		submit_button( __( 'Zapisz ustawienia', 'ai-news-portal' ) );
		echo '</form>';

		echo '<p class="description">' . esc_html__( 'Klucz API i prompt dochodzą w kroku z modelem AI.', 'ai-news-portal' ) . '</p>';
		echo '</div>';
	}
}
