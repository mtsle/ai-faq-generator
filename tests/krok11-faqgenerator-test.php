<?php
/**
 * Testy: FaqGenerator — rdzeń generatora par FAQ (Krok 11, Etap 4).
 *
 * Broni kontraktu generate() na ATRAPIE providera (bez sieci, bez klucza):
 *  - zawsze array{status,pairs}; NIGDY null, NIGDY wyjątek/warning;
 *  - status ok/empty/error rozdzielone poprawnie (WP_Error → error);
 *  - parser: czysty JSON, ```-fence, proza+JSON, owijka {faqs:[…]}, pojedynczy obiekt,
 *    śmieć/niepełny JSON → empty (nie crash), dedup pytań, cap do count;
 *  - clamp count do [1,50]; temat/opis jako dane.
 *
 * Zawiera oba znaleziska krytyka (Etap 4):
 *  - [WYSOKI] nie-skalarne question/answer → para ODRZUCONA, ZERO warningów;
 *  - [ŚREDNI] lista-wabik (meta/sources) przed faqs → wybieramy faqs, nie wabik.
 *
 * URUCHOMIENIE:  php tests/krok11-faqgenerator-test.php
 * Kod wyjścia: 0 = OK, 1 = błędy.
 *
 * @package AI_FAQ_Generator
 */

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }

// Łapacz warningów/notice — kluczowy dla znaleziska [WYSOKI] (Array to string conversion).
$GLOBALS['aifaq_warnings'] = 0;
set_error_handler(
	function ( $errno, $errstr ) {
		$GLOBALS['aifaq_warnings']++;
		echo "  [PHP WARNING] $errstr\n";
		return true;
	},
	E_WARNING | E_NOTICE | E_DEPRECATED
);

// --- shimy WP ---
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code; private $message;
		public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $t ) { return $t instanceof WP_Error; } }
if ( ! function_exists( 'mb_strtolower' ) ) { function mb_strtolower( $s ) { return strtolower( (string) $s ); } }

require __DIR__ . '/../src/Providers/ProviderInterface.php';
require __DIR__ . '/../src/Faq/FaqGenerator.php';

/**
 * Atrapa providera: oddaje zaprogramowaną wartość, zapamiętuje ostatnie opcje.
 */
class FakeProvider implements \AIFAQ\Providers\ProviderInterface {
	public $last_prompt = '';
	public $last_options = array();
	private $ret;
	public function __construct( $ret ) { $this->ret = $ret; }
	public function generate( string $prompt, array $options = array() ) {
		$this->last_prompt  = $prompt;
		$this->last_options = $options;
		return $this->ret;
	}
	public function embed( array $texts ) { return array(); }
	public function verify() { return true; }
}

$fail = 0;
function check( $cond, $label ) { global $fail; echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n"; if ( ! $cond ) { $fail++; } }

/** Skrót: FaqGenerator na atrapie zwracającej $ret. */
function gen_with( $ret ) {
	return new \AIFAQ\Faq\FaqGenerator( new FakeProvider( $ret ) );
}

$json = function ( $arr ) { return json_encode( $arr, JSON_UNESCAPED_UNICODE ); };

// ===========================================================================
echo "=== A. happy path — czysta lista JSON + structured output do providera ===\n";
$prov = new FakeProvider( $json( array(
	array( 'question' => 'Ile mleka daje krowa?', 'answer' => 'Około 25 litrów dziennie.' ),
	array( 'question' => 'Co jedzą krowy?', 'answer' => 'Trawę i siano.' ),
) ) );
$g   = new \AIFAQ\Faq\FaqGenerator( $prov );
$res = $g->generate( 'Krowy mleczne', 'dla hodowców', 5, 'pl' );
check( is_array( $res ) && isset( $res['status'], $res['pairs'] ), "zwraca array{status,pairs}" );
check( 'ok' === $res['status'], "status ok" );
check( 2 === count( $res['pairs'] ), "2 pary" );
check( 'Ile mleka daje krowa?' === $res['pairs'][0]['question'], "pytanie[0] poprawne" );
check( 'Trawę i siano.' === $res['pairs'][1]['answer'], "odpowiedź[1] poprawna" );
check( 'application/json' === ( $prov->last_options['response_mime_type'] ?? null ), "provider dostał response_mime_type" );
check( isset( $prov->last_options['response_schema']['type'] ) && 'ARRAY' === $prov->last_options['response_schema']['type'], "provider dostał response_schema (ARRAY)" );
check( false !== strpos( $prov->last_prompt, 'Krowy mleczne' ), "temat w promptcie" );
check( false !== strpos( $prov->last_prompt, 'dla hodowców' ), "opis w promptcie" );

echo "\n=== B. cap do count i clamp count[1,50] ===\n";
$five = array();
for ( $i = 1; $i <= 5; $i++ ) { $five[] = array( 'question' => "P$i", 'answer' => "O$i" ); }
$res = gen_with( $json( $five ) )->generate( 'temat', '', 2, 'pl' );
check( 2 === count( $res['pairs'] ), "5 par od modelu, count=2 → przycięte do 2" );
// count=0 → clamp do 1: model zwraca 5, wynik 1.
$res = gen_with( $json( $five ) )->generate( 'temat', '', 0, 'pl' );
check( 1 === count( $res['pairs'] ), "count=0 sklampowane do 1 (1 para)" );

echo "\n=== C. fallback: ```-fence i proza+JSON ===\n";
$fence = "Oto FAQ:\n```json\n" . $json( array( array( 'question' => 'Q?', 'answer' => 'A.' ) ) ) . "\n```\nGotowe.";
$res   = gen_with( $fence )->generate( 't', '', 5, 'pl' );
check( 'ok' === $res['status'] && 1 === count( $res['pairs'] ), "JSON w ```-fence wyłuskany" );
$prose = 'Proszę: ' . $json( array( array( 'question' => 'Q2?', 'answer' => 'A2.' ) ) ) . ' — to wszystko.';
$res   = gen_with( $prose )->generate( 't', '', 5, 'pl' );
check( 'ok' === $res['status'] && 1 === count( $res['pairs'] ), "JSON wycięty z prozy ([..])" );

echo "\n=== D. owijka + znalezisko [ŚREDNI]: wabik przed faqs ===\n";
$res = gen_with( $json( array( 'faqs' => array( array( 'question' => 'Q?', 'answer' => 'A.' ) ) ) ) )->generate( 't', '', 5, 'pl' );
check( 'ok' === $res['status'] && 1 === count( $res['pairs'] ), "owijka {faqs:[…]} rozpoznana" );
// KRYTYK [ŚREDNI]: lista-wabik meta/sources PRZED faqs nie może przesłonić prawdziwych par.
$decoy = array(
	'meta'    => array( 'a', 'b' ),
	'sources' => array( array( 'url' => 'x' ) ),
	'faqs'    => array( array( 'question' => 'Prawdziwe?', 'answer' => 'Tak.' ) ),
);
$res = gen_with( $json( $decoy ) )->generate( 't', '', 5, 'pl' );
check( 'ok' === $res['status'] && 1 === count( $res['pairs'] ), "wabik meta/sources NIE przesłania faqs" );
check( 'Prawdziwe?' === ( $res['pairs'][0]['question'] ?? null ), "wyciągnięto parę z faqs, nie z wabika" );
// Pojedynczy obiekt-para zamiast listy.
$res = gen_with( $json( array( 'question' => 'Sam?', 'answer' => 'Tak.' ) ) )->generate( 't', '', 5, 'pl' );
check( 'ok' === $res['status'] && 1 === count( $res['pairs'] ), "pojedynczy obiekt-para potraktowany jak lista 1-elem" );
// KRYTYK [NISKI]: znany klucz (data) z listą skalarów NIE może przesłonić prawdziwych par pod niestandardowym kluczem.
$res = gen_with( $json( array(
	'myFaqList' => array( array( 'question' => 'Pod dziwnym kluczem?', 'answer' => 'Tak.' ) ),
	'data'      => array( 1, 2, 3 ),
) ) )->generate( 't', '', 5, 'pl' );
check( 'ok' === $res['status'] && 1 === count( $res['pairs'] ), "znany klucz 'data' ze skalarami nie przesłania par pod niestandardowym kluczem" );
check( 'Pod dziwnym kluczem?' === ( $res['pairs'][0]['question'] ?? null ), "wyciągnięto pary z niestandardowego klucza (krok 2)" );

echo "\n=== E. znalezisko [WYSOKI]: nie-skalarne question/answer → odrzucone, ZERO warningów ===\n";
$GLOBALS['aifaq_warnings'] = 0;
$mixed = array(
	array( 'question' => 'Dobra?', 'answer' => 'Tak.' ),
	array( 'question' => 'Zalety?', 'answer' => array( 'Szybkość', 'Cena' ) ), // answer jako lista
	array( 'question' => array( 'a', 'b' ), 'answer' => 'ok' ),                 // question jako lista
	array( 'question' => 'Obiekt?', 'answer' => array( 'x' => 'y' ) ),          // answer jako obiekt
);
$res = gen_with( $json( $mixed ) )->generate( 't', '', 5, 'pl' );
check( 0 === $GLOBALS['aifaq_warnings'], "ZERO warningów PHP (brak Array to string conversion)" );
check( 1 === count( $res['pairs'] ), "tylko 1 poprawna para (3 nie-skalarne odsiane)" );
check( 'Dobra?' === $res['pairs'][0]['question'] && 'Tak.' === $res['pairs'][0]['answer'], "zachowana wyłącznie para stringowa" );
check( false === strpos( json_encode( $res['pairs'] ), 'Array' ), "brak śmieciowej pary 'Array'" );
// Gdy WSZYSTKIE pary nie-skalarne → empty (nie ok z 0 par).
$res = gen_with( $json( array( array( 'question' => 'q', 'answer' => array( 'x' ) ) ) ) )->generate( 't', '', 5, 'pl' );
check( 'empty' === $res['status'] && array() === $res['pairs'], "same nie-skalarne → empty" );

echo "\n=== F. śmieć/edge → empty, bez crasha ===\n";
$GLOBALS['aifaq_warnings'] = 0;
foreach ( array(
	'"zwykły tekst"'  => '"zwykły tekst"',
	'123'             => '123',
	'true'            => 'true',
	'null-literal'    => 'null',
	'pusta tablica'   => '[]',
	'lista skalarów'  => '[1,2,3]',
	'niepełny JSON'   => '[{"question":"q","answer":',
	'proza bez JSON'  => 'Nie mam nic do dodania.',
	'pusty string'    => '',
) as $label => $payload ) {
	$res = gen_with( $payload )->generate( 't', '', 5, 'pl' );
	check( 'empty' === $res['status'] && array() === $res['pairs'], "wejscie [{$label}] -> empty" );
}
check( 0 === $GLOBALS['aifaq_warnings'], "śmieciowe wejścia nie wywołały warningów" );

echo "\n=== G. statusy: WP_Error, pusty temat, pusty string providera ===\n";
$res = gen_with( new WP_Error( 'aifaq_gemini_http', 'boom' ) )->generate( 'temat', '', 5, 'pl' );
check( 'error' === $res['status'] && array() === $res['pairs'], "WP_Error providera → error" );
$prov2 = new FakeProvider( '[]' );
$g2    = new \AIFAQ\Faq\FaqGenerator( $prov2 );
$res   = $g2->generate( '   ', '', 5, 'pl' );
check( 'empty' === $res['status'], "pusty/whitespace temat → empty" );
check( '' === $prov2->last_prompt, "przy pustym temacie NIE wołamy providera" );
$res = gen_with( '' )->generate( 'temat', '', 5, 'pl' );
check( 'empty' === $res['status'], "pusty string od providera → empty (nie error)" );

echo "\n=== H. dedup pytań (różna wielkość liter) ===\n";
$dupes = array(
	array( 'question' => 'Ile to kosztuje?', 'answer' => 'Sto.' ),
	array( 'question' => 'ILE TO KOSZTUJE?', 'answer' => 'Sto ponownie.' ),
	array( 'question' => 'Co dalej?', 'answer' => 'Nic.' ),
);
$res = gen_with( $json( $dupes ) )->generate( 't', '', 5, 'pl' );
check( 2 === count( $res['pairs'] ), "duplikat pytania (case-insensitive) scalony → 2 pary" );

echo "\n=== PODSUMOWANIE ===\n";
restore_error_handler();
echo ( 0 === $fail ) ? "TEST KROK 11 (faqgenerator): WSZYSTKIE ASERCJE OK\n" : "TEST KROK 11 (faqgenerator): $fail ASERCJI NIE PRZESZŁO\n";
exit( $fail === 0 ? 0 : 1 );
