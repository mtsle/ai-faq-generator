<?php
/**
 * Strażnik harnessu obciążeniowego (`tests/load/`) — czysto STATYCZNY.
 *
 * PO CO ISTNIEJE: katalog `tests/load/` nie jest wołany przez żaden runner —
 * jego skrypty wymagają żywego MySQL-a, uruchomionego Local i wielu procesów OS,
 * więc siedem pozycji audytu (przebieg-2, faza F11) nie miało gdzie mieć
 * strażnika. Ten plik czyta te skrypty ZAMIAST je uruchamiać: zero bazy, zero
 * WordPressa, zero sieci. Wpięty jest jako segment do obu kopii runnera W1.
 *
 * OGRANICZENIE MÓWIONE WPROST: strażnik dowodzi, że gałąź obronna ISTNIEJE
 * i ma właściwy kształt — NIE że odpala się na żywym MySQL-u. Tam, gdzie tylko
 * to jest możliwe, pytanie idzie o KOD bez komentarzy (patrz `lh_kod()`),
 * bo asercja spełniona przez komentarz to fałszywa zieleń — w tym przebiegu
 * złapaliśmy taką dwa razy.
 *
 * URUCHOMIENIE:  php tests/load-harness-guard-test.php
 * Kod wyjścia: 0 = OK, 1 = błędy.
 *
 * @package AI_FAQ_Generator
 */

$root = dirname( __DIR__ );
$load = $root . '/tests/load';

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
function lh_check( $cond, $label ) {
	global $fail, $ran;
	++$ran;
	echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n";
	if ( ! $cond ) {
		++$fail;
	}
}

/**
 * Zwraca SAM KOD pliku — bez komentarzy i docbloków.
 *
 * @param string $sciezka Ścieżka pliku PHP.
 *
 * @return string
 */
function lh_kod( $sciezka ) {
	$src = (string) file_get_contents( $sciezka );
	$out = '';
	foreach ( token_get_all( $src ) as $t ) {
		if ( is_array( $t ) ) {
			if ( in_array( $t[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				$out .= "\n";
				continue;
			}
			$out .= $t[1];
			continue;
		}
		$out .= $t;
	}
	return $out;
}

// Kontrola samego narzędzia — bez niej `lh_kod()` zwracające pustkę dawałoby
// zieleń na asercjach negatywnych i czerwień na pozytywnych z innego powodu.
lh_check( is_dir( $load ), 'katalog tests/load istnieje' );
$plikow = glob( $load . '/*.php' );
lh_check( is_array( $plikow ) && count( $plikow ) >= 14, 'strażnik widzi skrypty harnessu (znalezionych: ' . count( (array) $plikow ) . ')' );

// ---------------------------------------------------------------------------
// A. RAU-R15-005 + UZUP-09 — bezpiecznik dobowej puli API (l7)
// ---------------------------------------------------------------------------
echo "\n=== A. Bezpiecznik puli API (l7-http-concurrency) ===\n";

$l7  = $load . '/l7-http-concurrency.php';
$l7k = lh_kod( $l7 );

// Blok bezpiecznika = od sondy do jej rozstrzygnięcia. Wycinamy go, bo dalej
// w pliku stoi pętla pomiarowa, która TE SAME warunki ma od zawsze — asercja
// na całym pliku byłaby spełniona przez nią i niczego by nie dowodziła.
$od_sondy = strpos( $l7k, '$probe' );
$do_petli = strpos( $l7k, '$levels' );
$blok_l7  = ( false !== $od_sondy && false !== $do_petli && $do_petli > $od_sondy )
	? substr( $l7k, $od_sondy, $do_petli - $od_sondy )
	: '';

lh_check( '' !== $blok_l7, 'l7: blok bezpiecznika daje sie wyciac (przed petla pomiarowa)' );
lh_check(
	false !== strpos( $blok_l7, "['http']" ) || false !== strpos( $blok_l7, '$probe_http' ),
	'l7: bezpiecznik CZYTA kod HTTP sondy, nie tylko jej czas'
);
// Wykryte wlasna mutacja: pytanie o samo wystapienie `$probe_err` spelnial jego
// PRZYPIS na gorze bloku — po wycieciu calej galezi asercja dalej przechodzila.
// Pytamy o WARUNEK, nie o obecnosc zmiennej.
lh_check(
	1 === preg_match( '/if\s*\(\s*.{2}\s*!==\s*\$probe_err\s*\)/', $blok_l7 ),
	'l7: bezpiecznik ma GALAZ na blad curla, nie samo odczytanie pola'
);
lh_check(
	false !== strpos( $blok_l7, '200 !==' ) || false !== strpos( $blok_l7, '!== $probe_http' ),
	'l7: kod inny niz 200 przerywa — szybka odpowiedz BLEDNA nie jest dowodem na atrape'
);
// Wykryte wlasna mutacja: `strpos( ..., '500' )` spelnia takze `500000`, wiec
// podniesienie progu tysiac razy przechodzilo. Pytamy o caly warunek.
lh_check(
	1 === preg_match( '/\$probe_ms\s*>\s*500\s*\)/', $blok_l7 ),
	'l7: prog czasu to dokladnie 500 ms (naprawa DOKLADA warunki, nie zamienia ich)'
);
// Przerwanie z ochrony budżetu nie może udawać sukcesu.
lh_check(
	false !== strpos( $blok_l7, 'exit( 1 )' ),
	'l7: przerwanie z ochrony budzetu konczy sie kodem 1, nie 0'
);
lh_check(
	false === strpos( $blok_l7, 'exit( 0 )' ),
	'l7: w bloku bezpiecznika nie zostalo wyjscie z kodem 0'
);

// ---------------------------------------------------------------------------
// B. RAU-R14-001 — shim L3 nie zajmuje nazw rdzenia bez osłony
// ---------------------------------------------------------------------------
echo "\n=== B. Shim stanu wspoldzielonego (l3-shared-state-shim) ===\n";

$shim  = $load . '/l3-shared-state-shim.php';
$shimk = lh_kod( $shim );

$nazwy_rdzenia = array(
	'get_transient',
	'set_transient',
	'delete_transient',
	'get_option',
	'update_option',
	'add_option',
	'delete_option',
);

$bez_oslony = array();
foreach ( $nazwy_rdzenia as $nazwa ) {
	$poz = strpos( $shimk, 'function ' . $nazwa . '(' );
	if ( false === $poz ) {
		$bez_oslony[] = $nazwa . ' (brak deklaracji)';
		continue;
	}
	// Osłona musi stać PRZED deklaracją i dotyczyć TEJ nazwy.
	$przed = substr( $shimk, 0, $poz );
	if ( false === strpos( $przed, "function_exists( '" . $nazwa . "' )" ) ) {
		$bez_oslony[] = $nazwa;
	}
}
lh_check(
	0 === count( $bez_oslony ),
	'l3-shim: KAZDA deklaracja nazwy rdzenia stoi pod function_exists'
		. ( $bez_oslony ? ( ' -> ' . implode( ', ', $bez_oslony ) ) : ' (' . count( $nazwy_rdzenia ) . '/' . count( $nazwy_rdzenia ) . ')' )
);

// Nazwy własne shimu muszą nosić prefiks — inaczej kolidują tak samo.
lh_check(
	false === strpos( $shimk, 'function real_get_option(' ),
	'l3-shim: znikla globalna nazwa `real_get_option` bez prefiksu wtyczki'
);
lh_check(
	false !== strpos( $shimk, 'function aifaq_lt_real_get_option(' ),
	'l3-shim: jej nastepczyni ma prefiks aifaq_lt_'
);
// Wywołujący muszą iść za zmianą — inaczej scenariusz pada na nieznanej funkcji.
$osieroceni = array();
foreach ( (array) $plikow as $plik ) {
	$kod = lh_kod( $plik );
	if ( false !== strpos( $kod, 'real_get_option(' ) && false === strpos( $kod, 'aifaq_lt_real_get_option(' ) ) {
		$osieroceni[] = basename( $plik );
	}
}
lh_check( 0 === count( $osieroceni ), 'l3-shim: zaden scenariusz nie wola juz starej nazwy' . ( $osieroceni ? ( ' -> ' . implode( ',', $osieroceni ) ) : '' ) );

// Podwójne załadowanie tego samego pliku ma być niemożliwe.
$przez_require = array();
foreach ( (array) $plikow as $plik ) {
	$kod = lh_kod( $plik );
	if ( 1 === preg_match( '/(?<!_once)\s+require\s+__DIR__\s*\.\s*.\/l3-shared-state-shim\.php./', $kod ) ) {
		$przez_require[] = basename( $plik );
	}
}
lh_check(
	0 === count( $przez_require ),
	'l3-shim: scenariusze ladują go przez require_once' . ( $przez_require ? ( ' -> ' . implode( ',', $przez_require ) ) : '' )
);

// ---------------------------------------------------------------------------
// C. RAU-R14-002 — adapter RealWpdb spełnia kontrakt, który deklaruje
// ---------------------------------------------------------------------------
echo "\n=== C. Adapter RealWpdb (l5-db-scale) ===\n";

$l5  = $load . '/l5-db-scale.php';
$l5k = lh_kod( $l5 );

// Zbiór składowych `$wpdb` UŻYWANYCH przez klasy, które ten skrypt ładuje.
// Liczony z kodu, nie przepisany: dopisanie nowego użycia w src/Data od razu
// podnosi wymaganie wobec adaptera.
$uzywane = array();
$it_data = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/src/Data', FilesystemIterator::SKIP_DOTS ) );
foreach ( $it_data as $f ) {
	if ( 'php' !== strtolower( $f->getExtension() ) ) {
		continue;
	}
	if ( preg_match_all( '/\$wpdb->([a-z_]+)/', lh_kod( $f->getPathname() ), $m ) ) {
		foreach ( $m[1] as $skladowa ) {
			$uzywane[ $skladowa ] = true;
		}
	}
}
lh_check( count( $uzywane ) >= 10, 'l5: skaner widzi skladowe $wpdb uzywane w src/Data (znalezionych: ' . count( $uzywane ) . ')' );

$brakujace = array();
foreach ( array_keys( $uzywane ) as $skladowa ) {
	$ma_metode = false !== strpos( $l5k, 'function ' . $skladowa . '(' );
	$ma_pole   = 1 === preg_match( '/public\s+\$' . preg_quote( $skladowa, '/' ) . '\b/', $l5k );
	if ( ! $ma_metode && ! $ma_pole ) {
		$brakujace[] = $skladowa;
	}
}
lh_check(
	0 === count( $brakujace ),
	'l5: adapter ma KAZDA skladowa $wpdb uzywana w src/Data'
		. ( $brakujace ? ( ' -> brakuje: ' . implode( ', ', $brakujace ) ) : ' (' . count( $uzywane ) . '/' . count( $uzywane ) . ')' )
);
// Pole `posts` musi dostać wartość — sama deklaracja to obietnica bez pokrycia.
lh_check(
	1 === preg_match( '/\$this->posts\s*=/', $l5k ),
	'l5: pole `posts` dostaje wartosc w konstruktorze, nie jest sama deklaracja'
);

// ---------------------------------------------------------------------------
// D. RAU-R14-003 — ta sama tabela, ten sam schemat w obu modułach
// ---------------------------------------------------------------------------
echo "\n=== D. Kolizja nazwy tabeli (l5 kontra l6) ===\n";

$l6  = $load . '/l6-generator-concurrency.php';
$l6k = lh_kod( $l6 );

/**
 * Wyciąga zbiór definicji kluczy z bloku `CREATE TABLE` danej tabeli.
 *
 * Blok wskazujemy KOLUMNA-ZNACZNIKIEM, nie nazwa tabeli: oba pliki trzymaja
 * nazwe w zmiennej (`{$T}` kontra `{$T_GENERATIONS}`), wiec szukanie po nazwie
 * znajdowaloby ja tylko w jednym z nich i asercja byla by pusta.
 *
 * @param string $kod      Kod pliku.
 * @param string $znacznik Kolumna wystepujaca WYLACZNIE w tej tabeli.
 *
 * @return array Posortowana lista definicji `KEY ...` / `PRIMARY KEY ...`.
 */
function lh_klucze( $kod, $znacznik ) {
	$poz_kol = strpos( $kod, $znacznik );
	if ( false === $poz_kol ) {
		return array();
	}
	$poz = strrpos( substr( $kod, 0, $poz_kol ), 'CREATE TABLE ' );
	if ( false === $poz ) {
		return array();
	}
	$blok = substr( $kod, $poz );
	$stop = strpos( $blok, 'ENGINE=' );
	$blok = ( false === $stop ) ? $blok : substr( $blok, 0, $stop );

	$klucze = array();
	if ( preg_match_all( '/((?:PRIMARY\s+)?KEY\s+[a-z_]*\s*\([^)]*\))/i', $blok, $m ) ) {
		foreach ( $m[1] as $k ) {
			$klucze[] = preg_replace( '/\s+/', ' ', trim( $k ) );
		}
	}
	sort( $klucze );
	return $klucze;
}

// Obie tabele noszą TĘ SAMĄ fizyczną nazwę — to źródło kolizji, więc pytamy o nią
// wprost. W l5 nazwa jest SKLEJANA z prefiksu, więc pełny literał tam nie pada:
// składamy ją tak, jak robi to kod, zamiast szukać gotowego napisu.
$nazwa_l5 = '';
if ( preg_match( '/\$PFX\s*=\s*.([a-z0-9_]+)./', $l5k, $m_pfx )
	&& preg_match( '/\$T_GENERATIONS\s*=\s*\$PFX\s*\.\s*.([a-z0-9_]+)./', $l5k, $m_gen ) ) {
	$nazwa_l5 = $m_pfx[1] . $m_gen[1];
}
$nazwa_l6 = '';
if ( preg_match( '/\$T\s*=\s*.([a-z0-9_]+)./', $l6k, $m_t ) ) {
	$nazwa_l6 = $m_t[1];
}
lh_check( '' !== $nazwa_l5 && '' !== $nazwa_l6, 'l5/l6: nazwy tabel daja sie odczytac z kodu (l5: ' . $nazwa_l5 . ', l6: ' . $nazwa_l6 . ')' );
lh_check(
	$nazwa_l5 === $nazwa_l6,
	'l5 i l6 nadal siegaja po TE SAMA fizyczna tabele — bez tego reszta sekcji nie ma sensu (l5: ' . $nazwa_l5 . ', l6: ' . $nazwa_l6 . ')'
);

$klucze_l5 = lh_klucze( $l5k, 'pairs_json longtext NULL' );
$klucze_l6 = lh_klucze( $l6k, 'pairs_json longtext NULL' );

lh_check( count( $klucze_l5 ) >= 3, 'l5: odczytano definicje kluczy tabeli generations (jest: ' . count( $klucze_l5 ) . ')' );
lh_check( count( $klucze_l6 ) >= 3, 'l6: odczytano definicje kluczy tej samej tabeli (jest: ' . count( $klucze_l6 ) . ')' );
lh_check(
	$klucze_l5 === $klucze_l6,
	'l5 i l6 definiuja TE SAMA tabele IDENTYCZNIE'
		. ( $klucze_l5 === $klucze_l6 ? '' : ' -> l5: [' . implode( ' ; ', $klucze_l5 ) . '] l6: [' . implode( ' ; ', $klucze_l6 ) . ']' )
);

// Indeks złożony musi zgadzać się z produkcyjnym Schema.php — to on decyduje,
// czy pomiar dotyczy struktury, którą klient naprawdę dostaje.
$schema = lh_kod( $root . '/src/Data/Schema.php' );
lh_check(
	false !== strpos( $schema, 'KEY created_id (created_at,id)' ),
	'produkcja: Schema.php deklaruje indeks zlozony created_id'
);
lh_check(
	false !== strpos( $l5k, 'KEY created_id (created_at,id)' ),
	'l5: harness ma ten sam indeks — inaczej mierzy strukture, ktorej produkcja nie ma'
);

// ---------------------------------------------------------------------------
// E. RAU-R15-006 — worker L6 melduje awarię, nie pustkę
// ---------------------------------------------------------------------------
echo "\n=== E. Worker zapisu (l6-history-write-worker) ===\n";

$w6  = $load . '/l6-history-write-worker.php';
$w6k = lh_kod( $w6 );

lh_check(
	1 === preg_match( '/if\s*\(\s*!\s*\$mysqli\s*\)/', $w6k ),
	'l6-worker: bada wynik mysqli_connect()'
);
lh_check(
	false !== strpos( $w6k, 'mysqli_connect_error()' ),
	'l6-worker: i podaje powod, nie samo „nie udalo sie"'
);
lh_check(
	1 === preg_match( '/false\s*===\s*\$stmt/', $w6k ),
	'l6-worker: bada wynik prepare()'
);
lh_check(
	1 === preg_match( '/if\s*\(\s*!\s*\$stmt->execute\(\s*\)\s*\)/', $w6k ),
	'l6-worker: bada takze wynik execute() — INSERT moze paść po udanym prepare'
);
// Nadrzędny czyta WYŁĄCZNIE stdout, więc sygnał awarii musi tam trafić.
lh_check(
	false !== strpos( $w6k, "'BLAD:'" ),
	'l6-worker: znacznik awarii idzie na STDOUT (nadrzedny nie czyta stderr)'
);
lh_check(
	false !== strpos( $w6k, 'exit( 1 )' ),
	'l6-worker: awaria konczy sie kodem 1'
);
// Kontrola pozytywna: plik nadrzędny miał tę gałąź od zawsze i ma ją nadal.
lh_check(
	1 === preg_match( '/if\s*\(\s*!\s*\$mysqli\s*\)/', $l6k ),
	'l6: plik nadrzedny nadal ma swoja galaz braku polaczenia (kontrola pozytywna)'
);

// ---------------------------------------------------------------------------
// F. RAU-R14-005 — opis mechanizmu zgodny z tym, co w pliku stoi
// ---------------------------------------------------------------------------
echo "\n=== F. Akumulator czasu bazy (l4-ask-worker) ===\n";

$l4      = $load . '/l4-ask-worker.php';
$l4_src  = (string) file_get_contents( $l4 );
$l4_kod  = lh_kod( $l4 );

// Mechanizm faktyczny: referencja do pola atrapy, w TRZECH miejscach.
$przypisan = preg_match_all( '/->time_ref\s*=\s*&\$TIME;/', $l4_kod );
lh_check( 3 === $przypisan, 'l4: akumulator wpinany referencja do pola atrapy w 3 miejscach (jest: ' . (int) $przypisan . ')' );
lh_check(
	0 === preg_match( '/function\s*\(/', $l4_kod ) && 0 === preg_match( '/fn\s*\(/', $l4_kod ),
	'l4: w pliku NIE MA funkcji anonimowej — wiec nie ma tez domkniecia'
);

// Opis nie może twierdzić czegoś przeciwnego.
lh_check(
	0 === preg_match( '/use\s*\(\s*&\$TIME\s*\)/', $l4_src ),
	'l4: opis NIE mowi juz o domknieciu `use ( &$TIME )` — mechanizmu takiego tu nie ma'
);
lh_check(
	false !== strpos( $l4_src, 'time_ref' ),
	'l4: opis nazywa mechanizm po imieniu (`time_ref`)'
);

// ---------------------------------------------------------------------------
// Z. Podłoga pokrycia i wartownik końca pliku.
// ---------------------------------------------------------------------------
echo "\n=== Z. Podloga pokrycia ===\n";
lh_check( $ran >= 30, 'wykonano komplet asercji (asercji: ' . $ran . ')' );
lh_check( true, 'plik dobiegl konca' );

echo "\n";
if ( 0 === $fail ) {
	echo "=== WSZYSTKIE OK (asercji: {$ran}) ===\n";
	exit( 0 );
}
echo "=== BLEDOW: {$fail} (asercji: {$ran}) ===\n";
exit( 1 );
