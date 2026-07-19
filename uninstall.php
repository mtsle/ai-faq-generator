<?php
/**
 * Odinstalowanie wtyczki — pełne sprzątanie.
 *
 * Uruchamiane przez WordPress WYŁĄCZNIE przy usuwaniu wtyczki
 * (nie przy deaktywacji). Usuwa tabele wtyczki oraz opcje.
 *
 * @package AI_FAQ_Generator
 */

// Zabezpieczenie: plik może być wywołany tylko przez mechanizm usuwania WP.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Usuń tabele wtyczki (schema v4 + stara historia v1).
$aifaq_tables = array(
	$wpdb->prefix . 'aifaq_knowledge',
	$wpdb->prefix . 'aifaq_qa_log',
	$wpdb->prefix . 'aifaq_cache',
	$wpdb->prefix . 'aifaq_faq',
	$wpdb->prefix . 'aifaq_generations',
	$wpdb->prefix . 'aifaq_history',
);

foreach ( $aifaq_tables as $aifaq_table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$aifaq_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL
}

// Usuń opcje i flagi wtyczki.
delete_option( 'aifaq_db_version' );
delete_option( 'aifaq_settings' );
delete_option( 'aifaq_history_migrated' );
delete_option( 'aifaq_page_id' );
delete_option( 'aifaq_page_bootstrapped' );

// Kaskada źródeł treści (Krok 17): stan kolejki pobierania, wynik testu pętli
// zwrotnej i flaga komunikatu o koszcie. Literały zamiast stałych klas —
// uninstall.php działa bez bootstrapu wtyczki (klasy nie są załadowane).
delete_option( 'aifaq_crawl_state' );
delete_option( 'aifaq_loopback' );
delete_option( 'aifaq_crawl_notice' );
// Lock paczki crawla — przy braku trwałego object cache siedzi w wp_options
// (CrawlQueue::LOCK), więc bez tego zostałby po odinstalowaniu wtyczki.
delete_option( 'aifaq_crawl_lock' );

// Zdejmij cron pobierania stron — bez tego zostałoby zadanie bez obsługi.
if ( function_exists( 'wp_unschedule_hook' ) ) {
	wp_unschedule_hook( 'aifaq_crawl_tick' );
}

// Wyrenderowany HTML podstron zapisany w postmeta (`RenderedContentSource::META_KEY`).
// To kopia treści witryny — przy usuwaniu wtyczki nie ma powodu jej trzymać.
if ( function_exists( 'delete_post_meta_by_key' ) ) {
	delete_post_meta_by_key( '_aifaq_rendered' );
}

// UWAGA: samej podstrony „Generator FAQ" NIE kasujemy — to treść w witrynie
// klienta (mógł ją przerobić, podlinkować w menu). Usunięcie zostawiamy jemu.
