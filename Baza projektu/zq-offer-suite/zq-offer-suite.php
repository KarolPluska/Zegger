<?php
/**
 * Plugin Name: ZQ Offer Suite (Panel ofertowy)
 * Description: Panel ofertowy (logowanie handlowców, cache arkusza Google, klienci, historia ofert, eksport PDF przez host kalkulatora).
 * Version: 1.2.18.7
 * Author: ZEGGER TECH
 * License: Proprietary
 *
 * © 2019–2026 ZEGGER TECH Sp. z o.o. Wszelkie prawa zastrzeżone.
 * Ten plugin (PHP/JS/CSS) jest utworem w rozumieniu prawa autorskiego.
 * Kopiowanie, rozpowszechnianie lub modyfikacja dozwolone wyłącznie za zgodą właściciela praw.
 */

if (!defined('ABSPATH')) { exit; }

define('ZQOS_VERSION', '1.2.18.7');
define('ZQOS_PLUGIN_FILE', __FILE__);
define('ZQOS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ZQOS_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once ZQOS_PLUGIN_DIR . 'includes/class-zqos-db.php';
require_once ZQOS_PLUGIN_DIR . 'includes/class-zqos-sheets.php';
require_once ZQOS_PLUGIN_DIR . 'includes/class-zqos-auth.php';
require_once ZQOS_PLUGIN_DIR . 'includes/class-zqos-rest.php';
require_once ZQOS_PLUGIN_DIR . 'includes/class-zqos-admin.php';
require_once ZQOS_PLUGIN_DIR . 'includes/class-zqos-panel.php';
require_once ZQOS_PLUGIN_DIR . 'includes/class-zqos-maintenance.php';
require_once ZQOS_PLUGIN_DIR . 'includes/class-zqos-reminders.php';

register_activation_hook(__FILE__, function(){
  ZQOS_DB::activate();
  ZQOS_Sheets::activate();
  ZQOS_Maintenance::activate();
  ZQOS_Reminders::activate();
  ZQOS_Panel::activate();
  flush_rewrite_rules();
});

register_deactivation_hook(__FILE__, function(){
  ZQOS_Sheets::deactivate();
  ZQOS_Maintenance::deactivate();
  ZQOS_Reminders::deactivate();
  flush_rewrite_rules();
});

add_action('plugins_loaded', function(){
  ZQOS_DB::init();
  ZQOS_Sheets::init();
  ZQOS_Maintenance::init();
  ZQOS_Reminders::init();
  ZQOS_Rest::init();
  ZQOS_Admin::init();
  ZQOS_Panel::init();
});
