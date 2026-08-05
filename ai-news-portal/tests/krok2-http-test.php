<?php
/**
 * Krok 2, etap 2.1 — warstwa sieciowa `AINP\Http`.
 *
 * Sprawdza to, czego nie widac w kodzie „na oko": jakie argumenty naprawde
 * ida do WordPressa (timeouty, sufity odpowiedzi, przekierowania), co sie
 * dzieje z odpowiedzia ucieta na limicie oraz cala logike `robots.txt`
 * razem z cache'em per host.
 *
 * Zero WordPressa i zero sieci — `wp_safe_remote_get()` jest atrapa, ktora
 * zapisuje kazde zadanie do `$GLOBALS['__req']` i oddaje przygotowana
 * odpowiedz. Asercje ilosciowe sa zawsze `=== N`, nigdy `> 0`.
 *
 * URUCHOMIENIE:  php tests/krok2-http-test.php
 * Kod wyjscia: 0 = OK, 1 = bledy.
 *
 * @package AI_News_Portal
 */

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
function k2_check( $cond, $label ) {
	global $fail, $ran;
	$ran++;
	echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n";
	if ( ! $cond ) {
		$fail++;
	}
}

// ---------------------------------------------------------------------------
// Atrapy WordPressa.
// ---------------------------------------------------------------------------
define( 'ABSPATH', __DIR__ . '/atrapy/wp/' );
define( 'AINP_VERSION', '0.1.0' );

$GLOBALS['__req']       = array();  // Kolejka wykonanych zadan.
$GLOBALS['__resp']      = array();  // Odpowiedzi: adres => odpowiedz albo WP_Error.
$GLOBALS['__resp_any']  = null;     // Odpowiedz dla adresow spoza listy.
$GLOBALS['__transient'] = array();  // Magazyn transientow.

class WP_Error {
	private $code;
	private $message;
	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}
	public function get_error_code() {
		return $this->code;
	}
	public function get_error_message() {
		return $this->message;
	}
}

function is_wp_error( $t ) {
	return $t instanceof WP_Error;
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

function home_url( $path = '' ) {
	return 'https://dworek.local' . $path;
}

function get_transient( $key ) {
	return array_key_exists( $key, $GLOBALS['__transient'] )
		? $GLOBALS['__transient'][ $key ]
		: false;
}

function set_transient( $key, $value, $ttl = 0 ) {
	$GLOBALS['__transient'][ $key ] = $value;
	$GLOBALS['__ttl'][ $key ]       = $ttl;
	return true;
}

function wp_safe_remote_get( $url, $args = array() ) {
	$GLOBALS['__req'][] = array(
		'url'  => $url,
		'args' => $args,
	);

	if ( array_key_exists( $url, $GLOBALS['__resp'] ) ) {
		return $GLOBALS['__resp'][ $url ];
	}

	if ( null !== $GLOBALS['__resp_any'] ) {
		return $GLOBALS['__resp_any'];
	}

	return array(
		'response' => array( 'code' => 200 ),
		'body'     => '',
	);
}

function wp_remote_retrieve_response_code( $r ) {
	return isset( $r['response']['code'] ) ? $r['response']['code'] : 0;
}

function wp_remote_retrieve_body( $r ) {
	return isset( $r['body'] ) ? $r['body'] : '';
}

/**
 * Czysci stan atrap miedzy scenariuszami.
 *
 * @return void
 */
function k2_reset() {
	$GLOBALS['__req']       = array();
	$GLOBALS['__resp']      = array();
	$GLOBALS['__resp_any']  = null;
	$GLOBALS['__transient'] = array();
	$GLOBALS['__ttl']       = array();
}

/**
 * Buduje odpowiedz HTTP dla atrapy.
 *
 * @param int    $code Kod odpowiedzi.
 * @param string $body Tresc.
 *
 * @return array
 */
function k2_resp( $code, $body = '' ) {
	return array(
		'response' => array( 'code' => $code ),
		'body'     => $body,
	);
}

require_once $root . '/src/Http.php';

use AINP\Http;

echo "=== KROK 2 / ETAP 2.1 — warstwa sieciowa (Http) ===\n\n";

// ---------------------------------------------------------------------------
echo "-- Argumenty zadania: timeouty, sufity, przekierowania --\n";
// ---------------------------------------------------------------------------
k2_reset();
$GLOBALS['__resp_any'] = k2_resp( 200, '<rss></rss>' );
$out                   = Http::get_feed( 'https://psy.pl/feed/' );

k2_check( 1 === count( $GLOBALS['__req'] ), 'feed: dokladnie jedno zadanie' );
$args = $GLOBALS['__req'][0]['args'];
k2_check( 10 === $args['timeout'], 'feed: timeout 10 s' );
k2_check( 4194304 === $args['limit_response_size'], 'feed: sufit odpowiedzi 4 MB' );
k2_check( 3 === $args['redirection'], 'feed: 3 przekierowania' );
k2_check( true === $out['ok'] && '<rss></rss>' === $out['body'], 'feed: tresc wraca nietknieta' );
k2_check( 200 === $out['code'] && '' === $out['reason'], 'feed: kod 200, brak powodu bledu' );
k2_check( false === $out['truncated'], 'feed: flaga ucięcia opuszczona' );

k2_reset();
$GLOBALS['__resp_any'] = k2_resp( 200, '<html></html>' );
$out                   = Http::get_article( 'https://psy.pl/artykul/' );

// Pierwsze zadanie to robots.txt, drugie to sam artykul.
k2_check( 2 === count( $GLOBALS['__req'] ), 'artykul: robots.txt + artykul = 2 zadania' );
$args = $GLOBALS['__req'][1]['args'];
k2_check( 15 === $args['timeout'], 'artykul: timeout 15 s' );
k2_check( 1048576 === $args['limit_response_size'], 'artykul: sufit odpowiedzi 1 MB' );
k2_check( 5 === $GLOBALS['__req'][0]['args']['timeout'], 'robots.txt: wlasny, krotszy timeout 5 s' );
k2_check( 65536 === $GLOBALS['__req'][0]['args']['limit_response_size'], 'robots.txt: sufit 64 KB' );
k2_check(
	'https://psy.pl/robots.txt' === $GLOBALS['__req'][0]['url'],
	'robots.txt: pobierany z korzenia hosta'
);

// Sufity MUSZA sie roznic — to jest cala poprawka z audytu planu.
k2_check(
	Http::LIMIT_FEED !== Http::LIMIT_ARTICLE && 4 === (int) ( Http::LIMIT_FEED / Http::LIMIT_ARTICLE ),
	'sufit feedu jest 4x wiekszy niz sufit artykulu'
);

// ---------------------------------------------------------------------------
echo "\n-- Podpis i naglowki --\n";
// ---------------------------------------------------------------------------
k2_reset();
$GLOBALS['__resp_any'] = k2_resp( 200, 'x' );
Http::get_feed( 'https://psy.pl/feed/' );
$args = $GLOBALS['__req'][0]['args'];

k2_check(
	'AI News Portal/0.1.0 (+https://dworek.local/)' === $args['user-agent'],
	'User-Agent: nazwa, wersja i adres witryny'
);
k2_check(
	false !== strpos( $args['headers']['Accept'], 'application/rss+xml' ),
	'feed: Accept prosi o RSS/Atom'
);

k2_reset();
$GLOBALS['__resp_any'] = k2_resp( 200, 'x' );
Http::get_article( 'https://psy.pl/a/' );
$args = $GLOBALS['__req'][1]['args'];
k2_check(
	false !== strpos( $args['headers']['Accept'], 'text/html' )
		&& false === strpos( $args['headers']['Accept'], 'application/rss+xml' ),
	'artykul: Accept prosi o HTML, nie o RSS'
);

// ---------------------------------------------------------------------------
echo "\n-- Odpowiedz ucieta na limicie --\n";
// ---------------------------------------------------------------------------
k2_reset();
$GLOBALS['__resp_any'] = k2_resp( 200, str_repeat( 'a', Http::LIMIT_FEED ) );
$out                   = Http::get_feed( 'https://psy.pl/feed/' );

k2_check( false === $out['ok'], 'feed ucięty: ok = false (uciety XML wywraca parser)' );
k2_check( 'too_large' === $out['reason'], 'feed ucięty: powod `too_large`' );
k2_check( true === $out['truncated'], 'feed ucięty: flaga ucięcia podniesiona' );
k2_check( '' === $out['body'], 'feed ucięty: tresc NIE jest podawana dalej' );

k2_reset();
$GLOBALS['__resp_any'] = k2_resp( 200, str_repeat( 'a', Http::LIMIT_ARTICLE ) );
$out                   = Http::get_article( 'https://psy.pl/a/' );

k2_check( true === $out['ok'], 'artykul ucięty: ok = true (ekstrakcja to zniesie)' );
k2_check( true === $out['truncated'], 'artykul ucięty: flaga ucięcia podniesiona' );
k2_check( Http::LIMIT_ARTICLE === strlen( $out['body'] ), 'artykul ucięty: tresc wraca w calosci' );

// Tresc krotsza o jeden bajt od limitu nie jest ucieta.
k2_reset();
$GLOBALS['__resp_any'] = k2_resp( 200, str_repeat( 'a', Http::LIMIT_FEED - 1 ) );
$out                   = Http::get_feed( 'https://psy.pl/feed/' );
k2_check( true === $out['ok'] && false === $out['truncated'], 'feed o bajt ponizej limitu: bez alarmu' );

// ---------------------------------------------------------------------------
echo "\n-- Bledy: kod HTTP, transport, zly adres --\n";
// ---------------------------------------------------------------------------
k2_reset();
$GLOBALS['__resp_any'] = k2_resp( 503, 'Service Unavailable' );
$out                   = Http::get_feed( 'https://psy.pl/feed/' );

k2_check( false === $out['ok'] && 503 === $out['code'], 'kod 503: ok = false, kod zachowany' );
k2_check( 'status' === $out['reason'], 'kod 503: powod `status`' );
k2_check( '' === $out['body'], 'kod 503: tresc bledu nie idzie dalej jako tresc' );

k2_reset();
$GLOBALS['__resp_any'] = new WP_Error( 'http_request_failed', 'Operation timed out' );
$out                   = Http::get_feed( 'https://psy.pl/feed/' );

k2_check( false === $out['ok'] && 0 === $out['code'], 'blad transportu: ok = false, kod 0' );
k2_check( 'transport' === $out['reason'], 'blad transportu: powod `transport`' );
k2_check( 'Operation timed out' === $out['error'], 'blad transportu: komunikat WordPressa zachowany' );
k2_check(
	! ( $out instanceof WP_Error ) && is_array( $out ),
	'blad transportu: na zewnatrz NIE wychodzi WP_Error'
);

foreach ( array( '', 'nie-adres', 'ftp://psy.pl/feed', 'javascript:alert(1)', '/feed/' ) as $bad ) {
	k2_reset();
	$out = Http::get_feed( $bad );
	k2_check(
		false === $out['ok'] && 'bad_url' === $out['reason'] && 0 === count( $GLOBALS['__req'] ),
		'zly adres „' . $bad . '": odrzucony BEZ zadania sieciowego'
	);
}

// ---------------------------------------------------------------------------
echo "\n-- robots.txt: werdykt, cache, klucz per host --\n";
// ---------------------------------------------------------------------------
k2_reset();
$GLOBALS['__resp']     = array( 'https://psy.pl/robots.txt' => k2_resp( 200, "User-agent: *\nDisallow: /" ) );
$GLOBALS['__resp_any'] = k2_resp( 200, '<html></html>' );
$out                   = Http::get_article( 'https://psy.pl/artykul/' );

k2_check( false === $out['ok'] && 'robots' === $out['reason'], 'zakaz w robots.txt: powod `robots`' );
k2_check( 1 === count( $GLOBALS['__req'] ), 'zakaz w robots.txt: artykul NIE jest pobierany' );

$keys = array_keys( $GLOBALS['__transient'] );
k2_check( 1 === count( $keys ), 'werdykt zapisany w dokladnie jednym transiencie' );
k2_check(
	0 === strpos( $keys[0], 'ainp_robots_' ),
	'klucz transientu ma prefiks `ainp_robots_` (inaczej uninstall go nie zamiecie)'
);
k2_check(
	'0' === $GLOBALS['__transient'][ $keys[0] ],
	'werdykt zapisany jako „0", nie jako false (false = pusty cache)'
);
k2_check( 43200 === $GLOBALS['__ttl'][ $keys[0] ], 'werdykt zyje 12 godzin' );

// Drugi artykul z tego samego hosta nie pobiera robots.txt ponownie.
$GLOBALS['__req'] = array();
$out              = Http::get_article( 'https://psy.pl/inny-artykul/' );
k2_check(
	false === $out['ok'] && 0 === count( $GLOBALS['__req'] ),
	'werdykt brany z cache: zero nowych zadan przy drugim artykule'
);

// Inny host = osobny klucz i osobne zapytanie.
$GLOBALS['__req'] = array();
$GLOBALS['__resp']['https://dogs.example/robots.txt'] = k2_resp( 200, "User-agent: *\nDisallow: /prywatne/" );
$out = Http::get_article( 'https://dogs.example/artykul/' );

k2_check( true === $out['ok'], 'inny host, wlasny robots.txt: pobieranie dozwolone' );
k2_check( 2 === count( $GLOBALS['__transient'] ), 'dwa hosty = dwa osobne transienty' );
k2_check(
	2 === count( $GLOBALS['__req'] ),
	'inny host: wlasne zapytanie o robots.txt + artykul'
);

// Ten sam host po http i po https to osobne werdykty.
k2_reset();
$GLOBALS['__resp_any'] = k2_resp( 404, '' );
Http::allowed( 'https://psy.pl/a/' );
Http::allowed( 'http://psy.pl/a/' );
k2_check( 2 === count( $GLOBALS['__transient'] ), 'http i https tego samego hosta = osobne klucze' );

/*
 * Werdykt zapisany w innym ksztalcie niz '1'/'0' — np. przez starsza wersje
 * wtyczki, ktora trzymala tam `true`. Taki wpis MUSI byc potraktowany jak
 * pusty cache i odswiezony, bo `'1' === true` jest falszem i serwis zostalby
 * po cichu uznany za zablokowany.
 */
k2_reset();
$GLOBALS['__resp_any'] = k2_resp( 404, '' );
$GLOBALS['__transient'][ 'ainp_robots_' . md5( 'https://psy.pl' ) ] = true;
k2_check( true === Http::allowed( 'https://psy.pl/a/' ), 'werdykt w obcym ksztalcie: pobrany na nowo' );
k2_check( 1 === count( $GLOBALS['__req'] ), 'werdykt w obcym ksztalcie: dokladnie jedno zapytanie' );
k2_check(
	'1' === $GLOBALS['__transient'][ 'ainp_robots_' . md5( 'https://psy.pl' ) ],
	'werdykt w obcym ksztalcie: nadpisany poprawnym „1"'
);

// Brak robots.txt i awaria sieci nie blokuja pobierania.
k2_reset();
$GLOBALS['__resp_any'] = k2_resp( 404, 'Not Found' );
k2_check( true === Http::allowed( 'https://psy.pl/a/' ), 'robots.txt 404: pobieranie dozwolone' );

k2_reset();
$GLOBALS['__resp_any'] = new WP_Error( 'http_request_failed', 'DNS' );
k2_check( true === Http::allowed( 'https://psy.pl/a/' ), 'robots.txt nieosiagalny: pobieranie dozwolone' );

k2_reset();
$GLOBALS['__resp_any'] = k2_resp( 200, '' );
k2_check( false === Http::allowed( 'ftp://psy.pl/a/' ), 'adres nie-http: allowed() = false' );
k2_check( 0 === count( $GLOBALS['__req'] ), 'adres nie-http: bez zadania o robots.txt' );

// ---------------------------------------------------------------------------
echo "\n-- robots.txt: parser (czysta funkcja, bez sieci) --\n";
// ---------------------------------------------------------------------------
$przypadki = array(
	array( '', true, 'pusty plik' ),
	array( "User-agent: *\nDisallow: /", false, 'blankietowy zakaz dla wszystkich' ),
	array( "user-agent: *\ndisallow: /", false, 'zakaz zapisany malymi literami' ),
	array( "User-agent: *\nDisallow:  /  ", false, 'zakaz z nadmiarowymi spacjami' ),
	array( "User-agent: *\r\nDisallow: /\r\n", false, 'konce linii CRLF' ),
	array( "User-agent: *\rDisallow: /\r", false, 'konce linii samym CR' ),
	array( "\xEF\xBB\xBFUser-agent: *\nDisallow: /", false, 'BOM na poczatku pliku' ),
	array( "User-agent: *\nDisallow: / # zamkniete", false, 'komentarz na koncu linii' ),
	array( "User-agent: Googlebot\nDisallow: /", true, 'zakaz dotyczy tylko Googlebota' ),
	array( "User-agent: *\nDisallow: /wp-admin/", true, 'zakaz tylko na podkatalog' ),
	array( "User-agent: *\nDisallow:", true, 'pusty Disallow = brak zakazu' ),
	array( "User-agent: *\nDisallow: /\nAllow: /", true, 'Allow otwiera furtke mimo zakazu' ),
	array( "User-agent: Googlebot\nUser-agent: *\nDisallow: /", false, 'dwa User-agent = jedna grupa' ),
	array( "User-agent: *\nDisallow: /a/\n\nUser-agent: Bot\nDisallow: /", true, 'zakaz w cudzej grupie' ),
	array( "# tylko komentarz\n\n", true, 'sam komentarz' ),
	array( "Sitemap: https://psy.pl/sitemap.xml\n", true, 'linia bez grupy' ),
);

foreach ( $przypadki as $p ) {
	list( $tresc, $oczekiwane, $opis ) = $p;
	k2_check( $oczekiwane === Http::robots_allows_all( $tresc ), 'parser: ' . $opis );
}

// ---------------------------------------------------------------------------
echo "\n-- Ponawianie: co jest przejsciowe, a co trwale --\n";
// ---------------------------------------------------------------------------
$retry = array(
	array( array( 'ok' => false, 'reason' => 'transport', 'code' => 0 ), true, 'blad transportu' ),
	array( array( 'ok' => false, 'reason' => 'status', 'code' => 429 ), true, 'kod 429' ),
	array( array( 'ok' => false, 'reason' => 'status', 'code' => 500 ), true, 'kod 500' ),
	array( array( 'ok' => false, 'reason' => 'status', 'code' => 503 ), true, 'kod 503' ),
	array( array( 'ok' => false, 'reason' => 'status', 'code' => 404 ), false, 'kod 404' ),
	array( array( 'ok' => false, 'reason' => 'status', 'code' => 403 ), false, 'kod 403' ),
	array( array( 'ok' => false, 'reason' => 'robots', 'code' => 0 ), false, 'zakaz robots.txt' ),
	array( array( 'ok' => false, 'reason' => 'bad_url', 'code' => 0 ), false, 'zly adres' ),
	array( array( 'ok' => false, 'reason' => 'too_large', 'code' => 200 ), false, 'odpowiedz za duza' ),
	array( array( 'ok' => true, 'reason' => '', 'code' => 200 ), false, 'wynik udany' ),
	/*
	 * Udany wynik nie jest ponawiany NIGDY, nawet gdy pozostale pola wygladaja
	 * na przejsciowy blad. Bez tej bramki bledne wypelnienie pol przez
	 * wywolujacego oznaczaloby drugie pobranie tej samej tresci.
	 */
	array( array( 'ok' => true, 'reason' => 'status', 'code' => 503 ), false, 'udany wynik z mylacymi polami' ),
);

foreach ( $retry as $r ) {
	list( $wynik, $oczekiwane, $opis ) = $r;
	k2_check( $oczekiwane === Http::is_retryable( $wynik ), 'ponowienie (' . $opis . ')' );
}

// ---------------------------------------------------------------------------
echo "\n-- Kontrola kodu (po tokenach, nie po tekscie) --\n";
// ---------------------------------------------------------------------------
/*
 * GOTCHA z Kroku 1: asercja czytajaca plik jako tekst klamie — trafia we
 * wlasne komentarze. Skanujemy wywolania funkcji po `token_get_all()`.
 */
$tokeny   = token_get_all( file_get_contents( $root . '/src/Http.php' ) );
$wywolane = array();
foreach ( $tokeny as $i => $t ) {
	if ( is_array( $t ) && T_STRING === $t[0] ) {
		// Nastepny znaczacy token to `(` => to wywolanie funkcji.
		for ( $j = $i + 1; $j < count( $tokeny ); $j++ ) {
			$n = $tokeny[ $j ];
			if ( is_array( $n ) && in_array( $n[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			if ( '(' === $n ) {
				$wywolane[ $t[1] ] = true;
			}
			break;
		}
	}
}

k2_check(
	isset( $wywolane['wp_safe_remote_get'] ) && ! isset( $wywolane['wp_remote_get'] ),
	'kod uzywa wp_safe_remote_get, nie wp_remote_get (ochrona przed SSRF)'
);
k2_check( ! isset( $wywolane['file_get_contents'] ), 'kod nie omija warstwy HTTP WordPressa' );
k2_check( ! isset( $wywolane['curl_init'] ), 'kod nie siega po cURL bezposrednio' );
k2_check(
	! isset( $wywolane['update_option'] ) && ! isset( $wywolane['get_option'] ),
	'warstwa sieciowa nie dotyka opcji (werdykt siedzi w transiencie)'
);

// ---------------------------------------------------------------------------
echo "\n====================================================\n";
echo 'Asercji: ' . $ran . ' | Niezaliczonych: ' . $fail . "\n";
if ( 0 === $fail ) {
	echo "WYNIK: WSZYSTKIE OK\n";
	exit( 0 );
}
echo "WYNIK: SĄ BŁĘDY\n";
exit( 1 );
