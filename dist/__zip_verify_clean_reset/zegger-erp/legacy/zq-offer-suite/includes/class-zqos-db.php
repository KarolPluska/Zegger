<?php
if (!defined('ABSPATH')) { exit; }

final class ZQOS_DB {
  const OPT_SETTINGS = 'zqos_settings';
  const OPT_BOOTSTRAP = 'zqos_bootstrap_creds';
  const OPT_DBVER = 'zqos_dbver';
  const DBVER = 117; // 1.2.15.0

  public static function init(){
    self::maybe_upgrade();
  }

  private static function maybe_upgrade(){
    $cur = (int) get_option(self::OPT_DBVER, 0);
    if ($cur >= self::DBVER) return;

    // Minimalny upgrade schema przez dbDelta (bez utraty danych)
    self::activate();
    update_option(self::OPT_DBVER, self::DBVER, false);
  }

  public static function tables(){
    global $wpdb;
    $p = $wpdb->prefix . 'zqos_';
    return array(
      'accounts' => $p . 'accounts',
      'tokens'   => $p . 'tokens',
      'clients'  => $p . 'clients',
      'acmap'    => $p . 'account_clients',
      'offers'   => $p . 'offers',
      'events'   => $p . 'events',
      'sheets'   => $p . 'sheets_cache',
    );
  }

  public static function activate(){
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset = $wpdb->get_charset_collate();
    $t = self::tables();

    $sql = array();

    $sql[] = "CREATE TABLE {$t['accounts']} (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      login VARCHAR(64) NOT NULL,
      pass_hash VARCHAR(255) NOT NULL,
      perms LONGTEXT NULL,
      fixed_client LONGTEXT NULL,
      profile LONGTEXT NULL,
      created_at DATETIME NOT NULL,
      updated_at DATETIME NOT NULL,
      PRIMARY KEY (id),
      UNIQUE KEY login (login)
    ) $charset;";

    $sql[] = "CREATE TABLE {$t['tokens']} (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      account_id BIGINT UNSIGNED NOT NULL,
      actor_account_id BIGINT UNSIGNED NULL,
      token_hash CHAR(64) NOT NULL,
      created_at DATETIME NOT NULL,
      expires_at DATETIME NOT NULL,
      last_seen DATETIME NULL,
      ip VARCHAR(64) NULL,
      ua VARCHAR(255) NULL,
      PRIMARY KEY (id),
      KEY account_id (account_id),
      KEY actor_account_id (actor_account_id),
      UNIQUE KEY token_hash (token_hash)
    ) $charset;";

    $sql[] = "CREATE TABLE {$t['clients']} (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      full_name VARCHAR(120) NULL,
      company VARCHAR(160) NULL,
      nip VARCHAR(32) NULL,
      phone VARCHAR(64) NULL,
      email VARCHAR(190) NULL,
      address TEXT NULL,
      created_at DATETIME NOT NULL,
      updated_at DATETIME NOT NULL,
      PRIMARY KEY (id)
    ) $charset;";

    $sql[] = "CREATE TABLE {$t['acmap']} (
      account_id BIGINT UNSIGNED NOT NULL,
      client_id BIGINT UNSIGNED NOT NULL,
      PRIMARY KEY (account_id, client_id),
      KEY client_id (client_id)
    ) $charset;";

    $sql[] = "CREATE TABLE {$t['offers']} (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      account_id BIGINT UNSIGNED NOT NULL,
      title VARCHAR(220) NOT NULL,
      title_norm VARCHAR(220) NOT NULL,
      dedupe_hash CHAR(64) NULL,
      status VARCHAR(32) NOT NULL DEFAULT 'unset',
      status_updated_at DATETIME NULL,
      comment TEXT NULL,
      sales_note TEXT NULL,
      data LONGTEXT NULL,
      pdf_path TEXT NULL,
      locked TINYINT UNSIGNED NOT NULL DEFAULT 0,
      locked_at DATETIME NULL,
      locked_by BIGINT UNSIGNED NULL,
      lock_reason VARCHAR(32) NULL,
      created_at DATETIME NOT NULL,
      updated_at DATETIME NOT NULL,
      PRIMARY KEY (id),
      KEY account_id (account_id),
      KEY title_norm (title_norm),
      KEY dedupe_hash (dedupe_hash),
      KEY status (status)
    ) $charset;";

    $sql[] = "CREATE TABLE {$t['events']} (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      account_id BIGINT UNSIGNED NULL,
      offer_id BIGINT UNSIGNED NULL,
      event VARCHAR(64) NOT NULL,
      meta LONGTEXT NULL,
      created_at DATETIME NOT NULL,
      PRIMARY KEY (id),
      KEY account_id (account_id),
      KEY offer_id (offer_id),
      KEY event (event)
    ) $charset;";

    // Cache w DB (opcjonalnie) - trzymamy ostatnią synchronizację
    $sql[] = "CREATE TABLE {$t['sheets']} (
      id TINYINT UNSIGNED NOT NULL,
      cache LONGTEXT NULL,
      fetched_at DATETIME NULL,
      updated_at DATETIME NOT NULL,
      PRIMARY KEY (id)
    ) $charset;";

    foreach ($sql as $q){
      dbDelta($q);
    }

    self::ensure_default_settings();
    if (!defined('ZERP_VERSION')) {
      self::ensure_bootstrap_account();
    }

    
    // v1.2.15.0 - auto-lock dla ofert ze statusem końcowym (best-effort, bez zmiany daty aktualizacji)
    // Jeżeli oferta miała już finalny status przed aktualizacją, domyślnie blokujemy ją przed edycją.
    try{
      $wpdb->query("UPDATE {$t['offers']} SET locked = 1, lock_reason = 'final_status' WHERE locked = 0 AND status IN ('won','lost')");
    }catch (\Throwable $e){
      // cisza - schema może być jeszcze w trakcie aktualizacji na niektórych środowiskach
    }

    // v117: status 'sent' nie jest statusem finalnym - odblokuj auto-locki z poprzednich wersji
    try{
      $wpdb->query("UPDATE {$t['offers']} SET locked = 0, lock_reason = NULL, locked_at = NULL, locked_by = NULL WHERE status = 'sent' AND lock_reason = 'final_status'");
    }catch (\Throwable $e){
      // cisza
    }

// zapisz wersję schematu
    update_option(self::OPT_DBVER, self::DBVER, false);
  }

  public static function ensure_default_settings(){
    $defaults = array(
      'sheet_pub_id' => '2PACX-1vSbOJxcV--Wjj_SvLZIkrmo1TfxNiHI2ytfWOtmkTaucRUh-lx3p28Vx89SIrfrJHn2mV2SijYDlVxh',
      'tabs' => array(
        array('name' => 'Ogrodzenia Panelowe',     'gid' => '213752737'),
        array('name' => 'Ogrodzenia Palisadowe',   'gid' => '1070814720'),
        array('name' => 'Słupki',                  'gid' => '1126111579'),
        array('name' => 'Akcesoria',               'gid' => '650876174'),
      ),
      'sync_interval_minutes' => 10,
      'vat_rate' => 0.23,
      'sheets_public' => 0,

      // Anty-duplikacja eksportu PDF
      'export_dedupe_seconds' => 15,

      // Retencja (cleanup ofert/PDF)
      'retention_enabled' => 0,
      'retention_months' => 12,

      // Ostrzeżenie o rozmiarze storage
      'storage_warn_mb' => 512,
    );

    $cur = get_option(self::OPT_SETTINGS, null);
    if (!is_array($cur)) {
      add_option(self::OPT_SETTINGS, $defaults, '', false);
      return;
    }

    // Merge nieinwazyjny: tylko brakujące klucze.
    $merged = $cur;
    foreach ($defaults as $k => $v){
      if (!array_key_exists($k, $merged)){
        $merged[$k] = $v;
      }
    }
    if ($merged !== $cur){
      update_option(self::OPT_SETTINGS, $merged, false);
    }
  }

  public static function settings(){
    $s = get_option(self::OPT_SETTINGS, array());
    return is_array($s) ? $s : array();
  }

  public static function update_settings($patch){
    $s = self::settings();
    if (!is_array($patch)) $patch = array();
    $out = array_merge($s, $patch);
    update_option(self::OPT_SETTINGS, $out, false);
    return $out;
  }

  private static function ensure_bootstrap_account(){
    global $wpdb;
    $t = self::tables();
    $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t['accounts']}");
    if ($count > 0) return;

    $login = 'admin';
    $pass = wp_generate_password(14, true, true);

    $now = current_time('mysql');
    $perms = array(
      'can_view_all_clients' => true,
      'can_force_sync' => true,
      'can_view_stats' => true,
      'super_admin' => true,
      'can_delete_offers_any' => true,
      'can_lock_offers' => true,
      'allow_special_offer' => true,
      'max_discount_percent' => 100,
      'allowed_tabs' => array(),
      'seller' => array(
        'name' => 'Administrator',
        'phone' => '',
        'email' => '',
        'branch' => ''
      ),
    );

    $wpdb->insert($t['accounts'], array(
      'login' => $login,
      'pass_hash' => password_hash($pass, PASSWORD_DEFAULT),
      'perms' => wp_json_encode($perms),
      'fixed_client' => null,
      'created_at' => $now,
      'updated_at' => $now,
    ), array('%s','%s','%s','%s','%s','%s'));

    update_option(self::OPT_BOOTSTRAP, array(
      'login' => $login,
      'pass' => $pass,
      'created_at' => $now,
    ), false);
  }

    public static function log_event($event, $account_id = null, $offer_id = null, $meta = null){
    global $wpdb;
    $t = self::tables();
    $now = current_time('mysql');

    // Meta powinno być tablicą. Jeśli ktoś poda scalar - opakuj (stabilność).
    if ($meta !== null && !is_array($meta)){
      $meta = array('_value' => (string)$meta);
    }

    // Best-effort: dopnij informacje o AKTORZE (Super Admin / impersonacja), żeby historia zmian była audytowalna.
    // Nie jest to krytyczne - przy braku kontekstu (np. cron/CLI) po prostu nie dopinamy danych.
    try{
      if (class_exists('ZQOS_Auth')){
        $cur = ZQOS_Auth::current_account();
        if (is_array($cur) && !empty($cur['actor_id'])){
          $actorId = (int)$cur['actor_id'];
          if ($actorId > 0){
            if ($meta === null) $meta = array();
            if (!isset($meta['_actor_id'])) $meta['_actor_id'] = $actorId;

            if (!isset($meta['_actor_login'])){
              $login = $wpdb->get_var($wpdb->prepare(
                "SELECT login FROM {$t['accounts']} WHERE id = %d LIMIT 1",
                $actorId
              ));
              if ($login) $meta['_actor_login'] = (string)$login;
            }
          }
        }
      }
    }catch (\Throwable $e){
      // cisza
    }

    $wpdb->insert($t['events'], array(
      'account_id' => $account_id ? (int)$account_id : null,
      'offer_id' => $offer_id ? (int)$offer_id : null,
      'event' => sanitize_key($event),
      'meta' => $meta ? wp_json_encode($meta) : null,
      'created_at' => $now,
    ), array('%d','%d','%s','%s','%s'));
  }
}
