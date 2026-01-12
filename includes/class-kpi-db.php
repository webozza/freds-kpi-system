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

    $sql = "CREATE TABLE $table (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
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
      UNIQUE KEY kpi_date (kpi_date)
    ) $charset;";

    dbDelta($sql);
  }

  public static function month_range($year, $month) {
    $start = sprintf('%04d-%02d-01', (int)$year, (int)$month);
    $end = date('Y-m-d', strtotime("$start +1 month"));
    return [$start, $end];
  }

  public static function get_month_rows($year, $month) {
    global $wpdb;
    [$start, $end] = self::month_range($year, $month);

    $rows = $wpdb->get_results(
      $wpdb->prepare(
        "SELECT * FROM " . self::table() . " WHERE kpi_date >= %s AND kpi_date < %s ORDER BY kpi_date ASC",
        $start, $end
      ),
      ARRAY_A
    );

    // Map by date for easy lookup
    $map = [];
    foreach ($rows as $r) {
      $map[$r['kpi_date']] = $r;
    }
    return $map;
  }

  public static function sanitize_numeric($key, $val) {
    if ($val === '' || $val === null) return 0;

    // money fields
    if ($key === 'quote_value' || $key === 'sales_value') {
      return round((float)$val, 2);
    }
    return (int)round((float)$val);
  }

  public static function upsert_day($date, $data) {
    global $wpdb;
    $table = self::table();

    $allowed = self::fields();

    $clean = [];
    foreach ($allowed as $k) {
      if (!array_key_exists($k, $data)) continue;
      $clean[$k] = self::sanitize_numeric($k, $data[$k]);
    }

    $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE kpi_date=%s", $date));
    if ($existing) {
      return $wpdb->update($table, $clean, ['kpi_date' => $date]);
    }

    $clean['kpi_date'] = $date;
    return $wpdb->insert($table, $clean);
  }

  public static function get_month_totals($year, $month) {
    global $wpdb;
    [$start, $end] = self::month_range($year, $month);

    $sumCols = [];
    foreach (self::fields() as $f) {
      $sumCols[] = "SUM($f) AS $f";
    }

    $sql = "SELECT " . implode(", ", $sumCols) . " FROM " . self::table() . " WHERE kpi_date >= %s AND kpi_date < %s";
    $row = $wpdb->get_row($wpdb->prepare($sql, $start, $end), ARRAY_A);

    return is_array($row) ? $row : [];
  }

  public static function get_year_monthly_totals($year) {
    global $wpdb;
    $year = (int)$year;

    $sumCols = [];
    foreach (self::fields() as $f) {
      $sumCols[] = "SUM($f) AS $f";
    }

    $sql = "
      SELECT
        MONTH(kpi_date) AS m,
        " . implode(", ", $sumCols) . "
      FROM " . self::table() . "
      WHERE YEAR(kpi_date) = %d
      GROUP BY MONTH(kpi_date)
      ORDER BY MONTH(kpi_date) ASC
    ";

    $rows = $wpdb->get_results($wpdb->prepare($sql, $year), ARRAY_A);
    $out = [];

    foreach ($rows as $r) {
      $m = (int)$r['m'];
      unset($r['m']);
      $out[$m] = $r;
    }

    // Ensure all 12 months exist
    for ($m = 1; $m <= 12; $m++) {
      if (!isset($out[$m])) {
        $out[$m] = array_fill_keys(self::fields(), 0);
      }
    }

    return $out;
  }
}
