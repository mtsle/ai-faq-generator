<?php
/**
 * Plugin Name:       AI News Portal
 * Plugin URI:        https://github.com/mtsle/ai-faq-generator
 * Description:       Portal wiedzy, ktory zasila sie sam: wtyczka pobiera wpisy z kanalow RSS, odsiewa je slowami wykluczajacymi, przepisuje modelem Gemini i publikuje w Centrum Wiedzy pod adresem /centrum-wiedzy/.
 * Version:           0.3.0
 * Requires at least: 6.5
 * Tested up to:      7.0.2
 * Requires PHP:      8.1
 * Author:            mtsle
 * Author URI:        https://github.com/mtsle
 * License:           GPLv2
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ai-news-portal
 *
 * @package AI_News_Portal
 *
 * Copyright (C) 2026 mtsle
 *
 * This program is free software; you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation; version 2 of the License.
 * Pelny tekst licencji: plik LICENSE w katalogu wtyczki.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
 * more details.
 */

// Blokada bezposredniego wywolania pliku.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// --- Stale wtyczki ---------------------------------------------------------
define( 'AINP_VERSION', '0.3.0' );
define( 'AINP_PLUGIN_FILE', __FILE__ );
define( 'AINP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AINP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'AINP_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/*
 * --- Ladowanie klas --------------------------------------------------------
 *
 * JAWNA lista `require_once`, bez autoloadera. Przestrzen nazw `AINP\` sama
 * niczego nie laduje (namespace to nie autoloader) — plik wpisany tutaj to
 * jedyny sposob, w jaki klasa trafia do pamieci.
 *
 * Lista rosnie razem z kazdym nowym plikiem w `src/`. Wpisujemy WYLACZNIE
 * klasy, ktore juz istnieja: `require_once` nieistniejacego pliku to blad
 * krytyczny, ktory wywraca cala witryne, a nie samo wywolanie wtyczki.
 * Kolejnosc ma znaczenie tylko tyle, ze `Plugin` i `Admin` siegaja do
 * `Settings` dopiero w czasie wykonania, nie przy ladowaniu pliku.
 *
 * Krok 1 zamknal sie na trzech klasach. W Kroku 2 doszly: Http (2.1), Feed (2.2),
 * Dedup (2.3). W Kroku 3: Filter (3.1) i Article (3.3); dalej Gemini, Validator,
 * Publisher, Portal.
 *
 * `Filter` stoi PO `Dedup`, bo `Filter::normalize()` wola `Dedup::normalize_text()`.
 * W praktyce kolejnosc i tak nie ma znaczenia (wywolanie nastepuje w czasie
 * wykonania, nie przy ladowaniu), ale lista czytana z gory ma pokazywac
 * zaleznosci, a nie je ukrywac.
 */
require_once AINP_PLUGIN_DIR . 'src/Settings.php';
require_once AINP_PLUGIN_DIR . 'src/Http.php';
require_once AINP_PLUGIN_DIR . 'src/Feed.php';
require_once AINP_PLUGIN_DIR . 'src/Dedup.php';
require_once AINP_PLUGIN_DIR . 'src/Filter.php';
require_once AINP_PLUGIN_DIR . 'src/Article.php';
require_once AINP_PLUGIN_DIR . 'src/Runner.php';
require_once AINP_PLUGIN_DIR . 'src/Plugin.php';
require_once AINP_PLUGIN_DIR . 'src/Admin.php';

// --- Hooki cyklu zycia -----------------------------------------------------
register_activation_hook( __FILE__, array( 'AINP\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'AINP\\Plugin', 'deactivate' ) );

// --- Start -----------------------------------------------------------------
add_action( 'plugins_loaded', array( 'AINP\\Plugin', 'boot' ) );

