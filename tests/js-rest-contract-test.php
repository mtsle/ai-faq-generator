<?php
/**
 * Test kontraktowy JS ↔ REST — nazwy pól wysyłanych z przeglądarki.
 *
 * DLACZEGO ISTNIEJE: w v0.17.0 `assets/js/faq-tool.js` wysyłał do
 * `POST /admin/generate-faq` pola `extra_desc` i `num_questions` (nazwy KOLUMN
 * w bazie), podczas gdy trasa deklaruje args `description` i `count`. Efekt był
 * cichy i przeżył dwa Kroki: „Dodatkowy opis" był ignorowany (do bazy szło ''),
 * a „Liczba pytań" nie działała (`count`=0 → fallback na `max_questions`
 * z ustawień, więc user prosił o 5 par i dostawał 20). Ani testy jednostkowe
 * (sprawdzały tylko stronę PHP), ani test Playwright (asercja „są jakieś pary"
 * zamiast „jest DOKŁADNIE tyle par") tego nie złapały.
 *
 * CO PILNUJE: każdy klucz wysyłany przez JS w body do trasy generatora musi być
 * ZADEKLAROWANY w `args` tej trasy w `RestController`. To test statyczny —
 * czyta oba pliki jako tekst, bez WordPressa i bez przeglądarki.
 *
 * URUCHOMIENIE:  php tests/js-rest-contract-test.php
 * Kod wyjścia: 0 = OK, 1 = błędy.
 *
 * @package AI_FAQ_Generator
 */

$fail = 0;
function check( $cond, $label ) { global $fail; echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n"; if ( ! $cond ) { $fail++; } }

$js_path  = __DIR__ . '/../assets/js/faq-tool.js';
$php_path = __DIR__ . '/../src/Rest/RestController.php';

$js  = file_get_contents( $js_path );
$php = file_get_contents( $php_path );

echo "=== A. Pliki wczytane ===\n";
check( false !== $js && '' !== $js, 'assets/js/faq-tool.js wczytany' );
check( false !== $php && '' !== $php, 'src/Rest/RestController.php wczytany' );

// ---------------------------------------------------------------------------
// Wyciągnij klucze body wysyłanego do cfg.endpoint (trasa generatora).
// Szukamy `fetch( cfg.endpoint` … `JSON.stringify( { … } )`.
// ---------------------------------------------------------------------------
echo "\n=== B. Klucze wysyłane przez JS do /admin/generate-faq ===\n";
$js_keys = array();
if ( preg_match( '/fetch\(\s*cfg\.endpoint.*?JSON\.stringify\(\s*\{(.*?)\}\s*\)/s', $js, $m ) ) {
	preg_match_all( '/(\w+)\s*:/', $m[1], $km );
	$js_keys = array_values( array_unique( $km[1] ) );
}
sort( $js_keys );
check( ! empty( $js_keys ), 'znaleziono body wysyłane do cfg.endpoint: ' . implode( ', ', $js_keys ) );

// ---------------------------------------------------------------------------
// Wyciągnij zadeklarowane args trasy /admin/generate-faq z RestController.
// Bierzemy fragment od '/admin/generate-faq' do końca bloku register_rest_route.
// ---------------------------------------------------------------------------
echo "\n=== C. Args zadeklarowane w trasie ===\n";
$rest_args = array();
$pos = strpos( $php, "'/admin/generate-faq'" );
if ( false !== $pos ) {
	// Blok tej trasy kończy się przed rejestracją kolejnej trasy.
	$next = strpos( $php, 'register_rest_route', $pos );
	$chunk = false !== $next ? substr( $php, $pos, $next - $pos ) : substr( $php, $pos, 2000 );
	// Klucze args to `'nazwa' => array(` wewnątrz bloku 'args'.
	$apos = strpos( $chunk, "'args'" );
	if ( false !== $apos ) {
		preg_match_all( "/'(\w+)'\s*=>\s*array\(/", substr( $chunk, $apos ), $am );
		// Odsiej klucze SPECYFIKACJI argumentu (i sam kontener 'args') — nazwami
		// pól API są tylko pozostałe klucze.
		$spec = array( 'args', 'methods', 'callback', 'permission_callback', 'validate_callback', 'sanitize_callback', 'required', 'type', 'default', 'enum', 'items', 'properties' );
		$rest_args = array_values( array_diff( array_unique( $am[1] ), $spec ) );
	}
}
sort( $rest_args );
check( ! empty( $rest_args ), 'znaleziono args trasy: ' . implode( ', ', $rest_args ) );

// ---------------------------------------------------------------------------
// SEDNO: każdy klucz z JS musi być zadeklarowanym argumentem trasy.
// ---------------------------------------------------------------------------
echo "\n=== D. Zgodność JS → REST (sedno testu) ===\n";
$unknown = array_diff( $js_keys, $rest_args );
check(
	empty( $unknown ),
	empty( $unknown )
		? 'każdy klucz wysyłany przez JS jest zadeklarowany w args trasy'
		: 'JS wysyła pola NIEZNANE trasie (będą po cichu zignorowane): ' . implode( ', ', $unknown )
);

// Twarde asercje na konkretny regres z v0.17.0.
check( in_array( 'description', $js_keys, true ), "JS wysyła 'description' (a nie 'extra_desc')" );
check( in_array( 'count', $js_keys, true ), "JS wysyła 'count' (a nie 'num_questions')" );
check( ! in_array( 'extra_desc', $js_keys, true ), "JS NIE wysyła 'extra_desc' (to nazwa kolumny w bazie, nie pola API)" );
check( ! in_array( 'num_questions', $js_keys, true ), "JS NIE wysyła 'num_questions' (to nazwa kolumny w bazie, nie pola API)" );
check( in_array( 'topic', $js_keys, true ), "JS wysyła 'topic'" );

// ---------------------------------------------------------------------------
// Krok 15: drugi styk JS↔REST — prefill „Ponownie wygeneruj" czyta szczegół
// generacji. Ta sama klasa błędu co wyżej: gdyby JS wysłał inną nazwę pola niż
// deklaruje trasa, prefill po cichu przestałby działać.
// ---------------------------------------------------------------------------
echo "\n=== E. Styk prefillu: faq-tool.js → /admin/generations/detail (K15) ===\n";
check( false !== strpos( $js, 'cfg.detailEndpoint' ), "JS używa cfg.detailEndpoint (nie sklejonego URL-a)" );

$detail_args = array();
$dpos = strpos( $php, "'/admin/generations/detail'" );
if ( false !== $dpos ) {
	$dnext  = strpos( $php, 'register_rest_route', $dpos );
	$dchunk = false !== $dnext ? substr( $php, $dpos, $dnext - $dpos ) : substr( $php, $dpos, 2000 );
	$dapos  = strpos( $dchunk, "'args'" );
	if ( false !== $dapos ) {
		preg_match_all( "/'(\w+)'\s*=>\s*array\(/", substr( $dchunk, $dapos ), $dam );
		$spec2       = array( 'args', 'methods', 'callback', 'permission_callback', 'validate_callback', 'sanitize_callback', 'required', 'type', 'default' );
		$detail_args = array_values( array_diff( array_unique( $dam[1] ), $spec2 ) );
	}
}
check( in_array( 'id', $detail_args, true ), "trasa /admin/generations/detail deklaruje arg 'id' (args: " . implode( ', ', $detail_args ) . ')' );

// Nazwa parametru regeneracji musi pochodzić ze stałej PHP, a nie z literału w JS
// — inaczej app.js (buduje link) i faq-tool.js (czyta) mogą się rozjechać.
$panel = @file_get_contents( __DIR__ . '/../src/App/GenerationsPanel.php' );
check( false !== $panel && false !== strpos( $panel, "REGEN_PARAM" ), 'GenerationsPanel definiuje stałą REGEN_PARAM' );
check( false !== strpos( $js, 'cfg.regenParam' ), 'faq-tool.js czyta nazwę parametru z configu (cfg.regenParam), nie z literału' );

echo "\n=== PODSUMOWANIE ===\n";
echo ( 0 === $fail ) ? "TEST KONTRAKTU JS-REST: WSZYSTKIE ASERCJE OK\n" : "TEST KONTRAKTU JS-REST: $fail ASERCJI NIE PRZESZŁO\n";
exit( $fail === 0 ? 0 : 1 );
