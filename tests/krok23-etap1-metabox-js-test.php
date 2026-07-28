<?php
/**
 * Krok 23 etap 1 — regres: Classic Editor + obca wtyczka fałszywie rozpoznana
 * jako Gutenberg (isBlock() zwraca true, ale core/editor nie ma realnej treści).
 *
 * DLACZEGO ISTNIEJE: gdy obca wtyczka ładuje wp.data na Classic Editorze,
 * `wp.data.select( 'core/editor' )` ISTNIEJE (isBlock() === true), ale
 * `getEditedPostContent()` / `getEditedPostAttribute( 'title' )` zwracają pusty
 * string. To NIE jest wyjątek — fallback do TinyMCE/#content i #title siedział
 * WYŁĄCZNIE w bloku catch, więc readContent()/readTitle() zwracały '' zamiast
 * spaść do ścieżki klasycznej. Test statyczny (bez WordPressa i przeglądarki):
 * czyta assets/js/faq-metabox.js jako tekst i dowodzi regexem, że:
 *  A. obie funkcje sprawdzają pustość WYNIKU gałęzi blokowej PRZED return,
 *  B. fallback klasyczny (tinymce.get/#content, #title) leży POZA blokiem catch,
 *  C. stary wzorzec „return natychmiast z gałęzi blokowej bez sprawdzenia
 *     pustki" (return String( wp.data...select(...)... ) jako pierwsza i jedyna
 *     instrukcja try) już nie występuje.
 *
 * Asercje ilościowe zawsze `=== N`, nigdy `> 0` (patrz tests/js-rest-contract-test.php
 * i historia regresu z v0.17.0/v0.18.0 — słaba asercja przepuściła realny błąd).
 *
 * Jeżeli w środowisku jest dostępny `node`, dodatkowo uruchamiana jest sekcja
 * behawioralna (sekcja Z): wyciąga ciała readTitle()/readContent() regexem,
 * odpala je w izolowanej piaskownicy Node z atrapą wp.data zwracającą pusty
 * string i dowodzi, że mimo to funkcja sięga po pole klasyczne. Brak node'a
 * NIE jest błędem testu — sekcja Z jest wtedy pomijana, a test i tak kończy
 * się exit 0 na samych asercjach statycznych A-C.
 *
 * URUCHOMIENIE:  php tests/krok23-etap1-metabox-js-test.php
 * Kod wyjścia: 0 = OK, 1 = błędy.
 *
 * @package AI_FAQ_Generator
 */

$fail = 0;
function check( $cond, $label ) { global $fail; echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n"; if ( ! $cond ) { $fail++; } }

$js_path = __DIR__ . '/../assets/js/faq-metabox.js';
$js      = file_get_contents( $js_path );

echo "=== A0. Plik wczytany ===\n";
check( false !== $js && strlen( $js ) > 200, 'assets/js/faq-metabox.js wczytany i nie jest zaślepką' );
$js = (string) $js;

/**
 * Wycina ciało funkcji `function <name>(` … do domykającego `}` na poziomie 0,
 * licznikiem klamer (ten sam trik co w js-rest-contract-test.php, sekcja F) —
 * naiwny regex zgubiłby się na zagnieżdżonych `if`/`try`.
 *
 * @param string $js   Treść pliku JS.
 * @param string $name Nazwa funkcji (np. "readTitle").
 * @return string|null Ciało funkcji WŁĄCZNIE z klamrami, albo null gdy brak.
 */
function aifaq_fn_body( $js, $name ) {
	$needle = 'function ' . $name . '(';
	$start  = strpos( $js, $needle );
	if ( false === $start ) {
		return null;
	}
	$brace = strpos( $js, '{', $start );
	if ( false === $brace ) {
		return null;
	}
	$depth = 0;
	$n     = strlen( $js );
	for ( $i = $brace; $i < $n; $i++ ) {
		$ch = $js[ $i ];
		if ( '{' === $ch ) {
			$depth++;
		}
		if ( '}' === $ch ) {
			$depth--;
			if ( 0 === $depth ) {
				return substr( $js, $start, $i - $start + 1 );
			}
		}
	}
	return null;
}

$readTitle   = aifaq_fn_body( $js, 'readTitle' );
$readContent = aifaq_fn_body( $js, 'readContent' );

echo "\n=== A1. Obie funkcje-czytniki znalezione ===\n";
check( is_string( $readTitle ) && strlen( $readTitle ) > 30, 'readTitle() wyciągnięta z pliku' );
check( is_string( $readContent ) && strlen( $readContent ) > 30, 'readContent() wyciągnięta z pliku' );

$readTitle   = (string) $readTitle;
$readContent = (string) $readContent;

// ---------------------------------------------------------------------------
// B. Fallback klasyczny musi leżeć POZA blokiem catch (dowód: string catch
// występuje w obu funkcjach, a odczyt pola klasycznego (#title / tinymce)
// występuje PO catchu, nie tylko wewnątrz niego).
// ---------------------------------------------------------------------------
echo "\n=== B. Struktura: fallback klasyczny poza catch ===\n";

$catch_pos_title   = strpos( $readTitle, 'catch (' );
$title_el_pos      = strpos( $readTitle, "getElementById( 'title' )" );
check( false !== $catch_pos_title, 'readTitle() ma blok catch (ścieżka wyjątku nadal istnieje)' );
check( false !== $title_el_pos, "readTitle() czyta #title (getElementById( 'title' ))" );
check(
	false !== $catch_pos_title && false !== $title_el_pos && $title_el_pos > $catch_pos_title,
	'readTitle(): odczyt #title leży PO catchu, czyli jest osiągalny również bez wyjątku'
);

// Szukamy wywołania w KODZIE (`window.tinymce && tinymce.get(...)`), nie samego
// stringu 'tinymce.get( 'content' )' — ten fragment pada też wewnątrz komentarza
// wyjaśniającego naprawę, więc goły strpos złapałby komentarz zamiast kodu.
$catch_pos_content = strpos( $readContent, 'catch (' );
$tinymce_pos        = strpos( $readContent, "window.tinymce && tinymce.get( 'content' )" );
check( false !== $catch_pos_content, 'readContent() ma blok catch (ścieżka wyjątku nadal istnieje)' );
check( false !== $tinymce_pos, "readContent() czyta window.tinymce && tinymce.get( 'content' )" );
check(
	false !== $catch_pos_content && false !== $tinymce_pos && $tinymce_pos > $catch_pos_content,
	'readContent(): odczyt tinymce leży PO catchu, czyli jest osiągalny również bez wyjątku'
);

// ---------------------------------------------------------------------------
// A. Sedno naprawy: wynik gałęzi blokowej jest sprawdzony na pustość PRZED
// zwrotem — zmienna pośrednia + `.trim()` + `if` strzegący `return`, zamiast
// gołego `return String( wp.data... )`.
// ---------------------------------------------------------------------------
echo "\n=== C. Sedno naprawy: sprawdzenie pustki przed return (nie przy pierwszym return z gałęzi blokowej) ===\n";

check(
	1 === preg_match( "/blockTitle\\s*=\\s*String\\(\\s*wp\\.data\\.select\\(\\s*'core\\/editor'\\s*\\)\\.getEditedPostAttribute/", $readTitle ),
	"readTitle() najpierw PRZYPISUJE wynik do zmiennej (blockTitle), nie zwraca go od razu"
);
check(
	1 === preg_match( "/if\\s*\\(\\s*''\\s*!==\\s*blockTitle\\.trim\\(\\)\\s*\\)\\s*\\{\\s*return blockTitle;/", $readTitle ),
	"readTitle() zwraca blockTitle TYLKO gdy trim() !== '' (sprawdzenie pustki przed return)"
);

check(
	1 === preg_match( "/blockContent\\s*=\\s*String\\(\\s*wp\\.data\\.select\\(\\s*'core\\/editor'\\s*\\)\\.getEditedPostContent/", $readContent ),
	"readContent() najpierw PRZYPISUJE wynik do zmiennej (blockContent), nie zwraca go od razu"
);
check(
	1 === preg_match( "/if\\s*\\(\\s*''\\s*!==\\s*blockContent\\.trim\\(\\)\\s*\\)\\s*\\{\\s*return blockContent;/", $readContent ),
	"readContent() zwraca blockContent TYLKO gdy trim() !== '' (sprawdzenie pustki przed return)"
);

// ---------------------------------------------------------------------------
// D. Stary wzorzec regresu MUSI zniknąć: `return String( wp.data.select(
// 'core/editor' ). getEditedPost... )` jako gołe zwrócenie bez pośredniej
// zmiennej i bez strażnika `if`. Dopasowanie liczone === 0 (nie samo "brak"
// stringiem, bo to i tak jest zerojedynkowe, ale trzymamy formę ilościową
// spójną z resztą pliku).
// ---------------------------------------------------------------------------
echo "\n=== D. Stary wzorzec regresu nieobecny ===\n";

$old_pattern_title   = "/return String\\(\\s*wp\\.data\\.select\\(\\s*'core\\/editor'\\s*\\)\\.getEditedPostAttribute/";
$old_pattern_content = "/return String\\(\\s*wp\\.data\\.select\\(\\s*'core\\/editor'\\s*\\)\\.getEditedPostContent/";

check(
	0 === preg_match_all( $old_pattern_title, $readTitle ),
	"readTitle(): 0 wystąpień starego wzorca 'return String( wp.data...getEditedPostAttribute... )' bez sprawdzenia pustki"
);
check(
	0 === preg_match_all( $old_pattern_content, $readContent ),
	"readContent(): 0 wystąpień starego wzorca 'return String( wp.data...getEditedPostContent... )' bez sprawdzenia pustki"
);

// isBlock() sam w sobie MUSI zostać nietknięty (zadanie mówi: "isBlock() ok.") —
// dowód negatywny, że naprawa nie rozjechała tej funkcji przy okazji.
echo "\n=== E. isBlock() nietknięty (poza zakresem naprawy) ===\n";
$isBlock = aifaq_fn_body( $js, 'isBlock' );
check( is_string( $isBlock ) && strlen( $isBlock ) > 20, 'isBlock() nadal istnieje' );
check(
	is_string( $isBlock ) && 1 === preg_match( "/return\\s*!!\\s*\\(\\s*window\\.wp\\s*&&\\s*wp\\.data\\s*&&\\s*wp\\.data\\.select\\s*&&\\s*wp\\.data\\.select\\(\\s*'core\\/editor'\\s*\\)\\s*\\)\\s*;/", (string) $isBlock ),
	'isBlock() zachował dokładne warunki sprzed naprawy (wp && wp.data && wp.data.select && wp.data.select( core/editor ))'
);

// ---------------------------------------------------------------------------
// Z. Sekcja behawioralna (opcjonalna) — tylko gdy `node` jest w PATH. Buduje
// minimalną piaskownicę: wp.data zwraca PUSTY string, jak na Classic Editorze
// fałszywie rozpoznanym przez obcą wtyczkę. Dowodzi, że mimo to readTitle()/
// readContent() sięgają po pole klasyczne (#title / tinymce.get('content')),
// czyli że naprawa DZIAŁA, a nie tylko "wygląda dobrze" w regexie.
// Test MUSI przechodzić także bez node — ta sekcja jest wtedy POMIJANA, nie
// licząca się do $fail.
// ---------------------------------------------------------------------------
echo "\n=== Z. Behawioralna próba w Node (opcjonalna) ===\n";

$node_bin = null;
$which    = @shell_exec( 'node -v 2>&1' );
if ( is_string( $which ) && 1 === preg_match( '/^v\d+\.\d+\.\d+/', trim( $which ) ) ) {
	$node_bin = 'node';
}

if ( null === $node_bin ) {
	echo "  SKIP  node niedostępny w PATH — sekcja Z pominięta (test i tak liczy się z A-E)\n";
} else {
	// Sandbox: podstawiamy globalny `wp.data.select('core/editor')`, którego
	// gettery zwracają '' (dokładnie scenariusz z opisu regresu), oraz
	// document/tinymce jako proste atrapy z jedną wartością do odczytania.
	$sandbox = <<<'NODEJS'
var calledTinymce = false;
var calledTitleEl = false;

global.window = global;
global.wp = {
	data: {
		select: function ( store ) {
			if ( 'core/editor' !== store ) { return null; }
			return {
				getEditedPostAttribute: function () { return ''; }, // obca wtyczka: pusty tytuł
				getEditedPostContent: function () { return ''; }     // obca wtyczka: pusta treść
			};
		}
	}
};

global.document = {
	getElementById: function ( id ) {
		if ( 'title' === id ) {
			calledTitleEl = true;
			return { value: 'TYTUL-Z-POLA-KLASYCZNEGO' };
		}
		if ( 'content' === id ) {
			return { value: 'TRESC-Z-POLA-KLASYCZNEGO' };
		}
		return null;
	}
};

global.tinymce = {
	get: function ( id ) {
		if ( 'content' !== id ) { return null; }
		calledTinymce = true;
		return {
			isHidden: function () { return false; },
			getContent: function () { return 'TRESC-Z-TINYMCE'; }
		};
	}
};

FN_ISBLOCK
FN_READTITLE
FN_READCONTENT

var title   = readTitle();
var content = readContent();

var out = {
	title: title,
	content: content,
	calledTinymce: calledTinymce,
	calledTitleEl: calledTitleEl
};
process.stdout.write( JSON.stringify( out ) );
NODEJS;

	$sandbox = str_replace( 'FN_ISBLOCK', $isBlock, $sandbox );
	$sandbox = str_replace( 'FN_READTITLE', $readTitle, $sandbox );
	$sandbox = str_replace( 'FN_READCONTENT', $readContent, $sandbox );

	$tmp = tempnam( sys_get_temp_dir(), 'aifaq_mb_' ) . '.js';
	file_put_contents( $tmp, $sandbox );

	$out    = @shell_exec( $node_bin . ' ' . escapeshellarg( $tmp ) . ' 2>&1' );
	@unlink( $tmp );

	$decoded = null;
	if ( is_string( $out ) ) {
		$decoded = json_decode( trim( $out ), true );
	}

	check( is_array( $decoded ), 'sandbox Node uruchomiony i zwrócił poprawny JSON (surowy wynik: ' . var_export( $out, true ) . ')' );

	if ( is_array( $decoded ) ) {
		check( 'TYTUL-Z-POLA-KLASYCZNEGO' === ( $decoded['title'] ?? null ), "readTitle() spadł do #title, gdy core/editor zwrócił pusty tytuł (dostał: " . var_export( $decoded['title'] ?? null, true ) . ')' );
		check( 'TRESC-Z-TINYMCE' === ( $decoded['content'] ?? null ), "readContent() spadł do TinyMCE, gdy core/editor zwrócił pustą treść (dostał: " . var_export( $decoded['content'] ?? null, true ) . ')' );
		check( true === ( $decoded['calledTinymce'] ?? null ), 'tinymce.get( "content" ) faktycznie WYWOŁANY (nie tylko dostępny)' );
		check( true === ( $decoded['calledTitleEl'] ?? null ), 'getElementById( "title" ) faktycznie WYWOŁANY (nie tylko dostępny)' );
	}
}

echo "\n=== PODSUMOWANIE ===\n";
echo ( 0 === $fail ) ? "TEST K23-E1 METABOX JS: WSZYSTKIE ASERCJE OK\n" : "TEST K23-E1 METABOX JS: $fail ASERCJI NIE PRZESZŁO\n";
exit( $fail === 0 ? 0 : 1 );
