<?php
/**
 * Tożsamość gościa dla warstwy REST — pseudonimowy identyfikator zamiast adresu IP.
 *
 * Wydzielone z {@see RestController} (Krok 23): rozpoznawanie proxy i haszowanie
 * adresu to osobna odpowiedzialność niż mapowanie HTTP, a kontroler wołał to
 * wyłącznie po to, żeby podać kubełek limitera do {@see \AIFAQ\Rag\RagService}.
 *
 * @package AI_FAQ_Generator
 */

namespace AIFAQ\Rest;

use AIFAQ\Core\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wyliczanie identyfikatora gościa (sha256 soli i adresu).
 */
class GuestIdentity {

	/**
	 * Identyfikator gościa: sha256(sól | adres) — nie przechowujemy IP (GR7).
	 *
	 * DOMYŚLNIE (`rag_trusted_proxy` wyłączone) źródłem jest wyłącznie `REMOTE_ADDR`:
	 * nagłówki proxy są podszywalne, a bez odwrotnego proxy przed witryną każdy gość
	 * mógłby sobie sam wystawić świeży kubełek limitera.
	 *
	 * Po WŁĄCZENIU przełącznika (witryna naprawdę stoi za Cloudflare/nginx) bierzemy
	 * pierwszego dostępnego kandydata: `CF-Connecting-IP`, potem OSTATNI element
	 * `X-Forwarded-For`. Ostatni, nie pierwszy — Cloudflare i typowy
	 * `proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for` DOKLEJAJĄ obserwowany
	 * adres na koniec łańcucha, więc początek listy pochodzi od klienta i jest dowolny.
	 *
	 * Włączenie przełącznika zmienia hash wszystkich gości — bieżące limity resetują się
	 * jednorazowo (świadoma nieciągłość, opisana w README).
	 *
	 * @return string
	 */
	public function ip_hash(): string {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ip     = $remote;

		// `class_exists` w konwencji projektu (por. Deactivator → MenuGuard): w izolowanym
		// harnessie testowym klasa ustawień bywa nieładowana, a brak przełącznika ma
		// oznaczać zachowanie DOMYŚLNE (samo REMOTE_ADDR), nigdy błąd krytyczny.
		$trusted = class_exists( Settings::class )
			&& '1' === (string) Settings::get_field( 'rag_trusted_proxy', '0' );

		if ( $trusted ) {
			$candidate = '';

			if ( isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
				$candidate = trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) );
			}

			if ( '' === $candidate && isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
				$chain     = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
				$candidate = trim( (string) end( $chain ) );
			}

			// Wartość spoza formatu adresu IP (albo pusta) → cofamy się do REMOTE_ADDR.
			if ( '' !== $candidate && false !== filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
				$ip = $candidate;
			}
		} else {
			$this->flag_proxy_seen();
		}

		$salt = function_exists( 'wp_salt' ) ? wp_salt( 'nonce' ) : 'aifaq';
		return hash( 'sha256', $salt . '|' . $ip );
	}

	/**
	 * Sygnalizacja: witryna dostaje nagłówki proxy, a przełącznik jest WYŁĄCZONY.
	 *
	 * Bez tego klient za Cloudflare ma jeden kubełek limitera dla całego świata
	 * (`REMOTE_ADDR` to adres proxy, identyczny dla wszystkich gości) i nikt mu tego
	 * nie mówi. Zapisujemy zwykłą opcję bez autoload, jeden raz — komunikat w kokpicie
	 * czyta ją osobno.
	 */
	private function flag_proxy_seen(): void {
		if ( ! isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) && ! isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			return;
		}

		if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
			return;
		}

		if ( '1' === (string) get_option( 'aifaq_proxy_seen', '' ) ) {
			return;
		}

		update_option( 'aifaq_proxy_seen', '1', false );
	}
}
