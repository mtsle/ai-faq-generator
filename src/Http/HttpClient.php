<?php
/**
 * Kontrakt generycznej warstwy transportu HTTP.
 *
 * Definiuje minimalny interfejs klienta HTTP, z którego korzystają
 * pozostałe działy wtyczki. Interfejs jest GENERYCZNY — nie wie nic
 * o żadnym konkretnym API ani dostawcy.
 *
 * @package AI_FAQ_Generator
 */

namespace AIFAQ\Http;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interfejs klienta HTTP.
 */
interface HttpClient {

	/**
	 * Wykonuje żądanie HTTP.
	 *
	 * @param string               $method  Metoda HTTP (np. „GET", „POST").
	 * @param string               $url     Docelowy adres URL.
	 * @param array<string,mixed>  $options Opcje żądania. Obsługiwane klucze:
	 *                                       - `headers` (array<string,string>) — nagłówki żądania,
	 *                                       - `body`    (string)               — ciało żądania,
	 *                                       - `timeout` (int)                  — limit czasu w sekundach.
	 *
	 * @return array{status:int,body:string}|\WP_Error
	 *         Przy sukcesie tablica `array{ status:int, body:string }`,
	 *         przy błędzie sieci/transportu obiekt `\WP_Error`.
	 */
	public function request( string $method, string $url, array $options = array() );
}
