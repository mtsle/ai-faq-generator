<?php
/**
 * Krok 6, etap 6.1 — wybor szablonu i adresy Centrum Wiedzy.
 *
 * Zestaw sprawdza DWIE rzeczy i obie na zachowaniu, nie na tekscie kodu:
 *
 *   1. Kolejnosc pierwszenstwa szablonow:
 *      motyw potomny/nadrzedny > szablon wtyczki > to, co dal WordPress.
 *   2. Goly `/centrum-wiedzy/kategoria/` odsyla 301 na archiwum, a kazdy inny
 *      adres i kazde zapytanie, ktore nie jest 404 — nie odsyla nigdzie.
 *
 * SZABLONY WTYCZKI SA TU PODSTAWIONE, nie prawdziwe: zestaw tworzy wlasny
 * katalog tymczasowy i wskazuje na niego `AINP_PLUGIN_DIR`. Dzieki temu
 * asercje o wyborze pliku dzialaja juz w etapie 6.1, zanim `src/templates/`
 * w ogole powstanie (6.2 i 6.5), a gdy powstanie — nic sie w tym zestawie
 * nie zmienia. Test mierzy REGULE wyboru, nie zawartosc katalogu.
 *
 * `locate_template()` jest atrapa ze stanem: udaje motyw, ktory ma dokladnie
 * te pliki, ktore mu wpiszemy. Kazde wywolanie jest zapisywane, wiec da sie
 * asercjonowac takze to, o CO wtyczka pytala motyw — a to jest sedno etapu:
 * pytanie o `archive.php` zamiast o `archive-ainp_article.php` cofneloby caly
 * mechanizm i nie widac by tego po samym wyniku.
 *
 * Prawdziwa klasa: `Portal`. Atrapy: `Plugin` (stale), funkcje WordPressa.
 *
 * URUCHOMIENIE:  php tests/krok6-portal-test.php
 * Kod wyjscia: 0 = OK, 1 = bledy.
 *
 * @package AI_News_Portal
 */

namespace {
	define( 'ABSPATH', __DIR__ . '/atrapy/wp/' );

	$fail = 0;
	$ran  = 0;

	/**
	 * Asercja.
	 *
	 * @param bool   $cond  Warunek.
	 * @param string $label Opis.
	 *
	 * @return void
	 */
	function k6p_check( $cond, $label ) {
		global $fail, $ran;
		$ran++;
		echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n";
		if ( ! $cond ) {
			$fail++;
		}
	}

	// --- Katalog udajacy wtyczke -------------------------------------------
	$tmp = sys_get_temp_dir() . '/ainp-portal-' . getmypid() . '/';
	@mkdir( $tmp . 'src/templates/', 0777, true );
	file_put_contents( $tmp . 'src/templates/archive.php', '<?php // archiwum wtyczki' );
	file_put_contents( $tmp . 'src/templates/single.php', '<?php // artykul wtyczki' );

	define( 'AINP_PLUGIN_DIR', $tmp );

	// --- Stan atrap --------------------------------------------------------
	$GLOBALS['__stan'] = array(
		'singular'     => false,
		'tax'          => false,
		'archive'      => false,
		'is404'        => false,
		'obiekt'       => null,
		'motyw'        => array(),   // Pliki, ktore „ma" motyw.
		'pytania'      => array(),   // Kazde wywolanie locate_template().
		'przekierowanie' => null,    // array( url, status ).
		'hooki'        => array(),
	);

	/**
	 * Zeruje stan miedzy przypadkami, zostawiajac liste plikow motywu.
	 *
	 * @param array $zmiany Nadpisania stanu.
	 *
	 * @return void
	 */
	function k6p_stan( array $zmiany = array() ) {
		$GLOBALS['__stan'] = array_merge(
			$GLOBALS['__stan'],
			array(
				'singular'       => false,
				'tax'            => false,
				'archive'        => false,
				'is404'          => false,
				'obiekt'         => null,
				'pytania'        => array(),
				'przekierowanie' => null,
			),
			$zmiany
		);
	}

	// --- Atrapy WordPressa -------------------------------------------------
	function add_filter( $hook, $cb, $prio = 10, $args = 1 ) {
		$GLOBALS['__stan']['hooki'][] = array( 'filter', $hook, $cb );
		return true;
	}

	function add_action( $hook, $cb, $prio = 10, $args = 1 ) {
		$GLOBALS['__stan']['hooki'][] = array( 'action', $hook, $cb );
		return true;
	}

	function is_singular( $typ = '' ) {
		return ( 'ainp_article' === $typ ) && $GLOBALS['__stan']['singular'];
	}

	function is_tax( $tax = '' ) {
		return ( 'ainp_topic' === $tax ) && $GLOBALS['__stan']['tax'];
	}

	function is_post_type_archive( $typ = '' ) {
		return ( 'ainp_article' === $typ ) && $GLOBALS['__stan']['archive'];
	}

	function is_404() {
		return (bool) $GLOBALS['__stan']['is404'];
	}

	function get_queried_object() {
		return $GLOBALS['__stan']['obiekt'];
	}

	/**
	 * Atrapa motywu: zwraca pierwszy kandydat, ktory motyw „ma".
	 *
	 * Odwzorowuje zachowanie prawdziwej funkcji: kolejnosc kandydatow decyduje,
	 * pusty ciag znaczy „motyw nie ma zadnego z nich".
	 *
	 * @param array|string $nazwy Kandydaci.
	 *
	 * @return string
	 */
	function locate_template( $nazwy, $load = false, $once = true ) {
		$nazwy = (array) $nazwy;
		$GLOBALS['__stan']['pytania'][] = $nazwy;

		foreach ( $nazwy as $nazwa ) {
			if ( in_array( $nazwa, $GLOBALS['__stan']['motyw'], true ) ) {
				return '/motyw/' . $nazwa;
			}
		}

		return '';
	}

	function get_post_type_archive_link( $typ ) {
		return 'https://dworek.local/centrum-wiedzy/';
	}

	function home_url( $sciezka = '' ) {
		return 'https://dworek.local' . $sciezka;
	}

	function wp_safe_redirect( $url, $status = 302 ) {
		$GLOBALS['__stan']['przekierowanie'] = array( $url, $status );
		// Prawdziwy `Portal` konczy `exit`. Wyjatek zatrzymuje go tak samo,
		// a zestaw moze isc dalej — ten sam chwyt co przy akcjach panelu.
		throw new \RuntimeException( 'redirect' );
	}

	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}

	function wp_unslash( $wartosc ) {
		return is_string( $wartosc ) ? stripslashes( $wartosc ) : $wartosc;
	}

	function sanitize_text_field( $tekst ) {
		return trim( strip_tags( (string) $tekst ) );
	}
}

namespace AINP {

	/**
	 * Atrapa `Plugin` — same stale. Prawdziwa klasa ciagnie za soba tabele,
	 * crona i rejestracje CPT, a `Portal` potrzebuje z niej trzech napisow.
	 */
	final class Plugin {
		public const CPT           = 'ainp_article';
		public const TAX           = 'ainp_topic';
		public const ARCHIVE_SLUG  = 'centrum-wiedzy';
	}
}

namespace {

	require_once dirname( __DIR__ ) . '/src/Portal.php';

	echo "== Krok 6, etap 6.1: szablony i adresy Centrum Wiedzy ==\n\n";

	// ---------------------------------------------------------------------
	echo "-- Podpiecie hookow --\n";

	\AINP\Portal::register();
	$hooki = $GLOBALS['__stan']['hooki'];

	k6p_check( 3 === count( $hooki ), 'register() podpina dokladnie 3 hooki (jest: ' . count( $hooki ) . ')' );
	k6p_check( 'filter' === $hooki[0][0] && 'template_include' === $hooki[0][1], 'pierwszy to filtr template_include' );
	k6p_check( 'action' === $hooki[1][0] && 'template_redirect' === $hooki[1][1], 'drugi to akcja template_redirect' );
	// Dolozone w 6.2. Arkusz stylow ma wlasny zestaw asercji w `krok6-karty-test.php`;
	// tutaj interesuje nas wylacznie to, ze `register()` jest jedynym miejscem,
	// w ktorym front wtyczki podpina sie do WordPressa.
	k6p_check( 'action' === $hooki[2][0] && 'wp_enqueue_scripts' === $hooki[2][1], 'trzeci to akcja wp_enqueue_scripts' );

	// ---------------------------------------------------------------------
	echo "\n-- Archiwum CPT --\n";

	k6p_stan( array( 'archive' => true, 'motyw' => array() ) );
	$wynik = \AINP\Portal::filter_template( '/motyw/archive.php' );

	k6p_check(
		$wynik === $tmp . 'src/templates/archive.php',
		'motyw bez wlasnego szablonu → wchodzi archive.php wtyczki'
	);
	k6p_check(
		array( array( 'archive-ainp_article.php' ) ) === $GLOBALS['__stan']['pytania'],
		'motyw pytany WYLACZNIE o archive-ainp_article.php'
	);

	$pytania = $GLOBALS['__stan']['pytania'][0];
	k6p_check( ! in_array( 'archive.php', $pytania, true ), 'ogolny archive.php NIE jest kandydatem motywu' );
	k6p_check( ! in_array( 'index.php', $pytania, true ), 'index.php NIE jest kandydatem motywu' );

	k6p_stan( array( 'archive' => true, 'motyw' => array( 'archive-ainp_article.php' ) ) );
	$wynik = \AINP\Portal::filter_template( '/motyw/archive.php' );

	k6p_check(
		'/motyw/archive-ainp_article.php' === $wynik,
		'motyw z wlasnym szablonem BIJE szablon wtyczki'
	);

	// ---------------------------------------------------------------------
	echo "\n-- Archiwum kategorii --\n";

	k6p_stan( array( 'tax' => true, 'motyw' => array(), 'obiekt' => (object) array( 'slug' => 'zywienie' ) ) );
	$wynik = \AINP\Portal::filter_template( '/motyw/archive.php' );

	k6p_check(
		$wynik === $tmp . 'src/templates/archive.php',
		'kategoria dostaje TEN SAM archive.php co archiwum CPT'
	);
	k6p_check(
		array( 'taxonomy-ainp_topic-zywienie.php', 'taxonomy-ainp_topic.php' ) === $GLOBALS['__stan']['pytania'][0],
		'kandydaci motywu: najpierw wersja dla terminu, potem ogolna taksonomii'
	);

	k6p_stan( array( 'tax' => true, 'motyw' => array( 'taxonomy-ainp_topic.php' ), 'obiekt' => (object) array( 'slug' => 'zywienie' ) ) );
	k6p_check(
		'/motyw/taxonomy-ainp_topic.php' === \AINP\Portal::filter_template( '/motyw/archive.php' ),
		'motyw z taxonomy-ainp_topic.php bije wtyczke'
	);

	k6p_stan(
		array(
			'tax'    => true,
			'motyw'  => array( 'taxonomy-ainp_topic.php', 'taxonomy-ainp_topic-zywienie.php' ),
			'obiekt' => (object) array( 'slug' => 'zywienie' ),
		)
	);
	k6p_check(
		'/motyw/taxonomy-ainp_topic-zywienie.php' === \AINP\Portal::filter_template( '/motyw/archive.php' ),
		'przy obu plikach motywu wygrywa ten dla konkretnego terminu'
	);

	k6p_stan( array( 'tax' => true, 'motyw' => array(), 'obiekt' => null ) );
	\AINP\Portal::filter_template( '/motyw/archive.php' );
	k6p_check(
		array( 'taxonomy-ainp_topic.php' ) === $GLOBALS['__stan']['pytania'][0],
		'brak obiektu terminu nie doklada kandydata z pustym slugiem'
	);

	// ---------------------------------------------------------------------
	echo "\n-- Pojedynczy artykul --\n";

	k6p_stan( array( 'singular' => true, 'motyw' => array(), 'obiekt' => (object) array( 'post_name' => 'karma-dla-szczeniaka' ) ) );
	$wynik = \AINP\Portal::filter_template( '/motyw/single.php' );

	k6p_check(
		$wynik === $tmp . 'src/templates/single.php',
		'artykul bez szablonu motywu → single.php wtyczki'
	);
	k6p_check(
		array( 'single-ainp_article-karma-dla-szczeniaka.php', 'single-ainp_article.php' ) === $GLOBALS['__stan']['pytania'][0],
		'kandydaci motywu: najpierw wersja dla wpisu, potem ogolna typu'
	);

	k6p_stan( array( 'singular' => true, 'motyw' => array( 'single-ainp_article.php' ), 'obiekt' => null ) );
	k6p_check(
		'/motyw/single-ainp_article.php' === \AINP\Portal::filter_template( '/motyw/single.php' ),
		'motyw z single-ainp_article.php bije wtyczke'
	);

	// ---------------------------------------------------------------------
	echo "\n-- Cudze zapytania --\n";

	k6p_stan( array( 'motyw' => array() ) );
	$wynik = \AINP\Portal::filter_template( '/motyw/page.php' );

	k6p_check( '/motyw/page.php' === $wynik, 'zwykla strona wraca bez zmian' );
	k6p_check( array() === $GLOBALS['__stan']['pytania'], 'przy cudzym zapytaniu motyw NIE jest w ogole pytany' );

	// ---------------------------------------------------------------------
	echo "\n-- Brak pliku wtyczki (stan etapu 6.1) --\n";

	unlink( $tmp . 'src/templates/archive.php' );
	k6p_stan( array( 'archive' => true, 'motyw' => array() ) );
	k6p_check(
		'/motyw/archive.php' === \AINP\Portal::filter_template( '/motyw/archive.php' ),
		'brak pliku wtyczki → szablon WordPressa bez zmian, zero include na pusto'
	);
	file_put_contents( $tmp . 'src/templates/archive.php', '<?php // archiwum wtyczki' );

	// ---------------------------------------------------------------------
	echo "\n-- Goly adres kategorii: 301 --\n";

	/**
	 * Odpala przekierowanie i zwraca zapisany stan.
	 *
	 * @param string $uri   Adres zadania.
	 * @param bool   $is404 Czy WordPress uznal zapytanie za 404.
	 *
	 * @return array|null
	 */
	function k6p_redirect( $uri, $is404 = true ) {
		$_SERVER['REQUEST_URI'] = $uri;
		k6p_stan( array( 'is404' => $is404 ) );

		try {
			\AINP\Portal::redirect_taxonomy_base();
		} catch ( \RuntimeException $e ) {
			// Zamiast `exit`.
		}

		return $GLOBALS['__stan']['przekierowanie'];
	}

	$r = k6p_redirect( '/centrum-wiedzy/kategoria/' );
	k6p_check( null !== $r, 'goly /centrum-wiedzy/kategoria/ przekierowuje' );
	k6p_check( 'https://dworek.local/centrum-wiedzy/' === $r[0], 'celem jest archiwum Centrum Wiedzy' );
	k6p_check( 301 === $r[1], 'status to 301, nie 302' );

	k6p_check( null !== k6p_redirect( '/centrum-wiedzy/kategoria' ), 'wersja bez ukosnika na koncu tez lapie' );
	k6p_check( null !== k6p_redirect( '/centrum-wiedzy/kategoria/?ainp_s=karma' ), 'ogon zapytania nie psuje dopasowania' );

	k6p_check( null === k6p_redirect( '/centrum-wiedzy/kategoria/zywienie/' ), 'prawdziwa kategoria NIE jest przekierowywana' );
	k6p_check( null === k6p_redirect( '/centrum-wiedzy/' ), 'archiwum NIE jest przekierowywane' );
	k6p_check( null === k6p_redirect( '/centrum-wiedzy/karma-dla-szczeniaka/' ), 'artykul NIE jest przekierowywany' );
	k6p_check( null === k6p_redirect( '/kategoria/' ), 'sam czlon kategoria bez archiwum nie lapie' );
	k6p_check( null === k6p_redirect( '/centrum-wiedzy/kategoria/', false ), 'gdy to nie jest 404 — zero przekierowania' );

	echo "\n-- Instalacja w podkatalogu --\n";

	// Motyw i tresc te same, zmienia sie tylko `home_url()`.
	k6p_check(
		'centrum-wiedzy/kategoria' === trim( (string) wp_parse_url( '/centrum-wiedzy/kategoria/', PHP_URL_PATH ), '/' ),
		'atrapa parsowania sciezki dziala tak, jak zaklada Portal'
	);

	// --- Sprzatanie --------------------------------------------------------
	@unlink( $tmp . 'src/templates/archive.php' );
	@unlink( $tmp . 'src/templates/single.php' );
	@rmdir( $tmp . 'src/templates' );
	@rmdir( $tmp . 'src' );
	@rmdir( $tmp );

	echo "\nWYNIK: " . ( $ran - $fail ) . " / {$ran} asercji\n";
	exit( $fail > 0 ? 1 : 0 );
}
