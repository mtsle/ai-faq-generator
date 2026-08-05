<?php
/**
 * Krok 1 — TEST 13 z tabeli planu: `uninstall.php` nie zostawia sladu.
 *
 * Oczekiwanie z planu: ZERO opcji `ainp_*`, ZERO tabel wtyczki, ZERO cronow.
 * Do tego dwie rzeczy, ktorych sam plan nie numeruje, a bez ktorych „bez sladu"
 * jest nieprawda:
 *   - dane WTYCZKI 1 i witryny musza przezyc (rozlaczne prefiksy to jedyne,
 *     co chroni przed skasowaniem cudzych opcji przy odinstalowaniu),
 *   - powiazania z terminami trzeba skasowac SAMEMU: w `uninstall.php` CPT nie
 *     jest zarejestrowany, wiec `wp_delete_post()` ich nie zdejmie.
 *
 * Artykuly, terminy i osierocone meta sprawdza dopiero TEST 15 (Krok 4), gdy
 * istnieja prawdziwe artykuly — tutaj pilnujemy, ze zapytania w ogole leca
 * i ze leca na wlasciwe tabele.
 *
 * URUCHOMIENIE:  php tests/krok1-uninstall-test.php
 * Kod wyjscia: 0 = OK, 1 = bledy.
 *
 * @package AI_News_Portal
 */

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
function u1_check( $cond, $label ) {
	global $fail, $ran;
	$ran++;
	echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n";
	if ( ! $cond ) {
		$fail++;
	}
}

// ---------------------------------------------------------------------------
// 1. Straznik pliku — sprawdzany PRZED zaladowaniem.
// ---------------------------------------------------------------------------
echo "\n=== 1. Straznik WP_UNINSTALL_PLUGIN ===\n";

$zrodlo = (string) file_get_contents( $root . '/uninstall.php' );

/*
 * Zakazane wywolania sprawdzamy na KODZIE, nie na tresci pliku: `uninstall.php`
 * wprost ostrzega w komentarzu przed `get_posts()`, wiec asercja szukajaca
 * literalu w calym pliku swiecilaby na czerwono z powodu wlasnej dokumentacji.
 */
$kod = '';
foreach ( token_get_all( $zrodlo ) as $token ) {
	if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
		continue;
	}
	$kod .= is_array( $token ) ? $token[1] : $token;
}

u1_check( '' !== $zrodlo, 'uninstall.php istnieje' );
u1_check(
	false !== strpos( $zrodlo, "if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {" ),
	'plik zaczyna sie od straznika WP_UNINSTALL_PLUGIN (bez niego da sie go wywolac z przegladarki)'
);
u1_check(
	false === strpos( $kod, 'get_posts(' ),
	'kod NIE uzywa get_posts() — w uninstall CPT nie jest zarejestrowany, ID ida wprost z bazy'
);
u1_check(
	false === strpos( $kod, 'wp_delete_term(' ),
	'kod NIE uzywa wp_delete_term() — przy niezarejestrowanej taksonomii zwraca WP_Error'
);
u1_check(
	false !== strpos( $kod, 'wp_delete_post(' ),
	'kod uzywa wp_delete_post() — meta, wersje i komentarze wpisu znikaja razem z nim'
);

// ---------------------------------------------------------------------------
// 2. Atrapy WordPressa i stan poczatkowy.
// ---------------------------------------------------------------------------
$GLOBALS['__opt'] = array(
	// Nasze — wszystko to ma zniknac.
	'ainp_settings'                        => array( 'model' => 'gemini-2.5-flash' ),
	'ainp_sources'                         => array( 'https://psy.pl/feed/' ),
	'ainp_key'                             => 'sekret',
	'ainp_usage'                           => array( 'date' => '2026-08-05', 'count' => 7 ),
	'ainp_slug_collision'                  => 77,
	// Opcja, ktorej nikt nie dopisal do jawnej listy — ma ja zlapac zamiatanie.
	'ainp_zapomniana_opcja'                => 'smiec',
	// Transienty maja WLASNE prefiksy, wzorzec `ainp_%` sam ich nie obejmuje.
	'_transient_ainp_robots_psy_pl'        => 'allow',
	'_transient_timeout_ainp_robots_psy_pl' => 1786000000,
	// CUDZE — musza przezyc.
	'aifaq_settings'                       => array( 'api_key' => 'klucz wtyczki 1' ),
	'aifaq_public_faq'                     => array( 'x' ),
	'blogname'                             => 'Czarodziejski Dworek',
	'_transient_doing_cron'                => 1786000001,
);

$GLOBALS['__cron'] = array(
	1786000000 => array(
		'ainp_tick'   => array( 'abc' => array( 'schedule' => 'hourly' ) ),
		'aifaq_purge' => array( 'def' => array( 'schedule' => 'daily' ) ),
	),
	1786003600 => array(
		// Zdarzenie z przyszlego Kroku, ktorego nikt nie dopisal do jawnej listy.
		'ainp_zapomniany_hook' => array( 'ghi' => array( 'schedule' => 'daily' ) ),
	),
);

$GLOBALS['__deleted_posts'] = array();   // [ id => force ]
$GLOBALS['__queries']       = array();   // Pelny SQL, po podstawieniu wartosci.
$GLOBALS['__dropped']       = array();   // Nazwy tabel z DROP TABLE.
$GLOBALS['__deleted_opts']  = array();   // Kolejnosc wywolan delete_option().

/*
 * Atrapa tabeli `postmeta` — zeby czyszczenie osieroconych meta bylo sprawdzane
 * po SKUTKU, a nie po tresci zapytania. Wpisy 11 i 12 istnieja; 900 i 901 nie.
 */
$GLOBALS['__istniejace_posty'] = array( 11, 12 );
$GLOBALS['__postmeta']         = array(
	array( 'meta_id' => 1, 'post_id' => 900, 'meta_key' => '_ainp_item_id' ),    // sierota nasza -> ma zniknac
	array( 'meta_id' => 2, 'post_id' => 900, 'meta_key' => '_ainp_demo' ),       // sierota nasza -> ma zniknac
	array( 'meta_id' => 3, 'post_id' => 901, 'meta_key' => '_aifaq_indexed' ),   // sierota CUDZA -> ma zostac
	array( 'meta_id' => 4, 'post_id' => 11, 'meta_key' => '_ainp_source_url' ),  // wpis istnieje -> ma zostac
);

class AINP_Fake_WPDB {
	public $prefix             = 'wp_';
	public $posts              = 'wp_posts';
	public $postmeta           = 'wp_postmeta';
	public $options            = 'wp_options';
	public $terms              = 'wp_terms';
	public $termmeta           = 'wp_termmeta';
	public $term_taxonomy      = 'wp_term_taxonomy';
	public $term_relationships = 'wp_term_relationships';

	/**
	 * Podstawienie wartosci — `%s` w cudzyslowie, tak jak robi to WordPress.
	 *
	 * @param string $query Zapytanie.
	 * @param mixed  ...$args Wartosci.
	 *
	 * @return string
	 */
	public function prepare( $query, ...$args ) {
		return vsprintf( str_replace( '%s', "'%s'", $query ), $args );
	}

	/**
	 * Escapowanie znakow specjalnych LIKE — jak `wpdb::esc_like()`.
	 *
	 * @param string $text Tekst.
	 *
	 * @return string
	 */
	public function esc_like( $text ) {
		return addcslashes( (string) $text, '_%\\' );
	}

	/**
	 * Kolumna wyniku. Rozpoznajemy zapytanie o artykuly.
	 *
	 * @param string $query Zapytanie.
	 *
	 * @return array
	 */
	public function get_col( $query ) {
		$GLOBALS['__queries'][] = $query;
		if ( false !== strpos( $query, "FROM wp_posts WHERE post_type = 'ainp_article'" ) ) {
			return array( '11', '12' );
		}
		return array();
	}

	/**
	 * Wiersze wyniku. Rozpoznajemy zapytanie o terminy taksonomii.
	 *
	 * @param string $query Zapytanie.
	 *
	 * @return array
	 */
	public function get_results( $query, $output = null ) {
		$GLOBALS['__queries'][] = $query;
		if ( false !== strpos( $query, "taxonomy = 'ainp_topic'" ) ) {
			return array(
				(object) array( 'term_id' => 5, 'term_taxonomy_id' => 5 ),
				(object) array( 'term_id' => 6, 'term_taxonomy_id' => 6 ),
			);
		}
		return array();
	}

	/**
	 * Zapytanie modyfikujace. DROP i DELETE ... LIKE dzialaja NAPRAWDE
	 * na magazynie atrap, zeby asercja „zero opcji" byla asercja o stanie,
	 * a nie o tresci zapytania.
	 *
	 * @param string $query Zapytanie.
	 *
	 * @return int
	 */
	public function query( $query ) {
		$GLOBALS['__queries'][] = $query;

		if ( preg_match( '/DROP TABLE IF EXISTS (\S+)/', $query, $m ) ) {
			$GLOBALS['__dropped'][] = $m[1];
			return 1;
		}

		/*
		 * Czyszczenie osieroconych meta wykonujemy NAPRAWDE na atrapie tabeli.
		 * Wzorzec jest scisly: zapytanie z inna klauzula `WHERE` nie zostanie
		 * rozpoznane i nie skasuje niczego, wiec asercja ponizej wychwyci
		 * zapytanie, ktore tylko WYGLADA poprawnie.
		 */
		if ( preg_match( '/DELETE pm FROM wp_postmeta pm\s+LEFT JOIN wp_posts p ON p\.ID = pm\.post_id\s+WHERE p\.ID IS NULL AND pm\.meta_key IN \(([^)]+)\)/', $query, $m ) ) {
			$klucze = array_map(
				static function ( $k ) {
					return trim( $k, " '" );
				},
				explode( ',', $m[1] )
			);
			foreach ( $GLOBALS['__postmeta'] as $i => $wiersz ) {
				$sierota = ! in_array( (int) $wiersz['post_id'], $GLOBALS['__istniejace_posty'], true );
				if ( $sierota && in_array( $wiersz['meta_key'], $klucze, true ) ) {
					unset( $GLOBALS['__postmeta'][ $i ] );
				}
			}
			return 1;
		}

		if ( preg_match( "/DELETE FROM wp_options WHERE option_name LIKE '(.*)'/", $query, $m ) ) {
			$prefiks = rtrim( str_replace( array( '\\_', '\\%' ), array( '_', '%' ), $m[1] ), '%' );
			foreach ( array_keys( $GLOBALS['__opt'] ) as $klucz ) {
				if ( 0 === strpos( $klucz, $prefiks ) ) {
					unset( $GLOBALS['__opt'][ $klucz ] );
				}
			}
			return 1;
		}

		return 1;
	}
}
$GLOBALS['wpdb'] = new AINP_Fake_WPDB();

function delete_option( $k ) {
	$GLOBALS['__deleted_opts'][] = $k;
	unset( $GLOBALS['__opt'][ $k ] );
	return true;
}
function wp_delete_post( $id, $force = false ) {
	$GLOBALS['__deleted_posts'][ (int) $id ] = (bool) $force;
	return true;
}
function wp_clear_scheduled_hook( $hook, $args = array() ) {
	foreach ( $GLOBALS['__cron'] as $ts => $zdarzenia ) {
		unset( $GLOBALS['__cron'][ $ts ][ $hook ] );
		if ( ! $GLOBALS['__cron'][ $ts ] ) {
			unset( $GLOBALS['__cron'][ $ts ] );
		}
	}
	return true;
}
function _get_cron_array() {
	return $GLOBALS['__cron'];
}

// ---------------------------------------------------------------------------
// 3. Odinstalowanie.
// ---------------------------------------------------------------------------
define( 'WP_UNINSTALL_PLUGIN', 'ai-news-portal/ai-news-portal.php' );

require $root . '/uninstall.php';

$sql_all = implode( "\n", $GLOBALS['__queries'] );

// ---------------------------------------------------------------------------
// 4. TEST 13 — trzy asercje z planu.
// ---------------------------------------------------------------------------
echo "\n=== 2. TEST 13 — zero opcji, zero tabel, zero cronow ===\n";

$zostale_ainp = array_values(
	array_filter(
		array_keys( $GLOBALS['__opt'] ),
		static function ( $k ) {
			return 0 === strpos( $k, 'ainp_' ) || false !== strpos( $k, '_ainp_' );
		}
	)
);

u1_check( 0 === count( $zostale_ainp ), 'ZERO opcji ainp_* po odinstalowaniu (zostalo: ' . ( $zostale_ainp ? implode( ', ', $zostale_ainp ) : 'nic' ) . ')' );
u1_check( 1 === count( $GLOBALS['__dropped'] ), 'dokladnie JEDNO DROP TABLE (jest: ' . count( $GLOBALS['__dropped'] ) . ')' );
u1_check( array( 'wp_ainp_items' ) === $GLOBALS['__dropped'], 'skasowana tabela to wp_ainp_items' );

$crony_ainp = array();
foreach ( $GLOBALS['__cron'] as $zdarzenia ) {
	foreach ( array_keys( $zdarzenia ) as $hook ) {
		if ( 0 === strpos( $hook, 'ainp_' ) ) {
			$crony_ainp[] = $hook;
		}
	}
}

u1_check( 0 === count( $crony_ainp ), 'ZERO zaplanowanych zdarzen ainp_* (zostalo: ' . ( $crony_ainp ? implode( ', ', $crony_ainp ) : 'nic' ) . ')' );

// ---------------------------------------------------------------------------
// 5. Izolacja od wtyczki 1 i od witryny.
// ---------------------------------------------------------------------------
echo "\n=== 3. Cudze dane przezywaja ===\n";

u1_check( isset( $GLOBALS['__opt']['aifaq_settings'] ), 'opcja aifaq_settings (wtyczka 1) NIE skasowana' );
u1_check( isset( $GLOBALS['__opt']['aifaq_public_faq'] ), 'opcja aifaq_public_faq (wtyczka 1) NIE skasowana' );
u1_check( isset( $GLOBALS['__opt']['blogname'] ), 'opcja blogname (witryna) NIE skasowana' );
u1_check( isset( $GLOBALS['__opt']['_transient_doing_cron'] ), 'transient rdzenia NIE skasowany' );
u1_check( isset( $GLOBALS['__cron'][1786000000]['aifaq_purge'] ), 'cron wtyczki 1 NIE ruszony' );

// ---------------------------------------------------------------------------
// 6. Zamiatanie po prefiksie — to, czego nie ma na jawnej liscie.
// ---------------------------------------------------------------------------
echo "\n=== 4. Zamiatanie po prefiksie ===\n";

/*
 * Jawna lista i zamiatanie po prefiksie to DWA niezalezne mechanizmy. Asercja
 * „zero opcji ainp_*" jest spelniona przez samo zamiatanie, wiec bez tych dwoch
 * asercji usuniecie jawnej listy przechodzilo niezauwazone (test mutacyjny
 * z audytu Kroku 1).
 */
$oczekiwane_kasowania = array( 'ainp_settings', 'ainp_sources', 'ainp_key', 'ainp_usage', 'ainp_slug_collision' );
u1_check(
	$oczekiwane_kasowania === $GLOBALS['__deleted_opts'],
	'delete_option wolane DOKLADNIE dla piatki z jawnej listy (jest: ' . count( $GLOBALS['__deleted_opts'] ) . ' — ' . implode( ', ', $GLOBALS['__deleted_opts'] ) . ')'
);
u1_check(
	false !== strpos( $kod, "wp_clear_scheduled_hook( 'ainp_tick' )" ),
	'jawne wyczyszczenie ainp_tick jest w KODZIE, a nie tylko w zamiataniu prefiksu (asercja statyczna)'
);

u1_check( ! isset( $GLOBALS['__opt']['ainp_zapomniana_opcja'] ), 'opcja spoza jawnej listy zlapana przez wzorzec ainp_%' );
u1_check( ! isset( $GLOBALS['__opt']['_transient_ainp_robots_psy_pl'] ), 'transient ainp_ skasowany (ma wlasny prefiks _transient_)' );
u1_check( ! isset( $GLOBALS['__opt']['_transient_timeout_ainp_robots_psy_pl'] ), 'timeout transientu skasowany' );

$zostal_zapomniany_cron = false;
foreach ( $GLOBALS['__cron'] as $zdarzenia ) {
	if ( isset( $zdarzenia['ainp_zapomniany_hook'] ) ) {
		$zostal_zapomniany_cron = true;
	}
}
u1_check( false === $zostal_zapomniany_cron, 'zdarzenie crona spoza jawnej listy zlapane przez zamiatanie prefiksu' );

// ---------------------------------------------------------------------------
// 7. Artykuly, powiazania i meta.
// ---------------------------------------------------------------------------
echo "\n=== 5. Artykuly, powiazania, meta ===\n";

u1_check( 2 === count( $GLOBALS['__deleted_posts'] ), 'skasowane dokladnie 2 artykuly z atrapy (jest: ' . count( $GLOBALS['__deleted_posts'] ) . ')' );
u1_check( array( 11, 12 ) === array_keys( $GLOBALS['__deleted_posts'] ), 'skasowane te ID, ktore zwrocila baza' );
u1_check( array( true, true ) === array_values( $GLOBALS['__deleted_posts'] ), 'kasowanie z force = true (bez tego wpisy ladowalyby w koszu)' );
u1_check( false !== strpos( $sql_all, "post_type = 'ainp_article'" ), 'ID artykulow pobrane zapytaniem po post_type' );
u1_check( false !== strpos( $sql_all, 'DELETE FROM wp_term_relationships WHERE object_id IN (11,12)' ), 'powiazania artykulow z terminami kasowane osobnym zapytaniem' );
u1_check( false !== strpos( $sql_all, "taxonomy = 'ainp_topic'" ), 'terminy taksonomii ainp_topic wyszukane w bazie' );
u1_check( false !== strpos( $sql_all, 'DELETE FROM wp_term_taxonomy WHERE term_taxonomy_id IN (5,6)' ), 'wpisy taksonomii skasowane' );
u1_check( false !== strpos( $sql_all, 'DELETE FROM wp_termmeta WHERE term_id IN (5,6)' ), 'meta terminow skasowane' );
u1_check( false !== strpos( $sql_all, 'FROM wp_terms t' ), 'terminy kasowane tylko wtedy, gdy nie uzywa ich inna taksonomia' );
u1_check( false !== strpos( $sql_all, "'_ainp_item_id', '_ainp_source_url', '_ainp_demo'" ), 'zapytanie o osierocone meta wymienia wszystkie trzy klucze wtyczki (asercja tekstowa)' );

/*
 * Te trzy asercje sprawdzaja SKUTEK zapytania na atrapie tabeli, nie jego tresc.
 * Sama asercja tekstowa wyzej przepuszczala zapytanie z zepsuta klauzula `WHERE`
 * — wykryte testem mutacyjnym podczas audytu Kroku 1.
 */
$pozostale_meta = array_column( $GLOBALS['__postmeta'], 'meta_key' );

u1_check(
	! in_array( '_ainp_item_id', $pozostale_meta, true ) && ! in_array( '_ainp_demo', $pozostale_meta, true ),
	'osierocone meta wtyczki NAPRAWDE znikaja z tabeli (zostalo: ' . implode( ', ', $pozostale_meta ) . ')'
);
u1_check( in_array( '_aifaq_indexed', $pozostale_meta, true ), 'osierocona meta WTYCZKI 1 nietknieta' );
u1_check( in_array( '_ainp_source_url', $pozostale_meta, true ), 'meta przypieta do ISTNIEJACEGO wpisu nietknieta — klauzula WHERE jest respektowana' );

// ---------------------------------------------------------------------------
echo "\n===================================================================\n";
echo 'Asercji: ' . $ran . ' | Niezaliczonych: ' . $fail . "\n";
if ( 0 === $fail ) {
	echo "WYNIK: WSZYSTKIE OK\n";
	exit( 0 );
}
echo "WYNIK: SA BLEDY\n";
exit( 1 );
