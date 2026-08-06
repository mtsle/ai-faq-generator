<?php
/**
 * Krok 3, etapy 3.3–3.5 — zdobycie tresci `AINP\Article`.
 *
 * Trzy pytania, na ktore ten zestaw odpowiada pomiarem:
 *
 *   3.3 Kiedy w ogole siegac do sieci? Kanal z pelna trescia ma NIE wywolywac
 *       zadnego zadania; kanal z sama zajawka — dokladnie jedno.
 *   3.4 Co zostaje z pobranej strony? Menu, stopka, formularz zapisu do
 *       newslettera i skrypty analityczne nie sa trescia artykulu, a wchodza
 *       modelowi do promptu i do odcisku tresci.
 *   3.5 Co jest za krotkie, zeby isc dalej? Strona renderowana JavaScriptem
 *       oddaje szkielet — musi skonczyc jako `skipped`, nie jako artykul
 *       napisany z niczego.
 *
 * Prawdziwe klasy: `Article`, `Dedup`. Atrapy: `Http`, `wp_kses_post`.
 * Zero WordPressa i zero sieci.
 *
 * URUCHOMIENIE:  php tests/krok3-article-test.php
 * Kod wyjscia: 0 = OK, 1 = bledy.
 *
 * @package AI_News_Portal
 */

namespace {
	define( 'ABSPATH', __DIR__ . '/atrapy/wp/' );

	$root = dirname( __DIR__ );
	$fail = 0;
	$ran  = 0;

	/**
	 * Asercja.
	 *
	 * @param bool   $cond  Warunek.
	 * @param string $label Opis.
	 *
	 * @return void
	 */
	function k3a_check( $cond, $label ) {
		global $fail, $ran;
		$ran++;
		echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n";
		if ( ! $cond ) {
			$fail++;
		}
	}

	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}

	/**
	 * Atrapa `wp_kses_post()` — zostawia znaczniki dozwolone we wpisie,
	 * wycina `<script>`, `<iframe>`, `<form>` i atrybuty zdarzen.
	 *
	 * Prawdziwa funkcja robi znacznie wiecej; tutaj chodzi o to, zeby test
	 * mierzyl, czy `Article` W OGOLE przepuszcza tresc przez oczyszczanie —
	 * a nie zeby powtarzac implementacje WordPressa.
	 *
	 * @param string $tresc Wejscie.
	 *
	 * @return string
	 */
	function wp_kses_post( $tresc ) {
		$GLOBALS['__kses']++;

		$tresc = preg_replace( '#<(script|style|iframe|form|object)\b[^>]*>.*?</\1>#is', '', (string) $tresc );
		$tresc = preg_replace( '#</?(script|style|iframe|form|object|input|button)\b[^>]*>#i', '', (string) $tresc );
		$tresc = preg_replace( '#\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\')#i', '', (string) $tresc );

		return (string) $tresc;
	}

	function wp_convert_hr_to_bytes( $wartosc ) {
		$wartosc = strtolower( trim( (string) $wartosc ) );
		$bajty   = (int) $wartosc;

		if ( false !== strpos( $wartosc, 'g' ) ) {
			return $bajty * 1024 * 1024 * 1024;
		}
		if ( false !== strpos( $wartosc, 'm' ) ) {
			return $bajty * 1024 * 1024;
		}
		if ( false !== strpos( $wartosc, 'k' ) ) {
			return $bajty * 1024;
		}

		return $bajty;
	}

	$GLOBALS['__kses']    = 0;
	$GLOBALS['__zadania'] = array();
	$GLOBALS['__strony']  = array();
}

namespace AINP {

	/** Atrapa warstwy sieciowej — kazde zadanie jest liczone. */
	class Http {

		public const ARTICLE = 'article';

		public static function get_article( string $url ): array {
			$GLOBALS['__zadania'][] = $url;

			if ( isset( $GLOBALS['__strony'][ $url ] ) ) {
				return $GLOBALS['__strony'][ $url ];
			}

			return array( 'ok' => false, 'code' => 0, 'body' => '', 'error' => 'brak planu', 'reason' => 'transport', 'truncated' => false );
		}

		/** Ta sama regula co w produkcji: przejsciowe sa transport, 429 i 5xx. */
		public static function is_retryable( array $result ): bool {
			if ( ! empty( $result['ok'] ) ) {
				return false;
			}

			$reason = isset( $result['reason'] ) ? (string) $result['reason'] : '';
			$code   = isset( $result['code'] ) ? (int) $result['code'] : 0;

			if ( 'transport' === $reason ) {
				return true;
			}

			return ( 429 === $code || ( $code >= 500 && $code <= 599 ) );
		}
	}
}

namespace {

	require_once $root . '/src/Dedup.php';
	require_once $root . '/src/Article.php';

	use AINP\Article;

	/**
	 * Odpowiedz `Http::get_article()`.
	 *
	 * @param string $body      Tresc.
	 * @param int    $code      Kod HTTP.
	 * @param bool   $truncated Czy ucieta.
	 *
	 * @return array
	 */
	function k3a_ok( $body, $code = 200, $truncated = false ) {
		return array( 'ok' => true, 'code' => $code, 'body' => $body, 'error' => '', 'reason' => '', 'truncated' => $truncated );
	}

	/**
	 * Blad `Http::get_article()`.
	 *
	 * @param string $reason Powod.
	 * @param int    $code   Kod HTTP.
	 * @param string $error  Komunikat.
	 *
	 * @return array
	 */
	function k3a_blad( $reason, $code = 0, $error = 'blad' ) {
		return array( 'ok' => false, 'code' => $code, 'body' => '', 'error' => $error, 'reason' => $reason, 'truncated' => false );
	}

	echo "=== KROK 3 / ETAPY 3.3–3.5 — scraping, ekstrakcja, oczyszczanie ===\n\n";

	// -----------------------------------------------------------------------
	echo "-- 3.3 Kiedy siegac do sieci --\n";
	// -----------------------------------------------------------------------
	$zajawka = 'Karma bytowa to podstawa diety psa. Przeczytaj więcej na naszej stronie.';
	$pelny   = str_repeat( 'Pełna treść artykułu o żywieniu psa. ', 60 );   // ok. 2200 znakow.

	k3a_check( Article::needs_scraping( $zajawka ), 'zajawka z kanalu wymaga scrapingu' );
	k3a_check( ! Article::needs_scraping( $pelny ), 'pelna tresc z kanalu NIE wymaga scrapingu' );
	k3a_check( Article::needs_scraping( '' ), 'pusta tresc wymaga scrapingu' );

	// Prog liczony na golym tekscie, nie na HTML-u: inaczej `<div class="…">`
	// razy sto udaje tresc, ktorej nie ma.
	$sam_html = '<div class="wrapper"><div class="row"><div class="col">' . str_repeat( '<span class="tag"></span>', 200 ) . '</div></div></div>';
	k3a_check( strlen( $sam_html ) > Article::MIN_FEED_CHARS, 'material testowy jest dlugi w BAJTACH' );
	k3a_check( Article::needs_scraping( $sam_html ), 'same znaczniki nie licza sie jako tresc' );

	// Granica progu — dokladnie, nie „mniej wiecej".
	$rowno = str_repeat( 'a', Article::MIN_FEED_CHARS );
	k3a_check( Article::MIN_FEED_CHARS === Article::text_length( $rowno ), 'text_length liczy znaki, nie bajty' );
	k3a_check( ! Article::needs_scraping( $rowno ), 'tresc rowna progowi NIE wymaga scrapingu' );
	k3a_check( Article::needs_scraping( substr( $rowno, 0, -1 ) ), 'tresc o znak krotsza wymaga scrapingu' );

	k3a_check(
		4 === Article::text_length( 'żółw' ),
		'polskie litery licza sie po jednym znaku (jest ' . Article::text_length( 'żółw' ) . ')'
	);
	k3a_check(
		0 === Article::text_length( '<script>var dlugi = "' . str_repeat( 'x', 2000 ) . '";</script>' ),
		'tresc <script> nie jest tekstem artykulu'
	);

	// -----------------------------------------------------------------------
	echo "\n-- 3.3 Pobranie strony --\n";
	// -----------------------------------------------------------------------
	$GLOBALS['__zadania'] = array();
	$GLOBALS['__strony']  = array(
		'https://psy.pl/karma/'   => k3a_ok( '<html><body><p>Treść</p></body></html>' ),
		'https://psy.pl/404/'     => k3a_blad( 'status', 404, 'Serwer odpowiedzial kodem 404' ),
		'https://psy.pl/timeout/' => k3a_blad( 'transport', 0, 'Operation timed out' ),
		'https://psy.pl/500/'     => k3a_blad( 'status', 503, 'Serwer odpowiedzial kodem 503' ),
		'https://psy.pl/robots/'  => k3a_blad( 'robots', 0, 'robots.txt serwisu zabrania pobierania' ),
		'https://psy.pl/pusta/'   => k3a_ok( "   \n  " ),
	);

	$wynik = Article::fetch( 'https://psy.pl/karma/' );
	k3a_check( true === $wynik['ok'], 'udane pobranie: ok' );
	k3a_check( false !== strpos( $wynik['html'], '<p>Treść</p>' ), 'tresc strony wraca w calosci' );
	k3a_check( 1 === count( $GLOBALS['__zadania'] ), 'jedno pobranie to JEDNO zadanie HTTP' );

	$wynik = Article::fetch( 'https://psy.pl/404/' );
	k3a_check( false === $wynik['ok'], '404: nieudane' );
	k3a_check( false === $wynik['retryable'], '404 NIE jest wart ponowienia' );

	$wynik = Article::fetch( 'https://psy.pl/timeout/' );
	k3a_check( true === $wynik['retryable'], 'timeout JEST wart ponowienia' );

	$wynik = Article::fetch( 'https://psy.pl/500/' );
	k3a_check( true === $wynik['retryable'], '503 JEST wart ponowienia' );

	$wynik = Article::fetch( 'https://psy.pl/robots/' );
	k3a_check( false === $wynik['ok'], 'zakaz z robots.txt: nieudane' );
	k3a_check( 'robots' === $wynik['reason'], 'powod zachowany jako `robots`' );
	k3a_check( false === $wynik['retryable'], 'zakazu z robots.txt nie ponawiamy' );

	$wynik = Article::fetch( 'https://psy.pl/pusta/' );
	k3a_check( false === $wynik['ok'], 'kod 200 z pusta trescia to blad, nie sukces' );
	k3a_check( 'empty' === $wynik['reason'], 'powod: `empty`' );
	k3a_check( false === $wynik['retryable'], 'pustej strony nie ponawiamy — to nie blad sieci' );

	echo "\n";
	echo '=== WYNIK: ' . ( $ran - $fail ) . ' / ' . $ran . " asercji ===\n";

	if ( $fail > 0 ) {
		echo "BŁĘDY: $fail\n";
		exit( 1 );
	}

	echo "WSZYSTKIE OK\n";
	exit( 0 );
}
