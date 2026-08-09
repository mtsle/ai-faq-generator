<?php
/**
 * Krok 6, etap 6.2 — karty archiwum: zdjecia kategorii, kafelek, zajawka.
 *
 * Zestaw mierzy DECYZJE, ktore podejmuje `Portal`, a nie wyglad HTML-a:
 * ktore zdjecie trafia na karte, co sie dzieje, gdy pliku nie ma, skad
 * bierze sie zajawka i naglowek archiwum. Rysowanie jest w `card.php`
 * i `archive.php`; te pliki sa tu sprawdzane osobno — parsowaniem, nie
 * czytaniem jako tekst (GOTCHA 28).
 *
 * NAJWAZNIEJSZA ASERCJA ZESTAWU dotyczy zajawki: `Portal::lead()` czyta
 * `post_excerpt` i ma NIE siegac po `get_the_excerpt()`. Roznica jest
 * niewidoczna, dopoki lead jest wypelniony — a przy pustym `post_excerpt`
 * `get_the_excerpt()` podstawia obciete 55 slow tresci artykulu. Test trzyma
 * w atrapie obie wartosci i sprawdza, ktora wychodzi.
 *
 * Prawdziwa klasa: `Portal`. Atrapy: `Plugin` (stale), funkcje WordPressa.
 *
 * URUCHOMIENIE:  php tests/krok6-karty-test.php
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
	function k6k_check( $cond, $label ) {
		global $fail, $ran;
		$ran++;
		echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n";
		if ( ! $cond ) {
			$fail++;
		}
	}

	// --- Katalog udajacy wtyczke -------------------------------------------
	$tmp = sys_get_temp_dir() . '/ainp-karty-' . getmypid() . '/';
	@mkdir( $tmp . 'assets/kategorie/', 0777, true );
	@mkdir( $tmp . 'src/templates/', 0777, true );
	file_put_contents( $tmp . 'assets/kategorie/zywienie.jpg', 'udaje-jpeg' );
	file_put_contents( $tmp . 'src/templates/card.php', '<?php $GLOBALS["__narysowano"][] = "wtyczka";' );

	define( 'AINP_PLUGIN_DIR', $tmp );
	define( 'AINP_PLUGIN_URL', 'https://dworek.local/wp-content/plugins/ai-news-portal/' );
	define( 'AINP_VERSION', '0.5.0' );

	$GLOBALS['__stan'] = array(
		'singular'  => false,
		'tax'       => false,
		'archive'   => false,
		'is404'     => false,
		'obiekt'    => null,
		'motyw'     => array(),
		'style'     => array(),
		'terminy'   => array(),
		'wpisy'     => array(),
	);
	$GLOBALS['__narysowano'] = array();

	// --- Atrapy WordPressa -------------------------------------------------
	function add_filter( $hook, $cb, $prio = 10, $args = 1 ) {
		return true; }
	function add_action( $hook, $cb, $prio = 10, $args = 1 ) {
		return true; }

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

	function locate_template( $nazwy, $load = false, $once = true ) {
		foreach ( (array) $nazwy as $nazwa ) {
			if ( in_array( $nazwa, $GLOBALS['__stan']['motyw'], true ) ) {
				return $GLOBALS['__stan']['motyw_dir'] . $nazwa;
			}
		}
		return '';
	}

	function wp_enqueue_style( $uchwyt, $src = '', $dep = array(), $ver = false, $media = 'all' ) {
		$GLOBALS['__stan']['style'][] = array(
			'uchwyt' => $uchwyt,
			'src'    => $src,
			'ver'    => $ver,
		);
	}

	function get_the_terms( $post_id, $tax ) {
		if ( 'ainp_topic' !== $tax ) {
			return false;
		}
		return $GLOBALS['__stan']['terminy'][ $post_id ] ?? false;
	}

	function get_term_link( $termin, $tax = '' ) {
		if ( is_object( $termin ) && 'bez-adresu' === $termin->slug ) {
			return new \WP_Error( 'brak', 'nie ma takiej taksonomii' );
		}
		return 'https://dworek.local/centrum-wiedzy/kategoria/' . $termin->slug . '/';
	}

	function get_post( $post_id ) {
		return $GLOBALS['__stan']['wpisy'][ $post_id ] ?? null;
	}

	function get_the_excerpt( $post_id = null ) {
		// Sroda pulapki: gdyby `Portal::lead()` siegnal tutaj, test to zobaczy.
		return 'OBCIETA TRESC ARTYKULU...';
	}

	function __( $tekst, $domena = '' ) {
		return $tekst; }

	function esc_html( $tekst ) {
		return htmlspecialchars( (string) $tekst, ENT_QUOTES ); }

	class WP_Error {
		public $code;
		public $message;
		public function __construct( $code = '', $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}
	}
}

namespace AINP {
	/** Atrapa `Plugin` — same stale. */
	final class Plugin {
		public const CPT          = 'ainp_article';
		public const TAX          = 'ainp_topic';
		public const ARCHIVE_SLUG = 'centrum-wiedzy';
	}
}

namespace {

	require_once dirname( __DIR__ ) . '/src/Portal.php';

	use AINP\Portal;

	echo "== Krok 6, etap 6.2: karty, zdjecia kategorii, zajawka ==\n\n";

	// ---------------------------------------------------------------------
	echo "-- Zdjecie kategorii --\n";

	k6k_check(
		'https://dworek.local/wp-content/plugins/ai-news-portal/assets/kategorie/zywienie.jpg'
			=== Portal::category_image_url( 'zywienie' ),
		'kategoria z plikiem dostaje adres zdjecia z katalogu wtyczki'
	);
	k6k_check( '' === Portal::category_image_url( 'podroze-z-psem' ), 'kategoria bez pliku → pusty ciag, nie 404 na froncie' );
	k6k_check( '' === Portal::category_image_url( '' ), 'pusty slug → pusty ciag' );

	echo "\n-- Slug jako skladnik sciezki --\n";

	k6k_check( '' === Portal::category_image_url( '../../../wp-config' ), 'wyjscie z katalogu odrzucone' );
	k6k_check( '' === Portal::category_image_url( 'zywienie/../zdrowie' ), 'ukosnik w slugu odrzucony' );
	k6k_check( '' === Portal::category_image_url( 'Zywienie' ), 'wielka litera odrzucona (nazwy plikow sa male)' );
	k6k_check( '' === Portal::category_image_url( 'żywienie' ), 'ogonek w slugu odrzucony' );
	k6k_check( '' === Portal::category_image_url( 'zywienie.jpg' ), 'kropka w slugu odrzucona' );

	// ---------------------------------------------------------------------
	echo "\n-- Kafelek zapasowy --\n";

	k6k_check( 'Ż' === Portal::initial( 'Żywienie' ), 'inicjal polskiej litery jest CALA litera, nie polowa bajtu' );
	k6k_check( 'Ż' === mb_substr( Portal::initial( 'Żywienie' ), 0, 1 ), 'inicjal to dokladnie jeden znak' );
	k6k_check( 'S' === Portal::initial( 'szkolenie' ), 'mala litera zamieniana na wielka' );
	k6k_check( 'Z' === Portal::initial( '  Zdrowie  ' ), 'biale znaki z brzegow nie wchodza na kafelek' );
	k6k_check( '' === Portal::initial( '' ), 'pusta nazwa → pusty kafelek, bez bledu' );

	// ---------------------------------------------------------------------
	echo "\n-- Kategoria artykulu --\n";

	$zywienie = (object) array(
		'name' => 'Żywienie',
		'slug' => 'zywienie',
	);
	$GLOBALS['__stan']['terminy'] = array(
		7  => array( $zywienie ),
		8  => array(),
		9  => false,
		10 => array( (object) array( 'name' => 'Bez adresu', 'slug' => 'bez-adresu' ) ),
	);

	$t = Portal::primary_term( 7 );
	k6k_check( is_object( $t ) && 'zywienie' === $t->slug, 'artykul z kategoria oddaje ten termin' );
	k6k_check( null === Portal::primary_term( 8 ), 'pusta lista terminow → null' );
	k6k_check( null === Portal::primary_term( 9 ), 'brak terminow (false z WordPressa) → null' );

	k6k_check(
		'https://dworek.local/centrum-wiedzy/kategoria/zywienie/' === Portal::term_link( $zywienie ),
		'adres kategorii prowadzi do archiwum taksonomii'
	);
	k6k_check(
		'' === Portal::term_link( (object) array( 'name' => 'Bez adresu', 'slug' => 'bez-adresu' ) ),
		'WP_Error z get_term_link() zamienia sie w pusty ciag, nie w obiekt w atrybucie href'
	);

	// ---------------------------------------------------------------------
	echo "\n-- Zajawka --\n";

	$GLOBALS['__stan']['wpisy'] = array(
		7  => (object) array( 'post_excerpt' => 'Pierwsza pozycja skladu decyduje o wartosci karmy.' ),
		8  => (object) array( 'post_excerpt' => '' ),
		9  => (object) array( 'post_excerpt' => '   ' ),
	);

	k6k_check(
		'Pierwsza pozycja skladu decyduje o wartosci karmy.' === Portal::lead( 7 ),
		'zajawka bierze sie z post_excerpt, czyli z leadu modelu'
	);
	k6k_check( '' === Portal::lead( 8 ), 'pusty post_excerpt → pusta zajawka, BEZ podstawiania tresci artykulu' );
	k6k_check( '' === Portal::lead( 9 ), 'same biale znaki → pusta zajawka' );
	k6k_check( '' === Portal::lead( 999 ), 'nieistniejacy wpis → pusta zajawka, bez bledu' );

	// ---------------------------------------------------------------------
	echo "\n-- Naglowek archiwum --\n";

	$GLOBALS['__stan']['tax']     = true;
	$GLOBALS['__stan']['obiekt']  = $zywienie;
	k6k_check( 'Żywienie' === Portal::archive_title(), 'archiwum kategorii ma w naglowku nazwe kategorii' );

	$GLOBALS['__stan']['obiekt'] = (object) array( 'name' => '', 'slug' => 'puste' );
	k6k_check( 'Centrum Wiedzy' === Portal::archive_title(), 'kategoria bez nazwy nie zostawia pustego naglowka' );

	$GLOBALS['__stan']['tax']     = false;
	$GLOBALS['__stan']['archive'] = true;
	$GLOBALS['__stan']['obiekt']  = null;
	k6k_check( 'Centrum Wiedzy' === Portal::archive_title(), 'archiwum CPT ma w naglowku nazwe portalu' );

	// ---------------------------------------------------------------------
	echo "\n-- Arkusz stylow --\n";

	$GLOBALS['__stan']['style'] = array();
	Portal::enqueue();
	$style = $GLOBALS['__stan']['style'];

	k6k_check( 1 === count( $style ), 'na archiwum styl dokladany DOKLADNIE raz' );
	k6k_check( 'ainp-portal' === $style[0]['uchwyt'], 'uchwyt stylu ma prefiks wtyczki' );
	k6k_check(
		'https://dworek.local/wp-content/plugins/ai-news-portal/src/templates/portal.css' === $style[0]['src'],
		'styl wskazuje na portal.css w katalogu szablonow'
	);
	k6k_check( '0.5.0' === $style[0]['ver'], 'wersja stylu rowna wersji wtyczki (cache przegladarki)' );

	$GLOBALS['__stan']['archive'] = false;
	$GLOBALS['__stan']['style']   = array();
	Portal::enqueue();
	k6k_check( array() === $GLOBALS['__stan']['style'], 'poza Centrum Wiedzy styl NIE jest dokladany' );

	$GLOBALS['__stan']['singular'] = true;
	$GLOBALS['__stan']['style']    = array();
	Portal::enqueue();
	k6k_check( 1 === count( $GLOBALS['__stan']['style'] ), 'na artykule styl tez wchodzi' );
	$GLOBALS['__stan']['singular'] = false;

	// ---------------------------------------------------------------------
	echo "\n-- Fragment karty --\n";

	$GLOBALS['__narysowano']      = array();
	$GLOBALS['__stan']['motyw']   = array();
	Portal::part( 'card.php' );
	k6k_check( array( 'wtyczka' ) === $GLOBALS['__narysowano'], 'bez motywu rysuje karte wtyczki' );

	$motyw_dir = sys_get_temp_dir() . '/ainp-motyw-' . getmypid() . '/';
	@mkdir( $motyw_dir . 'ai-news-portal/', 0777, true );
	file_put_contents( $motyw_dir . 'ai-news-portal/card.php', '<?php $GLOBALS["__narysowano"][] = "motyw";' );

	$GLOBALS['__stan']['motyw_dir'] = $motyw_dir;
	$GLOBALS['__stan']['motyw']     = array( 'ai-news-portal/card.php' );
	$GLOBALS['__narysowano']        = array();
	Portal::part( 'card.php' );
	k6k_check( array( 'motyw' ) === $GLOBALS['__narysowano'], 'karta z motywu BIJE karte wtyczki' );

	$GLOBALS['__stan']['motyw'] = array();
	$GLOBALS['__narysowano']    = array();
	Portal::part( 'nie-ma-takiego.php' );
	k6k_check( array() === $GLOBALS['__narysowano'], 'brak pliku → cisza, zero bledu krytycznego' );

	// ---------------------------------------------------------------------
	echo "\n-- Pliki szablonow --\n";

	$szablony = array( 'archive.php', 'card.php', 'portal.css' );
	$katalog  = dirname( __DIR__ ) . '/src/templates/';

	foreach ( $szablony as $plik ) {
		k6k_check( file_exists( $katalog . $plik ), "szablon {$plik} istnieje" );
	}

	foreach ( array( 'archive.php', 'card.php' ) as $plik ) {
		$tresc = (string) file_get_contents( $katalog . $plik );
		$ok    = true;
		try {
			// Parsowanie, nie czytanie tekstu — GOTCHA 28.
			token_get_all( $tresc, TOKEN_PARSE );
		} catch ( \ParseError $e ) {
			$ok = false;
		}
		k6k_check( $ok, "szablon {$plik} parsuje sie bez bledu skladni" );

		$tokeny  = token_get_all( $tresc );
		$ma_abspath = false;
		foreach ( $tokeny as $token ) {
			if ( is_array( $token ) && T_CONSTANT_ENCAPSED_STRING === $token[0] && "'ABSPATH'" === $token[1] ) {
				$ma_abspath = true;
			}
		}
		k6k_check( $ma_abspath, "szablon {$plik} ma blokade bezposredniego wywolania" );
	}

	// --- Sprzatanie --------------------------------------------------------
	@unlink( $tmp . 'assets/kategorie/zywienie.jpg' );
	@unlink( $tmp . 'src/templates/card.php' );
	@rmdir( $tmp . 'assets/kategorie' );
	@rmdir( $tmp . 'assets' );
	@rmdir( $tmp . 'src/templates' );
	@rmdir( $tmp . 'src' );
	@rmdir( $tmp );
	@unlink( $motyw_dir . 'ai-news-portal/card.php' );
	@rmdir( $motyw_dir . 'ai-news-portal' );
	@rmdir( $motyw_dir );

	echo "\nWYNIK: " . ( $ran - $fail ) . " / {$ran} asercji\n";
	exit( $fail > 0 ? 1 : 0 );
}
