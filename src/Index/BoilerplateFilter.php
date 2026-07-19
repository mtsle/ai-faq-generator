<?php
/**
 * Filtr balastu — przenosi powtarzalne linie (menu, stopka) w jedno miejsce.
 *
 * Na każdej podstronie WordPressa powtarza się to samo menu, ta sama stopka
 * i te same okruszki. Bez filtra 35 podstron wnosi do bazy wiedzy 35 kopii
 * stopki, które zjadają miejsce w kontekście promptu i psują wyszukiwanie
 * (fragment „menu” pasuje do wszystkiego).
 *
 * KLUCZOWA DECYZJA (KONTRAKT k17-v3 §3.5): balast jest PRZENOSZONY, nie
 * kasowany. Godziny otwarcia i adres są w stopce ORAZ na stronie kontaktu —
 * kasowanie usuwałoby je także tam, gdzie są sednem odpowiedzi. Przeniesienie
 * do jednego dokumentu „informacje ogólne o witrynie” zachowuje odpowiadalność
 * i zdejmuje powielenie.
 *
 * Klasa jest czysta: działa bez WordPressa (funkcje WP tylko przez
 * `function_exists()`), bez bazy i bez sieci.
 *
 * @package AI_FAQ_Generator
 */

namespace AIFAQ\Index;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Filtr powtarzalnych linii w zbiorze dokumentów.
 */
class BoilerplateFilter {

	/**
	 * Minimalna liczba dokumentów, przy której filtr w ogóle działa.
	 *
	 * Próg jest niski (5), bo od v3 nic nie ginie — balast jedynie zmienia
	 * miejsce. Wyższy próg wyłączałby filtr małym witrynom, którym powielona
	 * stopka szkodzi tak samo.
	 */
	public const MIN_DOCS = 5;

	/**
	 * Udział dokumentów, od którego linia uchodzi za balast (30%).
	 */
	public const DF_RATIO = 0.30;

	/**
	 * Bezpiecznik pojedynczego dokumentu: gdy filtr zabrałby ≥60% jego znaków,
	 * dokument zostaje nietknięty (to nie jest balast, tylko jego własna treść).
	 */
	public const DOC_FLOOR = 0.60;

	/**
	 * Kanał raportowania — ostrzeżenia z ostatniego wywołania {@see filter()}.
	 *
	 * @var array<int,string>
	 */
	public static array $last_warnings = array();

	/**
	 * Kanał raportowania — liczba USUNIĘTYCH WYSTĄPIEŃ linii (nie unikatów)
	 * z ostatniego wywołania {@see filter()}.
	 *
	 * @var int
	 */
	public static int $last_filtered = 0;

	/**
	 * Przenosi powtarzalny balast do jednego dokumentu.
	 *
	 * @param array<int,array{post_id:int,title:string,url:string,text:string}> $docs         Dokumenty wejściowe.
	 * @param int                                                               $sink_post_id `post_id` dokumentu „informacje ogólne”.
	 *                                                                                        Gdy `<= 0` albo brak go w zestawie —
	 *                                                                                        użyty zostanie najniższy `post_id`.
	 * @return array<int,array{post_id:int,title:string,url:string,text:string}>
	 */
	public static function filter( array $docs, int $sink_post_id = 0 ): array {
		// Oba kanały raportowania resetujemy NA POCZĄTKU — inaczej raport
		// pokazywałby sumę z poprzednich przebiegów.
		self::$last_warnings = array();
		self::$last_filtered = 0;

		$docs  = array_values( $docs );
		$count = count( $docs );

		// Za mały zbiór — statystyka „w ilu dokumentach występuje” nie ma sensu.
		if ( $count < self::MIN_DOCS ) {
			return $docs;
		}

		// 1. Rozbicie na linie (trim, puste odpadają).
		$lines_per_doc = array();
		foreach ( $docs as $idx => $doc ) {
			$lines_per_doc[ $idx ] = self::split_lines( (string) ( $doc['text'] ?? '' ) );
		}

		// 2. df — w ilu DOKUMENTACH (nie ile razy) występuje dana linia.
		$df = array();
		foreach ( $lines_per_doc as $lines ) {
			$seen = array();
			foreach ( $lines as $line ) {
				$key = self::normalize_line( $line );
				if ( '' === $key || isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ] = true;
				$df[ $key ]   = ( $df[ $key ] ?? 0 ) + 1;
			}
		}

		// 3. Które linie są balastem.
		$threshold = max( 3, (int) ceil( self::DF_RATIO * $count ) );
		$balast    = array();
		foreach ( $df as $key => $n ) {
			if ( $n >= $threshold && ( $n / $count ) >= self::DF_RATIO ) {
				$balast[ $key ] = true;
			}
		}
		if ( array() === $balast ) {
			return $docs;
		}

		// 4. Wycięcie balastu z dokumentów (z bezpiecznikiem DOC_FLOOR).
		$sink_lines = array(); // znormalizowana => ORYGINALNA postać (pierwsze wystąpienie).
		$out        = array();

		foreach ( $docs as $idx => $doc ) {
			$lines   = $lines_per_doc[ $idx ];
			$kept    = array();
			$removed = array();

			foreach ( $lines as $line ) {
				$key = self::normalize_line( $line );
				if ( '' !== $key && isset( $balast[ $key ] ) ) {
					$removed[] = array( $key, $line );
				} else {
					$kept[] = $line;
				}
			}

			if ( array() === $removed ) {
				$out[] = $doc;
				continue;
			}

			$orig_len = self::len( implode( "\n", $lines ) );
			$kept_len = self::len( implode( "\n", $kept ) );

			// DOC_FLOOR: dokument, który straciłby ≥60% znaków, zostaje
			// NIEPRZEFILTROWANY, a jego linie NIE idą do sinka.
			if ( $orig_len > 0 && ( ( $orig_len - $kept_len ) / $orig_len ) >= self::DOC_FLOOR ) {
				self::$last_warnings[] = sprintf(
					/* translators: 1: ID wpisu, 2: próg procentowy */
					self::t( 'Dokument %1$d zachowany bez filtrowania — usunięcie powtarzalnych linii zabrałoby ponad %2$d%% jego treści.' ),
					(int) ( $doc['post_id'] ?? 0 ),
					(int) round( self::DOC_FLOOR * 100 )
				);
				$out[] = $doc;
				continue;
			}

			foreach ( $removed as $pair ) {
				if ( ! isset( $sink_lines[ $pair[0] ] ) ) {
					$sink_lines[ $pair[0] ] = $pair[1];
				}
				++self::$last_filtered;
			}

			$doc['text'] = implode( "\n", $kept );
			$out[]       = $doc;
		}

		// 5. Dopisanie balastu JEDEN RAZ do dokumentu „informacje ogólne”.
		if ( array() !== $sink_lines ) {
			$out = self::pour_into_sink( $out, $sink_lines, $sink_post_id );
		}

		// 6. Dokument z pustym tekstem wypada z wyniku (inaczej Indexer uznałby,
		// że wpis stracił treść, i skasował jego fragmenty).
		$final = array();
		foreach ( $out as $doc ) {
			if ( '' === trim( (string) ( $doc['text'] ?? '' ) ) ) {
				continue;
			}
			$final[] = $doc;
		}

		return array_values( $final );
	}

	/**
	 * Normalizuje linię — WYŁĄCZNIE do liczenia `df`.
	 *
	 * Zwracana postać NIE trafia do bazy wiedzy: dokumenty zachowują oryginalne
	 * brzmienie linii. Normalizacja ma tylko zbliżyć do siebie warianty tej samej
	 * linii („Poniedziałek 7:00” vs „poniedziałek 8:00” → ten sam klucz).
	 *
	 * @param string $line Linia tekstu.
	 * @return string Klucz porównawczy.
	 */
	public static function normalize_line( string $line ): string {
		$out = function_exists( 'mb_strtolower' ) ? mb_strtolower( $line, 'UTF-8' ) : strtolower( $line );
		$out = (string) preg_replace( '/\s+/u', ' ', $out );
		$out = (string) preg_replace( '/\d+/u', '#', $out );

		return trim( $out );
	}

	/**
	 * Dopisuje zebrany balast do dokumentu-sinka (albo tworzy taki dokument).
	 *
	 * @param array<int,array<string,mixed>> $docs         Dokumenty po filtrowaniu.
	 * @param array<string,string>           $sink_lines   Balast: klucz znormalizowany => oryginał.
	 * @param int                            $sink_post_id Żądany `post_id` sinka.
	 * @return array<int,array<string,mixed>>
	 */
	private static function pour_into_sink( array $docs, array $sink_lines, int $sink_post_id ): array {
		$sink_id = $sink_post_id > 0 ? $sink_post_id : self::lowest_post_id( $docs );
		if ( $sink_id <= 0 ) {
			return $docs; // nie ma dokąd przenieść — dokument z post_id <= 0 jest nielegalny (§2.1).
		}

		foreach ( $docs as $i => $doc ) {
			if ( (int) ( $doc['post_id'] ?? 0 ) !== $sink_id ) {
				continue;
			}

			// Sink mógł zostać ochroniony przez DOC_FLOOR i wciąż mieć te linie
			// u siebie — wtedy nic nie dopisujemy (balast ma być CO NAJWYŻEJ raz).
			$have = array();
			foreach ( self::split_lines( (string) ( $doc['text'] ?? '' ) ) as $line ) {
				$have[ self::normalize_line( $line ) ] = true;
			}

			$add = array();
			foreach ( $sink_lines as $key => $original ) {
				if ( ! isset( $have[ $key ] ) ) {
					$add[] = $original;
				}
			}

			if ( array() !== $add ) {
				$text        = trim( (string) ( $doc['text'] ?? '' ) );
				$doc['text'] = ( '' === $text ) ? implode( "\n", $add ) : $text . "\n" . implode( "\n", $add );
				$docs[ $i ]  = $doc;
			}

			return $docs;
		}

		// Sinka nie ma w zestawie — tworzymy dokument „informacje ogólne o witrynie”.
		$docs[] = array(
			'post_id' => $sink_id,
			'title'   => self::site_name(),
			'url'     => self::site_home(),
			'text'    => implode( "\n", array_values( $sink_lines ) ),
		);

		return $docs;
	}

	/**
	 * Najniższy dodatni `post_id` w zestawie.
	 *
	 * @param array<int,array<string,mixed>> $docs Dokumenty.
	 * @return int 0, gdy zestaw pusty.
	 */
	private static function lowest_post_id( array $docs ): int {
		$lowest = 0;
		foreach ( $docs as $doc ) {
			$pid = (int) ( $doc['post_id'] ?? 0 );
			if ( $pid > 0 && ( 0 === $lowest || $pid < $lowest ) ) {
				$lowest = $pid;
			}
		}

		return $lowest;
	}

	/**
	 * Rozbija tekst na linie: `trim()` każdej, puste odpadają.
	 *
	 * @param string $text Tekst dokumentu.
	 * @return array<int,string>
	 */
	private static function split_lines( string $text ): array {
		$out = array();
		foreach ( explode( "\n", $text ) as $line ) {
			$line = trim( $line );
			if ( '' !== $line ) {
				$out[] = $line;
			}
		}

		return $out;
	}

	/**
	 * Długość tekstu w znakach (z zapasową ścieżką bez mbstring).
	 *
	 * @param string $text Tekst.
	 * @return int
	 */
	private static function len( string $text ): int {
		return function_exists( 'mb_strlen' ) ? (int) mb_strlen( $text ) : strlen( $text );
	}

	/**
	 * Nazwa witryny do dokumentu „informacje ogólne”.
	 *
	 * @return string
	 */
	private static function site_name(): string {
		$name = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : '';

		return '' !== trim( $name ) ? $name : self::t( 'Informacje ogólne o witrynie' );
	}

	/**
	 * Adres strony głównej do dokumentu „informacje ogólne”.
	 *
	 * @return string
	 */
	private static function site_home(): string {
		return function_exists( 'home_url' ) ? (string) home_url( '/' ) : '';
	}

	/**
	 * Tłumaczenie odporne na brak WordPressa (klasa działa w czystym PHP CLI).
	 *
	 * @param string $text Tekst źródłowy.
	 * @return string
	 */
	private static function t( string $text ): string {
		return function_exists( '__' ) ? (string) __( $text, 'ai-faq-generator' ) : $text; // phpcs:ignore WordPress.WP.I18n
	}
}
