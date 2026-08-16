<?php
/**
 * Naglowki bezpieczenstwa dla widokow Centrum Wiedzy. Krok 7, etap 7.1.
 *
 * @package AI_News_Portal
 */

namespace AINP;

/**
 * Wysylka naglowkow bezpieczenstwa — UZUPELNIAJACA, NIGDY NADPISUJACA.
 *
 * DLACZEGO TA KLASA W OGOLE ISTNIEJE. Pomiar na poligonie (2026-08-16) pokazal,
 * ze wtyczka nie wysylala do tej pory ZADNEGO naglowka: wszystko, co widac bylo
 * w odpowiedzi HTTP, pochodzilo z motywu (`inc/security.php`, `send_headers`).
 * Na motywie, ktory takiego pliku nie ma — a to wiekszosc motywow — strony
 * Centrum Wiedzy szly bez jednego naglowka bezpieczenstwa.
 *
 * DLACZEGO NIE PELNE CSP. Front tej wtyczki NIE jest samodzielna podstrona:
 * `archive.php` i `single.php` wolaja `get_header()` i `get_footer()`, wiec
 * strone renderuje motyw klienta, a wtyczka dokłada srodek. Zmierzone na
 * dworku: strona portalu ma 5 blokow `<style>` i 7 `<script>` — WSZYSTKIE
 * cudze; nasze szablony maja zero inline, a `portal.css` idzie przez
 * `wp_enqueue_style()`. Polityka bez `'unsafe-inline'` zabilaby wiec motyw,
 * a polityka z `'unsafe-inline'` daje bezpieczenstwo pozorne i przy okazji
 * blokuje fonty, mapy i analitykę, ktore motyw ciagnie z cudzych domen.
 * Stad decyzja usera z 2026-08-16: CSP tej wtyczki ogranicza sie do
 * `frame-ancestors 'self'` — to samo rozstrzygniecie, ktore wtyczka 1
 * stosuje dla stron ze shortcode'em.
 *
 * TRZY ZASADY, KTORE TRZYMAJA CALOSC:
 *
 *   1. Naglowek, ktory juz istnieje, NIE JEST ruszany. Sprawdzenie idzie przez
 *      `headers_list()`, per naglowek, nie hurtem.
 *   2. Wysylka zawsze z `replace = false`. `headers_list()` nie widzi naglowkow
 *      dokladanych przez serwer WWW (nginx, Apache), wiec sama detekcja nie
 *      wystarcza — drugi argument `false` gwarantuje, ze cudzej polityki nie
 *      da sie nadpisac nawet wtedy, gdy detekcja jej nie zobaczy.
 *   3. Klient ma jawna sciezke wyjscia: filtr `ainp_security_headers`. Zwrocenie
 *      pustej tablicy wylacza mechanizm w calosci, bez dotykania kodu wtyczki.
 *
 * @since 0.7.0
 */
final class Security {

	/**
	 * Filtr, ktorym klient zmienia albo wylacza polityke.
	 *
	 * Dostaje tablice `naglowek => wartosc` oraz nazwe widoku. Pusta tablica
	 * znaczy „nie wysylaj nic" — to jest udokumentowana sciezka dla witryny,
	 * ktora ma wlasna polityke bezpieczenstwa.
	 */
	public const FILTER = 'ainp_security_headers';

	/** Widok pelnostronicowy: archiwum, kategoria, artykul. */
	public const VIEW_PAGE = 'page';

	/** Ramka oEmbed pod `/centrum-wiedzy/tytul/embed/`. */
	public const VIEW_EMBED = 'embed';

	/** Kanal RSS naszego archiwum albo kategorii. */
	public const VIEW_FEED = 'feed';

	/** Brak dopasowania — to nie jest zadanie do naszego widoku. */
	public const VIEW_NONE = '';

	/**
	 * Podpiecie. Wolane z `Plugin::boot()`.
	 *
	 * `template_redirect` z priorytetem 0, bo:
	 *
	 *   - jest PRZED jakimkolwiek wyjsciem, wiec `header()` jeszcze dziala
	 *     (po pierwszym bajcie tresci naglowek juz nie wyjdzie);
	 *   - odpala sie takze dla kanalow RSS i dla embedow — obie te sciezki ida
	 *     przez `template-loader.php`, wiec zadna nie ucieka mechanizmowi;
	 *   - NIE odpala sie w kokpicie. To jest wlasnie powod, dla ktorego nie
	 *     stoimy na `send_headers`: tamten hook chodzi rowniez w `wp-admin`,
	 *     a polityka frontu nie ma tam czego szukac.
	 *
	 * Rejestracja jest bezwarunkowa — warunek wokol REJESTRACJI hooka to blad,
	 * ktory we wtyczce 1 kosztowal osobny etap napraw.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'template_redirect', array( self::class, 'maybe_send' ), 0 );
	}

	/**
	 * Wysyla naglowki, o ile zadanie dotyczy naszego widoku.
	 *
	 * @return void
	 */
	public static function maybe_send(): void {
		$widok = self::view();

		if ( self::VIEW_NONE === $widok ) {
			return;
		}

		$naglowki = apply_filters( self::FILTER, self::headers_for( $widok ), $widok );

		if ( ! is_array( $naglowki ) ) {
			return;
		}

		self::emit( $naglowki );
	}

	/**
	 * Rozstrzyga, ktory widok obsluguje biezace zadanie.
	 *
	 * KOLEJNOSC BRAMEK JEST TU CALYM MECHANIZMEM i wynika z dwoch ustalen
	 * zmierzonych w tym projekcie:
	 *
	 *   - `is_post_type_archive()` jest prawdziwe TAKZE dla `/…/feed/`, wiec
	 *     kanal RSS musi zostac rozpoznany PRZED widokiem pelnostronicowym.
	 *     Inaczej XML dostalby polityke ramek, ktora nic tam nie znaczy;
	 *   - `is_embed()` i `is_singular()` sa prawdziwe JEDNOCZESNIE (ustalenie
	 *     audytowe P1 z Kroku 6), wiec embed tez musi wyjsc przed strona.
	 *
	 * @return string Jedna ze stalych `VIEW_*`.
	 */
	public static function view(): string {
		if ( is_admin() ) {
			return self::VIEW_NONE;
		}

		if ( ! self::is_ours() ) {
			return self::VIEW_NONE;
		}

		if ( is_feed() ) {
			return self::VIEW_FEED;
		}

		if ( is_embed() ) {
			return self::VIEW_EMBED;
		}

		return self::VIEW_PAGE;
	}

	/**
	 * Czy zapytanie dotyczy tresci tej wtyczki.
	 *
	 * Trzy widoki i nic wiecej: artykul, archiwum kategorii, archiwum typu.
	 * Strona glowna klienta, jego wpisy i jego strony maja zostac nietkniete —
	 * wtyczka nie jest wtyczka bezpieczenstwa calej witryny.
	 *
	 * @return bool
	 */
	private static function is_ours(): bool {
		return is_singular( Plugin::CPT )
			|| is_tax( Plugin::TAX )
			|| is_post_type_archive( Plugin::CPT );
	}

	/**
	 * Zestaw naglowkow dla danego widoku.
	 *
	 * ROZNICE MIEDZY WIDOKAMI SA SWIADOME, nie sa efektem ubocznym warunku:
	 *
	 *   - EMBED nie dostaje ani `X-Frame-Options`, ani `frame-ancestors`.
	 *     Ramka oEmbed istnieje po to, zeby cudza strona mogla ja osadzic —
	 *     zakaz osadzania na tej wlasnie sciezce znosilby jej jedyny sens.
	 *     Tresc embeda to karta artykulu, czyli to samo, co i tak jest publiczne
	 *     pod adresem artykulu, wiec clickjacking nie ma tu czego ukrasc.
	 *   - FEED dostaje wylacznie `X-Content-Type-Options` i `Referrer-Policy`.
	 *     Reszta opisuje zachowanie DOKUMENTU HTML w przegladarce; dla XML-a
	 *     czytanego przez czytnik kanalow jest martwa, a `nosniff` ma tu realne
	 *     zadanie: broni przed potraktowaniem kanalu jako HTML.
	 *   - STRONA dostaje komplet, z CSP zawezonym do ramek.
	 *
	 * CSP CELOWO NIE ZAWIERA dyrektyw o zrodlach (`script-src`, `style-src`,
	 * `default-src`) ani `'unsafe-inline'`. To nie jest przeoczenie: polityka
	 * zrodel nalezy do wlasciciela strony, czyli do motywu, a nie do wtyczki,
	 * ktora dokłada do niej jeden fragment tresci. Test pilnuje tego jako
	 * niezmiennika.
	 *
	 * @param string $widok Jedna ze stalych `VIEW_*`.
	 *
	 * @return array<string,string> Mapa `naglowek => wartosc`.
	 */
	public static function headers_for( string $widok ): array {
		if ( self::VIEW_NONE === $widok ) {
			return array();
		}

		// Wspolne dla kazdego widoku, ktory obslugujemy.
		$naglowki = array(
			'X-Content-Type-Options' => 'nosniff',
			'Referrer-Policy'        => 'strict-origin-when-cross-origin',
		);

		if ( self::VIEW_FEED === $widok ) {
			return $naglowki;
		}

		$naglowki['Permissions-Policy'] = 'geolocation=(), camera=(), microphone=(), payment=(), usb=(), interest-cohort=()';

		if ( self::VIEW_EMBED === $widok ) {
			return $naglowki;
		}

		$naglowki['X-Frame-Options']         = 'SAMEORIGIN';
		$naglowki['Content-Security-Policy'] = "frame-ancestors 'self'";

		return $naglowki;
	}

	/**
	 * Wysyla te naglowki, ktorych jeszcze nie ma.
	 *
	 * Zwraca liste faktycznie wyslanych — nie dla wtyczki, tylko po to, zeby
	 * dalo sie to asercjonowac. Rzecz, ktorej nie widac w wyniku funkcji, nie
	 * da sie sprawdzic testem, a mutacja gasnaca bez sladu kłamie o pokryciu
	 * (nauka z serii mutacyjnej Kroku 5).
	 *
	 * @param array<string,string> $naglowki Mapa `naglowek => wartosc`.
	 *
	 * @return array<int,string> Nazwy wyslanych naglowkow.
	 */
	public static function emit( array $naglowki ): array {
		if ( headers_sent() ) {
			return array();
		}

		$juz = self::existing();
		$wyslane = array();

		foreach ( $naglowki as $nazwa => $wartosc ) {
			$nazwa   = (string) $nazwa;
			$wartosc = (string) $wartosc;

			if ( '' === $nazwa || '' === $wartosc ) {
				continue;
			}

			// Naglowek z wlamana linia rozbilby odpowiedz na dwie. Wartosc
			// pochodzi dzis ze stalych, ale przechodzi przez filtr klienta.
			if ( preg_match( '/[\r\n]/', $nazwa . $wartosc ) ) {
				continue;
			}

			if ( in_array( strtolower( $nazwa ), $juz, true ) ) {
				continue;
			}

			/*
			 * Drugi argument `false` jest TU CALA ROZNICA miedzy uzupelnianiem
			 * a nadpisywaniem. Nigdy nie zamieniamy go na `true`: motyw albo
			 * serwer moga miec wlasna polityke, ktorej `headers_list()` nie
			 * pokazuje, a jej podmiana bylaby cicha zmiana zabezpieczen cudzej
			 * witryny.
			 */
			header( $nazwa . ': ' . $wartosc, false );
			$wyslane[] = $nazwa;
		}

		return $wyslane;
	}

	/**
	 * Nazwy juz wyslanych naglowkow, malymi literami.
	 *
	 * @return array<int,string>
	 */
	private static function existing(): array {
		$nazwy = array();

		foreach ( headers_list() as $linia ) {
			$dwukropek = strpos( $linia, ':' );

			if ( false === $dwukropek ) {
				continue;
			}

			$nazwy[] = strtolower( trim( substr( $linia, 0, $dwukropek ) ) );
		}

		return $nazwy;
	}
}
