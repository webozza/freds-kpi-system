<?php
if (!defined('ABSPATH')) exit;

class KPI_Frontend {

  public static function init() {
    add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue']);

    add_shortcode('kpi_dashboard', [__CLASS__, 'shortcode_dashboard']);

    add_action('admin_post_nopriv_kpi_save_user_setup', [__CLASS__, 'forbid']);
    add_action('admin_post_kpi_save_user_setup', [__CLASS__, 'handle_save_user_setup']);

    add_action('admin_post_nopriv_kpi_front_save_month', [__CLASS__, 'forbid']);
    add_action('admin_post_kpi_front_save_month', [__CLASS__, 'handle_front_save_month']);

    add_action('admin_post_nopriv_kpi_save_active_months_user', [__CLASS__, 'forbid']);
    add_action('admin_post_kpi_save_active_months_user', [__CLASS__, 'handle_save_active_months_user']);
  }

  public static function enqueue() {
    if (!is_singular()) return;
    global $post;
    if (!$post) return;

    if (!has_shortcode($post->post_content, 'kpi_dashboard')) return;

    // --- Vendor: Flatpickr + Handsontable (CDN) ---
    wp_enqueue_style('kpi-flatpickr', 'https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css', [], '4.6.13');
    wp_enqueue_script('kpi-flatpickr', 'https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js', [], '4.6.13', true);

    wp_enqueue_style('kpi-handsontable', 'https://cdn.jsdelivr.net/npm/handsontable@14.5.0/dist/handsontable.full.min.css', [], '14.5.0');
    wp_enqueue_script('kpi-handsontable', 'https://cdn.jsdelivr.net/npm/handsontable@14.5.0/dist/handsontable.full.min.js', [], '14.5.0', true);

    // --- Our UI ---
    wp_enqueue_style('kpi-calc-frontend', KPI_CALC_URL . 'assets/frontend.css', [], KPI_CALC_VERSION);
    wp_enqueue_script('kpi-calc-frontend', KPI_CALC_URL . 'assets/frontend.js', ['jquery', 'kpi-flatpickr', 'kpi-handsontable'], KPI_CALC_VERSION, true);

    wp_localize_script('kpi-calc-frontend', 'kpiFront', [
      'ajaxUrl' => admin_url('admin-post.php'),
      'currencySymbol' => '$',
      'moneyDecimals' => 2,
      'percentDecimals' => 2,
      'todayYm' => date('Y-m'),
    ]);
  }

  // -----------------------
  // Access control
  // -----------------------
  private static function user_can_access() {
    if (!is_user_logged_in()) return false;
    if (current_user_can('manage_options')) return true;

    // Paid Memberships Pro gate (if installed)
    if (function_exists('pmpro_hasMembershipLevel')) {
      $allowed_level_ids = apply_filters('kpi_allowed_pmpro_levels', []); // optional: [1,2]
      if (!empty($allowed_level_ids)) {
        return pmpro_hasMembershipLevel($allowed_level_ids, get_current_user_id());
      }
      return pmpro_hasMembershipLevel(null, get_current_user_id());
    }

    return true;
  }

  public static function forbid() {
    wp_die('Forbidden');
  }

  // -----------------------
  // Setup (channels)
  // -----------------------
  private static function default_channels() {
    return [
      'leads_website' => 'Website',
      'leads_google_ads' => 'Google Ads',
      'leads_houzz' => 'Houzz',
      'leads_facebook' => 'Facebook',
      'leads_referrals' => 'Referrals',
      'leads_repeat_customers' => 'Repeat customers',
      'leads_instagram' => 'Instagram',
      'leads_walkins' => 'Walk-ins',
      'leads_other_1' => 'Other',
      'leads_other_2' => 'Other',
    ];
  }

  private static function get_setup($user_id) {
    $channels = get_user_meta($user_id, 'kpi_channels', true);
    $labels = get_user_meta($user_id, 'kpi_channel_labels', true);

    return [
      'channels' => is_array($channels) ? $channels : [],
      'labels'   => is_array($labels) ? $labels : [],
    ];
  }

  public static function handle_save_user_setup() {
    if (!is_user_logged_in()) wp_die('Forbidden');
    if (!self::user_can_access()) wp_die('Forbidden');

    check_admin_referer('kpi_save_user_setup');

    $user_id = get_current_user_id();

    $channels = isset($_POST['channels']) && is_array($_POST['channels']) ? array_map('sanitize_text_field', $_POST['channels']) : [];
    $channels = array_values(array_unique(array_filter($channels)));

    // allow only known keys
    $allowed = array_keys(self::default_channels());
    $channels = array_values(array_intersect($channels, $allowed));

    $labels_in = isset($_POST['labels']) && is_array($_POST['labels']) ? $_POST['labels'] : [];
    $labels = [
      'leads_other_1' => isset($labels_in['leads_other_1']) ? sanitize_text_field($labels_in['leads_other_1']) : 'Other',
      'leads_other_2' => isset($labels_in['leads_other_2']) ? sanitize_text_field($labels_in['leads_other_2']) : 'Other',
    ];

    update_user_meta($user_id, 'kpi_channels', $channels);
    update_user_meta($user_id, 'kpi_channel_labels', $labels);

    wp_safe_redirect(wp_get_referer() ?: home_url('/'));
    exit;
  }

  private static function render_setup_form() {
    $defaults = self::default_channels();

    ob_start(); ?>
      <div class="kpi-wrap">
        <div class="kpi-shell">
          <div class="kpi-topbar">
            <div class="kpi-title">
              <div class="kpi-badge">KPI System</div>
              <h2>Set up your KPI Tracker</h2>
              <p>Pick your marketing channels and we’ll build your personal dashboard.</p>
            </div>
          </div>

          <div class="kpi-card kpi-card--glass">
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="kpi-form">
              <?php wp_nonce_field('kpi_save_user_setup'); ?>
              <input type="hidden" name="action" value="kpi_save_user_setup">

              <div class="kpi-grid-2">
                <?php foreach ($defaults as $key => $label): ?>
                  <label class="kpi-check">
                    <input type="checkbox" name="channels[]" value="<?php echo esc_attr($key); ?>" checked>
                    <span><?php echo esc_html($label); ?></span>
                  </label>
                <?php endforeach; ?>
              </div>

              <div class="kpi-grid-2 kpi-mt">
                <div class="kpi-field">
                  <label>Other Channel #1 name</label>
                  <input type="text" name="labels[leads_other_1]" value="Other">
                </div>
                <div class="kpi-field">
                  <label>Other Channel #2 name</label>
                  <input type="text" name="labels[leads_other_2]" value="Other">
                </div>
              </div>

              <button class="kpi-btn kpi-mt">Create my dashboard</button>
            </form>
          </div>
        </div>
      </div>
    <?php
    return ob_get_clean();
  }

  // -----------------------
  // Formatting helpers
  // -----------------------
  private static function fmt_money0($n) {
    $n = (float)$n;
    return '$' . number_format($n, 0);
  }

  private static function fmt_money2($n) {
    $n = (float)$n;
    return '$' . number_format($n, 2);
  }

  private static function fmt_percent2($ratio) {
    $ratio = (float)$ratio;
    return number_format($ratio * 100, 2) . '%';
  }

  // -----------------------
  // Active months (per user, per year)
  // -----------------------
  private static function get_active_months_user($user_id, $year) {
    $key = 'kpi_active_months_' . (int)$year;
    $opt = get_user_meta((int)$user_id, $key, true);
    $out = [];
    for ($m=1; $m<=12; $m++) $out[$m] = !empty($opt[$m]) ? 1 : 0;

    // default: mark current month active if nothing saved yet
    $hasAny = false;
    for ($m=1; $m<=12; $m++) if (!empty($out[$m])) { $hasAny = true; break; }
    if (!$hasAny) {
      $curY = (int)date('Y');
      if ((int)$year === $curY) $out[(int)date('n')] = 1;
    }

    return $out;
  }

  private static function set_active_months_user($user_id, $year, $months) {
    $key = 'kpi_active_months_' . (int)$year;
    $clean = [];
    for ($m=1; $m<=12; $m++) $clean[$m] = isset($months[$m]) ? 1 : 0;
    update_user_meta((int)$user_id, $key, $clean);
  }

  public static function handle_save_active_months_user() {
    if (!is_user_logged_in()) wp_die('Forbidden');
    if (!self::user_can_access()) wp_die('Forbidden');

    check_admin_referer('kpi_save_active_months_user');

    $user_id = get_current_user_id();
    $year = isset($_POST['kpi_year']) ? (int)$_POST['kpi_year'] : (int)date('Y');
    $active = isset($_POST['active']) && is_array($_POST['active']) ? $_POST['active'] : [];

    self::set_active_months_user($user_id, $year, $active);

    wp_safe_redirect(wp_get_referer() ?: home_url('/'));
    exit;
  }

  // -----------------------
  // Shortcode
  // -----------------------
  public static function shortcode_dashboard($atts) {
    if (!is_user_logged_in()) {
      return '<div class="kpi-wrap"><div class="kpi-shell"><div class="kpi-card kpi-card--glass"><h3>Please log in</h3><p>You need to be logged in to access the KPI system.</p></div></div></div>';
    }

    if (!self::user_can_access()) {
      $levels_url = function_exists('pmpro_url') ? pmpro_url('levels') : wp_login_url();
      return '<div class="kpi-wrap"><div class="kpi-shell"><div class="kpi-card kpi-card--glass"><h3>Subscription required</h3><p>Please subscribe to access the KPI system.</p><p><a class="kpi-btn" href="' . esc_url($levels_url) . '">View Plans</a></p></div></div></div>';
    }

    $user_id = get_current_user_id();
    $setup = self::get_setup($user_id);

    if (empty($setup['channels'])) {
      return self::render_setup_form();
    }

    return self::render_dashboard($user_id, $setup);
  }

  // -----------------------
  // Dashboard (Activity + Monthly)
  // -----------------------
  private static function render_dashboard($user_id, $setup) {
    // tab
    $tab = isset($_GET['kpi_tab']) ? sanitize_key($_GET['kpi_tab']) : 'activity';
    if (!in_array($tab, ['activity','monthly'], true)) $tab = 'activity';

    // month/year from a single ym param (YYYY-MM) if provided
    $ym = isset($_GET['kpi_ym']) ? sanitize_text_field($_GET['kpi_ym']) : '';
    if (preg_match('/^\d{4}-\d{2}$/', $ym)) {
      $year = (int)substr($ym, 0, 4);
      $month = (int)substr($ym, 5, 2);
    } else {
      $year = isset($_GET['kpi_year']) ? (int)$_GET['kpi_year'] : (int)date('Y');
      $month = isset($_GET['kpi_month']) ? (int)$_GET['kpi_month'] : (int)date('n');
      $ym = sprintf('%04d-%02d', $year, $month);
    }

    $year = max(2000, min(2100, $year));
    $month = max(1, min(12, $month));

    $daysInMonth = (int)date('t', strtotime(sprintf('%04d-%02d-01', $year, $month)));
    $rowsByDate = KPI_DB::get_month_rows($user_id, $year, $month);
    $totals = KPI_DB::get_month_totals($user_id, $year, $month);

    $defaultLabels = self::default_channels();
    $labels = array_merge($defaultLabels, $setup['labels']);

    $channels = $setup['channels']; // selected lead channels only

    // compute totals/stats
    $leadTotal = 0;
    foreach ($channels as $k) $leadTotal += (float)($totals[$k] ?? 0);

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

    $monthTitle = date('F Y', strtotime(sprintf('%04d-%02d-01', $year, $month)));

    // Build Activity rows meta for Handsontable
    $activityRows = [];
    foreach ($channels as $k) {
      $activityRows[] = ['key' => $k, 'label' => $labels[$k], 'type' => 'int', 'group' => 'Leads'];
    }
    $pipe = [
      ['calls','Calls','int'],
      ['appointments','Appointments','int'],
      ['quotes','Quotes/Presentations','int'],
      ['quote_value','$$ Value','money'],
      ['sales','Sales','int'],
      ['sales_value','Sales Value $$','money'],
    ];
    foreach ($pipe as $p) {
      $activityRows[] = ['key' => $p[0], 'label' => $p[1], 'type' => $p[2], 'group' => 'Pipeline'];
    }

    // Pre-fill matrix [rowIndex][dayIndex] (1..daysInMonth)
    $prefill = [];
    for ($ri=0; $ri<count($activityRows); $ri++) {
      $prefill[$ri] = [];
      for ($d=1; $d<=$daysInMonth; $d++) {
        $date = sprintf('%04d-%02d-%02d', $year, $month, $d);
        $key = $activityRows[$ri]['key'];
        $val = isset($rowsByDate[$date][$key]) ? $rowsByDate[$date][$key] : 0;
        $prefill[$ri][] = $val;
      }
    }

    // Monthly figures data
    $monthly = KPI_DB::get_year_monthly_totals($user_id, $year);
    $active = self::get_active_months_user($user_id, $year);

    // Build monthly grid rows (strings for display)
    $monthlyGrid = [];

    // Leads section rows
    foreach ($channels as $k) {
      $monthlyGrid[] = self::build_monthly_row($labels[$k], 'int', $monthly, $active, function($m) use ($k) {
        return (float)($m[$k] ?? 0);
      });
    }

    // Total leads
    $monthlyGrid[] = self::build_monthly_row('Total Number Of Leads', 'int', $monthly, $active, function($m) use ($channels) {
      $sum = 0;
      foreach ($channels as $k) $sum += (float)($m[$k] ?? 0);
      return $sum;
    }, true);

    // Pipeline totals
    $monthlyGrid[] = self::build_monthly_row('Calls', 'int', $monthly, $active, fn($m)=>(float)($m['calls']??0));
    $monthlyGrid[] = self::build_monthly_row('Appointments', 'int', $monthly, $active, fn($m)=>(float)($m['appointments']??0));
    $monthlyGrid[] = self::build_monthly_row('Quotes/Presentations', 'int', $monthly, $active, fn($m)=>(float)($m['quotes']??0));
    $monthlyGrid[] = self::build_monthly_row('Total Quote Value', 'money0', $monthly, $active, fn($m)=>(float)($m['quote_value']??0));
    $monthlyGrid[] = self::build_monthly_row('Sales', 'int', $monthly, $active, fn($m)=>(float)($m['sales']??0));
    $monthlyGrid[] = self::build_monthly_row('Total Sales Value', 'money0', $monthly, $active, fn($m)=>(float)($m['sales_value']??0));

    // Stats (percent + money)
    $monthlyGrid[] = self::build_monthly_row('Average Quote Value', 'money0', $monthly, $active, function($m){
      $q = (float)($m['quotes']??0);
      $v = (float)($m['quote_value']??0);
      return $q>0 ? $v/$q : 0;
    });
    $monthlyGrid[] = self::build_monthly_row('Average Sale Value', 'money0', $monthly, $active, function($m){
      $s = (float)($m['sales']??0);
      $v = (float)($m['sales_value']??0);
      return $s>0 ? $v/$s : 0;
    });

    $monthlyGrid[] = self::build_monthly_row('Calls from Leads', 'pct', $monthly, $active, function($m) use ($channels){
      $leads=0; foreach($channels as $k) $leads+=(float)($m[$k]??0);
      $calls=(float)($m['calls']??0);
      return $leads>0 ? $calls/$leads : 0;
    });
    $monthlyGrid[] = self::build_monthly_row('Appointments from Calls', 'pct', $monthly, $active, function($m){
      $calls=(float)($m['calls']??0); $apps=(float)($m['appointments']??0);
      return $calls>0 ? $apps/$calls : 0;
    });
    $monthlyGrid[] = self::build_monthly_row('Appointments from Leads', 'pct', $monthly, $active, function($m) use ($channels){
      $leads=0; foreach($channels as $k) $leads+=(float)($m[$k]??0);
      $apps=(float)($m['appointments']??0);
      return $leads>0 ? $apps/$leads : 0;
    });
    $monthlyGrid[] = self::build_monthly_row('Quotes from Appointments', 'pct', $monthly, $active, function($m){
      $apps=(float)($m['appointments']??0); $q=(float)($m['quotes']??0);
      return $apps>0 ? $q/$apps : 0;
    });
    $monthlyGrid[] = self::build_monthly_row('Sales from Quotes', 'pct', $monthly, $active, function($m){
      $q=(float)($m['quotes']??0); $s=(float)($m['sales']??0);
      return $q>0 ? $s/$q : 0;
    });
    $monthlyGrid[] = self::build_monthly_row('Sales from Calls', 'pct', $monthly, $active, function($m){
      $calls=(float)($m['calls']??0); $s=(float)($m['sales']??0);
      return $calls>0 ? $s/$calls : 0;
    });
    $monthlyGrid[] = self::build_monthly_row('Sales from Leads', 'pct', $monthly, $active, function($m) use ($channels){
      $leads=0; foreach($channels as $k) $leads+=(float)($m[$k]??0);
      $s=(float)($m['sales']??0);
      return $leads>0 ? $s/$leads : 0;
    });

    ob_start(); ?>
      <div class="kpi-wrap">
        <div class="kpi-shell">
          <div class="kpi-topbar">
            <div class="kpi-title">
              <div class="kpi-badge">KPI System</div>
              <h2>Your KPI Dashboard</h2>
              <p class="kpi-subtitle"><?php echo esc_html($monthTitle); ?></p>
            </div>

            <div class="kpi-controls">
              <div class="kpi-control">
                <label>Month</label>
                <input id="kpiMonthPicker" type="text" value="<?php echo esc_attr($ym); ?>" />
                <small>Pick month/year</small>
              </div>

              <div class="kpi-tabs">
                <a class="kpi-tab <?php echo $tab==='activity'?'is-active':''; ?>" href="<?php echo esc_url(add_query_arg(['kpi_tab'=>'activity'])); ?>">Activity</a>
                <a class="kpi-tab <?php echo $tab==='monthly'?'is-active':''; ?>" href="<?php echo esc_url(add_query_arg(['kpi_tab'=>'monthly'])); ?>">Monthly figures</a>
              </div>
            </div>
          </div>

          <?php if ($tab === 'activity'): ?>
            <div class="kpi-cards">
              <div class="kpi-card">
                <h3>Totals</h3>
                <div class="kpi-kv"><span>Total Leads</span><strong id="kpi_total_leads"><?php echo esc_html((int)$leadTotal); ?></strong></div>
                <div class="kpi-kv"><span>Calls</span><strong id="kpi_total_calls"><?php echo esc_html((int)$calls); ?></strong></div>
                <div class="kpi-kv"><span>Appointments</span><strong id="kpi_total_apps"><?php echo esc_html((int)$apps); ?></strong></div>
                <div class="kpi-kv"><span>Quotes</span><strong id="kpi_total_quotes"><?php echo esc_html((int)$quotes); ?></strong></div>
                <div class="kpi-kv"><span>Total Quote Value</span><strong id="kpi_total_quote_val"><?php echo esc_html(self::fmt_money2($quoteVal)); ?></strong></div>
                <div class="kpi-kv"><span>Sales</span><strong id="kpi_total_sales"><?php echo esc_html((int)$sales); ?></strong></div>
                <div class="kpi-kv"><span>Total Sales Value</span><strong id="kpi_total_sales_val"><?php echo esc_html(self::fmt_money2($salesVal)); ?></strong></div>
              </div>

              <div class="kpi-card">
                <h3>Key Stats</h3>
                <div class="kpi-kv"><span>Avg Quote Value</span><strong id="kpi_avg_quote"><?php echo esc_html(self::fmt_money2($avgQuote)); ?></strong></div>
                <div class="kpi-kv"><span>Avg Sale Value</span><strong id="kpi_avg_sale"><?php echo esc_html(self::fmt_money2($avgSale)); ?></strong></div>

                <div class="kpi-kv"><span>Calls from Leads</span><strong id="kpi_calls_from_leads"><?php echo esc_html(self::fmt_percent2($callsFromLeads)); ?></strong></div>
                <div class="kpi-kv"><span>Appointments from Calls</span><strong id="kpi_apps_from_calls"><?php echo esc_html(self::fmt_percent2($appsFromCalls)); ?></strong></div>
                <div class="kpi-kv"><span>Appointments from Leads</span><strong id="kpi_apps_from_leads"><?php echo esc_html(self::fmt_percent2($appsFromLeads)); ?></strong></div>
                <div class="kpi-kv"><span>Quotes from Appointments</span><strong id="kpi_quotes_from_apps"><?php echo esc_html(self::fmt_percent2($quotesFromApps)); ?></strong></div>
                <div class="kpi-kv"><span>Sales from Quotes</span><strong id="kpi_sales_from_quotes"><?php echo esc_html(self::fmt_percent2($salesFromQuotes)); ?></strong></div>
                <div class="kpi-kv"><span>Sales from Calls</span><strong id="kpi_sales_from_calls"><?php echo esc_html(self::fmt_percent2($salesFromCalls)); ?></strong></div>
                <div class="kpi-kv"><span>Sales from Leads</span><strong id="kpi_sales_from_leads"><?php echo esc_html(self::fmt_percent2($salesFromLeads)); ?></strong></div>
              </div>

              <div class="kpi-card kpi-card--hint">
                <h3>Spreadsheet</h3>
                <p>Enter your daily numbers like Excel. Totals & stats update instantly.</p>
                <ul>
                  <li>Money formats like <strong>$70,000</strong></li>
                  <li>Rates format like <strong>35.00%</strong></li>
                </ul>
              </div>
            </div>

            <div class="kpi-card kpi-card--glass">
              <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="kpiActivityForm">
                <?php wp_nonce_field('kpi_front_save_month'); ?>
                <input type="hidden" name="action" value="kpi_front_save_month">
                <input type="hidden" name="kpi_ym" value="<?php echo esc_attr($ym); ?>">
                <input type="hidden" name="kpi_payload" id="kpi_payload" value="">

                <div class="kpi-hot-head">
                  <div>
                    <h3>Activity — <?php echo esc_html($monthTitle); ?></h3>
                    <p class="kpi-muted">Click a cell and type. Use arrow keys like a spreadsheet.</p>
                  </div>
                  <button class="kpi-btn" type="submit">Save Month</button>
                </div>

                <div id="kpiHotActivity" class="kpi-hot"></div>
              </form>
            </div>

            <script type="application/json" id="kpi_activity_rows"><?php echo wp_json_encode($activityRows); ?></script>
            <script type="application/json" id="kpi_activity_prefill"><?php echo wp_json_encode($prefill); ?></script>
            <script type="application/json" id="kpi_activity_meta"><?php echo wp_json_encode([
              'ym' => $ym,
              'daysInMonth' => $daysInMonth,
              'selectedLeadKeys' => $channels,
            ]); ?></script>

          <?php else: ?>
            <div class="kpi-card kpi-card--glass">
              <div class="kpi-hot-head">
                <div>
                  <h3>Monthly figures — <?php echo esc_html($year); ?></h3>
                  <p class="kpi-muted">Tick active months to control “Year to Date” and “Averages”.</p>
                </div>
              </div>

              <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="kpi-code-row">
                <?php wp_nonce_field('kpi_save_active_months_user'); ?>
                <input type="hidden" name="action" value="kpi_save_active_months_user">
                <input type="hidden" name="kpi_year" value="<?php echo esc_attr($year); ?>">

                <div class="kpi-code-months">
                  <?php for ($m=1;$m<=12;$m++): ?>
                    <label class="kpi-code-month">
                      <input type="checkbox" name="active[<?php echo $m; ?>]" value="1" <?php checked(1, $active[$m]); ?>>
                      <?php echo esc_html(date('M', mktime(0,0,0,$m,1,$year))); ?>
                    </label>
                  <?php endfor; ?>
                </div>

                <button class="kpi-btn kpi-btn--ghost" type="submit">Save</button>
              </form>

              <div id="kpiHotMonthly" class="kpi-hot kpi-hot--monthly"></div>

              <script type="application/json" id="kpi_monthly_grid"><?php echo wp_json_encode($monthlyGrid); ?></script>
              <script type="application/json" id="kpi_monthly_meta"><?php echo wp_json_encode([
                'year' => $year
              ]); ?></script>
            </div>
          <?php endif; ?>
        </div>
      </div>
    <?php
    return ob_get_clean();
  }

  private static function build_monthly_row($label, $fmt, $monthly, $active, $getter, $isEmphasis=false) {
    $row = [];
    $row[] = $label;

    $ytd = 0;
    $den = 0;

    for ($m=1; $m<=12; $m++) {
      $val = (float)$getter($monthly[$m] ?? []);
      $row[] = self::format_monthly_cell($fmt, $val);

      if (!empty($active[$m])) {
        $ytd += $val;
        $den++;
      }
    }

    $avg = $den > 0 ? $ytd / $den : 0;

    $row[] = self::format_monthly_cell($fmt, $ytd);
    $row[] = self::format_monthly_cell($fmt, $avg);

    // mark emphasis rows for JS styling
    $row[] = $isEmphasis ? '__emphasis__' : '';

    return $row;
  }

  private static function format_monthly_cell($fmt, $val) {
    if ($fmt === 'money0') return self::fmt_money0($val);
    if ($fmt === 'money2') return self::fmt_money2($val);
    if ($fmt === 'pct') return self::fmt_percent2($val);
    // int
    return (string)((int)round($val));
  }

  // -----------------------
  // Save Activity payload (Handsontable -> DB)
  // -----------------------
  public static function handle_front_save_month() {
    if (!is_user_logged_in()) wp_die('Forbidden');
    if (!self::user_can_access()) wp_die('Forbidden');

    check_admin_referer('kpi_front_save_month');

    $user_id = get_current_user_id();

    $ym = isset($_POST['kpi_ym']) ? sanitize_text_field($_POST['kpi_ym']) : '';
    if (!preg_match('/^\d{4}-\d{2}$/', $ym)) wp_die('Invalid month');

    $payloadRaw = isset($_POST['kpi_payload']) ? wp_unslash($_POST['kpi_payload']) : '';
    $payload = json_decode($payloadRaw, true);
    if (!is_array($payload) || empty($payload['kpi']) || !is_array($payload['kpi'])) {
      wp_die('Invalid payload');
    }

    foreach ($payload['kpi'] as $date => $fields) {
      $date = sanitize_text_field($date);
      if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) continue;

      if (!is_array($fields)) continue;

      $clean = [];
      foreach (KPI_DB::fields() as $f) {
        if (!isset($fields[$f])) continue;
        $clean[$f] = KPI_DB::sanitize_numeric($f, $fields[$f]);
      }

      KPI_DB::upsert_day($user_id, $date, $clean);
    }

    wp_safe_redirect(wp_get_referer() ?: home_url('/'));
    exit;
  }
}

add_action('init', ['KPI_Frontend', 'init']);
