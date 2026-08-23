<?php
/**
 * Krok 4, etap 4.3 — kontrakt odpowiedzi modelu (`Validator`).
 *
 * Wymuszony schemat z etapu 4.1 gwarantuje SKLADNIE: przyjdzie obiekt
 * z czterema polami typu `string`. Semantyki nie gwarantuje i dokumentacja
 * Gemini mowi to wprost. Ten zestaw pilnuje wszystkiego, czego schemat
 * NIE zlapie, a co skonczyloby sie artykulem na publicznej stronie:
 *
 *   1. ODPOWIEDZ OBCIETA. `finishReason: MAX_TOKENS` daje polowe artykulu,
 *      ktora nadal jest POPRAWNYM JSON-em — model po prostu konczy pole
 *      wczesniej. Lapie to wylacznie prog dlugosci.
 *   2. HTML, ktorego nie chcemy na stronie. `wp_kses_post()` idzie PRZED
 *      pomiarem: inaczej artykul zlozony ze skryptu i dwoch zdan przechodzi
 *      prog, a na strone trafiaja same dwa zdania.
 *   3. DLUGOSC LICZONA W ZNACZNIKACH. Dwadziescia pustych akapitow to ponad
 *      800 znakow HTML-a i zero tresci.
 *   4. KATEGORIA SPOZA LISTY. Przy pustej liscie `enum` (klient skasowal
 *      kategorie) `topic` jest zwyklym lancuchem i model wpisuje, co chce.
 *   5. PISOWNIA KATEGORII. „zywienie" i „ZDROWIE" maja przejsc, ale zapisac
 *      sie nazwa Z USTAWIEN — taksonomia ma miec jeden termin, nie trzy
 *      warianty zapisu tego samego.
 *
 * Prawdziwe klasy: `Validator`, `Settings`. Atrapy: funkcje WordPressa.
 *
 * URUCHOMIENIE:  php tests/krok4-walidator-test.php
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
	function k4w_check( $cond, $label ) {
		global $fail, $ran;
		$ran++;
		echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n";
		if ( ! $cond ) {
			$fail++;
		}
	}

	$GLOBALS['__opt'] = array();

	function get_option( $k, $default = false ) {
		return array_key_exists( $k, $GLOBALS['__opt'] ) ? $GLOBALS['__opt'][ $k ] : $default;
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

	function wp_strip_all_tags( $tekst, $remove_breaks = false ) {
		$tekst = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $tekst );
		return trim( strip_tags( (string) $tekst ) );
	}

	/**
	 * Atrapa `wp_kses_post()` — przepuszcza tylko znaczniki tresci.
	 *
	 * Nie jest to pelny kses, ale ma te sama wlasnosc, na ktorej stoi test:
	 * `<script>` znika RAZEM z zawartoscia, a `<p>` zostaje.
	 *
	 * @param string $tekst Wejscie.
	 *
	 * @return string
	 */
	function wp_kses_post( $tekst ) {
		$tekst = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $tekst );
		return strip_tags( (string) $tekst, '<p><h2><h3><ul><ol><li><strong><em><a><br>' );
	}
}

namespace {

	require_once $root . '/src/Settings.php';
	require_once $root . '/src/Validator.php';

	use AINP\Validator;

	$kategorie = array( 'Żywienie', 'Zdrowie', 'Zachowanie', 'Szkolenie', 'Pielęgnacja', 'Rasy', 'Życie z psem' );

	/**
	 * Poprawna odpowiedz modelu, z podmienionymi polami.
	 *
	 * @param array<string,string> $zmiany Nadpisania.
	 *
	 * @return array<string,string>
	 */
	function k4w_odp( array $zmiany = array() ) {
		$tresc = '<p>' . str_repeat( 'Pies potrzebuje regularnych spacerów i stałych pór karmienia. ', 20 ) . '</p>';

		return array_merge(
			array(
				'title'   => 'Jak karmić psa latem',
				'lead'    => 'Krótki poradnik o żywieniu psa w czasie upałów i o pilnowaniu wody.',
				'content' => $tresc,
				'topic'   => 'Żywienie',
			),
			$zmiany
		);
	}

	echo "=== KROK 4, ETAP 4.3: kontrakt odpowiedzi modelu ===\n\n";

	// ------------------------------------------------------------------
	echo "-- Poprawna odpowiedz --\n";

	$r = Validator::check( k4w_odp(), $kategorie );
	k4w_check( true === $r['ok'], 'poprawna odpowiedz przechodzi' );
	k4w_check( 'Jak karmić psa latem' === $r['data']['title'], 'tytul wraca oczyszczony' );
	k4w_check( 'Żywienie' === $r['data']['topic'], 'kategoria wraca w postaci z Ustawien' );
	k4w_check( '' === $r['error'], 'brak komunikatu bledu' );
	k4w_check( false !== strpos( $r['data']['content'], '<p>' ), 'znaczniki tresci przezywaja walidacje' );

	// ------------------------------------------------------------------
	echo "\n-- Brakujace i puste pola --\n";

	foreach ( array( 'title', 'lead', 'content', 'topic' ) as $pole ) {
		$bez = k4w_odp();
		unset( $bez[ $pole ] );
		$r = Validator::check( $bez, $kategorie );
		k4w_check( false === $r['ok'] && $pole === $r['field'], 'brak pola „' . $pole . '” odrzucony' );
	}

	$r = Validator::check( k4w_odp( array( 'content' => '' ) ), $kategorie );
	k4w_check( false === $r['ok'] && 'content' === $r['field'], 'pusta tresc odrzucona' );

	$r = Validator::check( k4w_odp( array( 'topic' => '' ) ), $kategorie );
	k4w_check( false === $r['ok'] && 'topic' === $r['field'], 'pusta kategoria odrzucona' );

	$r = Validator::check( 'to nie jest obiekt', $kategorie );
	k4w_check( false === $r['ok'] && 'json' === $r['field'], 'odpowiedz, ktora nie jest obiektem, odrzucona' );

	$r = Validator::check( k4w_odp( array( 'content' => array( 'nie', 'lancuch' ) ) ), $kategorie );
	k4w_check( false === $r['ok'], 'pole innego typu niz lancuch odrzucone' );

	// ------------------------------------------------------------------
	echo "\n-- Dlugosci: granice sa ostre --\n";

	$r = Validator::check( k4w_odp( array( 'title' => 'Pies' ) ), $kategorie );
	k4w_check( false === $r['ok'] && 'title' === $r['field'], 'tytul 4 znaki odrzucony' );

	$r = Validator::check( k4w_odp( array( 'title' => 'Psiak' ) ), $kategorie );
	k4w_check( true === $r['ok'], 'tytul dokladnie 5 znakow przechodzi' );

	$r = Validator::check( k4w_odp( array( 'title' => str_repeat( 'a', Validator::MAX_TITLE ) ) ), $kategorie );
	k4w_check( true === $r['ok'], 'tytul dokladnie na gornej granicy przechodzi' );

	$r = Validator::check( k4w_odp( array( 'title' => str_repeat( 'a', Validator::MAX_TITLE + 1 ) ) ), $kategorie );
	k4w_check( false === $r['ok'] && 'title' === $r['field'], 'tytul o znak za dlugi odrzucony' );

	$r = Validator::check( k4w_odp( array( 'lead' => 'Za krótka zajawka.' ) ), $kategorie );
	k4w_check( false === $r['ok'] && 'lead' === $r['field'], 'zajawka ponizej progu odrzucona' );

	$r = Validator::check( k4w_odp( array( 'lead' => str_repeat( 'b', Validator::MAX_LEAD + 1 ) ) ), $kategorie );
	k4w_check( false === $r['ok'] && 'lead' === $r['field'], 'zajawka powyzej progu odrzucona' );

	// Dlugosc liczona w ZNAKACH, nie w bajtach — inaczej polskie litery
	// przepuszczalyby tytul o jedna trzecia za dlugi.
	$ogonki = str_repeat( 'ą', Validator::MAX_TITLE );
	$r      = Validator::check( k4w_odp( array( 'title' => $ogonki ) ), $kategorie );
	k4w_check( true === $r['ok'], 'tytul z polskich liter mierzony w znakach, nie w bajtach' );

	// ------------------------------------------------------------------
	echo "\n-- Tresc: obcieta odpowiedz modelu --\n";

	$urwana = '<p>Pies wymaga ruchu.</p>';
	$r      = Validator::check( k4w_odp( array( 'content' => $urwana ) ), $kategorie );
	k4w_check( false === $r['ok'] && 'content' === $r['field'], 'obcieta tresc (MAX_TOKENS) odrzucona' );
	k4w_check( false !== strpos( $r['error'], (string) Validator::MIN_CONTENT ), 'powod podaje wymagany prog' );

	// Prog liczy TEKST, nie HTML: same puste akapity to zero tresci.
	$puste = str_repeat( '<p></p>', 200 );
	k4w_check( strlen( $puste ) > Validator::MIN_CONTENT, 'fikstura naprawde jest dluzsza niz prog w bajtach HTML-a' );
	$r = Validator::check( k4w_odp( array( 'content' => $puste ) ), $kategorie );
	k4w_check( false === $r['ok'] && 'content' === $r['field'], 'dlugosc w znacznikach NIE nabija progu' );

	// ------------------------------------------------------------------
	echo "\n-- Tresc: kses przed pomiarem --\n";

	$skrypt = '<script>' . str_repeat( 'var x = 1; ', 200 ) . '</script><p>Dwa zdania. I tyle.</p>';
	k4w_check( strlen( $skrypt ) > Validator::MIN_CONTENT, 'fikstura ze skryptem przekracza prog przed czyszczeniem' );
	$r = Validator::check( k4w_odp( array( 'content' => $skrypt ) ), $kategorie );
	k4w_check( false === $r['ok'] && 'content' === $r['field'], 'tresc mierzona PO wycieciu skryptu — odrzucona' );

	$z_html = '<p>' . str_repeat( 'Pies lubi spacery o stałych porach dnia. ', 25 ) . '</p><script>alert(1)</script>';
	$r      = Validator::check( k4w_odp( array( 'content' => $z_html ) ), $kategorie );
	k4w_check( true === $r['ok'], 'dluga tresc ze smieciem przechodzi' );
	k4w_check( false === strpos( $r['data']['content'], '<script' ), 'skrypt NIE trafia do zapisanej tresci' );
	k4w_check( false === strpos( $r['data']['content'], 'alert(1)' ), 'zawartosc skryptu tez znika' );

	// ------------------------------------------------------------------
	echo "\n-- Kategoria: pisownia i lista --\n";

	$r = Validator::check( k4w_odp( array( 'topic' => 'zywienie' ) ), $kategorie );
	k4w_check( true === $r['ok'], 'kategoria bez ogonkow przechodzi' );
	k4w_check( 'Żywienie' === $r['data']['topic'], 'i zapisuje sie nazwa Z USTAWIEN' );

	$r = Validator::check( k4w_odp( array( 'topic' => 'ZDROWIE' ) ), $kategorie );
	k4w_check( true === $r['ok'] && 'Zdrowie' === $r['data']['topic'], 'kategoria wielkimi literami dopasowana' );

	$r = Validator::check( k4w_odp( array( 'topic' => '  Życie   z psem  ' ) ), $kategorie );
	k4w_check( true === $r['ok'] && 'Życie z psem' === $r['data']['topic'], 'nadmiar spacji w kategorii nie przeszkadza' );

	$r = Validator::check( k4w_odp( array( 'topic' => 'Koty' ) ), $kategorie );
	k4w_check( false === $r['ok'] && 'topic' === $r['field'], 'kategoria spoza listy odrzucona' );
	k4w_check( false !== strpos( $r['error'], 'Koty' ), 'powod cytuje kategorie, ktora przyszla' );

	$r = Validator::check( k4w_odp( array( 'topic' => 'Żywienie i zdrowie' ) ), $kategorie );
	k4w_check( false === $r['ok'], 'dopasowanie jest do CALEJ nazwy, nie do fragmentu' );

	$r = Validator::check( k4w_odp(), array() );
	k4w_check( false === $r['ok'] && 'topic' === $r['field'], 'przy pustej liscie kategorii nie przechodzi NIC' );

	$GLOBALS['__opt']['ainp_settings'] = array( 'categories' => array( 'Rasy' ) );
	$r = Validator::check( k4w_odp( array( 'topic' => 'rasy' ) ) );
	k4w_check( true === $r['ok'] && 'Rasy' === $r['data']['topic'], 'bez argumentu lista brana z Ustawien' );

	$r = Validator::check( k4w_odp( array( 'topic' => 'Żywienie' ) ) );
	k4w_check( false === $r['ok'], 'kategoria usunieta z Ustawien przestaje przechodzic' );

	// ------------------------------------------------------------------
	echo "\n-- Znaczniki w tytule i zajawce --\n";

	$r = Validator::check( k4w_odp( array( 'title' => '<b>Jak karmić psa</b>' ) ), $kategorie );
	k4w_check( true === $r['ok'], 'tytul ze znacznikiem przechodzi' );
	k4w_check( 'Jak karmić psa' === $r['data']['title'], 'znaczniki z tytulu wyciete' );

	$r = Validator::check( k4w_odp( array( 'title' => '<b>Ala</b>' ) ), $kategorie );
	k4w_check( false === $r['ok'], 'tytul mierzony PO wycieciu znacznikow — za krotki' );

	$r = Validator::check( k4w_odp( array( 'lead' => '<p>' . str_repeat( 'x', Validator::MAX_LEAD ) . '</p>' ) ), $kategorie );
	k4w_check( true === $r['ok'], 'zajawka mierzona bez znacznikow' );

	// ------------------------------------------------------------------
	echo "\n";
	echo '=== Asercji: ' . $ran . ' | bledow: ' . $fail . " ===\n";
	if ( 0 === $fail ) {
		echo "WSZYSTKIE OK\n";
		exit( 0 );
	}
	echo "BŁĘDY\n";
	exit( 1 );
}
