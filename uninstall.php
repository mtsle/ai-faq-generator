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

// Usuń tabele wtyczki (schema v2 + stara historia v1).
$aifaq_tables = array(
	$wpdb->prefix . 'aifaq_knowledge',
	$wpdb->prefix . 'aifaq_qa_log',
	$wpdb->prefix . 'aifaq_cache',
	$wpdb->prefix . 'aifaq_faq',
	$wpdb->prefix . 'aifaq_history',
);

foreach ( $aifaq_tables as $aifaq_table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$aifaq_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL
}

// Usuń opcje i flagi wtyczki.
delete_option( 'aifaq_db_version' );
delete_option( 'aifaq_settings' );
delete_option( 'aifaq_history_migrated' );
