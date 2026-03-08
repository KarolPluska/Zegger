<?php
if (!defined('ABSPATH')) {
    exit;
}

final class ZERP_Offer_PDF
{
    public static function init(): void
    {
        // routes via REST
    }

    public static function get_pdf(int $offer_id, int $company_id): array
    {
        $offer = ZERP_Offers::get_offer($offer_id, $company_id);
        if (!$offer) {
            return array('ok' => false, 'message' => 'Oferta nie istnieje.');
        }

        if (!empty($offer['pdf_path'])) {
            return array(
                'ok' => true,
                'pdf_path' => (string) $offer['pdf_path'],
                'pdf_url' => self::uploads_url((string) $offer['pdf_path']),
                'source' => 'zerp',
            );
        }

        // Bez regresji: jeśli oferta pochodzi z legacy i ma plik PDF, pobieramy ścieżkę bezpośrednio z tabeli legacy.
        if (!empty($offer['legacy_offer_id'])) {
            global $wpdb;
            $legacy_table = $wpdb->prefix . 'zqos_offers';
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $legacy_table));
            if ($exists === $legacy_table) {
                $legacy_path = $wpdb->get_var($wpdb->prepare("SELECT pdf_path FROM {$legacy_table} WHERE id = %d LIMIT 1", (int) $offer['legacy_offer_id']));
                if (is_string($legacy_path) && trim($legacy_path) !== '') {
                    return array(
                        'ok' => true,
                        'pdf_path' => $legacy_path,
                        'pdf_url' => self::uploads_url($legacy_path),
                        'source' => 'legacy',
                    );
                }
            }
        }

        return array('ok' => false, 'message' => 'Brak PDF dla oferty.');
    }

    public static function attach_pdf(int $offer_id, int $company_id, string $relative_path, ?int $size_bytes = null): array
    {
        global $wpdb;
        $t = ZERP_DB::tables();

        $relative_path = ltrim(wp_normalize_path($relative_path), '/');
        if ($relative_path === '') {
            return array('ok' => false, 'message' => 'Niepoprawna ścieżka PDF.');
        }

        $wpdb->update(
            $t['offers'],
            array('pdf_path' => $relative_path, 'updated_at' => current_time('mysql')),
            array('id' => $offer_id, 'company_id' => $company_id),
            array('%s', '%s'),
            array('%d', '%d')
        );

        $wpdb->insert(
            $t['offer_pdf_archive'],
            array(
                'offer_id' => $offer_id,
                'file_path' => $relative_path,
                'file_size' => $size_bytes,
                'created_at' => current_time('mysql'),
            ),
            array('%d', '%s', '%d', '%s')
        );

        return array('ok' => true);
    }

    private static function uploads_url(string $relative_path): string
    {
        $uploads = wp_upload_dir();
        $base_url = isset($uploads['baseurl']) ? (string) $uploads['baseurl'] : '';
        return trailingslashit($base_url) . ltrim($relative_path, '/');
    }
}
