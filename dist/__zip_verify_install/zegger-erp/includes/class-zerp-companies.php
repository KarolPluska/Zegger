<?php
if (!defined('ABSPATH')) {
    exit;
}

final class ZERP_Companies
{
    public static function init(): void
    {
        // routes via REST
    }

    public static function get_company(int $company_id): ?array
    {
        global $wpdb;
        $t = ZERP_DB::tables();

        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['companies']} WHERE id = %d LIMIT 1", $company_id), ARRAY_A);
        if (!$row) {
            return null;
        }

        $row['id'] = (int) $row['id'];
        $row['owner_member_id'] = !empty($row['owner_member_id']) ? (int) $row['owner_member_id'] : null;
        $row['meta'] = !empty($row['meta_json']) ? json_decode((string) $row['meta_json'], true) : null;
        unset($row['meta_json']);

        return $row;
    }

    public static function update_company(int $company_id, array $payload): array
    {
        $member = ZERP_Auth::require_member();
        if (!$member) {
            return array('ok' => false, 'message' => 'Brak autoryzacji.');
        }

        if ((int) $member['company_id'] !== $company_id) {
            return array('ok' => false, 'message' => 'Brak dostępu do firmy.');
        }

        if (!ZERP_Auth::can('can_edit_company_profile')) {
            return array('ok' => false, 'message' => 'Brak uprawnień do edycji firmy.');
        }

        $allowed = array(
            'name', 'address', 'company_email', 'company_phone', 'www', 'logo_url', 'status'
        );

        $patch = array();
        $format = array();

        foreach ($allowed as $field) {
            if (!array_key_exists($field, $payload)) {
                continue;
            }

            $value = $payload[$field];
            if ($field === 'company_email') {
                $value = sanitize_email((string) $value);
            } elseif ($field === 'address') {
                $value = sanitize_textarea_field((string) $value);
            } elseif ($field === 'status') {
                $value = sanitize_key((string) $value);
                if (!in_array($value, array('active', 'inactive'), true)) {
                    $value = 'active';
                }
            } else {
                $value = sanitize_text_field((string) $value);
            }

            $patch[$field] = $value;
            $format[] = '%s';
        }

        if (!$patch) {
            return array('ok' => true, 'updated' => false, 'company' => self::get_company($company_id));
        }

        $patch['updated_at'] = current_time('mysql');
        $format[] = '%s';

        global $wpdb;
        $t = ZERP_DB::tables();
        $wpdb->update($t['companies'], $patch, array('id' => $company_id), $format, array('%d'));

        return array('ok' => true, 'updated' => true, 'company' => self::get_company($company_id));
    }

    public static function regenerate_join_code(int $company_id): array
    {
        $member = ZERP_Auth::require_member();
        if (!$member) {
            return array('ok' => false, 'message' => 'Brak autoryzacji.');
        }

        if ((int) $member['company_id'] !== $company_id) {
            return array('ok' => false, 'message' => 'Brak dostępu do firmy.');
        }

        if (!ZERP_Auth::can('can_manage_join_code')) {
            return array('ok' => false, 'message' => 'Brak uprawnień do zarządzania join code.');
        }

        global $wpdb;
        $t = ZERP_DB::tables();
        $join_code = ZERP_DB::generate_join_code();
        while ((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['companies']} WHERE join_code = %s", $join_code)) > 0) {
            $join_code = ZERP_DB::generate_join_code();
        }

        $wpdb->update(
            $t['companies'],
            array('join_code' => $join_code, 'updated_at' => current_time('mysql')),
            array('id' => $company_id),
            array('%s', '%s'),
            array('%d')
        );

        return array('ok' => true, 'join_code' => $join_code);
    }

    public static function search_companies(string $query, int $limit = 20): array
    {
        global $wpdb;
        $t = ZERP_DB::tables();

        $limit = max(1, min(50, $limit));
        $query = trim($query);
        if ($query === '') {
            return array();
        }

        $like = '%' . $wpdb->esc_like($query) . '%';
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT c.id, c.name, c.nip, c.address, c.company_phone, c.company_email, c.join_code, c.owner_member_id,
                        m.first_name AS owner_first_name, m.last_name AS owner_last_name, m.login AS owner_login
                   FROM {$t['companies']} c
              LEFT JOIN {$t['members']} m ON m.id = c.owner_member_id
                  WHERE c.name LIKE %s
                     OR c.join_code LIKE %s
                     OR c.nip LIKE %s
               ORDER BY c.name ASC
                  LIMIT {$limit}",
                $like,
                $like,
                $like
            ),
            ARRAY_A
        );

        if (!$rows) {
            return array();
        }

        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['owner_member_id'] = !empty($row['owner_member_id']) ? (int) $row['owner_member_id'] : null;
            $row['owner'] = trim((string) ($row['owner_first_name'] . ' ' . $row['owner_last_name']));
            if (!$row['owner']) {
                $row['owner'] = (string) ($row['owner_login'] ?? '');
            }
            unset($row['owner_first_name'], $row['owner_last_name'], $row['owner_login']);
        }

        return $rows;
    }
}
