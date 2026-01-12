<?php
if (!defined('ABSPATH')) exit;

class KPI_Admin {

  const MENU_SLUG = 'kpi-calculator';

  public static function init() {
    add_action('admin_menu', [__CLASS__, 'admin_menu']);
    add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue']);

    add_action('admin_post_kpi_save_month', [__CLASS__, 'handle_save_month']);
    add_action('admin_post_kpi_save_labels', [__CLASS__, 'handle_save_labels']);
    add_action('admin_post_kpi_save_active_months', [__CLASS__, 'handle_save_active_months']);
  }

  public static function admin_menu() {
    add_menu_page(
      'KPI Calculator',
      'KPI Calculator',
      'manage_options',
      self::MENU_SLUG,
      [__CLASS__, 'render_page'],
      'dashicons-chart-line',
      56
    );
  }

  public static function enqueue($hook) {
    if ($hook !== 'toplevel_page_' . self::MENU_SLUG) return;

    wp_enqueue_style('kpi-calc-admin', KPI_CALC_URL . 'assets/admin.css', [], KPI_CALC_VERSION);
    wp_enqueue_script('kpi-calc-admin', KPI_CALC_URL . 'assets/admin.js', ['jquery'], KPI_CALC_VERSION, true);

    wp_localize_script('kpi-calc-admin', 'kpiCalc', [
      'currencyDecimals' => 2,
      'ratioDecimals' => 6,
    ]);
  }

  // -----------------------
  // Options (labels + codes)
  // -----------------------
  public static function get_other_labels() {
    $opt = get_option('kpi_calc_lead_labels', []);
    return [
      'leads_other_1' => isset($opt['leads_other_1']) && $opt['leads_other_1'] !== '' ? $opt['leads_other_1'] : 'Other',
      'leads_other_2' => isset($opt['leads_other_2']) && $opt['leads_other_2'] !== '' ? $opt['leads_other_2'] : 'Other',
    ];
  }

  public static function get_active_months($year) {
    $year = (int)$year;
    $key = 'kpi_calc_active_months_' . $year;
    $opt = get_option($key, []);

    $out = [];
    for ($m = 1; $m <= 12; $m++) {
      $out[$m] = isset($opt[$m]) ? (int)$opt[$m] : 0;
    }
    return $out;
  }

  public static function set_active_months($year, $months) {
    $year = (int)$year;
    $key = 'kpi_calc_active_months_' . $year;

    $clean = [];
    for ($m = 1; $m <= 12; $m++) {
      $clean[$m] = isset($months[$m]) ? 1 : 0;
    }

    update_option($key, $clean, false);
  }

  // -----------------------
  // Rendering
  // -----------------------
  public static function render_page() {
    if (!current_user_can('manage_options')) return;

    $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'activity';
    if (!in_array($tab, ['activity', 'monthly'], true)) $tab = 'activity';

    $year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
    $month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');

    $year = max(2000, min(2100, $year));
    $month = max(1, min(12, $month));

    $labels = self::get_other_labels();

    ?>
    <div class="wrap kpi-calc-wrap">
      <h1>KPI Calculator</h1>

      <?php if (!empty($_GET['saved']) && $_GET['saved'] === '1'): ?>
        <div class="notice notice-success is-dismissible"><p>Saved.</p></div>
      <?php endif; ?>

      <h2 class="nav-tab-wrapper">
        <a class="nav-tab <?php echo $tab === 'activity' ? 'nav-tab-active' : ''; ?>"
           href="<?php echo esc_url(self::url(['tab' => 'activity', 'year' => $year, 'month' => $month])); ?>">
          Activity
        </a>
        <a class="nav-tab <?php echo $tab === 'monthly' ? 'nav-tab-active' : ''; ?>"
           href="<?php echo esc_url(self::url(['tab' => 'monthly', 'year' => $year])); ?>">
          Monthly figures
        </a>
      </h2>

      <div class="kpi-topbar">
        <form method="get" class="kpi-filter">
          <input type="hidden" name="page" value="<?php echo esc_attr(self::MENU_SLUG); ?>">
          <input type="hidden" name="tab" value="<?php echo esc_attr($tab); ?>">

          <label>Year</label>
          <input type="number" name="year" value="<?php echo esc_attr($year); ?>" min="2000" max="2100" class="kpi-year">

          <?php if ($tab === 'activity'): ?>
            <label>Month</label>
            <select name="month">
              <?php for ($m=1;$m<=12;$m++): ?>
                <option value="<?php echo $m; ?>" <?php selected($m, $month); ?>>
                  <?php echo esc_html(date('F', mktime(0,0,0,$m,1,$year))); ?>
                </option>
              <?php endfor; ?>
            </select>
          <?php endif; ?>

          <button class="button">Go</button>
        </form>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="kpi-settings-mini">
          <?php wp_nonce_field('kpi_save_labels'); ?>
          <input type="hidden" name="action" value="kpi_save_labels">
          <input type="hidden" name="redirect" value="<?php echo esc_attr(self::current_url()); ?>">

          <div class="kpi-mini-row">
            <label>Other #1 label</label>
            <input type="text" name="labels[leads_other_1]" value="<?php echo esc_attr($labels['leads_other_1']); ?>">
          </div>
          <div class="kpi-mini-row">
            <label>Other #2 label</label>
            <input type="text" name="labels[leads_other_2]" value="<?php echo esc_attr($labels['leads_other_2']); ?>">
          </div>
          <button class="button button-secondary">Save labels</button>
        </form>
      </div>

      <?php
      if ($tab === 'activity') {
        self::render_activity($year, $month, $labels);
      } else {
        self::render_monthly($year, $labels);
      }
      ?>
    </div>
    <?php
  }

  private static function render_activity($year, $month, $labels) {
    $daysInMonth = (int)date('t', strtotime(sprintf('%04d-%02d-01', $year, $month)));
    $rowsByDate = KPI_DB::get_month_rows($year, $month);

    $leadMetrics = [
      ['key' => 'leads_website', 'label' => 'Website', 'type' => 'int'],
      ['key' => 'leads_google_ads', 'label' => 'Google Ads', 'type' => 'int'],
      ['key' => 'leads_houzz', 'label' => 'Houzz', 'type' => 'int'],
      ['key' => 'leads_facebook', 'label' => 'Facebook', 'type' => 'int'],
      ['key' => 'leads_referrals', 'label' => 'Referrals', 'type' => 'int'],
      ['key' => 'leads_repeat_customers', 'label' => 'Repeat customers', 'type' => 'int'],
      ['key' => 'leads_instagram', 'label' => 'Instagram', 'type' => 'int'],
      ['key' => 'leads_walkins', 'label' => 'Walk-ins', 'type' => 'int'],
      ['key' => 'leads_other_1', 'label' => $labels['leads_other_1'], 'type' => 'int'],
      ['key' => 'leads_other_2', 'label' => $labels['leads_other_2'], 'type' => 'int'],
    ];

    $salesMetrics = [
      ['key' => 'calls', 'label' => 'Calls', 'type' => 'int'],
      ['key' => 'appointments', 'label' => 'Appointments', 'type' => 'int'],
      ['key' => 'quotes', 'label' => 'Quotes/Presentations', 'type' => 'int'],
      ['key' => 'quote_value', 'label' => '$$ Value', 'type' => 'money'],
      ['key' => 'sales', 'label' => 'Sales', 'type' => 'int'],
      ['key' => 'sales_value', 'label' => 'Sales Value $$', 'type' => 'money'],
    ];

    $totals = KPI_DB::get_month_totals($year, $month);
    $monthTitle = date('F Y', strtotime(sprintf('%04d-%02d-01', $year, $month)));

    ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="kpi-form">
      <?php wp_nonce_field('kpi_save_month'); ?>
      <input type="hidden" name="action" value="kpi_save_month">
      <input type="hidden" name="redirect" value="<?php echo esc_attr(self::current_url()); ?>">
      <input type="hidden" name="year" value="<?php echo esc_attr($year); ?>">
      <input type="hidden" name="month" value="<?php echo esc_attr($month); ?>">

      <h2 class="kpi-h2">Activity — <?php echo esc_html($monthTitle); ?></h2>

      <div class="kpi-table-wrap">
        <table class="widefat striped kpi-grid" id="kpi-activity-grid">
          <thead>
            <tr>
              <th class="kpi-sticky-col">Marketing Pillars ↓</th>
              <?php for ($d=1;$d<=31;$d++): ?>
                <th class="<?php echo $d > $daysInMonth ? 'kpi-disabled-day' : ''; ?>">
                  <?php echo (int)$d; ?>
                </th>
              <?php endfor; ?>
              <th class="kpi-total-col">TOTAL</th>
            </tr>
          </thead>

          <tbody>
            <tr class="kpi-section-row"><td colspan="33"><strong>Leads</strong></td></tr>
            <?php self::render_metric_rows($year, $month, $daysInMonth, $rowsByDate, $leadMetrics); ?>

            <tr class="kpi-spacer"><td colspan="33"></td></tr>

            <tr class="kpi-section-row"><td colspan="33"><strong>Sales / Pipeline</strong></td></tr>
            <?php self::render_metric_rows($year, $month, $daysInMonth, $rowsByDate, $salesMetrics); ?>
          </tbody>
        </table>
      </div>

      <p>
        <button class="button button-primary">Save month</button>
      </p>
    </form>

    <?php
    // Totals + stats (like the bottom of Activity tab)
    $leadTotal = 0;
    foreach ($leadMetrics as $m) $leadTotal += (float)($totals[$m['key']] ?? 0);

    $calls = (float)($totals['calls'] ?? 0);
    $apps  = (float)($totals['appointments'] ?? 0);
    $quotes = (float)($totals['quotes'] ?? 0);
    $quoteVal = (float)($totals['quote_value'] ?? 0);
    $sales = (float)($totals['sales'] ?? 0);
    $salesVal = (float)($totals['sales_value'] ?? 0);

    $avgQuote = $quotes > 0 ? $quoteVal / $quotes : 0;
    $avgSale  = $sales > 0 ? $salesVal / $sales : 0;

    $callsFromLeads = $leadTotal > 0 ? $calls / $leadTotal : 0;
    $appsFromCalls  = $calls > 0 ? $apps / $calls : 0;
    $appsFromLeads  = $leadTotal > 0 ? $apps / $leadTotal : 0;
    $quotesFromApps = $apps > 0 ? $quotes / $apps : 0;
    $salesFromQuotes= $quotes > 0 ? $sales / $quotes : 0;
    $salesFromCalls = $calls > 0 ? $sales / $calls : 0;
    $salesFromLeads = $leadTotal > 0 ? $sales / $leadTotal : 0;
    ?>

    <div class="kpi-summary" id="kpi-activity-summary"
         data-leads-total="<?php echo esc_attr($leadTotal); ?>"
         data-calls="<?php echo esc_attr($calls); ?>"
         data-appointments="<?php echo esc_attr($apps); ?>"
         data-quotes="<?php echo esc_attr($quotes); ?>"
         data-quote-value="<?php echo esc_attr($quoteVal); ?>"
         data-sales="<?php echo esc_attr($sales); ?>"
         data-sales-value="<?php echo esc_attr($salesVal); ?>"
    >
      <div class="kpi-card">
        <h3>Lead Data Totals</h3>
        <div class="kpi-kv"><span>Total Number Of Leads</span><strong class="kpi-num" data-kpi="total_leads"><?php echo esc_html((int)$leadTotal); ?></strong></div>
      </div>

      <div class="kpi-card">
        <h3>Sales Data Totals</h3>
        <div class="kpi-kv"><span>Calls</span><strong class="kpi-num" data-kpi="calls"><?php echo esc_html((int)$calls); ?></strong></div>
        <div class="kpi-kv"><span>Appointments</span><strong class="kpi-num" data-kpi="appointments"><?php echo esc_html((int)$apps); ?></strong></div>
        <div class="kpi-kv"><span>Quotes/Proposals Submitted</span><strong class="kpi-num" data-kpi="quotes"><?php echo esc_html((int)$quotes); ?></strong></div>
        <div class="kpi-kv"><span>Total Value of Quote/Proposals</span><strong class="kpi-num" data-kpi="quote_value"><?php echo esc_html(number_format($quoteVal, 2)); ?></strong></div>
        <div class="kpi-kv"><span>Number of Sales Won</span><strong class="kpi-num" data-kpi="sales"><?php echo esc_html((int)$sales); ?></strong></div>
        <div class="kpi-kv"><span>Total Value of Sales</span><strong class="kpi-num" data-kpi="sales_value"><?php echo esc_html(number_format($salesVal, 2)); ?></strong></div>
      </div>

      <div class="kpi-card">
        <h3>Statistics</h3>
        <div class="kpi-kv"><span>Average Quoted Value</span><strong class="kpi-num" data-kpi="avg_quote"><?php echo esc_html(number_format($avgQuote, 2)); ?></strong></div>
        <div class="kpi-kv"><span>Average Sales Value</span><strong class="kpi-num" data-kpi="avg_sale"><?php echo esc_html(number_format($avgSale, 2)); ?></strong></div>

        <div class="kpi-kv"><span>Calls from Leads</span><strong class="kpi-num" data-kpi="calls_from_leads"><?php echo esc_html(number_format($callsFromLeads, 6)); ?></strong></div>
        <div class="kpi-kv"><span>Appointments from Calls</span><strong class="kpi-num" data-kpi="apps_from_calls"><?php echo esc_html(number_format($appsFromCalls, 6)); ?></strong></div>
        <div class="kpi-kv"><span>Appointments from Leads</span><strong class="kpi-num" data-kpi="apps_from_leads"><?php echo esc_html(number_format($appsFromLeads, 6)); ?></strong></div>
        <div class="kpi-kv"><span>Quotes/Proposals From Appointments</span><strong class="kpi-num" data-kpi="quotes_from_apps"><?php echo esc_html(number_format($quotesFromApps, 6)); ?></strong></div>
        <div class="kpi-kv"><span>Sales from Quotes/Proposals</span><strong class="kpi-num" data-kpi="sales_from_quotes"><?php echo esc_html(number_format($salesFromQuotes, 6)); ?></strong></div>
        <div class="kpi-kv"><span>Sales from Calls</span><strong class="kpi-num" data-kpi="sales_from_calls"><?php echo esc_html(number_format($salesFromCalls, 6)); ?></strong></div>
        <div class="kpi-kv"><span>Sales from Leads</span><strong class="kpi-num" data-kpi="sales_from_leads"><?php echo esc_html(number_format($salesFromLeads, 6)); ?></strong></div>
      </div>
    </div>
    <?php
  }

  private static function render_monthly($year, $labels) {
    $monthly = KPI_DB::get_year_monthly_totals($year);
    $active = self::get_active_months($year);

    $leadRows = [
      ['key' => 'leads_website', 'label' => 'Website', 'type' => 'int'],
      ['key' => 'leads_google_ads', 'label' => 'Google Ads', 'type' => 'int'],
      ['key' => 'leads_houzz', 'label' => 'Houzz', 'type' => 'int'],
      ['key' => 'leads_facebook', 'label' => 'Facebook', 'type' => 'int'],
      ['key' => 'leads_referrals', 'label' => 'Referrals', 'type' => 'int'],
      ['key' => 'leads_repeat_customers', 'label' => 'Repeat customers', 'type' => 'int'],
      ['key' => 'leads_instagram', 'label' => 'Instagram', 'type' => 'int'],
      ['key' => 'leads_walkins', 'label' => 'Walk-ins', 'type' => 'int'],
      ['key' => 'leads_other_1', 'label' => $labels['leads_other_1'], 'type' => 'int'],
      ['key' => 'leads_other_2', 'label' => $labels['leads_other_2'], 'type' => 'int'],
    ];

    $salesRows = [
      ['key' => 'calls', 'label' => 'Calls', 'type' => 'int'],
      ['key' => 'appointments', 'label' => 'Appointments', 'type' => 'int'],
      ['key' => 'quotes', 'label' => 'Quotes/Proposals Submitted', 'type' => 'int'],
      ['key' => 'quote_value', 'label' => '$$ Value', 'type' => 'money'],
      ['key' => 'sales', 'label' => 'Sales', 'type' => 'int'],
      ['key' => 'sales_value', 'label' => 'Total Value of Sales', 'type' => 'money'],
    ];

    ?>
    <h2 class="kpi-h2">Monthly figures — <?php echo esc_html($year); ?></h2>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="kpi-active-months-form">
      <?php wp_nonce_field('kpi_save_active_months'); ?>
      <input type="hidden" name="action" value="kpi_save_active_months">
      <input type="hidden" name="redirect" value="<?php echo esc_attr(self::current_url()); ?>">
      <input type="hidden" name="year" value="<?php echo esc_attr($year); ?>">

      <div class="kpi-code-row">
        <div class="kpi-code-title"><strong>Code →</strong> (tick months to include in YTD & averages)</div>
        <div class="kpi-code-months">
          <?php for ($m=1;$m<=12;$m++): ?>
            <label class="kpi-code-month">
              <input type="checkbox" name="active[<?php echo $m; ?>]" value="1" <?php checked(1, $active[$m]); ?>>
              <?php echo esc_html(date('M', mktime(0,0,0,$m,1,$year))); ?>
            </label>
          <?php endfor; ?>
        </div>
        <button class="button button-secondary">Save code row</button>
      </div>
    </form>

    <div class="kpi-table-wrap">
      <table class="widefat striped kpi-grid kpi-monthly-grid" id="kpi-monthly-grid">
        <thead>
          <tr>
            <th class="kpi-sticky-col">Metric</th>
            <?php for ($m=1;$m<=12;$m++): ?>
              <th><?php echo esc_html(date('M', mktime(0,0,0,$m,1,$year))); ?></th>
            <?php endfor; ?>
            <th class="kpi-total-col">Year To Date Numbers</th>
            <th class="kpi-total-col">Averages</th>
          </tr>
        </thead>
        <tbody>
          <tr class="kpi-section-row"><td colspan="15"><strong>Lead Data Totals</strong></td></tr>
          <?php self::render_monthly_rows($monthly, $active, $leadRows); ?>

          <tr class="kpi-spacer"><td colspan="15"></td></tr>

          <tr class="kpi-section-row"><td colspan="15"><strong>Sales Data Totals</strong></td></tr>
          <?php self::render_monthly_rows($monthly, $active, $salesRows); ?>

          <tr class="kpi-spacer"><td colspan="15"></td></tr>

          <tr class="kpi-section-row"><td colspan="15"><strong>Statistics</strong></td></tr>
          <?php self::render_monthly_stats($monthly, $active); ?>
        </tbody>
      </table>
    </div>
    <?php
  }

  private static function render_metric_rows($year, $month, $daysInMonth, $rowsByDate, $metrics) {
    for ($i=0; $i<count($metrics); $i++) {
      $m = $metrics[$i];

      $rowTotal = 0;
      for ($d=1; $d<=31; $d++) {
        if ($d > $daysInMonth) continue;
        $date = sprintf('%04d-%02d-%02d', $year, $month, $d);
        $val = isset($rowsByDate[$date][$m['key']]) ? $rowsByDate[$date][$m['key']] : 0;
        $rowTotal += (float)$val;
      }

      echo '<tr class="kpi-metric-row" data-metric="' . esc_attr($m['key']) . '" data-type="' . esc_attr($m['type']) . '">';
      echo '<td class="kpi-sticky-col"><strong>' . esc_html($m['label']) . '</strong></td>';

      for ($d=1; $d<=31; $d++) {
        $disabled = $d > $daysInMonth;
        $date = sprintf('%04d-%02d-%02d', $year, $month, $d);
        $val = (!$disabled && isset($rowsByDate[$date][$m['key']])) ? $rowsByDate[$date][$m['key']] : 0;

        $step = $m['type'] === 'money' ? '0.01' : '1';
        $input = $disabled
          ? '<input type="number" class="kpi-input" disabled>'
          : '<input type="number" class="kpi-input" step="' . esc_attr($step) . '" name="kpi[' . esc_attr($date) . '][' . esc_attr($m['key']) . ']" value="' . esc_attr($val) . '">';

        echo '<td class="' . ($disabled ? 'kpi-disabled-day' : '') . '">' . $input . '</td>';
      }

      $fmtTotal = $m['type'] === 'money' ? number_format($rowTotal, 2) : (int)$rowTotal;
      echo '<td class="kpi-total-col"><span class="kpi-row-total" data-row-total="' . esc_attr($m['key']) . '">' . esc_html($fmtTotal) . '</span></td>';
      echo '</tr>';
    }
  }

  private static function render_monthly_rows($monthly, $active, $rows) {
    foreach ($rows as $r) {
      $key = $r['key'];
      $type = $r['type'];

      echo '<tr>';
      echo '<td class="kpi-sticky-col"><strong>' . esc_html($r['label']) . '</strong></td>';

      $ytd = 0;
      $den = 0;

      for ($m=1;$m<=12;$m++) {
        $val = (float)($monthly[$m][$key] ?? 0);
        echo '<td>' . esc_html($type === 'money' ? number_format($val, 2) : (int)$val) . '</td>';

        if (!empty($active[$m])) {
          $ytd += $val;
          $den += 1;
        }
      }

      $avg = $den > 0 ? $ytd / $den : 0;

      echo '<td class="kpi-total-col"><strong>' . esc_html($type === 'money' ? number_format($ytd, 2) : (int)$ytd) . '</strong></td>';
      echo '<td class="kpi-total-col"><strong>' . esc_html($type === 'money' ? number_format($avg, 2) : number_format($avg, 2)) . '</strong></td>';
      echo '</tr>';
    }

    // Total Number Of Leads row (computed)
    echo '<tr class="kpi-subtotal-row">';
    echo '<td class="kpi-sticky-col"><strong>Total Number Of Leads</strong></td>';

    $ytd = 0; $den = 0;
    for ($m=1;$m<=12;$m++) {
      $leadTotal = self::month_total_leads($monthly[$m]);
      echo '<td><strong>' . esc_html((int)$leadTotal) . '</strong></td>';

      if (!empty($active[$m])) {
        $ytd += $leadTotal;
        $den += 1;
      }
    }
    $avg = $den > 0 ? $ytd / $den : 0;
    echo '<td class="kpi-total-col"><strong>' . esc_html((int)$ytd) . '</strong></td>';
    echo '<td class="kpi-total-col"><strong>' . esc_html(number_format($avg, 2)) . '</strong></td>';
    echo '</tr>';
  }

  private static function render_monthly_stats($monthly, $active) {
    $statRows = [
      ['label' => 'Average Quote/Proposal Value', 'fn' => 'avg_quote'],
      ['label' => 'Average Sale Value', 'fn' => 'avg_sale'],
      ['label' => 'Calls from Leads', 'fn' => 'calls_from_leads'],
      ['label' => 'Appointments from Calls', 'fn' => 'apps_from_calls'],
      ['label' => 'Appointments from Leads', 'fn' => 'apps_from_leads'],
      ['label' => 'Quotes/Proposals From Appointments', 'fn' => 'quotes_from_apps'],
      ['label' => 'Sales from Quotes/Proposals', 'fn' => 'sales_from_quotes'],
      ['label' => 'Sales from Calls', 'fn' => 'sales_from_calls'],
      ['label' => 'Sales From Leads', 'fn' => 'sales_from_leads'],
    ];

    foreach ($statRows as $sr) {
      echo '<tr>';
      echo '<td class="kpi-sticky-col"><strong>' . esc_html($sr['label']) . '</strong></td>';

      $ytd = 0;
      $den = 0;

      for ($m=1;$m<=12;$m++) {
        $v = self::calc_stat($sr['fn'], $monthly[$m]);
        echo '<td>' . esc_html(number_format($v, 6)) . '</td>';

        if (!empty($active[$m])) {
          $ytd += $v;
          $den += 1;
        }
      }

      $avg = $den > 0 ? $ytd / $den : 0;
      echo '<td class="kpi-total-col"><strong>' . esc_html(number_format($ytd, 6)) . '</strong></td>';
      echo '<td class="kpi-total-col"><strong>' . esc_html(number_format($avg, 6)) . '</strong></td>';
      echo '</tr>';
    }
  }

  private static function month_total_leads($monthRow) {
    $keys = [
      'leads_website','leads_google_ads','leads_houzz','leads_facebook','leads_referrals',
      'leads_repeat_customers','leads_instagram','leads_walkins','leads_other_1','leads_other_2'
    ];
    $sum = 0;
    foreach ($keys as $k) $sum += (float)($monthRow[$k] ?? 0);
    return $sum;
  }

  private static function calc_stat($fn, $monthRow) {
    $leads = self::month_total_leads($monthRow);
    $calls = (float)($monthRow['calls'] ?? 0);
    $apps  = (float)($monthRow['appointments'] ?? 0);
    $quotes= (float)($monthRow['quotes'] ?? 0);
    $quoteVal = (float)($monthRow['quote_value'] ?? 0);
    $sales = (float)($monthRow['sales'] ?? 0);
    $salesVal = (float)($monthRow['sales_value'] ?? 0);

    switch ($fn) {
      case 'avg_quote': return $quotes > 0 ? ($quoteVal / $quotes) : 0;
      case 'avg_sale': return $sales > 0 ? ($salesVal / $sales) : 0;
      case 'calls_from_leads': return $leads > 0 ? ($calls / $leads) : 0;
      case 'apps_from_calls':  return $calls > 0 ? ($apps / $calls) : 0;
      case 'apps_from_leads':  return $leads > 0 ? ($apps / $leads) : 0;
      case 'quotes_from_apps': return $apps > 0 ? ($quotes / $apps) : 0;
      case 'sales_from_quotes':return $quotes > 0 ? ($sales / $quotes) : 0;
      case 'sales_from_calls': return $calls > 0 ? ($sales / $calls) : 0;
      case 'sales_from_leads': return $leads > 0 ? ($sales / $leads) : 0;
    }
    return 0;
  }

  // -----------------------
  // Handlers
  // -----------------------
  public static function handle_save_month() {
    if (!current_user_can('manage_options')) wp_die('Forbidden');
    check_admin_referer('kpi_save_month');

    $redirect = isset($_POST['redirect']) ? esc_url_raw($_POST['redirect']) : admin_url('admin.php?page=' . self::MENU_SLUG);

    $kpi = isset($_POST['kpi']) && is_array($_POST['kpi']) ? $_POST['kpi'] : [];

    foreach ($kpi as $date => $fields) {
      $date = sanitize_text_field($date);
      if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) continue;

      $clean = [];
      if (is_array($fields)) {
        foreach (KPI_DB::fields() as $f) {
          if (!isset($fields[$f])) continue;
          $clean[$f] = KPI_DB::sanitize_numeric($f, $fields[$f]);
        }
      }

      KPI_DB::upsert_day($date, $clean);
    }

    wp_safe_redirect(add_query_arg(['saved' => 1], $redirect));
    exit;
  }

  public static function handle_save_labels() {
    if (!current_user_can('manage_options')) wp_die('Forbidden');
    check_admin_referer('kpi_save_labels');

    $redirect = isset($_POST['redirect']) ? esc_url_raw($_POST['redirect']) : admin_url('admin.php?page=' . self::MENU_SLUG);

    $labels = isset($_POST['labels']) && is_array($_POST['labels']) ? $_POST['labels'] : [];
    $opt = [
      'leads_other_1' => isset($labels['leads_other_1']) ? sanitize_text_field($labels['leads_other_1']) : 'Other',
      'leads_other_2' => isset($labels['leads_other_2']) ? sanitize_text_field($labels['leads_other_2']) : 'Other',
    ];

    update_option('kpi_calc_lead_labels', $opt, false);

    wp_safe_redirect(add_query_arg(['saved' => 1], $redirect));
    exit;
  }

  public static function handle_save_active_months() {
    if (!current_user_can('manage_options')) wp_die('Forbidden');
    check_admin_referer('kpi_save_active_months');

    $redirect = isset($_POST['redirect']) ? esc_url_raw($_POST['redirect']) : admin_url('admin.php?page=' . self::MENU_SLUG);

    $year = isset($_POST['year']) ? (int)$_POST['year'] : (int)date('Y');
    $active = isset($_POST['active']) && is_array($_POST['active']) ? $_POST['active'] : [];

    self::set_active_months($year, $active);

    wp_safe_redirect(add_query_arg(['saved' => 1], $redirect));
    exit;
  }

  // -----------------------
  // URL helpers
  // -----------------------
  private static function url($args = []) {
    $base = admin_url('admin.php?page=' . self::MENU_SLUG);
    return add_query_arg($args, $base);
  }

  private static function current_url() {
    $page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : self::MENU_SLUG;
    $tab  = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'activity';
    $year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
    $month= isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');

    $args = ['page' => $page, 'tab' => $tab, 'year' => $year];
    if ($tab === 'activity') $args['month'] = $month;

    return admin_url('admin.php?' . http_build_query($args));
  }
}
