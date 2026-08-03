<?php
/**
 * Plugin Name:       AI FAQ Generator
 * Plugin URI:        https://github.com/mtsle/ai-faq-generator
 * Description:       Generator FAQ zawężony do tematu strony (RAG + embeddingi Gemini): gość pyta i dostaje odpowiedź wyłącznie w temacie treści strony. Dane strukturalne JSON-LD (FAQPage) zgodne ze Schema.org.
 * Version:           0.34.0
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            mtsle
 * Author URI:        https://github.com/mtsle
 * License:           GPLv2
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ai-faq-generator
 * Domain Path:       /languages
 *
 * @package AI_FAQ_Generator
 *
 * Copyright (C) 2026 mtsle
 *
 * This program is free software; you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation; version 2 of the License.
 * Pełny tekst licencji: plik LICENSE w katalogu wtyczki.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
 * more details.
 */

// Blokada bezpośredniego wywołania pliku.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// --- Stałe wtyczki ---------------------------------------------------------
define( 'AIFAQ_VERSION', '0.34.0' );
define( 'AIFAQ_DB_VERSION', '6' );
define( 'AIFAQ_PLUGIN_FILE', __FILE__ );
define( 'AIFAQ_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AIFAQ_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'AIFAQ_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Autoloader PSR-4-lite: przestrzeń nazw `AIFAQ\` → katalog `src/`.
 *
 * Przykład: klasa `AIFAQ\Data\Schema` ładuje się z `src/Data/Schema.php`.
 * Dzięki temu nie ma już ręcznych `require_once` dla każdej klasy.
 */
spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'AIFAQ\\';
		$length = strlen( $prefix );

		// Interesują nas tylko klasy z naszej przestrzeni nazw.
		if ( 0 !== strncmp( $prefix, $class, $length ) ) {
			return;
		}

		$relative = substr( $class, $length );
		$file     = AIFAQ_PLUGIN_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

// --- Hooki cyklu życia -----------------------------------------------------
register_activation_hook( __FILE__, array( 'AIFAQ\\Core\\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'AIFAQ\\Core\\Deactivator', 'deactivate' ) );

// --- Start -----------------------------------------------------------------
add_action( 'plugins_loaded', array( 'AIFAQ\\Core\\Plugin', 'instance' ) );
