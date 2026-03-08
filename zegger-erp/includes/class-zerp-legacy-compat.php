<?php
if (!defined('ABSPATH')) {
    exit;
}

final class ZERP_Legacy_Compat
{
    private static bool $booted = false;

    public static function init(): void
    {
        if (self::$booted) {
            return;
        }
        self::load_legacy_classes();

        if (class_exists('ZQOS_DB')) {
            ZQOS_DB::init();
        }
        if (class_exists('ZQOS_Sheets')) {
            ZQOS_Sheets::init();
        }
        if (class_exists('ZQOS_Maintenance')) {
            ZQOS_Maintenance::init();
        }
        if (class_exists('ZQOS_Reminders')) {
            ZQOS_Reminders::init();
        }
        if (class_exists('ZQOS_Rest')) {
            ZQOS_Rest::init();
        }
        if (class_exists('ZQOS_Panel')) {
            ZQOS_Panel::init();
        }

        self::$booted = true;
    }

    public static function activate(): void
    {
        self::load_legacy_classes();
        if (class_exists('ZQOS_DB')) {
            ZQOS_DB::activate();
        }
        if (class_exists('ZQOS_Sheets')) {
            ZQOS_Sheets::activate();
        }
        if (class_exists('ZQOS_Maintenance')) {
            ZQOS_Maintenance::activate();
        }
        if (class_exists('ZQOS_Reminders')) {
            ZQOS_Reminders::activate();
        }
        if (class_exists('ZQOS_Panel')) {
            ZQOS_Panel::activate();
        }
    }

    public static function deactivate(): void
    {
        if (class_exists('ZQOS_Sheets')) {
            ZQOS_Sheets::deactivate();
        }
        if (class_exists('ZQOS_Maintenance')) {
            ZQOS_Maintenance::deactivate();
        }
        if (class_exists('ZQOS_Reminders')) {
            ZQOS_Reminders::deactivate();
        }
    }

    private static function load_legacy_classes(): void
    {
        if (!defined('ZQOS_VERSION')) {
            define('ZQOS_VERSION', '1.2.18.7');
        }
        if (!defined('ZQOS_PLUGIN_FILE')) {
            define('ZQOS_PLUGIN_FILE', ZERP_PLUGIN_FILE);
        }
        if (!defined('ZQOS_PLUGIN_DIR')) {
            define('ZQOS_PLUGIN_DIR', trailingslashit(ZERP_PLUGIN_DIR . 'legacy/zq-offer-suite'));
        }
        if (!defined('ZQOS_PLUGIN_URL')) {
            define('ZQOS_PLUGIN_URL', trailingslashit(ZERP_PLUGIN_URL . 'legacy/zq-offer-suite'));
        }

        $legacy_files = array(
            ZQOS_PLUGIN_DIR . 'includes/class-zqos-db.php',
            ZQOS_PLUGIN_DIR . 'includes/class-zqos-sheets.php',
            ZQOS_PLUGIN_DIR . 'includes/class-zqos-auth.php',
            ZQOS_PLUGIN_DIR . 'includes/class-zqos-rest.php',
            ZQOS_PLUGIN_DIR . 'includes/class-zqos-panel.php',
            ZQOS_PLUGIN_DIR . 'includes/class-zqos-maintenance.php',
            ZQOS_PLUGIN_DIR . 'includes/class-zqos-reminders.php',
        );

        foreach ($legacy_files as $legacy_file) {
            if (is_readable($legacy_file)) {
                require_once $legacy_file;
            }
        }
    }
}
