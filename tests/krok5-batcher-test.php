<?php
/**
 * Test regresyjny Kroku 5 — EmbeddingBatcher (paczki ≤100 nad providerem).
 *
 * Na atrapie ProviderInterface: rozmiary paczek, kolejność wektorów, pusta lista,
 * propagacja WP_Error, wykrycie niezgodnej liczby wektorów, przycięcie batch_size.
 *
 * URUCHOMIENIE:  php tests/krok5-batcher-test.php
 * Kod wyjścia: 0 = OK, 1 = błędy.
 *
 * @package AI_FAQ_Generator
 */

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code; private $message;
		public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
		public function get_error_message() { return $this->message; }
		public function get_error_code() { return $this->code; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $t ) { return $t instanceof WP_Error; } }
if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }

require __DIR__ . '/../src/Providers/ProviderInterface.php';
require __DIR__ . '/../src/Index/EmbeddingBatcher.php';

use AIFAQ\Index\EmbeddingBatcher;
use AIFAQ\Providers\ProviderInterface;

/**
 * Atrapa providera: zapisuje rozmiary paczek; wektor = [liczba z tekstu „t{n}"].
 * Można ustawić tryb błędu lub „gubienia" wektorów.
 */
class SpyProvider implements ProviderInterface {
	public $batch_sizes = array();
	public $mode        = 'ok'; // 'ok' | 'error' | 'short'
	public function generate( string $prompt, array $options = array() ) { return ''; }
	public function embed( array $texts ) {
		$this->batch_sizes[] = count( $texts );
		if ( 'error' === $this->mode ) {
			return new WP_Error( 'boom', 'awaria' );
		}
		$vecs = array();
		foreach ( $texts as $t ) {
			$vecs[] = array( (float) (int) substr( $t, 1 ) ); // „t7" → [7.0]
		}
		if ( 'short' === $this->mode ) {
			array_pop( $vecs ); // zwróć o jeden za mało.
		}
		return $vecs;
	}
}

$fail = 0;
function check( $cond, $label ) {
	global $fail;
	echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n";
	if ( ! $cond ) { $fail++; }
}

// 250 tekstów: „t0".."t249".
$texts = array();
for ( $i = 0; $i < 250; $i++ ) { $texts[] = 't' . $i; }

// ===========================================================================
echo "=== A. Podział na paczki + kolejność ===\n";
$spy = new SpyProvider();
$b   = new EmbeddingBatcher( $spy, 100 );
$res = $b->embed_all( $texts );
check( is_array( $res ) && 250 === count( $res ), "250 tekstów → 250 wektorów" );
check( array( 100, 100, 50 ) === $spy->batch_sizes, "paczki: [100, 100, 50]" );
check( array( 0.0 ) === $res[0] && array( 249.0 ) === $res[249], "kolejność wektorów zachowana (0..249)" );

echo "\n=== B. Pusta lista ===\n";
$spy2 = new SpyProvider();
$b2   = new EmbeddingBatcher( $spy2, 100 );
check( array() === $b2->embed_all( array() ), "pusta lista → [] (bez wołania providera)" );
check( array() === $spy2->batch_sizes, "provider nie wołany dla pustej listy" );

echo "\n=== C. Propagacja błędu providera ===\n";
$spyE = new SpyProvider();
$spyE->mode = 'error';
$rE = ( new EmbeddingBatcher( $spyE, 100 ) )->embed_all( array( 'a', 'b' ) );
check( is_wp_error( $rE ) && 'boom' === $rE->get_error_code(), "WP_Error providera propagowany bez zmian" );

echo "\n=== D. Niezgodna liczba wektorów ===\n";
$spyS = new SpyProvider();
$spyS->mode = 'short';
$rS = ( new EmbeddingBatcher( $spyS, 100 ) )->embed_all( array( 'a', 'b', 'c' ) );
check( is_wp_error( $rS ) && 'aifaq_embed_count' === $rS->get_error_code(), "za mało wektorów → WP_Error aifaq_embed_count" );

echo "\n=== E. Przycięcie batch_size ===\n";
$spyC = new SpyProvider();
( new EmbeddingBatcher( $spyC, 999 ) )->embed_all( $texts );
$maxb = max( $spyC->batch_sizes );
check( $maxb <= EmbeddingBatcher::MAX_BATCH, "batch_size>100 przycięty do 100 (max paczka=" . $maxb . ')' );

echo "\n=== PODSUMOWANIE ===\n";
echo ( 0 === $fail ) ? "TEST KROK 5 (EmbeddingBatcher): WSZYSTKIE ASERCJE OK\n" : "TEST KROK 5 (EmbeddingBatcher): $fail ASERCJI NIE PRZESZŁO\n";
exit( $fail === 0 ? 0 : 1 );
