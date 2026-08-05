<?php
/**
 * Krok 2, etap 2.3 — odsiew duplikatow `AINP\Dedup`.
 *
 * Sedno testu to pary adresow, ktore MUSZA dac ten sam odcisk (ten sam
 * artykul podany trzema kanalami) i pary, ktore musza dac ROZNE odciski
 * (dwa rozne artykuly). Pomylka w pierwszej grupie robi portal pelen
 * duplikatow, w drugiej — cicho gubi artykuly, bo klucz UNIQUE odrzuci
 * zapis, a nikt tego nie zobaczy.
 *
 * Zero WordPressa i zero sieci. Asercje ilosciowe sa zawsze `=== N`.
 *
 * URUCHOMIENIE:  php tests/krok2-dedup-test.php
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
function k2d_check( $cond, $label ) {
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

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

// Kazde ostrzezenie PHP jest bledem testu — adres z kanalu bywa polamany,
// a wpis „Undefined array key" w logu klienta nie ma prawa sie pojawic.
$GLOBALS['__warn'] = array();
set_error_handler(
	function ( $no, $str ) {
		$GLOBALS['__warn'][] = $str;
		return true;
	}
);

require_once $root . '/src/Dedup.php';

use AINP\Dedup;

echo "=== KROK 2 / ETAP 2.3 — odsiew duplikatow (Dedup) ===\n\n";

// ---------------------------------------------------------------------------
echo "-- Adresy, ktore sa TYM SAMYM artykulem --\n";
// ---------------------------------------------------------------------------
$wzorzec = 'https://psy.pl/karma-bytowa';

$te_same = array(
	'https://psy.pl/karma-bytowa/'                             => 'koncowy ukosnik',
	'https://PSY.pl/karma-bytowa'                              => 'host wielkimi literami',
	'HTTPS://psy.pl/karma-bytowa'                              => 'schemat wielkimi literami',
	'https://psy.pl:443/karma-bytowa'                          => 'jawny port domyslny',
	'https://psy.pl/karma-bytowa#komentarze'                   => 'kotwica',
	'https://psy.pl/karma-bytowa/#respond'                     => 'kotwica i ukosnik razem',
	'https://psy.pl//karma-bytowa'                             => 'zdwojony ukosnik',
	'https://psy.pl/karma-bytowa?utm_source=newsletter'        => 'utm_source',
	'https://psy.pl/karma-bytowa?utm_medium=email&utm_id=7'    => 'dwa parametry utm',
	'https://psy.pl/karma-bytowa?fbclid=IwAR123'               => 'fbclid z Facebooka',
	'https://psy.pl/karma-bytowa?gclid=abc'                    => 'gclid z Google Ads',
	'https://psy.pl/karma-bytowa?ref=rss&source=feed'          => 'ref i source',
	'https://psy.pl/karma-bytowa?mc_cid=1&mc_eid=2'            => 'parametry Mailchimpa',
	'https://psy.pl/karma-bytowa?UTM_SOURCE=x'                 => 'utm wielkimi literami',
	'  https://psy.pl/karma-bytowa  '                          => 'spacje dookola adresu',
);

$odcisk = Dedup::url_hash( $wzorzec );

foreach ( $te_same as $adres => $opis ) {
	k2d_check( $odcisk === Dedup::url_hash( $adres ), 'ten sam odcisk mimo: ' . $opis );
}

// Kolejnosc parametrow tresciowych nie zmienia strony.
k2d_check(
	Dedup::url_hash( 'https://psy.pl/a?b=2&a=1' ) === Dedup::url_hash( 'https://psy.pl/a?a=1&b=2' ),
	'ten sam odcisk mimo: przestawiona kolejnosc parametrow'
);

// Korzen serwisu z ukosnikiem i bez.
k2d_check(
	Dedup::url_hash( 'https://psy.pl' ) === Dedup::url_hash( 'https://psy.pl/' ),
	'ten sam odcisk mimo: korzen z ukosnikiem i bez'
);

// ---------------------------------------------------------------------------
echo "\n-- Adresy, ktore sa ROZNYMI artykulami --\n";
// ---------------------------------------------------------------------------
$rozne = array(
	'https://psy.pl/karma-bytowa-2'      => 'inna sciezka',
	'https://psy.pl/Karma-Bytowa'        => 'inna wielkosc liter w SCIEZCE (serwer je rozroznia)',
	'https://inny.pl/karma-bytowa'       => 'inny host',
	'http://psy.pl/karma-bytowa'         => 'inny schemat',
	'https://psy.pl:8080/karma-bytowa'   => 'port nietypowy',
	'https://psy.pl:80/karma-bytowa'     => 'port 80 przy https jest nietypowy',
	'https://psy.pl/karma-bytowa?p=123'  => 'parametr tresciowy',
	'https://blog.psy.pl/karma-bytowa'   => 'poddomena',
);

foreach ( $rozne as $adres => $opis ) {
	k2d_check( $odcisk !== Dedup::url_hash( $adres ), 'inny odcisk, bo: ' . $opis );
}

// Parametr tresciowy zostaje, sledzacy znika — sprawdzane na postaci jawnej.
k2d_check(
	'https://psy.pl/a?p=123' === Dedup::normalize_url( 'https://psy.pl/a?utm_source=x&p=123&fbclid=y' ),
	'parametr tresciowy przezywa, sledzace znikaja'
);

/*
 * Port domyslny znika tylko dla WLASCIWEGO schematu: `:443` przy `http`
 * i `:80` przy `https` to porty nietypowe i musza zostac w adresie.
 */
k2d_check(
	'http://psy.pl:443/a' === Dedup::normalize_url( 'http://psy.pl:443/a' ),
	'port 443 przy http NIE jest domyslny — zostaje'
);
k2d_check(
	'http://psy.pl/a' === Dedup::normalize_url( 'http://psy.pl:80/a' ),
	'port 80 przy http jest domyslny — znika'
);
k2d_check(
	'https://psy.pl/a' === Dedup::normalize_url( 'https://psy.pl/a?utm_source=x' ),
	'gdy zostana same parametry sledzace, znak zapytania tez znika'
);

// ---------------------------------------------------------------------------
echo "\n-- Adresy nie do uzycia --\n";
// ---------------------------------------------------------------------------
$zle = array(
	'',
	'   ',
	'karma-bytowa',
	'/karma-bytowa',
	'ftp://psy.pl/a',
	'javascript:alert(1)',
	'https://',
	// Jeden ukosnik zamiast dwoch: `parse_url` zwraca schemat i sciezke,
	// ale ZADNEGO hosta — bez osobnego sprawdzenia powstalby adres
	// „https:///karma" i wlasny, nieistniejacy odcisk.
	'https:/karma',
	'mailto:redakcja@psy.pl',
);

foreach ( $zle as $zly ) {
	k2d_check( '' === Dedup::normalize_url( $zly ), 'adres odrzucony: „' . $zly . '"' );
	k2d_check( '' === Dedup::url_hash( $zly ), 'brak odcisku dla: „' . $zly . '"' );
}

// ---------------------------------------------------------------------------
echo "\n-- Ksztalt odcisku --\n";
// ---------------------------------------------------------------------------
k2d_check( 64 === strlen( $odcisk ), 'odcisk adresu ma 64 znaki (kolumna char(64))' );
k2d_check( 1 === preg_match( '/^[0-9a-f]{64}$/', $odcisk ), 'odcisk adresu jest szesnastkowy' );
k2d_check( $odcisk === Dedup::url_hash( $wzorzec ), 'odcisk jest powtarzalny' );
k2d_check(
	hash( 'sha256', 'https://psy.pl/karma-bytowa' ) === $odcisk,
	'odcisk to sha256 z POSTACI ZNORMALIZOWANEJ, nie z surowego adresu'
);

// ---------------------------------------------------------------------------
echo "\n-- Odcisk tresci --\n";
// ---------------------------------------------------------------------------
$tresc = '<p>Karma bytowa to podstawa <strong>diety</strong> psa doroslego.</p>';

$te_same_tresci = array(
	'Karma bytowa to podstawa diety psa doroslego.'                                  => 'goly tekst bez znacznikow',
	"<div>Karma   bytowa to podstawa\n\tdiety psa doroslego.</div>"                   => 'inne znaczniki i biale znaki',
	'<p>KARMA BYTOWA to podstawa <em>DIETY</em> psa doroslego.</p>'                   => 'inna wielkosc liter',
	'<p>Karma&nbsp;bytowa to podstawa <b>diety</b> psa doroslego.</p>'                => 'twarda spacja z encji',
	'<p>Karma bytowa to podstawa diety psa doroslego.</p><script>var x=1;</script>'   => 'doklejony skrypt',
	'<style>.a{color:red}</style><p>Karma bytowa to podstawa diety psa doroslego.</p>' => 'doklejony styl',
);

$odcisk_tresci = Dedup::content_hash( $tresc );

foreach ( $te_same_tresci as $wariant => $opis ) {
	k2d_check( $odcisk_tresci === Dedup::content_hash( $wariant ), 'ta sama tresc mimo: ' . $opis );
}

k2d_check(
	$odcisk_tresci !== Dedup::content_hash( '<p>Karma bytowa to podstawa diety psa MLODEGO.</p>' ),
	'inna tresc daje inny odcisk'
);
k2d_check( 64 === strlen( $odcisk_tresci ), 'odcisk tresci ma 64 znaki' );

// Pusta tresc nie ma odcisku — wolajacy zapisze NULL, a NULL-e nie koliduja
// ze soba w kluczu UNIQUE.
foreach ( array( '', '   ', "\n\t", '<p></p>', '<div><span> </span></div>', '<script>var x=1;</script>' ) as $puste ) {
	k2d_check( '' === Dedup::content_hash( $puste ), 'brak odcisku dla tresci pustej po odarciu ze znacznikow' );
}

/*
 * Odcisk liczony z pierwszych 8 KB: rozne ogony daja ten sam odcisk, rozny
 * poczatek — juz nie. Liczba 8192 jest wpisana WPROST, nie przez stala klasy:
 * test ma pilnowac umowy, a nie powtarzac to, co akurat mowi kod.
 */
k2d_check( 8192 === Dedup::CONTENT_BYTES, 'sufit odcisku tresci to 8 KB' );
$poczatek = str_repeat( 'a', 8192 );
k2d_check(
	Dedup::content_hash( $poczatek . 'XXXX' ) === Dedup::content_hash( $poczatek . 'YYYY' ),
	'tresc rozniaca sie dopiero po 8 KB: ten sam odcisk'
);
k2d_check(
	Dedup::content_hash( 'X' . $poczatek ) !== Dedup::content_hash( 'Y' . $poczatek ),
	'tresc rozniaca sie na poczatku: inny odcisk'
);

// ---------------------------------------------------------------------------
echo "\n-- Rozpoznawanie parametrow sledzacych --\n";
// ---------------------------------------------------------------------------
$sledzace = array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_id', 'UTM_TERM',
	'fbclid', 'gclid', 'dclid', 'msclkid', 'yclid', 'igshid', 'mc_cid', 'mc_eid', 'ref', 'source', '_ga', '_gl' );
foreach ( $sledzace as $p ) {
	k2d_check( true === Dedup::is_tracking( $p ), 'parametr sledzacy: ' . $p );
}

$tresciowe = array( 'p', 'page', 'id', 'post', 'q', 's', 'lang', 'sourcebook', 'reference', 'utm',
	// Prefiks `utm_` liczy sie tylko na POCZATKU nazwy.
	'x_utm_source', 'nasze_utm_dane' );
foreach ( $tresciowe as $p ) {
	k2d_check( false === Dedup::is_tracking( $p ), 'parametr tresciowy zostaje: ' . $p );
}

// ---------------------------------------------------------------------------
echo "\n-- Kontrola kodu (po tokenach, nie po tekscie) --\n";
// ---------------------------------------------------------------------------
$tokeny = token_get_all( file_get_contents( $root . '/src/Dedup.php' ) );
$nazwy  = array();
foreach ( $tokeny as $t ) {
	if ( is_array( $t ) && T_STRING === $t[0] ) {
		$nazwy[ $t[1] ] = true;
	}
}

k2d_check( isset( $nazwy['sha256'] ) || isset( $nazwy['hash'] ), 'odcisk liczony funkcja hash' );
k2d_check( ! isset( $nazwy['md5'] ), 'md5 nieuzywany (kolumna ma 64 znaki, nie 32)' );
k2d_check( ! isset( $nazwy['wpdb'] ), 'klasa nie dotyka bazy — o duplikaty pyta klucz UNIQUE' );
k2d_check( ! isset( $nazwy['get_option'] ), 'klasa nie czyta opcji' );

k2d_check(
	0 === count( $GLOBALS['__warn'] ),
	'zaden przebieg nie wypisal ostrzezenia PHP'
		. ( $GLOBALS['__warn'] ? ' — ' . implode( ' | ', $GLOBALS['__warn'] ) : '' )
);
restore_error_handler();

// ---------------------------------------------------------------------------
echo "\n====================================================\n";
echo 'Asercji: ' . $ran . ' | Niezaliczonych: ' . $fail . "\n";
if ( 0 === $fail ) {
	echo "WYNIK: WSZYSTKIE OK\n";
	exit( 0 );
}
echo "WYNIK: SĄ BŁĘDY\n";
exit( 1 );
