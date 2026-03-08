<?php
if (!defined('ABSPATH')) {
    exit;
}

final class ZERP_Rest
{
    public static function init(): void
    {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
    }

    private static function reg(string $path, $methods, string $callback, $permission = 'perm_member'): void
    {
        $perm_cb = $permission;
        if (is_string($permission) && $permission !== '__return_true') {
            $perm_cb = array(__CLASS__, $permission);
        }

        register_rest_route(ZERP_REST_NS, $path, array(
            'methods' => $methods,
            'permission_callback' => $perm_cb,
            'callback' => array(__CLASS__, $callback),
        ));
    }

    public static function register_routes(): void
    {
        self::reg('/ping', WP_REST_Server::READABLE, 'route_ping', '__return_true');

        // Auth
        self::reg('/auth/login', WP_REST_Server::CREATABLE, 'route_auth_login', '__return_true');
        self::reg('/auth/logout', WP_REST_Server::CREATABLE, 'route_auth_logout');
        self::reg('/auth/me', WP_REST_Server::READABLE, 'route_auth_me');
        self::reg('/auth/register/company', WP_REST_Server::CREATABLE, 'route_auth_register_company', '__return_true');
        self::reg('/auth/register/member', WP_REST_Server::CREATABLE, 'route_auth_register_member', '__return_true');
        self::reg('/auth/join-requests', WP_REST_Server::READABLE, 'route_auth_join_requests_list');
        self::reg('/auth/join-requests/(?P<id>\\d+)/approve', WP_REST_Server::CREATABLE, 'route_auth_join_request_approve');
        self::reg('/auth/join-requests/(?P<id>\\d+)/reject', WP_REST_Server::CREATABLE, 'route_auth_join_request_reject');
        self::reg('/auth/impersonate', WP_REST_Server::CREATABLE, 'route_auth_impersonate');
        self::reg('/auth/restore-self', WP_REST_Server::CREATABLE, 'route_auth_restore_self', 'perm_member_any_status');

        // App
        self::reg('/app/modules', WP_REST_Server::READABLE, 'route_app_modules');
        self::reg('/app/summary', WP_REST_Server::READABLE, 'route_app_summary');

        // Companies
        self::reg('/companies/me', WP_REST_Server::READABLE, 'route_companies_me_get');
        self::reg('/companies/me', WP_REST_Server::EDITABLE, 'route_companies_me_update');
        self::reg('/companies/search', WP_REST_Server::READABLE, 'route_companies_search', '__return_true');
        self::reg('/companies/me/join-code/regenerate', WP_REST_Server::CREATABLE, 'route_companies_regenerate_join_code');

        // Members
        self::reg('/members', WP_REST_Server::READABLE, 'route_members_list');
        self::reg('/members', WP_REST_Server::CREATABLE, 'route_members_create');
        self::reg('/members/(?P<id>\\d+)', WP_REST_Server::EDITABLE, 'route_members_update');
        self::reg('/members/(?P<id>\\d+)/suspend', WP_REST_Server::CREATABLE, 'route_members_suspend');
        self::reg('/members/(?P<id>\\d+)/reactivate', WP_REST_Server::CREATABLE, 'route_members_reactivate');

        // Relations
        self::reg('/relations', WP_REST_Server::READABLE, 'route_relations_list');
        self::reg('/relations/invite', WP_REST_Server::CREATABLE, 'route_relations_invite');
        self::reg('/relations/(?P<id>\\d+)/accept', WP_REST_Server::CREATABLE, 'route_relations_accept');
        self::reg('/relations/(?P<id>\\d+)/reject', WP_REST_Server::CREATABLE, 'route_relations_reject');

        // Sources + catalog
        self::reg('/sources/google', WP_REST_Server::READABLE, 'route_sources_google_get');
        self::reg('/sources/google', WP_REST_Server::CREATABLE, 'route_sources_google_set');
        self::reg('/sources/google/sync', WP_REST_Server::CREATABLE, 'route_sources_google_sync');
        self::reg('/sources/google/cache', WP_REST_Server::READABLE, 'route_sources_google_cache');

        self::reg('/catalog/categories', WP_REST_Server::READABLE, 'route_catalog_categories_list');
        self::reg('/catalog/categories', WP_REST_Server::CREATABLE, 'route_catalog_categories_upsert');
        self::reg('/catalog/items', WP_REST_Server::READABLE, 'route_catalog_items_list');
        self::reg('/catalog/items', WP_REST_Server::CREATABLE, 'route_catalog_items_upsert');
        self::reg('/catalog/items/(?P<id>\\d+)/archive', WP_REST_Server::CREATABLE, 'route_catalog_item_archive');
        self::reg('/catalog/variants', WP_REST_Server::READABLE, 'route_catalog_variants_list');
        self::reg('/catalog/variants', WP_REST_Server::CREATABLE, 'route_catalog_variants_upsert');
        self::reg('/catalog/merged', WP_REST_Server::READABLE, 'route_catalog_merged');

        // Clients
        self::reg('/clients', WP_REST_Server::READABLE, 'route_clients_list');
        self::reg('/clients', WP_REST_Server::CREATABLE, 'route_clients_create');
        self::reg('/clients/(?P<id>\\d+)', WP_REST_Server::EDITABLE, 'route_clients_update');
        self::reg('/clients/(?P<id>\\d+)', WP_REST_Server::DELETABLE, 'route_clients_delete');

        // Offers
        self::reg('/offers', WP_REST_Server::READABLE, 'route_offers_list');
        self::reg('/offers', WP_REST_Server::CREATABLE, 'route_offers_create');
        self::reg('/offers/(?P<id>\\d+)', WP_REST_Server::READABLE, 'route_offers_get');
        self::reg('/offers/(?P<id>\\d+)', WP_REST_Server::EDITABLE, 'route_offers_update');
        self::reg('/offers/(?P<id>\\d+)', WP_REST_Server::DELETABLE, 'route_offers_delete');
        self::reg('/offers/(?P<id>\\d+)/status', WP_REST_Server::EDITABLE, 'route_offers_status');
        self::reg('/offers/(?P<id>\\d+)/lock', WP_REST_Server::EDITABLE, 'route_offers_lock');
        self::reg('/offers/(?P<id>\\d+)/link-thread', WP_REST_Server::CREATABLE, 'route_offers_link_thread');
        self::reg('/offers/(?P<id>\\d+)/events', WP_REST_Server::READABLE, 'route_offers_events');
        self::reg('/offers/(?P<id>\\d+)/pdf', WP_REST_Server::READABLE, 'route_offers_pdf_get');
        self::reg('/offers/(?P<id>\\d+)/pdf', WP_REST_Server::CREATABLE, 'route_offers_pdf_attach');

        // Chat
        self::reg('/chat/categories', WP_REST_Server::READABLE, 'route_chat_categories');
        self::reg('/chat/threads', WP_REST_Server::READABLE, 'route_chat_threads_list');
        self::reg('/chat/threads', WP_REST_Server::CREATABLE, 'route_chat_threads_create');
        self::reg('/chat/threads/(?P<id>\\d+)', WP_REST_Server::READABLE, 'route_chat_thread_get');
        self::reg('/chat/threads/(?P<id>\\d+)/messages', WP_REST_Server::CREATABLE, 'route_chat_message_add');
        self::reg('/chat/threads/(?P<id>\\d+)/attachments', WP_REST_Server::CREATABLE, 'route_chat_attachments_upload');
        self::reg('/chat/threads/(?P<id>\\d+)/close', WP_REST_Server::CREATABLE, 'route_chat_thread_close');
        self::reg('/chat/threads/(?P<id>\\d+)/reopen', WP_REST_Server::CREATABLE, 'route_chat_thread_reopen');
        self::reg('/chat/threads/(?P<id>\\d+)/ping', WP_REST_Server::CREATABLE, 'route_chat_thread_ping');
        self::reg('/chat/threads/(?P<id>\\d+)/mute', WP_REST_Server::CREATABLE, 'route_chat_thread_mute');
        self::reg('/chat/threads/(?P<id>\\d+)/read', WP_REST_Server::CREATABLE, 'route_chat_thread_read');

        // Notifications + maintenance
        self::reg('/notifications', WP_REST_Server::READABLE, 'route_notifications_list');
        self::reg('/notifications/read', WP_REST_Server::CREATABLE, 'route_notifications_mark_read');
        self::reg('/notifications/unread-count', WP_REST_Server::READABLE, 'route_notifications_unread_count');
        self::reg('/maintenance/storage', WP_REST_Server::READABLE, 'route_maintenance_storage');
        self::reg('/maintenance/consistency', WP_REST_Server::READABLE, 'route_maintenance_consistency');
        self::reg('/migration/run', WP_REST_Server::CREATABLE, 'route_migration_run');
    }

    public static function perm_member(): bool
    {
        return ZERP_Auth::require_member() !== null;
    }

    public static function perm_member_any_status(): bool
    {
        return ZERP_Auth::require_member(false) !== null;
    }

    private static function error(string $message, int $status = 400, string $code = 'zerp_error', array $extra = array()): WP_Error
    {
        return new WP_Error($code, $message, array_merge(array('status' => $status), $extra));
    }

    private static function body(WP_REST_Request $request): array
    {
        $json = $request->get_json_params();
        if (is_array($json)) {
            return $json;
        }

        $params = $request->get_body_params();
        return is_array($params) ? $params : array();
    }

    private static function request_bool(WP_REST_Request $request, string $key, bool $default = false): bool
    {
        if (!$request->has_param($key)) {
            return $default;
        }
        return rest_sanitize_boolean($request->get_param($key));
    }

    private static function current_company_id(array $member): int
    {
        return !empty($member['company_id']) ? (int) $member['company_id'] : 0;
    }

    private static function normalize_result(array $result, int $ok_status = 200)
    {
        if (!empty($result['ok'])) {
            return new WP_REST_Response($result, $ok_status);
        }

        $message = !empty($result['message']) ? (string) $result['message'] : 'Operacja nie powiodła się.';
        return self::error($message, 400, 'zerp_operation_failed', array('data' => $result));
    }

    private static function app_modules_for_member(array $member): array
    {
        $visibility = isset($member['module_visibility']) && is_array($member['module_visibility']) ? $member['module_visibility'] : array();
        $permissions = isset($member['permissions']) && is_array($member['permissions']) ? $member['permissions'] : array();

        $defs = array(
            'dashboard' => array('Dashboard', 'Przegląd modułów i podsumowanie zdarzeń', 'dashboard_access', null),
            'offers' => array('Panel Ofertowy', 'Tworzenie ofert, historia, PDF i klienci', 'offers_module_access', 'can_view_offers_module'),
            'communicator' => array('Komunikator', 'Rozmowy relacyjne i ofertowe', 'communicator_module_access', 'can_view_communicator'),
            'company_users' => array('Firma i Użytkownicy', 'Firma, członkowie, role i relacje', 'company_users_module_access', 'can_view_company_profile'),
            'catalog' => array('Biblioteka Produktów', 'Google Sheets i katalog lokalny', 'catalog_module_access', 'can_view_sources'),
            'notifications' => array('Powiadomienia', 'Centrum alertów, pingi i zdarzenia systemowe', 'notifications_center_access', 'can_view_notifications'),
        );

        $active = array();
        foreach ($defs as $id => $def) {
            $allowed = !empty($permissions[$def[2]]) && in_array($id, $visibility, true);
            if ($def[3] !== null && empty($permissions[$def[3]])) {
                $allowed = false;
            }
            if (!$allowed) {
                continue;
            }
            $active[] = array('id' => $id, 'label' => $def[0], 'description' => $def[1], 'status' => 'active');
        }

        $future = array();
        if (!empty($permissions['future_modules_access']) && in_array('future_modules', $visibility, true)) {
            foreach (array('Zamówienia','Magazyn','Faktury / Finanse','CRM / Leady','Statystyki / Raporty','Integracje','Zaawansowane ustawienia') as $idx => $label) {
                $future[] = array('id' => 'future_' . $idx, 'label' => $label, 'description' => 'Moduł jest w produkcji', 'status' => 'in_production');
            }
        }

        return array('active' => $active, 'future' => $future);
    }

    public static function route_ping(): WP_REST_Response
    {
        return new WP_REST_Response(array('ok' => true, 'version' => ZERP_VERSION, 'ts' => time()));
    }

    public static function route_auth_login(WP_REST_Request $request)
    {
        $body = self::body($request);
        $login = isset($body['login']) ? (string) $body['login'] : (string) ($body['email'] ?? '');
        $password = isset($body['password']) ? (string) $body['password'] : '';

        $result = ZERP_Auth::login($login, $password);
        if (empty($result['ok'])) {
            return self::normalize_result($result);
        }
        ZERP_Auth::set_auth_cookie((string) $result['token'], (string) $result['expires_at']);
        return new WP_REST_Response($result);
    }

    public static function route_auth_logout()
    {
        return self::normalize_result(ZERP_Auth::logout());
    }

    public static function route_auth_me()
    {
        $member = ZERP_Auth::require_member();
        if (!$member) {
            return self::error('Brak autoryzacji.', 401, 'zerp_unauthorized');
        }

        return new WP_REST_Response(array(
            'ok' => true,
            'member' => $member,
            'modules' => self::app_modules_for_member($member),
            'impersonation' => array(
                'is_active' => !empty($member['actor_member_id']) && (int) $member['actor_member_id'] !== (int) $member['id'],
                'actor_member_id' => !empty($member['actor_member_id']) ? (int) $member['actor_member_id'] : null,
            ),
        ));
    }

    public static function route_auth_register_company(WP_REST_Request $request)
    {
        $body = self::body($request);
        $result = ZERP_Auth::register_company_owner($body);
        if (empty($result['ok'])) {
            return self::normalize_result($result);
        }

        if (!empty($result['login']) && !empty($body['password'])) {
            $login_result = ZERP_Auth::login((string) $result['login'], (string) $body['password']);
            if (!empty($login_result['ok'])) {
                ZERP_Auth::set_auth_cookie((string) $login_result['token'], (string) $login_result['expires_at']);
                $result['session'] = $login_result;
            }
        }

        return new WP_REST_Response($result, 201);
    }

    public static function route_auth_register_member(WP_REST_Request $request)
    {
        return self::normalize_result(ZERP_Auth::register_member_join_request(self::body($request)), 201);
    }
    public static function route_auth_join_requests_list()
    {
        $member = ZERP_Auth::require_member();
        if (!$member) {
            return self::error('Brak autoryzacji.', 401, 'zerp_unauthorized');
        }
        if (!ZERP_Auth::can('can_view_company_members')) {
            return self::error('Brak uprawnień.', 403, 'zerp_forbidden');
        }

        $company_id = self::current_company_id($member);
        if ($company_id <= 0) {
            return new WP_REST_Response(array('ok' => true, 'items' => array()));
        }

        global $wpdb;
        $t = ZERP_DB::tables();
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT jr.*, m.login, m.first_name, m.last_name, m.email, m.phone
                   FROM {$t['join_requests']} jr
                   JOIN {$t['members']} m ON m.id = jr.pending_member_id
                  WHERE jr.company_id = %d
               ORDER BY jr.id DESC",
                $company_id
            ),
            ARRAY_A
        );

        if (!$rows) {
            $rows = array();
        }

        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['pending_member_id'] = (int) $row['pending_member_id'];
            $row['company_id'] = !empty($row['company_id']) ? (int) $row['company_id'] : null;
            $row['reviewed_by'] = !empty($row['reviewed_by']) ? (int) $row['reviewed_by'] : null;
            $row['payload'] = !empty($row['payload']) ? json_decode((string) $row['payload'], true) : null;
        }

        return new WP_REST_Response(array('ok' => true, 'items' => $rows));
    }

    public static function route_auth_join_request_approve(WP_REST_Request $request)
    {
        $member = ZERP_Auth::require_member();
        if (!$member) {
            return self::error('Brak autoryzacji.', 401, 'zerp_unauthorized');
        }
        if (!ZERP_Auth::can('can_edit_company_members')) {
            return self::error('Brak uprawnień.', 403, 'zerp_forbidden');
        }

        return self::normalize_result(ZERP_Auth::approve_join_request((int) $request['id'], (int) $member['id']));
    }

    public static function route_auth_join_request_reject(WP_REST_Request $request)
    {
        $member = ZERP_Auth::require_member();
        if (!$member) {
            return self::error('Brak autoryzacji.', 401, 'zerp_unauthorized');
        }
        if (!ZERP_Auth::can('can_edit_company_members')) {
            return self::error('Brak uprawnień.', 403, 'zerp_forbidden');
        }

        $reason = sanitize_textarea_field((string) (self::body($request)['reason'] ?? ''));
        return self::normalize_result(ZERP_Auth::reject_join_request((int) $request['id'], (int) $member['id'], $reason));
    }

    public static function route_auth_impersonate(WP_REST_Request $request)
    {
        $target_member_id = (int) (self::body($request)['target_member_id'] ?? 0);
        if ($target_member_id <= 0) {
            return self::error('Brak target_member_id.', 400, 'zerp_invalid_input');
        }

        $result = ZERP_Auth::impersonate($target_member_id);
        if (empty($result['ok'])) {
            return self::normalize_result($result);
        }

        ZERP_Auth::set_auth_cookie((string) $result['token'], (string) $result['expires_at']);
        return new WP_REST_Response($result);
    }

    public static function route_auth_restore_self()
    {
        $member = ZERP_Auth::require_member(false);
        if (!$member) {
            return self::error('Brak autoryzacji.', 401, 'zerp_unauthorized');
        }

        $actor_member_id = !empty($member['actor_member_id']) ? (int) $member['actor_member_id'] : 0;
        if ($actor_member_id <= 0) {
            return self::error('Brak aktywnej impersonacji.', 400, 'zerp_no_impersonation');
        }

        ZERP_Auth::logout();
        $issued = ZERP_Auth::issue_token($actor_member_id, null);
        ZERP_Auth::set_auth_cookie((string) $issued['token'], (string) $issued['expires_at']);

        return new WP_REST_Response(array(
            'ok' => true,
            'token' => $issued['token'],
            'expires_at' => $issued['expires_at'],
            'member' => ZERP_Auth::member_public($actor_member_id),
        ));
    }

    public static function route_app_modules()
    {
        $member = ZERP_Auth::require_member();
        if (!$member) {
            return self::error('Brak autoryzacji.', 401, 'zerp_unauthorized');
        }

        return new WP_REST_Response(array('ok' => true, 'modules' => self::app_modules_for_member($member)));
    }

    public static function route_app_summary()
    {
        $member = ZERP_Auth::require_member();
        if (!$member) {
            return self::error('Brak autoryzacji.', 401, 'zerp_unauthorized');
        }

        global $wpdb;
        $t = ZERP_DB::tables();
        $member_id = (int) $member['id'];
        $company_id = self::current_company_id($member);

        $unread_notifications = ZERP_Notifications::unread_count($member_id);

        $unread_threads = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT msg.thread_id)
                   FROM {$t['thread_messages']} msg
                   JOIN {$t['thread_participants']} tp ON tp.thread_id = msg.thread_id AND tp.member_id = %d
                  WHERE msg.id > COALESCE(tp.last_read_message_id, 0)",
                $member_id
            )
        );

        $pending_joins = 0;
        if ($company_id > 0 && ZERP_Auth::can('can_view_company_members')) {
            $pending_joins = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$t['join_requests']} WHERE company_id = %d AND status = 'pending'",
                $company_id
            ));
        }

        return new WP_REST_Response(array(
            'ok' => true,
            'summary' => array(
                'unread_notifications' => $unread_notifications,
                'unread_threads' => $unread_threads,
                'pending_join_requests' => $pending_joins,
            ),
        ));
    }

    public static function route_companies_me_get()
    {
        $member = ZERP_Auth::require_member();
        if (!$member) {
            return self::error('Brak autoryzacji.', 401, 'zerp_unauthorized');
        }

        $company = ZERP_Companies::get_company(self::current_company_id($member));
        if (!$company) {
            return self::error('Nie znaleziono firmy.', 404, 'zerp_not_found');
        }

        return new WP_REST_Response(array('ok' => true, 'company' => $company));
    }

    public static function route_companies_me_update(WP_REST_Request $request)
    {
        $member = ZERP_Auth::require_member();
        if (!$member) {
            return self::error('Brak autoryzacji.', 401, 'zerp_unauthorized');
        }

        return self::normalize_result(ZERP_Companies::update_company(self::current_company_id($member), self::body($request)));
    }

    public static function route_companies_search(WP_REST_Request $request)
    {
        $query = sanitize_text_field((string) $request->get_param('q'));
        $limit = (int) $request->get_param('limit');
        if ($limit <= 0) {
            $limit = 20;
        }

        return new WP_REST_Response(array('ok' => true, 'items' => ZERP_Companies::search_companies($query, $limit)));
    }

    public static function route_companies_regenerate_join_code()
    {
        $member = ZERP_Auth::require_member();
        if (!$member) {
            return self::error('Brak autoryzacji.', 401, 'zerp_unauthorized');
        }

        return self::normalize_result(ZERP_Companies::regenerate_join_code(self::current_company_id($member)));
    }

    public static function route_members_list()
    {
        $member = ZERP_Auth::require_member();
        if (!$member) {
            return self::error('Brak autoryzacji.', 401, 'zerp_unauthorized');
        }
        if (!ZERP_Auth::can('can_view_company_members')) {
            return self::error('Brak uprawnień.', 403, 'zerp_forbidden');
        }

        return new WP_REST_Response(array('ok' => true, 'items' => ZERP_Members::list_company_members(self::current_company_id($member))));
    }

    public static function route_members_create(WP_REST_Request $request)
    {
        $member = ZERP_Auth::require_member();
        if (!$member) {
            return self::error('Brak autoryzacji.', 401, 'zerp_unauthorized');
        }

        return self::normalize_result(ZERP_Members::create_member(self::current_company_id($member), self::body($request)), 201);
    }

    public static function route_members_update(WP_REST_Request $request)
    {
        $member = ZERP_Auth::require_member();
        if (!$member) {
            return self::error('Brak autoryzacji.', 401, 'zerp_unauthorized');
        }

        return self::normalize_result(ZERP_Members::update_member(self::current_company_id($member), (int) $request['id'], self::body($request)));
    }

    public static function route_members_suspend(WP_REST_Request $request)
    {
        $member = ZERP_Auth::require_member();
        if (!$member) {
            return self::error('Brak autoryzacji.', 401, 'zerp_unauthorized');
        }

        return self::normalize_result(ZERP_Members::suspend_member(self::current_company_id($member), (int) $request['id']));
    }

    public static function route_members_reactivate(WP_REST_Request $request)
    {
        $member = ZERP_Auth::require_member();
        if (!$member) {
            return self::error('Brak autoryzacji.', 401, 'zerp_unauthorized');
        }

        return self::normalize_result(ZERP_Members::reactivate_member(self::current_company_id($member), (int) $request['id']));
    }
    public static function route_relations_list()
    {
        $member = ZERP_Auth::require_member();
        if (!$member) {
            return self::error('Brak autoryzacji.', 401, 'zerp_unauthorized');
        }

        return new WP_REST_Response(array('ok' => true, 'items' => ZERP_Relations::list_for_company(self::current_company_id($member))));
    }

    public static function route_relations_invite(WP_REST_Request $request)
    {
        $member = ZERP_Auth::require_member();
        if (!$member) {
            return self::error('Brak autoryzacji.', 401, 'zerp_unauthorized');
        }

        $body = self::body($request);
        $target_company_id = (int) ($body['target_company_id'] ?? 0);
        if ($target_company_id <= 0) {
            return self::error('Brak target_company_id.', 400, 'zerp_invalid_input');
        }
        $discount = isset($body['max_discount_from_target']) ? (float) $body['max_discount_from_target'] : 0.0;

        return self::normalize_result(ZERP_Relations::invite(self::current_company_id($member), $target_company_id, $discount), 201);
    }

    public static function route_relations_accept(WP_REST_Request $request)
    {
        $member = ZERP_Auth::require_member();
        if (!$member) {
            return self::error('Brak autoryzacji.', 401, 'zerp_unauthorized');
        }

        $discount = isset(self::body($request)['max_discount_for_other_side']) ? (float) self::body($request)['max_discount_for_other_side'] : 0.0;
        return self::normalize_result(ZERP_Relations::accept((int) $request['id'], self::current_company_id($member), $discount));
    }

    public static function route_relations_reject(WP_REST_Request $request)
    {
        $member = ZERP_Auth::require_member();
        if (!$member) {
            return self::error('Brak autoryzacji.', 401, 'zerp_unauthorized');
        }

        $reason = sanitize_textarea_field((string) (self::body($request)['reason'] ?? ''));
        return self::normalize_result(ZERP_Relations::reject((int) $request['id'], self::current_company_id($member), $reason));
    }

    public static function route_sources_google_get()
    {
        $member = ZERP_Auth::require_member();
        if (!$member) {
            return self::error('Brak autoryzacji.', 401, 'zerp_unauthorized');
        }

        return new WP_REST_Response(array('ok' => true, 'source' => ZERP_Sources_Google::get_source(self::current_company_id($member))));
    }

    public static function route_sources_google_set(WP_REST_Request $request)
    {
        $member = ZERP_Auth::require_member();
        if (!$member) {
            return self::error('Brak autoryzacji.', 401, 'zerp_unauthorized');
        }

        return self::normalize_result(ZERP_Sources_Google::configure_source(self::current_company_id($member), self::body($request)));
    }

    public static function route_sources_google_sync()
    {
        $member = ZERP_Auth::require_member();
        if (!$member) {
            return self::error('Brak autoryzacji.', 401, 'zerp_unauthorized');
        }

        return self::normalize_result(ZERP_Sources_Google::force_sync(self::current_company_id($member)));
    }

    public static function route_sources_google_cache()
    {
        $member = ZERP_Auth::require_member();
        if (!$member) {
            return self::error('Brak autoryzacji.', 401, 'zerp_unauthorized');
        }

        return new WP_REST_Response(array('ok' => true, 'cache' => ZERP_Sources_Google::get_cache(self::current_company_id($member))));
    }

    public static function route_catalog_categories_list()
    {
        $member = ZERP_Auth::require_member();
        if (!$member) {
            return self::error('Brak autoryzacji.', 401, 'zerp_unauthorized');
        }

        return new WP_REST_Response(array('ok' => true, 'items' => ZERP_Catalog::list_categories(self::current_company_id($member))));
    }

    public static function route_catalog_categories_upsert(WP_REST_Request $request)
    {
        $member = ZERP_Auth::require_member();
        if (!$member) {
            return self::error('Brak autoryzacji.', 401, 'zerp_unauthorized');
        }

        return self::normalize_result(ZERP_Catalog::upsert_category(self::current_company_id($member), self::body($request)));
    }

    public static function route_catalog_items_list(WP_REST_Request $request)
    {
        $member = ZERP_Auth::require_member();
        if (!$member) {
            return self::error('Brak autoryzacji.', 401, 'zerp_unauthorized');
        }

        return new WP_REST_Response(array(
            'ok' => true,
            'items' => ZERP_Catalog::list_items(self::current_company_id($member), self::request_bool($request, 'only_active', false)),
        ));
    }

    public static function route_catalog_items_upsert(WP_REST_Request $request)
    {
        $member = ZERP_Auth::require_member();
        if (!$member) {
            return self::error('Brak autoryzacji.', 401, 'zerp_unauthorized');
        }

        return self::normalize_result(ZERP_Catalog::upsert_item(self::current_company_id($member), self::body($request)));
    }

    public static function route_catalog_item_archive(WP_REST_Request $request)
    {
        $member = ZERP_Auth::require_member();
        if (!$member) {
            return self::error('Brak autoryzacji.', 401, 'zerp_unauthorized');
        }

        return self::normalize_result(ZERP_Catalog::archive_item(self::current_company_id($member), (int) $request['id']));
    }

    public static function route_catalog_variants_list(WP_REST_Request $request)
    {
        $member = ZERP_Auth::require_member();
        if (!$member) {
            return self::error('Brak autoryzacji.', 401, 'zerp_unauthorized');
        }

        $item_id = $request->has_param('item_id') ? (int) $request->get_param('item_id') : null;
        $only_active = self::request_bool($request, 'only_active', false);

        return new WP_REST_Response(array(
            'ok' => true,
            'items' => ZERP_Catalog::list_variants(self::current_company_id($member), $item_id, $only_active),
        ));
    }

    public static function route_catalog_variants_upsert(WP_REST_Request $request)
    {
        $member = ZERP_Auth::require_member();
        if (!$member) {
            return self::error('Brak autoryzacji.', 401, 'zerp_unauthorized');
        }

        return self::normalize_result(ZERP_Catalog::upsert_variant(self::current_company_id($member), self::body($request)));
    }

    public static function route_catalog_merged(WP_REST_Request $request)
    {
        $member = ZERP_Auth::require_member();
        if (!$member) {
            return self::error('Brak autoryzacji.', 401, 'zerp_unauthorized');
        }

        $viewer_company_id = self::current_company_id($member);
        $context_company_id = (int) $request->get_param('context_company_id');
        if ($context_company_id <= 0) {
            $context_company_id = $viewer_company_id;
        }

        return new WP_REST_Response(array(
            'ok' => true,
            'context_company_id' => $context_company_id,
            'items' => ZERP_Catalog::merged_products_for_context($viewer_company_id, $context_company_id),
        ));
    }

    public static function route_clients_list()
    {
        $member = ZERP_Auth::require_member();
        if (!$member) {
            return self::error('Brak autoryzacji.', 401, 'zerp_unauthorized');
        }
        if (!ZERP_Auth::can('can_view_all_company_clients')) {
            return self::error('Brak uprawnień do listy klientów.', 403, 'zerp_forbidden');
        }

        global $wpdb;
        $t = ZERP_DB::tables();
        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$t['clients']} WHERE company_id = %d ORDER BY id DESC", self::current_company_id($member)),
            ARRAY_A
        );
        if (!$rows) {
            $rows = array();
        }

        return new WP_REST_Response(array('ok' => true, 'items' => $rows));
    }

    public static function route_clients_create(WP_REST_Request $request)
    {
        $member = ZERP_Auth::require_member();
        if (!$member) {
            return self::error('Brak autoryzacji.', 401, 'zerp_unauthorized');
        }
        if (!ZERP_Auth::can('can_manage_company_clients')) {
            return self::error('Brak uprawnień do klientów.', 403, 'zerp_forbidden');
        }

        $body = self::body($request);
        $full_name = sanitize_text_field((string) ($body['full_name'] ?? ''));
        $company_name = sanitize_text_field((string) ($body['company_name'] ?? ''));
        if ($full_name === '' && $company_name === '') {
            return self::error('Podaj imię i nazwisko lub nazwę firmy klienta.', 400, 'zerp_invalid_input');
        }

        global $wpdb;
        $t = ZERP_DB::tables();
        $now = current_time('mysql');
        $wpdb->insert(
            $t['clients'],
            array(
                'company_id' => self::current_company_id($member),
                'legacy_client_id' => null,
                'full_name' => $full_name,
                'company_name' => $company_name,
                'nip' => sanitize_text_field((string) ($body['nip'] ?? '')),
                'phone' => sanitize_text_field((string) ($body['phone'] ?? '')),
                'email' => sanitize_email((string) ($body['email'] ?? '')),
                'address' => sanitize_textarea_field((string) ($body['address'] ?? '')),
                'created_by' => (int) $member['id'],
                'updated_by' => (int) $member['id'],
                'created_at' => $now,
                'updated_at' => $now,
            )
        );

        return new WP_REST_Response(array('ok' => true, 'id' => (int) $wpdb->insert_id), 201);
    }
    public static function route_clients_update(WP_REST_Request $request)
    {
        $member = ZERP_Auth::require_member();
        if (!$member) {
            return self::error('Brak autoryzacji.', 401, 'zerp_unauthorized');
        }
        if (!ZERP_Auth::can('can_manage_company_clients')) {
            return self::error('Brak uprawnień do klientów.', 403, 'zerp_forbidden');
        }

        $client_id = (int) $request['id'];
        if ($client_id <= 0) {
            return self::error('Niepoprawne ID klienta.', 400, 'zerp_invalid_input');
        }

        global $wpdb;
        $t = ZERP_DB::tables();
        $company_id = self::current_company_id($member);

        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$t['clients']} WHERE id = %d AND company_id = %d", $client_id, $company_id));
        if (!$exists) {
            return self::error('Klient nie istnieje.', 404, 'zerp_not_found');
        }

        $body = self::body($request);
        $patch = array();
        foreach (array('full_name', 'company_name', 'nip', 'phone') as $field) {
            if (!array_key_exists($field, $body)) {
                continue;
            }
            $patch[$field] = sanitize_text_field((string) $body[$field]);
        }
        if (array_key_exists('email', $body)) {
            $patch['email'] = sanitize_email((string) $body['email']);
        }
        if (array_key_exists('address', $body)) {
            $patch['address'] = sanitize_textarea_field((string) $body['address']);
        }

        if (!$patch) {
            return new WP_REST_Response(array('ok' => true, 'updated' => false));
        }

        $patch['updated_by'] = (int) $member['id'];
        $patch['updated_at'] = current_time('mysql');
        $wpdb->update($t['clients'], $patch, array('id' => $client_id, 'company_id' => $company_id));

        return new WP_REST_Response(array('ok' => true, 'updated' => true));
    }

    public static function route_clients_delete(WP_REST_Request $request)
    {
        $member = ZERP_Auth::require_member();
        if (!$member) {
            return self::error('Brak autoryzacji.', 401, 'zerp_unauthorized');
        }
        if (!ZERP_Auth::can('can_manage_company_clients')) {
            return self::error('Brak uprawnień do klientów.', 403, 'zerp_forbidden');
        }

        $client_id = (int) $request['id'];
        if ($client_id <= 0) {
            return self::error('Niepoprawne ID klienta.', 400, 'zerp_invalid_input');
        }

        global $wpdb;
        $t = ZERP_DB::tables();
        $company_id = self::current_company_id($member);
        $offer_ref = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['offers']} WHERE company_id = %d AND client_id = %d", $company_id, $client_id));
        if ($offer_ref > 0) {
            return self::error('Nie można usunąć klienta powiązanego z ofertami.', 409, 'zerp_client_in_use');
        }

        $wpdb->delete($t['clients'], array('id' => $client_id, 'company_id' => $company_id), array('%d', '%d'));
        return new WP_REST_Response(array('ok' => true));
    }

    public static function route_offers_list(WP_REST_Request $request)
    {
        $member = ZERP_Auth::require_member();
        if (!$member) {
            return self::error('Brak autoryzacji.', 401, 'zerp_unauthorized');
        }

        $relation_id = $request->has_param('relation_id') ? (int) $request->get_param('relation_id') : null;
        if ($relation_id !== null && $relation_id <= 0) {
            $relation_id = null;
        }

        return new WP_REST_Response(array('ok' => true, 'items' => ZERP_Offers::list_offers(self::current_company_id($member), $relation_id)));
    }

    public static function route_offers_get(WP_REST_Request $request)
    {
        $member = ZERP_Auth::require_member();
        if (!$member) {
            return self::error('Brak autoryzacji.', 401, 'zerp_unauthorized');
        }

        $offer = ZERP_Offers::get_offer((int) $request['id'], self::current_company_id($member));
        if (!$offer) {
            return self::error('Oferta nie istnieje.', 404, 'zerp_not_found');
        }

        return new WP_REST_Response(array('ok' => true, 'offer' => $offer));
    }

    public static function route_offers_create(WP_REST_Request $request)
    {
        $member = ZERP_Auth::require_member();
        if (!$member) {
            return self::error('Brak autoryzacji.', 401, 'zerp_unauthorized');
        }

        return self::normalize_result(ZERP_Offers::create_offer(self::current_company_id($member), (int) $member['id'], self::body($request)), 201);
    }

    public static function route_offers_update(WP_REST_Request $request)
    {
        $member = ZERP_Auth::require_member();
        if (!$member) {
            return self::error('Brak autoryzacji.', 401, 'zerp_unauthorized');
        }

        return self::normalize_result(ZERP_Offers::update_offer((int) $request['id'], self::current_company_id($member), (int) $member['id'], self::body($request)));
    }

    public static function route_offers_status(WP_REST_Request $request)
    {
        $member = ZERP_Auth::require_member();
        if (!$member) {
            return self::error('Brak autoryzacji.', 401, 'zerp_unauthorized');
        }

        $status = sanitize_key((string) (self::body($request)['status'] ?? ''));
        return self::normalize_result(ZERP_Offers::update_status((int) $request['id'], self::current_company_id($member), (int) $member['id'], $status));
    }

    public static function route_offers_lock(WP_REST_Request $request)
    {
        $member = ZERP_Auth::require_member();
        if (!$member) {
            return self::error('Brak autoryzacji.', 401, 'zerp_unauthorized');
        }

        return self::normalize_result(ZERP_Offers::toggle_lock((int) $request['id'], self::current_company_id($member), (int) $member['id'], self::request_bool($request, 'locked', true)));
    }

    public static function route_offers_delete(WP_REST_Request $request)
    {
        $member = ZERP_Auth::require_member();
        if (!$member) {
            return self::error('Brak autoryzacji.', 401, 'zerp_unauthorized');
        }

        return self::normalize_result(ZERP_Offers::delete_offer((int) $request['id'], self::current_company_id($member), (int) $member['id']));
    }

    public static function route_offers_link_thread(WP_REST_Request $request)
    {
        $member = ZERP_Auth::require_member();
        if (!$member) {
            return self::error('Brak autoryzacji.', 401, 'zerp_unauthorized');
        }

        $thread_id = (int) (self::body($request)['thread_id'] ?? 0);
        if ($thread_id <= 0) {
            return self::error('Brak thread_id.', 400, 'zerp_invalid_input');
        }

        return self::normalize_result(ZERP_Offers::link_offer_to_thread((int) $request['id'], $thread_id, self::current_company_id($member), (int) $member['id']));
    }

    public static function route_offers_events(WP_REST_Request $request)
    {
        $member = ZERP_Auth::require_member();
        if (!$member) {
            return self::error('Brak autoryzacji.', 401, 'zerp_unauthorized');
        }

        $offer_id = (int) $request['id'];
        $offer = ZERP_Offers::get_offer($offer_id, self::current_company_id($member));
        if (!$offer) {
            return self::error('Oferta nie istnieje.', 404, 'zerp_not_found');
        }

        global $wpdb;
        $t = ZERP_DB::tables();
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t['offer_events']} WHERE offer_id = %d ORDER BY id DESC", $offer_id), ARRAY_A);
        if (!$rows) {
            $rows = array();
        }

        return new WP_REST_Response(array('ok' => true, 'items' => $rows));
    }

    public static function route_offers_pdf_get(WP_REST_Request $request)
    {
        $member = ZERP_Auth::require_member();
        if (!$member) {
            return self::error('Brak autoryzacji.', 401, 'zerp_unauthorized');
        }

        return self::normalize_result(ZERP_Offer_PDF::get_pdf((int) $request['id'], self::current_company_id($member)));
    }

    public static function route_offers_pdf_attach(WP_REST_Request $request)
    {
        $member = ZERP_Auth::require_member();
        if (!$member) {
            return self::error('Brak autoryzacji.', 401, 'zerp_unauthorized');
        }
        if (!ZERP_Auth::can('can_export_offer_pdf')) {
            return self::error('Brak uprawnień do PDF.', 403, 'zerp_forbidden');
        }

        $body = self::body($request);
        return self::normalize_result(ZERP_Offer_PDF::attach_pdf((int) $request['id'], self::current_company_id($member), (string) ($body['relative_path'] ?? ''), isset($body['size_bytes']) ? (int) $body['size_bytes'] : null));
    }
    public static function route_chat_categories()
    {
        $member = ZERP_Auth::require_member();
        if (!$member) {
            return self::error('Brak autoryzacji.', 401, 'zerp_unauthorized');
        }

        return new WP_REST_Response(array('ok' => true, 'items' => ZERP_Chat::list_categories()));
    }

    public static function route_chat_threads_list(WP_REST_Request $request)
    {
        $member = ZERP_Auth::require_member();
        if (!$member) {
            return self::error('Brak autoryzacji.', 401, 'zerp_unauthorized');
        }

        $filters = array();
        if ($request->has_param('is_closed')) {
            $filters['is_closed'] = self::request_bool($request, 'is_closed', false);
        }
        if ($request->has_param('type')) {
            $filters['type'] = sanitize_key((string) $request->get_param('type'));
        }
        if ($request->has_param('relation_id')) {
            $filters['relation_id'] = (int) $request->get_param('relation_id');
        }
        if ($request->has_param('category_id')) {
            $filters['category_id'] = (int) $request->get_param('category_id');
        }

        return new WP_REST_Response(array('ok' => true, 'items' => ZERP_Chat::list_threads_for_member((int) $member['id'], $filters)));
    }

    public static function route_chat_threads_create(WP_REST_Request $request)
    {
        $member = ZERP_Auth::require_member();
        if (!$member) {
            return self::error('Brak autoryzacji.', 401, 'zerp_unauthorized');
        }

        $body = self::body($request);
        $relation_id = (int) ($body['relation_id'] ?? 0);
        if ($relation_id <= 0) {
            return self::error('Brak relation_id.', 400, 'zerp_invalid_input');
        }

        $linked_offer_id = isset($body['linked_offer_id']) && (int) $body['linked_offer_id'] > 0 ? (int) $body['linked_offer_id'] : null;
        $pings = !empty($body['ping_member_ids']) && is_array($body['ping_member_ids']) ? array_values(array_map('intval', $body['ping_member_ids'])) : array();

        return self::normalize_result(
            ZERP_Chat::create_thread(
                (int) $member['id'],
                $relation_id,
                sanitize_key((string) ($body['type'] ?? 'general')),
                isset($body['category_id']) ? (int) $body['category_id'] : 0,
                sanitize_text_field((string) ($body['title'] ?? '')),
                wp_kses_post((string) ($body['first_message'] ?? '')),
                $pings,
                $linked_offer_id
            ),
            201
        );
    }

    public static function route_chat_thread_get(WP_REST_Request $request)
    {
        $member = ZERP_Auth::require_member();
        if (!$member) {
            return self::error('Brak autoryzacji.', 401, 'zerp_unauthorized');
        }

        $thread = ZERP_Chat::get_thread((int) $request['id'], self::current_company_id($member));
        if (!$thread) {
            return self::error('Rozmowa nie istnieje.', 404, 'zerp_not_found');
        }

        ZERP_Chat::mark_thread_read((int) $request['id'], (int) $member['id']);
        return new WP_REST_Response(array('ok' => true, 'thread' => $thread));
    }

    public static function route_chat_message_add(WP_REST_Request $request)
    {
        $member = ZERP_Auth::require_member();
        if (!$member) {
            return self::error('Brak autoryzacji.', 401, 'zerp_unauthorized');
        }

        $body = self::body($request);
        $attachment_ids = !empty($body['attachment_ids']) && is_array($body['attachment_ids']) ? array_values(array_filter(array_map('intval', $body['attachment_ids']), static fn($id) => $id > 0)) : array();

        return self::normalize_result(
            ZERP_Chat::add_message((int) $request['id'], (int) $member['id'], wp_kses_post((string) ($body['body'] ?? '')), 'message', array(), $attachment_ids, true),
            201
        );
    }

    public static function route_chat_attachments_upload(WP_REST_Request $request)
    {
        $member = ZERP_Auth::require_member();
        if (!$member) {
            return self::error('Brak autoryzacji.', 401, 'zerp_unauthorized');
        }

        $thread_id = (int) $request['id'];
        $files = array();

        if (!empty($_FILES['file']) && is_array($_FILES['file'])) {
            $files[] = $_FILES['file'];
        }

        if (!empty($_FILES['files']) && is_array($_FILES['files']) && !empty($_FILES['files']['name']) && is_array($_FILES['files']['name'])) {
            $count = count($_FILES['files']['name']);
            for ($i = 0; $i < $count; $i++) {
                $files[] = array(
                    'name' => $_FILES['files']['name'][$i] ?? '',
                    'type' => $_FILES['files']['type'][$i] ?? '',
                    'tmp_name' => $_FILES['files']['tmp_name'][$i] ?? '',
                    'error' => $_FILES['files']['error'][$i] ?? 0,
                    'size' => $_FILES['files']['size'][$i] ?? 0,
                );
            }
        }

        if (!$files) {
            return self::error('Brak plików do uploadu.', 400, 'zerp_invalid_input');
        }
        if (count($files) > 5) {
            return self::error('Maksymalnie 5 plików w wiadomości.', 400, 'zerp_attachment_limit');
        }

        $total_size = 0;
        foreach ($files as $file) {
            $total_size += isset($file['size']) ? (int) $file['size'] : 0;
        }
        if ($total_size > 10 * 1024 * 1024) {
            return self::error('Łączny limit załączników to 10MB.', 400, 'zerp_attachment_size_limit');
        }

        $items = array();
        $attachment_ids = array();
        foreach ($files as $file) {
            $result = ZERP_Chat::store_attachment($thread_id, (int) $member['id'], $file);
            if (empty($result['ok'])) {
                return self::normalize_result($result);
            }
            $items[] = $result;
            $attachment_ids[] = (int) $result['attachment_id'];
        }

        return new WP_REST_Response(array('ok' => true, 'items' => $items, 'attachment_ids' => $attachment_ids), 201);
    }

    public static function route_chat_thread_close(WP_REST_Request $request)
    {
        $member = ZERP_Auth::require_member();
        if (!$member) {
            return self::error('Brak autoryzacji.', 401, 'zerp_unauthorized');
        }

        return self::normalize_result(ZERP_Chat::close_thread((int) $request['id'], (int) $member['id'], sanitize_textarea_field((string) (self::body($request)['reason'] ?? ''))));
    }

    public static function route_chat_thread_reopen(WP_REST_Request $request)
    {
        $member = ZERP_Auth::require_member();
        if (!$member) {
            return self::error('Brak autoryzacji.', 401, 'zerp_unauthorized');
        }

        return self::normalize_result(ZERP_Chat::reopen_thread((int) $request['id'], (int) $member['id'], sanitize_textarea_field((string) (self::body($request)['reason'] ?? ''))));
    }

    public static function route_chat_thread_ping(WP_REST_Request $request)
    {
        $member = ZERP_Auth::require_member();
        if (!$member) {
            return self::error('Brak autoryzacji.', 401, 'zerp_unauthorized');
        }

        $targets = !empty(self::body($request)['target_member_ids']) && is_array(self::body($request)['target_member_ids']) ? self::body($request)['target_member_ids'] : array();
        return self::normalize_result(ZERP_Chat::ping_members((int) $request['id'], (int) $member['id'], $targets));
    }

    public static function route_chat_thread_mute(WP_REST_Request $request)
    {
        $member = ZERP_Auth::require_member();
        if (!$member) {
            return self::error('Brak autoryzacji.', 401, 'zerp_unauthorized');
        }

        return self::normalize_result(ZERP_Chat::set_mute((int) $request['id'], (int) $member['id'], self::request_bool($request, 'mute', true)));
    }

    public static function route_chat_thread_read(WP_REST_Request $request)
    {
        $member = ZERP_Auth::require_member();
        if (!$member) {
            return self::error('Brak autoryzacji.', 401, 'zerp_unauthorized');
        }

        ZERP_Chat::mark_thread_read((int) $request['id'], (int) $member['id']);
        return new WP_REST_Response(array('ok' => true));
    }

    public static function route_notifications_list(WP_REST_Request $request)
    {
        $member = ZERP_Auth::require_member();
        if (!$member) {
            return self::error('Brak autoryzacji.', 401, 'zerp_unauthorized');
        }
        if (!ZERP_Auth::can('can_view_notifications')) {
            return self::error('Brak uprawnień do powiadomień.', 403, 'zerp_forbidden');
        }

        $section = sanitize_key((string) $request->get_param('section'));
        if ($section === '') {
            $section = null;
        }
        $limit = (int) $request->get_param('limit');
        if ($limit <= 0) {
            $limit = 100;
        }

        return new WP_REST_Response(array('ok' => true, 'items' => ZERP_Notifications::list_for_member((int) $member['id'], $section, $limit)));
    }

    public static function route_notifications_mark_read(WP_REST_Request $request)
    {
        $member = ZERP_Auth::require_member();
        if (!$member) {
            return self::error('Brak autoryzacji.', 401, 'zerp_unauthorized');
        }
        if (!ZERP_Auth::can('can_view_notifications')) {
            return self::error('Brak uprawnień do powiadomień.', 403, 'zerp_forbidden');
        }

        $ids = !empty(self::body($request)['ids']) && is_array(self::body($request)['ids']) ? self::body($request)['ids'] : array();
        return new WP_REST_Response(array('ok' => true, 'updated' => ZERP_Notifications::mark_read((int) $member['id'], $ids)));
    }

    public static function route_notifications_unread_count()
    {
        $member = ZERP_Auth::require_member();
        if (!$member) {
            return self::error('Brak autoryzacji.', 401, 'zerp_unauthorized');
        }

        return new WP_REST_Response(array('ok' => true, 'count' => ZERP_Notifications::unread_count((int) $member['id'])));
    }

    public static function route_maintenance_storage()
    {
        $member = ZERP_Auth::require_member();
        if (!$member) {
            return self::error('Brak autoryzacji.', 401, 'zerp_unauthorized');
        }
        if (!$member['is_owner'] && !ZERP_Auth::can('can_view_sync_logs')) {
            return self::error('Brak uprawnień.', 403, 'zerp_forbidden');
        }

        return new WP_REST_Response(array('ok' => true, 'stats' => ZERP_Maintenance::storage_diagnostics()));
    }

    public static function route_maintenance_consistency()
    {
        $member = ZERP_Auth::require_member();
        if (!$member) {
            return self::error('Brak autoryzacji.', 401, 'zerp_unauthorized');
        }
        if (!$member['is_owner'] && !ZERP_Auth::can('can_view_sync_logs')) {
            return self::error('Brak uprawnień.', 403, 'zerp_forbidden');
        }

        return new WP_REST_Response(array('ok' => true, 'result' => ZERP_Maintenance::diagnose_offer_thread_consistency()));
    }

    public static function route_migration_run()
    {
        $member = ZERP_Auth::require_member();
        if (!$member) {
            return self::error('Brak autoryzacji.', 401, 'zerp_unauthorized');
        }
        if (!$member['is_owner']) {
            return self::error('Tylko owner może uruchomić migrację.', 403, 'zerp_forbidden');
        }

        return new WP_REST_Response(array('ok' => true, 'result' => ZERP_Migration::run_full_migration()));
    }
}

