<?php
if (!defined('ABSPATH')) {
    exit;
}

final class ZERP_Chat
{
    public static function init(): void
    {
        // routes via REST
    }

    public static function list_categories(): array
    {
        global $wpdb;
        $t = ZERP_DB::tables();

        $rows = $wpdb->get_results("SELECT * FROM {$t['thread_categories']} WHERE is_active = 1 ORDER BY sort_order ASC, id ASC", ARRAY_A);
        return $rows ?: array();
    }

    public static function list_threads_for_member(int $member_id, array $filters = array()): array
    {
        global $wpdb;
        $t = ZERP_DB::tables();

        $member = $wpdb->get_row($wpdb->prepare("SELECT id, company_id FROM {$t['members']} WHERE id = %d LIMIT 1", $member_id), ARRAY_A);
        if (!$member) {
            return array();
        }

        $company_id = (int) $member['company_id'];

        $where = "WHERE r.status = 'active' AND (r.company_a_id = %d OR r.company_b_id = %d)";
        $params = array($company_id, $company_id);

        if (!empty($filters['is_closed'])) {
            $where .= ' AND th.is_closed = 1';
        } elseif (isset($filters['is_closed']) && !$filters['is_closed']) {
            $where .= ' AND th.is_closed = 0';
        }

        if (!empty($filters['type'])) {
            $where .= ' AND th.type = %s';
            $params[] = sanitize_key((string) $filters['type']);
        }

        if (!empty($filters['relation_id'])) {
            $where .= ' AND th.relation_id = %d';
            $params[] = (int) $filters['relation_id'];
        }

        if (!empty($filters['category_id'])) {
            $where .= ' AND th.category_id = %d';
            $params[] = (int) $filters['category_id'];
        }

        $sql = "SELECT th.*, r.company_a_id, r.company_b_id, c1.name AS company_a_name, c2.name AS company_b_name
                  FROM {$t['threads']} th
                  JOIN {$t['relations']} r ON r.id = th.relation_id
                  JOIN {$t['companies']} c1 ON c1.id = r.company_a_id
                  JOIN {$t['companies']} c2 ON c2.id = r.company_b_id
                  {$where}
              ORDER BY th.last_message_at DESC, th.id DESC";

        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);
        if (!$rows) {
            return array();
        }

        foreach ($rows as &$row) {
            $row = self::normalize_thread_row($row, $company_id);
            $row['unread_count'] = self::thread_unread_count((int) $row['id'], $member_id);
        }

        return $rows;
    }

    public static function create_thread(int $member_id, int $relation_id, string $type, int $category_id, string $title, string $first_message = '', array $ping_member_ids = array(), ?int $linked_offer_id = null): array
    {
        if (!ZERP_Auth::can('can_create_threads')) {
            return array('ok' => false, 'message' => 'Brak uprawnień do tworzenia rozmów.');
        }

        global $wpdb;
        $t = ZERP_DB::tables();

        $member = $wpdb->get_row($wpdb->prepare("SELECT id, company_id FROM {$t['members']} WHERE id = %d LIMIT 1", $member_id), ARRAY_A);
        if (!$member) {
            return array('ok' => false, 'message' => 'Nie znaleziono konta.');
        }

        $relation = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['relations']} WHERE id = %d LIMIT 1", $relation_id), ARRAY_A);
        if (!$relation || (string) $relation['status'] !== 'active') {
            return array('ok' => false, 'message' => 'Relacja A↔B nie jest aktywna.');
        }

        $member_company_id = (int) $member['company_id'];
        $companies = array((int) $relation['company_a_id'], (int) $relation['company_b_id']);
        if (!in_array($member_company_id, $companies, true)) {
            return array('ok' => false, 'message' => 'Brak dostępu do tej relacji.');
        }

        $type = sanitize_key($type);
        if (!in_array($type, array('general', 'offer'), true)) {
            $type = 'general';
        }

        if ($linked_offer_id !== null) {
            $offer = $wpdb->get_row($wpdb->prepare("SELECT id, relation_id FROM {$t['offers']} WHERE id = %d LIMIT 1", $linked_offer_id), ARRAY_A);
            if (!$offer) {
                return array('ok' => false, 'message' => 'Oferta do przypięcia nie istnieje.');
            }
            if ((int) $offer['relation_id'] !== $relation_id) {
                return array('ok' => false, 'message' => 'Oferta i rozmowa muszą należeć do tej samej relacji A↔B.');
            }

            $exists_link = $wpdb->get_var($wpdb->prepare("SELECT thread_id FROM {$t['offer_chat_link']} WHERE offer_id = %d LIMIT 1", $linked_offer_id));
            if ($exists_link) {
                return array('ok' => false, 'message' => 'Ta oferta jest już powiązana z inną rozmową.');
            }
        }

        $now = current_time('mysql');
        $wpdb->insert(
            $t['threads'],
            array(
                'relation_id' => $relation_id,
                'type' => $type,
                'category_id' => $category_id > 0 ? $category_id : null,
                'title' => sanitize_text_field($title),
                'linked_offer_id' => $linked_offer_id,
                'lead_member_id' => null,
                'is_closed' => 0,
                'created_by' => $member_id,
                'last_message_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ),
            array('%d', '%s', '%d', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s')
        );

        $thread_id = (int) $wpdb->insert_id;
        self::add_participant($thread_id, $member_id);
        self::log_system_event($thread_id, 'thread_created', array('type' => $type), $member_id);

        if ($linked_offer_id !== null) {
            $wpdb->replace(
                $t['offer_chat_link'],
                array(
                    'offer_id' => $linked_offer_id,
                    'thread_id' => $thread_id,
                    'relation_id' => $relation_id,
                    'created_by' => $member_id,
                    'created_at' => $now,
                ),
                array('%d', '%d', '%d', '%d', '%s')
            );

            $wpdb->update(
                $t['offers'],
                array('linked_thread_id' => $thread_id, 'updated_by' => $member_id, 'updated_at' => $now),
                array('id' => $linked_offer_id),
                array('%d', '%d', '%s'),
                array('%d')
            );

            self::log_system_event($thread_id, 'offer_attached', array('offer_id' => $linked_offer_id), $member_id);
        }

        if ($first_message !== '') {
            self::add_message($thread_id, $member_id, $first_message, 'message', array(), array(), false);
        }

        $ping_member_ids = array_values(array_filter(array_map('intval', $ping_member_ids), static fn($id) => $id > 0));
        if ($ping_member_ids && ZERP_Auth::can('can_ping_users')) {
            self::ping_members($thread_id, $member_id, $ping_member_ids);
        }

        return array('ok' => true, 'thread' => self::get_thread($thread_id, $member_company_id));
    }

    public static function get_thread(int $thread_id, int $viewer_company_id): ?array
    {
        global $wpdb;
        $t = ZERP_DB::tables();

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT th.*, r.company_a_id, r.company_b_id, c1.name AS company_a_name, c2.name AS company_b_name
                   FROM {$t['threads']} th
                   JOIN {$t['relations']} r ON r.id = th.relation_id
                   JOIN {$t['companies']} c1 ON c1.id = r.company_a_id
                   JOIN {$t['companies']} c2 ON c2.id = r.company_b_id
                  WHERE th.id = %d LIMIT 1",
                $thread_id
            ),
            ARRAY_A
        );

        if (!$row) {
            return null;
        }

        $companies = array((int) $row['company_a_id'], (int) $row['company_b_id']);
        if (!in_array($viewer_company_id, $companies, true)) {
            return null;
        }

        $thread = self::normalize_thread_row($row, $viewer_company_id);
        $thread['participants'] = self::participants($thread_id);
        $thread['messages'] = self::messages($thread_id);

        return $thread;
    }

    public static function add_message(int $thread_id, int $sender_member_id, string $body, string $message_type = 'message', array $meta = array(), array $attachment_ids = array(), bool $auto_notify = true): array
    {
        if (!ZERP_Auth::can('can_send_messages') && $message_type === 'message') {
            return array('ok' => false, 'message' => 'Brak uprawnień do wysyłania wiadomości.');
        }

        $body = trim($body);
        if ($message_type === 'message' && $body === '' && !$attachment_ids) {
            return array('ok' => false, 'message' => 'Treść wiadomości jest pusta.');
        }

        global $wpdb;
        $t = ZERP_DB::tables();

        $thread = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['threads']} WHERE id = %d LIMIT 1", $thread_id), ARRAY_A);
        if (!$thread) {
            return array('ok' => false, 'message' => 'Rozmowa nie istnieje.');
        }

        if (!empty($thread['is_closed']) && $message_type === 'message') {
            return array('ok' => false, 'message' => 'Rozmowa jest zamknięta.');
        }

        if (!ZERP_Relations::assert_member_relation_access($sender_member_id, (int) $thread['relation_id'])) {
            return array('ok' => false, 'message' => 'Brak dostępu do relacji rozmowy.');
        }

        $now = current_time('mysql');

        $wpdb->insert(
            $t['thread_messages'],
            array(
                'thread_id' => $thread_id,
                'sender_member_id' => $sender_member_id,
                'message_type' => sanitize_key($message_type),
                'body' => wp_kses_post($body),
                'meta_json' => $meta ? wp_json_encode($meta) : null,
                'created_at' => $now,
            ),
            array('%d', '%d', '%s', '%s', '%s', '%s')
        );

        $message_id = (int) $wpdb->insert_id;
        self::add_participant($thread_id, $sender_member_id);

        // pierwszy realny responder staje się prowadzącym
        if ((int) ($thread['lead_member_id'] ?? 0) <= 0 && $message_type === 'message') {
            $creator_id = (int) ($thread['created_by'] ?? 0);
            if ($sender_member_id !== $creator_id) {
                $wpdb->update($t['threads'], array('lead_member_id' => $sender_member_id), array('id' => $thread_id), array('%d'), array('%d'));
                self::log_system_event($thread_id, 'handler_assigned', array('lead_member_id' => $sender_member_id), $sender_member_id);
            }
        }

        $wpdb->update(
            $t['threads'],
            array('last_message_at' => $now, 'updated_at' => $now),
            array('id' => $thread_id),
            array('%s', '%s'),
            array('%d')
        );

        if ($attachment_ids) {
            foreach ($attachment_ids as $attachment_id) {
                $wpdb->update(
                    $t['thread_attachments'],
                    array('message_id' => $message_id),
                    array('id' => (int) $attachment_id, 'thread_id' => $thread_id),
                    array('%d'),
                    array('%d', '%d')
                );
            }
        }

        if ($auto_notify && $message_type === 'message') {
            self::notify_new_message($thread_id, $sender_member_id, $message_id);
        }

        return array('ok' => true, 'message_id' => $message_id);
    }

    public static function store_attachment(int $thread_id, int $uploader_member_id, array $file): array
    {
        if (!ZERP_Auth::can('can_upload_attachments')) {
            return array('ok' => false, 'message' => 'Brak uprawnień do załączników.');
        }

        $allowed_mimes = array('application/pdf', 'image/jpeg', 'image/png');
        $allowed_ext = array('pdf', 'jpg', 'jpeg', 'png');

        if (empty($file['name']) || empty($file['tmp_name'])) {
            return array('ok' => false, 'message' => 'Brak pliku.');
        }

        $size = isset($file['size']) ? (int) $file['size'] : 0;
        if ($size <= 0) {
            return array('ok' => false, 'message' => 'Pusty plik.');
        }

        if ($size > 10 * 1024 * 1024) {
            return array('ok' => false, 'message' => 'Limit pliku to 10MB.');
        }

        $name = sanitize_file_name((string) $file['name']);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed_ext, true)) {
            return array('ok' => false, 'message' => 'Niedozwolony typ pliku.');
        }

        $check = wp_check_filetype_and_ext((string) $file['tmp_name'], $name);
        $mime = isset($check['type']) ? (string) $check['type'] : '';
        if (!in_array($mime, $allowed_mimes, true)) {
            return array('ok' => false, 'message' => 'Niedozwolony MIME pliku.');
        }

        $uploads = wp_upload_dir();
        $base_dir = trailingslashit((string) $uploads['basedir']) . 'zegger-erp/attachments/' . gmdate('Y/m');
        if (!wp_mkdir_p($base_dir)) {
            return array('ok' => false, 'message' => 'Nie udało się utworzyć katalogu załączników.');
        }

        $target_name = wp_unique_filename($base_dir, $name);
        $target_path = trailingslashit($base_dir) . $target_name;

        if (!@move_uploaded_file((string) $file['tmp_name'], $target_path)) {
            return array('ok' => false, 'message' => 'Nie udało się zapisać pliku.');
        }

        $relative = str_replace(wp_normalize_path(trailingslashit((string) $uploads['basedir'])), '', wp_normalize_path($target_path));

        $settings = ZERP_DB::settings();
        $retention_months = isset($settings['attachment_retention_months']) ? (int) $settings['attachment_retention_months'] : 12;
        $retention_months = max(1, min(36, $retention_months));

        $expires_at = wp_date('Y-m-d H:i:s', strtotime('+' . $retention_months . ' months', current_time('timestamp')));

        global $wpdb;
        $t = ZERP_DB::tables();

        $wpdb->insert(
            $t['thread_attachments'],
            array(
                'thread_id' => $thread_id,
                'message_id' => 0,
                'uploader_member_id' => $uploader_member_id,
                'file_path' => $relative,
                'file_name' => $target_name,
                'mime_type' => $mime,
                'size_bytes' => $size,
                'expires_at' => $expires_at,
                'is_expired' => 0,
                'created_at' => current_time('mysql'),
            ),
            array('%d', '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%d', '%s')
        );

        return array('ok' => true, 'attachment_id' => (int) $wpdb->insert_id, 'file_name' => $target_name, 'file_path' => $relative, 'expires_at' => $expires_at);
    }

    public static function close_thread(int $thread_id, int $member_id, string $reason = ''): array
    {
        if (!ZERP_Auth::can('can_close_threads')) {
            return array('ok' => false, 'message' => 'Brak uprawnień do zamykania rozmów.');
        }

        global $wpdb;
        $t = ZERP_DB::tables();
        $thread = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['threads']} WHERE id = %d LIMIT 1", $thread_id), ARRAY_A);
        if (!$thread) {
            return array('ok' => false, 'message' => 'Rozmowa nie istnieje.');
        }
        if (!ZERP_Relations::assert_member_relation_access($member_id, (int) $thread['relation_id'])) {
            return array('ok' => false, 'message' => 'Brak dostępu do relacji rozmowy.');
        }

        $now = current_time('mysql');
        $wpdb->update(
            $t['threads'],
            array(
                'is_closed' => 1,
                'closed_by' => $member_id,
                'closed_reason' => sanitize_textarea_field($reason),
                'closed_at' => $now,
                'updated_at' => $now,
            ),
            array('id' => $thread_id),
            array('%d', '%d', '%s', '%s', '%s'),
            array('%d')
        );

        self::log_system_event($thread_id, 'thread_closed', array('reason' => $reason), $member_id);
        return array('ok' => true);
    }

    public static function reopen_thread(int $thread_id, int $member_id, string $reason = ''): array
    {
        if (!ZERP_Auth::can('can_reopen_threads')) {
            return array('ok' => false, 'message' => 'Brak uprawnień do wznawiania rozmów.');
        }

        global $wpdb;
        $t = ZERP_DB::tables();
        $thread = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['threads']} WHERE id = %d LIMIT 1", $thread_id), ARRAY_A);
        if (!$thread) {
            return array('ok' => false, 'message' => 'Rozmowa nie istnieje.');
        }
        if (!ZERP_Relations::assert_member_relation_access($member_id, (int) $thread['relation_id'])) {
            return array('ok' => false, 'message' => 'Brak dostępu do relacji rozmowy.');
        }

        $now = current_time('mysql');
        $wpdb->update(
            $t['threads'],
            array(
                'is_closed' => 0,
                'reopened_by' => $member_id,
                'reopen_reason' => sanitize_textarea_field($reason),
                'reopened_at' => $now,
                'updated_at' => $now,
            ),
            array('id' => $thread_id),
            array('%d', '%d', '%s', '%s', '%s'),
            array('%d')
        );

        self::add_participant($thread_id, $member_id);
        self::log_system_event($thread_id, 'thread_reopened', array('reason' => $reason), $member_id);

        return array('ok' => true);
    }

    public static function ping_members(int $thread_id, int $author_member_id, array $target_member_ids): array
    {
        if (!ZERP_Auth::can('can_ping_users')) {
            return array('ok' => false, 'message' => 'Brak uprawnień do pingowania.');
        }

        $target_member_ids = array_values(array_unique(array_filter(array_map('intval', $target_member_ids), static fn($id) => $id > 0)));
        if (!$target_member_ids) {
            return array('ok' => true, 'created' => 0);
        }

        global $wpdb;
        $t = ZERP_DB::tables();
        $thread = $wpdb->get_row($wpdb->prepare("SELECT relation_id FROM {$t['threads']} WHERE id = %d LIMIT 1", $thread_id), ARRAY_A);
        if (!$thread) {
            return array('ok' => false, 'message' => 'Rozmowa nie istnieje.');
        }

        if (!ZERP_Relations::assert_member_relation_access($author_member_id, (int) $thread['relation_id'])) {
            return array('ok' => false, 'message' => 'Brak dostępu do relacji rozmowy.');
        }

        $system_message = self::add_message($thread_id, $author_member_id, '', 'system', array('event' => 'ping_users', 'targets' => $target_member_ids), array(), false);
        if (empty($system_message['ok'])) {
            return $system_message;
        }
        $message_id = (int) $system_message['message_id'];

        $created = 0;
        foreach ($target_member_ids as $target_member_id) {
            if (!ZERP_Relations::assert_member_relation_access($target_member_id, (int) $thread['relation_id'])) {
                continue;
            }

            $wpdb->insert(
                $t['thread_pings'],
                array(
                    'thread_id' => $thread_id,
                    'message_id' => $message_id,
                    'target_member_id' => $target_member_id,
                    'created_by' => $author_member_id,
                    'created_at' => current_time('mysql'),
                ),
                array('%d', '%d', '%d', '%d', '%s')
            );

            ZERP_Notifications::create_for_member($target_member_id, 'ping_to_you', 'Otrzymano ping', 'Zostałeś oznaczony w rozmowie.', 'thread', $thread_id);
            $created++;
        }

        self::log_system_event($thread_id, 'users_pinged', array('targets' => $target_member_ids), $author_member_id);

        return array('ok' => true, 'created' => $created);
    }

    public static function messages(int $thread_id): array
    {
        global $wpdb;
        $t = ZERP_DB::tables();

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT msg.*, m.login AS sender_login, m.first_name, m.last_name
                   FROM {$t['thread_messages']} msg
              LEFT JOIN {$t['members']} m ON m.id = msg.sender_member_id
                  WHERE msg.thread_id = %d
               ORDER BY msg.id ASC",
                $thread_id
            ),
            ARRAY_A
        );

        if (!$rows) {
            return array();
        }

        $attachments = self::attachments_for_thread($thread_id);
        $att_by_message = array();
        foreach ($attachments as $att) {
            $mid = (int) ($att['message_id'] ?? 0);
            if ($mid <= 0) {
                continue;
            }
            if (!isset($att_by_message[$mid])) {
                $att_by_message[$mid] = array();
            }
            $att_by_message[$mid][] = $att;
        }

        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['thread_id'] = (int) $row['thread_id'];
            $row['sender_member_id'] = !empty($row['sender_member_id']) ? (int) $row['sender_member_id'] : null;
            $row['meta'] = !empty($row['meta_json']) ? json_decode((string) $row['meta_json'], true) : null;
            $row['sender_name'] = trim((string) ($row['first_name'] . ' ' . $row['last_name'])) ?: (string) ($row['sender_login'] ?? 'System');
            $row['attachments'] = $att_by_message[$row['id']] ?? array();
            unset($row['meta_json'], $row['sender_login'], $row['first_name'], $row['last_name']);
        }

        return $rows;
    }

    public static function participants(int $thread_id): array
    {
        global $wpdb;
        $t = ZERP_DB::tables();

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.*, m.login, m.first_name, m.last_name, m.email, m.company_id
                   FROM {$t['thread_participants']} p
                   JOIN {$t['members']} m ON m.id = p.member_id
                  WHERE p.thread_id = %d
               ORDER BY p.joined_at ASC",
                $thread_id
            ),
            ARRAY_A
        );

        if (!$rows) {
            return array();
        }

        foreach ($rows as &$row) {
            $row['thread_id'] = (int) $row['thread_id'];
            $row['member_id'] = (int) $row['member_id'];
            $row['company_id'] = !empty($row['company_id']) ? (int) $row['company_id'] : null;
            $row['is_muted'] = !empty($row['is_muted']) ? 1 : 0;
            $row['is_handler'] = !empty($row['is_handler']) ? 1 : 0;
            $row['display_name'] = trim((string) ($row['first_name'] . ' ' . $row['last_name'])) ?: (string) $row['login'];
            unset($row['first_name'], $row['last_name']);
        }

        return $rows;
    }

    public static function add_participant(int $thread_id, int $member_id): void
    {
        global $wpdb;
        $t = ZERP_DB::tables();
        $wpdb->replace(
            $t['thread_participants'],
            array(
                'thread_id' => $thread_id,
                'member_id' => $member_id,
                'is_muted' => 0,
                'is_handler' => 0,
                'joined_at' => current_time('mysql'),
                'last_read_message_id' => null,
                'last_read_at' => null,
            ),
            array('%d', '%d', '%d', '%d', '%s', '%d', '%s')
        );
    }

    public static function set_mute(int $thread_id, int $member_id, bool $mute): array
    {
        global $wpdb;
        $t = ZERP_DB::tables();
        $updated = $wpdb->update(
            $t['thread_participants'],
            array('is_muted' => $mute ? 1 : 0),
            array('thread_id' => $thread_id, 'member_id' => $member_id),
            array('%d'),
            array('%d', '%d')
        );

        if ($updated === false) {
            return array('ok' => false, 'message' => 'Nie udało się zmienić wyciszenia.');
        }

        return array('ok' => true);
    }

    public static function mark_thread_read(int $thread_id, int $member_id): void
    {
        global $wpdb;
        $t = ZERP_DB::tables();

        $last_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$t['thread_messages']} WHERE thread_id = %d ORDER BY id DESC LIMIT 1", $thread_id));
        $wpdb->update(
            $t['thread_participants'],
            array('last_read_message_id' => $last_id, 'last_read_at' => current_time('mysql')),
            array('thread_id' => $thread_id, 'member_id' => $member_id),
            array('%d', '%s'),
            array('%d', '%d')
        );
    }

    private static function thread_unread_count(int $thread_id, int $member_id): int
    {
        global $wpdb;
        $t = ZERP_DB::tables();

        $last_read = (int) $wpdb->get_var($wpdb->prepare("SELECT last_read_message_id FROM {$t['thread_participants']} WHERE thread_id = %d AND member_id = %d", $thread_id, $member_id));
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['thread_messages']} WHERE thread_id = %d AND id > %d", $thread_id, $last_read));
    }

    public static function log_system_event(int $thread_id, string $event_type, array $meta = array(), ?int $member_id = null): void
    {
        global $wpdb;
        $t = ZERP_DB::tables();

        $wpdb->insert(
            $t['thread_events'],
            array(
                'thread_id' => $thread_id,
                'event_type' => sanitize_key($event_type),
                'meta_json' => $meta ? wp_json_encode($meta) : null,
                'created_by' => $member_id,
                'created_at' => current_time('mysql'),
            ),
            array('%d', '%s', '%s', '%d', '%s')
        );

        $body = '';
        switch ($event_type) {
            case 'offer_attached':
                $body = 'Przypięto ofertę do rozmowy.';
                break;
            case 'offer_changed':
                $body = 'Oferta powiązana z rozmową została zmieniona.';
                break;
            case 'offer_status_changed':
                $body = 'Zmieniono status powiązanej oferty.';
                break;
            case 'thread_closed':
                $body = 'Rozmowa została zamknięta.';
                break;
            case 'thread_reopened':
                $body = 'Rozmowa została wznowiona.';
                break;
        }

        if ($body !== '') {
            self::add_message($thread_id, $member_id ?? 0, $body, 'system', array('event_type' => $event_type, 'meta' => $meta), array(), false);
        }
    }

    public static function log_offer_changelog(int $thread_id, int $member_id, int $offer_id, array $changes): void
    {
        if (!$changes) {
            return;
        }

        self::log_system_event($thread_id, 'offer_changed', array(
            'offer_id' => $offer_id,
            'changes' => array_values($changes),
        ), $member_id);
    }

    private static function notify_new_message(int $thread_id, int $sender_member_id, int $message_id): void
    {
        global $wpdb;
        $t = ZERP_DB::tables();

        $members = $wpdb->get_col($wpdb->prepare(
            "SELECT member_id FROM {$t['thread_participants']} WHERE thread_id = %d AND member_id <> %d",
            $thread_id,
            $sender_member_id
        ));

        if (!is_array($members)) {
            return;
        }

        foreach ($members as $member_id) {
            ZERP_Notifications::create_for_member((int) $member_id, 'new_message', 'Nowa wiadomość', 'Nowa wiadomość w rozmowie.', 'thread', $thread_id, array('message_id' => $message_id));
        }
    }

    private static function attachments_for_thread(int $thread_id): array
    {
        global $wpdb;
        $t = ZERP_DB::tables();

        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t['thread_attachments']} WHERE thread_id = %d ORDER BY id ASC", $thread_id), ARRAY_A);
        if (!$rows) {
            return array();
        }

        $uploads = wp_upload_dir();
        $baseurl = trailingslashit((string) ($uploads['baseurl'] ?? ''));

        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['thread_id'] = (int) $row['thread_id'];
            $row['message_id'] = (int) $row['message_id'];
            $row['size_bytes'] = (int) $row['size_bytes'];
            $row['is_expired'] = !empty($row['is_expired']) ? 1 : 0;
            $row['url'] = $baseurl . ltrim((string) $row['file_path'], '/');
        }

        return $rows;
    }

    private static function normalize_thread_row(array $row, int $viewer_company_id): array
    {
        $row['id'] = (int) $row['id'];
        $row['relation_id'] = (int) $row['relation_id'];
        $row['category_id'] = !empty($row['category_id']) ? (int) $row['category_id'] : null;
        $row['linked_offer_id'] = !empty($row['linked_offer_id']) ? (int) $row['linked_offer_id'] : null;
        $row['lead_member_id'] = !empty($row['lead_member_id']) ? (int) $row['lead_member_id'] : null;
        $row['is_closed'] = !empty($row['is_closed']) ? 1 : 0;
        $row['company_a_id'] = (int) $row['company_a_id'];
        $row['company_b_id'] = (int) $row['company_b_id'];

        $row['other_company_id'] = ($viewer_company_id === (int) $row['company_a_id']) ? (int) $row['company_b_id'] : (int) $row['company_a_id'];
        $row['other_company_name'] = ($viewer_company_id === (int) $row['company_a_id']) ? (string) $row['company_b_name'] : (string) $row['company_a_name'];

        return $row;
    }
}
