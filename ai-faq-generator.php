<?php
/**
 * Plugin Name:       AI FAQ Generator
 * Plugin URI:        https://github.com/mtsle/ai-faq-generator
 * Description:       Szybkie generowanie sekcji FAQ dla wpisów i stron z użyciem API AI (Google Gemini) oraz automatyczne dane strukturalne JSON-LD (FAQPage) zgodne ze Schema.org.
 * Version:           0.2.0
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
define( 'AIFAQ_VERSION', '0.2.0' );
define( 'AIFAQ_DB_VERSION', '1' );
define( 'AIFAQ_PLUGIN_FILE', __FILE__ );
define( 'AIFAQ_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AIFAQ_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'AIFAQ_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// --- Rdzeń -----------------------------------------------------------------
require_once AIFAQ_PLUGIN_DIR . 'includes/class-activator.php';
require_once AIFAQ_PLUGIN_DIR . 'includes/class-deactivator.php';
require_once AIFAQ_PLUGIN_DIR . 'includes/class-plugin.php';

// --- Hooki cyklu życia -----------------------------------------------------
register_activation_hook( __FILE__, array( 'AIFAQ_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'AIFAQ_Deactivator', 'deactivate' ) );

// --- Start -----------------------------------------------------------------
add_action( 'plugins_loaded', array( 'AIFAQ_Plugin', 'instance' ) );
