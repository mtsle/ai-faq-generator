<?php
/**
 * Strażnik CSP standalone — zero inline event-handlerów i inline `style=`
 * w plikach renderujących panel właściciela na trasie `/faqgenerator` (Krok 21).
 *
 * CSP standalone (`SecurityHeaders::headers_for(true, ...)`) ma
 * `script-src 'self' 'nonce-…'` BEZ `'unsafe-inline'` i `style-src 'self'`
 * BEZ `'unsafe-inline'`. Nonce chroni TYLKO znaczniki `<script nonce="…">` —
 * atrybuty `onclick=`/`onerror=`/… i atrybuty `style=` przeglądarka blokuje
 * BEZWZGLĘDNIE, nonce ich nie odblokowuje. Gdyby ktoś w przyszłości dopisał
 * taki atrybut do któregoś z plików renderujących panel właściciela
 * (osadzony w standalone przez `AppShell::render_body()`), przycisk po
 * prostu przestałby działać — cicho, bez błędu w PHP, widocznego dopiero
 * w konsoli przeglądarki. Ten strażnik łapie to STATYCZNIE, przed wydaniem.
 *
 * Liczy realne WYSTĄPIENIA atrybutów w źródle (nie samo `strpos` na nazwie),
 * żeby nie dać się nabrać komentarzowi wspominającemu "onclick" (lekcja
 * z v0.25.0 — `function_exists()`/komentarze fałszują `strpos` na źródle).
 *
 * URUCHOMIENIE:  php tests/krok21-csp-inline-guard-test.php
 * Kod wyjścia: 0 = OK, 1 = błędy.
 *
 * @package AI_FAQ_Generator
 */

$fail = 0;
$ran  = 0;
function check( $cond, $label ) {
	global $fail, $ran;
	++$ran;
	echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n";
	if ( ! $cond ) { ++$fail; }
}

/**
 * Pliki, których markup trafia na trasę standalone `/faqgenerator`
 * (bezpośrednio przez GeneratorPage::render_standalone() albo pośrednio przez
 * AppShell::render_body(), gdy renderujący jest właścicielem).
 */
$files = array(
	'src/PublicUi/GeneratorPage.php',
	'src/App/AppShell.php',
	'src/App/HistoryPanel.php',
	'src/App/GenerationsPanel.php',
	'src/App/FaqToolPanel.php',
	'src/Faq/PublicFaq.php',
);

$attr_pattern = '/\s(on[a-z]+|style)\s*=\s*["\']/i';

foreach ( $files as $rel ) {
	$path = __DIR__ . '/../' . $rel;
	check( file_exists( $path ), $rel . ': plik istnieje' );
	if ( ! file_exists( $path ) ) {
		continue;
	}

	$src = (string) file_get_contents( $path );

	// Usuwamy komentarze PHP i HTML PRZED szukaniem — inaczej wzmianka o
	// "onclick" w docblocku (jak w tym pliku testowym) dałaby fałszywy alarm.
	$stripped = preg_replace( '#/\*.*?\*/#s', '', $src );
	$stripped = preg_replace( '#//.*$#m', '', (string) $stripped );
	$stripped = preg_replace( '/<!--.*?-->/s', '', (string) $stripped );

	$matches = array();
	preg_match_all( $attr_pattern, (string) $stripped, $matches );

	check(
		empty( $matches[1] ),
		$rel . ': zero atrybutów inline (onclick=/style=/…) — CSP standalone (script-src/style-src bez unsafe-inline) ich nie przepuści (znaleziono: ' . implode( ',', array_unique( $matches[1] ?? array() ) ) . ')'
	);
}

// Regresja pozytywna: sam wzorzec realnie wykrywa atrybut, gdy jest.
check( 1 === preg_match( $attr_pattern, ' onclick="x()"' ), 'wzorzec wykrywa onclick=' );
check( 1 === preg_match( $attr_pattern, ' style="color:red"' ), 'wzorzec wykrywa style=' );
check( 0 === preg_match( $attr_pattern, ' data-onclick-hint="x"' ), 'wzorzec NIE łapie atrybutów data-* zawierających "onclick" w nazwie' );

echo "\n" . ( 0 === $fail ? '=== WSZYSTKIE OK (asercji: ' . $ran . ') ===' : "=== BŁĘDÓW: $fail (asercji: $ran) ===" ) . "\n";
exit( 0 === $fail ? 0 : 1 );
