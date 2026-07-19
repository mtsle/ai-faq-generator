<?php
/**
 * Kompozyt źródeł treści — scala kilka źródeł w jeden strumień dokumentów.
 *
 * Kaskada z Kroku 17 jest SUMUJĄCA: `post_content` nie widzi treści wstawianej
 * przez szablon motywu, a wyrenderowany HTML nie widzi biogramów, które szablon
 * pakuje do JS. Dopiero suma wszystkich źródeł daje bazę wiedzy, z której da się
 * odpowiedzieć na pytania o witrynę.
 *
 * Ta klasa jest JEDYNYM miejscem, w którym bramka {@see WpContentSource::is_indexable()}
 * jest wymuszana STRUKTURALNIE (KONTRAKT k17-v3 §1 reguła 4). Dotyczy to również
 * źródeł wstrzykniętych przez filtr `aifaq_content_sources` — konwencja „każde
 * źródło samo sprawdza” była łamana przez pierwsze obce źródło z wtyczki.
 *
 * @package AI_FAQ_Generator
 */

namespace AIFAQ\Index;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Źródło łączące wiele źródeł treści.
 */
class CompositeContentSource implements ContentSource {

	/**
	 * Źródła (wstrzyknięte obiekty).
	 *
	 * @var array<int,mixed>
	 */
	private array $sources;

	/**
	 * Czy ostatni przebieg objął komplet treści.
	 *
	 * Przed pierwszym {@see documents()} — `true`.
	 *
	 * @var bool
	 */
	private bool $complete = true;

	/**
	 * Ostrzeżenia z ostatniego przebiegu.
	 *
	 * @var array<int,string>
	 */
	private array $warnings = array();

	/**
	 * Statystyki wkładu poszczególnych źródeł.
	 *
	 * @var array<string,array{docs:int,chars:int}>
	 */
	private array $stats = array();

	/**
	 * Konstruktor.
	 *
	 * @param array<int,mixed> $sources Lista źródeł (elementy niebędące źródłami są pomijane z ostrzeżeniem).
	 */
	public function __construct( array $sources ) {
		$this->sources = array_values( $sources );
	}

	/**
	 * Zbiera dokumenty ze wszystkich źródeł, scala je po `post_id` i filtruje balast.
	 *
	 * Kolejność operacji jest wiążąca (KONTRAKT §3.6).
	 *
	 * @return array<int,array{post_id:int,title:string,url:string,text:string}>
	 */
	public function documents(): array {
		$this->complete = true;
		$this->warnings = array();
		$this->stats    = array();

		$merged = array();

		foreach ( $this->sources as $source ) {
			// 2. Element niebędący źródłem — pomijamy, ale NIE psujemy kompletności
			// (nic nie obiecywał, więc nic nie brakuje).
			if ( ! is_object( $source ) || ! method_exists( $source, 'documents' ) ) {
				$this->warnings[] = sprintf(
					/* translators: %s: typ elementu */
					$this->t( 'Pominięto element listy źródeł, który nie jest źródłem treści (%s).' ),
					is_object( $source ) ? get_class( $source ) : gettype( $source )
				);
				continue;
			}

			$name = $this->short_name( $source );

			// 1. Wyjątek z cudzego źródła nie może wywrócić indeksowania.
			// \Throwable, nie \Exception — TypeError z obcej wtyczki to \Error.
			try {
				$docs = $source->documents();
			} catch ( \Throwable $e ) {
				$this->complete   = false;
				$this->warnings[] = sprintf(
					/* translators: 1: nazwa źródła, 2: komunikat błędu */
					$this->t( 'Źródło %1$s zgłosiło błąd i zostało pominięte: %2$s' ),
					$name,
					$e->getMessage()
				);
				continue;
			}

			if ( method_exists( $source, 'is_complete' ) ) {
				try {
					if ( false === $source->is_complete() ) {
						$this->complete = false;
					}
				} catch ( \Throwable $e ) {
					$this->complete = false;
				}
			}

			if ( ! is_array( $docs ) ) {
				$this->warnings[] = sprintf(
					/* translators: %s: nazwa źródła */
					$this->t( 'Źródło %s zwróciło nieprawidłowy wynik — pominięte.' ),
					$name
				);
				continue;
			}

			foreach ( $docs as $doc ) {
				if ( ! is_array( $doc ) ) {
					continue;
				}

				// 3. Dokument bez `post_id > 0` albo bez tekstu jest nielegalny (§2.1).
				$post_id = (int) ( $doc['post_id'] ?? 0 );
				$text    = (string) ( $doc['text'] ?? '' );
				if ( $post_id <= 0 || '' === trim( $text ) ) {
					continue;
				}

				// 4. BRAMKA STRUKTURALNA — niezależnie od źródła.
				if ( ! $this->is_indexable( $post_id ) ) {
					continue;
				}

				$this->count_in( $name, $text );

				// 5. Scalanie po `post_id`; kolejność wyjścia = pierwsze wystąpienie.
				if ( ! isset( $merged[ $post_id ] ) ) {
					$merged[ $post_id ] = array(
						'post_id' => $post_id,
						'title'   => (string) ( $doc['title'] ?? '' ),
						'url'     => (string) ( $doc['url'] ?? '' ),
						'text'    => $text,
					);
					continue;
				}

				$merged[ $post_id ]['text'] .= "\n" . $text;

				// Pierwszy niepusty tytuł/URL wygrywa.
				if ( '' === trim( $merged[ $post_id ]['title'] ) ) {
					$merged[ $post_id ]['title'] = (string) ( $doc['title'] ?? '' );
				}
				if ( '' === trim( $merged[ $post_id ]['url'] ) ) {
					$merged[ $post_id ]['url'] = (string) ( $doc['url'] ?? '' );
				}
			}
		}

		// 6. Deduplikacja w obrębie JEDNEGO wpisu, po linii SUROWEJ (po trim).
		foreach ( $merged as $post_id => $doc ) {
			$merged[ $post_id ]['text'] = $this->dedupe_lines( (string) $doc['text'] );
		}

		$docs = array_values( $merged );
		if ( array() === $docs ) {
			return array();
		}

		// 8. Balast do jednego dokumentu „informacje ogólne o witrynie”.
		$sink = $this->sink_post_id( $docs );
		if ( class_exists( __NAMESPACE__ . '\BoilerplateFilter' ) && method_exists( __NAMESPACE__ . '\BoilerplateFilter', 'filter' ) ) {
			$docs = BoilerplateFilter::filter( $docs, $sink );
			foreach ( BoilerplateFilter::$last_warnings as $warning ) {
				$this->warnings[] = (string) $warning;
			}
		}

		return array_values( $docs );
	}

	/**
	 * Czy ostatni przebieg objął komplet treści.
	 *
	 * `false`, gdy któreś źródło rzuciło `\Throwable` albo samo zgłosiło
	 * niekompletność (np. trwa jeszcze pobieranie stron). Indexer pomija wtedy
	 * usuwanie osieroconych fragmentów — inaczej chwilowy brak treści skasowałby
	 * (opłacone) embeddingi.
	 *
	 * @return bool
	 */
	public function is_complete(): bool {
		return $this->complete;
	}

	/**
	 * Statystyki wkładu źródeł z ostatniego przebiegu.
	 *
	 * @return array<string,array{docs:int,chars:int}> Klucz = krótka nazwa klasy źródła.
	 */
	public function stats(): array {
		return $this->stats;
	}

	/**
	 * Ostrzeżenia z ostatniego przebiegu (własne + z {@see BoilerplateFilter}).
	 *
	 * @return array<int,string>
	 */
	public function warnings(): array {
		return $this->warnings;
	}

	// -----------------------------------------------------------------------
	// Wewnętrzne
	// -----------------------------------------------------------------------

	/**
	 * Bramka indeksowalności (§2.2) — wspólna dla WSZYSTKICH źródeł.
	 *
	 * Brak klasy/metody (np. w izolowanym teście) → warunek pomijany, nigdy fatal.
	 *
	 * @param int $post_id ID wpisu.
	 * @return bool
	 */
	private function is_indexable( int $post_id ): bool {
		$class = __NAMESPACE__ . '\WpContentSource';
		if ( ! class_exists( $class ) || ! method_exists( $class, 'is_indexable' ) ) {
			return true;
		}

		try {
			return false !== WpContentSource::is_indexable( $post_id );
		} catch ( \Throwable $e ) {
			return true; // bramka nie może wywalić indeksowania.
		}
	}

	/**
	 * Usuwa powtórzone linie w obrębie jednego dokumentu.
	 *
	 * Porównanie po linii SUROWEJ (tylko `trim()`), NIGDY po znormalizowanej:
	 * „Czesne: 450 zł” i „Czesne: 890 zł” to dwa różne fakty, a zbicie ich w jeden
	 * nie byłoby utratą danych, tylko FABRYKACJĄ faktu, który bot podałby gościowi
	 * jako cytat z witryny.
	 *
	 * @param string $text Tekst dokumentu.
	 * @return string
	 */
	private function dedupe_lines( string $text ): string {
		$seen = array();
		$out  = array();

		foreach ( explode( "\n", $text ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$key = md5( $line );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = $line;
		}

		return implode( "\n", $out );
	}

	/**
	 * Wybiera dokument „informacje ogólne o witrynie”.
	 *
	 * Strona główna, o ile faktycznie przeszła bramkę i jest w zestawie —
	 * w przeciwnym razie najniższy `post_id`. Warunek „jest w zestawie” jest
	 * istotny: gdyby strona główna była wykluczona z indeksu, tworzenie dla niej
	 * nowego dokumentu obchodziłoby bramkę z punktu 4.
	 *
	 * @param array<int,array<string,mixed>> $docs Scalone dokumenty.
	 * @return int
	 */
	private function sink_post_id( array $docs ): int {
		$front = 0;
		if ( function_exists( 'get_option' ) ) {
			$front = (int) get_option( 'page_on_front' );
		}

		$lowest = 0;
		$has    = false;
		foreach ( $docs as $doc ) {
			$pid = (int) ( $doc['post_id'] ?? 0 );
			if ( $pid <= 0 ) {
				continue;
			}
			if ( $pid === $front ) {
				$has = true;
			}
			if ( 0 === $lowest || $pid < $lowest ) {
				$lowest = $pid;
			}
		}

		return ( $front > 0 && $has ) ? $front : $lowest;
	}

	/**
	 * Dopisuje wkład źródła do statystyk.
	 *
	 * @param string $name Krótka nazwa klasy źródła.
	 * @param string $text Tekst przyjętego dokumentu.
	 * @return void
	 */
	private function count_in( string $name, string $text ): void {
		if ( ! isset( $this->stats[ $name ] ) ) {
			$this->stats[ $name ] = array(
				'docs'  => 0,
				'chars' => 0,
			);
		}

		++$this->stats[ $name ]['docs'];
		$this->stats[ $name ]['chars'] += function_exists( 'mb_strlen' ) ? (int) mb_strlen( $text ) : strlen( $text );
	}

	/**
	 * Krótka nazwa klasy źródła (bez przestrzeni nazw) — klucz w {@see stats()}.
	 *
	 * @param object $source Źródło.
	 * @return string
	 */
	private function short_name( object $source ): string {
		$parts = explode( '\\', get_class( $source ) );
		$name  = (string) end( $parts );
		$name  = (string) preg_replace( '/@anonymous.*$/', '@anonymous', $name );

		return '' !== $name ? $name : 'ContentSource';
	}

	/**
	 * Tłumaczenie odporne na brak WordPressa.
	 *
	 * @param string $text Tekst źródłowy.
	 * @return string
	 */
	private function t( string $text ): string {
		return function_exists( '__' ) ? (string) __( $text, 'ai-faq-generator' ) : $text; // phpcs:ignore WordPress.WP.I18n
	}
}
