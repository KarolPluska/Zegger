<?php
if (!defined('ABSPATH')) {
    exit;
}

final class ZERP_Auth
{
    private static ?array $current = null;
    private static ?string $current_token_hash = null;

    public static function init(): void
    {
        // no hooks
    }

    public static function token_hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function set_auth_cookie(string $token, string $expires_at): void
    {
        $exp_ts = strtotime($expires_at);
        if (!$exp_ts) {
            $exp_ts = time() + (12 * HOUR_IN_SECONDS);
        }

        if (PHP_VERSION_ID < 70300) {
            setcookie('zerp_token', $token, $exp_ts, '/; samesite=Lax', '', is_ssl(), true);
        } else {
            setcookie('zerp_token', $token, array(
                'expires' => $exp_ts,
                'path' => '/',
                'secure' => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ));
        }

        $_COOKIE['zerp_token'] = $token;
    }

    public static function clear_auth_cookie(): void
    {
        if (PHP_VERSION_ID < 70300) {
            setcookie('zerp_token', '', time() - 3600, '/; samesite=Lax', '', is_ssl(), true);
        } else {
            setcookie('zerp_token', '', array(
                'expires' => time() - 3600,
                'path' => '/',
                'secure' => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ));
        }

        unset($_COOKIE['zerp_token']);
    }

    private static function sanitize_token(?string $token): string
    {
        $token = is_string($token) ? trim($token) : '';
        if (!$token) {
            return '';
        }
        if (!preg_match('/^[a-f0-9]{64}$/i', $token)) {
            return '';
        }
        return strtolower($token);
    }

    private static function bearer_from_headers(): string
    {
        $header = '';
        if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
            $header = (string) $_SERVER['HTTP_AUTHORIZATION'];
        }
        if (!$header && !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $header = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }

        if ($header && stripos($header, 'bearer ') === 0) {
            return self::sanitize_token(substr($header, 7));
        }

        if (!empty($_SERVER['HTTP_X_ZERP_TOKEN'])) {
            return self::sanitize_token((string) $_SERVER['HTTP_X_ZERP_TOKEN']);
        }

        return '';
    }

    private static function bearer_from_cookie(): string
    {
        if (empty($_COOKIE['zerp_token'])) {
            return '';
        }

        return self::sanitize_token((string) $_COOKIE['zerp_token']);
    }

    public static function bearer_token(): string
    {
        $header = self::bearer_from_headers();
        if ($header) {
            return $header;
        }
        return self::bearer_from_cookie();
    }

    public static function current_member(): ?array
    {
        return self::require_member(false);
    }

    public static function require_member(bool $enforce_active = true): ?array
    {
        if (self::$current !== null) {
            if ($enforce_active && (!empty(self::$current['status']) && self::$current['status'] !== 'active')) {
                return null;
            }
            return self::$current;
        }

        $token = self::bearer_token();
        if (!$token) {
            return null;
        }

        global $wpdb;
        $t = ZERP_DB::tables();

        $token_hash = self::token_hash($token);
        $now = current_time('mysql');

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT tok.member_id, tok.actor_member_id, tok.expires_at,
                        m.id, m.company_id, m.login, m.email, m.phone, m.first_name, m.last_name, m.role, m.status, m.is_owner,
                        m.module_visibility, m.profile,
                        c.name AS company_name, c.join_code, c.status AS company_status
                   FROM {$t['auth_tokens']} tok
                   JOIN {$t['members']} m ON m.id = tok.member_id
              LEFT JOIN {$t['companies']} c ON c.id = m.company_id
                  WHERE tok.token_hash = %s
                    AND tok.expires_at > %s
                  LIMIT 1",
                $token_hash,
                $now
            ),
            ARRAY_A
        );

        if (!$row) {
            return null;
        }

        $wpdb->update(
            $t['auth_tokens'],
            array('last_seen' => $now),
            array('token_hash' => $token_hash),
            array('%s'),
            array('%s')
        );

        $member_id = (int) $row['id'];
        $permissions = self::load_permissions($member_id, (string) $row['role'], (int) $row['is_owner'] === 1);

        $module_visibility = json_decode((string) ($row['module_visibility'] ?? ''), true);
        if (!is_array($module_visibility) || !$module_visibility) {
            $module_visibility = ZERP_Permissions::visible_modules($permissions);
        }
        if (!in_array('notifications', $module_visibility, true) && !empty($permissions['notifications_center_access'])) {
            $module_visibility[] = 'notifications';
        }

        self::$current = array(
            'id' => $member_id,
            'company_id' => !empty($row['company_id']) ? (int) $row['company_id'] : null,
            'login' => (string) $row['login'],
            'email' => (string) $row['email'],
            'phone' => (string) ($row['phone'] ?? ''),
            'first_name' => (string) $row['first_name'],
            'last_name' => (string) $row['last_name'],
            'role' => (string) $row['role'],
            'status' => (string) $row['status'],
            'is_owner' => (int) $row['is_owner'] === 1,
            'profile' => json_decode((string) ($row['profile'] ?? ''), true),
            'permissions' => $permissions,
            'module_visibility' => $module_visibility,
            'actor_member_id' => !empty($row['actor_member_id']) ? (int) $row['actor_member_id'] : null,
            'company' => array(
                'id' => !empty($row['company_id']) ? (int) $row['company_id'] : null,
                'name' => (string) ($row['company_name'] ?? ''),
                'join_code' => (string) ($row['join_code'] ?? ''),
                'status' => (string) ($row['company_status'] ?? 'inactive'),
            ),
        );

        self::$current_token_hash = $token_hash;

        if ($enforce_active && self::$current['status'] !== 'active') {
            return null;
        }

        return self::$current;
    }

    private static function load_permissions(int $member_id, string $role, bool $is_owner): array
    {
        if ($is_owner) {
            return ZERP_Permissions::owner_defaults();
        }

        global $wpdb;
        $t = ZERP_DB::tables();
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT permission_key, allow_flag FROM {$t['member_permissions']} WHERE member_id = %d",
                $member_id
            ),
            ARRAY_A
        );

        if (!$rows) {
            return ZERP_Permissions::defaults_for_role($role);
        }

        $map = array();
        foreach ($rows as $row) {
            $key = sanitize_key((string) $row['permission_key']);
            if (!$key) {
                continue;
            }
            $map[$key] = !empty($row['allow_flag']) ? 1 : 0;
        }

        return ZERP_Permissions::normalize($map);
    }

    public static function actor_has_permission(string $key): bool
    {
        $member = self::require_member();
        if (!$member) {
            return false;
        }

        $key = sanitize_key($key);
        $actor_id = !empty($member['actor_member_id']) ? (int) $member['actor_member_id'] : 0;
        if ($actor_id <= 0 || $actor_id === (int) $member['id']) {
            return !empty($member['permissions'][$key]);
        }

        global $wpdb;
        $t = ZERP_DB::tables();
        $actor = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, role, is_owner FROM {$t['members']} WHERE id = %d LIMIT 1",
                $actor_id
            ),
            ARRAY_A
        );
        if (!$actor) {
            return false;
        }

        $permissions = self::load_permissions((int) $actor['id'], (string) $actor['role'], !empty($actor['is_owner']));
        return !empty($permissions[$key]);
    }

    public static function can(string $key): bool
    {
        $member = self::require_member();
        if (!$member) {
            return false;
        }

        $key = sanitize_key($key);
        if (!empty($member['permissions'][$key])) {
            return true;
        }

        return self::actor_has_permission($key);
    }

    public static function login(string $login_or_email, string $password): array
    {
        global $wpdb;
        $t = ZERP_DB::tables();

        $login_or_email = sanitize_text_field($login_or_email);
        $password = (string) $password;

        if (!$login_or_email || !$password) {
            return array('ok' => false, 'message' => 'Uzupełnij login/email i hasło.');
        }

        $rate = self::check_login_rate_limit($login_or_email);
        if (!empty($rate['blocked'])) {
            return array('ok' => false, 'message' => 'Zbyt wiele prób logowania. Spróbuj ponownie później.');
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$t['members']} WHERE login = %s OR email = %s LIMIT 1",
                $login_or_email,
                $login_or_email
            ),
            ARRAY_A
        );

        if (!$row || empty($row['pass_hash']) || !password_verify($password, (string) $row['pass_hash'])) {
            self::hit_login_rate_limit($login_or_email);
            return array('ok' => false, 'message' => 'Błędne dane logowania.');
        }

        if ((string) $row['status'] === 'suspended') {
            return array('ok' => false, 'message' => 'Konto jest zawieszone.');
        }

        self::clear_login_rate_limit($login_or_email);
        $issued = self::issue_token((int) $row['id']);

        $wpdb->update(
            $t['members'],
            array('last_login_at' => current_time('mysql')),
            array('id' => (int) $row['id']),
            array('%s'),
            array('%d')
        );

        return array(
            'ok' => true,
            'token' => $issued['token'],
            'expires_at' => $issued['expires_at'],
            'member' => self::member_public((int) $row['id']),
        );
    }

    public static function logout(): array
    {
        $member = self::require_member(false);
        if (!$member) {
            self::clear_auth_cookie();
            return array('ok' => true);
        }

        global $wpdb;
        $t = ZERP_DB::tables();

        if (self::$current_token_hash) {
            $wpdb->delete($t['auth_tokens'], array('token_hash' => self::$current_token_hash), array('%s'));
        }

        self::$current = null;
        self::$current_token_hash = null;
        self::clear_auth_cookie();

        return array('ok' => true);
    }

    public static function issue_token(int $member_id, ?int $actor_member_id = null): array
    {
        global $wpdb;
        $t = ZERP_DB::tables();

        $settings = ZERP_DB::settings();
        $hours = isset($settings['session_hours']) ? (int) $settings['session_hours'] : 12;
        $hours = max(1, min(168, $hours));

        $token = bin2hex(random_bytes(32));
        $hash = self::token_hash($token);
        $now = current_time('mysql');
        $expires_at = wp_date('Y-m-d H:i:s', current_time('timestamp') + ($hours * HOUR_IN_SECONDS));

        $wpdb->insert(
            $t['auth_tokens'],
            array(
                'member_id' => $member_id,
                'actor_member_id' => $actor_member_id,
                'token_hash' => $hash,
                'created_at' => $now,
                'expires_at' => $expires_at,
                'last_seen' => $now,
                'ip' => self::client_ip(),
                'ua' => self::client_ua(),
            ),
            array('%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s')
        );

        self::enforce_token_limit($member_id);

        return array(
            'token' => $token,
            'expires_at' => $expires_at,
        );
    }

    private static function enforce_token_limit(int $member_id): void
    {
        global $wpdb;
        $t = ZERP_DB::tables();

        $settings = ZERP_DB::settings();
        $limit = isset($settings['max_active_tokens_per_member']) ? (int) $settings['max_active_tokens_per_member'] : 5;
        $limit = max(1, min(20, $limit));

        $now = current_time('mysql');
        $wpdb->query($wpdb->prepare("DELETE FROM {$t['auth_tokens']} WHERE member_id = %d AND expires_at <= %s", $member_id, $now));

        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT id FROM {$t['auth_tokens']}
                  WHERE member_id = %d
               ORDER BY last_seen DESC, id DESC",
                $member_id
            )
        );

        if (!is_array($ids) || count($ids) <= $limit) {
            return;
        }

        $drop = array_slice($ids, $limit);
        if (!$drop) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($drop), '%d'));
        $sql = "DELETE FROM {$t['auth_tokens']} WHERE id IN ({$placeholders})";
        $wpdb->query($wpdb->prepare($sql, ...$drop));
    }

    public static function member_public(int $member_id): ?array
    {
        global $wpdb;
        $t = ZERP_DB::tables();

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT m.id, m.company_id, m.login, m.email, m.phone, m.first_name, m.last_name, m.role, m.status, m.is_owner,
                        m.module_visibility, c.name AS company_name, c.join_code
                   FROM {$t['members']} m
              LEFT JOIN {$t['companies']} c ON c.id = m.company_id
                  WHERE m.id = %d LIMIT 1",
                $member_id
            ),
            ARRAY_A
        );

        if (!$row) {
            return null;
        }

        $permissions = self::load_permissions((int) $row['id'], (string) $row['role'], !empty($row['is_owner']));

        $visibility = json_decode((string) ($row['module_visibility'] ?? ''), true);
        if (!is_array($visibility) || !$visibility) {
            $visibility = ZERP_Permissions::visible_modules($permissions);
        }
        if (!in_array('notifications', $visibility, true) && !empty($permissions['notifications_center_access'])) {
            $visibility[] = 'notifications';
        }

        return array(
            'id' => (int) $row['id'],
            'company_id' => !empty($row['company_id']) ? (int) $row['company_id'] : null,
            'login' => (string) $row['login'],
            'email' => (string) $row['email'],
            'phone' => (string) ($row['phone'] ?? ''),
            'first_name' => (string) ($row['first_name'] ?? ''),
            'last_name' => (string) ($row['last_name'] ?? ''),
            'role' => (string) $row['role'],
            'status' => (string) $row['status'],
            'is_owner' => !empty($row['is_owner']),
            'permissions' => $permissions,
            'module_visibility' => $visibility,
            'company' => array(
                'name' => (string) ($row['company_name'] ?? ''),
                'join_code' => (string) ($row['join_code'] ?? ''),
            ),
        );
    }

    public static function register_company_owner(array $payload): array
    {
        global $wpdb;
        $t = ZERP_DB::tables();

        $first_name = sanitize_text_field((string) ($payload['first_name'] ?? ''));
        $last_name = sanitize_text_field((string) ($payload['last_name'] ?? ''));
        $email = sanitize_email((string) ($payload['email'] ?? ''));
        $phone = sanitize_text_field((string) ($payload['phone'] ?? ''));
        $password = (string) ($payload['password'] ?? '');

        $company_name = sanitize_text_field((string) ($payload['company_name'] ?? ''));
        $company_nip = preg_replace('/\s+/', '', sanitize_text_field((string) ($payload['company_nip'] ?? '')));
        $company_address = sanitize_textarea_field((string) ($payload['company_address'] ?? ''));
        $company_email = sanitize_email((string) ($payload['company_email'] ?? ''));
        $company_phone = sanitize_text_field((string) ($payload['company_phone'] ?? ''));

        if (!$first_name || !$last_name || !$email || !$password || !$company_name || !$company_nip) {
            return array('ok' => false, 'message' => 'Brak wymaganych danych rejestracji firmy.');
        }

        if (!is_email($email) || !is_email($company_email)) {
            return array('ok' => false, 'message' => 'Niepoprawny adres e-mail.');
        }

        if (strlen($password) < 8) {
            return array('ok' => false, 'message' => 'Hasło musi mieć co najmniej 8 znaków.');
        }

        $exists = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['companies']} WHERE nip = %s", $company_nip));
        if ($exists > 0) {
            return array('ok' => false, 'message' => 'Firma o podanym NIP już istnieje.');
        }

        $login = self::suggest_login($first_name, $last_name, $email);
        if (self::login_exists($login)) {
            return array('ok' => false, 'message' => 'Nie udało się wygenerować unikalnego loginu.');
        }

        $now = current_time('mysql');
        $join_code = ZERP_DB::generate_join_code();
        while (self::join_code_exists($join_code)) {
            $join_code = ZERP_DB::generate_join_code();
        }

        $wpdb->insert(
            $t['companies'],
            array(
                'name' => $company_name,
                'nip' => $company_nip,
                'address' => $company_address,
                'company_email' => $company_email,
                'company_phone' => $company_phone,
                'join_code' => $join_code,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ),
            array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
        );

        $company_id = (int) $wpdb->insert_id;

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
                'role' => 'owner',
                'status' => 'active',
                'is_owner' => 1,
                'module_visibility' => wp_json_encode(ZERP_Permissions::visible_modules(ZERP_Permissions::owner_defaults())),
                'created_at' => $now,
                'updated_at' => $now,
            ),
            array('%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s')
        );

        $member_id = (int) $wpdb->insert_id;
        $wpdb->update(
            $t['companies'],
            array('owner_member_id' => $member_id, 'updated_at' => $now),
            array('id' => $company_id),
            array('%d', '%s'),
            array('%d')
        );

        self::replace_permissions($member_id, ZERP_Permissions::owner_defaults());

        return array(
            'ok' => true,
            'company_id' => $company_id,
            'member_id' => $member_id,
            'login' => $login,
            'join_code' => $join_code,
        );
    }

    public static function register_member_join_request(array $payload): array
    {
        global $wpdb;
        $t = ZERP_DB::tables();

        $first_name = sanitize_text_field((string) ($payload['first_name'] ?? ''));
        $last_name = sanitize_text_field((string) ($payload['last_name'] ?? ''));
        $email = sanitize_email((string) ($payload['email'] ?? ''));
        $phone = sanitize_text_field((string) ($payload['phone'] ?? ''));
        $password = (string) ($payload['password'] ?? '');
        $company_query = sanitize_text_field((string) ($payload['company_query'] ?? ''));
        $join_code = sanitize_text_field((string) ($payload['join_code'] ?? ''));

        if (!$first_name || !$last_name || !$email || !$password) {
            return array('ok' => false, 'message' => 'Brak wymaganych danych użytkownika.');
        }

        if (!is_email($email)) {
            return array('ok' => false, 'message' => 'Niepoprawny e-mail.');
        }

        if (strlen($password) < 8) {
            return array('ok' => false, 'message' => 'Hasło musi mieć co najmniej 8 znaków.');
        }

        $login = self::suggest_login($first_name, $last_name, $email);
        if (self::login_exists($login)) {
            return array('ok' => false, 'message' => 'Nie udało się wygenerować unikalnego loginu.');
        }

        $target = null;
        if ($join_code !== '') {
            $target = $wpdb->get_row($wpdb->prepare("SELECT id, name, join_code FROM {$t['companies']} WHERE join_code = %s LIMIT 1", $join_code), ARRAY_A);
        }
        if (!$target && $company_query !== '') {
            $query_like = '%' . $wpdb->esc_like($company_query) . '%';
            $target = $wpdb->get_row($wpdb->prepare("SELECT id, name, join_code FROM {$t['companies']} WHERE name LIKE %s ORDER BY id ASC LIMIT 1", $query_like), ARRAY_A);
        }
        if (!$target) {
            return array('ok' => false, 'message' => 'Nie znaleziono firmy docelowej.');
        }

        $now = current_time('mysql');
        $ttl_days = isset(ZERP_DB::settings()['orphan_account_ttl_days']) ? (int) ZERP_DB::settings()['orphan_account_ttl_days'] : 7;
        $ttl_days = max(1, min(30, $ttl_days));
        $expires_at = wp_date('Y-m-d H:i:s', current_time('timestamp') + ($ttl_days * DAY_IN_SECONDS));

        $wpdb->insert(
            $t['members'],
            array(
                'company_id' => null,
                'login' => $login,
                'email' => $email,
                'phone' => $phone,
                'first_name' => $first_name,
                'last_name' => $last_name,
                'pass_hash' => password_hash($password, PASSWORD_DEFAULT),
                'role' => 'user',
                'status' => 'pending_join',
                'is_owner' => 0,
                'module_visibility' => wp_json_encode(array('dashboard')),
                'created_at' => $now,
                'updated_at' => $now,
            ),
            array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s')
        );

        $member_id = (int) $wpdb->insert_id;
        self::replace_permissions($member_id, ZERP_Permissions::user_defaults());

        $wpdb->insert(
            $t['join_requests'],
            array(
                'pending_member_id' => $member_id,
                'company_id' => (int) $target['id'],
                'target_join_code' => (string) $target['join_code'],
                'status' => 'pending',
                'expires_at' => $expires_at,
                'payload' => wp_json_encode(array('company_name' => $target['name'])),
                'created_at' => $now,
                'updated_at' => $now,
            ),
            array('%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s')
        );

        return array(
            'ok' => true,
            'member_id' => $member_id,
            'expires_at' => $expires_at,
            'company' => array(
                'id' => (int) $target['id'],
                'name' => (string) $target['name'],
                'join_code' => (string) $target['join_code'],
            ),
        );
    }

    public static function approve_join_request(int $request_id, int $reviewer_member_id): array
    {
        global $wpdb;
        $t = ZERP_DB::tables();

        $request = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['join_requests']} WHERE id = %d LIMIT 1", $request_id), ARRAY_A);
        if (!$request || (string) $request['status'] !== 'pending') {
            return array('ok' => false, 'message' => 'Wniosek nie istnieje lub został już obsłużony.');
        }

        $now = current_time('mysql');
        if (strtotime((string) $request['expires_at']) < current_time('timestamp')) {
            $wpdb->update(
                $t['join_requests'],
                array('status' => 'expired', 'updated_at' => $now),
                array('id' => $request_id),
                array('%s', '%s'),
                array('%d')
            );
            return array('ok' => false, 'message' => 'Wniosek wygasł.');
        }

        $wpdb->update(
            $t['members'],
            array(
                'company_id' => (int) $request['company_id'],
                'status' => 'active',
                'updated_at' => $now,
            ),
            array('id' => (int) $request['pending_member_id']),
            array('%d', '%s', '%s'),
            array('%d')
        );

        $wpdb->update(
            $t['join_requests'],
            array(
                'status' => 'approved',
                'reviewed_by' => $reviewer_member_id,
                'reviewed_at' => $now,
                'updated_at' => $now,
            ),
            array('id' => $request_id),
            array('%s', '%d', '%s', '%s'),
            array('%d')
        );

        ZERP_Notifications::create_for_member((int) $request['pending_member_id'], 'join_request_approved', 'Dołączenie do firmy zaakceptowane', 'Twoje konto zostało przypisane do firmy.');

        return array('ok' => true);
    }

    public static function reject_join_request(int $request_id, int $reviewer_member_id, string $reason = ''): array
    {
        global $wpdb;
        $t = ZERP_DB::tables();

        $request = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['join_requests']} WHERE id = %d LIMIT 1", $request_id), ARRAY_A);
        if (!$request || (string) $request['status'] !== 'pending') {
            return array('ok' => false, 'message' => 'Wniosek nie istnieje lub został już obsłużony.');
        }

        $now = current_time('mysql');
        $wpdb->update(
            $t['join_requests'],
            array(
                'status' => 'rejected',
                'reason' => sanitize_textarea_field($reason),
                'reviewed_by' => $reviewer_member_id,
                'reviewed_at' => $now,
                'updated_at' => $now,
            ),
            array('id' => $request_id),
            array('%s', '%s', '%d', '%s', '%s'),
            array('%d')
        );

        ZERP_Notifications::create_for_member((int) $request['pending_member_id'], 'join_request_rejected', 'Wniosek o dołączenie odrzucony', 'Możesz poprawić dane i wysłać nowy wniosek.');

        return array('ok' => true);
    }

    public static function impersonate(int $target_member_id): array
    {
        $current = self::require_member();
        if (!$current) {
            return array('ok' => false, 'message' => 'Brak autoryzacji.');
        }

        if (!$current['is_owner'] && !self::can('can_impersonate_accounts')) {
            return array('ok' => false, 'message' => 'Brak uprawnień do impersonacji.');
        }

        $target = self::member_public($target_member_id);
        if (!$target) {
            return array('ok' => false, 'message' => 'Nie znaleziono konta docelowego.');
        }

        if ((int) $target['company_id'] !== (int) $current['company_id']) {
            return array('ok' => false, 'message' => 'Impersonacja tylko w obrębie tej samej firmy.');
        }

        $issued = self::issue_token((int) $target['id'], (int) $current['id']);

        return array(
            'ok' => true,
            'token' => $issued['token'],
            'expires_at' => $issued['expires_at'],
            'member' => $target,
            'actor_member_id' => (int) $current['id'],
        );
    }

    public static function replace_permissions(int $member_id, array $permissions): void
    {
        global $wpdb;
        $t = ZERP_DB::tables();

        $permissions = ZERP_Permissions::normalize($permissions);

        $wpdb->delete($t['member_permissions'], array('member_id' => $member_id), array('%d'));
        $now = current_time('mysql');

        foreach ($permissions as $key => $allow) {
            $wpdb->insert(
                $t['member_permissions'],
                array(
                    'member_id' => $member_id,
                    'permission_key' => $key,
                    'allow_flag' => !empty($allow) ? 1 : 0,
                    'updated_at' => $now,
                ),
                array('%d', '%s', '%d', '%s')
            );
        }
    }

    public static function suggest_login(string $first_name, string $last_name, string $email): string
    {
        $base = sanitize_user(
            strtolower(
                preg_replace('/[^a-z0-9]+/i', '.', trim($first_name . '.' . $last_name))
            ),
            true
        );
        if (!$base) {
            $base = sanitize_user(strstr($email, '@', true), true);
        }
        if (!$base) {
            $base = 'user';
        }

        $candidate = $base;
        $i = 1;
        while (self::login_exists($candidate) && $i < 1000) {
            $candidate = $base . $i;
            $i++;
        }

        return $candidate;
    }

    private static function login_exists(string $login): bool
    {
        global $wpdb;
        $t = ZERP_DB::tables();
        $count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['members']} WHERE login = %s", $login));
        return $count > 0;
    }

    private static function join_code_exists(string $join_code): bool
    {
        global $wpdb;
        $t = ZERP_DB::tables();
        $count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['companies']} WHERE join_code = %s", $join_code));
        return $count > 0;
    }

    private static function check_login_rate_limit(string $identity): array
    {
        $settings = ZERP_DB::settings();
        $attempts = isset($settings['login_rate_attempts']) ? (int) $settings['login_rate_attempts'] : 10;
        $window = isset($settings['login_rate_window_minutes']) ? (int) $settings['login_rate_window_minutes'] : 10;

        $attempts = max(3, min(50, $attempts));
        $window = max(1, min(120, $window));

        $key = 'zerp_login_rl_' . md5(strtolower($identity) . '|' . self::client_ip());
        $state = get_transient($key);

        if (!is_array($state)) {
            return array('blocked' => false);
        }

        $count = isset($state['count']) ? (int) $state['count'] : 0;
        $first = isset($state['first']) ? (int) $state['first'] : time();
        $age = time() - $first;

        if ($count >= $attempts && $age < ($window * MINUTE_IN_SECONDS)) {
            return array('blocked' => true);
        }

        return array('blocked' => false);
    }

    private static function hit_login_rate_limit(string $identity): void
    {
        $settings = ZERP_DB::settings();
        $window = isset($settings['login_rate_window_minutes']) ? (int) $settings['login_rate_window_minutes'] : 10;
        $window = max(1, min(120, $window));

        $key = 'zerp_login_rl_' . md5(strtolower($identity) . '|' . self::client_ip());
        $state = get_transient($key);
        if (!is_array($state)) {
            $state = array('count' => 0, 'first' => time());
        }

        $state['count'] = isset($state['count']) ? (int) $state['count'] + 1 : 1;
        set_transient($key, $state, $window * MINUTE_IN_SECONDS);
    }

    private static function clear_login_rate_limit(string $identity): void
    {
        $key = 'zerp_login_rl_' . md5(strtolower($identity) . '|' . self::client_ip());
        delete_transient($key);
    }

    private static function client_ip(): ?string
    {
        $ip = !empty($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        $ip = trim($ip);
        if (!$ip) {
            return null;
        }
        return substr($ip, 0, 64);
    }

    private static function client_ua(): ?string
    {
        $ua = !empty($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
        $ua = trim($ua);
        if (!$ua) {
            return null;
        }
        return substr($ua, 0, 255);
    }
}

