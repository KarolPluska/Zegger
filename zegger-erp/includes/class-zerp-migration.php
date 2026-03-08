<?php
if (!defined('ABSPATH')) {
    exit;
}

final class ZERP_Migration
{
    public static function run_full_migration(): array
    {
        $state = get_option(ZERP_DB::OPT_MIGRATION, array());
        if (!is_array($state)) {
            $state = array();
        }

        $result = array(
            'seed' => self::seed_zegger_tech(),
            'google_source' => self::migrate_google_source_settings(),
            'clients' => self::migrate_legacy_clients(),
            'offers' => self::migrate_legacy_offers(),
            'events' => self::migrate_legacy_events(),
        );

        $state['completed_at'] = current_time('mysql');
        $state['result'] = $result;
        update_option(ZERP_DB::OPT_MIGRATION, $state, false);

        return $result;
    }

    public static function seed_zegger_tech(): array
    {
        global $wpdb;
        $t = ZERP_DB::tables();

        $company = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['companies']} WHERE name = %s LIMIT 1", 'Zegger Tech'), ARRAY_A);
        if ($company) {
            return array('ok' => true, 'company_id' => (int) $company['id'], 'created' => false);
        }

        $now = current_time('mysql');
        $join_code = ZERP_DB::generate_join_code();
        while ((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['companies']} WHERE join_code = %s", $join_code)) > 0) {
            $join_code = ZERP_DB::generate_join_code();
        }

        $wpdb->insert(
            $t['companies'],
            array(
                'name' => 'Zegger Tech',
                'nip' => '0000000000',
                'address' => '',
                'company_email' => '',
                'company_phone' => '',
                'join_code' => $join_code,
                'status' => 'active',
                'meta_json' => wp_json_encode(array('seeded' => 1)),
                'created_at' => $now,
                'updated_at' => $now,
            ),
            array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
        );

        $company_id = (int) $wpdb->insert_id;

        $bootstrap = get_option('zqos_bootstrap_creds', array());
        $login = !empty($bootstrap['login']) ? sanitize_user((string) $bootstrap['login'], true) : 'owner';
        if (!$login) {
            $login = 'owner';
        }

        $pass_plain = !empty($bootstrap['pass']) ? (string) $bootstrap['pass'] : wp_generate_password(16, true, true);

        if ((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['members']} WHERE login = %s", $login)) > 0) {
            $login = 'owner_' . wp_generate_password(4, false, false);
        }

        $owner_email = sanitize_email($login . '@zegger.local');
        $wpdb->insert(
            $t['members'],
            array(
                'company_id' => $company_id,
                'login' => $login,
                'email' => $owner_email,
                'phone' => '',
                'first_name' => 'Owner',
                'last_name' => 'Zegger',
                'pass_hash' => password_hash($pass_plain, PASSWORD_DEFAULT),
                'role' => 'owner',
                'status' => 'active',
                'is_owner' => 1,
                'module_visibility' => wp_json_encode(ZERP_Permissions::visible_modules(ZERP_Permissions::owner_defaults())),
                'created_at' => $now,
                'updated_at' => $now,
            ),
            array('%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s')
        );

        $owner_id = (int) $wpdb->insert_id;
        ZERP_Auth::replace_permissions($owner_id, ZERP_Permissions::owner_defaults());

        $wpdb->update(
            $t['companies'],
            array('owner_member_id' => $owner_id, 'updated_at' => $now),
            array('id' => $company_id),
            array('%d', '%s'),
            array('%d')
        );

        update_option('zerp_bootstrap_owner', array(
            'login' => $login,
            'password' => $pass_plain,
            'company_id' => $company_id,
            'member_id' => $owner_id,
            'created_at' => $now,
        ), false);

        return array('ok' => true, 'company_id' => $company_id, 'member_id' => $owner_id, 'created' => true);
    }

    private static function seeded_company_id(): int
    {
        global $wpdb;
        $t = ZERP_DB::tables();
        $id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$t['companies']} WHERE name = %s LIMIT 1", 'Zegger Tech'));
        return $id;
    }

    private static function map_status(string $legacy_status): array
    {
        $legacy_status = strtolower(trim($legacy_status));
        $map = array(
            'unset' => 'new',
            'new' => 'new',
            'sent' => 'sent',
            'in_progress' => 'in_progress',
            'won' => 'accepted',
            'lost' => 'rejected',
            'canceled' => 'canceled',
            'needs_update' => 'in_progress',
        );

        $new = $map[$legacy_status] ?? 'new';
        return array('new' => $new, 'legacy' => $legacy_status ?: null);
    }

    public static function migrate_google_source_settings(): array
    {
        global $wpdb;
        $company_id = self::seeded_company_id();
        if ($company_id <= 0) {
            return array('ok' => false, 'message' => 'Brak firmy seed.');
        }

        $legacy_settings = get_option('zqos_settings', array());
        if (!is_array($legacy_settings) || empty($legacy_settings['sheet_pub_id'])) {
            return array('ok' => true, 'migrated' => false);
        }

        $t = ZERP_DB::tables();
        $exists = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['google_sources']} WHERE company_id = %d", $company_id));
        if ($exists > 0) {
            return array('ok' => true, 'migrated' => false, 'reason' => 'already_exists');
        }

        $tabs = isset($legacy_settings['tabs']) && is_array($legacy_settings['tabs']) ? $legacy_settings['tabs'] : array();
        $sync_interval = isset($legacy_settings['sync_interval_minutes']) ? (int) $legacy_settings['sync_interval_minutes'] : 10;
        if (!in_array($sync_interval, array(1, 5, 10, 15), true)) {
            $sync_interval = 10;
        }

        $now = current_time('mysql');
        $wpdb->insert(
            $t['google_sources'],
            array(
                'company_id' => $company_id,
                'sheet_pub_id' => sanitize_text_field((string) $legacy_settings['sheet_pub_id']),
                'tabs_json' => wp_json_encode($tabs),
                'sync_interval_minutes' => $sync_interval,
                'sync_enabled' => 1,
                'last_sync_at' => null,
                'last_sync_ok' => 0,
                'last_sync_error' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ),
            array('%d', '%s', '%s', '%d', '%d', '%s', '%d', '%s', '%s', '%s')
        );

        return array('ok' => true, 'migrated' => true);
    }

    public static function migrate_legacy_clients(): array
    {
        global $wpdb;
        $company_id = self::seeded_company_id();
        if ($company_id <= 0) {
            return array('ok' => false, 'message' => 'Brak firmy seed.');
        }

        $legacy = $wpdb->prefix . 'zqos_clients';
        if (!self::table_exists($legacy)) {
            return array('ok' => true, 'migrated' => 0, 'source_missing' => true);
        }

        $t = ZERP_DB::tables();
        $rows = $wpdb->get_results("SELECT * FROM {$legacy}", ARRAY_A);
        if (!$rows) {
            return array('ok' => true, 'migrated' => 0);
        }

        $now = current_time('mysql');
        $count = 0;

        foreach ($rows as $row) {
            $legacy_id = (int) ($row['id'] ?? 0);
            if ($legacy_id <= 0) {
                continue;
            }

            $exists = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['clients']} WHERE company_id = %d AND legacy_client_id = %d", $company_id, $legacy_id));
            if ($exists > 0) {
                continue;
            }

            $wpdb->insert(
                $t['clients'],
                array(
                    'company_id' => $company_id,
                    'legacy_client_id' => $legacy_id,
                    'full_name' => sanitize_text_field((string) ($row['full_name'] ?? '')),
                    'company_name' => sanitize_text_field((string) ($row['company'] ?? '')),
                    'nip' => sanitize_text_field((string) ($row['nip'] ?? '')),
                    'phone' => sanitize_text_field((string) ($row['phone'] ?? '')),
                    'email' => sanitize_email((string) ($row['email'] ?? '')),
                    'address' => sanitize_textarea_field((string) ($row['address'] ?? '')),
                    'created_at' => !empty($row['created_at']) ? (string) $row['created_at'] : $now,
                    'updated_at' => !empty($row['updated_at']) ? (string) $row['updated_at'] : $now,
                ),
                array('%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
            );
            $count++;
        }

        return array('ok' => true, 'migrated' => $count);
    }

    public static function migrate_legacy_offers(): array
    {
        global $wpdb;
        $company_id = self::seeded_company_id();
        if ($company_id <= 0) {
            return array('ok' => false, 'message' => 'Brak firmy seed.');
        }

        $legacy_offers = $wpdb->prefix . 'zqos_offers';
        if (!self::table_exists($legacy_offers)) {
            return array('ok' => true, 'migrated' => 0, 'source_missing' => true);
        }

        $t = ZERP_DB::tables();
        $rows = $wpdb->get_results("SELECT * FROM {$legacy_offers}", ARRAY_A);
        if (!$rows) {
            return array('ok' => true, 'migrated' => 0);
        }

        $owner_member_id = (int) $wpdb->get_var($wpdb->prepare("SELECT owner_member_id FROM {$t['companies']} WHERE id = %d", $company_id));
        $count = 0;

        foreach ($rows as $row) {
            $legacy_id = (int) ($row['id'] ?? 0);
            if ($legacy_id <= 0) {
                continue;
            }

            $exists = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['offers']} WHERE company_id = %d AND legacy_offer_id = %d", $company_id, $legacy_id));
            if ($exists > 0) {
                continue;
            }

            $status = self::map_status((string) ($row['status'] ?? 'unset'));
            $title = sanitize_text_field((string) ($row['title'] ?? ('Oferta #' . $legacy_id)));
            $title_norm = mb_strtolower(trim($title));
            $data_json = $row['data'] ?? null;
            if ($data_json !== null && !is_string($data_json)) {
                $data_json = wp_json_encode($data_json);
            }

            $legacy_account_id = !empty($row['account_id']) ? (int) $row['account_id'] : 0;
            $new_client_id = self::map_legacy_client_id($company_id, (string) $data_json);

            $wpdb->insert(
                $t['offers'],
                array(
                    'company_id' => $company_id,
                    'relation_id' => null,
                    'source_company_id' => $company_id,
                    'legacy_offer_id' => $legacy_id,
                    'created_by' => $owner_member_id,
                    'updated_by' => $owner_member_id,
                    'client_id' => $new_client_id,
                    'title' => $title,
                    'title_norm' => $title_norm,
                    'dedupe_hash' => !empty($row['dedupe_hash']) ? (string) $row['dedupe_hash'] : null,
                    'status' => $status['new'],
                    'legacy_status' => $status['legacy'],
                    'status_updated_at' => !empty($row['status_updated_at']) ? (string) $row['status_updated_at'] : null,
                    'comment' => sanitize_textarea_field((string) ($row['comment'] ?? '')),
                    'sales_note' => sanitize_textarea_field((string) ($row['sales_note'] ?? '')),
                    'data_json' => $data_json,
                    'pdf_path' => !empty($row['pdf_path']) ? (string) $row['pdf_path'] : null,
                    'locked' => !empty($row['locked']) ? 1 : 0,
                    'locked_at' => !empty($row['locked_at']) ? (string) $row['locked_at'] : null,
                    'locked_by' => $owner_member_id,
                    'lock_reason' => !empty($row['lock_reason']) ? sanitize_key((string) $row['lock_reason']) : null,
                    'version' => 1,
                    'created_at' => !empty($row['created_at']) ? (string) $row['created_at'] : current_time('mysql'),
                    'updated_at' => !empty($row['updated_at']) ? (string) $row['updated_at'] : current_time('mysql'),
                ),
                array(
                    '%d', '%d', '%d', '%d', '%d', '%d', '%d',
                    '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%d', '%s', '%s'
                )
            );

            $new_offer_id = (int) $wpdb->insert_id;
            if ($new_offer_id > 0) {
                $meta = array(
                    'legacy_offer_id' => $legacy_id,
                    'legacy_account_id' => $legacy_account_id,
                    'source' => 'zqos',
                );
                $wpdb->insert(
                    $t['offer_events'],
                    array(
                        'offer_id' => $new_offer_id,
                        'company_id' => $company_id,
                        'actor_member_id' => $owner_member_id,
                        'event_type' => 'legacy_offer_imported',
                        'meta_json' => wp_json_encode($meta),
                        'created_at' => current_time('mysql'),
                    ),
                    array('%d', '%d', '%d', '%s', '%s', '%s')
                );
            }
            $count++;
        }

        return array('ok' => true, 'migrated' => $count);
    }

    public static function migrate_legacy_events(): array
    {
        global $wpdb;
        $company_id = self::seeded_company_id();
        if ($company_id <= 0) {
            return array('ok' => false, 'message' => 'Brak firmy seed.');
        }

        $legacy_events = $wpdb->prefix . 'zqos_events';
        if (!self::table_exists($legacy_events)) {
            return array('ok' => true, 'migrated' => 0, 'source_missing' => true);
        }

        $t = ZERP_DB::tables();
        $rows = $wpdb->get_results("SELECT * FROM {$legacy_events} ORDER BY id ASC", ARRAY_A);
        if (!$rows) {
            return array('ok' => true, 'migrated' => 0);
        }

        $owner_member_id = (int) $wpdb->get_var($wpdb->prepare("SELECT owner_member_id FROM {$t['companies']} WHERE id = %d", $company_id));
        $count = 0;

        foreach ($rows as $row) {
            $legacy_offer_id = !empty($row['offer_id']) ? (int) $row['offer_id'] : 0;
            if ($legacy_offer_id <= 0) {
                continue;
            }

            $new_offer_id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$t['offers']} WHERE company_id = %d AND legacy_offer_id = %d LIMIT 1",
                $company_id,
                $legacy_offer_id
            ));

            if ($new_offer_id <= 0) {
                continue;
            }

            $meta = null;
            if (!empty($row['meta'])) {
                $decoded = json_decode((string) $row['meta'], true);
                if (is_array($decoded)) {
                    $meta = $decoded;
                }
            }

            $meta = is_array($meta) ? $meta : array();
            $meta['_legacy_event_id'] = (int) ($row['id'] ?? 0);
            $meta['_legacy_event'] = (string) ($row['event'] ?? 'legacy_event');

            $wpdb->insert(
                $t['offer_events'],
                array(
                    'offer_id' => $new_offer_id,
                    'company_id' => $company_id,
                    'actor_member_id' => $owner_member_id,
                    'event_type' => sanitize_key((string) ($row['event'] ?? 'legacy_event')),
                    'meta_json' => wp_json_encode($meta),
                    'created_at' => !empty($row['created_at']) ? (string) $row['created_at'] : current_time('mysql'),
                ),
                array('%d', '%d', '%d', '%s', '%s', '%s')
            );

            $count++;
        }

        return array('ok' => true, 'migrated' => $count);
    }

    private static function map_legacy_client_id(int $company_id, ?string $offer_data_json): ?int
    {
        if (!$offer_data_json) {
            return null;
        }

        $data = json_decode($offer_data_json, true);
        if (!is_array($data) || empty($data['client']) || !is_array($data['client'])) {
            return null;
        }

        $client = $data['client'];
        $legacy_client_id = !empty($client['id']) ? (int) $client['id'] : 0;
        if ($legacy_client_id <= 0) {
            return null;
        }

        global $wpdb;
        $t = ZERP_DB::tables();
        $new_client_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$t['clients']} WHERE company_id = %d AND legacy_client_id = %d LIMIT 1",
                $company_id,
                $legacy_client_id
            )
        );

        return $new_client_id > 0 ? $new_client_id : null;
    }

    private static function table_exists(string $table_name): bool
    {
        global $wpdb;
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name));
        return is_string($found) && $found === $table_name;
    }
}
