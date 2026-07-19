<?php
/**
 * Konfiguracja i teksty ekranu „Narzędzie FAQ" (kokpit) — CIENKIE PROXY.
 *
 * Od Kroku 18 jedynym źródłem prawdy dla narzędzia (markup + `config()` + `strings()`)
 * jest {@see \AIFAQ\App\FaqToolPanel} — ten sam komponent renderuje się w kokpicie
 * i na podstronie `/generator-faq/`, więc identyfikatory i teksty nie mają jak się
 * rozjechać. Klasa zostaje wyłącznie jako stabilny adres dla dotychczasowych
 * wywołań ({@see \AIFAQ\Admin\Menu::enqueue_assets()} i `views/faq-tool.php`).
 *
 * Proxy niczego nie modyfikuje, nie filtruje ani nie dokłada do wyniku.
 *
 * @package AI_FAQ_Generator
 */

namespace AIFAQ\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dostawca konfiguracji (window.aifaqFaqTool) i tekstów UI dla ekranu narzędzia.
 */
class FaqToolPage {

	/**
	 * Konfiguracja dla JS (bez sekretów). Trafia do `window.aifaqFaqTool`.
	 *
	 * @return array<string,mixed>
	 */
	public static function config(): array {
		return \AIFAQ\App\FaqToolPanel::config();
	}

	/**
	 * Teksty UI ekranu (pl/en/de wg Settings) — 34 klucze.
	 *
	 * @param string $lang Kod języka.
	 * @return array<string,string>
	 */
	public static function strings( string $lang ): array {
		return \AIFAQ\App\FaqToolPanel::strings( $lang );
	}
}
