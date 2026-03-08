<?php
if (!defined('ABSPATH')) {
    exit;
}

final class ZERP_Relations
{
    public static function init(): void
    {
        // routes via REST
    }

    public static function list_for_company(int $company_id): array
    {
        global $wpdb;
        $t = ZERP_DB::tables();

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT r.*, c1.name AS company_a_name, c2.name AS company_b_name
                   FROM {$t['relations']} r
                   JOIN {$t['companies']} c1 ON c1.id = r.company_a_id
                   JOIN {$t['companies']} c2 ON c2.id = r.company_b_id
                  WHERE r.company_a_id = %d OR r.company_b_id = %d
               ORDER BY r.id DESC",
                $company_id,
                $company_id
            ),
            ARRAY_A
        );

        if (!$rows) {
            return array();
        }

        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['company_a_id'] = (int) $row['company_a_id'];
            $row['company_b_id'] = (int) $row['company_b_id'];
            $row['max_discount_a_to_b'] = (float) $row['max_discount_a_to_b'];
            $row['max_discount_b_to_a'] = (float) $row['max_discount_b_to_a'];
            $row['other_company_id'] = ((int) $row['company_a_id'] === $company_id) ? (int) $row['company_b_id'] : (int) $row['company_a_id'];
            $row['other_company_name'] = ((int) $row['company_a_id'] === $company_id) ? (string) $row['company_b_name'] : (string) $row['company_a_name'];
        }

        return $rows;
    }

    public static function invite(int $company_id, int $target_company_id, float $max_discount_from_target = 0.0): array
    {
        $member = ZERP_Auth::require_member();
        if (!$member) {
            return array('ok' => false, 'message' => 'Brak autoryzacji.');
        }
        if ((int) $member['company_id'] !== $company_id) {
            return array('ok' => false, 'message' => 'Brak dostępu do firmy.');
        }
        if (!ZERP_Auth::can('can_manage_company_relations')) {
            return array('ok' => false, 'message' => 'Brak uprawnień do relacji firm.');
        }
        if ($company_id === $target_company_id) {
            return array('ok' => false, 'message' => 'Nie można zaprosić tej samej firmy.');
        }

        global $wpdb;
        $t = ZERP_DB::tables();

        $existing = self::find_relation($company_id, $target_company_id);
        if ($existing) {
            return array('ok' => false, 'message' => 'Relacja już istnieje lub oczekuje.', 'relation' => $existing);
        }

        $max_discount_from_target = max(0.0, min(100.0, $max_discount_from_target));
        $now = current_time('mysql');

        $wpdb->insert(
            $t['relations'],
            array(
                'company_a_id' => $company_id,
                'company_b_id' => $target_company_id,
                'status' => 'pending',
                'max_discount_a_to_b' => 0,
                'max_discount_b_to_a' => $max_discount_from_target,
                'invited_by' => (int) $member['id'],
                'created_at' => $now,
                'updated_at' => $now,
            ),
            array('%d', '%d', '%s', '%f', '%f', '%d', '%s', '%s')
        );

        $relation_id = (int) $wpdb->insert_id;
        self::log_event($relation_id, 'relation_invited', array('from' => $company_id, 'to' => $target_company_id), (int) $member['id']);

        ZERP_Notifications::create_for_company(
            $target_company_id,
            'relation_invite',
            'Nowe zaproszenie do relacji',
            'Otrzymano zaproszenie do relacji firma-firma.',
            'relation',
            $relation_id,
            array('from_company_id' => $company_id)
        );

        return array('ok' => true, 'relation_id' => $relation_id);
    }

    public static function accept(int $relation_id, int $accepting_company_id, float $max_discount_for_other_side = 0.0): array
    {
        $member = ZERP_Auth::require_member();
        if (!$member) {
            return array('ok' => false, 'message' => 'Brak autoryzacji.');
        }
        if ((int) $member['company_id'] !== $accepting_company_id) {
            return array('ok' => false, 'message' => 'Brak dostępu do firmy.');
        }
        if (!ZERP_Auth::can('can_manage_company_relations')) {
            return array('ok' => false, 'message' => 'Brak uprawnień do relacji firm.');
        }

        global $wpdb;
        $t = ZERP_DB::tables();
        $relation = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['relations']} WHERE id = %d LIMIT 1", $relation_id), ARRAY_A);
        if (!$relation) {
            return array('ok' => false, 'message' => 'Relacja nie istnieje.');
        }
        if ((string) $relation['status'] !== 'pending') {
            return array('ok' => false, 'message' => 'Relacja została już obsłużona.');
        }

        $company_a = (int) $relation['company_a_id'];
        $company_b = (int) $relation['company_b_id'];
        if ($accepting_company_id !== $company_a && $accepting_company_id !== $company_b) {
            return array('ok' => false, 'message' => 'Firma nie należy do tej relacji.');
        }

        $max_discount_for_other_side = max(0.0, min(100.0, $max_discount_for_other_side));

        $patch = array(
            'status' => 'active',
            'accepted_by' => (int) $member['id'],
            'accepted_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        );

        if ($accepting_company_id === $company_a) {
            $patch['max_discount_a_to_b'] = $max_discount_for_other_side;
        } else {
            $patch['max_discount_b_to_a'] = $max_discount_for_other_side;
        }

        $wpdb->update($t['relations'], $patch, array('id' => $relation_id));

        self::log_event($relation_id, 'relation_accepted', array('accepted_company_id' => $accepting_company_id), (int) $member['id']);

        $notify_company = ($accepting_company_id === $company_a) ? $company_b : $company_a;
        ZERP_Notifications::create_for_company(
            $notify_company,
            'relation_accepted',
            'Relacja została zaakceptowana',
            'Relacja firma-firma jest aktywna.',
            'relation',
            $relation_id,
            array('accepted_company_id' => $accepting_company_id)
        );

        return array('ok' => true);
    }

    public static function reject(int $relation_id, int $rejecting_company_id, string $reason = ''): array
    {
        $member = ZERP_Auth::require_member();
        if (!$member) {
            return array('ok' => false, 'message' => 'Brak autoryzacji.');
        }
        if ((int) $member['company_id'] !== $rejecting_company_id) {
            return array('ok' => false, 'message' => 'Brak dostępu do firmy.');
        }
        if (!ZERP_Auth::can('can_manage_company_relations')) {
            return array('ok' => false, 'message' => 'Brak uprawnień do relacji firm.');
        }

        global $wpdb;
        $t = ZERP_DB::tables();
        $relation = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['relations']} WHERE id = %d LIMIT 1", $relation_id), ARRAY_A);
        if (!$relation) {
            return array('ok' => false, 'message' => 'Relacja nie istnieje.');
        }

        $company_a = (int) $relation['company_a_id'];
        $company_b = (int) $relation['company_b_id'];
        if ($rejecting_company_id !== $company_a && $rejecting_company_id !== $company_b) {
            return array('ok' => false, 'message' => 'Firma nie należy do relacji.');
        }

        $wpdb->update(
            $t['relations'],
            array('status' => 'rejected', 'updated_at' => current_time('mysql')),
            array('id' => $relation_id),
            array('%s', '%s'),
            array('%d')
        );

        self::log_event($relation_id, 'relation_rejected', array('reason' => sanitize_textarea_field($reason), 'rejecting_company_id' => $rejecting_company_id), (int) $member['id']);

        $notify_company = ($rejecting_company_id === $company_a) ? $company_b : $company_a;
        ZERP_Notifications::create_for_company(
            $notify_company,
            'relation_rejected',
            'Relacja została odrzucona',
            'Zaproszenie do relacji zostało odrzucone.',
            'relation',
            $relation_id,
            array('rejecting_company_id' => $rejecting_company_id)
        );

        return array('ok' => true);
    }

    public static function find_relation(int $company_a_id, int $company_b_id): ?array
    {
        global $wpdb;
        $t = ZERP_DB::tables();

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$t['relations']}
                  WHERE (company_a_id = %d AND company_b_id = %d)
                     OR (company_a_id = %d AND company_b_id = %d)
                  LIMIT 1",
                $company_a_id,
                $company_b_id,
                $company_b_id,
                $company_a_id
            ),
            ARRAY_A
        );

        return $row ?: null;
    }

    public static function active_relation_id(int $company_a_id, int $company_b_id): ?int
    {
        $relation = self::find_relation($company_a_id, $company_b_id);
        if (!$relation || (string) $relation['status'] !== 'active') {
            return null;
        }
        return (int) $relation['id'];
    }

    public static function assert_member_relation_access(int $member_id, int $relation_id): bool
    {
        global $wpdb;
        $t = ZERP_DB::tables();

        $member_company_id = (int) $wpdb->get_var($wpdb->prepare("SELECT company_id FROM {$t['members']} WHERE id = %d LIMIT 1", $member_id));
        if ($member_company_id <= 0) {
            return false;
        }

        $relation = $wpdb->get_row($wpdb->prepare("SELECT company_a_id, company_b_id, status FROM {$t['relations']} WHERE id = %d LIMIT 1", $relation_id), ARRAY_A);
        if (!$relation || (string) $relation['status'] !== 'active') {
            return false;
        }

        return in_array($member_company_id, array((int) $relation['company_a_id'], (int) $relation['company_b_id']), true);
    }

    private static function log_event(int $relation_id, string $event_type, array $meta = array(), ?int $created_by = null): void
    {
        global $wpdb;
        $t = ZERP_DB::tables();
        $wpdb->insert(
            $t['relation_events'],
            array(
                'relation_id' => $relation_id,
                'event_type' => sanitize_key($event_type),
                'meta_json' => $meta ? wp_json_encode($meta) : null,
                'created_by' => $created_by,
                'created_at' => current_time('mysql'),
            ),
            array('%d', '%s', '%s', '%d', '%s')
        );
    }
}
