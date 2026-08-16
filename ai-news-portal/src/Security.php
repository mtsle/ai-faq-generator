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

	/**
	 * Stala wylaczajaca calosc, do wpisania w `wp-config.php`.
	 *
	 * Druga sciezka wyjscia, obok filtra. Istnieje, bo te dwie drogi trafiaja
	 * do dwoch roznych osob: filtr wymaga kodu w motywie potomnym albo we
	 * wlasnej wtyczce, a `define( 'AINP_NO_SECURITY_HEADERS', true );` moze
	 * dopisac administrator serwera, ktory nie pisze w PHP i nie ma gdzie
	 * trzymac wlasnego kodu.
	 */
	public const CONST_OFF = 'AINP_NO_SECURITY_HEADERS';

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
		if ( self::disabled() ) {
			return;
		}

		$widok = self::view();

		if ( self::VIEW_NONE === $widok ) {
			return;
		}

		/*
		 * Kolejnosc jest tu wiazaca: najpierw dopasowanie do polityki, ktora
		 * na tej witrynie JUZ obowiazuje, a dopiero potem filtr. Klient ma miec
		 * ostatnie slowo — gdyby filtr szedl pierwszy, `reconcile()` mogloby
		 * cofnac to, co klient swiadomie ustawil.
		 */
		$naglowki = self::reconcile( self::headers_for( $widok ) );
		$naglowki = apply_filters( self::FILTER, $naglowki, $widok );

		if ( ! is_array( $naglowki ) ) {
			return;
		}

		self::emit( $naglowki );
	}

	/**
	 * Czy mechanizm jest wylaczony stala z `wp-config.php`.
	 *
	 * @return bool
	 */
	public static function disabled(): bool {
		return defined( self::CONST_OFF ) && constant( self::CONST_OFF );
	}

	/**
	 * Dopasowuje nasz zestaw do polityki, ktora juz wisi w odpowiedzi.
	 *
	 * ROZSTRZYGNIECIE KONFLIKTU Z MOTYWEM — sedno etapu 7.2.
	 *
	 * Sam fakt, ze cudzego naglowka nie nadpisujemy, nie wystarcza. Zostaje
	 * pytanie, co zrobic z naszymi POZOSTALYMI naglowkami, kiedy motyw ma
	 * wlasne CSP. Sa dwa przypadki i rozniaca sie odpowiedz:
	 *
	 *   1. Cudze CSP ZAWIERA `frame-ancestors`. Wlasciciel witryny wypowiedzial
	 *      sie juz o osadzaniu w ramkach, i to nowoczesniejszym mechanizmem.
	 *      `X-Frame-Options` jest wtedy przez przegladarki IGNOROWANY (CSP ma
	 *      pierwszenstwo), wiec doklejanie go daje wylacznie szum w odpowiedzi
	 *      i pozorna sprzecznosc dla kogos, kto ja czyta. Nie wysylamy.
	 *   2. Cudze CSP NIE ZAWIERA `frame-ancestors`. Motyw opisal zrodla
	 *      zasobow, ale o ramkach nie powiedzial nic. Naszego CSP nie
	 *      dolozymy — dwa naglowki CSP dzialaja jak iloczyn polityk i mogłyby
	 *      zablokowac zasoby motywu. Zostaje `X-Frame-Options`, ktory tej
	 *      dziury nie zostawia i niczego cudzego nie psuje. Wysylamy.
	 *
	 * Zmierzone na poligonie: motyw dworka wysyla CSP Z `frame-ancestors`,
	 * czyli przypadek 1 — wtyczka nie dokłada tam ani CSP, ani ramek.
	 *
	 * @param array<string,string> $naglowki Zestaw dla widoku.
	 *
	 * @return array<string,string>
	 */
	public static function reconcile( array $naglowki ): array {
		$cudze_csp = self::existing_csp();

		if ( '' === $cudze_csp ) {
			return $naglowki;
		}

		unset( $naglowki['Content-Security-Policy'] );

		if ( false !== stripos( $cudze_csp, 'frame-ancestors' ) ) {
			unset( $naglowki['X-Frame-Options'] );
		}

		return $naglowki;
	}

	/**
	 * Wartosc CSP, ktore juz wisi w odpowiedzi, albo pusty napis.
	 *
	 * Wariant `-Report-Only` NIE liczy sie jako polityka: on niczego nie
	 * blokuje, tylko zglasza naruszenia, wiec zostawienie go samego byloby
	 * strona bez ochrony.
	 *
	 * @return string
	 */
	private static function existing_csp(): string {
		foreach ( headers_list() as $linia ) {
			$dwukropek = strpos( $linia, ':' );

			if ( false === $dwukropek ) {
				continue;
			}

			$nazwa = strtolower( trim( substr( $linia, 0, $dwukropek ) ) );

			if ( 'content-security-policy' === $nazwa ) {
				return trim( substr( $linia, $dwukropek + 1 ) );
			}
		}

		return '';
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

		/*
		 * Wspolne dla kazdego widoku. Uzasadnienie kazdego z osobna — etap 7.3:
		 *
		 * `nosniff` bo tresc artykulu pochodzi z modelu i z cudzej strony.
		 * Zgadywanie typu przez przegladarke jest tu dokladnie ta klasa
		 * ryzyka, ktora ten naglowek zamyka.
		 *
		 * `strict-origin-when-cross-origin` bo artykul MA link do zrodla, czyli
		 * wyjscie na cudza domene jest tu normalnym ruchem, nie wyjatkiem.
		 * Ta wartosc wysyla w takim przejsciu sama nazwe witryny zamiast
		 * pelnego adresu z fraza wyszukiwania, a przy zejsciu z HTTPS na HTTP
		 * nie wysyla nic. `no-referrer` bylby scislejszy, ale odbiera zrodlu
		 * informacje, kto do niego linkuje — a my z tego zrodla korzystamy.
		 */
		$naglowki = array(
			'X-Content-Type-Options' => 'nosniff',
			'Referrer-Policy'        => 'strict-origin-when-cross-origin',
		);

		if ( self::VIEW_FEED === $widok ) {
			return $naglowki;
		}

		/*
		 * Wylaczone sa wylacznie te funkcje przegladarki, ktorych zaden widok
		 * Centrum Wiedzy nie uzywa i uzywac nie bedzie (wtyczka nie ma ani
		 * jednej linii JavaScriptu). `interest-cohort` zostaje mimo wycofania
		 * FLoC: kosztuje kilkanascie bajtow, a wciaz trafiaja sie przegladarki,
		 * ktore go czytaja.
		 */
		$naglowki['Permissions-Policy'] = 'geolocation=(), camera=(), microphone=(), payment=(), usb=(), interest-cohort=()';

		if ( self::VIEW_EMBED === $widok ) {
			return $naglowki;
		}

		$naglowki['X-Frame-Options']         = 'SAMEORIGIN';
		$naglowki['Content-Security-Policy'] = "frame-ancestors 'self'";

		return $naglowki;
	}

	/**
	 * Naglowki ROZWAZONE I ODRZUCONE, z powodem. Etap 7.3.
	 *
	 * Ta lista nie jest komentarzem — test asercjonuje, ze zaden z tych
	 * naglowkow nie wychodzi w zadnym widoku. Powod jest praktyczny: zestaw
	 * wtyczki 1 lezy obok w tym samym repozytorium i skopiowanie go w calosci
	 * jest ruchem naturalnym, a bylby bledem. Tamta wtyczka wysyla komplet na
	 * SWOJEJ samodzielnej podstronie, ta dokłada tresc do cudzej strony.
	 *
	 * Kazdy wpis to decyzja, ktora ma zostac odwrocona swiadomie albo wcale.
	 *
	 * @return array<string,string> Mapa `naglowek => powod odrzucenia`.
	 */
	public static function rejected(): array {
		return array(
			'Strict-Transport-Security'   => 'Obejmuje CALA domene i wszystkie jej adresy, takze te, ktore z wtyczka nie maja nic wspolnego, na wiele miesiecy naprzod. Wtyczka podstrony nie ma prawa podjac tej decyzji za wlasciciela witryny. Po HTTP jest zreszta ignorowany.',
			'Cross-Origin-Opener-Policy'  => 'Zrywa `window.opener` dla okien otwieranych ze strony. Na cudzej stronie to droga do zepsucia logowania przez zewnetrzny serwis albo okna platnosci, ktorych wtyczka nie widzi i nie ma jak przetestowac.',
			'Cross-Origin-Resource-Policy' => 'Blokuje pobranie zasobu przez inny origin. Dla dokumentu HTML zysk jest zaden, a dla ramki oEmbed — ktora ma byc osadzana z cudzych domen — bylby wprost szkodliwy.',
			'X-XSS-Protection'            => 'Naglowek wycofany. Sterowal filtrem, ktorego zadna dzisiejsza przegladarka juz nie ma; jego jedyna sensowna wartosc to zero, czyli wylaczenie czegos, co nie istnieje.',
			'X-Permitted-Cross-Domain-Policies' => 'Dotyczy wtyczek Flash i Acrobat. Martwy technologicznie.',
		);
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
