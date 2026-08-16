<?php
/**
 * Krok 7, etap 7.1 — mechanizm wysylki naglowkow bezpieczenstwa.
 *
 * Zestaw sprawdza TRZY rzeczy, wszystkie na zachowaniu, nie na tekscie kodu:
 *
 *   1. KTORE zadania mechanizm w ogole obsluguje. Bramki musza rozpoznac
 *      kanal RSS i ramke oEmbed ZANIM zobacza w nich zwykla strone — bo
 *      `is_post_type_archive()` jest prawdziwe takze pod `/…/feed/`,
 *      a `is_embed()` i `is_singular()` sa prawdziwe jednoczesnie.
 *   2. CO wychodzi dla kazdego widoku, wraz z niezmiennikiem chroniacym
 *      decyzje projektowa: nasze CSP NIE MOZE dotykac zrodel zasobow,
 *      bo strone renderuje motyw klienta.
 *   3. JAK to wychodzi: nigdy nie nadpisujac cudzego naglowka i zawsze
 *      z `replace = false`.
 *
 * ATRAPY `header()`, `headers_list()` i `headers_sent()` mieszkaja
 * w przestrzeni nazw `AINP`. To nie jest sztuczka dla sztuczki: PHP przy
 * wywolaniu niekwalifikowanej funkcji szuka najpierw w biezacej przestrzeni,
 * a dopiero potem w globalnej — inaczej nie da sie podejrzec funkcji
 * wbudowanej. Kod produkcyjny wola je bez `\` i to jest wymog tego zestawu.
 *
 * Prawdziwa klasa: `Security`. Atrapy: `Plugin` (stale), funkcje WordPressa
 * i trzy funkcje naglowkowe PHP.
 *
 * URUCHOMIENIE:  php tests/krok7-naglowki-test.php
 * Kod wyjscia: 0 = OK, 1 = bledy.
 *
 * @package AI_News_Portal
 */

namespace {

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
	function k7n_check( $cond, $label ) {
		global $fail, $ran;
		$ran++;
		echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n";
		if ( ! $cond ) {
			$fail++;
		}
	}

	// --- Stan atrap --------------------------------------------------------
	$GLOBALS['__k7'] = array(
		'admin'      => false,
		'feed'       => false,
		'embed'      => false,
		'singular'   => false,   // Nasz CPT.
		'obcy_singl' => false,   // Cudzy typ wpisu.
		'tax'        => false,
		'archive'    => false,
		'sent'       => false,   // Czy naglowki juz poszly.
		'lista'      => array(), // Co juz wisi w odpowiedzi.
		'wyslane'    => array(), // array( array( linia, replace ) ).
		'hooki'      => array(),
		'filtr'      => null,    // Callback podpiety pod ainp_security_headers.
		'filtr_args' => array(),
		'stala'      => null,    // AINP_NO_SECURITY_HEADERS: null = nie zdefiniowana.
	);

	/**
	 * Zeruje stan zapytania, zostawiajac konfiguracje odpowiedzi.
	 *
	 * @param array $zmiany Nadpisania.
	 *
	 * @return void
	 */
	function k7n_stan( array $zmiany = array() ) {
		$GLOBALS['__k7'] = array_merge(
			$GLOBALS['__k7'],
			array(
				'admin'      => false,
				'feed'       => false,
				'embed'      => false,
				'singular'   => false,
				'obcy_singl' => false,
				'tax'        => false,
				'archive'    => false,
				'sent'       => false,
				'lista'      => array(),
				'wyslane'    => array(),
				'filtr'      => null,
				'filtr_args' => array(),
				'stala'      => null,
			),
			$zmiany
		);
	}

	// --- Atrapy WordPressa -------------------------------------------------

	function add_action( $hook, $cb, $priorytet = 10, $args = 1 ) {
		$GLOBALS['__k7']['hooki'][] = array( 'action', $hook, $priorytet );
		return true;
	}

	function apply_filters( $hook, $wartosc ) {
		$args = array_slice( func_get_args(), 2 );

		if ( null === $GLOBALS['__k7']['filtr'] ) {
			return $wartosc;
		}

		$GLOBALS['__k7']['filtr_args'] = $args;

		return call_user_func_array(
			$GLOBALS['__k7']['filtr'],
			array_merge( array( $wartosc ), $args )
		);
	}

	function is_admin() {
		return (bool) $GLOBALS['__k7']['admin'];
	}

	function is_feed() {
		return (bool) $GLOBALS['__k7']['feed'];
	}

	function is_embed() {
		return (bool) $GLOBALS['__k7']['embed'];
	}

	function is_singular( $typ = '' ) {
		if ( 'ainp_article' === $typ ) {
			return (bool) $GLOBALS['__k7']['singular'];
		}
		return (bool) $GLOBALS['__k7']['obcy_singl'];
	}

	function is_tax( $taks = '' ) {
		return 'ainp_topic' === $taks && (bool) $GLOBALS['__k7']['tax'];
	}

	function is_post_type_archive( $typ = '' ) {
		return 'ainp_article' === $typ && (bool) $GLOBALS['__k7']['archive'];
	}
}

namespace AINP {

	/**
	 * Atrapa `Plugin` — same stale. Prawdziwa klasa ciagnie za soba tabele,
	 * crona i rejestracje CPT, a `Security` potrzebuje z niej dwoch napisow.
	 */
	final class Plugin {
		public const CPT = 'ainp_article';
		public const TAX = 'ainp_topic';
	}

	/**
	 * Atrapa `headers_sent()`.
	 *
	 * @return bool
	 */
	function headers_sent() {
		return (bool) $GLOBALS['__k7']['sent'];
	}

	/**
	 * Atrapa `headers_list()` — zwraca to, co „juz wisi" w odpowiedzi.
	 *
	 * @return array<int,string>
	 */
	function headers_list() {
		return $GLOBALS['__k7']['lista'];
	}

	/**
	 * Atrapa `header()`. Zapisuje TAKZE drugi argument, bo to on decyduje,
	 * czy cudzy naglowek zostaje nadpisany.
	 *
	 * @param string $linia   Naglowek.
	 * @param bool   $replace Czy zastapic istniejacy.
	 *
	 * @return void
	 */
	function header( $linia, $replace = true ) {
		$GLOBALS['__k7']['wyslane'][] = array( $linia, $replace );
	}

	/**
	 * Atrapa `defined()`. Prawdziwej stalej nie da sie w tescie cofnac,
	 * a sciezke wylaczenia trzeba sprawdzic w obie strony.
	 *
	 * @param string $nazwa Nazwa stalej.
	 *
	 * @return bool
	 */
	function defined( $nazwa ) {
		return 'AINP_NO_SECURITY_HEADERS' === $nazwa && null !== $GLOBALS['__k7']['stala'];
	}

	/**
	 * Atrapa `constant()`.
	 *
	 * @param string $nazwa Nazwa stalej.
	 *
	 * @return mixed
	 */
	function constant( $nazwa ) {
		return 'AINP_NO_SECURITY_HEADERS' === $nazwa ? $GLOBALS['__k7']['stala'] : null;
	}
}

namespace {

	require_once dirname( __DIR__ ) . '/src/Security.php';

	use AINP\Security;

	echo "== Krok 7, etap 7.1: naglowki bezpieczenstwa Centrum Wiedzy ==\n\n";

	/**
	 * Nazwy naglowkow wyslanych w ostatnim przebiegu.
	 *
	 * @return array<int,string>
	 */
	function k7n_nazwy() {
		$out = array();
		foreach ( $GLOBALS['__k7']['wyslane'] as $wpis ) {
			$out[] = strtolower( trim( substr( $wpis[0], 0, (int) strpos( $wpis[0], ':' ) ) ) );
		}
		return $out;
	}

	// ---------------------------------------------------------------------
	echo "-- Podpiecie hooka --\n";

	Security::register();
	$hooki = $GLOBALS['__k7']['hooki'];

	k7n_check( 1 === count( $hooki ), 'register() podpina dokladnie jeden hook (jest: ' . count( $hooki ) . ')' );
	k7n_check( 'action' === $hooki[0][0], 'to akcja, nie filtr' );
	k7n_check( 'template_redirect' === $hooki[0][1], 'hook to template_redirect (nie send_headers — ten chodzi takze w kokpicie)' );
	k7n_check( 0 === $hooki[0][2], 'priorytet 0 — przed jakimkolwiek wyjsciem' );

	// ---------------------------------------------------------------------
	echo "\n-- Ktore zadania sa nasze --\n";

	k7n_stan( array( 'archive' => true ) );
	k7n_check( Security::VIEW_PAGE === Security::view(), 'archiwum CPT to widok strony' );

	k7n_stan( array( 'tax' => true ) );
	k7n_check( Security::VIEW_PAGE === Security::view(), 'archiwum kategorii to widok strony' );

	k7n_stan( array( 'singular' => true ) );
	k7n_check( Security::VIEW_PAGE === Security::view(), 'artykul to widok strony' );

	// GOTCHA: `is_embed()` i `is_singular()` sa prawdziwe JEDNOCZESNIE.
	k7n_stan( array( 'singular' => true, 'embed' => true ) );
	k7n_check( Security::VIEW_EMBED === Security::view(), 'embed artykulu to widok embed, NIE strona (obie flagi prawdziwe naraz)' );

	// GOTCHA: `is_post_type_archive()` jest prawdziwe takze pod `/…/feed/`.
	k7n_stan( array( 'archive' => true, 'feed' => true ) );
	k7n_check( Security::VIEW_FEED === Security::view(), 'kanal RSS archiwum to widok feed, NIE strona' );

	k7n_stan( array( 'singular' => true, 'feed' => true ) );
	k7n_check( Security::VIEW_FEED === Security::view(), 'kanal komentarzy artykulu tez jest feedem' );

	k7n_stan();
	k7n_check( Security::VIEW_NONE === Security::view(), 'zapytanie spoza portalu nie jest nasze' );

	k7n_stan( array( 'obcy_singl' => true ) );
	k7n_check( Security::VIEW_NONE === Security::view(), 'cudzy wpis nie jest nasz — wtyczka nie zabezpiecza calej witryny' );

	k7n_stan( array( 'embed' => true, 'obcy_singl' => true ) );
	k7n_check( Security::VIEW_NONE === Security::view(), 'embed cudzego wpisu nie jest nasz' );

	k7n_stan( array( 'admin' => true, 'archive' => true ) );
	k7n_check( Security::VIEW_NONE === Security::view(), 'kokpit zostaje poza zasiegiem nawet przy naszych flagach' );

	// ---------------------------------------------------------------------
	echo "\n-- Co wychodzi dla kazdego widoku --\n";

	$strona = Security::headers_for( Security::VIEW_PAGE );
	$embed  = Security::headers_for( Security::VIEW_EMBED );
	$feed   = Security::headers_for( Security::VIEW_FEED );

	k7n_check( 'strict-origin-when-cross-origin' === ( $strona['Referrer-Policy'] ?? '' ), 'strona: Referrer-Policy strict-origin-when-cross-origin' );
	k7n_check( 'nosniff' === ( $strona['X-Content-Type-Options'] ?? '' ), 'strona: X-Content-Type-Options nosniff' );
	k7n_check( 'SAMEORIGIN' === ( $strona['X-Frame-Options'] ?? '' ), 'strona: X-Frame-Options SAMEORIGIN' );
	k7n_check( isset( $strona['Permissions-Policy'] ), 'strona: Permissions-Policy obecne' );
	k7n_check( "frame-ancestors 'self'" === ( $strona['Content-Security-Policy'] ?? '' ), 'strona: CSP zawezone do frame-ancestors' );

	k7n_check( ! isset( $embed['X-Frame-Options'] ), 'embed: BEZ X-Frame-Options — ramka oEmbed ma sie dac osadzic' );
	k7n_check( ! isset( $embed['Content-Security-Policy'] ), 'embed: BEZ CSP — zakaz ramek znosilby sens tej sciezki' );
	k7n_check( 'strict-origin-when-cross-origin' === ( $embed['Referrer-Policy'] ?? '' ), 'embed: Referrer-Policy jednak wychodzi' );
	k7n_check( 'nosniff' === ( $embed['X-Content-Type-Options'] ?? '' ), 'embed: nosniff jednak wychodzi' );

	k7n_check( ! isset( $feed['Content-Security-Policy'] ), 'feed: BEZ CSP — dla XML-a nic nie znaczy' );
	k7n_check( ! isset( $feed['X-Frame-Options'] ), 'feed: BEZ X-Frame-Options' );
	k7n_check( ! isset( $feed['Permissions-Policy'] ), 'feed: BEZ Permissions-Policy' );
	k7n_check( 'nosniff' === ( $feed['X-Content-Type-Options'] ?? '' ), 'feed: nosniff — broni przed potraktowaniem kanalu jako HTML' );
	k7n_check( 'strict-origin-when-cross-origin' === ( $feed['Referrer-Policy'] ?? '' ), 'feed: Referrer-Policy' );

	k7n_check( array() === Security::headers_for( Security::VIEW_NONE ), 'widok pusty nie daje zadnego naglowka' );

	// NIEZMIENNIK CHRONIACY DECYZJE PROJEKTOWA. Front renderuje motyw klienta,
	// wiec polityka zrodel nalezy do niego. Gdyby ktos kiedys dopisal tu
	// `script-src` albo `'unsafe-inline'`, ta asercja ma zaswiecic na czerwono.
	$csp = $strona['Content-Security-Policy'];
	k7n_check( false === strpos( $csp, 'unsafe-inline' ), 'NIEZMIENNIK: nasze CSP nie zawiera unsafe-inline' );
	k7n_check( false === strpos( $csp, 'script-src' ), 'NIEZMIENNIK: nasze CSP nie rusza script-src' );
	k7n_check( false === strpos( $csp, 'style-src' ), 'NIEZMIENNIK: nasze CSP nie rusza style-src' );
	k7n_check( false === strpos( $csp, 'default-src' ), 'NIEZMIENNIK: nasze CSP nie rusza default-src' );

	// ---------------------------------------------------------------------
	echo "\n-- Jak to wychodzi: uzupelnianie, nie nadpisywanie --\n";

	k7n_stan();
	$wyslane = Security::emit( array( 'A-Jeden' => '1', 'A-Dwa' => '2' ) );
	k7n_check( 2 === count( $GLOBALS['__k7']['wyslane'] ), 'brakujace naglowki wychodza' );
	k7n_check( array( 'A-Jeden', 'A-Dwa' ) === $wyslane, 'emit() zwraca nazwy tego, co naprawde poszlo' );

	$wszystkie_false = true;
	foreach ( $GLOBALS['__k7']['wyslane'] as $wpis ) {
		if ( false !== $wpis[1] ) {
			$wszystkie_false = false;
		}
	}
	k7n_check( $wszystkie_false, 'KAZDE wywolanie header() ma replace = false — cudzej polityki nie da sie nadpisac' );

	k7n_stan( array( 'lista' => array( 'referrer-policy: no-referrer', 'X-Powered-By: PHP' ) ) );
	$wyslane = Security::emit( array( 'Referrer-Policy' => 'strict-origin-when-cross-origin', 'X-Frame-Options' => 'SAMEORIGIN' ) );
	k7n_check( array( 'X-Frame-Options' ) === $wyslane, 'naglowek juz obecny zostaje nietkniety, brakujacy dochodzi' );
	k7n_check( ! in_array( 'referrer-policy', k7n_nazwy(), true ), 'porownanie nazw jest nieczule na wielkosc liter' );

	k7n_stan( array( 'sent' => true ) );
	Security::emit( array( 'A-Jeden' => '1' ) );
	k7n_check( array() === $GLOBALS['__k7']['wyslane'], 'po wyslaniu naglowkow mechanizm milczy zamiast rzucac ostrzezeniem' );

	k7n_stan();
	Security::emit( array( "A-Zla\r\nSet-Cookie" => 'x', 'A-Pusta' => '', '' => 'y', 'A-Dobra' => 'ok' ) );
	k7n_check( array( 'a-dobra' ) === k7n_nazwy(), 'wartosc z lamana linia, pusta nazwa i pusta wartosc sa odrzucane' );

	// ---------------------------------------------------------------------
	echo "\n-- Sciezka pelna: maybe_send() --\n";

	k7n_stan();
	Security::maybe_send();
	k7n_check( array() === $GLOBALS['__k7']['wyslane'], 'zapytanie spoza portalu: zero naglowkow' );

	k7n_stan( array( 'archive' => true ) );
	Security::maybe_send();
	k7n_check( 5 === count( $GLOBALS['__k7']['wyslane'] ), 'archiwum dostaje komplet pieciu naglowkow (jest: ' . count( $GLOBALS['__k7']['wyslane'] ) . ')' );

	k7n_stan( array( 'archive' => true, 'feed' => true ) );
	Security::maybe_send();
	k7n_check( 2 === count( $GLOBALS['__k7']['wyslane'] ), 'kanal RSS dostaje dwa, nie piec' );

	// ---------------------------------------------------------------------
	echo "\n-- Sciezka wyjscia dla klienta: filtr --\n";

	k7n_stan(
		array(
			'archive' => true,
			'filtr'   => static function ( $naglowki, $widok ) {
				return array();
			},
		)
	);
	Security::maybe_send();
	k7n_check( array() === $GLOBALS['__k7']['wyslane'], 'pusta tablica z filtra wylacza mechanizm w calosci' );
	k7n_check( array( Security::VIEW_PAGE ) === $GLOBALS['__k7']['filtr_args'], 'filtr dostaje nazwe widoku jako drugi argument' );

	k7n_stan(
		array(
			'archive' => true,
			'filtr'   => static function ( $naglowki, $widok ) {
				$naglowki['Cross-Origin-Opener-Policy'] = 'same-origin';
				return $naglowki;
			},
		)
	);
	Security::maybe_send();
	k7n_check( in_array( 'cross-origin-opener-policy', k7n_nazwy(), true ), 'klient moze dolozyc wlasny naglowek przez filtr' );

	k7n_stan(
		array(
			'archive' => true,
			'filtr'   => static function ( $naglowki, $widok ) {
				return 'nie tablica';
			},
		)
	);
	Security::maybe_send();
	k7n_check( array() === $GLOBALS['__k7']['wyslane'], 'filtr zwracajacy nie-tablice nie wywraca zadania' );

	// ---------------------------------------------------------------------
	echo "\n-- Etap 7.2: konflikt z polityka motywu --\n";

	$komplet = Security::headers_for( Security::VIEW_PAGE );

	k7n_stan();
	k7n_check( 5 === count( Security::reconcile( $komplet ) ), 'motyw bez wlasnego CSP: komplet zostaje nietkniety' );

	// Przypadek zmierzony na dworku: motyw MA frame-ancestors.
	k7n_stan( array( 'lista' => array( "Content-Security-Policy: default-src 'self'; frame-ancestors 'self'" ) ) );
	$po = Security::reconcile( $komplet );
	k7n_check( ! isset( $po['Content-Security-Policy'] ), 'cudze CSP: naszego CSP nie dokladamy' );
	k7n_check( ! isset( $po['X-Frame-Options'] ), 'cudze CSP z frame-ancestors: X-Frame-Options tez odpada — przegladarka i tak by go zignorowala' );
	k7n_check( 3 === count( $po ), 'zostaja trzy naglowki, ktore z polityka motywu nie koliduja' );

	// Motyw opisal zrodla, ale o ramkach nie powiedzial nic.
	k7n_stan( array( 'lista' => array( "Content-Security-Policy: default-src 'self'; img-src *" ) ) );
	$po = Security::reconcile( $komplet );
	k7n_check( ! isset( $po['Content-Security-Policy'] ), 'cudze CSP bez frame-ancestors: drugiego CSP nie wysylamy (dwa dzialaja jak iloczyn polityk)' );
	k7n_check( 'SAMEORIGIN' === ( $po['X-Frame-Options'] ?? '' ), 'cudze CSP bez frame-ancestors: X-Frame-Options ZOSTAJE i zatyka dziure' );

	k7n_stan( array( 'lista' => array( "Content-Security-Policy-Report-Only: default-src 'self'; frame-ancestors 'none'" ) ) );
	k7n_check( 5 === count( Security::reconcile( $komplet ) ), 'Report-Only nie jest polityka — niczego nie blokuje, wiec nie zwalnia nas z ochrony' );

	k7n_stan( array( 'archive' => true, 'lista' => array( "Content-Security-Policy: frame-ancestors 'self'" ) ) );
	Security::maybe_send();
	$nazwy = k7n_nazwy();
	k7n_check( 3 === count( $nazwy ), 'sciezka pelna przy motywie z polityka: trzy naglowki' );
	k7n_check( ! in_array( 'content-security-policy', $nazwy, true ), 'sciezka pelna: zadnego drugiego CSP w odpowiedzi' );

	// ---------------------------------------------------------------------
	echo "\n-- Etap 7.2: dwie sciezki wylaczenia --\n";

	k7n_stan( array( 'archive' => true, 'stala' => true ) );
	Security::maybe_send();
	k7n_check( array() === $GLOBALS['__k7']['wyslane'], 'stala AINP_NO_SECURITY_HEADERS wylacza mechanizm w calosci' );
	k7n_check( Security::disabled(), 'disabled() mowi wprost, ze mechanizm jest wylaczony' );

	k7n_stan( array( 'archive' => true, 'stala' => false ) );
	Security::maybe_send();
	k7n_check( 5 === count( $GLOBALS['__k7']['wyslane'] ), 'stala zdefiniowana jako false NIE wylacza — liczy sie wartosc, nie samo istnienie' );

	// Kolejnosc: filtr dostaje juz uzgodniony zestaw i ma ostatnie slowo.
	k7n_stan(
		array(
			'archive' => true,
			'lista'   => array( "Content-Security-Policy: frame-ancestors 'self'" ),
			'filtr'   => static function ( $naglowki, $widok ) {
				$GLOBALS['__k7']['widziane_przez_filtr'] = $naglowki;
				return $naglowki;
			},
		)
	);
	Security::maybe_send();
	k7n_check(
		! isset( $GLOBALS['__k7']['widziane_przez_filtr']['Content-Security-Policy'] ),
		'filtr dostaje zestaw JUZ uzgodniony z motywem, nie surowy — inaczej klient poprawialby cos, co i tak nie wyjdzie'
	);

	echo "\nWYNIK: " . ( $ran - $fail ) . " / {$ran} asercji\n";
	exit( $fail > 0 ? 1 : 0 );
}
