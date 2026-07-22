<?php
/**
 * Komunikaty kokpitu o stanie linku do generatora w menu nawigacji (Krok 20).
 *
 * Trzeci komunikat wtyczki, po {@see PageNotice} (podstrona) i {@see IndexNotice}
 * (baza wektorów). Powód istnienia jest ten sam co tam: wtyczka Kroku 20 dopisuje
 * pozycję do menu motywu, ale w kilku sytuacjach zrobić tego NIE MOŻE (nie ma menu
 * przypiętego do żadnej lokalizacji, motyw jest blokowy, klient usunął pozycję ręcznie,
 * podstrona zniknęła) — a decyzja usera nr 1 zabrania tworzenia menu za klienta.
 * Bez tej klasy „gość nie ma jak trafić do generatora” byłoby awarią CICHĄ, dokładnie
 * tą, którą Krok 18 zamykał dla podstrony.
 *
 * DLACZEGO OSOBNA KLASA, A NIE ROZBUDOWA {@see PageNotice} — powody spisane
 * w {@see IndexNotice} (nagłówek klasy, 4 punkty) obowiązują tu bez zmian: własna
 * przestrzeń wyciszania, własna whitelista akcji pod własnym noncem, brak dotykania
 * kodu pokrytego asercjami Kroku 18.
 *
 * Hooki (`admin_post_aifaq_menu_fix` oraz wypis z callbacku komunikatów) rejestruje
 * {@see \AIFAQ\Core\Plugin} — tutaj są same metody.
 *
 * @package AI_FAQ_Generator
 */

namespace AIFAQ\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Komunikat o stanie pozycji menu wraz z obsługą naprawy „na jedno kliknięcie”.
 *
 * Klasa celowo NIE jest `final` — testy dziedziczą po niej, żeby podmienić
 * {@see self::guard()} na atrapę strażnika menu. Dlatego każde odwołanie do
 * `MenuGuard` idzie przez ten szew (`$g = static::guard();`), nigdy literałem.
 *
 * KONTRAKT k20-v3 §4.2: klasa reaguje na SIEDEM z dziesięciu stanów
 * (`no_menu`, `no_location`, `location_ambiguous`, `menu_changed`, `failed`,
 * `removed_by_user`, `page_missing`) i MILCZY przy `ok`, `disabled`, `missing`.
 */
class MenuNotice {

	/**
	 * Akcja `admin-post.php` i zarazem nazwa nonce'a.
	 */
	public const ACTION = 'aifaq_menu_fix';

	/**
	 * Parametr GET z rodzajem naprawy: create|dismiss (whitelista ZAMKNIĘTA).
	 */
	public const PARAM = 'aifaq_fix';

	/**
	 * Opcja z LITERAŁEM STANU, dla którego właściciel zamknął komunikat.
	 *
	 * Trzymamy stan, a nie „1": klient wycisza „brak menu", potem menu tworzy,
	 * a wtedy pojawia się problem INNY (np. `location_ambiguous`) — z gołym
	 * booleanem nie dowiedziałby się o nim NIGDY. Wzorzec {@see PageNotice::DISMISSED}.
	 */
	public const DISMISSED = 'aifaq_menu_notice_dismissed';

	/**
	 * Opcja z datą (`RRRR-MM-DD`) przekroczenia dobowego sufitu witryny (§7.4).
	 *
	 * Zapisuje ją ścieżka pytania gościa (E6); tutaj tylko ją czytamy.
	 */
	public const BUDGET_HIT = 'aifaq_budget_hit';

	/**
	 * Stany, przy których komunikat MILCZY (KONTRAKT §4.2).
	 */
	private const SILENT = array( 'ok', 'disabled', 'missing' );

	/**
	 * Wypisuje komunikaty menu i dobowego sufitu witryny (callback `admin notices`).
	 *
	 * DWA NIEZALEŻNE BLOKI, żaden z wczesnym `return` na poziomie tej metody:
	 * sufit dobowy jest awarią zupełnie innej warstwy niż menu i nie ma prawa
	 * zniknąć dlatego, że akurat nie ma klasy strażnika menu (i odwrotnie).
	 */
	public static function render(): void {
		// 1. Komunikat jest dla właściciela witryny, nie dla redakcji.
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// 2. Tylko ekrany, na których komunikat ma sens.
		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}
		$screen = get_current_screen();

		// `get_current_screen()` zwraca null, dopóki nie ustawiono $GLOBALS['current_screen'],
		// a odczyt ->id z nulla to ostrzeżenie PHP 8.2 wypisane wprost na górze kokpitu klienta.
		if ( ! is_object( $screen ) || ! isset( $screen->id ) ) {
			return;
		}
		$sid = (string) $screen->id;
		if ( 'plugins' !== $sid && 'dashboard' !== $sid && false === strpos( $sid, 'ai-faq-generator' ) ) {
			return;
		}

		self::render_menu_row();
		self::render_budget_row();
	}

	/**
	 * Obsługuje kliknięcie w akcję naprawczą (hook `admin_post_aifaq_menu_fix`).
	 *
	 * KONTRAKT §4.3 (poprawka FZ18) — kolejność kroków jest ZAMROŻONA:
	 * cap → nonce → whitelista → przekierowanie. WordPress odpala
	 * `admin_post_<akcja>` dla KAŻDEGO zalogowanego użytkownika (także Subskrybenta),
	 * a bez `check_admin_referer()` akcja jest wykonywalna z obcej domeny
	 * w przeglądarce zalogowanego administratora (CSRF) — `repair( 'create' )`
	 * pisze do NAWIGACJI witryny klienta.
	 */
	public static function handle_fix(): void {
		// 1. Uprawnienia. `return` jest OBOWIĄZKOWY — atrapa `wp_die()` w teście nie
		// przerywa wykonania, a bez niego kod poleciałby dalej aż do naprawy.
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
			if ( function_exists( 'wp_die' ) ) {
				$denied = function_exists( 'esc_html__' )
					? esc_html__( 'Brak uprawnień.', 'ai-faq-generator' )
					: 'Brak uprawnień.';
				wp_die( $denied, '', array( 'response' => 403 ) );
			}
			return;
		}

		// 2. CSRF.
		if ( function_exists( 'check_admin_referer' ) ) {
			check_admin_referer( self::ACTION );
		}

		// 3. Rodzaj naprawy — whitelista ZAMKNIĘTA: create|dismiss.
		$fix = '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce sprawdzony wyżej.
		if ( isset( $_GET[ self::PARAM ] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- j.w.
			$raw = $_GET[ self::PARAM ];
			if ( function_exists( 'wp_unslash' ) ) {
				$raw = wp_unslash( $raw );
			}
			$fix = function_exists( 'sanitize_key' )
				? (string) sanitize_key( $raw )
				: strtolower( (string) preg_replace( '/[^a-z0-9_\-]/i', '', (string) $raw ) );
		}

		if ( 'dismiss' === $fix ) {
			// KONTRAKT §3.1 (FZ10): `repair( 'dismiss' )` NIE ZAPISUJE NICZEGO,
			// wyciszenie należy w całości do tej klasy — więc strażnika tu NIE wołamy.
			self::remember_dismissal();
		} elseif ( 'create' === $fix ) {
			$g = static::guard();
			if ( class_exists( $g ) && method_exists( $g, 'repair' ) ) {
				try {
					$g::repair( 'create' );
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
		}

		// 4. Powrót tam, skąd przyszedł właściciel.
		if ( function_exists( 'wp_safe_redirect' ) ) {
			$referer = function_exists( 'wp_get_referer' ) ? wp_get_referer() : '';
			$back    = $referer ? (string) $referer : self::admin_link( '' );
			wp_safe_redirect( $back );
		}

		// Bezwarunkowy `exit` czyniłby tę metodę niewywoływalną z CLI — proces testowy
		// kończyłby się w połowie pliku z kodem 0, czyli runner raportowałby SUKCES
		// na urwanym teście.
		if ( ! defined( 'AIFAQ_TESTING' ) ) {
			exit;
		}

		return;
	}

	/**
	 * Buduje adres akcji naprawczej wraz z nonce'em.
	 *
	 * @param string $fix create|dismiss.
	 * @return string Adres `admin-post.php` z nonce'em.
	 */
	public static function action_url( string $fix ): string {
		$url = 'admin-post.php?action=' . self::ACTION . '&' . self::PARAM . '=' . $fix;
		$url = self::admin_link( $url );

		if ( function_exists( 'wp_nonce_url' ) ) {
			return (string) wp_nonce_url( $url, self::ACTION );
		}

		return $url;
	}

	/**
	 * SZEW TESTOWY — jedyna droga do wstrzyknięcia stanu menu.
	 *
	 * @return string Pełna nazwa klasy strażnika menu.
	 */
	protected static function guard(): string {
		return '\\AIFAQ\\PublicUi\\MenuGuard';
	}

	/**
	 * Wiersz o stanie pozycji w menu nawigacji.
	 */
	private static function render_menu_row(): void {
		// Stan przez szew; brak klasy z innego etapu nie ma prawa wywalić kokpitu.
		$g = static::guard();
		if ( ! class_exists( $g ) || ! method_exists( $g, 'menu_state' ) ) {
			return;
		}

		// Stan liczymy NA ŻYWO (`menu_state()` jest czystą diagnozą, ZERO zapisów —
		// KONTRAKT §3.1), nigdy z zapisanej opcji: opcja bywa nieaktualna, a wtedy
		// właściciel dostaje przycisk naprawiający problem, którego już nie ma.
		$state = self::blank_state();
		try {
			$read = $g::menu_state();
			if ( is_array( $read ) ) {
				$state = array_merge( $state, $read );
			}
		} catch ( \Throwable $e ) {
			unset( $e );
			return;
		}

		$status = (string) $state['state'];
		if ( '' === $status || in_array( $status, self::SILENT, true ) ) {
			return;
		}

		// Zamknięcie zapisane WRAZ ZE STANEM, którego dotyczy.
		if ( function_exists( 'get_option' ) && $status === (string) get_option( self::DISMISSED, '' ) ) {
			return;
		}

		$row = self::row( $status, $state );
		if ( empty( $row ) ) {
			return;
		}

		self::print_row( $row );
	}

	/**
	 * Wiersz o wyczerpaniu dobowego sufitu witryny (KONTRAKT §7.4, poprawka FZ30).
	 *
	 * Bez niego sufit jest NIEWIDZIALNĄ AWARIĄ: goście dostają 429, a właściciel
	 * widzi tylko, że „bot przestał odpowiadać”. Flagę zapisuje ścieżka pytania
	 * gościa; tutaj czytamy ją i porównujemy z DZISIEJSZĄ datą — wczorajsza
	 * flaga nie ma prawa straszyć, bo pula wróciła.
	 */
	private static function render_budget_row(): void {
		if ( ! function_exists( 'get_option' ) ) {
			return;
		}

		$hit = trim( (string) get_option( self::BUDGET_HIT, '' ) );
		if ( '' === $hit || $hit !== self::today() ) {
			return;
		}

		$budget = self::daily_budget();
		if ( $budget <= 0 ) {
			// Sufit wyłączony (§2.2) — zaległa flaga nie ma czego opisywać.
			return;
		}

		/* translators: %d: dobowy limit pytań gości ustawiony w Ustawieniach. */
		$msg = sprintf( __( 'Witryna wyczerpała dobowy limit zapytań gości (%d). Zwiększ go w Ustawieniach albo podnieś kwotę u dostawcy.', 'ai-faq-generator' ), $budget );

		printf(
			'<div class="notice notice-warning"><p><strong>AI FAQ Generator:</strong> %1$s</p><p><a class="button" href="%2$s">%3$s</a></p></div>',
			esc_html( $msg ),
			esc_url( self::admin_link( 'admin.php?page=' . self::settings_slug() ) ),
			esc_html__( 'Otwórz ustawienia', 'ai-faq-generator' )
		);
	}

	/**
	 * Składa wiersz komunikatu dla danego stanu menu.
	 *
	 * @param string $status Stan z {@see \AIFAQ\PublicUi\MenuGuard}.
	 * @param array  $state  Pełny stan menu (9 kluczy).
	 * @return array Klucze: level, msg, actions, dismiss. Pusta tablica = nic nie wypisujemy.
	 */
	private static function row( string $status, array $state ): array {
		$tries = (int) $state['tries'];
		$error = trim( (string) $state['error'] );

		switch ( $status ) {
			case 'no_menu':
				return array(
					'level'   => 'notice-warning',
					'msg'     => __( 'Wtyczka nie doda linku sama, bo do żadnego miejsca w motywie nie jest przypięte menu. Wtyczka celowo nie tworzy menu za Ciebie — mogłaby zastąpić nawigację wbudowaną w motyw. Utwórz menu w Wygląd → Menu i przypisz je do miejsca w motywie, potem kliknij «Dodaj link».', 'ai-faq-generator' ),
					'actions' => array(
						array(
							'url'   => self::admin_link( 'nav-menus.php' ),
							'label' => __( 'Otwórz Wygląd → Menu', 'ai-faq-generator' ),
						),
						array(
							'url'   => self::action_url( 'create' ),
							'label' => __( 'Dodaj link', 'ai-faq-generator' ),
						),
					),
					'dismiss' => true,
				);

			case 'no_location':
				$msg = __( 'Ten motyw nie udostępnia klasycznych menu (motyw blokowy). Dodaj link do nawigacji ręcznie w edytorze motywu.', 'ai-faq-generator' );
				$url = self::page_url();
				if ( '' !== $url ) {
					/* translators: %s: adres podstrony generatora. */
					$msg .= ' ' . sprintf( __( 'Adres podstrony: %s', 'ai-faq-generator' ), $url );
				}

				return array(
					'level'   => 'notice-info',
					'msg'     => $msg,
					// Świadomie BEZ przycisku „Dodaj link": na motywie blokowym próba
					// zakończyłaby się tym samym stanem, a klient klikałby w kółko.
					'actions' => array(),
					'dismiss' => true,
				);

			case 'location_ambiguous':
				return array(
					'level'   => 'notice-warning',
					'msg'     => __( 'Motyw ma więcej niż jedno miejsce na menu i żadne nie wygląda na główną nawigację. Wtyczka nie zgaduje — wskaż miejsce w Ustawieniach, żeby link nie trafił np. do stopki albo do paska ikon społecznościowych.', 'ai-faq-generator' ),
					'actions' => array(
						array(
							'url'   => self::admin_link( 'admin.php?page=' . self::settings_slug() ),
							'label' => __( 'Wskaż miejsce w ustawieniach', 'ai-faq-generator' ),
						),
					),
					'dismiss' => true,
				);

			case 'menu_changed':
				return array(
					'level'   => 'notice-warning',
					'msg'     => __( 'Link do generatora jest w innym menu niż to, które motyw wyświetla teraz — goście go nie widzą. Kliknij «Dodaj link», żeby dopisać go do aktualnego menu.', 'ai-faq-generator' ),
					'actions' => array(
						array(
							'url'   => self::action_url( 'create' ),
							'label' => __( 'Dodaj link', 'ai-faq-generator' ),
						),
					),
					'dismiss' => true,
				);

			case 'failed':
				$msg = self::with_error( __( 'Nie udało się dodać linku do menu. Wtyczka spróbuje ponownie.', 'ai-faq-generator' ), $error );
				/* translators: %d: liczba nieudanych prób dodania pozycji do menu. */
				$msg .= ' ' . sprintf( __( '(prób: %d)', 'ai-faq-generator' ), $tries );

				return array(
					'level'   => 'notice-error',
					'msg'     => $msg,
					'actions' => array(
						array(
							'url'   => self::action_url( 'create' ),
							'label' => __( 'Spróbuj ponownie', 'ai-faq-generator' ),
						),
					),
					'dismiss' => true,
				);

			case 'removed_by_user':
				return array(
					'level'   => 'notice-info',
					'msg'     => __( 'Link został usunięty z menu. Wtyczka go nie przywróci — jeśli chcesz go z powrotem, kliknij «Dodaj link».', 'ai-faq-generator' ),
					'actions' => array(
						array(
							'url'   => self::action_url( 'create' ),
							'label' => __( 'Dodaj link', 'ai-faq-generator' ),
						),
					),
					'dismiss' => true,
				);

			case 'page_missing':
				// Kolejność napraw ma znaczenie: dopóki nie ma opublikowanej podstrony,
				// nie ma dokąd linkować. Rdzeń nie kasuje pozycji przy przeniesieniu
				// podstrony do kosza — oznacza ją `_invalid` i ODFILTROWUJE NA FRONCIE
				// (KONTRAKT §3.3 pkt 7), więc gość nie widzi ani linku, ani błędu 404.
				return array(
					'level'   => 'notice-warning',
					'msg'     => __( 'Najpierw musi istnieć opublikowana podstrona generatora — dopiero wtedy link w menu ma dokąd prowadzić. Zajmij się komunikatem o podstronie powyżej; gość nie widzi teraz ani linku, ani błędu.', 'ai-faq-generator' ),
					'actions' => array(),
					'dismiss' => true,
				);
		}

		return array();
	}

	/**
	 * Wypisuje gotowy wiersz komunikatu.
	 *
	 * @param array $row Wynik {@see self::row()}.
	 */
	private static function print_row( array $row ): void {
		printf(
			'<div class="notice %1$s is-dismissible"><p><strong>AI FAQ Generator:</strong> %2$s</p>',
			esc_attr( (string) $row['level'] ),
			esc_html( (string) $row['msg'] )
		);

		$links = $row['actions'];
		if ( ! empty( $row['dismiss'] ) ) {
			$links[] = array(
				'url'   => self::action_url( 'dismiss' ),
				'label' => __( 'Nie pokazuj więcej', 'ai-faq-generator' ),
				'class' => 'button-link',
			);
		}

		if ( ! empty( $links ) ) {
			$parts = array();
			foreach ( $links as $link ) {
				$parts[] = sprintf(
					'<a class="%1$s" href="%2$s">%3$s</a>',
					esc_attr( isset( $link['class'] ) ? (string) $link['class'] : 'button' ),
					esc_url( (string) $link['url'] ),
					esc_html( (string) $link['label'] )
				);
			}
			echo '<p>' . implode( ' ', $parts ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- każdy fragment przeszedł esc_* wyżej.
		}

		echo '</div>';
	}

	/**
	 * Zapisuje zamknięcie komunikatu wraz ze stanem, którego dotyczy.
	 *
	 * Tu świeża diagnoza jest właściwa: właściciel zamyka to, co widzi TERAZ.
	 */
	private static function remember_dismissal(): void {
		$status = '';
		$g      = static::guard();

		if ( class_exists( $g ) && method_exists( $g, 'menu_state' ) ) {
			try {
				$state = $g::menu_state();
				if ( is_array( $state ) && isset( $state['state'] ) ) {
					$status = (string) $state['state'];
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		if ( function_exists( 'add_option' ) ) {
			add_option( self::DISMISSED, '', '', 'no' );
		}
		if ( function_exists( 'update_option' ) ) {
			update_option( self::DISMISSED, $status, false );
		}
	}

	/**
	 * Dzisiejsza data w strefie witryny (`RRRR-MM-DD`).
	 *
	 * Ten sam zegar, którym licznik doby posługuje się przy zapisie (§2.2) —
	 * inaczej komunikat gasłby o północy UTC, a nie o północy klienta.
	 *
	 * @return string
	 */
	private static function today(): string {
		if ( function_exists( 'current_time' ) ) {
			return (string) current_time( 'Y-m-d' );
		}

		return gmdate( 'Y-m-d' );
	}

	/**
	 * Dobowy sufit witryny z ustawień (jawny default, KONTRAKT §11.3).
	 *
	 * @return int
	 */
	private static function daily_budget(): int {
		if ( ! class_exists( '\AIFAQ\Core\Settings' ) || ! method_exists( '\AIFAQ\Core\Settings', 'get_field' ) ) {
			return 0;
		}

		try {
			return (int) \AIFAQ\Core\Settings::get_field( 'rag_daily_budget', 12 );
		} catch ( \Throwable $e ) {
			unset( $e );
			return 0;
		}
	}

	/**
	 * Adres podstrony generatora ze strażnika podstrony (nigdy sklejany ze slugu).
	 *
	 * @return string Adres albo pusty string.
	 */
	private static function page_url(): string {
		if ( ! class_exists( '\AIFAQ\PublicUi\PageGuard' ) || ! method_exists( '\AIFAQ\PublicUi\PageGuard', 'page_url' ) ) {
			return '';
		}

		try {
			return (string) \AIFAQ\PublicUi\PageGuard::page_url();
		} catch ( \Throwable $e ) {
			unset( $e );
			return '';
		}
	}

	/**
	 * Slug ekranu Ustawień — ze stałej, gdy klasa menu istnieje, inaczej literał.
	 *
	 * @return string
	 */
	private static function settings_slug(): string {
		if ( class_exists( '\AIFAQ\Admin\Menu' ) && defined( '\AIFAQ\Admin\Menu::SLUG_SETTINGS' ) ) {
			return (string) constant( '\AIFAQ\Admin\Menu::SLUG_SETTINGS' );
		}

		return 'ai-faq-generator-settings';
	}

	/**
	 * Adres w kokpicie. Argument podajemy JAWNIE, także pusty — `admin_url()`
	 * bez parametru wywraca się na atrapach o wymaganym argumencie.
	 *
	 * @param string $path Ścieżka względem wp-admin.
	 * @return string
	 */
	private static function admin_link( string $path ): string {
		return function_exists( 'admin_url' ) ? (string) admin_url( $path ) : $path;
	}

	/**
	 * Dokleja przyczynę do komunikatu, gdy jest znana.
	 *
	 * @param string $msg   Komunikat bazowy.
	 * @param string $error Przyczyna ze stanu menu.
	 * @return string
	 */
	private static function with_error( string $msg, string $error ): string {
		return '' === $error ? $msg : $msg . ' ' . $error;
	}

	/**
	 * Pełny, typowany kształt stanu (9 kluczy, KONTRAKT §3.1) — na wypadek śmieci.
	 *
	 * @return array
	 */
	private static function blank_state(): array {
		return array(
			'state'    => '',
			'item_id'  => 0,
			'owned'    => '',
			'location' => '',
			'menu_id'  => 0,
			'label'    => '',
			'tries'    => 0,
			'last'     => 0,
			'error'    => '',
		);
	}
}
