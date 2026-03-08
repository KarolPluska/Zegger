<?php
if (!defined('ABSPATH')) {
    exit;
}

final class ZERP_Sources_Google
{
    public const CRON_HOOK = 'zerp_google_sync';
    public const LOCK_PREFIX = 'zerp_google_sync_lock_';

    public static function init(): void
    {
        add_action(self::CRON_HOOK, array(__CLASS__, 'cron_run'));
        add_filter('cron_schedules', array(__CLASS__, 'cron_schedules'));

        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 90, 'zerp_5min', self::CRON_HOOK);
        }
    }

    public static function cron_schedules(array $schedules): array
    {
        if (!isset($schedules['zerp_1min'])) {
            $schedules['zerp_1min'] = array('interval' => MINUTE_IN_SECONDS, 'display' => 'Zegger ERP - 1 min');
        }
        if (!isset($schedules['zerp_5min'])) {
            $schedules['zerp_5min'] = array('interval' => 5 * MINUTE_IN_SECONDS, 'display' => 'Zegger ERP - 5 min');
        }
        if (!isset($schedules['zerp_10min'])) {
            $schedules['zerp_10min'] = array('interval' => 10 * MINUTE_IN_SECONDS, 'display' => 'Zegger ERP - 10 min');
        }
        if (!isset($schedules['zerp_15min'])) {
            $schedules['zerp_15min'] = array('interval' => 15 * MINUTE_IN_SECONDS, 'display' => 'Zegger ERP - 15 min');
        }
        return $schedules;
    }

    public static function cron_run(): void
    {
        global $wpdb;
        $t = ZERP_DB::tables();
        $sources = $wpdb->get_results("SELECT company_id FROM {$t['google_sources']} WHERE sync_enabled = 1", ARRAY_A);
        if (!$sources) {
            return;
        }

        foreach ($sources as $source) {
            $company_id = (int) ($source['company_id'] ?? 0);
            if ($company_id <= 0) {
                continue;
            }
            self::sync_company($company_id, false);
        }
    }

    public static function get_source(int $company_id): ?array
    {
        global $wpdb;
        $t = ZERP_DB::tables();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['google_sources']} WHERE company_id = %d LIMIT 1", $company_id), ARRAY_A);
        if (!$row) {
            return null;
        }

        $row['tabs'] = !empty($row['tabs_json']) ? json_decode((string) $row['tabs_json'], true) : array();
        if (!is_array($row['tabs'])) {
            $row['tabs'] = array();
        }
        unset($row['tabs_json']);

        $row['company_id'] = (int) $row['company_id'];
        $row['sync_interval_minutes'] = (int) $row['sync_interval_minutes'];
        $row['sync_enabled'] = !empty($row['sync_enabled']) ? 1 : 0;
        $row['last_sync_ok'] = !empty($row['last_sync_ok']) ? 1 : 0;

        return $row;
    }

    public static function configure_source(int $company_id, array $payload): array
    {
        $member = ZERP_Auth::require_member();
        if (!$member) {
            return array('ok' => false, 'message' => 'Brak autoryzacji.');
        }
        if ((int) $member['company_id'] !== $company_id) {
            return array('ok' => false, 'message' => 'Brak dostępu do firmy.');
        }
        if (!ZERP_Auth::can('can_manage_google_source')) {
            return array('ok' => false, 'message' => 'Brak uprawnień do konfiguracji źródła Google.');
        }

        $sheet_pub_id = sanitize_text_field((string) ($payload['sheet_pub_id'] ?? ''));
        if (!$sheet_pub_id) {
            return array('ok' => false, 'message' => 'Brak sheet_pub_id.');
        }

        $tabs = isset($payload['tabs']) && is_array($payload['tabs']) ? $payload['tabs'] : array();
        $norm_tabs = array();
        foreach ($tabs as $tab) {
            if (!is_array($tab)) {
                continue;
            }
            $name = sanitize_text_field((string) ($tab['name'] ?? ''));
            $gid = sanitize_text_field((string) ($tab['gid'] ?? ''));
            if (!$name || !$gid) {
                continue;
            }
            $norm_tabs[] = array('name' => $name, 'gid' => $gid);
        }

        $interval = isset($payload['sync_interval_minutes']) ? (int) $payload['sync_interval_minutes'] : 10;
        if (!in_array($interval, array(1, 5, 10, 15), true)) {
            $interval = 10;
        }

        $sync_enabled = isset($payload['sync_enabled']) ? (!empty($payload['sync_enabled']) ? 1 : 0) : 1;

        global $wpdb;
        $t = ZERP_DB::tables();
        $exists = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['google_sources']} WHERE company_id = %d", $company_id));

        $data = array(
            'sheet_pub_id' => $sheet_pub_id,
            'tabs_json' => wp_json_encode($norm_tabs),
            'sync_interval_minutes' => $interval,
            'sync_enabled' => $sync_enabled,
            'updated_at' => current_time('mysql'),
        );

        if ($exists > 0) {
            $wpdb->update($t['google_sources'], $data, array('company_id' => $company_id), array('%s', '%s', '%d', '%d', '%s'), array('%d'));
        } else {
            $data['company_id'] = $company_id;
            $data['created_at'] = current_time('mysql');
            $data['last_sync_ok'] = 0;
            $wpdb->insert($t['google_sources'], $data, array('%s', '%s', '%d', '%d', '%s', '%d', '%s', '%d'));
        }

        return array('ok' => true, 'source' => self::get_source($company_id));
    }

    public static function force_sync(int $company_id): array
    {
        $member = ZERP_Auth::require_member();
        if (!$member) {
            return array('ok' => false, 'message' => 'Brak autoryzacji.');
        }
        if ((int) $member['company_id'] !== $company_id) {
            return array('ok' => false, 'message' => 'Brak dostępu do firmy.');
        }
        if (!ZERP_Auth::can('can_force_google_sync')) {
            return array('ok' => false, 'message' => 'Brak uprawnień do ręcznej synchronizacji.');
        }

        return self::sync_company($company_id, true);
    }

    public static function get_cache(int $company_id): ?array
    {
        global $wpdb;
        $t = ZERP_DB::tables();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['google_cache']} WHERE company_id = %d LIMIT 1", $company_id), ARRAY_A);
        if (!$row) {
            return null;
        }

        $cache = !empty($row['cache_json']) ? json_decode((string) $row['cache_json'], true) : null;
        if (!is_array($cache)) {
            return null;
        }

        return $cache;
    }

    public static function sync_company(int $company_id, bool $force): array
    {
        $source = self::get_source($company_id);
        if (!$source || empty($source['sheet_pub_id'])) {
            return array('ok' => false, 'message' => 'Brak konfiguracji Google Source.');
        }

        $lock_key = self::LOCK_PREFIX . $company_id;
        if (get_transient($lock_key) && !$force) {
            return array('ok' => false, 'message' => 'Synchronizacja już trwa.');
        }

        set_transient($lock_key, 1, 5 * MINUTE_IN_SECONDS);
        $started = microtime(true);

        $errors = array();
        $data = array();
        $tabs = isset($source['tabs']) && is_array($source['tabs']) ? $source['tabs'] : array();

        foreach ($tabs as $tab) {
            $name = sanitize_text_field((string) ($tab['name'] ?? ''));
            $gid = sanitize_text_field((string) ($tab['gid'] ?? ''));
            if (!$name || !$gid) {
                $errors[] = 'Niepoprawna konfiguracja tab (name/gid).';
                continue;
            }

            $matrix = self::fetch_tab_matrix((string) $source['sheet_pub_id'], $gid, $name, $errors);
            if (!$matrix) {
                continue;
            }

            $data[$name] = $matrix;
        }

        $cache = array(
            'ok' => empty($errors),
            'fetched_at' => current_time('mysql'),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'errors' => $errors,
            'data' => $data,
            'data_hash' => hash('sha256', wp_json_encode($data)),
        );

        global $wpdb;
        $t = ZERP_DB::tables();

        $wpdb->replace(
            $t['google_cache'],
            array(
                'company_id' => $company_id,
                'data_hash' => (string) $cache['data_hash'],
                'cache_json' => wp_json_encode($cache),
                'fetched_at' => (string) $cache['fetched_at'],
                'updated_at' => (string) $cache['fetched_at'],
            ),
            array('%d', '%s', '%s', '%s', '%s')
        );

        $wpdb->update(
            $t['google_sources'],
            array(
                'last_sync_at' => (string) $cache['fetched_at'],
                'last_sync_ok' => empty($errors) ? 1 : 0,
                'last_sync_error' => empty($errors) ? null : sanitize_textarea_field(implode("\n", $errors)),
                'updated_at' => (string) $cache['fetched_at'],
            ),
            array('company_id' => $company_id),
            array('%s', '%d', '%s', '%s'),
            array('%d')
        );

        delete_transient($lock_key);

        return array('ok' => empty($errors), 'cache' => $cache, 'message' => empty($errors) ? 'OK' : 'Synchronizacja zakończona z błędami.');
    }

    private static function fetch_tab_matrix(string $sheet_pub_id, string $gid, string $name, array &$errors): ?array
    {
        $url = 'https://docs.google.com/spreadsheets/d/e/' . rawurlencode($sheet_pub_id) . '/gviz/tq?tqx=out:json&gid=' . rawurlencode($gid);

        $response = wp_remote_get($url, array(
            'timeout' => 20,
            'redirection' => 5,
            'headers' => array(
                'Accept' => 'application/json,text/plain,*/*',
                'User-Agent' => 'ZeggerERP/' . ZERP_VERSION . ' ' . home_url('/'),
            ),
        ));

        if (is_wp_error($response)) {
            $errors[] = $name . ': ' . $response->get_error_message();
            return null;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        if ($code < 200 || $code >= 300 || !$body) {
            $errors[] = $name . ': HTTP ' . $code;
            return null;
        }

        $gviz = self::parse_gviz_json($body);
        if (!$gviz || empty($gviz['table'])) {
            $errors[] = $name . ': nie udało się zdekodować odpowiedzi gviz.';
            return null;
        }

        $matrix = self::table_to_matrix($gviz);
        if (!$matrix || empty($matrix['headers'])) {
            $errors[] = $name . ': brak nagłówków.';
            return null;
        }

        return $matrix;
    }

    public static function parse_gviz_json(string $body): ?array
    {
        $body = trim($body);
        $start = strpos($body, '{');
        $end = strrpos($body, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $json = substr($body, $start, $end - $start + 1);
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    }

    public static function table_to_matrix(array $gviz): ?array
    {
        if (empty($gviz['table']) || !is_array($gviz['table'])) {
            return null;
        }

        $table = $gviz['table'];
        $cols = isset($table['cols']) && is_array($table['cols']) ? $table['cols'] : array();
        $rows = isset($table['rows']) && is_array($table['rows']) ? $table['rows'] : array();

        if (!$cols) {
            return null;
        }

        $headers = array();
        foreach ($cols as $col) {
            $headers[] = sanitize_text_field((string) ($col['label'] ?? ''));
        }

        $out_rows = array();
        foreach ($rows as $row) {
            $cells = isset($row['c']) && is_array($row['c']) ? $row['c'] : array();
            $line = array();

            foreach ($cells as $cell) {
                if (!is_array($cell) || !array_key_exists('v', $cell)) {
                    $line[] = '';
                    continue;
                }
                if (is_scalar($cell['v']) || $cell['v'] === null) {
                    $line[] = (string) $cell['v'];
                } else {
                    $line[] = '';
                }
            }

            while (count($line) < count($headers)) {
                $line[] = '';
            }

            $out_rows[] = $line;
        }

        return array(
            'headers' => $headers,
            'rows' => $out_rows,
        );
    }
}

