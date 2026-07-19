<?php
/**
 * Źródło treści oparte na WordPressie.
 *
 * Pobiera opublikowane wpisy wskazanych typów (domyślnie `post` + `page`)
 * i zamienia ich SUROWĄ treść (`post_content`, bez `apply_filters('the_content')`)
 * na zwykły tekst, gotowy do chunkowania i embedowania.
 *
 * Klasa niesie też wspólną bramkę indeksowania `is_indexable()` — jedno miejsce,
 * w którym decydujemy „czy ten wpis w ogóle wolno wpuścić do bazy wiedzy”.
 * Wymuszana jest strukturalnie przez `CompositeContentSource` (KONTRAKT §3.6).
 *
 * Cała klasa działa w czystym PHP CLI: każde wołanie funkcji WordPressa jest
 * obudowane `function_exists()`, każde odwołanie do innej klasy — `class_exists()`.
 *
 * @package AI_FAQ_Generator
 */

namespace AIFAQ\Index;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Źródło treści z WordPressa.
 */
class WpContentSource implements ContentSource {

	/**
	 * Shortcode'y „bramkujące" — usuwane RAZEM Z ZAWARTOŚCIĄ.
	 *
	 * Wtyczki członkowskie chowają treść płatną shortcode'em wprost w `post_content`
	 * (`[restrict]sekret[/restrict]`). Zdjęcie samych nawiasów zamieniłoby sekret
	 * w zwykły tekst i wpuściło go do PUBLICZNEGO czatbota (KONTRAKT §1 pkt 3).
	 * `vc_raw_html`/`et_pb_code` są na liście, bo zawierają base64/JS, nie prozę.
	 *
	 * Dopasowanie nazwy bez rozróżniania wielkości liter; dla rodzin
	 * `mepr-*`, `pmpro_*`, `ihc-*`, `um_*` — także prefiksowo.
	 *
	 * @var array<int,string>
	 */
	public const GATE_SHORTCODES = array(
		'restrict',
		'membership',
		'private',
		'members',
		'member',
		'mepr-active',
		'mepr-unauthorized',
		'mepr-show',
		'mepr-hide',
		's2if',
		's2hide',
		's2member',
		'pmpro_member',
		'pmpro_has_membership_level',
		'ihc-hide-block',
		'ihc-user-status',
		'um_loggedin',
		'um_loggedout',
		'wcm_restrict',
		'groups_member_content',
		'swpm_protected',
		'vc_raw_html',
		'vc_raw_js',
		'et_pb_code',
		'et_pb_fullwidth_code',
	);

	/**
	 * Rodziny shortcode'ów bramkujących dopasowywane prefiksowo.
	 *
	 * @var array<int,string>
	 */
	private const GATE_PREFIXES = array( 'mepr-', 'pmpro_', 'ihc-', 'um_' );

	/**
	 * Klucze atrybutów shortcode'ów, których wartości niosą treść dla człowieka.
	 *
	 * @var array<int,string>
	 */
	private const ATT_KEYS = array(
		'button_text',
		'description',
		'subtitle',
		'heading',
		'caption',
		'content',
		'title',
		'text',
		'alt',
	);

	/**
	 * Opcje WordPressa wskazujące systemowe strony WooCommerce.
	 *
	 * Koszyk/zamówienie/moje konto/regulamin mają status `publish`, więc bez tej
	 * listy przechodziłyby przez bramkę (KONTRAKT §2.2 warunek 5).
	 *
	 * @var array<int,string>
	 */
	private const WOO_PAGE_OPTIONS = array(
		'woocommerce_cart_page_id',
		'woocommerce_checkout_page_id',
		'woocommerce_myaccount_page_id',
		'woocommerce_terms_page_id',
	);

	/**
	 * Memoizacja wyniku `is_indexable()` per `post_id`.
	 *
	 * Bez tego test skali (400 wpisów) robi tysiące zapytań do bazy.
	 *
	 * @var array<int,bool>
	 */
	private static array $indexable_cache = array();

	/**
	 * Typy wpisów do indeksowania.
	 *
	 * @var array<int,string>
	 */
	private array $post_types;

	/**
	 * Konstruktor.
	 *
	 * @param array<int,string> $post_types Typy wpisów (domyślnie post + page).
	 */
	public function __construct( array $post_types = array( 'post', 'page' ) ) {
		$this->post_types = $post_types;
	}

	/**
	 * Zwraca opublikowane wpisy jako dokumenty (pomija te bez treści tekstowej).
	 *
	 * @return array<int,array{post_id:int,title:string,url:string,text:string}>
	 */
	public function documents(): array {
		if ( ! function_exists( 'get_posts' ) ) {
			return array();
		}

		$posts = get_posts(
			array(
				'post_type'        => $this->post_types,
				'post_status'      => 'publish',
				'numberposts'      => -1,
				'has_password'     => false, // nie indeksuj treści chronionej hasłem.
				// M14: indeks ma objąć CAŁĄ opublikowaną treść niezależnie od filtrów
				// wtyczek (WPML/Polylang zawężają do bieżącego języka). Bez tego
				// pruning kasowałby przy każdym reindeksie wpisy pozostałych języków.
				'suppress_filters' => true,
				'orderby'          => 'ID',
				'order'            => 'ASC',
			)
		);

		$docs = array();

		foreach ( (array) $posts as $post ) {
			$post_id = self::post_id_of( $post );
			if ( $post_id <= 0 ) {
				continue;
			}

			$text = self::to_plain( (string) ( is_object( $post ) && isset( $post->post_content ) ? $post->post_content : '' ) );
			if ( '' === $text ) {
				continue; // wpis bez treści tekstowej (np. sama galeria) — pomijamy.
			}

			$docs[] = array(
				'post_id' => $post_id,
				'title'   => self::title_of( $post_id ),
				'url'     => self::url_of( $post_id ),
				'text'    => $text,
			);
		}

		return $docs;
	}

	/**
	 * Zamienia HTML wpisu na zwykły tekst.
	 *
	 * Kolejność (KONTRAKT §3.1):
	 * 0. komentarze bloków Gutenberga precz;
	 * 1. shortcode'y bramkujące precz RAZEM Z ZAWARTOŚCIĄ;
	 * 2. atrybuty pozostałych shortcode'ów → osobne linie;
	 * 3. pozostałe znaczniki `[...]` precz, tekst pomiędzy nimi zostaje;
	 * 4. tagi blokowe → złamania linii, reszta tagów precz, encje, białe znaki.
	 *
	 * `strip_shortcodes()` NIE MOŻE tu paść ani razu — usuwa treść Divi/WPBakery
	 * razem z zawartością (druga przyczyna problemu P1).
	 *
	 * @param string $html Surowa treść wpisu (`post_content`).
	 * @return string Zwykły tekst (może być pusty).
	 */
	public static function to_plain( string $html ): string {
		// 0. Komentarze bloków Gutenberga: <!-- wp:paragraph --> itd.
		$html = self::re( '/<!--.*?-->/s', ' ', $html );

		// 1. Shortcode'y bramkujące — precz razem z zawartością.
		$html = self::strip_gated_shortcodes( $html );

		// 2. Atrybuty pozostałych shortcode'ów → osobne linie (znacznik znika).
		$html = self::shortcodes_to_lines( $html );

		// 3. Cokolwiek w nawiasach kwadratowych zostało — precz; tekst pomiędzy zostaje.
		$html = self::re( '/\[[^\]]*\]/', "\n", $html );

		// 4a. Tagi blokowe i komórki tabel → złamanie linii, żeby fakty się nie zlepiły
		// („<td>Poniedziałek</td><td>7:00</td>" nie może dać „Poniedziałek7:00").
		// Lista rozszerzona pod treść z szablonu motywu (nie tylko Gutenberga).
		$html = self::re(
			'#<(br|/p|/div|/h[1-6]|/li|/tr|/td|/th|/blockquote|/a|/section|/article|/header|/footer|/nav|/ul|/ol|/dt|/dd|/figcaption|/label|/button)(?=[\s/>])[^>]*>#i',
			"\n",
			$html
		);

		// 4b. Pozostałe tagi precz.
		$text = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $html ) : strip_tags( $html );

		// 4c. Encje HTML → znaki (&amp; → &, &nbsp; → spacja itd.).
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = str_replace( "\xC2\xA0", ' ', $text ); // twarda spacja → zwykła.

		// 4d. Normalizacja białych znaków.
		$text = self::re( '/[ \t]+/u', ' ', $text );
		$text = self::re( '/\s*\n\s*/u', "\n", $text );
		$text = self::re( '/\n{2,}/u', "\n", $text );

		return trim( $text );
	}

	/**
	 * Wspólna bramka: czy wpis wolno wpuścić do bazy wiedzy?
	 *
	 * Warunki i użyte funkcje WP — KONTRAKT §2.2 (powierzchnia mockowania ZAMROŻONA).
	 * Brak funkcji WP albo klasy z innego etapu → dany warunek jest POMIJANY,
	 * nigdy fatal i nigdy „false na wszelki wypadek".
	 *
	 * @param int $post_id Identyfikator wpisu.
	 * @return bool `true`, gdy wpis nadaje się do indeksowania.
	 */
	public static function is_indexable( int $post_id ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}
		if ( isset( self::$indexable_cache[ $post_id ] ) ) {
			return self::$indexable_cache[ $post_id ];
		}

		try {
			$result = self::compute_indexable( $post_id );
		} catch ( \Throwable $e ) {
			$result = true; // awaria warunku nie może wygaszać całego indeksu.
		}

		self::$indexable_cache[ $post_id ] = $result;
		return $result;
	}

	/**
	 * Czyści memoizację bramki (przydatne w testach i po zmianie ustawień).
	 */
	public static function reset_indexable_cache(): void {
		self::$indexable_cache = array();
	}

	// -----------------------------------------------------------------------
	// Bramka — części składowe.
	// -----------------------------------------------------------------------

	/**
	 * Wylicza wynik bramki (bez memoizacji).
	 *
	 * @param int $post_id Identyfikator wpisu.
	 * @return bool
	 */
	protected static function compute_indexable( int $post_id ): bool {
		// 1. Status musi być `publish`.
		if ( function_exists( 'get_post_status' ) ) {
			$status = get_post_status( $post_id );
			if ( ! is_string( $status ) || 'publish' !== $status ) {
				return false;
			}
		}

		// 2. Wpis chroniony hasłem.
		if ( function_exists( 'get_post_field' ) ) {
			$password = get_post_field( 'post_password', $post_id );
			if ( is_scalar( $password ) && '' !== trim( (string) $password ) ) {
				return false;
			}
		}

		// 3. Załączniki (pliki) nie są treścią.
		if ( function_exists( 'get_post_type' ) ) {
			if ( 'attachment' === get_post_type( $post_id ) ) {
				return false;
			}
		}

		// 4. Własna podstrona wtyczki (generator nie indeksuje sam siebie).
		if ( self::is_own_page( $post_id ) ) {
			return false;
		}

		// 5. Systemowe strony WooCommerce (koszyk, zamówienie, moje konto, regulamin).
		if ( function_exists( 'get_option' ) ) {
			foreach ( self::WOO_PAGE_OPTIONS as $option ) {
				if ( (int) get_option( $option ) === $post_id ) {
					return false;
				}
			}
		}

		// 6. `noindex` ustawiony w jednej z czterech wtyczek SEO.
		if ( self::has_seo_noindex( $post_id ) ) {
			return false;
		}

		// 7. Slug na liście wykluczeń właściciela (dotyczy WSZYSTKICH źródeł, nie tylko crawla).
		if ( self::is_excluded_slug( $post_id ) ) {
			return false;
		}

		// 8. Ostatnie słowo ma filtr.
		if ( function_exists( 'apply_filters' ) ) {
			if ( true === (bool) apply_filters( 'aifaq_skip_post', false, $post_id ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Czy wpis jest własną podstroną wtyczki?
	 *
	 * @param int $post_id Identyfikator wpisu.
	 * @return bool
	 */
	protected static function is_own_page( int $post_id ): bool {
		if ( function_exists( 'get_option' ) ) {
			$page_id = (int) get_option( 'aifaq_page_id' );
			if ( $page_id > 0 && $page_id === $post_id ) {
				return true;
			}
		}

		if ( ! function_exists( 'get_post_field' ) ) {
			return false;
		}
		if ( ! class_exists( '\AIFAQ\Core\Router' ) || ! method_exists( '\AIFAQ\Core\Router', 'slug' ) ) {
			return false;
		}

		try {
			$slug = (string) \AIFAQ\Core\Router::slug();
		} catch ( \Throwable $e ) {
			return false;
		}
		if ( '' === $slug ) {
			return false;
		}

		$name = get_post_field( 'post_name', $post_id );
		$name = is_scalar( $name ) ? (string) $name : '';

		return ( '' !== $name && strtolower( $name ) === strtolower( $slug ) );
	}

	/**
	 * Czy któraś z czterech wtyczek SEO oznaczyła wpis jako `noindex`?
	 *
	 * @param int $post_id Identyfikator wpisu.
	 * @return bool
	 */
	protected static function has_seo_noindex( int $post_id ): bool {
		if ( ! function_exists( 'get_post_meta' ) ) {
			return false;
		}

		// Yoast: 1 = noindex, 2 = index (dlatego porównanie ścisłe, nie „truthy").
		$yoast = get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true );
		if ( is_scalar( $yoast ) && '1' === trim( (string) $yoast ) ) {
			return true;
		}

		// Rank Math: tablica dyrektyw, np. array( 'noindex', 'nofollow' ).
		$rank_math = get_post_meta( $post_id, 'rank_math_robots', true );
		if ( is_array( $rank_math ) ) {
			foreach ( $rank_math as $directive ) {
				if ( is_scalar( $directive ) && 'noindex' === strtolower( trim( (string) $directive ) ) ) {
					return true;
				}
			}
		} elseif ( is_string( $rank_math ) && false !== stripos( $rank_math, 'noindex' ) ) {
			return true;
		}

		// SEOPress: 'yes' = „nie indeksuj tej strony".
		$seopress = get_post_meta( $post_id, '_seopress_robots_index', true );
		if ( is_scalar( $seopress ) && self::is_truthy( (string) $seopress ) ) {
			return true;
		}

		// All in One SEO.
		$aioseo = get_post_meta( $post_id, '_aioseo_robots_noindex', true );
		if ( is_scalar( $aioseo ) && self::is_truthy( (string) $aioseo ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Czy slug wpisu jest na liście wykluczeń (`crawl_exclude`)?
	 *
	 * Puste tokeny odrzucane bezwarunkowo (końcowy przecinek wykluczyłby całą
	 * witrynę). Dopasowanie po PEŁNYM segmencie ścieżki, nigdy po fragmencie.
	 *
	 * @param int $post_id Identyfikator wpisu.
	 * @return bool
	 */
	protected static function is_excluded_slug( int $post_id ): bool {
		if ( ! function_exists( 'get_post_field' ) ) {
			return false;
		}
		if ( ! class_exists( '\AIFAQ\Core\Settings' ) || ! method_exists( '\AIFAQ\Core\Settings', 'get_field' ) ) {
			return false;
		}

		try {
			$raw = (string) \AIFAQ\Core\Settings::get_field( 'crawl_exclude', '' );
		} catch ( \Throwable $e ) {
			return false;
		}
		if ( '' === trim( $raw ) ) {
			return false;
		}

		$tokens = array_filter( array_map( 'trim', explode( ',', $raw ) ), 'strlen' );
		if ( array() === $tokens ) {
			return false;
		}

		$name = get_post_field( 'post_name', $post_id );
		$name = is_scalar( $name ) ? strtolower( trim( (string) $name ) ) : '';
		if ( '' === $name ) {
			return false;
		}

		foreach ( $tokens as $token ) {
			$token = strtolower( trim( (string) $token, "/ \t\n\r\0\x0B" ) );
			if ( '' === $token ) {
				continue;
			}
			// Token może być ścieżką („oferta/przedszkole") — bierzemy ostatni segment.
			$segments = array_values( array_filter( explode( '/', $token ), 'strlen' ) );
			if ( array() === $segments ) {
				continue;
			}
			if ( $name === (string) end( $segments ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Czy wartość meta znaczy „tak"?
	 *
	 * @param string $value Wartość.
	 * @return bool
	 */
	private static function is_truthy( string $value ): bool {
		$value = strtolower( trim( $value ) );
		return in_array( $value, array( '1', 'yes', 'true', 'on' ), true );
	}

	// -----------------------------------------------------------------------
	// Shortcode'y.
	// -----------------------------------------------------------------------

	/**
	 * Usuwa shortcode'y bramkujące RAZEM Z ICH ZAWARTOŚCIĄ.
	 *
	 * @param string $html Treść.
	 * @return string
	 */
	protected static function strip_gated_shortcodes( string $html ): string {
		$alt = self::gate_alternation();

		// Warianty parzyste: [tag ...]cokolwiek[/tag] — także zagnieżdżone (kilka przebiegów).
		$pair = '#\[(' . $alt . ')(?=[\s\]/])[^\]]*\](.*?)\[/\1\]#is';
		for ( $pass = 0; $pass < 5; $pass++ ) {
			$next = preg_replace( $pair, "\n", $html );
			if ( null === $next || $next === $html ) {
				break;
			}
			$html = (string) $next;
		}

		// Warianty samozamykające i niesparowane resztki (sam znacznik, bez treści).
		return self::re( '#\[/?(' . $alt . ')(?=[\s\]/])[^\]]*\]#i', "\n", $html );
	}

	/**
	 * Zamienia pozostałe shortcode'y na osobne linie z ich „ludzkimi" atrybutami.
	 *
	 * @param string $html Treść.
	 * @return string
	 */
	protected static function shortcodes_to_lines( string $html ): string {
		$out = preg_replace_callback(
			'/\[[^\[\]]*\]/',
			static function ( array $matches ): string {
				$values = self::shortcode_att_values( $matches[0] );
				if ( array() === $values ) {
					return "\n";
				}
				return "\n" . implode( "\n", $values ) . "\n";
			},
			$html
		);

		return ( null === $out ) ? $html : (string) $out;
	}

	/**
	 * Wyciąga z pojedynczego znacznika wartości atrybutów niosących treść.
	 *
	 * @param string $tag Znacznik wraz z nawiasami, np. `[promo title="Czesne 450 zł"]`.
	 * @return array<int,string>
	 */
	protected static function shortcode_att_values( string $tag ): array {
		$pattern = '/(?<![\w\-])(' . implode( '|', self::ATT_KEYS ) . ')\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s\]]+))/i';

		if ( ! preg_match_all( $pattern, $tag, $matches, PREG_SET_ORDER ) ) {
			return array();
		}

		$values = array();
		foreach ( $matches as $match ) {
			$value = '';
			if ( isset( $match[2] ) && '' !== $match[2] ) {
				$value = $match[2];
			} elseif ( isset( $match[3] ) && '' !== $match[3] ) {
				$value = $match[3];
			} elseif ( isset( $match[4] ) ) {
				$value = $match[4];
			}

			$value = trim( $value );
			if ( self::att_value_useful( $value ) ) {
				$values[] = $value;
			}
		}

		return $values;
	}

	/**
	 * Czy wartość atrybutu jest treścią dla człowieka?
	 *
	 * Próg 3 znaki (nie 20 — „Czesne 450 zł" ma 13), z odsiewem liczb, URL-i,
	 * ciągów hex/base64 i wartości CSS (KONTRAKT §3.1 krok 2).
	 *
	 * @param string $value Wartość atrybutu.
	 * @return bool
	 */
	protected static function att_value_useful( string $value ): bool {
		$value = trim( $value );
		if ( '' === $value ) {
			return false;
		}

		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
		if ( $length < 3 ) {
			return false;
		}
		if ( is_numeric( $value ) ) {
			return false;
		}
		if ( preg_match( '#^(https?://|//|www\.)#i', $value ) ) {
			return false;
		}
		if ( preg_match( '#^[A-Za-z0-9+/=]{40,}$#', $value ) ) {
			return false;
		}
		if ( preg_match( '#^\#?[0-9a-f]{3,8}$#i', $value ) ) {
			return false;
		}
		if ( preg_match( '#^\d+(\.\d+)?(px|em|rem|%|vh|vw)$#i', $value ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Buduje alternatywę regexa dla nazw shortcode'ów bramkujących.
	 *
	 * @return string
	 */
	private static function gate_alternation(): string {
		static $alternation = null;
		if ( null !== $alternation ) {
			return $alternation;
		}

		$parts = array();
		foreach ( self::GATE_PREFIXES as $prefix ) {
			$parts[] = preg_quote( $prefix, '#' ) . '[a-z0-9_\-]*';
		}
		foreach ( self::GATE_SHORTCODES as $name ) {
			$parts[] = preg_quote( $name, '#' );
		}

		$alternation = implode( '|', $parts );
		return $alternation;
	}

	// -----------------------------------------------------------------------
	// Drobiazgi.
	// -----------------------------------------------------------------------

	/**
	 * `preg_replace` odporny na porażkę (np. niepoprawne UTF-8 przy modyfikatorze `u`).
	 *
	 * Bez tego `null` z preg_replace rzutowany na string kasowałby CAŁĄ treść wpisu.
	 *
	 * @param string $pattern     Wzorzec.
	 * @param string $replacement Zamiennik.
	 * @param string $subject     Tekst wejściowy.
	 * @return string
	 */
	private static function re( string $pattern, string $replacement, string $subject ): string {
		$out = preg_replace( $pattern, $replacement, $subject );
		return ( null === $out ) ? $subject : (string) $out;
	}

	/**
	 * Wyciąga `post_id` z obiektu/tablicy/liczby zwróconej przez `get_posts()`.
	 *
	 * @param mixed $post Wpis.
	 * @return int
	 */
	private static function post_id_of( $post ): int {
		if ( is_object( $post ) && isset( $post->ID ) ) {
			return (int) $post->ID;
		}
		if ( is_array( $post ) && isset( $post['ID'] ) ) {
			return (int) $post['ID'];
		}
		if ( is_numeric( $post ) ) {
			return (int) $post;
		}
		return 0;
	}

	/**
	 * Tytuł wpisu (z zapasem, gdy WordPressa nie ma).
	 *
	 * @param int $post_id Identyfikator wpisu.
	 * @return string
	 */
	private static function title_of( int $post_id ): string {
		if ( function_exists( 'get_the_title' ) ) {
			$title = get_the_title( $post_id );
			return is_scalar( $title ) ? (string) $title : '';
		}
		return '';
	}

	/**
	 * Adres wpisu (z zapasem, gdy WordPressa nie ma).
	 *
	 * @param int $post_id Identyfikator wpisu.
	 * @return string
	 */
	private static function url_of( int $post_id ): string {
		if ( function_exists( 'get_permalink' ) ) {
			$url = get_permalink( $post_id );
			return is_scalar( $url ) ? (string) $url : '';
		}
		return '';
	}
}
