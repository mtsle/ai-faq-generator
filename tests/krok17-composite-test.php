<?php
/**
 * Testy Kroku 17 — CompositeContentSource (KONTRAKT k17-v3, §3.6).
 *
 * Pisane W CIEMNO, wyłącznie z kontraktu.
 *
 * Najważniejsze dwie asercje w tym pliku:
 *  - BRAMKA STRUKTURALNA (§1 reguła 4): dokument wstrzyknięty przez OBCE źródło musi
 *    przejść przez `WpContentSource::is_indexable()`. Bramka w każdym źródle z osobna
 *    była konwencją — źródło z filtra `aifaq_content_sources` mogło ją pominąć.
 *  - DEDUPLIKACJA PO HASZU SUROWEJ LINII (§3.6 pkt 6): „Czesne: 450 zł" i „Czesne: 890 zł"
 *    MUSZĄ przeżyć obie. Hasz znormalizowany zlałby je w jedną, a to nie utrata danych,
 *    tylko FABRYKACJA faktu, który bot podałby jako cytat z witryny.
 *
 * URUCHOMIENIE:  php -d extension=mbstring tests/krok17-composite-test.php
 * Kod wyjścia: 0 = OK, 1 = błędy.
 *
 * @package AI_FAQ_Generator
 */

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
if ( ! defined( 'AIFAQ_VERSION' ) ) { define( 'AIFAQ_VERSION', '0.20.0-test' ); }

$GLOBALS['__opt']      = array();
$GLOBALS['__postdata'] = array();
$GLOBALS['__meta']     = array();

if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'get_bloginfo' ) ) { function get_bloginfo( $show = 'name' ) { return 'Przedszkole Testowe'; } }
if ( ! function_exists( 'home_url' ) ) { function home_url( $path = '/' ) { return 'https://example.test' . $path; } }
if ( ! function_exists( 'get_the_title' ) ) { function get_the_title( $id ) { return 'Tytuł ' . (int) $id; } }
if ( ! function_exists( 'get_permalink' ) ) { function get_permalink( $p = 0 ) { $id = is_object( $p ) ? (int) $p->ID : (int) $p; return 'https://example.test/?p=' . $id; } }
if ( ! function_exists( 'get_posts' ) ) { function get_posts( $args = array() ) { return array(); } }
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = false ) {
		return array_key_exists( $key, $GLOBALS['__opt'] ) ? $GLOBALS['__opt'][ $key ] : $default;
	}
}
if ( ! function_exists( 'get_post_status' ) ) {
	function get_post_status( $id = 0 ) { return $GLOBALS['__postdata'][ (int) $id ]['post_status'] ?? 'publish'; }
}
if ( ! function_exists( 'get_post_type' ) ) {
	function get_post_type( $id = 0 ) { return $GLOBALS['__postdata'][ (int) $id ]['post_type'] ?? 'page'; }
}
if ( ! function_exists( 'get_post_field' ) ) {
	function get_post_field( $field, $id = 0, $context = 'display' ) { return $GLOBALS['__postdata'][ (int) $id ][ $field ] ?? ''; }
}
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $id, $key = '', $single = false ) {
		$all = $GLOBALS['__meta'][ (int) $id ] ?? array();
		if ( '' === $key ) { return $all; }
		$vals = $all[ $key ] ?? array();
		return $single ? ( $vals[0] ?? '' ) : $vals;
	}
}
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $hook, $value = null, ...$args ) { return $value; } }

$fail = 0;
$ran  = 0;
function check( $cond, $label ) {
	global $fail, $ran;
	++$ran;
	echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n";
	if ( ! $cond ) { $fail++; }
}

require __DIR__ . '/../src/Index/ContentSource.php';
require __DIR__ . '/../src/Index/WpContentSource.php';
foreach ( array( 'BoilerplateFilter', 'CompositeContentSource' ) as $cls ) {
	$f = __DIR__ . '/../src/Index/' . $cls . '.php';
	if ( is_file( $f ) ) { require $f; }
}

use AIFAQ\Index\ContentSource;

/** Atrapa źródła — zwraca podane dokumenty. */
class FakeSrc implements ContentSource {
	public $docs = array();
	public function __construct( array $docs = array() ) { $this->docs = $docs; }
	public function documents(): array { return $this->docs; }
}
/** Atrapa źródła pod stats() — osobna klasa, bo klucz stats() to KRÓTKA NAZWA KLASY. */
class SrcAlfa extends FakeSrc {}
/** Druga atrapa pod stats(). */
class SrcBeta extends FakeSrc {}
/** Źródło wysypujące się w połowie pracy. */
class ThrowingSrc implements ContentSource {
	public function documents(): array { throw new \RuntimeException( 'awaria źródła treści' ); }
}
/**
 * Źródło niekompletne — implementuje ContentSource i DOKŁADA metodę is_complete().
 * Kontrakt §3.7 pkt 2 wymaga duck typingu (method_exists), nie instanceof.
 */
class IncompleteSrc extends FakeSrc {
	public function is_complete(): bool { return false; }
}

/** Dokument w kształcie §2.1. */
function cd( $id, $text, $title = '', $url = '' ) {
	return array( 'post_id' => $id, 'title' => $title, 'url' => $url, 'text' => $text );
}
/** Liczy wystąpienia dokładnej linii w zestawie. */
function cd_count_line( array $docs, $line ) {
	$n = 0;
	foreach ( $docs as $d ) {
		foreach ( explode( "\n", (string) $d['text'] ) as $l ) { if ( trim( $l ) === $line ) { ++$n; } }
	}
	return $n;
}

// Stan wpisów — ustawiony RAZ (is_indexable memoizuje wynik per post_id).
$GLOBALS['__postdata'] = array(
	710 => array( 'post_status' => 'draft', 'post_type' => 'page' ),
	711 => array( 'post_status' => 'publish', 'post_type' => 'page' ),
	712 => array( 'post_status' => 'publish', 'post_type' => 'page', 'post_password' => 'sekret' ),
	713 => array( 'post_status' => 'publish', 'post_type' => 'attachment' ),
);

if ( class_exists( 'AIFAQ\Index\CompositeContentSource' ) ) {

	$C = 'AIFAQ\Index\CompositeContentSource';

	// =======================================================================
	echo "=== A. Scalanie po post_id (§3.6 pkt 5) ===\n";
	$src = new $C( array(
		new FakeSrc( array( cd( 700, 'Treść z bazy.', '', '' ) ) ),
		new FakeSrc( array( cd( 700, 'Treść z renderu.', 'Tytuł 700', 'https://example.test/700' ) ) ),
	) );
	$docs = $src->documents();
	check( 1 === count( $docs ), 'dwa źródła z tym samym post_id → DOKŁADNIE jeden dokument' );
	check( isset( $docs[0] ) && "Treść z bazy.\nTreść z renderu." === $docs[0]['text'], 'text sklejony "\\n" w kolejności źródeł' );
	check( isset( $docs[0] ) && 'Tytuł 700' === $docs[0]['title'], 'pierwszy NIEPUSTY title wygrywa' );
	check( isset( $docs[0] ) && 'https://example.test/700' === $docs[0]['url'], 'pierwszy NIEPUSTY url wygrywa' );

	// =======================================================================
	echo "\n=== B. Źródło rzuca wyjątek → reszta działa, is_complete() === false ===\n";
	$src = new $C( array( new ThrowingSrc(), new FakeSrc( array( cd( 701, 'Ocalała treść.' ) ) ) ) );
	$docs = $src->documents();
	check( 1 === count( $docs ), 'wyjątek jednego źródła nie zabiera dokumentów pozostałym' );
	check( isset( $docs[0] ) && 701 === (int) $docs[0]['post_id'], 'ocalał dokument z drugiego źródła' );
	check( false === $src->is_complete(), 'po wyjątku is_complete() === false (Indexer pominie pruning)' );
	check( 1 === count( $src->warnings() ), 'wyjątek dopisał dokładnie 1 ostrzeżenie' );

	// =======================================================================
	echo "\n=== C. Element $sources niebędący źródłem → pominięty, is_complete() BEZ zmiany ===\n";
	$src = new $C( array( 'nie-obiekt', new stdClass(), new FakeSrc( array( cd( 702, 'Poprawna treść.' ) ) ) ) );
	$docs = $src->documents();
	check( 1 === count( $docs ), 'string i obiekt bez documents() pominięte, potok nie pada' );
	check( true === $src->is_complete(), 'is_complete() pozostaje true (§3.6 pkt 2 — to nie awaria źródła)' );
	check( 2 === count( $src->warnings() ), 'dwa nieprawidłowe elementy → 2 ostrzeżenia' );

	// =======================================================================
	echo "\n=== D. Dokumenty bez post_id / bez text odrzucane (§3.6 pkt 3) ===\n";
	$src = new $C( array( new FakeSrc( array(
		array( 'title' => 'Bez post_id', 'url' => 'u', 'text' => 'Treść bez identyfikatora.' ),
		cd( 0, 'post_id zero.' ),
		cd( -5, 'post_id ujemny.' ),
		cd( 703, '' ),
		array( 'post_id' => 704, 'title' => '', 'url' => '' ),
		cd( 705, 'Jedyny poprawny dokument.' ),
	) ) ) );
	$docs = $src->documents();
	check( 1 === count( $docs ), '5 wadliwych dokumentów odrzuconych, zostaje 1 poprawny' );
	check( isset( $docs[0] ) && 705 === (int) $docs[0]['post_id'], 'ocalał dokument o post_id 705' );

	// =======================================================================
	echo "\n=== E. BRAMKA STRUKTURALNA is_indexable() (§1 reguła 4, §3.6 pkt 4) ===\n";
	$src = new $C( array( new FakeSrc( array(
		cd( 710, 'Szkic — nie wolno indeksować.' ),
		cd( 712, 'Wpis z hasłem — nie wolno indeksować.' ),
		cd( 713, 'Załącznik — nie wolno indeksować.' ),
		cd( 711, 'Opublikowana strona — wolno.' ),
	) ) ) );
	$docs = $src->documents();
	check( 1 === count( $docs ), 'źródło podało 4 dokumenty, bramka przepuściła 1' );
	check( isset( $docs[0] ) && 711 === (int) $docs[0]['post_id'], 'PARA POZYTYWNA: przeszedł wyłącznie wpis opublikowany (711)' );
	$joined = isset( $docs[0] ) ? $docs[0]['text'] : '';
	check( false === strpos( $joined, 'nie wolno indeksować' ), 'żadna treść niedozwolona nie przeciekła do wyniku' );

	// =======================================================================
	echo "\n=== F. Deduplikacja po haszu SUROWEJ linii (§3.6 pkt 6) ===\n";
	$src = new $C( array(
		new FakeSrc( array( cd( 720, "Godziny otwarcia 7:00-18:00\nCzesne: 450 zł" ) ) ),
		new FakeSrc( array( cd( 720, "Godziny otwarcia 7:00-18:00\nCzesne: 890 zł" ) ) ),
	) );
	$docs = $src->documents();
	check( 1 === count( $docs ), 'jeden dokument po scaleniu' );
	check( 1 === cd_count_line( $docs, 'Godziny otwarcia 7:00-18:00' ), 'identyczna linia z dwóch źródeł zachowana RAZ (pierwsze wystąpienie)' );
	check( 1 === cd_count_line( $docs, 'Czesne: 450 zł' ) && 1 === cd_count_line( $docs, 'Czesne: 890 zł' ),
		'PARA POZYTYWNA: dwie kwoty różniące się tylko cyframi PRZEŻYWAJĄ OBIE (brak fabrykacji faktu)' );
	check( isset( $docs[0] ) && "Godziny otwarcia 7:00-18:00\nCzesne: 450 zł\nCzesne: 890 zł" === $docs[0]['text'],
		'wynikowy tekst dokładnie w kolejności źródeł, bez duplikatu' );

	echo "\n=== G. Deduplikacja NIE działa między różnymi post_id ===\n";
	$src  = new $C( array( new FakeSrc( array( cd( 730, 'Zapraszamy do kontaktu.' ), cd( 731, 'Zapraszamy do kontaktu.' ) ) ) ) );
	$docs = $src->documents();
	check( 2 === count( $docs ), 'dwa różne wpisy → dwa dokumenty' );
	check( 2 === cd_count_line( $docs, 'Zapraszamy do kontaktu.' ), 'ta sama linia w dwóch wpisach zostaje w obu (dedup tylko w obrębie post_id)' );

	// =======================================================================
	echo "\n=== H. stats() — klucz = krótka nazwa klasy, docs + chars ===\n";
	$txt_a = 'Treść wniesiona przez pierwsze źródło.';
	$txt_b = 'Treść wniesiona przez drugie źródło, nieco dłuższa.';
	$src   = new $C( array( new SrcAlfa( array( cd( 740, $txt_a ) ) ), new SrcBeta( array( cd( 741, $txt_b ) ) ) ) );
	$src->documents();
	$stats = $src->stats();
	check( 2 === count( $stats ), 'stats() ma po jednym wpisie na źródło' );
	check( isset( $stats['SrcAlfa'], $stats['SrcBeta'] ), 'klucze to KRÓTKIE nazwy klas (bez namespace)' );
	check( isset( $stats['SrcAlfa']['docs'] ) && 1 === (int) $stats['SrcAlfa']['docs'], 'SrcAlfa: docs === 1' );
	check( isset( $stats['SrcBeta']['docs'] ) && 1 === (int) $stats['SrcBeta']['docs'], 'SrcBeta: docs === 1' );
	check( isset( $stats['SrcAlfa']['chars'] ) && mb_strlen( $txt_a ) === (int) $stats['SrcAlfa']['chars'], 'SrcAlfa: chars === długość wniesionego tekstu' );
	check( isset( $stats['SrcBeta']['chars'] ) && mb_strlen( $txt_b ) === (int) $stats['SrcBeta']['chars'], 'SrcBeta: chars === długość wniesionego tekstu' );

	// =======================================================================
	echo "\n=== I. is_complete() — stan początkowy i duck typing ===\n";
	$src = new $C( array( new FakeSrc( array( cd( 750, 'Treść.' ) ) ) ) );
	check( true === $src->is_complete(), 'przed pierwszym documents() → true' );
	$src->documents();
	check( true === $src->is_complete(), 'źródło BEZ metody is_complete() nie psuje kompletności' );
	$src2 = new $C( array( new IncompleteSrc( array( cd( 751, 'Treść z niedokończonego crawla.' ) ) ) ) );
	$src2->documents();
	check( false === $src2->is_complete(), 'źródło z is_complete() === false → kompozyt niekompletny' );
	check( 1 === count( $src2->documents() ), 'niekompletne źródło mimo to ODDAJE to, co ma (§3.3)' );

	// =======================================================================
	echo "\n=== J. Wpięcie BoilerplateFilter + wybór sinka (§3.6 pkt 8) ===\n";
	if ( class_exists( 'AIFAQ\Index\BoilerplateFilter' ) ) {
		$header = 'Menu Start Oferta Kontakt';
		// UWAGA (spięcie K17): treść unikalna musi różnić się czymś WIĘCEJ niż liczbą.
		// `normalize_line()` (§3.5) zamienia ciągi cyfr na `#` przy liczeniu `df`, więc
		// „Podstrona numer 760 …” i „… 761 …” to dla filtra ta sama linia — cały korpus
		// stałby się balastem i `DOC_FLOOR` uratowałby wszystkie dokumenty.
		$mk6 = static function () use ( $header ) {
			$tematy = array(
				'rytmika i zabawy muzyczne', 'plastyka oraz prace ręczne', 'gimnastyka korekcyjna',
				'język angielski w zabawie', 'zajęcia logopedyczne', 'przyroda i eksperymenty',
			);
			$out = array();
			for ( $i = 760; $i <= 765; $i++ ) {
				$out[] = cd(
					$i,
					$header . "\n" . 'Podstrona numer ' . $i . ' opisuje ' . $tematy[ $i - 760 ]
						. ', plan dnia oraz zasady zapisów do grupy przedszkolnej.'
				);
			}
			return $out;
		};

		// Para NEGATYWNA: 4 dokumenty z tego samego korpusu → filtr nie rusza.
		$src  = new $C( array( new FakeSrc( array_slice( $mk6(), 0, 4 ) ) ) );
		$docs = $src->documents();
		check( 4 === cd_count_line( $docs, $header ), 'poniżej MIN_DOCS nagłówek zostaje we wszystkich 4 dokumentach' );

		// Para POZYTYWNA: 6 dokumentów, brak page_on_front → sink = najniższy post_id.
		unset( $GLOBALS['__opt']['page_on_front'] );
		$src  = new $C( array( new FakeSrc( $mk6() ) ) );
		$docs = $src->documents();
		check( 6 === count( $docs ), '6 dokumentów na wyjściu' );
		check( 1 === cd_count_line( $docs, $header ), 'nagłówek przeniesiony — DOKŁADNIE jedno wystąpienie w całej bazie wiedzy' );
		$sink_id = 0;
		foreach ( $docs as $d ) { if ( false !== strpos( $d['text'], $header ) ) { $sink_id = (int) $d['post_id']; } }
		check( 760 === $sink_id, 'bez page_on_front sinkiem jest NAJNIŻSZY post_id (760)' );

		// page_on_front wskazuje inny dokument.
		$GLOBALS['__opt']['page_on_front'] = 763;
		$src  = new $C( array( new FakeSrc( $mk6() ) ) );
		$docs = $src->documents();
		$sink_id = 0;
		foreach ( $docs as $d ) { if ( false !== strpos( $d['text'], $header ) ) { $sink_id = (int) $d['post_id']; } }
		check( 763 === $sink_id, 'sinkiem jest strona główna z opcji page_on_front' );
		check( 1 === cd_count_line( $docs, $header ), 'nadal dokładnie jedno wystąpienie nagłówka' );
		unset( $GLOBALS['__opt']['page_on_front'] );
	} else {
		check( false, 'SEKCJA J POMINIĘTA: brak klasy AIFAQ\Index\BoilerplateFilter (Etap 2)' );
	}

} else {
	check( false, 'SEKCJE A–J POMINIĘTE: brak klasy AIFAQ\Index\CompositeContentSource (Etap 2)' );
}

echo "\n=== PODŁOGA ASERCJI ===\n";
$done = $ran;
check( $done >= 32, 'wykonano co najmniej 32 asercje (było: ' . $done . ')' );

echo "\n=== PODSUMOWANIE ===\n";
echo ( 0 === $fail ) ? "TEST KROK 17 (CompositeContentSource): WSZYSTKIE ASERCJE OK ($ran)\n" : "TEST KROK 17 (CompositeContentSource): $fail ASERCJI NIE PRZESZŁO (z $ran)\n";
exit( 0 === $fail ? 0 : 1 );
