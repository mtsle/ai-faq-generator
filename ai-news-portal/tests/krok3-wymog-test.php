<?php
/**
 * Krok 3, wariant C — bramka slow WYMAGANYCH plus dlugi D-1 i D-5.
 *
 * Wariant C jest decyzja usera z 2026-08-07 i zmienia semantyke filtra wzgledem
 * planu: do tej pory pozycja przechodzila, dopoki nie trafila na slowo
 * wykluczajace; teraz musi jeszcze POWIEDZIEC, ze jest o psach. Powod jest
 * zmierzony na 70 prawdziwych pozycjach — przez sam odsiew wykluczeniami
 * przechodzily wesela, jazda konna, pizza, garderoba, obiady wegetarianskie,
 * Wi-Fi, przedszkole, slad weglowy i ogrzewanie. Zaden z tych artykulow nie ma
 * slowa wykluczajacego, wiec zadna dlugosc listy wykluczen ich nie zatrzyma.
 *
 * Ten zestaw pilnuje czterech rzeczy, ktore w tej bramce psuja sie po cichu:
 *
 *   1. PUSTA LISTA WYLACZA BRAMKE. Gdyby pusta lista znaczyla „nic nie
 *      przechodzi", instalacja sprzed wariantu C przestalaby zbierac
 *      cokolwiek — i to bez jednego bledu w logu.
 *   2. BRAMKA PATRZY TYLKO NA TYTUL I ZAJAWKE. Slowo „pies" w tresci
 *      artykulu o pizzy (stopka, boks „powiazane wpisy") nie ma prawa go
 *      uratowac, bo wtedy bramka nie odsiewa niczego i jest samym kosztem.
 *   3. WYKLUCZENIA MAJA PIERWSZENSTWO. Artykul o kocie ma zostac odrzucony
 *      jako artykul o kocie — tylko ta notatka mowi, ktore slowo zadzialalo.
 *   4. BRAMKA NIE DOTYKA SIECI. Cala jej wartosc jest w tym, ze stoi PRZED
 *      scrapingiem.
 *
 * Plus dwa dlugi z audytu Kroku 3:
 *   D-1: `Article::extract()` nie ma oddawac `ok = true` przy zerze znakow
 *        tekstu (drzewo urwane przez libxml na 255. poziomie zagniezdzenia).
 *   D-5: ksztalt zapytania w `prepare_batch()`. Atrapa bazy filtruje wiersze
 *        w PHP, wiec warunek `WHERE status='new' AND content_hash IS NULL`
 *        nie jest pokryty niczym poza odbiorem — a to znaczy, ze mutacja
 *        w nim przechodzi.
 *
 * Prawdziwe klasy: `Filter`, `Runner`, `Article`, `Dedup`, `Settings`.
 * Atrapy: `$wpdb`, `Http`, `Plugin`. Zero WordPressa i zero sieci.
 *
 * URUCHOMIENIE:  php tests/krok3-wymog-test.php
 * Kod wyjscia: 0 = OK, 1 = bledy.
 *
 * @package AI_News_Portal
 */

namespace {
	define( 'ABSPATH', __DIR__ . '/atrapy/wp/' );

	$root = dirname( __DIR__ );
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
	function k3w_check( $cond, $label ) {
		global $fail, $ran;
		$ran++;
		echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n";
		if ( ! $cond ) {
			$fail++;
		}
	}

	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}

	function current_time( $type, $gmt = 0 ) {
		return '2026-08-07 12:00:00';
	}

	function wp_encode_emoji( $tekst ) {
		return $tekst;
	}

	function esc_url_raw( $url ) {
		return trim( (string) $url );
	}

	function wp_kses_post( $tekst ) {
		return $tekst;
	}

	/**
	 * Atrapa `remove_accents()` dla alfabetu polskiego.
	 *
	 * @param string $tekst Wejscie.
	 *
	 * @return string
	 */
	function remove_accents( $tekst ) {
		return strtr(
			(string) $tekst,
			array(
				'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n',
				'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
				'Ą' => 'A', 'Ć' => 'C', 'Ę' => 'E', 'Ł' => 'L', 'Ń' => 'N',
				'Ó' => 'O', 'Ś' => 'S', 'Ź' => 'Z', 'Ż' => 'Z',
			)
		);
	}

	$GLOBALS['__opt'] = array();

	function get_option( $key, $default = false ) {
		return array_key_exists( $key, $GLOBALS['__opt'] ) ? $GLOBALS['__opt'][ $key ] : $default;
	}

	/**
	 * Atrapa `$wpdb` — zapamietuje zapytania, oddaje zaplanowane wiersze.
	 */
	class AINP_Fake_WPDB_K3W {

		public $prefix     = 'wp_';
		public $last_error = '';
		public $queries    = array();

		/** Wiersze oddawane przez `get_results()`. */
		public $wiersze = array();

		/** Wynik `query()`. */
		public $domyslny = 1;

		public function prepare( $sql, ...$args ) {
			$i = 0;

			return preg_replace_callback(
				'/%[sd]/',
				function ( $m ) use ( &$i, $args ) {
					$v = array_key_exists( $i, $args ) ? $args[ $i ] : '';
					$i++;
					if ( '%d' === $m[0] ) {
						return (string) (int) $v;
					}
					return "'" . str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), (string) $v ) . "'";
				},
				$sql
			);
		}

		public function query( $sql ) {
			$this->queries[] = $sql;
			return $this->domyslny;
		}

		public function get_results( $sql ) {
			$this->queries[] = $sql;
			return $this->wiersze;
		}

		public function get_var( $sql ) {
			$this->queries[] = $sql;
			return '';
		}
	}
}

namespace AINP {

	/** Atrapa warstwy sieciowej. Liczy KAZDE zadanie — na tym stoi punkt 4. */
	class Http {

		public static function get_feed( string $url ): array {
			$GLOBALS['__zadania'][] = 'feed:' . $url;

			return array( 'ok' => false, 'code' => 0, 'body' => '', 'error' => 'brak planu', 'reason' => 'transport', 'truncated' => false );
		}

		public static function get_article( string $url ): array {
			$GLOBALS['__zadania'][] = 'article:' . $url;

			return array( 'ok' => false, 'code' => 0, 'body' => '', 'error' => 'nie powinno paść', 'reason' => 'transport', 'truncated' => false );
		}

		public static function is_http_url( string $url ): bool {
			$parts = parse_url( $url );

			if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
				return false;
			}

			return in_array( strtolower( (string) $parts['scheme'] ), array( 'http', 'https' ), true );
		}
	}

	/** Atrapa bootstrapu — potrzebna jest tylko nazwa tabeli. */
	class Plugin {

		public static function table(): string {
			return 'wp_ainp_items';
		}
	}
}

namespace {

	require_once $root . '/src/Dedup.php';
	require_once $root . '/src/Settings.php';
	require_once $root . '/src/Filter.php';
	require_once $root . '/src/Article.php';
	require_once $root . '/src/Runner.php';

	use AINP\Article;
	use AINP\Filter;
	use AINP\Runner;

	/**
	 * Ustawia atrapy w stan poczatkowy.
	 *
	 * @return AINP_Fake_WPDB_K3W
	 */
	function k3w_reset() {
		$GLOBALS['__opt']     = array();
		$GLOBALS['__zadania'] = array();
		$GLOBALS['wpdb']      = new AINP_Fake_WPDB_K3W();

		return $GLOBALS['wpdb'];
	}

	/**
	 * Wiersz tabeli.
	 *
	 * @param int    $id      Identyfikator.
	 * @param string $title   Tytul.
	 * @param string $excerpt Zajawka.
	 * @param string $content Tresc z kanalu.
	 *
	 * @return object
	 */
	function k3w_wiersz( $id, $title, $excerpt = '', $content = '' ) {
		return (object) array(
			'id'           => $id,
			'url'          => 'https://psy.pl/' . $id . '/',
			'url_hash'     => str_repeat( (string) $id, 64 ),
			'title'        => $title,
			'excerpt'      => $excerpt,
			'content'      => $content,
			'content_hash' => '',
			'status'       => 'new',
			'note'         => '',
			'attempts'     => 0,
		);
	}

	/*
	 * Krotka lista slow wymaganych — testujemy MECHANIZM, nie liste z ustawien.
	 * Formy odmienione stoja osobno i to nie jest niechlujstwo listy, tylko
	 * skutek granicy slowa: `szczeniak` NIE zlapie `szczeniaka`. Ta sama
	 * wlasnosc kaze trzymac pelny paradygmat w `Settings::defaults()`.
	 */
	$wymagane = array( 'pies', 'psa', 'psy', 'psach', 'szczeniak', 'szczeniaka', 'jamnik' );

	echo "=== KROK 3 / WARIANT C — bramka slow wymaganych (+ D-1, D-5) ===\n\n";

	// -----------------------------------------------------------------------
	echo "-- Co MA przejsc --\n";
	// -----------------------------------------------------------------------
	$przechodza = array(
		array( 'title' => 'Jak wybrać karmę dla psa', 'summary' => 'Poradnik' ),
		array( 'title' => 'Szkolenie', 'summary' => 'Pierwsze komendy dla szczeniaka' ),
		array( 'title' => 'Jamnik w mieszkaniu', 'summary' => '' ),
		array( 'title' => 'Wszystko o psach myśliwskich', 'summary' => '' ),
		// Odmiana z ogonkami po stronie TEKSTU, lista jest bez nich.
		array( 'title' => 'Pierwszy spacer ze szczeniakiem', 'summary' => 'Instrukcja' ),
	);

	foreach ( $przechodza as $poz ) {
		k3w_check(
			Filter::has_required( $poz, array_merge( $wymagane, array( 'szczeniakiem' ) ) ),
			'przechodzi: „' . $poz['title'] . '”'
		);
	}

	// -----------------------------------------------------------------------
	echo "\n-- Co MA wypasc (dokladnie te tytuly zmierzono na dworku) --\n";
	// -----------------------------------------------------------------------
	$wypadaja = array(
		array( 'title' => 'Proste obiady z sezonowych warzyw', 'summary' => 'Przepisy' ),
		array( 'title' => 'Zespół weselny — jak wybrać', 'summary' => 'Porady' ),
		array( 'title' => 'Jazda konna dla początkujących', 'summary' => '' ),
		array( 'title' => 'Najlepsza pizza w mieście', 'summary' => '' ),
		array( 'title' => 'Jak przyspieszyć Wi-Fi w domu', 'summary' => '' ),
		array( 'title' => 'Ślad węglowy gospodarstwa domowego', 'summary' => '' ),
	);

	foreach ( $wypadaja as $poz ) {
		k3w_check(
			! Filter::has_required( $poz, $wymagane ),
			'wypada: „' . $poz['title'] . '”'
		);
	}

	// -----------------------------------------------------------------------
	echo "\n-- Punkt 1: pusta lista WYLACZA bramke --\n";
	// -----------------------------------------------------------------------
	$obcy = array( 'title' => 'Najlepsza pizza w mieście', 'summary' => 'Ranking' );

	k3w_check( Filter::has_required( $obcy, array() ), 'pusta lista przepuszcza wszystko' );
	k3w_check(
		Filter::has_required( $obcy, array( '', '   ' ) ),
		'lista z samych pustych wpisow tez przepuszcza — `clean_words()` zostawia pustke'
	);

	k3w_reset();
	$GLOBALS['__opt']['ainp_settings'] = array( 'required_words' => array() );
	k3w_check(
		Filter::has_required( $obcy ),
		'wyczyszczone pole w Ustawieniach wylacza bramke, a nie odrzuca wszystkiego'
	);

	k3w_reset();
	$GLOBALS['__opt']['ainp_settings'] = array( 'required_words' => 'nie tablica' );
	k3w_check(
		Filter::has_required( $obcy ),
		'popsuta wartosc opcji tez wylacza bramke, zamiast wywracac przebieg'
	);

	// -----------------------------------------------------------------------
	echo "\n-- Punkt 2: bramka NIE oglada tresci --\n";
	// -----------------------------------------------------------------------
	$pizza_ze_stopka = array(
		'title'   => 'Najlepsza pizza w mieście',
		'summary' => 'Ranking lokali',
		'content' => 'Pełna treść o pizzy. W stopce serwisu: „Zapisz się na newsletter dla właścicieli psa”.',
	);

	k3w_check(
		! Filter::has_required( $pizza_ze_stopka, $wymagane ),
		'slowo o psie W TRESCI nie ratuje artykulu o pizzy'
	);
	k3w_check(
		'' !== Filter::haystack( $pizza_ze_stopka ),
		'kontrola: tresc jest w pozycji — bramka ja pomija swiadomie, nie przez pusty material'
	);

	// -----------------------------------------------------------------------
	echo "\n-- Pozycja bez tytulu i bez zajawki --\n";
	// -----------------------------------------------------------------------
	k3w_check(
		! Filter::has_required( array( 'content' => 'Długi tekst o psach i szczeniakach.' ), $wymagane ),
		'sama tresc, bez tytulu i zajawki, nie przechodzi — nie ma czego oceniac'
	);

	// -----------------------------------------------------------------------
	echo "\n-- Granica slowa dziala tak samo jak przy wykluczeniach --\n";
	// -----------------------------------------------------------------------
	k3w_check(
		! Filter::has_required( array( 'title' => 'Pieszo przez Bieszczady' ), array( 'pies' ) ),
		'„pies" NIE lapie „pieszo"'
	);
	k3w_check(
		! Filter::has_required( array( 'title' => 'Psalmy i psalterz' ), array( 'psy' ) ),
		'„psy" NIE lapie „psalmy"'
	);
	k3w_check(
		Filter::has_required( array( 'title' => 'PIES na medal' ), array( 'pies' ) ),
		'wielkie litery nie przeszkadzaja'
	);

	// -----------------------------------------------------------------------
	echo "\n-- Punkt 3 i 4: bramka przy ZAPISIE pozycji --\n";
	// -----------------------------------------------------------------------
	$wpdb = k3w_reset();

	$los = Runner::insert_item(
		array( 'url' => 'https://psy.pl/pizza/', 'title' => 'Najlepsza pizza w mieście', 'summary' => 'Ranking', 'content' => 'Treść o pizzy' ),
		array( 'kot' ),
		$wymagane
	);
	$sql = $wpdb->queries[0];

	k3w_check( 'skipped' === $los, 'pozycja poza tematem konczy jako `skipped`' );
	k3w_check( false !== strpos( $sql, "'skipped'" ), 'status w zapytaniu to `skipped`' );
	k3w_check(
		false !== strpos( $sql, 'Poza tematem' ),
		'notatka mowi „poza tematem", nie „słowo wykluczające”'
	);
	k3w_check( false === strpos( $sql, 'Słowo wykluczające' ), 'notatka NIE udaje trafienia wykluczeniem' );
	k3w_check( false === strpos( $sql, 'Treść o pizzy' ), 'tresc pozycji poza tematem nie jest zapisywana' );
	k3w_check( 0 === count( $GLOBALS['__zadania'] ), 'zero zadan HTTP (jest ' . count( $GLOBALS['__zadania'] ) . ')' );

	$wpdb = k3w_reset();
	$los  = Runner::insert_item(
		array( 'url' => 'https://psy.pl/karma/', 'title' => 'Karma dla psa', 'summary' => '', 'content' => 'Treść' ),
		array( 'kot' ),
		$wymagane
	);

	k3w_check( 'added' === $los, 'pozycja o psie przechodzi normalnie' );

	// Punkt 3: wykluczenie ma pierwszenstwo przed „poza tematem".
	$wpdb = k3w_reset();
	$los  = Runner::insert_item(
		array( 'url' => 'https://psy.pl/kot/', 'title' => 'Kot rudy w domu', 'summary' => '', 'content' => 'Treść' ),
		array( 'kot' ),
		$wymagane
	);
	$sql  = $wpdb->queries[0];

	k3w_check( 'skipped' === $los, 'artykul o kocie: `skipped`' );
	k3w_check(
		false !== strpos( $sql, 'Słowo wykluczające: kot' ),
		'powodem jest SLOWO WYKLUCZAJACE, nie „poza tematem" — kolejnosc bramek'
	);

	// Pusta lista wymagana przy zapisie = zachowanie sprzed wariantu C.
	$wpdb = k3w_reset();
	$los  = Runner::insert_item(
		array( 'url' => 'https://psy.pl/pizza2/', 'title' => 'Najlepsza pizza', 'summary' => '', 'content' => 'Treść' ),
		array( 'kot' ),
		array()
	);

	k3w_check( 'added' === $los, 'z pusta lista wymagana pozycja przechodzi jak przed wariantem C' );

	// -----------------------------------------------------------------------
	echo "\n-- Bramka przy PRZYGOTOWANIU tresci (wiersze zebrane wczesniej) --\n";
	// -----------------------------------------------------------------------
	$wpdb = k3w_reset();

	$los = Runner::prepare_item(
		k3w_wiersz( 7, 'Zespół weselny — jak wybrać', 'Porady', '<p>' . str_repeat( 'Treść o weselach. ', 200 ) . '</p>' ),
		array( 'kot' ),
		$wymagane
	);

	k3w_check( 'skipped' === $los, 'wiersz zebrany przed wariantem C zostaje odsiany przy przygotowaniu' );
	k3w_check( 1 === count( $wpdb->queries ), 'dokladnie JEDNO zapytanie: `finish()` (jest ' . count( $wpdb->queries ) . ')' );
	k3w_check( false !== strpos( $wpdb->queries[0], 'Poza tematem' ), 'powod zapisany do `note`' );
	k3w_check(
		0 === count( $GLOBALS['__zadania'] ),
		'ZERO zadan HTTP — bramka stoi przed scrapingiem (jest ' . count( $GLOBALS['__zadania'] ) . ')'
	);

	// -----------------------------------------------------------------------
	echo "\n-- Cala partia: lista wymagana z USTAWIEN, nie z wywolania --\n";
	// -----------------------------------------------------------------------
	// `prepare_item()` z jawna lista sprawdzilismy wyzej. Tu idzie droga
	// prawdziwa: `prepare_batch()` czyta liste z ustawien i ma ja przekazac
	// dalej. Bez tej asercji podmiana `$wymagane` na pusta tablice w wywolaniu
	// `prepare_item()` przechodzi niezauwazona.
	$wpdb                              = k3w_reset();
	$GLOBALS['__opt']['ainp_settings'] = array(
		'excluded_words' => array(),
		'required_words' => array( 'pies', 'psa' ),
	);
	$wpdb->wiersze = array(
		k3w_wiersz( 71, 'Zespół weselny — jak wybrać', 'Porady dla par', '<p>' . str_repeat( 'Treść o weselach. ', 200 ) . '</p>' ),
	);

	$podsumowanie = Runner::prepare_batch( 10 );

	k3w_check( 1 === (int) $podsumowanie['taken'], 'partia wziela jeden wiersz' );
	k3w_check( 1 === (int) $podsumowanie['skipped'], 'i odsiala go bramka wariantu C' );
	k3w_check( 0 === (int) $podsumowanie['ready'], 'zero pozycji gotowych dla modelu' );
	k3w_check(
		false !== strpos( implode( ' | ', $wpdb->queries ), 'Poza tematem' ),
		'powod „poza tematem” zapisany — lista z ustawien doszla do `prepare_item()`'
	);
	k3w_check( 0 === count( $GLOBALS['__zadania'] ), 'i nadal zero zadan HTTP' );

	// -----------------------------------------------------------------------
	echo "\n-- Lista z ustawien: `lists()` czyta opcje RAZ --\n";
	// -----------------------------------------------------------------------
	k3w_reset();
	$GLOBALS['__opt']['ainp_settings'] = array(
		'excluded_words' => array( 'Kot', 'kot' ),
		'required_words' => array( 'Pies', ' pies ', 'PSA' ),
	);

	$listy = Filter::lists();

	k3w_check( 1 === count( $listy['excluded'] ), 'lista wykluczen znormalizowana i bez powtorzen' );
	k3w_check( 2 === count( $listy['required'] ), 'lista wymagana znormalizowana i bez powtorzen' );
	k3w_check( in_array( 'pies', $listy['required'], true ), 'slowa wymagane zapisane malymi literami' );
	k3w_check(
		$listy['excluded'] === Filter::words() && $listy['required'] === Filter::required_words(),
		'`lists()` oddaje dokladnie to samo, co obie metody osobno'
	);

	// -----------------------------------------------------------------------
	echo "\n-- DLUG D-1: `extract()` przy zerze znakow tekstu --\n";
	// -----------------------------------------------------------------------
	// Drzewo glebsze niz 255 poziomow libxml urywa; zostaje lancuch pelen
	// znacznikow i ZERO tekstu. Przedtem szlo to dalej jako `ok = true`.
	$gleboki = str_repeat( '<div>', 400 ) . str_repeat( '</div>', 400 );
	$wynik   = Article::extract( '<html><body>' . $gleboki . '</body></html>', 'https://psy.pl/x/' );

	k3w_check( false === $wynik['ok'], 'drzewo bez ani jednego znaku tekstu to `ok = false`' );
	k3w_check(
		false !== strpos( (string) $wynik['error'], 'Nie znaleziono treści' ),
		'powod nazywa brak tresci, a nie jej dlugosc'
	);

	// Szkielet aplikacji JS: same znaczniki i skrypt.
	$wynik = Article::extract( '<html><body><div id="root"></div><script>window.APP=1;</script></body></html>', 'https://psy.pl/spa/' );
	k3w_check( false === $wynik['ok'], 'szkielet renderowany JavaScriptem to `ok = false`' );

	// Kontrola w druga strone: tresc KROTKA, ale prawdziwa, ma isc dalej —
	// prog dlugosci nalezy do `Runner`, nie do parsera.
	$wynik = Article::extract( '<html><body><article><p>Krótki, ale prawdziwy tekst o psie.</p></article></body></html>', 'https://psy.pl/y/' );
	k3w_check( true === $wynik['ok'], 'krotka, ale prawdziwa tresc dalej daje `ok = true`' );
	k3w_check( 0 < Article::text_length( (string) $wynik['html'] ), 'i ma niezerowa dlugosc tekstu' );

	// -----------------------------------------------------------------------
	echo "\n-- DLUG D-5: ksztalt zapytania w `prepare_batch()` --\n";
	// -----------------------------------------------------------------------
	// Atrapa bazy filtruje wiersze w PHP, wiec WARUNEK nie jest sprawdzany
	// niczym poza odbiorem na zywej instalacji. Ta asercja patrzy wprost na
	// zapytanie: mutacja w `WHERE` przestaje byc niewidzialna.
	$wpdb          = k3w_reset();
	$wpdb->wiersze = array();

	Runner::prepare_batch( 10 );

	$select = $wpdb->queries[0];

	k3w_check( 1 === count( $wpdb->queries ), 'pusta partia to dokladnie jedno zapytanie' );
	k3w_check( false !== strpos( $select, "status = 'new'" ), 'warunek bierze WYLACZNIE wiersze o statusie `new`' );
	k3w_check(
		1 === preg_match( '/content_hash IS NULL OR content_hash = \'\'/', $select ),
		'i wylacznie te bez odcisku tresci — NULL albo pusty (odcisk jest znacznikiem „juz przygotowana")'
	);
	k3w_check( false !== strpos( $select, 'ORDER BY id ASC' ), 'kolejnosc rosnaca po `id` — najstarsze najpierw' );
	k3w_check( false !== strpos( $select, 'LIMIT 10' ), 'sufit partii trafia do zapytania, nie do petli w PHP' );

	// Sufit ponizej 1 zjadlby cala kolejke jednym zapytaniem bez limitu.
	$wpdb = k3w_reset();
	Runner::prepare_batch( 0 );
	k3w_check( false !== strpos( $wpdb->queries[0], 'LIMIT 1' ), 'sufit 0 schodzi do 1, nie do braku limitu' );

	echo "\n";
	echo '=== WYNIK: ' . ( $ran - $fail ) . ' / ' . $ran . " asercji ===\n";

	if ( $fail > 0 ) {
		echo "BŁĘDY: $fail\n";
		exit( 1 );
	}

	echo "WSZYSTKIE OK\n";
	exit( 0 );
}
