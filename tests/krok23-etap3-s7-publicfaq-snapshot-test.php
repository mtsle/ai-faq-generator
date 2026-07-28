<?php
/**
 * Krok 23 etap 3, segment S7 (eksporty + JSON-LD/SEO) — łatanie luki z Fazy A:
 * `PublicFaq::snapshot_previous()`/`OPTION_PREV` (K23 etap 1, znalezisko B6 — publikacja/
 * zdjęcie FAQ było NIEODWRACALNE jednym kliknięciem, naprawione snapshotem ostatniej
 * wersji) miało dotąd ZERO testów behawioralnych. Jedyne dotychczasowe dotknięcie to
 * `krok19-migracja-test.php`, które sprawdza WYŁĄCZNIE, że `uninstall.php` kasuje opcję
 * `aifaq_public_faq_prev` po nazwie — nie że mechanizm snapshotu faktycznie DZIAŁA.
 *
 * UWAGA: druga luka z Fazy A ("emisja JSON-LD gdy podstrona jest w koszu") sprawdzona
 * i uznana za JUŻ pokrytą — `seo-jsonld-test.php` testuje `post_status = 'draft'` na
 * DOKŁADNIE tej samej gałęzi kodu (`PageSchema::target_post()`: `'publish' !== $post->
 * post_status`), więc scenariusz "trash" byłby identycznym testem tej samej linii,
 * bez nowej informacji — pominięty świadomie, nie przeoczony.
 *
 * Pokrywa:
 *  A. Pierwsza publikacja (opcja OPTION jeszcze nie istnieje) → BRAK snapshotu
 *     (nie ma czego zachować, `OPTION_PREV` zostaje nietknięte).
 *  B. Druga publikacja (nadpisanie istniejących par) → poprzednia wersja trafia
 *     do `OPTION_PREV` PRZED nadpisaniem `OPTION` nowymi parami.
 *  C. `unpublish()` po publikacji → bieżąca wersja trafia do `OPTION_PREV` PRZED
 *     skasowaniem `OPTION` (dowód, że zdjęcie też jest objęte siecią bezpieczeństwa).
 *  D. Publikacja pustej/niepoprawnej listy (po `normalize()` daje `[]`) → `publish()`
 *     zwraca 0 i NIE dotyka ani `OPTION`, ani `OPTION_PREV` (żadnego nadpisania).
 *  E. Snapshot NIE nadpisuje się samą pustką: jeśli opcja PRZED nadpisaniem była już
 *     pusta/nieistniejąca, `OPTION_PREV` zostaje nietknięte (nie warto "zachowywać nicości").
 *
 * URUCHOMIENIE:  php tests/krok23-etap3-s7-publicfaq-snapshot-test.php
 * Kod wyjścia: 0 = OK, 1 = błędy.
 *
 * @package AI_FAQ_Generator
 */

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }

if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'current_time' ) ) { function current_time( $t ) { return '2026-07-28 12:00:00'; } }

$GLOBALS['__opt'] = array();
if ( ! function_exists( 'get_option' ) ) { function get_option( $k, $d = false ) { return $GLOBALS['__opt'][ $k ] ?? $d; } }
if ( ! function_exists( 'update_option' ) ) { function update_option( $k, $v, $autoload = null ) { $GLOBALS['__opt'][ $k ] = $v; return true; } }
if ( ! function_exists( 'delete_option' ) ) { function delete_option( $k ) { unset( $GLOBALS['__opt'][ $k ] ); return true; } }

require __DIR__ . '/../src/Faq/PublicFaq.php';

use AIFAQ\Faq\PublicFaq;

$fail = 0;
function check( $cond, $label ) { global $fail; echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n"; if ( ! $cond ) { $fail++; } }

$pary1 = array( array( 'question' => 'Ile kosztuje?', 'answer' => '500 zł.' ) );
$pary2 = array( array( 'question' => 'Jakie godziny?', 'answer' => '7-17.' ), array( 'question' => 'Gdzie jesteście?', 'answer' => 'Warszawa.' ) );

// ===========================================================================
echo "=== A. Pierwsza publikacja — brak snapshotu (nic wcześniej nie było) ===\n";
// ===========================================================================
$GLOBALS['__opt'] = array();
check( 1 === PublicFaq::publish( $pary1, 10 ), 'A1: publish() zwraca liczbę par (1)' );
check( ! array_key_exists( PublicFaq::OPTION_PREV, $GLOBALS['__opt'] ), 'A2: OPTION_PREV NIE powstało (nie było co zachować)' );
check( $pary1[0]['question'] === ( $GLOBALS['__opt'][ PublicFaq::OPTION ]['pairs'][0]['question'] ?? '' ), 'A3: OPTION zawiera nowe pary' );

// ===========================================================================
echo "\n=== B. Druga publikacja (nadpisanie) — poprzednia wersja trafia do OPTION_PREV ===\n";
// ===========================================================================
check( 2 === PublicFaq::publish( $pary2, 20 ), 'B1: druga publikacja zwraca liczbę nowych par (2)' );
check(
	'Ile kosztuje?' === ( $GLOBALS['__opt'][ PublicFaq::OPTION_PREV ]['pairs'][0]['question'] ?? '' ),
	'B2 (KLUCZOWA): OPTION_PREV zawiera POPRZEDNIĄ wersję (pary1), zanim OPTION dostało pary2'
);
check(
	'Jakie godziny?' === ( $GLOBALS['__opt'][ PublicFaq::OPTION ]['pairs'][0]['question'] ?? '' ),
	'B3: OPTION zawiera już NOWĄ wersję (pary2)'
);
check( 10 === ( $GLOBALS['__opt'][ PublicFaq::OPTION_PREV ]['generation_id'] ?? -1 ), 'B4: OPTION_PREV zachowuje też metadane starej wersji (generation_id=10)' );

// ===========================================================================
echo "\n=== C. unpublish() po publikacji — bieżąca wersja trafia do OPTION_PREV ===\n";
// ===========================================================================
$GLOBALS['__opt'] = array();
PublicFaq::publish( $pary1, 5 );
PublicFaq::unpublish();
check( ! array_key_exists( PublicFaq::OPTION, $GLOBALS['__opt'] ), 'C1: OPTION skasowane przez unpublish()' );
check(
	'Ile kosztuje?' === ( $GLOBALS['__opt'][ PublicFaq::OPTION_PREV ]['pairs'][0]['question'] ?? '' ),
	'C2 (KLUCZOWA): unpublish() TEŻ robi snapshot — zdjęcie z podstrony jest odwracalne z bazy'
);

// ===========================================================================
echo "\n=== D. Publikacja pustej/niepoprawnej listy → 0, ZERO dotknięcia opcji ===\n";
// ===========================================================================
$GLOBALS['__opt'] = array( PublicFaq::OPTION => array( 'pairs' => $pary1, 'generation_id' => 1 ) );
$niepoprawne = array( array( 'question' => '', 'answer' => '' ) ); // normalize() odrzuci — pusta para.
check( 0 === PublicFaq::publish( $niepoprawne, 99 ), 'D1: publish() z samymi pustymi parami zwraca 0' );
check(
	'Ile kosztuje?' === ( $GLOBALS['__opt'][ PublicFaq::OPTION ]['pairs'][0]['question'] ?? '' ),
	'D2: OPTION NIETKNIĘTE (stara publikacja przetrwała nieudaną próbę)'
);
check( ! array_key_exists( PublicFaq::OPTION_PREV, $GLOBALS['__opt'] ), 'D3: OPTION_PREV też NIE powstało (nic nie nadpisano, nic do zachowania)' );

// ===========================================================================
echo "\n=== E. Snapshot nie zachowuje pustki — OPTION_PREV nietknięte gdy PRZED było puste ===\n";
// ===========================================================================
$GLOBALS['__opt'] = array( PublicFaq::OPTION => array( 'pairs' => array(), 'generation_id' => 0 ) );
PublicFaq::publish( $pary1, 1 );
check( ! array_key_exists( PublicFaq::OPTION_PREV, $GLOBALS['__opt'] ), 'E1: OPTION_PREV nietknięte — poprzedni stan był pusty, nie warto go "zachowywać"' );

// ===========================================================================
echo "\n=== PODSUMOWANIE ===\n";
echo ( 0 === $fail ) ? "TEST K23 ETAP 3 SEGMENT S7 (PublicFaq — snapshot OPTION_PREV): WSZYSTKIE ASERCJE OK\n" : "TEST K23 ETAP 3 SEGMENT S7 (PublicFaq — snapshot OPTION_PREV): $fail ASERCJI NIE PRZESZŁO\n";
exit( $fail === 0 ? 0 : 1 );
