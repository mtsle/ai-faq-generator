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
	 * Globalny budżet czasu JEDNEGO wywołania `generate()` / `embed()` w sekundach (K19, F3).
	 *
	 * Kaskada 400 (§3.1d) i retry 429/503 (§4.8) sumują się w TYM SAMYM budżecie, liczonym
	 * od pierwszej linii `generate()`/`embed()`, nie od wejścia do `request_json()`.
	 * W samej metodzie budżet jest dodatkowo zawężany do `max_execution_time` PHP.
	 */
	const REQUEST_RETRY_BUDGET = 100;

	/**
	 * Minimalny czas (sekundy), jaki musi zostać w budżecie, żeby kolejna próba w ogóle wystartowała.
	 *
	 * Samo „czy mieszczę się w budżecie" przepuszczałoby próbę startującą w 89. sekundzie,
	 * która trwa własne 60 s. Próba już rozpoczęta nigdy nie jest przerywana (w PHP się nie da).
	 */
	private const MIN_ATTEMPT_SECONDS = 5;

	/**
	 * Modele, którym NIE wysyłamy budżetu myślenia równego 0.
	 *
	 * Zmierzone: 2026-07-20 11:34, `zasoby/smoke-provider.php --thinking` (Etap 0).
	 * UWAGA: oba wpisy są ZAPOBIEGAWCZE, nie zmierzone — na darmowym kluczu
	 * `gemini-2.5-pro` i `gemini-2.0-flash` mają przydział 0 żądań/dobę, więc ich reakcji
	 * na `thinkingBudget = 0` NIE DAŁO SIĘ zmierzyć (odpowiedziały 429, nie 400).
	 * Korektę wnosi transient `aifaq_no_thinking_<model>`, gdy klient wepnie klucz płatny.
	 */
	private const NO_ZERO_THINKING = array( 'gemini-2.5-pro', 'gemini-2.0-flash' );

	/**
	 * Modele, które klucza `thinkingConfig` nie znają w ogóle — pole pomijamy w całości.
	 */
	private const NO_THINKING_KEY = array( 'gemini-2.0-flash' );

	/**
	 * Metadane OSTATNIEGO wywołania — kanał boczny, poza `ProviderInterface`.
	 *
	 * Inicjalizowane W DEKLARACJI (nie w konstruktorze): typowana właściwość bez wartości
	 * początkowej daje w PHP 8.2 fatal „must not be accessed before initialization", a
	 * diagnostyka RAG woła `last_meta()` także przy trafieniu w cache i przy odmowie bramki,
	 * gdzie `generate()` w ogóle nie zaszło.
	 *
	 * @var array<string,mixed>
	 */
	private array $meta = array(
		'finish_reason'   => '',
		'truncated'       => false,
		'empty_text'      => false,
		'prompt_tokens'   => 0,
		'thoughts_tokens' => 0,
		'output_tokens'   => 0,
		'total_tokens'    => 0,
		'http_status'     => 0,
		'error_code'      => '',
		'thinking_sent'   => -2,
		'retries'         => 0,
		'model'           => '',
	);

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
	 * Zadanie embeddingu wysyłane jako `taskType` (`''` = NIE wysyłaj — zachowanie sprzed K19).
	 *
	 * @var string
	 */
	private string $embed_task_type;

	/**
	 * Funkcja usypiająca między ponowieniami (wstrzykiwalna, żeby testy nie spały).
	 *
	 * @var callable
	 */
	private $sleeper;

	/**
	 * Sufit czekania na ponowienie w sekundach — reguła „nie warto czekać" (§4.8).
	 *
	 * @var int
	 */
	private int $max_wait_seconds;

	/**
	 * Znacznik startu budżetu czasu bieżącego wywołania (`microtime( true )`).
	 *
	 * @var float
	 */
	private float $budget_started = 0.0;

	/**
	 * Efektywny budżet czasu bieżącego wywołania w sekundach.
	 *
	 * @var int
	 */
	private int $budget_seconds = 0;

	/**
	 * Konstruktor.
	 *
	 * Argumenty 5–7 mają wartości domyślne CELOWO: istniejące testy konstruują tę klasę
	 * 17 razy z dokładnie czterema argumentami.
	 *
	 * @param HttpClient    $http             Klient HTTP (transport wszystkich żądań).
	 * @param string        $api_key          Klucz API Gemini.
	 * @param string        $model            Model do generowania tekstu.
	 * @param string        $embed_model      Model do embeddingów.
	 * @param string        $embed_task_type  `taskType` embeddingów; `''` = nie wysyłaj klucza.
	 * @param callable|null $sleeper          Funkcja usypiająca; `null` → `sleep`.
	 * @param int           $max_wait_seconds Sufit czekania na ponowienie (klamp 1..60).
	 */
	public function __construct(
		HttpClient $http,
		string $api_key,
		string $model,
		string $embed_model,
		string $embed_task_type = '',
		?callable $sleeper = null,
		int $max_wait_seconds = 5
	) {
		$this->http             = $http;
		$this->api_key          = $api_key;
		$this->model            = $model;
		$this->embed_model      = $embed_model;
		$this->embed_task_type  = $embed_task_type;
		$this->sleeper          = ( null === $sleeper ) ? 'sleep' : $sleeper;
		$this->max_wait_seconds = max( 1, min( 60, $max_wait_seconds ) );
	}

	/**
	 * Metadane OSTATNIEGO wywołania — kanał boczny poza `ProviderInterface`.
	 *
	 * Struktura jest DETERMINISTYCZNA: wszystkie 12 kluczy jest obecnych zawsze, także
	 * przed pierwszym `generate()`. Nigdy nie zawiera klucza API, nagłówków ani promptu.
	 *
	 * @return array{finish_reason:string, truncated:bool, empty_text:bool, prompt_tokens:int,
	 *               thoughts_tokens:int, output_tokens:int, total_tokens:int, http_status:int,
	 *               error_code:string, thinking_sent:int, retries:int, model:string}
	 */
	public function last_meta(): array {
		return $this->meta;
	}

	/**
	 * Model faktycznie użyty przez tę instancję (poza `ProviderInterface`, wzorem `last_meta()`).
	 *
	 * @return string
	 */
	public function model(): string {
		return $this->model;
	}

	/**
	 * Generuje tekstową odpowiedź modelu na podany prompt.
	 *
	 * @param string              $prompt  Treść zapytania do modelu.
	 * @param array<string,mixed> $options Opcje generowania (`temperature`, `max_tokens`,
	 *                                     `response_mime_type`, `response_schema`,
	 *                                     `thinking_budget`, `system`).
	 *
	 * @return string|\WP_Error Wygenerowany tekst lub `\WP_Error` przy błędzie.
	 */
	public function generate( string $prompt, array $options = array() ) {
		$this->reset_meta_all();
		$this->start_budget();
		$this->meta['model'] = $this->model;

		$generation_config = array(
			'temperature' => isset( $options['temperature'] )
				? (float) $options['temperature']
				: self::DEFAULT_TEMPERATURE,
		);

		if ( isset( $options['max_tokens'] ) ) {
			$generation_config['maxOutputTokens'] = (int) $options['max_tokens'];
		}

		// Structured output (opcjonalne, addytywne): tylko gdy wołający tego zażąda.
		// RAG (Answerer) tych opcji nie przekazuje → jego payload pozostaje bez zmian.
		if ( isset( $options['response_mime_type'] ) ) {
			$generation_config['responseMimeType'] = (string) $options['response_mime_type'];
		}

		if ( isset( $options['response_schema'] ) && is_array( $options['response_schema'] ) ) {
			$generation_config['responseSchema'] = $options['response_schema'];
		}

		// K19 / M1b — budżet myślenia (opcjonalny, addytywny): tylko gdy wołający go poda
		// I model go przyjmuje. FaqGenerator tej opcji nie przekazuje → jego payload bez zmian.
		if ( isset( $options['thinking_budget'] )
			&& is_numeric( $options['thinking_budget'] )
			&& $this->model_accepts_thinking()
		) {
			$budget = $this->clamp_thinking( (int) $options['thinking_budget'] );

			// Pro myślenia nie da się wyłączyć — budżet 0 daje tam 400; podnosimy do minimum.
			if ( 0 === $budget && in_array( $this->model, self::NO_ZERO_THINKING, true ) ) {
				$budget = 128;
			}

			$generation_config['thinkingConfig'] = array( 'thinkingBudget' => $budget );
			$this->meta['thinking_sent']         = $budget;
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

		// K19 — instrukcja systemowa jako klucz NAJWYŻSZEGO POZIOMU (obok contents/generationConfig).
		// Treść instrukcji należy do warstwy RAG; provider jest tu wyłącznie transportem.
		if ( isset( $options['system'] ) && is_string( $options['system'] ) && '' !== trim( $options['system'] ) ) {
			$payload['systemInstruction'] = array(
				'parts' => array( array( 'text' => (string) $options['system'] ) ),
			);
		}

		$url  = self::API_BASE . $this->model . ':generateContent';
		$data = $this->send_generate( $url, $payload );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		// usageMetadata NAJPIERW — żeby liczniki były wypełnione także na ścieżkach blocked/parse.
		$usage                           = ( isset( $data['usageMetadata'] ) && is_array( $data['usageMetadata'] ) )
			? $data['usageMetadata']
			: array();
		$this->meta['prompt_tokens']     = (int) ( $usage['promptTokenCount'] ?? 0 );
		$this->meta['thoughts_tokens']   = (int) ( $usage['thoughtsTokenCount'] ?? 0 );
		$this->meta['output_tokens']     = (int) ( $usage['candidatesTokenCount'] ?? 0 );
		$this->meta['total_tokens']      = (int) ( $usage['totalTokenCount'] ?? 0 );

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
		$finish                      = (string) ( $data['candidates'][0]['finishReason'] ?? '' );
		$this->meta['finish_reason'] = $finish;
		$this->meta['truncated']     = ( 'MAX_TOKENS' === $finish );

		if ( '' !== $finish && ! in_array( $finish, array( 'STOP', 'MAX_TOKENS' ), true ) ) {
			return new \WP_Error(
				'aifaq_gemini_blocked',
				/* translators: %s: powód zatrzymania generacji */
				sprintf( __( 'Model przerwał generowanie (%s).', 'ai-faq-generator' ), $finish )
			);
		}

		// Wyciągamy tekst z pierwszego kandydata.
		if ( ! isset( $data['candidates'][0]['content']['parts'][0]['text'] ) ) {
			$this->meta['empty_text'] = true;

			// M2: ucięcie na limicie tokenów przestaje być nierozróżnialne od śmieciowej odpowiedzi.
			if ( 'MAX_TOKENS' === $finish ) {
				return new \WP_Error( 'aifaq_gemini_truncated', 'Odpowiedź ucięta na limicie tokenów.' );
			}

			return new \WP_Error( 'aifaq_gemini_parse', 'Nieoczekiwana odpowiedź API.' );
		}

		// MAX_TOKENS Z TEKSTEM NADAL ZWRACA TEKST — sygnałem ucięcia jest wyłącznie
		// `last_meta()['truncated']`, a decyzję o niecache'owaniu podejmuje warstwa RAG.
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
		// Wyłącznie pola TRANSPORTOWE — dziewięć pól generacyjnych należy do generate().
		$this->reset_meta_transport();
		$this->start_budget();

		$model_ref = 'models/' . $this->embed_model;
		$requests  = array();

		foreach ( $texts as $text ) {
			$request = array(
				'model'                => $model_ref,
				'content'              => array(
					'parts' => array(
						array( 'text' => (string) $text ),
					),
				),
				'outputDimensionality' => self::EMBED_DIMENSIONS,
			);

			// K19 / M5: `taskType` addytywnie i na KAŻDYM elemencie listy.
			// `''` (domyślne) → klucza nie ma w payloadzie w ogóle = zachowanie sprzed K19.
			if ( '' !== $this->embed_task_type ) {
				$request['taskType'] = $this->embed_task_type;
			}

			$requests[] = $request;
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
	 * @param string              $url               Docelowy adres endpointu.
	 * @param array<string,mixed> $payload           Ciało żądania (zostanie zserializowane do JSON).
	 * @param bool                $record_generation Czy wołającym jest `generate()` (czytelność/diagnostyka).
	 *
	 * @return array<string,mixed>|\WP_Error
	 *         Zdekodowana odpowiedź API lub `\WP_Error` przy błędzie sieci/HTTP.
	 */
	private function request_json( string $url, array $payload, bool $record_generation = false ) {
		unset( $record_generation );

		// WYŁĄCZNIK OBWODU — dopóki żyje cooldown, nie ruszamy do API w ogóle.
		// Ta ścieżka NIE inkrementuje `retries` (żadnej próby nie było).
		if ( function_exists( 'get_transient' ) && get_transient( 'aifaq_provider_cooldown' ) ) {
			$this->meta['http_status'] = 429;
			$this->meta['error_code']  = 'aifaq_gemini_rate';

			return new \WP_Error( 'aifaq_gemini_rate', 'Dostawca chwilowo odmawia — spróbuj za chwilę.' );
		}

		$retry_on = true;
		if ( function_exists( 'apply_filters' ) ) {
			$retry_on = (bool) apply_filters( 'aifaq_http_retry', $retry_on );
		}

		$max_attempts = $retry_on ? 3 : 1;   // maksymalnie 2 ponowienia.
		$attempts     = 0;
		$last_error   = null;

		while ( $attempts < $max_attempts ) {
			$remaining = $this->remaining_budget();

			// Próba nie startuje, gdy w budżecie zostało mniej niż minimum.
			if ( $remaining < self::MIN_ATTEMPT_SECONDS ) {
				break;
			}

			if ( $attempts > 0 ) {
				++$this->meta['retries'];
			}

			$resp = $this->http->request(
				'POST',
				$url,
				array(
					'headers' => array(
						'x-goog-api-key' => $this->api_key,
						'Content-Type'   => 'application/json',
					),
					'body'    => wp_json_encode( $payload ),
					'timeout' => (int) min( (float) self::REQUEST_TIMEOUT, $remaining ),
				)
			);
			++$attempts;

			// Błąd sieci/transportu — propagujemy bez zmian i bez ponawiania.
			if ( is_wp_error( $resp ) ) {
				$this->meta['http_status'] = 0;
				$this->meta['error_code']  = (string) $resp->get_error_code();

				return $resp;
			}

			$status = (int) ( $resp['status'] ?? 0 );
			$data   = json_decode( (string) ( $resp['body'] ?? '' ), true );

			$this->meta['http_status'] = $status;

			if ( 200 === $status ) {
				$this->meta['error_code'] = '';

				return is_array( $data ) ? $data : array();
			}

			// Kod inny niż 200 — komunikat z API, jeśli dostępny; nigdy klucz.
			$code = 'aifaq_gemini_http';
			if ( 429 === $status ) {
				$code = 'aifaq_gemini_rate';
			} elseif ( 503 === $status ) {
				$code = 'aifaq_gemini_busy';
			}

			$message = isset( $data['error']['message'] )
				? (string) $data['error']['message']
				: sprintf( 'Błąd API (kod %d).', $status );

			$this->meta['error_code'] = $code;
			$last_error               = new \WP_Error( $code, $message );

			// Ponawiamy WYŁĄCZNIE 429 i 503; 400 z thinkingConfig obsługuje kaskada w generate().
			if ( 429 !== $status && 503 !== $status ) {
				return $last_error;
			}

			if ( $attempts >= $max_attempts ) {
				break;
			}

			$delay = $this->retry_delay( is_array( $data ) ? $data : array(), $resp, $attempts );

			// REGUŁA „NIE WARTO CZEKAĆ": dłużej niż sufit ścieżki albo niż reszta budżetu
			// → zero uśpienia, zero kolejnej próby. Gość i tak dostanie przyjazne HTTP 429.
			if ( $delay > $this->max_wait_seconds || $delay > $this->remaining_budget() ) {
				return $last_error;
			}

			( $this->sleeper )( $delay );
		}

		if ( null !== $last_error ) {
			// Ponowienia wyczerpane — wyłącznik obwodu na 60 s.
			if ( function_exists( 'set_transient' ) ) {
				set_transient( 'aifaq_provider_cooldown', 1, 60 );
			}

			return $last_error;
		}

		// Budżet nie pozwolił nawet wystartować.
		return new \WP_Error( 'aifaq_gemini_busy', 'Zabrakło czasu na wykonanie żądania.' );
	}

	/**
	 * Wysyła żądanie generacji z KASKADĄ na odrzucony `thinkingConfig` (§3.1d).
	 *
	 * Dokładnie trzy próby, nigdy czwarta. Kaskada niczego nie resetuje — licznik `retries`
	 * akumuluje się między wejściami do `request_json()`.
	 *
	 * @param string              $url     Adres endpointu generacji.
	 * @param array<string,mixed> $payload Payload pierwszej próby.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	private function send_generate( string $url, array $payload ) {
		$data = $this->request_json( $url, $payload, true );

		if ( ! is_wp_error( $data ) ) {
			return $data;
		}

		// Kaskada dotyczy WYŁĄCZNIE kodu 400 i wyłącznie payloadu z budżetem myślenia.
		// Warunek stoi na kodzie HTTP, nie na treści komunikatu (ta bywa lokalizowana).
		if ( 400 !== (int) $this->meta['http_status']
			|| ! isset( $payload['generationConfig']['thinkingConfig'] )
		) {
			return $data;
		}

		// Próba 2: minimum akceptowane przez modele „myślące"; maxOutputTokens bez zmian.
		if ( $this->remaining_budget() < self::MIN_ATTEMPT_SECONDS ) {
			return $data;
		}

		$payload['generationConfig']['thinkingConfig'] = array( 'thinkingBudget' => 128 );
		$this->meta['thinking_sent']                   = 128;
		++$this->meta['retries'];

		$data = $this->request_json( $url, $payload, true );

		if ( ! is_wp_error( $data ) ) {
			return $data;
		}

		if ( 400 !== (int) $this->meta['http_status'] ) {
			return $data;
		}

		// Próba 3: zdejmujemy pole i PODNOSIMY sufit tokenów — po zdjęciu budżetu obowiązuje
		// domyślne, dynamiczne myślenie modelu (dla Pro regularnie 1000–4000 tokenów),
		// więc pozostawienie 800 dałoby gwarantowany MAX_TOKENS bez `parts`, czyli 502.
		if ( $this->remaining_budget() < self::MIN_ATTEMPT_SECONDS ) {
			return $data;
		}

		unset( $payload['generationConfig']['thinkingConfig'] );

		$requested = isset( $payload['generationConfig']['maxOutputTokens'] )
			? (int) $payload['generationConfig']['maxOutputTokens']
			: 0;
		$payload['generationConfig']['maxOutputTokens'] = max( $requested, 2048 );

		$this->remember_no_thinking();
		$this->meta['thinking_sent'] = -2;
		++$this->meta['retries'];

		// Po trzecim 400 NIE MA czwartej próby.
		return $this->request_json( $url, $payload, true );
	}

	/**
	 * Ustala opóźnienie przed ponowieniem — ŹRÓDŁEM JEST CIAŁO ODPOWIEDZI, NIE NAGŁÓWEK.
	 *
	 * Gemini nie zwraca `Retry-After` jako nagłówka HTTP; wymagane opóźnienie oddaje w ciele.
	 * Kolejność źródeł jest częścią kontraktu; wartość spoza zakresu 1..60 s przechodzi
	 * do NASTĘPNEGO źródła, a nie „na granicę".
	 *
	 * @param array<string,mixed> $data     Zdekodowane ciało odpowiedzi.
	 * @param array<string,mixed> $resp     Surowa odpowiedź transportu (klucz `headers` opcjonalny).
	 * @param int                 $attempts Liczba prób wykonanych do tej pory (1 lub 2).
	 *
	 * @return int Opóźnienie w sekundach.
	 */
	private function retry_delay( array $data, array $resp, int $attempts ): int {
		// 1. error.details[] o @type zawierającym RetryInfo → retryDelay (np. "56s").
		if ( isset( $data['error']['details'] ) && is_array( $data['error']['details'] ) ) {
			foreach ( $data['error']['details'] as $detail ) {
				if ( ! is_array( $detail ) || ! isset( $detail['@type'], $detail['retryDelay'] ) ) {
					continue;
				}
				if ( false === strpos( (string) $detail['@type'], 'RetryInfo' ) ) {
					continue;
				}

				$seconds = $this->parse_delay( $detail['retryDelay'] );
				if ( $seconds > 0 ) {
					return $seconds;
				}
			}
		}

		// 2. regex na error.message ("Please retry in 56.458591106s").
		if ( isset( $data['error']['message'] )
			&& preg_match( '/retry in ([\d.]+)\s*s/i', (string) $data['error']['message'], $matches )
		) {
			$seconds = $this->parse_delay( $matches[1] );
			if ( $seconds > 0 ) {
				return $seconds;
			}
		}

		// 3. nagłówek Retry-After — kanał UZUPEŁNIAJĄCY; atrapy nie muszą zwracać `headers`.
		$headers = ( isset( $resp['headers'] ) && is_array( $resp['headers'] ) ) ? $resp['headers'] : array();
		if ( isset( $headers['retry-after'] ) ) {
			$seconds = $this->parse_delay( $headers['retry-after'] );
			if ( $seconds > 0 ) {
				return $seconds;
			}
		}

		// 4. backoff dopasowany do okna 5 żądań/min.
		return ( 1 === $attempts ) ? 5 : 15;
	}

	/**
	 * Zamienia surową wskazówkę opóźnienia na sekundy z zakresu 1..60 (0 = nieużyteczna).
	 *
	 * @param mixed $raw Wartość z API (np. `"56.458591106s"`, `"7s"`, `"0s"`, `"abc"`).
	 *
	 * @return int Sekundy w zakresie 1..60 albo 0.
	 */
	private function parse_delay( $raw ): int {
		if ( ! is_scalar( $raw ) ) {
			return 0;
		}

		if ( ! preg_match( '/^\s*([\d.]+)/', (string) $raw, $matches ) ) {
			return 0;
		}

		$seconds = (int) $matches[1];

		return ( $seconds >= 1 && $seconds <= 60 ) ? $seconds : 0;
	}

	/**
	 * Czy do tego modelu wolno w ogóle wysłać `thinkingConfig`.
	 *
	 * @return bool
	 */
	private function model_accepts_thinking(): bool {
		if ( in_array( $this->model, self::NO_THINKING_KEY, true ) ) {
			return false;
		}

		if ( function_exists( 'get_transient' ) && get_transient( 'aifaq_no_thinking_' . $this->model ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Zapamiętuje (na 12 h), że ten model odrzucił `thinkingConfig`.
	 *
	 * Bez tej pamięci model nieznający pola płaciłby dwa dodatkowe wywołania za KAŻDE pytanie,
	 * w nieskończoność — przy dobowej puli 20 żądań to połowa pojemności produktu.
	 *
	 * @return void
	 */
	private function remember_no_thinking(): void {
		if ( function_exists( 'set_transient' ) ) {
			set_transient( 'aifaq_no_thinking_' . $this->model, 1, 12 * 3600 );
		}
	}

	/**
	 * Klamp budżetu myślenia do zbioru legalnego `{-1, 0} ∪ [128, 24576]`.
	 *
	 * Ten sam zbiór realizuje sanityzacja ustawień — dwa miejsca, jedna reguła.
	 *
	 * @param int $budget Wartość surowa.
	 *
	 * @return int Wartość legalna.
	 */
	private function clamp_thinking( int $budget ): int {
		if ( $budget < -1 ) {
			return -1;
		}
		if ( 0 === $budget ) {
			return 0;
		}
		if ( $budget > 0 && $budget < 128 ) {
			return 128;   // API odrzuca 1..127 błędem 400.
		}
		if ( $budget > 24576 ) {
			return 24576;
		}

		return $budget;
	}

	/**
	 * Startuje budżet czasu bieżącego wywołania (pierwsza linia `generate()` / `embed()`).
	 *
	 * @return void
	 */
	private function start_budget(): void {
		$this->budget_started = microtime( true );

		$ini                  = function_exists( 'ini_get' ) ? (int) ini_get( 'max_execution_time' ) : 0;
		$this->budget_seconds = ( $ini > 0 )
			? min( self::REQUEST_RETRY_BUDGET, max( 20, $ini - 5 ) )
			: self::REQUEST_RETRY_BUDGET;
	}

	/**
	 * Ile sekund budżetu zostało do dyspozycji.
	 *
	 * @return float
	 */
	private function remaining_budget(): float {
		if ( $this->budget_seconds <= 0 ) {
			return (float) self::REQUEST_RETRY_BUDGET;
		}

		return (float) $this->budget_seconds - ( microtime( true ) - $this->budget_started );
	}

	/**
	 * Reset pól TRANSPORTOWYCH metadanych (`generate()` i `embed()`, nigdy `request_json()`).
	 *
	 * @return void
	 */
	private function reset_meta_transport(): void {
		$this->meta['http_status'] = 0;
		$this->meta['error_code']  = '';
		$this->meta['retries']     = 0;
	}

	/**
	 * Reset kompletu 12 pól metadanych — wyłącznie `generate()`.
	 *
	 * @return void
	 */
	private function reset_meta_all(): void {
		$this->meta['finish_reason']   = '';
		$this->meta['truncated']       = false;
		$this->meta['empty_text']      = false;
		$this->meta['prompt_tokens']   = 0;
		$this->meta['thoughts_tokens'] = 0;
		$this->meta['output_tokens']   = 0;
		$this->meta['total_tokens']    = 0;
		$this->meta['thinking_sent']   = -2;   // -2 = pola NIE wysłano; 0 znaczy „wysłano budżet 0".
		$this->meta['model']           = '';

		$this->reset_meta_transport();
	}
}
