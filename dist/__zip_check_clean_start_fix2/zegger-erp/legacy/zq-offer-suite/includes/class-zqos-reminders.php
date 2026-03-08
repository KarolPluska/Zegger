<?php
if (!defined('ABSPATH')) { exit; }

/**
 * ZQOS_Reminders
 * - System przypominania: po 72h bez edycji oferta przechodzi na status 'needs_update'
 *
 * Logika:
 * - Dotyczy statusów aktywnych: new, sent, in_progress
 * - Status systemowy: needs_update (nie do wyboru ręcznie)
 * - Statusy końcowe: won, lost, canceled - nie bierzemy pod uwagę w cron
 *
 * Wydajność:
 * - Cron uruchamia się co godzinę i przetwarza partie (limit) aby nie obciążać DB.
 */
final class ZQOS_Reminders {

  const CRON_HOOK = 'zqos_offer_reminders';
  const HOURS_STALE = 72;

  public static function init(){
    add_action(self::CRON_HOOK, array(__CLASS__, 'cron_run'));
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
    $next = wp_next_scheduled(self::CRON_HOOK);
    if ($next && !$force){
      return;
    }
    if ($next){
      self::deactivate();
    }

    // Co godzinę. Start za ~5 minut.
    wp_schedule_event(time() + 300, 'hourly', self::CRON_HOOK);
  }

  public static function cron_run(){
    // Batch 500 na przebieg - w razie dużej ilości ofert cron "dogoni" w kolejnych godzinach.
    self::mark_stale_offers_needs_update(500);
  }

  public static function mark_stale_offers_needs_update($limit = 500){
    global $wpdb;
    $t = ZQOS_DB::tables();

    $limit = (int)$limit;
    if ($limit < 1) $limit = 1;
    if ($limit > 2000) $limit = 2000;

    $nowTs = (int) current_time('timestamp');
    $cutTs = $nowTs - (int)(self::HOURS_STALE * HOUR_IN_SECONDS);
    $cut = wp_date('Y-m-d H:i:s', $cutTs);
    $now = wp_date('Y-m-d H:i:s', $nowTs);

    // Pobieramy tylko ID (i account_id do logów), żeby nie ciągnąć dużych payloadów.
    $rows = $wpdb->get_results($wpdb->prepare(
      "SELECT id, account_id, status
       FROM {$t['offers']}
       WHERE status IN ('new','sent','in_progress')
         AND updated_at < %s
       ORDER BY updated_at ASC, id ASC
       LIMIT %d",
      $cut, $limit
    ), ARRAY_A);

    if (!$rows) return array('ok'=>true, 'updated'=>0);

    $ids = array();
    foreach ($rows as $r){
      $id = (int)($r['id'] ?? 0);
      if ($id > 0) $ids[] = $id;
    }
    if (!$ids) return array('ok'=>true, 'updated'=>0);

    // Aktualizacja zbiorcza (bez pętli update)
    $placeholders = implode(',', array_fill(0, count($ids), '%d'));
    $sql = "UPDATE {$t['offers']}
            SET status = 'needs_update',
                status_updated_at = %s,
                updated_at = %s
            WHERE id IN ($placeholders)";
    $args = array_merge(array($sql, $now, $now), $ids);
    $sqlP = call_user_func_array(array($wpdb, 'prepare'), $args);
    $ok = $wpdb->query($sqlP);

    if ($ok === false){
      return array('ok'=>false, 'updated'=>0);
    }

    // Per-offer event (do historii zmian oferty) - systemowe oznaczenie jako 'needs_update'
    foreach ($rows as $r){
      $oid = (int)($r['id'] ?? 0);
      if ($oid <= 0) continue;
      $old = isset($r['status']) ? (string)$r['status'] : '';
      ZQOS_DB::log_event('offer_marked_needs_update', null, $oid, array(
        'old' => $old,
        'new' => 'needs_update',
        'reason' => 'stale_72h',
        'cut' => $cut,
        'system' => 1,
      ));
    }

    // Event agregowany (bez wypisywania ID w meta żeby nie puchło)
    ZQOS_DB::log_event('offers_marked_needs_update', null, null, array(
      'count' => (int)$ok,
      'cut' => $cut,
    ));

    return array('ok'=>true, 'updated'=>(int)$ok);
  }
}
