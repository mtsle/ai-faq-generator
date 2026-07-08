<?php
/**
 * Implementacja transportu HTTP oparta na WordPress HTTP API.
 *
 * Cienka, generyczna nakładka na `wp_remote_request`. Zawsze zwraca
 * ustrukturyzowaną odpowiedź (status + body) albo obiekt `\WP_Error`;
 * nigdy nie rzuca wyjątku i nie loguje ciał ani nagłówków (sekrety).
 *
 * @package AI_FAQ_Generator
 */

namespace AIFAQ\Http;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Klient HTTP na bazie WordPress HTTP API.
 */
class WpHttpClient implements HttpClient {

	/**
	 * Domyślny limit czasu żądania w sekundach.
	 */
	const DEFAULT_TIMEOUT = 15;

	/**
	 * Wykonuje żądanie HTTP przez `wp_remote_request`.
	 *
	 * @param string               $method  Metoda HTTP (np. „GET", „POST").
	 * @param string               $url     Docelowy adres URL.
	 * @param array<string,mixed>  $options Opcje żądania (`headers`, `body`, `timeout`).
	 *
	 * @return array{status:int,body:string}|\WP_Error
	 *         Przy sukcesie tablica `array{ status:int, body:string }`,
	 *         przy błędzie sieci/transportu obiekt `\WP_Error`.
	 */
	public function request( string $method, string $url, array $options = array() ) {
		$args = array(
			'method'  => $method,
			// GP2: zawsze jawny timeout — domyślny, gdy nie podano.
			'timeout' => isset( $options['timeout'] ) ? (int) $options['timeout'] : self::DEFAULT_TIMEOUT,
		);

		if ( isset( $options['headers'] ) && is_array( $options['headers'] ) ) {
			$args['headers'] = $options['headers'];
		}

		if ( isset( $options['body'] ) ) {
			$args['body'] = $options['body'];
		}

		$response = wp_remote_request( $url, $args );

		// GP1: błąd sieci/transportu zwracamy jako WP_Error, nie wyjątek.
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return array(
			'status' => (int) wp_remote_retrieve_response_code( $response ),
			'body'   => (string) wp_remote_retrieve_body( $response ),
		);
	}
}
