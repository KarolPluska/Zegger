<?php
if (!defined('ABSPATH')) { exit; }

final class ZQOS_Sheets {

  const CRON_HOOK = 'zqos_sync_sheets';
  const LOCK_TRANSIENT = 'zqos_sync_lock';

  // Cache XLSX w ramach jednego sync-a (pobieramy workbook 1x, używamy dla wszystkich zakładek)
  private static $xlsx_loaded_pub = null;
  private static $xlsx_matrices_by_name = null; // [sheetName => ['headers'=>[], 'rows'=>[]]]

  public static function init(){
    add_filter('cron_schedules', array(__CLASS__, 'cron_schedules'));
    add_action(self::CRON_HOOK, array(__CLASS__, 'cron_run'));

    // Na wypadek gdyby admin zmienił interwał - dbamy o re-sync harmonogramu
    add_action('update_option_' . ZQOS_DB::OPT_SETTINGS, function($old, $new){
      self::reschedule();
    }, 10, 2);

    // Samonaprawa harmonogramu: przy częstych reinstalach (dezaktywuj/usuń/wgraj)
    // activation hook uruchamia się przed plugins_loaded, więc niestandardowe schedule
    // mogły nie być jeszcze zarejestrowane => wp_schedule_event() mogło się nie zapisać.
    // Jeśli nie ma zaplanowanego zdarzenia, ustaw je teraz.
    if (!wp_next_scheduled(self::CRON_HOOK)){
      self::reschedule(true);
    }
  }

  public static function activate(){
    // activation hook uruchamia się przed plugins_loaded => cron_schedules może nie być jeszcze podpięty.
    // Rejestrujemy tymczasowo nasze schedule, żeby wp_schedule_event() zadziałał.
    add_filter('cron_schedules', array(__CLASS__, 'cron_schedules'));
    self::reschedule(true);
    remove_filter('cron_schedules', array(__CLASS__, 'cron_schedules'));
    // pierwszy sync szybko po aktywacji
    if (!wp_next_scheduled(self::CRON_HOOK)){
      wp_schedule_single_event(time() + 60, self::CRON_HOOK);
    }
  }

  public static function deactivate(){
    $ts = wp_next_scheduled(self::CRON_HOOK);
    while ($ts){
      wp_unschedule_event($ts, self::CRON_HOOK);
      $ts = wp_next_scheduled(self::CRON_HOOK);
    }
    delete_transient(self::LOCK_TRANSIENT);
  }

  public static function cron_schedules($schedules){
    // 1/5/10/15 min
    if (!isset($schedules['zqos_1min'])){
      $schedules['zqos_1min'] = array('interval' => 1 * MINUTE_IN_SECONDS, 'display' => 'Co 1 minutę (ZQ Offer)');
    }
    if (!isset($schedules['zqos_5min'])){
      $schedules['zqos_5min'] = array('interval' => 5 * MINUTE_IN_SECONDS, 'display' => 'Co 5 minut (ZQ Offer)');
    }
    if (!isset($schedules['zqos_10min'])){
      $schedules['zqos_10min'] = array('interval' => 10 * MINUTE_IN_SECONDS, 'display' => 'Co 10 minut (ZQ Offer)');
    }
    if (!isset($schedules['zqos_15min'])){
      $schedules['zqos_15min'] = array('interval' => 15 * MINUTE_IN_SECONDS, 'display' => 'Co 15 minut (ZQ Offer)');
    }
    return $schedules;
  }

  public static function reschedule($force = false){
    $settings = ZQOS_DB::settings();
    $mins = isset($settings['sync_interval_minutes']) ? (int)$settings['sync_interval_minutes'] : 10;
    $allowed = array(1,5,10,15);
    if (!in_array($mins, $allowed, true)) { $mins = 10; }
    $rec_map = array(1=>'zqos_1min', 5=>'zqos_5min', 10=>'zqos_10min', 15=>'zqos_15min');
    $rec = $rec_map[$mins];

    $next = wp_next_scheduled(self::CRON_HOOK);
    if ($next && !$force){
      // Jeśli częstotliwość się zgadza - zostaw.
      $events = _get_cron_array();
      if (is_array($events) && isset($events[$next][self::CRON_HOOK])){
        foreach ($events[$next][self::CRON_HOOK] as $k => $evt){
          if (!empty($evt['schedule']) && $evt['schedule'] === $rec){
            return;
          }
        }
      }
    }

    // Usuń stare i ustaw nowe
    if ($next){
      while ($next){
        wp_unschedule_event($next, self::CRON_HOOK);
        $next = wp_next_scheduled(self::CRON_HOOK);
      }
    }
    $ok = wp_schedule_event(time() + 120, $rec, self::CRON_HOOK);
    if (!$ok){
      // Fallback: chociaż pojedynczy sync, żeby cache w ogóle powstał.
      wp_schedule_single_event(time() + 120, self::CRON_HOOK);
      ZQOS_DB::log_event('cron_schedule_failed', null, null, array('schedule' => $rec, 'mins' => $mins));
    }
  }

  public static function cron_run(){
    self::sync_all(false);
  }

  public static function sync_all($force = false){
    if (get_transient(self::LOCK_TRANSIENT) && !$force){
      return array('ok' => false, 'message' => 'Sync już trwa (lock).');
    }
    set_transient(self::LOCK_TRANSIENT, 1, 10 * MINUTE_IN_SECONDS);

    try{
      $res = self::do_sync_all();
      delete_transient(self::LOCK_TRANSIENT);
      return $res;
    }catch(Exception $e){
      delete_transient(self::LOCK_TRANSIENT);
      return array('ok' => false, 'message' => $e->getMessage());
    }
  }

  
  private static function validate_matrix($name, $matrix, &$errors){
    if (!is_array($matrix)) { $errors[] = $name . ': brak danych (matrix).'; return; }
    $headers = isset($matrix['headers']) && is_array($matrix['headers']) ? $matrix['headers'] : array();
    if (!$headers) { $errors[] = $name . ': brak nagłówków w arkuszu.'; return; }

    $h = array_map(function($x){ return mb_strtolower(trim((string)$x)); }, $headers);

    $hasCat = false; $hasProd = false; $hasPrice = false;
    foreach ($h as $v){
      if ($v === 'kategoria') $hasCat = true;
      if ($v === 'produkt' || strpos($v, 'nazwa produktu') !== false) $hasProd = true;
      if (strpos($v, 'cena netto') !== false) $hasPrice = true;
      if (!$hasPrice && strpos($v, 'cena') !== false && strpos($v, 'netto') !== false) $hasPrice = true;
    }

    // Nie blokujemy synca - tylko raport w errors.
    if (!$hasCat || !$hasProd){
      $errors[] = $name . ': brak wymaganych kolumn (Kategoria/Produkt) - sprawdź arkusz.';
    }
    if (!$hasPrice){
      $errors[] = $name . ': brak kolumny ceny netto (np. "Cena netto [PLN]") - panel może działać niepoprawnie.';
    }
  }


private static function do_sync_all(){
    global $wpdb;
    $t = ZQOS_DB::tables();
    $settings = ZQOS_DB::settings();

    $pub = isset($settings['sheet_pub_id']) ? trim((string)$settings['sheet_pub_id']) : '';
    if (!$pub){
      return array('ok' => false, 'message' => 'Brak sheet_pub_id w ustawieniach.');
    }
    $tabs = isset($settings['tabs']) && is_array($settings['tabs']) ? $settings['tabs'] : array();
    if (!$tabs){
      return array('ok' => false, 'message' => 'Brak zakładek (tabs) w ustawieniach.');
    }

    $data = array();
    $errors = array();
    $started = microtime(true);

    foreach ($tabs as $tab){
      $name = isset($tab['name']) ? (string)$tab['name'] : '';
      $gid  = isset($tab['gid']) ? (string)$tab['gid'] : '';
      $name = trim($name);
      $gid  = trim($gid);
      if (!$name || !$gid){
        $errors[] = 'Niepoprawna konfiguracja tab (brak name/gid).';
        continue;
      }

      $matrix = self::fetch_matrix_for_gid($pub, $gid, $name, $errors);
      if (!$matrix){
        // fetch_matrix_for_gid dodaje błąd do $errors
        continue;
      }
      self::validate_matrix($name, $matrix, $errors);
      $data[$name] = $matrix;
    }

    $fetched_at = current_time('mysql');
    $dataHash = hash('sha256', wp_json_encode($data));
    $cache = array(
      'data_hash' => $dataHash,

      'ok' => empty($errors),
      'fetched_at' => $fetched_at,
      'duration_ms' => (int) round((microtime(true) - $started) * 1000),
      'errors' => $errors,
      'data' => $data,
    );

    // zapisz w DB (id=1)
    $wpdb->replace($t['sheets'], array(
      'id' => 1,
      'cache' => wp_json_encode($cache),
      'fetched_at' => $fetched_at,
      'updated_at' => $fetched_at,
    ), array('%d','%s','%s','%s'));

    // oraz do pliku w uploads (dla szybkości i niezależności od DB)
    self::write_cache_file($cache);

    ZQOS_DB::log_event('sheets_sync', null, null, array('ok' => $cache['ok'], 'errors' => $errors));

    return array('ok' => $cache['ok'], 'cache' => $cache);
  }

  /**
   * Pobiera XLSX całego dokumentu (Publish to web) i zwraca matrices wg nazwy arkusza.
   * Uwaga: to jest fallback na wypadek gdy Google zwraca pubhtml bez tabeli oraz /gviz = 404.
   */
  private static function ensure_xlsx_loaded($pub, &$errors, $contextName){
    $pub = trim((string)$pub);
    if (!$pub) return;

    if (self::$xlsx_loaded_pub === $pub && is_array(self::$xlsx_matrices_by_name)){
      return;
    }

    self::$xlsx_loaded_pub = $pub;
    self::$xlsx_matrices_by_name = null;

    $xlsxUrl = 'https://docs.google.com/spreadsheets/d/e/' . rawurlencode($pub) . '/pub?output=xlsx';

    $resp = wp_remote_get($xlsxUrl, array(
      'timeout' => 35,
      'redirection' => 8,
      'headers' => array(
        'Accept' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet, application/octet-stream;q=0.9, */*;q=0.8',
        'User-Agent' => 'ZQOfferSuite/' . ZQOS_VERSION . ' (' . home_url('/') . ')',
      ),
    ));

    if (is_wp_error($resp)){
      $errors[] = $contextName . ': XLSX: ' . $resp->get_error_message();
      return;
    }

    $code = (int) wp_remote_retrieve_response_code($resp);
    $body = (string) wp_remote_retrieve_body($resp);
    if ($code < 200 || $code >= 300 || !$body){
      $errors[] = $contextName . ': XLSX: HTTP ' . $code;
      return;
    }

    // Jeśli Google zwróci HTML (np. strona błędu), nie próbuj otwierać jako zip.
    $head = substr($body, 0, 200);
    if (strpos($head, '<!DOCTYPE') !== false || strpos($head, '<html') !== false){
      $errors[] = $contextName . ': XLSX: Google zwrócił HTML zamiast pliku .xlsx (sprawdź publikację arkusza).';
      return;
    }

    $parsed = self::xlsx_binary_to_matrices($body);
    if (!is_array($parsed) || empty($parsed)){
      $errors[] = $contextName . ': XLSX: nie można sparsować workbooka (brak arkuszy).';
      return;
    }

    self::$xlsx_matrices_by_name = $parsed;
  }

  private static function xlsx_binary_to_matrices($bin){
    if (!$bin || !is_string($bin)) return null;
    if (!class_exists('ZipArchive')) return null;

    $tmp = wp_tempnam('zqos_xlsx_');
    if (!$tmp) return null;
    @file_put_contents($tmp, $bin);

    $zip = new ZipArchive();
    $ok = $zip->open($tmp);
    if ($ok !== true){
      @unlink($tmp);
      return null;
    }

    $workbookXml = $zip->getFromName('xl/workbook.xml');
    $relsXml     = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if (!$workbookXml || !$relsXml){
      $zip->close();
      @unlink($tmp);
      return null;
    }

    $sharedStrings = array();
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedXml){
      $sharedStrings = self::parse_shared_strings($sharedXml);
    }

    $rels = self::parse_workbook_rels($relsXml);
    $sheets = self::parse_workbook_sheets($workbookXml);
    if (!$rels || !$sheets){
      $zip->close();
      @unlink($tmp);
      return null;
    }

    $out = array();
    foreach ($sheets as $sh){
      $name = isset($sh['name']) ? (string)$sh['name'] : '';
      $rid  = isset($sh['rid']) ? (string)$sh['rid'] : '';
      if (!$name || !$rid) continue;
      if (!isset($rels[$rid])) continue;

      $target = $rels[$rid];
      $target = ltrim($target, '/');
      if (strpos($target, 'xl/') !== 0){
        $target = 'xl/' . $target;
      }
      $sheetXml = $zip->getFromName($target);
      if (!$sheetXml) continue;

      $rows = self::parse_sheet_rows($sheetXml, $sharedStrings);
      if (!is_array($rows) || empty($rows)) continue;

      $matrix = self::rows_to_matrix($rows);
      if (!$matrix) continue;

      $out[$name] = $matrix;
    }

    $zip->close();
    @unlink($tmp);
    return $out;
  }

  private static function parse_workbook_rels($xml){
    $sx = self::safe_simplexml($xml);
    if (!$sx) return null;
    $out = array();
    foreach ($sx->Relationship as $rel){
      $attrs = $rel->attributes();
      if (!$attrs) continue;
      $id = isset($attrs['Id']) ? (string)$attrs['Id'] : '';
      $target = isset($attrs['Target']) ? (string)$attrs['Target'] : '';
      if ($id && $target) $out[$id] = $target;
    }
    return $out;
  }

  private static function parse_workbook_sheets($xml){
    $sx = self::safe_simplexml($xml);
    if (!$sx) return null;
    // workbook.xml ma namespace - SimpleXML czasem ukrywa elementy; używamy xpath.
    $sheets = $sx->xpath('//*[local-name()="sheet"]');
    if (!$sheets) return null;
    $out = array();
    foreach ($sheets as $sh){
      $attrs = $sh->attributes();
      if (!$attrs) continue;
      $name = isset($attrs['name']) ? (string)$attrs['name'] : '';
      // r:id jest w namespace, więc bierzemy po local-name
      $rid = '';
      foreach ($attrs as $k => $v){
        if ((string)$k === 'id' || (string)$k === 'r:id'){
          $rid = (string)$v;
        }
      }
      if (!$rid){
        $ridAttr = $sh->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        if ($ridAttr && isset($ridAttr['id'])) $rid = (string)$ridAttr['id'];
      }
      if ($name && $rid){
        $out[] = array('name' => $name, 'rid' => $rid);
      }
    }
    return $out;
  }

  private static function parse_shared_strings($xml){
    $sx = self::safe_simplexml($xml);
    if (!$sx) return array();
    $out = array();
    // sharedStrings.xml ma <si><t> lub <si><r><t>
    $sis = $sx->xpath('//*[local-name()="si"]');
    if (!$sis) return array();
    foreach ($sis as $si){
      $txt = '';
      $ts = $si->xpath('.//*[local-name()="t"]');
      if ($ts){
        foreach ($ts as $t){
          $txt .= (string)$t;
        }
      }
      $txt = self::norm_cell($txt);
      $out[] = $txt;
    }
    return $out;
  }

  private static function parse_sheet_rows($xml, $sharedStrings){
    $sx = self::safe_simplexml($xml);
    if (!$sx) return null;
    $rows = $sx->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]');
    if (!$rows) return null;

    $out = array();
    $maxCols = 0;

    foreach ($rows as $row){
      $cells = $row->xpath('./*[local-name()="c"]');
      if (!$cells) continue;

      $line = array();
      $rowMax = -1;

      foreach ($cells as $c){
        $attrs = $c->attributes();
        if (!$attrs || !isset($attrs['r'])) continue;

        $ref = (string)$attrs['r'];
        if (!preg_match('/^([A-Z]+)(\d+)$/i', $ref, $m)) continue;
        $colLetters = strtoupper($m[1]);
        $colIdx = self::col_letters_to_index($colLetters);
        if ($colIdx < 0) continue;

        $t = isset($attrs['t']) ? (string)$attrs['t'] : '';
        $v = '';

        if ($t === 's'){
          $vNode = $c->xpath('./*[local-name()="v"]');
          $idx = ($vNode && isset($vNode[0])) ? (int)((string)$vNode[0]) : -1;
          $v = ($idx >= 0 && isset($sharedStrings[$idx])) ? $sharedStrings[$idx] : '';
        }elseif ($t === 'inlineStr'){
          $tNode = $c->xpath('./*[local-name()="is"]//*[local-name()="t"]');
          if ($tNode && isset($tNode[0])){
            $v = (string)$tNode[0];
          }
        }else{
          $vNode = $c->xpath('./*[local-name()="v"]');
          if ($vNode && isset($vNode[0])){
            $v = (string)$vNode[0];
          }
        }

        $v = self::norm_cell($v);
        $line[$colIdx] = $v;
        if ($colIdx > $rowMax) $rowMax = $colIdx;
      }

      if ($rowMax < 0) continue;

      // Uzupełnij luki
      $filled = array();
      for ($i = 0; $i <= $rowMax; $i++){
        $filled[] = isset($line[$i]) ? $line[$i] : '';
      }
      $out[] = $filled;
      if (count($filled) > $maxCols) $maxCols = count($filled);
    }

    if (!$out || $maxCols < 1) return null;
    // wyrównaj długości
    foreach ($out as $i => $r){
      $c = count($r);
      if ($c < $maxCols){
        for ($k = $c; $k < $maxCols; $k++) $out[$i][] = '';
      }
    }
    return $out;
  }

  private static function rows_to_matrix($rows){
    if (!is_array($rows) || empty($rows)) return null;

    $maxCols = 0;
    foreach ($rows as $r){
      if (is_array($r) && count($r) > $maxCols) $maxCols = count($r);
    }
    if ($maxCols < 1) return null;

    // pad
    foreach ($rows as $i => $r){
      if (!is_array($r)) { $rows[$i] = array_fill(0, $maxCols, ''); continue; }
      $c = count($r);
      if ($c < $maxCols){
        for ($k = $c; $k < $maxCols; $k++) $rows[$i][] = '';
      }
    }

    // header row heuristic (XLSX często ma kilka wierszy nagłówków)
    $headerIdx = 0;
    $bestScore = -1;
    $scanMax = min(30, count($rows));

    $normalize = function($s){
      $s = trim((string)$s);
      if ($s === '') return '';
      $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
      $s = function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
      $s = preg_replace('/[^0-9a-ząęłńóśżź\s]+/u', ' ', $s);
      $s = preg_replace('/\s+/u', ' ', $s);
      return trim($s);
    };

    for ($i = 0; $i < $scanMax; $i++){
      $row = $rows[$i];
      if (!is_array($row)) continue;

      $score = 0;
      $hasCat = false;
      $hasProd = false;
      $hasRal = 0;

      foreach ($row as $cell){
        $lc = $normalize($cell);
        if ($lc === '') continue;

        if (strpos($lc, 'kategoria') !== false){ $score += 3; $hasCat = true; }
        if (strpos($lc, 'produkt') !== false || strpos($lc, 'nazwa') !== false){ $score += 3; $hasProd = true; }
        if (strpos($lc, 'podkategoria') !== false || strpos($lc, 'subkategoria') !== false){ $score += 2; }
        if (strpos($lc, 'wymiar') !== false || strpos($lc, 'rozmiar') !== false || strpos($lc, 'wariant') !== false){ $score += 1; }

        if (preg_match('/(^|\b)(6005|7016|8017|9005)(\b|$)/u', $lc)){
          $score += 1;
          $hasRal++;
        }
      }

      if ($hasCat && $hasProd) $score += 2;
      if ($hasRal >= 2) $score += 2;

      if ($score > $bestScore){
        $bestScore = $score;
        $headerIdx = $i;
      }
    }

    // minimalny próg - jeśli nie znaleziono sensownego headera, zostaw 0
    if ($bestScore < 4){
      $headerIdx = 0;
    }

    $headers = $rows[$headerIdx];
    $dataRows = array_slice($rows, $headerIdx + 1);

    // usuń puste wiersze
    $cleanRows = array();
    foreach ($dataRows as $r){
      $nonEmpty = false;
      foreach ($r as $v){
        if ((string)$v !== ''){ $nonEmpty = true; break; }
      }
      if ($nonEmpty) $cleanRows[] = $r;
    }

    foreach ($headers as $i => $h){
      $headers[$i] = trim((string)$h);
    }

    return array('headers' => $headers, 'rows' => $cleanRows);
  }

  private static function safe_simplexml($xml){
    if (!$xml || !is_string($xml)) return null;
    $internal = libxml_use_internal_errors(true);
    $sx = simplexml_load_string($xml);
    libxml_clear_errors();
    libxml_use_internal_errors($internal);
    return $sx ?: null;
  }

  private static function col_letters_to_index($letters){
    $letters = strtoupper((string)$letters);
    $n = 0;
    $len = strlen($letters);
    if ($len < 1) return -1;
    for ($i = 0; $i < $len; $i++){
      $c = ord($letters[$i]);
      if ($c < 65 || $c > 90) return -1;
      $n = $n * 26 + ($c - 64);
    }
    return $n - 1; // 0-based
  }

  private static function norm_cell($txt){
    $txt = (string)$txt;
    $txt = html_entity_decode($txt, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $txt = preg_replace('/\s+/u', ' ', $txt);
    return trim($txt);
  }

  public static function get_cache(){
    // 1) plik
    $file = self::cache_file();
    if ($file && file_exists($file)){
      $raw = file_get_contents($file);
      if ($raw){
        $json = json_decode($raw, true);
        if (is_array($json) && isset($json['data'])){
          return $json;
        }
      }
    }

    // 2) DB
    global $wpdb;
    $t = ZQOS_DB::tables();
    $raw = $wpdb->get_var("SELECT cache FROM {$t['sheets']} WHERE id = 1");
    if ($raw){
      $json = json_decode($raw, true);
      if (is_array($json) && isset($json['data'])){
        return $json;
      }
    }
    return null;
  }

  private static function cache_file(){
    $u = wp_upload_dir();
    $dir = trailingslashit($u['basedir']) . 'zq-offer';
    if (!is_dir($dir)){
      wp_mkdir_p($dir);
    }
    return trailingslashit($dir) . 'sheets-cache.json';
  }

  private static function write_cache_file($cache){
    $file = self::cache_file();
    if (!$file) return;
    @file_put_contents($file, wp_json_encode($cache), LOCK_EX);
  }

  public static function parse_gviz_json($body){
    // GViz zwykle: google.visualization.Query.setResponse({...});
    $pos1 = strpos($body, '{');
    $pos2 = strrpos($body, '}');
    if ($pos1 === false || $pos2 === false || $pos2 <= $pos1){
      return null;
    }
    $jsonStr = substr($body, $pos1, $pos2 - $pos1 + 1);
    $json = json_decode($jsonStr, true);
    return is_array($json) ? $json : null;
  }

  public static function table_to_matrix($gviz){
    if (!isset($gviz['table']) || !is_array($gviz['table'])) return null;
    $t = $gviz['table'];

    $cols = isset($t['cols']) && is_array($t['cols']) ? $t['cols'] : array();
    $rows = isset($t['rows']) && is_array($t['rows']) ? $t['rows'] : array();

    if (!$cols) return null;

    $headers = array();
    foreach ($cols as $c){
      $label = '';
      if (isset($c['label'])) $label = (string)$c['label'];
      $headers[] = trim($label);
    }

    $outRows = array();
    foreach ($rows as $r){
      if (!isset($r['c']) || !is_array($r['c'])) continue;
      $cells = $r['c'];
      $line = array();
      foreach ($cells as $cell){
        if ($cell === null){
          $line[] = '';
          continue;
        }
        if (is_array($cell)){
          // prefer f (formatted), else v
          if (array_key_exists('f', $cell) && $cell['f'] !== null && $cell['f'] !== ''){
            $line[] = (string)$cell['f'];
          }elseif (array_key_exists('v', $cell) && $cell['v'] !== null){
            // v może być num, bool, string
            $line[] = is_scalar($cell['v']) ? (string)$cell['v'] : '';
          }else{
            $line[] = '';
          }
        }else{
          $line[] = is_scalar($cell) ? (string)$cell : '';
        }
      }
      $outRows[] = $line;
    }

    return array(
      'headers' => $headers,
      'rows' => $outRows,
    );
  }

  /**
   * Pobranie danych zakładki (gid) z opublikowanego arkusza.
   * - Preferuje GViz (szybkie), ale dla linków typu /d/e/ bywa 404.
   * - Fallback: pubhtml + parsowanie HTML tabeli (działa stabilnie dla "Publish to web").
   */
  private static function fetch_matrix_for_gid($pub, $gid, $name, &$errors){
    $pub = trim((string)$pub);
    $gid = trim((string)$gid);
    if (!$pub || !$gid){
      $errors[] = $name . ': brak pub/gid.';
      return null;
    }

    // 1) GViz (może nie działać dla /d/e/ w części przypadków)
    $gvizUrl = 'https://docs.google.com/spreadsheets/d/e/' . rawurlencode($pub) . '/gviz/tq?tqx=out:json&gid=' . rawurlencode($gid) . '&headers=1';
    $resp = wp_remote_get($gvizUrl, array(
      'timeout' => 25,
      'redirection' => 3,
      'headers' => array(
        'Accept' => 'text/javascript, application/json;q=0.9, */*;q=0.8',
        'User-Agent' => 'ZQOfferSuite/' . ZQOS_VERSION . ' (' . home_url('/') . ')',
      ),
    ));

    if (!is_wp_error($resp)){
      $code = (int) wp_remote_retrieve_response_code($resp);
      $body = (string) wp_remote_retrieve_body($resp);
      if ($code >= 200 && $code < 300 && $body){
        $json = self::parse_gviz_json($body);
        if (is_array($json)){
          $matrix = self::table_to_matrix($json);
          if ($matrix){
            return $matrix;
          }
        }
      }
    }

    // 2) Fallback: pubhtml (stabilne dla Twojego typu linku)
    // Uwaga: używamy query ?gid=...&single=true (NIE #gid=...) żeby dostać konkretną zakładkę.
    $htmlUrl = 'https://docs.google.com/spreadsheets/d/e/' . rawurlencode($pub) . '/pubhtml?gid=' . rawurlencode($gid) . '&single=true';
    $resp2 = wp_remote_get($htmlUrl, array(
      'timeout' => 30,
      'redirection' => 3,
      'headers' => array(
        'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
        'User-Agent' => 'ZQOfferSuite/' . ZQOS_VERSION . ' (' . home_url('/') . ')',
      ),
    ));

    if (is_wp_error($resp2)){
      $errors[] = $name . ': ' . $resp2->get_error_message();
      return null;
    }
    $code2 = (int) wp_remote_retrieve_response_code($resp2);
    $body2 = (string) wp_remote_retrieve_body($resp2);
    if ($code2 < 200 || $code2 >= 300 || !$body2){
      $errors[] = $name . ': HTTP ' . $code2;
      return null;
    }

    $matrix2 = self::pubhtml_to_matrix($body2);
    if (!$matrix2){
      // 3) Fallback 2: XLSX (pobieramy całe XLSX i parsujemy po nazwie arkusza)
      self::ensure_xlsx_loaded($pub, $errors, $name);
      if (is_array(self::$xlsx_matrices_by_name) && !empty(self::$xlsx_matrices_by_name)){
        // dopasuj po nazwie (case-insensitive)
        $wanted = trim((string)$name);
        if ($wanted !== ''){
          if (isset(self::$xlsx_matrices_by_name[$wanted])){
            return self::$xlsx_matrices_by_name[$wanted];
          }
          $map = array();
          foreach (self::$xlsx_matrices_by_name as $k => $v){
            $lk = function_exists('mb_strtolower') ? mb_strtolower($k, 'UTF-8') : strtolower($k);
            $map[$lk] = $k;
          }
          $lw = function_exists('mb_strtolower') ? mb_strtolower($wanted, 'UTF-8') : strtolower($wanted);
          if (isset($map[$lw])){
            $real = $map[$lw];
            return self::$xlsx_matrices_by_name[$real];
          }
        }

        $avail = array_keys(self::$xlsx_matrices_by_name);
        $errors[] = $name . ': XLSX pobrano, ale nie znaleziono arkusza o tej nazwie. Dostępne: ' . implode(', ', array_slice($avail, 0, 12)) . (count($avail) > 12 ? '…' : '');
        return null;
      }

      $errors[] = $name . ': nie można sparsować pubhtml (brak tabeli) i brak XLSX fallback.';
      return null;
    }
    return $matrix2;
  }

  /**
   * Parsuje HTML wygenerowany przez "Publish to web" (pubhtml) do matrix: headers + rows.
   * Implementacja jest defensywna (Google zmienia markup).
   */
  private static function pubhtml_to_matrix($html){
    if (!$html || !is_string($html)) return null;

    if (!class_exists('DOMDocument')) return null;

    $internal = libxml_use_internal_errors(true);
    $dom = new DOMDocument();

    // DOMDocument potrafi psuć UTF-8 bez deklaracji
    $wrapped = "<?xml encoding=\"UTF-8\">" . $html;
    $ok = $dom->loadHTML($wrapped, LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    libxml_use_internal_errors($internal);
    if (!$ok) return null;

    $xpath = new DOMXPath($dom);

    // Preferuj table.waffle (Google Sheets pubhtml)
    $tables = $xpath->query('//table[contains(concat(" ", normalize-space(@class), " "), " waffle ")]');
    if (!$tables || $tables->length < 1){
      $tables = $xpath->query('//table');
    }
    if (!$tables || $tables->length < 1) return null;

    $table = $tables->item(0);
    if (!$table) return null;

    $rows = array();
    $maxCols = 0;

    foreach ($table->getElementsByTagName('tr') as $tr){
      $line = array();
      // bierz td i th (czasem header jest th)
      foreach ($tr->childNodes as $cell){
        if (!($cell instanceof DOMElement)) continue;
        $tag = strtolower($cell->tagName);
        if ($tag !== 'td' && $tag !== 'th') continue;
        $txt = $cell->textContent;
        $txt = preg_replace('/\s+/u', ' ', (string)$txt);
        $txt = trim($txt);
        $line[] = $txt;
      }
      if (empty($line)) continue;
      $rows[] = $line;
      if (count($line) > $maxCols) $maxCols = count($line);
    }

    if (!$rows || $maxCols < 1) return null;

    // wyrównaj długości wierszy
    foreach ($rows as $i => $r){
      $c = count($r);
      if ($c < $maxCols){
        for ($k = $c; $k < $maxCols; $k++) $rows[$i][] = '';
      }
    }

    // znajdź wiersz nagłówka (heurystyka) - szukamy typowych kolumn
    $headerIdx = 0;
    $needles = array('kategoria','podkategoria','produkt','wymiar','ral','cena','netto','brutto');
    $scanMax = min(6, count($rows));
    for ($i = 0; $i < $scanMax; $i++){
      $hit = 0;
      foreach ($rows[$i] as $cell){
        $lc = function_exists('mb_strtolower') ? mb_strtolower($cell, 'UTF-8') : strtolower($cell);
        foreach ($needles as $n){
          if ($lc === $n){ $hit++; break; }
        }
      }
      if ($hit >= 2){ $headerIdx = $i; break; }
    }

    $headers = $rows[$headerIdx];
    $dataRows = array_slice($rows, $headerIdx + 1);

    // usuń całkowicie puste wiersze
    $cleanRows = array();
    foreach ($dataRows as $r){
      $nonEmpty = false;
      foreach ($r as $v){
        if ($v !== ''){ $nonEmpty = true; break; }
      }
      if ($nonEmpty) $cleanRows[] = $r;
    }

    // nagłówki też czyść
    foreach ($headers as $i => $h){
      $headers[$i] = trim((string)$h);
    }

    return array(
      'headers' => $headers,
      'rows' => $cleanRows,
    );
  }
}
