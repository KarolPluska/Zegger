<?php
if (!defined('ABSPATH')) {
    exit;
}

final class ZERP_Members
{
    public static function init(): void
    {
        // routes via REST
    }

    public static function list_company_members(int $company_id): array
    {
        global $wpdb;
        $t = ZERP_DB::tables();

        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT id FROM {$t['members']} WHERE company_id = %d ORDER BY is_owner DESC, id ASC", $company_id),
            ARRAY_A
        );

        if (!$rows) {
            return array();
        }

        $out = array();
        foreach ($rows as $row) {
            $member = ZERP_Auth::member_public((int) $row['id']);
            if ($member) {
                $out[] = $member;
            }
        }

        return $out;
    }

    public static function create_member(int $company_id, array $payload): array
    {
        $current = ZERP_Auth::require_member();
        if (!$current) {
            return array('ok' => false, 'message' => 'Brak autoryzacji.');
        }
        if ((int) $current['company_id'] !== $company_id) {
            return array('ok' => false, 'message' => 'Brak dostępu do firmy.');
        }
        if (!ZERP_Auth::can('can_create_company_members')) {
            return array('ok' => false, 'message' => 'Brak uprawnień do tworzenia kont.');
        }

        $first_name = sanitize_text_field((string) ($payload['first_name'] ?? ''));
        $last_name = sanitize_text_field((string) ($payload['last_name'] ?? ''));
        $email = sanitize_email((string) ($payload['email'] ?? ''));
        $phone = sanitize_text_field((string) ($payload['phone'] ?? ''));
        $role = sanitize_key((string) ($payload['role'] ?? 'user'));
        $password = (string) ($payload['password'] ?? wp_generate_password(14, true, true));

        if (!$first_name || !$last_name || !$email) {
            return array('ok' => false, 'message' => 'Brak wymaganych danych użytkownika.');
        }
        if (!is_email($email)) {
            return array('ok' => false, 'message' => 'Niepoprawny e-mail.');
        }
        if (!in_array($role, array('manager', 'user'), true)) {
            $role = 'user';
        }
        if (strlen($password) < 8) {
            return array('ok' => false, 'message' => 'Hasło musi mieć co najmniej 8 znaków.');
        }

        $login = ZERP_Auth::suggest_login($first_name, $last_name, $email);

        global $wpdb;
        $t = ZERP_DB::tables();
        $exists = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['members']} WHERE login = %s OR email = %s", $login, $email));
        if ($exists > 0) {
            return array('ok' => false, 'message' => 'Konto o podanym loginie/e-mailu już istnieje.');
        }

        $default_permissions = ZERP_Permissions::defaults_for_role($role);
        $module_visibility = ZERP_Permissions::visible_modules($default_permissions);

        $now = current_time('mysql');
        $wpdb->insert(
            $t['members'],
            array(
                'company_id' => $company_id,
                'login' => $login,
                'email' => $email,
                'phone' => $phone,
                'first_name' => $first_name,
                'last_name' => $last_name,
                'pass_hash' => password_hash($password, PASSWORD_DEFAULT),
                'role' => $role,
                'status' => 'active',
                'is_owner' => 0,
                'module_visibility' => wp_json_encode($module_visibility),
                'created_by' => (int) $current['id'],
                'updated_by' => (int) $current['id'],
                'created_at' => $now,
                'updated_at' => $now,
            ),
            array('%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%s', '%s')
        );

        $member_id = (int) $wpdb->insert_id;
        ZERP_Auth::replace_permissions($member_id, $default_permissions);

        ZERP_Notifications::create_for_member($member_id, 'system_event', 'Konto zostało utworzone', 'Twoje konto zostało dodane do firmy.');

        return array(
            'ok' => true,
            'member' => ZERP_Auth::member_public($member_id),
            'generated_login' => $login,
            'generated_password' => $password,
        );
    }

    public static function update_member(int $company_id, int $member_id, array $payload): array
    {
        $current = ZERP_Auth::require_member();
        if (!$current) {
            return array('ok' => false, 'message' => 'Brak autoryzacji.');
        }
        if ((int) $current['company_id'] !== $company_id) {
            return array('ok' => false, 'message' => 'Brak dostępu do firmy.');
        }
        if (!ZERP_Auth::can('can_edit_company_members')) {
            return array('ok' => false, 'message' => 'Brak uprawnień do edycji kont.');
        }

        global $wpdb;
        $t = ZERP_DB::tables();

        $member = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['members']} WHERE id = %d AND company_id = %d LIMIT 1", $member_id, $company_id), ARRAY_A);
        if (!$member) {
            return array('ok' => false, 'message' => 'Nie znaleziono użytkownika.');
        }

        if (!empty($member['is_owner'])) {
            if (isset($payload['status']) && sanitize_key((string) $payload['status']) === 'suspended') {
                return array('ok' => false, 'message' => 'Nie można zawiesić ownera.');
            }
            if (isset($payload['role']) && sanitize_key((string) $payload['role']) !== 'owner') {
                return array('ok' => false, 'message' => 'Nie można zmienić roli ownera.');
            }
        }

        $patch = array();
        $format = array();

        foreach (array('first_name', 'last_name', 'phone') as $text_field) {
            if (!array_key_exists($text_field, $payload)) {
                continue;
            }
            $patch[$text_field] = sanitize_text_field((string) $payload[$text_field]);
            $format[] = '%s';
        }

        if (array_key_exists('email', $payload)) {
            $email = sanitize_email((string) $payload['email']);
            if (!is_email($email)) {
                return array('ok' => false, 'message' => 'Niepoprawny e-mail.');
            }
            $patch['email'] = $email;
            $format[] = '%s';
        }

        if (array_key_exists('role', $payload) && empty($member['is_owner'])) {
            $role = sanitize_key((string) $payload['role']);
            if (!in_array($role, array('manager', 'user'), true)) {
                $role = 'user';
            }
            $patch['role'] = $role;
            $format[] = '%s';
        }

        if (array_key_exists('status', $payload)) {
            $status = sanitize_key((string) $payload['status']);
            if (!in_array($status, array('active', 'suspended', 'pending_join'), true)) {
                $status = 'active';
            }
            if (!empty($member['is_owner']) && $status !== 'active') {
                return array('ok' => false, 'message' => 'Owner musi pozostać aktywny.');
            }
            $patch['status'] = $status;
            $format[] = '%s';
        }

        if (array_key_exists('module_visibility', $payload)) {
            $module_visibility = is_array($payload['module_visibility']) ? array_values(array_map('sanitize_key', $payload['module_visibility'])) : array();
            if (!empty($member['is_owner'])) {
                return array('ok' => false, 'message' => 'Nie można ukrywać modułów ownerowi.');
            }
            $patch['module_visibility'] = wp_json_encode($module_visibility);
            $format[] = '%s';
        }

        if ($patch) {
            $patch['updated_by'] = (int) $current['id'];
            $patch['updated_at'] = current_time('mysql');
            $format[] = '%d';
            $format[] = '%s';

            $wpdb->update($t['members'], $patch, array('id' => $member_id), $format, array('%d'));
        }

        if (array_key_exists('permissions', $payload)) {
            if (!ZERP_Auth::can('can_assign_member_permissions')) {
                return array('ok' => false, 'message' => 'Brak uprawnień do przypisywania uprawnień.');
            }
            if (!empty($member['is_owner'])) {
                return array('ok' => false, 'message' => 'Nie można zmieniać uprawnień ownera.');
            }

            $permissions = is_array($payload['permissions']) ? $payload['permissions'] : array();
            ZERP_Auth::replace_permissions($member_id, $permissions);
        }

        if (array_key_exists('password', $payload) && $payload['password'] !== '') {
            if (!ZERP_Auth::can('can_reset_member_passwords')) {
                return array('ok' => false, 'message' => 'Brak uprawnień do resetu hasła.');
            }
            $password = (string) $payload['password'];
            if (strlen($password) < 8) {
                return array('ok' => false, 'message' => 'Hasło musi mieć co najmniej 8 znaków.');
            }
            $wpdb->update(
                $t['members'],
                array('pass_hash' => password_hash($password, PASSWORD_DEFAULT), 'updated_by' => (int) $current['id'], 'updated_at' => current_time('mysql')),
                array('id' => $member_id),
                array('%s', '%d', '%s'),
                array('%d')
            );
        }

        return array('ok' => true, 'member' => ZERP_Auth::member_public($member_id));
    }

    public static function suspend_member(int $company_id, int $member_id): array
    {
        return self::update_member($company_id, $member_id, array('status' => 'suspended'));
    }

    public static function reactivate_member(int $company_id, int $member_id): array
    {
        return self::update_member($company_id, $member_id, array('status' => 'active'));
    }
}
