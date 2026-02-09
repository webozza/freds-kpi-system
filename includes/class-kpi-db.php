<?php
if (!defined('ABSPATH')) exit;

class KPI_DB {

  // ----------- tables -----------
  public static function table_daily() {
    global $wpdb;
    return $wpdb->prefix . 'kpi_daily';
  }

  public static function table_channels() {
    global $wpdb;
    return $wpdb->prefix . 'kpi_channels';
  }

  public static function table_leads_daily() {
    global $wpdb;
    return $wpdb->prefix . 'kpi_leads_daily';
  }

  // Pipeline fields (stay in kpi_daily)
  public static function pipeline_fields() {
    return [
      'calls','appointments','quotes','quote_value','sales','sales_value',
    ];
  }

  // ----------- activation / schema -----------
  public static function activate() {
    global $wpdb;
    $daily = self::table_daily();
    $channels = self::table_channels();
    $leadsDaily = self::table_leads_daily();
    $charset = $wpdb->get_charset_collate();

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    // Existing table (keep as-is for installs that already have it)
    $sqlDaily = "CREATE TABLE $daily (
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

    dbDelta($sqlDaily);

    // New: channels table (unlimited)
    $sqlChannels = "CREATE TABLE $channels (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      user_id BIGINT UNSIGNED NOT NULL,
      name VARCHAR(191) NOT NULL,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      sort_order INT NOT NULL DEFAULT 0,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY user_id (user_id),
      KEY is_active (is_active)
    ) $charset;";

    // New: daily leads per channel
    $sqlLeadsDaily = "CREATE TABLE $leadsDaily (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      user_id BIGINT UNSIGNED NOT NULL,
      channel_id BIGINT UNSIGNED NOT NULL,
      kpi_date DATE NOT NULL,
      value INT NOT NULL DEFAULT 0,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY uniq_user_channel_date (user_id, channel_id, kpi_date),
      KEY user_date (user_id, kpi_date),
      KEY channel_id (channel_id)
    ) $charset;";

    dbDelta($sqlChannels);
    dbDelta($sqlLeadsDaily);

    self::maybe_upgrade_table();
    self::maybe_seed_defaults_for_all_users();
    self::maybe_migrate_old_fixed_leads_once();
  }

  private static function maybe_upgrade_table() {
    global $wpdb;
    $table = self::table_daily();

    $col = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM $table LIKE %s", "user_id"));
    if (!$col) {
      $wpdb->query("ALTER TABLE $table ADD COLUMN user_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER id");
    }

    // Ensure unique key exists (ignore errors)
    $wpdb->query("ALTER TABLE $table DROP INDEX kpi_date");
    $wpdb->query("ALTER TABLE $table ADD UNIQUE KEY user_date (user_id, kpi_date)");
  }

  // ----------- defaults / channels -----------
  public static function ensure_default_channels($user_id) {
    global $wpdb;
    $t = self::table_channels();
    $user_id = (int)$user_id;

    $existing = (int)$wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM $t WHERE user_id=%d",
      $user_id
    ));
    if ($existing > 0) return;

    $defaults = [
      'Website',
      'Google Ads',
      'Houzz',
      'Facebook',
      'Referrals',
      'Repeat customers',
      'Instagram',
      'Walk-ins',
    ];

    $order = 0;
    foreach ($defaults as $name) {
      $wpdb->insert($t, [
        'user_id' => $user_id,
        'name' => $name,
        'is_active' => 1,
        'sort_order' => $order++,
      ], ['%d','%s','%d','%d']);
    }
  }

  private static function maybe_seed_defaults_for_all_users() {
    // safe, cheap: only runs when activate() runs
    // It seeds per-user defaults when they first visit via frontend anyway.
  }

  public static function get_channels($user_id, $only_active = true) {
    global $wpdb;
    $t = self::table_channels();
    $user_id = (int)$user_id;

    $where = "WHERE user_id=%d";
    if ($only_active) $where .= " AND is_active=1";

    return $wpdb->get_results($wpdb->prepare(
      "SELECT id, name, is_active, sort_order
       FROM $t
       $where
       ORDER BY sort_order ASC, id ASC",
      $user_id
    ), ARRAY_A);
  }

  public static function save_channels($user_id, array $rows) {
    global $wpdb;
    $t = self::table_channels();
    $user_id = (int)$user_id;

    $keep_ids = [];
    $order = 0;

    foreach ($rows as $r) {
      $name = trim((string)($r['name'] ?? ''));
      if ($name === '') continue;

      $is_active = !empty($r['is_active']) ? 1 : 0;
      $id = isset($r['id']) ? (int)$r['id'] : 0;

      if ($id > 0) {
        $keep_ids[] = $id;
        $wpdb->update($t, [
          'name' => $name,
          'is_active' => $is_active,
          'sort_order' => $order++,
        ], [
          'id' => $id,
          'user_id' => $user_id,
        ], ['%s','%d','%d'], ['%d','%d']);
      } else {
        $wpdb->insert($t, [
          'user_id' => $user_id,
          'name' => $name,
          'is_active' => $is_active,
          'sort_order' => $order++,
        ], ['%d','%s','%d','%d']);
        $keep_ids[] = (int)$wpdb->insert_id;
      }
    }

    // If user removed everything, keep table empty (allowed)
    if (!empty($keep_ids)) {
      $placeholders = implode(',', array_fill(0, count($keep_ids), '%d'));
      $sql = "DELETE FROM $t WHERE user_id=%d AND id NOT IN ($placeholders)";
      $wpdb->query($wpdb->prepare($sql, array_merge([$user_id], $keep_ids)));
    } else {
      $wpdb->delete($t, ['user_id' => $user_id], ['%d']);
    }
  }

  // ----------- date helpers -----------
  public static function month_range($year, $month) {
    $start = sprintf('%04d-%02d-01', (int)$year, (int)$month);
    $end = date('Y-m-d', strtotime("$start +1 month"));
    return [$start, $end];
  }

  // ----------- pipeline data (kpi_daily) -----------
  public static function sanitize_numeric($key, $val) {
    if ($val === '' || $val === null) return 0;

    if ($key === 'quote_value' || $key === 'sales_value') {
      return round((float)$val, 2);
    }
    return (int)round((float)$val);
  }

  public static function upsert_pipeline_day($user_id, $date, $data) {
    global $wpdb;
    $table = self::table_daily();
    $user_id = (int)$user_id;

    $allowed = self::pipeline_fields();

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

  public static function get_month_pipeline_rows($user_id, $year, $month) {
    global $wpdb;
    $user_id = (int)$user_id;
    [$start, $end] = self::month_range($year, $month);

    $rows = $wpdb->get_results(
      $wpdb->prepare(
        "SELECT * FROM " . self::table_daily() . " WHERE user_id=%d AND kpi_date >= %s AND kpi_date < %s ORDER BY kpi_date ASC",
        $user_id, $start, $end
      ),
      ARRAY_A
    );

    $map = [];
    foreach ($rows as $r) $map[$r['kpi_date']] = $r;
    return $map;
  }

  public static function get_month_pipeline_totals($user_id, $year, $month) {
    global $wpdb;
    $user_id = (int)$user_id;
    [$start, $end] = self::month_range($year, $month);

    $sumCols = [];
    foreach (self::pipeline_fields() as $f) $sumCols[] = "SUM($f) AS $f";

    $sql = "SELECT " . implode(", ", $sumCols) . " FROM " . self::table_daily() . " WHERE user_id=%d AND kpi_date >= %s AND kpi_date < %s";
    $row = $wpdb->get_row($wpdb->prepare($sql, $user_id, $start, $end), ARRAY_A);

    return is_array($row) ? $row : [];
  }

  public static function get_year_pipeline_monthly_totals($user_id, $year) {
    global $wpdb;
    $user_id = (int)$user_id;
    $year = (int)$year;

    $sumCols = [];
    foreach (self::pipeline_fields() as $f) $sumCols[] = "SUM($f) AS $f";

    $sql = "
      SELECT
        MONTH(kpi_date) AS m,
        " . implode(", ", $sumCols) . "
      FROM " . self::table_daily() . "
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
      if (!isset($out[$m])) $out[$m] = array_fill_keys(self::pipeline_fields(), 0);
    }

    return $out;
  }

  // ----------- leads data (kpi_leads_daily) -----------
  public static function upsert_lead_day($user_id, $channel_id, $date, $value) {
    global $wpdb;
    $t = self::table_leads_daily();
    $user_id = (int)$user_id;
    $channel_id = (int)$channel_id;
    $value = (int)round((float)$value);

    $existing = $wpdb->get_var($wpdb->prepare(
      "SELECT id FROM $t WHERE user_id=%d AND channel_id=%d AND kpi_date=%s",
      $user_id, $channel_id, $date
    ));

    if ($existing) {
      return $wpdb->update($t, ['value' => $value], ['id' => (int)$existing], ['%d'], ['%d']);
    }

    return $wpdb->insert($t, [
      'user_id' => $user_id,
      'channel_id' => $channel_id,
      'kpi_date' => $date,
      'value' => $value,
    ], ['%d','%d','%s','%d']);
  }

  public static function get_month_leads_map($user_id, $year, $month) {
    global $wpdb;
    $t = self::table_leads_daily();
    $user_id = (int)$user_id;
    [$start, $end] = self::month_range($year, $month);

    $rows = $wpdb->get_results($wpdb->prepare(
      "SELECT kpi_date, channel_id, value
       FROM $t
       WHERE user_id=%d AND kpi_date >= %s AND kpi_date < %s",
      $user_id, $start, $end
    ), ARRAY_A);

    // map[date][channel_id] = value
    $map = [];
    foreach ($rows as $r) {
      $d = $r['kpi_date'];
      $cid = (int)$r['channel_id'];
      if (!isset($map[$d])) $map[$d] = [];
      $map[$d][$cid] = (int)$r['value'];
    }
    return $map;
  }

  public static function get_month_leads_totals_by_channel($user_id, $year, $month) {
    global $wpdb;
    $t = self::table_leads_daily();
    $user_id = (int)$user_id;
    [$start, $end] = self::month_range($year, $month);

    $rows = $wpdb->get_results($wpdb->prepare(
      "SELECT channel_id, SUM(value) AS total
       FROM $t
       WHERE user_id=%d AND kpi_date >= %s AND kpi_date < %s
       GROUP BY channel_id",
      $user_id, $start, $end
    ), ARRAY_A);

    $out = [];
    foreach ($rows as $r) $out[(int)$r['channel_id']] = (int)$r['total'];
    return $out;
  }

  public static function get_year_leads_monthly_totals($user_id, $year) {
    global $wpdb;
    $t = self::table_leads_daily();
    $user_id = (int)$user_id;
    $year = (int)$year;

    $rows = $wpdb->get_results($wpdb->prepare(
      "SELECT MONTH(kpi_date) AS m, channel_id, SUM(value) AS total
       FROM $t
       WHERE user_id=%d AND YEAR(kpi_date)=%d
       GROUP BY MONTH(kpi_date), channel_id
       ORDER BY MONTH(kpi_date) ASC",
      $user_id, $year
    ), ARRAY_A);

    // out[month][channel_id] = total
    $out = [];
    for ($m=1; $m<=12; $m++) $out[$m] = [];

    foreach ($rows as $r) {
      $m = (int)$r['m'];
      $cid = (int)$r['channel_id'];
      $out[$m][$cid] = (int)$r['total'];
    }

    return $out;
  }

  // ----------- one-time migration from old fixed leads columns -----------
  private static function maybe_migrate_old_fixed_leads_once() {
    $flag = get_option('kpi_unlimited_channels_migrated_v1');
    if ($flag) return;

    global $wpdb;
    $daily = self::table_daily();

    // Find users with any kpi_daily rows
    $userIds = $wpdb->get_col("SELECT DISTINCT user_id FROM $daily WHERE user_id>0");
    if (!$userIds) {
      update_option('kpi_unlimited_channels_migrated_v1', 1);
      return;
    }

    // Old fixed lead columns => default channel names in same order as ensure_default_channels()
    $oldCols = [
      'leads_website' => 'Website',
      'leads_google_ads' => 'Google Ads',
      'leads_houzz' => 'Houzz',
      'leads_facebook' => 'Facebook',
      'leads_referrals' => 'Referrals',
      'leads_repeat_customers' => 'Repeat customers',
      'leads_instagram' => 'Instagram',
      'leads_walkins' => 'Walk-ins',
      // Old others become real channels too
      'leads_other_1' => 'Other 1',
      'leads_other_2' => 'Other 2',
    ];

    foreach ($userIds as $uid) {
      $uid = (int)$uid;
      self::ensure_default_channels($uid);

      // Ensure "Other 1/2" channels exist
      $existing = self::get_channels($uid, false);
      $nameToId = [];
      foreach ($existing as $c) $nameToId[strtolower($c['name'])] = (int)$c['id'];

      foreach (['Other 1','Other 2'] as $nm) {
        if (!isset($nameToId[strtolower($nm)])) {
          $wpdb->insert(self::table_channels(), [
            'user_id' => $uid,
            'name' => $nm,
            'is_active' => 0,
            'sort_order' => 999,
          ], ['%d','%s','%d','%d']);
          $nameToId[strtolower($nm)] = (int)$wpdb->insert_id;
        }
      }

      // Refresh mapping
      $existing = self::get_channels($uid, false);
      $nameToId = [];
      foreach ($existing as $c) $nameToId[strtolower($c['name'])] = (int)$c['id'];

      // Pull all old daily rows
      $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT kpi_date, " . implode(", ", array_keys($oldCols)) . " FROM $daily WHERE user_id=%d",
        $uid
      ), ARRAY_A);

      foreach ($rows as $r) {
        $date = $r['kpi_date'];
        foreach ($oldCols as $col => $nm) {
          $val = isset($r[$col]) ? (int)$r[$col] : 0;
          if ($val <= 0) continue;
          $cid = $nameToId[strtolower($nm)] ?? 0;
          if ($cid) self::upsert_lead_day($uid, $cid, $date, $val);
        }
      }
    }

    update_option('kpi_unlimited_channels_migrated_v1', 1);
  }
}
