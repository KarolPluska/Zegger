<?php
/**
 * Plugin Name: Zegger ERP
 * Description: Kompletny system ERP Zegger z zachowanym modułem Panel Ofertowy, komunikatorem, relacjami firm, źródłami danych i centrum powiadomień.
 * Version: 1.0.0
 * Author: ZEGGER TECH
 * Requires at least: 6.4
 * Requires PHP: 8.1
 */

if (!defined('ABSPATH')) {
    exit;
}

define('ZERP_VERSION', '1.0.0');
define('ZERP_PLUGIN_FILE', __FILE__);
define('ZERP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ZERP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('ZERP_REST_NS', 'zegger-erp/v1');

require_once ZERP_PLUGIN_DIR . 'includes/class-zerp-db.php';
require_once ZERP_PLUGIN_DIR . 'includes/class-zerp-permissions.php';
require_once ZERP_PLUGIN_DIR . 'includes/class-zerp-auth.php';
require_once ZERP_PLUGIN_DIR . 'includes/class-zerp-migration.php';
require_once ZERP_PLUGIN_DIR . 'includes/class-zerp-companies.php';
require_once ZERP_PLUGIN_DIR . 'includes/class-zerp-members.php';
require_once ZERP_PLUGIN_DIR . 'includes/class-zerp-relations.php';
require_once ZERP_PLUGIN_DIR . 'includes/class-zerp-sources-google.php';
require_once ZERP_PLUGIN_DIR . 'includes/class-zerp-catalog.php';
require_once ZERP_PLUGIN_DIR . 'includes/class-zerp-offers.php';
require_once ZERP_PLUGIN_DIR . 'includes/class-zerp-offer-pdf.php';
require_once ZERP_PLUGIN_DIR . 'includes/class-zerp-chat.php';
require_once ZERP_PLUGIN_DIR . 'includes/class-zerp-notifications.php';
require_once ZERP_PLUGIN_DIR . 'includes/class-zerp-maintenance.php';
require_once ZERP_PLUGIN_DIR . 'includes/class-zerp-admin.php';
require_once ZERP_PLUGIN_DIR . 'includes/class-zerp-app.php';
require_once ZERP_PLUGIN_DIR . 'includes/class-zerp-rest.php';
require_once ZERP_PLUGIN_DIR . 'includes/class-zerp-legacy-compat.php';

register_activation_hook(__FILE__, static function () {
    ZERP_DB::activate();
    ZERP_Maintenance::activate();
    ZERP_Legacy_Compat::activate();
    ZERP_Migration::run_full_migration();
    flush_rewrite_rules();
});

register_deactivation_hook(__FILE__, static function () {
    ZERP_Maintenance::deactivate();
    ZERP_Legacy_Compat::deactivate();
    flush_rewrite_rules();
});

add_action('plugins_loaded', static function () {
    ZERP_DB::init();
    ZERP_Legacy_Compat::init();
    ZERP_Auth::init();
    ZERP_Companies::init();
    ZERP_Members::init();
    ZERP_Relations::init();
    ZERP_Sources_Google::init();
    ZERP_Catalog::init();
    ZERP_Offers::init();
    ZERP_Offer_PDF::init();
    ZERP_Chat::init();
    ZERP_Notifications::init();
    ZERP_Maintenance::init();
    ZERP_Admin::init();
    ZERP_App::init();
    ZERP_Rest::init();
});
