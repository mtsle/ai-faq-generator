<?php
/**
 * Etap 8.3 — WYKONANIE prawdziwych szablonow frontu.
 *
 * Do etapu 8.3 zaden zestaw nie uruchamial `src/templates/*.php`. `krok6-portal`
 * podstawial w ich miejsce atrapy w rodzaju `<?php // archiwum wtyczki`
 * (krok6-portal-test.php:56-59), a `krok6-karty` sprawdzal je wylacznie
 * `token_get_all()` i `file_exists()` (krok6-karty-test.php:754-782). Znaczylo to,
 * ze literowka w nazwie funkcji, zla funkcja escapujaca albo wywrocona galaz
 * `if` w tych 282 liniach przechodzily przez caly runner i pokazywaly sie
 * dopiero na zywej stronie.
 *
 * Ten zestaw laduje pliki szablonow TAK, JAK ROBI TO WORDPRESS — przez `include`
 * z buforem wyjscia — i asercjonuje to, co wyszlo.
 *
 * Prawdziwe pliki: `src/templates/{archive,card,single}.php`.
 * Atrapy: funkcje WordPressa i klasa `Portal`. Kazda atrapa `Portal` ma
 * sygnature SKOPIOWANA z produkcji (Portal.php: linie podane przy metodach) —
 * wezsza zjadalaby argumenty po cichu.
 *
 * Atrapy escapujace NIE udaja, ze cos czyszcza: zapisuja, KTORA funkcja zostala
 * uzyta. Dzieki temu asercja „paginacja idzie przez `wp_kses_post`, nie przez
 * `esc_html`" mierzy szablon, a nie sile atrapy.
 *
 * URUCHOMIENIE:  php tests/etap83-szablony-test.php
 * Kod wyjscia: 0 = OK, 1 = bledy.
 *
 * @package AI_News_Portal
 */

namespace {
	// Bez tej stalej bramka `defined( 'ABSPATH' ) || exit;` w szablonie
	// zakonczylaby skrypt PO CICHU — dokladnie ta pulapka zabila caly zestaw
	// `krok7-naglowki-test.php` (patrz naglowek runnera).
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
	function s83_check( $cond, $label ) {
		global $fail, $ran;
		$ran++;
		echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n";
		if ( ! $cond ) {
			$fail++;
		}
	}

	/**
	 * Stan sceny: co szablon ma zastac.
	 *
	 * @param array<string,mixed> $zmiany Nadpisania.
	 *
	 * @return void
	 */
	function s83_scena( array $zmiany = array() ) {
		$GLOBALS['__s83'] = array_merge(
			array(
				'wpisy'      => array(),   // Kolejka wpisow dla petli `have_posts()`.
				'wpis'       => null,      // Wpis ustawiony przez `the_post()`.
				'kategorie'  => array(),
				'biezaca'    => '',
				'szukane'    => '',
				'paginacja'  => '',
				'tytul'      => 'Centrum Wiedzy',
				'zrodlo'     => '',
				'lead'       => '',
				'foto'       => '',
				'rotacja'        => 1,     // Numer wariantu, ktory odda atrapa `category_variant()`.
				'rotacja_pytana' => 0,     // Ile razy szablon o ten numer poprosil.
				'wariant_pytany' => 0,     // Z jakim wariantem szablon zbudowal adres.
				'termin'     => null,
				'link_term'  => '',
				'escapery'   => array(),   // Ktora funkcja escapujaca dostala co.
				'karty'      => 0,         // Ile razy `Portal::part()` narysowal karte.
				'naglowek'   => 0,
				'stopka'     => 0,
			),
			$zmiany
		);
	}

	/**
	 * Termin taksonomii w ksztalcie, jaki oddaje `Portal::primary_term()`.
	 *
	 * @param string $nazwa Nazwa.
	 * @param string $slug  Slug.
	 *
	 * @return object
	 */
	function s83_termin( $nazwa, $slug ) {
		return (object) array(
			'name' => $nazwa,
			'slug' => $slug,
		);
	}

	/**
	 * Uruchamia PRAWDZIWY szablon i oddaje jego wyjscie.
	 *
	 * @param string $plik Nazwa pliku w `src/templates/`.
	 *
	 * @return string
	 */
	function s83_render( $plik ) {
		ob_start();
		include dirname( __DIR__ ) . '/src/templates/' . $plik;
		return (string) ob_get_clean();
	}
}

namespace AINP {

	/**
	 * Atrapa `Portal`. Sygnatury 1:1 z produkcja — numery linii przy metodach
	 * wskazuja oryginal w `src/Portal.php`.
	 */
	final class Portal {

		public const SEARCH_VAR = 'ainp_s';                                  // Portal.php:78.

		public static function pagination(): string {                        // Portal.php:154.
			return (string) $GLOBALS['__s83']['paginacja'];
		}

		public static function search_term(): string {                       // Portal.php:185.
			return (string) $GLOBALS['__s83']['szukane'];
		}

		public static function categories(): array {                         // Portal.php:246.
			return (array) $GLOBALS['__s83']['kategorie'];
		}

		public static function archive_link(): string {                      // Portal.php:267.
			return 'https://dworek.local/centrum-wiedzy/';
		}

		public static function current_term_slug(): string {                 // Portal.php:278.
			return (string) $GLOBALS['__s83']['biezaca'];
		}

		public static function part( string $plik ): void {                  // Portal.php:456.
			$GLOBALS['__s83']['karty']++;
			$GLOBALS['__s83']['czesc'] = $plik;
			echo "\n<!-- karta: " . $plik . " -->\n";
		}

		public static function archive_title(): string {                     // Portal.php:487.
			return (string) $GLOBALS['__s83']['tytul'];
		}

		public static function primary_term( int $post_id ): ?object {       // Portal.php:516.
			$GLOBALS['__s83']['pytano_o_termin'] = $post_id;
			return $GLOBALS['__s83']['termin'];
		}

		public static function term_link( object $termin ): string {         // Portal.php:535.
			// Pusty adres dla slugu `bez-linku` odgrywa termin, ktorego
			// WordPress nie potrafi zlinkowac — szablon ma go POMINAC.
			return ( 'bez-linku' === $termin->slug ) ? '' : (string) $GLOBALS['__s83']['link_term'];
		}

		public static function category_image_url( string $slug, int $wariant = 1 ): string {  // Portal.php:566.
			// Atrapa ZAPISUJE numer wariantu, o ktory ja poproszono. Bez tego
			// test widzialby tylko, ze karta rysuje obrazek, a nie CZY w ogole
			// pyta o rotacje — a to wlasnie mialoby prawo cicho zniknac.
			$GLOBALS['__s83']['wariant_pytany'] = $wariant;

			return (string) $GLOBALS['__s83']['foto'];
		}

		public static function category_variant( string $slug ): int {       // Portal.php:8.8.
			$GLOBALS['__s83']['rotacja_pytana']++;

			return (int) $GLOBALS['__s83']['rotacja'];
		}

		public static function initial( string $nazwa ): string {            // Portal.php:582.
			return '' === $nazwa ? '' : mb_strtoupper( mb_substr( $nazwa, 0, 1 ) );
		}

		public static function source_url( int $post_id ): string {          // Portal.php:610.
			return (string) $GLOBALS['__s83']['zrodlo'];
		}

		public static function source_host( string $adres ): string {        // Portal.php:636.
			// Zachowanie SKOPIOWANE z produkcji razem z obcieciem `www.`
			// (Portal.php:643). Atrapa oddajaca surowy host mierzylaby sama
			// siebie, a asercja o ramce zrodla bylaby falszywa.
			$host = strtolower( (string) wp_parse_url_host( $adres ) );
			return ( 0 === strpos( $host, 'www.' ) ) ? substr( $host, 4 ) : $host;
		}

		public static function lead( int $post_id ): string {                // Portal.php:659.
			return (string) $GLOBALS['__s83']['lead'];
		}
	}
}

namespace {

	// ── Atrapy WordPressa ────────────────────────────────────────────────────
	// Escapery ZAPISUJA, ze zostaly uzyte, i robia to, co naprawde robia
	// w rdzeniu. Gdyby tylko oddawaly wejscie, asercja o escapowaniu mierzylaby
	// atrape, a nie szablon.

	function wp_parse_url_host( $adres ) {
		$host = parse_url( (string) $adres, PHP_URL_HOST );
		return is_string( $host ) ? $host : '';
	}

	function esc_html( $tekst ) {
		$GLOBALS['__s83']['escapery'][] = array( 'esc_html', (string) $tekst );
		return htmlspecialchars( (string) $tekst, ENT_QUOTES, 'UTF-8' );
	}

	function esc_attr( $tekst ) {
		$GLOBALS['__s83']['escapery'][] = array( 'esc_attr', (string) $tekst );
		return htmlspecialchars( (string) $tekst, ENT_QUOTES, 'UTF-8' );
	}

	function esc_url( $adres ) {
		$GLOBALS['__s83']['escapery'][] = array( 'esc_url', (string) $adres );
		$adres = str_replace( array( '"', "'", '<', '>' ), '', (string) $adres );
		return ( 0 === strpos( $adres, 'javascript:' ) ) ? '' : $adres;
	}

	function wp_kses_post( $html ) {
		$GLOBALS['__s83']['escapery'][] = array( 'wp_kses_post', (string) $html );
		return (string) preg_replace( '#<script\b[^>]*>.*?</script>#is', '', (string) $html );
	}

	function esc_html_e( $tekst, $domena = '' ) {
		echo esc_html( $tekst );
	}

	function esc_attr_e( $tekst, $domena = '' ) {
		echo esc_attr( $tekst );
	}

	function get_header( $nazwa = null ) {
		$GLOBALS['__s83']['naglowek']++;
		echo "<!-- naglowek motywu -->\n";
	}

	function get_footer( $nazwa = null ) {
		$GLOBALS['__s83']['stopka']++;
		echo "\n<!-- stopka motywu -->";
	}

	function have_posts() {
		return array() !== $GLOBALS['__s83']['wpisy'];
	}

	function the_post() {
		$GLOBALS['__s83']['wpis'] = array_shift( $GLOBALS['__s83']['wpisy'] );
	}

	function get_the_ID() {
		$wpis = $GLOBALS['__s83']['wpis'];
		return isset( $wpis['id'] ) ? (int) $wpis['id'] : 0;
	}

	function the_title() {
		$wpis = $GLOBALS['__s83']['wpis'];
		echo esc_html( isset( $wpis['tytul'] ) ? $wpis['tytul'] : '' );
	}

	function the_permalink() {
		$wpis = $GLOBALS['__s83']['wpis'];
		echo esc_url( isset( $wpis['adres'] ) ? $wpis['adres'] : '' );
	}

	function the_content() {
		$wpis = $GLOBALS['__s83']['wpis'];
		echo isset( $wpis['tresc'] ) ? $wpis['tresc'] : '';
	}

	function post_class( $dodatkowe = '' ) {
		echo 'class="' . esc_attr( trim( 'post type-ainp_article ' . $dodatkowe ) ) . '"';
	}

	function get_the_date( $format = '' ) {
		$wpis = $GLOBALS['__s83']['wpis'];
		if ( 'c' === $format ) {
			return isset( $wpis['data_iso'] ) ? $wpis['data_iso'] : '';
		}
		return isset( $wpis['data'] ) ? $wpis['data'] : '';
	}

	// ── Scena ────────────────────────────────────────────────────────────────

	echo "== Etap 8.3: wykonanie prawdziwych szablonow frontu ==\n";

	/**
	 * Czy w wyjsciu jest podany fragment.
	 *
	 * @param string $html     Wyjscie szablonu.
	 * @param string $fragment Szukany fragment.
	 *
	 * @return bool
	 */
	function s83_ma( $html, $fragment ) {
		return false !== strpos( $html, $fragment );
	}

	/**
	 * Ile razy dana funkcja escapujaca dostala podana wartosc.
	 *
	 * @param string $funkcja Nazwa.
	 * @param string $wartosc Wartosc.
	 *
	 * @return int
	 */
	function s83_escapowano( $funkcja, $wartosc ) {
		$ile = 0;
		foreach ( $GLOBALS['__s83']['escapery'] as $wpis ) {
			if ( $funkcja === $wpis[0] && $wartosc === $wpis[1] ) {
				$ile++;
			}
		}
		return $ile;
	}

	// -------------------------------------------------------------------------
	echo "\n-- archive.php: szkielet i miejsce motywu --\n";

	s83_scena(
		array(
			'wpisy' => array(
				array( 'id' => 11, 'tytul' => 'Karma dla szczeniaka', 'adres' => 'https://dworek.local/centrum-wiedzy/karma/', 'data' => '3 sierpnia 2026', 'data_iso' => '2026-08-03T10:00:00+02:00' ),
				array( 'id' => 12, 'tytul' => 'Spacer zimą', 'adres' => 'https://dworek.local/centrum-wiedzy/spacer/', 'data' => '4 sierpnia 2026', 'data_iso' => '2026-08-04T10:00:00+02:00' ),
			),
		)
	);
	$html = s83_render( 'archive.php' );

	s83_check( 1 === $GLOBALS['__s83']['naglowek'], 'archiwum wola get_header() dokladnie raz — naglowek rysuje MOTYW' );
	s83_check( 1 === $GLOBALS['__s83']['stopka'], 'i get_footer() dokladnie raz' );
	s83_check( s83_ma( $html, '<main class="ainp-portal" id="ainp-portal">' ), 'jest kontener glowny z zakotwiczeniem' );
	s83_check( 2 === $GLOBALS['__s83']['karty'], 'dwa wpisy w petli = DWIE karty (jest: ' . $GLOBALS['__s83']['karty'] . ')' );
	s83_check( 'card.php' === $GLOBALS['__s83']['czesc'], 'petla rysuje karte przez Portal::part( card.php )' );
	s83_check( s83_ma( $html, '<div class="ainp-portal__list">' ), 'karty leza w liscie, nie luzem' );

	// -------------------------------------------------------------------------
	echo "\n-- archive.php: wyszukiwarka bez JavaScriptu --\n";

	s83_check( s83_ma( $html, 'method="get"' ), 'formularz idzie metoda GET — adres wyniku da sie zapisac w zakladkach' );
	s83_check( s83_ma( $html, 'action="https://dworek.local/centrum-wiedzy/"' ), 'action celuje w ARCHIWUM, nie w biezacy adres kategorii' );
	s83_check( s83_ma( $html, 'name="ainp_s"' ), 'pole nazywa sie wlasnym parametrem ainp_s, nie `s` WordPressa' );
	s83_check( s83_ma( $html, 'role="search"' ), 'formularz ma role search' );
	s83_check( s83_ma( $html, '<label class="screen-reader-text" for="ainp-search">' ), 'pole ma etykiete zwiazana z id — czytnik ekranu wie, co to' );
	s83_check( ! s83_ma( $html, '<script' ), 'w calym archiwum nie ma ANI JEDNEGO znacznika script' );
	s83_check( ! s83_ma( $html, 'onclick=' ) && ! s83_ma( $html, 'onsubmit=' ), 'ani jednego atrybutu zdarzeniowego' );

	// -------------------------------------------------------------------------
	echo "\n-- archive.php: fraza wraca do pola, ale ESCAPOWANA --\n";

	s83_scena( array( 'szukane' => '"><script>alert(1)</script>', 'wpisy' => array() ) );
	$html = s83_render( 'archive.php' );

	s83_check( ! s83_ma( $html, '<script>alert(1)</script>' ), 'wstrzykniecie z frazy NIE wychodzi jako znacznik' );
	s83_check( s83_ma( $html, 'value="&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;"' ), 'fraza wraca do pola w postaci zescapowanej' );
	s83_check( 1 === s83_escapowano( 'esc_attr', '"><script>alert(1)</script>' ), 'fraza przeszla przez esc_attr dokladnie raz' );
	s83_check( s83_ma( $html, 'Nic nie pasuje do tego zapytania' ), 'pusty wynik SZUKANIA ma wlasny komunikat' );
	s83_check( ! s83_ma( $html, 'Nie ma tu jeszcze żadnych artykułów' ), 'i nie miesza go z komunikatem pustego portalu' );

	s83_scena( array( 'wpisy' => array() ) );
	$html = s83_render( 'archive.php' );
	s83_check( s83_ma( $html, 'Nie ma tu jeszcze żadnych artykułów' ), 'pusty portal BEZ szukania ma komunikat o pustym portalu' );
	s83_check( ! s83_ma( $html, 'Nic nie pasuje' ), 'i nie sugeruje, ze cos szukano' );

	// -------------------------------------------------------------------------
	echo "\n-- archive.php: przyciski kategorii --\n";

	s83_scena(
		array(
			'wpisy'     => array(),
			'kategorie' => array( s83_termin( 'Żywienie', 'zywienie' ), s83_termin( 'Zdrowie', 'zdrowie' ), s83_termin( 'Kaleka', 'bez-linku' ) ),
			'biezaca'   => 'zdrowie',
			'link_term' => 'https://dworek.local/centrum-wiedzy/kategoria/x/',
		)
	);
	$html = s83_render( 'archive.php' );

	s83_check( 3 === substr_count( $html, 'class="ainp-chip' ), 'trzy przyciski: „Wszystkie" + dwie kategorie z adresem (jest: ' . substr_count( $html, 'class="ainp-chip' ) . ')' );
	s83_check( ! s83_ma( $html, 'Kaleka' ), 'kategoria bez adresu jest POMINIETA, nie rysowana martwym linkiem' );
	s83_check( 1 === substr_count( $html, 'aria-current="page"' ), 'dokladnie jeden przycisk jest oznaczony jako biezacy' );
	s83_check( 1 === substr_count( $html, 'is-current' ), 'i dokladnie jeden ma klase is-current' );
	s83_check( s83_ma( $html, 'Zdrowie' ) && s83_ma( $html, 'Żywienie' ), 'obie linkowalne kategorie sa na liscie' );
	s83_check( s83_ma( $html, 'aria-label="Kategorie"' ), 'nawigacja kategorii ma etykiete' );

	s83_scena( array( 'wpisy' => array(), 'kategorie' => array(), 'biezaca' => '' ) );
	$html = s83_render( 'archive.php' );
	s83_check( ! s83_ma( $html, 'ainp-portal__cats' ), 'brak kategorii = brak calej nawigacji, nie pusty pasek' );

	// -------------------------------------------------------------------------
	echo "\n-- archive.php: paginacja idzie przez wp_kses_post, nie przez esc_html --\n";

	$strony = '<a class="page-numbers" href="https://dworek.local/centrum-wiedzy/page/2/">2</a><script>alert(2)</script>';
	s83_scena( array( 'wpisy' => array( array( 'id' => 11, 'tytul' => 'T', 'adres' => 'https://x/', 'data' => 'd', 'data_iso' => 'i' ) ), 'paginacja' => $strony ) );
	$html = s83_render( 'archive.php' );

	s83_check( 1 === s83_escapowano( 'wp_kses_post', $strony ), 'znaczniki paginacji ida przez wp_kses_post dokladnie raz' );
	s83_check( 0 === s83_escapowano( 'esc_html', $strony ), 'i NIE przez esc_html — inaczej linki wyszlyby jako widoczny tekst' );
	s83_check( s83_ma( $html, '<a class="page-numbers"' ), 'link do drugiej strony zostaje klikalnym znacznikiem' );
	s83_check( ! s83_ma( $html, 'alert(2)' ), 'a wstrzykniety script z paginacji nie przechodzi' );
	s83_check( s83_ma( $html, 'aria-label="Strony artykułów"' ), 'pager ma etykiete' );

	s83_scena( array( 'wpisy' => array( array( 'id' => 11, 'tytul' => 'T', 'adres' => 'https://x/', 'data' => 'd', 'data_iso' => 'i' ) ), 'paginacja' => '' ) );
	$html = s83_render( 'archive.php' );
	s83_check( ! s83_ma( $html, 'ainp-portal__pager' ), 'jedna strona wynikow = brak calego pagera' );

	// -------------------------------------------------------------------------
	echo "\n-- card.php: zdjecie kategorii albo kafelek z litera --\n";

	s83_scena(
		array(
			'wpis'      => array( 'id' => 11, 'tytul' => 'Karma dla szczeniaka', 'adres' => 'https://dworek.local/centrum-wiedzy/karma/', 'data' => '3 sierpnia 2026', 'data_iso' => '2026-08-03T10:00:00+02:00' ),
			'termin'    => s83_termin( 'Żywienie', 'zywienie' ),
			'link_term' => 'https://dworek.local/centrum-wiedzy/kategoria/zywienie/',
			'foto'      => 'https://dworek.local/wp-content/plugins/ai-news-portal/assets/kategorie/zywienie.jpg',
			'lead'      => 'Szczeniak rosnie szybciej niz pies dorosly.',
		)
	);
	$html = s83_render( 'card.php' );

	s83_check( 11 === $GLOBALS['__s83']['pytano_o_termin'], 'karta pyta o kategorie DLA TEGO wpisu, nie globalnie' );
	s83_check( s83_ma( $html, '<img src="https://dworek.local/wp-content/plugins/ai-news-portal/assets/kategorie/zywienie.jpg"' ), 'zdjecie kategorii wchodzi jako img' );
	s83_check( s83_ma( $html, 'alt=""' ), 'alt jest PUSTY — zdjecie jest dekoracja kategorii (WCAG)' );
	s83_check( s83_ma( $html, 'loading="lazy"' ) && s83_ma( $html, 'decoding="async"' ), 'zdjecie laduje sie leniwie i dekoduje asynchronicznie' );
	s83_check( s83_ma( $html, 'width="1200" height="675"' ), 'wymiary sa w znaczniku — inaczej strona skacze przy ladowaniu (CLS)' );
	s83_check( ! s83_ma( $html, 'ainp-card__tile' ), 'przy zdjeciu NIE ma kafelka zastepczego' );
	s83_check( s83_ma( $html, 'aria-hidden="true"' ), 'link samego zdjecia jest ukryty przed czytnikiem — tytul obok prowadzi tam samo' );
	s83_check( s83_ma( $html, 'tabindex="-1"' ), 'i wypada z kolejnosci klawisza Tab, zeby nie dublowac tytulu' );
	s83_check( s83_ma( $html, '<a class="ainp-card__kicker" href="https://dworek.local/centrum-wiedzy/kategoria/zywienie/">' ), 'nazwa kategorii jest linkiem, gdy adres istnieje' );
	s83_check( s83_ma( $html, '<p class="ainp-card__lead">Szczeniak rosnie szybciej niz pies dorosly.</p>' ), 'lead wchodzi jako akapit' );
	s83_check( s83_ma( $html, 'datetime="2026-08-03T10:00:00+02:00"' ), 'data ma postac maszynowa w atrybucie datetime' );
	s83_check( 1 === preg_match( '#<time class="ainp-card__date"[^>]*>\s*3 sierpnia 2026\s*</time>#', $html ), 'i czytelna dla czlowieka w tresci znacznika time' );
	s83_check( s83_ma( $html, 'class="post type-ainp_article ainp-card"' ), 'karta dostaje klasy WordPressa RAZEM z wlasna' );

	// ROTACJA WARIANTOW (8.8) — asercja na WYWOLANIU, nie na stalej.
	// Sam fakt, ze `Portal::IMAGE_VARIANTS` rowna sie 3, nie mowi nic o tym,
	// czy szablon o wariant pyta. Mutacja usuwajaca `category_variant()`
	// z `card.php` przezylaby asercje na stalej i zginie na tych dwoch.
	s83_check( 1 === $GLOBALS['__s83']['rotacja_pytana'], 'karta pyta o wariant zdjecia DOKLADNIE RAZ na karte' );
	s83_check( 1 === $GLOBALS['__s83']['wariant_pytany'], 'i przekazuje otrzymany numer do budowy adresu, nie zgaduje' );

	s83_scena(
		array(
			'wpis'      => array( 'id' => 13, 'tytul' => 'Druga karta tej samej kategorii', 'adres' => 'https://x/', 'data' => 'd', 'data_iso' => 'i' ),
			'termin'    => s83_termin( 'Żywienie', 'zywienie' ),
			'link_term' => '',
			'rotacja'   => 3,
			'foto'      => 'https://dworek.local/wp-content/plugins/ai-news-portal/assets/kategorie/zywienie-3.jpg',
		)
	);
	$html = s83_render( 'card.php' );

	s83_check( 3 === $GLOBALS['__s83']['wariant_pytany'], 'karta oddaje dalej wariant 3, gdy tyle wskazala rotacja' );
	s83_check( s83_ma( $html, 'assets/kategorie/zywienie-3.jpg"' ), 'i rysuje zdjecie tego wariantu, nie zawsze pierwszego' );

	s83_scena(
		array(
			'wpis'      => array( 'id' => 12, 'tytul' => 'Bez zdjecia', 'adres' => 'https://x/', 'data' => 'd', 'data_iso' => 'i' ),
			'termin'    => s83_termin( 'Żywienie', 'zywienie' ),
			'link_term' => '',
			'foto'      => '',
			'lead'      => '',
		)
	);
	$html = s83_render( 'card.php' );

	s83_check( ! s83_ma( $html, '<img' ), 'brak zdjecia = zaden pusty img' );
	s83_check( s83_ma( $html, '<span class="ainp-card__tile" aria-hidden="true">Ż</span>' ), 'zamiast zdjecia kafelek z pierwsza litera kategorii' );
	s83_check( s83_ma( $html, '<span class="ainp-card__kicker">Żywienie</span>' ), 'kategoria bez adresu wychodzi jako span, nie martwy link' );
	s83_check( ! s83_ma( $html, 'ainp-card__lead' ), 'pusty lead = brak calego akapitu' );

	s83_scena( array( 'wpis' => array( 'id' => 13, 'tytul' => 'Sierota', 'adres' => 'https://x/', 'data' => 'd', 'data_iso' => 'i' ), 'termin' => null ) );
	$html = s83_render( 'card.php' );
	s83_check( ! s83_ma( $html, 'ainp-card__kicker' ), 'wpis BEZ kategorii nie rysuje pustego naglowka kategorii' );
	s83_check( s83_ma( $html, 'ainp-card__title' ), 'ale sam tytul zostaje — karta bez kategorii nadal jest karta' );

	// -------------------------------------------------------------------------
	echo "\n-- card.php: tytul i adres sa escapowane --\n";

	s83_scena(
		array(
			'wpis'   => array( 'id' => 14, 'tytul' => 'Pies <script>alert(3)</script>', 'adres' => 'javascript:alert(4)', 'data' => 'd', 'data_iso' => 'i' ),
			'termin' => s83_termin( '<b>Zła</b> kategoria', 'zla' ),
			'foto'   => '',
		)
	);
	$html = s83_render( 'card.php' );

	s83_check( ! s83_ma( $html, '<script>alert(3)</script>' ), 'wstrzykniecie z tytulu nie wychodzi znacznikiem' );
	s83_check( ! s83_ma( $html, 'javascript:alert(4)' ), 'adres `javascript:` nie przechodzi przez esc_url' );
	s83_check( ! s83_ma( $html, '<b>Zła</b>' ), 'nazwa kategorii tez jest escapowana' );
	s83_check( 1 === s83_escapowano( 'esc_html', '<b>Zła</b> kategoria' ), 'i idzie przez esc_html dokladnie raz' );

	// -------------------------------------------------------------------------
	echo "\n-- single.php: metryczka, tresc i ramka zrodla --\n";

	s83_scena(
		array(
			'wpisy'     => array( array( 'id' => 21, 'tytul' => 'Karma bytowa', 'adres' => 'https://dworek.local/centrum-wiedzy/karma/', 'tresc' => '<p>Tresc artykulu.</p>', 'data' => '5 sierpnia 2026', 'data_iso' => '2026-08-05T09:00:00+02:00' ) ),
			'termin'    => s83_termin( 'Żywienie', 'zywienie' ),
			'link_term' => 'https://dworek.local/centrum-wiedzy/kategoria/zywienie/',
			'zrodlo'    => 'https://www.psy.pl/artykul/karma-bytowa/',
		)
	);
	$html = s83_render( 'single.php' );

	s83_check( 1 === $GLOBALS['__s83']['naglowek'] && 1 === $GLOBALS['__s83']['stopka'], 'artykul tez oddaje naglowek i stopke motywowi' );
	s83_check( s83_ma( $html, '<h1 class="ainp-article__title">Karma bytowa</h1>' ), 'tytul artykulu jest jedynym H1' );
	s83_check( 1 === substr_count( $html, '<h1' ), 'i wystepuje dokladnie raz' );
	s83_check( s83_ma( $html, '<p>Tresc artykulu.</p>' ), 'tresc wchodzi przez the_content(), z zachowanymi znacznikami' );
	s83_check( s83_ma( $html, '<a class="ainp-article__cat" href="https://dworek.local/centrum-wiedzy/kategoria/zywienie/">' ), 'kategoria w metryczce jest linkiem' );
	s83_check( s83_ma( $html, 'datetime="2026-08-05T09:00:00+02:00"' ), 'data maszynowa w metryczce' );
	s83_check( s83_ma( $html, 'rel="nofollow noopener"' ), 'link do zrodla ma nofollow i noopener' );
	s83_check( 1 === preg_match( '#rel="nofollow noopener">\s*psy\.pl\s*</a>#', $html ), 'w ramce zrodla widac sam host bez `www.`, nie caly adres' );
	s83_check( 2 === substr_count( $html, 'ainp-article__back' ), 'powrot do Centrum Wiedzy jest NAD i POD artykulem (jest: ' . substr_count( $html, 'ainp-article__back' ) . ')' );
	s83_check( s83_ma( $html, 'class="post type-ainp_article ainp-article"' ), 'artykul dostaje klasy WordPressa razem z wlasna' );

	s83_scena(
		array(
			'wpisy'  => array( array( 'id' => 22, 'tytul' => 'Bez zrodla', 'adres' => 'https://x/', 'tresc' => '<p>x</p>', 'data' => 'd', 'data_iso' => 'i' ) ),
			'termin' => null,
			'zrodlo' => '',
		)
	);
	$html = s83_render( 'single.php' );

	s83_check( ! s83_ma( $html, 'ainp-article__source' ), 'brak adresu zrodla = brak calej ramki zrodla' );
	s83_check( ! s83_ma( $html, 'ainp-article__cat' ), 'brak kategorii = brak pustego pola kategorii w metryczce' );
	s83_check( s83_ma( $html, '<time datetime=' ), 'ale data zostaje — metryczka nie znika w calosci' );

	// -------------------------------------------------------------------------
	echo "\n-- Wszystkie trzy szablony maja bramke ABSPATH --\n";

	foreach ( array( 'archive.php', 'card.php', 'single.php' ) as $plik ) {
		$kod = (string) file_get_contents( dirname( __DIR__ ) . '/src/templates/' . $plik );
		s83_check(
			false !== strpos( $kod, "if ( ! defined( 'ABSPATH' ) ) {" ),
			$plik . ': bramka ABSPATH stoi — bez niej plik wywolany wprost z przegladarki wykonalby sie bez WordPressa'
		);
	}

	echo "\nWYNIK: " . ( $ran - $fail ) . " / {$ran} asercji\n";
	exit( $fail > 0 ? 1 : 0 );
}
