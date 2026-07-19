<?php
/**
 * Shortcode `[aifaq_generator]` + automatyczna podstrona „Generator FAQ".
 *
 * Trasa `/faqgenerator` ({@see \AIFAQ\Core\Router}) renderuje generator jako
 * osobny dokument HTML poza motywem — jest przewidywalna, ale NIEWIDOCZNA:
 * nie ma jej w Stronach, więc klient nie doda jej do menu nawigacji.
 *
 * Ta klasa domyka wejście dla gościa:
 * 1. `[aifaq_generator]` — generator na dowolnej stronie/wpisie, wewnątrz motywu;
 * 2. przy pierwszym uruchomieniu wtyczki powstaje podstrona z tym shortcode'em,
 *    żeby działało bez czytania instrukcji.
 *
 * Zawartość jest rola-aware (tak samo jak trasa): gość widzi sam generator,
 * właściciel pełny panel — decyduje {@see \AIFAQ\App\AppShell::render_body()}.
 *
 * @package AI_FAQ_Generator
 */

namespace AIFAQ\PublicUi;

use AIFAQ\App\AppShell;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rejestracja shortcode'u i podstrony generatora.
 */
class Shortcode {

	/**
	 * Znacznik shortcode'u.
	 */
	const TAG = 'aifaq_generator';

	/**
	 * Slug automatycznie tworzonej podstrony.
	 *
	 * CELOWO różny od {@see \AIFAQ\Core\Router::slug()} (`faqgenerator`) —
	 * reguła rewrite trasy jest rejestrowana z priorytetem `top`, więc strona
	 * o tym samym slugu byłaby przez nią przesłonięta i nigdy by się nie pokazała.
	 */
	const PAGE_SLUG = 'generator-faq';

	/**
	 * Opcja z ID utworzonej podstrony.
	 */
	const PAGE_OPTION = 'aifaq_page_id';

	/**
	 * Opcja-znacznik „próba utworzenia strony już była".
	 *
	 * Bez niej klient, który świadomie skasuje podstronę, dostawałby ją z powrotem
	 * przy każdym ładowaniu WordPressa.
	 */
	const BOOTSTRAP_OPTION = 'aifaq_page_bootstrapped';

	/**
	 * Rejestruje hooki (wołane z Plugin::init_hooks).
	 */
	public function register(): void {
		add_shortcode( self::TAG, array( __CLASS__, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue' ) );
		// Priorytet 0: nagłówki „nie cache'uj" muszą polecieć ZANIM zacznie się
		// wyjście — `wp_enqueue_scripts` i `the_content` są na to za późno.
		add_action( 'template_redirect', array( $this, 'maybe_nocache' ), 0 );
		// Odrzucony przebieg `the_content` (wyciąg, RSS, podgląd page buildera) nie
		// może wypalić slotu jednokrotnego renderu panelu — zerujemy go na starcie pętli.
		add_action( 'loop_start', array( __CLASS__, 'reset_panel_flag' ) );
		// Priorytet 30: po rejestracji typów treści, żeby `wp_insert_post` miał `page`.
		add_action( 'init', array( $this, 'maybe_ensure_page' ), 30 );
	}

	/**
	 * Renderuje generator w miejscu shortcode'u.
	 *
	 * Trzy bramki na wejściu odsiewają przebiegi filtra `the_content`, które NIE są
	 * właściwym renderem treści: kanały RSS, budowanie wyciągu (`wp_trim_excerpt()`
	 * wołane przez `get_the_excerpt()`, a przez nie wtyczki SEO składające
	 * `og:description` jeszcze w `wp_head`) oraz zapytania poboczne i podglądy page
	 * builderów. Bez nich taki przebieg skonsumowałby slot jednokrotnego renderu
	 * panelu ({@see \AIFAQ\App\FaqToolPanel::widget()}) i właściciel zobaczyłby
	 * zakładkę „Narzędzie FAQ" z nagłówkiem, ale PUSTĄ w środku — bez błędu w konsoli.
	 *
	 * @param array<string,string>|string $atts    Atrybuty shortcode'u (nieużywane).
	 * @param string|null                 $content Treść między znacznikami (nieużywana).
	 * @param string                      $tag     Nazwa znacznika (nieużywana).
	 * @return string Markup (zbudowany z `esc_*` w AppShell/GeneratorPage).
	 */
	public static function render( $atts = array(), $content = '', $tag = '' ): string {
		unset( $atts, $content, $tag );

		if ( function_exists( 'is_feed' ) && is_feed() ) {
			return '';
		}

		if ( function_exists( 'doing_filter' )
			&& ( doing_filter( 'get_the_excerpt' ) || doing_filter( 'wp_trim_excerpt' ) ) ) {
			return '';
		}

		if ( function_exists( 'is_main_query' ) && function_exists( 'in_the_loop' )
			&& ! ( is_main_query() && in_the_loop() ) ) {
			return '';
		}

		// Zabezpieczenie na wypadek shortcode'u w widgecie/szablonie bloków,
		// gdzie `maybe_enqueue()` nie zdążyło go wykryć w treści wpisu.
		self::enqueue_assets();

		return AppShell::render_body();
	}

	/**
	 * Zeruje flagę jednokrotnego renderu panelu narzędzia (hook `loop_start`).
	 *
	 * Dzięki temu przebieg odrzucony przez bramki {@see render()} nie zatruwa
	 * właściwego renderu treści.
	 */
	public static function reset_panel_flag(): void {
		if ( class_exists( '\AIFAQ\App\FaqToolPanel' ) ) {
			\AIFAQ\App\FaqToolPanel::reset_render_flag();
		}
	}

	/**
	 * Wyłącza cache dla odpowiedzi niosącej panel właściciela (hook `template_redirect`).
	 *
	 * Ścieżka shortcode'owa to ZWYKŁA, cache'owalna strona WP — a od Kroku 18 niesie
	 * właścicielowi nonce `wp_rest`, komplet adresów `/admin/*`, statystyki bazy wiedzy
	 * i zakładkę Ustawienia. Brzegowy cache kluczujący po URL (Cloudflare „Cache
	 * Everything", Varnish, LiteSpeed, WP Rocket, Batcache) podałby ten HTML
	 * ANONIMOWEMU GOŚCIOWI. Gość nie dostaje ani nagłówka, ani stałej — jego strona
	 * zostaje cache'owalna, co jest pożądane.
	 */
	public function maybe_nocache(): void {
		if ( function_exists( 'is_singular' ) && ! is_singular() ) {
			return;
		}

		$post = function_exists( 'get_post' ) ? get_post() : null;

		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		if ( ! function_exists( 'has_shortcode' )
			|| ! has_shortcode( (string) $post->post_content, self::TAG ) ) {
			return;
		}

		if ( ! \AIFAQ\App\AppShell::is_owner() ) {
			return;
		}

		if ( function_exists( 'nocache_headers' ) ) {
			nocache_headers();
		}

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
	}

	/**
	 * Wpina style i skrypty, gdy oglądany wpis zawiera nasz shortcode.
	 *
	 * Wykrycie na `wp_enqueue_scripts` (a nie dopiero w `render()`) sprawia, że
	 * CSS trafia do `<head>` — inaczej strona mignęłaby nieostylowana.
	 */
	public function maybe_enqueue(): void {
		if ( ! is_singular() ) {
			return;
		}

		$post = get_post();

		if ( ! $post instanceof \WP_Post || ! has_shortcode( (string) $post->post_content, self::TAG ) ) {
			return;
		}

		self::enqueue_assets();
	}

	/**
	 * Wpina assety generatora (idempotentne — WP pomija już wpięte uchwyty).
	 */
	public static function enqueue_assets(): void {
		$ver = AIFAQ_VERSION;

		wp_enqueue_style(
			'aifaq-generator',
			AIFAQ_PLUGIN_URL . 'assets/css/generator.css',
			array(),
			$ver
		);

		wp_enqueue_script(
			'aifaq-generator',
			AIFAQ_PLUGIN_URL . 'assets/js/generator.js',
			array(),
			$ver,
			true
		);

		// `wp_add_inline_script`, nie `wp_localize_script` — ten drugi rzutuje
		// liczby na stringi, a JS porównuje `maxLen` liczbowo.
		wp_add_inline_script(
			'aifaq-generator',
			'window.aifaqFront = ' . wp_json_encode( GeneratorPage::config() ) . ';',
			'before'
		);

		if ( ! AppShell::is_owner() ) {
			return;
		}

		// Pas i szelki dla wtyczek cache decydujących dopiero na `shutdown`; właściwe
		// nagłówki wysyła {@see maybe_nocache()} na `template_redirect` (tutaj wyjście
		// już się zaczęło, więc `nocache_headers()` byłoby martwe).
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		wp_enqueue_style( 'aifaq-app', AIFAQ_PLUGIN_URL . 'assets/css/app.css', array( 'aifaq-generator' ), $ver );
		wp_enqueue_script( 'aifaq-app', AIFAQ_PLUGIN_URL . 'assets/js/app.js', array( 'aifaq-generator' ), $ver, true );
		wp_add_inline_script(
			'aifaq-app',
			'window.aifaqApp = ' . wp_json_encode( AppShell::config() ) . ';',
			'before'
		);

		// Krok 18 — narzędzie FAQ ożywa także na froncie. Uchwyty są te same co
		// w `Menu::enqueue_assets()`, więc wpięcie jest idempotentne. Średnik na końcu
		// wstrzykiwanego kodu jest OBOWIĄZKOWY: `wp_add_inline_script` skleja fragmenty
		// tego samego uchwytu.
		wp_enqueue_style( 'aifaq-faq-tool', AIFAQ_PLUGIN_URL . 'assets/css/faq-tool.css', array( 'aifaq-generator' ), $ver );
		wp_enqueue_script( 'aifaq-faq-tool', AIFAQ_PLUGIN_URL . 'assets/js/faq-tool.js', array(), $ver, true );
		wp_add_inline_script(
			'aifaq-faq-tool',
			'window.aifaqFaqTool = ' . wp_json_encode( \AIFAQ\App\FaqToolPanel::config() ) . ';',
			'before'
		);
	}

	/**
	 * Pilnuje podstrony generatora na `init` — TANIO.
	 *
	 * `init` odpala się na KAŻDYM żądaniu witryny (także gościa, bota, `wp-cron.php`
	 * i `admin-ajax.php`), a pełna diagnoza stanu podstrony kosztuje 2-3 zapytania
	 * plus zapis opcji. Broni jej jedna AUTOLOADOWANA opcja `aifaq_page_ok`,
	 * trójstanowa: `'1'` = ok, `'0'` = stan terminalny (front i tak go nie naprawi —
	 * podstrona skasowana świadomie, w koszu, nieopublikowana, bez shortcode'u albo
	 * przesłonięta kolizją slugu), `''` = jest co robić. Flagę utrzymuje
	 * `PageGuard::save_state()`; tutaj tylko ją czytamy.
	 */
	public function maybe_ensure_page(): void {
		if ( ! class_exists( '\AIFAQ\PublicUi\PageGuard' ) ) {
			if ( get_option( self::BOOTSTRAP_OPTION ) ) {
				return;
			}

			self::legacy_ensure_page();
			return;
		}

		if ( '' !== (string) get_option( 'aifaq_page_ok', '' ) ) {
			return;
		}

		try {
			\AIFAQ\PublicUi\PageGuard::ensure();
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Tworzy (lub odnajduje) podstronę z shortcode'em generatora.
	 *
	 * Wołane przy aktywacji ({@see \AIFAQ\Core\Activator}). Sygnatura i typ zwrotny
	 * są NIETKNIĘTE — cała diagnoza i naprawa mieszka od Kroku 18 w {@see PageGuard}.
	 *
	 * @return int ID strony albo 0, gdy nie udało się jej utworzyć.
	 */
	public static function ensure_page(): int {
		if ( class_exists( '\AIFAQ\PublicUi\PageGuard' ) ) {
			try {
				$state = \AIFAQ\PublicUi\PageGuard::ensure();
				return (int) ( $state['id'] ?? 0 );
			} catch ( \Throwable $e ) {
				unset( $e );
				return 0;
			}
		}

		return self::legacy_ensure_page();
	}

	/**
	 * Dawne ciało `ensure_page()` — wyłącznie bezpiecznik, gdy nie ma {@see PageGuard}.
	 *
	 * Znane wady (flaga zapisywana PRZED próbą, cicha porażka, przejmowanie szkicu)
	 * są tu ZOSTAWIONE świadomie: naprawą jest `PageGuard`, a ta ścieżka po spięciu
	 * Kroku 18 nigdy się nie wykonuje.
	 *
	 * @return int ID strony albo 0, gdy nie udało się jej utworzyć.
	 */
	private static function legacy_ensure_page(): int {
		update_option( self::BOOTSTRAP_OPTION, '1' );

		// 1. Znamy ID i strona nadal żyje — nic nie robimy.
		$existing = (int) get_option( self::PAGE_OPTION, 0 );

		if ( $existing > 0 ) {
			$post = get_post( $existing );

			if ( $post instanceof \WP_Post && 'page' === $post->post_type && 'trash' !== $post->post_status ) {
				return $existing;
			}
		}

		// 2. Strona o naszym slugu już jest (np. po reinstalacji, gdy opcja przepadła)
		//    — przejmujemy ją zamiast tworzyć duplikat „generator-faq-2".
		$found = get_page_by_path( self::PAGE_SLUG );

		if ( $found instanceof \WP_Post ) {
			update_option( self::PAGE_OPTION, (int) $found->ID );
			return (int) $found->ID;
		}

		// 3. Tworzymy.
		$id = wp_insert_post(
			array(
				'post_title'     => GeneratorPage::page_title(),
				'post_name'      => self::PAGE_SLUG,
				'post_content'   => '<!-- wp:shortcode -->[' . self::TAG . ']<!-- /wp:shortcode -->',
				'post_status'    => 'publish',
				'post_type'      => 'page',
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
			),
			true
		);

		if ( is_wp_error( $id ) || ! $id ) {
			return 0;
		}

		update_option( self::PAGE_OPTION, (int) $id );

		return (int) $id;
	}
}
