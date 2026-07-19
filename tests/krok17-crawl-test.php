<?php
/**
 * Testy Kroku 17 — CrawlQueue + RenderedContentSource (KONTRAKT k17-v3, §3.3 i §3.4).
 *
 * UWAGA METODOLOGICZNA: ten plik, w odróżnieniu od pozostałych `krok17-*`, powstał
 * podczas SPIĘCIA (Etap 6), a nie „w ciemno” — Etap 5 został przerwany limitem sesji.
 * Asercje pisane są wobec KONTRAKTU, ale autor widział implementację, więc ich wartość
 * dowodowa jest niższa niż testów Etapu 5. Odnotowane w `ODCHYLENIA.md`.
 *
 * Najważniejsze asercje (wszystkie o skutku bezpieczeństwa):
 *  - `documents()` NIE wykonuje ANI JEDNEGO żądania HTTP (licznik `=== 0`),
 *  - żądanie crawla idzie BEZ CIASTECZEK (`cookies === array()`) — inaczej crawler
 *    zobaczyłby treść płatną/prywatną i wpuścił ją do publicznego czatbota,
 *  - `documents()` re-filtruje przez `is_indexable()` — wpis przestawiony na szkic
 *    PO scrawlowaniu nie może dalej karmić bota,
 *  - `loopback_ok()` odrzuca stronę-wyzwanie (200 + HTML, ale bez nazwy witryny).
 *
 * URUCHOMIENIE:  php -d extension=mbstring tests/krok17-crawl-test.php
 * Kod wyjścia: 0 = OK, 1 = błędy.
 *
 * @package AI_FAQ_Generator
 */

if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
if ( ! defined( 'AIFAQ_VERSION' ) ) { define( 'AIFAQ_VERSION', '0.20.0-test' ); }
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
if ( ! defined( 'WEEK_IN_SECONDS' ) ) { define( 'WEEK_IN_SECONDS', 604800 ); }

$GLOBALS['__opt']      = array();
$GLOBALS['__meta']     = array();
$GLOBALS['__postdata'] = array();
$GLOBALS['__posts']    = array();
$GLOBALS['__http']     = array( 'calls' => 0, 'args' => array(), 'reply' => null );

if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return $s; } }
if ( ! function_exists( 'get_bloginfo' ) ) { function get_bloginfo( $show = 'name' ) { return 'Przedszkole Testowe'; } }
if ( ! function_exists( 'home_url' ) ) { function home_url( $path = '/' ) { return 'https://example.test' . $path; } }
if ( ! function_exists( 'get_the_title' ) ) { function get_the_title( $id ) { return 'Tytuł ' . (int) $id; } }
if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( $p = 0 ) { $id = is_object( $p ) ? (int) $p->ID : (int) $p; return 'https://example.test/?p=' . $id; }
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = false ) {
		return array_key_exists( $key, $GLOBALS['__opt'] ) ? $GLOBALS['__opt'][ $key ] : $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $key, $value, $autoload = null ) { $GLOBALS['__opt'][ $key ] = $value; return true; }
}
if ( ! function_exists( 'add_option' ) ) {
	function add_option( $key, $value = '', $dep = '', $autoload = 'yes' ) {
		if ( array_key_exists( $key, $GLOBALS['__opt'] ) ) { return false; }
		$GLOBALS['__opt'][ $key ] = $value;
		$GLOBALS['__autoload'][ $key ] = $autoload;
		return true;
	}
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $key ) { unset( $GLOBALS['__opt'][ $key ] ); return true; }
}
if ( ! function_exists( 'get_post_status' ) ) {
	function get_post_status( $id = 0 ) { return $GLOBALS['__postdata'][ (int) $id ]['post_status'] ?? 'publish'; }
}
if ( ! function_exists( 'get_post_type' ) ) {
	function get_post_type( $id = 0 ) { return $GLOBALS['__postdata'][ (int) $id ]['post_type'] ?? 'page'; }
}
if ( ! function_exists( 'get_post_field' ) ) {
	function get_post_field( $field, $id = 0, $context = 'display' ) { return $GLOBALS['__postdata'][ (int) $id ][ $field ] ?? ''; }
}
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $id, $key = '', $single = false ) {
		$all  = $GLOBALS['__meta'][ (int) $id ] ?? array();
		if ( '' === $key ) { return $all; }
		$vals = $all[ $key ] ?? array();
		return $single ? ( $vals[0] ?? '' ) : $vals;
	}
}
if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $id, $key, $value ) { $GLOBALS['__meta'][ (int) $id ][ $key ] = array( $value ); return true; }
}
if ( ! function_exists( 'delete_post_meta_by_key' ) ) {
	function delete_post_meta_by_key( $key ) {
		foreach ( $GLOBALS['__meta'] as $id => $rows ) { unset( $GLOBALS['__meta'][ $id ][ $key ] ); }
		return true;
	}
}
if ( ! function_exists( 'get_posts' ) ) {
	function get_posts( $args = array() ) { return $GLOBALS['__posts']; }
}
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $hook, $value = null, ...$a ) { return $value; } }
if ( ! function_exists( 'wp_parse_url' ) ) { function wp_parse_url( $url, $c = -1 ) { return parse_url( $url, $c ); } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ) { return is_string( $s ) ? trim( $s ) : ''; } }
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $s, $br = true ) { return trim( strip_tags( (string) $s ) ); }
}
if ( ! function_exists( 'wp_next_scheduled' ) ) { function wp_next_scheduled( $h, $a = array() ) { return $GLOBALS['__cron'][ $h ] ?? false; } }
if ( ! function_exists( 'wp_schedule_event' ) ) {
	function wp_schedule_event( $t, $rec, $hook, $args = array() ) { $GLOBALS['__cron'][ $hook ] = $t; $GLOBALS['__cron_rec'][ $hook ] = $rec; return true; }
}
if ( ! function_exists( 'wp_unschedule_event' ) ) { function wp_unschedule_event( $t, $h, $a = array() ) { unset( $GLOBALS['__cron'][ $h ] ); return true; } }
if ( ! function_exists( 'wp_unschedule_hook' ) ) { function wp_unschedule_hook( $h ) { unset( $GLOBALS['__cron'][ $h ] ); return true; } }
if ( ! function_exists( 'wp_cache_add' ) ) { function wp_cache_add( $k, $v, $g = '', $e = 0 ) { return true; } }
if ( ! function_exists( 'wp_cache_delete' ) ) { function wp_cache_delete( $k, $g = '' ) { return true; } }
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $t ) { return $t instanceof WP_Error_Stub; } }

/** Minimalny odpowiednik WP_Error. */
class WP_Error_Stub {
	public $msg;
	public function __construct( $m = 'blad' ) { $this->msg = $m; }
	public function get_error_message() { return $this->msg; }
}

/** LICZNIK ŻĄDAŃ — sedno asercji „documents() nie robi HTTP”. */
if ( ! function_exists( 'wp_remote_get' ) ) {
	function wp_remote_get( $url, $args = array() ) {
		++$GLOBALS['__http']['calls'];
		$GLOBALS['__http']['args'][] = array( 'url' => $url, 'args' => $args );
		$r = $GLOBALS['__http']['reply'];
		return is_callable( $r ) ? $r( $url, $args ) : $r;
	}
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $r ) { return (int) ( $r['response']['code'] ?? 0 ); }
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $r ) { return (string) ( $r['body'] ?? '' ); }
}
if ( ! function_exists( 'wp_remote_retrieve_header' ) ) {
	function wp_remote_retrieve_header( $r, $h ) { return (string) ( $r['headers'][ strtolower( $h ) ] ?? '' ); }
}

$fail = 0;
$ran  = 0;
function check( $cond, $label ) {
	global $fail, $ran;
	++$ran;
	if ( $cond ) { echo "  OK   $label\n"; return; }
	echo "  FAIL $label\n";
	++$fail;
}
/** Buduje udaną odpowiedź HTTP. */
function ok_reply( $body, $ct = 'text/html; charset=UTF-8' ) {
	return array( 'response' => array( 'code' => 200 ), 'headers' => array( 'content-type' => $ct ), 'body' => $body );
}

require_once __DIR__ . '/../src/Index/ContentSource.php';
require_once __DIR__ . '/../src/Index/WpContentSource.php';
require_once __DIR__ . '/../src/Index/CrawlQueue.php';
require_once __DIR__ . '/../src/Index/RenderedContentSource.php';

$Q = 'AIFAQ\Index\CrawlQueue';
$R = 'AIFAQ\Index\RenderedContentSource';
$W = 'AIFAQ\Index\WpContentSource';

echo "=== A. Stałe (§3.4, §3.3) ===\n";
if ( class_exists( $Q ) && class_exists( $R ) ) {
	check( 'aifaq_crawl_tick' === constant( $Q . '::CRON_HOOK' ), 'CRON_HOOK === aifaq_crawl_tick' );
	check( 'aifaq_minute' === constant( $Q . '::CRON_SCHEDULE' ), 'CRON_SCHEDULE === aifaq_minute' );
	check( 'aifaq_crawl_state' === constant( $Q . '::OPTION' ), 'OPTION === aifaq_crawl_state' );
	check( 10 === constant( $Q . '::BATCH_MAX' ), 'BATCH_MAX === 10' );
	check( 20 === constant( $Q . '::BATCH_SECONDS' ), 'BATCH_SECONDS === 20' );
	check( '_aifaq_rendered' === constant( $R . '::META_KEY' ), 'META_KEY === _aifaq_rendered' );
} else {
	check( false, 'sekcja A pominięta — brak klas CrawlQueue/RenderedContentSource' );
}

echo "\n=== B. documents() NIE wykonuje żadnego żądania HTTP (§3.3) ===\n";
if ( class_exists( $R ) ) {
	$GLOBALS['__http']['calls'] = 0;
	$GLOBALS['__meta']          = array(
		11 => array( '_aifaq_rendered' => array( 'Treść strony jedenastej o zajęciach rytmicznych.' ) ),
		12 => array( '_aifaq_rendered' => array( 'Treść strony dwunastej o planie dnia i posiłkach.' ) ),
	);
	$GLOBALS['__opt'][ constant( $Q . '::OPTION' ) ] = array(
		'queue' => array(), 'done' => array( 11, 12 ), 'total' => 2,
		'started' => 0, 'needs_reindex' => false, 'warnings' => array(),
	);
	// §3.3: źródłem prawdy są wpisy z zapisanym `_aifaq_rendered`.
	$GLOBALS['__posts'] = array();
	foreach ( array( 11, 12 ) as $id ) {
		$o     = new stdClass();
		$o->ID = $id;
		$GLOBALS['__posts'][] = $o;
	}
	$src  = new $R( new $Q() );
	$docs = $src->documents();

	check( 0 === $GLOBALS['__http']['calls'], 'ZERO wywołań wp_remote_get w documents()' );
	check( 2 === count( $docs ), 'dwa dokumenty z postmeta (=== 2)' );
	$ids = array_map( static function ( $d ) { return (int) $d['post_id']; }, $docs );
	sort( $ids );
	check( array( 11, 12 ) === $ids, 'post_id z wpisów mających `_aifaq_rendered`, nie zgadywane z URL-a' );
	$k = array_keys( $docs[0] );
	sort( $k );
	check( array( 'post_id', 'text', 'title', 'url' ) === $k, 'kształt dokumentu wg §2.1' );
} else {
	check( false, 'sekcja B pominięta — brak RenderedContentSource' );
}

echo "\n=== C. documents() RE-FILTRUJE przez is_indexable() (§3.3) ===\n";
if ( class_exists( $R ) && method_exists( $W, 'is_indexable' ) ) {
	// Para POZYTYWNA: oba wpisy zdatne → 2 dokumenty (sekcja B już to pokazała).
	// Para NEGATYWNA: wpis 12 przestawiony na szkic PO scrawlowaniu.
	if ( method_exists( $W, 'reset_indexable_cache' ) ) { $W::reset_indexable_cache(); }
	$GLOBALS['__postdata'][12] = array( 'post_status' => 'draft' );
	$src  = new $R( new $Q() );
	$docs = $src->documents();
	check( 1 === count( $docs ), 'wpis przestawiony na szkic PO crawlu wypada z wyniku (=== 1)' );
	check( 11 === (int) $docs[0]['post_id'], 'zostaje wyłącznie wpis nadal opublikowany' );

	if ( method_exists( $W, 'reset_indexable_cache' ) ) { $W::reset_indexable_cache(); }
	$GLOBALS['__postdata'][12] = array( 'post_status' => 'publish' );
	$GLOBALS['__postdata'][11] = array( 'post_status' => 'publish', 'post_password' => 'tajne' );
	$src  = new $R( new $Q() );
	$docs = $src->documents();
	check( 1 === count( $docs ), 'wpis zabezpieczony hasłem PO crawlu też wypada (=== 1)' );
	unset( $GLOBALS['__postdata'][11], $GLOBALS['__postdata'][12] );
	if ( method_exists( $W, 'reset_indexable_cache' ) ) { $W::reset_indexable_cache(); }
} else {
	check( false, 'sekcja C pominięta — brak RenderedContentSource / is_indexable' );
}

echo "\n=== D. is_complete() = pusta kolejka (§3.4) ===\n";
if ( class_exists( $R ) && class_exists( $Q ) ) {
	$GLOBALS['__opt'][ constant( $Q . '::OPTION' ) ] = array(
		'queue' => array(), 'done' => array( 11 ), 'total' => 1,
		'started' => 0, 'needs_reindex' => false, 'warnings' => array(),
	);
	$q = new $Q();
	check( false === $q->progress()['running'], 'pusta kolejka → running === false' );
	check( true === ( new $R( $q ) )->is_complete(), 'pusta kolejka → is_complete() === true' );

	$GLOBALS['__opt'][ constant( $Q . '::OPTION' ) ] = array(
		'queue' => array( array( 'post_id' => 13, 'url' => 'https://example.test/?p=13' ) ),
		'done' => array( 11 ), 'total' => 2, 'started' => 0, 'needs_reindex' => false, 'warnings' => array(),
	);
	$q = new $Q();
	check( true === $q->progress()['running'], 'niepusta kolejka → running === true' );
	check( false === ( new $R( $q ) )->is_complete(), 'niepusta kolejka → is_complete() === false' );

	// Świeża instalacja: 0/0 NIE może dać „niekompletny” (v2 blokował tak pruning na zawsze).
	$GLOBALS['__opt'][ constant( $Q . '::OPTION' ) ] = array();
	$q = new $Q();
	check( false === $q->progress()['running'], 'świeża instalacja (0/0) → running === false' );
} else {
	check( false, 'sekcja D pominięta' );
}

echo "\n=== E. clean_html(): co usuwa, a czego NIE RUSZA (§3.3) ===\n";
if ( class_exists( $R ) ) {
	$html = '<head><title>x</title></head><nav>Menu Start</nav><header>Nagłówek</header>'
		. '<script>alert("zle")</script><style>.a{color:red}</style><svg><path/></svg>'
		. '<noscript>bez js</noscript><template>szablon</template><iframe src="x"></iframe>'
		. '<main><p>Prawdziwa treść strony.</p></main>'
		. '<form><label>Imię</label></form><aside>Oferta dodatkowa</aside>'
		. '<div id="comments"><p>Telefon gościa 601 234 567</p></div>'
		. '<footer>Stopka firmy</footer>';
	$out = $R::clean_html( $html );

	check( false === strpos( $out, 'alert(' ), 'zawartość <script> usunięta' );
	check( false === strpos( $out, 'color:red' ), 'zawartość <style> usunięta' );
	check( false === strpos( $out, 'bez js' ), '<noscript> usunięty' );
	check( false === strpos( $out, 'szablon' ), '<template> usunięty' );
	check( false !== strpos( $out, 'Prawdziwa treść strony.' ), 'treść główna zachowana' );
	check( false !== strpos( $out, 'Menu Start' ), '<nav> NIE jest usuwany po nazwie tagu' );
	check( false !== strpos( $out, 'Stopka firmy' ), '<footer> NIE jest usuwany po nazwie tagu' );
	check( false !== strpos( $out, 'Imię' ), '<form> NIE jest usuwany (gubiłby formularz kontaktowy)' );
	check( false !== strpos( $out, 'Oferta dodatkowa' ), '<aside> NIE jest usuwany (gubiłby ofertę)' );
	check( false === strpos( $out, '601 234 567' ), 'KOMENTARZE usunięte — telefon gościa nie trafi do bota' );
} else {
	check( false, 'sekcja E pominięta' );
}

echo "\n=== F. loopback_ok(): predykat sukcesu, nie samo 200 (§3.3) ===\n";
if ( class_exists( $R ) ) {
	// Para POZYTYWNA.
	$GLOBALS['__http']['calls'] = 0;
	$GLOBALS['__http']['reply'] = ok_reply( '<html><body>Witamy w Przedszkole Testowe</body></html>' );
	unset( $GLOBALS['__opt'][ $R::OPTION_LOOPBACK ] );
	$res = $R::loopback_ok( true );
	check( true === $res['ok'], 'strona z nazwą witryny → ok === true' );
	check( 1 === $GLOBALS['__http']['calls'], 'dokładnie JEDNO żądanie (=== 1)' );

	// Para NEGATYWNA 1: strona-wyzwanie Cloudflare — 200 + HTML, ale bez nazwy witryny.
	$GLOBALS['__http']['reply'] = ok_reply( '<html><body>Just a moment... Checking your browser</body></html>' );
	unset( $GLOBALS['__opt'][ $R::OPTION_LOOPBACK ] );
	$res = $R::loopback_ok( true );
	check( false === $res['ok'], 'strona-wyzwanie (200+HTML, brak nazwy) → ok === false' );
	check( '' !== (string) $res['message'], 'niepusty komunikat przy porażce' );

	// Para NEGATYWNA 2: zły Content-Type.
	$GLOBALS['__http']['reply'] = ok_reply( 'Przedszkole Testowe', 'application/pdf' );
	unset( $GLOBALS['__opt'][ $R::OPTION_LOOPBACK ] );
	check( false === $R::loopback_ok( true )['ok'], 'Content-Type != text/html → ok === false' );

	// Para NEGATYWNA 3: błąd transportu.
	$GLOBALS['__http']['reply'] = new WP_Error_Stub( 'timeout' );
	unset( $GLOBALS['__opt'][ $R::OPTION_LOOPBACK ] );
	check( false === $R::loopback_ok( true )['ok'], 'WP_Error → ok === false' );

	// Alternatywny marker: brak nazwy, ale jest link api.w.org.
	$GLOBALS['__http']['reply'] = ok_reply( '<html><head><link rel="https://api.w.org/" href="x"></head><body>y</body></html>' );
	unset( $GLOBALS['__opt'][ $R::OPTION_LOOPBACK ] );
	check( true === $R::loopback_ok( true )['ok'], 'marker api.w.org też uznawany za sukces' );
} else {
	check( false, 'sekcja F pominięta' );
}

echo "\n=== G. Żądanie crawla idzie JAKO GOŚĆ (§3.4, §7 pkt 4) ===\n";
if ( class_exists( $Q ) ) {
	$GLOBALS['__http']['calls'] = 0;
	$GLOBALS['__http']['args']  = array();
	$GLOBALS['__http']['reply'] = ok_reply( '<html><body><main>Treść pobranej podstrony.</main></body></html>' );
	$GLOBALS['__opt'][ constant( $Q . '::OPTION' ) ] = array(
		'queue' => array( array( 'post_id' => 21, 'url' => 'https://example.test/?p=21' ) ),
		'done' => array(), 'total' => 1, 'started' => 0, 'needs_reindex' => false, 'warnings' => array(),
	);
	( new $Q() )->tick();

	check( 1 === $GLOBALS['__http']['calls'], 'tick() pobrał dokładnie jeden URL (=== 1)' );
	$args = $GLOBALS['__http']['args'][0]['args'] ?? array();
	check( isset( $args['cookies'] ) && array() === $args['cookies'], 'cookies === array() — crawl JAKO GOŚĆ' );
	check( 15 === ( $args['timeout'] ?? 0 ), 'timeout === 15' );
	check( 2 === ( $args['redirection'] ?? 0 ), 'redirection === 2' );
	check( isset( $args['limit_response_size'] ), 'limit_response_size ustawiony (przerwanie transferu, nie pomiar po fakcie)' );
	check( isset( $args['headers']['X-AIFAQ-Crawl'] ), 'nagłówek X-AIFAQ-Crawl obecny (ochrona przed rekurencją crona)' );
	check( false !== strpos( (string) ( $args['user-agent'] ?? '' ), 'AIFAQ-Indexer' ), 'user-agent zawiera AIFAQ-Indexer' );

	$saved = get_post_meta( 21, '_aifaq_rendered', true );
	check( is_string( $saved ) && false !== strpos( $saved, 'Treść pobranej podstrony.' ), 'treść zapisana do postmeta _aifaq_rendered' );
} else {
	check( false, 'sekcja G pominięta' );
}

echo "\n=== H. tick(): błąd żądania nie gubi pozostałych URL-i (§3.4) ===\n";
if ( class_exists( $Q ) ) {
	$GLOBALS['__http']['calls'] = 0;
	$GLOBALS['__http']['reply'] = static function ( $url ) {
		if ( false !== strpos( $url, 'p=31' ) ) { return new WP_Error_Stub( 'timeout' ); }
		return ok_reply( '<html><body><main>Dobra treść ' . $url . '</main></body></html>' );
	};
	$GLOBALS['__opt'][ constant( $Q . '::OPTION' ) ] = array(
		'queue' => array(
			array( 'post_id' => 31, 'url' => 'https://example.test/?p=31' ),
			array( 'post_id' => 32, 'url' => 'https://example.test/?p=32' ),
			array( 'post_id' => 33, 'url' => 'https://example.test/?p=33' ),
		),
		'done' => array(), 'total' => 3, 'started' => 0, 'needs_reindex' => false, 'warnings' => array(),
	);
	( new $Q() )->tick();

	check( 3 === $GLOBALS['__http']['calls'], 'wszystkie 3 URL-e spróbowane mimo błędu pierwszego (=== 3)' );
	check( '' === (string) get_post_meta( 31, '_aifaq_rendered', true ), 'wpis z błędem NIE ma zapisanej treści' );
	check( '' !== (string) get_post_meta( 32, '_aifaq_rendered', true ), 'wpis 32 zapisany normalnie' );
	check( '' !== (string) get_post_meta( 33, '_aifaq_rendered', true ), 'wpis 33 zapisany normalnie' );
	$st = get_option( constant( $Q . '::OPTION' ), array() );
	check( array() === ( $st['queue'] ?? null ), 'kolejka opróżniona — błędny URL oznaczony done, nie zapętlony' );
	check( ! empty( $st['warnings'] ), 'błąd odnotowany w warnings[]' );
	check( true === ( $st['needs_reindex'] ?? false ), 'po opróżnieniu kolejki needs_reindex === true' );
} else {
	check( false, 'sekcja H pominięta' );
}

echo "\n=== I. tick(): odpowiedź spoza text/html odrzucona (§3.4) ===\n";
if ( class_exists( $Q ) ) {
	$GLOBALS['__http']['reply'] = ok_reply( '%PDF-1.4 binarne smieci', 'application/pdf' );
	$GLOBALS['__opt'][ constant( $Q . '::OPTION' ) ] = array(
		'queue' => array( array( 'post_id' => 41, 'url' => 'https://example.test/?p=41' ) ),
		'done' => array(), 'total' => 1, 'started' => 0, 'needs_reindex' => false, 'warnings' => array(),
	);
	( new $Q() )->tick();
	check( '' === (string) get_post_meta( 41, '_aifaq_rendered', true ), 'PDF nie trafia do bazy wiedzy (binaria w płatnym embeddingu)' );
} else {
	check( false, 'sekcja I pominięta' );
}

echo "\n=== J. seed(): wykluczenia i przyrostowość (§3.4) ===\n";
if ( class_exists( $Q ) && method_exists( $W, 'is_indexable' ) ) {
	if ( method_exists( $W, 'reset_indexable_cache' ) ) { $W::reset_indexable_cache(); }
	$GLOBALS['__opt']      = array( 'aifaq_page_id' => 53 );
	$GLOBALS['__postdata'] = array(
		51 => array( 'post_status' => 'publish', 'post_type' => 'page', 'post_name' => 'o-nas' ),
		52 => array( 'post_status' => 'draft', 'post_type' => 'page', 'post_name' => 'szkic' ),
		53 => array( 'post_status' => 'publish', 'post_type' => 'page', 'post_name' => 'generator-faq' ),
		54 => array( 'post_status' => 'publish', 'post_type' => 'page', 'post_name' => 'haslo', 'post_password' => 'x' ),
		55 => array( 'post_status' => 'publish', 'post_type' => 'attachment', 'post_name' => 'zalacznik' ),
		56 => array( 'post_status' => 'publish', 'post_type' => 'page', 'post_name' => 'kontakt' ),
	);
	$GLOBALS['__posts'] = array();
	foreach ( array_keys( $GLOBALS['__postdata'] ) as $id ) {
		$o           = new stdClass();
		$o->ID       = $id;
		$o->post_name = $GLOBALS['__postdata'][ $id ]['post_name'];
		$GLOBALS['__posts'][] = $o;
	}

	$q     = new $Q();
	$added = $q->seed();
	check( 2 === $added, 'zasiane DOKŁADNIE 2 z 6 wpisów (o-nas + kontakt) — reszta wykluczona' );

	$st  = get_option( constant( $Q . '::OPTION' ), array() );
	$ids = array_map( static function ( $r ) { return (int) $r['post_id']; }, $st['queue'] ?? array() );
	sort( $ids );
	check( array( 51, 56 ) === $ids, 'w kolejce wyłącznie 51 i 56' );
	check( ! in_array( 53, $ids, true ), 'WŁASNA podstrona wtyczki wykluczona (wtyczka nie indeksuje sama siebie)' );

	// Przyrostowość: drugie seed() bez zmian NIE dokłada niczego (inaczej reindeks
	// odmawiałby startu w nieskończoność — zakleszczenie z k17-v2).
	check( 0 === $q->seed(), 'drugie seed() bez zmian dokłada 0 URL-i (przyrostowe)' );
} else {
	check( false, 'sekcja J pominięta' );
}

echo "\n=== PODŁOGA ASERCJI ===\n";
check( $ran >= 45, "wykonano co najmniej 45 asercji (było: $ran)" );

echo "\n=== PODSUMOWANIE ===\n";
if ( $fail > 0 ) {
	echo "TEST KROK 17 (crawl + kolejka): $fail ASERCJI NIE PRZESZŁO (z $ran)\n";
	exit( 1 );
}
echo "TEST KROK 17 (crawl + kolejka): WSZYSTKIE ASERCJE OK ($ran)\n";
exit( 0 );
