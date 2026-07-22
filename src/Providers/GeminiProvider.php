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
	 * Czas życia wyłącznika obwodu przy limicie MINUTOWYM albo nierozpoznanym (sekundy).
	 */
	const COOLDOWN_SECONDS = 60;

	/**
	 * Czas życia wyłącznika obwodu przy limicie DOBOWYM (sekundy).
	 *
	 * Stała godzina, nie „do północy": moment resetu kwoty dobowej NIE JEST w tym projekcie
	 * zmierzony (dokumentacja dostawcy mówi o północy czasu pacyficznego). Precyzja liczona
	 * lokalnym czasem WordPressa albo blokowałaby po odnowieniu puli, albo puszczała za wcześnie.
	 */
	const COOLDOWN_DAY_SECONDS = 3600;

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
		'quota_scope'     => '',
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
	 * Struktura jest DETERMINISTYCZNA: wszystkie 13 kluczy jest obecnych zawsze, także
	 * przed pierwszym `generate()`. Nigdy nie zawiera klucza API, nagłówków ani promptu.
	 *
	 * `quota_scope` (K20 §8.5) rozróżnia rodzaj wyczerpanego limitu: `'day'` | `'minute'` | `''`.
	 * Kod błędu jest dla obu limitów TEN SAM (`aifaq_gemini_rate`) — rozróżnienie żyje wyłącznie
	 * tutaj i w zachowaniu (ponowienia, czas wyłącznika obwodu).
	 *
	 * @return array{finish_reason:string, truncated:bool, empty_text:bool, prompt_tokens:int,
	 *               thoughts_tokens:int, output_tokens:int, total_tokens:int, http_status:int,
	 *               error_code:string, thinking_sent:int, retries:int, model:string,
	 *               quota_scope:string}
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
	 * @param bool                $record_generation Czy wołającym jest `generate()`. Wyznacza PULĘ
	 *                                               wyłącznika obwodu (§8.4): `generate` albo `embed`.
	 *
	 * @return array<string,mixed>|\WP_Error
	 *         Zdekodowana odpowiedź API lub `\WP_Error` przy błędzie sieci/HTTP.
	 */
	private function request_json( string $url, array $payload, bool $record_generation = false ) {
		// K20 §8.4 — klucz wyłącznika obwodu liczony RAZ i używany zarówno przy odczycie,
		// jak i przy zapisie; dwa różne wyrażenia rozjechałyby pule.
		$cooldown_key = $this->cooldown_key( $record_generation );

		// WYŁĄCZNIK OBWODU — dopóki żyje cooldown, nie ruszamy do API w ogóle.
		// Ta ścieżka NIE inkrementuje `retries` (żadnej próby nie było).
		if ( function_exists( 'get_transient' ) && get_transient( $cooldown_key ) ) {
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

				// K20 §8.1 — rodzaj wyczerpanej kwoty. Kod błędu zostaje TEN SAM dla obu
				// limitów; rozróżnienie idzie kanałem bocznym `last_meta()['quota_scope']`.
				$this->meta['quota_scope'] = $this->quota_scope( is_array( $data ) ? $data : array() );
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

			// K20 §8.2 — limit DOBOWY: ZERO ponowień. `retryDelay` jest tu mylący (zmierzone `8s`
			// przy puli, która wraca dopiero następnej doby), więc nawet go nie czytamy.
			if ( 'day' === $this->meta['quota_scope'] ) {
				$this->arm_cooldown( $cooldown_key );

				return $last_error;
			}

			if ( $attempts >= $max_attempts ) {
				break;
			}

			$delay = $this->retry_delay( is_array( $data ) ? $data : array(), $resp, $attempts );

			// REGUŁA „NIE WARTO CZEKAĆ": dłużej niż sufit ścieżki albo niż reszta budżetu
			// → zero uśpienia, zero kolejnej próby. Gość i tak dostanie przyjazne HTTP 429.
			//
			// K20 §8.3 — TU BYŁA WADA K19: wyjście następowało PRZED uzbrojeniem wyłącznika,
			// a na ścieżce gościa (sufit 5 s) warunek zachodził ZAWSZE, bo każde zmierzone
			// `retryDelay` (6..52 s) jest większe. Cooldown nie uzbrajał się nigdy, więc przy
			// wyczerpanej puli KAŻDY gość płacił jedno żądanie do API przez resztę doby.
			if ( $delay > $this->max_wait_seconds || $delay > $this->remaining_budget() ) {
				$this->arm_cooldown( $cooldown_key );

				return $last_error;
			}

			( $this->sleeper )( $delay );
		}

		if ( null !== $last_error ) {
			// Ponowienia wyczerpane — wyłącznik obwodu (60 s; przy limicie dobowym godzina).
			$this->arm_cooldown( $cooldown_key );

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

		// 4. backoff własny, gdy API nie podało żadnej wskazówki.
		//
		// K20 §8.6: pierwotne uzasadnienie („okno 5 żądań/min") opierało się na przesłance
		// ZMIERZONEJ JAKO NIEPRAWDZIWA — E0b wysłał 6 żądań bez przerw i dostał 6 × HTTP 200,
		// więc limit minutowy jest wyższy niż 5. Wartości 5/15 s zostają: nie ma dowodu, że są
		// złe, a realny limit minutowy dostawcy pozostaje niezmierzony.
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
	 * Rodzaj wyczerpanej kwoty odczytany z ciała odpowiedzi 429 (K20 §8.1).
	 *
	 * Ścieżka: `error.details[]` → element o `@type` zawierającym `google.rpc.QuotaFailure`
	 * → `violations[]` → `quotaId`. Parser NIE zakłada ani liczby naruszeń (zmierzone 1, 3 i 4),
	 * ani ich kolejności, ani obecności pola `quotaValue` (przy przydziale zerowym go NIE MA).
	 *
	 * PIERWSZEŃSTWO MA `PerDay`: w jednym ciele potrafią wystąpić RAZEM `…PerMinute…`
	 * i `…PerDay…` (zmierzone na `gemini-2.5-pro`, 4 naruszenia), a wtedy czekanie minutowe
	 * jest bezcelowe — pula wraca dopiero następnej doby.
	 *
	 * @param array<string,mixed> $data Zdekodowane ciało odpowiedzi.
	 *
	 * @return string `'day'` | `'minute'` | `''` (brak rozpoznania).
	 */
	private function quota_scope( array $data ): string {
		if ( ! isset( $data['error']['details'] ) || ! is_array( $data['error']['details'] ) ) {
			return '';
		}

		$scope = '';

		foreach ( $data['error']['details'] as $detail ) {
			if ( ! is_array( $detail ) || ! isset( $detail['@type'] ) || ! is_scalar( $detail['@type'] ) ) {
				continue;
			}
			if ( false === strpos( (string) $detail['@type'], 'QuotaFailure' ) ) {
				continue;
			}
			if ( ! isset( $detail['violations'] ) || ! is_array( $detail['violations'] ) ) {
				continue;
			}

			foreach ( $detail['violations'] as $violation ) {
				if ( ! is_array( $violation ) || ! isset( $violation['quotaId'] ) || ! is_scalar( $violation['quotaId'] ) ) {
					continue;
				}

				$quota_id = (string) $violation['quotaId'];

				// Dobowy wygrywa natychmiast — dalsze naruszenia niczego już nie zmienią.
				if ( false !== stripos( $quota_id, 'PerDay' ) ) {
					return 'day';
				}
				if ( false !== stripos( $quota_id, 'PerMinute' ) ) {
					$scope = 'minute';
				}
			}
		}

		return $scope;
	}

	/**
	 * Klucz wyłącznika obwodu — ROZDZIELNY dla puli generacji i puli embeddingów (K20 §8.4).
	 *
	 * Jawne odchylenie od K19 §4.8 (jeden globalny `aifaq_provider_cooldown`). Powód jest
	 * zmierzony: E0b dostał 429 z `generateContent` o 17:57:09 i poprawny wektor z `embed()`
	 * o 17:57:10 — PULE SĄ ODRĘBNE. Jeden wspólny transient wyłączał indeksowanie, które działa.
	 *
	 * @param bool $record_generation Czy wołającym jest `generate()`.
	 *
	 * @return string Nazwa transientu.
	 */
	private function cooldown_key( bool $record_generation ): string {
		return $record_generation
			? 'aifaq_cooldown_generate_' . $this->model
			: 'aifaq_cooldown_embed_' . $this->embed_model;
	}

	/**
	 * Uzbraja wyłącznik obwodu dla podanej puli (K20 §8.2, §8.3).
	 *
	 * TTL wynika WYŁĄCZNIE z `quota_scope`: limit dobowy → godzina, wszystko inne (w tym
	 * ciało bez `quotaId` oraz 503) → 60 s. Jedno miejsce decyzji, żeby trzy ścieżki wyjścia
	 * z `request_json()` nie mogły się rozjechać.
	 *
	 * @param string $key Klucz transientu z {@see cooldown_key()}.
	 *
	 * @return void
	 */
	private function arm_cooldown( string $key ): void {
		if ( ! function_exists( 'set_transient' ) ) {
			return;
		}

		$ttl = ( 'day' === $this->meta['quota_scope'] )
			? self::COOLDOWN_DAY_SECONDS
			: self::COOLDOWN_SECONDS;

		set_transient( $key, 1, $ttl );
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
	 * `quota_scope` należy TUTAJ, a nie do pól generacyjnych: wyczerpana kwota dotyczy
	 * embeddingów dokładnie tak samo jak generacji, a §13.14 wymaga zerowania go na starcie
	 * KAŻDEGO żądania.
	 *
	 * @return void
	 */
	private function reset_meta_transport(): void {
		$this->meta['http_status'] = 0;
		$this->meta['error_code']  = '';
		$this->meta['retries']     = 0;
		$this->meta['quota_scope'] = '';
	}

	/**
	 * Reset kompletu 13 pól metadanych — wyłącznie `generate()`.
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
