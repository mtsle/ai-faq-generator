<?php
/**
 * Plugin Name:       AI FAQ Generator
 * Plugin URI:        https://github.com/mtsle/ai-faq-generator
 * Description:       Generator FAQ zawężony do tematu strony (RAG + embeddingi Gemini): gość pyta i dostaje odpowiedź wyłącznie w temacie treści strony. Dane strukturalne JSON-LD (FAQPage) zgodne ze Schema.org.
 * Version:           0.23.0
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            mtsle
 * Author URI:        https://github.com/mtsle
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ai-faq-generator
 * Domain Path:       /languages
 *
 * @package AI_FAQ_Generator
 */

// Blokada bezpośredniego wywołania pliku.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// --- Stałe wtyczki ---------------------------------------------------------
define( 'AIFAQ_VERSION', '0.23.0' );
define( 'AIFAQ_DB_VERSION', '4' );
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
