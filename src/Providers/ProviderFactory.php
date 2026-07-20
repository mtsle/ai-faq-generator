<?php
/**
 * Fabryka dostawców AI.
 *
 * Jedyne miejsce, które tworzy konkretny provider AI na podstawie ustawień
 * wtyczki. Reszta kodu prosi fabrykę o gotowy {@see ProviderInterface} i nie
 * wie nic o klasach implementacji ani o konfiguracji. Fabryka SAMA nie wykonuje
 * żadnych żądań HTTP ani nie rozmawia z API — jedynie składa obiekty.
 *
 * @package AI_FAQ_Generator
 */

namespace AIFAQ\Providers;

use AIFAQ\Http\HttpClient;
use AIFAQ\Http\WpHttpClient;
use AIFAQ\Core\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fabryka providerów AI.
 */
class ProviderFactory {

	/**
	 * Testowy override providera (atrapa wstrzykiwana w testach).
	 *
	 * Gdy różny od `null`, {@see make()} zwraca go od razu, pomijając odczyt
	 * ustawień i tworzenie prawdziwego providera. Dzięki temu testy działają
	 * bez sieci i bez konfiguracji.
	 *
	 * @var ProviderInterface|null
	 */
	private static ?ProviderInterface $override = null;

	/**
	 * Tworzy providera AI wg ustawień wtyczki.
	 *
	 * Kolejność działania:
	 * 1. Jeśli ustawiono testowy override — zwraca go natychmiast.
	 * 2. Uzupełnia transport HTTP domyślnym {@see WpHttpClient}, gdy nie podano.
	 * 3. Odczytuje dostawcę i modele WYŁĄCZNIE z {@see Settings} (zero hardkodu).
	 * 4. Składa i zwraca właściwą implementację {@see ProviderInterface}.
	 *
	 * @param HttpClient|null $http Klient HTTP do wstrzyknięcia (transport żądań).
	 *                              Gdy `null`, używany jest {@see WpHttpClient}.
	 *                              Pozwala podstawić atrapę transportu w testach.
	 *
	 * @return ProviderInterface Gotowy dostawca AI.
	 */
	public static function make( ?HttpClient $http = null ): ProviderInterface {
		// GA5: atrapa providera podstawiona przez set_override() — bez sieci.
		if ( null !== self::$override ) {
			return self::$override;
		}

		return self::build( (string) Settings::get_field( 'api_key', '' ), $http );
	}

	/**
	 * Jak {@see make()}, ale z jawnie podanym kluczem API.
	 *
	 * Używane przez „Test połączenia", który sprawdza świeżo wpisany, jeszcze
	 * niezapisany klucz. Pozostała konfiguracja (dostawca, modele) idzie z ustawień.
	 *
	 * @param string          $api_key Klucz API do przetestowania/użycia.
	 * @param HttpClient|null $http    Transport (domyślnie {@see WpHttpClient}).
	 * @return ProviderInterface
	 */
	public static function make_with_key( string $api_key, ?HttpClient $http = null ): ProviderInterface {
		if ( null !== self::$override ) {
			return self::$override;
		}

		return self::build( $api_key, $http );
	}

	/**
	 * Jak {@see make()}, ale provider liczy embeddingi w trybie ZAPYTANIA (asymetrycznym).
	 *
	 * @param HttpClient|null $http Transport (domyślnie {@see WpHttpClient}).
	 * @return ProviderInterface
	 */
	public static function make_for_query( ?HttpClient $http = null ): ProviderInterface {
		if ( null !== self::$override ) {
			return self::$override;
		}

		return self::build( (string) Settings::get_field( 'api_key', '' ), $http, self::query_task() );
	}

	/**
	 * Jak {@see make()}, ale provider liczy embeddingi w trybie DOKUMENTU (indeksowanie).
	 *
	 * Sufit czekania na ponowienie jest tu wyższy (60 s zamiast 5 s): reindeks idzie
	 * w kontekście admina, a nie w żądaniu gościa blokującym workera PHP-FPM.
	 *
	 * @param HttpClient|null $http Transport (domyślnie {@see WpHttpClient}).
	 * @return ProviderInterface
	 */
	public static function make_for_index( ?HttpClient $http = null ): ProviderInterface {
		if ( null !== self::$override ) {
			return self::$override;
		}

		return self::build( (string) Settings::get_field( 'api_key', '' ), $http, self::doc_task(), 60 );
	}

	/**
	 * Efektywne zadanie embeddingu DOKUMENTÓW — JEDYNE źródło prawdy, także dla podpisu indeksu.
	 *
	 * @return string Wartość PO filtrze `aifaq_embed_task`.
	 */
	public static function doc_task(): string {
		return self::task( 'RETRIEVAL_DOCUMENT', 'doc' );
	}

	/**
	 * Efektywne zadanie embeddingu PYTAŃ.
	 *
	 * @return string Wartość PO filtrze `aifaq_embed_task`.
	 */
	public static function query_task(): string {
		return self::task( 'RETRIEVAL_QUERY', 'query' );
	}

	/**
	 * Nakłada filtr `aifaq_embed_task` — DOKŁADNIE RAZ, w jednym miejscu.
	 *
	 * Świadomie NIE w `build()`: tam filtr działałby dwukrotnie na ścieżkach `make_for_*()`
	 * i — co gorsza — raz na wartości `''` ścieżki legacy `make()`, która ma pozostać
	 * bit w bit dzisiejsza. Opisane w `plany/krok19/ODCHYLENIA.md`.
	 *
	 * @param string $default Wartość domyślna zadania.
	 * @param string $flavour Rodzaj ścieżki (`doc` albo `query`).
	 * @return string
	 */
	private static function task( string $default, string $flavour ): string {
		$task = $default;

		if ( function_exists( 'apply_filters' ) ) {
			$task = (string) apply_filters( 'aifaq_embed_task', $task, $flavour );
		}

		return $task;
	}

	/**
	 * Składa providera wg ustawień, z podanym kluczem i transportem.
	 *
	 * @param string          $api_key    Klucz API.
	 * @param HttpClient|null $http       Transport (domyślnie {@see WpHttpClient}).
	 * @param string          $embed_task `taskType` embeddingów; `''` = nie wysyłaj (ścieżka legacy).
	 * @param int             $max_wait   Sufit czekania na ponowienie w sekundach.
	 * @return ProviderInterface
	 */
	private static function build(
		string $api_key,
		?HttpClient $http,
		string $embed_task = '',
		int $max_wait = 5
	): ProviderInterface {
		// GA5: domyślny transport, chyba że wstrzyknięto atrapę.
		$http = $http ?? new WpHttpClient();

		// GA4: dostawca i modele wyłącznie z ustawień.
		$defaults    = Settings::defaults();
		$provider    = (string) Settings::get_field( 'provider', 'gemini' );
		$model       = (string) Settings::get_field( 'model', '' );
		$embed_model = (string) Settings::get_field( 'embed_model', '' );

		// M9 + utwardzenie (v0.8.0): pusty LUB nieznany model → dobierz domyślny.
		// Nieznany = spoza aktualnej whitelisty, np. model wycofany przez dostawcę
		// (Google usunął serię `gemini-1.5-*`). Bez tego zapisany, nieistniejący
		// model daje mylące 404 z API i psuje generację u klienta — samo-naprawa.
		if ( '' === $model || ! array_key_exists( $model, Settings::models() ) ) {
			$model = (string) $defaults['model'];
		}
		if ( '' === $embed_model || ! array_key_exists( $embed_model, Settings::embed_models() ) ) {
			$embed_model = (string) $defaults['embed_model'];
		}

		switch ( $provider ) {
			// Punkt rozszerzenia — kolejni dostawcy dokładani tutaj, np.:
			// case 'openai': return new OpenAiProvider( $http, $api_key, $model, $embed_model );
			// Uwaga: `provider` jest ograniczony do 'gemini' przez Settings::sanitize(),
			// więc `default` to jedyna realna wartość, nie cichy fallback.
			case 'gemini':
			default:
				// Szósty argument (`$sleeper`) zostaje `null` — fabryka nie wstrzykuje uśpienia.
				return new GeminiProvider( $http, $api_key, $model, $embed_model, $embed_task, null, $max_wait );
		}
	}

	/**
	 * Ustawia (lub czyści) testowy override providera.
	 *
	 * Wywołane z obiektem — {@see make()} zwróci tę atrapę zamiast tworzyć
	 * prawdziwego providera. Wywołane z `null` — przywraca normalne działanie
	 * fabryki (odczyt ustawień). Przeznaczone WYŁĄCZNIE do testów.
	 *
	 * @param ProviderInterface|null $provider Atrapa providera lub `null` (reset).
	 */
	public static function set_override( ?ProviderInterface $provider ): void {
		self::$override = $provider;
	}
}
