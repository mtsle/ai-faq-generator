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

	// Zajawki bywaja dlugie — 400 znakow to typowy „lead" z serwisu. Prog musi
	// stac WYRAZNIE wyzej, inaczej lead przechodzi jako pelny artykul.
	$dlugi_lead = str_repeat( 'Zajawka artykułu z serwisu. ', 15 );   // ok. 420 znakow.
	k3a_check(
		Article::text_length( $dlugi_lead ) > 400 && Article::text_length( $dlugi_lead ) < 600,
		'material testowy ma typowa dlugosc leadu (' . Article::text_length( $dlugi_lead ) . ' znakow)'
	);
	k3a_check( Article::needs_scraping( $dlugi_lead ), 'lead na 420 znakow NADAL wymaga scrapingu' );
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

	// -----------------------------------------------------------------------
	echo "\n-- 3.4 Ekstrakcja: co zostaje ze strony --\n";
	// -----------------------------------------------------------------------
	$strona = '<!DOCTYPE html><html lang="pl"><head>'
		. '<title>Karma bytowa — psy.pl</title>'
		. '<link rel="canonical" href="https://psy.pl/karma-bytowa/">'
		. '<script>var ga = "analityka konkurs zapisy";</script>'
		. '<style>.menu{color:red}</style>'
		. '</head><body>'
		. '<header><h1>psy.pl</h1><p>Portal o psach od 2001 roku, największy w Polsce serwis</p></header>'
		. '<nav><ul><li>Strona główna</li><li>Żywienie</li><li>Zdrowie</li><li>Kontakt z redakcją</li></ul></nav>'
		. '<div id="wrapper"><div class="content"><article>'
		. '<h1>Karma bytowa dla psa</h1>'
		. '<p>Karma bytowa to podstawa diety dorosłego psa. Zażółć gęślą jaźń.</p>'
		. '<p>Drugi akapit mówi o białku, tłuszczach i węglowodanach w karmie suchej.</p>'
		. '<!-- komentarz redakcyjny: sprawdzić źródło -->'
		. '</article></div></div>'
		. '<aside><p>Polecane: kot rasy brytyjskiej, konkurs z nagrodami, zapisy na webinar</p></aside>'
		. '<form><input name="email"><button>Zapisz się do newslettera</button></form>'
		. '<footer><p>Copyright 2026 psy.pl. Wszelkie prawa zastrzeżone. Regulamin i polityka prywatności.</p></footer>'
		. '</body></html>';

	$wynik = Article::extract( $strona, 'https://psy.pl/feed-link/' );

	k3a_check( true === $wynik['ok'], 'ekstrakcja: ok' );
	k3a_check( false !== strpos( $wynik['html'], 'Karma bytowa to podstawa diety' ), 'tresc artykulu zostala' );
	k3a_check( false !== strpos( $wynik['html'], 'Drugi akapit' ), 'drugi akapit tez' );
	k3a_check( false !== strpos( $wynik['html'], 'Zażółć gęślą jaźń' ), 'polskie znaki bez krzakow i bez encji' );
	k3a_check( false === strpos( $wynik['html'], '&#' ), 'w wyniku nie ma encji liczbowych' );

	foreach (
		array(
			'Portal o psach'      => 'naglowek serwisu (header)',
			'Strona główna'       => 'menu (nav)',
			'kot rasy'            => 'pasek boczny (aside)',
			'newsletter'          => 'formularz (form)',
			'Wszelkie prawa'      => 'stopka (footer)',
			'analityka'           => 'skrypt (script)',
			'color:red'           => 'styl (style)',
			'komentarz redakcyjny' => 'komentarz HTML',
		) as $tekst => $opis
	) {
		k3a_check( false === strpos( $wynik['html'], $tekst ), 'wyciete: ' . $opis );
	}

	// Slowa wykluczajace z paska bocznego („kot", „konkurs", „zapisy") nie moga
	// przeciec do tresci — filtr juz przepuscil ta pozycje, wiec to ostatnia
	// bramka przed modelem i przed odciskiem tresci.
	k3a_check( false === strpos( $wynik['html'], 'konkurs' ), 'slowo z paska bocznego nie truje tresci' );

	// -----------------------------------------------------------------------
	echo "\n-- 3.4 Smieci WEWNATRZ bloku z trescia --\n";
	// -----------------------------------------------------------------------
	/*
	 * Poprzedni przypadek mial menu i stopke OBOK artykulu, wiec przechodzil
	 * takze wtedy, gdy lista wycinanych znacznikow byla pusta — wygrywal blok,
	 * ktory ich nie zawieral. Prawdziwe szablony wstawiaja te elementy DO
	 * srodka wpisu i dopiero to sprawdza, czy wycinanie w ogole dziala.
	 */
	$w_srodku = '<html><body><div class="entry">'
		. '<p>' . str_repeat( 'Właściwa treść artykułu o żywieniu psa. ', 25 ) . '</p>'
		. '<nav><p>Poprzedni wpis: kot brytyjski</p></nav>'
		. '<aside><p>Zapisy na webinar i konkurs z nagrodami</p></aside>'
		. '<form><p>Zapisz się do newslettera</p></form>'
		. '<footer><p>Autor: redakcja serwisu</p></footer>'
		. '<script>var track = "analityka wewnętrzna";</script>'
		. '<style>.entry{margin:0}</style>'
		. '<iframe src="https://reklama.example/x"></iframe>'
		. '<noscript><p>Włącz JavaScript</p></noscript>'
		. '</div></body></html>';

	$wynik = Article::extract( $w_srodku, 'https://psy.pl/a/' );

	k3a_check( false !== strpos( $wynik['html'], 'Właściwa treść' ), 'tresc wpisu zostaje' );

	foreach (
		array(
			'Poprzedni wpis'      => 'nawigacja wewnatrz wpisu',
			'Zapisy na webinar'   => 'ramka promocyjna wewnatrz wpisu',
			'newslettera'         => 'formularz wewnatrz wpisu',
			'Autor: redakcja'     => 'stopka wpisu',
			'analityka'           => 'skrypt wewnatrz wpisu',
			'margin:0'            => 'styl wewnatrz wpisu',
			'reklama.example'     => 'ramka iframe',
			'Włącz JavaScript'    => 'noscript',
		) as $tekst => $opis
	) {
		k3a_check( false === strpos( $wynik['html'], $tekst ), 'wyciete ze SRODKA wpisu: ' . $opis );
	}

	// -----------------------------------------------------------------------
	echo "\n-- 3.4 Encje i znaki specjalne --\n";
	// -----------------------------------------------------------------------
	$encje = '<html><body><div><p>Karma &amp; woda, temperatura &lt;20&deg;C, cena&nbsp;99 zł</p>'
		. '<p>Zapis kodu: &lt;script&gt;alert(1)&lt;/script&gt;</p></div></body></html>';

	$wynik = Article::extract( $encje, 'https://psy.pl/a/' );

	k3a_check( false !== strpos( $wynik['html'], '&amp;' ), 'znak & zostaje ZAPISANY jako encja, nie goly' );
	k3a_check( false === strpos( $wynik['html'], '<script>alert' ), 'zapisany jako tekst <script> NIE staje sie znacznikiem' );
	k3a_check( false !== strpos( $wynik['html'], '99' ), 'twarda spacja z &nbsp; nie gubi tresci' );

	// -----------------------------------------------------------------------
	echo "\n-- 3.4 Wybor bloku przy zagniezdzeniu --\n";
	// -----------------------------------------------------------------------
	$zagniezdzone = '<html><body><div id="zewnetrzny">'
		. '<div id="pierwsza-polowa"><p>' . str_repeat( 'Pierwsza połowa artykułu. ', 20 ) . '</p></div>'
		. '<div id="druga-polowa"><p>' . str_repeat( 'Druga połowa artykułu. ', 20 ) . '</p></div>'
		. '</div></body></html>';

	$wynik = Article::extract( $zagniezdzone, 'https://psy.pl/a/' );

	k3a_check(
		false !== strpos( $wynik['html'], 'Pierwsza połowa' ) && false !== strpos( $wynik['html'], 'Druga połowa' ),
		'wygrywa blok zawierajacy CALY artykul, nie jego polowe'
	);

	$krotki_dluzszy = '<html><body>'
		. '<div id="krotki"><p>Krótka notka redakcyjna.</p></div>'
		. '<div id="dlugi"><p>' . str_repeat( 'Właściwa treść artykułu o żywieniu psa. ', 30 ) . '</p></div>'
		. '</body></html>';

	$wynik = Article::extract( $krotki_dluzszy, 'https://psy.pl/a/' );

	k3a_check( false !== strpos( $wynik['html'], 'Właściwa treść' ), 'wygrywa blok z wieksza iloscia tekstu' );
	k3a_check( false === strpos( $wynik['html'], 'Krótka notka' ), 'krotszy blok obok NIE wchodzi do wyniku' );

	/*
	 * Remis: opakowanie i wpis maja DOKLADNIE tyle samo tekstu w akapitach.
	 * Wygrac ma ten szerszy, czyli pierwszy w dokumencie — razem z nim zostaje
	 * to, co w wpisie nie jest akapitem (obrazek, lista, ramka z cytatem).
	 * Odwrocenie warunku na `>=` oddaje zwyciestwo najglebiej zagniezdzonemu.
	 */
	$remis = '<html><body><div id="opakowanie"><div id="wpis">'
		. '<figure>ZDJĘCIE GŁÓWNE</figure>'
		. '<p>' . str_repeat( 'Jeden akapit w dwóch pojemnikach. ', 20 ) . '</p>'
		. '</div></div></body></html>';

	$wynik = Article::extract( $remis, 'https://psy.pl/a/' );

	k3a_check( false !== strpos( $wynik['html'], 'id="wpis"' ), 'przy remisie wygrywa blok SZERSZY (pierwszy w dokumencie)' );
	k3a_check( false !== strpos( $wynik['html'], 'ZDJĘCIE GŁÓWNE' ), 'to, co nie jest akapitem, zostaje razem z blokiem' );

	// Strona na samych `<br>`, bez ani jednego akapitu — zamiast pustki
	// bierzemy cale `<body>` i niech zdecyduje prog dlugosci.
	$bez_akapitow = '<html><body><div>Tekst<br>bez<br>akapitów, ale całkiem długi jak na notkę.</div></body></html>';
	$wynik        = Article::extract( $bez_akapitow, 'https://psy.pl/a/' );

	k3a_check( true === $wynik['ok'], 'strona bez akapitow: ekstrakcja sie nie wywraca' );
	k3a_check( false !== strpos( $wynik['html'], 'bez<br>akapitów' ), 'wynikiem jest cale body' );

	// -----------------------------------------------------------------------
	echo "\n-- 3.4 Adres kanoniczny --\n";
	// -----------------------------------------------------------------------
	$wynik = Article::extract( $strona, 'https://psy.pl/feed-link/' );
	k3a_check( 'https://psy.pl/karma-bytowa/' === $wynik['canonical'], 'canonical bezwzgledny' );

	$wzgledny = '<html><head><link rel="canonical" href="/artykul/karma/"></head><body><p>x</p></body></html>';
	$wynik    = Article::extract( $wzgledny, 'https://psy.pl/skad-przyszlo/' );
	k3a_check( 'https://psy.pl/artykul/karma/' === $wynik['canonical'], 'canonical wzgledny rozwiniety wzgledem adresu strony' );

	$bez_schematu = '<html><head><link rel="canonical" href="//psy.pl/artykul/"></head><body><p>x</p></body></html>';
	$wynik        = Article::extract( $bez_schematu, 'https://psy.pl/a/' );
	k3a_check( 'https://psy.pl/artykul/' === $wynik['canonical'], 'canonical bez schematu dostaje schemat strony' );

	$wielkie = '<html><head><link REL="Canonical" HREF="https://psy.pl/duze/"></head><body><p>x</p></body></html>';
	$wynik   = Article::extract( $wielkie, 'https://psy.pl/a/' );
	k3a_check( 'https://psy.pl/duze/' === $wynik['canonical'], 'rel wielkimi literami tez jest canonical' );

	$smiec = '<html><head><link rel="canonical" href="javascript:alert(1)"></head><body><p>x</p></body></html>';
	$wynik = Article::extract( $smiec, 'https://psy.pl/a/' );
	k3a_check( '' === $wynik['canonical'], 'canonical, ktory nie jest adresem http(s), jest odrzucany' );

	// Schemat inny niz http(s) MA hosta, wiec sama bramka zgodnosci hostow go
	// nie zatrzyma — potrzebne sa obie.
	$ftp   = '<html><head><link rel="canonical" href="ftp://psy.pl/artykul/"></head><body><p>x</p></body></html>';
	$wynik = Article::extract( $ftp, 'https://psy.pl/artykul/' );
	k3a_check( '' === $wynik['canonical'], 'canonical po ftp:// odrzucony mimo zgodnego hosta' );

	$brak  = '<html><head></head><body><p>x</p></body></html>';
	$wynik = Article::extract( $brak, 'https://psy.pl/a/' );
	k3a_check( '' === $wynik['canonical'], 'brak canonical to pusty lancuch, nie blad' );

	// --- Bramki z audytu Kroku 3 (P1, P2) ---
	$obcy  = '<html><head><link rel="canonical" href="https://sklep-z-karma.example/promocja/"></head><body><p>x</p></body></html>';
	$wynik = Article::extract( $obcy, 'https://psy.pl/artykul/' );
	k3a_check( '' === $wynik['canonical'], 'canonical na OBCEJ domenie odrzucony (P2)' );

	$korzen = '<html><head><link rel="canonical" href="https://psy.pl/"></head><body><p>x</p></body></html>';
	$wynik  = Article::extract( $korzen, 'https://psy.pl/artykul/' );
	k3a_check( '' === $wynik['canonical'], 'canonical na STRONE GLOWNA odrzucony (P1)' );

	$korzen_bez = '<html><head><link rel="canonical" href="https://psy.pl"></head><body><p>x</p></body></html>';
	$wynik      = Article::extract( $korzen_bez, 'https://psy.pl/artykul/' );
	k3a_check( '' === $wynik['canonical'], 'canonical na hosta bez sciezki tez odrzucony' );

	$wzgledny_korzen = '<html><head><link rel="canonical" href="/"></head><body><p>x</p></body></html>';
	$wynik           = Article::extract( $wzgledny_korzen, 'https://psy.pl/artykul/' );
	k3a_check( '' === $wynik['canonical'], 'wzgledny „/" po rozwinieciu tez jest korzeniem' );

	$poddomena = '<html><head><link rel="canonical" href="https://www.psy.pl/artykul/"></head><body><p>x</p></body></html>';
	$wynik     = Article::extract( $poddomena, 'https://psy.pl/artykul/' );
	k3a_check( '' === $wynik['canonical'], 'inna poddomena to inny host — odrzucony' );

	$wlasny = '<html><head><link rel="canonical" href="https://psy.pl/wlasciwy-adres/"></head><body><p>x</p></body></html>';
	$wynik  = Article::extract( $wlasny, 'https://psy.pl/artykul/' );
	k3a_check( 'https://psy.pl/wlasciwy-adres/' === $wynik['canonical'], 'canonical z tego samego hosta i z sciezka PRZECHODZI' );

	$wielkosc = '<html><head><link rel="canonical" href="https://PSY.pl/artykul-2/"></head><body><p>x</p></body></html>';
	$wynik    = Article::extract( $wielkosc, 'https://psy.pl/artykul/' );
	k3a_check( '' !== $wynik['canonical'], 'host wielkimi literami to nadal ten sam host' );

	// -----------------------------------------------------------------------
	echo "\n-- Audyt D-7: boksy rozpoznawane po klasie --\n";
	// -----------------------------------------------------------------------
	$boksy = '<html><body><div class="entry-content">'
		. '<p>' . str_repeat( 'Właściwa treść artykułu o psach. ', 25 ) . '</p>'
		. '<div class="post-rating"><p>Rate this post</p></div>'
		. '<div class="related-posts"><p>Zobacz też: kot brytyjski, kot perski</p></div>'
		. '<div class="newsletter-box"><p>Zapisz się do newslettera i odbierz rabat</p></div>'
		. '<div class="social-share"><p>Udostępnij na Facebooku</p></div>'
		. '<div id="comments"><p>Komentarze czytelników</p></div>'
		. '<div class="widget-area"><p>Popularne wpisy</p></div>'
		. '</div></body></html>';

	$wynik = Article::extract( $boksy, 'https://psy.pl/a/' );

	k3a_check( false !== strpos( $wynik['html'], 'Właściwa treść' ), 'tresc wpisu zostaje mimo odsiewu boksow' );

	foreach (
		array(
			'Rate this post'   => 'ramka oceny (post-rating)',
			'kot brytyjski'    => 'boks powiazanych wpisow (related-posts)',
			'newslettera'      => 'zachęta do newslettera (newsletter-box)',
			'Facebooku'        => 'przyciski udostepniania (social-share)',
			'Komentarze'       => 'komentarze (id=comments)',
			'Popularne wpisy'  => 'widget (widget-area)',
		) as $tekst => $opis
	) {
		k3a_check( false === strpos( $wynik['html'], $tekst ), 'wyciety: ' . $opis );
	}

	// Znacznik musi byc dlugi i jednoznaczny: XPath 1.0 nie zna granicy slowa,
	// wiec krotki „rate" wycialby element z klasa „corporate-news".
	/*
	 * Blok o pechowej nazwie stoi OBOK wlasciwej tresci, nie zamiast niej —
	 * inaczej siec asekuracyjna oddalaby artykul mimo bledu i test
	 * przepuscilby za krotki znacznik (np. `rate` zamiast `rate-this`).
	 */
	$falszywy = '<html><body><div class="entry">'
		. '<p>' . str_repeat( 'Właściwa treść artykułu o psach. ', 25 ) . '</p>'
		. '<div class="corporate-news"><p>' . str_repeat( 'Treść korporacyjna o psach służbowych. ', 25 ) . '</p></div>'
		. '</div></body></html>';
	$wynik    = Article::extract( $falszywy, 'https://psy.pl/a/' );
	k3a_check( false !== strpos( $wynik['html'], 'Właściwa treść' ), 'wlasciwa tresc zostaje' );
	k3a_check( false !== strpos( $wynik['html'], 'korporacyjna' ), 'klasa „corporate-news" NIE jest brana za boks' );

	// Siec asekuracyjna: gdy odsiew po klasach zjadlby caly artykul, wygrywa
	// wynik BEZ odsiewu.
	$pulapka = '<html><body><div class="post-share-content"><p>'
		. str_repeat( 'Cały artykuł siedzi w pojemniku o pechowej nazwie klasy. ', 25 ) . '</p></div></body></html>';
	$wynik   = Article::extract( $pulapka, 'https://psy.pl/a/' );
	k3a_check( true === $wynik['ok'], 'pechowa nazwa klasy nie kasuje artykulu: ok' );
	k3a_check( false !== strpos( $wynik['html'], 'pechowej nazwie' ), 'siec asekuracyjna oddala tresc mimo trafienia w znacznik' );

	// -----------------------------------------------------------------------
	echo "\n-- Audyt D-3: sufit dlugosci tresci --\n";
	// -----------------------------------------------------------------------
	$krotka = '<p>' . str_repeat( 'Krótki artykuł. ', 20 ) . '</p>';
	k3a_check( $krotka === Article::cap( $krotka ), 'tresc ponizej sufitu wraca NIETKNIETA' );

	$dluga = '<div class="entry">';
	for ( $i = 0; $i < 400; $i++ ) {
		$dluga .= '<p>Akapit numer ' . $i . ' z treścią artykułu o żywieniu psa, powtarzany dla objętości tekstu. </p>';
	}
	$dluga .= '</div>';

	$przyciete = Article::cap( $dluga );

	k3a_check( Article::text_length( $dluga ) > Article::MAX_CONTENT_CHARS, 'material testowy przekracza sufit ' . Article::MAX_CONTENT_CHARS );
	k3a_check( Article::text_length( $przyciete ) <= Article::MAX_CONTENT_CHARS, 'po przycieciu miesci sie w sufinie (' . Article::text_length( $przyciete ) . ')' );
	k3a_check( Article::text_length( $przyciete ) > Article::MIN_TEXT_CHARS, 'po przycieciu nadal jest z czego pisac' );
	k3a_check( false !== strpos( $przyciete, 'Akapit numer 0' ), 'poczatek artykulu zachowany' );
	k3a_check( false === strpos( $przyciete, 'Akapit numer 399' ), 'koniec artykulu odciety' );
	k3a_check(
		substr_count( $przyciete, '<p>' ) === substr_count( $przyciete, '</p>' ),
		'ciecie NIE rozrywa znacznikow (otwarcia = zamkniecia)'
	);

	// Artykul w JEDNYM wielkim wezle tekstowym — ciecie musi wejsc glebiej.
	$jeden = '<div><p>' . str_repeat( 'Jedno zdanie bez akapitów. ', 2000 ) . '</p></div>';
	$c     = Article::cap( $jeden );
	k3a_check( Article::text_length( $c ) <= Article::MAX_CONTENT_CHARS, 'jeden wielki wezel tez daje sie przyciac (' . Article::text_length( $c ) . ')' );
	k3a_check( substr_count( $c, '<p' ) === substr_count( $c, '</p>' ), 'przy cieciu w srodku wezla znaczniki zostaja domkniete' );

	// Ekstrakcja stosuje sufit sama.
	$wielka = '<html><body><article>';
	for ( $i = 0; $i < 400; $i++ ) {
		$wielka .= '<p>Akapit numer ' . $i . ' z treścią artykułu o żywieniu psa, powtarzany dla objętości tekstu. </p>';
	}
	$wielka .= '</article></body></html>';
	$wynik   = Article::extract( $wielka, 'https://psy.pl/a/' );
	k3a_check( Article::text_length( $wynik['html'] ) <= Article::MAX_CONTENT_CHARS, 'extract() oddaje tresc juz przycieta' );

	// -----------------------------------------------------------------------
	echo "\n-- Audyt D-7: declutter() dla tresci z kanalu --\n";
	// -----------------------------------------------------------------------
	$z_kanalu = '<p>' . str_repeat( 'Treść artykułu prosto z kanału RSS. ', 25 ) . '</p>'
		. '<div class="post-ratings"><p>Rate this post</p></div>'
		. '<div class="related"><p>Powiązane: kot syjamski</p></div>';

	$czysty = Article::declutter( $z_kanalu );

	k3a_check( false !== strpos( $czysty, 'prosto z kanału' ), 'tresc z kanalu zostaje w calosci' );
	k3a_check( false === strpos( $czysty, 'Rate this post' ), 'ramka oceny z kanalu wycieta' );
	k3a_check( false === strpos( $czysty, 'kot syjamski' ), 'boks powiazanych z kanalu wyciety' );
	k3a_check( '' === Article::declutter( '' ), 'pusta tresc wraca pusta' );

	$sam_boks = '<div class="related"><p>' . str_repeat( 'Sama ramka i nic poza nia. ', 30 ) . '</p></div>';
	k3a_check(
		Article::text_length( Article::declutter( $sam_boks ) ) > 0,
		'gdy odsiew zjadlby wszystko, tresc z kanalu wraca nietknieta'
	);

	$pusty_href = '<html><head><link rel="canonical" href=""></head><body><p>x</p></body></html>';
	$wynik      = Article::extract( $pusty_href, 'https://psy.pl/a/' );
	k3a_check( '' === $wynik['canonical'], 'pusty href nie udaje adresu' );

	// -----------------------------------------------------------------------
	echo "\n-- 3.4 Polamany HTML i stan libxml --\n";
	// -----------------------------------------------------------------------
	libxml_use_internal_errors( false );

	$polamany = '<html><body><div><p>Treść <b>bez domknięcia<p>Drugi akapit</div></body>';
	$wynik    = Article::extract( $polamany, 'https://psy.pl/a/' );

	k3a_check( true === $wynik['ok'], 'polamany HTML nie wywraca ekstrakcji' );
	k3a_check( false !== strpos( $wynik['html'], 'Drugi akapit' ), 'tresc z polamanego HTML-a odzyskana' );
	k3a_check(
		false === libxml_use_internal_errors(),
		'stan libxml PRZYWROCONY — wtyczka nie zmienia globalnych ustawien na stale'
	);

	k3a_check( false === Article::extract( '', 'https://psy.pl/a/' )['ok'], 'pusta strona: ok = false' );
	k3a_check( false === Article::extract( '   ', 'https://psy.pl/a/' )['ok'], 'same biale znaki: ok = false' );

	// -----------------------------------------------------------------------
	echo "\n-- 3.5 Oczyszczanie tresci --\n";
	// -----------------------------------------------------------------------
	$GLOBALS['__kses'] = 0;

	$brudna = '<p onclick="alert(1)">Treść artykułu</p><script>alert(2)</script><iframe src="//zle.pl"></iframe>';
	$czysta = Article::clean( $brudna );

	k3a_check( 1 === $GLOBALS['__kses'], 'tresc przechodzi przez wp_kses_post DOKLADNIE raz' );
	k3a_check( false !== strpos( $czysta, 'Treść artykułu' ), 'tresc zostaje' );
	k3a_check( false === strpos( $czysta, 'onclick' ), 'atrybut zdarzenia wyciety' );
	k3a_check( false === strpos( $czysta, '<script' ), 'skrypt wyciety' );
	k3a_check( false === strpos( $czysta, '<iframe' ), 'ramka wycieta' );
	k3a_check( false !== strpos( $czysta, '<p' ), 'znaczniki dozwolone we wpisie zostaja' );

	$GLOBALS['__kses'] = 0;
	k3a_check( '' === Article::clean( '   ' ), 'pusta tresc wraca pusta' );
	k3a_check( 0 === $GLOBALS['__kses'], 'pustej tresci nie ma po co oczyszczac' );

	// -----------------------------------------------------------------------
	echo "\n-- 3.5 Prog dlugosci --\n";
	// -----------------------------------------------------------------------
	$dosc  = '<p>' . str_repeat( 'Treść artykułu o psach. ', 30 ) . '</p>';   // ok. 720 znakow.
	$malo  = '<p>Krótka notka o niczym.</p>';
	$rowno = '<p>' . str_repeat( 'a', Article::MIN_TEXT_CHARS ) . '</p>';

	k3a_check( Article::is_long_enough( $dosc ), 'pelna tresc przechodzi prog' );
	k3a_check( ! Article::is_long_enough( $malo ), 'krotka notka NIE przechodzi progu' );

	// Notka na 250 znakow to typowa „wzmianka" — dosc, zeby wygladac jak
	// tresc, za malo, zeby model mial z czego pisac.
	$wzmianka = '<p>' . str_repeat( 'Krótka wzmianka redakcyjna. ', 9 ) . '</p>';
	k3a_check(
		Article::text_length( $wzmianka ) > 200 && Article::text_length( $wzmianka ) < 300,
		'material testowy ma dlugosc wzmianki (' . Article::text_length( $wzmianka ) . ' znakow)'
	);
	k3a_check( ! Article::is_long_enough( $wzmianka ), 'wzmianka na 250 znakow NADAL nie przechodzi progu' );
	k3a_check( Article::is_long_enough( $rowno ), 'tresc rowna progowi przechodzi' );
	k3a_check(
		! Article::is_long_enough( '<p>' . str_repeat( 'a', Article::MIN_TEXT_CHARS - 1 ) . '</p>' ),
		'tresc o znak krotsza NIE przechodzi'
	);

	// Szkielet strony renderowanej JavaScriptem: duzo znacznikow, zero tekstu.
	$szkielet = '<html><body><div id="root"></div><div class="loader"><span></span></div></body></html>';
	$wynik    = Article::extract( $szkielet, 'https://psy.pl/spa/' );

	k3a_check(
		! Article::is_long_enough( Article::clean( $wynik['html'] ) ),
		'strona renderowana JavaScriptem nie przechodzi progu (idzie do skipped)'
	);

	$notatka = Article::short_note( $malo );
	k3a_check( false !== strpos( $notatka, 'Za mało treści' ), 'notatka mowi, co sie stalo' );
	k3a_check( 1 === preg_match( '/\d+ znaków \(potrzeba ' . Article::MIN_TEXT_CHARS . '\)/u', $notatka ), 'notatka podaje LICZBY, nie ogolnik' );

	// -----------------------------------------------------------------------
	echo "\n-- 3.5 Budzet pamieci --\n";
	// -----------------------------------------------------------------------
	$stary_limit = ini_get( 'memory_limit' );

	ini_set( 'memory_limit', '-1' );
	k3a_check( 0 === Article::memory_limit_bytes(), 'memory_limit = -1 znaczy BRAK progu' );
	k3a_check( Article::memory_ok(), 'bez limitu pamieci zawsze wolno pobierac' );

	ini_set( 'memory_limit', '512M' );
	k3a_check( 536870912 === Article::memory_limit_bytes(), 'limit 512M przeliczony na bajty' );
	k3a_check( Article::memory_ok(), 'przy 512M i pustym tescie pamieci wystarcza' );

	// Limit ponizej biezacego zuzycia — bramka MUSI zamknac sie przed zadaniem.
	$zajete = memory_get_usage( true );
	ini_set( 'memory_limit', (string) max( 1, (int) ( $zajete / 1048576 ) ) . 'M' );

	k3a_check( ! Article::memory_ok(), 'przy limicie ponizej zuzycia bramka jest zamknieta' );

	$GLOBALS['__zadania'] = array();
	$wynik                = Article::fetch( 'https://psy.pl/karma/' );

	k3a_check( false === $wynik['ok'], 'przy braku pamieci pobranie nie dochodzi do skutku' );
	k3a_check( 'memory' === $wynik['reason'], 'powod: `memory`' );
	k3a_check( true === $wynik['retryable'], 'brak pamieci JEST wart ponowienia — nastepny tick ma czysta pamiec' );
	k3a_check(
		0 === count( $GLOBALS['__zadania'] ),
		'ZERO zadan HTTP: bramka stoi PRZED zadaniem, nie po nim (jest ' . count( $GLOBALS['__zadania'] ) . ')'
	);

	ini_set( 'memory_limit', (string) $stary_limit );
	k3a_check( Article::memory_ok(), 'po przywroceniu limitu bramka znowu jest otwarta' );

	echo "\n";
	echo '=== WYNIK: ' . ( $ran - $fail ) . ' / ' . $ran . " asercji ===\n";

	if ( $fail > 0 ) {
		echo "BŁĘDY: $fail\n";
		exit( 1 );
	}

	echo "WSZYSTKIE OK\n";
	exit( 0 );
}
