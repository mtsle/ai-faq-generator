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
		add_shortcode( self::TAG, array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue' ) );
		// Priorytet 30: po rejestracji typów treści, żeby `wp_insert_post` miał `page`.
		add_action( 'init', array( $this, 'maybe_ensure_page' ), 30 );
	}

	/**
	 * Renderuje generator w miejscu shortcode'u.
	 *
	 * @return string Markup (zbudowany z `esc_*` w AppShell/GeneratorPage).
	 */
	public function render(): string {
		// Zabezpieczenie na wypadek shortcode'u w widgecie/szablonie bloków,
		// gdzie `maybe_enqueue()` nie zdążyło go wykryć w treści wpisu.
		self::enqueue_assets();

		return AppShell::render_body();
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

		wp_enqueue_style( 'aifaq-app', AIFAQ_PLUGIN_URL . 'assets/css/app.css', array( 'aifaq-generator' ), $ver );
		wp_enqueue_script( 'aifaq-app', AIFAQ_PLUGIN_URL . 'assets/js/app.js', array( 'aifaq-generator' ), $ver, true );
		wp_add_inline_script(
			'aifaq-app',
			'window.aifaqApp = ' . wp_json_encode( AppShell::config() ) . ';',
			'before'
		);
	}

	/**
	 * Tworzy podstronę generatora przy pierwszym uruchomieniu (raz).
	 */
	public function maybe_ensure_page(): void {
		if ( get_option( self::BOOTSTRAP_OPTION ) ) {
			return;
		}

		self::ensure_page();
	}

	/**
	 * Tworzy (lub odnajduje) podstronę z shortcode'em generatora.
	 *
	 * Wołane przy aktywacji ({@see \AIFAQ\Core\Activator}) i raz na `init`
	 * dla instalacji zaktualizowanych bez reaktywacji.
	 *
	 * @return int ID strony albo 0, gdy nie udało się jej utworzyć.
	 */
	public static function ensure_page(): int {
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
