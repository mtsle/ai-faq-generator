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
	 * Zderza kopie dokumentu w repozytorium z kanonem w `projektAUDYT/dokumentacja/`.
	 *
	 * PO CO: straznicy dokumentacyjni czytali dotad WYLACZNIE kanon, ktory lezy POZA
	 * repozytorium. Lokalnie bylo zielono, a pierwsze zderzenie z GitHub Actions
	 * przewrocilo cztery zestawy naraz — CI tego katalogu nie widzi. Dokument jest
	 * teraz kopiowany do repo i to kopie czytaja asercje, wiec straznik chodzi
	 * takze w CI. Ta funkcja pilnuje, zeby kopia nie rozjechala sie z kanonem.
	 *
	 * Asercja wykonuje sie w OBU galeziach, takze gdy kanonu nie ma. Liczba asercji
	 * musi byc identyczna w kazdym srodowisku, bo runner wtyczki 2 zalicza zestaw
	 * dopiero przy DOKLADNEJ rownosci liczby wykonanych asercji.
	 *
	 * @param string $w_repo Sciezka kopii w repozytorium.
	 * @param string $nazwa  Nazwa pliku dokumentu.
	 *
	 * @return void
	 */
	function ainp_doc_zgodna( $w_repo, $nazwa ) {
		$kanon = dirname( dirname( dirname( dirname( __DIR__ ) ) ) ) . '/projektAUDYT/dokumentacja/' . $nazwa;
		if ( ! is_file( $kanon ) ) {
			k6k_check( is_file( $w_repo ), 'kopia ' . $nazwa . ' jest w repo (kanon spoza repo niedostepny w tym srodowisku)' );
			return;
		}
		// Porownanie idzie po tresci ZNORMALIZOWANEJ na konce wierszy, nie po bajtach.
		// Git zamienia CRLF na LF przy pobraniu, wiec swiezy klon mialby inne bajty niz
		// kanon na maszynie autora i kontrola zapalilaby sie na roznicy, ktora nie jest
		// rozjazdem tresci.
		$norm = static function ( $sciezka ) {
			return str_replace( "
", "
", (string) file_get_contents( $sciezka ) );
		};
		k6k_check(
			is_file( $w_repo ) && $norm( $kanon ) === $norm( $w_repo ),
			'kopia ' . $nazwa . ' w repo zgodna z kanonem w projektAUDYT (konce wierszy pominiete)'
		);
	}

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
	// Warianty 8.8. Trzy uklady naraz, bo w paczce klienta wystapia wszystkie:
	//   `zywienie` — komplet 1-2-3
	//   `zdrowie`  — DZIURA: jest 1 i 3, nie ma 2 (klient skasowal jeden plik)
	//   `rasy`     — sam wariant 2, bez pliku podstawowego
	file_put_contents( $tmp . 'assets/kategorie/zywienie-2.jpg', 'udaje-jpeg' );
	file_put_contents( $tmp . 'assets/kategorie/zywienie-3.jpg', 'udaje-jpeg' );
	file_put_contents( $tmp . 'assets/kategorie/zdrowie.jpg', 'udaje-jpeg' );
	file_put_contents( $tmp . 'assets/kategorie/zdrowie-3.jpg', 'udaje-jpeg' );
	file_put_contents( $tmp . 'assets/kategorie/rasy-2.jpg', 'udaje-jpeg' );
	// Plik PONAD sufitem `IMAGE_VARIANTS`. Lezy tu po to, zeby sufit dalo sie
	// asercjonowac: bez niego „wariant 4 daje pusty ciag" przechodzi rowniez
	// wtedy, gdy sufitu w kodzie nie ma wcale, bo pliku i tak brakuje na dysku.
	// Dwie mutacje (zdjete sito, podniesiony sufit) przezyly dokladnie na tym.
	file_put_contents( $tmp . 'assets/kategorie/zywienie-4.jpg', 'udaje-jpeg' );
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
		'embed'          => false,
		'load_template'  => array(),
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
		$GLOBALS['__stan']['ostatnie_args'] = $args;
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

	function is_embed() {
		return (bool) ( $GLOBALS['__stan']['embed'] ?? false );
	}

	/**
	 * Atrapa `load_template()` — odwzorowuje kontrakt rdzenia.
	 *
	 * Prawdziwa funkcja udostepnia szablonowi `$post` i zmienne zapytania.
	 * Atrapa robi DOKLADNIE to samo, bo od tego zalezy naprawa D2: gdyby
	 * `Portal::part()` wrocilo do golego `require`, szablon motywu przestalby
	 * widziec `$post`, a ta atrapa to zobaczy.
	 */
	function load_template( $sciezka, $raz = true, $args = array() ) {
		global $post, $wp_query;
		$GLOBALS['__stan']['load_template'][] = array( $sciezka, $raz );
		if ( $raz ) {
			require_once $sciezka;
		} else {
			require $sciezka;
		}
	}

	/** Atrapa `WP_Query` — tyle, ile widzi `Portal::filter_query()`. */
	class Fake_Query {
		public $glowne;
		public $feed      = false;
		public $ustawione = array();
		public function __construct( $glowne = true, $feed = false ) {
			$this->glowne = $glowne;
			$this->feed   = $feed;
		}
		public function is_main_query() {
			return $this->glowne;
		}
		public function is_feed() {
			return $this->feed;
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
	/**
	 * Przechwycenie `file_exists()` W PRZESTRZENI `AINP` — licznik odczytow dysku.
	 *
	 * `Portal.php` siedzi w tej samej przestrzeni i wola `file_exists()` BEZ
	 * ukosnika, wiec PHP szuka najpierw tej funkcji, a dopiero potem globalnej.
	 * Sama praca jest oddawana globalnej — mierzymy, nie udajemy.
	 *
	 * @param string $sciezka Sprawdzana sciezka.
	 *
	 * @return bool
	 */
	function file_exists( $sciezka ) {
		$GLOBALS['__odczyty_dysku'] = ( $GLOBALS['__odczyty_dysku'] ?? 0 ) + 1;

		return \file_exists( $sciezka );
	}

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
	echo "\n-- Praca frontu: sprawdzenia dysku na karte (RAU-R13-004) --\n";

	/*
	 * Do przebiegu 2 KAZDA karta listy pytala dysk CZTERY razy: trzy razy
	 * z petli rotacji wariantow w `category_variant()` plus raz z samego
	 * szablonu. Wynik nie byl pamietany ani miedzy kartami, ani miedzy
	 * zadaniami, wiec liczba wywolan `file_exists()` rosla LINIOWO z liczba
	 * kart — praca wejscia-wyjscia w petli renderowania frontu, sprzeczna
	 * z TECH-71 („front nie wykonuje zadnej pracy poza zapytaniem do bazy",
	 * stan REALIZOWANE).
	 *
	 * MIERZYMY LICZBE ODCZYTOW DYSKU, NIE CZAS. Czas na Windows jest zbyt
	 * ziarnisty, zeby cokolwiek dowiesc, a liczba wywolan jest dokladnie tym,
	 * co zglosil audyt. Licznik dziala, bo `Portal.php` siedzi w przestrzeni
	 * `AINP` i wola `file_exists()` BEZ ukosnika: PHP szuka wtedy najpierw
	 * `AINP\file_exists`, a dopiero potem globalnej.
	 *
	 * Slug jest NOWY i nieuzywany wyzej — pamiec zadania jest statyczna, wiec
	 * dla kategorii dotknietej wczesniejszymi asercjami licznik zaczynalby
	 * od zera bez zaslugi naprawy.
	 */
	$k6k_slug_pomiar = 'pomiar-kart';
	file_put_contents( $tmp . 'assets/kategorie/' . $k6k_slug_pomiar . '.jpg', 'udaje-jpeg' );
	file_put_contents( $tmp . 'assets/kategorie/' . $k6k_slug_pomiar . '-2.jpg', 'udaje-jpeg' );
	file_put_contents( $tmp . 'assets/kategorie/' . $k6k_slug_pomiar . '-3.jpg', 'udaje-jpeg' );

	$GLOBALS['__odczyty_dysku'] = 0;

	// Kontrola samego licznika: bez niej „zero odczytow" znaczyloby tylko tyle,
	// ze przechwycenie nie dziala, i cala sekcja bylaby pusta.
	$k6k_przed = $GLOBALS['__odczyty_dysku'];
	Portal::category_image_url( 'nieistniejaca-kategoria-kontrolna', 1 );
	k6k_check(
		$GLOBALS['__odczyty_dysku'] > $k6k_przed,
		'licznik odczytow dysku dziala (przechwycenie AINP\\file_exists jest aktywne)'
	);

	// Jedna karta: rotacja wariantow plus zapytanie szablonu.
	$GLOBALS['__odczyty_dysku'] = 0;
	$k6k_wariant = Portal::category_variant( $k6k_slug_pomiar );
	Portal::category_image_url( $k6k_slug_pomiar, $k6k_wariant );
	$k6k_pierwsza = $GLOBALS['__odczyty_dysku'];

	k6k_check(
		$k6k_pierwsza <= Portal::IMAGE_VARIANTS,
		'pierwsza karta pyta dysk najwyzej raz na wariant (jest: ' . $k6k_pierwsza . ', wariantow: ' . Portal::IMAGE_VARIANTS . ')'
	);

	// Dziewiec kolejnych kart TEJ SAMEJ kategorii — tak wyglada strona archiwum.
	$GLOBALS['__odczyty_dysku'] = 0;
	for ( $k6k_i = 0; $k6k_i < 9; $k6k_i++ ) {
		$k6k_w = Portal::category_variant( $k6k_slug_pomiar );
		Portal::category_image_url( $k6k_slug_pomiar, $k6k_w );
	}
	$k6k_kolejne = $GLOBALS['__odczyty_dysku'];

	k6k_check(
		0 === $k6k_kolejne,
		'DZIEWIEC kolejnych kart tej samej kategorii NIE pyta dysku ani razu (jest: ' . $k6k_kolejne . ')'
	);

	// SEDNO: koszt ma NIE rosnac liniowo z liczba kart. Bez naprawy bylo
	// 4 odczyty na karte, czyli 36 na te dziewiec.
	k6k_check(
		$k6k_kolejne < 9,
		'koszt dyskowy NIE rosnie liniowo z liczba kart (przed naprawa: 4 na karte)'
	);

	/*
	 * OBRONA W GLAB — i jej cena przy dowodzie mutacyjnym.
	 *
	 * Pamiec jest w DWOCH miejscach: w `category_image_url()` (na pare
	 * slug+wariant) i w `category_variant()` (na liste dostepnych wariantow).
	 * Wystarczy PIERWSZA, zeby odczyty dysku spadly do zera — sprawdzone
	 * mutacja: wyciecie samej drugiej nie zmienia zachowania, bo petla trafia
	 * w pamiec pierwszej. Druga oszczedza juz tylko wywolania metody.
	 *
	 * Asercja behawioralna wyzej nie moze wiec pilnowac drugiej pamieci.
	 * Pilnuje jej ta, STRUKTURALNA — inaczej cofniecie tamtej warstwy przeszloby
	 * przez komplet testow niezauwazone.
	 */
	$k6k_portal = (string) file_get_contents( dirname( __DIR__ ) . '/src/Portal.php' );
	k6k_check(
		1 === preg_match( '/static\s+\$pamiec\s*=\s*array\(\s*\);/', $k6k_portal ),
		'pamiec pary slug+wariant istnieje w category_image_url() (warstwa nosna)'
	);
	k6k_check(
		1 === preg_match( '/static\s+\$warianty\s*=\s*array\(\s*\);/', $k6k_portal )
			&& 1 === preg_match( '/array_key_exists\(\s*\$slug,\s*\$warianty\s*\)/', $k6k_portal ),
		'pamiec listy wariantow istnieje w category_variant() (warstwa druga, nie do zmierzenia behawioralnie)'
	);

	// Rotacja wariantow MUSI dzialac dalej — pamiec nie moze jej zamrozic
	// na jednym zdjeciu, bo po to powstala (etap 8.8).
	$k6k_widziane = array();
	for ( $k6k_i = 0; $k6k_i < Portal::IMAGE_VARIANTS; $k6k_i++ ) {
		$k6k_widziane[] = Portal::category_variant( $k6k_slug_pomiar );
	}
	k6k_check(
		count( array_unique( $k6k_widziane ) ) === Portal::IMAGE_VARIANTS,
		'rotacja nadal daje KAZDY wariant po kolei, pamiec jej nie zamrozila (widziane: ' . implode( ',', $k6k_widziane ) . ')'
	);

	// Nowa kategoria musi zostac policzona od nowa — pamiec jest per SLUG,
	// nie globalna, inaczej druga kategoria dostawalaby cudzy wynik.
	file_put_contents( $tmp . 'assets/kategorie/pomiar-druga.jpg', 'udaje-jpeg' );
	$GLOBALS['__odczyty_dysku'] = 0;
	Portal::category_variant( 'pomiar-druga' );
	k6k_check(
		$GLOBALS['__odczyty_dysku'] > 0,
		'INNA kategoria jest liczona od nowa — pamiec jest per slug, nie globalna (jest: ' . $GLOBALS['__odczyty_dysku'] . ')'
	);

	// Szablon nie moze juz twierdzic, ze niczego nie liczy.
	$k6k_card = (string) file_get_contents( dirname( __DIR__ ) . '/src/templates/card.php' );
	// Docblock łamie się na wiersze z prefiksem ` * `, więc badane zdanie NIGDY
	// nie stoi w pliku jednym ciągiem. Pytanie o surowy tekst przechodziłoby
	// zawsze — czyli z niewłaściwego powodu. Normalizujemy białe znaki i gwiazdki.
	$k6k_card_plaski = trim( preg_replace( '/[\s*]+/', ' ', $k6k_card ) );
	k6k_check(
		false === strpos( $k6k_card_plaski, 'Nic nie zapytuje i nic nie liczy' ),
		'docblock card.php nie twierdzi juz, ze nic nie zapytuje i nic nie liczy'
	);
	k6k_check(
		false !== strpos( $k6k_card_plaski, 'Portal::category_image_url' )
			&& false !== strpos( $k6k_card_plaski, 'pamietane w obrebie zadania' ),
		'docblock card.php nazywa to, co szablon naprawde robi, i wskazuje pamiec zadania'
	);

	// TECH-71 ma opisywac stan faktyczny, nie zyczenie.
	$k6k_tech = dirname( dirname( __DIR__ ) ) . '/audyt/dokumentacja/DOKUMENTACJA-TECHNICZNA.txt';
	ainp_doc_zgodna( $k6k_tech, 'DOKUMENTACJA-TECHNICZNA.txt' );
	$k6k_doc  = is_readable( $k6k_tech ) ? (string) file_get_contents( $k6k_tech ) : '';
	if ( '' !== $k6k_doc ) {
		// Ta sama pułapka co wyżej: w dokumencie zdanie jest złamane na dwa
		// wiersze z wcięciem kolumnowym, więc pytanie o surowy tekst nigdy nie
		// trafiało i asercja świeciła na zielono niezależnie od treści reguły.
		$k6k_doc_plaski = preg_replace( '/\s+/', ' ', $k6k_doc );
		k6k_check(
			false === strpos( $k6k_doc_plaski, 'front nie wykonuje żadnej pracy poza zapytaniem do bazy' ),
			'TECH-71: znikla teza, ze front nie wykonuje ZADNEJ pracy poza zapytaniem do bazy'
		);
		// Pytanie musi trafić w LINIĘ KOTWICY, nie w prozę reguły: `category_image_url`
		// pada też w opisie realizacji, więc szukanie po całym dokumencie było
		// spełnione niezależnie od tego, co stoi w polu „Kotwica".
		$k6k_kotwica = '';
		if ( preg_match( '/ID: TECH-71.*?Kotwica\s*:(.*?)Stan\s*:/s', $k6k_doc, $k6k_m ) ) {
			$k6k_kotwica = preg_replace( '/\s+/', ' ', $k6k_m[1] );
		}
		k6k_check( '' !== $k6k_kotwica, 'TECH-71: pole Kotwica daje sie wyciac z dokumentu' );
		k6k_check(
			false !== strpos( $k6k_kotwica, 'category_image_url' )
				&& false !== strpos( $k6k_kotwica, 'card.php' ),
			'TECH-71: kotwica wskazuje miejsce, w ktorym praca frontu naprawde zachodzi (jest: ' . trim( $k6k_kotwica ) . ')'
		);
	} else {
		k6k_check( false, 'TECH-71: dokumentacja techniczna nieczytelna z testu (' . $k6k_tech . ')' );
	}

	@unlink( $tmp . 'assets/kategorie/' . $k6k_slug_pomiar . '.jpg' );
	@unlink( $tmp . 'assets/kategorie/' . $k6k_slug_pomiar . '-2.jpg' );
	@unlink( $tmp . 'assets/kategorie/' . $k6k_slug_pomiar . '-3.jpg' );
	@unlink( $tmp . 'assets/kategorie/pomiar-druga.jpg' );

	// ---------------------------------------------------------------------
	echo "\n-- Warianty zdjecia kategorii (8.8) --\n";

	$k6k_baza = 'https://dworek.local/wp-content/plugins/ai-news-portal/assets/kategorie/';

	k6k_check( $k6k_baza . 'zywienie.jpg' === Portal::category_image_url( 'zywienie', 1 ), 'wariant 1 to nadal samo <slug>.jpg — nazwa sprzed 8.8 nietknieta' );
	k6k_check( $k6k_baza . 'zywienie-2.jpg' === Portal::category_image_url( 'zywienie', 2 ), 'wariant 2 to <slug>-2.jpg' );
	k6k_check( $k6k_baza . 'zywienie-3.jpg' === Portal::category_image_url( 'zywienie', 3 ), 'wariant 3 to <slug>-3.jpg' );
	k6k_check( Portal::category_image_url( 'zywienie' ) === Portal::category_image_url( 'zywienie', 1 ), 'wywolanie bez wariantu znaczy to samo, co wariant 1 (zgodnosc wstecz)' );

	k6k_check( '' === Portal::category_image_url( 'zywienie', 4 ), 'plik ponad sufitem IGNOROWANY, choc LEZY na dysku — sam plik nie wystarczy, trzeba podniesc IMAGE_VARIANTS' );
	k6k_check( '' === Portal::category_image_url( 'zywienie', 0 ), 'wariant 0 odrzucony' );
	k6k_check( '' === Portal::category_image_url( 'zywienie', -2 ), 'wariant ujemny odrzucony — inaczej wszedlby w nazwe pliku' );
	k6k_check( '' === Portal::category_image_url( 'zdrowie', 2 ), 'brakujacy wariant to pusty ciag, nie 404 na froncie' );
	k6k_check( '' === Portal::category_image_url( '../../../wp-config', 2 ), 'sito na slug obowiazuje takze przy wariancie' );

	echo "\n-- Rotacja: sasiednie karty tej samej kategorii --\n";

	// ASERCJA NA WYNIKU, NIE NA STALEJ: liczy sie to, ze trzy kolejne karty
	// dostaja trzy ROZNE adresy. Sprawdzanie samego `IMAGE_VARIANTS === 3`
	// przezylaby kazda mutacja, ktora rotacje wylacza.
	$k6k_ciag = array();
	for ( $k6k_i = 0; $k6k_i < 4; $k6k_i++ ) {
		$k6k_ciag[] = Portal::category_variant( 'zywienie' );
	}

	k6k_check( array( 1, 2, 3, 1 ) === $k6k_ciag, 'trzy karty pod rzad biora warianty 1-2-3, czwarta zaczyna od nowa' );

	$k6k_adresy = array();
	foreach ( array_slice( $k6k_ciag, 0, 3 ) as $k6k_w ) {
		$k6k_adresy[] = Portal::category_image_url( 'zywienie', $k6k_w );
	}

	k6k_check( 3 === count( array_unique( $k6k_adresy ) ), 'trzy sasiednie karty jednej kategorii pokazuja TRZY ROZNE zdjecia' );
	k6k_check( ! in_array( '', $k6k_adresy, true ), 'i zadna z nich nie spada na kafelek zastepczy' );

	echo "\n-- Rotacja: przypadki brzegowe --\n";

	k6k_check( array( 1, 3, 1 ) === array( Portal::category_variant( 'zdrowie' ), Portal::category_variant( 'zdrowie' ), Portal::category_variant( 'zdrowie' ) ), 'dziura w numeracji jest POMIJANA — licznik nie wskaze pliku, ktorego nie ma' );
	k6k_check( 2 === Portal::category_variant( 'rasy' ), 'kategoria bez pliku podstawowego zaczyna od wariantu, ktory istnieje' );
	k6k_check( 1 === Portal::category_variant( 'podroze-z-psem' ), 'kategoria bez ANI JEDNEGO zdjecia oddaje 1, a karta i tak dostanie pusty adres' );
	k6k_check( '' === Portal::category_image_url( 'podroze-z-psem', Portal::category_variant( 'podroze-z-psem' ) ), 'czyli kafelek z inicjalem, tak jak przed 8.8' );

	// Liczniki sa ROZLACZNE per kategoria: gdyby byly wspolne, karta „Zdrowia"
	// przesuwalaby zdjecia „Zywienia" i rotacja zalezalaby od kolejnosci
	// kategorii na stronie.
	//
	// Do tej proby biora sie kategorie z ROZNA liczba wariantow (3 i 2), a
	// asercja pyta o NASTEPNY element cyklu, nie o rownosc. Wspolny licznik
	// przesunalby sie tu o 3 (jedno wlasne wywolanie plus dwa cudze), czyli
	// o pelen cykl „Zywienia" — i oddalby ten sam wariant, co poprzednio.
	$k6k_a = Portal::category_variant( 'zywienie' );
	Portal::category_variant( 'zdrowie' );
	Portal::category_variant( 'zdrowie' );
	$k6k_b = Portal::category_variant( 'zywienie' );

	k6k_check( ( $k6k_a % 3 ) + 1 === $k6k_b, 'dwie karty innej kategorii pomiedzy NIE przesuwaja rotacji tej pierwszej' );

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

	/*
	 * LUKA TESTOWA L3 z audytu — kontrakt zmiennych szablonu.
	 *
	 * Karta wstawiana golym `require` wykonywala sie w zasiegu metody
	 * statycznej i NIE widziala `$post`. Nasz `card.php` tego nie zauwazyl,
	 * bo chodzi na `get_the_ID()`, ale motyw piszacy karte po WordPressowemu
	 * dostawal ostrzezenie o nieistniejacej zmiennej i pusta karte. Ta
	 * asercja pilnuje, ze `part()` idzie przez `load_template()`.
	 */
	echo "\n-- Kontrakt zmiennych szablonu (naprawa D2) --\n";

	$GLOBALS['post']                     = (object) array( 'ID' => 7, 'post_title' => 'Karma dla szczeniaka' );
	$GLOBALS['wp_query']                 = (object) array( 'query_vars' => array() );
	$GLOBALS['__stan']['load_template']  = array();

	file_put_contents(
		$motyw_dir . 'ai-news-portal/motywowa.php',
		'<?php $GLOBALS["__widziany_post"] = isset( $post ) ? $post->post_title : null;'
	);
	$GLOBALS['__widziany_post'] = 'NIE USTAWIONO';
	$GLOBALS['__stan']['motyw'] = array( 'ai-news-portal/motywowa.php' );

	Portal::part( 'motywowa.php' );

	k6k_check(
		'Karma dla szczeniaka' === $GLOBALS['__widziany_post'],
		'szablon motywu widzi $post — czyli part() idzie przez load_template(), nie przez gole require'
	);
	k6k_check( 1 === count( $GLOBALS['__stan']['load_template'] ), 'load_template() wolane dokladnie raz' );
	k6k_check(
		false === $GLOBALS['__stan']['load_template'][0][1],
		'drugi argument to FALSE — inaczej w petli narysowalaby sie tylko pierwsza karta'
	);

	// Dowod na „tylko pierwsza karta": trzy wstawienia = trzy rysowania.
	$GLOBALS['__narysowano']    = array();
	$GLOBALS['__stan']['motyw'] = array();
	Portal::part( 'card.php' );
	Portal::part( 'card.php' );
	Portal::part( 'card.php' );
	k6k_check(
		array( 'wtyczka', 'wtyczka', 'wtyczka' ) === $GLOBALS['__narysowano'],
		'karta wstawiona trzy razy rysuje sie trzy razy, nie raz'
	);

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

	echo "\n-- Krok 7, etap 7.5: sufit dlugosci frazy --\n";

	/*
	 * Fraza z adresu jest mnoznikiem kosztu zapytania: WordPress rozbija ja
	 * na slowa i robi z kazdego osobny warunek LIKE. Bez sufitu kilka
	 * kilobajtow w pasku przegladarki to kilkaset warunkow na tabeli wpisow,
	 * bez logowania i bez limitu.
	 */
	$_GET['ainp_s'] = str_repeat( 'a', 5000 );
	$dluga          = Portal::search_term();
	k6k_check( Portal::SEARCH_MAX === strlen( $dluga ), 'fraza dluzsza niz sufit jest przycinana do ' . Portal::SEARCH_MAX . ' znakow (jest: ' . strlen( $dluga ) . ')' );

	$_GET['ainp_s'] = str_repeat( 'ą', 5000 );
	$ogonki         = Portal::search_term();
	k6k_check( Portal::SEARCH_MAX === mb_strlen( $ogonki ), 'ciecie liczy ZNAKI, nie bajty' );
	k6k_check( $ogonki === mb_convert_encoding( $ogonki, 'UTF-8', 'UTF-8' ), 'przyciety napis zostaje poprawnym UTF-8 — substr rozcialby litere w polowie' );

	$_GET['ainp_s'] = 'karma dla szczeniaka';
	k6k_check( 'karma dla szczeniaka' === Portal::search_term(), 'normalna fraza przechodzi bez zmian' );

	// Sufit nie moze wyciac frazy z paginacji — inaczej strona 2 wynikow
	// byłaby strona 2 calego archiwum (dlug z etapu 6.4).
	$_GET['ainp_s']               = str_repeat( 'b', 5000 );
	$GLOBALS['__stan']['archive'] = true;
	$html                         = Portal::pagination();
	k6k_check( '' === $html || false !== strpos( $html, 'ainp_s' ), 'przycieta fraza nadal jedzie z paginacja' );

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

	echo "\n-- Feed archiwum NIE dostaje naszej liczby na strone (naprawa D1) --\n";

	/*
	 * `/centrum-wiedzy/feed/` ma `is_post_type_archive()` rowne PRAWDA, wiec
	 * przechodzi przez te sama bramke co strona. Kanal RSS nie ma ukladu
	 * w dwie kolumny, wiec nasza liczba nie ma tam czego pilnowac — rzadzi
	 * ustawienie witryny (`posts_per_rss`).
	 */
	$GLOBALS['__stan']['archive'] = true;
	$GLOBALS['__stan']['tax']     = false;
	unset( $_GET['ainp_s'] );

	$q = new Fake_Query( true, true );
	Portal::filter_query( $q );
	k6k_check(
		! isset( $q->ustawione['posts_per_page'] ),
		'feed archiwum zostaje przy ustawieniu witryny, nie przy naszej liczbie'
	);

	$_GET['ainp_s'] = 'karma';
	$q = new Fake_Query( true, true );
	Portal::filter_query( $q );
	k6k_check( 'karma' === ( $q->ustawione['s'] ?? null ), 'ale szukanie w feedzie dziala dalej' );
	k6k_check(
		! isset( $q->ustawione['posts_per_page'] ),
		'szukanie w feedzie nadal nie narzuca liczby na strone'
	);
	unset( $_GET['ainp_s'] );

	$q = new Fake_Query( true, false );
	Portal::filter_query( $q );
	k6k_check( 10 === ( $q->ustawione['posts_per_page'] ?? null ), 'zwykle archiwum (nie feed) dostaje liczbe dalej' );

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

	/*
	 * LUKA TESTOWA L2 z audytu — asercja mierzyla martwa wartosc.
	 *
	 * Poprzednia wersja sprawdzala doslowne `karma%20dla%20szczeniaka`
	 * i przechodzila, ale niczego nie broniła: `paginate_links()` scala
	 * parametry z BIEZACEGO adresu i one nadpisuja nasze `add_args`
	 * (rdzen, `general-template.php`, „Merge additional query vars found
	 * in the original URL"). Zmierzone przy audycie: to samo wywolanie
	 * z PUSTYM `add_args` daje identyczny adres strony 2.
	 *
	 * Nasza wartosc dochodzi do skutku wylacznie wtedy, gdy adres bazowy
	 * nie ma juz tego parametru. Wtedy jednak MUSI byc zakodowana, bo
	 * `add_query_arg()` w tym WordPressie wartosci NIE koduje (zmierzone:
	 * `add_query_arg( array( 'q' => 'a b' ), 'http://e/' )` → `q=a b`).
	 * Dlatego asercja pyta teraz o NIEZMIENNIK — „fraza nie rozbije
	 * adresu" — a nie o konkretny napis.
	 */
	$rozbijaki = array( ' ', '&', '?', '#', '=' );
	$obecne    = array();
	foreach ( $rozbijaki as $znak ) {
		if ( false !== strpos( (string) $args['add_args']['ainp_s'], $znak ) ) {
			$obecne[] = $znak;
		}
	}
	k6k_check(
		array() === $obecne,
		'fraza w add_args jest zakodowana — zaden znak rozbijajacy adres (obecne: ' . implode( '', $obecne ) . ')'
	);

	$GLOBALS['__stan']['paginacja'] = array();
	$_GET['ainp_s']                 = 'karma & woda?psy=tak';
	Portal::pagination();
	$args2 = $GLOBALS['__stan']['paginacja'][0];
	k6k_check(
		$args2['add_args']['ainp_s'] === rawurlencode( 'karma & woda?psy=tak' ),
		'fraza ze znakami sterujacymi adresu jedzie zakodowana w calosci'
	);
	k6k_check(
		'karma & woda?psy=tak' === rawurldecode( $args2['add_args']['ainp_s'] ),
		'zakodowana fraza po odkodowaniu wraca identyczna — zero podwojnego kodowania'
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
	echo "\n-- Przyciski pokazuja takze puste kategorie --\n";

	/*
	 * Zakladanie terminow (`Plugin::ensure_topics()`) jest sprawdzane
	 * w `krok1-cykl-zycia-test.php`, gdzie `Plugin` jest PRAWDZIWY. Tutaj
	 * `Plugin` to atrapa ze stalymi, wiec asercja o nim mierzylaby atrape.
	 */

	$GLOBALS['__stan']['lista_terminow'] = array(
		$zywienie,
		(object) array( 'name' => 'Rasy', 'slug' => 'rasy' ),
	);
	$GLOBALS['__stan']['ostatnie_args']  = array();
	Portal::categories();
	k6k_check(
		false === ( $GLOBALS['__stan']['ostatnie_args']['hide_empty'] ?? true ),
		'get_terms pytany z hide_empty=false — inaczej swiezy portal pokazuje jeden przycisk'
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
