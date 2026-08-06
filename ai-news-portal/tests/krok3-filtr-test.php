<?php
/**
 * Krok 3, etap 3.1 — filtr slow wykluczajacych `AINP\Filter`.
 *
 * Test pilnuje dwoch rzeczy, ktore w tej klasie psuja sie po cichu:
 *
 *   1. Dopasowanie MUSI miec granice slowa. Gdy jej zabraknie, `kot` zaczyna
 *      lapac `kotwice` i `kotlet`, a wtedy filtr kasuje artykuly o psach
 *      i nikt tego nie widzi — pominieta pozycja wyglada tak samo jak
 *      poprawnie odrzucona.
 *   2. Znaki diakrytyczne musza zniknac PO OBU stronach. Lista w ustawieniach
 *      jest bez ogonkow, tekst z kanalu je ma; brak `remove_accents()` po
 *      stronie tekstu wylacza polowe listy i tez nie zostawia sladu.
 *
 * Zero WordPressa (poza atrapa `remove_accents`) i zero sieci. Asercje
 * ilosciowe zawsze `=== N`.
 *
 * URUCHOMIENIE:  php tests/krok3-filtr-test.php
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
function k3f_check( $cond, $label ) {
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

/**
 * Atrapa `remove_accents()` — te same podstawienia, ktore robi WordPress
 * dla alfabetu polskiego. Wiecej nie trzeba: lista slow jest polska.
 *
 * @param string $tekst Wejscie.
 *
 * @return string
 */
function remove_accents( $tekst ) {
	return strtr(
		(string) $tekst,
		array(
			'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n',
			'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
			'Ą' => 'A', 'Ć' => 'C', 'Ę' => 'E', 'Ł' => 'L', 'Ń' => 'N',
			'Ó' => 'O', 'Ś' => 'S', 'Ź' => 'Z', 'Ż' => 'Z',
			'é' => 'e', 'á' => 'a', 'ü' => 'u',
		)
	);
}

$GLOBALS['__opcje'] = array();

/**
 * Atrapa `get_option()`.
 *
 * @param string $nazwa    Nazwa opcji.
 * @param mixed  $domyslna Wartosc domyslna.
 *
 * @return mixed
 */
function get_option( $nazwa, $domyslna = false ) {
	return array_key_exists( $nazwa, $GLOBALS['__opcje'] ) ? $GLOBALS['__opcje'][ $nazwa ] : $domyslna;
}

/**
 * Atrapa `update_option()`.
 *
 * @param string $nazwa    Nazwa opcji.
 * @param mixed  $wartosc  Wartosc.
 *
 * @return bool
 */
function update_option( $nazwa, $wartosc ) {
	$GLOBALS['__opcje'][ $nazwa ] = $wartosc;
	return true;
}

// Kazde ostrzezenie PHP jest bledem testu — pozycja z kanalu bywa polamana,
// a „Undefined array key" w logu klienta nie ma prawa sie pojawic.
$GLOBALS['__warn'] = array();
set_error_handler(
	function ( $no, $str ) {
		$GLOBALS['__warn'][] = $str;
		return true;
	}
);

require_once $root . '/src/Dedup.php';
require_once $root . '/src/Settings.php';
require_once $root . '/src/Filter.php';

use AINP\Filter;
use AINP\Settings;

echo "=== KROK 3 / ETAP 3.1 — filtr slow wykluczajacych ===\n\n";

// ---------------------------------------------------------------------------
echo "-- Granica slowa: co MA zostac zlapane --\n";
// ---------------------------------------------------------------------------
$lapie = array(
	'Mój kot śpi na kanapie'          => 'slowo w srodku zdania',
	'kot'                             => 'samo slowo',
	'KOT NA DACHU'                    => 'wielkie litery',
	'Uwaga, kot!'                     => 'przecinek przed, wykrzyknik po',
	'(kot)'                           => 'w nawiasach',
	'pies-kot-chomik'                 => 'rozdzielone myslnikami',
	"nowa linia\nkot\nkoniec"         => 'wlasna linia',
	'<p>kot</p>'                      => 'w znaczniku HTML',
	'&#107;ot i kot'                  => 'obok encji liczbowej',
	'kot: opis'                       => 'przed dwukropkiem',
	'"kot"'                           => 'w cudzyslowie',
);

foreach ( $lapie as $tekst => $opis ) {
	k3f_check(
		'kot' === Filter::match( array( 'title' => $tekst ), array( 'kot' ) ),
		'lapie „kot": ' . $opis
	);
}

// ---------------------------------------------------------------------------
echo "\n-- Granica slowa: co NIE MA zostac zlapane --\n";
// ---------------------------------------------------------------------------
$nie_lapie = array(
	'kotwica w porcie'      => 'kotwica',
	'schabowy kotlet'       => 'kotlet',
	'zaslona i kotara'      => 'kotara',
	'ekotoksyczny odpad'    => 'slowo w srodku innego',
	'kotylion na balu'      => 'kotylion',
	'kotek'                 => 'zdrobnienie bez wpisu na liscie',
	'3kot'                  => 'cyfra przed slowem',
	'kot3'                  => 'cyfra po slowie',
	'kotłownia'             => 'polska litera zaraz po rdzeniu',
	'pies i chomik'         => 'inne slowo z listy, ale nie to szukane',
);

foreach ( $nie_lapie as $tekst => $opis ) {
	k3f_check(
		'' === Filter::match( array( 'title' => $tekst ), array( 'kot' ) ),
		'nie lapie „kot" w: ' . $opis
	);
}

// Granica slowa musi znac litery SPOZA alfabetu lacinskiego. Bez modyfikatora
// `u` wyrazenie oglada bajty: przed „k" stoi wtedy drugi bajt litery „ż",
// ktory bajtowo nie jest litera — i „żkot" zostaje uznane za slowo „kot".
// Litera musi byc taka, ktorej `remove_accents()` NIE sprowadza do ASCII —
// inaczej test sprawdzalby usuwanie ogonkow, a nie granice slowa. Cyrylica
// nadaje sie idealnie: bajtowo jej pierwszy bajt nie jest litera ASCII.
k3f_check(
	'' === Filter::match( array( 'title' => 'яkot' ), array( 'kot' ) ),
	'nie lapie „kot" w: litera wielobajtowa tuz przed slowem'
);
k3f_check(
	'' === Filter::match( array( 'title' => 'kotя' ), array( 'kot' ) ),
	'nie lapie „kot" w: litera wielobajtowa tuz po slowie'
);

// Skad bierze sie mala litera: `Dedup::normalize_text()` juz ja robi, a filtr
// powtarza to swiadomie. Ta asercja przypina zalozenie, na ktorym stoi
// powtorzenie — gdyby definicja golego tekstu przestala zmieniac wielkosc,
// zobaczymy to tutaj, a nie w produkcji.
k3f_check(
	'kot' === AINP\Dedup::normalize_text( 'KOT' ),
	'wspolna definicja golego tekstu sprowadza do malych liter'
);

// ---------------------------------------------------------------------------
echo "\n-- Polamane kodowanie --\n";
// ---------------------------------------------------------------------------
// Kanal z blednym bajtem to nie teoria: wystarczy zle skonfigurowany serwer.
// Wyrazenie z modyfikatorem `u` na takim tekscie zwraca `false` — czyli
// „slowa nie ma" — i CALY kanal przechodzi filtr. Znalezione mutacja.
$polamany = "Artykul o kocie \xC3\x28 i psach, slowo kot w srodku";

k3f_check(
	1 !== preg_match( '//u', $polamany ),
	'material testowy faktycznie ma polamana sekwencje UTF-8'
);
k3f_check(
	'kot' === Filter::match( array( 'title' => $polamany ), array( 'kot' ) ),
	'slowo wykluczajace znalezione MIMO polamanego kodowania'
);
k3f_check(
	1 === preg_match( '//u', Filter::normalize( $polamany ) ),
	'po normalizacji tekst jest poprawnym UTF-8'
);
k3f_check(
	'' === Filter::match( array( 'title' => $polamany ), array( 'chomik' ) ),
	'polamane kodowanie nie zaczyna lapac czegokolwiek'
);

// ---------------------------------------------------------------------------
echo "\n-- Znaki diakrytyczne po obu stronach porownania --\n";
// ---------------------------------------------------------------------------
k3f_check(
	'zolw' === Filter::match( array( 'title' => 'Żółw błotny w ogrodzie' ), array( 'zolw' ) ),
	'tekst z ogonkami, slowo bez ogonkow'
);
k3f_check(
	'krolik' === Filter::match( array( 'title' => 'Królik miniaturowy' ), array( 'krolik' ) ),
	'„Królik" trafia w „krolik"'
);
k3f_check(
	'zolw' === Filter::match( array( 'title' => 'zolw stepowy' ), array( 'Żółw' ) ),
	'slowo z ogonkami wpisane recznie w Ustawieniach tez dziala'
);
k3f_check(
	'wyprzedaz' === Filter::match( array( 'title' => 'WYPRZEDAŻ karm' ), array( 'wyprzedaz' ) ),
	'wielkie litery z ogonkiem'
);

// ---------------------------------------------------------------------------
echo "\n-- Wyrazenia wielowyrazowe --\n";
// ---------------------------------------------------------------------------
k3f_check(
	'swinka morska' === Filter::match( array( 'title' => 'Świnka morska dla dziecka' ), array( 'swinka morska' ) ),
	'„Świnka morska" jako jedno wyrazenie'
);
k3f_check(
	'swinka morska' === Filter::match( array( 'title' => "Świnka\n morska" ), array( 'swinka morska' ) ),
	'lamana linia miedzy wyrazami nie psuje dopasowania'
);
k3f_check(
	'black friday' === Filter::match( array( 'title' => 'Black Friday w sklepie' ), array( 'black friday' ) ),
	'„Black Friday" wielkimi literami'
);
k3f_check(
	'' === Filter::match( array( 'title' => 'swinka domowa i welna morska' ), array( 'swinka morska' ) ),
	'oba wyrazy osobno to NIE jest wyrazenie'
);
k3f_check(
	'swinka morska' === Filter::match( array( 'title' => 'swinka&nbsp;morska' ), array( 'swinka morska' ) ),
	'twarda spacja z encji liczy sie jak zwykla'
);

// ---------------------------------------------------------------------------
echo "\n-- Wejscie filtra: tytul + zajawka + tresc z kanalu --\n";
// ---------------------------------------------------------------------------
k3f_check(
	'kot' === Filter::match( array( 'title' => 'Bez slow', 'summary' => 'ale w zajawce jest kot' ), array( 'kot' ) ),
	'slowo w zajawce'
);
k3f_check(
	'kot' === Filter::match( array( 'title' => 'Bez slow', 'content' => '<p>a tu kot</p>' ), array( 'kot' ) ),
	'slowo w tresci z content:encoded'
);
k3f_check(
	'kot' === Filter::match( array( 'excerpt' => 'kot' ), array( 'kot' ) ),
	'kolumna `excerpt` z tabeli tez jest czytana'
);
k3f_check(
	'' === Filter::match( array( 'url' => 'https://psy.pl/kot' ), array( 'kot' ) ),
	'ADRES nie jest wejsciem filtra — slug potrafi klamac o tresci'
);
k3f_check(
	'' === Filter::match( array(), array( 'kot' ) ),
	'pusta pozycja przechodzi'
);
k3f_check(
	'' === Filter::match( array( 'title' => 'cokolwiek' ), array() ),
	'pusta lista slow przepuszcza wszystko'
);
k3f_check(
	'' === Filter::match( array( 'title' => array( 'nie', 'lancuch' ) ), array( 'kot' ) ),
	'pole niebedace lancuchem nie wywraca filtra'
);

// Tresc `<script>` nie jest tekstem artykulu — `strip_tags` samo zostawiloby ja
// w wyniku i skrypt analityczny ze slowem „konkurs" kasowalby artykuly.
k3f_check(
	'' === Filter::match( array( 'content' => '<script>var kot = 1;</script><p>pies</p>' ), array( 'kot' ) ),
	'slowo WEWNATRZ <script> nie liczy sie jako tresc'
);
k3f_check(
	'' === Filter::match( array( 'content' => '<style>.kot{color:red}</style><p>pies</p>' ), array( 'kot' ) ),
	'slowo wewnatrz <style> tez nie'
);

// ---------------------------------------------------------------------------
echo "\n-- Kolejnosc i zwracana wartosc --\n";
// ---------------------------------------------------------------------------
$wynik = Filter::match(
	array( 'title' => 'Chomik i kot w jednym tekscie' ),
	array( 'kot', 'chomik' )
);
k3f_check( 'kot' === $wynik, 'zwraca slowo Z LISTY, w kolejnosci listy, nie tekstu' );

k3f_check(
	'Słowo wykluczające: kot' === Filter::note( 'kot' ),
	'notatka do kolumny `note` ma czytelny powod'
);

// ---------------------------------------------------------------------------
echo "\n-- Sufit dlugosci tekstu --\n";
// ---------------------------------------------------------------------------
$dlugi_ogon  = str_repeat( 'pies ', 30000 ) . 'kot';   // ok. 150 KB, slowo na koncu.
$dlugi_przod = 'kot ' . str_repeat( 'pies ', 30000 );

k3f_check(
	strlen( $dlugi_ogon ) > Filter::MAX_HAYSTACK_BYTES,
	'material testowy faktycznie przekracza sufit ' . Filter::MAX_HAYSTACK_BYTES . ' B'
);
k3f_check(
	'' === Filter::match( array( 'content' => $dlugi_ogon ), array( 'kot' ) ),
	'slowo za sufitem 128 KB nie jest szukane'
);
k3f_check(
	'kot' === Filter::match( array( 'content' => $dlugi_przod ), array( 'kot' ) ),
	'slowo przed sufitem jest znajdowane'
);

// ---------------------------------------------------------------------------
echo "\n-- Lista slow z ustawien --\n";
// ---------------------------------------------------------------------------
$GLOBALS['__opcje'] = array();
$domyslne           = Filter::words();

k3f_check( 48 === count( $domyslne ), 'domyslna lista ma dokladnie 48 slow (jest ' . count( $domyslne ) . ')' );
k3f_check( in_array( 'kot', $domyslne, true ), 'warstwa 1 (inne gatunki) jest na liscie' );
k3f_check( in_array( 'black friday', $domyslne, true ), 'warstwa 2 (komercja) jest na liscie' );
k3f_check( in_array( 'schronisko', $domyslne, true ), 'warstwa 3 (tresci nieodpowiednie) jest na liscie' );
k3f_check(
	count( $domyslne ) === count( array_unique( $domyslne ) ),
	'domyslna lista nie ma powtorzen'
);

$GLOBALS['__opcje'][ Settings::OPTION ] = array( 'excluded_words' => array( 'Kot', 'KOT ', ' kot', 'Żółw', '', '   ', 5 ) );
$wlasne                                 = Filter::words();

k3f_check( 3 === count( $wlasne ), 'lista klienta po oczyszczeniu ma 3 pozycje (jest ' . count( $wlasne ) . ')' );
k3f_check( in_array( 'kot', $wlasne, true ), 'powtorzenia po normalizacji scalone w jedno' );
k3f_check( in_array( 'zolw', $wlasne, true ), 'slowo z ogonkami znormalizowane' );
k3f_check( in_array( '5', $wlasne, true ), 'liczba wpisana w pole tekstowe nie wywraca listy' );

$GLOBALS['__opcje'][ Settings::OPTION ] = array( 'excluded_words' => 'nie tablica' );
k3f_check( array() === Filter::words(), 'zepsuta opcja daje pusta liste, nie blad' );

$GLOBALS['__opcje'] = array();

// ---------------------------------------------------------------------------
echo "\n-- Ostrzezenia PHP --\n";
// ---------------------------------------------------------------------------
k3f_check(
	0 === count( $GLOBALS['__warn'] ),
	'zero ostrzezen PHP w calym zestawie (' . count( $GLOBALS['__warn'] ) . ')'
);

if ( $GLOBALS['__warn'] ) {
	foreach ( array_unique( $GLOBALS['__warn'] ) as $ostrzezenie ) {
		echo '       ! ' . $ostrzezenie . "\n";
	}
}

// ---------------------------------------------------------------------------
restore_error_handler();

echo "\n";
echo '=== WYNIK: ' . ( $ran - $fail ) . ' / ' . $ran . " asercji ===\n";

if ( $fail > 0 ) {
	echo "BŁĘDY: $fail\n";
	exit( 1 );
}

echo "WSZYSTKIE OK\n";
exit( 0 );
