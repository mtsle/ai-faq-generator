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
	 * Składa providera wg ustawień, z podanym kluczem i transportem.
	 *
	 * @param string          $api_key Klucz API.
	 * @param HttpClient|null $http    Transport (domyślnie {@see WpHttpClient}).
	 * @return ProviderInterface
	 */
	private static function build( string $api_key, ?HttpClient $http ): ProviderInterface {
		// GA5: domyślny transport, chyba że wstrzyknięto atrapę.
		$http = $http ?? new WpHttpClient();

		// GA4: dostawca i modele wyłącznie z ustawień.
		$defaults    = Settings::defaults();
		$provider    = (string) Settings::get_field( 'provider', 'gemini' );
		$model       = (string) Settings::get_field( 'model', '' );
		$embed_model = (string) Settings::get_field( 'embed_model', '' );

		// M9: pusty model dałby URL `.../models/:generateContent` (404 z mylącym
		// komunikatem) — przy braku konfiguracji dobierz wartość domyślną.
		if ( '' === $model ) {
			$model = (string) $defaults['model'];
		}
		if ( '' === $embed_model ) {
			$embed_model = (string) $defaults['embed_model'];
		}

		switch ( $provider ) {
			// Punkt rozszerzenia — kolejni dostawcy dokładani tutaj, np.:
			// case 'openai': return new OpenAiProvider( $http, $api_key, $model, $embed_model );
			// Uwaga: `provider` jest ograniczony do 'gemini' przez Settings::sanitize(),
			// więc `default` to jedyna realna wartość, nie cichy fallback.
			case 'gemini':
			default:
				return new GeminiProvider( $http, $api_key, $model, $embed_model );
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
