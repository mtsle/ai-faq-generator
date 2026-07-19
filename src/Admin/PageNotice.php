<?php
/**
 * Komunikaty kokpitu o stanie podstrony generatora.
 *
 * Pierwszy `admin_notices` w tej wtyczce. Powód: podstrona `/generator-faq/`
 * potrafiła nie powstać (albo wylądować w koszu, zostać szkicem, stracić
 * shortcode) i właściciel witryny NIE MIAŁ JAK się o tym dowiedzieć — awaria
 * była cicha. Ta klasa zamienia zapisany stan podstrony na jeden wiersz
 * komunikatu z akcją naprawczą „na jedno kliknięcie" i obsługuje tę akcję
 * (`admin-post.php`) za bramką uprawnień i nonce'a.
 *
 * Stan liczymy na żywo ({@see \AIFAQ\PublicUi\PageGuard::page_state()}),
 * nie ze świeżej diagnozy: `admin_init` utrwala go przed `admin_notices`,
 * a diagnoza na każdym ekranie kokpitu kosztowałaby zapytania do bazy.
 *
 * Hooki (`admin_notices`, `admin_post_aifaq_page_fix`) rejestruje
 * {@see \AIFAQ\Core\Plugin} — tutaj są same metody.
 *
 * @package AI_FAQ_Generator
 */

namespace AIFAQ\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Komunikat o stanie podstrony generatora wraz z obsługą naprawy.
 *
 * Klasa celowo NIE jest `final` — testy dziedziczą po niej, żeby podmienić
 * {@see self::guard()} na licznik wywołań. Dlatego każde odwołanie do
 * `PageGuard` idzie przez ten szew (`$g = static::guard();`), nigdy literałem.
 */
class PageNotice {

	/**
	 * Akcja `admin-post.php` i zarazem nazwa nonce'a.
	 */
	public const ACTION = 'aifaq_page_fix';

	/**
	 * Parametr GET z rodzajem naprawy: create|restore|publish|dismiss.
	 */
	public const PARAM = 'aifaq_fix';

	/**
	 * Opcja z LITERAŁEM STATUSU, dla którego właściciel zamknął komunikat.
	 *
	 * Trzymamy status, a nie „1", żeby zmiana stanu (np. `no_shortcode`
	 * → `trashed`) sama unieważniła zamknięcie i alarm wrócił.
	 */
	public const DISMISSED = 'aifaq_page_notice_dismissed';

	/**
	 * Wypisuje komunikat o stanie podstrony generatora (hook `admin_notices`).
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

		// 3. Stan przez szew; brak klasy z innego etapu nie ma prawa wywalić kokpitu.
		$g = static::guard();
		if ( ! class_exists( $g ) ) {
			return;
		}

		// Stan liczymy NA ŻYWO przez `page_state()`, nigdy z zapisanej opcji `state()`.
		// KONTRAKT §3.6.1: „page_state() nie zapisuje niczego — to czysta funkcja
		// diagnostyczna i tylko na niej opiera się PageNotice”.
		//
		// DLACZEGO to jest istotne, a nie kosmetyczne (znalezisko Etapu 5, asercja §8.3 #54):
		// zapisana opcja bywa nieaktualna — przy podstronie, która istnieje, ale straciła
		// shortcode, `state()` na świeżej instalacji jest pusty, więc właściciel dostawał
		// wiersz `missing` („podstrona jeszcze nie powstała”) z przyciskiem, który utworzyłby
		// DRUGĄ stronę `generator-faq-2`, podczas gdy prawdziwa istnieje. Dodatkowo zamknięcie
		// „Nie pokazuj więcej” zapisywane jest per status przez `handle_fix()`, które czyta
		// `page_state()` — porównywanie go tutaj ze `state()` porównywało dwa różne źródła.
		if ( ! method_exists( $g, 'page_state' ) ) {
			return;
		}
		$state = self::blank_state();
		try {
			$read = $g::page_state();
			if ( is_array( $read ) ) {
				$state = array_merge( $state, $read );
			}
		} catch ( \Throwable $e ) {
			unset( $e );
			return;
		}

		$status = (string) $state['status'];
		if ( '' === $status ) {
			return;
		}

		// 4. Zamknięcie zapisane WRAZ ZE STATUSEM, którego dotyczy.
		if ( function_exists( 'get_option' ) && $status === (string) get_option( self::DISMISSED, '' ) ) {
			return;
		}

		$row = self::row( $status, $state, $g );
		if ( empty( $row ) ) {
			return;
		}

		self::print_row( $row );
	}

	/**
	 * Obsługuje kliknięcie w akcję naprawczą (hook `admin_post_aifaq_page_fix`).
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

		// 2. Jedyna powierzchnia CSRF tego Kroku.
		if ( function_exists( 'check_admin_referer' ) ) {
			check_admin_referer( self::ACTION );
		}

		// 3. Rodzaj naprawy.
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

		// 4. Każda akcja właściciela otwiera tanią bramkę frontu, żeby stan przeliczył
		// się natychmiast. Literał, nie stała — działa też bez klasy PageGuard.
		if ( function_exists( 'update_option' ) ) {
			update_option( 'aifaq_page_ok', '', true );
		}

		if ( 'dismiss' === $fix ) {
			self::remember_dismissal();
		} elseif ( 'create' === $fix || 'restore' === $fix || 'publish' === $fix ) {
			$g = static::guard();
			if ( class_exists( $g ) ) {
				try {
					$g::repair( $fix );
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
		}

		// 5. Powrót tam, skąd przyszedł właściciel.
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
	 * @param string $fix create|restore|publish|dismiss.
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
	 * SZEW TESTOWY — jedyna droga do policzenia wywołań `repair()`.
	 *
	 * @return string Pełna nazwa klasy strażnika podstrony.
	 */
	protected static function guard(): string {
		return '\\AIFAQ\\PublicUi\\PageGuard';
	}

	/**
	 * Składa wiersz komunikatu dla danego statusu.
	 *
	 * @param string $status Status z {@see \AIFAQ\PublicUi\PageGuard}.
	 * @param array  $state  Pełny stan podstrony (6 kluczy).
	 * @param string $guard  Nazwa klasy strażnika (szew).
	 * @return array Klucze: level, msg, actions, dismiss. Pusta tablica = nic nie wypisujemy.
	 */
	private static function row( string $status, array $state, string $guard ): array {
		$id    = (int) $state['id'];
		$tries = (int) $state['tries'];
		$error = trim( (string) $state['error'] );

		// Szkic albo wpis, który przestał być naszą podstroną: przycisk „Opublikuj"
		// opublikowałby właścicielowi na żywej witrynie jego niedokończoną stronę.
		// Zamiast tego proponujemy utworzenie podstrony od nowa.
		if ( 'not_public' === $status && ! self::has_shortcode( $id ) ) {
			$status = 'missing';
		}

		switch ( $status ) {
			case 'ok':
				$actions = array();
				$url     = self::page_url( $guard );
				if ( '' !== $url ) {
					$actions[] = array(
						'url'   => $url,
						'label' => __( 'Otwórz podstronę «Generator FAQ»', 'ai-faq-generator' ),
					);
				}

				return array(
					'level'   => 'notice-success',
					'msg'     => __( 'Podstrona generatora działa.', 'ai-faq-generator' ),
					'actions' => $actions,
					'dismiss' => true,
				);

			case 'missing':
				return array(
					'level'   => 'notice-warning',
					'msg'     => __( 'Podstrona generatora jeszcze nie powstała.', 'ai-faq-generator' ),
					'actions' => array(
						array(
							'url'   => self::action_url( 'create' ),
							'label' => __( 'Utwórz podstronę teraz', 'ai-faq-generator' ),
						),
					),
					'dismiss' => true,
				);

			case 'failed':
				$msg = self::with_error( __( 'Nie udało się utworzyć podstrony generatora.', 'ai-faq-generator' ), $error );
				/* translators: %d: liczba nieudanych prób utworzenia podstrony. */
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

			case 'trashed':
				return array(
					'level'   => 'notice-warning',
					'msg'     => self::with_error( __( 'Podstrona generatora jest w koszu — goście dostają błąd 404.', 'ai-faq-generator' ), $error ),
					'actions' => array(
						array(
							'url'   => self::action_url( 'restore' ),
							'label' => __( 'Przywróć podstronę', 'ai-faq-generator' ),
						),
					),
					'dismiss' => true,
				);

			case 'not_public':
				return array(
					'level'   => 'notice-warning',
					'msg'     => self::with_error( __( 'Podstrona generatora nie jest opublikowana — widzisz ją tylko Ty.', 'ai-faq-generator' ), $error ),
					'actions' => array(
						array(
							'url'   => self::action_url( 'publish' ),
							'label' => __( 'Opublikuj podstronę', 'ai-faq-generator' ),
						),
					),
					'dismiss' => true,
				);

			case 'no_shortcode':
				$actions = array();
				$edit    = self::edit_url( $id );
				if ( '' !== $edit ) {
					$actions[] = array(
						'url'   => $edit,
						'label' => __( 'Otwórz w edytorze', 'ai-faq-generator' ),
					);
				}

				return array(
					'level'   => 'notice-warning',
					'msg'     => __( 'Z podstrony zniknął shortcode [aifaq_generator] — generator się nie wyświetli.', 'ai-faq-generator' ),
					'actions' => $actions,
					'dismiss' => true,
				);

			case 'slug_taken':
				return array(
					'level'   => 'notice-error',
					'msg'     => __( 'Adres szybkiej trasy jest taki sam jak adres podstrony — podstrona jest niedostępna.', 'ai-faq-generator' ),
					'actions' => array(
						array(
							'url'   => self::admin_link( 'admin.php?page=ai-faq-generator-settings' ),
							'label' => __( 'Otwórz ustawienia', 'ai-faq-generator' ),
						),
					),
					// Jedyny wiersz bez „Nie pokazuj więcej": ten stan czyni produkt
					// niedostępnym dla gości, a znika po jednym kliknięciu w Ustawieniach.
					// Zamknięcie komunikatu byłoby wyłączeniem alarmu.
					'dismiss' => false,
				);

			case 'deleted':
				return array(
					'level'   => 'notice-info',
					'msg'     => __( 'Podstrona generatora została usunięta. Generator działa nadal pod adresem szybkiej trasy.', 'ai-faq-generator' ),
					'actions' => array(
						array(
							'url'   => self::action_url( 'create' ),
							'label' => __( 'Utwórz podstronę ponownie', 'ai-faq-generator' ),
						),
					),
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
	 * Zapisuje zamknięcie komunikatu wraz ze statusem, którego dotyczy.
	 *
	 * Tu świeża diagnoza jest właściwa: właściciel zamyka to, co widzi TERAZ.
	 */
	private static function remember_dismissal(): void {
		$status = '';
		$g      = static::guard();

		if ( class_exists( $g ) && method_exists( $g, 'page_state' ) ) {
			try {
				$state = $g::page_state();
				if ( is_array( $state ) && isset( $state['status'] ) ) {
					$status = (string) $state['status'];
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
	 * Czy wpis o tym ID to wciąż nasza podstrona (strona z shortcode'em)?
	 *
	 * @param int $id ID wpisu.
	 * @return bool
	 */
	private static function has_shortcode( int $id ): bool {
		if ( $id <= 0 || ! function_exists( 'get_post' ) ) {
			return false;
		}

		try {
			$post = get_post( $id );
		} catch ( \Throwable $e ) {
			unset( $e );
			return false;
		}

		if ( ! is_object( $post ) || ! isset( $post->post_type ) || 'page' !== (string) $post->post_type ) {
			return false;
		}

		$content = isset( $post->post_content ) ? (string) $post->post_content : '';

		return false !== strpos( $content, '[' . self::shortcode_tag() );
	}

	/**
	 * Adres podstrony ze strażnika (nigdy sklejany z slugu — WP nadaje sufiksy).
	 *
	 * @param string $guard Nazwa klasy strażnika (szew).
	 * @return string Adres albo pusty string.
	 */
	private static function page_url( string $guard ): string {
		if ( ! class_exists( $guard ) || ! method_exists( $guard, 'page_url' ) ) {
			return '';
		}

		try {
			return (string) $guard::page_url();
		} catch ( \Throwable $e ) {
			unset( $e );
			return '';
		}
	}

	/**
	 * Adres edycji wpisu; pusty string, gdy nieznany lub bez uprawnień.
	 *
	 * @param int $id ID wpisu.
	 * @return string
	 */
	private static function edit_url( int $id ): string {
		if ( $id <= 0 || ! function_exists( 'get_edit_post_link' ) ) {
			return '';
		}

		return (string) get_edit_post_link( $id );
	}

	/**
	 * Znacznik shortcode'u — ze stałej, gdy klasa istnieje, inaczej literał.
	 *
	 * @return string
	 */
	private static function shortcode_tag(): string {
		if ( class_exists( '\AIFAQ\PublicUi\Shortcode' ) ) {
			try {
				$tag = (string) \AIFAQ\PublicUi\Shortcode::TAG;
				if ( '' !== $tag ) {
					return $tag;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		return 'aifaq_generator';
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
	 * @param string $error Przyczyna ze stanu podstrony.
	 * @return string
	 */
	private static function with_error( string $msg, string $error ): string {
		return '' === $error ? $msg : $msg . ' ' . $error;
	}

	/**
	 * Pełny, typowany kształt stanu (6 kluczy) — na wypadek śmieci w opcji.
	 *
	 * @return array
	 */
	private static function blank_state(): array {
		return array(
			'status'  => '',
			'id'      => 0,
			'tries'   => 0,
			'last'    => 0,
			'error'   => '',
			'deleted' => 0,
		);
	}
}
