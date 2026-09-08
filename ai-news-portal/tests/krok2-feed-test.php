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
// `Feed::clean_text()` bierze z `Dedup` ochrone przed polamanym UTF-8 — ta sama
// definicja golego tekstu, ktorej uzywa `Filter::normalize()`.
require_once $root . '/src/Dedup.php';
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
/*
 * ZMIENIONA przy naprawie RAU-R04-002. Do wersji 1.0.0 ta asercja zadala
 * znacznikow w polu `content` — czyli utrwalala dokladnie ten defekt, ktory
 * naprawiamy: `clean_text()` deklarowala w docbloku zdjecie znacznikow, encji
 * i bialych znakow, a wykonywala samo `trim()`. Pytanie zostaje to samo (czy
 * tresc z CDATA dochodzi w calosci), tylko zadane o TEKST, nie o znacznik.
 */
k2f_check(
	'Pelna tresc z CDATA.' === $i['content'],
	'RSS: content:encoded z CDATA dochodzi jako TEKST, bez znacznikow'
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
// ZMIENIONA przy naprawie RAU-R04-002 — patrz uzasadnienie przy asercji RSS wyzej.
k2f_check( 'Tresc HTML' === $i['content'], 'Atom: tresc type="html" rozkodowana i bez znacznikow' );
k2f_check( 'Zajawka Atoma.' === $i['summary'], 'Atom: zajawka z <summary>' );
k2f_check( 'tag:example.org,2026:1' === $i['guid'], 'Atom: <id> trafia do guid' );
k2f_check( strtotime( '2026-08-01T09:00:00Z' ) === $i['published'], 'Atom: published ma pierwszenstwo' );

$i = $out['items'][1];
k2f_check( 'https://example.org/wpis-2/' === $i['url'], 'Atom: <link> bez rel liczy sie jak alternate' );
// ZMIENIONA przy naprawie RAU-R04-002 — pytanie („czy tresc z wezlow doszla,
// zamiast pustego lancucha") zostaje, badane na tekscie zamiast na znaczniku.
k2f_check(
	'Akapit w wezlach' === $i['content'],
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

/*
 * Bramka DOCTYPE patrzy WYLACZNIE w prolog (audyt K2, D1). Skan calego
 * dokumentu kasowal caly kanal przez jeden artykul, ktory cytuje kod HTML
 * w sekcji CDATA — a to zwykla tresc na blogu.
 */
$cdata = '<?xml version="1.0"?><rss version="2.0"><channel><title>Blog</title>'
	. '<item><title>Jak zaczac strone</title><link>https://psy.pl/strona/</link>'
	. '<content:encoded xmlns:content="http://purl.org/rss/1.0/modules/content/">'
	. '<![CDATA[<p>Kazdy plik zaczyna sie od <!DOCTYPE html>.</p>]]></content:encoded>'
	. '</item></channel></rss>';
$out = Feed::parse( $cdata );
k2f_check( true === $out['ok'], 'DOCTYPE w CDATA: kanal NIE jest kasowany' );
k2f_check( 1 === count( $out['items'] ), 'DOCTYPE w CDATA: pozycja doszla' );
/*
 * ZMIENIONA przy naprawie RAU-R04-002. Pytanie tej asercji brzmi „czy kanal
 * przezyl DOCTYPE w CDATA i oddal tresc pozycji", a nie „czy znacznik przetrwal
 * czyszczenie". Po naprawie `clean_text()` zdejmuje znaczniki — takze cytowany
 * `<!DOCTYPE html>` — wiec badamy TEKST zdania, ktory nadal ma przyjsc caly.
 */
k2f_check(
	false !== strpos( $out['items'][0]['content'], 'Kazdy plik zaczyna sie od' ),
	'DOCTYPE w CDATA: tresc artykulu zachowana w calosci'
);

// To samo w tekscie zwyklego elementu, bez CDATA (encje `&lt;`).
$w_tekscie = '<?xml version="1.0"?><rss version="2.0"><channel><title>Blog</title>'
	. '<item><title>O tagu &lt;!DOCTYPE&gt;</title><link>https://psy.pl/doctype/</link>'
	. '<description>Tekst o &lt;!DOCTYPE html&gt; w zajawce.</description></item></channel></rss>';
$out = Feed::parse( $w_tekscie );
k2f_check( true === $out['ok'] && 1 === count( $out['items'] ), 'DOCTYPE w zajawce: kanal przechodzi' );

// Prolog moze miec wiecej niz deklaracje XML — bramka ma isc PRZEZ nie do konca.
$po_pi = '<?xml version="1.0"?><?xml-stylesheet type="text/xsl" href="/s.xsl"?>'
	. '<!DOCTYPE rss><rss version="2.0"><channel><title>t</title></channel></rss>';
$out   = Feed::parse( $po_pi );
k2f_check( false === $out['ok'], 'DOCTYPE za instrukcja przetwarzania: nadal odrzucony' );

$po_komentarzu = '<?xml version="1.0"?><!-- kanal generowany automatycznie -->'
	. '<!DOCTYPE rss><rss version="2.0"><channel><title>t</title></channel></rss>';
$out           = Feed::parse( $po_komentarzu );
k2f_check( false === $out['ok'], 'DOCTYPE za komentarzem prologu: nadal odrzucony' );

// Slowo DOCTYPE w samym komentarzu prologu nikomu nie szkodzi.
$w_komentarzu = '<?xml version="1.0"?><!-- ten kanal nie ma <!DOCTYPE> -->'
	. '<rss version="2.0"><channel><title>t</title></channel></rss>';
$out          = Feed::parse( $w_komentarzu );
k2f_check( true === $out['ok'], 'DOCTYPE wewnatrz komentarza: kanal przechodzi' );

// ---------------------------------------------------------------------------
echo "\n-- guid: identyfikator to nie adres (audyt K2, D2) --\n";
// ---------------------------------------------------------------------------
/*
 * `<guid isPermaLink="false">` to identyfikator wpisu, nie adres artykulu.
 * WordPress publikuje tam `https://serwis.pl/?p=123` — adres, ktory po
 * przekierowaniu prowadzi do tej samej strony co <link>. Wziety jako adres
 * dawalby po canonicalu w Kroku 3 DRUGA kopie tego samego materialu.
 */
$guid_false = '<?xml version="1.0"?><rss version="2.0"><channel><title>t</title>'
	. '<item><title>Wpis bez link</title><guid isPermaLink="false">https://psy.pl/?p=123</guid></item>'
	. '</channel></rss>';
$out        = Feed::parse( $guid_false );
k2f_check( 0 === count( $out['items'] ), 'guid isPermaLink="false": NIE jest brany za adres' );
k2f_check( 1 === $out['skipped'], 'guid isPermaLink="false": pozycja policzona jako pominieta' );

$guid_wielkie = '<?xml version="1.0"?><rss version="2.0"><channel><title>t</title>'
	. '<item><title>Wpis</title><guid isPermaLink=" FALSE ">https://psy.pl/?p=124</guid></item>'
	. '</channel></rss>';
$out          = Feed::parse( $guid_wielkie );
k2f_check( 0 === count( $out['items'] ), 'guid isPermaLink=" FALSE ": wielkosc liter i spacje nie omijaja bramki' );

// Brak atrybutu znaczy „true" — tak mowi RSS 2.0.
$guid_bez_atrybutu = '<?xml version="1.0"?><rss version="2.0"><channel><title>t</title>'
	. '<item><title>Wpis</title><guid>https://psy.pl/bez-atrybutu/</guid></item>'
	. '</channel></rss>';
$out               = Feed::parse( $guid_bez_atrybutu );
k2f_check( 1 === count( $out['items'] ), 'guid bez atrybutu: domyslnie trwaly odnosnik' );
k2f_check(
	'https://psy.pl/bez-atrybutu/' === $out['items'][0]['url'],
	'guid bez atrybutu: adres wziety z guid'
);

// Odrzucony guid zostaje w polu `guid` — jako identyfikator, do czego sluzy.
$out = Feed::parse( $guid_false );
k2f_check( 0 === count( $out['items'] ), 'guid odrzucony jako adres nie tworzy pozycji-widma' );

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
echo "\n-- RAU-R04-002: clean_text() realizuje swoj docblock --\n";
// ---------------------------------------------------------------------------
/*
 * Do wersji 1.0.0 cialo `clean_text()` bylo jednym `return trim()`, mimo ze
 * docblock deklarowal zdjecie znacznikow, encji i nadmiarowych bialych znakow.
 * A to ona czysci adres, zajawke, tresc i guida w OBU galeziach parsera — wiec
 * `<description>` i `<content:encoded>` z pelnym HTML-em (norma w kanalach
 * z pelna trescia) szly ze znacznikami do tablicy pozycji, do bazy i do sufitu
 * dlugosci w `Filter::haystack()`.
 */
$brudny = '<?xml version="1.0" encoding="UTF-8"?>'
	. '<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/"><channel>'
	. '<title>Kanal z pelna trescia</title><item>'
	. '<title>Karma bytowa</title>'
	. '<link>https://psy.pl/a/?x=1&amp;y=2</link>'
	. '<description><![CDATA[<div class="lead"><strong>Zajawka</strong> z&nbsp;HTML-em.</div>]]></description>'
	. '<content:encoded><![CDATA[<p>Pierwszy akapit.</p><p>Drugi akapit.</p>'
	. '<script>alert("x")</script><style>.a{color:red}</style>]]></content:encoded>'
	. '<guid isPermaLink="false">psy-pl-777</guid>'
	. '</item></channel></rss>';

$out = Feed::parse( $brudny );
k2f_check( true === $out['ok'], 'R04-002: kanal z pelna trescia sparsowany' );
$i = $out['items'][0];

k2f_check(
	false === strpos( $i['content'], '<' ) && false === strpos( $i['content'], '>' ),
	'R04-002: tresc wychodzi BEZ znacznikow'
);
k2f_check(
	false === strpos( $i['summary'], '<' ) && false === strpos( $i['summary'], '>' ),
	'R04-002: zajawka wychodzi BEZ znacznikow'
);
k2f_check(
	false === strpos( $i['content'], 'alert("x")' ),
	'R04-002: TRESC skryptu znika razem ze znacznikiem, nie zostaje tekstem'
);
k2f_check(
	false === strpos( $i['content'], 'color:red' ),
	'R04-002: tresc stylu znika razem ze znacznikiem'
);
k2f_check(
	false === strpos( $i['summary'], '&nbsp;' ) && false === strpos( $i['summary'], "\xC2\xA0" ),
	'R04-002: encje rozwiniete, twarda spacja zamieniona na zwykla'
);
k2f_check(
	'https://psy.pl/a/?x=1&y=2' === $i['url'],
	'R04-002: adres z encja `&amp;` wychodzi rozkodowany (' . $i['url'] . ')'
);

// PODZIAL NA AKAPITY ZOSTAJE — zwykle zwiniecie bialych znakow skleiloby
// caly artykul w jeden blok, a to material, ktory idzie do modelu.
k2f_check(
	false !== strpos( $i['content'], "\n" ),
	'R04-002: tresc zachowuje podzial na akapity'
);
k2f_check(
	1 === preg_match( '/Pierwszy akapit\.\s*\n+\s*Drugi akapit\./', $i['content'] ),
	'R04-002: granica miedzy akapitami to znak konca linii, nie spacja'
);

// Kontrola negatywna: pole bez znacznikow ma przejsc NIETKNIETE poza trimem.
$czysty = '<?xml version="1.0"?><rss version="2.0"><channel><title>T</title><item>'
	. '<title>Tytul</title><link>https://psy.pl/b/</link>'
	. '<description>  Zwykla zajawka bez HTML-u.  </description>'
	. '<guid isPermaLink="false">g-1</guid></item></channel></rss>';
$out2 = Feed::parse( $czysty );
k2f_check(
	'Zwykla zajawka bez HTML-u.' === $out2['items'][0]['summary'],
	'R04-002: tekst bez znacznikow przechodzi bez zmian poza trimem'
);

// ---------------------------------------------------------------------------
echo "\n-- RAU-R04-001: deklaracja XML szukana WYLACZNIE w prologu --\n";
// ---------------------------------------------------------------------------
/*
 * `strip_prolog()` szukalo `<?xml` w CALYM dokumencie i obcinalo wszystko przed
 * pierwszym trafieniem. Kanal BEZ deklaracji, ktory cytuje ja dalej w CDATA
 * — normalna tresc na blogu o technologii — zostawal przyciety do smiecia
 * i odrzucany W CALOSCI, razem z wszystkimi pozycjami.
 */
$cytat = '<rss version="2.0"><channel><title>Blog techniczny</title><item>'
	. '<title>Jak zaczac plik XML</title><link>https://blog.pl/xml/</link>'
	. '<description><![CDATA[Kazdy plik zaczyna sie od <?xml version="1.0"?> i to jest regula.]]></description>'
	. '<guid isPermaLink="false">blog-xml-1</guid></item></channel></rss>';
$out3 = Feed::parse( $cytat );
k2f_check( true === $out3['ok'], 'R04-001: kanal bez deklaracji, cytujacy ja w CDATA, NIE jest odrzucony (' . $out3['error'] . ')' );
k2f_check( 1 === count( $out3['items'] ), 'R04-001: pozycja z takiego kanalu doszla' );
k2f_check(
	false !== strpos( $out3['items'][0]['summary'], 'Kazdy plik zaczyna sie od' ),
	'R04-001: tresc cytujaca deklaracje zachowana'
);

// Kontrola pozytywna: prawdziwa doklejka PRZED deklaracja nadal jest scinana.
$doklejka = "\n\n  " . '<?xml version="1.0"?><rss version="2.0"><channel><title>T</title><item>'
	. '<title>Tytul</title><link>https://psy.pl/c/</link><guid isPermaLink="false">g-2</guid>'
	. '</item></channel></rss>';
$out4 = Feed::parse( $doklejka );
k2f_check( true === $out4['ok'], 'R04-001: doklejka przed deklaracja nadal scinana — kanal parsuje sie' );

// Doklejka niebiala, ale krotka — mieszczaca sie w prologu — tez ma byc scieta.
$notice = 'Notice: Undefined index: x in /var/www/motyw.php on line 12. ';
$krotka = $notice . '<?xml version="1.0"?><rss version="2.0"><channel><title>T</title><item>'
	. '<title>Tytul</title><link>https://psy.pl/d/</link><guid isPermaLink="false">g-3</guid>'
	. '</item></channel></rss>';
k2f_check( strlen( $notice ) < 4096, 'material: komunikat motywu miesci sie w prologu' );
k2f_check( true === Feed::parse( $krotka )['ok'], 'R04-001: komunikat motywu przed deklaracja jest scinany' );

/*
 * GRANICA PROLOGU. Doklejka dluzsza niz `MAX_PROLOG_BYTES` przestaje byc
 * prologiem — i tak ma byc: skan bez granicy to koszt liniowy na kazdym
 * kanale, a doklejka wazaca 4 KB nie jest juz „pusta linia z motywu".
 */
$daleka = str_repeat( $notice, 200 ) . '<?xml version="1.0"?><rss version="2.0"><channel>'
	. '<title>T</title></channel></rss>';
k2f_check( strlen( str_repeat( $notice, 200 ) ) > 4096, 'material: doklejka przekracza granice prologu' );
k2f_check(
	false === Feed::parse( $daleka )['ok'],
	'R04-001: deklaracja za granica prologu NIE jest juz szukana'
);

// ---------------------------------------------------------------------------
echo "\n-- RAU-R04-003: Atom czytany przez NS_ATOM, zero pozycji to blad --\n";
// ---------------------------------------------------------------------------
/*
 * Stala `NS_ATOM` byla MARTWA — jedno trafienie w calym repozytorium, wlasna
 * deklaracja. Wpisy szly przez `$doc->entry`, wiec kanal `<atom:feed>` z
 * prefiksowanym korzeniem konczyl sie wynikiem ok=true z ZEREM pozycji
 * i pustym polem bledu: wlasciciel widzial „kanal bez nowosci".
 */
$prefiks = '<?xml version="1.0" encoding="UTF-8"?>'
	. '<atom:feed xmlns:atom="http://www.w3.org/2005/Atom">'
	. '<atom:title>Kanal prefiksowany</atom:title>'
	. '<atom:entry>'
	. '<atom:title>Wpis pierwszy</atom:title>'
	. '<atom:link href="https://example.org/p-1/" rel="alternate"/>'
	. '<atom:summary>Zajawka wpisu.</atom:summary>'
	. '<atom:id>tag:example.org,2026:p1</atom:id>'
	. '<atom:published>2026-08-05T09:00:00Z</atom:published>'
	. '</atom:entry>'
	. '<atom:entry>'
	. '<atom:title>Wpis drugi</atom:title>'
	. '<atom:link href="https://example.org/p-2/"/>'
	. '<atom:id>tag:example.org,2026:p2</atom:id>'
	. '</atom:entry>'
	. '</atom:feed>';
$out5 = Feed::parse( $prefiks );
k2f_check( true === $out5['ok'], 'R04-003: kanal <atom:feed> sparsowany (' . $out5['error'] . ')' );
k2f_check( 'atom' === $out5['format'], 'R04-003: rozpoznany jako atom' );
k2f_check( 2 === count( $out5['items'] ), 'R04-003: OBA wpisy prefiksowane odczytane (jest ' . count( $out5['items'] ) . ')' );
k2f_check( 'https://example.org/p-1/' === $out5['items'][0]['url'], 'R04-003: adres z prefiksowanego <atom:link>' );
k2f_check( 'Wpis pierwszy' === $out5['items'][0]['title'], 'R04-003: tytul z prefiksowanego <atom:title>' );
k2f_check( 'Zajawka wpisu.' === $out5['items'][0]['summary'], 'R04-003: zajawka z prefiksowanego <atom:summary>' );
k2f_check( 'tag:example.org,2026:p1' === $out5['items'][0]['guid'], 'R04-003: guid z prefiksowanego <atom:id>' );
k2f_check( 'Kanal prefiksowany' === $out5['title'], 'R04-003: tytul kanalu z prefiksowanego <atom:title>' );

// Cicha pustka wykluczona: brak <entry> to BLAD z powodem, nie sukces.
$pusty = '<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom">'
	. '<title>Kanal bez wpisow</title></feed>';
$out6 = Feed::parse( $pusty );
k2f_check( false === $out6['ok'], 'R04-003: kanal Atom bez <entry> to BLAD, nie cicha pustka' );
k2f_check( '' !== $out6['error'], 'R04-003: blad ma niepusty powod (' . $out6['error'] . ')' );

// Kontrola negatywna: wpisy BYLY, tylko odpadly na adresie — to nie jest blad.
$odpadly = '<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom">'
	. '<title>Kanal z wpisem bez adresu</title>'
	. '<entry><title>Bez adresu</title><id>x</id></entry></feed>';
$out7 = Feed::parse( $odpadly );
k2f_check( true === $out7['ok'], 'R04-003: wpis odrzucony na adresie NIE robi z kanalu bledu' );
k2f_check( 1 === $out7['skipped'], 'R04-003: taki wpis liczy sie jako pominiety' );

// Stala przestaje byc martwa.
k2f_check( isset( $nazwy['NS_ATOM'] ), 'R04-003: stala NS_ATOM jest UZYWANA w kodzie, nie tylko zadeklarowana' );

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
