<?php
if (!defined('ABSPATH')) { exit; }

final class ZQOS_Admin {

  public static function init(){
    if (is_admin()){
      add_action('admin_menu', array(__CLASS__, 'menu'));
      add_action('admin_notices', array(__CLASS__, 'bootstrap_notice'));
      add_action('admin_post_zqos_save_settings', array(__CLASS__, 'handle_save_settings'));
      add_action('admin_post_zqos_create_account', array(__CLASS__, 'handle_create_account'));
      add_action('admin_post_zqos_reset_account_pass', array(__CLASS__, 'handle_reset_account_pass'));
      add_action('admin_post_zqos_update_account', array(__CLASS__, 'handle_update_account'));
      add_action('admin_post_zqos_delete_account', array(__CLASS__, 'handle_delete_account'));
      add_action('admin_post_zqos_create_client', array(__CLASS__, 'handle_create_client'));
      add_action('admin_post_zqos_delete_client', array(__CLASS__, 'handle_delete_client'));
      add_action('admin_post_zqos_assign_client', array(__CLASS__, 'handle_assign_client'));
      add_action('admin_post_zqos_sync_now', array(__CLASS__, 'handle_sync_now'));
      add_action('admin_post_zqos_download_offer_pdf', array(__CLASS__, 'handle_admin_download_pdf'));
      add_action('admin_post_zqos_delete_offers', array(__CLASS__, 'handle_delete_offers'));
    }
  }

  public static function menu(){
    add_menu_page(
      'Panel ofertowy',
      'Panel ofertowy',
      'manage_options',
      'zqos',
      array(__CLASS__, 'page_dashboard'),
      'dashicons-clipboard',
      58
    );

    add_submenu_page('zqos', 'Ustawienia', 'Ustawienia', 'manage_options', 'zqos-settings', array(__CLASS__, 'page_settings'));
    add_submenu_page('zqos', 'Konta', 'Konta', 'manage_options', 'zqos-accounts', array(__CLASS__, 'page_accounts'));
    add_submenu_page('zqos', 'Klienci', 'Klienci', 'manage_options', 'zqos-clients', array(__CLASS__, 'page_clients'));
    add_submenu_page('zqos', 'Oferty', 'Oferty', 'manage_options', 'zqos-offers', array(__CLASS__, 'page_offers'));
    add_submenu_page('zqos', 'Statystyki', 'Statystyki', 'manage_options', 'zqos-stats', array(__CLASS__, 'page_stats'));
  }

  public static function bootstrap_notice(){
    if (!current_user_can('manage_options')) return;
    $creds = get_option(ZQOS_DB::OPT_BOOTSTRAP, null);
    if (!is_array($creds) || empty($creds['login']) || empty($creds['pass'])) return;

    echo '<div class="notice notice-warning"><p><strong>ZQ Offer Suite:</strong> Utworzono konto startowe dla panelu ofertowego: ';
    echo 'login <code>' . esc_html($creds['login']) . '</code>, hasło <code>' . esc_html($creds['pass']) . '</code>. ';
    echo 'Po utworzeniu własnych kont usuń/zmień hasło dla tego konta. (Info wyświetlane do czasu usunięcia tej noty w Ustawieniach.)</p></div>';
  }

  private static function admin_url_page($slug){
    return admin_url('admin.php?page=' . $slug);
  }

  private static function render_cron_status_box(){
    $hook = ZQOS_Sheets::CRON_HOOK;
    $nowTs = time();
    $now = current_time('mysql');
    $nextTs = wp_next_scheduled($hook);

    $disable = (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON);
    $alt = (defined('ALTERNATE_WP_CRON') && ALTERNATE_WP_CRON);

    $schedule = '';
    if ($nextTs){
      $events = _get_cron_array();
      if (is_array($events) && isset($events[$nextTs][$hook])){
        foreach ($events[$nextTs][$hook] as $evt){
          if (!empty($evt['schedule'])){ $schedule = (string)$evt['schedule']; break; }
        }
      }
    }

    echo '<h3>Status automatycznej synchronizacji (WP-Cron)</h3>';
    echo '<table class="widefat striped" style="max-width:860px">';
    echo '<tbody>';
    echo '<tr><th style="width:240px">Aktualny czas (serwer WP)</th><td><code>' . esc_html($now) . '</code></td></tr>';
    echo '<tr><th>Hook</th><td><code>' . esc_html($hook) . '</code></td></tr>';
    echo '<tr><th>Następne uruchomienie</th><td>';
    if ($nextTs){
      echo '<code>' . esc_html(date_i18n('Y-m-d H:i:s', $nextTs)) . '</code>';
      if ($schedule){
        echo ' <span style="opacity:.8">(schedule: <code>' . esc_html($schedule) . '</code>)</span>';
      }
      $delta = $nextTs - $nowTs;
      echo ' <span style="opacity:.8">(za ok. ' . esc_html((string)max(-999999, $delta)) . 's)</span>';
    } else {
      echo '<strong style="color:#b32d2e">brak zaplanowanego eventu</strong>';
    }
    echo '</td></tr>';
    echo '<tr><th>DISABLE_WP_CRON</th><td>' . ($disable ? '<strong style="color:#b32d2e">true</strong> (WP-Cron wyłączony)' : '<strong style="color:#1e8e3e">false</strong>') . '</td></tr>';
    echo '<tr><th>ALTERNATE_WP_CRON</th><td>' . ($alt ? '<strong style="color:#1e8e3e">true</strong>' : '<strong style="color:#555">false</strong>') . '</td></tr>';
    echo '</tbody>';
    echo '</table>';

    if ($disable || !$nextTs){
      $cronUrl = home_url('/wp-cron.php?doing_wp_cron=1');
      $phpCmd = 'php -q ' . rtrim(ABSPATH, '/\\') . '/wp-cron.php >/dev/null 2>&1';
      $curlCmd = 'curl -sS ' . $cronUrl . ' >/dev/null 2>&1';
      echo '<div class="notice notice-warning" style="max-width:860px;margin-top:12px">';
      echo '<p><strong>Auto-sync nie zadziała</strong>, jeśli WP-Cron jest wyłączony lub nie ma zaplanowanego eventu. Najpewniejsze rozwiązanie to systemowy CRON na serwerze, odpalany co 1 minutę:</p>';
      echo '<p><code>' . esc_html($phpCmd) . '</code><br><span style="opacity:.8">lub</span><br><code>' . esc_html($curlCmd) . '</code></p>';
      echo '<p style="margin-bottom:0">Po ustawieniu CRON-a ustaw w Ustawieniach wtyczki interwał 1/5/10/15 min - WordPress wykona sync, gdy event będzie "due".</p>';
      echo '</div>';
    }
  }

  public static function page_dashboard(){
    if (!current_user_can('manage_options')) return;

    $cache = ZQOS_Sheets::get_cache();
    $meta = $cache ? array(
      'fetched_at' => $cache['fetched_at'] ?? '',
      'duration_ms' => $cache['duration_ms'] ?? '',
      'errors' => $cache['errors'] ?? array(),
    ) : null;

    echo '<div class="wrap"><h1>Panel ofertowy - Dashboard</h1>';

    echo '<p><a class="button button-primary" href="' . esc_url(self::admin_url_page('zqos-settings')) . '">Ustawienia</a> ';
    echo '<a class="button" href="' . esc_url(self::admin_url_page('zqos-accounts')) . '">Konta</a> ';
    echo '<a class="button" href="' . esc_url(self::admin_url_page('zqos-clients')) . '">Klienci</a> ';
    echo '<a class="button" href="' . esc_url(self::admin_url_page('zqos-offers')) . '">Oferty</a></p>';

    echo '<h2>Synchronizacja arkusza</h2>';
    if ($meta){
      echo '<p>Ostatnia synchronizacja: <code>' . esc_html($meta['fetched_at']) . '</code> ('
        . esc_html((string)$meta['duration_ms']) . ' ms)</p>';
      if (!empty($meta['errors'])){
        echo '<div class="notice notice-error"><p><strong>Błędy synchronizacji:</strong><br>' . esc_html(implode("\n", $meta['errors'])) . '</p></div>';
      } else {
        echo '<div class="notice notice-success"><p>Cache OK.</p></div>';
      }
    } else {
      echo '<div class="notice notice-warning"><p>Brak cache. Uruchom synchronizację ręcznie.</p></div>';
    }

    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field('zqos_sync_now');
    echo '<input type="hidden" name="action" value="zqos_sync_now">';
    submit_button('Synchronizuj teraz (ręcznie)');
    echo '</form>';

    self::render_cron_status_box();

    echo '<h2>URL panelu (do iframe)</h2>';
    $panelUrl = add_query_arg(array('zq_offer_panel' => '1', 'embed' => '1'), home_url('/'));
    echo '<p><code>' . esc_html($panelUrl) . '</code></p>';

    echo '<h2>Storage (oferty / PDF)</h2>';
    $stats = ZQOS_Maintenance::storage_stats();
    $mb = (int) floor(((int)($stats['pdf_bytes'] ?? 0)) / (1024 * 1024));
    echo '<p>Oferty: <strong>' . esc_html((string)($stats['offers_total'] ?? 0)) . '</strong>, z PDF: <strong>' . esc_html((string)($stats['offers_with_pdf'] ?? 0)) . '</strong>, pliki PDF: <strong>' . esc_html((string)($stats['pdf_files'] ?? 0)) . '</strong>, rozmiar: <strong>' . esc_html((string)$mb) . ' MB</strong>.</p>';

    $s = ZQOS_DB::settings();
    $warn = (int)($s['storage_warn_mb'] ?? 512);
    if ($warn > 0 && $mb >= $warn){
      echo '<div class="notice notice-warning"><p><strong>Uwaga:</strong> Rozmiar PDF przekroczył próg ' . esc_html((string)$warn) . ' MB. Rozważ retencję lub ręczne czyszczenie ofert.</p></div>';
    }

    echo '</div>';
  }

  public static function page_settings(){
    if (!current_user_can('manage_options')) return;
    $s = ZQOS_DB::settings();
    $tabs = isset($s['tabs']) && is_array($s['tabs']) ? $s['tabs'] : array();

    echo '<div class="wrap"><h1>Ustawienia - Panel ofertowy</h1>';

    self::render_cron_status_box();

    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field('zqos_save_settings');
    echo '<input type="hidden" name="action" value="zqos_save_settings">';

    echo '<table class="form-table" role="presentation"><tbody>';

    echo '<tr><th scope="row"><label for="sheet_pub_id">sheet_pub_id</label></th><td>';
    echo '<input name="sheet_pub_id" id="sheet_pub_id" type="text" class="regular-text" value="' . esc_attr($s['sheet_pub_id'] ?? '') . '">';
    echo '<p class="description">ID publikacji arkusza (część URL /d/e/.../).</p>';
    echo '</td></tr>';

    echo '<tr><th scope="row">Interwał sync</th><td>';
    $mins = (int)($s['sync_interval_minutes'] ?? 10);
    echo '<select name="sync_interval_minutes">';
    echo '<option value="1"' . selected($mins, 1, false) . '>1 minuta</option>';
    echo '<option value="5"' . selected($mins, 5, false) . '>5 minut</option>';
    echo '<option value="10"' . selected($mins, 10, false) . '>10 minut</option>';
    echo '<option value="15"' . selected($mins, 15, false) . '>15 minut</option>';
    echo '</select>';
    echo '</td></tr>';

    echo '<tr><th scope="row">VAT</th><td>';
    echo '<input name="vat_rate" type="number" step="0.01" min="0" max="0.99" value="' . esc_attr((string)($s['vat_rate'] ?? 0.23)) . '">';
    echo '</td></tr>';

    echo '<tr><th scope="row">Sheets public</th><td>';
    echo '<label><input type="checkbox" name="sheets_public" value="1"' . checked(!empty($s['sheets_public']), true, false) . '> Pozwól pobierać arkusz bez logowania (niezalecane)</label>';
    echo '</td></tr>';

    // Sesje / bezpieczeństwo
    echo '<tr><th scope="row">Sesja (token)</th><td>';
    $hours = (int)($s['session_hours'] ?? 12);
    if ($hours < 1) $hours = 1;
    if ($hours > 168) $hours = 168;
    echo '<label>Wygasanie po <input name="session_hours" type="number" min="1" max="168" step="1" value="' . esc_attr((string)$hours) . '" style="width:90px"> godzin</label>';
    echo '<p class="description">Po tym czasie wymagane jest ponowne logowanie.</p>';
    echo '</td></tr>';

    echo '<tr><th scope="row">Limit aktywnych sesji</th><td>';
    $maxTok = (int)($s['max_active_tokens_per_account'] ?? 3);
    if ($maxTok < 1) $maxTok = 1;
    if ($maxTok > 20) $maxTok = 20;
    echo '<label>Maks. tokenów na konto: <input name="max_active_tokens_per_account" type="number" min="1" max="20" step="1" value="' . esc_attr((string)$maxTok) . '" style="width:90px"></label>';
    echo '<p class="description">Przy logowaniu nadmiarowe tokeny są automatycznie usuwane (najstarszy/nieaktywny).</p>';
    echo '</td></tr>';

    echo '<tr><th scope="row">Rate limit logowania</th><td>';
    $rlA = (int)($s['login_rate_attempts'] ?? 10);
    $rlW = (int)($s['login_rate_window_minutes'] ?? 10);
    if ($rlA < 3) $rlA = 3;
    if ($rlA > 50) $rlA = 50;
    if ($rlW < 1) $rlW = 1;
    if ($rlW > 120) $rlW = 120;
    echo '<label>Max <input name="login_rate_attempts" type="number" min="3" max="50" step="1" value="' . esc_attr((string)$rlA) . '" style="width:90px"> prób w <input name="login_rate_window_minutes" type="number" min="1" max="120" step="1" value="' . esc_attr((string)$rlW) . '" style="width:90px"> minut (login+IP)</label>';
    echo '<p class="description">Nieudane logowania są logowane w zdarzeniach (events).</p>';
    echo '</td></tr>';


    // Anti-duplicate export
    echo '<tr><th scope="row">Eksport - anty-duplikacja</th><td>';
    $dedupe = (int)($s['export_dedupe_seconds'] ?? 15);
    if ($dedupe < 0) $dedupe = 0;
    if ($dedupe > 60) $dedupe = 60;
    echo '<label>Czas (sekundy): <input name="export_dedupe_seconds" type="number" min="0" max="60" step="1" value="' . esc_attr((string)$dedupe) . '" style="width:90px"></label>';
    echo '<p class="description">0 = wyłączone. Zalecane 10-15 sekund (ochrona przed podwójnym kliknięciem eksportu).</p>';
    echo '</td></tr>';

    // Retencja
    echo '<tr><th scope="row">Retencja ofert/PDF</th><td>';
    $retOn = !empty($s['retention_enabled']);
    $retMonths = (int)($s['retention_months'] ?? 12);
    if ($retMonths < 1) $retMonths = 1;
    if ($retMonths > 120) $retMonths = 120;
    echo '<label><input type="checkbox" name="retention_enabled" value="1"' . checked($retOn, true, false) . '> Włącz automatyczne czyszczenie (cron)</label><br>';
    echo '<label>Usuń starsze niż <input name="retention_months" type="number" min="1" max="120" step="1" value="' . esc_attr((string)$retMonths) . '" style="width:90px"> miesięcy</label>';
    echo '<p class="description">Cron uruchamia się raz dziennie. Usuwa rekord oferty + PDF + zdarzenia powiązane.</p>';
    echo '</td></tr>';

    // Storage warning
    echo '<tr><th scope="row">Ostrzeżenie o rozmiarze storage</th><td>';
    $warn = (int)($s['storage_warn_mb'] ?? 512);
    if ($warn < 0) $warn = 0;
    if ($warn > 50000) $warn = 50000;
    echo '<label>Próg (MB): <input name="storage_warn_mb" type="number" min="0" max="50000" step="1" value="' . esc_attr((string)$warn) . '" style="width:110px"></label>';
    echo '<p class="description">0 = wyłączone. Ostrzeżenie pojawi się na Dashboardzie.</p>';
    echo '</td></tr>';

    echo '</tbody></table>';

    echo '<h2>Zakładki (tabs)</h2>';
    echo '<table class="widefat striped"><thead><tr><th>Nazwa</th><th>GID</th></tr></thead><tbody>';
    for ($i=0; $i<4; $i++){
      $name = $tabs[$i]['name'] ?? '';
      $gid = $tabs[$i]['gid'] ?? '';
      echo '<tr><td><input type="text" name="tabs[' . $i . '][name]" value="' . esc_attr($name) . '" class="regular-text"></td>';
      echo '<td><input type="text" name="tabs[' . $i . '][gid]" value="' . esc_attr($gid) . '" class="regular-text"></td></tr>';
    }
    echo '</tbody></table>';

    submit_button('Zapisz ustawienia');
    echo '</form>';

    echo '<h2>Usuń notę konta startowego</h2>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field('zqos_save_settings');
    echo '<input type="hidden" name="action" value="zqos_save_settings">';
    echo '<input type="hidden" name="clear_bootstrap" value="1">';
    submit_button('Ukryj notę (nie usuwa konta)', 'secondary');
    echo '</form>';

    echo '</div>';
  }

  public static function handle_save_settings(){
    if (!current_user_can('manage_options')) wp_die('Forbidden');
    check_admin_referer('zqos_save_settings');

    if (!empty($_POST['clear_bootstrap'])){
      delete_option(ZQOS_DB::OPT_BOOTSTRAP);
      wp_safe_redirect(self::admin_url_page('zqos-settings'));
      exit;
    }

    $pub = isset($_POST['sheet_pub_id']) ? sanitize_text_field(wp_unslash($_POST['sheet_pub_id'])) : '';
    $mins = isset($_POST['sync_interval_minutes']) ? (int)$_POST['sync_interval_minutes'] : 10;
    $allowed = array(1,5,10,15);
    if (!in_array($mins, $allowed, true)) { $mins = 10; }

    $vat = isset($_POST['vat_rate']) ? (float)$_POST['vat_rate'] : 0.23;
    if ($vat < 0) $vat = 0;
    if ($vat > 0.99) $vat = 0.99;

    $tabsIn = isset($_POST['tabs']) && is_array($_POST['tabs']) ? $_POST['tabs'] : array();
    $tabs = array();
    foreach ($tabsIn as $tab){
      $tabs[] = array(
        'name' => isset($tab['name']) ? sanitize_text_field(wp_unslash($tab['name'])) : '',
        'gid'  => isset($tab['gid']) ? sanitize_text_field(wp_unslash($tab['gid'])) : '',
      );
    }
    // gwarantuj 4
    while (count($tabs) < 4){
      $tabs[] = array('name' => '', 'gid' => '');
    }

    $sheets_public = !empty($_POST['sheets_public']) ? 1 : 0;

    $dedupe = isset($_POST['export_dedupe_seconds']) ? (int)$_POST['export_dedupe_seconds'] : 15;
    if ($dedupe < 0) $dedupe = 0;
    if ($dedupe > 60) $dedupe = 60;

    $retOn = !empty($_POST['retention_enabled']) ? 1 : 0;
    $retMonths = isset($_POST['retention_months']) ? (int)$_POST['retention_months'] : 12;
    if ($retMonths < 1) $retMonths = 1;
    if ($retMonths > 120) $retMonths = 120;

    $warn = isset($_POST['storage_warn_mb']) ? (int)$_POST['storage_warn_mb'] : 512;
    if ($warn < 0) $warn = 0;
    if ($warn > 50000) $warn = 50000;

    ZQOS_DB::update_settings(array(
      'sheet_pub_id' => $pub,
      'sync_interval_minutes' => $mins,
      'vat_rate' => $vat,
      'tabs' => $tabs,
      'sheets_public' => $sheets_public,

      'export_dedupe_seconds' => $dedupe,
      'retention_enabled' => $retOn,
      'retention_months' => $retMonths,
      'storage_warn_mb' => $warn,

      'session_hours' => isset($_POST['session_hours']) ? max(1, min(168, (int)$_POST['session_hours'])) : 12,
      'max_active_tokens_per_account' => isset($_POST['max_active_tokens_per_account']) ? max(1, min(20, (int)$_POST['max_active_tokens_per_account'])) : 3,
      'login_rate_attempts' => isset($_POST['login_rate_attempts']) ? max(3, min(50, (int)$_POST['login_rate_attempts'])) : 10,
      'login_rate_window_minutes' => isset($_POST['login_rate_window_minutes']) ? max(1, min(120, (int)$_POST['login_rate_window_minutes'])) : 10,
    ));

    wp_safe_redirect(self::admin_url_page('zqos-settings') . '&updated=1');
    exit;
  }

  public static function page_accounts(){
    if (!current_user_can('manage_options')) return;
    global $wpdb;
    $t = ZQOS_DB::tables();

    $rows = $wpdb->get_results("SELECT id, login, perms, created_at FROM {$t['accounts']} ORDER BY id DESC LIMIT 200", ARRAY_A);

    echo '<div class="wrap"><h1>Konta</h1>';
    echo '<h2>Dodaj konto</h2>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field('zqos_create_account');
    echo '<input type="hidden" name="action" value="zqos_create_account">';
    echo '<table class="form-table"><tbody>';
    echo '<tr><th>Login</th><td><input name="login" type="text" class="regular-text" required></td></tr>';
    echo '<tr><th>Hasło</th><td><input name="password" type="text" class="regular-text" required></td></tr>';
    echo '<tr><th>Uprawnienia</th><td>';
    echo '<label><input type="checkbox" name="can_view_all_clients" value="1"> Wszyscy klienci</label><br>';
    echo '<label><input type="checkbox" name="can_force_sync" value="1"> Wymuś sync</label><br>';
    echo '<label><input type="checkbox" name="can_view_stats" value="1"> Statystyki</label><br>';
    echo '<label><input type="checkbox" name="super_admin" value="1"> Super Admin (przełączanie kont)</label><br>';
    echo '<input type="hidden" name="can_select_client" value="0">';
    echo '<label><input type="checkbox" name="can_select_client" value="1" checked> Wybór klienta z bazy</label><br>';
    echo '<input type="hidden" name="can_add_client" value="0">';
    echo '<label><input type="checkbox" name="can_add_client" value="1" checked> Dodawanie klientów</label><br>';
    echo '<input type="hidden" name="can_edit_client" value="0">';
    echo '<label><input type="checkbox" name="can_edit_client" value="1"> Edycja danych klienta</label>';
    echo '</td></tr>';

    echo '<tr><th>Kontrola rabatów</th><td>';
    echo '<label><input type="checkbox" name="allow_special_offer" value="1" checked> Pozwól na "Oferta specjalna" (ręczna cena)</label><br>';
    echo '<label>Maks. rabat %: <input name="max_discount_percent" type="number" min="0" max="100" step="0.01" value="100" style="width:120px"></label>';
    echo '<p class="description">Jeśli ustawisz np. 10 - panel i backend zablokuje większy rabat.</p>';
    echo '</td></tr>';

    // Allowed tabs
    $s = ZQOS_DB::settings();
    $tabsCfg = isset($s['tabs']) && is_array($s['tabs']) ? $s['tabs'] : array();
    $tabNames = array();
    foreach ($tabsCfg as $tb){ $nm = isset($tb['name']) ? trim((string)$tb['name']) : ''; if ($nm) $tabNames[] = $nm; }
    if (!$tabNames){ $tabNames = array('Ogrodzenia Panelowe','Ogrodzenia Palisadowe','Słupki','Akcesoria'); }

    echo '<tr><th>Dostępne kategorie</th><td>';
    echo '<p class="description">Jeśli nie zaznaczysz nic - konto widzi wszystkie kategorie.</p>';
    foreach ($tabNames as $nm){
      echo '<label style="display:inline-block;margin-right:14px;"><input type="checkbox" name="allowed_tabs[]" value="' . esc_attr($nm) . '"> ' . esc_html($nm) . '</label>';
    }
    echo '</td></tr>';

    echo '<tr><th>Dane sprzedawcy (PDF)</th><td>';
    echo '<label>Imię i nazwisko: <input name="seller_name" type="text" class="regular-text" value=""></label><br>';
    echo '<label>Telefon: <input name="seller_phone" type="text" class="regular-text" value=""></label><br>';
    echo '<label>Email: <input name="seller_email" type="email" class="regular-text" value=""></label><br>';
    echo '<label>Oddział: <input name="seller_branch" type="text" class="regular-text" value=""></label>';
    echo '</td></tr>';

    echo '<tr><th>Kasowanie ofert</th><td>';
    echo '<label><input type="checkbox" name="can_delete_offers_own" value="1" checked> Może kasować swoje</label><br>';
    echo '<label><input type="checkbox" name="can_delete_offers_any" value="1"> Może kasować wszystkie (admin)</label>';
    echo '</td></tr>';

    
    echo '<tr><th>Blokowanie ofert</th><td>';
    echo '<label><input type="checkbox" name="can_lock_offers" value="1"> Może blokować/odblokowywać oferty</label>';
    echo '</td></tr>';
echo '</tbody></table>';
    submit_button('Utwórz konto');
    echo '</form>';

    echo '<h2>Lista kont</h2>';
    echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Login</th><th>Uprawnienia</th><th>Data</th><th>Akcje</th></tr></thead><tbody>';
    foreach ($rows as $r){
      $perms = json_decode((string)($r['perms'] ?? ''), true);
      if (!is_array($perms)) $perms = array();
      $p = array();
      if (!empty($perms['can_view_all_clients'])) $p[] = 'wszyscy_klienci';
      if (!empty($perms['can_force_sync'])) $p[] = 'force_sync';
      if (!empty($perms['can_view_stats'])) $p[] = 'stats';
      if (!empty($perms['super_admin'])) $p[] = 'super_admin';
      $canSelClient = array_key_exists('can_select_client', $perms) ? !empty($perms['can_select_client']) : true;
      $canAddClient = array_key_exists('can_add_client', $perms) ? !empty($perms['can_add_client']) : true;
      $canEditClient = array_key_exists('can_edit_client', $perms) ? !empty($perms['can_edit_client']) : (!empty($perms['can_view_all_clients']));
      if (!$canSelClient) $p[] = 'no_client_select';
      if (!$canAddClient) $p[] = 'no_client_add';
      if (!$canEditClient) $p[] = 'no_client_edit';
      if (array_key_exists('allow_special_offer', $perms) && empty($perms['allow_special_offer'])) $p[] = 'no_special';
      if (isset($perms['max_discount_percent'])) $p[] = 'max_disc=' . (float)$perms['max_discount_percent'];
      if (!empty($perms['allowed_tabs']) && is_array($perms['allowed_tabs'])) $p[] = 'tabs=' . count($perms['allowed_tabs']);
      if (!empty($perms['can_delete_offers_any'])) $p[] = 'del_any';
      else if (!empty($perms['can_delete_offers_own'])) $p[] = 'del_own';
      if (!empty($perms['can_lock_offers'])) $p[] = 'lock';

      echo '<tr>';
      echo '<td>' . esc_html((string)$r['id']) . '</td>';
      echo '<td><code>' . esc_html((string)$r['login']) . '</code></td>';
      echo '<td>' . esc_html(implode(', ', $p)) . '</td>';
      echo '<td>' . esc_html((string)$r['created_at']) . '</td>';
      echo '<td>';

      echo '<details style="display:inline-block;margin-right:10px;"><summary class="button">Edytuj</summary>';
      echo '<div style="padding:10px 0;">';
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
      wp_nonce_field('zqos_update_account');
      echo '<input type="hidden" name="action" value="zqos_update_account">';
      echo '<input type="hidden" name="id" value="' . esc_attr((string)$r['id']) . '">';

      echo '<p><label><input type="checkbox" name="can_view_all_clients" value="1"' . checked(!empty($perms['can_view_all_clients']), true, false) . '> Wszyscy klienci</label><br>';
      echo '<label><input type="checkbox" name="can_force_sync" value="1"' . checked(!empty($perms['can_force_sync']), true, false) . '> Wymuś sync</label><br>';
      echo '<label><input type="checkbox" name="can_view_stats" value="1"' . checked(!empty($perms['can_view_stats']), true, false) . '> Statystyki</label><br>';
      echo '<label><input type="checkbox" name="super_admin" value="1"' . checked(!empty($perms['super_admin']), true, false) . '> Super Admin (przełączanie kont)</label><br>';

      // v1.2.5 - domyślnie TRUE jeśli klucz nie istnieje (stare konta)
      $canSelClient = array_key_exists('can_select_client', $perms) ? !empty($perms['can_select_client']) : true;
      $canAddClient = array_key_exists('can_add_client', $perms) ? !empty($perms['can_add_client']) : true;
      $canEditClient = array_key_exists('can_edit_client', $perms) ? !empty($perms['can_edit_client']) : (!empty($perms['can_view_all_clients']));
      echo '<input type="hidden" name="can_select_client" value="0">';
      echo '<label><input type="checkbox" name="can_select_client" value="1"' . checked($canSelClient, true, false) . '> Wybór klienta z bazy</label><br>';
      echo '<input type="hidden" name="can_add_client" value="0">';
      echo '<label><input type="checkbox" name="can_add_client" value="1"' . checked($canAddClient, true, false) . '> Dodawanie klientów</label><br>';
      echo '<input type="hidden" name="can_edit_client" value="0">';
      echo '<label><input type="checkbox" name="can_edit_client" value="1"' . checked($canEditClient, true, false) . '> Edycja danych klienta</label></p>';

      $allowSpec = array_key_exists('allow_special_offer', $perms) ? !empty($perms['allow_special_offer']) : true;
      $maxD = isset($perms['max_discount_percent']) ? (float)$perms['max_discount_percent'] : 100;
      if ($maxD < 0) $maxD = 0; if ($maxD > 100) $maxD = 100;

      echo '<p><label><input type="checkbox" name="allow_special_offer" value="1"' . checked($allowSpec, true, false) . '> Oferta specjalna</label><br>';
      echo '<label>Maks. rabat %: <input name="max_discount_percent" type="number" min="0" max="100" step="0.01" value="' . esc_attr((string)$maxD) . '" style="width:110px"></label></p>';

      $allowedTabs = (isset($perms['allowed_tabs']) && is_array($perms['allowed_tabs'])) ? $perms['allowed_tabs'] : array();

      $s2 = ZQOS_DB::settings();
      $tabsCfg2 = isset($s2['tabs']) && is_array($s2['tabs']) ? $s2['tabs'] : array();
      $tabNames2 = array();
      foreach ($tabsCfg2 as $tb){ $nm = isset($tb['name']) ? trim((string)$tb['name']) : ''; if ($nm) $tabNames2[] = $nm; }
      if (!$tabNames2){ $tabNames2 = array('Ogrodzenia Panelowe','Ogrodzenia Palisadowe','Słupki','Akcesoria'); }

      echo '<p><b>Dostępne kategorie:</b><br><span style="font-size:12px;color:#666">Brak zaznaczeń = wszystkie</span><br>';
      foreach ($tabNames2 as $nm){
        echo '<label style="display:inline-block;margin-right:12px;"><input type="checkbox" name="allowed_tabs[]" value="' . esc_attr($nm) . '"' . checked(in_array($nm, $allowedTabs, true), true, false) . '> ' . esc_html($nm) . '</label>';
      }
      echo '</p>';

      $seller = (isset($perms['seller']) && is_array($perms['seller'])) ? $perms['seller'] : array();
      $sn = isset($seller['name']) ? (string)$seller['name'] : '';
      $sp = isset($seller['phone']) ? (string)$seller['phone'] : '';
      $se = isset($seller['email']) ? (string)$seller['email'] : '';
      $sb = isset($seller['branch']) ? (string)$seller['branch'] : '';

      echo '<p><b>Sprzedawca (PDF):</b><br>';
      echo '<label>Imię i nazwisko: <input name="seller_name" type="text" class="regular-text" value="' . esc_attr($sn) . '"></label><br>';
      echo '<label>Telefon: <input name="seller_phone" type="text" class="regular-text" value="' . esc_attr($sp) . '"></label><br>';
      echo '<label>Email: <input name="seller_email" type="email" class="regular-text" value="' . esc_attr($se) . '"></label><br>';
      echo '<label>Oddział: <input name="seller_branch" type="text" class="regular-text" value="' . esc_attr($sb) . '"></label></p>';

      $delOwn = !empty($perms['can_delete_offers_own']);
      $delAny = !empty($perms['can_delete_offers_any']);
      echo '<p><b>Kasowanie ofert:</b><br>';
      echo '<label><input type="checkbox" name="can_delete_offers_own" value="1"' . checked($delOwn, true, false) . '> swoje</label><br>';
      echo '<label><input type="checkbox" name="can_delete_offers_any" value="1"' . checked($delAny, true, false) . '> wszystkie (admin)</label></p>';

      submit_button('Zapisz', 'primary small', 'submit', false);
      echo '</form></div></details>';


      echo '<form style="display:inline-block" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
      wp_nonce_field('zqos_reset_account_pass');
      echo '<input type="hidden" name="action" value="zqos_reset_account_pass">';
      echo '<input type="hidden" name="id" value="' . esc_attr((string)$r['id']) . '">';
      echo '<input type="text" name="new_pass" placeholder="Nowe hasło" required>';
      echo '<button class="button">Ustaw hasło</button>';
      echo '</form> ';

      echo '<form style="display:inline-block" method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'Usunąć konto?\')">';
      wp_nonce_field('zqos_delete_account');
      echo '<input type="hidden" name="action" value="zqos_delete_account">';
      echo '<input type="hidden" name="id" value="' . esc_attr((string)$r['id']) . '">';
      echo '<button class="button button-link-delete">Usuń</button>';
      echo '</form>';

      echo '</td>';
      echo '</tr>';
    }
    echo '</tbody></table>';

    echo '</div>';
  }

  public static function handle_create_account(){
    if (!current_user_can('manage_options')) wp_die('Forbidden');
    check_admin_referer('zqos_create_account');

    global $wpdb;
    $t = ZQOS_DB::tables();

    $login = isset($_POST['login']) ? sanitize_text_field(wp_unslash($_POST['login'])) : '';
    $pass  = isset($_POST['password']) ? (string) wp_unslash($_POST['password']) : '';

    $login = trim($login);
    if (!$login || strlen($login) > 64) wp_die('Niepoprawny login.');
    if (!$pass) wp_die('Brak hasła.');

    $perms = array(
      'can_view_all_clients' => !empty($_POST['can_view_all_clients']),
      'can_force_sync' => !empty($_POST['can_force_sync']),
      'can_view_stats' => !empty($_POST['can_view_stats']),
      'super_admin' => !empty($_POST['super_admin']),
      // v1.2.5 - domyślnie TRUE jeśli klucz nie istnieje (stare konta)
      'can_select_client' => !empty($_POST['can_select_client']),
      'can_add_client' => !empty($_POST['can_add_client']),
      // v1.2.7 - domyślnie FALSE (chyba że konto ma Wszyscy klienci)
      'can_edit_client' => !empty($_POST['can_edit_client']),

      // v1.1.1
      'allow_special_offer' => !empty($_POST['allow_special_offer']),
      'max_discount_percent' => isset($_POST['max_discount_percent']) ? (float)$_POST['max_discount_percent'] : 100,
      'allowed_tabs' => isset($_POST['allowed_tabs']) && is_array($_POST['allowed_tabs']) ? array_values(array_filter(array_map(function($x){ return sanitize_text_field(wp_unslash($x)); }, $_POST['allowed_tabs']))) : array(),
      'can_delete_offers_own' => !empty($_POST['can_delete_offers_own']),
      'can_delete_offers_any' => !empty($_POST['can_delete_offers_any']),
                  'can_lock_offers' => !empty($_POST['can_lock_offers']),
'can_lock_offers' => !empty($_POST['can_lock_offers']),
'seller' => array(
        'name' => isset($_POST['seller_name']) ? sanitize_text_field(wp_unslash($_POST['seller_name'])) : '',
        'phone' => isset($_POST['seller_phone']) ? sanitize_text_field(wp_unslash($_POST['seller_phone'])) : '',
        'email' => isset($_POST['seller_email']) ? sanitize_email(wp_unslash($_POST['seller_email'])) : '',
        'branch' => isset($_POST['seller_branch']) ? sanitize_text_field(wp_unslash($_POST['seller_branch'])) : '',
      ),
    );
    if ($perms['max_discount_percent'] < 0) $perms['max_discount_percent'] = 0;
    if ($perms['max_discount_percent'] > 100) $perms['max_discount_percent'] = 100;

    $now = current_time('mysql');

    $ok = $wpdb->insert($t['accounts'], array(
      'login' => $login,
      'pass_hash' => password_hash($pass, PASSWORD_DEFAULT),
      'perms' => wp_json_encode($perms),
      'fixed_client' => null,
      'created_at' => $now,
      'updated_at' => $now,
    ), array('%s','%s','%s','%s','%s','%s'));

    if (!$ok){
      wp_die('Nie można utworzyć konta (login może już istnieć).');
    }

    wp_safe_redirect(self::admin_url_page('zqos-accounts') . '&created=1');
    exit;
  }

  public static function handle_reset_account_pass(){
    if (!current_user_can('manage_options')) wp_die('Forbidden');
    check_admin_referer('zqos_reset_account_pass');

    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $pass = isset($_POST['new_pass']) ? (string)wp_unslash($_POST['new_pass']) : '';
    if ($id <= 0 || !$pass) wp_die('Niepoprawne dane.');

    global $wpdb;
    $t = ZQOS_DB::tables();
    $now = current_time('mysql');
    $wpdb->update($t['accounts'], array(
      'pass_hash' => password_hash($pass, PASSWORD_DEFAULT),
      'updated_at' => $now,
    ), array('id' => $id), array('%s','%s'), array('%d'));

    wp_safe_redirect(self::admin_url_page('zqos-accounts') . '&updated=1');
	  exit;
	}

  public static function handle_update_account(){
    if (!current_user_can('manage_options')) wp_die('Forbidden');
    check_admin_referer('zqos_update_account');

    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    if ($id <= 0) wp_die('Niepoprawne dane.');

    $perms = array(
      'can_view_all_clients' => !empty($_POST['can_view_all_clients']),
      'can_force_sync' => !empty($_POST['can_force_sync']),
      'can_view_stats' => !empty($_POST['can_view_stats']),
      'super_admin' => !empty($_POST['super_admin']),
      // v1.2.5 - domyślnie TRUE jeśli klucz nie istnieje (stare konta)
      'can_select_client' => !empty($_POST['can_select_client']),
      'can_add_client' => !empty($_POST['can_add_client']),
      // v1.2.7 - domyślnie FALSE (chyba że konto ma Wszyscy klienci)
      'can_edit_client' => !empty($_POST['can_edit_client']),
      'allow_special_offer' => !empty($_POST['allow_special_offer']),
      'max_discount_percent' => isset($_POST['max_discount_percent']) ? (float)$_POST['max_discount_percent'] : 100,
      'allowed_tabs' => isset($_POST['allowed_tabs']) && is_array($_POST['allowed_tabs']) ? array_values(array_filter(array_map(function($x){ return sanitize_text_field(wp_unslash($x)); }, $_POST['allowed_tabs']))) : array(),
      'can_delete_offers_own' => !empty($_POST['can_delete_offers_own']),
      'can_delete_offers_any' => !empty($_POST['can_delete_offers_any']),
      'seller' => array(
        'name' => isset($_POST['seller_name']) ? sanitize_text_field(wp_unslash($_POST['seller_name'])) : '',
        'phone' => isset($_POST['seller_phone']) ? sanitize_text_field(wp_unslash($_POST['seller_phone'])) : '',
        'email' => isset($_POST['seller_email']) ? sanitize_email(wp_unslash($_POST['seller_email'])) : '',
        'branch' => isset($_POST['seller_branch']) ? sanitize_text_field(wp_unslash($_POST['seller_branch'])) : '',
      ),
    );
    if ($perms['max_discount_percent'] < 0) $perms['max_discount_percent'] = 0;
    if ($perms['max_discount_percent'] > 100) $perms['max_discount_percent'] = 100;

    global $wpdb;
    $t = ZQOS_DB::tables();
    $now = current_time('mysql');

    $wpdb->update($t['accounts'], array(
      'perms' => wp_json_encode($perms),
      'updated_at' => $now,
    ), array('id' => $id), array('%s','%s'), array('%d'));

    wp_safe_redirect(self::admin_url_page('zqos-accounts') . '&updated=1');
    exit;
  }

  public static function handle_delete_account(){
    if (!current_user_can('manage_options')) wp_die('Forbidden');
    check_admin_referer('zqos_delete_account');

    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    if ($id <= 0) wp_die('Niepoprawne id.');

    global $wpdb;
    $t = ZQOS_DB::tables();

    $wpdb->delete($t['tokens'], array('account_id' => $id), array('%d'));
    $wpdb->delete($t['acmap'], array('account_id' => $id), array('%d'));
    $wpdb->delete($t['offers'], array('account_id' => $id), array('%d'));
    $wpdb->delete($t['accounts'], array('id' => $id), array('%d'));

    wp_safe_redirect(self::admin_url_page('zqos-accounts') . '&deleted=1');
    exit;
  }

  public static function page_clients(){
    if (!current_user_can('manage_options')) return;
    global $wpdb;
    $t = ZQOS_DB::tables();
    $clients = $wpdb->get_results("SELECT id, full_name, company, nip, phone, email, address FROM {$t['clients']} ORDER BY id DESC LIMIT 300", ARRAY_A);
    $accounts = $wpdb->get_results("SELECT id, login FROM {$t['accounts']} ORDER BY login ASC", ARRAY_A);

    echo '<div class="wrap"><h1>Klienci</h1>';

    echo '<h2>Dodaj klienta</h2>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field('zqos_create_client');
    echo '<input type="hidden" name="action" value="zqos_create_client">';
    echo '<table class="form-table"><tbody>';
    echo '<tr><th>Imię i nazwisko</th><td><input name="full_name" class="regular-text"></td></tr>';
    echo '<tr><th>Nazwa firmy</th><td><input name="company" class="regular-text"></td></tr>';
    echo '<tr><th>NIP</th><td><input name="nip" class="regular-text"></td></tr>';
    echo '<tr><th>Telefon</th><td><input name="phone" class="regular-text"></td></tr>';
    echo '<tr><th>Email</th><td><input name="email" class="regular-text"></td></tr>';
    echo '<tr><th>Adres</th><td><textarea name="address" class="large-text" rows="3"></textarea></td></tr>';
    echo '</tbody></table>';
    submit_button('Dodaj klienta');
    echo '</form>';

    echo '<h2>Przypisywanie klientów do kont</h2>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field('zqos_assign_client');
    echo '<input type="hidden" name="action" value="zqos_assign_client">';
    echo '<p>Konto: <select name="account_id">';
    foreach ($accounts as $a){
      echo '<option value="' . esc_attr((string)$a['id']) . '">' . esc_html($a['login']) . '</option>';
    }
    echo '</select> ';
    echo 'Klient: <select name="client_id">';
    foreach ($clients as $c){
      $label = trim(($c['company'] ?? '') . ' ' . ($c['full_name'] ?? ''));
      if (!$label) $label = 'Klient #' . $c['id'];
      echo '<option value="' . esc_attr((string)$c['id']) . '">' . esc_html($label) . '</option>';
    }
    echo '</select> ';
    echo '<label><input type="checkbox" name="set_fixed" value="1"> Ustaw jako domyślny klient dla konta</label> ';
    echo '<button class="button button-primary">Przypisz</button></p>';
    echo '</form>';

    echo '<h2>Lista klientów</h2>';
    echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Firma</th><th>Osoba</th><th>NIP</th><th>Kontakt</th><th>Adres</th><th>Akcje</th></tr></thead><tbody>';
    foreach ($clients as $c){
      echo '<tr>';
      echo '<td>' . esc_html((string)$c['id']) . '</td>';
      echo '<td>' . esc_html((string)($c['company'] ?? '')) . '</td>';
      echo '<td>' . esc_html((string)($c['full_name'] ?? '')) . '</td>';
      echo '<td>' . esc_html((string)($c['nip'] ?? '')) . '</td>';
      $contact = trim(($c['phone'] ?? '') . ' ' . ($c['email'] ?? ''));
      echo '<td>' . esc_html($contact) . '</td>';
      echo '<td>' . esc_html((string)($c['address'] ?? '')) . '</td>';
      echo '<td>';
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'Usunąć klienta?\')">';
      wp_nonce_field('zqos_delete_client');
      echo '<input type="hidden" name="action" value="zqos_delete_client">';
      echo '<input type="hidden" name="id" value="' . esc_attr((string)$c['id']) . '">';
      echo '<button class="button button-link-delete">Usuń</button>';
      echo '</form>';
      echo '</td>';
      echo '</tr>';
    }
    echo '</tbody></table>';

    echo '</div>';
  }

  public static function handle_create_client(){
    if (!current_user_can('manage_options')) wp_die('Forbidden');
    check_admin_referer('zqos_create_client');

    global $wpdb;
    $t = ZQOS_DB::tables();
    $now = current_time('mysql');

    $data = array(
      'full_name' => sanitize_text_field(wp_unslash($_POST['full_name'] ?? '')),
      'company' => sanitize_text_field(wp_unslash($_POST['company'] ?? '')),
      'nip' => sanitize_text_field(wp_unslash($_POST['nip'] ?? '')),
      'phone' => sanitize_text_field(wp_unslash($_POST['phone'] ?? '')),
      'email' => sanitize_email(wp_unslash($_POST['email'] ?? '')),
      'address' => sanitize_textarea_field(wp_unslash($_POST['address'] ?? '')),
      'created_at' => $now,
      'updated_at' => $now,
    );

    $wpdb->insert($t['clients'], $data, array('%s','%s','%s','%s','%s','%s','%s','%s'));
    wp_safe_redirect(self::admin_url_page('zqos-clients') . '&created=1');
    exit;
  }

  public static function handle_delete_client(){
    if (!current_user_can('manage_options')) wp_die('Forbidden');
    check_admin_referer('zqos_delete_client');

    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    if ($id <= 0) wp_die('Niepoprawne id.');

    global $wpdb;
    $t = ZQOS_DB::tables();

    $wpdb->delete($t['acmap'], array('client_id' => $id), array('%d'));
    $wpdb->delete($t['clients'], array('id' => $id), array('%d'));

    wp_safe_redirect(self::admin_url_page('zqos-clients') . '&deleted=1');
    exit;
  }

  public static function handle_assign_client(){
    if (!current_user_can('manage_options')) wp_die('Forbidden');
    check_admin_referer('zqos_assign_client');

    $accId = isset($_POST['account_id']) ? (int)$_POST['account_id'] : 0;
    $clientId = isset($_POST['client_id']) ? (int)$_POST['client_id'] : 0;
    $setFixed = !empty($_POST['set_fixed']);

    if ($accId <= 0 || $clientId <= 0) wp_die('Niepoprawne dane.');

    global $wpdb;
    $t = ZQOS_DB::tables();

    $wpdb->replace($t['acmap'], array(
      'account_id' => $accId,
      'client_id' => $clientId,
    ), array('%d','%d'));

    if ($setFixed){
      $client = $wpdb->get_row($wpdb->prepare(
        "SELECT id, full_name, company, nip, phone, email, address FROM {$t['clients']} WHERE id = %d LIMIT 1",
        $clientId
      ), ARRAY_A);

      if ($client){
        $now = current_time('mysql');
        $wpdb->update($t['accounts'], array(
          'fixed_client' => wp_json_encode($client),
          'updated_at' => $now,
        ), array('id' => $accId), array('%s','%s'), array('%d'));
      }
    }

    wp_safe_redirect(self::admin_url_page('zqos-clients') . '&assigned=1');
    exit;
  }

  public static function page_offers(){
    if (!current_user_can('manage_options')) return;
    global $wpdb;
    $t = ZQOS_DB::tables();

    // Filtry
    $f_account = isset($_GET['account_id']) ? (int)$_GET['account_id'] : 0;
    if ($f_account < 0) $f_account = 0;

    $f_from = isset($_GET['date_from']) ? sanitize_text_field(wp_unslash($_GET['date_from'])) : '';
    $f_to   = isset($_GET['date_to']) ? sanitize_text_field(wp_unslash($_GET['date_to'])) : '';
    $f_has_pdf = !empty($_GET['has_pdf']) ? 1 : 0;
    $f_q = isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '';

    $where = 'WHERE 1=1';
    $params = array();

    if ($f_account > 0){
      $where .= ' AND o.account_id = %d';
      $params[] = $f_account;
    }

    if ($f_from && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_from)){
      $where .= ' AND o.created_at >= %s';
      $params[] = $f_from . ' 00:00:00';
    }
    if ($f_to && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_to)){
      $where .= ' AND o.created_at <= %s';
      $params[] = $f_to . ' 23:59:59';
    }

    if ($f_has_pdf){
      $where .= " AND o.pdf_path IS NOT NULL AND o.pdf_path <> ''";
    }

    if ($f_q !== ''){
      $like = '%' . $wpdb->esc_like($f_q) . '%';
      $where .= ' AND o.title LIKE %s';
      $params[] = $like;
    }

    $sql = "SELECT o.id, o.title, o.created_at, o.pdf_path, a.login AS account_login
            FROM {$t['offers']} o
            JOIN {$t['accounts']} a ON a.id = o.account_id
            $where
            ORDER BY o.id DESC
            LIMIT 500";
    if ($params){
      array_unshift($params, $sql);
      $sql = call_user_func_array(array($wpdb, 'prepare'), $params);
    }
    $rows = $wpdb->get_results($sql, ARRAY_A);

    // Lista kont do filtra
    $accounts = $wpdb->get_results("SELECT id, login FROM {$t['accounts']} ORDER BY login ASC", ARRAY_A);

    $deleted = isset($_GET['deleted']) ? (int)$_GET['deleted'] : null;

    echo '<div class="wrap"><h1>Oferty</h1>';

    echo '<h2 class="title">Filtry</h2>';
    echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" style="margin:10px 0 16px;">';
    echo '<input type="hidden" name="page" value="zqos-offers">';
    echo '<label style="margin-right:10px;">Konto: ';
    echo '<select name="account_id">';
    echo '<option value="0">Wszystkie</option>';
    foreach ($accounts as $a){
      $aid = (int)($a['id'] ?? 0);
      $al = (string)($a['login'] ?? '');
      echo '<option value="' . esc_attr((string)$aid) . '"' . selected($f_account, $aid, false) . '>' . esc_html($al) . '</option>';
    }
    echo '</select></label>';

    echo '<label style="margin-right:10px;">Od: <input type="date" name="date_from" value="' . esc_attr($f_from) . '"></label>';
    echo '<label style="margin-right:10px;">Do: <input type="date" name="date_to" value="' . esc_attr($f_to) . '"></label>';
    echo '<label style="margin-right:10px;"><input type="checkbox" name="has_pdf" value="1"' . checked($f_has_pdf, 1, false) . '> tylko z PDF</label>';
    echo '<label style="margin-right:10px;">Szukaj: <input type="text" name="q" value="' . esc_attr($f_q) . '" placeholder="nazwa oferty" style="width:220px"></label>';
    echo '<button class="button">Zastosuj</button> ';
    echo '<a class="button" href="' . esc_url(self::admin_url_page('zqos-offers')) . '">Wyczyść</a>';
    echo '</form>';

    if ($deleted !== null){
      if ($deleted > 0){
        echo '<div class="notice notice-success is-dismissible"><p>Usunięto ofert: <strong>' . esc_html((string)$deleted) . '</strong>.</p></div>';
      } else {
        echo '<div class="notice notice-info is-dismissible"><p>Nie usunięto żadnych ofert.</p></div>';
      }
    }

    echo '<form id="zqos-offers-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field('zqos_delete_offers');
    echo '<input type="hidden" name="action" value="zqos_delete_offers">';

    // zachowaj filtry po delete
    echo '<input type="hidden" name="redirect_account_id" value="' . esc_attr((string)$f_account) . '">';
    echo '<input type="hidden" name="redirect_date_from" value="' . esc_attr($f_from) . '">';
    echo '<input type="hidden" name="redirect_date_to" value="' . esc_attr($f_to) . '">';
    echo '<input type="hidden" name="redirect_has_pdf" value="' . esc_attr((string)$f_has_pdf) . '">';
    echo '<input type="hidden" name="redirect_q" value="' . esc_attr($f_q) . '">';

    echo '<p>';
    echo '<button type="submit" class="button button-link-delete" onclick="return window.ZQOSOffersBulkDelete && window.ZQOSOffersBulkDelete.confirmDelete();">Usuń zaznaczone</button>';
    echo '</p>';

    
      $canLockOffers = !empty($perms['can_lock_offers']);
      echo '<p><b>Blokowanie ofert:</b><br>';
      echo '<label><input type="checkbox" name="can_lock_offers" value="1"' . checked($canLockOffers, true, false) . '> może blokować/odblokowywać</label></p>';
echo '<table class="widefat striped"><thead><tr>';
    echo '<th style="width:28px;"><input type="checkbox" id="zqos-offers-checkall" aria-label="Zaznacz wszystkie"></th>';
    echo '<th style="width:70px;">ID</th><th>Nazwa</th><th style="width:180px;">Konto</th><th style="width:170px;">Data</th><th style="width:120px;">PDF</th>';
    echo '</tr></thead><tbody>';

    if (!$rows){
      echo '<tr><td colspan="6" style="padding:14px;color:#666;">Brak ofert.</td></tr>';
    } else {
      foreach ($rows as $r){
        $id = (int)($r['id'] ?? 0);
        echo '<tr>';
        echo '<td><input type="checkbox" class="zqos-offer-chk" name="offer_ids[]" value="' . esc_attr((string)$id) . '"></td>';
        echo '<td>' . esc_html((string)$id) . '</td>';
        echo '<td>' . esc_html((string)($r['title'] ?? '')) . '</td>';
        echo '<td><code>' . esc_html((string)($r['account_login'] ?? '')) . '</code></td>';
        echo '<td>' . esc_html((string)($r['created_at'] ?? '')) . '</td>';
        echo '<td>';
        if (!empty($r['pdf_path'])){
          $url = admin_url('admin-post.php?action=zqos_download_offer_pdf&id=' . $id . '&_wpnonce=' . wp_create_nonce('zqos_download_offer_pdf_' . $id));
          echo '<a class="button" href="' . esc_url($url) . '">Pobierz</a>';
        } else {
          echo '<span style="color:#666">brak</span>';
        }
        echo '</td>';
        echo '</tr>';
      }
    }

    echo '</tbody></table>';

    echo '<p style="margin-top:10px;">';
    echo '<button type="submit" class="button button-link-delete" onclick="return window.ZQOSOffersBulkDelete && window.ZQOSOffersBulkDelete.confirmDelete();">Usuń zaznaczone</button>';
    echo '</p>';

    echo '</form>';

    echo '<script>
    (function(){
      var form = document.getElementById("zqos-offers-form");
      var all = document.getElementById("zqos-offers-checkall");
      function getChecks(){
        return Array.prototype.slice.call(document.querySelectorAll(".zqos-offer-chk"));
      }
      function anySelected(){
        var ch = getChecks();
        for (var i=0;i<ch.length;i++){ if (ch[i].checked) return true; }
        return false;
      }
      function setAll(v){
        var ch = getChecks();
        for (var i=0;i<ch.length;i++){ ch[i].checked = !!v; }
      }
      if (all){
        all.addEventListener("change", function(){ setAll(all.checked); });
      }
      window.ZQOSOffersBulkDelete = {
        confirmDelete: function(){
          if (!anySelected()){
            alert("Nie zaznaczyłeś żadnych ofert.");
            return false;
          }
          return confirm("Usunąć zaznaczone oferty? Tej operacji nie da się cofnąć.");
        }
      };
    })();
    </script>';

    echo '</div>';
  }


  public static function handle_delete_offers(){
  if (!current_user_can('manage_options')) wp_die('Forbidden');
  check_admin_referer('zqos_delete_offers');

  $ids = isset($_POST['offer_ids']) ? (array)$_POST['offer_ids'] : array();
  $clean = array();
  foreach ($ids as $v){
    $n = (int)$v;
    if ($n > 0) $clean[$n] = $n;
  }
  $ids = array_values($clean);
  // zachowaj filtry
  $ra = isset($_POST['redirect_account_id']) ? (int)$_POST['redirect_account_id'] : 0;
  if ($ra < 0) $ra = 0;
  $rdf = isset($_POST['redirect_date_from']) ? sanitize_text_field(wp_unslash($_POST['redirect_date_from'])) : '';
  $rdt = isset($_POST['redirect_date_to']) ? sanitize_text_field(wp_unslash($_POST['redirect_date_to'])) : '';
  $rpdf = isset($_POST['redirect_has_pdf']) ? (int)$_POST['redirect_has_pdf'] : 0;
  $rq = isset($_POST['redirect_q']) ? sanitize_text_field(wp_unslash($_POST['redirect_q'])) : '';

  $redirectBase = self::admin_url_page('zqos-offers');
  $redirectArgs = array();
  if ($ra > 0) $redirectArgs['account_id'] = $ra;
  if ($rdf && preg_match('/^\d{4}-\d{2}-\d{2}$/', $rdf)) $redirectArgs['date_from'] = $rdf;
  if ($rdt && preg_match('/^\d{4}-\d{2}-\d{2}$/', $rdt)) $redirectArgs['date_to'] = $rdt;
  if ($rpdf) $redirectArgs['has_pdf'] = 1;
  if ($rq !== '') $redirectArgs['q'] = $rq;

  if (!$ids){
    $url = add_query_arg(array_merge($redirectArgs, array('deleted' => 0)), $redirectBase);
    wp_safe_redirect($url);
    exit;
  }
  if (count($ids) > 500){
    $ids = array_slice($ids, 0, 500);
  }

  global $wpdb;
  $t = ZQOS_DB::tables();

  $placeholders = implode(',', array_fill(0, count($ids), '%d'));

  // Pobierz ścieżki PDF do usunięcia
  $sqlSel = "SELECT id, pdf_path FROM {$t['offers']} WHERE id IN ($placeholders)";
  $argsSel = array_merge(array($sqlSel), $ids);
  $sqlSelP = call_user_func_array(array($wpdb, 'prepare'), $argsSel);
  $rows = $wpdb->get_results($sqlSelP, ARRAY_A);

  $u = wp_upload_dir();
  $baseDir = wp_normalize_path(trailingslashit($u['basedir']));
  foreach ((array)$rows as $r){
    $pdf = isset($r['pdf_path']) ? (string)$r['pdf_path'] : '';
    if (!$pdf) continue;

    $full = wp_normalize_path(trailingslashit($u['basedir']) . ltrim($pdf, '/'));
    // Ochrona przed traversal
    if (strpos($full, $baseDir) !== 0) continue;

    if (file_exists($full)){
      @unlink($full);
    }
  }

  // Usuń eventy powiązane z ofertą (statystyki)
  $sqlDelEv = "DELETE FROM {$t['events']} WHERE offer_id IN ($placeholders)";
  $argsDelEv = array_merge(array($sqlDelEv), $ids);
  $sqlDelEvP = call_user_func_array(array($wpdb, 'prepare'), $argsDelEv);
  $wpdb->query($sqlDelEvP);

  // Usuń oferty
  $sqlDel = "DELETE FROM {$t['offers']} WHERE id IN ($placeholders)";
  $argsDel = array_merge(array($sqlDel), $ids);
  $sqlDelP = call_user_func_array(array($wpdb, 'prepare'), $argsDel);
  $deleted = $wpdb->query($sqlDelP);

  if ($deleted === false) $deleted = 0;

  $url = add_query_arg(array_merge($redirectArgs, array('deleted' => (int)$deleted)), $redirectBase);
  wp_safe_redirect($url);
  exit;
}

public static function handle_admin_download_pdf(){
    if (!current_user_can('manage_options')) wp_die('Forbidden');
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) wp_die('Niepoprawne id.');
    if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'zqos_download_offer_pdf_' . $id)) wp_die('Bad nonce');

    global $wpdb;
    $t = ZQOS_DB::tables();
    $row = $wpdb->get_row($wpdb->prepare(
      "SELECT title, pdf_path FROM {$t['offers']} WHERE id = %d LIMIT 1",
      $id
    ), ARRAY_A);

    if (!$row || empty($row['pdf_path'])) wp_die('Brak PDF.');

    $u = wp_upload_dir();
    $full = trailingslashit($u['basedir']) . ltrim((string)$row['pdf_path'], '/');
    if (!file_exists($full)) wp_die('Plik nie istnieje.');

    nocache_headers();
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . rawurlencode(sanitize_file_name($row['title'])) . '.pdf"');
    header('Content-Length: ' . filesize($full));
    // @phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    readfile($full);
    exit;
  }

  public static function page_stats(){
    if (!current_user_can('manage_options')) return;
    global $wpdb;
    $t = ZQOS_DB::tables();

    // Filtry dat
    $from = isset($_GET['date_from']) ? sanitize_text_field(wp_unslash($_GET['date_from'])) : '';
    $to   = isset($_GET['date_to']) ? sanitize_text_field(wp_unslash($_GET['date_to'])) : '';
    $accId = isset($_GET['account_id']) ? (int)$_GET['account_id'] : 0;
    if ($accId < 0) $accId = 0;

    if (!$from || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)){
      $from = wp_date('Y-m-d', current_time('timestamp') - 30 * DAY_IN_SECONDS);
    }
    if (!$to || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)){
      $to = wp_date('Y-m-d', current_time('timestamp'));
    }

    $fromDT = $from . ' 00:00:00';
    $toDT   = $to . ' 23:59:59';

    $accounts = $wpdb->get_results("SELECT id, login FROM {$t['accounts']} ORDER BY login ASC", ARRAY_A);

    // Oferty per konto w zakresie (filtr dat w JOIN, żeby konta bez ofert też były widoczne)
    $sqlOffers = "SELECT a.login, COUNT(o.id) AS cnt
                 FROM {$t['accounts']} a
                 LEFT JOIN {$t['offers']} o
                   ON o.account_id = a.id
                  AND o.created_at >= %s
                  AND o.created_at <= %s";
    $paramsOffer = array($fromDT, $toDT);
    if ($accId > 0){
      $sqlOffers .= ' WHERE a.id = %d';
      $paramsOffer[] = $accId;
    }
    $sqlOffers .= ' GROUP BY a.id ORDER BY cnt DESC, a.login ASC';

    array_unshift($paramsOffer, $sqlOffers);
    $sqlOffersP = call_user_func_array(array($wpdb, 'prepare'), $paramsOffer);
    $offers = $wpdb->get_results($sqlOffersP, ARRAY_A);

    // Events w zakresie
    $whereEv = 'WHERE created_at >= %s AND created_at <= %s';
    $paramsEv = array($fromDT, $toDT);
    if ($accId > 0){
      $whereEv .= ' AND account_id = %d';
      $paramsEv[] = $accId;
    }
    $sqlEv = "SELECT event, COUNT(id) AS cnt
             FROM {$t['events']}
             $whereEv
             GROUP BY event
             ORDER BY cnt DESC";
    array_unshift($paramsEv, $sqlEv);
    $sqlEvP = call_user_func_array(array($wpdb, 'prepare'), $paramsEv);
    $events = $wpdb->get_results($sqlEvP, ARRAY_A);

    echo '<div class="wrap"><h1>Statystyki</h1>';

    echo '<h2 class="title">Zakres</h2>';
    echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" style="margin:10px 0 16px;">';
    echo '<input type="hidden" name="page" value="zqos-stats">';
    echo '<label style="margin-right:10px;">Od: <input type="date" name="date_from" value="' . esc_attr($from) . '"></label>';
    echo '<label style="margin-right:10px;">Do: <input type="date" name="date_to" value="' . esc_attr($to) . '"></label>';
    echo '<label style="margin-right:10px;">Konto: <select name="account_id">';
    echo '<option value="0">Wszystkie</option>';
    foreach ($accounts as $a){
      $aid = (int)($a['id'] ?? 0);
      $al = (string)($a['login'] ?? '');
      echo '<option value="' . esc_attr((string)$aid) . '"' . selected($accId, $aid, false) . '>' . esc_html($al) . '</option>';
    }
    echo '</select></label>';
    echo '<button class="button">Zastosuj</button> ';
    echo '<a class="button" href="' . esc_url(self::admin_url_page('zqos-stats')) . '">Reset</a>';
    echo '</form>';
    echo '<h2>Oferty per konto</h2>';
    echo '<table class="widefat striped"><thead><tr><th>Konto</th><th>Liczba ofert</th></tr></thead><tbody>';
    foreach ($offers as $r){
      echo '<tr><td><code>' . esc_html($r['login']) . '</code></td><td>' . esc_html((string)$r['cnt']) . '</td></tr>';
    }
    echo '</tbody></table>';

    echo '<h2>Zdarzenia (' . esc_html($from) . ' - ' . esc_html($to) . ')</h2>';
    echo '<table class="widefat striped"><thead><tr><th>Event</th><th>Ilość</th></tr></thead><tbody>';
    foreach ($events as $r){
      echo '<tr><td><code>' . esc_html($r['event']) . '</code></td><td>' . esc_html((string)$r['cnt']) . '</td></tr>';
    }
    echo '</tbody></table>';

    echo '</div>';
  }

  public static function handle_sync_now(){
    if (!current_user_can('manage_options')) wp_die('Forbidden');
    check_admin_referer('zqos_sync_now');
    ZQOS_Sheets::sync_all(true);
    wp_safe_redirect(self::admin_url_page('zqos') . '&synced=1');
    exit;
  }
}
