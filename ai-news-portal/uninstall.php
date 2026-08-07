<?php
/**
 * Odinstalowanie AI News Portal — BEZ SLADU.
 *
 * UWAGA: usuniecie wtyczki kasuje BEZPOWROTNIE caly dorobek portalu — wszystkie
 * artykuly (takze szkice i te w koszu), ich kategorie, tabele materialow,
 * ustawienia i klucz API. Samo WYLACZENIE wtyczki nie rusza niczego; kasuje
 * dopiero „Usun". To samo ostrzezenie idzie wytluszczone do `readme.txt`
 * i do instrukcji dla klienta (Krok 7).
 *
 * Powod takiej decyzji: wtyczka 1 z tej samej paczki czysci po sobie calkowicie.
 * Dwie wtyczki oddawane razem nie moga sprzatac odwrotnie.
 *
 * GOTCHA: w tym pliku wtyczka NIE jest zaladowana. Ani CPT, ani taksonomia nie
 * sa zarejestrowane, wiec `get_posts()`, `get_terms()` i `wp_delete_term()`
 * nie maja czego znalezc albo zwracaja `WP_Error` — identyfikatory wyciagamy
 * wprost zapytaniem do bazy.
 *
 * @package AI_News_Portal
 */

// Ten plik uruchamia WYLACZNIE WordPress przy usuwaniu wtyczki.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$ainp_cpt   = 'ainp_article';
$ainp_tax   = 'ainp_topic';
$ainp_table = $wpdb->prefix . 'ainp_items';

// ---------------------------------------------------------------------------
// 1. Artykuly — wszystkie statusy, razem ze szkicami i koszem.
// ---------------------------------------------------------------------------
$ainp_post_ids = $wpdb->get_col(
	$wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", $ainp_cpt )
);

if ( $ainp_post_ids ) {
	$ainp_post_ids = array_map( 'intval', $ainp_post_ids );
	$ainp_lista    = implode( ',', $ainp_post_ids );

	/*
	 * Powiazania z terminami kasujemy SAMI. `wp_delete_post()` zdejmuje je
	 * przez `get_object_taxonomies( $post->post_type )`, a ta funkcja przy
	 * niezarejestrowanym CPT zwraca pusta tablice — bez tego zapytania
	 * w `term_relationships` zostalyby sieroty.
	 */
	$wpdb->query( "DELETE FROM {$wpdb->term_relationships} WHERE object_id IN ({$ainp_lista})" );

	// Wpis + jego meta, wersje i komentarze. Bez `true` wpis wyladowalby w koszu.
	foreach ( $ainp_post_ids as $ainp_post_id ) {
		wp_delete_post( $ainp_post_id, true );
	}
}

// Meta osierocone (wpis skasowany wczesniej reka klienta, meta zostala).
$wpdb->query(
	"DELETE pm FROM {$wpdb->postmeta} pm
	 LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
	 WHERE p.ID IS NULL AND pm.meta_key IN ( '_ainp_item_id', '_ainp_source_url', '_ainp_demo' )"
);

// ---------------------------------------------------------------------------
// 2. Kategorie artykulow (taksonomia `ainp_topic`).
// ---------------------------------------------------------------------------
$ainp_terms = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT term_id, term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s",
		$ainp_tax
	)
);

if ( $ainp_terms ) {
	$ainp_term_ids = array_map( static fn( $t ) => (int) $t->term_id, $ainp_terms );
	$ainp_tt_ids   = array_map( static fn( $t ) => (int) $t->term_taxonomy_id, $ainp_terms );

	$ainp_tt_lista   = implode( ',', $ainp_tt_ids );
	$ainp_term_lista = implode( ',', $ainp_term_ids );

	$wpdb->query( "DELETE FROM {$wpdb->term_relationships} WHERE term_taxonomy_id IN ({$ainp_tt_lista})" );
	$wpdb->query( "DELETE FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id IN ({$ainp_tt_lista})" );

	/*
	 * Sam termin kasujemy tylko wtedy, gdy nie uzywa go zadna inna taksonomia —
	 * `wp_terms` jest wspolne dla calego WordPressa.
	 */
	$wpdb->query(
		"DELETE t FROM {$wpdb->terms} t
		 LEFT JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
		 WHERE t.term_id IN ({$ainp_term_lista}) AND tt.term_taxonomy_id IS NULL"
	);

	/*
	 * Meta terminow — DOPIERO TERAZ i tylko dla tych, ktore naprawde znikly.
	 *
	 * Kasowanie po samym `term_id` zabieralo `termmeta` takze terminowi
	 * WSPOLDZIELONEMU z inna taksonomia, ktory zostaje w `wp_terms`. Wtyczka
	 * nigdy nie zapisuje wlasnych `termmeta`, wiec kazdy taki wiersz jest
	 * z definicji CUDZY — to byloby kasowanie danych innej taksonomii.
	 * Ta sama oslona co przy `wp_terms` wyzej, o jedna tabele dalej.
	 * Ustalenie U10 z testu 15 (Krok 4).
	 */
	$wpdb->query(
		"DELETE tm FROM {$wpdb->termmeta} tm
		 LEFT JOIN {$wpdb->terms} t ON t.term_id = tm.term_id
		 WHERE tm.term_id IN ({$ainp_term_lista}) AND t.term_id IS NULL"
	);
}

// ---------------------------------------------------------------------------
// 3. Tabela materialow.
// ---------------------------------------------------------------------------
$wpdb->query( "DROP TABLE IF EXISTS {$ainp_table}" );

// ---------------------------------------------------------------------------
// 4. Opcje. Najpierw jawna lista, potem zamiatanie po prefiksie.
// ---------------------------------------------------------------------------
$ainp_opcje = array(
	'ainp_settings',
	'ainp_sources',
	'ainp_key',
	'ainp_usage',
	'ainp_slug_collision',
);

foreach ( $ainp_opcje as $ainp_opcja ) {
	delete_option( $ainp_opcja );
}

/*
 * Zamiatanie po prefiksie lapie to, czego jawna lista nie nadgonila, oraz
 * transienty — te maja wlasne prefiksy (`_transient_ainp_…`), wiec wzorzec
 * `ainp_%` sam ich NIE obejmuje.
 */
$ainp_wzorce = array(
	$wpdb->esc_like( 'ainp_' ) . '%',
	$wpdb->esc_like( '_transient_ainp_' ) . '%',
	$wpdb->esc_like( '_transient_timeout_ainp_' ) . '%',
);

foreach ( $ainp_wzorce as $ainp_wzorzec ) {
	$wpdb->query(
		$wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $ainp_wzorzec )
	);
}

// ---------------------------------------------------------------------------
// 5. Cron.
// ---------------------------------------------------------------------------
wp_clear_scheduled_hook( 'ainp_tick' );

/*
 * Zamiatanie zdarzen po prefiksie: gdyby ktorys Krok dolozyl drugie zdarzenie
 * i zapomnial go tu dopisac, i tak zniknie.
 */
$ainp_crony = _get_cron_array();

if ( is_array( $ainp_crony ) ) {
	foreach ( $ainp_crony as $ainp_zdarzenia ) {
		if ( ! is_array( $ainp_zdarzenia ) ) {
			continue;
		}
		foreach ( array_keys( $ainp_zdarzenia ) as $ainp_hook ) {
			if ( is_string( $ainp_hook ) && 0 === strpos( $ainp_hook, 'ainp_' ) ) {
				wp_clear_scheduled_hook( $ainp_hook );
			}
		}
	}
}
