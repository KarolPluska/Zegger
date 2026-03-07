<?php
if (!defined('ABSPATH')) { exit; }

/**
 * ZQOS_Maintenance
 * - Retencja ofert i PDF (opcjonalnie)
 * - Statystyki storage
 */
final class ZQOS_Maintenance {

  const CRON_HOOK = 'zqos_cleanup_offers';

  public static function init(){
    add_action(self::CRON_HOOK, array(__CLASS__, 'cron_run'));

    // Przeplanuj po zmianie ustawień
    add_action('update_option_' . ZQOS_DB::OPT_SETTINGS, function($old, $new){
      self::reschedule();
    }, 10, 2);
  }

  public static function activate(){
    self::reschedule(true);
  }

  public static function deactivate(){
    $ts = wp_next_scheduled(self::CRON_HOOK);
    while ($ts){
      wp_unschedule_event($ts, self::CRON_HOOK);
      $ts = wp_next_scheduled(self::CRON_HOOK);
    }
  }

  public static function reschedule($force = false){
    $s = ZQOS_DB::settings();
    $enabled = !empty($s['retention_enabled']);

    // Jeśli wyłączone - usuń cron
    if (!$enabled){
      self::deactivate();
      return;
    }

    // Jeśli włączone - zapewnij daily
    $next = wp_next_scheduled(self::CRON_HOOK);
    if ($next && !$force){
      return;
    }

    if ($next){
      self::deactivate();
    }

    // Uruchom raz dziennie. Start za ~5 minut.
    wp_schedule_event(time() + 300, 'daily', self::CRON_HOOK);
  }

  public static function cron_run(){
    $s = ZQOS_DB::settings();
    if (empty($s['retention_enabled'])) return;

    $months = isset($s['retention_months']) ? (int)$s['retention_months'] : 12;
    if ($months < 1) $months = 1;
    if ($months > 120) $months = 120;

    self::cleanup_offers_older_than_months($months, 1000);
  }

  public static function cleanup_offers_older_than_months($months, $limit = 1000){
    global $wpdb;
    $t = ZQOS_DB::tables();

    $cutTs = current_time('timestamp') - (int)round($months * 30 * DAY_IN_SECONDS);
    $cut = wp_date('Y-m-d H:i:s', $cutTs);

    $limit = (int)$limit;
    if ($limit < 1) $limit = 1;
    if ($limit > 5000) $limit = 5000;

    $rows = $wpdb->get_results($wpdb->prepare(
      "SELECT id, pdf_path FROM {$t['offers']} WHERE created_at < %s ORDER BY id ASC LIMIT %d",
      $cut, $limit
    ), ARRAY_A);

    if (!$rows) return array('ok' => true, 'deleted' => 0);

    $ids = array();
    $u = wp_upload_dir();
    $baseDir = wp_normalize_path(trailingslashit($u['basedir']));

    foreach ($rows as $r){
      $id = (int)($r['id'] ?? 0);
      if ($id <= 0) continue;
      $ids[] = $id;

      $pdf = isset($r['pdf_path']) ? (string)$r['pdf_path'] : '';
      if ($pdf){
        $full = wp_normalize_path(trailingslashit($u['basedir']) . ltrim($pdf, '/'));
        if (strpos($full, $baseDir) === 0 && file_exists($full)){
          @unlink($full);
        }
      }
    }

    if (!$ids) return array('ok' => true, 'deleted' => 0);

    // usuń eventy i oferty
    $placeholders = implode(',', array_fill(0, count($ids), '%d'));

    $sqlEv = "DELETE FROM {$t['events']} WHERE offer_id IN ($placeholders)";
    $argsEv = array_merge(array($sqlEv), $ids);
    $sqlEvP = call_user_func_array(array($wpdb, 'prepare'), $argsEv);
    $wpdb->query($sqlEvP);

    $sqlOff = "DELETE FROM {$t['offers']} WHERE id IN ($placeholders)";
    $argsOff = array_merge(array($sqlOff), $ids);
    $sqlOffP = call_user_func_array(array($wpdb, 'prepare'), $argsOff);
    $deleted = $wpdb->query($sqlOffP);
    if ($deleted === false) $deleted = 0;

    ZQOS_DB::log_event('offers_cleanup', null, null, array('deleted' => (int)$deleted, 'months' => (int)$months));

    return array('ok' => true, 'deleted' => (int)$deleted);
  }

  public static function storage_stats(){
    global $wpdb;
    $t = ZQOS_DB::tables();

    $offersTotal = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$t['offers']}");
    $offersWithPdf = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$t['offers']} WHERE pdf_path IS NOT NULL AND pdf_path <> ''");

    $u = wp_upload_dir();
    $dir = trailingslashit($u['basedir']) . 'zq-offer/pdfs';

    $bytes = 0;
    $files = 0;
    if (is_dir($dir)){
      $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
      foreach ($it as $f){
        /** @var SplFileInfo $f */
        if (!$f->isFile()) continue;
        $files++;
        $bytes += (int)$f->getSize();
        // bez przesady - limit na bardzo duże ilości plików
        if ($files > 20000) break;
      }
    }

    return array(
      'offers_total' => $offersTotal,
      'offers_with_pdf' => $offersWithPdf,
      'pdf_files' => $files,
      'pdf_bytes' => $bytes,
    );
  }
}
