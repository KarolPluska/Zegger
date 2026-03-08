<?php
if (!defined('ABSPATH')) {
    exit;
}

final class ZERP_Catalog
{
    public static function init(): void
    {
        // routes via REST
    }

    public static function list_categories(int $company_id): array
    {
        global $wpdb;
        $t = ZERP_DB::tables();
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t['catalog_categories']} WHERE company_id = %d ORDER BY sort_order ASC, id ASC", $company_id), ARRAY_A);
        return $rows ?: array();
    }

    public static function list_items(int $company_id, bool $only_active = false): array
    {
        global $wpdb;
        $t = ZERP_DB::tables();
        $sql = "SELECT * FROM {$t['catalog_items']} WHERE company_id = %d";
        if ($only_active) {
            $sql .= ' AND is_active = 1';
        }
        $sql .= ' ORDER BY id DESC';

        $rows = $wpdb->get_results($wpdb->prepare($sql, $company_id), ARRAY_A);
        if (!$rows) {
            return array();
        }

        foreach ($rows as &$row) {
            $row['meta'] = !empty($row['meta_json']) ? json_decode((string) $row['meta_json'], true) : null;
            unset($row['meta_json']);
        }

        return $rows;
    }

    public static function list_variants(int $company_id, ?int $item_id = null, bool $only_active = false): array
    {
        global $wpdb;
        $t = ZERP_DB::tables();

        $where = 'WHERE company_id = %d';
        $params = array($company_id);

        if ($item_id !== null) {
            $where .= ' AND item_id = %d';
            $params[] = $item_id;
        }
        if ($only_active) {
            $where .= ' AND is_active = 1';
        }

        $sql = "SELECT * FROM {$t['catalog_variants']} {$where} ORDER BY id DESC";
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);
        if (!$rows) {
            return array();
        }

        foreach ($rows as &$row) {
            $row['meta'] = !empty($row['meta_json']) ? json_decode((string) $row['meta_json'], true) : null;
            unset($row['meta_json']);
            $row['unit_net'] = $row['unit_net'] !== null ? (float) $row['unit_net'] : null;
            $row['unit_gross'] = $row['unit_gross'] !== null ? (float) $row['unit_gross'] : null;
        }

        return $rows;
    }

    public static function upsert_category(int $company_id, array $payload): array
    {
        $member = ZERP_Auth::require_member();
        if (!$member || (int) $member['company_id'] !== $company_id) {
            return array('ok' => false, 'message' => 'Brak dostępu.');
        }
        if (!ZERP_Auth::can('can_manage_catalog_categories')) {
            return array('ok' => false, 'message' => 'Brak uprawnień do kategorii.');
        }

        $id = !empty($payload['id']) ? (int) $payload['id'] : 0;
        $name = sanitize_text_field((string) ($payload['name'] ?? ''));
        if (!$name) {
            return array('ok' => false, 'message' => 'Nazwa kategorii jest wymagana.');
        }

        $data = array(
            'company_id' => $company_id,
            'parent_id' => !empty($payload['parent_id']) ? (int) $payload['parent_id'] : null,
            'name' => $name,
            'slug' => sanitize_title($name),
            'sort_order' => isset($payload['sort_order']) ? (int) $payload['sort_order'] : 0,
            'is_active' => isset($payload['is_active']) ? (!empty($payload['is_active']) ? 1 : 0) : 1,
            'updated_at' => current_time('mysql'),
        );

        global $wpdb;
        $t = ZERP_DB::tables();

        if ($id > 0) {
            $wpdb->update($t['catalog_categories'], $data, array('id' => $id, 'company_id' => $company_id), array('%d', '%d', '%s', '%s', '%d', '%d', '%s'), array('%d', '%d'));
            return array('ok' => true, 'id' => $id);
        }

        $data['created_at'] = current_time('mysql');
        $wpdb->insert($t['catalog_categories'], $data, array('%d', '%d', '%s', '%s', '%d', '%d', '%s', '%s'));
        return array('ok' => true, 'id' => (int) $wpdb->insert_id);
    }

    public static function upsert_item(int $company_id, array $payload): array
    {
        $member = ZERP_Auth::require_member();
        if (!$member || (int) $member['company_id'] !== $company_id) {
            return array('ok' => false, 'message' => 'Brak dostępu.');
        }
        if (!ZERP_Auth::can('can_manage_internal_catalog')) {
            return array('ok' => false, 'message' => 'Brak uprawnień do katalogu.');
        }

        $id = !empty($payload['id']) ? (int) $payload['id'] : 0;
        $name = sanitize_text_field((string) ($payload['name'] ?? ''));
        if (!$name) {
            return array('ok' => false, 'message' => 'Nazwa produktu jest wymagana.');
        }

        $data = array(
            'company_id' => $company_id,
            'category_id' => !empty($payload['category_id']) ? (int) $payload['category_id'] : null,
            'name' => $name,
            'sku' => sanitize_text_field((string) ($payload['sku'] ?? '')),
            'unit' => sanitize_text_field((string) ($payload['unit'] ?? 'szt')),
            'description' => sanitize_textarea_field((string) ($payload['description'] ?? '')),
            'source_label' => 'local',
            'is_active' => isset($payload['is_active']) ? (!empty($payload['is_active']) ? 1 : 0) : 1,
            'meta_json' => !empty($payload['meta']) && is_array($payload['meta']) ? wp_json_encode($payload['meta']) : null,
            'updated_at' => current_time('mysql'),
        );

        global $wpdb;
        $t = ZERP_DB::tables();

        if ($id > 0) {
            $wpdb->update($t['catalog_items'], $data, array('id' => $id, 'company_id' => $company_id));
            return array('ok' => true, 'id' => $id);
        }

        $data['created_at'] = current_time('mysql');
        $wpdb->insert($t['catalog_items'], $data);

        return array('ok' => true, 'id' => (int) $wpdb->insert_id);
    }

    public static function upsert_variant(int $company_id, array $payload): array
    {
        $member = ZERP_Auth::require_member();
        if (!$member || (int) $member['company_id'] !== $company_id) {
            return array('ok' => false, 'message' => 'Brak dostępu.');
        }
        if (!ZERP_Auth::can('can_manage_catalog_variants')) {
            return array('ok' => false, 'message' => 'Brak uprawnień do wariantów.');
        }

        $id = !empty($payload['id']) ? (int) $payload['id'] : 0;
        $item_id = !empty($payload['item_id']) ? (int) $payload['item_id'] : 0;
        $color_label = sanitize_text_field((string) ($payload['color_label'] ?? 'Brak'));
        $sku = sanitize_text_field((string) ($payload['sku'] ?? ''));

        if ($item_id <= 0 || !$sku) {
            return array('ok' => false, 'message' => 'Wariant wymaga item_id i SKU.');
        }

        $unit_net = isset($payload['unit_net']) ? (float) $payload['unit_net'] : null;
        $unit_gross = isset($payload['unit_gross']) ? (float) $payload['unit_gross'] : null;

        $data = array(
            'company_id' => $company_id,
            'item_id' => $item_id,
            'color_label' => $color_label ?: 'Brak',
            'sku' => $sku,
            'unit_net' => $unit_net,
            'unit_gross' => $unit_gross,
            'is_active' => isset($payload['is_active']) ? (!empty($payload['is_active']) ? 1 : 0) : 1,
            'source_label' => 'local',
            'meta_json' => !empty($payload['meta']) && is_array($payload['meta']) ? wp_json_encode($payload['meta']) : null,
            'updated_at' => current_time('mysql'),
        );

        global $wpdb;
        $t = ZERP_DB::tables();

        if ($id > 0) {
            $wpdb->update($t['catalog_variants'], $data, array('id' => $id, 'company_id' => $company_id));
            return array('ok' => true, 'id' => $id);
        }

        $data['created_at'] = current_time('mysql');
        $wpdb->insert($t['catalog_variants'], $data);
        return array('ok' => true, 'id' => (int) $wpdb->insert_id);
    }

    public static function archive_item(int $company_id, int $item_id): array
    {
        $member = ZERP_Auth::require_member();
        if (!$member || (int) $member['company_id'] !== $company_id) {
            return array('ok' => false, 'message' => 'Brak dostępu.');
        }
        if (!ZERP_Auth::can('can_archive_catalog_items')) {
            return array('ok' => false, 'message' => 'Brak uprawnień do archiwizacji.');
        }

        global $wpdb;
        $t = ZERP_DB::tables();

        $wpdb->update($t['catalog_items'], array('is_active' => 0, 'updated_at' => current_time('mysql')), array('id' => $item_id, 'company_id' => $company_id), array('%d', '%s'), array('%d', '%d'));
        $wpdb->update($t['catalog_variants'], array('is_active' => 0, 'updated_at' => current_time('mysql')), array('item_id' => $item_id, 'company_id' => $company_id), array('%d', '%s'), array('%d', '%d'));

        return array('ok' => true);
    }

    public static function merged_products_for_context(int $viewer_company_id, int $context_company_id): array
    {
        if ($viewer_company_id !== $context_company_id) {
            $relation_id = ZERP_Relations::active_relation_id($viewer_company_id, $context_company_id);
            if (!$relation_id) {
                return array();
            }
        }

        $local_items = self::list_items($context_company_id, true);
        $local_variants = self::list_variants($context_company_id, null, true);
        $google_cache = ZERP_Sources_Google::get_cache($context_company_id);

        $merged = array();

        foreach ($local_items as $item) {
            $item_id = (int) ($item['id'] ?? 0);
            if ($item_id <= 0) {
                continue;
            }

            foreach ($local_variants as $variant) {
                if ((int) $variant['item_id'] !== $item_id) {
                    continue;
                }

                $merged[] = array(
                    'source' => 'local',
                    'company_id' => $context_company_id,
                    'item_id' => $item_id,
                    'item_name' => (string) ($item['name'] ?? ''),
                    'category_id' => !empty($item['category_id']) ? (int) $item['category_id'] : null,
                    'color' => (string) ($variant['color_label'] ?? 'Brak'),
                    'sku' => (string) ($variant['sku'] ?? ''),
                    'unit_net' => isset($variant['unit_net']) ? (float) $variant['unit_net'] : null,
                    'unit_gross' => isset($variant['unit_gross']) ? (float) $variant['unit_gross'] : null,
                    'unit' => (string) ($item['unit'] ?? 'szt'),
                );
            }
        }

        if ($google_cache && !empty($google_cache['data']) && is_array($google_cache['data'])) {
            foreach ($google_cache['data'] as $sheet_name => $matrix) {
                $parsed = self::parse_google_matrix_to_products((string) $sheet_name, $matrix);
                foreach ($parsed as $row) {
                    $merged[] = $row;
                }
            }
        }

        return $merged;
    }

    private static function parse_google_matrix_to_products(string $sheet_name, $matrix): array
    {
        if (!is_array($matrix)) {
            return array();
        }

        $headers = isset($matrix['headers']) && is_array($matrix['headers']) ? $matrix['headers'] : array();
        $rows = isset($matrix['rows']) && is_array($matrix['rows']) ? $matrix['rows'] : array();
        if (!$headers || !$rows) {
            return array();
        }

        $header_map = array();
        foreach ($headers as $idx => $header) {
            $norm = mb_strtolower(trim((string) $header));
            $header_map[$norm] = $idx;
        }

        $idx_category = self::first_index($header_map, array('kategoria', 'category'));
        $idx_subcategory = self::first_index($header_map, array('podkategoria', 'subcategory', 'subkategoria'));
        $idx_product = self::first_index($header_map, array('produkt', 'product', 'nazwa produktu', 'nazwa'));
        $idx_unit = self::first_index($header_map, array('jednostka', 'unit'));
        $idx_price = self::first_index($header_map, array('cena netto [pln]', 'cena netto', 'netto [pln]', 'cena netto pln'));

        if ($idx_product === null) {
            return array();
        }

        $variant_columns = array();
        foreach ($headers as $idx => $header) {
            $header_raw = trim((string) $header);
            if ($header_raw === '') {
                continue;
            }

            $match = array();
            if (preg_match('/^Nr\s*towaru(?:\s+(.*))?$/iu', $header_raw, $match)) {
                $suffix = isset($match[1]) ? trim((string) $match[1]) : '';
                $color = $suffix !== '' ? $suffix : 'Brak';
                $variant_columns[] = array(
                    'index' => $idx,
                    'color' => $color,
                );
            }
        }

        $out = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $product_name = self::row_value($row, $idx_product);
            if ($product_name === '') {
                continue;
            }

            $category = self::row_value($row, $idx_category);
            $subcategory = self::row_value($row, $idx_subcategory);
            $unit = self::row_value($row, $idx_unit);
            if ($unit === '') {
                $unit = 'szt';
            }

            $base_price = self::to_float(self::row_value($row, $idx_price));

            if (!$variant_columns) {
                $out[] = array(
                    'source' => 'google',
                    'sheet' => $sheet_name,
                    'category' => $category,
                    'subcategory' => $subcategory,
                    'item_name' => $product_name,
                    'color' => 'Brak',
                    'sku' => '',
                    'unit' => $unit,
                    'unit_net' => $base_price,
                    'unit_gross' => null,
                );
                continue;
            }

            foreach ($variant_columns as $variant) {
                $sku = self::row_value($row, (int) $variant['index']);
                if ($sku === '') {
                    continue;
                }

                $out[] = array(
                    'source' => 'google',
                    'sheet' => $sheet_name,
                    'category' => $category,
                    'subcategory' => $subcategory,
                    'item_name' => $product_name,
                    'color' => $variant['color'],
                    'sku' => $sku,
                    'unit' => $unit,
                    'unit_net' => $base_price,
                    'unit_gross' => null,
                );
            }
        }

        return $out;
    }

    private static function first_index(array $header_map, array $candidates): ?int
    {
        foreach ($candidates as $candidate) {
            if (array_key_exists($candidate, $header_map)) {
                return (int) $header_map[$candidate];
            }
        }
        return null;
    }

    private static function row_value(array $row, ?int $index): string
    {
        if ($index === null || !array_key_exists($index, $row)) {
            return '';
        }
        return trim((string) $row[$index]);
    }

    private static function to_float(string $value): ?float
    {
        $value = str_replace(array(' ', ',', 'zł', 'PLN'), array('', '.', '', ''), $value);
        if ($value === '' || !is_numeric($value)) {
            return null;
        }
        return (float) $value;
    }
}
