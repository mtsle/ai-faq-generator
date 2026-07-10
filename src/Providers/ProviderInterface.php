<?php
/**
 * Kontrakt dostawcy AI.
 *
 * Definiuje ABSTRAKCYJNY interfejs providera modelu AI, z którego korzysta
 * pozostała część wtyczki. Interfejs jest GENERYCZNY — nie wie nic o żadnym
 * konkretnym dostawcy, modelu, adresie URL ani endpointcie. Konkretne
 * implementacje dostarczają osobne klasy.
 *
 * @package AI_FAQ_Generator
 */

namespace AIFAQ\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interfejs dostawcy AI.
 */
interface ProviderInterface {

	/**
	 * Generuje tekstową odpowiedź modelu na podany prompt.
	 *
	 * @param string              $prompt  Treść zapytania do modelu.
	 * @param array<string,mixed> $options Opcje generowania. Obsługiwane klucze:
	 *                                      - `model`       (string) — identyfikator modelu,
	 *                                      - `temperature` (float)  — losowość odpowiedzi,
	 *                                      - `max_tokens`  (int)    — limit długości odpowiedzi.
	 *
	 * @return string|\WP_Error
	 *         Przy sukcesie `string` z wygenerowanym tekstem, przy błędzie
	 *         obiekt `\WP_Error`. Metoda NIE rzuca wyjątków — błędy są
	 *         zwracane jako `\WP_Error`.
	 */
	public function generate( string $prompt, array $options = array() );

	/**
	 * Zamienia listę tekstów na wektory (embeddingi).
	 *
	 * @param array<int,string> $texts Lista tekstów do zwektoryzowania.
	 *
	 * @return array<int,array<int,float>>|\WP_Error
	 *         Przy sukcesie lista wektorów `array<int, array<int, float>>` —
	 *         jeden wektor na każdy tekst, w tej samej kolejności co wejście.
	 *         Przy błędzie obiekt `\WP_Error`. Metoda NIE rzuca wyjątków —
	 *         błędy są zwracane jako `\WP_Error`.
	 */
	public function embed( array $texts );

	/**
	 * Lekki test autoryzacji klucza (używany przez „Test połączenia").
	 *
	 * NIE generuje treści ani nie liczy embeddingów — jedynie sprawdza, czy klucz
	 * autoryzuje u dostawcy. Dzięki temu test idzie tą samą ścieżką co realne
	 * żądania (bez duplikowania adresów/nagłówków w warstwie ustawień).
	 *
	 * @return true|\WP_Error `true`, gdy klucz działa; `\WP_Error` przy błędzie.
	 */
	public function verify();
}
