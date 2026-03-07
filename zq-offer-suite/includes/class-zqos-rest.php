<?php
if (!defined('ABSPATH')) { exit; }

final class ZQOS_Rest {

  const NS = 'zq-offer/v1';

  // Statusy ofert (stały zestaw - walidowany po stronie API)
  // Uwaga: status 'needs_update' jest statusem systemowym (ustawiany automatycznie) i nie może być wybierany ręcznie w UI.
  private static function offer_statuses(){
    return array('unset','new','sent','in_progress','won','lost','canceled','needs_update');
  }

  // Statusy, które użytkownik może ustawić ręcznie (tworzenie/zmiana statusu)
  private static function offer_user_statuses(){
    return array('unset','new','sent','in_progress','won','lost','canceled');
  }

  private static function normalize_offer_status($raw, $allow_unset){
    $s = is_string($raw) ? $raw : (string)$raw;
    $s = strtolower(trim($s));
    if ($s === '') return null;
    $allowed = self::offer_statuses();
    if (!in_array($s, $allowed, true)) return null;
    if (!$allow_unset && $s === 'unset') return null;
    return $s;
  }

  

  // v1.2.15.0 - blokowanie/odblokowywanie ofert
  private static function status_auto_locks(){
    return array('won','lost');
  }

  private static function is_final_status($status){
    $s = self::normalize_offer_status($status, true);
    if (!$s) return false;
    return in_array($s, self::status_auto_locks(), true);
  }

  private static function actor_account_id(){
    $acc = ZQOS_Auth::require_account();
    if (!$acc) return 0;
    $aid = isset($acc['actor_id']) ? (int)$acc['actor_id'] : 0;
    if ($aid > 0 && $aid !== (int)$acc['id']) return $aid;
    return (int)$acc['id'];
  }

  private static function actor_is_super_admin(){
    return (bool) (ZQOS_Auth::require_account() && ZQOS_Auth::actor_has_permission('super_admin'));
  }

  private static function actor_can_toggle_lock(){
    return self::actor_is_super_admin() || (bool) (ZQOS_Auth::require_account() && ZQOS_Auth::actor_has_permission('can_lock_offers'));
  }

  private static function deny_if_locked($offer_row){
    if (!is_array($offer_row)) return null;
    $locked = !empty($offer_row['locked']);
    if (!$locked) return null;
    if (self::actor_is_super_admin()) return null;
    return new \WP_REST_Response(array('ok'=>false,'message'=>'Oferta jest zablokowana.'), 423);
  }

  private static function normalize_locked_fields(&$row){
    if (!is_array($row)) return;
    $row['locked'] = !empty($row['locked']) ? 1 : 0;
    if (!array_key_exists('locked_at', $row)) $row['locked_at'] = null;
    if (!array_key_exists('locked_by', $row)) $row['locked_by'] = null;
    if (!array_key_exists('lock_reason', $row)) $row['lock_reason'] = null;
    if (!array_key_exists('locked_by_login', $row)) $row['locked_by_login'] = null;
  }

public static function init(){
    add_action('rest_api_init', array(__CLASS__, 'register_routes'));
  }

  public static function register_routes(){

    register_rest_route(self::NS, '/ping', array(
      'methods' => 'GET',
      'permission_callback' => '__return_true',
      'callback' => function(){
        return rest_ensure_response(array('ok' => true, 'version' => ZQOS_VERSION, 'ts' => time()));
      }
    ));

    register_rest_route(self::NS, '/login', array(
      'methods' => 'POST',
      'permission_callback' => '__return_true',
      'callback' => array(__CLASS__, 'route_login'),
      'args' => array(
        'login' => array('required' => true),
        'password' => array('required' => true),
      ),
    ));

    register_rest_route(self::NS, '/logout', array(
      'methods' => 'POST',
      'permission_callback' => array(__CLASS__, 'perm_account'),
      'callback' => function(){
        return rest_ensure_response(ZQOS_Auth::logout());
      }
    ));

    register_rest_route(self::NS, '/me', array(
      'methods' => 'GET',
      'permission_callback' => array(__CLASS__, 'perm_account'),
      'callback' => function(){
        $acc = ZQOS_Auth::require_account();
        $actor = ZQOS_Auth::actor_summary();

        // W trybie impersonacji UI potrzebuje wiedzieć, czy AKTOR ma uprawnienia administracyjne
        // (np. kasowanie cudzych ofert), niezależnie od uprawnień konta, na które przełączono panel.
        $actorCaps = array(
          'super_admin' => (bool) ZQOS_Auth::actor_has_permission('super_admin'),
          'can_delete_offers_any' => (bool) ZQOS_Auth::actor_has_permission('can_delete_offers_any'),
          'can_delete_offers_own' => (bool) ZQOS_Auth::actor_has_permission('can_delete_offers_own'),
          'can_lock_offers' => (bool) ZQOS_Auth::actor_has_permission('can_lock_offers'),
        );

        $canSwitch = !empty($actorCaps['super_admin']);

        return rest_ensure_response(array(
          'ok' => true,
          'account' => $acc,
          'actor' => $actor,
          'can_switch' => $canSwitch,
          'actor_caps' => $actorCaps,
        ));
      }
    ));

    // Profil (dane konta + statystyki)
    register_rest_route(self::NS, '/profile', array(
      'methods' => 'GET',
      'permission_callback' => array(__CLASS__, 'perm_account'),
      'callback' => array(__CLASS__, 'route_profile_get'),
    ));

    register_rest_route(self::NS, '/profile', array(
      'methods' => 'POST',
      'permission_callback' => array(__CLASS__, 'perm_account'),
      'callback' => array(__CLASS__, 'route_profile_update'),
    ));

    register_rest_route(self::NS, '/profile/time', array(
      'methods' => 'POST',
      'permission_callback' => array(__CLASS__, 'perm_account'),
      'callback' => array(__CLASS__, 'route_profile_time'),
      'args' => array(
        'seconds' => array('required' => true),
      ),
    ));



    register_rest_route(self::NS, '/sheets', array(
      'methods' => 'GET',
      'permission_callback' => array(__CLASS__, 'perm_sheets'),
      'callback' => array(__CLASS__, 'route_sheets'),
      'args' => array(
        'force' => array('required' => false),
      ),
    ));

    register_rest_route(self::NS, '/clients', array(
      'methods' => 'GET',
      'permission_callback' => array(__CLASS__, 'perm_account'),
      'callback' => array(__CLASS__, 'route_clients'),
    ));

    register_rest_route(self::NS, '/clients', array(
      'methods' => 'POST',
      'permission_callback' => array(__CLASS__, 'perm_account'),
      'callback' => array(__CLASS__, 'route_client_create'),
    ));

    register_rest_route(self::NS, '/clients/(?P<id>\d+)', array(
      'methods' => 'PUT',
      'permission_callback' => array(__CLASS__, 'perm_account'),
      'callback' => array(__CLASS__, 'route_client_update'),
      'args' => array(
        'id' => array('required' => true),
      ),
    ));


    register_rest_route(self::NS, '/offers', array(
      'methods' => 'GET',
      'permission_callback' => array(__CLASS__, 'perm_account'),
      'callback' => array(__CLASS__, 'route_offers_list'),
    ));

    register_rest_route(self::NS, '/offers', array(
      'methods' => 'POST',
      'permission_callback' => array(__CLASS__, 'perm_account'),
      'callback' => array(__CLASS__, 'route_offer_save'),
    ));

    register_rest_route(self::NS, '/offers/(?P<id>\d+)', array(
      'methods' => 'GET',
      'permission_callback' => array(__CLASS__, 'perm_account'),
      'callback' => array(__CLASS__, 'route_offer_get'),
      'args' => array(
        'id' => array('required' => true),
      ),
    ));

    register_rest_route(self::NS, '/offers/(?P<id>\d+)', array(
      'methods' => 'PUT',
      'permission_callback' => array(__CLASS__, 'perm_account'),
      'callback' => array(__CLASS__, 'route_offer_overwrite'),
      'args' => array(
        'id' => array('required' => true),
      ),
    ));

    register_rest_route(self::NS, '/offers/(?P<id>\d+)/status', array(
      'methods' => 'PUT',
      'permission_callback' => array(__CLASS__, 'perm_account'),
      'callback' => array(__CLASS__, 'route_offer_status_update'),
      'args' => array(
        'id' => array('required' => true),
      ),
    ));


    

    // v1.2.15.0 - blokowanie/odblokowywanie + duplikowanie ofert
    register_rest_route(self::NS, '/offers/(?P<id>\d+)/lock', array(
      'methods' => 'PUT',
      'permission_callback' => array(__CLASS__, 'perm_account'),
      'callback' => array(__CLASS__, 'route_offer_lock'),
      'args' => array(
        'id' => array('required' => true),
      ),
    ));

    register_rest_route(self::NS, '/offers/(?P<id>\d+)/duplicate', array(
      'methods' => 'POST',
      'permission_callback' => array(__CLASS__, 'perm_account'),
      'callback' => array(__CLASS__, 'route_offer_duplicate'),
      'args' => array(
        'id' => array('required' => true),
      ),
    ));

register_rest_route(self::NS, '/offers/(?P<id>\d+)/sales-note', array(
      'methods' => 'GET',
      'permission_callback' => array(__CLASS__, 'perm_account'),
      'callback' => array(__CLASS__, 'route_offer_sales_note_get'),
      'args' => array(
        'id' => array('required' => true),
      ),
    ));

    register_rest_route(self::NS, '/offers/(?P<id>\d+)/sales-note', array(
      'methods' => 'PUT',
      'permission_callback' => array(__CLASS__, 'perm_account'),
      'callback' => array(__CLASS__, 'route_offer_sales_note_update'),
      'args' => array(
        'id' => array('required' => true),
      ),
    ));

    
    register_rest_route(self::NS, '/offers/(?P<id>\d+)/history', array(
      'methods' => 'GET',
      'permission_callback' => array(__CLASS__, 'perm_account'),
      'callback' => array(__CLASS__, 'route_offer_history'),
      'args' => array(
        'id' => array('required' => true),
      ),
    ));

// Podgląd oferty (read-only, dostępny także dla zablokowanych ofert)
register_rest_route(self::NS, '/offers/(?P<id>\d+)/preview', array(
  'methods' => 'GET',
  'permission_callback' => array(__CLASS__, 'perm_account'),
  'callback' => array(__CLASS__, 'route_offer_preview'),
  'args' => array(
    'id' => array('required' => true),
  ),
));

register_rest_route(self::NS, '/offers/(?P<id>\d+)/pdf', array(
      'methods' => 'GET',
      'permission_callback' => array(__CLASS__, 'perm_account'),
      'callback' => array(__CLASS__, 'route_offer_pdf_get'),
      'args' => array(
        'id' => array('required' => true),
      ),
    ));

    
    register_rest_route(self::NS, '/offers/(?P<id>\d+)', array(
      'methods' => 'DELETE',
      'permission_callback' => array(__CLASS__, 'perm_account'),
      'callback' => array(__CLASS__, 'route_offer_delete'),
      'args' => array(
        'id' => array('required' => true),
      ),
    ));

register_rest_route(self::NS, '/offers/export', array(
      'methods' => 'POST',
      'permission_callback' => array(__CLASS__, 'perm_account'),
      'callback' => array(__CLASS__, 'route_offer_export'),
    ));
  

register_rest_route(self::NS, '/offers/(?P<id>\d+)/export', array(
      'methods' => 'POST',
      'permission_callback' => array(__CLASS__, 'perm_account'),
      'callback' => array(__CLASS__, 'route_offer_export_existing'),
      'args' => array(
        'id' => array('required' => true),
      ),
    ));

  

    // Super Admin: lista kont + przełączanie kont (impersonacja)
    register_rest_route(self::NS, '/accounts', array(
      'methods' => 'GET',
      'permission_callback' => array(__CLASS__, 'perm_super_admin'),
      'callback' => array(__CLASS__, 'route_accounts_list'),
    ));

    register_rest_route(self::NS, '/switch', array(
      'methods' => 'POST',
      'permission_callback' => array(__CLASS__, 'perm_super_admin'),
      'callback' => array(__CLASS__, 'route_switch_account'),
      'args' => array(
        'account_id' => array('required' => true),
      ),
    ));
}

  public static function perm_account(){
    return (bool) ZQOS_Auth::require_account();
  }

  public static function perm_sheets(\WP_REST_Request $req){
    $settings = ZQOS_DB::settings();
    $public = !empty($settings['sheets_public']);
    if ($public) return true;
    return (bool) ZQOS_Auth::require_account();
  }


  public static function perm_super_admin(){
    // Super Admin może przełączać konta również w trybie impersonacji (sprawdzamy aktora).
    return (bool) (ZQOS_Auth::require_account() && ZQOS_Auth::actor_has_permission('super_admin'));
  }


  
  private static function perms($acc){
    $p = isset($acc['perms']) && is_array($acc['perms']) ? $acc['perms'] : array();
    return $p;
  }

  private static function perm_bool($acc, $key){
    $p = self::perms($acc);
    return !empty($p[$key]);
  }

  private static function perm_bool_default($acc, $key, $default){
    $p = self::perms($acc);
    if (!is_array($p) || !array_key_exists($key, $p)) return (bool)$default;
    return !empty($p[$key]);
  }


  private static function can_edit_client($acc){
    $p = self::perms($acc);
    if (is_array($p) && array_key_exists('can_edit_client', $p)) return !empty($p['can_edit_client']);
    // domyślnie: tylko konta "Wszyscy klienci" (admin) mają edycję
    return !empty($p['can_view_all_clients']);
  }


  private static function perm_int($acc, $key, $default){
    $p = self::perms($acc);
    if (!array_key_exists($key, $p)) return (int)$default;
    return (int)$p[$key];
  }

  private static function perm_array($acc, $key){
    $p = self::perms($acc);
    $v = isset($p[$key]) ? $p[$key] : null;
    if (!is_array($v)) return array();
    // normalize strings
    $out = array();
    foreach ($v as $x){
      $x = trim((string)$x);
      if ($x !== '') $out[] = $x;
    }
    return $out;
  }

  private static function validate_offer_by_perms($acc, $data){
    $p = self::perms($acc);

    $allowSpecial = true;
    if (array_key_exists('allow_special_offer', $p)){
      $allowSpecial = !empty($p['allow_special_offer']);
    }
    $maxDisc = 100;
    if (array_key_exists('max_discount_percent', $p)){
      $maxDisc = (float)$p['max_discount_percent'];
      if ($maxDisc < 0) $maxDisc = 0;
      if ($maxDisc > 100) $maxDisc = 100;
    }

    $allowedTabs = self::perm_array($acc, 'allowed_tabs');

    if (is_array($data)){
      $special = !empty($data['special_offer']);
      if ($special && !$allowSpecial){
        return array(false, 'Brak uprawnień do "Oferta specjalna".');
      }
      $lines = isset($data['lines']) && is_array($data['lines']) ? $data['lines'] : array();
      foreach ($lines as $i => $L){
        if (!is_array($L)) continue;

        // tab restriction (nie dotyczy pozycji niestandardowych)
        $is_custom = !empty($L['is_custom']);
        if (!$is_custom){
          $sheet = isset($L['sheet']) ? trim((string)$L['sheet']) : '';
          if ($sheet === '__CUSTOM__' || $sheet === '__TRANSPORT__') $is_custom = true;
          if (!$is_custom && isset($L['custom_kind']) && (string)$L['custom_kind'] === 'transport') $is_custom = true;
        }

        if ($allowedTabs && !$is_custom){
          $sheet = isset($L['sheet']) ? trim((string)$L['sheet']) : '';
          if ($sheet && !in_array($sheet, $allowedTabs, true)){
            return array(false, 'Pozycja #' . ((int)$i + 1) . ': brak dostępu do zakładki "' . $sheet . '".');
          }
        }

        // discount limit
        if (isset($L['disc'])){
          $disc = (float)$L['disc'];
          if ($disc > $maxDisc + 1e-9){
            return array(false, 'Pozycja #' . ((int)$i + 1) . ': rabat przekracza limit (' . $maxDisc . '%).');
          }
        }

        // manual prices only with special_offer
        if (empty($data['special_offer']) && isset($L['manual_unit_net']) && $L['manual_unit_net'] !== null && $L['manual_unit_net'] !== ''){
          return array(false, 'Pozycja #' . ((int)$i + 1) . ': ręczna cena jest dozwolona tylko w trybie "Oferta specjalna".');
        }
      }
    }

    return array(true, null);
  }


  private static function filter_cache_for_account($cache, $acc){
    if (!$acc || !is_array($cache) || empty($cache['data']) || !is_array($cache['data'])) return $cache;
    $tabs = self::perm_array($acc, 'allowed_tabs');
    if (!$tabs) return $cache;

    $data = array();
    foreach ($tabs as $t){
      if (isset($cache['data'][$t])) $data[$t] = $cache['data'][$t];
    }
    $cache['data'] = $data;
    return $cache;
  }


private static function has_fixed_client($acc){
  $fc = isset($acc['fixed_client']) ? $acc['fixed_client'] : null;
  if (!is_array($fc)) return false;
  return !empty($fc['id']) || !empty($fc['company']) || !empty($fc['full_name']) || !empty($fc['phone']) || !empty($fc['email']) || !empty($fc['address']) || !empty($fc['nip']);
}

private static function sanitize_fixed_client($fc){
  if (!is_array($fc)) return null;
  return array(
    'id' => isset($fc['id']) ? (int)$fc['id'] : null,
    'full_name' => isset($fc['full_name']) ? sanitize_text_field((string)$fc['full_name']) : '',
    'company' => isset($fc['company']) ? sanitize_text_field((string)$fc['company']) : '',
    'nip' => isset($fc['nip']) ? sanitize_text_field((string)$fc['nip']) : '',
    'phone' => isset($fc['phone']) ? sanitize_text_field((string)$fc['phone']) : '',
    'email' => isset($fc['email']) ? sanitize_email((string)$fc['email']) : '',
    'address' => isset($fc['address']) ? sanitize_text_field((string)$fc['address']) : '',
  );
}

private static function apply_fixed_client_to_offer_data($acc, $data){
  if (!is_array($data)) return $data;
  if (!self::has_fixed_client($acc)) return $data;
  $fc = self::sanitize_fixed_client($acc['fixed_client']);
  if (!$fc) return $data;
  $data['client'] = $fc;
  return $data;
}

public static function route_login(\WP_REST_Request $req){
    $login = $req->get_param('login');
    $pass = $req->get_param('password');
    $res = ZQOS_Auth::login($login, $pass);
    if (empty($res['ok'])){
      return new \WP_REST_Response(array('ok' => false, 'message' => $res['message'] ?? 'Błąd logowania.'), 401);
    }
    // Ustaw HttpOnly cookie (fallback auth dla iframe)
    if (!empty($res['token'])){
      ZQOS_Auth::set_auth_cookie($res['token'], $res['expires_at'] ?? null);
    }
    return rest_ensure_response($res);
  }

  public static function route_sheets(\WP_REST_Request $req){
    $force = $req->get_param('force');
    $force = ($force === '1' || $force === 1 || $force === true);

    if ($force && !ZQOS_Auth::check_permission('can_force_sync')){
      return new \WP_REST_Response(array('ok' => false, 'message' => 'Brak uprawnień do wymuszenia synchronizacji.'), 403);
    }

    if ($force){
      $sync = ZQOS_Sheets::sync_all(true);
      if (empty($sync['ok'])){
        // zwróć, ale spróbuj dołączyć ostatni cache
        $cache = ZQOS_Sheets::get_cache();
    $cache = self::filter_cache_for_account($cache, ZQOS_Auth::require_account());
        $cacheOut = self::filter_cache_for_account($cache, ZQOS_Auth::require_account());
        return rest_ensure_response(array('ok' => false, 'message' => $sync['message'] ?? 'Sync error', 'cache' => $cacheOut));
      }
      $cacheOut = self::filter_cache_for_account($sync['cache'], ZQOS_Auth::require_account());
      return rest_ensure_response(array('ok' => true, 'data' => $cacheOut['data'], 'meta' => array(
        'fetched_at' => $sync['cache']['fetched_at'],
        'duration_ms' => $sync['cache']['duration_ms'],
        'errors' => $sync['cache']['errors'],
        'data_hash' => $sync['cache']['data_hash'] ?? null,
      )));
    }

    $cache = ZQOS_Sheets::get_cache();
    if (!$cache){
      // pierwszy raz: spróbuj synchronizować (bez wymuszenia uprawnienia)
      $sync = ZQOS_Sheets::sync_all(true);
      if (!empty($sync['ok'])){
        $cache = $sync['cache'];
      }
    }

    if (!$cache || empty($cache['data'])){
      return new \WP_REST_Response(array('ok' => false, 'message' => 'Brak danych w cache - uruchom synchronizację w panelu WP lub poczekaj na cron.'), 503);
    }

    return rest_ensure_response(array(
      'ok' => true,
      'data' => $cache['data'],
      'meta' => array(
        'fetched_at' => $cache['fetched_at'] ?? null,
        'duration_ms' => $cache['duration_ms'] ?? null,
        'errors' => $cache['errors'] ?? array(),
        'data_hash' => $cache['data_hash'] ?? null,
      )
    ));
  }

  
public static function route_clients(\WP_REST_Request $req){
  $acc = ZQOS_Auth::require_account();
  if (!$acc) return new \WP_REST_Response(array('ok'=>false,'message'=>'Unauthorized'), 401);

  $canSelect = self::perm_bool_default($acc, 'can_select_client', true);
  if (!$canSelect || self::has_fixed_client($acc)){
    // Konto bez uprawnień do wyboru klienta lub konto ze stałym klientem.
    return rest_ensure_response(array('ok' => true, 'clients' => array()));
  }

  global $wpdb;
  $t = ZQOS_DB::tables();

  $all = !empty(($acc['perms'] ?? array())['can_view_all_clients']);

    if ($all){
      $rows = $wpdb->get_results("SELECT id, full_name, company, nip, phone, email, address FROM {$t['clients']} ORDER BY company ASC, full_name ASC LIMIT 2000", ARRAY_A);
    } else {
      $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT c.id, c.full_name, c.company, c.nip, c.phone, c.email, c.address
         FROM {$t['clients']} c
         JOIN {$t['acmap']} m ON m.client_id = c.id
         WHERE m.account_id = %d
         ORDER BY c.company ASC, c.full_name ASC
         LIMIT 2000",
        (int)$acc['id']
      ), ARRAY_A);
    }

    return rest_ensure_response(array('ok' => true, 'clients' => $rows));
  }



  
public static function route_client_create(\WP_REST_Request $req){
  $acc = ZQOS_Auth::require_account();
  if (!$acc) return new \WP_REST_Response(array('ok'=>false,'message'=>'Unauthorized'), 401);

  if (self::has_fixed_client($acc)){
    return new \WP_REST_Response(array('ok'=>false,'message'=>'Konto ma stałego klienta - nie można dodawać nowych klientów.'), 403);
  }

  if (!self::perm_bool_default($acc, 'can_add_client', true)){
    return new \WP_REST_Response(array('ok'=>false,'message'=>'Brak uprawnień do dodawania klientów.'), 403);
  }

    $params = $req->get_json_params();
    if (!is_array($params)) $params = array();

    $c = null;
    if (isset($params['client']) && is_array($params['client'])){
      $c = $params['client'];
    } else {
      $c = $params;
    }
    if (!is_array($c)) $c = array();

    $full = isset($c['full_name']) ? sanitize_text_field((string)$c['full_name']) : '';
    $company = isset($c['company']) ? sanitize_text_field((string)$c['company']) : '';
    $nip = isset($c['nip']) ? sanitize_text_field((string)$c['nip']) : '';
    $phone = isset($c['phone']) ? sanitize_text_field((string)$c['phone']) : '';
    $email = isset($c['email']) ? sanitize_email((string)$c['email']) : '';
    $address = isset($c['address']) ? sanitize_text_field((string)$c['address']) : '';

    $full = trim($full);
    $company = trim($company);
    $nip = trim($nip);
    $phone = trim($phone);
    $email = trim($email);
    $address = trim($address);

    // NIP: preferuj cyfry (123-456-78-90 -> 1234567890), ale nie wymuszaj na siłę przy innych formatach
    if ($nip){
      $digits = preg_replace('/[^0-9]/', '', $nip);
      if ($digits) $nip = $digits;
    }

    if ($full === '' && $company === ''){
      return new \WP_REST_Response(array('ok'=>false,'message'=>'Podaj imię i nazwisko lub nazwę firmy.'), 400);
    }

    if ($email && !is_email($email)){
      return new \WP_REST_Response(array('ok'=>false,'message'=>'Niepoprawny adres email.'), 400);
    }

    global $wpdb;
    $t = ZQOS_DB::tables();
    $now = current_time('mysql');

    // dedupe: NIP > email > (company+full_name)
    $existing = null;
    if ($nip){
      $existing = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$t['clients']} WHERE nip = %s LIMIT 1",
        $nip
      ), ARRAY_A);
    }
    if (!$existing && $email){
      $existing = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$t['clients']} WHERE email = %s LIMIT 1",
        $email
      ), ARRAY_A);
    }
    if (!$existing && ($company || $full)){
      $existing = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$t['clients']} WHERE company = %s AND full_name = %s LIMIT 1",
        $company, $full
      ), ARRAY_A);
    }

    $clientId = 0;

    if ($existing){
      $clientId = (int)($existing['id'] ?? 0);

      // bezpieczny update: tylko dopisz brakujące pola
      $upd = array();
      if ($full && empty($existing['full_name'])) $upd['full_name'] = $full;
      if ($company && empty($existing['company'])) $upd['company'] = $company;
      if ($nip && empty($existing['nip'])) $upd['nip'] = $nip;
      if ($phone && empty($existing['phone'])) $upd['phone'] = $phone;
      if ($email && empty($existing['email'])) $upd['email'] = $email;
      if ($address && empty($existing['address'])) $upd['address'] = $address;

      if ($upd){
        $upd['updated_at'] = $now;
        $wpdb->update($t['clients'], $upd, array('id' => $clientId));
      }
    } else {
      $wpdb->insert($t['clients'], array(
        'full_name' => $full ?: null,
        'company' => $company ?: null,
        'nip' => $nip ?: null,
        'phone' => $phone ?: null,
        'email' => $email ?: null,
        'address' => $address ?: null,
        'created_at' => $now,
        'updated_at' => $now,
      ), array('%s','%s','%s','%s','%s','%s','%s','%s'));
      $clientId = (int)$wpdb->insert_id;
    }

    if ($clientId <= 0){
      return new \WP_REST_Response(array('ok'=>false,'message'=>'Nie udało się zapisać klienta.'), 500);
    }

    // mapuj klienta do konta (żeby był widoczny bez "Wszyscy klienci")
    $wpdb->query($wpdb->prepare(
      "INSERT IGNORE INTO {$t['acmap']} (account_id, client_id) VALUES (%d, %d)",
      (int)$acc['id'], $clientId
    ));

    $row = $wpdb->get_row($wpdb->prepare(
      "SELECT id, full_name, company, nip, phone, email, address FROM {$t['clients']} WHERE id = %d LIMIT 1",
      $clientId
    ), ARRAY_A);

    ZQOS_DB::log_event('client_saved', (int)$acc['id'], null, array('client_id' => $clientId));

    return rest_ensure_response(array('ok' => true, 'client' => $row));
  }


public static function route_client_update(\WP_REST_Request $req){
  $acc = ZQOS_Auth::require_account();
  if (!$acc) return new \WP_REST_Response(array('ok'=>false,'message'=>'Unauthorized'), 401);

  if (self::has_fixed_client($acc)){
    return new \WP_REST_Response(array('ok'=>false,'message'=>'Konto ma stałego klienta - nie można edytować klientów.'), 403);
  }

  if (!self::can_edit_client($acc)){
    return new \WP_REST_Response(array('ok'=>false,'message'=>'Brak uprawnień do edycji danych klienta.'), 403);
  }

  $id = (int) $req->get_param('id');
  if ($id <= 0){
    return new \WP_REST_Response(array('ok'=>false,'message'=>'Niepoprawne ID klienta.'), 400);
  }

  global $wpdb;
  $t = ZQOS_DB::tables();

  $all = !empty(($acc['perms'] ?? array())['can_view_all_clients']);

  if (!$all){
    $has = $wpdb->get_var($wpdb->prepare(
      "SELECT 1 FROM {$t['acmap']} WHERE account_id = %d AND client_id = %d LIMIT 1",
      (int)$acc['id'], $id
    ));
    if (!$has){
      return new \WP_REST_Response(array('ok'=>false,'message'=>'Brak dostępu do tego klienta.'), 403);
    }
  }

  $params = $req->get_json_params();
  if (!is_array($params)) $params = array();

  $c = null;
  if (isset($params['client']) && is_array($params['client'])){
    $c = $params['client'];
  } else {
    $c = $params;
  }
  if (!is_array($c)) $c = array();

  $full = isset($c['full_name']) ? sanitize_text_field((string)$c['full_name']) : '';
  $company = isset($c['company']) ? sanitize_text_field((string)$c['company']) : '';
  $nip = isset($c['nip']) ? sanitize_text_field((string)$c['nip']) : '';
  $phone = isset($c['phone']) ? sanitize_text_field((string)$c['phone']) : '';
  $email = isset($c['email']) ? sanitize_email((string)$c['email']) : '';
  $address = isset($c['address']) ? sanitize_text_field((string)$c['address']) : '';

  $full = trim($full);
  $company = trim($company);
  $nip = trim($nip);
  $phone = trim($phone);
  $email = trim($email);
  $address = trim($address);

  if ($nip){
    $digits = preg_replace('/[^0-9]/', '', $nip);
    if ($digits) $nip = $digits;
  }

  if ($full === '' && $company === ''){
    return new \WP_REST_Response(array('ok'=>false,'message'=>'Podaj imię i nazwisko lub nazwę firmy.'), 400);
  }

  if ($email && !is_email($email)){
    return new \WP_REST_Response(array('ok'=>false,'message'=>'Niepoprawny adres email.'), 400);
  }

  // unikalność NIP/email (best-effort)
  if ($nip){
    $dup = (int)$wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM {$t['clients']} WHERE nip = %s AND id <> %d",
      $nip, $id
    ));
    if ($dup > 0){
      return new \WP_REST_Response(array('ok'=>false,'message'=>'Istnieje już klient z takim NIP.'), 409);
    }
  }
  if ($email){
    $dupE = (int)$wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM {$t['clients']} WHERE email = %s AND id <> %d",
      $email, $id
    ));
    if ($dupE > 0){
      return new \WP_REST_Response(array('ok'=>false,'message'=>'Istnieje już klient z takim adresem email.'), 409);
    }
  }

  $now = current_time('mysql');

  $ok = $wpdb->update($t['clients'], array(
    'full_name' => $full ?: null,
    'company' => $company ?: null,
    'nip' => $nip ?: null,
    'phone' => $phone ?: null,
    'email' => $email ?: null,
    'address' => $address ?: null,
    'updated_at' => $now,
  ), array('id' => $id), array('%s','%s','%s','%s','%s','%s','%s'), array('%d'));

  if ($ok === false){
    return new \WP_REST_Response(array('ok'=>false,'message'=>'Nie udało się zapisać zmian.'), 500);
  }

  // mapuj klienta do konta (żeby był widoczny)
  $wpdb->query($wpdb->prepare(
    "INSERT IGNORE INTO {$t['acmap']} (account_id, client_id) VALUES (%d, %d)",
    (int)$acc['id'], $id
  ));

  $row = $wpdb->get_row($wpdb->prepare(
    "SELECT id, full_name, company, nip, phone, email, address FROM {$t['clients']} WHERE id = %d LIMIT 1",
    $id
  ), ARRAY_A);

  if (!$row){
    return new \WP_REST_Response(array('ok'=>false,'message'=>'Nie znaleziono klienta.'), 404);
  }

  ZQOS_DB::log_event('client_updated', (int)$acc['id'], null, array('client_id' => $id));

  return rest_ensure_response(array('ok' => true, 'client' => $row));
}


  private static function sanitize_offer_title($title){
    $title = trim((string)$title);
    $title = wp_strip_all_tags($title);
    $title = preg_replace('/\s+/u', ' ', $title);
    if (mb_strlen($title) > 180){
      $title = mb_substr($title, 0, 180);
    }
    return $title;
  }

  private static function title_norm($title){
    $t = mb_strtolower(trim((string)$title));
    $t = preg_replace('/\s+/u', ' ', $t);
    return $t;
  }

  private static function ensure_unique_title($account_id, $title){
    global $wpdb;
    $t = ZQOS_DB::tables();

    // Zawsze dopinamy datę i godzinę jak wymaga użytkownik
    $stamp = current_time('Y-m-d H:i:s');
    $final = $title . ' (' . $stamp . ')';
    $norm = self::title_norm($final);

    $exists = (int) $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM {$t['offers']} WHERE account_id = %d AND title_norm = %s",
      (int)$account_id, $norm
    ));

    if ($exists > 0){
      $n = 2;
      do {
        $cand = $final . ' - ' . $n;
        $candNorm = self::title_norm($cand);
        $cnt = (int) $wpdb->get_var($wpdb->prepare(
          "SELECT COUNT(*) FROM {$t['offers']} WHERE account_id = %d AND title_norm = %s",
          (int)$account_id, $candNorm
        ));
        if ($cnt === 0){
          $final = $cand;
          $norm = $candNorm;
          break;
        }
        $n++;
      } while ($n < 50);
      if ($n >= 50){
        $final .= ' - ' . wp_generate_password(4, false, false);
        $norm = self::title_norm($final);
      }
    }

    return array($final, $norm);
  }

  private static function extract_totals_for_hash($data){
    $net = 0.0;
    $gross = 0.0;
    $lines = 0;

    if (is_array($data)){
      if (isset($data['totals']) && is_array($data['totals'])){
        $net = (float)($data['totals']['net'] ?? 0);
        $gross = (float)($data['totals']['gross'] ?? 0);
      }
      if (isset($data['lines']) && is_array($data['lines'])){
        $lines = count($data['lines']);
      }
    }

    // Zaokrąglenie do groszy (stabilność hash)
    $net = round($net, 2);
    $gross = round($gross, 2);
    $lines = max(0, (int)$lines);

    return array($net, $gross, $lines);
  }

  private static function compute_export_dedupe_hash($account_id, $title, $data){
    $account_id = (int)$account_id;
    $titleNorm = self::title_norm($title);
    list($net, $gross, $lines) = self::extract_totals_for_hash($data);
    $raw = $account_id . '|' . $titleNorm . '|' . number_format($net, 2, '.', '') . '|' . number_format($gross, 2, '.', '') . '|' . $lines;
    return hash('sha256', $raw);
  }

  public static function route_offer_save(\WP_REST_Request $req){
    $acc = ZQOS_Auth::require_account();
    if (!$acc) return new \WP_REST_Response(array('ok'=>false,'message'=>'Unauthorized'), 401);

    $params = $req->get_json_params();
    if (!is_array($params)) $params = array();

    $title = self::sanitize_offer_title($params['title'] ?? '');
    if (!$title){
      return new \WP_REST_Response(array('ok'=>false,'message'=>'Nazwa kalkulacji jest wymagana.'), 400);
    }

    $status = self::normalize_offer_status($params['status'] ?? '', false);
    if (!$status){
      return new \WP_REST_Response(array('ok'=>false,'message'=>'Wybierz status oferty.'), 400);
    }

    if ($status === 'needs_update'){
      return new \WP_REST_Response(array('ok'=>false,'message'=>'Status "Wymaga zaktualizowania" jest ustawiany automatycznie.'), 400);
    }

    $comment = isset($params['comment']) ? wp_kses_post((string)$params['comment']) : '';
    if (mb_strlen($comment) > 3000) $comment = mb_substr($comment, 0, 3000);

    $data = $params['data'] ?? null;
    if ($data === null){
      return new \WP_REST_Response(array('ok'=>false,'message'=>'Brak danych oferty.'), 400);
    }

    // walidacja uprawnień (rabat/oferta specjalna/zakładki)
    list($vOk, $vMsg) = self::validate_offer_by_perms($acc, $data);
    if (!$vOk){
      return new \WP_REST_Response(array('ok'=>false,'message'=>$vMsg), 403);
    }

    // walidacja uprawnień (rabat/oferta specjalna/zakładki)
    list($vOk, $vMsg) = self::validate_offer_by_perms($acc, $data);
    if (!$vOk){
      return new \WP_REST_Response(array('ok'=>false,'message'=>$vMsg), 403);
    }

    list($finalTitle, $titleNorm) = self::ensure_unique_title((int)$acc['id'], $title);

    global $wpdb;
    $t = ZQOS_DB::tables();
    $now = current_time('mysql');

    $wpdb->insert($t['offers'], array(
      'account_id' => (int)$acc['id'],
      'title' => $finalTitle,
      'title_norm' => $titleNorm,
      'dedupe_hash' => null,
      'status' => $status,
      'status_updated_at' => $now,
      'comment' => $comment,
      'data' => wp_json_encode($data),
      'pdf_path' => null,
      'locked' => self::is_final_status($status) ? 1 : 0,
      'locked_at' => self::is_final_status($status) ? $now : null,
      'locked_by' => self::is_final_status($status) ? self::actor_account_id() : null,
      'lock_reason' => self::is_final_status($status) ? 'final_status' : null,
      'created_at' => $now,
      'updated_at' => $now,
    ), array('%d','%s','%s','%s','%s','%s','%s','%s','%s','%d','%s','%s','%s','%s','%s'));

    $id = (int)$wpdb->insert_id;
    ZQOS_DB::log_event('offer_saved', (int)$acc['id'], $id, array('title' => $finalTitle, 'status' => $status));

    return rest_ensure_response(array('ok'=>true,'id'=>$id,'title'=>$finalTitle,'status'=>$status,'locked'=>self::is_final_status($status)?1:0));
  }

  
private static function compute_offer_totals_from_data($data){
  $net_before = 0.0;
  $net_after = 0.0;
  $has_discount = false;

  if (!is_array($data)){
    return array('net_before'=>0.0,'net_after'=>0.0,'has_discount'=>false);
  }

  // net_after: preferuj zapisane totals.net (zgodne z UI)
  if (isset($data['totals']) && is_array($data['totals']) && isset($data['totals']['net']) && is_numeric($data['totals']['net'])){
    $net_after = (float)$data['totals']['net'];
  }

  $calc_after = 0.0;
  if (isset($data['lines']) && is_array($data['lines'])){
    foreach ($data['lines'] as $ln){
      if (!is_array($ln)) continue;

      $disc = (isset($ln['disc']) && is_numeric($ln['disc'])) ? (float)$ln['disc'] : 0.0;
      if ($disc > 0.0001) $has_discount = true;

      // po rabacie
      if (isset($ln['net']) && is_numeric($ln['net'])){
        $calc_after += (float)$ln['net'];
      } else {
        $unit_after = (isset($ln['unit_net_after']) && is_numeric($ln['unit_net_after'])) ? (float)$ln['unit_net_after'] : null;
        $units = null;
        if (isset($ln['qty_units']) && is_numeric($ln['qty_units'])) $units = (float)$ln['qty_units'];
        elseif (isset($ln['qty']) && is_numeric($ln['qty'])) $units = (float)$ln['qty'];
        if ($units === null || $units <= 0) $units = 1.0;
        if ($unit_after !== null) $calc_after += $unit_after * $units;
      }

      // przed rabatem (dla transportu uwzględnij min_net i dopłaty)
      $is_custom = !empty($ln['is_custom']);
      $kind = isset($ln['custom_kind']) ? (string)$ln['custom_kind'] : '';
      if ($is_custom && $kind === 'transport' && isset($ln['transport']) && is_array($ln['transport']) && isset($ln['transport']['base_net']) && is_numeric($ln['transport']['base_net'])){
        $base = (float)$ln['transport']['base_net'];
        $extras = (isset($ln['transport']['extras_total']) && is_numeric($ln['transport']['extras_total'])) ? (float)$ln['transport']['extras_total'] : 0.0;
        $net_before += ($base + $extras);
      } else {
        $unit = (isset($ln['unit_net']) && is_numeric($ln['unit_net'])) ? (float)$ln['unit_net'] : 0.0;
        $units = null;
        if (isset($ln['qty_units']) && is_numeric($ln['qty_units'])) $units = (float)$ln['qty_units'];
        elseif (isset($ln['qty']) && is_numeric($ln['qty'])) $units = (float)$ln['qty'];
        if ($units === null || $units <= 0) $units = 1.0;
        $net_before += ($unit * $units);
      }
    }
  }

  if ($net_after <= 0.0 && $calc_after > 0.0){
    $net_after = $calc_after;
  }

  if (!$has_discount && abs($net_after - $net_before) > 0.01){
    $has_discount = true;
  }

  // normalizacja (2 miejsca po przecinku)
  $net_before = round($net_before, 2);
  $net_after  = round($net_after, 2);

  return array(
    'net_before' => $net_before,
    'net_after' => $net_after,
    'has_discount' => $has_discount,
  );
}


public static function route_offers_list(\WP_REST_Request $req){
    $acc = ZQOS_Auth::require_account();
    if (!$acc) return new \WP_REST_Response(array('ok'=>false,'message'=>'Unauthorized'), 401);

    global $wpdb;
    $t = ZQOS_DB::tables();
    $rows = $wpdb->get_results($wpdb->prepare(
      "SELECT o.id, o.title, o.created_at, o.updated_at, o.pdf_path, o.status, o.status_updated_at, o.comment, o.sales_note, o.data, o.locked, o.locked_at, o.locked_by, o.lock_reason, a.login AS account_login, l.login AS locked_by_login FROM {$t['offers']} o
       LEFT JOIN {$t['accounts']} a ON a.id = o.account_id
       LEFT JOIN {$t['accounts']} l ON l.id = o.locked_by
       WHERE o.account_id = %d
       ORDER BY o.id DESC
       LIMIT 200",
      (int)$acc['id']
    ), ARRAY_A);

    
$status_change_counts = array();
$offer_ids = array();
foreach ($rows as $tmp_row){
  if (!empty($tmp_row['id'])) $offer_ids[] = (int)$tmp_row['id'];
}
if (!empty($offer_ids)){
  $placeholders = implode(',', array_fill(0, count($offer_ids), '%d'));
  $sql = "SELECT offer_id, COUNT(*) AS cnt FROM {$t['events']} WHERE offer_id IN ($placeholders) AND event IN ('offer_status_changed','offer_marked_needs_update') GROUP BY offer_id";
  $prepared = $wpdb->prepare($sql, $offer_ids);
  $cnt_rows = $wpdb->get_results($prepared, ARRAY_A);
  if (is_array($cnt_rows)){
    foreach ($cnt_rows as $cr){
      $oid = isset($cr['offer_id']) ? (int)$cr['offer_id'] : 0;
      if ($oid > 0) $status_change_counts[$oid] = isset($cr['cnt']) ? (int)$cr['cnt'] : 0;
    }
  }
}

foreach ($rows as &$rr){
  // Wylicz kwoty do szybkiego podglądu listy (bez zwracania pełnego JSON 'data')
  $data = null;
  if (isset($rr['data']) && $rr['data'] !== null && $rr['data'] !== ''){
    $j = json_decode((string)$rr['data'], true);
    if (is_array($j)) $data = $j;
  }

  $tot = self::compute_offer_totals_from_data($data);
  $rr['total_net_before'] = $tot['net_before'];
  $rr['total_net_after'] = $tot['net_after'];
  $rr['has_discount'] = $tot['has_discount'] ? 1 : 0;
  $rr['total_net_display'] = $tot['has_discount'] ? $tot['net_after'] : $tot['net_before'];
  $rr['status_change_count'] = isset($status_change_counts[(int)$rr['id']]) ? (int)$status_change_counts[(int)$rr['id']] : 0;

  unset($rr['data']);
  self::normalize_locked_fields($rr);
}
unset($rr);

return rest_ensure_response
(array('ok' => true, 'offers' => $rows));
  }

  public static function route_offer_get(\WP_REST_Request $req){
    $acc = ZQOS_Auth::require_account();
    if (!$acc) return new \WP_REST_Response(array('ok'=>false,'message'=>'Unauthorized'), 401);

    $id = (int) $req->get_param('id');
    if ($id <= 0) return new \WP_REST_Response(array('ok'=>false,'message'=>'Niepoprawne id.'), 400);

    global $wpdb;
    $t = ZQOS_DB::tables();
    $row = $wpdb->get_row($wpdb->prepare(
      "SELECT o.id, o.title, o.status, o.status_updated_at, o.comment, o.sales_note, o.data, o.created_at, o.updated_at, o.pdf_path, o.locked, o.locked_at, o.locked_by, o.lock_reason, l.login AS locked_by_login FROM {$t['offers']} o LEFT JOIN {$t['accounts']} l ON l.id = o.locked_by WHERE o.id = %d AND o.account_id = %d LIMIT 1",
      $id, (int)$acc['id']
    ), ARRAY_A);

    if (!$row) return new \WP_REST_Response(array('ok'=>false,'message'=>'Nie znaleziono oferty.'), 404);

  $deny = self::deny_if_locked($row);
  if ($deny) return $deny;

    $row['data'] = $row['data'] ? json_decode($row['data'], true) : null;
    self::normalize_locked_fields($row);
    return rest_ensure_response(array('ok'=>true,'offer'=>$row));
  }


  
public static function route_offer_preview(\WP_REST_Request $req){
  $acc = ZQOS_Auth::require_account();
  if (!$acc) return new \WP_REST_Response(array('ok'=>false,'message'=>'Unauthorized'), 401);

  $id = (int) $req->get_param('id');
  if ($id <= 0) return new \WP_REST_Response(array('ok'=>false,'message'=>'Niepoprawne id.'), 400);

  global $wpdb;
  $t = ZQOS_DB::tables();

  $row = $wpdb->get_row($wpdb->prepare(
    "SELECT o.id, o.title, o.status, o.status_updated_at, o.comment, o.sales_note, o.data, o.created_at, o.updated_at, o.pdf_path, o.locked, o.locked_at, o.locked_by, o.lock_reason, l.login AS locked_by_login FROM {$t['offers']} o LEFT JOIN {$t['accounts']} l ON l.id = o.locked_by WHERE o.id = %d AND o.account_id = %d LIMIT 1",
    $id, (int)$acc['id']
  ), ARRAY_A);

  if (!$row) return new \WP_REST_Response(array('ok'=>false,'message'=>'Nie znaleziono oferty.'), 404);

  $data = null;
  if (!empty($row['data'])){
    $j = json_decode((string)$row['data'], true);
    if (is_array($j)) $data = $j;
  }

  $tot = self::compute_offer_totals_from_data($data);

  // Klient (minimalny zestaw do podglądu)
  $client = null;
  if (is_array($data) && isset($data['client']) && is_array($data['client'])){
    $c = $data['client'];
    $client = array(
      'id' => isset($c['id']) ? (string)$c['id'] : null,
      'full_name' => isset($c['full_name']) ? (string)$c['full_name'] : '',
      'company' => isset($c['company']) ? (string)$c['company'] : '',
      'nip' => isset($c['nip']) ? (string)$c['nip'] : '',
      'phone' => isset($c['phone']) ? (string)$c['phone'] : '',
      'email' => isset($c['email']) ? (string)$c['email'] : '',
      'address' => isset($c['address']) ? (string)$c['address'] : '',
    );
  }

  $seller = null;
  if (is_array($data) && isset($data['seller']) && is_array($data['seller'])){
    $s = $data['seller'];
    $seller = array(
      'login' => isset($s['login']) ? (string)$s['login'] : '',
      'name' => isset($s['name']) ? (string)$s['name'] : '',
      'phone' => isset($s['phone']) ? (string)$s['phone'] : '',
      'email' => isset($s['email']) ? (string)$s['email'] : '',
      'branch' => isset($s['branch']) ? (string)$s['branch'] : '',
    );
  }

  $validity_days = null;
  if (is_array($data) && isset($data['validity_days']) && is_numeric($data['validity_days'])){
    $validity_days = (int)$data['validity_days'];
    if ($validity_days < 1) $validity_days = 1;
    if ($validity_days > 365) $validity_days = 365;
  }

  // Podgląd pozycji: pierwsze 8 linii (bez ciężkich danych)
  $lines_preview = array();
  $lines_count = 0;
  if (is_array($data) && isset($data['lines']) && is_array($data['lines'])){
    $lines_count = count($data['lines']);
    $max = 8;
    $i = 0;
    foreach ($data['lines'] as $ln){
      if (!is_array($ln)) continue;
      $i++;
      if ($i > $max) break;

      $is_custom = !empty($ln['is_custom']);
      $kind = isset($ln['custom_kind']) ? (string)$ln['custom_kind'] : '';
      $label = '';
      if ($is_custom && $kind === 'transport'){
        $km = (isset($ln['transport']) && is_array($ln['transport']) && isset($ln['transport']['km'])) ? (int)$ln['transport']['km'] : null;
        $label = $km ? ('Transport (' . $km . ' km)') : 'Transport';
      } elseif ($is_custom){
        $label = isset($ln['produkt']) ? (string)$ln['produkt'] : 'Pozycja niestandardowa';
      } else {
        $p = isset($ln['produkt']) ? (string)$ln['produkt'] : '';
        $w = isset($ln['wymiar']) ? (string)$ln['wymiar'] : '';
        $r = isset($ln['ral']) ? (string)$ln['ral'] : '';
        $label = $p;
        if ($w) $label .= ' ' . $w;
        if ($r) $label .= ' / ' . $r;
        if (!$label) $label = 'Pozycja';
      }

      $qty = null;
      if (isset($ln['qty_units']) && is_numeric($ln['qty_units'])) $qty = (float)$ln['qty_units'];
      elseif (isset($ln['qty']) && is_numeric($ln['qty'])) $qty = (float)$ln['qty'];
      if ($qty === null || $qty <= 0) $qty = 1;

      $disc = (isset($ln['disc']) && is_numeric($ln['disc'])) ? (float)$ln['disc'] : 0.0;

      $line_net_before = 0.0;
      $line_net_after = null;
      $line_has_discount = ($disc > 0.0001);

      if ($is_custom && $kind === 'transport' && isset($ln['transport']) && is_array($ln['transport']) && isset($ln['transport']['base_net']) && is_numeric($ln['transport']['base_net'])){
        $base = (float)$ln['transport']['base_net'];
        $extras = (isset($ln['transport']['extras_total']) && is_numeric($ln['transport']['extras_total'])) ? (float)$ln['transport']['extras_total'] : 0.0;
        $line_net_before = $base + $extras;
      } else {
        $unit_before = (isset($ln['unit_net']) && is_numeric($ln['unit_net'])) ? (float)$ln['unit_net'] : 0.0;
        $line_net_before = $unit_before * $qty;
      }

      if (isset($ln['net']) && is_numeric($ln['net'])){
        $line_net_after = (float)$ln['net'];
      } else {
        $unit_after = (isset($ln['unit_net_after']) && is_numeric($ln['unit_net_after'])) ? (float)$ln['unit_net_after'] : null;
        if ($unit_after !== null){
          $line_net_after = $unit_after * $qty;
        }
      }
      if ($line_net_after === null){
        $line_net_after = $line_net_before;
      }
      if (!$line_has_discount && abs($line_net_after - $line_net_before) > 0.01){
        $line_has_discount = true;
      }

      $line_net_before = round((float)$line_net_before, 2);
      $line_net_after = round((float)$line_net_after, 2);

      $lines_preview[] = array(
        'label' => $label,
        'qty' => $qty,
        'disc' => $disc,
        'net_before' => $line_net_before,
        'net_after' => $line_net_after,
        'has_discount' => $line_has_discount ? true : false,
        'net_display' => $line_has_discount ? $line_net_after : $line_net_before,
      );
    }
  }

  self::normalize_locked_fields($row);

  $preview = array(
    'id' => (int)$row['id'],
    'title' => (string)$row['title'],
    'status' => (string)$row['status'],
    'status_updated_at' => isset($row['status_updated_at']) ? (string)$row['status_updated_at'] : '',
    'comment' => isset($row['comment']) ? (string)$row['comment'] : '',
    'sales_note' => isset($row['sales_note']) ? (string)$row['sales_note'] : '',
    'created_at' => isset($row['created_at']) ? (string)$row['created_at'] : '',
    'updated_at' => isset($row['updated_at']) ? (string)$row['updated_at'] : '',
    'pdf_path' => isset($row['pdf_path']) ? (string)$row['pdf_path'] : '',
    'locked' => $row['locked'],
    'locked_at' => $row['locked_at'],
    'locked_by' => $row['locked_by'],
    'lock_reason' => $row['lock_reason'],
    'locked_by_login' => $row['locked_by_login'],
    'totals' => array(
      'net_before' => $tot['net_before'],
      'net_after' => $tot['net_after'],
      'has_discount' => $tot['has_discount'] ? true : false,
      'net_display' => $tot['has_discount'] ? $tot['net_after'] : $tot['net_before'],
    ),
    'lines_count' => (int)$lines_count,
    'lines_preview' => $lines_preview,
    'client' => $client,
    'validity_days' => $validity_days,
    'seller' => $seller,
  );

  return rest_ensure_response(array('ok'=>true,'preview'=>$preview));
}


public static function route_offer_history(\WP_REST_Request $req){
    $acc = ZQOS_Auth::require_account();
    if (!$acc) return new \WP_REST_Response(array('ok'=>false,'message'=>'Unauthorized'), 401);

    $id = (int) $req->get_param('id');
    if ($id <= 0) return new \WP_REST_Response(array('ok'=>false,'message'=>'Niepoprawne id.'), 400);

    global $wpdb;
    $t = ZQOS_DB::tables();

    // Upewnij się, że oferta należy do bieżącego konta (w trybie impersonacji to konto docelowe).
    $offer = $wpdb->get_row($wpdb->prepare(
      "SELECT id, title, status, created_at, updated_at FROM {$t['offers']} WHERE id = %d AND account_id = %d LIMIT 1",
      $id, (int)$acc['id']
    ), ARRAY_A);

    if (!$offer){
      return new \WP_REST_Response(array('ok'=>false,'message'=>'Nie znaleziono oferty.'), 404);
    }

    $rows = $wpdb->get_results($wpdb->prepare(
      "SELECT e.id, e.event, e.meta, e.created_at, e.account_id, a.login AS account_login
       FROM {$t['events']} e
       LEFT JOIN {$t['accounts']} a ON a.id = e.account_id
       WHERE e.offer_id = %d
       ORDER BY e.id ASC
       LIMIT 500",
      $id
    ), ARRAY_A);

    if (is_array($rows)){
      foreach ($rows as &$r){
        $r['account_id'] = isset($r['account_id']) && $r['account_id'] !== null ? (int)$r['account_id'] : null;
        $r['account_login'] = isset($r['account_login']) && $r['account_login'] !== null ? (string)$r['account_login'] : null;
        $r['event'] = isset($r['event']) ? (string)$r['event'] : '';
        $r['created_at'] = isset($r['created_at']) ? (string)$r['created_at'] : '';
        $meta = null;
        if (isset($r['meta']) && $r['meta'] !== null && $r['meta'] !== ''){
          $j = json_decode((string)$r['meta'], true);
          if (is_array($j)) $meta = $j;
        }
        $r['meta'] = $meta;
      }
      unset($r);
    } else {
      $rows = array();
    }

    return rest_ensure_response(array(
      'ok' => true,
      'offer' => $offer,
      'history' => $rows,
    ));
  }


private static function normalize_client_for_compare($c){
  $out = array(
    'id' => null,
    'full_name' => '',
    'company' => '',
    'nip' => '',
    'phone' => '',
    'email' => '',
    'address' => '',
  );
  if (!is_array($c)) $c = array();

  if (isset($c['id']) && $c['id'] !== '' && $c['id'] !== null){
    $out['id'] = (string)$c['id'];
  }

  foreach (array('full_name','company','nip','phone','email','address') as $k){
    if (isset($c[$k])) $out[$k] = trim((string)$c[$k]);
  }

  return $out;
}

private static function client_hash($c){
  $n = self::normalize_client_for_compare($c);
  return hash('sha256', wp_json_encode($n));
}

public static function route_offer_overwrite(\WP_REST_Request $req){
  $acc = ZQOS_Auth::require_account();
  if (!$acc) return new \WP_REST_Response(array('ok'=>false,'message'=>'Unauthorized'), 401);

  $id = (int) $req->get_param('id');
  if ($id <= 0) return new \WP_REST_Response(array('ok'=>false,'message'=>'Niepoprawne id.'), 400);

  $params = $req->get_json_params();
  if (!is_array($params)) $params = array();

  $title = self::sanitize_offer_title($params['title'] ?? '');
  if (!$title){
    return new \WP_REST_Response(array('ok'=>false,'message'=>'Nazwa kalkulacji jest wymagana.'), 400);
  }

  $status = self::normalize_offer_status($params['status'] ?? '', false);
  if (!$status){
    return new \WP_REST_Response(array('ok'=>false,'message'=>'Wybierz status oferty.'), 400);
  }

  if ($status === 'needs_update'){
    return new \WP_REST_Response(array('ok'=>false,'message'=>'Status "Wymaga zaktualizowania" jest ustawiany automatycznie.'), 400);
  }

  $comment = isset($params['comment']) ? wp_kses_post((string)$params['comment']) : '';
  if (mb_strlen($comment) > 3000) $comment = mb_substr($comment, 0, 3000);

  $data = $params['data'] ?? null;
  if ($data === null){
    return new \WP_REST_Response(array('ok'=>false,'message'=>'Brak danych oferty.'), 400);
  }

  // jeśli konto ma stałego klienta - wymuś jego dane (nie pozwalaj na zmianę z UI)
  $data = self::apply_fixed_client_to_offer_data($acc, $data);

  // walidacja uprawnień (rabat/oferta specjalna/zakładki)
  list($vOk, $vMsg) = self::validate_offer_by_perms($acc, $data);
  if (!$vOk){
    return new \WP_REST_Response(array('ok'=>false,'message'=>$vMsg), 403);
  }

  global $wpdb;
  $t = ZQOS_DB::tables();
  $row = $wpdb->get_row($wpdb->prepare(
    "SELECT id, title, status, status_updated_at, comment, data, pdf_path, locked, locked_at, locked_by, lock_reason FROM {$t['offers']} WHERE id = %d AND account_id = %d LIMIT 1",
    $id, (int)$acc['id']
  ), ARRAY_A);

  if (!$row) return new \WP_REST_Response(array('ok'=>false,'message'=>'Nie znaleziono oferty.'), 404);

  $storedTitle = isset($row['title']) ? trim((string)$row['title']) : '';
  if (trim($title) !== $storedTitle){
    return new \WP_REST_Response(array('ok'=>false,'message'=>'Nie można nadpisać: zmieniono tytuł oferty. Zapisz jako nową ofertę.'), 409);
  }

  $storedData = $row['data'] ? json_decode($row['data'], true) : null;
  $storedClient = (is_array($storedData) && isset($storedData['client'])) ? $storedData['client'] : null;
  $newClient = (is_array($data) && isset($data['client'])) ? $data['client'] : null;

  $hOld = self::client_hash($storedClient);
  $hNew = self::client_hash($newClient);
  if ($hOld !== $hNew){
    return new \WP_REST_Response(array('ok'=>false,'message'=>'Nie można nadpisać: zmieniono dane klienta. Zapisz jako nową ofertę.'), 409);
  }

  $now = current_time('mysql');
  $oldStatus = isset($row['status']) ? (string)$row['status'] : 'unset';

  $update = array(
    'comment' => $comment,
    'data' => wp_json_encode($data),
    'pdf_path' => null,
    'updated_at' => $now,
  );
  $formats = array('%s','%s','%s','%s');

  if ($oldStatus !== $status){
    $update['status'] = $status;
    $update['status_updated_at'] = $now;
    $formats[] = '%s';
    $formats[] = '%s';

    // Auto-lock po statusach końcowych
    if (self::is_final_status($status)){
      $update['locked'] = 1;
      $update['locked_at'] = $now;
      $update['locked_by'] = self::actor_account_id();
      $update['lock_reason'] = 'final_status';
      $formats[] = '%d';
      $formats[] = '%s';
      $formats[] = '%d';
      $formats[] = '%s';
    }
  }

  $ok = $wpdb->update($t['offers'], $update, array(
    'id' => $id,
    'account_id' => (int)$acc['id'],
  ), $formats, array('%d','%d'));

  if ($ok === false){
    return new \WP_REST_Response(array('ok'=>false,'message'=>'DB update failed'), 500);
  }

  ZQOS_DB::log_event('offer_overwritten', (int)$acc['id'], $id, array(
    'title' => $storedTitle,
    'old_status' => $oldStatus,
    'new_status' => $status,
    'pdf_cleared' => true,
  ));

  return rest_ensure_response(array('ok'=>true,'id'=>$id,'title'=>$storedTitle,'status'=>$status,'overwritten'=>true,'pdf_cleared'=>true));
}

  public static function route_offer_status_update(\WP_REST_Request $req){
    $acc = ZQOS_Auth::require_account();
    if (!$acc) return new \WP_REST_Response(array('ok'=>false,'message'=>'Unauthorized'), 401);

    $id = (int) $req->get_param('id');
    if ($id <= 0) return new \WP_REST_Response(array('ok'=>false,'message'=>'Niepoprawne id.'), 400);

    $params = $req->get_json_params();
    if (!is_array($params)) $params = array();

    $newStatus = self::normalize_offer_status($params['status'] ?? $req->get_param('status'), false);
    if (!$newStatus){
      return new \WP_REST_Response(array('ok'=>false,'message'=>'Niepoprawny status oferty.'), 400);
    }

    // status systemowy - nie pozwalamy ustawić ręcznie
    if ($newStatus === 'needs_update'){
      return new \WP_REST_Response(array('ok'=>false,'message'=>'Status "Wymaga zaktualizowania" jest ustawiany automatycznie.'), 400);
    }

    global $wpdb;
    $t = ZQOS_DB::tables();

    $row = $wpdb->get_row($wpdb->prepare(
      "SELECT id, status, status_updated_at, locked, locked_at, locked_by, lock_reason FROM {$t['offers']} WHERE id = %d AND account_id = %d LIMIT 1",
      $id, (int)$acc['id']
    ), ARRAY_A);

    if (!$row){
      return new \WP_REST_Response(array('ok'=>false,'message'=>'Nie znaleziono oferty.'), 404);
    }

    $deny = self::deny_if_locked($row);
    if ($deny) return $deny;

    $deny = self::deny_if_locked($row);
    if ($deny) return $deny;

    $oldStatus = isset($row['status']) ? (string)$row['status'] : 'unset';
    if ($oldStatus === $newStatus){
      return rest_ensure_response(array('ok'=>true,'id'=>$id,'status'=>$newStatus,'changed'=>false,'status_updated_at'=>$row['status_updated_at'] ?? null));
    }

    $now = current_time('mysql');

    $upd = array(
      'status' => $newStatus,
      'status_updated_at' => $now,
      'updated_at' => $now,
    );
    $updFormats = array('%s','%s','%s');

    // Auto-lock po statusach końcowych
    if (self::is_final_status($newStatus)){
      $upd['locked'] = 1;
      $upd['locked_at'] = $now;
      $upd['locked_by'] = self::actor_account_id();
      $upd['lock_reason'] = 'final_status';
      $updFormats[] = '%d';
      $updFormats[] = '%s';
      $updFormats[] = '%d';
      $updFormats[] = '%s';
    }

    $ok = $wpdb->update($t['offers'], $upd, array(
      'id' => $id,
      'account_id' => (int)$acc['id'],
    ), $updFormats, array('%d','%d'));

    if ($ok === false){
      return new \WP_REST_Response(array('ok'=>false,'message'=>'DB update failed'), 500);
    }

    ZQOS_DB::log_event('offer_status_changed', (int)$acc['id'], $id, array(
      'old' => $oldStatus,
      'new' => $newStatus,
    ));

    return rest_ensure_response(array('ok'=>true,'id'=>$id,'status'=>$newStatus,'changed'=>true,'status_updated_at'=>$now,'locked'=>(self::is_final_status($newStatus)?1:(!empty($row['locked'])?1:0)),'locked_at'=>(self::is_final_status($newStatus)?$now:($row['locked_at'] ?? null)),'locked_by'=>(self::is_final_status($newStatus)?self::actor_account_id():($row['locked_by'] ?? null)),'lock_reason'=>(self::is_final_status($newStatus)?'final_status':($row['lock_reason'] ?? null))));
  }


  

  public static function route_offer_lock(\WP_REST_Request $req){
    $acc = ZQOS_Auth::require_account();
    if (!$acc) return new \WP_REST_Response(array('ok'=>false,'message'=>'Unauthorized'), 401);

    if (!self::actor_can_toggle_lock()){
      return new \WP_REST_Response(array('ok'=>false,'message'=>'Brak uprawnień do blokowania ofert.'), 403);
    }

    $id = (int) $req->get_param('id');
    if ($id <= 0) return new \WP_REST_Response(array('ok'=>false,'message'=>'Niepoprawne id.'), 400);

    $params = $req->get_json_params();
    if (!is_array($params)) $params = array();

    $wantLocked = null;
    if (array_key_exists('locked', $params)){
      $wantLocked = (bool) $params['locked'];
    } else if ($req->get_param('locked') !== null){
      $wantLocked = (bool) $req->get_param('locked');
    }
    if ($wantLocked === null){
      return new \WP_REST_Response(array('ok'=>false,'message'=>'Brak parametru locked.'), 400);
    }

    global $wpdb;
    $t = ZQOS_DB::tables();

    $row = $wpdb->get_row($wpdb->prepare(
      "SELECT id, status, locked, locked_at, locked_by, lock_reason FROM {$t['offers']} WHERE id = %d AND account_id = %d LIMIT 1",
      $id, (int)$acc['id']
    ), ARRAY_A);

    if (!$row){
      return new \WP_REST_Response(array('ok'=>false,'message'=>'Nie znaleziono oferty.'), 404);
    }

    $isFinal = self::is_final_status($row['status'] ?? '');
    if ($isFinal && !$wantLocked && !self::actor_is_super_admin()){
      return new \WP_REST_Response(array('ok'=>false,'message'=>'Oferta ze statusem końcowym nie może zostać odblokowana.'), 403);
    }

    $now = current_time('mysql');

    if ($wantLocked){
      $ok = $wpdb->update($t['offers'], array(
        'locked' => 1,
        'locked_at' => $now,
        'locked_by' => self::actor_account_id(),
        'lock_reason' => $isFinal ? 'final_status' : 'manual',
        'updated_at' => $now,
      ), array(
        'id' => $id,
        'account_id' => (int)$acc['id'],
      ), array('%d','%s','%d','%s','%s'), array('%d','%d'));
    } else {
      $ok = $wpdb->query($wpdb->prepare(
        "UPDATE {$t['offers']} SET locked = 0, locked_at = NULL, locked_by = NULL, lock_reason = NULL, updated_at = %s WHERE id = %d AND account_id = %d",
        $now, $id, (int)$acc['id']
      ));
    }

    if ($ok === false){
      return new \WP_REST_Response(array('ok'=>false,'message'=>'DB update failed'), 500);
    }

    ZQOS_DB::log_event('offer_lock_toggled', (int)$acc['id'], $id, array(
      'locked' => $wantLocked ? 1 : 0,
      'reason' => $isFinal ? 'final_status' : ($wantLocked ? 'manual' : null),
    ));

    return rest_ensure_response(array(
      'ok' => true,
      'id' => $id,
      'locked' => $wantLocked ? 1 : 0,
      'locked_at' => $wantLocked ? $now : null,
      'locked_by' => $wantLocked ? self::actor_account_id() : null,
      'lock_reason' => $isFinal ? 'final_status' : ($wantLocked ? 'manual' : null),
    ));
  }

  public static function route_offer_duplicate(\WP_REST_Request $req){
    $acc = ZQOS_Auth::require_account();
    if (!$acc) return new \WP_REST_Response(array('ok'=>false,'message'=>'Unauthorized'), 401);

    $id = (int) $req->get_param('id');
    if ($id <= 0) return new \WP_REST_Response(array('ok'=>false,'message'=>'Niepoprawne id.'), 400);

    global $wpdb;
    $t = ZQOS_DB::tables();

    $row = $wpdb->get_row($wpdb->prepare(
      "SELECT id, title, comment, sales_note, data FROM {$t['offers']} WHERE id = %d AND account_id = %d LIMIT 1",
      $id, (int)$acc['id']
    ), ARRAY_A);

    if (!$row){
      return new \WP_REST_Response(array('ok'=>false,'message'=>'Nie znaleziono oferty.'), 404);
    }

    $baseTitle = trim((string)$row['title']);
    if (!$baseTitle) $baseTitle = 'Oferta';
    $copyTitle = $baseTitle . ' (kopia)';

    list($finalTitle, $titleNorm) = self::ensure_unique_title((int)$acc['id'], $copyTitle);

    $now = current_time('mysql');

    $wpdb->insert($t['offers'], array(
      'account_id' => (int)$acc['id'],
      'title' => $finalTitle,
      'title_norm' => $titleNorm,
      'dedupe_hash' => null,
      'status' => 'new',
      'status_updated_at' => $now,
      'comment' => $row['comment'] ?? '',
      'sales_note' => $row['sales_note'] ?? '',
      'data' => $row['data'] ?? null,
      'pdf_path' => null,
      'locked' => 0,
      'locked_at' => null,
      'locked_by' => null,
      'lock_reason' => null,
      'created_at' => $now,
      'updated_at' => $now,
    ), array('%d','%s','%s','%s','%s','%s','%s','%s','%s','%s','%d','%s','%s','%s','%s','%s'));

    $newId = (int) $wpdb->insert_id;
    ZQOS_DB::log_event('offer_duplicated', (int)$acc['id'], $newId, array('src_id' => $id, 'title' => $finalTitle));

    return rest_ensure_response(array('ok'=>true,'id'=>$newId,'title'=>$finalTitle,'status'=>'new','locked'=>0));
  }


public static function route_offer_sales_note_get(\WP_REST_Request $req){
    $acc = ZQOS_Auth::require_account();
    if (!$acc) return new \WP_REST_Response(array('ok'=>false,'message'=>'Unauthorized'), 401);

    $id = (int) $req->get_param('id');
    if ($id <= 0) return new \WP_REST_Response(array('ok'=>false,'message'=>'Niepoprawne id.'), 400);

    global $wpdb;
    $t = ZQOS_DB::tables();
    $row = $wpdb->get_row($wpdb->prepare(
      "SELECT id, sales_note FROM {$t['offers']} WHERE id = %d AND account_id = %d LIMIT 1",
      $id, (int)$acc['id']
    ), ARRAY_A);

    if (!$row) return new \WP_REST_Response(array('ok'=>false,'message'=>'Nie znaleziono oferty.'), 404);

    return rest_ensure_response(array('ok'=>true,'id'=>$id,'sales_note'=>$row['sales_note'] ?? ''));
  }

  public static function route_offer_sales_note_update(\WP_REST_Request $req){
    $acc = ZQOS_Auth::require_account();
    if (!$acc) return new \WP_REST_Response(array('ok'=>false,'message'=>'Unauthorized'), 401);

    $id = (int) $req->get_param('id');
    if ($id <= 0) return new \WP_REST_Response(array('ok'=>false,'message'=>'Niepoprawne id.'), 400);

    $params = $req->get_json_params();
    if (!is_array($params)) $params = array();

    $note = '';
    if (array_key_exists('sales_note', $params)) $note = (string)$params['sales_note'];
    else if ($req->get_param('sales_note') !== null) $note = (string)$req->get_param('sales_note');

    $note = sanitize_textarea_field($note);
    if (mb_strlen($note) > 5000) $note = mb_substr($note, 0, 5000);

    global $wpdb;
    $t = ZQOS_DB::tables();

    $row = $wpdb->get_row($wpdb->prepare(
      "SELECT id, locked, locked_at, locked_by, lock_reason FROM {$t['offers']} WHERE id = %d AND account_id = %d LIMIT 1",
      $id, (int)$acc['id']
    ), ARRAY_A);

    if (!$row){
      return new \WP_REST_Response(array('ok'=>false,'message'=>'Nie znaleziono oferty.'), 404);
    }

    $deny = self::deny_if_locked($row);
    if ($deny) return $deny;


    $now = current_time('mysql');
    $ok = $wpdb->update($t['offers'], array(
      'sales_note' => $note,
      'updated_at' => $now,
    ), array(
      'id' => $id,
      'account_id' => (int)$acc['id'],
    ), array('%s','%s'), array('%d','%d'));

    if ($ok === false){
      return new \WP_REST_Response(array('ok'=>false,'message'=>'DB update failed'), 500);
    }

    ZQOS_DB::log_event('offer_sales_note_updated', (int)$acc['id'], $id, array(
      'len' => mb_strlen($note),
    ));

    return rest_ensure_response(array('ok'=>true,'id'=>$id,'sales_note'=>$note,'updated_at'=>$now));
  }


  public static function route_offer_export(\WP_REST_Request $req){
    $acc = ZQOS_Auth::require_account();
    if (!$acc) return new \WP_REST_Response(array('ok'=>false,'message'=>'Unauthorized'), 401);

    $params = $req->get_json_params();
    if (!is_array($params)) $params = array();

    $title = self::sanitize_offer_title($params['title'] ?? '');
    if (!$title){
      return new \WP_REST_Response(array('ok'=>false,'message'=>'Nazwa kalkulacji jest wymagana.'), 400);
    }

    // Status jest wymagany w UI, ale dla kompatybilności (stare hosty eksportu) akceptujemy brak i zapisujemy jako 'unset'.
    $status = self::normalize_offer_status($params['status'] ?? '', true);
    if (!$status) $status = 'unset';
    $comment = isset($params['comment']) ? wp_kses_post((string)$params['comment']) : '';
    if (mb_strlen($comment) > 3000) $comment = mb_substr($comment, 0, 3000);

    $data = $params['data'] ?? null;
    if ($data === null){
      return new \WP_REST_Response(array('ok'=>false,'message'=>'Brak danych oferty.'), 400);
    }
    // jeśli konto ma stałego klienta - wymuś jego dane (nie pozwalaj na zmianę z UI)
    $data = self::apply_fixed_client_to_offer_data($acc, $data);

    $pdfB64 = (string)($params['pdf_base64'] ?? '');
    if (!$pdfB64){
      return new \WP_REST_Response(array('ok'=>false,'message'=>'Brak PDF (pdf_base64).'), 400);
    }

    // usuń prefix data:application/pdf;base64,
    if (strpos($pdfB64, 'base64,') !== false){
      $pdfB64 = preg_replace('#^data:application/pdf;base64,#', '', $pdfB64);
    }
    $pdfBin = base64_decode($pdfB64, true);
    if ($pdfBin === false || strlen($pdfBin) < 100){
      return new \WP_REST_Response(array('ok'=>false,'message'=>'Niepoprawny PDF (base64).'), 400);
    }

    // Anty-duplikacja eksportu (np. podwójny klik) - okno 10-15 sekund
    $settings = ZQOS_DB::settings();
    $dedupeSec = isset($settings['export_dedupe_seconds']) ? (int)$settings['export_dedupe_seconds'] : 15;
    if ($dedupeSec < 0) $dedupeSec = 0;
    if ($dedupeSec > 60) $dedupeSec = 60;

    $dedupeHash = self::compute_export_dedupe_hash((int)$acc['id'], $title, $data);

    if ($dedupeSec > 0 && $dedupeHash){
      global $wpdb;
      $t = ZQOS_DB::tables();

      $cutTs = current_time('timestamp') - $dedupeSec;
      $cut = wp_date('Y-m-d H:i:s', $cutTs);

      $row = $wpdb->get_row($wpdb->prepare(
        "SELECT id, title, pdf_path FROM {$t['offers']} WHERE account_id = %d AND dedupe_hash = %s AND created_at >= %s ORDER BY id DESC LIMIT 1",
        (int)$acc['id'], $dedupeHash, $cut
      ), ARRAY_A);

      if ($row && !empty($row['pdf_path'])){
        // Opcjonalnie sprawdź, czy plik istnieje
        $u = wp_upload_dir();
        $full = trailingslashit($u['basedir']) . ltrim((string)$row['pdf_path'], '/');
        if (file_exists($full) && filesize($full) > 100){
          ZQOS_DB::log_event('offer_export_deduped', (int)$acc['id'], (int)$row['id'], array('title' => (string)$row['title']));
          return rest_ensure_response(array('ok' => true, 'id' => (int)$row['id'], 'title' => (string)$row['title'], 'deduped' => true));
        }
      }
    }

    list($finalTitle, $titleNorm) = self::ensure_unique_title((int)$acc['id'], $title);

    global $wpdb;
    $t = ZQOS_DB::tables();
    $now = current_time('mysql');

    $wpdb->insert($t['offers'], array(
      'account_id' => (int)$acc['id'],
      'title' => $finalTitle,
      'title_norm' => $titleNorm,
      'dedupe_hash' => $dedupeHash,
      'status' => $status,
      'status_updated_at' => $now,
      'comment' => $comment,
      'data' => wp_json_encode($data),
      'pdf_path' => null,
      'locked' => self::is_final_status($status) ? 1 : 0,
      'locked_at' => self::is_final_status($status) ? $now : null,
      'locked_by' => self::is_final_status($status) ? self::actor_account_id() : null,
      'lock_reason' => self::is_final_status($status) ? 'final_status' : null,
      'created_at' => $now,
      'updated_at' => $now,
    ), array('%d','%s','%s','%s','%s','%s','%s','%s','%s','%d','%s','%s','%s','%s','%s'));

    $id = (int)$wpdb->insert_id;

    $path = self::store_pdf($acc, $id, $finalTitle, $pdfBin);
    if (!$path){
      return new \WP_REST_Response(array('ok'=>false,'message'=>'Nie można zapisać pliku PDF (uploads).'), 500);
    }

    $wpdb->update($t['offers'], array(
      'pdf_path' => $path,
      'updated_at' => $now,
    ), array(
      'id' => $id,
      'account_id' => (int)$acc['id'],
    ), array('%s','%s'), array('%d','%d'));

    ZQOS_DB::log_event('offer_exported', (int)$acc['id'], $id, array('title' => $finalTitle, 'status' => $status));
    if ($status === 'unset'){
      ZQOS_DB::log_event('offer_exported_status_unset', (int)$acc['id'], $id, array('title' => $finalTitle));
    }

    return rest_ensure_response(array('ok'=>true,'id'=>$id,'title'=>$finalTitle));
  }


  // v1.2.15.1 - eksport PDF do ISTNIEJĄCEJ oferty (nadpisanie pdf_path bez tworzenia nowego rekordu)
  public static function route_offer_export_existing(\WP_REST_Request $req){
    $acc = ZQOS_Auth::require_account();
    if (!$acc) return new \WP_REST_Response(array('ok'=>false,'message'=>'Unauthorized'), 401);

    $id = (int) $req->get_param('id');
    if ($id <= 0) return new \WP_REST_Response(array('ok'=>false,'message'=>'Niepoprawne id.'), 400);

    global $wpdb;
    $t = ZQOS_DB::tables();

    $row = $wpdb->get_row($wpdb->prepare(
      "SELECT id, account_id, title, pdf_path, locked, locked_at, locked_by, lock_reason, status FROM {$t['offers']} WHERE id = %d AND account_id = %d LIMIT 1",
      $id, (int)$acc['id']
    ), ARRAY_A);

    if (!$row){
      return new \WP_REST_Response(array('ok'=>false,'message'=>'Nie znaleziono oferty.'), 404);
    }

    $deny = self::deny_if_locked($row);
    if ($deny) return $deny;

    $params = $req->get_json_params();
    if (!is_array($params)) $params = array();

    $pdfB64 = (string)($params['pdf_base64'] ?? '');
    if (!$pdfB64){
      return new \WP_REST_Response(array('ok'=>false,'message'=>'Brak PDF (pdf_base64).'), 400);
    }

    // usuń prefix data:application/pdf;base64,
    if (strpos($pdfB64, 'base64,') !== false){
      $pdfB64 = preg_replace('#^data:application/pdf;base64,#', '', $pdfB64);
    }

    $pdfBin = base64_decode($pdfB64, true);
    if ($pdfBin === false || strlen($pdfBin) < 100){
      return new \WP_REST_Response(array('ok'=>false,'message'=>'Niepoprawny PDF (base64).'), 400);
    }

    $now = current_time('mysql');

    $oldPath = !empty($row['pdf_path']) ? (string)$row['pdf_path'] : '';
    $path = self::store_pdf($acc, $id, (string)$row['title'], $pdfBin);
    if (!$path){
      return new \WP_REST_Response(array('ok'=>false,'message'=>'Nie można zapisać pliku PDF (uploads).'), 500);
    }

    $wpdb->update($t['offers'], array(
      'pdf_path' => $path,
      'updated_at' => $now,
    ), array(
      'id' => (int)$id,
      'account_id' => (int)$acc['id'],
    ), array('%s','%s'), array('%d','%d'));

    // usuń poprzedni PDF jeśli zmieniła się ścieżka (best-effort)
    if ($oldPath && $oldPath !== $path){
      $u = wp_upload_dir();
      $oldFull = trailingslashit($u['basedir']) . ltrim($oldPath, '/');
      if (file_exists($oldFull)) @unlink($oldFull);
    }

    ZQOS_DB::log_event('offer_pdf_exported', (int)$acc['id'], (int)$id, array(
      'title' => (string)$row['title'],
      'replaced' => (bool)$oldPath,
    ));

    return rest_ensure_response(array('ok'=>true,'id'=>(int)$id,'title'=>(string)$row['title']));
  }


  
  public static function route_offer_delete(\WP_REST_Request $req){
    $acc = ZQOS_Auth::require_account();
    if (!$acc) return new \WP_REST_Response(array('ok'=>false,'message'=>'Unauthorized'), 401);

    $id = (int) $req->get_param('id');
    if ($id <= 0) return new \WP_REST_Response(array('ok'=>false,'message'=>'Niepoprawne id.'), 400);

    $isImpersonating = !empty($acc['actor_id']) && (int)$acc['actor_id'] !== (int)$acc['id'];

    // "Może kasować wszystkie" powinno działać także w trybie przełączania kont (impersonacja):
    // uprawnienie sprawdzamy dla AKTORA, nie tylko dla aktualnie wybranego konta.
    $canAny = self::perm_bool($acc, 'can_delete_offers_any');
    if ($isImpersonating && ZQOS_Auth::actor_has_permission('can_delete_offers_any')){
      $canAny = true;
    }

    // "Może kasować swoje" dotyczy tylko aktualnie wybranego konta (nie aktora w trybie impersonacji).
    $canOwn = $canAny ? true : self::perm_bool($acc, 'can_delete_offers_own');
    if (!$canOwn){
      return new \WP_REST_Response(array('ok'=>false,'message'=>'Brak uprawnień do kasowania ofert.'), 403);
    }

    global $wpdb;
    $t = ZQOS_DB::tables();

    if ($canAny){
      $row = $wpdb->get_row($wpdb->prepare("SELECT id, account_id, title, pdf_path, locked, locked_at, locked_by, lock_reason FROM {$t['offers']} WHERE id = %d LIMIT 1", $id), ARRAY_A);
    } else {
      $row = $wpdb->get_row($wpdb->prepare("SELECT id, account_id, title, pdf_path, locked, locked_at, locked_by, lock_reason FROM {$t['offers']} WHERE id = %d AND account_id = %d LIMIT 1", $id, (int)$acc['id']), ARRAY_A);
    }

    if (!$row){
      return new \WP_REST_Response(array('ok'=>false,'message'=>'Nie znaleziono oferty.'), 404);
    }

    // usuń plik PDF (best-effort)
    if (!empty($row['pdf_path'])){
      $u = wp_upload_dir();
      $full = trailingslashit($u['basedir']) . ltrim((string)$row['pdf_path'], '/');
      if (file_exists($full)) @unlink($full);
    }

    $wpdb->delete($t['offers'], array('id' => (int)$row['id']), array('%d'));
    ZQOS_DB::log_event('offer_deleted', (int)$acc['id'], (int)$row['id'], array('title' => (string)$row['title']));

    return rest_ensure_response(array('ok'=>true));
  }

private static function store_pdf($acc, $offer_id, $title, $bin){
    $u = wp_upload_dir();
    $base = trailingslashit($u['basedir']) . 'zq-offer/pdfs/' . sanitize_title((string)$acc['login']);
    if (!is_dir($base)){
      wp_mkdir_p($base);
    }
    if (!is_dir($base) || !is_writable($base)){
      return null;
    }

    $safe = sanitize_title($title);
    if (!$safe) $safe = 'oferta';
    $fname = $safe . '-id' . (int)$offer_id . '.pdf';
    $full = trailingslashit($base) . $fname;

    $ok = @file_put_contents($full, $bin, LOCK_EX);
    if ($ok === false) return null;

    // zwracamy ścieżkę względną względem uploads (dla przenoszenia)
    $rel = 'zq-offer/pdfs/' . sanitize_title((string)$acc['login']) . '/' . $fname;
    return $rel;
  }

  public static function route_offer_pdf_get(\WP_REST_Request $req){
    $acc = ZQOS_Auth::require_account();
    if (!$acc) return new \WP_REST_Response(array('ok'=>false,'message'=>'Unauthorized'), 401);

    $id = (int) $req->get_param('id');
    if ($id <= 0) return new \WP_REST_Response(array('ok'=>false,'message'=>'Niepoprawne id.'), 400);

    global $wpdb;
    $t = ZQOS_DB::tables();
    $row = $wpdb->get_row($wpdb->prepare(
      "SELECT title, pdf_path FROM {$t['offers']} WHERE id = %d AND account_id = %d LIMIT 1",
      $id, (int)$acc['id']
    ), ARRAY_A);

    if (!$row || empty($row['pdf_path'])){
      return new \WP_REST_Response(array('ok'=>false,'message'=>'Brak PDF dla tej oferty.'), 404);
    }

    $u = wp_upload_dir();
    $full = trailingslashit($u['basedir']) . ltrim((string)$row['pdf_path'], '/');
    if (!file_exists($full)){
      return new \WP_REST_Response(array('ok'=>false,'message'=>'Plik PDF nie istnieje.'), 404);
    }

    // Wyślij plik i zakończ (REST nie ma streamingu out-of-the-box)
    nocache_headers();
    header('Content-Type: application/pdf');
    $safeTitle = sanitize_file_name((string)$row['title']);
    if (!$safeTitle) $safeTitle = 'Oferta';
    $dateTag = wp_date('Ymd', current_time('timestamp'));
    $fname = 'Oferta_ZEGGER_' . $safeTitle . '_' . $dateTag . '.pdf';
    header('Content-Disposition: attachment; filename="' . rawurlencode($fname) . '"');
    header('Content-Length: ' . filesize($full));
    // @phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    readfile($full);
    exit;
  }

  private static function norm_profile($raw){
    $p = null;
    if (is_array($raw)) $p = $raw;
    else if (is_string($raw) && $raw !== ''){
      $j = json_decode($raw, true);
      if (is_array($j)) $p = $j;
    }

    $out = array(
      'avatar_url' => '',
      'cover_url' => '',
      'time_total_sec' => 0,
    );

    if ($p && !empty($p['avatar_url'])) $out['avatar_url'] = esc_url_raw((string)$p['avatar_url']);
    if ($p && !empty($p['cover_url'])) $out['cover_url'] = esc_url_raw((string)$p['cover_url']);
    if ($p && isset($p['time_total_sec'])) $out['time_total_sec'] = max(0, (int)$p['time_total_sec']);

    return $out;
  }

  public static function route_profile_get(\WP_REST_Request $req){
    $acc = ZQOS_Auth::require_account();
    if (!$acc) return new \WP_REST_Response(array('ok'=>false,'message'=>'Unauthorized'), 401);

    global $wpdb;
    $t = ZQOS_DB::tables();

    $accId = (int)($acc['id'] ?? 0);

    $offers_count = (int) $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM {$t['offers']} WHERE account_id = %d",
      $accId
    ));

    // Statystyki statusów (pod KPI handlowca)
    $statusCounts = array();
    foreach (self::offer_statuses() as $st){
      $statusCounts[$st] = 0;
    }
    $rowsSt = $wpdb->get_results($wpdb->prepare(
      "SELECT COALESCE(NULLIF(status,''),'unset') AS st, COUNT(*) AS cnt
       FROM {$t['offers']}
       WHERE account_id = %d
       GROUP BY COALESCE(NULLIF(status,''),'unset')",
      $accId
    ), ARRAY_A);
    if ($rowsSt){
      foreach ($rowsSt as $r){
        $st = isset($r['st']) ? (string)$r['st'] : 'unset';
        $cnt = isset($r['cnt']) ? (int)$r['cnt'] : 0;
        if (!array_key_exists($st, $statusCounts)) $statusCounts[$st] = 0;
        $statusCounts[$st] += max(0, $cnt);
      }
    }

    $perms = (isset($acc['perms']) && is_array($acc['perms'])) ? $acc['perms'] : array();
    $allClients = !empty($perms['can_view_all_clients']);

    if ($allClients){
      $clients_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t['clients']}");
    } else {
      $clients_count = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$t['acmap']} WHERE account_id = %d",
        $accId
      ));
    }

    $profile = self::norm_profile($acc['profile'] ?? null);

    return rest_ensure_response(array(
      'ok' => true,
      'profile' => $profile,
      'stats' => array(
        'offers_count' => $offers_count,
        'clients_count' => $clients_count,
        'time_total_sec' => (int)($profile['time_total_sec'] ?? 0),
        'status_counts' => $statusCounts,
      ),
    ));
  }


  public static function route_profile_update(\WP_REST_Request $req){
  $acc = ZQOS_Auth::require_account();
  if (!$acc) return new \WP_REST_Response(array('ok'=>false,'message'=>'Unauthorized'), 401);

  global $wpdb;
  $t = ZQOS_DB::tables();
  $now = current_time('mysql');

  $accId = (int)($acc['id'] ?? 0);

  $perms = (isset($acc['perms']) && is_array($acc['perms'])) ? $acc['perms'] : array();
  $seller = (isset($perms['seller']) && is_array($perms['seller'])) ? $perms['seller'] : array();

  $has_name   = method_exists($req, 'has_param') ? $req->has_param('seller_name') : ($req->get_param('seller_name') !== null);
  $has_branch = method_exists($req, 'has_param') ? $req->has_param('seller_branch') : ($req->get_param('seller_branch') !== null);
  $has_phone  = method_exists($req, 'has_param') ? $req->has_param('seller_phone') : ($req->get_param('seller_phone') !== null);
  $has_email  = method_exists($req, 'has_param') ? $req->has_param('seller_email') : ($req->get_param('seller_email') !== null);
  $has_avatar = method_exists($req, 'has_param') ? $req->has_param('avatar_url') : ($req->get_param('avatar_url') !== null);
  $has_cover  = method_exists($req, 'has_param') ? $req->has_param('cover_url') : ($req->get_param('cover_url') !== null);

  // Zablokowane pola: edycja tylko w backendzie WP (Konta)
  $canEditLocked = current_user_can('manage_options');
  if (($has_name || $has_branch) && !$canEditLocked){
    return new \WP_REST_Response(array(
      'ok' => false,
      'message' => 'Pola "Imię i nazwisko" oraz "Nazwa profilu" są zablokowane - edytuj je w panelu WordPress (Konta).',
    ), 403);
  }

  // Seller (używany w PDF)
  $seller_name = null;
  $seller_branch = null;

  if ($has_name){
    $seller_name = sanitize_text_field((string)$req->get_param('seller_name'));
    $seller['name'] = ($seller_name !== '') ? $seller_name : '';
  }
  if ($has_branch){
    $seller_branch = sanitize_text_field((string)$req->get_param('seller_branch'));
    $seller['branch'] = ($seller_branch !== '') ? $seller_branch : '';
  }
  if ($has_phone){
    $seller_phone_raw = (string)$req->get_param('seller_phone');
    $seller_phone = preg_replace('/[^0-9\+\(\)\-\s]/', '', $seller_phone_raw);
    $seller['phone'] = ($seller_phone !== '') ? $seller_phone : '';
  }
  if ($has_email){
    $seller_email = sanitize_email((string)$req->get_param('seller_email'));
    $seller['email'] = ($seller_email !== '') ? $seller_email : '';
  }

  $perms['seller'] = $seller;

  $profile = self::norm_profile($acc['profile'] ?? null);

  // Profile assets
  if ($has_avatar){
    $avatar_url = trim((string)$req->get_param('avatar_url'));
    $avatar_url = $avatar_url ? esc_url_raw($avatar_url) : '';
    $profile['avatar_url'] = $avatar_url;
  }
  if ($has_cover){
    $cover_url  = trim((string)$req->get_param('cover_url'));
    $cover_url  = $cover_url ? esc_url_raw($cover_url) : '';
    $profile['cover_url']  = $cover_url;
  }

  $ok1 = $wpdb->update($t['accounts'], array(
    'perms' => wp_json_encode($perms),
    'profile' => wp_json_encode($profile),
    'updated_at' => $now,
  ), array('id' => $accId), array('%s','%s','%s'), array('%d'));

  if ($ok1 === false){
    return new \WP_REST_Response(array('ok'=>false,'message'=>'DB update failed'), 500);
  }

  $log = array();
  if ($has_name) $log['seller_name'] = $seller_name;
  if ($has_branch) $log['seller_branch'] = $seller_branch;
  ZQOS_DB::log_event('profile_saved', $accId, null, $log);

  return rest_ensure_response(array('ok' => true));
  }

  public static function route_profile_time(\WP_REST_Request $req){
    $acc = ZQOS_Auth::require_account();
    if (!$acc) return new \WP_REST_Response(array('ok'=>false,'message'=>'Unauthorized'), 401);

    $sec = (int) $req->get_param('seconds');
    if ($sec < 1) $sec = 1;
    if ($sec > 3600) $sec = 3600;

    global $wpdb;
    $t = ZQOS_DB::tables();
    $now = current_time('mysql');

    $accId = (int)($acc['id'] ?? 0);
    $profile = self::norm_profile($acc['profile'] ?? null);
    $profile['time_total_sec'] = max(0, (int)($profile['time_total_sec'] ?? 0) + $sec);

    $ok = $wpdb->update($t['accounts'], array(
      'profile' => wp_json_encode($profile),
      'updated_at' => $now,
    ), array('id' => $accId), array('%s','%s'), array('%d'));

    if ($ok === false){
      return new \WP_REST_Response(array('ok'=>false,'message'=>'DB update failed'), 500);
    }

    ZQOS_DB::log_event('panel_time', $accId, null, array('seconds' => $sec));

    return rest_ensure_response(array('ok' => true, 'time_total_sec' => (int)$profile['time_total_sec']));
  }



  public static function route_accounts_list(\WP_REST_Request $req){
    $acc = ZQOS_Auth::require_account();
    if (!$acc) return new \WP_REST_Response(array('ok'=>false,'message'=>'Unauthorized'), 401);
    if (!ZQOS_Auth::actor_has_permission('super_admin')){
      return new \WP_REST_Response(array('ok'=>false,'message'=>'Brak uprawnień.'), 403);
    }

    global $wpdb;
    $t = ZQOS_DB::tables();

    $rows = $wpdb->get_results("SELECT id, login, perms FROM {$t['accounts']} ORDER BY login ASC LIMIT 2000", ARRAY_A);
    $out = array();
    foreach ($rows as $r){
      $perms = json_decode((string)($r['perms'] ?? ''), true);
      if (!is_array($perms)) $perms = array();
      $sellerName = '';
      if (!empty($perms['seller']) && is_array($perms['seller']) && !empty($perms['seller']['name'])){
        $sellerName = (string)$perms['seller']['name'];
      }
      $out[] = array(
        'id' => (int)$r['id'],
        'login' => (string)$r['login'],
        'seller_name' => $sellerName,
      );
    }

    return rest_ensure_response(array(
      'ok' => true,
      'accounts' => $out,
    ));
  }

  public static function route_switch_account(\WP_REST_Request $req){
    $acc = ZQOS_Auth::require_account();
    if (!$acc) return new \WP_REST_Response(array('ok'=>false,'message'=>'Unauthorized'), 401);
    if (!ZQOS_Auth::actor_has_permission('super_admin')){
      return new \WP_REST_Response(array('ok'=>false,'message'=>'Brak uprawnień.'), 403);
    }

    $targetId = (int)$req->get_param('account_id');
    if ($targetId <= 0){
      return new \WP_REST_Response(array('ok'=>false,'message'=>'Niepoprawne konto.'), 400);
    }

    $target = ZQOS_Auth::get_account_public($targetId);
    if (!$target){
      return new \WP_REST_Response(array('ok'=>false,'message'=>'Konto nie istnieje.'), 404);
    }

    $actorId = ZQOS_Auth::actor_id();
    if (!$actorId){
      return new \WP_REST_Response(array('ok'=>false,'message'=>'Brak aktora.'), 403);
    }

    // jeśli przełączamy się na konto aktora (Super Admin) - kończymy impersonację
    $actorForToken = ((int)$actorId === (int)$targetId) ? null : (int)$actorId;

    $issued = ZQOS_Auth::issue_token_for_account($targetId, $actorForToken);
    if (!$issued || empty($issued['token'])){
      return new \WP_REST_Response(array('ok'=>false,'message'=>'Nie udało się utworzyć sesji.'), 500);
    }

    // Sync HttpOnly cookie z nowym tokenem
    ZQOS_Auth::set_auth_cookie($issued['token'], $issued['expires_at'] ?? null);

    ZQOS_DB::log_event('account_switch', (int)$actorId, null, array(
      'from_account_id' => (int)($acc['id'] ?? 0),
      'to_account_id' => (int)$targetId,
      'actor_account_id' => (int)$actorId,
      'impersonation' => $actorForToken ? 1 : 0,
    ));

    return rest_ensure_response(array(
      'ok' => true,
      'token' => $issued['token'],
      'expires_at' => $issued['expires_at'],
      'account' => $target,
      'actor' => ZQOS_Auth::actor_summary(),
      'can_switch' => true,
    ));
  }


}
