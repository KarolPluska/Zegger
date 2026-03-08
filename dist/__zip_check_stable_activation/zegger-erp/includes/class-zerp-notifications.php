<?php
if (!defined('ABSPATH')) {
    exit;
}

final class ZERP_Notifications
{
    public static function init(): void
    {
        // routes via ZERP_Rest
    }

    public static function create_for_member(int $member_id, string $type, string $title, string $body = '', ?string $entity_type = null, ?int $entity_id = null, array $meta = array()): int
    {
        global $wpdb;
        $t = ZERP_DB::tables();

        $wpdb->insert(
            $t['notifications'],
            array(
                'member_id' => $member_id,
                'notification_type' => sanitize_key($type),
                'title' => sanitize_text_field($title),
                'body' => sanitize_textarea_field($body),
                'entity_type' => $entity_type ? sanitize_key($entity_type) : null,
                'entity_id' => $entity_id,
                'meta_json' => $meta ? wp_json_encode($meta) : null,
                'is_read' => 0,
                'created_at' => current_time('mysql'),
            ),
            array('%d', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s')
        );

        return (int) $wpdb->insert_id;
    }

    public static function create_for_company(int $company_id, string $type, string $title, string $body = '', ?string $entity_type = null, ?int $entity_id = null, array $meta = array()): int
    {
        global $wpdb;
        $t = ZERP_DB::tables();

        $member_ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$t['members']} WHERE company_id = %d AND status = 'active'", $company_id));
        $count = 0;
        if (is_array($member_ids)) {
            foreach ($member_ids as $member_id) {
                self::create_for_member((int) $member_id, $type, $title, $body, $entity_type, $entity_id, $meta);
                $count++;
            }
        }

        return $count;
    }

    public static function list_for_member(int $member_id, ?string $section = null, int $limit = 100): array
    {
        global $wpdb;
        $t = ZERP_DB::tables();

        $limit = max(1, min(250, $limit));
        $where = 'WHERE member_id = %d';
        $params = array($member_id);

        if ($section) {
            $map = array(
                'messages' => array('new_message', 'ping_to_you'),
                'offers' => array('offer_attached', 'offer_changed', 'offer_status_changed'),
                'requests' => array('join_request', 'join_request_approved', 'join_request_rejected'),
                'invitations' => array('relation_invite', 'relation_accepted', 'relation_rejected'),
                'system' => array('system_event', 'thread_reopened', 'thread_closed'),
            );

            $types = $map[$section] ?? array();
            if ($types) {
                $placeholders = implode(',', array_fill(0, count($types), '%s'));
                $where .= " AND notification_type IN ({$placeholders})";
                foreach ($types as $type) {
                    $params[] = $type;
                }
            }
        }

        $sql = "SELECT * FROM {$t['notifications']} {$where} ORDER BY id DESC LIMIT {$limit}";
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);
        if (!$rows) {
            return array();
        }

        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['member_id'] = (int) $row['member_id'];
            $row['entity_id'] = !empty($row['entity_id']) ? (int) $row['entity_id'] : null;
            $row['is_read'] = !empty($row['is_read']) ? 1 : 0;
            $row['meta'] = !empty($row['meta_json']) ? json_decode((string) $row['meta_json'], true) : null;
            unset($row['meta_json']);
        }

        return $rows;
    }

    public static function mark_read(int $member_id, array $notification_ids): int
    {
        global $wpdb;
        $t = ZERP_DB::tables();

        $ids = array_values(array_filter(array_map('intval', $notification_ids), static fn($x) => $x > 0));
        if (!$ids) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $sql = "UPDATE {$t['notifications']} SET is_read = 1 WHERE member_id = %d AND id IN ({$placeholders})";
        $params = array_merge(array($member_id), $ids);

        $updated = $wpdb->query($wpdb->prepare($sql, ...$params));
        if (!$updated) {
            return 0;
        }

        $read_at = current_time('mysql');
        foreach ($ids as $id) {
            $wpdb->replace(
                $t['notification_reads'],
                array(
                    'notification_id' => $id,
                    'member_id' => $member_id,
                    'read_at' => $read_at,
                ),
                array('%d', '%d', '%s')
            );
        }

        return (int) $updated;
    }

    public static function unread_count(int $member_id): int
    {
        global $wpdb;
        $t = ZERP_DB::tables();
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['notifications']} WHERE member_id = %d AND is_read = 0", $member_id));
    }
}
