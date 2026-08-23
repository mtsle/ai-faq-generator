<?php
/**
 * Tryb demo — ograniczenia dla publicznej wystawy wtyczki.
 *
 * @package AI_FAQ_Generator
 */

namespace AIFAQ\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ograniczenia trybu demo.
 *
 * Włącza go stała `AIFAQ_DEMO` w `wp-config.php` (ustawia ją bootstrap wystawy,
 * nie wtyczka). Bez stałej cała klasa jest martwa: każde pytanie dostaje
 * odpowiedź „wolno" bez jednego zapytania do bazy.
 *
 * Po co istnieje: na wystawie kokpit jest otwarty dla wszystkich, a konto
 * gościa MUSI mieć `manage_options` — bez tego nie zobaczy ani jednego ekranu
 * wtyczki. To znaczy, że gość ma technicznie dostęp do wszystkiego, co robi
 * właściciel: podmiany klucza, przebudowy indeksu (embedding całej witryny),
 * a nawet podniesienia limitów, które mają go powstrzymać. Tryb demo odbiera
 * właśnie te możliwości, zostawiając resztę panelu do oglądania.
 *
 * Trzy grupy ograniczeń:
 *
 *   1. ZAMROŻONE USTAWIENIA — klucz API, oba modele, dobowy sufit witryny,
 *      limit i okno limitera oraz przełącznik zaufanego proxy. Ostatni jest tu
 *      nieoczywisty, a najgroźniejszy: przy `rag_trusted_proxy` limiter bierze
 *      adres z nagłówka `X-Forwarded-For`, który ustawia sam klient — gość
 *      włączyłby go i miałby po jednym kubełku na każdy zmyślony adres.
 *   2. OPERACJE NIEDOSTĘPNE — przebudowa i czyszczenie bazy wiedzy oraz test
 *      połączenia. Reindeks to embedding całej witryny, czyszczenie zostawia
 *      wystawę bez indeksu do najbliższego resetu, a test połączenia pali
 *      wywołanie na każde kliknięcie.
 *   3. LIMITY NA ADRES IP przy generatorze FAQ. Wtyczka ma już limit
 *      godzinowy per użytkownik, ale na wystawie WSZYSCY goście siedzą na
 *      jednym koncie `demo` — kubełek per użytkownik jest tam kubełkiem
 *      wspólnym i nie rozdziela nikogo od nikogo.
 *
 * Liczniki żyją w transientach i znikają razem z resetem wystawy.
 */
final class Demo {

	/** Odstęp między generacjami FAQ z jednego adresu IP (sekundy). */
	public const GENERATE_ODSTEP = 300;

	/** Ile generacji FAQ na dobę z jednego adresu IP. */
	public const GENERATE_NA_DOBE = 3;

	/** Doba w sekundach — życie licznika dobowego. */
	private const DOBA = 86400;

	/** Prefiks transientów trybu demo. */
	private const TRANSIENT = 'aifaq_demo_';

	/**
	 * Pola ustawień, których na wystawie nie wolno zmienić.
	 *
	 * Lista jest w JEDNYM miejscu, bo `Settings::sanitize()` obsługuje każde
	 * z tych pól osobnym blokiem — rozsypanie warunku po tych blokach dałoby
	 * siedem miejsc do przeoczenia przy następnym polu.
	 *
	 * @var array<int,string>
	 */
	public const POLA_ZAMROZONE = array(
		'api_key',
		'model',
		'embed_model',
		'rag_daily_budget',
		'rag_rate_limit',
		'rag_rate_window',
		'rag_trusted_proxy',
	);

	/**
	 * Czy instalacja stoi w trybie demo.
	 *
	 * Stała, nie opcja: opcję da się zmienić z tego samego otwartego kokpitu,
	 * który tryb demo ma chronić.
	 *
	 * @return bool
	 */
	public static function active(): bool {
		return defined( 'AIFAQ_DEMO' ) && (bool) constant( 'AIFAQ_DEMO' );
	}

	/**
	 * Przywraca zamrożone pola do wartości sprzed zapisu.
	 *
	 * Wołane na KOŃCU `Settings::sanitize()`, więc obejmuje obie ścieżki zapisu
	 * ustawień: formularz kokpitu (Settings API) i `POST /admin/settings`
	 * z aplikacji na podstronie. Blokada w samym formularzu zatrzymałaby
	 * przeglądarkę, ale nie ręcznie złożone żądanie.
	 *
	 * Poza trybem demo oddaje wejście bez zmian.
	 *
	 * @param array<string,mixed> $out     Ustawienia po sanityzacji.
	 * @param array<string,mixed> $current Ustawienia sprzed zapisu.
	 *
	 * @return array<string,mixed>
	 */
	public static function freeze( array $out, array $current ): array {
		if ( ! self::active() ) {
			return $out;
		}

		foreach ( self::POLA_ZAMROZONE as $pole ) {
			if ( array_key_exists( $pole, $current ) ) {
				$out[ $pole ] = $current[ $pole ];
			} else {
				unset( $out[ $pole ] );
			}
		}

		return $out;
	}

	/**
	 * Czy wolno wykonać operację objętą odstępem. Odpowiedź „wolno" REZERWUJE
	 * odstęp: następne pytanie z tego samego adresu IP dostanie odmowę.
	 *
	 * @param string $akcja  Nazwa operacji.
	 * @param int    $odstep Odstęp w sekundach.
	 *
	 * @return bool
	 */
	public static function allow( string $akcja, int $odstep ): bool {
		if ( ! self::active() ) {
			return true;
		}

		$klucz = self::TRANSIENT . $akcja . '_' . self::ip_hash();

		if ( false !== get_transient( $klucz ) ) {
			return false;
		}

		set_transient( $klucz, time(), max( 1, $odstep ) );

		return true;
	}

	/**
	 * Czy wolno wygenerować FAQ: limit dobowy na adres IP ORAZ odstęp.
	 *
	 * Limit sprawdzany PRZED odstępem — odmowa z wyczerpanego limitu nie ma
	 * rezerwować odstępu, bo i tak nic się nie wydarzy, a przedłużałaby
	 * blokadę o kolejne minuty przy każdym bezskutecznym kliknięciu.
	 *
	 * @return bool
	 */
	public static function allow_generate(): bool {
		if ( ! self::active() ) {
			return true;
		}

		$klucz = self::TRANSIENT . 'gencnt_' . self::ip_hash();
		$ile   = (int) get_transient( $klucz );

		if ( $ile >= self::GENERATE_NA_DOBE ) {
			return false;
		}

		if ( ! self::allow( 'generate', self::GENERATE_ODSTEP ) ) {
			return false;
		}

		set_transient( $klucz, $ile + 1, self::DOBA );

		return true;
	}

	/**
	 * Odcisk adresu IP gościa.
	 *
	 * Świadomie NIE korzysta z `GuestIdentity::ip_hash()`, choć tamta klasa
	 * robi to samo: tamta honoruje ustawienie `rag_trusted_proxy`, czyli czyta
	 * adres z nagłówka ustawianego przez klienta. Limit demo ma być odporny
	 * także wtedy, gdy ustawienie zostanie kiedyś włączone we wzorcu wystawy.
	 *
	 * @return string
	 */
	private static function ip_hash(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '0.0.0.0';

		return md5( $ip );
	}
}
