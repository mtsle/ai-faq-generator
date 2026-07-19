<?php
/**
 * Testy Kroku 17 — źródła bazodanowe: WpContentSource (§3.1), is_indexable() (§2.2)
 * i PostMetaContentSource (§3.2). KONTRAKT k17-v3.
 *
 * Pisane W CIEMNO — wyłącznie z kontraktu, bez zaglądania w kod Etapu 1.
 *
 * Trzy rzeczy, na których ten plik stoi:
 *  1. `strip_shortcodes()` jest tu ZDEFINIOWANY i WIERNY WordPressowi (kasuje
 *     `[tag]…[/tag]` RAZEM z treścią) — wzorzec `krok5-contentsource-test.php` go nie
 *     definiował, przez co asercja „treść w shortcode przeżywa" była zielona nawet na
 *     kodzie, który tę treść gubi (§8 pkt 4 kontraktu). Dodatkowo licznik wywołań: §3.1
 *     ZABRANIA wołania tej funkcji, więc asercja brzmi `=== 0`, a nie „wynik wygląda OK".
 *  2. `is_indexable()` testowany po ZAMROŻONEJ powierzchni mockowania z tabeli §2.2 —
 *     każdy warunek na WŁASNYM post_id, bo kontrakt wymaga memoizacji wyniku.
 *  3. Do każdej asercji negatywnej („to ma zniknąć") jest para pozytywna na tym samym
 *     korpusie („a to ma zostać") — inaczej zaślepka `return '';` przechodzi.
 *
 * URUCHOMIENIE:  php -d extension=mbstring tests/krok17-db-sources-test.php
 * Kod wyjścia: 0 = OK, 1 = błędy.
 *
 * @package AI_FAQ_Generator
 */

namespace AIFAQ\Core {

	// Atrapa routera — §2.2 warunek 4 porównuje post_name z Router::slug().
	if ( ! class_exists( 'AIFAQ\Core\Router' ) ) {
		/** Atrapa routera wtyczki (tylko slug trasy publicznej). */
		class Router {
			/**
			 * Slug wirtualnej trasy generatora.
			 *
			 * @return string Slug.
			 */
			public static function slug(): string { return 'faqgenerator'; }
		}
	}
}

namespace {

	if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
	if ( ! defined( 'AIFAQ_VERSION' ) ) { define( 'AIFAQ_VERSION', '0.20.0-test' ); }

	// -----------------------------------------------------------------------
	// Rejestry i magazyny atrap.
	// -----------------------------------------------------------------------
	$GLOBALS['__opt']            = array();  // get_option()
	$GLOBALS['__postdata']       = array();  // post_status / post_password / post_type / post_name
	$GLOBALS['__meta']           = array();  // post_id => array( klucz => array( wartości ) )
	$GLOBALS['__posts_by_type']  = array();  // typ => array( obiekty WP_Post )
	$GLOBALS['__get_posts_args'] = array();  // wszystkie wywołania get_posts()
	$GLOBALS['__status_calls']   = array();  // licznik get_post_status() per id (memoizacja)
	$GLOBALS['__strip_calls']    = 0;        // licznik strip_shortcodes() — MUSI zostać 0
	$GLOBALS['__filter_cb']      = array();  // podpięte filtry

	// -----------------------------------------------------------------------
	// Shimy WP.
	// -----------------------------------------------------------------------
	if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
	if ( ! function_exists( 'wp_unslash' ) ) { function wp_unslash( $s ) { return is_string( $s ) ? stripslashes( $s ) : $s; } }
	if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ) { return trim( (string) preg_replace( '/[\r\n\t]+/', ' ', (string) preg_replace( '/<[^>]*>/', '', (string) $s ) ) ); } }
	if ( ! function_exists( 'sanitize_textarea_field' ) ) { function sanitize_textarea_field( $s ) { return trim( (string) preg_replace( '/<[^>]*>/', '', (string) $s ) ); } }
	if ( ! function_exists( 'sanitize_title' ) ) { function sanitize_title( $s ) { $s = strtolower( trim( (string) $s ) ); $s = preg_replace( '/[^a-z0-9]+/', '-', $s ); return trim( (string) $s, '-' ); } }
	if ( ! function_exists( 'get_bloginfo' ) ) { function get_bloginfo( $show = 'name' ) { return 'Przedszkole Testowe'; } }
	if ( ! function_exists( 'home_url' ) ) { function home_url( $path = '/' ) { return 'https://example.test' . $path; } }
	if ( ! function_exists( 'get_the_title' ) ) { function get_the_title( $id ) { return 'Tytuł ' . (int) $id; } }
	if ( ! function_exists( 'get_permalink' ) ) { function get_permalink( $p = 0 ) { $id = is_object( $p ) ? (int) $p->ID : (int) $p; return 'https://example.test/?p=' . $id; } }

	if ( ! function_exists( 'get_option' ) ) {
		function get_option( $key, $default = false ) {
			return array_key_exists( $key, $GLOBALS['__opt'] ) ? $GLOBALS['__opt'][ $key ] : $default;
		}
	}
	if ( ! function_exists( 'update_option' ) ) {
		function update_option( $key, $value, $autoload = null ) { $GLOBALS['__opt'][ $key ] = $value; return true; }
	}
	if ( ! function_exists( 'get_post_status' ) ) {
		function get_post_status( $id = 0 ) {
			$id = (int) $id;
			$GLOBALS['__status_calls'][ $id ] = ( $GLOBALS['__status_calls'][ $id ] ?? 0 ) + 1;
			return $GLOBALS['__postdata'][ $id ]['post_status'] ?? 'publish';
		}
	}
	if ( ! function_exists( 'get_post_type' ) ) {
		function get_post_type( $id = 0 ) { return $GLOBALS['__postdata'][ (int) $id ]['post_type'] ?? 'page'; }
	}
	if ( ! function_exists( 'get_post_field' ) ) {
		function get_post_field( $field, $id = 0, $context = 'display' ) {
			return $GLOBALS['__postdata'][ (int) $id ][ $field ] ?? '';
		}
	}
	if ( ! function_exists( 'get_post_meta' ) ) {
		function get_post_meta( $id, $key = '', $single = false ) {
			$all = $GLOBALS['__meta'][ (int) $id ] ?? array();
			if ( '' === $key ) { return $all; }
			$vals = $all[ $key ] ?? array();
			return $single ? ( $vals[0] ?? '' ) : $vals;
		}
	}
	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( $hook, $value = null, ...$args ) {
			if ( isset( $GLOBALS['__filter_cb'][ $hook ] ) ) {
				return call_user_func_array( $GLOBALS['__filter_cb'][ $hook ], array_merge( array( $value ), $args ) );
			}
			return $value;
		}
	}
	if ( ! function_exists( 'is_serialized' ) ) {
		function is_serialized( $data, $strict = true ) {
			if ( ! is_string( $data ) ) { return false; }
			$data = trim( $data );
			if ( 'N;' === $data ) { return true; }
			if ( strlen( $data ) < 4 || ':' !== $data[1] ) { return false; }
			return (bool) preg_match( '/^[aOsbdi]:/', $data );
		}
	}

	/**
	 * Atrapa get_posts() ROZGAŁĘZIAJĄCA SIĘ PO post_type (§8 pkt 5).
	 * Wzorzec z Kroku 5 ignorował argumenty i przepuszczał implementację pytającą o złe typy.
	 */
	if ( ! function_exists( 'get_posts' ) ) {
		function get_posts( $args = array() ) {
			$GLOBALS['__get_posts_args'][] = $args;
			$types = $args['post_type'] ?? 'post';
			if ( ! is_array( $types ) ) {
				$types = array_filter( array_map( 'trim', explode( ',', (string) $types ) ), 'strlen' );
			}
			$out = array();
			foreach ( $types as $t ) {
				foreach ( ( $GLOBALS['__posts_by_type'][ $t ] ?? array() ) as $p ) { $out[] = $p; }
			}
			if ( isset( $args['fields'] ) && 'ids' === $args['fields'] ) {
				return array_map( static function ( $p ) { return (int) $p->ID; }, $out );
			}
			return $out;
		}
	}

	/**
	 * WIERNA atrapa strip_shortcodes() — kasuje shortcode RAZEM Z ZAWARTOŚCIĄ,
	 * dokładnie jak WordPress dla zarejestrowanych shortcode'ów. Kontrakt §3.1 pkt 3
	 * ZABRANIA jej wołania; licznik poniżej pilnuje `=== 0`.
	 */
	if ( ! function_exists( 'strip_shortcodes' ) ) {
		function strip_shortcodes( $content ) {
			++$GLOBALS['__strip_calls'];
			$content = (string) preg_replace( '#\[([a-zA-Z0-9_\-]+)[^\]]*\](.*?)\[/\1\]#s', '', (string) $content );
			return (string) preg_replace( '#\[/?[a-zA-Z0-9_\-][^\]]*\]#', '', $content );
		}
	}
	/** Wierna atrapa wp_strip_all_tags() (ścieżkę zapasową pokrywa krok5-contentsource-test.php). */
	if ( ! function_exists( 'wp_strip_all_tags' ) ) {
		function wp_strip_all_tags( $text, $remove_breaks = false ) {
			$text = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text );
			$text = strip_tags( (string) $text );
			if ( $remove_breaks ) { $text = preg_replace( '/[\r\n\t ]+/', ' ', $text ); }
			return trim( (string) $text );
		}
	}

	$fail = 0;
	$ran  = 0;
	function check( $cond, $label ) {
		global $fail, $ran;
		++$ran;
		echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n";
		if ( ! $cond ) { $fail++; }
	}

	require __DIR__ . '/../src/Core/Settings.php';
	require __DIR__ . '/../src/Index/ContentSource.php';
	require __DIR__ . '/../src/Index/WpContentSource.php';
	$pm_file = __DIR__ . '/../src/Index/PostMetaContentSource.php';
	if ( is_file( $pm_file ) ) { require $pm_file; }

	use AIFAQ\Core\Settings;
	use AIFAQ\Index\WpContentSource;

	// -----------------------------------------------------------------------
	// Stan świata ustawiony RAZ, przed pierwszym is_indexable() (memoizacja!).
	// -----------------------------------------------------------------------
	$GLOBALS['__opt'] = array(
		'aifaq_page_id'                  => 5005,
		'woocommerce_cart_page_id'       => 5007,
		'woocommerce_checkout_page_id'   => 0,
		'woocommerce_myaccount_page_id'  => 0,
		'woocommerce_terms_page_id'      => 0,
		Settings::OPTION                 => array( 'crawl_exclude' => 'cennik, oferta, ' ),
	);
	$GLOBALS['__postdata'] = array(
		5001 => array( 'post_status' => 'publish', 'post_type' => 'page', 'post_name' => 'kontakt' ),
		5002 => array( 'post_status' => 'draft', 'post_type' => 'page', 'post_name' => 'szkic' ),
		5003 => array( 'post_status' => 'publish', 'post_type' => 'page', 'post_name' => 'tajne', 'post_password' => 'sekret' ),
		5004 => array( 'post_status' => 'publish', 'post_type' => 'attachment', 'post_name' => 'zdjecie' ),
		5005 => array( 'post_status' => 'publish', 'post_type' => 'page', 'post_name' => 'generator-faq' ),
		5006 => array( 'post_status' => 'publish', 'post_type' => 'page', 'post_name' => 'faqgenerator' ),
		5007 => array( 'post_status' => 'publish', 'post_type' => 'page', 'post_name' => 'koszyk' ),
		5008 => array( 'post_status' => 'publish', 'post_type' => 'page', 'post_name' => 'yoast' ),
		5009 => array( 'post_status' => 'publish', 'post_type' => 'page', 'post_name' => 'rankmath' ),
		5010 => array( 'post_status' => 'publish', 'post_type' => 'page', 'post_name' => 'seopress' ),
		5011 => array( 'post_status' => 'publish', 'post_type' => 'page', 'post_name' => 'aioseo' ),
		5012 => array( 'post_status' => 'publish', 'post_type' => 'page', 'post_name' => 'cennik' ),
		5013 => array( 'post_status' => 'publish', 'post_type' => 'page', 'post_name' => 'ofertowanie' ),
		5014 => array( 'post_status' => 'publish', 'post_type' => 'page', 'post_name' => 'filtrowana' ),
	);
	$GLOBALS['__meta'] = array(
		5008 => array( '_yoast_wpseo_meta-robots-noindex' => array( '1' ) ),
		5009 => array( 'rank_math_robots' => array( array( 'noindex', 'nofollow' ) ) ),
		5010 => array( '_seopress_robots_index' => array( 'yes' ) ),
		5011 => array( '_aioseo_robots_noindex' => array( '1' ) ),
	);
	$GLOBALS['__filter_cb']['aifaq_skip_post'] = static function ( $skip, $id = 0 ) {
		return 5014 === (int) $id ? true : $skip;
	};

	// =======================================================================
	echo "=== A. to_plain(): shortcode'y BRAMKUJĄCE — usuwane RAZEM Z TREŚCIĄ ===\n";
	if ( method_exists( 'AIFAQ\Index\WpContentSource', 'to_plain' ) ) {
		$gate = array(
			'[restrict]Sekretna tresc platna[/restrict]'                  => 'Sekretna tresc platna',
			'[RESTRICT]Wersalikami tez sekret[/RESTRICT]'                 => 'Wersalikami tez sekret',
			'[membership level="2"]Tylko dla czlonkow[/membership]'       => 'Tylko dla czlonkow',
			'[mepr-show rules="12"]Kurs premium MemberPress[/mepr-show]'  => 'Kurs premium MemberPress',
			'[s2If current_user_is(s2member_level1)]Tresc s2[/s2If]'      => 'Tresc s2',
			'[pmpro_member]Materialy PMPro[/pmpro_member]'                => 'Materialy PMPro',
			'[ihc-hide-block ihc_mb_type="show"]Blok IHC[/ihc-hide-block]' => 'Blok IHC',
			'[um_loggedin]Panel uzytkownika UM[/um_loggedin]'             => 'Panel uzytkownika UM',
			'[wcm_restrict plans="vip"]Plan VIP[/wcm_restrict]'           => 'Plan VIP',
			'[groups_member_content]Grupa zamknieta[/groups_member_content]' => 'Grupa zamknieta',
			'[swpm_protected]Chronione SWPM[/swpm_protected]'             => 'Chronione SWPM',
			'[vc_raw_html]JTNDZGl2JTNFYmFzZTY0JTNDJTJGZGl2JTNF[/vc_raw_html]' => 'JTNDZGl2JTNFYmFzZTY0',
			'[et_pb_code]var sekret = "x";[/et_pb_code]'                  => 'var sekret',
		);
		$leaked = array();
		foreach ( $gate as $src => $needle ) {
			$got = WpContentSource::to_plain( $src );
			if ( false !== mb_strpos( $got, $needle ) ) { $leaked[] = $needle; }
		}
		check( array() === $leaked, 'żaden z 13 shortcode\'ów bramkujących nie wypuścił treści (wyciekło: ' . count( $leaked ) . ')' );
		check( '' === trim( WpContentSource::to_plain( '[restrict]Sekret[/restrict]' ) ), 'sam [restrict] → pusty tekst' );
		$mix = WpContentSource::to_plain( 'Zapraszamy na zajęcia. [restrict]Hasło do kursu: ABC123[/restrict] Kontakt w sekretariacie.' );
		check( false === mb_strpos( $mix, 'ABC123' ), 'treść bramkowana usunięta ze środka akapitu' );
		check( false !== mb_strpos( $mix, 'Zapraszamy na zajęcia.' ) && false !== mb_strpos( $mix, 'Kontakt w sekretariacie.' ),
			'PARA POZYTYWNA: tekst dookoła bramki zachowany' );
		check( '' === trim( WpContentSource::to_plain( '[s2member]' ) ), 'wariant samozamykający bramki → pusto' );

		// ===================================================================
		echo "\n=== B. to_plain(): treść ZWYKŁYCH shortcode'ów PRZEŻYWA (sedno P1) ===\n";
		check( 'Tekst' === trim( WpContentSource::to_plain( '[et_pb_text]Tekst[/et_pb_text]' ) ), '[et_pb_text]Tekst[/et_pb_text] → "Tekst"' );
		$divi = WpContentSource::to_plain( '[et_pb_section][et_pb_row][et_pb_text]Nasze przedszkole działa od 2011 roku.[/et_pb_text][/et_pb_row][/et_pb_section]' );
		check( false !== mb_strpos( $divi, 'Nasze przedszkole działa od 2011 roku.' ), 'zagnieżdżone sekcje Divi → treść zachowana' );
		$vcb = WpContentSource::to_plain( '[vc_column_text]Zapisy trwają cały rok.[/vc_column_text]' );
		check( false !== mb_strpos( $vcb, 'Zapisy trwają cały rok.' ), 'WPBakery [vc_column_text] → treść zachowana' );

		echo "\n=== C. LICZNIK: strip_shortcodes() nie wolno wołać ANI RAZU (§3.1 pkt 3) ===\n";
		check( 0 === $GLOBALS['__strip_calls'], 'to_plain() nie wywołał strip_shortcodes() (wywołań: ' . $GLOBALS['__strip_calls'] . ')' );

		// ===================================================================
		echo "\n=== D. to_plain(): atrybuty shortcode'ów → osobne linie (próg 3 znaki) ===\n";
		$attr_keep = WpContentSource::to_plain( '[cena title="Czesne 450 zł" subtitle="Godziny 7:00-18:00"]' );
		check( false !== mb_strpos( $attr_keep, 'Czesne 450 zł' ), 'atrybut title (13 znaków) zachowany — v2 z progiem 20 go gubił' );
		check( false !== mb_strpos( $attr_keep, 'Godziny 7:00-18:00' ), 'atrybut subtitle zachowany' );
		check( false !== mb_strpos( $attr_keep, "\n" ), 'atrybuty rozdzielone znakiem nowej linii' );
		$attr_more = WpContentSource::to_plain( '[box heading="Rekrutacja" content="Trwa nabór" text="Zadzwoń" description="Grupy 3-latków" alt="Zdjęcie sali" button_text="Zapisz się" caption="Nasza kadra"]' );
		$missing = array();
		foreach ( array( 'Rekrutacja', 'Trwa nabór', 'Zadzwoń', 'Grupy 3-latków', 'Zdjęcie sali', 'Zapisz się', 'Nasza kadra' ) as $n ) {
			if ( false === mb_strpos( $attr_more, $n ) ) { $missing[] = $n; }
		}
		check( array() === $missing, 'wszystkie 7 pozostałych kluczy z listy §3.1 wyciągane (brakuje: ' . count( $missing ) . ')' );
		$attr_drop = WpContentSource::to_plain(
			'[x title="12" heading="https://example.test/strona" text="#a3f" alt="20px" caption="ab" content="AAAABBBBCCCCDDDDEEEEFFFFGGGGHHHHIIIIJJJJKKKK" rel="Nieznany klucz atrybutu"]'
		);
		$oops = array();
		foreach ( array( '12', 'https://example.test/strona', '#a3f', '20px', 'ab', 'AAAABBBBCCCC', 'Nieznany klucz atrybutu' ) as $n ) {
			if ( false !== mb_strpos( $attr_drop, $n ) ) { $oops[] = $n; }
		}
		check( array() === $oops, 'odsiane: liczby, URL, hex CSS, jednostki CSS, <3 znaki, base64, klucz spoza listy (przeszło: ' . count( $oops ) . ')' );

		echo "\n=== E. to_plain(): rozszerzona lista tagów blokowych (§3.1) ===\n";
		$blocks = array(
			'<a href="#">Kontakt</a><a href="#">Oferta</a>'            => array( 'Kontakt', 'Oferta', 'KontaktOferta' ),
			'<section>Alfa</section><article>Beta</article>'           => array( 'Alfa', 'Beta', 'AlfaBeta' ),
			'<header>Nagłówek</header><footer>Stopka</footer>'         => array( 'Nagłówek', 'Stopka', 'NagłówekStopka' ),
			'<nav>Menu</nav><ul><li>Jeden</li></ul>'                   => array( 'Menu', 'Jeden', 'MenuJeden' ),
			'<dl><dt>Termin</dt><dd>Opis</dd></dl>'                    => array( 'Termin', 'Opis', 'TerminOpis' ),
			'<figcaption>Podpis</figcaption><label>Etykieta</label>'   => array( 'Podpis', 'Etykieta', 'PodpisEtykieta' ),
			'<button>Wyślij</button><ol><li>Punkt</li></ol>'           => array( 'Wyślij', 'Punkt', 'WyślijPunkt' ),
		);
		$glued = array();
		foreach ( $blocks as $html => $spec ) {
			$got = WpContentSource::to_plain( $html );
			if ( false === mb_strpos( $got, $spec[0] ) || false === mb_strpos( $got, $spec[1] ) || false !== mb_strpos( $got, $spec[2] ) ) {
				$glued[] = $spec[2];
			}
		}
		check( array() === $glued, '7 par tagów blokowych rozdziela słowa i nie gubi treści (zlepione: ' . count( $glued ) . ')' );

	} else {
		check( false, 'SEKCJE A–E POMINIĘTE: brak WpContentSource::to_plain()' );
	}

	// =======================================================================
	echo "\n=== F. is_indexable(): 8 warunków z tabeli §2.2 ===\n";
	if ( method_exists( 'AIFAQ\Index\WpContentSource', 'is_indexable' ) ) {
		check( true === WpContentSource::is_indexable( 5001 ), 'PARA POZYTYWNA: zwykła opublikowana strona → true' );
		check( false === WpContentSource::is_indexable( 5002 ), '1) status inny niż publish → false' );
		check( false === WpContentSource::is_indexable( 5003 ), '2) wpis z hasłem → false' );
		check( false === WpContentSource::is_indexable( 5004 ), '3) typ attachment → false' );
		check( false === WpContentSource::is_indexable( 5005 ), '4a) podstrona wtyczki z opcji aifaq_page_id → false' );
		check( false === WpContentSource::is_indexable( 5006 ), '4b) post_name === Router::slug() → false' );
		check( false === WpContentSource::is_indexable( 5007 ), '5) strona koszyka WooCommerce → false' );
		check( false === WpContentSource::is_indexable( 5008 ), '6a) noindex Yoast → false' );
		check( false === WpContentSource::is_indexable( 5009 ), '6b) noindex Rank Math (tablica) → false' );
		check( false === WpContentSource::is_indexable( 5010 ), '6c) noindex SEOPress → false' );
		check( false === WpContentSource::is_indexable( 5011 ), '6d) noindex AIOSEO → false' );
		check( false === WpContentSource::is_indexable( 5012 ), '7) slug na liście crawl_exclude → false' );
		check( true === WpContentSource::is_indexable( 5013 ), '7-para) slug "ofertowanie" NIE pasuje do "oferta" (pełny segment, nie fragment)' );
		check( false === WpContentSource::is_indexable( 5014 ), '8) filtr aifaq_skip_post → false' );

		echo "\n=== G. is_indexable(): memoizacja per post_id ===\n";
		$before = $GLOBALS['__status_calls'][5001] ?? 0;
		WpContentSource::is_indexable( 5001 );
		WpContentSource::is_indexable( 5001 );
		check( ( $GLOBALS['__status_calls'][5001] ?? 0 ) === $before, 'kolejne wywołania nie odpytują WP ponownie (memoizacja §2.2)' );
		check( 1 === $before, 'pierwszy przebieg odpytał get_post_status() dokładnie raz' );

		echo "\n=== H. crawl_exclude: pusty token nie wyklucza całej witryny ===\n";
		// Lista w opcji kończy się przecinkiem („cennik, oferta, ") — pusty token
		// odrzucany BEZWARUNKOWO, inaczej wykluczyłby wszystko (§3.4).
		check( true === WpContentSource::is_indexable( 5001 ), 'strona spoza listy nadal indeksowalna mimo końcowego przecinka' );
	} else {
		check( false, 'SEKCJE F–H POMINIĘTE: brak WpContentSource::is_indexable() (Etap 1)' );
	}

	// =======================================================================
	echo "\n=== I. PostMetaContentSource (§3.2) ===\n";
	if ( class_exists( 'AIFAQ\Index\PostMetaContentSource' ) ) {

		$mk = static function ( $id ) { $o = new stdClass(); $o->ID = $id; $o->post_content = ''; return $o; };

		$GLOBALS['__posts_by_type'] = array(
			'page'  => array( $mk( 6001 ), $mk( 6002 ), $mk( 6200 ), $mk( 6300 ) ),
			'post'  => array( $mk( 6003 ) ),
			'kadra' => array( $mk( 6100 ) ),
		);
		foreach ( array( 6001, 6002, 6003, 6100, 6200 ) as $id ) {
			$GLOBALS['__postdata'][ $id ] = array( 'post_status' => 'publish', 'post_type' => ( 6003 === $id ? 'post' : 'page' ), 'post_name' => 'strona-' . $id );
		}
		$GLOBALS['__postdata'][6300] = array( 'post_status' => 'draft', 'post_type' => 'page', 'post_name' => 'szkic-meta' );

		$bio_6001    = 'Anna Kowalska prowadzi zajęcia muzyczne od dwunastu lat.';
		$opis_6001   = 'Grupa Motylki to dzieci w wieku od trzech do czterech lat.';
		$tresc_6001  = 'Zajęcia rytmiki odbywają się w każdy wtorek o dziesiątej.';
		$kadra0_6002 = 'Marek Nowak uczy gry na pianinie oraz prowadzi chór dziecięcy.';
		$kadra1_6002 = 'Ewa Zielińska odpowiada za zajęcia plastyczne i wycieczki.';
		$desc_6003   = 'Opis wpisu na blogu o zajęciach muzycznych dla dzieci.';
		$bio_6100    = 'Biogram domyślny z listy DEFAULT_KEYS działa również tutaj.';
		$notat_6100  = 'Notatka redakcyjna o wydarzeniu przedszkolnym w czerwcu.';

		// UWAGA: unia `+`, nie array_merge() — array_merge PRZENUMEROWAŁBY klucze liczbowe.
		$GLOBALS['__meta'] = array(
			6001 => array(
				'bio'                   => array( $bio_6001 ),
				'opis'                  => array( $opis_6001 ),
				'zajecia_tresc'         => array( $tresc_6001 ),
				'_ukryty_opis'          => array( 'To nie powinno trafić do bazy wiedzy nigdy przenigdy.' ),
				'rank_math_description' => array( 'Opis SEO dla wyszukiwarek Google oraz innych robotów.' ),
				'_yoast_wpseo_metadesc' => array( 'Meta opis Yoast, który też nie jest treścią strony.' ),
				'panels_data'           => array( 'a:1:{s:4:"grid";s:4:"dane";}' ),
				'kadra_opis'            => array( 'a:2:{i:0;s:5:"pierw";i:1;s:5:"drugi";}' ),
				'kontakt_text'          => array( 'https://example.test/bardzo/dluga/sciezka/kontaktowa' ),
				'numer_text'            => array( '1234567890123456789012' ),
				'telefon_opis'          => array( '+48 123 456 789 (022) 33' ),
				'krotki_opis'           => array( 'Za krótkie' ),
				'tablica_bio'           => array( array( 'wartość', 'nieskalarna' ) ),
			),
			6002 => array(
				'kadra_1_bio'     => array( $kadra1_6002 ),
				'kadra_0_bio'     => array( $kadra0_6002 ),
				'kadra_0_zdjecie' => array( '987654321098765432109' ),
			),
			6003 => array( 'description' => array( $desc_6003 ) ),
			6100 => array( 'notatka' => array( $notat_6100 ), 'bio' => array( $bio_6100 ) ),
			6200 => array(),
			6300 => array( 'bio' => array( 'Biogram na szkicu nie może trafić do bazy wiedzy.' ) ),
		) + $GLOBALS['__meta'];

		$PM = 'AIFAQ\Index\PostMetaContentSource';
		check( in_array( 'bio', constant( $PM . '::DEFAULT_KEYS' ), true ) && in_array( 'opis', constant( $PM . '::DEFAULT_KEYS' ), true ),
			'DEFAULT_KEYS zawiera bio i opis' );
		check( in_array( 'rank_math_description', constant( $PM . '::DENY_KEYS' ), true ),
			'DENY_KEYS zawiera rank_math_description (klucz BEZ podkreślnika)' );

		$GLOBALS['__get_posts_args'] = array();
		$src  = new AIFAQ\Index\PostMetaContentSource();
		$docs = $src->documents();

		$asked = array();
		foreach ( $GLOBALS['__get_posts_args'] as $a ) {
			$t = $a['post_type'] ?? '';
			if ( ! is_array( $t ) ) { $t = array_filter( array_map( 'trim', explode( ',', (string) $t ) ), 'strlen' ); }
			foreach ( $t as $one ) { $asked[ $one ] = true; }
		}
		$asked = array_keys( $asked );
		sort( $asked );
		check( array( 'page', 'post' ) === $asked, 'domyślnie pyta WYŁĄCZNIE o post+page (§1 reguła 5), zapytano o: ' . implode( ',', $asked ) );

		$by_id = array();
		foreach ( $docs as $d ) { $by_id[ (int) $d['post_id'] ] = $d; }
		check( 3 === count( $docs ), '3 dokumenty: 6001, 6002, 6003 (6200 bez meta i 6300 szkic odpadają) — jest ' . count( $docs ) );
		check( isset( $by_id[6001], $by_id[6002], $by_id[6003] ), 'w wyniku dokładnie oczekiwane post_id' );
		check( ! isset( $by_id[6200] ), 'wpis bez pasującej postmeta pominięty' );
		check( ! isset( $by_id[6300] ), 'wpis w statusie draft odsiany przez is_indexable()' );

		$expect_6001 = $bio_6001 . "\n" . $opis_6001 . "\n" . $tresc_6001;
		check( isset( $by_id[6001] ) && $expect_6001 === trim( $by_id[6001]['text'] ),
			'pola sklejone ALFABETYCZNIE po kluczu (bio, opis, zajecia_tresc), separator "\\n"' );

		$expect_6002 = $kadra0_6002 . "\n" . $kadra1_6002;
		check( isset( $by_id[6002] ) && $expect_6002 === trim( $by_id[6002]['text'] ),
			'ACF: kadra_0_bio i kadra_1_bio dopasowane po odcięciu segmentów numerycznych, kolejność alfabetyczna' );

		$all_text = '';
		foreach ( $docs as $d ) { $all_text .= "\n" . $d['text']; }
		$rejected = array(
			'klucz na podkreślniku'  => 'To nie powinno trafić',
			'DENY rank_math'         => 'Opis SEO dla wyszukiwarek',
			'DENY _yoast_metadesc'   => 'Meta opis Yoast',
			'serializowane'          => 'a:2:{i:0;',
			'URL'                    => 'https://example.test/bardzo',
			'czysto numeryczne'      => '1234567890123456789012',
			'telefon'                => '+48 123 456 789 (022) 33',
			'krótsze niż 20 znaków'  => 'Za krótkie',
			'nieskalarne'            => 'nieskalarna',
			'liczba w polu ACF'      => '987654321098765432109',
		);
		$leaks = array();
		foreach ( $rejected as $why => $needle ) {
			if ( false !== mb_strpos( $all_text, $needle ) ) { $leaks[] = $why; }
		}
		check( array() === $leaks, '10 reguł odrzucania działa (przeciekło: ' . implode( ', ', $leaks ) . ')' );
		check( false !== mb_strpos( $all_text, $desc_6003 ),
			'PARA POZYTYWNA: klucz "description" BEZ podkreślnika i spoza DENY_KEYS przechodzi' );

		echo "\n=== J. PostMetaContentSource: typy i klucze z konstruktora ===\n";
		$GLOBALS['__get_posts_args'] = array();
		$src2  = new AIFAQ\Index\PostMetaContentSource( array( 'kadra' ), array( 'notatka' ) );
		$docs2 = $src2->documents();
		$asked2 = array();
		foreach ( $GLOBALS['__get_posts_args'] as $a ) {
			$t = $a['post_type'] ?? '';
			if ( ! is_array( $t ) ) { $t = array_filter( array_map( 'trim', explode( ',', (string) $t ) ), 'strlen' ); }
			foreach ( $t as $one ) { $asked2[ $one ] = true; }
		}
		check( array( 'kadra' ) === array_keys( $asked2 ), 'atrapa get_posts rozgałęzia po typie — zapytano wyłącznie o "kadra"' );
		check( 1 === count( $docs2 ), 'jeden dokument z typu kadra' );
		check( isset( $docs2[0] ) && ( $bio_6100 . "\n" . $notat_6100 ) === trim( $docs2[0]['text'] ),
			'klucz z konstruktora DODANY do DEFAULT_KEYS (bio + notatka), nie zastępuje ich' );
		check( isset( $docs2[0] ) && 6100 === (int) $docs2[0]['post_id'] && '' !== (string) $docs2[0]['title'] && '' !== (string) $docs2[0]['url'],
			'dokument ma wypełnione post_id/title/url (§2.1)' );

		echo "\n=== K. PostMetaContentSource: brak postmeta → pusta lista, zero fatali ===\n";
		$src3  = new AIFAQ\Index\PostMetaContentSource( array( 'nieistniejacy_typ' ) );
		$docs3 = $src3->documents();
		check( array() === $docs3, 'typ bez wpisów → pusta tablica' );
		$GLOBALS['__meta'][6100] = array();
		$src4  = new AIFAQ\Index\PostMetaContentSource( array( 'kadra' ) );
		check( array() === $src4->documents(), 'wpis bez żadnej postmeta → pusta tablica (bez fatala)' );
	} else {
		check( false, 'SEKCJE I–K POMINIĘTE: brak klasy AIFAQ\Index\PostMetaContentSource (Etap 1)' );
	}

	echo "\n=== L. Licznik strip_shortcodes() po CAŁYM pliku ===\n";
	check( 0 === $GLOBALS['__strip_calls'], 'strip_shortcodes() nie zostało wywołane ANI RAZU w całym potoku (' . $GLOBALS['__strip_calls'] . ')' );

	// -----------------------------------------------------------------------
	echo "\n=== PODŁOGA ASERCJI ===\n";
	$done = $ran;
	check( $done >= 45, 'wykonano co najmniej 45 asercji (było: ' . $done . ')' );

	echo "\n=== PODSUMOWANIE ===\n";
	echo ( 0 === $fail ) ? "TEST KROK 17 (źródła bazodanowe): WSZYSTKIE ASERCJE OK ($ran)\n" : "TEST KROK 17 (źródła bazodanowe): $fail ASERCJI NIE PRZESZŁO (z $ran)\n";
	exit( 0 === $fail ? 0 : 1 );
}
