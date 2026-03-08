<?php
if (!defined('ABSPATH')) {
    exit;
}

final class ZERP_App
{
    public static function init(): void
    {
        add_filter('query_vars', array(__CLASS__, 'query_vars'));
        add_action('template_redirect', array(__CLASS__, 'maybe_render_app'), 1);
    }

    public static function query_vars(array $vars): array
    {
        $vars[] = 'zegger_erp';
        $vars[] = 'module';
        return $vars;
    }

    public static function maybe_render_app(): void
    {
        $is_erp = (string) get_query_var('zegger_erp', '') === '1';
        if (!$is_erp) {
            return;
        }

        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');

        $config = array(
            'rest_base' => esc_url_raw(home_url('/?rest_route=/' . ZERP_REST_NS . '/')),
            'app_url' => esc_url_raw(add_query_arg(array('zegger_erp' => '1'), home_url('/'))),
            'legacy_offer_panel_url' => esc_url_raw(add_query_arg(array('zq_offer_panel' => '1', 'embed' => '1'), home_url('/'))),
            'version' => ZERP_VERSION,
            'assets_url' => ZERP_PLUGIN_URL . 'assets/',
            'nonce' => wp_create_nonce('wp_rest'),
            'timezone' => wp_timezone_string(),
        );

        $css = self::read_asset('assets/css/erp-shell.css');
        $js = self::read_asset('assets/js/erp-shell.js');

        echo '<!doctype html>';
        echo '<html lang="pl">';
        echo '<head>';
        echo '<meta charset="utf-8">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">';
        echo '<title>Zegger ERP</title>';
        echo '<style>' . $css . '</style>';
        echo '</head>';
        echo '<body class="zerp-app-body">';

        echo '<div id="zerp-app" class="zerp-app">';
        echo '  <header class="zerp-topbar">';
        echo '    <div class="zerp-brand-wrap">';
        echo '      <div class="zerp-brand-mark">ZE</div>';
        echo '      <div>';
        echo '        <div class="zerp-brand-name">Zegger ERP</div>';
        echo '        <div class="zerp-brand-sub" id="zerp-current-module-label">Dashboard</div>';
        echo '      </div>';
        echo '    </div>';
        echo '    <div class="zerp-topbar-actions">';
        echo '      <button type="button" class="zerp-icon-btn" id="zerp-open-communicator" title="Komunikator">💬</button>';
        echo '      <button type="button" class="zerp-icon-btn" id="zerp-open-notifications" title="Powiadomienia">🔔<span id="zerp-notification-count" class="zerp-badge">0</span></button>';
        echo '      <button type="button" class="zerp-user-btn" id="zerp-user-menu-btn">Konto</button>';
        echo '    </div>';
        echo '  </header>';

        echo '  <div id="zerp-impersonation-banner" class="zerp-impersonation-banner" hidden></div>';

        echo '  <main class="zerp-main">';
        echo '    <aside class="zerp-sidebar" id="zerp-sidebar"></aside>';
        echo '    <section class="zerp-content">';
        echo '      <div id="zerp-auth-view" class="zerp-auth-view"></div>';
        echo '      <div id="zerp-module-view" class="zerp-module-view" hidden></div>';
        echo '    </section>';
        echo '  </main>';
        echo '</div>';

        echo '<div id="zerp-modal-root"></div>';
        echo '<script>window.ZEGGER_ERP=' . wp_json_encode($config) . ';</script>';
        echo '<script>' . $js . '</script>';
        echo '</body>';
        echo '</html>';
        exit;
    }

    private static function read_asset(string $path): string
    {
        $full = ZERP_PLUGIN_DIR . ltrim($path, '/');
        if (!is_readable($full)) {
            return '';
        }

        $content = file_get_contents($full);
        return is_string($content) ? $content : '';
    }
}
