<?php
/**
 * Ekrany kokpitu AI News Portal: Materialy, Ustawienia i komunikaty.
 *
 * ETAP 8.1 (split). Tresc tego pliku PRZYSZLA Z `Admin.php` bez jednej zmiany
 * w kodzie — przeniesienie, nie przepisanie. Powodem byl pomiar: metody
 * rysujace nie wolaja ANI JEDNEJ metody grupy akcji, a grupa akcji nie wola
 * ani jednej metody rysujacej. Wspolne sa wylacznie stale, ktore zostaly
 * na `Admin`. Granica jest wiec faktem, nie porzadkowaniem po uwazaniu.
 *
 * DLACZEGO TRAIT, A NIE OSOBNA KLASA. `Admin::render_items()`
 * i `Admin::render_settings()` sa wpisane jako wywolania zwrotne w
 * `add_menu_page()` / `add_submenu_page()`, a testy siegaja po nie po nazwie
 * (samo `Admin::render_items` w 13 miejscach). Osobna klasa wymagalaby albo
 * zmiany tych nazw — czyli zmiany publicznego API w etapie, ktory ma go nie
 * ruszac — albo warstwy metod przekazujacych, ktora nic nie wnosi. Trait
 * zostawia `Admin::render_items()` DOKLADNIE tym, czym byla.
 *
 * Plik musi byc zaladowany PRZED `Admin.php`: trait ma istniec, zanim klasa
 * go uzyje. Kolejnosc pilnuje lista `require_once` w pliku glownym.
 *
 * @package AI_News_Portal
 */

namespace AINP;

// Blokada bezposredniego wywolania pliku.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rysowanie ekranow wtyczki. Uzywane wylacznie przez `Admin`.
 */
trait Admin_Screen {

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
		$zuzycie         = Gemini::usage();
		$ma_klucz        = ( '' !== trim( (string) get_option( Settings::OPTION_KEY, '' ) ) );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'AI News Portal — Materiały', 'ai-news-portal' ) . '</h1>';

		self::render_demo_notice();
		self::render_lock_notice();
		self::render_run_notice();
		self::render_prep_notice();
		self::render_pub_notice();
		self::render_retry_notice();
		self::render_tick_notice();

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

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_PREPARE ) . '" />';
		wp_nonce_field( self::ACTION_PREPARE, self::NONCE_FIELD );
		echo '<p>';
		submit_button( __( 'Przygotuj treści', 'ai-news-portal' ), 'secondary', 'submit', false );
		echo ' <span class="description">'
			. esc_html(
				sprintf(
					/* translators: %d: rozmiar partii */
					__( 'Bierze %d pozycji: odsiewa słowami wykluczającymi, dobiera pełną treść, gdy kanał podał samą zapowiedź, i odrzuca powtórki treści.', 'ai-news-portal' ),
					Runner::PREPARE_BATCH
				)
			)
			. '</span>';
		echo '</p>';
		echo '</form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_PUBLISH ) . '" />';
		wp_nonce_field( self::ACTION_PUBLISH, self::NONCE_FIELD );
		echo '<p>';
		submit_button( __( 'Opublikuj teraz', 'ai-news-portal' ), 'secondary', 'submit', false, $ma_klucz ? array() : array( 'disabled' => 'disabled' ) );
		echo ' <span class="description">'
			. esc_html(
				sprintf(
					/* translators: 1: rozmiar partii, 2: zuzyte wywolania, 3: sufit dobowy */
					__( 'Wysyła do modelu %1$d pozycji i publikuje gotowe artykuły. Wywołań AI dziś: %2$d z %3$d.', 'ai-news-portal' ),
					Runner::AI_BATCH,
					$zuzycie['count'],
					$zuzycie['cap']
				)
			)
			. '</span>';
		echo '</p>';
		echo '</form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_RETRY ) . '" />';
		wp_nonce_field( self::ACTION_RETRY, self::NONCE_FIELD );
		echo '<p>';
		$nieudane = Runner::failed_counts();

		submit_button( __( 'Wznów nieudane', 'ai-news-portal' ), 'secondary', 'submit', false, $nieudane['total'] > 0 ? array() : array( 'disabled' => 'disabled' ) );
		echo ' <span class="description">'
			. esc_html(
				sprintf(
					/* translators: 1: liczba nieudanych pozycji, 2: ile z nich wroci do modelu */
					__( 'Nieudanych pozycji: %1$d, w tym %2$d takich, które padły dopiero przy modelu — każda z nich zajmie kolejne wywołanie z dobowej puli. Klikaj po usunięciu przyczyny.', 'ai-news-portal' ),
					$nieudane['total'],
					$nieudane['ai']
				)
			)
			. '</span>';
		echo '</p>';
		echo '</form>';

		if ( ! $ma_klucz ) {
			echo '<div class="notice notice-warning"><p>'
				. esc_html__( 'Klucz API nie jest zapisany — bez niego wtyczka nie wywoła modelu. Uzupełnij go w Ustawieniach.', 'ai-news-portal' )
				. '</p></div>';
		}

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
	 * Podsumowanie ostatniej publikacji.
	 *
	 * Kazda liczba jest tu po to, zeby klient wiedzial, GDZIE poszly wywolania
	 * z dobowej puli — najczestsze pytanie przy limicie 20 na dobe brzmi
	 * „dlaczego nic nie przybylo", a odpowiedzia bywa „wszystkie pozycje byly
	 * poza tematem" albo „sufit wyczerpany".
	 *
	 * @return void
	 */
	private static function render_tick_notice(): void {
		$przebieg = get_transient( self::TRANSIENT_TICK );

		if ( ! is_array( $przebieg ) ) {
			echo '<div class="notice notice-warning inline"><p>'
				. esc_html__( 'Automat nie zgłosił jeszcze żadnego przebiegu. Portal pracuje sam dopiero wtedy, gdy ktoś odwiedza stronę — WordPress uruchamia zadania cykliczne ruchem, nie zegarem.', 'ai-news-portal' )
				. '</p></div>';

			return;
		}

		/*
		 * Zapisu NIE kasujemy po pokazaniu — to nie jest komunikat o akcji, tylko
		 * stan automatu. Ma byc widoczny przy kazdym wejsciu na ekran.
		 */
		$pobrane      = (int) ( $przebieg['collect']['added'] ?? 0 );
		$przygotowane = (int) ( $przebieg['prepare']['ready'] ?? 0 );
		$opublikowane = (int) ( $przebieg['publish']['published'] ?? 0 );
		$wywolania    = (int) ( $przebieg['publish']['calls'] ?? 0 );

		echo '<div class="notice notice-info inline"><p><strong>'
			. esc_html__( 'Ostatni automatyczny przebieg', 'ai-news-portal' ) . '</strong> — '
			. esc_html( (string) ( $przebieg['time'] ?? '' ) ) . '. '
			. esc_html(
				sprintf(
					/* translators: 1: nowe pozycje, 2: przygotowane tresci, 3: artykuly, 4: wywolania AI */
					__( 'Nowych pozycji: %1$d. Przygotowanych treści: %2$d. Opublikowanych artykułów: %3$d. Wywołań AI: %4$d.', 'ai-news-portal' ),
					$pobrane,
					$przygotowane,
					$opublikowane,
					$wywolania
				)
			) . '</p>';

		if ( ! empty( $przebieg['locked'] ) ) {
			echo '<p>' . esc_html__( 'Przebieg nie ruszył, bo trwał inny — to normalne, gdy ktoś klika przyciski w tym samym czasie.', 'ai-news-portal' ) . '</p>';
		}

		if ( '' !== (string) ( $przebieg['publish']['note'] ?? '' ) ) {
			echo '<p><strong>' . esc_html__( 'Publikacja wstrzymana:', 'ai-news-portal' ) . '</strong> '
				. esc_html( (string) $przebieg['publish']['note'] ) . '</p>';
		}

		if ( ! empty( $przebieg['skipped'] ) ) {
			echo '<p>' . esc_html(
				sprintf(
					/* translators: %s: lista pominietych faz */
					__( 'Zabrakło czasu na etapy: %s. Jeśli powtarza się to co godzinę, przyczyną jest zwykle wolny kanał RSS.', 'ai-news-portal' ),
					implode( ', ', array_map( 'strval', (array) $przebieg['skipped'] ) )
				)
			) . '</p>';
		}

		if ( ! empty( $przebieg['recovered'] ) ) {
			echo '<p>' . esc_html(
				sprintf(
					/* translators: %d: liczba odzyskanych pozycji */
					__( 'Odzyskanych pozycji po przerwanym przebiegu: %d.', 'ai-news-portal' ),
					(int) $przebieg['recovered']
				)
			) . '</p>';
		}

		foreach ( (array) ( $przebieg['errors'] ?? array() ) as $faza => $blad ) {
			echo '<p><strong>' . esc_html( (string) $faza ) . '</strong>: ' . esc_html( (string) $blad ) . '</p>';
		}

		echo '</div>';
	}

	/**
	 * Komunikat po wznowieniu pozycji.
	 *
	 * @return void
	 */
	private static function render_retry_notice(): void {
		$klucz     = self::TRANSIENT_RETRY . get_current_user_id();
		$wznowione = get_transient( $klucz );

		if ( false === $wznowione ) {
			return;
		}

		delete_transient( $klucz );

		/*
		 * Zero tez dostaje komunikat. Cisza po klikniecu wyglada jak usterka,
		 * a „nie bylo czego wznawiac" jest odpowiedzia — i to dobra.
		 */
		echo '<div class="notice notice-success"><p>' . esc_html(
			sprintf(
				/* translators: %d: liczba wznowionych pozycji */
				__( 'Wznowionych pozycji: %d.', 'ai-news-portal' ),
				(int) ( $wznowione['revived'] ?? 0 )
			)
		) . '</p>';

		if ( ! empty( $wznowione['ai'] ) ) {
			echo '<p>' . esc_html(
				sprintf(
					/* translators: %d: liczba pozycji wracajacych do modelu */
					__( 'W tym %d, które padły dopiero przy modelu — każda zajmie kolejne wywołanie z dobowej puli.', 'ai-news-portal' ),
					(int) $wznowione['ai']
				)
			) . '</p>';
		}

		echo '</div>';
	}

	/**
	 * Komunikat po partii publikacji.
	 *
	 * @return void
	 */
	private static function render_pub_notice(): void {
		$klucz        = self::TRANSIENT_PUB . get_current_user_id();
		$podsumowanie = get_transient( $klucz );

		if ( ! is_array( $podsumowanie ) ) {
			return;
		}

		delete_transient( $klucz );

		echo '<div class="notice notice-success"><p>' . esc_html(
			sprintf(
				/* translators: 1: opublikowane, 2: nieudane, 3: odsiane poza tematem, 4: wywolania AI */
				__( 'Opublikowano artykułów: %1$d. Nieudanych: %2$d. Odsianych poza tematem: %3$d. Wywołań AI w tym przebiegu: %4$d.', 'ai-news-portal' ),
				(int) $podsumowanie['published'],
				(int) $podsumowanie['failed'],
				(int) $podsumowanie['offtopic'],
				(int) $podsumowanie['calls']
			)
		) . '</p>';

		if ( '' !== (string) $podsumowanie['note'] ) {
			echo '<p><strong>' . esc_html__( 'Przebieg wstrzymany:', 'ai-news-portal' ) . '</strong> '
				. esc_html( (string) $podsumowanie['note'] ) . '</p>';
		}

		if ( ! empty( $podsumowanie['budget_hit'] ) ) {
			echo '<p>' . esc_html__( 'Skończył się budżet czasu przebiegu — kliknij ponownie, żeby wziąć kolejne pozycje.', 'ai-news-portal' ) . '</p>';
		}

		foreach ( (array) $podsumowanie['errors'] as $id => $blad ) {
			echo '<p><strong>#' . esc_html( (string) (int) $id ) . '</strong>: ' . esc_html( (string) $blad ) . '</p>';
		}

		echo '</div>';
	}

	/**
	 * Komunikaty trybu demo na ekranie „Materialy".
	 *
	 * Dwa niezalezne: staly opis wystawy (zeby gosc wiedzial, gdzie jest
	 * i czemu czesc pol nie dziala) oraz odpowiedz na odmowe z limitu —
	 * odmowa bez slowa wygladalaby jak przycisk, ktory czasem nie dziala,
	 * dokladnie jak przy zamku przebiegu nizej.
	 *
	 * @return void
	 */
	private static function render_demo_notice(): void {
		if ( ! Demo::active() ) {
			return;
		}

		echo '<div class="notice notice-info"><p>'
			. esc_html__( 'Instalacja demonstracyjna: przyciski mają odstępy i dzienne limity na adres IP, a dane wracają do stanu wzorcowego co kilka godzin.', 'ai-news-portal' )
			. '</p></div>';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- odczyt, nic nie zapisuje.
		if ( isset( $_GET['ainp_status'] ) && 'demo' === $_GET['ainp_status'] ) {
			echo '<div class="notice notice-warning"><p>'
				. esc_html__( 'Limit trybu demo: odczekaj chwilę przed kolejnym przebiegiem — publikacja ma też dzienny limit na adres IP.', 'ai-news-portal' )
				. '</p></div>';
		}
	}

	/**
	 * Informacja, ze partia byla juz w toku (zamek D-8).
	 *
	 * Osobny komunikat, nie cisza: klient, ktory kliknal dwa razy, ma zobaczyc,
	 * ze drugie klikniecie NIC nie zrobilo. Bez tego wyglada to jak przycisk,
	 * ktory czasem dziala, a czasem nie.
	 *
	 * @return void
	 */
	private static function render_lock_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- odczyt, nic nie zapisuje.
		if ( ! isset( $_GET['ainp_status'] ) || 'zajete' !== $_GET['ainp_status'] ) {
			return;
		}

		// Komunikat jest wspolny dla „Przygotuj treści" i „Opublikuj teraz" —
		// oba biora ten sam zamek, wiec nie moze mowic o jednym z nich.
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'Przebieg już trwa — poczekaj na jego koniec i odśwież stronę.', 'ai-news-portal' ) . '</p></div>';
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
	 * Podsumowanie ostatniego przygotowania tresci.
	 *
	 * @return void
	 */
	private static function render_prep_notice(): void {
		$klucz        = self::TRANSIENT_PREP . get_current_user_id();
		$podsumowanie = get_transient( $klucz );

		if ( ! is_array( $podsumowanie ) ) {
			return;
		}

		delete_transient( $klucz );

		echo '<div class="notice notice-success"><p>' . esc_html(
			sprintf(
				/* translators: 1: wziete, 2: gotowe, 3: pominiete, 4: do ponowienia, 5: nieudane */
				__( 'Przygotowano %1$d pozycji: %2$d z treścią gotową, %3$d pominiętych, %4$d do ponowienia, %5$d nieudanych.', 'ai-news-portal' ),
				(int) $podsumowanie['taken'],
				(int) $podsumowanie['ready'],
				(int) $podsumowanie['skipped'],
				(int) $podsumowanie['retry'],
				(int) $podsumowanie['failed']
			)
		) . '</p>';

		if ( 0 === (int) $podsumowanie['taken'] && empty( $podsumowanie['budget_hit'] ) ) {
			echo '<p>' . esc_html__( 'Nie było czego przygotowywać — wszystkie pobrane pozycje mają już treść albo zostały zamknięte.', 'ai-news-portal' ) . '</p>';
		}

		if ( ! empty( $podsumowanie['budget_hit'] ) ) {
			echo '<p>' . esc_html(
				sprintf(
					/* translators: %d: budzet czasu w sekundach */
					__( 'Przebieg zatrzymał się po %d s, żeby nie przekroczyć limitu czasu serwera. Reszta pozycji czeka — kliknij ponownie.', 'ai-news-portal' ),
					Runner::PREPARE_BUDGET
				)
			) . '</p>';
		}

		foreach ( (array) $podsumowanie['errors'] as $id => $blad ) {
			echo '<p><strong>#' . esc_html( (string) $id ) . '</strong>: ' . esc_html( (string) $blad ) . '</p>';
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
		$wymagane   = is_array( $ustawienia['required_words'] ) ? $ustawienia['required_words'] : array();
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

		echo '<tr><th scope="row"><label for="ainp_required_words">' . esc_html__( 'Słowa wymagane', 'ai-news-portal' ) . '</label></th><td>';
		echo '<textarea name="ainp_required_words" id="ainp_required_words" rows="6" cols="70" class="large-text">'
			. esc_textarea( implode( ', ', $wymagane ) ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'Pozycja przechodzi tylko wtedy, gdy w TYTULE albo ZAJAWCE stoi co najmniej jedno z tych słów. Odmiany trzeba wypisać osobno — dopasowanie jest do całego słowa. Puste pole wyłącza ten warunek.', 'ai-news-portal' ) . '</p>';
		echo '</td></tr>';

		/*
		 * Klucz API. Pole jest PUSTE nawet wtedy, gdy klucz jest zapisany —
		 * wartosc nie ma prawa wrocic do HTML-a, bo stamtad ida do przegladarki,
		 * do pamieci podrecznej i do kazdego rozszerzenia, ktore czyta formularz.
		 * Klient widzi tylko informacje, CZY klucz jest.
		 */
		$ma_klucz = ( '' !== trim( (string) get_option( Settings::OPTION_KEY, '' ) ) );

		echo '<tr><th scope="row"><label for="ainp_key">' . esc_html__( 'Klucz API Gemini', 'ai-news-portal' ) . '</label></th><td>';
		if ( Demo::active() ) {
			/*
			 * Tryb demo: zamiast pola — jedno zdanie. Pole bez mocy (zapis
			 * i tak blokuje `Admin::save_key()`) tylko udawaloby, ze cos
			 * znaczy, a gosc probowalby zgadnac, czemu „nie dziala".
			 */
			echo '<p class="description">'
				. esc_html__( 'Instalacja demonstracyjna — klucz API jest ustawiony na stałe i nie można go zmienić.', 'ai-news-portal' )
				. '</p>';
		} else {
			echo '<input type="password" name="ainp_key" id="ainp_key" class="regular-text" value="" autocomplete="off" />';
			echo '<p class="description">'
				. ( $ma_klucz
					? esc_html__( 'Klucz jest zapisany. Puste pole zostawia go bez zmian.', 'ai-news-portal' )
					: esc_html__( 'Klucz nie jest jeszcze zapisany — bez niego wtyczka nie wywoła modelu.', 'ai-news-portal' ) )
				. '</p>';
			if ( $ma_klucz ) {
				echo '<label><input type="checkbox" name="ainp_key_clear" value="1" /> '
					. esc_html__( 'Usuń zapisany klucz', 'ai-news-portal' ) . '</label>';
			}
		}
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="ainp_prompt">' . esc_html__( 'Prompt', 'ai-news-portal' ) . '</label></th><td>';
		echo '<textarea name="ainp_prompt" id="ainp_prompt" rows="14" cols="70" class="large-text code">'
			. esc_textarea( (string) $ustawienia['prompt'] ) . '</textarea>';
		echo '<p class="description">'
			. esc_html__( 'Znaczniki podstawiane przed wysłaniem: {kategorie}, {tytul}, {zrodlo}, {tresc}. Puste pole przywraca prompt domyślny.', 'ai-news-portal' )
			. '</p>';
		echo '</td></tr>';

		// W trybie demo model i sufit dobowy sa tylko do odczytu — zapis i tak
		// je pomija (`Admin::sanitize_settings()`), a pole aktywne klamaloby.
		$demo_blokada = Demo::active() ? ' disabled="disabled"' : '';

		echo '<tr><th scope="row"><label for="ainp_model">' . esc_html__( 'Model', 'ai-news-portal' ) . '</label></th><td>';
		echo '<input type="text" name="ainp_model" id="ainp_model" class="regular-text" value="' . esc_attr( (string) $ustawienia['model'] ) . '"' . $demo_blokada . ' />';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="ainp_daily_cap">' . esc_html__( 'Sufit wywołań AI na dobę', 'ai-news-portal' ) . '</label></th><td>';
		echo '<input type="number" name="ainp_daily_cap" id="ainp_daily_cap" min="1" max="1000" value="' . esc_attr( (string) (int) $ustawienia['daily_cap'] ) . '"' . $demo_blokada . ' />';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Publikacja', 'ai-news-portal' ) . '</th><td>';
		echo '<label><input type="checkbox" name="ainp_save_as_draft" value="1" ' . checked( ! empty( $ustawienia['save_as_draft'] ), true, false ) . ' /> '
			. esc_html__( 'Zapisuj jako szkice zamiast publikować', 'ai-news-portal' ) . '</label>';
		echo '</td></tr>';

		echo '</tbody></table>';

		submit_button( __( 'Zapisz ustawienia', 'ai-news-portal' ) );
		echo '</form>';

		echo '<p class="description">' . esc_html__( 'Klucz API jest zapisywany osobno i nigdy nie wraca do tego formularza.', 'ai-news-portal' ) . '</p>';
		echo '</div>';
	}
}
