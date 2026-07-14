<?php
/**
 * Router publicznej trasy `/faqgenerator`.
 *
 * Rejestruje wirtualną trasę (rewrite rule + query var) prowadzącą do
 * publicznego generatora FAQ dla gości. Slug jest konfigurowalny w
 * ustawieniach (domyślnie `faqgenerator`).
 *
 * Trasa renderuje publiczny generator (standalone HTML, poza motywem) —
 * {@see \AIFAQ\PublicUi\GeneratorPage}. Ten sam widget zamontujemy w kokpicie
 * w Kroku 9.
 *
 * @package AI_FAQ_Generator
 */

namespace AIFAQ\Core;

use AIFAQ\PublicUi\GeneratorPage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Obsługa wirtualnej trasy publicznego generatora.
 */
class Router {

	/**
	 * Nazwa zmiennej zapytania rozpoznającej naszą trasę.
	 */
	const QUERY_VAR = 'aifaq_page';

	/**
	 * Rejestruje hooki trasy (wywoływane z Plugin::init_hooks).
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'add_rewrite_rules' ) );
		add_action( 'init', array( $this, 'maybe_flush_rewrite' ), 20 );
		add_filter( 'query_vars', array( $this, 'register_query_var' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render' ) );
	}

	/**
	 * Przy ustawionej fladze (po zmianie sluga trasy) przebudowuje reguły rewrite raz.
	 *
	 * Uruchamiane na `init` z priorytetem 20 — PO {@see add_rewrite_rules()}
	 * (priorytet 10), więc świeża reguła nowego sluga jest już zarejestrowana,
	 * zanim `flush_rewrite_rules()` przeliczy i zapisze reguły. Bez tego zmiana
	 * sluga w Ustawieniach kończy się 404 na nowym adresie.
	 */
	public function maybe_flush_rewrite(): void {
		if ( get_option( Settings::FLUSH_FLAG ) ) {
			flush_rewrite_rules();
			delete_option( Settings::FLUSH_FLAG );
		}
	}

	/**
	 * Slug publicznej trasy (z ustawień, z bezpiecznym fallbackiem).
	 */
	public static function slug(): string {
		$slug = (string) Settings::get_field( 'page_slug', 'faqgenerator' );
		$slug = sanitize_title( $slug );
		return ( '' !== $slug ) ? $slug : 'faqgenerator';
	}

	/**
	 * Dodaje regułę rewrite kierującą slug → nasza zmienna zapytania.
	 */
	public function add_rewrite_rules(): void {
		add_rewrite_rule(
			'^' . self::slug() . '/?$',
			'index.php?' . self::QUERY_VAR . '=1',
			'top'
		);
	}

	/**
	 * Rejestruje naszą publiczną zmienną zapytania.
	 *
	 * @param array<int,string> $vars Istniejące zmienne zapytania.
	 * @return array<int,string>
	 */
	public function register_query_var( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Jeśli trafiono w naszą trasę — renderuje publiczny generator FAQ.
	 */
	public function maybe_render(): void {
		if ( 1 !== (int) get_query_var( self::QUERY_VAR ) ) {
			return;
		}

		status_header( 200 );
		nocache_headers();
		GeneratorPage::render_standalone();
		exit;
	}
}
