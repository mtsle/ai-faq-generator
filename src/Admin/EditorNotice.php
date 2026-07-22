<?php
/**
 * Podpowiedź w edytorze wpisu: gdzie szukać panelu „AI FAQ" (Krok 20).
 *
 * Zamyka dług Kroku 16 („odkrywalność metaboksu”): w WordPressie 7.0 klasyczne
 * metaboksy siedzą w ZWINIĘTEJ szufladzie „Meta Boxes" na dole edytora
 * (`aria-expanded="false"`, obszar `display:none`, widoczny 32-pikselowy uchwyt).
 * Redaktor, który nie wie, że ma ją rozwinąć, nie zobaczy panelu NIGDY — a to
 * jedyne miejsce, w którym generator FAQ spotyka się z pisaniem treści.
 *
 * Czego ta klasa świadomie NIE robi (KONTRAKT k20-v3 §4.3):
 * - nie zmienia `context` metaboksu (`'normal'` jest pinowane testem Kroku 16),
 * - nie dokłada ANI JEDNEGO BAJTU JS-a — zależność od pakietu `wp-edit-post`
 *   (rozwijanie szuflady z kodu) jest w tym Kroku zakazana,
 * - nie dotyka {@see PostMetaBox}.
 *
 * Hook `admin_post_aifaq_editor_hint` i wypis rejestruje {@see \AIFAQ\Core\Plugin}.
 *
 * @package AI_FAQ_Generator
 */

namespace AIFAQ\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Jednorazowa podpowiedź o szufladzie „Meta Boxes" w edytorze wpisu.
 *
 * KONTRAKT §13.7: komunikat WISI do kliknięcia „nie pokazuj więcej” i po nim
 * NIGDY nie wraca. Wzorzec „wyciszenie wraz ze statusem” z {@see PageNotice}
 * tej klasy NIE dotyczy — nie ma tu żadnego statusu, jest jedna informacja.
 */
class EditorNotice {

	/**
	 * Akcja `admin-post.php` i zarazem nazwa nonce'a.
	 */
	public const ACTION = 'aifaq_editor_hint';

	/**
	 * Parametr GET; whitelista ZAMKNIĘTA i jednoelementowa: dismiss.
	 */
	public const PARAM = 'aifaq_fix';

	/**
	 * Klucz metadanych UŻYTKOWNIKA z zamknięciem podpowiedzi.
	 *
	 * PER UŻYTKOWNIK, nie per witryna (KONTRAKT §4.2, poprawka FZ16): przy opcji
	 * globalnej pierwszy Autor klikający „nie pokazuj więcej" gasiłby podpowiedź
	 * CAŁEJ REDAKCJI — czyli jedyny mechanizm odkrywalności metaboksu przestawałby
	 * działać dla wszystkich, którzy go nigdy nie zobaczyli. Idiom rdzenia dla
	 * podpowiedzi per osoba to metadane użytkownika (`dismissed_wp_pointers`).
	 *
	 * Świadomie NIE MA go w `uninstall.php` — to nie jest opcja witryny.
	 */
	public const USER_META = 'aifaq_editor_hint_done';

	/**
	 * Wypisuje podpowiedź na ekranie edycji wpisu (callback `admin notices`).
	 */
	public static function render(): void {
		// 1. Ekran: WYŁĄCZNIE edytor wpisu (`post.php`, `post-new.php`).
		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}
		$screen = get_current_screen();

		// Guard `is_object()` jak w PageNotice — odczyt ->id z nulla to ostrzeżenie
		// PHP 8.2 wypisane wprost na górze kokpitu klienta.
		if ( ! is_object( $screen ) || ! self::is_editor_screen( $screen ) ) {
			return;
		}

		// 2. Uprawnienia: CAP NARZĘDZIA, nie `manage_options`. Ta podpowiedź jest
		// dla Redaktora i Autora — administrator zna swój kokpit, a to właśnie
		// redakcja gubi metabox w zwiniętej szufladzie.
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( self::tool_capability() ) ) {
			return;
		}

		// 3. Zamknięte raz = zamknięte na zawsze, dla TEJ osoby.
		if ( self::dismissed() ) {
			return;
		}

		printf(
			'<div class="notice notice-info is-dismissible"><p><strong>AI FAQ Generator:</strong> %1$s</p><p><a class="button-link" href="%2$s">%3$s</a></p></div>',
			esc_html__( 'Panel «AI FAQ» znajdziesz na dole edytora, w zwiniętej szufladzie «Meta Boxes» — rozwiń ją raz, a WordPress zapamięta.', 'ai-faq-generator' ),
			esc_url( self::action_url( 'dismiss' ) ),
			esc_html__( 'Nie pokazuj więcej', 'ai-faq-generator' )
		);
	}

	/**
	 * Obsługuje kliknięcie „nie pokazuj więcej" (hook `admin_post_aifaq_editor_hint`).
	 *
	 * KONTRAKT §4.3 (poprawka FZ18) — kolejność ZAMROŻONA: cap → nonce → whitelista
	 * → przekierowanie. `admin_post_<akcja>` jest wykonywalne przez KAŻDEGO
	 * zalogowanego użytkownika, także Subskrybenta.
	 */
	public static function handle_fix(): void {
		// 1. Uprawnienia (cap narzędzia). `return` OBOWIĄZKOWY — atrapa `wp_die()`
		// w teście nie przerywa wykonania.
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( self::tool_capability() ) ) {
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

		// 3. Whitelista ZAMKNIĘTA: dismiss.
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
			self::remember_dismissal();
		}

		// 4. Powrót do edytora, z którego przyszedł użytkownik.
		if ( function_exists( 'wp_safe_redirect' ) ) {
			$referer = function_exists( 'wp_get_referer' ) ? wp_get_referer() : '';
			$back    = $referer ? (string) $referer : self::admin_link( '' );
			wp_safe_redirect( $back );
		}

		if ( ! defined( 'AIFAQ_TESTING' ) ) {
			exit;
		}

		return;
	}

	/**
	 * Buduje adres akcji wraz z nonce'em.
	 *
	 * @param string $fix dismiss.
	 * @return string
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
	 * Cap narzędzia — JEDYNE źródło prawdy leży w cudzym pliku (`RestController`).
	 *
	 * Odwołanie do nieistniejącej stałej to w PHP 8 fatalny `Error`, więc czytamy
	 * ją przez `defined()`/`constant()` z jawnym fallbackiem (KONTRAKT §11.3).
	 * Filtr `aifaq_tool_capability` nakładamy tak samo jak `tool_capability()`:
	 * witryna zawężająca cap filtrem MUSI zawęzić także tę podpowiedź, inaczej
	 * obiecywalibyśmy panel roli, która przy „Generuj" dostanie 403.
	 *
	 * @return string
	 */
	public static function tool_capability(): string {
		$cap = 'publish_posts';

		if ( class_exists( '\AIFAQ\Rest\RestController' )
			&& defined( '\AIFAQ\Rest\RestController::CAPABILITY_TOOL' ) ) {
			$cap = (string) constant( '\AIFAQ\Rest\RestController::CAPABILITY_TOOL' );
		}

		if ( function_exists( 'apply_filters' ) ) {
			$cap = (string) apply_filters( 'aifaq_tool_capability', $cap );
		}

		return '' === $cap ? 'publish_posts' : $cap;
	}

	/**
	 * Czy bieżący ekran to edytor pojedynczego wpisu/strony?
	 *
	 * `admin_notices` nie dostaje `$hook_suffix` (tak bramkuje się metabox
	 * w {@see PostMetaBox::enqueue()}), więc ekran rozpoznajemy po `WP_Screen`:
	 * `base === 'post'` obejmuje `post.php` I `post-new.php`, a `id` jest wtedy
	 * typem wpisu (`post`, `page`). Lista wpisów (`edit.php`) ma `base === 'edit'`
	 * i `id === 'edit-post'` — i ma tu MILCZEĆ.
	 *
	 * @param object $screen Obiekt ekranu kokpitu.
	 * @return bool
	 */
	private static function is_editor_screen( object $screen ): bool {
		$base = isset( $screen->base ) ? (string) $screen->base : '';
		$id   = isset( $screen->id ) ? (string) $screen->id : '';

		$now = isset( $GLOBALS['pagenow'] ) ? (string) $GLOBALS['pagenow'] : '';

		$is_editor = ( 'post' === $base )
			|| ( '' === $base && in_array( $id, array( 'post', 'page' ), true ) )
			|| ( 'post.php' === $now || 'post-new.php' === $now );

		if ( ! $is_editor ) {
			return false;
		}

		// Metabox istnieje tylko dla typów obsługiwanych przez PostMetaBox —
		// na innym typie wpisu podpowiedź kłamałaby. Typ nieznany = przepuszczamy.
		$type = isset( $screen->post_type ) ? (string) $screen->post_type : '';
		if ( '' === $type ) {
			return true;
		}

		return in_array( $type, self::supported_types(), true );
	}

	/**
	 * Typy wpisów z metaboksem — ze stałej cudzej klasy, z fallbackiem.
	 *
	 * @return array<int,string>
	 */
	private static function supported_types(): array {
		if ( class_exists( '\AIFAQ\Admin\PostMetaBox' ) && defined( '\AIFAQ\Admin\PostMetaBox::POST_TYPES' ) ) {
			$types = constant( '\AIFAQ\Admin\PostMetaBox::POST_TYPES' );
			if ( is_array( $types ) && ! empty( $types ) ) {
				return array_map( 'strval', $types );
			}
		}

		return array( 'post', 'page' );
	}

	/**
	 * Czy bieżący użytkownik zamknął już podpowiedź?
	 *
	 * @return bool
	 */
	private static function dismissed(): bool {
		$uid = self::current_user_id();
		if ( $uid <= 0 || ! function_exists( 'get_user_meta' ) ) {
			return false;
		}

		return '1' === (string) get_user_meta( $uid, self::USER_META, true );
	}

	/**
	 * Zapisuje zamknięcie podpowiedzi dla BIEŻĄCEGO użytkownika.
	 */
	private static function remember_dismissal(): void {
		$uid = self::current_user_id();
		if ( $uid <= 0 || ! function_exists( 'update_user_meta' ) ) {
			return;
		}

		update_user_meta( $uid, self::USER_META, '1' );
	}

	/**
	 * ID zalogowanego użytkownika (0 poza WordPressem).
	 *
	 * @return int
	 */
	private static function current_user_id(): int {
		return function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
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
}
