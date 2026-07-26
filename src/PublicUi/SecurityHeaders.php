<?php
/**
 * Nagłówki bezpieczeństwa HTTP dla podstrony generatora (Krok 21).
 *
 * Wysyłane WYŁĄCZNIE na dwóch adresach, które renderują nasz generator:
 *  1. trasa standalone `/faqgenerator` ({@see \AIFAQ\Core\Router}) — dokument w
 *     100% zbudowany przez wtyczkę (bez `wp_head()`/`wp_footer()`, bez motywu,
 *     bez cudzych skryptów) → wolno tu wysłać PEŁNE, restrykcyjne CSP;
 *  2. podstrona z shortcode'em `[aifaq_generator]` (Krok 17/18) — ZWYKŁA strona
 *     WordPressa wewnątrz motywu klienta, obok dowolnych innych wtyczek
 *     (analytics, cookie bannery, page buildery) → pełne CSP mogłoby złamać
 *     coś, czego ta wtyczka nie widzi i nie kontroluje. Dostaje tylko
 *     nagłówki, które NIC nie ograniczają w ładowaniu zasobów: nosniff,
 *     Referrer-Policy, Permissions-Policy (funkcje, których i tak nikt tu nie
 *     używa) i CSP zawężone do samego `frame-ancestors` (ochrona przed
 *     clickjackingiem, zero wpływu na skrypty/style/obrazy).
 *
 * ŚWIADOMIE POMINIĘTE:
 *  - `Strict-Transport-Security` — odpowiedzialność serwera/CDN (§16 zlecenia):
 *    wtyczka nie wie, czy certyfikat całej witryny jest poprawny ani czy każda
 *    podstrona jest dostępna po HTTPS; błędne HSTS potrafi zablokować
 *    właścicielowi dostęp do WŁASNEJ witryny.
 *  - Nagłówki na TRASACH poza tymi dwiema (reszta witryny klienta) — hosting,
 *    CDN albo inna wtyczka mogą już coś tam wysyłać; ta klasa nigdy tego nie
 *    nadpisuje ani nie dubluje.
 *
 * Każdy zestaw przechodzi przez filtr {@see apply_filters()} `aifaq_security_headers`
 * — furtka dla instalacji, którym ta konkretna polityka przeszkadza (np. właściciel
 * świadomie osadza podstronę w iframe na własnej drugiej domenie).
 *
 * @package AI_FAQ_Generator
 */

namespace AIFAQ\PublicUi;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wysyła nagłówki bezpieczeństwa dla podstrony generatora.
 */
class SecurityHeaders {

	/**
	 * Nonce CSP bieżącego żądania — WSPÓLNY dla nagłówka i znaczników `<script>`
	 * wypisywanych przez {@see \AIFAQ\PublicUi\GeneratorPage::render_standalone()}.
	 *
	 * Ustalany RAZ na żądanie (leniwie, przy pierwszym wywołaniu {@see nonce()}),
	 * żeby wartość w nagłówku i w atrybutach `nonce="…"` była BIT W BIT ta sama —
	 * inna wartość w którymkolwiek miejscu unieważniłaby wszystkie inline skrypty
	 * standalone strony (biała strona, zero JS).
	 *
	 * @var string
	 */
	private static string $nonce = '';

	/**
	 * Rejestruje hook (wołane z {@see \AIFAQ\Core\Plugin::init_hooks()}).
	 *
	 * Priorytet 0 — MUSI polecieć przed {@see \AIFAQ\Core\Router::maybe_render()}
	 * (priorytet domyślny 10), bo ten kończy żądanie standalone trasy przez
	 * `exit`. Callbacki zarejestrowane PO `exit` nigdy by nie wystartowały.
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'maybe_send' ), 0 );
	}

	/**
	 * Rozpoznaje kontekst żądania i wysyła odpowiedni zestaw nagłówków.
	 *
	 * Nie robi nic dla żadnej innej strony witryny — brak rozpoznania = brak nagłówków.
	 */
	public function maybe_send(): void {
		if ( $this->is_standalone_route() ) {
			// Nonce MUSI powstać PRZED zbudowaniem nagłówka — headers_for() go czyta.
			$this->emit( self::headers_for( true, self::nonce() ) );
			return;
		}

		if ( $this->is_shortcode_page() ) {
			$this->emit( self::headers_for( false ) );
		}
	}

	/**
	 * Nonce CSP bieżącego żądania (generowany raz, cache'owany w pamięci procesu).
	 *
	 * Używany WYŁĄCZNIE na trasie standalone: `GeneratorPage::render_standalone()`
	 * dokłada go jako atrybut `nonce="…"` do swoich TRZECH inline `<script>` —
	 * jedynych inline skryptów, jakie ta wtyczka kiedykolwiek pisze na tej trasie
	 * (assety zewnętrzne lecą przez `<script src>`, objęte przez `script-src 'self'`
	 * bez potrzeby nonce'u). Dzięki temu CSP standalone NIE potrzebuje już
	 * `'unsafe-inline'` w `script-src`.
	 *
	 * @return string 32-znakowy losowy token (hex).
	 */
	public static function nonce(): string {
		if ( '' !== self::$nonce ) {
			return self::$nonce;
		}

		if ( function_exists( 'wp_generate_password' ) ) {
			self::$nonce = wp_generate_password( 32, false, false );
			return self::$nonce;
		}

		try {
			self::$nonce = bin2hex( random_bytes( 16 ) );
		} catch ( \Throwable $e ) {
			unset( $e );
			self::$nonce = (string) mt_rand( 100000000, 999999999 ) . (string) mt_rand( 100000000, 999999999 );
		}

		return self::$nonce;
	}

	/**
	 * Czy bieżące żądanie trafiło w wirtualną trasę `/faqgenerator`.
	 *
	 * @return bool
	 */
	private function is_standalone_route(): bool {
		if ( ! function_exists( 'get_query_var' ) || ! class_exists( '\AIFAQ\Core\Router' ) ) {
			return false;
		}
		return 1 === (int) get_query_var( \AIFAQ\Core\Router::QUERY_VAR );
	}

	/**
	 * Czy bieżące żądanie renderuje stronę z shortcode'em `[aifaq_generator]`.
	 *
	 * Rozpoznanie IDENTYCZNE jak w {@see Shortcode::maybe_nocache()} i
	 * {@see PageSchema::target_post()} — jedna reguła w całej wtyczce.
	 *
	 * @return bool
	 */
	private function is_shortcode_page(): bool {
		if ( ! function_exists( 'is_singular' ) || ! is_singular() ) {
			return false;
		}

		$post = function_exists( 'get_post' ) ? get_post() : null;

		if ( ! $post instanceof \WP_Post ) {
			return false;
		}

		return function_exists( 'has_shortcode' )
			&& has_shortcode( (string) $post->post_content, Shortcode::TAG );
	}

	/**
	 * Buduje zestaw nagłówków — CZYSTA DECYZJA, zero I/O, testowalna bez WordPressa
	 * (wzorzec {@see \AIFAQ\Core\Plugin::should_flush_cache()}).
	 *
	 * @param bool   $standalone `true` = trasa `/faqgenerator` (własny dokument,
	 *                           wolno CSP zawężające zasoby); `false` = podstrona
	 *                           z shortcode'em wewnątrz motywu (tylko nagłówki
	 *                           neutralne dla ładowania zasobów).
	 * @param string $nonce      Nonce CSP z {@see nonce()} — używany WYŁĄCZNIE gdy
	 *                           `$standalone` jest `true`; ignorowany w przeciwnym razie.
	 * @return array<string,string> Mapa nazwa nagłówka → wartość.
	 */
	public static function headers_for( bool $standalone, string $nonce = '' ): array {
		$headers = array(
			'X-Content-Type-Options' => 'nosniff',
			'Referrer-Policy'        => 'strict-origin-when-cross-origin',
			// Funkcje przeglądarki, których generator nie używa i nigdy nie użyje —
			// wyłączenie ich jest bezpieczne bezwarunkowo, w OBU kontekstach.
			'Permissions-Policy'     => 'geolocation=(), camera=(), microphone=(), payment=(), usb=(), interest-cohort=()',
			// Fallback dla przeglądarek bez obsługi `frame-ancestors` w CSP.
			'X-Frame-Options'        => 'SAMEORIGIN',
		);

		if ( $standalone ) {
			// `script-src` bierze nonce ZAMIAST `'unsafe-inline'` — dokument standalone
			// jest w 100% nasz, więc jedyne inline skrypty to trzy znaczniki w
			// `GeneratorPage::render_standalone()`, oznaczone TYM SAMYM nonce'em.
			// Pusty nonce (wywołanie spoza realnego żądania, np. test) → bezpieczny
			// fallback na `'unsafe-inline'`, żeby nigdy nie wysłać CSP, którego
			// nic nie mogłoby spełnić.
			$script_src = ( '' !== $nonce ) ? "'self' 'nonce-" . $nonce . "'" : "'self' 'unsafe-inline'";

			$headers['Content-Security-Policy'] = "default-src 'self'; script-src {$script_src}; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'";
			// Izolacja dokumentu — bezpieczna WYŁĄCZNIE tutaj: strona jest w 100%
			// nasza i nie ma żadnej legalnej relacji `window.opener` (nikt jej nie
			// otwiera jako popup logowania/płatności). Na podstronie wewnątrz motywu
			// klienta te same nagłówki mogłyby zerwać integrację, której nie widzimy
			// (np. bramkę płatności otwierającą się z tej strony w nowej karcie).
			$headers['Cross-Origin-Opener-Policy']   = 'same-origin';
			$headers['Cross-Origin-Resource-Policy'] = 'same-origin';
		} else {
			$headers['Content-Security-Policy'] = "frame-ancestors 'self'";
		}

		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Pozwala dostroić albo wyłączyć nagłówki bezpieczeństwa generatora.
			 *
			 * @param array<string,string> $headers    Mapa nazwa → wartość.
			 * @param bool                 $standalone Czy trasa standalone `/faqgenerator`.
			 */
			$headers = (array) apply_filters( 'aifaq_security_headers', $headers, $standalone );
		}

		return $headers;
	}

	/**
	 * Wysyła nagłówki (I/O — cienka powłoka nad {@see headers_for()}).
	 *
	 * @param array<string,string> $headers Mapa nazwa → wartość.
	 */
	private function emit( array $headers ): void {
		if ( headers_sent() ) {
			return;
		}

		foreach ( $headers as $name => $value ) {
			if ( ! is_string( $name ) || '' === $name || ! is_scalar( $value ) ) {
				continue;
			}
			header( $name . ': ' . $value, true );
		}
	}
}
