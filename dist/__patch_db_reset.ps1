$path = 'zegger-erp\includes\class-zerp-db.php'
$raw = [System.IO.File]::ReadAllText((Resolve-Path $path), [System.Text.Encoding]::UTF8)

if ($raw -notmatch 'public static function reset_clean_start\(') {
$insert = @"

    public static function reset_clean_start(): array
    {
        global $wpdb;

        if (class_exists('ZERP_Maintenance')) {
            ZERP_Maintenance::deactivate();
        }

        $google_hook = class_exists('ZERP_Sources_Google') ? ZERP_Sources_Google::CRON_HOOK : 'zerp_google_sync';
        $ts = wp_next_scheduled($google_hook);
        while ($ts) {
            wp_unschedule_event($ts, $google_hook);
            $ts = wp_next_scheduled($google_hook);
        }

        $dropped_tables = 0;
        foreach (array_values(self::tables()) as $table_name) {
            $table_name = str_replace('`', '', (string) $table_name);
            $res = $wpdb->query("DROP TABLE IF EXISTS `{$table_name}`");
            if ($res !== false) {
                $dropped_tables++;
            }
        }

        $options_table = $wpdb->options;
        $deleted_options = (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM `{$options_table}` WHERE option_name LIKE %s",
            'zerp_%'
        ));

        $deleted_transients = (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM `{$options_table}` WHERE option_name LIKE %s OR option_name LIKE %s",
            '_transient_zerp_%',
            '_transient_timeout_zerp_%'
        ));

        self::activate();

        if (class_exists('ZERP_Maintenance')) {
            ZERP_Maintenance::activate();
        }

        return array(
            'ok' => true,
            'prefix' => $wpdb->prefix,
            'tables_dropped' => $dropped_tables,
            'options_deleted' => $deleted_options,
            'transients_deleted' => $deleted_transients,
        );
    }
"@

$raw = $raw.Replace(
    "    public static function log_maintenance(string `$action_key, array `$meta = array()): void",
    $insert + "`r`n    public static function log_maintenance(string `$action_key, array `$meta = array()): void"
)
}

$enc = New-Object System.Text.UTF8Encoding($false)
[System.IO.File]::WriteAllText((Resolve-Path $path), $raw, $enc)