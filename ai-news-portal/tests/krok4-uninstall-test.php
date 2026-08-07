<?php
/**
 * Krok 4 — TEST 15 z tabeli planu: `uninstall.php` PRZY ISTNIEJACYCH ARTYKULACH.
 *
 * Oczekiwanie z planu: ZERO wpisow `ainp_article`, ZERO terminow `ainp_topic`,
 * ZERO osieroconych `postmeta`.
 *
 * CZYM SIE ROZNI OD TESTU 13 (`krok1-uninstall-test.php`). Tamten powstal, gdy
 * wtyczka nie umiala jeszcze niczego opublikowac, wiec sprawdza, czy zapytania
 * W OGOLE leca i czy leca na wlasciwe tabele — artykuly i terminy asercjuje
 * po TRESCI zapytania i po zarejestrowanych wywolaniach. Tutaj atrapa bazy
 * trzyma prawdziwe wiersze i naprawde je kasuje, wiec kazda asercja jest
 * asercja o STANIE tabeli po odinstalowaniu. Zapytanie, ktore tylko wyglada
 * poprawnie, nic tu nie skasuje i asercja to zobaczy.
 *
 * Fikstura odwzorowuje stan PO Kroku 4, ze wszystkimi czterema statusami,
 * ktore wtyczka potrafi zostawic: opublikowany artykul z kategoria, szkic po
 * bledzie kategorii (U3), wpis w koszu i nieusuniete demo (U1 — demo znika
 * dopiero po publikacji, a edytowane nie znika wcale).
 *
 * GOTCHA, ktora ta fikstura pilnuje: atrapa `wp_delete_post()` NIE zdejmuje
 * powiazan z terminami — dokladnie tak zachowuje sie WordPress w `uninstall.php`,
 * bo `get_object_taxonomies()` przy niezarejestrowanym CPT zwraca pusta tablice.
 * Dzieki temu usuniecie jawnego `DELETE FROM term_relationships` z kodu zostawia
 * sieroty, ktore asercja wylapie.
 *
 * ZNALAZL BLAD U10: `uninstall.php` kasowal `termmeta` po samym `term_id`, wiec
 * zabieral takze meta terminu WSPOLDZIELONEGO z inna taksonomia — a wtyczka
 * nigdy nie zapisuje wlasnych `termmeta`, wiec kazdy taki wiersz byl cudzy.
 * Naprawione ta sama oslona `LEFT JOIN`, ktora chroni juz `wp_terms`.
 *
 * ZMIERZONE przy powstaniu (2026-08-07): 29 asercji, kontrola mutacyjna 12/12
 * na PELNEJ kopii wtyczki, przy zielonej probie kontrolnej. Dwie mutacje
 * przezyly pierwsza serie i obie byly luka w FIKSTURZE, nie w kodzie:
 *   - M1 (brak `DELETE ... WHERE object_id IN`) — kazde powiazanie artykulu
 *     wskazywalo NASZA taksonomie, wiec sprzatalo je pozniejsze zapytanie po
 *     `term_taxonomy_id`. Dolozony wiersz: nasz artykul przypiety do OBCEJ
 *     taksonomii — tego nie zdejmie nic poza kasowaniem po `object_id`.
 *   - M10 (brak kasowania `termmeta`) — nie bylo asercji na ten skutek.
 *
 * URUCHOMIENIE:  php tests/krok4-uninstall-test.php
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
function u15_check( $cond, $label ) {
	global $fail, $ran;
	$ran++;
	echo ( $cond ? '  OK   ' : '  FAIL ' ) . $label . "\n";
	if ( ! $cond ) {
		$fail++;
	}
}

/**
 * Ile wierszy tablicy spelnia warunek.
 *
 * @param array    $wiersze Tablica wierszy.
 * @param callable $warunek Warunek.
 *
 * @return int
 */
function u15_ile( array $wiersze, callable $warunek ) {
	return count( array_filter( $wiersze, $warunek ) );
}

// ---------------------------------------------------------------------------
// 1. Fikstura — stan witryny po Kroku 4.
// ---------------------------------------------------------------------------
$GLOBALS['__posts'] = array(
	// NASZE — wszystkie cztery statusy, ktore wtyczka potrafi zostawic.
	101 => array( 'ID' => 101, 'post_type' => 'ainp_article', 'post_status' => 'publish' ),
	102 => array( 'ID' => 102, 'post_type' => 'ainp_article', 'post_status' => 'draft' ),
	103 => array( 'ID' => 103, 'post_type' => 'ainp_article', 'post_status' => 'trash' ),
	104 => array( 'ID' => 104, 'post_type' => 'ainp_article', 'post_status' => 'publish' ), // demo, nieusuniete
	// CUDZE — musza przezyc.
	201 => array( 'ID' => 201, 'post_type' => 'post', 'post_status' => 'publish' ),
	202 => array( 'ID' => 202, 'post_type' => 'page', 'post_status' => 'publish' ),
);

$GLOBALS['__postmeta'] = array(
	array( 'post_id' => 101, 'meta_key' => '_ainp_item_id', 'meta_value' => 7 ),
	array( 'post_id' => 101, 'meta_key' => '_ainp_source_url', 'meta_value' => 'https://www.psy.pl/a/' ),
	array( 'post_id' => 102, 'meta_key' => '_ainp_item_id', 'meta_value' => 8 ),
	array( 'post_id' => 103, 'meta_key' => '_ainp_item_id', 'meta_value' => 9 ),
	array( 'post_id' => 104, 'meta_key' => '_ainp_demo', 'meta_value' => 1 ),
	// Sieroty po wpisach skasowanych wczesniej reka klienta.
	array( 'post_id' => 900, 'meta_key' => '_ainp_item_id', 'meta_value' => 3 ),
	array( 'post_id' => 900, 'meta_key' => '_ainp_source_url', 'meta_value' => 'https://ccw24.pl/b/' ),
	array( 'post_id' => 901, 'meta_key' => '_ainp_demo', 'meta_value' => 1 ),
	// CUDZE — jedna przypieta do istniejacego wpisu, jedna osierocona.
	array( 'post_id' => 201, 'meta_key' => '_aifaq_indexed', 'meta_value' => 1 ),
	array( 'post_id' => 902, 'meta_key' => '_aifaq_faq', 'meta_value' => 'x' ),
);

/*
 * Termin 6 jest WSPOLDZIELONY: te sama pozycje w `wp_terms` uzywaja dwie
 * taksonomie — nasza `ainp_topic` (tt 6) i wbudowana `category` (tt 16).
 * `wp_terms` jest tabela calego WordPressa, wiec wiersz 6 ma PRZEZYC, a zniknac
 * ma wylacznie nasze przypisanie taksonomii. To jest jedyny powod, dla ktorego
 * `uninstall.php` kasuje terminy z LEFT JOIN, a nie po prostu po `term_id`.
 */
$GLOBALS['__terms'] = array(
	5 => array( 'term_id' => 5, 'name' => 'Zywienie' ),
	6 => array( 'term_id' => 6, 'name' => 'Zdrowie' ),   // wspoldzielony
	9 => array( 'term_id' => 9, 'name' => 'Aktualnosci' ),
);

$GLOBALS['__term_taxonomy'] = array(
	5  => array( 'term_taxonomy_id' => 5, 'term_id' => 5, 'taxonomy' => 'ainp_topic' ),
	6  => array( 'term_taxonomy_id' => 6, 'term_id' => 6, 'taxonomy' => 'ainp_topic' ),
	16 => array( 'term_taxonomy_id' => 16, 'term_id' => 6, 'taxonomy' => 'category' ),
	19 => array( 'term_taxonomy_id' => 19, 'term_id' => 9, 'taxonomy' => 'category' ),
);

$GLOBALS['__term_relationships'] = array(
	array( 'object_id' => 101, 'term_taxonomy_id' => 5 ),
	array( 'object_id' => 104, 'term_taxonomy_id' => 6 ),
	array( 'object_id' => 201, 'term_taxonomy_id' => 16 ),
	array( 'object_id' => 201, 'term_taxonomy_id' => 19 ),
	// Sierota po artykule skasowanym wczesniej reka klienta.
	array( 'object_id' => 900, 'term_taxonomy_id' => 5 ),
	/*
	 * NASZ artykul przypiety do OBCEJ taksonomii. Zdarza sie, gdy ktos zarejestruje
	 * dodatkowa taksonomie dla `ainp_article` albo zrobi to wtyczka trzecia.
	 * Tego wiersza NIE zdejmie ani `wp_delete_post()` (CPT niezarejestrowany), ani
	 * kasowanie po naszym `term_taxonomy_id` — zostaje wylacznie kasowanie po
	 * `object_id`. Bez tej pozycji w fiksturze mutacja zdejmujaca to zapytanie
	 * PRZECHODZI (zmierzone: M1 przezyla pierwsza serie).
	 */
	array( 'object_id' => 101, 'term_taxonomy_id' => 19 ),
);

$GLOBALS['__termmeta'] = array(
	array( 'term_id' => 5, 'meta_key' => 'ainp_kolor' ),   // nasz termin -> ma zniknac
	/*
	 * Meta terminu WSPOLDZIELONEGO — ma PRZEZYC. To jest ustalenie U10,
	 * znalezione przy pisaniu tego testu i naprawione: `uninstall.php` kasowal
	 * `termmeta` po samym `term_id`, wiec zabieral takze meta terminu, ktory
	 * zostaje w `wp_terms`, bo uzywa go `category`.
	 */
	array( 'term_id' => 6, 'meta_key' => 'kategoria_ikona' ),
	array( 'term_id' => 9, 'meta_key' => 'obcy_klucz' ),   // obcy termin -> ma zostac
);

$GLOBALS['__opt'] = array(
	'ainp_settings' => array( 'model' => 'gemini-2.5-flash' ),
	'ainp_usage'    => array( 'date' => '2026-08-07', 'count' => 2 ),
	'aifaq_settings' => array( 'api_key' => 'klucz wtyczki 1' ),
);

$GLOBALS['__cron']          = array();
$GLOBALS['__deleted_posts'] = array();   // [ id => force ]
$GLOBALS['__dropped']       = array();

// ---------------------------------------------------------------------------
// 2. Atrapa bazy — kasuje NAPRAWDE.
// ---------------------------------------------------------------------------

/**
 * Atrapa `$wpdb`, ktora wykonuje zapytania `uninstall.php` na tablicach w pamieci.
 *
 * Kazdy wzorzec jest scisly. Zapytanie z inna klauzula `WHERE` nie zostanie
 * rozpoznane i NIE skasuje niczego — wtedy asercja o stanie zapali sie na
 * czerwono. To jest cala roznica miedzy tym testem a testem 13.
 */
class AINP_Fake_WPDB_Uninstall {
	public $prefix             = 'wp_';
	public $posts              = 'wp_posts';
	public $postmeta           = 'wp_postmeta';
	public $options            = 'wp_options';
	public $terms              = 'wp_terms';
	public $termmeta           = 'wp_termmeta';
	public $term_taxonomy      = 'wp_term_taxonomy';
	public $term_relationships = 'wp_term_relationships';

	/**
	 * Podstawienie wartosci — `%s` w cudzyslowie, `%d` BEZ cudzyslowu.
	 *
	 * GOTCHA z Kroku 4: atrapa, ktora cytuje wszystko, przepuszcza porownanie
	 * liczbowe bez sladu.
	 *
	 * @param string $query   Zapytanie.
	 * @param mixed  ...$args Wartosci.
	 *
	 * @return string
	 */
	public function prepare( $query, ...$args ) {
		return vsprintf( str_replace( '%s', "'%s'", $query ), $args );
	}

	/**
	 * Escapowanie znakow specjalnych LIKE.
	 *
	 * @param string $text Tekst.
	 *
	 * @return string
	 */
	public function esc_like( $text ) {
		return addcslashes( (string) $text, '_%\\' );
	}

	/**
	 * Rozbicie listy `IN (...)` na liczby calkowite.
	 *
	 * @param string $lista Zawartosc nawiasu.
	 *
	 * @return array
	 */
	private function ids( $lista ) {
		return array_map( 'intval', array_map( 'trim', explode( ',', $lista ) ) );
	}

	/**
	 * Identyfikatory artykulow — czytane z ATRAPY TABELI, nie z listy na sztywno.
	 *
	 * @param string $query Zapytanie.
	 *
	 * @return array
	 */
	public function get_col( $query ) {
		if ( preg_match( "/SELECT ID FROM wp_posts WHERE post_type = '([^']+)'/", $query, $m ) ) {
			$typ = $m[1];
			$out = array();
			foreach ( $GLOBALS['__posts'] as $wiersz ) {
				if ( $wiersz['post_type'] === $typ ) {
					$out[] = (string) $wiersz['ID'];
				}
			}
			return $out;
		}
		return array();
	}

	/**
	 * Terminy taksonomii — czytane z ATRAPY TABELI.
	 *
	 * @param string $query  Zapytanie.
	 * @param mixed  $output Nieuzywane.
	 *
	 * @return array
	 */
	public function get_results( $query, $output = null ) {
		if ( preg_match( "/FROM wp_term_taxonomy WHERE taxonomy = '([^']+)'/", $query, $m ) ) {
			$tax = $m[1];
			$out = array();
			foreach ( $GLOBALS['__term_taxonomy'] as $wiersz ) {
				if ( $wiersz['taxonomy'] === $tax ) {
					$out[] = (object) array(
						'term_id'          => $wiersz['term_id'],
						'term_taxonomy_id' => $wiersz['term_taxonomy_id'],
					);
				}
			}
			return $out;
		}
		return array();
	}

	/**
	 * Zapytanie modyfikujace — wykonywane na tablicach w pamieci.
	 *
	 * @param string $query Zapytanie.
	 *
	 * @return int
	 */
	public function query( $query ) {
		// Powiazania po OBIEKCIE (artykuly).
		if ( preg_match( '/DELETE FROM wp_term_relationships WHERE object_id IN \(([^)]*)\)/', $query, $m ) ) {
			$ids = $this->ids( $m[1] );
			foreach ( $GLOBALS['__term_relationships'] as $i => $wiersz ) {
				if ( in_array( (int) $wiersz['object_id'], $ids, true ) ) {
					unset( $GLOBALS['__term_relationships'][ $i ] );
				}
			}
			return 1;
		}

		// Powiazania po TAKSONOMII.
		if ( preg_match( '/DELETE FROM wp_term_relationships WHERE term_taxonomy_id IN \(([^)]*)\)/', $query, $m ) ) {
			$ids = $this->ids( $m[1] );
			foreach ( $GLOBALS['__term_relationships'] as $i => $wiersz ) {
				if ( in_array( (int) $wiersz['term_taxonomy_id'], $ids, true ) ) {
					unset( $GLOBALS['__term_relationships'][ $i ] );
				}
			}
			return 1;
		}

		// Wpisy taksonomii.
		if ( preg_match( '/DELETE FROM wp_term_taxonomy WHERE term_taxonomy_id IN \(([^)]*)\)/', $query, $m ) ) {
			foreach ( $this->ids( $m[1] ) as $tt ) {
				unset( $GLOBALS['__term_taxonomy'][ $tt ] );
			}
			return 1;
		}

		/*
		 * Meta terminow — tylko dla tych, ktorych juz nie ma w `wp_terms`.
		 * Wzorzec jest scisly: kasowanie po samym `term_id` (postac sprzed U10)
		 * nie zostanie rozpoznane i nie skasuje niczego.
		 */
		if ( preg_match( '/DELETE tm FROM wp_termmeta tm\s+LEFT JOIN wp_terms t ON t\.term_id = tm\.term_id\s+WHERE tm\.term_id IN \(([^)]*)\) AND t\.term_id IS NULL/', $query, $m ) ) {
			$ids = $this->ids( $m[1] );
			foreach ( $GLOBALS['__termmeta'] as $i => $wiersz ) {
				$term_id = (int) $wiersz['term_id'];
				if ( in_array( $term_id, $ids, true ) && ! isset( $GLOBALS['__terms'][ $term_id ] ) ) {
					unset( $GLOBALS['__termmeta'][ $i ] );
				}
			}
			return 1;
		}

		// Terminy — tylko te, ktorych nie uzywa juz zadna taksonomia.
		if ( preg_match( '/DELETE t FROM wp_terms t\s+LEFT JOIN wp_term_taxonomy tt ON tt\.term_id = t\.term_id\s+WHERE t\.term_id IN \(([^)]*)\) AND tt\.term_taxonomy_id IS NULL/', $query, $m ) ) {
			foreach ( $this->ids( $m[1] ) as $term_id ) {
				$uzywany = false;
				foreach ( $GLOBALS['__term_taxonomy'] as $wiersz ) {
					if ( (int) $wiersz['term_id'] === $term_id ) {
						$uzywany = true;
						break;
					}
				}
				if ( ! $uzywany ) {
					unset( $GLOBALS['__terms'][ $term_id ] );
				}
			}
			return 1;
		}

		// Osierocone meta wpisow.
		if ( preg_match( '/DELETE pm FROM wp_postmeta pm\s+LEFT JOIN wp_posts p ON p\.ID = pm\.post_id\s+WHERE p\.ID IS NULL AND pm\.meta_key IN \(([^)]+)\)/', $query, $m ) ) {
			$klucze = array_map(
				static function ( $k ) {
					return trim( $k, " '" );
				},
				explode( ',', $m[1] )
			);
			foreach ( $GLOBALS['__postmeta'] as $i => $wiersz ) {
				$sierota = ! isset( $GLOBALS['__posts'][ (int) $wiersz['post_id'] ] );
				if ( $sierota && in_array( $wiersz['meta_key'], $klucze, true ) ) {
					unset( $GLOBALS['__postmeta'][ $i ] );
				}
			}
			return 1;
		}

		if ( preg_match( '/DROP TABLE IF EXISTS (\S+)/', $query, $m ) ) {
			$GLOBALS['__dropped'][] = $m[1];
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
$GLOBALS['wpdb'] = new AINP_Fake_WPDB_Uninstall();

function delete_option( $k ) {
	unset( $GLOBALS['__opt'][ $k ] );
	return true;
}

/**
 * Atrapa `wp_delete_post()` — kasuje wpis I JEGO META, ale NIE powiazania
 * z terminami. Tak wlasnie zachowuje sie WordPress w `uninstall.php`: CPT nie
 * jest zarejestrowany, wiec `get_object_taxonomies()` zwraca pusta tablice.
 *
 * @param int  $id    Identyfikator wpisu.
 * @param bool $force Kasowanie z pominieciem kosza.
 *
 * @return bool
 */
function wp_delete_post( $id, $force = false ) {
	$id = (int) $id;
	$GLOBALS['__deleted_posts'][ $id ] = (bool) $force;

	if ( ! $force ) {
		// Bez `true` wpis ladowalby w koszu, czyli ZOSTAWALBY w tabeli.
		$GLOBALS['__posts'][ $id ]['post_status'] = 'trash';
		return true;
	}

	unset( $GLOBALS['__posts'][ $id ] );
	foreach ( $GLOBALS['__postmeta'] as $i => $wiersz ) {
		if ( (int) $wiersz['post_id'] === $id ) {
			unset( $GLOBALS['__postmeta'][ $i ] );
		}
	}
	return true;
}

function wp_clear_scheduled_hook( $hook, $args = array() ) {
	foreach ( $GLOBALS['__cron'] as $ts => $zdarzenia ) {
		unset( $GLOBALS['__cron'][ $ts ][ $hook ] );
	}
	return true;
}

function _get_cron_array() {
	return $GLOBALS['__cron'];
}

// ---------------------------------------------------------------------------
// 3. Stan przed odinstalowaniem — zeby asercje ponizej mialy z czego spadac.
// ---------------------------------------------------------------------------
echo "\n=== 1. Fikstura: jest co kasowac ===\n";

u15_check(
	4 === u15_ile( $GLOBALS['__posts'], static fn( $w ) => 'ainp_article' === $w['post_type'] ),
	'przed odinstalowaniem: 4 artykuly ainp_article (publish, draft, trash, demo)'
);
u15_check(
	2 === u15_ile( $GLOBALS['__term_taxonomy'], static fn( $w ) => 'ainp_topic' === $w['taxonomy'] ),
	'przed odinstalowaniem: 2 kategorie ainp_topic'
);
u15_check(
	8 === u15_ile( $GLOBALS['__postmeta'], static fn( $w ) => 0 === strpos( $w['meta_key'], '_ainp_' ) ),
	'przed odinstalowaniem: 8 meta _ainp_* w tabeli'
);
u15_check(
	3 === u15_ile(
		$GLOBALS['__postmeta'],
		static fn( $w ) => 0 === strpos( $w['meta_key'], '_ainp_' ) && ! isset( $GLOBALS['__posts'][ (int) $w['post_id'] ] )
	),
	'przed odinstalowaniem: 3 z nich sa OSIEROCONE (post_id 900 i 901)'
);

// ---------------------------------------------------------------------------
// 4. Odinstalowanie.
// ---------------------------------------------------------------------------
define( 'WP_UNINSTALL_PLUGIN', 'ai-news-portal/ai-news-portal.php' );

require $root . '/uninstall.php';

// ---------------------------------------------------------------------------
// 5. TEST 15 — trzy asercje wprost z tabeli planu.
// ---------------------------------------------------------------------------
echo "\n=== 2. TEST 15 — zero wpisow, zero terminow, zero osieroconych meta ===\n";

$zostale_artykuly = array_keys(
	array_filter( $GLOBALS['__posts'], static fn( $w ) => 'ainp_article' === $w['post_type'] )
);
u15_check(
	0 === count( $zostale_artykuly ),
	'ZERO wpisow ainp_article w tabeli (zostalo: ' . ( $zostale_artykuly ? implode( ', ', $zostale_artykuly ) : 'nic' ) . ')'
);

$zostale_tt = array_keys(
	array_filter( $GLOBALS['__term_taxonomy'], static fn( $w ) => 'ainp_topic' === $w['taxonomy'] )
);
u15_check(
	0 === count( $zostale_tt ),
	'ZERO terminow ainp_topic (zostalo tt: ' . ( $zostale_tt ? implode( ', ', $zostale_tt ) : 'nic' ) . ')'
);

$zostale_meta = array_values(
	array_filter( $GLOBALS['__postmeta'], static fn( $w ) => 0 === strpos( $w['meta_key'], '_ainp_' ) )
);
u15_check(
	0 === count( $zostale_meta ),
	'ZERO meta _ainp_* — ani przypietych, ani osieroconych (zostalo: ' . count( $zostale_meta ) . ')'
);

// ---------------------------------------------------------------------------
// 6. Kazdy status z osobna — bo kazdy ma inna droge wyjscia.
// ---------------------------------------------------------------------------
echo "\n=== 3. Wszystkie cztery statusy skasowane ===\n";

u15_check( 4 === count( $GLOBALS['__deleted_posts'] ), 'wp_delete_post wolane 4 razy (jest: ' . count( $GLOBALS['__deleted_posts'] ) . ')' );
u15_check( array( 101, 102, 103, 104 ) === array_keys( $GLOBALS['__deleted_posts'] ), 'skasowane dokladnie te ID, ktore zwrocila baza' );
u15_check(
	array( true, true, true, true ) === array_values( $GLOBALS['__deleted_posts'] ),
	'wszystkie z force = true (bez tego szkic i publish ladowalyby w koszu, czyli ZOSTAWALYBY)'
);
u15_check( ! isset( $GLOBALS['__posts'][103] ), 'wpis juz bedacy W KOSZU tez zniknal — kosz nie jest kryjowka' );
u15_check( ! isset( $GLOBALS['__posts'][104] ), 'nieusuniete demo zniknelo razem z reszta (U1: edytowane demo zostaje az do odinstalowania)' );

// ---------------------------------------------------------------------------
// 7. Powiazania — tu wychodzi brak jawnego DELETE.
// ---------------------------------------------------------------------------
echo "\n=== 4. Powiazania z terminami ===\n";

$obiekty = array_column( $GLOBALS['__term_relationships'], 'object_id' );
$tt_uzyte = array_column( $GLOBALS['__term_relationships'], 'term_taxonomy_id' );

u15_check(
	! in_array( 101, $obiekty, true ) && ! in_array( 104, $obiekty, true ),
	'powiazania skasowanych artykulow znikly (atrapa wp_delete_post ich NIE zdejmuje — musi to zrobic jawne zapytanie)'
);
u15_check( ! in_array( 900, $obiekty, true ), 'powiazanie osierocone po artykule skasowanym reka klienta tez znika — po term_taxonomy_id' );
u15_check(
	0 === u15_ile( $GLOBALS['__term_relationships'], static fn( $w ) => ! isset( $GLOBALS['__posts'][ (int) $w['object_id'] ] ) ),
	'ZERO powiazan wskazujacych nieistniejacy wpis — takze przypiecie naszego artykulu do OBCEJ taksonomii'
);
u15_check( ! in_array( 5, $tt_uzyte, true ) && ! in_array( 6, $tt_uzyte, true ), 'zadne powiazanie nie wskazuje juz naszej taksonomii' );
u15_check( 2 === count( $GLOBALS['__term_relationships'] ), 'zostaly dokladnie 2 powiazania — oba cudze (jest: ' . count( $GLOBALS['__term_relationships'] ) . ')' );

// ---------------------------------------------------------------------------
// 8. Termin wspoldzielony — wp_terms jest tabela CALEGO WordPressa.
// ---------------------------------------------------------------------------
echo "\n=== 5. Izolacja: cudze dane przezywaja ===\n";

u15_check( ! isset( $GLOBALS['__terms'][5] ), 'termin uzywany TYLKO przez ainp_topic skasowany z wp_terms' );
u15_check( isset( $GLOBALS['__terms'][6] ), 'termin WSPOLDZIELONY z category ZOSTAJE w wp_terms — inaczej wtyczka kasuje kategorie witryny' );
u15_check( isset( $GLOBALS['__term_taxonomy'][16] ), 'przypisanie tego terminu do category nietkniete' );
u15_check( isset( $GLOBALS['__terms'][9] ), 'obcy termin nietkniety' );
u15_check( isset( $GLOBALS['__term_taxonomy'][19] ), 'obce przypisanie taksonomii nietkniete' );
u15_check( isset( $GLOBALS['__posts'][201] ) && isset( $GLOBALS['__posts'][202] ), 'wpis i strona witryny nietkniete' );

$klucze_meta = array_column( $GLOBALS['__postmeta'], 'meta_key' );
u15_check( in_array( '_aifaq_indexed', $klucze_meta, true ), 'meta wtyczki 1 przypieta do istniejacego wpisu nietknieta' );
u15_check( in_array( '_aifaq_faq', $klucze_meta, true ), 'OSIEROCONA meta wtyczki 1 nietknieta — klauzula meta_key jest respektowana' );
u15_check( isset( $GLOBALS['__opt']['aifaq_settings'] ), 'opcja wtyczki 1 nietknieta' );
u15_check( array( 'wp_ainp_items' ) === $GLOBALS['__dropped'], 'skasowana dokladnie jedna tabela: wp_ainp_items' );

$pozostale_termmeta = array_column( $GLOBALS['__termmeta'], 'meta_key' );
u15_check( ! in_array( 'ainp_kolor', $pozostale_termmeta, true ), 'meta terminu uzywanego TYLKO przez nas skasowana' );
u15_check(
	in_array( 'kategoria_ikona', $pozostale_termmeta, true ),
	'U10: meta terminu WSPOLDZIELONEGO ZOSTAJE — nalezy do category, ktora nas nie dotyczy'
);
u15_check( in_array( 'obcy_klucz', $pozostale_termmeta, true ), 'meta obcego terminu nietknieta' );

// ---------------------------------------------------------------------------
echo "\n===================================================================\n";
echo 'Asercji: ' . $ran . ' | Niezaliczonych: ' . $fail . "\n";
if ( 0 === $fail ) {
	echo "WYNIK: WSZYSTKIE OK\n";
	exit( 0 );
}
echo "WYNIK: SA BLEDY\n";
exit( 1 );
