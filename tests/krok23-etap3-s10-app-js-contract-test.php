<?php
/**
 * Krok 23 etap 3, segment S10 (front JS ↔ REST kontrakt) — rozszerzenie sprawdzonej
 * metody z `js-rest-contract-test.php` (który pokrywa DOTĄD wyłącznie `faq-tool.js` i
 * `faq-metabox.js`) na `assets/js/app.js` — panel Ustawień w kokpicie.
 *
 * DLACZEGO TO WAŻNE WŁAŚNIE TUTAJ: `/admin/settings` i `/admin/verify` NIE MAJĄ
 * zadeklarowanego `args` w `RouteRegistrar` (w odróżnieniu od tras generatora) —
 * `AdminService::save_settings()`/`verify()` czytają pola PO NAZWIE wprost z body
 * (`$request->get_param( $field )` w pętli po literalnej liście). Bez schematu `args`
 * WordPress NIE odrzuci ani nie zasygnalizuje nieznanego/brakującego pola — rozjazd
 * nazw między JS a PHP byłby DOKŁADNIE tak samo cichy jak historyczny bug v0.17.0
 * (`extra_desc`/`num_questions`), tylko na innej trasie i bez żadnej sieci
 * bezpieczeństwa REST-owej.
 *
 * Pokrywa:
 *  A. `POST /admin/settings` — payload budowany w `app.js` (`model`, `temperature`,
 *     `language`, opcjonalnie `api_key`) musi być PODZBIOREM literalnej listy pól,
 *     które `AdminService::save_settings()` faktycznie czyta.
 *  B. `POST /admin/verify` — jedyne pole (`api_key`) wysyłane przez JS musi być tym,
 *     które `AdminService::verify()` czyta.
 *  C. `POST /admin/generations/delete` — payload `{ id }` zgodny z jedynym polem
 *     zadeklarowanym w `args` tej trasy (kontrola krzyżowa: ta trasa MA schemat,
 *     więc to też dowód, że metoda wyciągania kluczy z JS działa poprawnie na
 *     obiekcie literalnym, nie tylko na `JSON.stringify` inline jak w faq-tool.js).
 *
 * URUCHOMIENIE:  php tests/krok23-etap3-s10-app-js-contract-test.php
 * Kod wyjścia: 0 = OK, 1 = błędy.
 *
 * @package AI_FAQ_Generator
 */

$fail = 0;
function check( $cond, $label ) { global $fail; echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n"; if ( ! $cond ) { $fail++; } }

$js_path       = __DIR__ . '/../assets/js/app.js';
$admin_path    = __DIR__ . '/../src/Rest/AdminService.php';
$routes_path   = __DIR__ . '/../src/Rest/RouteRegistrar.php';

$js    = (string) file_get_contents( $js_path );
$admin = (string) file_get_contents( $admin_path );
$routes = (string) file_get_contents( $routes_path );

echo "=== A. Pliki wczytane ===\n";
check( '' !== $js, 'assets/js/app.js wczytany' );
check( '' !== $admin, 'src/Rest/AdminService.php wczytany' );
check( '' !== $routes, 'src/Rest/RouteRegistrar.php wczytany' );

// ---------------------------------------------------------------------------
echo "\n=== B. POST /admin/settings — payload JS vs pola czytane przez PHP ===\n";
// ---------------------------------------------------------------------------
// JS: `var payload = { model: …, temperature: …, language: … };` + warunkowe `payload.api_key = …`.
//
// UWAGA (znalezisko przy pisaniu tego testu): wartości par to WYRAŻENIA TRÓJARGUMENTOWE
// (`modelSel ? modelSel.value : undefined`) — samo `/(\w+)\s*:/` łapie też ".value :"
// z ternara jako fałszywy klucz "value". Klucz obiektu w tym stylu kodu stoi ZAWSZE
// bezpośrednio po `{` albo po `,` (nigdy po `.`) — dokładamy sztuczny wiodący przecinek
// do wyciętego wnętrza i wymagamy przecinka/początku PRZED nazwą klucza.
$js_settings_keys = array();
if ( preg_match( '/var\s+payload\s*=\s*\{(.*?)\};/s', $js, $m ) ) {
	preg_match_all( '/,\s*(\w+)\s*:/', ',' . $m[1], $km );
	$js_settings_keys = array_values( array_unique( $km[1] ) );
}
if ( preg_match( '/payload\.(\w+)\s*=/', $js, $m2 ) ) {
	$js_settings_keys[] = $m2[1];
}
sort( $js_settings_keys );
check( ! empty( $js_settings_keys ), 'znaleziono payload settings w app.js: ' . implode( ', ', $js_settings_keys ) );

// PHP: pętla `foreach ( array( 'api_key', 'model', 'temperature', 'language' ) as $field )`
// w AdminService::save_settings() — jedyne pola, które w ogóle mogą trafić do Settings::save().
$php_settings_keys = array();
if ( preg_match( '/function\s+save_settings.*?foreach\s*\(\s*array\(\s*(.*?)\s*\)\s*as\s*\$field\s*\)/s', $admin, $m3 ) ) {
	preg_match_all( "/'(\w+)'/", $m3[1], $km2 );
	$php_settings_keys = $km2[1];
}
sort( $php_settings_keys );
check( ! empty( $php_settings_keys ), 'znaleziono literalną listę pól w save_settings(): ' . implode( ', ', $php_settings_keys ) );

$missing_in_php = array_diff( $js_settings_keys, $php_settings_keys );
check(
	empty( $missing_in_php ),
	'B (KLUCZOWA): każde pole wysyłane przez JS (' . implode( ',', $js_settings_keys ) . ') jest czytane przez PHP — brakujące: ' . implode( ',', $missing_in_php )
);

// ---------------------------------------------------------------------------
echo "\n=== C. POST /admin/verify — payload JS vs pole czytane przez PHP ===\n";
// ---------------------------------------------------------------------------
$js_verify_keys = array();
if ( preg_match( '/post\(\s*ep\.verify\s*,\s*\{(.*?)\}\s*\)/s', $js, $m4 ) ) {
	preg_match_all( '/,\s*(\w+)\s*:/', ',' . $m4[1], $km3 );
	$js_verify_keys = array_values( array_unique( $km3[1] ) );
}
check( array( 'api_key' ) === $js_verify_keys, 'C1: app.js wysyła do ep.verify dokładnie { api_key } (jest: ' . implode( ',', $js_verify_keys ) . ')' );
check(
	false !== strpos( $admin, "get_param( 'api_key' )" ) && false !== strpos( $admin, 'function verify(' ),
	'C2: AdminService::verify() czyta parametr api_key'
);

// ---------------------------------------------------------------------------
echo "\n=== D. POST /admin/generations/delete — payload JS vs args zadeklarowane w trasie ===\n";
// ---------------------------------------------------------------------------
$js_del_keys = array();
if ( preg_match( '/post\(\s*ep\.generationsDelete\s*,\s*\{(.*?)\}\s*\)/s', $js, $m5 ) ) {
	preg_match_all( '/(\w+)\s*:/', $m5[1], $km4 );
	$js_del_keys = array_values( array_unique( $km4[1] ) );
}
check( array( 'id' ) === $js_del_keys, 'D1: app.js wysyła do ep.generationsDelete dokładnie { id } (jest: ' . implode( ',', $js_del_keys ) . ')' );

// Wytnij blok trasy /admin/generations/delete z RouteRegistrar i sprawdź args.
if ( preg_match( "#'/admin/generations/delete'.*?\\)\\s*\\);#s", $routes, $m6 ) ) {
	check( false !== strpos( $m6[0], "'id'" ), 'D2: trasa /admin/generations/delete deklaruje args[id]' );
} else {
	check( false, 'D2: nie znaleziono bloku rejestracji trasy /admin/generations/delete' );
}

echo "\n=== PODSUMOWANIE ===\n";
echo ( 0 === $fail ) ? "TEST K23 ETAP 3 SEGMENT S10 (app.js <-> REST kontrakt): WSZYSTKIE ASERCJE OK\n" : "TEST K23 ETAP 3 SEGMENT S10 (app.js <-> REST kontrakt): $fail ASERCJI NIE PRZESZŁO\n";
exit( $fail === 0 ? 0 : 1 );
