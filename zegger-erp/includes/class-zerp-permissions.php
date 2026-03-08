<?php
if (!defined('ABSPATH')) {
    exit;
}

final class ZERP_Permissions
{
    public static function all_keys(): array
    {
        return array(
            'dashboard_access',
            'offers_module_access',
            'communicator_module_access',
            'company_users_module_access',
            'catalog_module_access',
            'notifications_center_access',
            'future_modules_access',

            'can_impersonate_accounts',
            'can_view_notifications',
            'can_manage_notification_items',

            'can_view_company_profile',
            'can_edit_company_profile',
            'can_manage_company_branding',
            'can_manage_join_code',
            'can_view_company_members',
            'can_create_company_members',
            'can_edit_company_members',
            'can_suspend_company_members',
            'can_reset_member_passwords',
            'can_assign_module_visibility',
            'can_assign_member_permissions',
            'can_manage_company_relations',

            'can_view_sources',
            'can_manage_google_source',
            'can_force_google_sync',
            'can_view_sync_logs',
            'can_manage_internal_catalog',
            'can_manage_catalog_categories',
            'can_manage_catalog_variants',
            'can_archive_catalog_items',

            'can_view_offers_module',
            'can_create_offers',
            'can_edit_offers',
            'can_delete_own_offers',
            'can_delete_company_offers',
            'can_export_offer_pdf',
            'can_change_offer_status',
            'can_bind_offer_to_chat',
            'can_send_offer_to_relation',
            'can_manage_offer_lock',
            'can_view_all_company_clients',
            'can_manage_company_clients',

            'can_view_communicator',
            'can_create_threads',
            'can_send_messages',
            'can_upload_attachments',
            'can_ping_users',
            'can_close_threads',
            'can_reopen_threads',
            'can_change_linked_offer_status',
            'can_manage_thread_participants',
            'can_view_all_relation_threads',
        );
    }

    public static function owner_defaults(): array
    {
        $out = array();
        foreach (self::all_keys() as $key) {
            $out[$key] = 1;
        }
        return $out;
    }

    public static function manager_defaults(): array
    {
        return array(
            'dashboard_access' => 1,
            'offers_module_access' => 1,
            'communicator_module_access' => 1,
            'company_users_module_access' => 1,
            'catalog_module_access' => 1,
            'notifications_center_access' => 1,
            'future_modules_access' => 1,
            'can_view_notifications' => 1,
            'can_view_company_profile' => 1,
            'can_edit_company_profile' => 1,
            'can_view_company_members' => 1,
            'can_create_company_members' => 1,
            'can_edit_company_members' => 1,
            'can_suspend_company_members' => 1,
            'can_reset_member_passwords' => 1,
            'can_view_sources' => 1,
            'can_manage_google_source' => 1,
            'can_force_google_sync' => 1,
            'can_view_sync_logs' => 1,
            'can_manage_internal_catalog' => 1,
            'can_manage_catalog_categories' => 1,
            'can_manage_catalog_variants' => 1,
            'can_archive_catalog_items' => 1,
            'can_view_offers_module' => 1,
            'can_create_offers' => 1,
            'can_edit_offers' => 1,
            'can_delete_own_offers' => 1,
            'can_export_offer_pdf' => 1,
            'can_change_offer_status' => 1,
            'can_bind_offer_to_chat' => 1,
            'can_send_offer_to_relation' => 1,
            'can_manage_offer_lock' => 1,
            'can_view_all_company_clients' => 1,
            'can_manage_company_clients' => 1,
            'can_view_communicator' => 1,
            'can_create_threads' => 1,
            'can_send_messages' => 1,
            'can_upload_attachments' => 1,
            'can_ping_users' => 1,
            'can_close_threads' => 1,
            'can_reopen_threads' => 1,
            'can_change_linked_offer_status' => 1,
            'can_manage_thread_participants' => 1,
            'can_view_all_relation_threads' => 1,
        );
    }

    public static function user_defaults(): array
    {
        return array(
            'dashboard_access' => 1,
            'offers_module_access' => 1,
            'communicator_module_access' => 1,
            'catalog_module_access' => 1,
            'notifications_center_access' => 1,
            'can_view_notifications' => 1,
            'can_view_sources' => 1,
            'can_view_offers_module' => 1,
            'can_create_offers' => 1,
            'can_edit_offers' => 1,
            'can_delete_own_offers' => 1,
            'can_export_offer_pdf' => 1,
            'can_change_offer_status' => 1,
            'can_bind_offer_to_chat' => 1,
            'can_send_offer_to_relation' => 1,
            'can_view_communicator' => 1,
            'can_create_threads' => 1,
            'can_send_messages' => 1,
            'can_upload_attachments' => 1,
            'can_ping_users' => 1,
            'can_reopen_threads' => 1,
        );
    }

    public static function defaults_for_role(string $role): array
    {
        $role = sanitize_key($role);
        if ($role === 'owner') {
            return self::owner_defaults();
        }
        if ($role === 'manager') {
            return self::manager_defaults();
        }
        return self::user_defaults();
    }

    public static function normalize(array $permissions): array
    {
        $valid = array();
        foreach (self::all_keys() as $key) {
            $valid[$key] = !empty($permissions[$key]) ? 1 : 0;
        }
        return $valid;
    }

    public static function visible_modules(array $permissions): array
    {
        $keys = array(
            'dashboard' => 'dashboard_access',
            'offers' => 'offers_module_access',
            'communicator' => 'communicator_module_access',
            'company_users' => 'company_users_module_access',
            'catalog' => 'catalog_module_access',
            'notifications' => 'notifications_center_access',
            'future_modules' => 'future_modules_access',
        );

        $visible = array();
        foreach ($keys as $module => $perm_key) {
            if (!empty($permissions[$perm_key])) {
                $visible[] = $module;
            }
        }

        return $visible;
    }
}

