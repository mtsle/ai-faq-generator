<?php
/**
 * Tryb demo — ograniczenia dla publicznej wystawy wtyczki.
 *
 * Wlacza go stala `AINP_DEMO` w `wp-config.php` (ustawia ja bootstrap demo,
 * nie wtyczka). Na zwyklej instalacji stala nie istnieje i cala klasa jest
 * martwa: kazda metoda `allow_*()` odpowiada „wolno" bez jednego zapytania.
 *
 * Po co ta klasa istnieje: demo stoi pod otwartym kokpitem i darmowym kluczem
 * Gemini z pula 20 wywolan na dobe. Bez ograniczen pierwszy ciekawski
 * wyczerpalby pule przed poludniem, a drugi podmienilby klucz na wlasny albo
 * skasowal go w ogole. Tryb demo:
 *
 *   1. BLOKUJE zapis, podmiane i kasowanie klucza API (`Admin::save_key()`).
 *   2. BLOKUJE zmiane modelu i sufitu dobowego (`Admin::sanitize_settings()`)
 *      — podniesiony sufit albo model spoza darmowej puli zabilyby klucz
 *      rownie skutecznie, co jego podmiana.
 *   3. Wymusza ODSTEP miedzy przebiegami na adres IP: „Pobierz teraz"
 *      i „Przygotuj treści" to praca sieciowa, „Opublikuj teraz" dodatkowo
 *      pali wywolania z dobowej puli.
 *   4. Naklada dobowy limit „Opublikuj teraz" NA ADRES IP — niezalezny od
 *      wspolnego sufitu `daily_cap`, zeby jeden gosc nie zjadl puli wszystkim.
 *
 * Liczniki zyja w transientach (baza), nie w pamieci — na demo requesty
 * obsluguja rozne procesy PHP. Reset wystawy co 6 godzin czysci je razem
 * z cala baza, co jest zachowaniem pozadanym: swieza wystawa, swieze limity.
 *
 * @package AI_News_Portal
 */

namespace AINP;

// Blokada bezposredniego wywolania pliku.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ograniczenia trybu demo.
 */
final class Demo {

	/** Odstep miedzy przebiegami „Pobierz teraz" na adres IP (sekundy). */
	public const FETCH_ODSTEP = 600;

	/** Odstep miedzy przebiegami „Przygotuj treści" na adres IP (sekundy). */
	public const PREPARE_ODSTEP = 600;

	/** Odstep miedzy przebiegami „Opublikuj teraz" na adres IP (sekundy). */
	public const PUBLISH_ODSTEP = 300;

	/** Ile razy na dobe jeden adres IP moze kliknac „Opublikuj teraz". */
	public const PUBLISH_NA_DOBE = 3;

	/** Doba w sekundach — zycie licznika publikacji na adres IP. */
	private const DOBA = 86400;

	/** Prefiks transientow trybu demo. */
	private const TRANSIENT = 'ainp_demo_';

	/**
	 * Czy instalacja stoi w trybie demo.
	 *
	 * Stala, nie opcja: opcje da sie zmienic z otwartego kokpitu, ktory tryb
	 * demo ma wlasnie chronic. Stala w `wp-config.php` jest poza zasiegiem
	 * kazdego, kto nie ma dostepu do plikow maszyny.
	 *
	 * @return bool
	 */
	public static function active(): bool {
		return defined( 'AINP_DEMO' ) && (bool) constant( 'AINP_DEMO' );
	}

	/**
	 * Czy wolno uruchomic przebieg spod przycisku. Odpowiedz „wolno" REZERWUJE
	 * odstep: nastepne pytanie z tego samego adresu IP dostanie odmowe az do
	 * wygasniecia transientu.
	 *
	 * Poza trybem demo zawsze „wolno", bez dotykania bazy.
	 *
	 * @param string $akcja  Nazwa akcji: `fetch`, `prepare` albo `publish`.
	 * @param int    $odstep Odstep w sekundach.
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
	 * Czy wolno kliknac „Opublikuj teraz": limit dobowy na adres IP ORAZ
	 * odstep miedzy klikami.
	 *
	 * Limit sprawdzany PRZED odstepem — odmowa z powodu wyczerpanego limitu
	 * nie ma rezerwowac odstepu, bo i tak nic sie nie wydarzy.
	 *
	 * @return bool
	 */
	public static function allow_publish(): bool {
		if ( ! self::active() ) {
			return true;
		}

		$klucz = self::TRANSIENT . 'pubcnt_' . self::ip_hash();
		$ile   = (int) get_transient( $klucz );

		if ( $ile >= self::PUBLISH_NA_DOBE ) {
			return false;
		}

		if ( ! self::allow( 'publish', self::PUBLISH_ODSTEP ) ) {
			return false;
		}

		set_transient( $klucz, $ile + 1, self::DOBA );

		return true;
	}

	/**
	 * Odcisk adresu IP goscia.
	 *
	 * `REMOTE_ADDR`, nie naglowki `X-Forwarded-For`: demo stoi bez proxy,
	 * a naglowek ustawia klient — limit liczony po nim bylby limitem
	 * na dobra wole goscia. Skrot zamiast surowego adresu, zeby adresy IP
	 * gosci nie osiadaly w tabeli opcji.
	 *
	 * @return string
	 */
	private static function ip_hash(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '0.0.0.0';

		return md5( $ip );
	}
}
