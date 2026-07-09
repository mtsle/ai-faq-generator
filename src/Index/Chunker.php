<?php
/**
 * Dzielenie treści na fragmenty (chunking) pod embeddingi/RAG.
 *
 * Czysta logika bez zależności od WordPressa, bazy ani sieci: bierze zwykły
 * tekst (już bez HTML) i zwraca listę nakładających się fragmentów o zadanym
 * rozmiarze, tnąc na granicy zdań/akapitów (a dopiero w ostateczności twardo).
 * Nakładka (overlap) zachowuje kontekst na styku fragmentów, co poprawia
 * trafność wyszukiwania w Retrieverze.
 *
 * @package AI_FAQ_Generator
 */

namespace AIFAQ\Index;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Chunker treści.
 */
class Chunker {

	/**
	 * Docelowy rozmiar fragmentu w znakach.
	 *
	 * @var int
	 */
	private int $target;

	/**
	 * Wielkość nakładki (znaki z końca poprzedniego fragmentu doklejane na
	 * początek następnego).
	 *
	 * @var int
	 */
	private int $overlap;

	/**
	 * Konstruktor.
	 *
	 * @param int $target  Docelowy rozmiar fragmentu w znakach (min. 1).
	 * @param int $overlap Nakładka w znakach (0 = brak; zawsze < target).
	 */
	public function __construct( int $target = 1000, int $overlap = 200 ) {
		$this->target  = max( 1, $target );
		$this->overlap = max( 0, min( $overlap, $this->target - 1 ) );
	}

	/**
	 * Dzieli tekst na listę fragmentów.
	 *
	 * @param string $text Zwykły tekst (bez HTML).
	 * @return array<int,string> Lista fragmentów (bez pustych).
	 */
	public function chunk( string $text ): array {
		$text = $this->normalize( $text );
		if ( '' === $text ) {
			return array();
		}

		// 1) Jednostki = zdania/akapity; zbyt długie tniemy twardo do rozmiaru celu.
		$units = array();
		foreach ( $this->split_units( $text ) as $unit ) {
			if ( $this->len( $unit ) > $this->target ) {
				foreach ( $this->hard_split( $unit, $this->target ) as $piece ) {
					$units[] = $piece;
				}
			} else {
				$units[] = $unit;
			}
		}

		// 2) Zachłanne pakowanie jednostek do fragmentów ≤ target.
		$chunks = $this->pack( $units );

		// 3) Nakładka: doklej ogon poprzedniego fragmentu na początek następnego.
		return $this->apply_overlap( $chunks );
	}

	/**
	 * Normalizuje białe znaki (CRLF→LF, zwija spacje i puste linie), trymuje.
	 *
	 * @param string $text Tekst wejściowy.
	 * @return string
	 */
	private function normalize( string $text ): string {
		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );
		$text = (string) preg_replace( '/[ \t]+/u', ' ', $text );
		$text = (string) preg_replace( '/\n{2,}/u', "\n", $text );
		return trim( $text );
	}

	/**
	 * Dzieli tekst na jednostki: zdania (po . ! ? …) oraz linie/akapity.
	 *
	 * @param string $text Znormalizowany tekst.
	 * @return array<int,string>
	 */
	private function split_units( string $text ): array {
		$parts = preg_split( '/(?<=[.!?…])\s+|\n+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $parts ) ) {
			return array( $text );
		}
		return array_values( array_filter( array_map( 'trim', $parts ), 'strlen' ) );
	}

	/**
	 * Zachłannie skleja jednostki w fragmenty nieprzekraczające celu.
	 *
	 * @param array<int,string> $units Jednostki tekstu.
	 * @return array<int,string>
	 */
	private function pack( array $units ): array {
		$chunks = array();
		$cur    = '';

		foreach ( $units as $unit ) {
			$candidate = ( '' === $cur ) ? $unit : $cur . ' ' . $unit;
			if ( '' !== $cur && $this->len( $candidate ) > $this->target ) {
				$chunks[] = $cur;
				$cur      = $unit;
			} else {
				$cur = $candidate;
			}
		}

		if ( '' !== trim( $cur ) ) {
			$chunks[] = $cur;
		}

		return $chunks;
	}

	/**
	 * Dokleja nakładkę: ogon poprzedniego (oryginalnego) fragmentu na początek
	 * każdego kolejnego. Korzysta z kopii bazowej, więc nakładki się nie kumulują.
	 *
	 * @param array<int,string> $chunks Fragmenty po pakowaniu.
	 * @return array<int,string>
	 */
	private function apply_overlap( array $chunks ): array {
		if ( 0 === $this->overlap || count( $chunks ) < 2 ) {
			return $chunks;
		}

		$base = $chunks;
		for ( $i = 1, $n = count( $chunks ); $i < $n; $i++ ) {
			$tail = $this->tail( $base[ $i - 1 ], $this->overlap );
			if ( '' !== $tail ) {
				$chunks[ $i ] = $tail . ' ' . $base[ $i ];
			}
		}

		return $chunks;
	}

	/**
	 * Twardy podział zbyt długiej jednostki na kawałki ≤ $size (po znakach).
	 *
	 * @param string $unit Jednostka do podziału.
	 * @param int    $size Maksymalny rozmiar kawałka.
	 * @return array<int,string>
	 */
	private function hard_split( string $unit, int $size ): array {
		$out = array();
		$len = $this->len( $unit );
		for ( $off = 0; $off < $len; $off += $size ) {
			$out[] = $this->sub( $unit, $off, $size );
		}
		return $out;
	}

	/**
	 * Ogon łańcucha: ostatnie ~$n znaków, przycięte do granicy słowa.
	 *
	 * @param string $s Łańcuch źródłowy.
	 * @param int    $n Docelowa długość ogona.
	 * @return string
	 */
	private function tail( string $s, int $n ): string {
		$len = $this->len( $s );
		if ( $len <= $n ) {
			return $s;
		}
		$t   = $this->sub( $s, $len - $n, $n );
		// Spacja to pojedynczy bajt w UTF-8 — cięcie po niej jest bezpieczne.
		$pos = strpos( $t, ' ' );
		if ( false !== $pos && $pos < strlen( $t ) - 1 ) {
			$t = substr( $t, $pos + 1 );
		}
		return $t;
	}

	/**
	 * Długość w znakach — mbstring, a bez niego bezpieczne liczenie UTF-8 (PCRE).
	 *
	 * Fallback NIE używa bajtowego `strlen` (przecinałby wielobajtowe znaki),
	 * tylko liczy pełne znaki wzorcem `/u`.
	 *
	 * @param string $s Łańcuch.
	 * @return int
	 */
	private function len( string $s ): int {
		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $s );
		}
		$count = preg_match_all( '/./us', $s );
		return false === $count ? strlen( $s ) : $count;
	}

	/**
	 * Podłańcuch po ZNAKACH — mbstring, a bez niego bezpieczne cięcie UTF-8 (PCRE).
	 *
	 * Fallback rozbija łańcuch na pełne znaki, więc nigdy nie przecina polskiej
	 * litery w połowie bajtu (inaczej do bazy/embeddingu trafiłby uszkodzony UTF-8).
	 *
	 * @param string $s      Łańcuch.
	 * @param int    $start  Początek (w znakach).
	 * @param int    $length Długość (w znakach).
	 * @return string
	 */
	private function sub( string $s, int $start, int $length ): string {
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $s, $start, $length );
		}
		$chars = preg_split( '//u', $s, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $chars ) ) {
			return substr( $s, $start, $length ); // ostateczny fallback (nie powinno się zdarzyć).
		}
		return implode( '', array_slice( $chars, $start, $length ) );
	}
}
