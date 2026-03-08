<?php
if (!defined('ABSPATH')) {
    exit;
}

final class ZERP_Offers
{
    public static function init(): void
    {
        // routes via REST
    }

    public static function statuses(): array
    {
        return array(
            'new' => 'Nowa',
            'in_progress' => 'W trakcie',
            'sent' => 'Wysłane',
            'accepted' => 'Przyjęte',
            'in_realization' => 'W realizacji',
            'completed' => 'Zrealizowana',
            'rejected' => 'Odrzucona',
            'canceled' => 'Anulowana',
        );
    }

    public static function normalize_status(string $status): string
    {
        $status = sanitize_key($status);
        $legacy_map = array(
            'unset' => 'new',
            'won' => 'accepted',
            'lost' => 'rejected',
            'needs_update' => 'in_progress',
        );

        if (isset($legacy_map[$status])) {
            return $legacy_map[$status];
        }

        $allowed = array_keys(self::statuses());
        if (!in_array($status, $allowed, true)) {
            return 'new';
        }

        return $status;
    }

    public static function get_offer(int $offer_id, int $company_id): ?array
    {
        global $wpdb;
        $t = ZERP_DB::tables();

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$t['offers']} WHERE id = %d AND company_id = %d LIMIT 1", $offer_id, $company_id),
            ARRAY_A
        );

        if (!$row) {
            return null;
        }

        return self::normalize_offer_row($row);
    }

    public static function list_offers(int $company_id, ?int $relation_id = null): array
    {
        global $wpdb;
        $t = ZERP_DB::tables();

        $where = 'WHERE company_id = %d';
        $params = array($company_id);

        if ($relation_id !== null) {
            $where .= ' AND relation_id = %d';
            $params[] = $relation_id;
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$t['offers']} {$where} ORDER BY id DESC", ...$params),
            ARRAY_A
        );

        if (!$rows) {
            return array();
        }

        $out = array();
        foreach ($rows as $row) {
            $out[] = self::normalize_offer_row($row);
        }

        return $out;
    }

    public static function create_offer(int $company_id, int $member_id, array $payload): array
    {
        if (!ZERP_Auth::can('can_create_offers')) {
            return array('ok' => false, 'message' => 'Brak uprawnień do tworzenia ofert.');
        }

        $title = sanitize_text_field((string) ($payload['title'] ?? ''));
        if ($title === '') {
            return array('ok' => false, 'message' => 'Tytuł oferty jest wymagany.');
        }

        $relation_id = !empty($payload['relation_id']) ? (int) $payload['relation_id'] : null;
        $source_company_id = !empty($payload['source_company_id']) ? (int) $payload['source_company_id'] : $company_id;

        $relation_check = self::validate_offer_relation_context($company_id, $source_company_id, $relation_id);
        if (empty($relation_check['ok'])) {
            return $relation_check;
        }
        $relation_id = $relation_check['relation_id'];

        $status = self::normalize_status((string) ($payload['status'] ?? 'new'));
        $comment = sanitize_textarea_field((string) ($payload['comment'] ?? ''));
        $sales_note = sanitize_textarea_field((string) ($payload['sales_note'] ?? ''));
        $client_id = !empty($payload['client_id']) ? (int) $payload['client_id'] : null;

        $data_json = null;
        if (isset($payload['data']) && is_array($payload['data'])) {
            $data_json = wp_json_encode($payload['data']);
        } elseif (isset($payload['data_json']) && is_string($payload['data_json'])) {
            $data_json = $payload['data_json'];
        }

        $now = current_time('mysql');
        global $wpdb;
        $t = ZERP_DB::tables();

        $wpdb->insert(
            $t['offers'],
            array(
                'company_id' => $company_id,
                'relation_id' => $relation_id,
                'source_company_id' => $source_company_id,
                'legacy_offer_id' => null,
                'created_by' => $member_id,
                'updated_by' => $member_id,
                'client_id' => $client_id,
                'title' => $title,
                'title_norm' => mb_strtolower(trim($title)),
                'status' => $status,
                'legacy_status' => null,
                'status_updated_at' => $now,
                'comment' => $comment,
                'sales_note' => $sales_note,
                'data_json' => $data_json,
                'pdf_path' => null,
                'linked_thread_id' => null,
                'locked' => 0,
                'version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            )
        );

        $offer_id = (int) $wpdb->insert_id;
        self::log_event($offer_id, $company_id, $member_id, 'offer_created', array('status' => $status));

        return array('ok' => true, 'offer' => self::get_offer($offer_id, $company_id));
    }

    public static function update_offer(int $offer_id, int $company_id, int $member_id, array $payload): array
    {
        if (!ZERP_Auth::can('can_edit_offers')) {
            return array('ok' => false, 'message' => 'Brak uprawnień do edycji ofert.');
        }

        global $wpdb;
        $t = ZERP_DB::tables();

        $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['offers']} WHERE id = %d AND company_id = %d LIMIT 1", $offer_id, $company_id), ARRAY_A);
        if (!$existing) {
            return array('ok' => false, 'message' => 'Oferta nie istnieje.');
        }

        if (!empty($existing['locked']) && !ZERP_Auth::can('can_manage_offer_lock')) {
            return array('ok' => false, 'message' => 'Oferta jest zablokowana.');
        }

        $patch = array();
        $changelog = array();

        if (array_key_exists('title', $payload)) {
            $title = sanitize_text_field((string) $payload['title']);
            if ($title !== '' && $title !== (string) $existing['title']) {
                $patch['title'] = $title;
                $patch['title_norm'] = mb_strtolower(trim($title));
                $changelog[] = 'zmieniono tytuł';
            }
        }

        if (array_key_exists('comment', $payload)) {
            $comment = sanitize_textarea_field((string) $payload['comment']);
            if ($comment !== (string) $existing['comment']) {
                $patch['comment'] = $comment;
                $changelog[] = 'zmieniono komentarz';
            }
        }

        if (array_key_exists('sales_note', $payload)) {
            $sales_note = sanitize_textarea_field((string) $payload['sales_note']);
            if ($sales_note !== (string) $existing['sales_note']) {
                $patch['sales_note'] = $sales_note;
                $changelog[] = 'zmieniono notatkę handlową';
            }
        }

        if (array_key_exists('client_id', $payload)) {
            $client_id = !empty($payload['client_id']) ? (int) $payload['client_id'] : null;
            if ((int) $existing['client_id'] !== (int) $client_id) {
                $patch['client_id'] = $client_id;
                $changelog[] = 'zmieniono klienta';
            }
        }

        if (array_key_exists('data', $payload) && is_array($payload['data'])) {
            $new_data_json = wp_json_encode($payload['data']);
            if ($new_data_json !== (string) ($existing['data_json'] ?? '')) {
                $patch['data_json'] = $new_data_json;
                $changelog[] = 'zmieniono pozycje oferty';
            }
        }

        if (array_key_exists('status', $payload)) {
            if (!ZERP_Auth::can('can_change_offer_status')) {
                return array('ok' => false, 'message' => 'Brak uprawnień do zmiany statusu.');
            }
            $status = self::normalize_status((string) $payload['status']);
            if ($status !== (string) $existing['status']) {
                $patch['status'] = $status;
                $patch['status_updated_at'] = current_time('mysql');
                $changelog[] = 'zmieniono status';
            }
        }

        if (!$patch) {
            return array('ok' => true, 'updated' => false, 'offer' => self::normalize_offer_row($existing));
        }

        $patch['updated_by'] = $member_id;
        $patch['updated_at'] = current_time('mysql');
        $patch['version'] = ((int) $existing['version']) + 1;

        $wpdb->update($t['offers'], $patch, array('id' => $offer_id, 'company_id' => $company_id));

        self::log_event($offer_id, $company_id, $member_id, 'offer_updated', array('changes' => $changelog));

        $linked_thread_id = !empty($existing['linked_thread_id']) ? (int) $existing['linked_thread_id'] : 0;
        if ($linked_thread_id > 0) {
            ZERP_Chat::log_offer_changelog($linked_thread_id, $member_id, $offer_id, $changelog);
            ZERP_Notifications::create_for_company($company_id, 'offer_changed', 'Oferta zaktualizowana', implode(', ', $changelog), 'offer', $offer_id);
        }

        return array('ok' => true, 'updated' => true, 'offer' => self::get_offer($offer_id, $company_id));
    }

    public static function update_status(int $offer_id, int $company_id, int $member_id, string $status): array
    {
        return self::update_offer($offer_id, $company_id, $member_id, array('status' => $status));
    }

    public static function toggle_lock(int $offer_id, int $company_id, int $member_id, bool $locked): array
    {
        if (!ZERP_Auth::can('can_manage_offer_lock')) {
            return array('ok' => false, 'message' => 'Brak uprawnień do blokowania ofert.');
        }

        global $wpdb;
        $t = ZERP_DB::tables();
        $offer = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['offers']} WHERE id = %d AND company_id = %d LIMIT 1", $offer_id, $company_id), ARRAY_A);
        if (!$offer) {
            return array('ok' => false, 'message' => 'Oferta nie istnieje.');
        }

        $wpdb->update(
            $t['offers'],
            array(
                'locked' => $locked ? 1 : 0,
                'locked_at' => $locked ? current_time('mysql') : null,
                'locked_by' => $locked ? $member_id : null,
                'lock_reason' => $locked ? 'manual' : null,
                'updated_by' => $member_id,
                'updated_at' => current_time('mysql'),
            ),
            array('id' => $offer_id),
            array('%d', '%s', '%d', '%s', '%d', '%s'),
            array('%d')
        );

        self::log_event($offer_id, $company_id, $member_id, $locked ? 'offer_locked' : 'offer_unlocked');

        return array('ok' => true, 'offer' => self::get_offer($offer_id, $company_id));
    }

    public static function delete_offer(int $offer_id, int $company_id, int $member_id): array
    {
        global $wpdb;
        $t = ZERP_DB::tables();

        $offer = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['offers']} WHERE id = %d AND company_id = %d LIMIT 1", $offer_id, $company_id), ARRAY_A);
        if (!$offer) {
            return array('ok' => false, 'message' => 'Oferta nie istnieje.');
        }

        $can_delete = ZERP_Auth::can('can_delete_company_offers') || ((int) $offer['created_by'] === $member_id && ZERP_Auth::can('can_delete_own_offers'));
        if (!$can_delete) {
            return array('ok' => false, 'message' => 'Brak uprawnień do usunięcia oferty.');
        }

        if (!empty($offer['linked_thread_id'])) {
            return array('ok' => false, 'message' => 'Nie można usunąć oferty powiązanej z rozmową.');
        }

        $wpdb->delete($t['offers'], array('id' => $offer_id, 'company_id' => $company_id), array('%d', '%d'));
        $wpdb->delete($t['offer_events'], array('offer_id' => $offer_id), array('%d'));
        $wpdb->delete($t['offer_pdf_archive'], array('offer_id' => $offer_id), array('%d'));

        return array('ok' => true);
    }

    public static function link_offer_to_thread(int $offer_id, int $thread_id, int $company_id, int $member_id): array
    {
        if (!ZERP_Auth::can('can_bind_offer_to_chat')) {
            return array('ok' => false, 'message' => 'Brak uprawnień do przypięcia oferty do rozmowy.');
        }

        global $wpdb;
        $t = ZERP_DB::tables();

        $offer = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['offers']} WHERE id = %d AND company_id = %d LIMIT 1", $offer_id, $company_id), ARRAY_A);
        if (!$offer) {
            return array('ok' => false, 'message' => 'Oferta nie istnieje.');
        }

        $thread = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['threads']} WHERE id = %d LIMIT 1", $thread_id), ARRAY_A);
        if (!$thread) {
            return array('ok' => false, 'message' => 'Rozmowa nie istnieje.');
        }
        if (!empty($thread['is_closed'])) {
            return array('ok' => false, 'message' => 'Nie można przypiąć oferty do zamkniętej rozmowy.');
        }

        if (!empty($offer['linked_thread_id']) && (int) $offer['linked_thread_id'] !== $thread_id) {
            return array('ok' => false, 'message' => 'Oferta jest już przypięta do innej rozmowy.');
        }

        if (!empty($thread['linked_offer_id']) && (int) $thread['linked_offer_id'] !== $offer_id) {
            return array('ok' => false, 'message' => 'Rozmowa ma już przypiętą ofertę.');
        }

        $offer_relation = !empty($offer['relation_id']) ? (int) $offer['relation_id'] : 0;
        $thread_relation = !empty($thread['relation_id']) ? (int) $thread['relation_id'] : 0;
        if ($offer_relation <= 0 || $thread_relation <= 0 || $offer_relation !== $thread_relation) {
            return array('ok' => false, 'message' => 'Oferta i rozmowa muszą należeć do tej samej relacji A↔B.');
        }

        // Tylko prowadzący rozmowę może przypiąć ofertę.
        if (!empty($thread['lead_member_id']) && (int) $thread['lead_member_id'] !== $member_id) {
            return array('ok' => false, 'message' => 'Tylko prowadzący rozmowy może przypiąć ofertę.');
        }

        $now = current_time('mysql');
        $wpdb->update(
            $t['offers'],
            array('linked_thread_id' => $thread_id, 'updated_by' => $member_id, 'updated_at' => $now),
            array('id' => $offer_id),
            array('%d', '%d', '%s'),
            array('%d')
        );

        $wpdb->update(
            $t['threads'],
            array('linked_offer_id' => $offer_id, 'updated_at' => $now),
            array('id' => $thread_id),
            array('%d', '%s'),
            array('%d')
        );

        $wpdb->replace(
            $t['offer_chat_link'],
            array(
                'offer_id' => $offer_id,
                'thread_id' => $thread_id,
                'relation_id' => $offer_relation,
                'created_by' => $member_id,
                'created_at' => $now,
            ),
            array('%d', '%d', '%d', '%d', '%s')
        );

        self::log_event($offer_id, $company_id, $member_id, 'offer_linked_to_thread', array('thread_id' => $thread_id));
        ZERP_Chat::log_system_event($thread_id, 'offer_attached', array('offer_id' => $offer_id), $member_id);

        return array('ok' => true, 'offer' => self::get_offer($offer_id, $company_id));
    }

    public static function validate_offer_relation_context(int $company_id, int $source_company_id, ?int $relation_id): array
    {
        if ($source_company_id === $company_id) {
            return array('ok' => true, 'relation_id' => null);
        }

        if ($relation_id === null) {
            $relation_id = ZERP_Relations::active_relation_id($company_id, $source_company_id);
        }

        if (!$relation_id) {
            return array('ok' => false, 'message' => 'Brak aktywnej relacji A↔B dla wybranego kontekstu danych.');
        }

        global $wpdb;
        $t = ZERP_DB::tables();
        $relation = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['relations']} WHERE id = %d LIMIT 1", $relation_id), ARRAY_A);
        if (!$relation || (string) $relation['status'] !== 'active') {
            return array('ok' => false, 'message' => 'Relacja A↔B nie jest aktywna.');
        }

        $companies = array((int) $relation['company_a_id'], (int) $relation['company_b_id']);
        if (!in_array($company_id, $companies, true) || !in_array($source_company_id, $companies, true)) {
            return array('ok' => false, 'message' => 'Oferta musi należeć do właściwej relacji A↔B.');
        }

        return array('ok' => true, 'relation_id' => (int) $relation_id);
    }

    public static function log_event(int $offer_id, int $company_id, ?int $actor_member_id, string $event_type, array $meta = array()): void
    {
        global $wpdb;
        $t = ZERP_DB::tables();
        $wpdb->insert(
            $t['offer_events'],
            array(
                'offer_id' => $offer_id,
                'company_id' => $company_id,
                'actor_member_id' => $actor_member_id,
                'event_type' => sanitize_key($event_type),
                'meta_json' => $meta ? wp_json_encode($meta) : null,
                'created_at' => current_time('mysql'),
            ),
            array('%d', '%d', '%d', '%s', '%s', '%s')
        );
    }

    private static function normalize_offer_row(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['company_id'] = (int) $row['company_id'];
        $row['relation_id'] = !empty($row['relation_id']) ? (int) $row['relation_id'] : null;
        $row['source_company_id'] = !empty($row['source_company_id']) ? (int) $row['source_company_id'] : null;
        $row['legacy_offer_id'] = !empty($row['legacy_offer_id']) ? (int) $row['legacy_offer_id'] : null;
        $row['linked_thread_id'] = !empty($row['linked_thread_id']) ? (int) $row['linked_thread_id'] : null;
        $row['created_by'] = !empty($row['created_by']) ? (int) $row['created_by'] : null;
        $row['updated_by'] = !empty($row['updated_by']) ? (int) $row['updated_by'] : null;
        $row['client_id'] = !empty($row['client_id']) ? (int) $row['client_id'] : null;
        $row['locked'] = !empty($row['locked']) ? 1 : 0;
        $row['version'] = (int) $row['version'];
        $row['data'] = !empty($row['data_json']) ? json_decode((string) $row['data_json'], true) : null;
        unset($row['data_json']);
        return $row;
    }
}
