<?php
/**
 * Testy Kroku 17 — BoilerplateFilter (KONTRAKT k17-v3, §3.5).
 *
 * Pisane W CIEMNO: kod produkcyjny Etapów 1–3 powstaje równolegle i w chwili pisania
 * tego pliku mógł jeszcze nie istnieć. Asercje pochodzą WYŁĄCZNIE z kontraktu —
 * rozbieżność test↔implementacja jest dowodem, że kontrakt był nieprecyzyjny.
 *
 * Sedno §3.5: balast NIE jest kasowany, tylko PRZENOSZONY do dokumentu „informacje
 * ogólne o witrynie" (sink). Godziny otwarcia i adres mają zostać odpowiadalne —
 * ale raz, a nie 35×. Dlatego każda asercja liczy WYSTĄPIENIA linii (`=== N`),
 * nigdy „czy zniknęła".
 *
 * Licznik podłogowy na końcu pliku: bez niego plik z pominiętą sekcją (brak klasy)
 * raportuje zielono, wykonawszy zero asercji.
 *
 * URUCHOMIENIE:  php -d extension=mbstring tests/krok17-boilerplate-test.php
 * Kod wyjścia: 0 = OK, 1 = błędy.
 *
 * @package AI_FAQ_Generator
 */

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
if ( ! defined( 'AIFAQ_VERSION' ) ) { define( 'AIFAQ_VERSION', '0.20.0-test' ); }

// ---------------------------------------------------------------------------
// Shimy WP (BoilerplateFilter woła je tylko przy tworzeniu dokumentu-sinka).
// ---------------------------------------------------------------------------
$GLOBALS['__opt'] = array();

if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'get_bloginfo' ) ) { function get_bloginfo( $show = 'name' ) { return 'Przedszkole Testowe'; } }
if ( ! function_exists( 'home_url' ) ) { function home_url( $path = '/' ) { return 'https://example.test' . $path; } }
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = false ) {
		return array_key_exists( $key, $GLOBALS['__opt'] ) ? $GLOBALS['__opt'][ $key ] : $default;
	}
}

$fail = 0;
$ran  = 0;
function check( $cond, $label ) {
	global $fail, $ran;
	++$ran;
	echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n";
	if ( ! $cond ) { $fail++; }
}

$bp_file = __DIR__ . '/../src/Index/BoilerplateFilter.php';
if ( is_file( $bp_file ) ) { require $bp_file; }

// ---------------------------------------------------------------------------
// Korpusy i pomocniki.
// ---------------------------------------------------------------------------
define( 'BP_HEADER', 'Menu Start Oferta Kontakt' );          // 25 znaków.
define( 'BP_RARE', 'Zapisy na rok 2027 już trwają' );
define( 'BP_STOPKA', 'Copyright 2024 Przedszkole' );

/** Buduje dokument w kształcie §2.1. */
function bp_doc( $id, array $lines ) {
	return array(
		'post_id' => $id,
		'title'   => 'Strona ' . $id,
		'url'     => 'https://example.test/?p=' . $id,
		'text'    => implode( "\n", $lines ),
	);
}

/**
 * Unikalny, DŁUGI akapit — żeby usunięcie nagłówka nie ruszyło progu DOC_FLOOR.
 *
 * UWAGA (spięcie K17): akapit MUSI różnić się czymś więcej niż liczbą.
 * `normalize_line()` (§3.5) zamienia ciągi cyfr na `#` przy liczeniu `df`, więc
 * „Podstrona numer 201 …” i „Podstrona numer 202 …” to dla filtra TA SAMA linia —
 * cały korpus stałby się balastem, a `DOC_FLOOR` uratowałby wszystkie dokumenty.
 * Pierwsza wersja tego testu miała dokładnie taki błąd.
 */
function bp_long( $id ) {
	$tematy = array(
		'rytmika i zabawy muzyczne', 'plastyka oraz prace ręczne', 'gimnastyka korekcyjna',
		'język angielski w zabawie', 'zajęcia logopedyczne', 'przyroda i eksperymenty',
		'taniec nowoczesny', 'teatrzyk i drama', 'kuchnia malucha', 'basen i pływanie',
		'robotyka dla przedszkolaka', 'spotkania z książką',
	);
	$temat = $tematy[ ( (int) $id ) % count( $tematy ) ];

	return 'Podstrona numer ' . $id . ' opisuje ' . $temat . ', plan dnia oraz zasady '
		. 'zapisów do grupy przedszkolnej w bieżącym roku szkolnym.';
}

/** Liczy WYSTĄPIENIA dokładnej linii w całym zestawie (po trim). */
function bp_count_line( array $docs, $line ) {
	$n = 0;
	foreach ( $docs as $d ) {
		foreach ( explode( "\n", (string) $d['text'] ) as $l ) {
			if ( trim( $l ) === $line ) { ++$n; }
		}
	}
	return $n;
}

/** Zwraca post_id dokumentów zawierających daną linię. */
function bp_ids_with_line( array $docs, $line ) {
	$ids = array();
	foreach ( $docs as $d ) {
		foreach ( explode( "\n", (string) $d['text'] ) as $l ) {
			if ( trim( $l ) === $line ) { $ids[] = (int) $d['post_id']; break; }
		}
	}
	return $ids;
}

/** Liczy dokumenty, w których którakolwiek linia pasuje do wzorca. */
function bp_docs_matching( array $docs, $regex ) {
	$n = 0;
	foreach ( $docs as $d ) {
		if ( preg_match( $regex, (string) $d['text'] ) ) { ++$n; }
	}
	return $n;
}

// 12 dokumentów: wspólny nagłówek + unikalna treść; RARE tylko w dwóch.
$corpus12 = array();
for ( $i = 201; $i <= 212; $i++ ) {
	$lines = array( BP_HEADER, bp_long( $i ) );
	if ( 201 === $i || 202 === $i ) { $lines[] = BP_RARE; }
	$corpus12[] = bp_doc( $i, $lines );
}

if ( class_exists( 'AIFAQ\Index\BoilerplateFilter' ) ) {

	$F = 'AIFAQ\Index\BoilerplateFilter';

	// =======================================================================
	echo "=== A. Stałe progowe (§3.5) ===\n";
	check( 5 === constant( $F . '::MIN_DOCS' ), 'MIN_DOCS === 5' );
	check( 0.30 === constant( $F . '::DF_RATIO' ), 'DF_RATIO === 0.30' );
	check( 0.60 === constant( $F . '::DOC_FLOOR' ), 'DOC_FLOOR === 0.60' );

	// =======================================================================
	echo "\n=== B. GUARD MIN_DOCS: 4 dokumenty → filtr NIE rusza niczego ===\n";
	// Para negatywna do sekcji C — TEN SAM korpus, tylko obcięty do 4 pozycji.
	$c4  = array_slice( $corpus12, 0, 4 );
	$out = $F::filter( $c4, 201 );
	check( 4 === count( $out ), '4 dokumenty na wejściu → 4 na wyjściu' );
	check( 4 === bp_count_line( $out, BP_HEADER ), 'nagłówek nadal w 4 dokumentach (poniżej MIN_DOCS)' );
	check( 0 === $F::$last_filtered, '$last_filtered === 0 (nic nie usunięto)' );
	check( array() === $F::$last_warnings, '$last_warnings puste' );

	// =======================================================================
	echo "\n=== C. 12 dokumentów → nagłówek PRZENIESIONY do sinka (para pozytywna) ===\n";
	$out = $F::filter( $corpus12, 201 );
	check( 12 === count( $out ), '12 dokumentów na wejściu → 12 na wyjściu' );
	check( 1 === bp_count_line( $out, BP_HEADER ), 'nagłówek występuje DOKŁADNIE RAZ w całym zestawie' );
	check( array( 201 ) === bp_ids_with_line( $out, BP_HEADER ), 'jedyne wystąpienie jest w dokumencie sink (post_id 201)' );
	check( 12 === $F::$last_filtered, '$last_filtered === 12 (usunięte wystąpienia, nie unikaty)' );
	check( array() === $F::$last_warnings, 'brak ostrzeżeń — żaden dokument nie dobił do DOC_FLOOR' );
	check( 12 === bp_docs_matching( $out, '/Podstrona numer /' ), 'unikalna treść zachowana we wszystkich 12 dokumentach' );
	check( 2 === bp_count_line( $out, BP_RARE ), 'linia z 2 z 12 dokumentów (df poniżej progu) ZOSTAJE — 2 wystąpienia' );
	$keys_ok = true;
	foreach ( $out as $d ) {
		if ( ! isset( $d['post_id'], $d['title'], $d['url'], $d['text'] ) ) { $keys_ok = false; }
	}
	check( $keys_ok, 'każdy dokument zachowuje kształt §2.1 (post_id/title/url/text)' );
	check( array_keys( $out ) === range( 0, count( $out ) - 1 ), 'wynik przez array_values() — klucze 0..n-1' );

	// =======================================================================
	echo "\n=== D. Sink SPOZA zestawu → dokument „informacje ogólne” tworzony ===\n";
	$out = $F::filter( $corpus12, 9999 );
	check( 13 === count( $out ), '12 dokumentów + nowy sink === 13' );
	check( 1 === bp_count_line( $out, BP_HEADER ), 'nagłówek nadal dokładnie raz' );
	check( array( 9999 ) === bp_ids_with_line( $out, BP_HEADER ), 'wystąpienie w nowo utworzonym dokumencie 9999' );
	$sink = null;
	foreach ( $out as $d ) { if ( 9999 === (int) $d['post_id'] ) { $sink = $d; } }
	check( is_array( $sink ) && 'Przedszkole Testowe' === $sink['title'], 'title sinka === get_bloginfo(\'name\')' );
	check( is_array( $sink ) && 'https://example.test/' === $sink['url'], 'url sinka === home_url(\'/\')' );

	// =======================================================================
	echo "\n=== E. DOC_FLOOR: dokument w ≥60% z balastu zostaje NIEPRZEFILTROWANY ===\n";
	$corpus_floor = array();
	for ( $i = 301; $i <= 311; $i++ ) { $corpus_floor[] = bp_doc( $i, array( BP_HEADER, bp_long( $i ) ) ); }
	$corpus_floor[] = bp_doc( 312, array( BP_HEADER, 'Tak.' ) ); // 26 z 30 znaków to balast.
	$out = $F::filter( $corpus_floor, 301 );
	check( 12 === count( $out ), '12 dokumentów na wyjściu' );
	check( 2 === bp_count_line( $out, BP_HEADER ), 'nagłówek 2×: raz w sinku, raz w chronionym dokumencie' );
	check( array( 301, 312 ) === bp_ids_with_line( $out, BP_HEADER ), 'wystąpienia dokładnie w 301 (sink) i 312 (chroniony)' );
	check( 11 === $F::$last_filtered, '$last_filtered === 11 (dokument 312 pominięty w usuwaniu)' );
	check( 1 === count( $F::$last_warnings ), 'DOC_FLOOR dopisał dokładnie 1 ostrzeżenie' );
	$doc312 = null;
	foreach ( $out as $d ) { if ( 312 === (int) $d['post_id'] ) { $doc312 = $d; } }
	check( is_array( $doc312 ) && false !== strpos( $doc312['text'], 'Tak.' ), 'chroniony dokument zachował własną treść' );

	// =======================================================================
	echo "\n=== F. RESET pól statycznych na początku filter() ===\n";
	// Wejście: po sekcji E $last_warnings jest NIEPUSTE, $last_filtered === 11.
	$out = $F::filter( $corpus12, 201 );
	check( 12 === $F::$last_filtered, 'drugie wywołanie nie kumuluje: $last_filtered === 12, nie 23' );
	check( array() === $F::$last_warnings, '$last_warnings wyzerowane po poprzednim ostrzeżeniu' );
	$out = $F::filter( $corpus12, 201 );
	check( 12 === $F::$last_filtered, 'trzecie wywołanie na tym samym korpusie → nadal 12' );
	$out = $F::filter( $c4, 201 );
	check( 0 === $F::$last_filtered, 'po przebiegu bez filtrowania $last_filtered wraca do 0' );

	// =======================================================================
	echo "\n=== G. normalize_line() — wyłącznie do liczenia df ===\n";
	check( $F::normalize_line( '  Copyright   2024  Przedszkole ' ) === $F::normalize_line( 'COPYRIGHT 2025 przedszkole' ),
		'różnica wielkości liter, cyfr i białych znaków → ta sama forma znormalizowana' );
	check( 0 === preg_match( '/\d/', $F::normalize_line( 'Telefon 123 456 789' ) ), 'ciągi cyfr zastąpione (brak cyfr w wyniku)' );
	check( false !== strpos( $F::normalize_line( 'Telefon 123 456 789' ), '#' ), 'cyfry zamienione na #' );
	check( $F::normalize_line( 'Alfa' ) !== $F::normalize_line( 'Beta' ), 'różne linie → różne formy znormalizowane' );

	echo "\n=== H. Normalizacja w praktyce: warianty tej samej stopki ===\n";
	$corpus_norm = array();
	for ( $i = 0; $i < 12; $i++ ) {
		$id      = 401 + $i;
		$variant = ( 0 === $i % 2 )
			? 'Copyright ' . ( 2020 + $i ) . ' Przedszkole'
			: 'COPYRIGHT  ' . ( 2020 + $i ) . '   przedszkole';
		$corpus_norm[] = bp_doc( $id, array( $variant, bp_long( $id ) ) );
	}
	$out = $F::filter( $corpus_norm, 401 );
	check( 1 === bp_docs_matching( $out, '/copyright/i' ), 'wszystkie 12 wariantów uznane za tę samą linię → zostaje 1 dokument z nią' );
	check( 12 === $F::$last_filtered, '$last_filtered === 12 mimo różnych zapisów' );
	$sink401 = null;
	foreach ( $out as $d ) { if ( 401 === (int) $d['post_id'] ) { $sink401 = $d; } }
	$originals = array();
	foreach ( $corpus_norm as $d ) { $originals[] = explode( "\n", $d['text'] )[0]; }
	$kept = '';
	if ( is_array( $sink401 ) ) {
		foreach ( explode( "\n", $sink401['text'] ) as $l ) {
			if ( preg_match( '/copyright/i', $l ) ) { $kept = trim( $l ); }
		}
	}
	check( in_array( $kept, $originals, true ), 'do sinka trafił ORYGINAŁ linii, nie forma znormalizowana ("' . $kept . '")' );

	// =======================================================================
	echo "\n=== I. Dokument z pustym tekstem usuwany z wyniku ===\n";
	$corpus13   = $corpus12;
	$corpus13[] = bp_doc( 250, array( '   ' ) );
	check( 13 === count( $corpus13 ), 'wejście ma 13 dokumentów (12 + jeden pusty)' );
	$out = $F::filter( $corpus13, 201 );
	check( 12 === count( $out ), 'dokument bez treści usunięty z wyniku → 12' );
	check( 0 === count( bp_ids_with_line( $out, '' ) ), 'w wyniku nie ma dokumentu z pustą linią-treścią' );

} else {
	check( false, 'SEKCJE A–I POMINIĘTE: brak klasy AIFAQ\Index\BoilerplateFilter (Etap 2)' );
}

// ---------------------------------------------------------------------------
// Licznik podłogowy — chroni przed „zielenią z pominięcia".
// ---------------------------------------------------------------------------
echo "\n=== PODŁOGA ASERCJI ===\n";
$done = $ran;
check( $done >= 34, 'wykonano co najmniej 34 asercje (było: ' . $done . ')' );

echo "\n=== PODSUMOWANIE ===\n";
echo ( 0 === $fail ) ? "TEST KROK 17 (BoilerplateFilter): WSZYSTKIE ASERCJE OK ($ran)\n" : "TEST KROK 17 (BoilerplateFilter): $fail ASERCJI NIE PRZESZŁO (z $ran)\n";
exit( 0 === $fail ? 0 : 1 );
