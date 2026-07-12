<?php
/**
 * RateLimiter — limit zapytań gościa (ochrona kosztu/klucza).
 *
 * Okno stałe 1h na gościa identyfikowanego przez `ip_hash` (sha256, nie surowe
 * IP — GR7). Licznik trzymany w WP transiencie. `rag_rate_limit` = 0 wyłącza
 * limit. Fail-closed: po wyczerpaniu limitu `allow()` = false, egzekwowane PRZED
 * wywołaniem API (GR5).
 *
 * @package AI_FAQ_Generator
 */

namespace AIFAQ\Rag;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Limiter zapytań na WP transientach (okno godzinowe per ip_hash).
 */
class RateLimiter {

	/**
	 * Długość okna w sekundach (1h).
	 */
	const WINDOW = 3600;

	/**
	 * Prefiks klucza transientu.
	 */
	const PREFIX = 'aifaq_rl_';

	/**
	 * @var int
	 */
	private $limit;

	/**
	 * Zegar (wstrzykiwalny dla testów). Zwraca uniksowy timestamp.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * @param int           $limit Limit zapytań/okno (clamp min 0; 0 = wyłączony).
	 * @param callable|null $clock Zegar; domyślnie `time()`.
	 */
	public function __construct( int $limit, ?callable $clock = null ) {
		$this->limit = max( 0, $limit );
		$this->clock = $clock ?? 'time';
	}

	/**
	 * Czy gość mieści się w limicie w bieżącym oknie.
	 *
	 * @param string $ip_hash Identyfikator gościa (sha256).
	 * @return bool
	 */
	public function allow( string $ip_hash ): bool {
		if ( $this->limit <= 0 ) {
			return true;
		}
		$state = $this->read( $ip_hash );
		return $state['count'] < $this->limit;
	}

	/**
	 * Rejestruje jedno zapytanie gościa (inkrement licznika w oknie).
	 *
	 * @param string $ip_hash Identyfikator gościa (sha256).
	 */
	public function hit( string $ip_hash ): void {
		if ( $this->limit <= 0 ) {
			return;
		}
		$now   = $this->now();
		$state = $this->read( $ip_hash );

		// Nowe okno, jeśli brak stanu lub poprzednie wygasło.
		if ( $state['count'] <= 0 || $now >= $state['reset'] ) {
			$state = array(
				'count' => 0,
				'reset' => $now + self::WINDOW,
			);
		}

		++$state['count'];
		$ttl = max( 1, $state['reset'] - $now );
		set_transient( self::PREFIX . $ip_hash, $state, $ttl );
	}

	/**
	 * Odczyt stanu okna; wygasłe/nieistniejące traktujemy jako puste.
	 *
	 * @param string $ip_hash Identyfikator gościa.
	 * @return array{count:int,reset:int}
	 */
	private function read( string $ip_hash ): array {
		$now   = $this->now();
		$stored = get_transient( self::PREFIX . $ip_hash );
		if ( ! is_array( $stored ) || ! isset( $stored['count'], $stored['reset'] ) || $now >= (int) $stored['reset'] ) {
			return array(
				'count' => 0,
				'reset' => $now + self::WINDOW,
			);
		}
		return array(
			'count' => (int) $stored['count'],
			'reset' => (int) $stored['reset'],
		);
	}

	/**
	 * Bieżący timestamp (przez wstrzyknięty zegar).
	 *
	 * @return int
	 */
	private function now(): int {
		return (int) call_user_func( $this->clock );
	}
}
