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
}
