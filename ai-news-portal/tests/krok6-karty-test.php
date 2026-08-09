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
		'style'          => array(),
		'terminy'        => array(),
		'wpisy'          => array(),
		'is_admin'       => false,
		'lista_terminow' => array(),
		'paginacja'      => array(),
		'linki'          => '',
		'meta'           => array(),
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

	function get_post_meta( $post_id, $klucz, $pojedyncza = false ) {
		if ( '_ainp_source_url' !== $klucz ) {
			return '';
		}
		return $GLOBALS['__stan']['meta'][ $post_id ] ?? '';
	}

	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}

	function get_the_excerpt( $post_id = null ) {
		// Sroda pulapki: gdyby `Portal::lead()` siegnal tutaj, test to zobaczy.
		return 'OBCIETA TRESC ARTYKULU...';
	}

	function __( $tekst, $domena = '' ) {
		return $tekst; }

	function is_admin() {
		return (bool) $GLOBALS['__stan']['is_admin'];
	}

	function get_post_type_archive_link( $typ ) {
		return 'https://dworek.local/centrum-wiedzy/';
	}

	function home_url( $sciezka = '' ) {
		return 'https://dworek.local' . $sciezka;
	}

	function get_terms( $args = array() ) {
		return $GLOBALS['__stan']['lista_terminow'];
	}

	function wp_unslash( $wartosc ) {
		return is_string( $wartosc ) ? stripslashes( $wartosc ) : $wartosc;
	}

	function sanitize_text_field( $tekst ) {
		return trim( strip_tags( (string) $tekst ) );
	}

	function paginate_links( $args = array() ) {
		$GLOBALS['__stan']['paginacja'][] = $args;

		return $GLOBALS['__stan']['linki'];
	}

	/** Atrapa `WP_Query` — tyle, ile widzi `Portal::filter_query()`. */
	class Fake_Query {
		public $glowne;
		public $ustawione = array();
		public function __construct( $glowne = true ) {
			$this->glowne = $glowne;
		}
		public function is_main_query() {
			return $this->glowne;
		}
		public function set( $klucz, $wartosc ) {
			$this->ustawione[ $klucz ] = $wartosc;
		}
	}

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
		public const META_SOURCE  = '_ainp_source_url';
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
	echo "\n-- Wyszukiwarka: wlasny parametr (etap 6.3) --\n";

	k6k_check( 'ainp_s' === Portal::SEARCH_VAR, 'parametr wyszukiwania to ainp_s, NIE s' );

	unset( $_GET['ainp_s'] );
	k6k_check( '' === Portal::search_term(), 'bez parametru fraza jest pusta' );

	$_GET['ainp_s'] = '  karma dla szczeniaka  ';
	k6k_check( 'karma dla szczeniaka' === Portal::search_term(), 'fraza przycinana z bialych znakow' );

	$_GET['ainp_s'] = '<script>alert(1)</script>psy';
	k6k_check(
		false === strpos( Portal::search_term(), '<script' ),
		'znaczniki wycinane z frazy zanim trafi do zapytania i na naglowek'
	);

	echo "\n-- Przepisanie ainp_s na s --\n";

	$_GET['ainp_s']               = 'karma';
	$GLOBALS['__stan']['archive'] = true;
	$GLOBALS['__stan']['tax']     = false;

	$q = new Fake_Query();
	Portal::filter_query( $q );
	k6k_check( 'karma' === ( $q->ustawione['s'] ?? null ), 'na archiwum fraza trafia do zapytania jako s' );

	$GLOBALS['__stan']['tax']     = true;
	$GLOBALS['__stan']['archive'] = false;
	$q = new Fake_Query();
	Portal::filter_query( $q );
	k6k_check( 'karma' === ( $q->ustawione['s'] ?? null ), 'na archiwum kategorii szukanie tez dziala' );

	$q = new Fake_Query( false );
	Portal::filter_query( $q );
	k6k_check( array() === $q->ustawione, 'zapytanie POBOCZNE (widget, powiazane) nie jest ruszane' );

	$GLOBALS['__stan']['is_admin'] = true;
	$q = new Fake_Query();
	Portal::filter_query( $q );
	k6k_check( array() === $q->ustawione, 'w kokpicie zapytanie nie jest ruszane' );
	$GLOBALS['__stan']['is_admin'] = false;

	$GLOBALS['__stan']['tax']     = false;
	$GLOBALS['__stan']['archive'] = false;
	$q = new Fake_Query();
	Portal::filter_query( $q );
	k6k_check( array() === $q->ustawione, 'poza Centrum Wiedzy fraza NIE jest podstawiana do cudzego archiwum' );

	$GLOBALS['__stan']['archive'] = true;
	unset( $_GET['ainp_s'] );
	$q = new Fake_Query();
	Portal::filter_query( $q );
	k6k_check( ! isset( $q->ustawione['s'] ), 'bez frazy zapytanie NIE dostaje wyszukiwania' );

	$_GET['ainp_s'] = '   ';
	$q = new Fake_Query();
	Portal::filter_query( $q );
	k6k_check( ! isset( $q->ustawione['s'] ), 'sama spacja to nie jest szukanie' );

	echo "\n-- Liczba artykulow na stronie (etap 6.4) --\n";

	k6k_check( 10 === Portal::PER_PAGE, 'na stronie dziesiec artykulow — decyzja usera z etapu 6.0' );

	unset( $_GET['ainp_s'] );
	$GLOBALS['__stan']['archive'] = true;
	$q = new Fake_Query();
	Portal::filter_query( $q );
	k6k_check( 10 === ( $q->ustawione['posts_per_page'] ?? null ), 'archiwum dostaje wlasna liczbe na strone' );

	$GLOBALS['__stan']['tax']     = true;
	$GLOBALS['__stan']['archive'] = false;
	$q = new Fake_Query();
	Portal::filter_query( $q );
	k6k_check( 10 === ( $q->ustawione['posts_per_page'] ?? null ), 'archiwum kategorii tez, inaczej rozjechalaby sie paginacja' );

	$GLOBALS['__stan']['tax'] = false;
	$q = new Fake_Query();
	Portal::filter_query( $q );
	k6k_check(
		! isset( $q->ustawione['posts_per_page'] ),
		'poza Centrum Wiedzy liczba wpisow na stronie NIE jest zmieniana cudzemu archiwum'
	);

	echo "\n-- Paginacja --\n";

	$GLOBALS['__stan']['archive']   = true;
	$GLOBALS['__stan']['paginacja'] = array();
	$GLOBALS['__stan']['linki']     = '';
	k6k_check( '' === Portal::pagination(), 'jedna strona wynikow → zero znacznikow paginacji' );

	$GLOBALS['__stan']['linki'] = '   ';
	k6k_check( '' === Portal::pagination(), 'same biale znaki z WordPressa tez znacza brak paginacji' );

	$GLOBALS['__stan']['linki'] = '<a class="page-numbers" href="/centrum-wiedzy/page/2/">2</a>';
	k6k_check( '' !== Portal::pagination(), 'przy wielu stronach paginacja jest rysowana' );

	$GLOBALS['__stan']['paginacja'] = array();
	unset( $_GET['ainp_s'] );
	Portal::pagination();
	k6k_check(
		! isset( $GLOBALS['__stan']['paginacja'][0]['add_args'] ),
		'bez szukania paginacja nie doklada pustego parametru do adresu'
	);

	$GLOBALS['__stan']['paginacja'] = array();
	$_GET['ainp_s']                 = 'karma dla szczeniaka';
	Portal::pagination();
	$args = $GLOBALS['__stan']['paginacja'][0];
	k6k_check(
		isset( $args['add_args']['ainp_s'] ),
		'przy szukaniu fraza jedzie z paginacja — inaczej strona 2 wynikow to strona 2 CALEGO archiwum'
	);
	k6k_check(
		'karma%20dla%20szczeniaka' === $args['add_args']['ainp_s'],
		'spacje we frazie sa zakodowane w adresie kolejnej strony'
	);
	unset( $_GET['ainp_s'] );

	echo "\n-- Naglowek przy szukaniu --\n";

	$_GET['ainp_s'] = 'karma dla szczeniaka';
	k6k_check(
		'Wyniki wyszukiwania: „karma dla szczeniaka”' === Portal::archive_title(),
		'naglowek pokazuje szukana fraze'
	);

	$GLOBALS['__stan']['tax']    = true;
	$GLOBALS['__stan']['obiekt'] = $zywienie;
	k6k_check(
		'Wyniki wyszukiwania: „karma dla szczeniaka”' === Portal::archive_title(),
		'szukanie z poziomu kategorii tez daje naglowek wynikow, nie nazwe kategorii'
	);
	unset( $_GET['ainp_s'] );

	echo "\n-- Przyciski kategorii --\n";

	$GLOBALS['__stan']['lista_terminow'] = array(
		$zywienie,
		(object) array( 'name' => 'Zdrowie', 'slug' => 'zdrowie' ),
	);
	k6k_check( 2 === count( Portal::categories() ), 'lista kategorii oddaje terminy z artykulami' );

	$GLOBALS['__stan']['lista_terminow'] = new \WP_Error( 'brak', 'nie ma taksonomii' );
	k6k_check( array() === Portal::categories(), 'WP_Error z get_terms() zamienia sie w pusta liste, nie w blad' );

	$GLOBALS['__stan']['lista_terminow'] = array( $zywienie, 'smiec', null );
	k6k_check( 1 === count( Portal::categories() ), 'wpisy, ktore nie sa obiektami, sa odsiewane' );

	$GLOBALS['__stan']['tax']    = true;
	$GLOBALS['__stan']['obiekt'] = $zywienie;
	k6k_check( 'zywienie' === Portal::current_term_slug(), 'na archiwum kategorii znany jest jej slug' );

	$GLOBALS['__stan']['tax']     = false;
	$GLOBALS['__stan']['archive'] = true;
	k6k_check( '' === Portal::current_term_slug(), 'na archiwum CPT zadna kategoria nie jest zaznaczona' );

	k6k_check(
		'https://dworek.local/centrum-wiedzy/' === Portal::archive_link(),
		'formularz i przycisk Wszystkie celuja w archiwum Centrum Wiedzy'
	);

	// ---------------------------------------------------------------------
	echo "\n-- Zrodlo artykulu (etap 6.5) --\n";

	$GLOBALS['__stan']['meta'] = array(
		7  => 'https://www.psy.pl/karma-dla-szczeniaka/',
		8  => '',
		9  => '   ',
		10 => 'javascript:alert(1)',
		11 => 'ftp://plik.example/psy.txt',
		12 => 'psy.pl/bez-schematu/',
		13 => 'http://ccw24.pl/artykul/',
	);

	k6k_check(
		'https://www.psy.pl/karma-dla-szczeniaka/' === Portal::source_url( 7 ),
		'adres zrodla bierze sie z meta wpisu'
	);
	k6k_check( 'http://ccw24.pl/artykul/' === Portal::source_url( 13 ), 'zwykly http tez przechodzi' );
	k6k_check( '' === Portal::source_url( 8 ), 'brak adresu → pusty ciag, ramka zrodla sie nie rysuje' );
	k6k_check( '' === Portal::source_url( 9 ), 'same biale znaki → pusty ciag' );
	k6k_check( '' === Portal::source_url( 10 ), 'schemat javascript: ODRZUCONY' );
	k6k_check( '' === Portal::source_url( 11 ), 'schemat ftp: odrzucony' );
	k6k_check( '' === Portal::source_url( 12 ), 'adres bez schematu odrzucony' );

	k6k_check(
		'psy.pl' === Portal::source_host( 'https://www.psy.pl/karma-dla-szczeniaka/' ),
		'w ramce stoi nazwa serwisu bez www, pelny adres zostaje w href'
	);
	k6k_check( 'ccw24.pl' === Portal::source_host( 'http://ccw24.pl/artykul/' ), 'host bez www zostaje jak jest' );
	k6k_check( '' === Portal::source_host( 'to nie jest adres' ), 'smiec zamiast adresu → pusta nazwa, bez bledu' );

	// ---------------------------------------------------------------------
	echo "\n-- Pliki szablonow --\n";

	$szablony = array( 'archive.php', 'single.php', 'card.php', 'portal.css' );
	$katalog  = dirname( __DIR__ ) . '/src/templates/';

	foreach ( $szablony as $plik ) {
		k6k_check( file_exists( $katalog . $plik ), "szablon {$plik} istnieje" );
	}

	foreach ( array( 'archive.php', 'single.php', 'card.php' ) as $plik ) {
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

	// ---------------------------------------------------------------------
	echo "\n-- Arkusz stylow: granice umowy z motywem (etap 6.6) --\n";

	$css = (string) file_get_contents( $katalog . 'portal.css' );

	// Komentarze precz — inaczej slowo z opisu liczy sie jak regula.
	$reguly = (string) preg_replace( '#/\*.*?\*/#s', '', $css );

	k6k_check(
		1 === preg_match_all( '/@media/', $reguly ),
		'DOKLADNIE jeden breakpoint — wymog planu (jest: ' . preg_match_all( '/@media/', $reguly ) . ')'
	);
	k6k_check(
		0 === preg_match_all( '/!\s*important/i', $reguly ),
		'zero !important — styl wtyczki nie licytuje sie z motywem'
	);
	k6k_check(
		0 === preg_match_all( '/(^|[;{\s])font-family\s*:/i', $reguly ),
		'arkusz NIE ustawia kroju pisma (decyzja D z etapu 6.0)'
	);
	k6k_check(
		0 === preg_match_all( '/(^|[;{\s])(body|html)\s*[,{]/i', $reguly ),
		'arkusz nie siega do body ani html — to teren motywu'
	);
	k6k_check(
		false === strpos( $reguly, 'prefers-color-scheme' ),
		'zero zgadywania trybu systemu — motyw bywa ciemny niezaleznie od ustawien systemu'
	);
	k6k_check(
		substr_count( $reguly, ':focus-visible' ) >= 1,
		'focus klawiatury ma wlasny, widoczny stan'
	);

	// Kazdy selektor wlasny musi zaczynac sie od naszego prefiksu — inaczej
	// arkusz maluje elementy, ktorych nie postawil.
	$obce = array();
	foreach ( explode( '}', $reguly ) as $blok ) {
		$selektor = trim( explode( '{', $blok )[0] );
		if ( '' === $selektor || 0 === strpos( $selektor, '@' ) ) {
			continue;
		}
		foreach ( explode( ',', $selektor ) as $czesc ) {
			$czesc = trim( $czesc );
			if ( '' !== $czesc && 0 !== strpos( $czesc, '.ainp-' ) ) {
				$obce[] = $czesc;
			}
		}
	}
	k6k_check(
		array() === $obce,
		'kazdy selektor zaczyna sie od .ainp- (obce: ' . implode( ' | ', $obce ) . ')'
	);

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
