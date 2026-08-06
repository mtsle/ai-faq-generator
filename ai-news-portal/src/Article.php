<?php
/**
 * Zdobycie tresci artykulu: scraping i prosta ekstrakcja.
 *
 * ETAP 3.3. Klasa wchodzi do gry dopiero wtedy, gdy pozycja PRZESZLA filtr —
 * i to jest cala oszczednosc tego etapu. Wszystkie cztery domyslne kanaly
 * podaja pelna tresc w `content:encoded`, wiec w normalnej pracy scraping
 * jest mechanizmem AWARYJNYM, nie glowna droga.
 *
 * Prog decyduje o jednym: czy tresc z kanalu wystarczy modelowi. Kanal, ktory
 * podaje sama zajawke („Przeczytaj wiecej…"), daje 150–400 znakow; artykul
 * ma ich kilka tysiecy. Miedzy tymi liczbami jest duzo miejsca, wiec prog nie
 * musi byc precyzyjny — ma tylko odroznic zajawke od tekstu.
 *
 * PHP NIE RENDERUJE JAVASCRIPTU. Strona budowana po stronie klienta odda tu
 * szkielet bez tresci i skonczy jako `skipped`. To jest zachowanie zamierzone,
 * zapisane w planie — nie usterka do obejscia.
 *
 * @package AI_News_Portal
 */

namespace AINP;

// Blokada bezposredniego wywolania pliku.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scraping i ekstrakcja tresci.
 */
final class Article {

	/**
	 * Prog, ponizej ktorego tresc z kanalu uznajemy za zajawke: 1200 znakow.
	 *
	 * Zmierzone na czterech domyslnych kanalach: pelne teksty maja 3–15 tys.
	 * znakow, zajawki 150–400. Prog stoi z zapasem po obu stronach, bo
	 * pomylka w te strone kosztuje jedno zadanie HTTP, a w druga — artykul
	 * napisany przez model z samej zajawki.
	 */
	public const MIN_FEED_CHARS = 1200;

	/**
	 * Prog, ponizej ktorego pozycja idzie do `skipped`: 500 znakow.
	 *
	 * Tyle musi zostac PO ekstrakcji i oczyszczeniu, zeby w ogole bylo
	 * z czego pisac artykul. Ponizej tej granicy konczy strona renderowana
	 * JavaScriptem, strona za Cloudflare i strona bledu 404 podana z kodem 200.
	 */
	public const MIN_TEXT_CHARS = 500;

	/**
	 * Znaczniki wycinane z drzewa PRZED szukaniem tresci.
	 *
	 * To nie jest lista „brzydkich" elementow, tylko lista tego, co powtarza
	 * sie na KAZDEJ podstronie serwisu: menu, naglowek, stopka, pasek boczny,
	 * formularz zapisu do newslettera. Zostawione w tresci trafiaja do promptu
	 * (kosztuja tokeny), do odcisku tresci (dwa rozne artykuly z tego samego
	 * serwisu maja wtedy bardzo podobny tekst) i do gotowego artykulu.
	 */
	public const STRIP_TAGS = array(
		'script',
		'style',
		'noscript',
		'nav',
		'header',
		'footer',
		'aside',
		'form',
		'iframe',
		'svg',
		'button',
		'select',
		'textarea',
		'template',
	);

	/** Elementy, ktore moga byc pojemnikiem na tresc artykulu. */
	public const BLOCK_TAGS = array( 'article', 'main', 'section', 'div', 'td' );

	// -----------------------------------------------------------------------
	// Decyzja: czy w ogole siegac do sieci
	// -----------------------------------------------------------------------

	/**
	 * Czy tresc z kanalu wymaga uzupelnienia scrapingiem.
	 *
	 * @param string $content Tresc podana przez kanal (`content:encoded`
	 *                        albo zajawka).
	 *
	 * @return bool
	 */
	public static function needs_scraping( string $content ): bool {
		return self::text_length( $content ) < self::MIN_FEED_CHARS;
	}

	/**
	 * Dlugosc GOLEGO tekstu, bez znacznikow i encji.
	 *
	 * Liczenie `strlen()` na HTML-u klamie w obie strony: `<div class="…">`
	 * dokłada setki znakow, ktorych nikt nie przeczyta, a polska litera zajmuje
	 * dwa bajty. Stad `mb_strlen` po odarciu ze znacznikow — dokladnie ta sama
	 * definicja golego tekstu, ktorej uzywa odcisk tresci.
	 *
	 * @param string $tresc HTML albo goly tekst.
	 *
	 * @return int Liczba znakow.
	 */
	public static function text_length( string $tresc ): int {
		$tekst = Dedup::normalize_text( $tresc );

		if ( '' === $tekst ) {
			return 0;
		}

		return function_exists( 'mb_strlen' ) ? mb_strlen( $tekst, 'UTF-8' ) : strlen( $tekst );
	}

	// -----------------------------------------------------------------------
	// Sciaganie strony
	// -----------------------------------------------------------------------

	/**
	 * Pobiera strone artykulu.
	 *
	 * Cala robota sieciowa siedzi w `Http::get_article()`: timeout 15 s, sufit
	 * 1 MB, trzy przekierowania, `robots.txt` sprawdzany per host. Tutaj
	 * zostaje tlumaczenie wyniku na jezyk przebiegu — a najwazniejsze w tym
	 * tlumaczeniu jest rozroznienie bledu PRZEJSCIOWEGO (wart ponowienia)
	 * od trwalego (ponawianie go tylko zjada budzet czasu).
	 *
	 * @param string $url Adres artykulu.
	 *
	 * @return array<string,mixed> `ok`, `html`, `error`, `reason`, `retryable`,
	 *                             `truncated`.
	 */
	public static function fetch( string $url ): array {
		$odpowiedz = Http::get_article( $url );

		if ( ! $odpowiedz['ok'] ) {
			return array(
				'ok'        => false,
				'html'      => '',
				'error'     => (string) $odpowiedz['error'],
				'reason'    => (string) $odpowiedz['reason'],
				'retryable' => Http::is_retryable( $odpowiedz ),
				'truncated' => (bool) $odpowiedz['truncated'],
			);
		}

		$html = (string) $odpowiedz['body'];

		/*
		 * Serwer potrafi oddac kod 200 z pusta trescia — najczesciej wtedy, gdy
		 * strona zniknela, a przekierowanie prowadzi na strone glowna. Pusta
		 * odpowiedz nie jest bledem sieci, wiec ponawianie jej nic nie da.
		 */
		if ( '' === trim( $html ) ) {
			return array(
				'ok'        => false,
				'html'      => '',
				'error'     => 'Serwer oddał pustą stronę',
				'reason'    => 'empty',
				'retryable' => false,
				'truncated' => (bool) $odpowiedz['truncated'],
			);
		}

		return array(
			'ok'        => true,
			'html'      => $html,
			'error'     => '',
			'reason'    => '',
			'retryable' => false,
			'truncated' => (bool) $odpowiedz['truncated'],
		);
	}

	// -----------------------------------------------------------------------
	// Ekstrakcja tresci ze strony
	// -----------------------------------------------------------------------

	/**
	 * Wyciaga z pobranej strony tresc artykulu i adres kanoniczny.
	 *
	 * ETAP 3.4. „Prosta ekstrakcja" jest tu okresleniem zakresu, nie jakosci:
	 * wycinamy to, co powtarza sie na kazdej podstronie, a potem bierzemy blok
	 * z najwieksza iloscia tekstu W AKAPITACH. Miara na akapitach jest jedyna,
	 * ktora dziala na zagniezdzonych `<div>`-ach — sam tekst potomkow zawsze
	 * wygrywalby `<body>`, bo rodzic zawiera wszystko, co maja dzieci.
	 *
	 * Trzy pulapki, ktore ta metoda omija:
	 *
	 *   1. `loadHTML()` bez deklaracji kodowania zaklada ISO-8859-1 i robi
	 *      z polskich liter krzaki. Stad `<meta charset>` doklejany z przodu
	 *      — zalecany przez plan zamiast `mb_convert_encoding(…, 'HTML-ENTITIES')`,
	 *      ktore od PHP 8.2 jest przestarzale.
	 *   2. Prawdziwy HTML nie jest poprawnym XML-em, a libxml zglasza kazde
	 *      odstepstwo. Bez `libxml_use_internal_errors()` kazda strona sypie
	 *      ostrzezeniami do logu klienta. Stan biblioteki jest PRZYWRACANY —
	 *      wtyczka nie ma prawa zmieniac globalnych ustawien na stale.
	 *   3. `saveHTML()` oddaje polskie litery jako encje. Rozkodowanie ich tu,
	 *      PRZED `wp_kses_post()` (etap 3.5), jest bezpieczne: cokolwiek
	 *      rozkodowanie odsloni, oczyszczanie i tak jeszcze zobaczy.
	 *
	 * @param string $html     Tresc strony.
	 * @param string $base_url Adres, spod ktorego przyszla — do rozwiniecia
	 *                         wzglednego `canonical`.
	 *
	 * @return array<string,mixed> `ok`, `html`, `canonical`, `error`.
	 */
	public static function extract( string $html, string $base_url = '' ): array {
		if ( '' === trim( $html ) ) {
			return self::extract_result( false, '', '', 'Pusta strona' );
		}

		$poprzedni = libxml_use_internal_errors( true );

		try {
			$dokument = new \DOMDocument();

			// Deklaracja kodowania MUSI stac przed trescia, inaczej libxml
			// zgaduje — i zgaduje ISO-8859-1.
			$zaladowany = $dokument->loadHTML(
				'<meta http-equiv="Content-Type" content="text/html; charset=utf-8">' . $html,
				LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET
			);

			if ( ! $zaladowany ) {
				return self::extract_result( false, '', '', 'Nie udało się sparsować strony' );
			}

			$xpath     = new \DOMXPath( $dokument );
			$canonical = self::canonical( $xpath, $base_url );

			self::strip_nodes( $xpath );

			$blok = self::best_block( $xpath );

			if ( null === $blok ) {
				return self::extract_result( false, '', $canonical, 'Nie znaleziono treści na stronie' );
			}

			return self::extract_result( true, self::inner_html( $blok ), $canonical, '' );
		} catch ( \Throwable $e ) {
			return self::extract_result( false, '', '', 'Błąd ekstrakcji: ' . $e->getMessage() );
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $poprzedni );
		}
	}

	/**
	 * Adres kanoniczny ze strony.
	 *
	 * Wazny dlatego, ze ten sam artykul bywa podawany pod adresem z kanalu
	 * i pod wlasnym adresem serwisu. `canonical` pozwala rozpoznac, ze to
	 * jeden zasob, zanim model dostanie go po raz drugi.
	 *
	 * @param \DOMXPath $xpath    Zapytania po drzewie.
	 * @param string    $base_url Adres, spod ktorego przyszla strona.
	 *
	 * @return string Adres bezwzgledny http(s) albo pusty lancuch.
	 */
	private static function canonical( \DOMXPath $xpath, string $base_url ): string {
		$wezly = $xpath->query( '//link[translate(@rel,"CANOICL","canoicl")="canonical"][@href]' );

		if ( false === $wezly || 0 === $wezly->length ) {
			return '';
		}

		$href = trim( (string) $wezly->item( 0 )->getAttribute( 'href' ) );

		if ( '' === $href ) {
			return '';
		}

		// Adres wzgledny rozwijamy tylko wtedy, gdy wiemy, wzgledem czego.
		if ( 0 === strpos( $href, '//' ) ) {
			$schemat = (string) wp_parse_url( $base_url, PHP_URL_SCHEME );
			$href    = ( '' === $schemat ? 'https' : $schemat ) . ':' . $href;
		} elseif ( 0 === strpos( $href, '/' ) ) {
			$czesci = wp_parse_url( $base_url );

			if ( ! is_array( $czesci ) || empty( $czesci['scheme'] ) || empty( $czesci['host'] ) ) {
				return '';
			}

			$href = $czesci['scheme'] . '://' . $czesci['host'] . $href;
		}

		return ( '' === Dedup::normalize_url( $href ) ) ? '' : $href;
	}

	/**
	 * Wycina z drzewa elementy powtarzajace sie na kazdej podstronie.
	 *
	 * @param \DOMXPath $xpath Zapytania po drzewie.
	 *
	 * @return void
	 */
	private static function strip_nodes( \DOMXPath $xpath ): void {
		$zapytanie = '//' . implode( '|//', self::STRIP_TAGS ) . '|//comment()';
		$wezly     = $xpath->query( $zapytanie );

		if ( false === $wezly ) {
			return;
		}

		// Iteracja po zywej liscie wezlow gubi co drugi element, gdy usuwamy
		// w trakcie — stad kopia do zwyklej tablicy.
		$do_usuniecia = array();

		foreach ( $wezly as $wezel ) {
			$do_usuniecia[] = $wezel;
		}

		foreach ( $do_usuniecia as $wezel ) {
			if ( $wezel->parentNode ) {
				$wezel->parentNode->removeChild( $wezel );
			}
		}
	}

	/**
	 * Blok z najwieksza iloscia tekstu w akapitach.
	 *
	 * @param \DOMXPath $xpath Zapytania po drzewie.
	 *
	 * @return \DOMNode|null
	 */
	private static function best_block( \DOMXPath $xpath ) {
		$kandydaci = $xpath->query( '//' . implode( '|//', self::BLOCK_TAGS ) );
		$najlepszy = null;
		$najwiecej = 0;

		if ( false !== $kandydaci ) {
			foreach ( $kandydaci as $wezel ) {
				$punkty = self::paragraph_length( $xpath, $wezel );

				// Ostry warunek `>` daje pierwszy z najlepszych, a przy
				// zagniezdzeniu pierwszy jest zawsze ten szerszy — czyli ten,
				// ktory zawiera CALY artykul, nie jego pierwsza polowe.
				if ( $punkty > $najwiecej ) {
					$najwiecej = $punkty;
					$najlepszy = $wezel;
				}
			}
		}

		if ( null !== $najlepszy ) {
			return $najlepszy;
		}

		/*
		 * Zaden blok nie ma akapitow — strona moze byc zbudowana na samych
		 * `<br>`. Wtedy bierzemy `<body>` w calosci i niech o wyniku zdecyduje
		 * prog dlugosci z etapu 3.5.
		 */
		$body = $xpath->query( '//body' );

		return ( false !== $body && $body->length > 0 ) ? $body->item( 0 ) : null;
	}

	/**
	 * Ile tekstu jest w akapitach WEWNATRZ tego wezla.
	 *
	 * @param \DOMXPath $xpath Zapytania po drzewie.
	 * @param \DOMNode  $wezel Kandydat.
	 *
	 * @return int
	 */
	private static function paragraph_length( \DOMXPath $xpath, \DOMNode $wezel ): int {
		$akapity = $xpath->query( './/p|.//li', $wezel );

		if ( false === $akapity ) {
			return 0;
		}

		$suma = 0;

		foreach ( $akapity as $akapit ) {
			$suma += self::text_length( (string) $akapit->textContent );
		}

		return $suma;
	}

	/**
	 * Zawartosc wezla jako HTML.
	 *
	 * @param \DOMNode $wezel Wezel.
	 *
	 * @return string
	 */
	private static function inner_html( \DOMNode $wezel ): string {
		$dokument = $wezel->ownerDocument;

		if ( null === $dokument ) {
			return '';
		}

		$html = '';

		foreach ( $wezel->childNodes as $dziecko ) {
			$kawalek = $dokument->saveHTML( $dziecko );

			if ( is_string( $kawalek ) ) {
				$html .= $kawalek;
			}
		}

		// `saveHTML()` oddaje polskie litery jako encje liczbowe. Rozkodowanie
		// jest tu bezpieczne, bo `wp_kses_post()` (etap 3.5) idzie PO nim.
		return trim( html_entity_decode( $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	/**
	 * Staly ksztalt wyniku ekstrakcji.
	 *
	 * @param bool   $ok        Powodzenie.
	 * @param string $html      Tresc.
	 * @param string $canonical Adres kanoniczny.
	 * @param string $error     Komunikat.
	 *
	 * @return array<string,mixed>
	 */
	private static function extract_result( bool $ok, string $html, string $canonical, string $error ): array {
		return array(
			'ok'        => $ok,
			'html'      => $html,
			'canonical' => $canonical,
			'error'     => $error,
		);
	}
}
