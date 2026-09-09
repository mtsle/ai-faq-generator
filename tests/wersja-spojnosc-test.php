<?php
/**
 * Strażnik spójności numeru wersji wtyczki 1 — czysto STATYCZNY.
 *
 * PO CO ISTNIEJE: numer wersji wtyczki 1 żyje w CZTERECH miejscach (nagłówek
 * `Version:`, stała `AIFAQ_VERSION`, `Stable tag` w `readme.txt` i najnowszy
 * wpis changeloga) i do wydania 1.1.0 nie pilnowało ich NIC. Wtyczka 2 ma taką
 * bramkę od Kroku 8 (`ai-news-portal/tests/etap85-readme-test.php`) — ten plik
 * wyrównuje asymetrię.
 *
 * DRUGA POŁOWA to instrukcje. Pięć źródeł HTML miało numer wersji WPISANY
 * W TREŚĆ i błąd wyszedł dwa razy: po wydaniu v1.0.0 wszystkie pięć PDF-ów
 * mówiło 0.33.0, a po bumpie do 1.1.0 mówiłyby 1.0.0. Źródła stoją teraz na
 * znaczniku `{{WERSJA}}`, który builder podstawia z nagłówka wtyczki. Strażnik
 * pilnuje, żeby liczba nie wróciła do treści — bo wtedy znów miałaby własne,
 * drugie źródło prawdy.
 *
 * OGRANICZENIE MÓWIONE WPROST: to bramka na ŹRÓDŁA w repozytorium. Builder
 * (`zasoby/skrypty/instrukcje/buduj-pdf.mjs`) leży poza repo i CI go nie widzi,
 * więc test nie twierdzi niczego o samych plikach PDF. Twierdzi, że w źródłach
 * nie ma z czego skłamać.
 *
 * URUCHOMIENIE:  php tests/wersja-spojnosc-test.php
 * Kod wyjścia: 0 = OK, 1 = błędy.
 *
 * @package AI_FAQ_Generator
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
function ws_check( $cond, $label ) {
	global $fail, $ran;
	++$ran;
	echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n";
	if ( ! $cond ) {
		++$fail;
	}
}

/**
 * Czyta plik albo kończy z błędem — cichy pusty napis spełniłby pół asercji.
 *
 * @param string $sciezka Ścieżka pliku.
 *
 * @return string
 */
function ws_plik( $sciezka ) {
	if ( ! is_file( $sciezka ) ) {
		echo "  FAIL brak pliku: {$sciezka}\n";
		exit( 1 );
	}
	return (string) file_get_contents( $sciezka );
}

// ---------------------------------------------------------------------------
// A. Cztery źródła numeru wersji muszą podawać TO SAMO.
// ---------------------------------------------------------------------------
echo "=== A. Cztery zrodla numeru wersji ===\n";

$glowny = ws_plik( $root . '/ai-faq-generator.php' );
$rt     = ws_plik( $root . '/readme.txt' );

preg_match( '/^\s*\*\s*Version:\s*([0-9.]+)\s*$/m', $glowny, $m_hdr );
preg_match( "/define\(\s*'AIFAQ_VERSION',\s*'([0-9.]+)'\s*\)/", $glowny, $m_const );

$wersja_hdr   = trim( $m_hdr[1] ?? '' );
$wersja_const = trim( $m_const[1] ?? '' );

ws_check( '' !== $wersja_hdr, 'naglowek wtyczki ma pole `Version`' );
ws_check( '' !== $wersja_const, 'plik glowny definiuje `AIFAQ_VERSION`' );
ws_check(
	1 === preg_match( '/^[0-9]+\.[0-9]+\.[0-9]+$/', $wersja_const ),
	"`AIFAQ_VERSION` ma postac X.Y.Z (jest: \"{$wersja_const}\")"
);
ws_check(
	'' !== $wersja_hdr && $wersja_hdr === $wersja_const,
	"`AIFAQ_VERSION` ({$wersja_const}) === `Version` z naglowka ({$wersja_hdr})"
);

preg_match( '/^Stable tag:\s*([0-9.]+)\s*$/m', $rt, $m_stable );
$stable = trim( $m_stable[1] ?? '' );
ws_check( '' !== $stable, 'readme.txt ma pole `Stable tag`' );
ws_check(
	'' !== $stable && $stable === $wersja_const,
	"readme.txt `Stable tag` ({$stable}) === `AIFAQ_VERSION` ({$wersja_const})"
);

// Najnowszy wpis changeloga to BIEZACA wersja — inaczej wydanie wyszloby bez wpisu.
preg_match( '/== Changelog ==(.*)/su', $rt, $m_ch );
preg_match( '/^=\s*([0-9.]+)\s*=$/m', $m_ch[1] ?? '', $m_first );
$naj = trim( $m_first[1] ?? '' );
ws_check( '' !== $naj, 'readme.txt ma sekcje `== Changelog ==` z wpisem wersji' );
ws_check(
	'' !== $naj && $naj === $wersja_const,
	"najnowszy wpis changeloga ({$naj}) === `AIFAQ_VERSION` ({$wersja_const})"
);

// ---------------------------------------------------------------------------
// B. Zrodla instrukcji nie moga miec wersji wpisanej w tresc.
// ---------------------------------------------------------------------------
echo "\n=== B. Zrodla instrukcji stoja na znaczniku, nie na literale ===\n";

$zrodla = glob( $root . '/instrukcje/zrodla/*.html' );
sort( $zrodla );

// Liczba plikow jest asercja sama w sobie: nowe zrodlo dolozone bez znacznika
// ma ZAPALIC straznika, a nie przemknac jako pominiecie.
ws_check( count( $zrodla ) >= 5, 'katalog `instrukcje/zrodla` ma komplet zrodel (jest: ' . count( $zrodla ) . ')' );

foreach ( $zrodla as $sciezka ) {
	$nazwa = basename( $sciezka );
	$tresc = ws_plik( $sciezka );

	ws_check(
		false !== strpos( $tresc, '{{WERSJA}}' ),
		"{$nazwa}: ma znacznik `{{WERSJA}}`"
	);

	// Kazdy literal X.Y.Z w zrodle to drugie zrodlo prawdy o wersji — dokladnie
	// ten uklad, ktory dwa razy wypuscil PDF-y z nieprawdziwym numerem.
	preg_match_all( '/[0-9]+\.[0-9]+\.[0-9]+/', $tresc, $m_lit );
	$literaly = array_unique( $m_lit[0] );
	ws_check(
		array() === $literaly,
		"{$nazwa}: zero literalow X.Y.Z w tresci" . ( array() === $literaly ? '' : ' (znalezione: ' . implode( ', ', $literaly ) . ')' )
	);

	// Sama biezaca wersja pytana OSOBNO — gdyby ktos wpisal ja w formie, ktorej
	// wzorzec wyzej nie lapie, to ramie i tak czerwienieje.
	ws_check(
		false === strpos( $tresc, $wersja_const ),
		"{$nazwa}: nie zawiera biezacego numeru {$wersja_const} jako tekstu"
	);
}

// ---------------------------------------------------------------------------
// C. Znacznik ma byc jedynym mechanizmem — nie moze zostac osierocony.
// ---------------------------------------------------------------------------
echo "\n=== C. Znacznik nie jest osierocony ===\n";

$z_znacznikiem = 0;
foreach ( $zrodla as $sciezka ) {
	if ( false !== strpos( (string) file_get_contents( $sciezka ), '{{WERSJA}}' ) ) {
		++$z_znacznikiem;
	}
}
ws_check(
	$z_znacznikiem === count( $zrodla ),
	"znacznik `{{WERSJA}}` jest w KAZDYM zrodle ({$z_znacznikiem} z " . count( $zrodla ) . ')'
);

// ---------------------------------------------------------------------------
// Z. Podłoga pokrycia i wartownik końca pliku.
// ---------------------------------------------------------------------------
echo "\n=== Z. Podloga pokrycia ===\n";
ws_check( $ran >= 24, 'wykonano komplet asercji (asercji: ' . $ran . ')' );
ws_check( true, 'plik dobiegl konca' );

echo "\n";
if ( 0 === $fail ) {
	echo "=== WSZYSTKIE OK (asercji: {$ran}) ===\n";
	exit( 0 );
}
echo "=== BLEDOW: {$fail} (asercji: {$ran}) ===\n";
exit( 1 );
