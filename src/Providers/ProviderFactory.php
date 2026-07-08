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

		// GA5: domyślny transport, chyba że wstrzyknięto atrapę.
		$http = $http ?? new WpHttpClient();

		// GA4: dostawca i modele wyłącznie z ustawień.
		$provider    = (string) Settings::get_field( 'provider', 'gemini' );
		$api_key     = (string) Settings::get_field( 'api_key', '' );
		$model       = (string) Settings::get_field( 'model', '' );
		$embed_model = (string) Settings::get_field( 'embed_model', '' );

		switch ( $provider ) {
			// Punkt rozszerzenia — kolejni dostawcy dokładani tutaj, np.:
			// case 'openai': return new OpenAiProvider( $http, $api_key, $model, $embed_model );
			// case 'groq':   return new GroqProvider( $http, $api_key, $model, $embed_model );
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
