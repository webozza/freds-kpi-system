<?php
if (!defined('ABSPATH')) exit;

class KPI_DB {

  public static function table() {
    global $wpdb;
    return $wpdb->prefix . 'kpi_daily';
  }

  public static function fields() {
    return [
      'leads_website','leads_google_ads','leads_houzz','leads_facebook','leads_referrals',
      'leads_repeat_customers','leads_instagram','leads_walkins','leads_other_1','leads_other_2',
      'calls','appointments','quotes','quote_value','sales','sales_value',
    ];
  }

  public static function activate() {
    global $wpdb;
    $table = self::table();
    $charset = $wpdb->get_charset_collate();

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    // Create (fresh installs)
    $sql = "CREATE TABLE $table (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
      kpi_date DATE NOT NULL,

      leads_website INT NOT NULL DEFAULT 0,
      leads_google_ads INT NOT NULL DEFAULT 0,
      leads_houzz INT NOT NULL DEFAULT 0,
      leads_facebook INT NOT NULL DEFAULT 0,
      leads_referrals INT NOT NULL DEFAULT 0,
      leads_repeat_customers INT NOT NULL DEFAULT 0,
      leads_instagram INT NOT NULL DEFAULT 0,
      leads_walkins INT NOT NULL DEFAULT 0,
      leads_other_1 INT NOT NULL DEFAULT 0,
      leads_other_2 INT NOT NULL DEFAULT 0,

      calls INT NOT NULL DEFAULT 0,
      appointments INT NOT NULL DEFAULT 0,
      quotes INT NOT NULL DEFAULT 0,
      quote_value DECIMAL(12,2) NOT NULL DEFAULT 0.00,
      sales INT NOT NULL DEFAULT 0,
      sales_value DECIMAL(12,2) NOT NULL DEFAULT 0.00,

      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

      PRIMARY KEY (id),
      UNIQUE KEY user_date (user_id, kpi_date),
      KEY user_id (user_id),
      KEY kpi_date (kpi_date)
    ) $charset;";

    dbDelta($sql);

    // Upgrade older installs (add user_id + unique key)
    self::maybe_upgrade_table();
  }

  private static function maybe_upgrade_table() {
    global $wpdb;
    $table = self::table();

    $col = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM $table LIKE %s", "user_id"));
    if (!$col) {
      $wpdb->query("ALTER TABLE $table ADD COLUMN user_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER id");
    }

    // Ensure unique key exists (ignore errors)
    $wpdb->query("ALTER TABLE $table DROP INDEX kpi_date");
    $wpdb->query("ALTER TABLE $table ADD UNIQUE KEY user_date (user_id, kpi_date)");
  }

  public static function month_range($year, $month) {
    $start = sprintf('%04d-%02d-01', (int)$year, (int)$month);
    $end = date('Y-m-d', strtotime("$start +1 month"));
    return [$start, $end];
  }

  public static function sanitize_numeric($key, $val) {
    if ($val === '' || $val === null) return 0;

    if ($key === 'quote_value' || $key === 'sales_value') {
      return round((float)$val, 2);
    }
    return (int)round((float)$val);
  }

  public static function upsert_day($user_id, $date, $data) {
    global $wpdb;
    $table = self::table();
    $user_id = (int)$user_id;

    $allowed = self::fields();

    $clean = [];
    foreach ($allowed as $k) {
      if (!array_key_exists($k, $data)) continue;
      $clean[$k] = self::sanitize_numeric($k, $data[$k]);
    }

    $existing = $wpdb->get_var($wpdb->prepare(
      "SELECT id FROM $table WHERE user_id=%d AND kpi_date=%s",
      $user_id, $date
    ));

    if ($existing) {
      return $wpdb->update($table, $clean, ['user_id' => $user_id, 'kpi_date' => $date]);
    }

    $clean['user_id'] = $user_id;
    $clean['kpi_date'] = $date;
    return $wpdb->insert($table, $clean);
  }

  public static function get_month_rows($user_id, $year, $month) {
    global $wpdb;
    $user_id = (int)$user_id;
    [$start, $end] = self::month_range($year, $month);

    $rows = $wpdb->get_results(
      $wpdb->prepare(
        "SELECT * FROM " . self::table() . " WHERE user_id=%d AND kpi_date >= %s AND kpi_date < %s ORDER BY kpi_date ASC",
        $user_id, $start, $end
      ),
      ARRAY_A
    );

    $map = [];
    foreach ($rows as $r) {
      $map[$r['kpi_date']] = $r;
    }
    return $map;
  }

  public static function get_month_totals($user_id, $year, $month) {
    global $wpdb;
    $user_id = (int)$user_id;
    [$start, $end] = self::month_range($year, $month);

    $sumCols = [];
    foreach (self::fields() as $f) $sumCols[] = "SUM($f) AS $f";

    $sql = "SELECT " . implode(", ", $sumCols) . " FROM " . self::table() . " WHERE user_id=%d AND kpi_date >= %s AND kpi_date < %s";
    $row = $wpdb->get_row($wpdb->prepare($sql, $user_id, $start, $end), ARRAY_A);

    return is_array($row) ? $row : [];
  }

  public static function get_year_monthly_totals($user_id, $year) {
    global $wpdb;
    $user_id = (int)$user_id;
    $year = (int)$year;

    $sumCols = [];
    foreach (self::fields() as $f) $sumCols[] = "SUM($f) AS $f";

    $sql = "
      SELECT
        MONTH(kpi_date) AS m,
        " . implode(", ", $sumCols) . "
      FROM " . self::table() . "
      WHERE user_id=%d AND YEAR(kpi_date) = %d
      GROUP BY MONTH(kpi_date)
      ORDER BY MONTH(kpi_date) ASC
    ";

    $rows = $wpdb->get_results($wpdb->prepare($sql, $user_id, $year), ARRAY_A);
    $out = [];

    foreach ($rows as $r) {
      $m = (int)$r['m'];
      unset($r['m']);
      $out[$m] = $r;
    }

    for ($m = 1; $m <= 12; $m++) {
      if (!isset($out[$m])) $out[$m] = array_fill_keys(self::fields(), 0);
    }

    return $out;
  }
}
