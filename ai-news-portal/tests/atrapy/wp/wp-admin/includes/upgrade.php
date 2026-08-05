<?php
/**
 * Atrapa `wp-admin/includes/upgrade.php` z rdzenia WordPressa.
 *
 * `Plugin::create_table()` robi `require_once ABSPATH . 'wp-admin/includes/upgrade.php'`,
 * bo `dbDelta()` nie jest ladowane domyslnie. W tescie nie ma prawdziwego
 * WordPressa, a `require_once` nieistniejacego pliku to blad krytyczny —
 * dlatego atrapa lezy pod sciezka, ktora testy podstawiaja jako `ABSPATH`.
 *
 * `dbDelta()` niczego nie wykonuje: zapisuje otrzymany SQL, zeby test mogl
 * sprawdzic schemat tabeli (dwa nazwane UNIQUE, LONGTEXT, charset collate).
 *
 * @package AI_News_Portal
 */

if ( ! function_exists( 'dbDelta' ) ) {
	/**
	 * Atrapa `dbDelta()` — rejestruje SQL zamiast go wykonywac.
	 *
	 * @param string|array $queries Zapytania CREATE TABLE.
	 *
	 * @return array
	 */
	function dbDelta( $queries = '' ) {
		$GLOBALS['__ainp_dbdelta'][] = is_array( $queries ) ? implode( "\n", $queries ) : (string) $queries;
		return array();
	}
}
