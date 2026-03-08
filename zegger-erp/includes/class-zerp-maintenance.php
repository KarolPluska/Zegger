<?php
if (!defined('ABSPATH')) {
    exit;
}

final class ZERP_Maintenance
{
    public const HOOK_HOURLY = 'zerp_maintenance_hourly';
    public const HOOK_DAILY = 'zerp_maintenance_daily';

    public static function init(): void
    {
        add_action(self::HOOK_HOURLY, array(__CLASS__, 'run_hourly'));
        add_action(self::HOOK_DAILY, array(__CLASS__, 'run_daily'));

        if (!wp_next_scheduled(self::HOOK_HOURLY)) {
            wp_schedule_event(time() + 120, 'hourly', self::HOOK_HOURLY);
        }
        if (!wp_next_scheduled(self::HOOK_DAILY)) {
            wp_schedule_event(time() + 300, 'daily', self::HOOK_DAILY);
        }
    }

    public static function activate(): void
    {
        if (!wp_next_scheduled(self::HOOK_HOURLY)) {
            wp_schedule_event(time() + 120, 'hourly', self::HOOK_HOURLY);
        }
        if (!wp_next_scheduled(self::HOOK_DAILY)) {
            wp_schedule_event(time() + 300, 'daily', self::HOOK_DAILY);
        }
    }

    public static function deactivate(): void
    {
        self::unschedule(self::HOOK_HOURLY);
        self::unschedule(self::HOOK_DAILY);
    }

    private static function unschedule(string $hook): void
    {
        $ts = wp_next_scheduled($hook);
        while ($ts) {
            wp_unschedule_event($ts, $hook);
            $ts = wp_next_scheduled($hook);
        }
    }

    public static function run_hourly(): void
    {
        self::cleanup_orphan_accounts();
        self::expire_attachments();
        self::diagnose_offer_thread_consistency();
    }

    public static function run_daily(): void
    {
        self::cleanup_old_notifications();
        self::storage_diagnostics();
    }

    public static function cleanup_orphan_accounts(): int
    {
        global $wpdb;
        $t = ZERP_DB::tables();

        $settings = ZERP_DB::settings();
        $ttl_days = isset($settings['orphan_account_ttl_days']) ? (int) $settings['orphan_account_ttl_days'] : 7;
        $ttl_days = max(1, min(30, $ttl_days));

        $cutoff = wp_date('Y-m-d H:i:s', current_time('timestamp') - ($ttl_days * DAY_IN_SECONDS));

        $member_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT id FROM {$t['members']}
                  WHERE company_id IS NULL
                    AND status = 'pending_join'
                    AND created_at < %s",
                $cutoff
            )
        );

        if (!$member_ids) {
            return 0;
        }

        $member_ids = array_map('intval', $member_ids);
        $placeholders = implode(',', array_fill(0, count($member_ids), '%d'));

        $wpdb->query($wpdb->prepare("DELETE FROM {$t['join_requests']} WHERE pending_member_id IN ({$placeholders})", ...$member_ids));
        $deleted = $wpdb->query($wpdb->prepare("DELETE FROM {$t['members']} WHERE id IN ({$placeholders})", ...$member_ids));

        ZERP_DB::log_maintenance('cleanup_orphan_accounts', array('deleted' => (int) $deleted, 'ttl_days' => $ttl_days));
        return (int) $deleted;
    }

    public static function expire_attachments(): int
    {
        global $wpdb;
        $t = ZERP_DB::tables();

        $now = current_time('mysql');
        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$t['thread_attachments']} WHERE is_expired = 0 AND expires_at <= %s LIMIT 500", $now),
            ARRAY_A
        );

        if (!$rows) {
            return 0;
        }

        $uploads = wp_upload_dir();
        $base_dir = wp_normalize_path(trailingslashit((string) ($uploads['basedir'] ?? '')));
        $expired = 0;

        foreach ($rows as $row) {
            $attachment_id = (int) ($row['id'] ?? 0);
            if ($attachment_id <= 0) {
                continue;
            }

            $full = $base_dir . ltrim(wp_normalize_path((string) ($row['file_path'] ?? '')), '/');
            if (str_starts_with($full, $base_dir) && file_exists($full)) {
                @unlink($full);
            }

            $wpdb->update(
                $t['thread_attachments'],
                array('is_expired' => 1),
                array('id' => $attachment_id),
                array('%d'),
                array('%d')
            );

            $thread_id = (int) ($row['thread_id'] ?? 0);
            if ($thread_id > 0) {
                ZERP_Chat::log_system_event($thread_id, 'file_expired', array('attachment_id' => $attachment_id), null);
            }

            $expired++;
        }

        if ($expired > 0) {
            ZERP_DB::log_maintenance('expire_attachments', array('expired' => $expired));
        }

        return $expired;
    }

    public static function cleanup_old_notifications(): int
    {
        global $wpdb;
        $t = ZERP_DB::tables();

        $cutoff = wp_date('Y-m-d H:i:s', current_time('timestamp') - (365 * DAY_IN_SECONDS));
        $deleted = $wpdb->query($wpdb->prepare("DELETE FROM {$t['notifications']} WHERE created_at < %s AND is_read = 1", $cutoff));
        if ($deleted > 0) {
            ZERP_DB::log_maintenance('cleanup_notifications', array('deleted' => (int) $deleted));
        }
        return (int) $deleted;
    }

    public static function diagnose_offer_thread_consistency(): array
    {
        global $wpdb;
        $t = ZERP_DB::tables();

        $rows = $wpdb->get_results(
            "SELECT l.offer_id, l.thread_id, o.linked_thread_id AS offer_linked_thread_id, th.linked_offer_id AS thread_linked_offer_id
               FROM {$t['offer_chat_link']} l
          LEFT JOIN {$t['offers']} o ON o.id = l.offer_id
          LEFT JOIN {$t['threads']} th ON th.id = l.thread_id
              WHERE o.id IS NULL OR th.id IS NULL OR o.linked_thread_id <> l.thread_id OR th.linked_offer_id <> l.offer_id",
            ARRAY_A
        );

        if (!$rows) {
            return array('ok' => true, 'issues' => 0);
        }

        foreach ($rows as $row) {
            ZERP_DB::log_maintenance('offer_thread_inconsistency', array(
                'offer_id' => (int) ($row['offer_id'] ?? 0),
                'thread_id' => (int) ($row['thread_id'] ?? 0),
                'offer_linked_thread_id' => isset($row['offer_linked_thread_id']) ? (int) $row['offer_linked_thread_id'] : null,
                'thread_linked_offer_id' => isset($row['thread_linked_offer_id']) ? (int) $row['thread_linked_offer_id'] : null,
            ));
        }

        return array('ok' => false, 'issues' => count($rows));
    }

    public static function storage_diagnostics(): array
    {
        global $wpdb;
        $t = ZERP_DB::tables();

        $offers_total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t['offers']}");
        $offers_with_pdf = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t['offers']} WHERE pdf_path IS NOT NULL AND pdf_path <> ''");
        $attachments_total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t['thread_attachments']}");
        $attachments_active = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t['thread_attachments']} WHERE is_expired = 0");

        $stats = array(
            'offers_total' => $offers_total,
            'offers_with_pdf' => $offers_with_pdf,
            'attachments_total' => $attachments_total,
            'attachments_active' => $attachments_active,
        );

        ZERP_DB::log_maintenance('storage_diagnostics', $stats);

        return $stats;
    }
}
