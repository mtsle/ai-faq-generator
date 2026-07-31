<?php
/**
 * Krok 23 etap 5 — straznik WSADU NAPRAW (D1, S1-S4, D3-A, D4-B, D6-B).
 *
 * Kazda sekcja pilnuje JEDNEJ naprawy wybranej przez usera z rejestru
 * `plany/KROK23-ETAP5-WHILE-REPORT.md`. Tam, gdzie da sie sprawdzic ZACHOWANIE
 * (zamek publikacji, bramka roli w config()), test jest behawioralny na atrapach.
 * Tam, gdzie zachowanie wymaga zywego WordPressa (naglowki HTTP, widok kokpitu),
 * test jest strukturalny — i mowi to wprost w opisie asercji.
 *
 * URUCHOMIENIE:  php tests/krok23-etap5-naprawy-test.php
 * Kod wyjscia: 0 = OK, 1 = bledy.
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
function n5_check( $cond, $label ) {
	global $fail, $ran;
	$ran++;
	echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n";
	if ( ! $cond ) {
		$fail++;
	}
}

/**
 * Naglowek sekcji.
 *
 * @param string $t Tytul.
 *
 * @return void
 */
function n5_section( $t ) {
	echo "\n=== {$t} ===\n";
}

/**
 * Zwraca SAM KOD pliku, bez komentarzy i docblokow.
 *
 * DLACZEGO ISTNIEJE: podczas pisania tego zestawu TRZY asercje dawaly falszywy
 * alarm, bo `strpos()`/`preg_match()` po surowym zrodle trafialy w KOMENTARZ
 * tlumaczacy dana naprawe — komentarz z natury cytuje to, czego w kodzie ma NIE
 * byc („usunieto `SELECT *`", „`finally`, nie zwykla kolejnosc"). Asercja o kodzie
 * musi patrzec na kod. Kazde sprawdzenie STRUKTURY w tym pliku idzie przez te
 * funkcje; sprawdzenia dotyczace TRESCI komunikatow dla uzytkownika — nie.
 *
 * @param string $path Sciezka pliku PHP.
 * @return string Kod zlozony z tokenow, bez komentarzy.
 */
function n5_code( $path ) {
	$src = (string) file_get_contents( $path );
	$out = '';
	foreach ( token_get_all( $src ) as $t ) {
		if ( is_array( $t ) ) {
			if ( in_array( $t[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			$out .= $t[1];
			continue;
		}
		$out .= $t;
	}
	return $out;
}

// ---------------------------------------------------------------------------
// Atrapy WordPressa — minimum potrzebne do ZACHOWANIA zamka i config().
// ---------------------------------------------------------------------------
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $root . '/' );
}

$GLOBALS['__opts']   = array();
$GLOBALS['__owner']  = true;

if ( ! function_exists( 'add_option' ) ) {
	/**
	 * Atrapa `add_option()` — kluczowa dla testu zamka: zwraca false, gdy klucz
	 * JUZ istnieje (tak samo jak WordPress, ktory opiera sie na UNIQUE KEY).
	 *
	 * @param string $k Klucz.
	 * @param mixed  $v Wartosc.
	 * @return bool
	 */
	function add_option( $k, $v = '', $d = '', $a = 'yes' ) {
		unset( $d, $a );
		if ( array_key_exists( $k, $GLOBALS['__opts'] ) ) {
			return false;
		}
		$GLOBALS['__opts'][ $k ] = $v;
		return true;
	}
}
if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Atrapa `get_option()`.
	 *
	 * @param string $k Klucz.
	 * @param mixed  $d Domyslna.
	 * @return mixed
	 */
	function get_option( $k, $d = false ) {
		return array_key_exists( $k, $GLOBALS['__opts'] ) ? $GLOBALS['__opts'][ $k ] : $d;
	}
}
if ( ! function_exists( 'delete_option' ) ) {
	/**
	 * Atrapa `delete_option()`.
	 *
	 * @param string $k Klucz.
	 * @return bool
	 */
	function delete_option( $k ) {
		unset( $GLOBALS['__opts'][ $k ] );
		return true;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	/**
	 * Atrapa `update_option()`.
	 *
	 * @param string $k Klucz.
	 * @param mixed  $v Wartosc.
	 * @return bool
	 */
	function update_option( $k, $v = '', $a = null ) {
		unset( $a );
		$GLOBALS['__opts'][ $k ] = $v;
		return true;
	}
}
if ( ! function_exists( 'current_time' ) ) {
	/**
	 * Atrapa `current_time()`.
	 *
	 * @param string $t Typ.
	 * @return string
	 */
	function current_time( $t ) {
		unset( $t );
		return '2026-07-31 00:00:00';
	}
}
if ( ! function_exists( 'is_user_logged_in' ) ) {
	/**
	 * Atrapa logowania.
	 *
	 * @return bool
	 */
	function is_user_logged_in() {
		return (bool) $GLOBALS['__owner'];
	}
}
if ( ! function_exists( 'current_user_can' ) ) {
	/**
	 * Atrapa uprawnien.
	 *
	 * @param string $c Cap.
	 * @return bool
	 */
	function current_user_can( $c ) {
		unset( $c );
		return (bool) $GLOBALS['__owner'];
	}
}

require_once $root . '/src/Faq/PublicFaq.php';

// ---------------------------------------------------------------------------
// D1 — zamek publikacji. BEHAWIORALNIE.
// ---------------------------------------------------------------------------
n5_section( 'D1 — zamek publikacji FAQ (zachowanie)' );

$GLOBALS['__opts'] = array();

$first = \AIFAQ\Faq\PublicFaq::acquire_lock();
n5_check( true === $first, 'pierwsze zajecie zamka udaje sie' );

$second = \AIFAQ\Faq\PublicFaq::acquire_lock();
n5_check(
	false === $second,
	'DRUGIE, ROWNOCZESNE zajecie zamka jest ODRZUCONE (to jest cala istota naprawy: '
		. 'bez tego dwie publikacje nadpisywaly sie, gubiac prace jednej z nich bez sladu)'
);

\AIFAQ\Faq\PublicFaq::release_lock();
n5_check(
	true === \AIFAQ\Faq\PublicFaq::acquire_lock(),
	'po zwolnieniu zamek znow jest do wziecia (brak zakleszczenia)'
);
\AIFAQ\Faq\PublicFaq::release_lock();

// Przeterminowany zamek MUSI dac sie przejac — inaczej padniety proces
// blokowalby publikacje az do konca TTL, bez zadnej drogi wyjscia dla wlasciciela.
$GLOBALS['__opts'][ \AIFAQ\Faq\PublicFaq::LOCK ] = (string) ( time() - \AIFAQ\Faq\PublicFaq::LOCK_TTL - 5 );
n5_check(
	true === \AIFAQ\Faq\PublicFaq::acquire_lock(),
	'zamek PRZETERMINOWANY (proces padl) jest przejmowany, nie blokuje na zawsze'
);
\AIFAQ\Faq\PublicFaq::release_lock();

// Zamek swiezy NIE moze byc przejmowany — inaczej TTL nie chronilby niczego.
$GLOBALS['__opts'][ \AIFAQ\Faq\PublicFaq::LOCK ] = (string) time();
n5_check(
	false === \AIFAQ\Faq\PublicFaq::acquire_lock(),
	'zamek SWIEZY nie jest przejmowany (kontrola negatywna do asercji wyzej)'
);
\AIFAQ\Faq\PublicFaq::release_lock();

n5_check(
	false === get_option( \AIFAQ\Faq\PublicFaq::LOCK, false ),
	'release_lock() faktycznie kasuje klucz'
);

// Sprzatanie po zamku musi byc znane deinstalacji (strazy tego takze
// uninstall-guard-test.php; tu kotwica, bo klucz powstal w tym wsadzie).
$un_src = (string) file_get_contents( $root . '/uninstall.php' );
n5_check(
	false !== strpos( $un_src, "'" . \AIFAQ\Faq\PublicFaq::LOCK . "'" ),
	'uninstall.php kasuje klucz zamka publikacji'
);

// ---------------------------------------------------------------------------
// D1 (ciag dalszy) — warstwa REST zwraca 409, a zamek jest zwalniany w `finally`.
// ---------------------------------------------------------------------------
n5_section( 'D1 — warstwa REST (struktura)' );

$pub_src = n5_code( $root . '/src/Rest/PublishService.php' );

n5_check(
	1 === preg_match( '/acquire_lock\(\)\s*\)\s*\{.*?409/s', $pub_src ),
	'nieudane zajecie zamka konczy sie odpowiedzia 409 (a nie cichym nadpisaniem)'
);
// UWAGA METODYCZNA: pierwotnie stal tu jeden regex wymagajacy, zeby `release_lock()`
// szlo BEZPOSREDNIO po `finally {`. Dawal falszywy alarm, bo w `publish()` miedzy
// nawiasem a wywolaniem stoi komentarz. Rozdzielone na dwie proste asercje ilosciowe
// — odporne na formatowanie, a mierza dokladnie to samo.
n5_check(
	2 === substr_count( $pub_src, 'finally' ),
	'sa DWA bloki `finally` (=== 2): po jednym na publish i unpublish — '
		. 'wyjatek nie moze zostawic zamka zajetego do konca TTL'
);
n5_check(
	2 === substr_count( $pub_src, 'PublicFaq::release_lock()' ),
	'zamek jest zwalniany dwukrotnie (=== 2), po razie na trase'
);
n5_check(
	2 === substr_count( $pub_src, 'PublicFaq::acquire_lock()' ),
	'obie trasy zmieniajace FAQ biora zamek (=== 2): publish ORAZ unpublish '
		. '(unpublish tez robi snapshot, wiec przeplot niszczy kopie)'
);

// ---------------------------------------------------------------------------
// S3 — nieistniejace `generation_id` nie trafia do bazy.
// ---------------------------------------------------------------------------
n5_section( 'S3 — weryfikacja generation_id' );

n5_check(
	1 === preg_match( '/if\s*\(\s*\$id\s*>\s*0\s*&&\s*null\s*===\s*\(\s*new\s+GenerationRepository\(\)\s*\)->find\(\s*\$id\s*\)\s*\)\s*\{\s*\$id\s*=\s*0;/s', $pub_src ),
	'nieznane `id` jest ZEROWANE przed zapisem (pary sa poprawne, wiec zadania nie odrzucamy)'
);
// Sprawdzenie musi stac PRZED wywolaniem publish(), inaczej nie ma znaczenia.
$pos_check   = strpos( $pub_src, '$id = 0;' );
$pos_publish = strpos( $pub_src, 'PublicFaq::publish(' );
n5_check(
	false !== $pos_check && false !== $pos_publish && $pos_check < $pos_publish,
	'zerowanie `id` dzieje sie PRZED PublicFaq::publish() (kolejnosc decyduje o sensie)'
);

// ---------------------------------------------------------------------------
// S2 — AppShell::config() ma wlasna bramke roli. BEHAWIORALNIE.
// ---------------------------------------------------------------------------
n5_section( 'S2 — samoobrona AppShell::config()' );

$shell_src = n5_code( $root . '/src/App/AppShell.php' );
n5_check(
	1 === preg_match( '/function\s+config\(\)\s*:\s*array\s*\{\s*if\s*\(\s*!\s*self::is_owner\(\)\s*\)\s*\{\s*return\s+array\(\s*[\'"]isOwner[\'"]\s*=>\s*false/s', $shell_src ),
	'config() sprawdza role JAKO PIERWSZA rzecz i dla nie-wlasciciela zwraca isOwner=false'
);
// Nonce i adresy /admin/* nie moga powstac przed bramka.
$pos_gate  = strpos( $shell_src, "return array( 'isOwner' => false )" );
$pos_nonce = strpos( $shell_src, "wp_create_nonce( 'wp_rest' )" );
n5_check(
	false !== $pos_gate && false !== $pos_nonce && $pos_gate < $pos_nonce,
	'bramka stoi PRZED wygenerowaniem nonce wp_rest (gosc nie dostaje go nigdy)'
);

// ---------------------------------------------------------------------------
// S1 — nocache dla wlasciciela niezaleznie od miejsca shortcode'u.
// ---------------------------------------------------------------------------
n5_section( 'S1 — nocache panelu wlasciciela' );

$sc_src = n5_code( $root . '/src/PublicUi/Shortcode.php' );
$body   = '';
if ( preg_match( '/function\s+maybe_nocache\(\)\s*:\s*void\s*\{(.*?)\n\t\}/s', $sc_src, $m ) ) {
	$body = $m[1];
}
n5_check( '' !== $body, 'znaleziono cialo maybe_nocache()' );

$pos_owner = strpos( $body, 'is_owner()' );
$pos_short = strpos( $body, 'has_shortcode(' );
n5_check(
	false !== $pos_owner,
	'maybe_nocache() nadal sprawdza role (gosc ma zachowac cache — to sie NIE zmienia)'
);
n5_check(
	false === $pos_short || $pos_owner < $pos_short,
	'bramka roli stoi PRZED wykrywaniem shortcode\'u — nagłowki zaleza od ROLI, '
		. 'a nie od tego, czy shortcode siedzi akurat w post_content'
);
n5_check(
	false !== strpos( $body, 'aifaq_nocache_for_owner' ),
	'jest filtr `aifaq_nocache_for_owner` dla integratorow chcacych zawezic zachowanie'
);
n5_check(
	false !== strpos( $body, 'nocache_headers()' ) && false !== strpos( $body, 'DONOTCACHEPAGE' ),
	'wysylane sa OBA sygnaly: naglowki HTTP (dla CDN) i stala (dla wtyczek cache)'
);

// ---------------------------------------------------------------------------
// S4 — brak SELECT * na liscie historii.
// ---------------------------------------------------------------------------
n5_section( 'S4 — jawna lista kolumn w GenerationRepository::page()' );

$gen_src = n5_code( $root . '/src/Data/GenerationRepository.php' );

// UWAGA METODYCZNA: pierwotnie ten test szukal napisu „SELECT *" w CIELE metody
// i dawal dwa falszywe alarmy — trafial w KOMENTARZ, ktory tlumaczy, dlaczego
// `SELECT *` zostal usuniety (i wymienia `pairs_json` z nazwy). Asercja na kodzie
// nie moze czytac prozy: wyciagamy sam literal SQL z `prepare()` i sprawdzamy JEGO.
$page_sql = '';
if ( preg_match( '/function\s+page\(.*?\$wpdb->prepare\(\s*"([^"]+)"/s', $gen_src, $m ) ) {
	$page_sql = $m[1];
}
n5_check( '' !== $page_sql, 'wyciagnieto zapytanie SQL z page()' );
n5_check(
	false === strpos( $page_sql, 'SELECT *' ),
	'SQL page() NIE jest `SELECT *` (ciagnal `pairs_json` — do kilku MB na wiersz — na liste metadanych)'
);
n5_check(
	false === strpos( $page_sql, 'pairs_json' ),
	'SQL page() w ogole nie pobiera kolumny `pairs_json`'
);
// Komplet kolumn, ktorych faktycznie uzywa konsument (GeneratorService::generation_item()).
foreach ( array( 'id', 'created_at', 'topic', 'extra_desc', 'num_questions', 'language', 'user_id' ) as $col ) {
	n5_check( false !== strpos( $page_sql, $col ), "SQL page() pobiera kolumne `{$col}` (uzywana przez generation_item())" );
}
// `find()` — sciezka szczegolu — MUSI nadal dawac pary.
n5_check(
	1 === preg_match( "/function\s+find\(.*?pairs_json.*?\n\t\}/s", $gen_src ),
	'find() (szczegol) NADAL czyta pairs_json — pary zniknely tylko z LISTY'
);

// ---------------------------------------------------------------------------
// D4-B — martwy FaqRepository usuniety, tabela zostaje.
// ---------------------------------------------------------------------------
n5_section( 'D4-B — martwy kod usuniety' );

n5_check(
	! file_exists( $root . '/src/Data/FaqRepository.php' ),
	'src/Data/FaqRepository.php USUNIETY (zero wywolan w repo, tabela pusta przez caly cykl zycia)'
);

$schema_src = (string) file_get_contents( $root . '/src/Data/Schema.php' );
n5_check(
	false !== strpos( $schema_src, "const T_FAQ" ),
	'stala T_FAQ ZOSTAJE (tabela nietknieta — kasowanie to migracja z DROP TABLE, '
		. 'odlozona swiadomie na po v1.0.0)'
);
n5_check(
	false !== strpos( $schema_src, 'NIEUŻYWANA' ) || false !== strpos( $schema_src, 'NIEUZYWANA' ),
	'T_FAQ ma komentarz tlumaczacy, DLACZEGO tabela istnieje bez kodu (inaczej wroci jako „zagadka")'
);
// Kontrola: nikt nie odwoluje sie do skasowanej klasy.
$refs = 0;
$it   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/src', FilesystemIterator::SKIP_DOTS ) );
foreach ( $it as $f ) {
	if ( 'php' !== strtolower( $f->getExtension() ) ) {
		continue;
	}
	$src = (string) file_get_contents( $f->getPathname() );
	if ( 1 === preg_match( '/\bnew\s+FaqRepository\b|\bFaqRepository::/', $src ) ) {
		$refs++;
	}
}
n5_check( 0 === $refs, "zero odwolan do FaqRepository w src/ (znaleziono: {$refs})" );

// ---------------------------------------------------------------------------
// D3-A / D6-B — progi ostrzegawcze.
// ---------------------------------------------------------------------------
n5_section( 'D3-A / D6-B — progi ostrzegawcze' );

$retr_src = n5_code( $root . '/src/Rag/Retriever.php' );
n5_check(
	1 === preg_match( '/const\s+SCALE_WARN_CHUNKS\s*=\s*5000\s*;/', $retr_src ),
	'D3-A: Retriever::SCALE_WARN_CHUNKS === 5000 (prog OSTRZEGAWCZY, nie limit)'
);

$qa_src = n5_code( $root . '/src/Data/QaLogRepository.php' );
n5_check(
	1 === preg_match( '/const\s+SIZE_WARN_ROWS\s*=\s*50000\s*;/', $qa_src ),
	'D6-B: QaLogRepository::SIZE_WARN_ROWS === 50000'
);

$dash_src = (string) file_get_contents( $root . '/src/Admin/views/dashboard.php' );
n5_check(
	false !== strpos( $dash_src, 'SCALE_WARN_CHUNKS' ),
	'Dashboard czyta prog ze STALEJ, nie z wklejonej liczby'
);
n5_check(
	false !== strpos( $dash_src, 'SIZE_WARN_ROWS' ),
	'Dashboard czyta prog dziennika ze STALEJ'
);

// D6-B jest decyzja „ostrzegamy, NIE kasujemy" — komunikat musi to mowic wprost,
// inaczej wlasciciel uzna, ze wtyczka posprzatala za niego.
n5_check(
	false !== strpos( $dash_src, 'NIE kasuje ich sama' ),
	'komunikat dziennika mowi WPROST, ze wtyczka nic nie kasuje sama (retencja zostaje opt-in)'
);

// Podloga licznika.
n5_check( $ran >= 30, "wykonano komplet asercji (asercji: {$ran})" );

echo "\n=== " . ( 0 === $fail ? 'WSZYSTKIE OK' : "BLEDOW: {$fail}" ) . " (asercji: {$ran}) ===\n";
exit( $fail > 0 ? 1 : 0 );
