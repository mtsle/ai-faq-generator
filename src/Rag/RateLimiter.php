<?php
/**
 * RateLimiter — limit zapytań gościa (ochrona kosztu/klucza).
 *
 * Okno konfigurowalne (godzina albo doba) na gościa identyfikowanego przez
 * `ip_hash` (sha256, nie surowe IP — GR7). Licznik trzymany w trwałym cache
 * obiektowym, a gdy go nie ma — w WP transiencie. `rag_rate_limit` = 0 wyłącza
 * limit. Egzekwowany PRZED wywołaniem API (GR5).
 *
 * Gwarancja: **best-effort**, nie atomowa. Klasa NIE obiecuje, że przy zbieżnych
 * żądaniach przepuści dokładnie N zapytań, i nie obiecuje, że po awarii magazynu
 * dalej blokuje. Degraduje się fail-open (przepuszcza) w trzech sytuacjach:
 *  1. magazyn nie oddaje zapisanej wartości (wyczyszczony cache obiektowy,
 *     skasowany albo niezapisany transient) — licznik startuje od zera;
 *  2. dwa żądania czytają licznik w tej samej milisekundzie na ścieżce
 *     transientowej (klasyczne TOCTOU między odczytem a zapisem);
 *  3. brak funkcji WordPressa (czyste PHP CLI) — nie ma gdzie liczyć.
 * Szybka ścieżka `wp_cache_add()` + `wp_cache_incr()` jest atomowa WYŁĄCZNIE przy
 * trwałym cache obiektowym (Redis/Memcached). Docelowe środowisko produktu — tani
 * hosting współdzielony — takiego cache nie ma, więc realnie działa ścieżka
 * transientowa z punktu 2. Obiecywanie tu atomowości byłoby nieprawdą; twardą
 * granicą kosztu jest dobowy sufit witryny (`RagService`), nie ten licznik.
 *
 * @package AI_FAQ_Generator
 */

namespace AIFAQ\Rag;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Limiter zapytań gościa (okno kalendarzowe per ip_hash).
 */
class RateLimiter {

	/**
	 * Domyślna długość okna w sekundach (1h). Zachowana dla wywołań, które nie
	 * podają trzeciego argumentu konstruktora — zachowują się jak przed K20.
	 */
	const WINDOW = 3600;

	/**
	 * Prefiks klucza (transient ORAZ cache obiektowy).
	 */
	const PREFIX = 'aifaq_rl_';

	/**
	 * Grupa cache obiektowego (wspólna dla wtyczki).
	 */
	const CACHE_GROUP = 'aifaq';

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
	 * Czy zegar został wstrzyknięty (wtedy identyfikator okna liczymy z NIEGO,
	 * a nie z `current_time()` — inaczej wstrzyknięcie zegara nic by nie dawało).
	 *
	 * @var bool
	 */
	private $injected_clock;

	/**
	 * Długość okna w sekundach.
	 *
	 * @var int
	 */
	private $window;

	/**
	 * Kolejność argumentów jest ZAMROŻONA: drugim parametrem pozostaje `$clock`.
	 * Dwie atrapy w testach (`krok6-rag-test.php`, `krok19-rag-test.php`) wołają
	 * `new RateLimiter( 3, $clock )` i `parent::__construct( … )` — wstawienie okna
	 * na drugą pozycję dałoby `TypeError` w cudzym pliku.
	 *
	 * @param int           $limit  Limit zapytań/okno (clamp min 0; 0 = wyłączony).
	 * @param callable|null $clock  Zegar; domyślnie `time()`.
	 * @param int           $window Długość okna w sekundach (3600 = godzina, 86400 = doba).
	 */
	public function __construct( int $limit, ?callable $clock = null, int $window = 3600 ) {
		$this->limit          = max( 0, $limit );
		$this->injected_clock = ( null !== $clock );
		$this->clock          = $clock ?? 'time';
		$this->window         = $window > 0 ? $window : self::WINDOW;
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
		return $this->read( $ip_hash ) < $this->limit;
	}

	/**
	 * Rejestruje jedno zapytanie gościa (inkrement licznika w bieżącym oknie).
	 *
	 * @param string $ip_hash Identyfikator gościa (sha256).
	 */
	public function hit( string $ip_hash ): void {
		if ( $this->limit <= 0 ) {
			return;
		}
		$window = $this->window_id();

		// Szybka ścieżka: trwały cache obiektowy. `wp_cache_add()` zakłada licznik
		// tylko wtedy, gdy klucza nie ma; przegrany wyścig idzie na `wp_cache_incr()`.
		if ( $this->use_object_cache() ) {
			$key = self::PREFIX . $ip_hash . '_' . $window;
			if ( ! wp_cache_add( $key, 1, self::CACHE_GROUP, $this->window ) ) {
				wp_cache_incr( $key, 1, self::CACHE_GROUP );
			}
			return;
		}

		if ( ! function_exists( 'set_transient' ) ) {
			return;
		}
		$count = $this->read_transient( $ip_hash, $window );
		set_transient(
			self::PREFIX . $ip_hash,
			array(
				'window' => $window,
				'count'  => $count + 1,
			),
			$this->window
		);
	}

	/**
	 * Zwraca gościowi jednostkę pobraną przez `hit()`.
	 *
	 * Wołane WYŁĄCZNIE wtedy, gdy żądanie realnie opuściło proces (patrz
	 * `RagService`), bo inaczej wyłącznik obwodu dostawcy — który odpowiada
	 * błędem bez wychodzenia do sieci — czyniłby limiter bezzębnym dokładnie
	 * w chwili, gdy jest najbardziej potrzebny.
	 *
	 * Gdy nie ma czego zwracać (licznik 0, inne okno, wygasły wpis) — no-op.
	 * Licznik nigdy nie schodzi poniżej zera.
	 *
	 * @param string $ip_hash Identyfikator gościa (sha256).
	 */
	public function refund( string $ip_hash ): void {
		if ( $this->limit <= 0 ) {
			return;
		}
		$window = $this->window_id();

		if ( $this->use_object_cache() ) {
			$key   = self::PREFIX . $ip_hash . '_' . $window;
			$value = wp_cache_get( $key, self::CACHE_GROUP );
			if ( false === $value || (int) $value <= 0 || ! function_exists( 'wp_cache_decr' ) ) {
				return;
			}
			wp_cache_decr( $key, 1, self::CACHE_GROUP );
			return;
		}

		if ( ! function_exists( 'set_transient' ) ) {
			return;
		}
		$count = $this->read_transient( $ip_hash, $window );
		if ( $count <= 0 ) {
			return;
		}
		set_transient(
			self::PREFIX . $ip_hash,
			array(
				'window' => $window,
				'count'  => $count - 1,
			),
			$this->window
		);
	}

	/**
	 * Licznik gościa w bieżącym oknie (0, gdy brak danych albo inne okno).
	 *
	 * @param string $ip_hash Identyfikator gościa.
	 * @return int
	 */
	private function read( string $ip_hash ): int {
		$window = $this->window_id();

		if ( $this->use_object_cache() ) {
			$value = wp_cache_get( self::PREFIX . $ip_hash . '_' . $window, self::CACHE_GROUP );
			return ( false === $value ) ? 0 : max( 0, (int) $value );
		}

		return $this->read_transient( $ip_hash, $window );
	}

	/**
	 * Odczyt licznika z transientu.
	 *
	 * Klucz jest STAŁY (`aifaq_rl_<ip_hash>`), a identyfikator okna siedzi
	 * w WARTOŚCI. Data w kluczu dawałaby dwa nowe wiersze `wp_options` na gościa
	 * i dobę, a wygasłych transientów na hostingu z `DISABLE_WP_CRON` i niskim
	 * ruchem nikt nie sprząta.
	 *
	 * Wpis w starym kształcie (`count` + `reset`, sprzed K20) nie ma klucza
	 * `window`, więc czyta się jako zero — jednorazowy reset liczników przy
	 * aktualizacji, świadomy i nieszkodliwy.
	 *
	 * @param string $ip_hash Identyfikator gościa.
	 * @param string $window  Identyfikator bieżącego okna.
	 * @return int
	 */
	private function read_transient( string $ip_hash, string $window ): int {
		if ( ! function_exists( 'get_transient' ) ) {
			return 0;
		}
		$stored = get_transient( self::PREFIX . $ip_hash );
		if ( ! is_array( $stored ) || ! isset( $stored['window'], $stored['count'] ) ) {
			return 0;
		}
		if ( (string) $stored['window'] !== $window ) {
			return 0;
		}
		return max( 0, (int) $stored['count'] );
	}

	/**
	 * Identyfikator bieżącego okna — kotwiczony KALENDARZOWO, nie ruchomo.
	 *
	 * Okno dobowe zmienia się o północy witryny, godzinowe — o pełnej godzinie.
	 * Dzięki temu gość nie musi czekać pełnych 3600 s od ostatniego pytania,
	 * a licznik nie wymaga własnego znacznika wygaśnięcia.
	 *
	 * @return string `RRRR-MM-DD` albo `RRRR-MM-DD-HH`.
	 */
	private function window_id(): string {
		$format = ( $this->window >= 86400 ) ? 'Y-m-d' : 'Y-m-d-H';

		if ( ! $this->injected_clock && function_exists( 'current_time' ) ) {
			return (string) current_time( $format );
		}

		return (string) gmdate( $format, $this->now() );
	}

	/**
	 * Czy dostępna jest szybka ścieżka trwałego cache obiektowego.
	 *
	 * @return bool
	 */
	private function use_object_cache(): bool {
		return function_exists( 'wp_using_ext_object_cache' )
			&& wp_using_ext_object_cache()
			&& function_exists( 'wp_cache_add' )
			&& function_exists( 'wp_cache_incr' )
			&& function_exists( 'wp_cache_get' );
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
