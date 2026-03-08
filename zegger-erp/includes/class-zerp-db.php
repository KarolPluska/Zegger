<?php
if (!defined('ABSPATH')) {
    exit;
}

final class ZERP_DB
{
    public const DB_VERSION = 100;
    public const OPT_DB_VERSION = 'zerp_db_version';
    public const OPT_SETTINGS = 'zerp_settings';
    public const OPT_MIGRATION = 'zerp_migration_state';

    public static function init(): void
    {
        self::maybe_upgrade();
    }

    public static function activate(): void
    {
        self::create_schema();
        self::ensure_default_settings();
        self::ensure_default_categories();
        update_option(self::OPT_DB_VERSION, self::DB_VERSION, false);
    }

    private static function maybe_upgrade(): void
    {
        $current = (int) get_option(self::OPT_DB_VERSION, 0);
        if ($current >= self::DB_VERSION) {
            return;
        }

        self::activate();
    }

    public static function table(string $key): string
    {
        $tables = self::tables();
        return $tables[$key] ?? '';
    }

    public static function tables(): array
    {
        global $wpdb;
        $p = $wpdb->prefix . 'zerp_';

        return array(
            'companies' => $p . 'companies',
            'members' => $p . 'company_members',
            'member_permissions' => $p . 'company_member_permissions',
            'auth_tokens' => $p . 'auth_tokens',
            'join_requests' => $p . 'join_requests',
            'relations' => $p . 'company_relations',
            'relation_events' => $p . 'relation_events',
            'google_sources' => $p . 'google_sources',
            'google_cache' => $p . 'google_cache',
            'catalog_categories' => $p . 'catalog_categories',
            'catalog_items' => $p . 'catalog_items',
            'catalog_variants' => $p . 'catalog_variants',
            'catalog_events' => $p . 'catalog_events',
            'clients' => $p . 'clients',
            'offers' => $p . 'offers',
            'offer_events' => $p . 'offer_events',
            'offer_chat_link' => $p . 'offer_chat_link',
            'offer_pdf_archive' => $p . 'offer_pdf_archive',
            'thread_categories' => $p . 'thread_categories',
            'threads' => $p . 'threads',
            'thread_participants' => $p . 'thread_participants',
            'thread_messages' => $p . 'thread_messages',
            'thread_attachments' => $p . 'thread_attachments',
            'thread_pings' => $p . 'thread_pings',
            'thread_events' => $p . 'thread_events',
            'notifications' => $p . 'notifications',
            'notification_reads' => $p . 'notification_reads',
            'maintenance_logs' => $p . 'maintenance_logs',
        );
    }

    public static function settings(): array
    {
        $settings = get_option(self::OPT_SETTINGS, array());
        return is_array($settings) ? $settings : array();
    }

    public static function update_settings(array $patch): array
    {
        $settings = self::settings();
        $merged = array_merge($settings, $patch);
        update_option(self::OPT_SETTINGS, $merged, false);
        return $merged;
    }

    public static function ensure_default_settings(): void
    {
        $defaults = array(
            'session_hours' => 12,
            'max_active_tokens_per_member' => 5,
            'login_rate_attempts' => 10,
            'login_rate_window_minutes' => 10,
            'orphan_account_ttl_days' => 7,
            'attachment_retention_months' => 12,
            'default_vat_rate' => 0.23,
            'storage_warn_mb' => 1024,
            'auth_background_url' => '',
        );

        $current = self::settings();
        if (!$current) {
            add_option(self::OPT_SETTINGS, $defaults, '', false);
            return;
        }

        $out = $current;
        foreach ($defaults as $key => $value) {
            if (!array_key_exists($key, $out)) {
                $out[$key] = $value;
            }
        }

        if ($out !== $current) {
            update_option(self::OPT_SETTINGS, $out, false);
        }
    }

    public static function ensure_default_categories(): void
    {
        global $wpdb;
        $t = self::tables();
        $existing = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t['thread_categories']}");
        if ($existing > 0) {
            return;
        }

        $defaults = array('OgÄ‚Ĺ‚lne', 'Zapytanie ofertowe', 'ZamÄ‚Ĺ‚wienie', 'Reklamacja', 'Techniczne', 'Rozliczenia', 'Inne');
        $now = current_time('mysql');
        foreach ($defaults as $idx => $name) {
            $wpdb->insert(
                $t['thread_categories'],
                array(
                    'name' => $name,
                    'slug' => sanitize_title($name),
                    'is_default' => 1,
                    'is_active' => 1,
                    'sort_order' => $idx,
                    'created_at' => $now,
                    'updated_at' => $now,
                ),
                array('%s', '%s', '%d', '%d', '%d', '%s', '%s')
            );
        }
    }

    public static function create_schema(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $t = self::tables();

        $queries = array();

        $queries[] = "CREATE TABLE {$t['companies']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(191) NOT NULL,
            nip VARCHAR(32) NOT NULL,
            address TEXT NULL,
            company_email VARCHAR(191) NULL,
            company_phone VARCHAR(64) NULL,
            www VARCHAR(191) NULL,
            logo_url TEXT NULL,
            join_code VARCHAR(64) NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'active',
            owner_member_id BIGINT UNSIGNED NULL,
            meta_json LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY nip (nip),
            UNIQUE KEY join_code (join_code),
            KEY status (status)
        ) {$charset};";

        $queries[] = "CREATE TABLE {$t['members']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_id BIGINT UNSIGNED NULL,
            login VARCHAR(64) NOT NULL,
            email VARCHAR(191) NOT NULL,
            phone VARCHAR(64) NULL,
            first_name VARCHAR(100) NOT NULL,
            last_name VARCHAR(100) NOT NULL,
            pass_hash VARCHAR(255) NOT NULL,
            role VARCHAR(32) NOT NULL DEFAULT 'user',
            status VARCHAR(32) NOT NULL DEFAULT 'active',
            is_owner TINYINT UNSIGNED NOT NULL DEFAULT 0,
            module_visibility LONGTEXT NULL,
            profile LONGTEXT NULL,
            created_by BIGINT UNSIGNED NULL,
            updated_by BIGINT UNSIGNED NULL,
            last_login_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY login (login),
            UNIQUE KEY email (email),
            KEY company_id (company_id),
            KEY role (role),
            KEY status (status)
        ) {$charset};";

        $queries[] = "CREATE TABLE {$t['member_permissions']} (
            member_id BIGINT UNSIGNED NOT NULL,
            permission_key VARCHAR(100) NOT NULL,
            allow_flag TINYINT UNSIGNED NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (member_id, permission_key),
            KEY permission_key (permission_key)
        ) {$charset};";

        $queries[] = "CREATE TABLE {$t['auth_tokens']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            member_id BIGINT UNSIGNED NOT NULL,
            actor_member_id BIGINT UNSIGNED NULL,
            token_hash CHAR(64) NOT NULL,
            created_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            last_seen DATETIME NULL,
            ip VARCHAR(64) NULL,
            ua VARCHAR(255) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY token_hash (token_hash),
            KEY member_id (member_id),
            KEY actor_member_id (actor_member_id),
            KEY expires_at (expires_at)
        ) {$charset};";

        $queries[] = "CREATE TABLE {$t['join_requests']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            pending_member_id BIGINT UNSIGNED NOT NULL,
            company_id BIGINT UNSIGNED NULL,
            target_join_code VARCHAR(64) NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'pending',
            reason TEXT NULL,
            reviewed_by BIGINT UNSIGNED NULL,
            reviewed_at DATETIME NULL,
            expires_at DATETIME NOT NULL,
            payload LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY pending_member_id (pending_member_id),
            KEY company_id (company_id),
            KEY target_join_code (target_join_code),
            KEY status (status),
            KEY expires_at (expires_at)
        ) {$charset};";

        $queries[] = "CREATE TABLE {$t['relations']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_a_id BIGINT UNSIGNED NOT NULL,
            company_b_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'pending',
            max_discount_a_to_b DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            max_discount_b_to_a DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            invited_by BIGINT UNSIGNED NOT NULL,
            accepted_by BIGINT UNSIGNED NULL,
            accepted_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_relation (company_a_id, company_b_id),
            KEY status (status)
        ) {$charset};";

        $queries[] = "CREATE TABLE {$t['relation_events']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            relation_id BIGINT UNSIGNED NOT NULL,
            event_type VARCHAR(64) NOT NULL,
            meta_json LONGTEXT NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY relation_id (relation_id),
            KEY event_type (event_type)
        ) {$charset};";

        $queries[] = "CREATE TABLE {$t['google_sources']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_id BIGINT UNSIGNED NOT NULL,
            sheet_pub_id VARCHAR(191) NOT NULL,
            tabs_json LONGTEXT NULL,
            sync_interval_minutes INT UNSIGNED NOT NULL DEFAULT 10,
            sync_enabled TINYINT UNSIGNED NOT NULL DEFAULT 1,
            sync_lock_until DATETIME NULL,
            last_sync_at DATETIME NULL,
            last_sync_ok TINYINT UNSIGNED NOT NULL DEFAULT 0,
            last_sync_error TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY company_id (company_id)
        ) {$charset};";

        $queries[] = "CREATE TABLE {$t['google_cache']} (
            company_id BIGINT UNSIGNED NOT NULL,
            data_hash CHAR(64) NULL,
            cache_json LONGTEXT NULL,
            fetched_at DATETIME NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (company_id),
            KEY fetched_at (fetched_at)
        ) {$charset};";

        $queries[] = "CREATE TABLE {$t['catalog_categories']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_id BIGINT UNSIGNED NOT NULL,
            parent_id BIGINT UNSIGNED NULL,
            name VARCHAR(191) NOT NULL,
            slug VARCHAR(191) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT UNSIGNED NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY company_id (company_id),
            KEY parent_id (parent_id),
            KEY is_active (is_active)
        ) {$charset};";

        $queries[] = "CREATE TABLE {$t['catalog_items']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_id BIGINT UNSIGNED NOT NULL,
            category_id BIGINT UNSIGNED NULL,
            name VARCHAR(191) NOT NULL,
            sku VARCHAR(128) NULL,
            unit VARCHAR(32) NOT NULL DEFAULT 'szt',
            description TEXT NULL,
            source_label VARCHAR(32) NOT NULL DEFAULT 'local',
            is_active TINYINT UNSIGNED NOT NULL DEFAULT 1,
            meta_json LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY company_id (company_id),
            KEY category_id (category_id),
            KEY is_active (is_active)
        ) {$charset};";

        $queries[] = "CREATE TABLE {$t['catalog_variants']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_id BIGINT UNSIGNED NOT NULL,
            item_id BIGINT UNSIGNED NOT NULL,
            color_label VARCHAR(128) NOT NULL,
            sku VARCHAR(128) NOT NULL,
            unit_net DECIMAL(14,2) NULL,
            unit_gross DECIMAL(14,2) NULL,
            is_active TINYINT UNSIGNED NOT NULL DEFAULT 1,
            source_label VARCHAR(32) NOT NULL DEFAULT 'local',
            meta_json LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY company_id (company_id),
            KEY item_id (item_id),
            KEY sku (sku),
            KEY color_label (color_label)
        ) {$charset};";

        $queries[] = "CREATE TABLE {$t['catalog_events']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_id BIGINT UNSIGNED NOT NULL,
            item_id BIGINT UNSIGNED NULL,
            variant_id BIGINT UNSIGNED NULL,
            event_type VARCHAR(64) NOT NULL,
            meta_json LONGTEXT NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY company_id (company_id),
            KEY event_type (event_type)
        ) {$charset};";

        $queries[] = "CREATE TABLE {$t['clients']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_id BIGINT UNSIGNED NOT NULL,
            legacy_client_id BIGINT UNSIGNED NULL,
            full_name VARCHAR(191) NULL,
            company_name VARCHAR(191) NULL,
            nip VARCHAR(32) NULL,
            phone VARCHAR(64) NULL,
            email VARCHAR(191) NULL,
            address TEXT NULL,
            created_by BIGINT UNSIGNED NULL,
            updated_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY company_id (company_id),
            KEY legacy_client_id (legacy_client_id),
            KEY nip (nip)
        ) {$charset};";

        $queries[] = "CREATE TABLE {$t['offers']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_id BIGINT UNSIGNED NOT NULL,
            relation_id BIGINT UNSIGNED NULL,
            source_company_id BIGINT UNSIGNED NULL,
            legacy_offer_id BIGINT UNSIGNED NULL,
            created_by BIGINT UNSIGNED NULL,
            updated_by BIGINT UNSIGNED NULL,
            client_id BIGINT UNSIGNED NULL,
            title VARCHAR(220) NOT NULL,
            title_norm VARCHAR(220) NOT NULL,
            dedupe_hash CHAR(64) NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'new',
            legacy_status VARCHAR(32) NULL,
            status_updated_at DATETIME NULL,
            comment TEXT NULL,
            sales_note TEXT NULL,
            data_json LONGTEXT NULL,
            pdf_path TEXT NULL,
            linked_thread_id BIGINT UNSIGNED NULL,
            locked TINYINT UNSIGNED NOT NULL DEFAULT 0,
            locked_at DATETIME NULL,
            locked_by BIGINT UNSIGNED NULL,
            lock_reason VARCHAR(64) NULL,
            version INT UNSIGNED NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY company_id (company_id),
            KEY relation_id (relation_id),
            KEY source_company_id (source_company_id),
            KEY client_id (client_id),
            KEY legacy_offer_id (legacy_offer_id),
            KEY status (status),
            KEY linked_thread_id (linked_thread_id),
            KEY dedupe_hash (dedupe_hash)
        ) {$charset};";

        $queries[] = "CREATE TABLE {$t['offer_events']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            offer_id BIGINT UNSIGNED NOT NULL,
            company_id BIGINT UNSIGNED NULL,
            actor_member_id BIGINT UNSIGNED NULL,
            event_type VARCHAR(64) NOT NULL,
            meta_json LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY offer_id (offer_id),
            KEY event_type (event_type)
        ) {$charset};";

        $queries[] = "CREATE TABLE {$t['offer_chat_link']} (
            offer_id BIGINT UNSIGNED NOT NULL,
            thread_id BIGINT UNSIGNED NOT NULL,
            relation_id BIGINT UNSIGNED NOT NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (offer_id),
            UNIQUE KEY thread_id (thread_id),
            KEY relation_id (relation_id)
        ) {$charset};";

        $queries[] = "CREATE TABLE {$t['offer_pdf_archive']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            offer_id BIGINT UNSIGNED NOT NULL,
            file_path TEXT NOT NULL,
            file_size BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY offer_id (offer_id)
        ) {$charset};";

        $queries[] = "CREATE TABLE {$t['thread_categories']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(191) NOT NULL,
            slug VARCHAR(191) NOT NULL,
            is_default TINYINT UNSIGNED NOT NULL DEFAULT 0,
            is_active TINYINT UNSIGNED NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug),
            KEY is_active (is_active)
        ) {$charset};";

        $queries[] = "CREATE TABLE {$t['threads']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            relation_id BIGINT UNSIGNED NOT NULL,
            type VARCHAR(32) NOT NULL DEFAULT 'general',
            category_id BIGINT UNSIGNED NULL,
            title VARCHAR(191) NULL,
            linked_offer_id BIGINT UNSIGNED NULL,
            lead_member_id BIGINT UNSIGNED NULL,
            is_closed TINYINT UNSIGNED NOT NULL DEFAULT 0,
            closed_by BIGINT UNSIGNED NULL,
            closed_reason TEXT NULL,
            closed_at DATETIME NULL,
            reopened_by BIGINT UNSIGNED NULL,
            reopen_reason TEXT NULL,
            reopened_at DATETIME NULL,
            created_by BIGINT UNSIGNED NOT NULL,
            last_message_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY linked_offer_id (linked_offer_id),
            KEY relation_id (relation_id),
            KEY category_id (category_id),
            KEY is_closed (is_closed),
            KEY last_message_at (last_message_at)
        ) {$charset};";

        $queries[] = "CREATE TABLE {$t['thread_participants']} (
            thread_id BIGINT UNSIGNED NOT NULL,
            member_id BIGINT UNSIGNED NOT NULL,
            is_muted TINYINT UNSIGNED NOT NULL DEFAULT 0,
            is_handler TINYINT UNSIGNED NOT NULL DEFAULT 0,
            joined_at DATETIME NOT NULL,
            last_read_message_id BIGINT UNSIGNED NULL,
            last_read_at DATETIME NULL,
            PRIMARY KEY (thread_id, member_id),
            KEY member_id (member_id)
        ) {$charset};";

        $queries[] = "CREATE TABLE {$t['thread_messages']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            thread_id BIGINT UNSIGNED NOT NULL,
            sender_member_id BIGINT UNSIGNED NULL,
            message_type VARCHAR(32) NOT NULL DEFAULT 'message',
            body LONGTEXT NULL,
            meta_json LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY thread_id (thread_id),
            KEY sender_member_id (sender_member_id),
            KEY message_type (message_type),
            KEY created_at (created_at)
        ) {$charset};";

        $queries[] = "CREATE TABLE {$t['thread_attachments']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            thread_id BIGINT UNSIGNED NOT NULL,
            message_id BIGINT UNSIGNED NOT NULL,
            uploader_member_id BIGINT UNSIGNED NULL,
            file_path TEXT NOT NULL,
            file_name VARCHAR(255) NOT NULL,
            mime_type VARCHAR(100) NOT NULL,
            size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
            expires_at DATETIME NOT NULL,
            is_expired TINYINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY thread_id (thread_id),
            KEY message_id (message_id),
            KEY expires_at (expires_at),
            KEY is_expired (is_expired)
        ) {$charset};";

        $queries[] = "CREATE TABLE {$t['thread_pings']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            thread_id BIGINT UNSIGNED NOT NULL,
            message_id BIGINT UNSIGNED NOT NULL,
            target_member_id BIGINT UNSIGNED NOT NULL,
            created_by BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY thread_id (thread_id),
            KEY target_member_id (target_member_id)
        ) {$charset};";

        $queries[] = "CREATE TABLE {$t['thread_events']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            thread_id BIGINT UNSIGNED NOT NULL,
            event_type VARCHAR(64) NOT NULL,
            meta_json LONGTEXT NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY thread_id (thread_id),
            KEY event_type (event_type)
        ) {$charset};";

        $queries[] = "CREATE TABLE {$t['notifications']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            member_id BIGINT UNSIGNED NOT NULL,
            notification_type VARCHAR(64) NOT NULL,
            title VARCHAR(191) NOT NULL,
            body TEXT NULL,
            entity_type VARCHAR(64) NULL,
            entity_id BIGINT UNSIGNED NULL,
            meta_json LONGTEXT NULL,
            is_read TINYINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY member_id (member_id),
            KEY notification_type (notification_type),
            KEY is_read (is_read),
            KEY entity_type (entity_type)
        ) {$charset};";

        $queries[] = "CREATE TABLE {$t['notification_reads']} (
            notification_id BIGINT UNSIGNED NOT NULL,
            member_id BIGINT UNSIGNED NOT NULL,
            read_at DATETIME NOT NULL,
            PRIMARY KEY (notification_id, member_id),
            KEY member_id (member_id)
        ) {$charset};";

        $queries[] = "CREATE TABLE {$t['maintenance_logs']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            action_key VARCHAR(64) NOT NULL,
            meta_json LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY action_key (action_key),
            KEY created_at (created_at)
        ) {$charset};";

        foreach ($queries as $query) {
            dbDelta($query);
        }
    }

    public static function now(): string
    {
        return current_time('mysql');
    }

    public static function generate_join_code(): string
    {
        return strtolower(wp_generate_password(12, false, false));
    }


    public static function reset_clean_start(): array
    {
        global $wpdb;

        if (class_exists('ZERP_Maintenance')) {
            ZERP_Maintenance::deactivate();
        }

        $google_hook = class_exists('ZERP_Sources_Google') ? ZERP_Sources_Google::CRON_HOOK : 'zerp_google_sync';
        $ts = wp_next_scheduled($google_hook);
        while ($ts) {
            wp_unschedule_event($ts, $google_hook);
            $ts = wp_next_scheduled($google_hook);
        }

        $dropped_tables = 0;
        foreach (array_values(self::tables()) as $table_name) {
            $table_name = str_replace('`', '', (string) $table_name);
            $res = $wpdb->query("DROP TABLE IF EXISTS `{$table_name}`");
            if ($res !== false) {
                $dropped_tables++;
            }
        }

        $options_table = $wpdb->options;
        $deleted_options = (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM `{$options_table}` WHERE option_name LIKE %s",
            'zerp_%'
        ));

        $deleted_transients = (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM `{$options_table}` WHERE option_name LIKE %s OR option_name LIKE %s",
            '_transient_zerp_%',
            '_transient_timeout_zerp_%'
        ));

        self::activate();

        if (class_exists('ZERP_Maintenance')) {
            ZERP_Maintenance::activate();
        }

        return array(
            'ok' => true,
            'prefix' => $wpdb->prefix,
            'tables_dropped' => $dropped_tables,
            'options_deleted' => $deleted_options,
            'transients_deleted' => $deleted_transients,
        );
    }
    public static function log_maintenance(string $action_key, array $meta = array()): void
    {
        global $wpdb;
        $t = self::tables();
        $wpdb->insert(
            $t['maintenance_logs'],
            array(
                'action_key' => sanitize_key($action_key),
                'meta_json' => $meta ? wp_json_encode($meta) : null,
                'created_at' => self::now(),
            ),
            array('%s', '%s', '%s')
        );
    }
}
