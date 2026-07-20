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
	 * Górny limit czasu (sekundy) — zabezpiecza przed zawieszeniem workera.
	 */
	const MAX_TIMEOUT = 120;

	/**
	 * Wykonuje żądanie HTTP przez `wp_remote_request`.
	 *
	 * @param string               $method  Metoda HTTP (np. „GET", „POST").
	 * @param string               $url     Docelowy adres URL.
	 * @param array<string,mixed>  $options Opcje żądania (`headers`, `body`, `timeout`).
	 *
	 * @return array{status:int,body:string,headers:array<string,string>}|\WP_Error
	 *         Przy sukcesie tablica `array{ status:int, body:string, headers:array<string,string> }`,
	 *         przy błędzie sieci/transportu obiekt `\WP_Error`.
	 */
	public function request( string $method, string $url, array $options = array() ) {
		// Jawny timeout. 0/ujemne = „czekaj w nieskończoność" w cURL → wiszący
		// worker; traktujemy je jak brak wartości i klampujemy do MAX_TIMEOUT.
		$timeout = isset( $options['timeout'] ) ? (int) $options['timeout'] : self::DEFAULT_TIMEOUT;
		if ( $timeout <= 0 ) {
			$timeout = self::DEFAULT_TIMEOUT;
		}
		$timeout = min( $timeout, self::MAX_TIMEOUT );

		$args = array(
			'method'  => $method,
			'timeout' => $timeout,
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

		// K19 (§2.6): nagłówki odpowiedzi jako kanał UZUPEŁNIAJĄCY dla retry (`Retry-After`).
		// `wp_remote_retrieve_headers()` zwraca OBIEKT (CaseInsensitiveDictionary) z `protected $data`
		// — surowe `(array)` daje klucz "\0*\0data" i po cichu gubi wszystkie nagłówki.
		// Kolejność jest istotna: getAll() → (array) → is_array.
		$headers = array();
		if ( function_exists( 'wp_remote_retrieve_headers' ) ) {
			$raw = wp_remote_retrieve_headers( $response );

			if ( is_object( $raw ) && method_exists( $raw, 'getAll' ) ) {
				$raw = $raw->getAll();
			}
			if ( is_object( $raw ) ) {
				$raw = (array) $raw;
			}
			if ( is_array( $raw ) ) {
				foreach ( $raw as $key => $value ) {
					// Powtórzony nagłówek bywa tablicą — bierzemy pierwszą wartość.
					$headers[ strtolower( (string) $key ) ] = is_array( $value )
						? (string) reset( $value )
						: (string) $value;
				}
			}
		}

		return array(
			'status'  => (int) wp_remote_retrieve_response_code( $response ),
			'body'    => (string) wp_remote_retrieve_body( $response ),
			'headers' => $headers,
		);
	}
}
