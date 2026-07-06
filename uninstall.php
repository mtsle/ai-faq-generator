<?php
/**
 * Odinstalowanie wtyczki — pełne sprzątanie.
 *
 * Uruchamiane przez WordPress WYŁĄCZNIE przy usuwaniu wtyczki
 * (nie przy deaktywacji). Usuwa tabelę historii oraz opcje.
 *
 * @package AI_FAQ_Generator
 */

// Zabezpieczenie: plik może być wywołany tylko przez mechanizm usuwania WP.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Usuń tabelę historii.
$table_name = $wpdb->prefix . 'aifaq_history';
$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL

// Usuń opcje wtyczki.
delete_option( 'aifaq_db_version' );
delete_option( 'aifaq_settings' );
