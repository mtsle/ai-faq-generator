<?php
/**
 * Krok 23 etap 3, segment S1 (rdzeń czysty) — łatanie luk znalezionych w audycie
 * "RWA pre-release" (Faza A planu segmentów testowych): `PairsInput::from_request()`
 * (ścieżka eksportu) i `PublishService::export()` nie miały ŻADNEJ bezpośredniej
 * asercji — tylko `PairsInput::from_request_for_publish()` (inna metoda tej samej
 * klasy) była testowana wprost. Dodatkowo: `Chunker` na tekście bez separatorów
 * spacją (CJK) i na pojedynczym zdaniu dłuższym niż target.
 *
 * Pokrywa:
 *  A. `PairsInput::from_request()` — sanityzacja, odrzucanie nieskalarnych/pustych,
 *     cap `Exporter::MAX_PAIRS`, wejście nie-tablicowe.
 *  B. `PublishService::export()` — integracja end-to-end (REST → sanityzacja →
 *     Exporter::export()): złośliwe pary trafiają do 5 formatów już bez skryptu,
 *     pusta lista → 400.
 *  C. `Chunker` — tekst CJK (brak spacji, tail() nie ma na czym przycinać granicy
 *     słowa) i pojedyncze zdanie dłuższe niż target (naturalna interpunkcja, nie
 *     tylko "jedno słowo" jak w krok5-chunker-test.php sekcja D).
 *
 * URUCHOMIENIE:  php -d extension=mbstring tests/krok23-etap3-s1-core-test.php
 * Kod wyjścia: 0 = OK, 1 = błędy.
 *
 * @package AI_FAQ_Generator
 */

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }

// --- shimy WP (te same wzorce co krok23-etap1-rest-sanityzacja-test.php) ---
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'wp_unslash' ) ) { function wp_unslash( $s ) { return is_string( $s ) ? stripslashes( $s ) : $s; } }
if ( ! function_exists( 'sanitize_textarea_field' ) ) { function sanitize_textarea_field( $s ) { return trim( (string) preg_replace( '/<[^>]*>/', '', (string) $s ) ); } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ) { return trim( (string) preg_replace( '/<[^>]*>/', '', (string) $s ) ); } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); } }

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		private $data; private $status;
		public function __construct( $data = null, $status = 200 ) { $this->data = $data; $this->status = (int) $status; }
		public function get_data() { return $this->data; }
		public function get_status() { return $this->status; }
	}
}
if ( ! class_exists( 'WP_REST_Request' ) ) {
	// export() ma type hint `WP_REST_Request $request` — atrapa MUSI nosić tę
	// nazwę klasy (nie tylko duck-typing), inaczej PHP rzuca TypeError przy wywołaniu.
	class WP_REST_Request {
		private $p;
		public function __construct( array $p = array() ) { $this->p = $p; }
		public function get_param( $k ) { return $this->p[ $k ] ?? null; }
	}
}

require __DIR__ . '/../src/Faq/Exporter.php';
require __DIR__ . '/../src/Rest/PairsInput.php';
require __DIR__ . '/../src/Index/Chunker.php';

// PublishService::export() nie touch'uje GenerationRepository/PageGuard/PublicFaq
// (te są używane tylko wewnątrz publish()/unpublish(), których ten segment nie
// wywołuje) — `use AIFAQ\Data\GenerationRepository;` w tym pliku to tylko alias,
// PHP nie wymaga załadowania klasy dopóki nie jest faktycznie instancjonowana.
require __DIR__ . '/../src/Rest/PublishService.php';

use AIFAQ\Faq\Exporter;
use AIFAQ\Rest\PairsInput;
use AIFAQ\Rest\PublishService;
use AIFAQ\Index\Chunker;

$fail = 0;
function check( $cond, $label ) { global $fail; echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n"; if ( ! $cond ) { $fail++; } }
function mlen( $s ) { return function_exists( 'mb_strlen' ) ? mb_strlen( $s ) : strlen( $s ); }

// ===========================================================================
echo "=== A. PairsInput::from_request() (ścieżka eksportu) ===\n";
// ===========================================================================
check( array() === PairsInput::from_request( null ), 'A1: wejście null → []' );
check( array() === PairsInput::from_request( 'nie-tablica' ), 'A2: wejście string → []' );
check( array() === PairsInput::from_request( array( 'nie-tablica-item' ) ), 'A3: element nie-tablica → pominięty' );
check( array() === PairsInput::from_request( array( array( 'question' => array( 'x' ), 'answer' => 'a' ) ) ), 'A4: question nieskalarne (tablica) → pominięte' );
check( array() === PairsInput::from_request( array( array( 'question' => 'q', 'answer' => '   ' ) ) ), 'A5: answer puste po trim → pominięte' );
check( array() === PairsInput::from_request( array( array( 'answer' => 'tylko odpowiedź' ) ) ), 'A6: brak klucza question → pominięte (domyślne "")' );

$sanit = PairsInput::from_request( array( array( 'question' => '<script>x</script>Czy to bezpieczne?', 'answer' => 'Tak.' ) ) );
check( 1 === count( $sanit ), 'A7: poprawna para z domieszką HTML przechodzi' );
check( false === strpos( $sanit[0]['question'], '<script' ), 'A8: <script> usunięty przez sanitize_textarea_field' );
check( false !== strpos( $sanit[0]['question'], 'Czy to bezpieczne' ), 'A9: właściwa treść zachowana' );

$duzo = array();
for ( $i = 0; $i < Exporter::MAX_PAIRS + 20; $i++ ) { $duzo[] = array( 'question' => "Q$i?", 'answer' => "A$i." ); }
$capped = PairsInput::from_request( $duzo );
check( Exporter::MAX_PAIRS === count( $capped ), 'A10: cap do MAX_PAIRS (' . Exporter::MAX_PAIRS . '), jest: ' . count( $capped ) );
check( 'Q0?' === $capped[0]['question'], 'A11: cap zachowuje KOLEJNOŚĆ od początku (pierwsza para = Q0)' );

// from_request() celowo NIE przycina długości pojedynczego pola (robi to dopiero
// Exporter::normalize() w export()) — dokumentujemy ten kontrakt wprost, żeby
// przyszła zmiana w jednym miejscu nie rozjechała się cicho z drugim.
$dlugie = PairsInput::from_request( array( array( 'question' => str_repeat( 'q', 1000 ), 'answer' => 'a' ) ) );
check( 1000 === mlen( $dlugie[0]['question'] ), 'A12 (kontrakt): from_request() NIE przycina długości — to zadanie Exporter::normalize()' );

// ===========================================================================
echo "\n=== B. PublishService::export() — integracja REST end-to-end ===\n";
// ===========================================================================
$svc = new PublishService();

$resp_empty = $svc->export( new WP_REST_Request( array( 'pairs' => array() ) ) );
check( 400 === $resp_empty->get_status(), 'B1: pary puste → 400' );
check( 'error' === ( $resp_empty->get_data()['status'] ?? '' ), 'B2: status "error" przy pustej liście' );

$resp_null = $svc->export( new WP_REST_Request( array() ) );
check( 400 === $resp_null->get_status(), 'B3: brak parametru pairs → 400 (nie fatal)' );

$zlosliwe = array(
	array(
		'question' => '<script>alert(1)</script>Ile to kosztuje?',
		'answer'   => 'Cena to <b onclick="steal()">100 zł</b>, koniec.',
	),
);
$resp_ok = $svc->export( new WP_REST_Request( array( 'pairs' => $zlosliwe ) ) );
check( 200 === $resp_ok->get_status(), 'B4: poprawna para → 200' );
$data = $resp_ok->get_data();
check( 'ok' === ( $data['status'] ?? '' ), 'B5: status "ok"' );
foreach ( array( 'html', 'gutenberg', 'json', 'jsonld' ) as $fmt ) {
	check( false === strpos( $data[ $fmt ] ?? '', '<script' ), "B6.$fmt: format $fmt nie zawiera <script>" );
	check( false === strpos( $data[ $fmt ] ?? '', 'onclick' ), "B7.$fmt: format $fmt nie zawiera onclick" );
}
check( false !== strpos( $data['html'] ?? '', 'Ile to kosztuje' ), 'B8: treść pytania widoczna w HTML mimo sanityzacji' );

// Elementor to JSON — sam wp_json_encode ucieka <script>, ale tab_content/tab_title
// renderuje się jako HTML po stronie Elementora (patrz docblock Exporter::to_elementor);
// sprawdzamy, że po zdekodowaniu JSON-a znacznik zniknął (nie tylko że jest zescape'owany).
$el = json_decode( $data['elementor'] ?? '', true );
$tab = $el['content'][0]['elements'][0]['elements'][0]['settings']['tabs'][0] ?? array();
check( false === strpos( $tab['tab_title'] ?? '<brak>', '<script' ), 'B9: Elementor tab_title bez <script> po dekodowaniu JSON' );
check( false === strpos( $tab['tab_content'] ?? '<brak>', 'onclick' ), 'B10: Elementor tab_content bez onclick po dekodowaniu JSON' );

// ===========================================================================
echo "\n=== C. Chunker — przypadki brzegowe nieujęte w krok5-chunker-test.php ===\n";
// ===========================================================================
// C1: CJK — brak spacji w ogóle, tail() nie ma granicy słowa do przycięcia.
$cjk_char = '文'; // 1 znak wielobajtowy, bez odpowiednika spacji.
$cjk_text = str_repeat( $cjk_char, 300 );
$cc  = new Chunker( 50, 15 );
$chc = $cc->chunk( $cjk_text );
check( count( $chc ) > 1, 'C1a: tekst CJK (300 znaków, bez spacji) → wiele fragmentów (' . count( $chc ) . ')' );
$all_valid_cjk = true;
foreach ( $chc as $frag ) { if ( 1 !== preg_match( '//u', $frag ) ) { $all_valid_cjk = false; } }
check( $all_valid_cjk, 'C1b: każdy fragment CJK to poprawny UTF-8 (żaden znak nie przecięty)' );
// apply_overlap() skleja spacją ASCII nawet gdy oba boki to CJK (brak naturalnego
// separatora) — usuwamy TE sztuczne spacje i sprawdzamy, że nic INNEGO się nie
// wmieszało: każdy pozostały znak to dokładnie $cjk_char, żaden bajt nie uciekł
// jako śmieć/znak zastępczy przy cięciu wielobajtowym.
$only_cjk_and_spaces = true;
foreach ( $chc as $frag ) {
	if ( '' !== str_replace( array( $cjk_char, ' ' ), '', $frag ) ) { $only_cjk_and_spaces = false; }
}
check( $only_cjk_and_spaces, 'C1c: każdy fragment CJK zawiera WYŁĄCZNIE ' . $cjk_char . ' i sklejające spacje (brak śmieci z cięcia wielobajtowego)' );

// C2: pojedyncze "zdanie" (z naturalną interpunkcją) dłuższe niż target — inny
// kod niż krok5-chunker-test.php sekcja D (tam było jedno "słowo" bez kropki).
$dlugie_zdanie = 'To jest jedno bardzo długie zdanie opisujące ofertę przedszkola bez żadnej kropki w środku aż do samego końca gdzie w końcu się kończy.';
check( mlen( $dlugie_zdanie ) > 60, 'C2 (założenie): zdanie testowe dłuższe niż target=60' );
$cs  = new Chunker( 60, 0 );
$chs = $cs->chunk( $dlugie_zdanie );
check( count( $chs ) > 1, 'C3: pojedyncze zdanie > target → twardy podział na wiele fragmentów (' . count( $chs ) . ')' );
$maxlen_s = 0;
foreach ( $chs as $f ) { $maxlen_s = max( $maxlen_s, mlen( $f ) ); }
check( $maxlen_s <= 60, 'C4: każdy kawałek zdania ≤ target=60 (max=' . $maxlen_s . ')' );
check( trim( $dlugie_zdanie ) === trim( implode( '', $chs ) ), 'C5: sklejenie kawałków = oryginalne zdanie (bez zgubienia treści, overlap=0)' );

// ===========================================================================
echo "\n=== PODSUMOWANIE ===\n";
echo ( 0 === $fail ) ? "TEST K23 ETAP 3 SEGMENT S1 (rdzeń czysty): WSZYSTKIE ASERCJE OK\n" : "TEST K23 ETAP 3 SEGMENT S1 (rdzeń czysty): $fail ASERCJI NIE PRZESZŁO\n";
exit( $fail === 0 ? 0 : 1 );
