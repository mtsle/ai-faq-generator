<?php
/**
 * Ustawienia wtyczki — konfiguracja API.
 *
 * Przechowuje opcje w wp_options pod kluczem `aifaq_settings`,
 * rejestruje je przez Settings API (z sanityzacją) oraz obsługuje
 * akcję AJAX „Test połączenia" (realny ping klucza do Gemini).
 *
 * @package AI_FAQ_Generator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Warstwa ustawień i konfiguracji API.
 */
class AIFAQ_Settings {

	/**
	 * Nazwa opcji w wp_options.
	 */
	const OPTION = 'aifaq_settings';

	/**
	 * Grupa ustawień (Settings API).
	 */
	const GROUP = 'aifaq_settings_group';

	/**
	 * Akcja i nonce dla testu połączenia.
	 */
	const AJAX_TEST   = 'aifaq_test_connection';
	const NONCE_TEST  = 'aifaq_test_connection';

	/**
	 * Uprawnienie wymagane do zmian ustawień.
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Wartości domyślne.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'provider'      => 'gemini',
			'api_key'       => '',
			'model'         => 'gemini-1.5-flash',
			'temperature'   => 0.4,
			'max_questions' => 20,
			'language'      => 'pl',
		);
	}

	/**
	 * Dostępne modele (whitelista + etykiety).
	 *
	 * @return array<string,string>
	 */
	public static function models(): array {
		return array(
			'gemini-1.5-flash'    => __( 'Gemini 1.5 Flash (szybki, darmowy)', 'ai-faq-generator' ),
			'gemini-1.5-flash-8b' => __( 'Gemini 1.5 Flash-8B (najlżejszy)', 'ai-faq-generator' ),
			'gemini-1.5-pro'      => __( 'Gemini 1.5 Pro (jakość)', 'ai-faq-generator' ),
		);
	}

	/**
	 * Dostępne języki (whitelista + etykiety).
	 *
	 * @return array<string,string>
	 */
	public static function languages(): array {
		return array(
			'pl' => __( 'polski', 'ai-faq-generator' ),
			'en' => __( 'angielski', 'ai-faq-generator' ),
			'de' => __( 'niemiecki', 'ai-faq-generator' ),
		);
	}

	/**
	 * Zwraca wszystkie ustawienia (scalone z domyślnymi).
	 *
	 * @return array<string,mixed>
	 */
	public static function get(): array {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return array_merge( self::defaults(), $stored );
	}

	/**
	 * Zwraca pojedyncze ustawienie.
	 *
	 * @param string $key     Klucz ustawienia.
	 * @param mixed  $default Wartość domyślna, gdy brak.
	 * @return mixed
	 */
	public static function get_field( string $key, $default = null ) {
		$all = self::get();
		return $all[ $key ] ?? $default;
	}

	/**
	 * Rejestruje ustawienia (hook admin_init).
	 */
	public function register(): void {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * Sanityzacja i walidacja wejścia z formularza.
	 *
	 * @param mixed $input Surowe dane z formularza.
	 * @return array<string,mixed>
	 */
	public static function sanitize( $input ): array {
		$defaults = self::defaults();
		$current  = self::get();
		$input    = is_array( $input ) ? $input : array();
		$out      = $current;

		// Dostawca (na razie tylko Gemini).
		$out['provider'] = 'gemini';

		// Klucz API — bez tagów i białych znaków brzegowych.
		if ( isset( $input['api_key'] ) ) {
			$out['api_key'] = trim( sanitize_text_field( $input['api_key'] ) );
		}

		// Model — tylko z whitelisty.
		$models        = array_keys( self::models() );
		$out['model']  = ( isset( $input['model'] ) && in_array( $input['model'], $models, true ) )
			? $input['model']
			: $defaults['model'];

		// Temperatura — zakres 0.0–1.0, krok 0.1.
		$temp                = isset( $input['temperature'] ) ? (float) $input['temperature'] : $defaults['temperature'];
		$out['temperature']  = max( 0.0, min( 1.0, round( $temp, 1 ) ) );

		// Maksymalna liczba pytań — zakres 5–20.
		$maxq                 = isset( $input['max_questions'] ) ? (int) $input['max_questions'] : $defaults['max_questions'];
		$out['max_questions'] = max( 5, min( 20, $maxq ) );

		// Język — tylko z whitelisty.
		$langs           = array_keys( self::languages() );
		$out['language'] = ( isset( $input['language'] ) && in_array( $input['language'], $langs, true ) )
			? $input['language']
			: $defaults['language'];

		return $out;
	}

	/**
	 * AJAX: „Test połączenia" — realny, lekki ping klucza do Gemini.
	 *
	 * Odpytuje endpoint listy modeli (nie generuje FAQ), żeby wyłącznie
	 * sprawdzić, czy klucz autoryzuje. Zwraca zielony/czerwony komunikat.
	 */
	public function ajax_test_connection(): void {
		check_ajax_referer( self::NONCE_TEST, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Brak uprawnień.', 'ai-faq-generator' ) ), 403 );
		}

		$api_key = isset( $_POST['api_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) ) : '';

		if ( '' === $api_key ) {
			wp_send_json_error( array( 'message' => __( 'Podaj klucz API.', 'ai-faq-generator' ) ) );
		}

		$url = add_query_arg(
			'key',
			rawurlencode( $api_key ),
			'https://generativelanguage.googleapis.com/v1beta/models'
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error(
				array(
					/* translators: %s: komunikat błędu sieci */
					'message' => sprintf( __( 'Błąd sieci: %s', 'ai-faq-generator' ), $response->get_error_message() ),
				)
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 === $code ) {
			wp_send_json_success( array( 'message' => __( 'Połączenie OK — klucz działa.', 'ai-faq-generator' ) ) );
		}

		$body  = json_decode( wp_remote_retrieve_body( $response ), true );
		$error = '';
		if ( is_array( $body ) && isset( $body['error']['message'] ) ) {
			$error = (string) $body['error']['message'];
		} else {
			/* translators: %d: kod odpowiedzi HTTP */
			$error = sprintf( __( 'kod odpowiedzi %d', 'ai-faq-generator' ), $code );
		}

		wp_send_json_error(
			array(
				/* translators: %s: komunikat błędu z API */
				'message' => sprintf( __( 'Błąd: %s', 'ai-faq-generator' ), $error ),
			)
		);
	}
}
