<?php
/**
 * Źródło treści z WYRENDEROWANYCH stron (crawl jako gość).
 *
 * Trzecie źródło kaskady Kroku 17. Odpowiada za treść, której w bazie NIE MA
 * w żadnej postaci — wpisaną wprost w szablony motywu (godziny otwarcia, cennik,
 * bloki „o nas" w `header.php`/`front-page.php`). Pomiar rozpoznania na
 * `dworek.local`: `post_content` ~4 200 znaków, postmeta +9 700, wyrenderowany
 * HTML ~41 000 (5 z 7 pytań kontrolnych osiągalnych wyłącznie tą drogą).
 *
 * Klasa sama NIGDY nie wykonuje żądań HTTP — pobieraniem zajmuje się
 * {@see CrawlQueue} w tle (cron), a tutaj tylko odczytujemy to, co kolejka już
 * zapisała w postmeta `_aifaq_rendered`.
 *
 * @package AI_FAQ_Generator
 */

namespace AIFAQ\Index;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Źródło treści z wyrenderowanych stron witryny.
 */
class RenderedContentSource implements ContentSource {

	/**
	 * Klucz postmeta z wyrenderowanym tekstem strony.
	 *
	 * Zaczyna się od `_`, więc: (1) WordPress ukrywa go w panelu „Pola własne",
	 * (2) {@see PostMetaContentSource} go pomija — treść nie liczy się dwa razy.
	 */
	public const META_KEY = '_aifaq_rendered';

	/**
	 * Opcja z wynikiem testu pętli zwrotnej (loopback).
	 */
	public const OPTION_LOOPBACK = 'aifaq_loopback';

	/**
	 * Ważność wyniku testu loopbacku w sekundach (6 h).
	 */
	public const LOOPBACK_TTL = 21600;

	/**
	 * Elementy usuwane WRAZ Z ZAWARTOŚCIĄ.
	 *
	 * To kod i grafika, nie proza: zostawienie ich zalewa bazę wiedzy JS-em
	 * i ścieżkami SVG, które potem trafiają do promptu jako „treść strony".
	 * Świadomie NIE ma tu `nav`/`header`/`footer`/`form`/`aside` — zmierzone na
	 * realnych motywach: wycinanie ich po nazwie tagu gubi realną treść
	 * (motywy blokowe pakują sekcje „o nas" do `<header>`). Balast powtarzalny
	 * zdejmuje {@see BoilerplateFilter} (Etap 2), bo widzi CAŁY korpus naraz.
	 *
	 * @var array<int,string>
	 */
	protected const DROP_TAGS = array( 'script', 'style', 'head', 'svg', 'noscript', 'template', 'iframe' );

	/**
	 * Identyfikatory kontenerów komentarzy (usuwane z zawartością).
	 *
	 * @var array<int,string>
	 */
	protected const COMMENT_IDS = array( 'comments', 'respond' );

	/**
	 * Klasy kontenerów komentarzy (usuwane z zawartością).
	 *
	 * Motywy klasyczne + blokowe (`wp-block-comments*`). Powód jest prywatnościowy,
	 * nie estetyczny: komentarz gościa razem z jego telefonem trafiłby do promptu
	 * i mógłby zostać wyrecytowany INNEMU, anonimowemu gościowi (KONTRAKT §1).
	 *
	 * @var array<int,string>
	 */
	protected const COMMENT_CLASSES = array( 'comments-area', 'comment-list', 'wp-block-comments', 'wp-block-comment-template' );

	/**
	 * Kolejka crawla (opcjonalna).
	 *
	 * @var CrawlQueue|null
	 */
	protected ?CrawlQueue $queue;

	/**
	 * Konstruktor.
	 *
	 * @param CrawlQueue|null $queue Kolejka crawla; `null` = czytaj wyłącznie
	 *                               postmeta i uznaj zestaw za kompletny.
	 */
	public function __construct( ?CrawlQueue $queue = null ) {
		$this->queue = $queue;
	}

	/**
	 * Zwraca dokumenty z treści już pobranej przez kolejkę.
	 *
	 * NIGDY nie wykonuje żądań HTTP — oddaje to, co kolejka zdążyła zapisać,
	 * i nie czeka na komplet.
	 *
	 * > Dlaczego „to, co jest", a nie pustka przy niekompletnym crawlu: pustka
	 * > odbierałaby wszystkim stronom treść renderowaną, zmieniając ich hashe,
	 * > co wymuszałoby PEŁNY (płatny) re-embed całej witryny — a po dokończeniu
	 * > crawla drugi. Oddawanie częściowego zestawu daje stabilne hashe: strona
	 * > już pobrana się nie zmienia (skip-unchanged), zmienia się tylko nowa.
	 *
	 * Każdy dokument przechodzi przez {@see WpContentSource::is_indexable()} —
	 * wpis przestawiony na szkic albo zabezpieczony hasłem PO scrawlowaniu nie
	 * może dalej karmić publicznego bota.
	 *
	 * @return array<int,array{post_id:int,title:string,url:string,text:string}>
	 */
	public function documents(): array {
		if ( ! function_exists( 'get_posts' ) || ! function_exists( 'get_post_meta' ) ) {
			return array();
		}

		$posts = get_posts(
			array(
				'post_type'        => array( 'post', 'page' ),
				'post_status'      => 'publish',
				'numberposts'      => -1,
				'has_password'     => false,
				'suppress_filters' => true,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'meta_key'         => self::META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			)
		);

		$docs = array();
		$seen = array();

		foreach ( (array) $posts as $post ) {
			$post_id = is_object( $post ) ? (int) ( $post->ID ?? 0 ) : (int) $post;
			if ( $post_id <= 0 || isset( $seen[ $post_id ] ) ) {
				continue;
			}
			$seen[ $post_id ] = true;

			if ( ! self::indexable( $post_id ) ) {
				continue;
			}

			$text = trim( (string) get_post_meta( $post_id, self::META_KEY, true ) );
			if ( '' === $text ) {
				continue; // strona jeszcze niepobrana albo pusta — pomijamy.
			}

			$docs[] = array(
				'post_id' => $post_id,
				'title'   => function_exists( 'get_the_title' ) ? (string) get_the_title( $post_id ) : '',
				'url'     => function_exists( 'get_permalink' ) ? (string) get_permalink( $post_id ) : '',
				'text'    => $text,
			);
		}

		return $docs;
	}

	/**
	 * Czy zestaw jest kompletny (crawl zakończony).
	 *
	 * `false` → {@see Indexer} pomija pruning, żeby nie kasować fragmentów stron,
	 * które po prostu nie zostały jeszcze pobrane.
	 *
	 * @return bool
	 */
	public function is_complete(): bool {
		if ( null === $this->queue ) {
			return true; // brak kolejki = czytamy wyłącznie to, co jest w bazie.
		}

		try {
			$progress = $this->queue->progress();
			return false === (bool) ( $progress['running'] ?? false );
		} catch ( \Throwable $e ) {
			// Nie wiemy, czy crawl trwa → zachowawczo „niekompletne”: pruning
			// zostanie pominięty. Fałszywe „kompletne” kasowałoby bazę wiedzy.
			return false;
		}
	}

	/**
	 * Czyści wyrenderowany HTML — ZWRACA HTML, nie tekst.
	 *
	 * Zamiana na zwykły tekst należy do {@see WpContentSource::to_plain()} (jeden
	 * kontrakt tekstu dla wszystkich źródeł). Tutaj tylko wycinamy to, co nie jest
	 * treścią: kod, grafikę wektorową, ramki i kontenery komentarzy — każde
	 * RAZEM Z ZAWARTOŚCIĄ.
	 *
	 * @param string $html Surowy HTML strony.
	 * @return string HTML bez elementów technicznych i komentarzy.
	 */
	public static function clean_html( string $html ): string {
		if ( '' === trim( $html ) ) {
			return '';
		}

		foreach ( self::DROP_TAGS as $tag ) {
			$html = self::drop_tag( $html, $tag );
		}

		foreach ( self::COMMENT_IDS as $id ) {
			$html = self::drop_container( $html, 'id', $id );
		}

		foreach ( self::COMMENT_CLASSES as $class ) {
			$html = self::drop_container( $html, 'class', $class );
		}

		return $html;
	}

	/**
	 * Test pętli zwrotnej: czy wtyczka w ogóle potrafi pobrać własną stronę.
	 *
	 * Sam kod 200 NIE wystarcza — strona-wyzwanie Cloudflare, cache CDN i tryb
	 * „wkrótce otwieramy" zwracają poprawne 200 z HTML-em, który nie jest naszą
	 * witryną. Dlatego predykat sukcesu to:
	 * kod 200 ORAZ `Content-Type: text/html…` ORAZ (nazwa witryny w treści LUB
	 * link `https://api.w.org/` z nagłówka WordPressa).
	 *
	 * Wynik cache'owany w opcji {@see OPTION_LOOPBACK} na 6 h; `$force` omija
	 * cache. To JEDYNA metoda zapisująca tę opcję.
	 *
	 * @param bool $force Wymuś świeży test (pomija cache).
	 * @return array{ok:bool,message:string,checked:int}
	 */
	public static function loopback_ok( bool $force = false ): array {
		$now = time();

		if ( ! $force && function_exists( 'get_option' ) ) {
			$cached = get_option( self::OPTION_LOOPBACK, array() );
			if ( is_array( $cached ) && isset( $cached['ok'], $cached['message'], $cached['checked'] ) ) {
				$checked = (int) $cached['checked'];
				if ( $checked > 0 && $checked <= $now && ( $now - $checked ) < self::LOOPBACK_TTL ) {
					return array(
						'ok'      => (bool) $cached['ok'],
						'message' => (string) $cached['message'],
						'checked' => $checked,
					);
				}
			}
		}

		$result            = self::probe_loopback();
		$result['checked'] = $now;

		if ( function_exists( 'update_option' ) ) {
			update_option( self::OPTION_LOOPBACK, $result, false );
		}

		return $result;
	}

	/**
	 * Czy adres wskazuje na środowisko lokalne/prywatne (certyfikat samopodpisany).
	 *
	 * Na `*.local` (Local by Flywheel) weryfikacja SSL zawsze zawodzi, więc crawl
	 * bez tego wyjątku nie pobrałby ani jednej strony. W internecie publicznym
	 * weryfikacja ZOSTAJE włączona.
	 *
	 * @param string $url Adres do sprawdzenia.
	 * @return bool
	 */
	public static function is_local_url( string $url ): bool {
		$host = (string) parse_url( $url, PHP_URL_HOST );
		if ( '' === $host ) {
			return false;
		}
		$host = strtolower( $host );

		if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1', '[::1]' ), true ) ) {
			return true;
		}

		foreach ( array( '.local', '.test', '.localhost', '.invalid', '.example' ) as $suffix ) {
			if ( substr( $host, -strlen( $suffix ) ) === $suffix ) {
				return true;
			}
		}

		// Adresy prywatne IPv4 (10/8, 172.16/12, 192.168/16).
		if ( preg_match( '/^10\.\d{1,3}\.\d{1,3}\.\d{1,3}$/', $host ) ) {
			return true;
		}
		if ( preg_match( '/^192\.168\.\d{1,3}\.\d{1,3}$/', $host ) ) {
			return true;
		}
		if ( preg_match( '/^172\.(1[6-9]|2\d|3[01])\.\d{1,3}\.\d{1,3}$/', $host ) ) {
			return true;
		}

		return false;
	}

	// -----------------------------------------------------------------------
	// Wnętrze (protected — żeby test mógł podstawić atrapę przez dziedziczenie).
	// -----------------------------------------------------------------------

	/**
	 * Wykonuje realne żądanie do strony głównej i ocenia wynik.
	 *
	 * @return array{ok:bool,message:string,checked:int}
	 */
	protected static function probe_loopback(): array {
		if ( ! function_exists( 'wp_remote_get' ) || ! function_exists( 'home_url' ) ) {
			return self::result( false, self::txt( 'Test pętli zwrotnej niedostępny (brak funkcji WordPressa).' ) );
		}

		$url  = (string) home_url( '/' );
		$resp = wp_remote_get( $url, self::request_args( $url ) );

		if ( function_exists( 'is_wp_error' ) && is_wp_error( $resp ) ) {
			return self::result(
				false,
				sprintf(
					/* translators: %s: komunikat błędu HTTP */
					self::txt( 'Wtyczka nie potrafi pobrać własnej strony: %s. Treść z szablonów motywu nie trafi do bazy wiedzy.' ),
					$resp->get_error_message()
				)
			);
		}

		$code = function_exists( 'wp_remote_retrieve_response_code' ) ? (int) wp_remote_retrieve_response_code( $resp ) : 0;
		if ( 200 !== $code ) {
			return self::result(
				false,
				sprintf(
					/* translators: %d: kod odpowiedzi HTTP */
					self::txt( 'Własna strona odpowiedziała kodem %d zamiast 200 — crawl treści renderowanej nie ruszy.' ),
					$code
				)
			);
		}

		$type = function_exists( 'wp_remote_retrieve_header' ) ? (string) wp_remote_retrieve_header( $resp, 'content-type' ) : '';
		if ( 0 !== stripos( trim( $type ), 'text/html' ) ) {
			return self::result(
				false,
				sprintf(
					/* translators: %s: nagłówek Content-Type */
					self::txt( 'Własna strona zwróciła typ „%s" zamiast HTML-a.' ),
					'' !== $type ? $type : '?'
				)
			);
		}

		$body = function_exists( 'wp_remote_retrieve_body' ) ? (string) wp_remote_retrieve_body( $resp ) : '';
		if ( ! self::looks_like_own_site( $body ) ) {
			return self::result( false, self::txt( 'Odpowiedź nie wygląda na stronę tej witryny (możliwa strona-wyzwanie firewalla, cache CDN albo tryb „wkrótce otwieramy"). Crawl pobrałby śmieci — jest wyłączony.' ) );
		}

		return self::result( true, self::txt( 'Pobieranie własnych stron działa (kod 200, rozpoznany HTML WordPressa).' ) );
	}

	/**
	 * Czy treść odpowiedzi wygląda na stronę TEJ witryny.
	 *
	 * @param string $body Treść odpowiedzi.
	 * @return bool
	 */
	protected static function looks_like_own_site( string $body ): bool {
		if ( '' === $body ) {
			return false;
		}

		if ( false !== stripos( $body, 'https://api.w.org/' ) ) {
			return true;
		}

		$name = function_exists( 'get_bloginfo' ) ? trim( (string) get_bloginfo( 'name' ) ) : '';
		if ( '' === $name ) {
			return false;
		}

		$variants = array( $name, htmlspecialchars( $name, ENT_QUOTES, 'UTF-8' ) );
		foreach ( array_unique( $variants ) as $variant ) {
			if ( '' !== $variant && false !== stripos( $body, $variant ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Argumenty żądania HTTP — zawsze JAKO GOŚĆ.
	 *
	 * Pusta tablica ciasteczek jest wymogiem bezpieczeństwa (KONTRAKT §1):
	 * reindeks uruchamia zalogowany administrator, więc żądanie z jego sesją
	 * pokazałoby treść płatną/roboczą, która trafiłaby do publicznego bota.
	 *
	 * @param string $url Adres docelowy (decyduje o `sslverify`).
	 * @return array<string,mixed>
	 */
	protected static function request_args( string $url ): array {
		return array(
			'timeout'             => 15,
			'redirection'         => 2,
			'cookies'             => array(),
			'sslverify'           => ! self::is_local_url( $url ),
			'limit_response_size' => 2097152,
			'user-agent'          => 'AIFAQ-Indexer/' . ( defined( 'AIFAQ_VERSION' ) ? AIFAQ_VERSION : 'dev' ),
			'headers'             => array( 'X-AIFAQ-Crawl' => '1' ),
		);
	}

	/**
	 * Pakuje wynik testu loopbacku.
	 *
	 * @param bool   $ok      Czy sukces.
	 * @param string $message Komunikat dla właściciela witryny.
	 * @return array{ok:bool,message:string,checked:int}
	 */
	protected static function result( bool $ok, string $message ): array {
		return array(
			'ok'      => $ok,
			'message' => $message,
			'checked' => time(),
		);
	}

	/**
	 * Czy wpis wolno zaindeksować (wspólna bramka z Etapu 1).
	 *
	 * Brak klasy/metody → warunek POMIJANY (nigdy fatal, nigdy `false`) —
	 * wymóg pracy w czystym PHP CLI i przy etapach budowanych równolegle.
	 *
	 * @param int $post_id ID wpisu.
	 * @return bool
	 */
	protected static function indexable( int $post_id ): bool {
		$class = __NAMESPACE__ . '\\WpContentSource';
		if ( class_exists( $class ) && method_exists( $class, 'is_indexable' ) ) {
			return (bool) WpContentSource::is_indexable( $post_id );
		}
		return true;
	}

	/**
	 * Tłumaczenie odporne na brak WordPressa (klasa działa w czystym PHP CLI).
	 *
	 * @param string $text Tekst źródłowy.
	 * @return string
	 */
	protected static function txt( string $text ): string {
		// phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
		return function_exists( '__' ) ? __( $text, 'ai-faq-generator' ) : $text;
	}

	/**
	 * Usuwa wszystkie wystąpienia tagu WRAZ Z ZAWARTOŚCIĄ.
	 *
	 * Skaner na `strpos`, nie wyrażenie regularne: `<script>.*?</script>` na
	 * stronie ważącej 2 MB potrafi przekroczyć `pcre.backtrack_limit` i zwrócić
	 * `null`, czyli po cichu ZOSTAWIĆ cały JavaScript w bazie wiedzy.
	 *
	 * @param string $html HTML wejściowy.
	 * @param string $tag  Nazwa tagu (bez nawiasów).
	 * @return string
	 */
	protected static function drop_tag( string $html, string $tag ): string {
		$lower = strtolower( $html );
		$open  = '<' . $tag;
		$close = '</' . $tag;

		if ( false === strpos( $lower, $open ) ) {
			return $html;
		}

		$out    = '';
		$copied = 0;
		$search = 0;
		$guard  = 0;

		while ( $guard++ < 5000 ) {
			$start = strpos( $lower, $open, $search );
			if ( false === $start ) {
				break;
			}

			// Granica nazwy tagu — żeby `<style>` nie łapało `<styleblock>`.
			$after = substr( $lower, $start + strlen( $open ), 1 );
			if ( '' !== $after && false === strpos( " \t\r\n>/", $after ) ) {
				$search = $start + 1;
				continue;
			}

			$end = strpos( $lower, $close, $start + strlen( $open ) );
			if ( false === $end ) {
				break; // brak zamknięcia — nie ryzykujemy ucięcia reszty dokumentu.
			}

			$gt   = strpos( $lower, '>', $end );
			$stop = ( false === $gt ) ? strlen( $html ) : $gt + 1;

			$out   .= substr( $html, $copied, $start - $copied ) . "\n";
			$copied = $stop;
			$search = $stop;
		}

		return $out . substr( $html, $copied );
	}

	/**
	 * Usuwa kontener o danym atrybucie WRAZ Z ZAWARTOŚCIĄ (z liczeniem zagnieżdżeń).
	 *
	 * @param string $html  HTML wejściowy.
	 * @param string $attr  Nazwa atrybutu (`id` albo `class`).
	 * @param string $value Szukana wartość/token.
	 * @return string
	 */
	protected static function drop_container( string $html, string $attr, string $value ): string {
		if ( false === stripos( $html, $value ) ) {
			return $html; // tania odsiewka: bez tego każde wywołanie skanuje cały dokument.
		}

		$guard = 0;
		while ( $guard++ < 20 ) {
			$found = self::find_container( $html, $attr, $value );
			if ( null === $found ) {
				break;
			}

			list( $start, $tag, $body_from ) = $found;

			$end = self::find_matching_close( $html, $tag, $body_from );

			// Brak domknięcia (HTML uszkodzony) → tniemy do końca dokumentu.
			// Świadomie po stronie prywatności (KONTRAKT §1): lepiej stracić ogon
			// strony niż wpuścić do publicznego bota komentarze gości. Sekcja
			// komentarzy i tak leży na końcu dokumentu.
			$html = substr( $html, 0, $start ) . "\n" . ( null === $end ? '' : substr( $html, $end ) );
		}

		return $html;
	}

	/**
	 * Znajduje pierwszy tag otwierający pasujący do selektora.
	 *
	 * @param string $html  HTML.
	 * @param string $attr  Nazwa atrybutu.
	 * @param string $value Szukana wartość/token.
	 * @return array{0:int,1:string,2:int}|null Pozycja startu, nazwa tagu, pozycja za tagiem.
	 */
	protected static function find_container( string $html, string $attr, string $value ) {
		if ( ! preg_match_all( '#<([a-z][a-z0-9]*)\b([^>]*)>#i', $html, $matches, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}

		foreach ( $matches[0] as $i => $whole ) {
			$attrs = (string) ( $matches[2][ $i ][0] ?? '' );
			if ( ! self::attr_matches( $attrs, $attr, $value ) ) {
				continue;
			}
			$start = (int) $whole[1];
			return array( $start, strtolower( (string) $matches[1][ $i ][0] ), $start + strlen( (string) $whole[0] ) );
		}

		return null;
	}

	/**
	 * Czy zestaw atrybutów zawiera dany token (`class`) lub wartość (`id`).
	 *
	 * @param string $attrs Surowy fragment atrybutów tagu.
	 * @param string $attr  Nazwa atrybutu.
	 * @param string $value Szukana wartość/token.
	 * @return bool
	 */
	protected static function attr_matches( string $attrs, string $attr, string $value ): bool {
		if ( ! preg_match( '/(^|\s)' . preg_quote( $attr, '/' ) . '\s*=\s*("|\')(.*?)\2/is', $attrs, $m ) ) {
			return false;
		}

		$tokens = preg_split( '/\s+/', strtolower( trim( (string) $m[3] ) ) );
		return in_array( strtolower( $value ), (array) $tokens, true );
	}

	/**
	 * Znajduje pozycję ZA tagiem zamykającym pasującym do otwartego kontenera.
	 *
	 * @param string $html HTML.
	 * @param string $tag  Nazwa tagu.
	 * @param int    $from Pozycja, od której szukamy (za tagiem otwierającym).
	 * @return int|null Pozycja za `</tag>` albo `null`, gdy brak domknięcia.
	 */
	protected static function find_matching_close( string $html, string $tag, int $from ) {
		$lower  = strtolower( $html );
		$len    = strlen( $lower );
		$depth  = 1;
		$pos    = $from;
		$guard  = 0;
		$offset = strlen( $tag ) + 1;

		while ( $guard++ < 20000 && $pos < $len ) {
			$open  = strpos( $lower, '<' . $tag, $pos );
			$close = strpos( $lower, '</' . $tag, $pos );

			if ( false === $close ) {
				return null;
			}

			if ( false !== $open && $open < $close ) {
				$after = substr( $lower, $open + $offset, 1 );
				if ( '' === $after || false !== strpos( " \t\r\n>/", $after ) ) {
					++$depth;
				}
				$pos = $open + $offset;
				continue;
			}

			--$depth;
			$gt  = strpos( $lower, '>', $close );
			$pos = ( false === $gt ) ? $len : $gt + 1;

			if ( 0 === $depth ) {
				return $pos;
			}
		}

		return null;
	}
}
