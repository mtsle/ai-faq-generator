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
	 * Po WŁĄCZENIU przełącznika nagłówek jest wciąż danymi OD KLIENTA — `filter_var`
	 * sprawdza jego FORMAT, nie POCHODZENIE (Z-10). Do naprawy Z4 kod ufał mu bez
	 * sprawdzenia nadawcy: witryna za własnym nginx dostawała `CF-Connecting-IP`
	 * dopisany przez atakującego (S1), serwer źródłowy Cloudflare osiągalny po IP
	 * przyjmował nagłówek z pominięciem Cloudflare (S2), a XFF bez proxy był w całości
	 * wartością klienta (S3) — każde żądanie dostawało świeży kubełek limitera.
	 * Teraz {@see self::client_ip()}: nagłówek WYŁĄCZNIE od nadawcy z listy zaufanych
	 * proxy, jawnie wybrany nagłówek, pusta lista = nagłówki ignorowane.
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
			$ip = self::client_ip(
				$remote,
				Settings::proxy_list( (string) Settings::get_field( 'rag_trusted_proxies', '' ) ),
				(string) Settings::get_field( 'rag_proxy_header', 'cf' ),
				isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) : '',
				isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) : ''
			);
		} else {
			$this->flag_proxy_seen();
		}

		$salt = function_exists( 'wp_salt' ) ? wp_salt( 'nonce' ) : 'aifaq';
		return hash( 'sha256', $salt . '|' . $ip );
	}

	/**
	 * Adres gościa przy włączonym zaufanym proxy — czysta funkcja, zero I/O.
	 *
	 * 1. Pusta lista zaufanych albo nadawca (`REMOTE_ADDR`) spoza listy → `REMOTE_ADDR`.
	 *    Żądanie, które nie przyszło od naszego proxy, nie ma prawa nam nic mówić
	 *    o adresie gościa (S1, S2, S3).
	 * 2. `cf` → `CF-Connecting-IP`; `X-Forwarded-For` ignorowany.
	 * 3. `xff` → łańcuch OD PRAWEJ: zaufane proxy pomijamy, pierwszy adres spoza
	 *    listy to gość. Proxy DOKLEJA obserwowany adres na koniec, więc lewa strona
	 *    pochodzi od klienta. Śmieć przed znalezieniem gościa → `REMOTE_ADDR`
	 *    (nie zgadujemy). `CF-Connecting-IP` ignorowany.
	 *
	 * @param string            $remote   REMOTE_ADDR.
	 * @param array<int,string> $zaufane  Lista z {@see Settings::proxy_list()}.
	 * @param string            $naglowek 'cf' albo 'xff'.
	 * @param string            $cf       Wartość CF-Connecting-IP ('' = brak).
	 * @param string            $xff      Wartość X-Forwarded-For ('' = brak).
	 *
	 * @return string
	 */
	public static function client_ip( string $remote, array $zaufane, string $naglowek, string $cf, string $xff ): string {
		// Pusta lista to także „nadawca spoza listy" — ip_on_list() zwraca wtedy false.
		if ( ! self::ip_on_list( $remote, $zaufane ) ) {
			return $remote;
		}

		if ( 'xff' === $naglowek ) {
			foreach ( array_reverse( explode( ',', $xff ) ) as $ogniwo ) {
				$ogniwo = trim( $ogniwo );

				if ( false === filter_var( $ogniwo, FILTER_VALIDATE_IP ) ) {
					return $remote;
				}

				if ( ! self::ip_on_list( $ogniwo, $zaufane ) ) {
					return $ogniwo;
				}
			}

			return $remote;
		}

		$kandydat = trim( $cf );

		return ( '' !== $kandydat && false !== filter_var( $kandydat, FILTER_VALIDATE_IP ) ) ? $kandydat : $remote;
	}

	/**
	 * Czy adres należy do listy (adres dokładny albo zakres CIDR, IPv4 i IPv6).
	 *
	 * @param string            $ip    Adres do sprawdzenia.
	 * @param array<int,string> $lista Wpisy z {@see Settings::proxy_list()}.
	 *
	 * @return bool
	 */
	public static function ip_on_list( string $ip, array $lista ): bool {
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}

		$bin = inet_pton( $ip );

		foreach ( $lista as $wpis ) {
			$adres   = (string) $wpis;
			$prefiks = null;

			if ( false !== strpos( $adres, '/' ) ) {
				list( $adres, $dlugosc ) = explode( '/', $adres, 2 );
				$prefiks                 = (int) $dlugosc;
			}

			if ( false === filter_var( $adres, FILTER_VALIDATE_IP ) ) {
				continue;
			}

			$siec = inet_pton( $adres );

			// Rodziny adresów muszą się zgadzać (4 bajty IPv4, 16 bajtów IPv6).
			if ( false === $siec || false === $bin || strlen( $siec ) !== strlen( $bin ) ) {
				continue;
			}

			if ( null === $prefiks ) {
				if ( $siec === $bin ) {
					return true;
				}
				continue;
			}

			$pelne  = intdiv( $prefiks, 8 );
			$reszta = $prefiks % 8;

			if ( $prefiks > 8 * strlen( $bin ) || substr( $bin, 0, $pelne ) !== substr( $siec, 0, $pelne ) ) {
				continue;
			}

			if ( 0 === $reszta ) {
				return true;
			}

			$maska = ( 0xFF << ( 8 - $reszta ) ) & 0xFF;

			if ( ( ord( $bin[ $pelne ] ) & $maska ) === ( ord( $siec[ $pelne ] ) & $maska ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Który komunikat o proxy pokazać w kokpicie (czytelnik `aifaq_proxy_seen`, R10).
	 *
	 * @return string 'unconfigured' = przełącznik włączony, lista pusta (nagłówki
	 *                ignorowane, wszyscy goście w jednym kubełku); 'proxy_seen' = przełącznik
	 *                wyłączony, a żądania niosą nagłówki proxy; '' = nic do powiedzenia.
	 */
	public static function proxy_notice(): string {
		if ( ! class_exists( Settings::class ) ) {
			return '';
		}

		if ( '1' === (string) Settings::get_field( 'rag_trusted_proxy', '0' ) ) {
			return array() === Settings::proxy_list( (string) Settings::get_field( 'rag_trusted_proxies', '' ) ) ? 'unconfigured' : '';
		}

		return ( function_exists( 'get_option' ) && '1' === (string) get_option( 'aifaq_proxy_seen', '' ) ) ? 'proxy_seen' : '';
	}

	/**
	 * Sygnalizacja: witryna dostaje nagłówki proxy, a przełącznik jest WYŁĄCZONY.
	 *
	 * Bez tego klient za Cloudflare ma jeden kubełek limitera dla całego świata
	 * (`REMOTE_ADDR` to adres proxy, identyczny dla wszystkich gości) i nikt mu tego
	 * nie mówi. Zapisujemy zwykłą opcję bez autoload, jeden raz. Czytelnik:
	 * {@see self::proxy_notice()} → ostrzeżenie w kokpicie (dashboard.php).
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
