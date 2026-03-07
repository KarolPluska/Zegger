<?php
if (!defined('ABSPATH')) { exit; }

final class ZQOS_Auth {

  private static $current = null;
  private static $current_token_hash = null;
  private static $current_actor = null;

  public static function init(){
    // no hooks
  }

    private static function sanitize_token($t){
    $t = is_string($t) ? trim($t) : '';
    if ($t === '') return '';
    // Token wydawany przez bin2hex(random_bytes(32)) => 64 znaki hex
    if (!preg_match('/^[a-f0-9]{64}$/i', $t)) return '';
    return strtolower($t);
  }

  private static function header_token(){
    $h = null;

    // Authorization: Bearer ...
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) $h = (string) $_SERVER['HTTP_AUTHORIZATION'];
    if (!$h && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) $h = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];

    if ($h && stripos($h, 'bearer ') === 0){
      return self::sanitize_token(trim(substr($h, 7)));
    }

    // fallback custom header
    if (isset($_SERVER['HTTP_X_ZQ_TOKEN'])) return self::sanitize_token(trim((string)$_SERVER['HTTP_X_ZQ_TOKEN']));

    return '';
  }

  private static function cookie_token(){
    if (!isset($_COOKIE['zqos_token'])) return '';
    return self::sanitize_token(trim((string)$_COOKIE['zqos_token']));
  }

  public static function bearer_token(){
    // Header ma priorytet, ale jeśli jest nieaktualny (np. stary localStorage override),
    // to cookie może uratować sesję w iframe.
    $t = self::header_token();
    if ($t) return $t;
    return self::cookie_token();
  }

  public static function set_auth_cookie($token, $expires_at){
    $token = self::sanitize_token($token);
    if (!$token) return;

    $expTs = 0;
    if (is_string($expires_at) && $expires_at){
      $ts = strtotime($expires_at);
      if ($ts) $expTs = (int)$ts;
    }
    if ($expTs <= 0) $expTs = time() + (12 * HOUR_IN_SECONDS);

    if (PHP_VERSION_ID < 70300){
      @setcookie('zqos_token', $token, $expTs, '/; samesite=Lax', '', is_ssl(), true);
    } else {
      @setcookie('zqos_token', $token, array(
        'expires' => $expTs,
        'path' => '/',
        'secure' => is_ssl(),
        'httponly' => true,
        'samesite' => 'Lax',
      ));
    }

    $_COOKIE['zqos_token'] = $token;
  }

  public static function clear_auth_cookie(){
    $expTs = time() - 3600;
    if (PHP_VERSION_ID < 70300){
      @setcookie('zqos_token', '', $expTs, '/; samesite=Lax', '', is_ssl(), true);
    } else {
      @setcookie('zqos_token', '', array(
        'expires' => $expTs,
        'path' => '/',
        'secure' => is_ssl(),
        'httponly' => true,
        'samesite' => 'Lax',
      ));
    }
    unset($_COOKIE['zqos_token']);
  }


  public static function token_hash($token){
    if (!$token) return '';
    return hash('sha256', $token);
  }

  public static function current_account(){
    return self::$current;
  }

    public static function require_account(){
    $acc = self::current_account();
    if ($acc) return $acc;

    // 2 źródła: header (Bearer/X-ZQ-Token) oraz HttpOnly cookie
    $candidates = array();
    $hTok = self::header_token();
    $cTok = self::cookie_token();
    if ($hTok) $candidates[] = $hTok;
    if ($cTok && $cTok !== $hTok) $candidates[] = $cTok;

    if (!$candidates) return null;

    global $wpdb;
    $t = ZQOS_DB::tables();
    $now = current_time('mysql');

    foreach ($candidates as $token){
      $hash = self::token_hash($token);
      if (!$hash) continue;

      $row = $wpdb->get_row($wpdb->prepare(
        "SELECT a.id, a.login, a.perms, a.fixed_client, a.profile, tok.actor_account_id, tok.expires_at
         FROM {$t['tokens']} tok
         JOIN {$t['accounts']} a ON a.id = tok.account_id
         WHERE tok.token_hash = %s AND tok.expires_at > %s
         LIMIT 1",
        $hash, $now
      ), ARRAY_A);

      if (!$row) continue;

      // update last_seen best-effort
      $wpdb->update($t['tokens'], array(
        'last_seen' => $now,
      ), array(
        'token_hash' => $hash,
      ), array('%s'), array('%s'));

      self::$current = array(
        'id' => (int)$row['id'],
        'login' => (string)$row['login'],
        'perms' => self::decode_json($row['perms']),
        'fixed_client' => self::decode_json($row['fixed_client']),
        'profile' => self::decode_json($row['profile'] ?? null),
        'actor_id' => !empty($row['actor_account_id']) ? (int)$row['actor_account_id'] : null,
      );
      self::$current_token_hash = $hash;

      return self::$current;
    }

    return null;
  }

  public static function decode_json($raw){
    if ($raw === null || $raw === '') return null;
    $j = json_decode((string)$raw, true);
    return is_array($j) ? $j : null;
	}

  private static function settings(){
    return ZQOS_DB::settings();
  }

  private static function setting_int($key, $default){
    $s = self::settings();
    if (!is_array($s)) $s = array();
    $v = isset($s[$key]) ? (int)$s[$key] : (int)$default;
    return $v;
  }

  private static function client_ip(){
    $ip = '';
    if (isset($_SERVER['REMOTE_ADDR'])) $ip = (string)$_SERVER['REMOTE_ADDR'];
    $ip = trim($ip);
    if (strlen($ip) > 64) $ip = substr($ip, 0, 64);
    return $ip;
  }

  private static function client_ua(){
    $ua = '';
    if (isset($_SERVER['HTTP_USER_AGENT'])) $ua = (string)$_SERVER['HTTP_USER_AGENT'];
    $ua = trim($ua);
    if (strlen($ua) > 255) $ua = substr($ua, 0, 255);
    return $ua;
  }

  private static function login_rl_key($login, $ip){
    $login = strtolower(trim((string)$login));
    return 'zqos_login_rl_' . md5($login . '|' . $ip);
  }

  private static function login_rate_limited($login){
    // domyślnie: 10 prób / 10 minut / login+IP
    $attempts = self::setting_int('login_rate_attempts', 10);
    $windowMin = self::setting_int('login_rate_window_minutes', 10);

    if ($attempts < 3) $attempts = 3;
    if ($attempts > 50) $attempts = 50;
    if ($windowMin < 1) $windowMin = 1;
    if ($windowMin > 120) $windowMin = 120;

    $ip = self::client_ip();
    if (!$ip) return array(false, null);

    $key = self::login_rl_key($login, $ip);
    $cur = get_transient($key);
    if (!is_array($cur)) $cur = array('cnt' => 0, 'first' => time());

    $cnt = isset($cur['cnt']) ? (int)$cur['cnt'] : 0;
    $first = isset($cur['first']) ? (int)$cur['first'] : time();

    $age = time() - $first;
    if ($age < 0) $age = 0;

    if ($cnt >= $attempts && $age < ($windowMin * MINUTE_IN_SECONDS)){
      $retryIn = ($windowMin * MINUTE_IN_SECONDS) - $age;
      if ($retryIn < 0) $retryIn = 0;
      return array(true, $retryIn);
    }

    return array(false, null);
  }

  private static function login_rate_hit($login){
    $attempts = self::setting_int('login_rate_attempts', 10);
    $windowMin = self::setting_int('login_rate_window_minutes', 10);
    if ($attempts < 3) $attempts = 3;
    if ($attempts > 50) $attempts = 50;
    if ($windowMin < 1) $windowMin = 1;
    if ($windowMin > 120) $windowMin = 120;

    $ip = self::client_ip();
    if (!$ip) return;

    $key = self::login_rl_key($login, $ip);
    $cur = get_transient($key);
    if (!is_array($cur)) $cur = array('cnt' => 0, 'first' => time());

    $cnt = isset($cur['cnt']) ? (int)$cur['cnt'] : 0;
    $first = isset($cur['first']) ? (int)$cur['first'] : time();
    if ($first <= 0) $first = time();

    // jeżeli okno minęło, resetuj
    $age = time() - $first;
    if ($age >= ($windowMin * MINUTE_IN_SECONDS)){
      $cnt = 0;
      $first = time();
    }

    $cnt++;
    $ttl = max(60, $windowMin * MINUTE_IN_SECONDS);
    set_transient($key, array('cnt' => $cnt, 'first' => $first), $ttl);
  }

  private static function login_rate_clear($login){
    $ip = self::client_ip();
    if (!$ip) return;
    delete_transient(self::login_rl_key($login, $ip));
  }

  private static function enforce_token_limit($account_id){
    $limit = self::setting_int('max_active_tokens_per_account', 3);
    if ($limit < 1) $limit = 1;
    if ($limit > 20) $limit = 20;

    global $wpdb;
    $t = ZQOS_DB::tables();
    $now = current_time('mysql');

    // usuń wygasłe
    $wpdb->query($wpdb->prepare(
      "DELETE FROM {$t['tokens']} WHERE account_id = %d AND expires_at <= %s",
      (int)$account_id, $now
    ));

    $active = (int)$wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM {$t['tokens']} WHERE account_id = %d AND expires_at > %s",
      (int)$account_id, $now
    ));

    if ($active < $limit) return;

    // usuń najstarszy (najmniej aktywny) token
    $oldest = $wpdb->get_var($wpdb->prepare(
      "SELECT token_hash FROM {$t['tokens']}
       WHERE account_id = %d AND expires_at > %s
       ORDER BY last_seen IS NULL, last_seen ASC, created_at ASC
       LIMIT 1",
      (int)$account_id, $now
    ));

    if ($oldest){
      $wpdb->delete($t['tokens'], array('token_hash' => (string)$oldest), array('%s'));
      ZQOS_DB::log_event('token_revoked', (int)$account_id, null, array('reason' => 'max_active_tokens'));
    }
  }

  

  public static function actor_id(){
    $acc = self::require_account();
    if (!$acc) return null;
    $aid = isset($acc['actor_id']) ? (int)$acc['actor_id'] : 0;
    if ($aid > 0 && $aid !== (int)$acc['id']) return $aid;
    return (int)$acc['id'];
  }

  public static function actor_summary(){
    $acc = self::require_account();
    if (!$acc) return null;
    $aid = isset($acc['actor_id']) ? (int)$acc['actor_id'] : 0;
    if ($aid <= 0 || $aid === (int)$acc['id']) return null;

    if (is_array(self::$current_actor) && (int)(self::$current_actor['id'] ?? 0) === $aid){
      return self::$current_actor;
    }

    global $wpdb;
    $t = ZQOS_DB::tables();
    $row = $wpdb->get_row($wpdb->prepare(
      "SELECT id, login FROM {$t['accounts']} WHERE id = %d LIMIT 1",
      $aid
    ), ARRAY_A);
    if (!$row) return null;

    self::$current_actor = array(
      'id' => (int)$row['id'],
      'login' => (string)$row['login'],
    );
    return self::$current_actor;
  }

  public static function actor_has_permission($key){
    $acc = self::require_account();
    if (!$acc) return false;

    $aid = isset($acc['actor_id']) ? (int)$acc['actor_id'] : 0;
    if ($aid <= 0 || $aid === (int)$acc['id']){
      $perms = isset($acc['perms']) && is_array($acc['perms']) ? $acc['perms'] : array();
      return !empty($perms[$key]);
    }

    global $wpdb;
    $t = ZQOS_DB::tables();
    $raw = $wpdb->get_var($wpdb->prepare(
      "SELECT perms FROM {$t['accounts']} WHERE id = %d LIMIT 1",
      $aid
    ));
    $perms = self::decode_json($raw);
    if (!is_array($perms)) $perms = array();
    return !empty($perms[$key]);
  }

  public static function get_account_public($account_id){
    $account_id = (int)$account_id;
    if ($account_id <= 0) return null;

    global $wpdb;
    $t = ZQOS_DB::tables();
    $row = $wpdb->get_row($wpdb->prepare(
      "SELECT id, login, perms, fixed_client, profile FROM {$t['accounts']} WHERE id = %d LIMIT 1",
      $account_id
    ), ARRAY_A);

    if (!$row) return null;

    return array(
      'id' => (int)$row['id'],
      'login' => (string)$row['login'],
      'perms' => self::decode_json($row['perms']),
      'fixed_client' => self::decode_json($row['fixed_client']),
      'profile' => self::decode_json($row['profile'] ?? null),
    );
  }

  public static function issue_token_for_account($account_id, $actor_account_id = null){
    $account_id = (int)$account_id;
    $actor_account_id = $actor_account_id ? (int)$actor_account_id : null;
    if ($account_id <= 0) return null;

    self::enforce_token_limit($account_id);

    $token = bin2hex(random_bytes(32)); // 64 znaki hex
    $hash = self::token_hash($token);

    $now = current_time('mysql');
    $hours = self::setting_int('session_hours', 12);
    if ($hours < 1) $hours = 1;
    if ($hours > 168) $hours = 168; // max 7 dni
    $expTs = current_time('timestamp') + ($hours * HOUR_IN_SECONDS);
    $exp = wp_date('Y-m-d H:i:s', $expTs);

    $ip = self::client_ip();
    if ($ip === '') $ip = null;
    $ua = self::client_ua();
    if ($ua === '') $ua = null;

    global $wpdb;
    $t = ZQOS_DB::tables();
    $wpdb->insert($t['tokens'], array(
      'account_id' => $account_id,
      'actor_account_id' => $actor_account_id,
      'token_hash' => $hash,
      'created_at' => $now,
      'expires_at' => $exp,
      'last_seen' => $now,
      'ip' => $ip,
      'ua' => $ua,
    ), array('%d','%d','%s','%s','%s','%s','%s','%s','%s'));

    return array('token' => $token, 'expires_at' => $exp);
  }



	public static function check_permission($key){
    $acc = self::require_account();
    if (!$acc) return false;
    $perms = isset($acc['perms']) && is_array($acc['perms']) ? $acc['perms'] : array();
    return !empty($perms[$key]);
  }

  public static function login($login, $password){
    global $wpdb;
    $t = ZQOS_DB::tables();

    $login = trim((string)$login);
    if (!$login || strlen($login) > 64) return array('ok' => false, 'message' => 'Niepoprawny login.');
    if (!is_string($password) || $password === '') return array('ok' => false, 'message' => 'Brak hasła.');

    // rate limit (login+IP)
    list($blocked, $retryIn) = self::login_rate_limited($login);
    if ($blocked){
      ZQOS_DB::log_event('login_blocked', null, null, array('login' => $login, 'ip' => self::client_ip()));
      $sec = is_int($retryIn) ? $retryIn : 0;
      return array('ok' => false, 'message' => 'Zbyt wiele prób logowania. Spróbuj ponownie za ' . (int)ceil($sec/60) . ' min.');
    }

    $row = $wpdb->get_row($wpdb->prepare(
      "SELECT id, login, pass_hash, perms, fixed_client, profile FROM {$t['accounts']} WHERE login = %s LIMIT 1",
      $login
    ), ARRAY_A);

    if (!$row || empty($row['pass_hash']) || !password_verify($password, $row['pass_hash'])){
      self::login_rate_hit($login);
      ZQOS_DB::log_event('login_failed', null, null, array('login' => $login, 'ip' => self::client_ip()));
      return array('ok' => false, 'message' => 'Błędny login lub hasło.');
    }

    // sukces -> wyczyść limiter
    self::login_rate_clear($login);

    // limit aktywnych sesji/tokenów
    self::enforce_token_limit((int)$row['id']);

    $token = bin2hex(random_bytes(32)); // 64 znaki hex
    $hash = self::token_hash($token);

    $now = current_time('mysql');
    $hours = self::setting_int('session_hours', 12);
    if ($hours < 1) $hours = 1;
    if ($hours > 168) $hours = 168; // max 7 dni
    $expTs = current_time('timestamp') + ($hours * HOUR_IN_SECONDS);
    $exp = wp_date('Y-m-d H:i:s', $expTs);

    $ip = self::client_ip();
    if ($ip === '') $ip = null;
    $ua = self::client_ua();
    if ($ua === '') $ua = null;

    $wpdb->insert($t['tokens'], array(
      'account_id' => (int)$row['id'],
      'actor_account_id' => null,
      'token_hash' => $hash,
      'created_at' => $now,
      'expires_at' => $exp,
      'last_seen' => $now,
      'ip' => $ip,
      'ua' => $ua,
    ), array('%d','%d','%s','%s','%s','%s','%s','%s','%s'));

    ZQOS_DB::log_event('login', (int)$row['id'], null, array('login' => $login));

    return array(
      'ok' => true,
      'token' => $token,
      'account' => array(
        'id' => (int)$row['id'],
        'login' => (string)$row['login'],
        'perms' => self::decode_json($row['perms']),
        'fixed_client' => self::decode_json($row['fixed_client']),
        'profile' => self::decode_json($row['profile'] ?? null),
      ),
      'expires_at' => $exp,
    );
  }

    public static function logout(){
    $acc = self::require_account();
    if (!$acc){
      self::clear_auth_cookie();
      return array('ok' => true);
    }

    global $wpdb;
    $t = ZQOS_DB::tables();

    if (self::$current_token_hash){
      $wpdb->delete($t['tokens'], array('token_hash' => self::$current_token_hash), array('%s'));
    }

    ZQOS_DB::log_event('logout', (int)$acc['id'], null, null);
    self::$current = null;
    self::$current_token_hash = null;
    self::$current_actor = null;

    self::clear_auth_cookie();
    return array('ok' => true);
  }
}
