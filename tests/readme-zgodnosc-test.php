<?php
/**
 * Strażnik zgodności README repozytorium z kodem — czysto STATYCZNY.
 *
 * PO CO ISTNIEJE: README korzenia opisuje OBIE wtyczki i sypie liczbami — ile jest
 * zestawów testów, ile asercji, ile tras REST, ile plików w którym katalogu. Do tej
 * pory nie pilnowało ich NIC. Wtyczka 2 ma taką bramkę od Kroku 8
 * (`ai-news-portal/tests/etap85-readme-test.php`), ale ona czyta README WTYCZKI 2 —
 * README korzenia było poza jej zasięgiem. Ten plik wyrównuje asymetrię.
 *
 * Rozjazd nie jest hipotetyczny: przy wydaniu 1.1.0 tabela CI w README podawała
 * 60 zestawów, choć naprawy dołożyły segment już wcześniej, a wiersz wtyczki 2 mówił
 * 2083 asercje zamiast 2306. Obie liczby były nieprawdziwe i nikt tego nie widział,
 * bo README nikt nie liczył — czytano je.
 *
 * JAK DZIAŁA: każda asercja WYCIĄGA liczbę albo nazwę z README i zderza ją z wartością
 * POLICZONĄ ZE ŹRÓDEŁ. Nie ma tu przepisanej listy oczekiwań — zmiana w kodzie i zmiana
 * w README muszą się spotkać, inaczej test pada.
 *
 * OGRANICZENIE MÓWIONE WPROST: strażnik sprawdza twierdzenia POLICZALNE. Nie oceni, czy
 * zdanie opisowe jest prawdziwe — od tego jest czytelnik.
 *
 * URUCHOMIENIE:  php tests/readme-zgodnosc-test.php
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
function rz_check( $cond, $label ) {
	global $fail, $ran;
	++$ran;
	echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n";
	if ( ! $cond ) {
		++$fail;
	}
}

/**
 * Czyta plik albo kończy z błędem — pusty napis spełniłby pół asercji po cichu.
 *
 * @param string $sciezka Ścieżka.
 *
 * @return string
 */
function rz_plik( $sciezka ) {
	if ( ! is_file( $sciezka ) ) {
		echo "  FAIL brak pliku: {$sciezka}\n";
		exit( 1 );
	}
	return (string) file_get_contents( $sciezka );
}

/**
 * Liczy pliki w katalogu (rekurencyjnie), opcjonalnie po wzorcu nazwy.
 *
 * @param string $katalog Katalog.
 * @param string $wzorzec Wyrażenie regularne na nazwę pliku; pusty = wszystkie.
 *
 * @return int
 */
function rz_ile_plikow( $katalog, $wzorzec = '' ) {
	if ( ! is_dir( $katalog ) ) {
		return -1;
	}
	$ile = 0;
	$it  = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $katalog, FilesystemIterator::SKIP_DOTS )
	);
	foreach ( $it as $plik ) {
		if ( ! $plik->isFile() ) {
			continue;
		}
		if ( '' !== $wzorzec && 1 !== preg_match( $wzorzec, $plik->getFilename() ) ) {
			continue;
		}
		++$ile;
	}
	return $ile;
}

/**
 * Wyciąga z README pierwszą grupę wyrażenia. Brak trafienia = null, NIE pusty napis:
 * inaczej „nie znalazłem" udawałoby „znalazłem zero" i asercja byłaby pusta.
 *
 * @param string $tresc   Treść README.
 * @param string $wzorzec Wyrażenie z jedną grupą.
 *
 * @return string|null
 */
function rz_z_readme( $tresc, $wzorzec ) {
	if ( 1 !== preg_match( $wzorzec, $tresc, $m ) ) {
		return null;
	}
	return $m[1];
}

/**
 * Zderza liczbę z README z wartością policzoną ze źródeł.
 *
 * @param string   $tresc     Treść README.
 * @param string   $wzorzec   Wyrażenie z jedną grupą liczbową.
 * @param int      $policzone Wartość policzona.
 * @param string   $opis      Opis asercji.
 *
 * @return void
 */
function rz_liczba( $tresc, $wzorzec, $policzone, $opis ) {
	$z_readme = rz_z_readme( $tresc, $wzorzec );
	if ( null === $z_readme ) {
		rz_check( false, $opis . ' — NIE ZNALEZIONO tego twierdzenia w README (wzorzec: ' . $wzorzec . ')' );
		return;
	}
	rz_check(
		(int) $z_readme === $policzone,
		$opis . ': README mowi ' . $z_readme . ', policzone ze zrodel ' . $policzone
	);
}

$readme = rz_plik( $root . '/README.md' );

// ---------------------------------------------------------------------------
// A. Wersje i wymagania — README kontra nagłówki obu wtyczek.
// ---------------------------------------------------------------------------
echo "=== A. Wersje i wymagania ===\n";

$w1_glowny = rz_plik( $root . '/ai-faq-generator.php' );
$w2_glowny = rz_plik( $root . '/ai-news-portal/ai-news-portal.php' );

/**
 * Czyta pole z nagłówka wtyczki.
 *
 * @param string $src  Treść pliku głównego.
 * @param string $pole Nazwa pola.
 *
 * @return string
 */
function rz_naglowek( $src, $pole ) {
	if ( 1 !== preg_match( '/^\s*\*\s*' . preg_quote( $pole, '/' ) . ':\s*(.+?)\s*$/m', $src, $m ) ) {
		return '';
	}
	return $m[1];
}

$w1_wersja = rz_naglowek( $w1_glowny, 'Version' );
$w2_wersja = rz_naglowek( $w2_glowny, 'Version' );

// Wiersz tabeli „Stan" dla każdej wtyczki. Numer wersji stoi w trzeciej kolumnie.
$w1_wiersz = rz_z_readme( $readme, '/\|\s*\*\*AI FAQ Generator\*\*\s*\|[^|]*\|\s*([0-9.]+)[^|]*\|/' );
$w2_wiersz = rz_z_readme( $readme, '/\|\s*\*\*AI News Portal\*\*\s*\|[^|]*\|\s*([0-9.]+)[^|]*\|/' );

rz_check( '' !== $w1_wersja, 'naglowek wtyczki 1 ma pole `Version` (' . $w1_wersja . ')' );
rz_check( '' !== $w2_wersja, 'naglowek wtyczki 2 ma pole `Version` (' . $w2_wersja . ')' );
rz_check(
	null !== $w1_wiersz && $w1_wiersz === $w1_wersja,
	'README tabela „Stan": wtyczka 1 = ' . ( $w1_wiersz ?? 'BRAK' ) . ' === naglowek ' . $w1_wersja
);
rz_check(
	null !== $w2_wiersz && $w2_wiersz === $w2_wersja,
	'README tabela „Stan": wtyczka 2 = ' . ( $w2_wiersz ?? 'BRAK' ) . ' === naglowek ' . $w2_wersja
);

// Wymagania: README podaje je w tej samej tabeli, w kolumnach WordPress i PHP.
foreach (
	array(
		array( 'AI FAQ Generator', $w1_glowny ),
		array( 'AI News Portal', $w2_glowny ),
	) as $para
) {
	list( $nazwa, $src ) = $para;
	$wp_kod  = rz_naglowek( $src, 'Requires at least' );
	$php_kod = rz_naglowek( $src, 'Requires PHP' );
	$wiersz  = rz_z_readme( $readme, '/\|\s*\*\*' . preg_quote( $nazwa, '/' ) . '\*\*\s*\|([^\n]*)/' );
	rz_check(
		null !== $wiersz && false !== strpos( $wiersz, '≥ ' . $wp_kod ) && false !== strpos( $wiersz, '≥ ' . $php_kod ),
		$nazwa . ': README podaje WordPress ≥ ' . $wp_kod . ' i PHP ≥ ' . $php_kod . ' (z naglowka wtyczki)'
	);
}

// ---------------------------------------------------------------------------
// B. Liczby testów — README kontra runnery i katalogi zestawów.
// ---------------------------------------------------------------------------
echo "\n=== B. Liczby testow ===\n";

$runner1 = rz_plik( $root . '/.github/ci/testy-wtyczka1.sh' );
$runner2 = rz_plik( $root . '/.github/ci/testy-wtyczka2.sh' );

/**
 * Wycina tablicę `segments=( … )` z runnera i zwraca jej wiersze.
 *
 * @param string $src Treść runnera.
 *
 * @return string[]
 */
function rz_segmenty( $src ) {
	if ( 1 !== preg_match( '/segments=\((.*?)\n\)/s', $src, $m ) ) {
		return array();
	}
	$out = array();
	foreach ( explode( "\n", $m[1] ) as $linia ) {
		if ( false !== strpos( $linia, '|' ) ) {
			$out[] = $linia;
		}
	}
	return $out;
}

$seg1 = rz_segmenty( $runner1 );
$seg2 = rz_segmenty( $runner2 );

// Rezolwer musi mieć własną kontrolę poprawności — pusta tablica udawałaby „zero segmentow".
rz_check( count( $seg1 ) > 0, 'rezolwer widzi tablice segmentow runnera W1 (znalezionych: ' . count( $seg1 ) . ')' );
rz_check( count( $seg2 ) > 0, 'rezolwer widzi tablice segmentow runnera W2 (znalezionych: ' . count( $seg2 ) . ')' );

$zestawy1 = rz_ile_plikow( $root . '/tests', '/-test\.php$/' );
$zestawy2 = rz_ile_plikow( $root . '/ai-news-portal/tests', '/-test\.php$/' );

rz_liczba( $readme, '/\*\*(\d+)\*\* w \d+ segmentach\s*\|/', $zestawy1, 'zestawy wtyczki 1' );
rz_liczba( $readme, '/\*\*\d+\*\* w (\d+) segmentach/', count( $seg1 ), 'segmenty wtyczki 1' );
rz_liczba( $readme, '/\|\s*\*\*(\d+)\*\* w \d+ segmentach\s*\|\s*$/m', $zestawy2, 'zestawy wtyczki 2' );

// Suma asercji zadeklarowana w tabeli segmentow runnera W2 — to ona jest kryterium przebiegu.
$suma_asercji = 0;
foreach ( $seg2 as $linia ) {
	if ( preg_match_all( '/:(\d+)/', $linia, $m ) ) {
		foreach ( $m[1] as $n ) {
			$suma_asercji += (int) $n;
		}
	}
}
rz_check( $suma_asercji > 0, 'rezolwer zsumowal asercje z runnera W2 (suma: ' . $suma_asercji . ')' );
rz_liczba( $readme, '/\*\*(\d+)\*\*, liczone co do jednej/', $suma_asercji, 'asercje wtyczki 2 (tabela „Testy i straznicy")' );
rz_liczba( $readme, '/dok\x{0142}adnie (\d+) asercji\*\*/u', $suma_asercji, 'asercje wtyczki 2 (tabela CI)' );
rz_liczba( $readme, '/\*\*(\d+) zestaw[a-zóy]*, 0 niezaliczonych\*\*/u', $zestawy1, 'zestawy wtyczki 1 (tabela CI)' );
rz_liczba( $readme, '/\*\*(\d+) segment\x{00f3}w, \d+ zestaw\x{00f3}w/u', count( $seg2 ), 'segmenty wtyczki 2 (tabela CI)' );
rz_liczba( $readme, '/\*\*\d+ segment\x{00f3}w, (\d+) zestaw\x{00f3}w/u', $zestawy2, 'zestawy wtyczki 2 (tabela CI)' );

// ---------------------------------------------------------------------------
// C. Drzewo katalogów — każda liczba w sekcji „Gdzie co leży".
// ---------------------------------------------------------------------------
echo "\n=== C. Drzewo katalogow ===\n";

rz_liczba( $readme, '/src\/\s+(\d+) pliki/u', rz_ile_plikow( $root . '/src', '/\.php$/' ), 'src/ wtyczki 1 (pliki .php)' );
rz_liczba( $readme, '/tests\/\s+(\d+) zestawy/u', $zestawy1, 'tests/ wtyczki 1 (zestawy)' );
rz_liczba( $readme, '/tests\/load\/ \((\d+) skrypt/u', rz_ile_plikow( $root . '/tests/load', '/\.php$/' ), 'tests/load/ (skrypty)' );
rz_liczba( $readme, '/assets\/\s+(\d+) plik/u', rz_ile_plikow( $root . '/assets' ), 'assets/ wtyczki 1' );
rz_liczba( $readme, '/instrukcje\/\s+(\d+) plik/u', rz_ile_plikow( $root . '/instrukcje' ), 'instrukcje/ wtyczki 1' );
rz_liczba( $readme, '/audyt\/\s+(\d+) plik/u', rz_ile_plikow( $root . '/audyt' ), 'audyt/' );
rz_liczba( $readme, '/wtyczka 2 \x{2014} (\d+) plik/u', rz_ile_plikow( $root . '/ai-news-portal' ), 'ai-news-portal/ razem' );
rz_liczba( $readme, '/\n  src\/\s+(\d+)\s+\d+ klas/u', rz_ile_plikow( $root . '/ai-news-portal/src' ), 'ai-news-portal/src/' );
rz_liczba( $readme, '/\n  tests\/\s+(\d+)\s+\d+ zestaw/u', rz_ile_plikow( $root . '/ai-news-portal/tests' ), 'ai-news-portal/tests/ (pliki)' );
rz_liczba( $readme, '/\n  tests\/\s+\d+\s+(\d+) zestaw/u', $zestawy2, 'ai-news-portal/tests/ (zestawy)' );
rz_liczba( $readme, '/\n  instrukcje\/\s+(\d+)\s+\d+ PDF/u', rz_ile_plikow( $root . '/ai-news-portal/instrukcje' ), 'ai-news-portal/instrukcje/' );
rz_liczba( $readme, '/\n  assets\/\s+(\d+)\s*\n/u', rz_ile_plikow( $root . '/ai-news-portal/assets' ), 'ai-news-portal/assets/' );

// Podkatalogi `src/` wtyczki 1 — liczby stoją w drzewie obok nazwy katalogu.
foreach ( array( 'Admin', 'Index', 'Rest', 'Data', 'PublicUi', 'Core', 'Rag', 'App', 'Providers', 'Faq', 'Http', 'Seo' ) as $pod ) {
	rz_liczba(
		$readme,
		'/\n    ' . $pod . '\/\s+(\d+)\s/u',
		rz_ile_plikow( $root . '/src/' . $pod, '/\.php$/' ),
		'src/' . $pod . '/'
	);
}

// ---------------------------------------------------------------------------
// D. Fakty produktowe — nazwy i liczby, które README podaje wprost.
// ---------------------------------------------------------------------------
echo "\n=== D. Fakty produktowe ===\n";

$tras = 0;
foreach ( glob( $root . '/src/Rest/*.php' ) as $plik ) {
	$tras += (int) preg_match_all( '/register_rest_route\(/', (string) file_get_contents( $plik ) );
}
rz_check( $tras > 0, 'rezolwer widzi rejestracje tras REST (znalezionych: ' . $tras . ')' );
rz_liczba( $readme, '/`aifaq\/v1` \x{2014} (\d+) tras/u', $tras, 'liczba tras REST wtyczki 1' );

// Tabele wtyczki 1: README wymienia je z nazwy, Schema.php je tworzy.
$schema = rz_plik( $root . '/src/Data/Schema.php' );
$w_kodzie = array();
if ( preg_match_all( '/CREATE TABLE \{\$(\w+)\}/', $schema, $m ) ) {
	$w_kodzie = $m[1];
}
rz_check( count( $w_kodzie ) > 0, 'rezolwer widzi instrukcje CREATE TABLE (znalezionych: ' . count( $w_kodzie ) . ')' );
rz_liczba( $readme, '/Tabele w bazie \| (\d+): `knowledge`/u', count( $w_kodzie ), 'liczba tabel wtyczki 1' );
foreach ( array( 'knowledge', 'qa_log', 'cache', 'faq', 'generations' ) as $tabela ) {
	rz_check(
		in_array( $tabela, $w_kodzie, true ),
		'tabela `' . $tabela . '` wymieniona w README naprawde powstaje w Schema.php'
	);
}

// Adresy i zadanie cykliczne — README podaje je jako konkret, więc muszą stać w kodzie.
$portal = rz_plik( $root . '/ai-news-portal/src/Portal.php' );
$plugin2 = rz_plik( $root . '/ai-news-portal/src/Plugin.php' );
rz_check(
	false !== strpos( $readme, '/centrum-wiedzy/' ) && false !== strpos( $portal . $plugin2, 'centrum-wiedzy' ),
	'adres frontu wtyczki 2 (`/centrum-wiedzy/`) stoi i w README, i w kodzie'
);
rz_check(
	false !== strpos( $readme, '`ainp_tick`' ) && false !== strpos( $plugin2, 'ainp_tick' ),
	'nazwa zadania cyklicznego (`ainp_tick`) stoi i w README, i w kodzie'
);
rz_check(
	false !== strpos( $readme, '`/faqgenerator`' ) && false !== strpos( $w1_glowny . rz_plik( $root . '/src/Core/Plugin.php' ), 'faqgenerator' ),
	'adres podstrony wtyczki 1 (`/faqgenerator`) stoi i w README, i w kodzie'
);
rz_check(
	false !== strpos( $readme, '`ainp_items`' )
		&& false !== strpos( rz_plik( $root . '/ai-news-portal/uninstall.php' ), 'ainp_items' ),
	'nazwa tabeli wtyczki 2 (`ainp_items`) stoi i w README, i w kodzie'
);

// ---------------------------------------------------------------------------
// E. Odsyłacze — README nie może wskazywać plików, których nie ma.
// ---------------------------------------------------------------------------
echo "\n=== E. Odsylacze ===\n";

$martwe = array();
if ( preg_match_all( '/\]\(([^)#:]+?)\)/', $readme, $m ) ) {
	foreach ( array_unique( $m[1] ) as $cel ) {
		$cel = trim( $cel );
		if ( '' === $cel || 0 === strpos( $cel, 'http' ) || 0 === strpos( $cel, '#' ) ) {
			continue;
		}
		if ( ! file_exists( $root . '/' . rtrim( $cel, '/' ) ) ) {
			$martwe[] = $cel;
		}
	}
}
rz_check( 0 === count( $martwe ), 'zero martwych odsylaczy w README' . ( $martwe ? ' (martwe: ' . implode( ', ', $martwe ) . ')' : '' ) );
rz_check( is_file( $root . '/CONTRIBUTING.md' ), 'CONTRIBUTING.md istnieje' );

// CONTRIBUTING podaje JEDNĄ liczbę — ile zestawów ma własną podłogę pokrycia w środku
// pliku. Liczba bez strażnika skłamie tak samo jak każda inna, więc liczymy ją ze źródeł.
$z_podloga = 0;
foreach ( glob( $root . '/tests/*.php' ) as $plik_t ) {
	if ( 1 === preg_match( '/\$(?:ran|floor) >= \d+/', (string) file_get_contents( $plik_t ) ) ) {
		++$z_podloga;
	}
}
rz_check( $z_podloga > 0, 'rezolwer widzi podlogi pokrycia (znalezionych: ' . $z_podloga . ')' );
rz_liczba(
	rz_plik( $root . '/CONTRIBUTING.md' ),
	'/\*\*(\d+) zestaw\S* wtyczki 1 maj/u',
	$z_podloga,
	'CONTRIBUTING: zestawy z wlasna podloga pokrycia'
);
rz_check( false !== strpos( $readme, 'CONTRIBUTING.md' ), 'README wskazuje CONTRIBUTING.md' );

// ---------------------------------------------------------------------------
// Z. Podłoga pokrycia i wartownik końca pliku.
// ---------------------------------------------------------------------------
echo "\n=== Z. Podloga pokrycia ===\n";
rz_check( $ran >= 45, 'wykonano komplet asercji (asercji: ' . $ran . ')' );
rz_check( true, 'plik dobiegl konca' );

echo "\n";
if ( 0 === $fail ) {
	echo "=== WSZYSTKIE OK (asercji: {$ran}) ===\n";
	exit( 0 );
}
echo "=== BLEDOW: {$fail} (asercji: {$ran}) ===\n";
exit( 1 );
