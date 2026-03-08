<?php
if (!defined('ABSPATH')) {
    exit;
}

final class ZERP_Admin
{
    public static function init(): void
    {
        if (!is_admin()) {
            return;
        }

        add_action('admin_menu', array(__CLASS__, 'register_menu'));
        add_action('admin_post_zerp_run_migration', array(__CLASS__, 'handle_run_migration'));
        add_action('admin_post_zerp_force_sync_google', array(__CLASS__, 'handle_force_sync_google'));
        add_action('admin_post_zerp_regenerate_join_code', array(__CLASS__, 'handle_regenerate_join_code'));
    }

    public static function register_menu(): void
    {
        add_menu_page(
            'Zegger ERP',
            'Zegger ERP',
            'manage_options',
            'zegger-erp',
            array(__CLASS__, 'page_dashboard'),
            'dashicons-screenoptions',
            57
        );

        add_submenu_page('zegger-erp', 'Migracja', 'Migracja', 'manage_options', 'zegger-erp-migration', array(__CLASS__, 'page_migration'));
        add_submenu_page('zegger-erp', 'Diagnostyka', 'Diagnostyka', 'manage_options', 'zegger-erp-diagnostics', array(__CLASS__, 'page_diagnostics'));
    }

    public static function page_dashboard(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        global $wpdb;
        $t = ZERP_DB::tables();

        $companies = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t['companies']}");
        $members = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t['members']}");
        $offers = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t['offers']}");
        $threads = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t['threads']}");
        $relations = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t['relations']}");

        echo '<div class="wrap">';
        echo '<h1>Zegger ERP - Dashboard</h1>';
        echo '<p>Nowy system ERP z zachowanym modułem Panel Ofertowy legacy.</p>';

        echo '<table class="widefat striped" style="max-width:900px">';
        echo '<thead><tr><th>Metryka</th><th>Wartość</th></tr></thead><tbody>';
        echo '<tr><td>Firmy</td><td><strong>' . esc_html((string) $companies) . '</strong></td></tr>';
        echo '<tr><td>Użytkownicy</td><td><strong>' . esc_html((string) $members) . '</strong></td></tr>';
        echo '<tr><td>Oferty ERP</td><td><strong>' . esc_html((string) $offers) . '</strong></td></tr>';
        echo '<tr><td>Wątki komunikatora</td><td><strong>' . esc_html((string) $threads) . '</strong></td></tr>';
        echo '<tr><td>Relacje firma-firma</td><td><strong>' . esc_html((string) $relations) . '</strong></td></tr>';
        echo '</tbody></table>';

        $erp_url = add_query_arg(array('zegger_erp' => '1'), home_url('/'));
        $legacy_panel_url = add_query_arg(array('zq_offer_panel' => '1', 'embed' => '1'), home_url('/'));

        echo '<p style="margin-top:16px">';
        echo '<a class="button button-primary" href="' . esc_url($erp_url) . '" target="_blank" rel="noopener">Otwórz Zegger ERP</a> ';
        echo '<a class="button" href="' . esc_url($legacy_panel_url) . '" target="_blank" rel="noopener">Otwórz legacy Panel Ofertowy</a>';
        echo '</p>';

        echo '</div>';
    }

    public static function page_migration(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $state = get_option(ZERP_DB::OPT_MIGRATION, array());
        if (!is_array($state)) {
            $state = array();
        }

        echo '<div class="wrap">';
        echo '<h1>Zegger ERP - Migracja</h1>';
        echo '<p>Uruchamiaj migrację po aktywacji pluginu lub po imporcie danych legacy.</p>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('zerp_run_migration');
        echo '<input type="hidden" name="action" value="zerp_run_migration">';
        submit_button('Uruchom migrację teraz');
        echo '</form>';

        if (!empty($state['completed_at'])) {
            echo '<h2>Ostatni wynik</h2>';
            echo '<p><strong>Zakończono:</strong> ' . esc_html((string) $state['completed_at']) . '</p>';
            if (!empty($state['result'])) {
                echo '<pre style="background:#fff;padding:12px;border:1px solid #ddd;overflow:auto;max-width:1000px">' . esc_html(wp_json_encode($state['result'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre>';
            }
        }

        echo '</div>';
    }

    public static function page_diagnostics(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        global $wpdb;
        $t = ZERP_DB::tables();
        $stats = ZERP_Maintenance::storage_diagnostics();
        $issues = ZERP_Maintenance::diagnose_offer_thread_consistency();

        echo '<div class="wrap">';
        echo '<h1>Zegger ERP - Diagnostyka</h1>';

        echo '<h2>Storage</h2>';
        echo '<table class="widefat striped" style="max-width:900px"><tbody>';
        foreach ($stats as $key => $value) {
            echo '<tr><th>' . esc_html((string) $key) . '</th><td>' . esc_html((string) $value) . '</td></tr>';
        }
        echo '</tbody></table>';

        echo '<h2>Spójność powiązań oferta↔wątek</h2>';
        echo '<p>Liczba wykrytych problemów: <strong>' . esc_html((string) ($issues['issues'] ?? 0)) . '</strong></p>';

        $logs = $wpdb->get_results("SELECT * FROM {$t['maintenance_logs']} ORDER BY id DESC LIMIT 50", ARRAY_A);
        echo '<h2>Log maintenance</h2>';
        if (!$logs) {
            echo '<p>Brak wpisów.</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Akcja</th><th>Meta</th><th>Data</th></tr></thead><tbody>';
            foreach ($logs as $log) {
                echo '<tr>';
                echo '<td>' . esc_html((string) $log['id']) . '</td>';
                echo '<td>' . esc_html((string) $log['action_key']) . '</td>';
                echo '<td><code>' . esc_html((string) $log['meta_json']) . '</code></td>';
                echo '<td>' . esc_html((string) $log['created_at']) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        echo '</div>';
    }

    public static function handle_run_migration(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden');
        }
        check_admin_referer('zerp_run_migration');

        ZERP_Migration::run_full_migration();

        wp_safe_redirect(admin_url('admin.php?page=zegger-erp-migration'));
        exit;
    }

    public static function handle_force_sync_google(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden');
        }

        $company_id = isset($_POST['company_id']) ? (int) $_POST['company_id'] : 0;
        if ($company_id > 0) {
            ZERP_Sources_Google::sync_company($company_id, true);
        }

        wp_safe_redirect(admin_url('admin.php?page=zegger-erp-diagnostics'));
        exit;
    }

    public static function handle_regenerate_join_code(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden');
        }

        $company_id = isset($_POST['company_id']) ? (int) $_POST['company_id'] : 0;
        if ($company_id > 0) {
            $member = ZERP_Auth::require_member(false);
            if ($member) {
                ZERP_Companies::regenerate_join_code($company_id);
            }
        }

        wp_safe_redirect(admin_url('admin.php?page=zegger-erp'));
        exit;
    }
}
