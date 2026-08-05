<?php
/**
 * Krok 2, etap 2.2 — parser kanalow `AINP\Feed`.
 *
 * Kanaly w tym tescie sa pisane recznie i celowo brzydkie: BOM przed
 * deklaracja XML, CDATA, encje kodowane dwa razy, wpisy bez adresu, Atom
 * z `rel="self"` przed `rel="alternate"`, tresc `type="xhtml"` trzymana
 * w wezlach zamiast w tekscie. Kazdy z tych przypadkow wystepuje w zywych
 * kanalach i kazdy potrafi po cichu wyzerowac pobieranie.
 *
 * Osobno pilnowane sa dwie rzeczy bezpieczenstwa: `<!DOCTYPE>` ma byc
 * odrzucany BEZ parsowania (bomba encyjna) i zaden przebieg nie ma prawa
 * wypisac ostrzezenia PHP.
 *
 * Zero WordPressa i zero sieci. Asercje ilosciowe sa zawsze `=== N`.
 *
 * URUCHOMIENIE:  php tests/krok2-feed-test.php
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
function k2f_check( $cond, $label ) {
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

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

// Kazde ostrzezenie PHP jest bledem testu — parser ma milczec.
$GLOBALS['__warn'] = array();
set_error_handler(
	function ( $no, $str ) {
		$GLOBALS['__warn'][] = $str;
		return true;
	}
);

require_once $root . '/src/Http.php';
require_once $root . '/src/Feed.php';

use AINP\Feed;

echo "=== KROK 2 / ETAP 2.2 — parser kanalow (Feed) ===\n\n";

// ---------------------------------------------------------------------------
echo "-- RSS 2.0 --\n";
// ---------------------------------------------------------------------------
$rss = '<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/"
     xmlns:dc="http://purl.org/dc/elements/1.1/">
<channel>
	<title>Psy.pl &amp;#8211; wiedza</title>
	<link>https://psy.pl/</link>
	<item>
		<title><![CDATA[Karma  <b>bytowa</b>
		 dla   psa]]></title>
		<link>https://psy.pl/karma-bytowa/</link>
		<description>Krotki zajawka.</description>
		<content:encoded><![CDATA[<p>Pelna tresc <strong>z CDATA</strong>.</p>]]></content:encoded>
		<guid isPermaLink="false">psy-pl-123</guid>
		<pubDate>Tue, 04 Aug 2026 08:30:00 +0200</pubDate>
	</item>
	<item>
		<title>Wpis z adresem tylko w guid</title>
		<description>Bez elementu link.</description>
		<guid isPermaLink="true">https://psy.pl/z-guid/</guid>
		<dc:date>2026-08-03T10:00:00+02:00</dc:date>
	</item>
	<item>
		<title>Wpis bez zadnego adresu</title>
		<description>Do pominiecia.</description>
		<guid isPermaLink="false">tylko-identyfikator</guid>
	</item>
</channel>
</rss>';

$out = Feed::parse( $rss );

k2f_check( true === $out['ok'], 'RSS: kanal rozlozony' );
k2f_check( 'rss' === $out['format'], 'RSS: rozpoznany format' );
k2f_check( 2 === count( $out['items'] ), 'RSS: dokladnie 2 pozycje z adresem' );
k2f_check( 1 === $out['skipped'], 'RSS: dokladnie 1 pozycja pominieta' );
k2f_check( 'Psy.pl – wiedza' === $out['title'], 'RSS: tytul kanalu z rozwinieta podwojna encja' );

$i = $out['items'][0];
k2f_check(
	'Karma bytowa dla psa' === $i['title'],
	'RSS: tytul bez znacznikow HTML, w jednej linii, bez zdwojonych spacji'
);
k2f_check( 'https://psy.pl/karma-bytowa/' === $i['url'], 'RSS: adres z <link>' );
k2f_check( 'Krotki zajawka.' === $i['summary'], 'RSS: zajawka z <description>' );
k2f_check(
	'<p>Pelna tresc <strong>z CDATA</strong>.</p>' === $i['content'],
	'RSS: content:encoded z CDATA, ze znacznikami HTML'
);
k2f_check( 'psy-pl-123' === $i['guid'], 'RSS: guid zachowany' );
k2f_check( strtotime( 'Tue, 04 Aug 2026 08:30:00 +0200' ) === $i['published'], 'RSS: data z pubDate' );

$i = $out['items'][1];
k2f_check( 'https://psy.pl/z-guid/' === $i['url'], 'RSS: adres wziety z guid isPermaLink' );
k2f_check( '' === $i['content'], 'RSS: brak content:encoded daje pusta tresc, nie blad' );
k2f_check(
	strtotime( '2026-08-03T10:00:00+02:00' ) === $i['published'],
	'RSS: data z dc:date, gdy nie ma pubDate'
);

// Kanal bez <channel> to nie jest kanal.
$out = Feed::parse( '<?xml version="1.0"?><rss version="2.0"><item><title>x</title></item></rss>' );
k2f_check( false === $out['ok'], 'RSS bez <channel>: odrzucony' );

// Kanal poprawny, ale pusty.
$out = Feed::parse( '<?xml version="1.0"?><rss version="2.0"><channel><title>Pusty</title></channel></rss>' );
k2f_check( true === $out['ok'], 'RSS bez wpisow: to nie jest blad' );
k2f_check( 0 === count( $out['items'] ) && 0 === $out['skipped'], 'RSS bez wpisow: zero pozycji' );

// ---------------------------------------------------------------------------
echo "\n-- Atom --\n";
// ---------------------------------------------------------------------------
$atom = '<?xml version="1.0" encoding="UTF-8"?>
<feed xmlns="http://www.w3.org/2005/Atom">
	<title>Kanal Atom</title>
	<entry>
		<title>Wpis pierwszy</title>
		<link rel="self" href="https://example.org/feed/wpis-1"/>
		<link rel="alternate" href="https://example.org/wpis-1/"/>
		<summary>Zajawka Atoma.</summary>
		<content type="html">&lt;p&gt;Tresc HTML&lt;/p&gt;</content>
		<id>tag:example.org,2026:1</id>
		<published>2026-08-01T09:00:00Z</published>
		<updated>2026-08-02T09:00:00Z</updated>
	</entry>
	<entry>
		<title>Wpis z trescia XHTML</title>
		<link href="https://example.org/wpis-2/"/>
		<content type="xhtml"><div xmlns="http://www.w3.org/1999/xhtml"><p>Akapit w wezlach</p></div></content>
		<id>tag:example.org,2026:2</id>
		<updated>2026-08-05T09:00:00Z</updated>
	</entry>
	<entry>
		<title>Wpis tylko z rel=self</title>
		<link rel="self" href="https://example.org/feed/wpis-3"/>
		<id>tag:example.org,2026:3</id>
	</entry>
	<entry>
		<title>Wpis z zalacznikiem i adresem</title>
		<link rel="enclosure" href="https://example.org/pliki/audio.mp3"/>
		<link rel="alternate" href="https://example.org/wpis-4/"/>
		<id>tag:example.org,2026:4</id>
	</entry>
	<entry>
		<title>Wpis z rel pisanym wielkimi literami</title>
		<link rel="SELF" href="https://example.org/feed/wpis-5"/>
		<link rel="Alternate" href="https://example.org/wpis-5/"/>
		<id>tag:example.org,2026:5</id>
	</entry>
</feed>';

$out = Feed::parse( $atom );

k2f_check( true === $out['ok'] && 'atom' === $out['format'], 'Atom: rozpoznany i rozlozony' );
k2f_check( 'Kanal Atom' === $out['title'], 'Atom: tytul kanalu' );
k2f_check( 4 === count( $out['items'] ), 'Atom: 4 pozycje z adresem artykulu' );
/*
 * Wpis, ktorego jedyny <link> ma rel="self", NIE jest przepuszczany.
 * Na poziomie wpisu ten adres zwykle prowadzi do kanalu albo do API, a nie do
 * artykulu — pobrany i zapisany zasmiecilby portal i wpis do odsiewu duplikatow.
 */
k2f_check( 1 === $out['skipped'], 'Atom: wpis wylacznie z rel="self" pominiety' );

$i = $out['items'][0];
k2f_check( 'https://example.org/wpis-1/' === $i['url'], 'Atom: rel="alternate" wygrywa z rel="self"' );
k2f_check( '<p>Tresc HTML</p>' === $i['content'], 'Atom: tresc type="html" rozkodowana' );
k2f_check( 'Zajawka Atoma.' === $i['summary'], 'Atom: zajawka z <summary>' );
k2f_check( 'tag:example.org,2026:1' === $i['guid'], 'Atom: <id> trafia do guid' );
k2f_check( strtotime( '2026-08-01T09:00:00Z' ) === $i['published'], 'Atom: published ma pierwszenstwo' );

$i = $out['items'][1];
k2f_check( 'https://example.org/wpis-2/' === $i['url'], 'Atom: <link> bez rel liczy sie jak alternate' );
k2f_check(
	false !== strpos( $i['content'], '<p>Akapit w wezlach</p>' ),
	'Atom: tresc type="xhtml" wyciagnieta z wezlow, nie pusta'
);
k2f_check( strtotime( '2026-08-05T09:00:00Z' ) === $i['published'], 'Atom: updated jako zapas' );

$i = $out['items'][2];
k2f_check(
	'https://example.org/wpis-4/' === $i['url'],
	'Atom: rel="enclosure" przegrywa z rel="alternate"'
);
k2f_check( '' === $i['content'], 'Atom: brak <content> daje pusta tresc, nie blad' );

$i = $out['items'][3];
k2f_check(
	'https://example.org/wpis-5/' === $i['url'],
	'Atom: rel czytany bez wzgledu na wielkosc liter'
);

// Wpis w ogole bez adresu.
$out = Feed::parse(
	'<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom"><title>t</title>'
	. '<entry><title>Bez adresu</title><id>x</id></entry></feed>'
);
k2f_check(
	0 === count( $out['items'] ) && 1 === $out['skipped'],
	'Atom: wpis bez adresu pominiety, nie przepuszczony'
);

// ---------------------------------------------------------------------------
echo "\n-- Wejscie brudne i zlosliwe --\n";
// ---------------------------------------------------------------------------
$pozycja = '<item><title>a</title><link>https://psy.pl/a/</link></item>';

$bom = "\xEF\xBB\xBF\n  <?xml version=\"1.0\"?><rss version=\"2.0\"><channel><title>Z BOM</title>"
	. $pozycja . '</channel></rss>';
$out = Feed::parse( $bom );
k2f_check( true === $out['ok'] && 1 === count( $out['items'] ), 'BOM i puste linie przed <?xml: kanal dziala' );

/*
 * BOM przy kanale BEZ deklaracji XML. Deklaracja jest nieobowiazkowa, a wtedy
 * BOM przykleja sie wprost do `<rss` i parser widzi nieznany znacznik.
 */
$out = Feed::parse( "\xEF\xBB\xBF<rss version=\"2.0\"><channel><title>Bez deklaracji</title>" . $pozycja . '</channel></rss>' );
k2f_check( true === $out['ok'] && 1 === count( $out['items'] ), 'BOM bez deklaracji XML: kanal dziala' );

/*
 * Smiec przed deklaracja — najczesciej ostrzezenie PHP doklejone przez wtyczke
 * po stronie serwisu. Kanal jest poprawny, psuje go doklejka.
 */
$smiec_przed = "Notice: Undefined variable: x in /home/site/wp-content/plugins/x.php on line 12\n"
	. '<?xml version="1.0"?><rss version="2.0"><channel><title>Ze smieciem</title>' . $pozycja . '</channel></rss>';
$out = Feed::parse( $smiec_przed );
k2f_check( true === $out['ok'] && 1 === count( $out['items'] ), 'ostrzezenie PHP przed <?xml: kanal odzyskany' );

/*
 * Wielkie litery w nazwie elementu glownego. XML jest wrazliwy na wielkosc
 * liter, wiec bez `strtolower` taki kanal bylby „nieznanym formatem".
 */
$out = Feed::parse( '<?xml version="1.0"?><RSS version="2.0"><channel><title>Wielkie</title>' . $pozycja . '</channel></RSS>' );
k2f_check( true === $out['ok'] && 1 === count( $out['items'] ), '<RSS> wielkimi literami: rozpoznany' );

foreach ( array( '   ', 'zwykly tekst', '<html><body>strona bledu</body></html>' ) as $smiec ) {
	$out = Feed::parse( $smiec );
	k2f_check(
		false === $out['ok'] && '' !== $out['error'] && 0 === count( $out['items'] ),
		'smiec zamiast kanalu („' . substr( $smiec, 0, 18 ) . '"): blad z komunikatem'
	);
}

// Pusta odpowiedz ma dawac komunikat o pustce, nie o zlym XML-u — to trafia
// do kolumny `note` i po tym czlowiek rozpoznaje, co sie stalo.
$out = Feed::parse( '' );
k2f_check( false === $out['ok'], 'pusty kanal: odrzucony' );
k2f_check( false !== strpos( $out['error'], 'pusty' ), 'pusty kanal: komunikat mowi o pustce' );

// RSS 1.0 (RDF) — swiadomie poza zakresem, ma dac czytelny blad.
$rdf = '<?xml version="1.0"?><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
	. '<item><title>x</title><link>https://psy.pl/x/</link></item></rdf:RDF>';
$out = Feed::parse( $rdf );
k2f_check( false === $out['ok'], 'RSS 1.0 (RDF): odrzucony' );
k2f_check( false !== strpos( $out['error'], 'Nieznany format' ), 'RSS 1.0 (RDF): komunikat mowi o formacie' );

// Bomba encyjna — ma polec na bramce DOCTYPE, bez parsowania.
$bomba = '<?xml version="1.0"?><!DOCTYPE lolz [<!ENTITY lol "lol">'
	. '<!ENTITY lol2 "&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;&lol;">'
	. '<!ENTITY lol3 "&lol2;&lol2;&lol2;&lol2;&lol2;&lol2;&lol2;&lol2;&lol2;&lol2;">]>'
	. '<rss version="2.0"><channel><title>&lol3;</title></channel></rss>';
$out = Feed::parse( $bomba );
k2f_check( false === $out['ok'], 'bomba encyjna: odrzucona' );
k2f_check( false !== strpos( $out['error'], 'DOCTYPE' ), 'bomba encyjna: powod nazwany wprost' );
k2f_check( '' === $out['title'], 'bomba encyjna: nic nie zostalo rozwiniete' );

// XXE — proba odczytania pliku z dysku.
$xxe = '<?xml version="1.0"?><!DOCTYPE r [<!ENTITY x SYSTEM "file:///c:/windows/win.ini">]>'
	. '<rss version="2.0"><channel><title>&x;</title></channel></rss>';
$out = Feed::parse( $xxe );
k2f_check( false === $out['ok'] && '' === $out['title'], 'XXE: odrzucone, zawartosc pliku nie wyciekla' );

/*
 * DOCTYPE malymi literami. Taki dokument i tak nie jest poprawnym XML-em,
 * wiec sprawdzamy nie tylko odrzucenie, ale i POWOD: ma nazywac DOCTYPE,
 * a nie zrzucac na czlowieka bledu parsera. To rozroznienie decyduje o tym,
 * co klient zobaczy w kolumnie `note`.
 */
$out = Feed::parse( '<?xml version="1.0"?><!doctype rss><rss version="2.0"><channel><title>t</title></channel></rss>' );
k2f_check( false === $out['ok'], 'DOCTYPE malymi literami tez odrzucony' );
k2f_check( false !== strpos( $out['error'], 'DOCTYPE' ), 'DOCTYPE malymi literami: powod nazwany wprost' );

k2f_check(
	0 === count( $GLOBALS['__warn'] ),
	'zaden przebieg nie wypisal ostrzezenia PHP' . ( $GLOBALS['__warn'] ? ' — ' . implode( ' | ', $GLOBALS['__warn'] ) : '' )
);

// Stan globalny libxml zostaje oddany taki, jaki byl.
libxml_use_internal_errors( false );
Feed::parse( 'nie-xml' );
k2f_check(
	false === libxml_use_internal_errors( false ),
	'parser oddaje ustawienie libxml_use_internal_errors nietkniete'
);

// ---------------------------------------------------------------------------
echo "\n-- Ksztalt wyniku --\n";
// ---------------------------------------------------------------------------
$out   = Feed::parse( $rss );
$klucze = array( 'ok', 'error', 'format', 'title', 'items', 'skipped' );
foreach ( $klucze as $k ) {
	k2f_check( array_key_exists( $k, $out ), 'wynik ma klucz `' . $k . '`' );
}
$klucze_pozycji = array( 'title', 'url', 'summary', 'content', 'guid', 'published' );
foreach ( $klucze_pozycji as $k ) {
	k2f_check( array_key_exists( $k, $out['items'][0] ), 'pozycja ma klucz `' . $k . '`' );
}
k2f_check( 0 === $out['items'][0]['published'] || is_int( $out['items'][0]['published'] ), 'data jest liczba' );

// Blad zwraca ten sam ksztalt, nie okrojony.
$out = Feed::parse( 'nie-xml' );
foreach ( $klucze as $k ) {
	k2f_check( array_key_exists( $k, $out ), 'wynik bledu ma klucz `' . $k . '`' );
}

// ---------------------------------------------------------------------------
echo "\n-- Kontrola kodu (po tokenach, nie po tekscie) --\n";
// ---------------------------------------------------------------------------
$tokeny = token_get_all( file_get_contents( $root . '/src/Feed.php' ) );
$nazwy  = array();
foreach ( $tokeny as $t ) {
	if ( is_array( $t ) && T_STRING === $t[0] ) {
		$nazwy[ $t[1] ] = true;
	}
}

k2f_check( ! isset( $nazwy['LIBXML_NOENT'] ), 'kod NIE uzywa LIBXML_NOENT (to ono podstawia encje)' );
k2f_check( ! isset( $nazwy['LIBXML_DTDLOAD'] ), 'kod nie laduje DTD' );
k2f_check( ! isset( $nazwy['file_get_contents'] ), 'parser nie czyta z dysku' );
k2f_check( isset( $nazwy['LIBXML_NONET'] ), 'kod ustawia LIBXML_NONET' );
k2f_check( ! isset( $nazwy['simplexml_load_file'] ), 'parser nie laduje XML-a z adresu' );

// ---------------------------------------------------------------------------
restore_error_handler();
echo "\n====================================================\n";
echo 'Asercji: ' . $ran . ' | Niezaliczonych: ' . $fail . "\n";
if ( 0 === $fail ) {
	echo "WYNIK: WSZYSTKIE OK\n";
	exit( 0 );
}
echo "WYNIK: SĄ BŁĘDY\n";
exit( 1 );
