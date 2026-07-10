<?php
/**
 * Dostawca AI oparty na Google Gemini (API v1beta).
 *
 * Konkretna implementacja {@see ProviderInterface} realizująca generowanie
 * tekstu oraz embeddingi przez REST API Gemini. Klasa NIE wykonuje żadnych
 * żądań HTTP samodzielnie — cały transport odbywa się przez wstrzyknięty
 * {@see HttpClient}. Metody NIE rzucają wyjątków: każdą ścieżkę błędu
 * zwracają jako `\WP_Error`.
 *
 * @package AI_FAQ_Generator
 */

namespace AIFAQ\Providers;

use AIFAQ\Http\HttpClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dostawca Gemini.
 */
class GeminiProvider implements ProviderInterface {

	/**
	 * Bazowy adres endpointów modeli Gemini (v1beta).
	 */
	const API_BASE = 'https://generativelanguage.googleapis.com/v1beta/models/';

	/**
	 * Domyślna losowość odpowiedzi, gdy nie podano w opcjach.
	 */
	const DEFAULT_TEMPERATURE = 0.7;

	/**
	 * Liczba wymiarów wektora embeddingu żądana od API.
	 *
	 * Model `gemini-embedding-001` domyślnie zwraca 3072 wymiary; wybieramy 768
	 * dla mniejszej bazy i szybszego liczenia podobieństwa w PHP na hostingu klienta.
	 */
	const EMBED_DIMENSIONS = 768;

	/**
	 * Limit czasu żądania w sekundach.
	 *
	 * Dłuższy niż domyślne 15 s transportu — `gemini-2.5-flash` domyślnie „myśli",
	 * a batch embeddingów bywa duży; zbyt krótki timeout dawał twardy błąd bez retry.
	 */
	const REQUEST_TIMEOUT = 60;

	/**
	 * Klient HTTP używany do wszystkich żądań (wstrzyknięty).
	 *
	 * @var HttpClient
	 */
	private HttpClient $http;

	/**
	 * Klucz API Gemini (dostarczany z zewnątrz, przekazywany w nagłówku).
	 *
	 * @var string
	 */
	private string $api_key;

	/**
	 * Identyfikator modelu do generowania tekstu.
	 *
	 * @var string
	 */
	private string $model;

	/**
	 * Identyfikator modelu do embeddingów.
	 *
	 * @var string
	 */
	private string $embed_model;

	/**
	 * Konstruktor.
	 *
	 * @param HttpClient $http        Klient HTTP (transport wszystkich żądań).
	 * @param string     $api_key     Klucz API Gemini.
	 * @param string     $model       Model do generowania tekstu.
	 * @param string     $embed_model Model do embeddingów.
	 */
	public function __construct( HttpClient $http, string $api_key, string $model, string $embed_model ) {
		$this->http        = $http;
		$this->api_key     = $api_key;
		$this->model       = $model;
		$this->embed_model = $embed_model;
	}

	/**
	 * Generuje tekstową odpowiedź modelu na podany prompt.
	 *
	 * @param string              $prompt  Treść zapytania do modelu.
	 * @param array<string,mixed> $options Opcje generowania (`temperature`, `max_tokens`).
	 *
	 * @return string|\WP_Error Wygenerowany tekst lub `\WP_Error` przy błędzie.
	 */
	public function generate( string $prompt, array $options = array() ) {
		$generation_config = array(
			'temperature' => isset( $options['temperature'] )
				? (float) $options['temperature']
				: self::DEFAULT_TEMPERATURE,
		);

		if ( isset( $options['max_tokens'] ) ) {
			$generation_config['maxOutputTokens'] = (int) $options['max_tokens'];
		}

		$payload = array(
			'contents'         => array(
				array(
					'parts' => array(
						array( 'text' => $prompt ),
					),
				),
			),
			'generationConfig' => $generation_config,
		);

		$url  = self::API_BASE . $this->model . ':generateContent';
		$data = $this->request_json( $url, $payload );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		// Prompt zablokowany (safety) — brak kandydatów, ale jest powód blokady.
		if ( isset( $data['promptFeedback']['blockReason'] ) ) {
			return new \WP_Error(
				'aifaq_gemini_blocked',
				/* translators: %s: powód blokady zwrócony przez API */
				sprintf( __( 'Zapytanie odrzucone przez model (%s).', 'ai-faq-generator' ), (string) $data['promptFeedback']['blockReason'] )
			);
		}

		// Kandydat przerwany z powodu innego niż normalne zakończenie/limit tokenów
		// (np. SAFETY/RECITATION) — zwykle bez użytecznego tekstu; nie udawaj sukcesu.
		$finish = (string) ( $data['candidates'][0]['finishReason'] ?? '' );
		if ( '' !== $finish && ! in_array( $finish, array( 'STOP', 'MAX_TOKENS' ), true ) ) {
			return new \WP_Error(
				'aifaq_gemini_blocked',
				/* translators: %s: powód zatrzymania generacji */
				sprintf( __( 'Model przerwał generowanie (%s).', 'ai-faq-generator' ), $finish )
			);
		}

		// Wyciągamy tekst z pierwszego kandydata.
		if ( ! isset( $data['candidates'][0]['content']['parts'][0]['text'] ) ) {
			return new \WP_Error( 'aifaq_gemini_parse', 'Nieoczekiwana odpowiedź API.' );
		}

		return (string) $data['candidates'][0]['content']['parts'][0]['text'];
	}

	/**
	 * Zamienia listę tekstów na wektory (embeddingi) jednym żądaniem batch.
	 *
	 * @param array<int,string> $texts Lista tekstów do zwektoryzowania.
	 *
	 * @return array<int,array<int,float>>|\WP_Error
	 *         Lista wektorów w kolejności wejścia lub `\WP_Error` przy błędzie.
	 */
	public function embed( array $texts ) {
		$model_ref = 'models/' . $this->embed_model;
		$requests  = array();

		foreach ( $texts as $text ) {
			$requests[] = array(
				'model'                => $model_ref,
				'content'              => array(
					'parts' => array(
						array( 'text' => (string) $text ),
					),
				),
				'outputDimensionality' => self::EMBED_DIMENSIONS,
			);
		}

		$payload = array( 'requests' => $requests );

		$url  = self::API_BASE . $this->embed_model . ':batchEmbedContents';
		$data = $this->request_json( $url, $payload );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( ! isset( $data['embeddings'] ) || ! is_array( $data['embeddings'] ) ) {
			return new \WP_Error( 'aifaq_gemini_parse', 'Nieoczekiwana odpowiedź API.' );
		}

		$vectors = array();

		foreach ( $data['embeddings'] as $embedding ) {
			if ( ! isset( $embedding['values'] ) || ! is_array( $embedding['values'] ) ) {
				return new \WP_Error( 'aifaq_gemini_parse', 'Nieoczekiwana odpowiedź API.' );
			}

			// Rzutujemy każdy element wektora na float.
			$vectors[] = array_map( 'floatval', $embedding['values'] );
		}

		return $vectors;
	}

	/**
	 * Lekki test autoryzacji klucza — pyta o listę modeli (GET), nic nie generuje.
	 *
	 * Klucz idzie WYŁĄCZNIE w nagłówku `x-goog-api-key` (nigdy w URL). Zwraca
	 * `true` przy 200, w przeciwnym razie `\WP_Error` z komunikatem z API.
	 *
	 * @return true|\WP_Error
	 */
	public function verify() {
		$resp = $this->http->request(
			'GET',
			rtrim( self::API_BASE, '/' ),
			array(
				'headers' => array(
					'x-goog-api-key' => $this->api_key,
					'Accept'         => 'application/json',
				),
				'timeout' => self::REQUEST_TIMEOUT,
			)
		);

		if ( is_wp_error( $resp ) ) {
			return $resp;
		}

		if ( 200 === (int) ( $resp['status'] ?? 0 ) ) {
			return true;
		}

		$data    = json_decode( (string) ( $resp['body'] ?? '' ), true );
		$message = isset( $data['error']['message'] )
			? (string) $data['error']['message']
			: sprintf( 'Błąd API (kod %d).', (int) ( $resp['status'] ?? 0 ) );

		return new \WP_Error( 'aifaq_gemini_http', $message );
	}

	/**
	 * Wykonuje żądanie POST z ciałem JSON i zwraca zdekodowaną odpowiedź.
	 *
	 * Wspólna ścieżka dla {@see generate()} i {@see embed()}. Klucz API trafia
	 * WYŁĄCZNIE do nagłówka `x-goog-api-key` — nigdy do URL ani do logów.
	 *
	 * @param string              $url     Docelowy adres endpointu.
	 * @param array<string,mixed> $payload Ciało żądania (zostanie zserializowane do JSON).
	 *
	 * @return array<string,mixed>|\WP_Error
	 *         Zdekodowana odpowiedź API lub `\WP_Error` przy błędzie sieci/HTTP.
	 */
	private function request_json( string $url, array $payload ) {
		$resp = $this->http->request(
			'POST',
			$url,
			array(
				'headers' => array(
					'x-goog-api-key' => $this->api_key,
					'Content-Type'   => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
				'timeout' => self::REQUEST_TIMEOUT,
			)
		);

		// Błąd sieci/transportu — propagujemy bez zmian.
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}

		$data = json_decode( $resp['body'], true );

		// Kod inny niż 200 — komunikat z API, jeśli dostępny; nigdy klucz.
		if ( 200 !== $resp['status'] ) {
			$message = isset( $data['error']['message'] )
				? (string) $data['error']['message']
				: sprintf( 'Błąd API (kod %d).', (int) $resp['status'] );

			return new \WP_Error( 'aifaq_gemini_http', $message );
		}

		return is_array( $data ) ? $data : array();
	}
}
