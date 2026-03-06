<?php
if (!defined('ABSPATH'))
  exit;

class KPI_Frontend
{

  public static function init()
  {
    add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue']);

    add_shortcode('kpi_dashboard', [__CLASS__, 'shortcode_dashboard']);

    add_action('admin_post_nopriv_kpi_save_user_setup', [__CLASS__, 'forbid']);
    add_action('admin_post_kpi_save_user_setup', [__CLASS__, 'handle_save_user_setup']);

    add_action('admin_post_nopriv_kpi_front_save_month', [__CLASS__, 'forbid']);
    add_action('admin_post_kpi_front_save_month', [__CLASS__, 'handle_front_save_month']);

    add_action('admin_post_nopriv_kpi_save_active_months_user', [__CLASS__, 'forbid']);
    add_action('admin_post_kpi_save_active_months_user', [__CLASS__, 'handle_save_active_months_user']);

    add_action('wp_ajax_kpi_autosave_patch', [__CLASS__, 'handle_autosave_patch']);
    add_action('wp_ajax_nopriv_kpi_autosave_patch', [__CLASS__, 'forbid']);

    // Per-period channel copy (Issue 1)
    add_action('wp_ajax_kpi_copy_period_channels', [__CLASS__, 'handle_copy_period_channels']);
    add_action('wp_ajax_nopriv_kpi_copy_period_channels', [__CLASS__, 'forbid']);
  }

  public static function enqueue()
  {
    if (!is_singular())
      return;
    global $post;
    if (!$post)
      return;

    if (!has_shortcode($post->post_content, 'kpi_dashboard'))
      return;

    // --- Vendor: Handsontable (CDN) ---
    wp_enqueue_style(
      'kpi-handsontable',
      'https://cdn.jsdelivr.net/npm/handsontable@14.5.0/dist/handsontable.full.min.css',
      [],
      '14.5.0'
    );
    wp_enqueue_script(
      'kpi-handsontable',
      'https://cdn.jsdelivr.net/npm/handsontable@14.5.0/dist/handsontable.full.min.js',
      [],
      '14.5.0',
      true
    );

    // --- Vendor: Chart.js (local) ---
    wp_enqueue_script(
      'kpi-chartjs',
      KPI_CALC_URL . 'assets/vendor/chart.min.js',
      [],
      '4.4.7',
      true
    );

    // --- Our UI ---
    wp_enqueue_style('kpi-calc-frontend', KPI_CALC_URL . 'assets/frontend.css', [], KPI_CALC_VERSION);
    wp_enqueue_script('kpi-calc-frontend', KPI_CALC_URL . 'assets/frontend.js', ['jquery', 'kpi-handsontable', 'kpi-chartjs'], KPI_CALC_VERSION, true);

    $user_id = get_current_user_id();
    $cycle = $user_id ? self::get_year_cycle_settings($user_id) : ['mode' => 'calendar', 'fyStart' => 1];

    wp_localize_script('kpi-calc-frontend', 'kpiFront', [
      'ajaxUrl' => admin_url('admin-ajax.php'),
      'nonce' => wp_create_nonce('kpi_autosave'),
      'nonceCopy' => wp_create_nonce('kpi_copy_period_channels'),
      'nonceTeam' => wp_create_nonce('kpi_team_action'),
      'currencySymbol' => '$',
      'moneyDecimals' => 2,
      'percentDecimals' => 2,
      'todayYm' => date('Y-m'),
      'yearMode' => $cycle['mode'],
      'fyStartMonth' => (int) $cycle['fyStart'],
    ]);
  }

  // -----------------------
  // Access control
  // -----------------------
  private static function user_can_access()
  {
    if (!is_user_logged_in())
      return false;
    if (current_user_can('manage_options'))
      return true;

    // Team members (active or pending) are granted access via their owner's membership
    if (class_exists('KPI_Teams')) {
      $uid      = get_current_user_id();
      $owner_id = KPI_Teams::get_owner_for_member($uid);
      if ($owner_id && KPI_Teams::get_member_limit($owner_id) > 0) return true;
    }

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

  public static function forbid()
  {
    wp_die('Forbidden');
  }

  // -----------------------
  // Setup (unlimited channels)
  // -----------------------
  private static function setup_done($user_id)
  {
    return (bool) get_user_meta((int) $user_id, 'kpi_setup_done', true);
  }

  private static function get_year_cycle_settings($user_id)
  {
    $mode = get_user_meta((int) $user_id, 'kpi_year_mode', true);
    $mode = in_array($mode, ['calendar', 'financial'], true) ? $mode : 'calendar';

    $fyStart = (int) get_user_meta((int) $user_id, 'kpi_fy_start_month', true);
    if ($fyStart < 1 || $fyStart > 12)
      $fyStart = 1; // default Jan

    return ['mode' => $mode, 'fyStart' => $fyStart];
  }

  public static function handle_save_user_setup()
  {
    if (!is_user_logged_in())
      wp_die('Forbidden');
    if (!self::user_can_access())
      wp_die('Forbidden');

    check_admin_referer('kpi_save_user_setup');

    $user_id = get_current_user_id();

    // Which period are we saving to? (null = global, "YYYY-MM" = period-specific)
    $kpi_period = isset($_POST['kpi_period']) ? sanitize_text_field(wp_unslash($_POST['kpi_period'])) : null;
    // Empty string or invalid format → treat as global (null)
    if (!$kpi_period || !preg_match('/^\d{4}-\d{2}$/', $kpi_period))
      $kpi_period = null;

    // Expect JSON payload from JS (channel editor)
    $raw = isset($_POST['kpi_channels_json']) ? wp_unslash($_POST['kpi_channels_json']) : '[]';
    $rows = json_decode($raw, true);
    if (!is_array($rows))
      $rows = [];

    KPI_DB::save_channels($user_id, $rows, $kpi_period);

    // --- Save year cycle settings (if posted) ---
    if (isset($_POST['kpi_year_mode'])) {
      $mode = sanitize_key($_POST['kpi_year_mode']);
      if (!in_array($mode, ['calendar', 'financial'], true))
        $mode = 'calendar';
      update_user_meta($user_id, 'kpi_year_mode', $mode);
    }

    if (isset($_POST['kpi_fy_start_month'])) {
      $fyStart = (int) $_POST['kpi_fy_start_month'];
      $fyStart = max(1, min(12, $fyStart));
      update_user_meta($user_id, 'kpi_fy_start_month', $fyStart);
    }

    // mark setup done
    update_user_meta($user_id, 'kpi_setup_done', 1);

    wp_safe_redirect(wp_get_referer() ?: home_url('/'));
    exit;
  }

  // -----------------------
  // AJAX: Copy period channels (Issue 1)
  // -----------------------
  public static function handle_copy_period_channels()
  {
    if (!is_user_logged_in())
      wp_send_json_error(['msg' => 'Forbidden'], 403);
    if (!self::user_can_access())
      wp_send_json_error(['msg' => 'Forbidden'], 403);

    check_ajax_referer('kpi_copy_period_channels', 'nonce');

    $user_id = get_current_user_id();

    $from = isset($_POST['from_period']) ? sanitize_text_field(wp_unslash($_POST['from_period'])) : null;
    $to = isset($_POST['to_period']) ? sanitize_text_field(wp_unslash($_POST['to_period'])) : '';

    if (!preg_match('/^\d{4}-\d{2}$/', $to)) {
      wp_send_json_error(['msg' => 'Invalid to_period'], 400);
    }
    if ($from && !preg_match('/^\d{4}-\d{2}$/', $from)) {
      $from = null; // invalid from = use global
    }

    $newChannels = KPI_DB::copy_channels_to_period($user_id, $from ?: null, $to);

    wp_send_json_success(['channels' => $newChannels, 'period' => $to]);
  }

  private static function render_channel_editor($channels, $formIdSuffix = '')
  {
    // $channels: array of ['id','name','is_active',...]
    ob_start(); ?>
          <div class="kpi-channel-editor" id="kpiChannelEditor<?php echo esc_attr($formIdSuffix); ?>">
            <?php foreach ($channels as $c): ?>
                <div class="kpi-channel-row" data-id="<?php echo (int) $c['id']; ?>">
                  <input type="checkbox" class="kpi-channel-active" <?php checked((int) ($c['is_active'] ?? 0), 1); ?>>
                  <input type="text" class="kpi-channel-name" value="<?php echo esc_attr($c['name']); ?>" placeholder="Channel name">
                  <button type="button" class="kpi-channel-remove">Remove</button>
                </div>
            <?php endforeach; ?>
          </div>

          <button type="button" class="kpi-btn kpi-btn--ghost" id="kpiAddChannel<?php echo esc_attr($formIdSuffix); ?>">+ Add channel</button>
        <?php
        return ob_get_clean();
  }

  private static function render_setup_form($user_id)
  {
    KPI_DB::ensure_default_channels($user_id);
    $channels = KPI_DB::get_channels($user_id, false);

    ob_start(); ?>
          <div class="kpi-wrap">
            <div class="kpi-shell">
              <div class="kpi-topbar">
                <div class="kpi-title">
                  <div class="kpi-badge">KPI System</div>
                  <h2>Set up your KPI Tracker </h2>
                  <p>Create, rename, enable/disable, and remove channels. You can add as many as you like.</p>
                </div>
              </div>

              <div class="kpi-card kpi-card--glass">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="kpi-form" id="kpiSetupForm">
                  <?php wp_nonce_field('kpi_save_user_setup'); ?>
                  <input type="hidden" name="action" value="kpi_save_user_setup">
                  <input type="hidden" name="kpi_channels_json" id="kpi_channels_json" value="">

                  <div class="kpi-mt">
                    <?php echo self::render_channel_editor($channels); ?>
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
  private static function fmt_money0($n)
  {
    $n = (float) $n;
    return '$' . number_format($n, 0);
  }

  private static function fmt_money2($n)
  {
    $n = (float) $n;
    return '$' . number_format($n, 2);
  }

  private static function fmt_percent2($ratio)
  {
    $ratio = (float) $ratio;
    return number_format($ratio * 100, 2) . '%';
  }

  // -----------------------
  // Active months (per user, per year)
  // -----------------------
  private static function get_active_months_user($user_id, $year)
  {
    $key = 'kpi_active_months_' . (int) $year;
    $opt = get_user_meta((int) $user_id, $key, true);
    $out = [];
    for ($m = 1; $m <= 12; $m++)
      $out[$m] = !empty($opt[$m]) ? 1 : 0;

    // default: mark current month active if nothing saved yet
    $hasAny = false;
    for ($m = 1; $m <= 12; $m++)
      if (!empty($out[$m])) {
        $hasAny = true;
        break;
      }
    if (!$hasAny) {
      $curY = (int) date('Y');
      if ((int) $year === $curY)
        $out[(int) date('n')] = 1;
    }

    return $out;
  }

  private static function set_active_months_user($user_id, $year, $months)
  {
    $key = 'kpi_active_months_' . (int) $year;
    $clean = [];
    for ($m = 1; $m <= 12; $m++)
      $clean[$m] = isset($months[$m]) ? 1 : 0;
    update_user_meta((int) $user_id, $key, $clean);
  }

  public static function handle_save_active_months_user()
  {
    if (!is_user_logged_in())
      wp_die('Forbidden');
    if (!self::user_can_access())
      wp_die('Forbidden');

    check_admin_referer('kpi_save_active_months_user');

    $user_id = get_current_user_id();
    $year = isset($_POST['kpi_year']) ? (int) $_POST['kpi_year'] : (int) date('Y');
    $active = isset($_POST['active']) && is_array($_POST['active']) ? $_POST['active'] : [];

    self::set_active_months_user($user_id, $year, $active);

    wp_safe_redirect(wp_get_referer() ?: home_url('/'));
    exit;
  }

  // -----------------------
  // Shortcode
  // -----------------------
  public static function shortcode_dashboard($atts)
  {
    if (!is_user_logged_in()) {
      return '<div class="kpi-wrap"><div class="kpi-shell"><div class="kpi-card kpi-card--glass"><h3>Please log in</h3><p>You need to be logged in to access the KPI system.</p></div></div></div>';
    }

    if (!self::user_can_access()) {
      $levels_url = function_exists('pmpro_url') ? pmpro_url('levels') : wp_login_url();
      return '<div class="kpi-wrap"><div class="kpi-shell"><div class="kpi-card kpi-card--glass"><h3>Subscription required</h3><p>Please subscribe to access the KPI system.</p><p><a class="kpi-btn" href="' . esc_url($levels_url) . '">View Plans</a></p></div></div></div>';
    }

    $user_id = get_current_user_id();

    // Activate pending team membership on dashboard visit (fallback if wp_login hook missed)
    if (class_exists('KPI_Teams')) {
      KPI_Teams::maybe_activate_on_login($user_id);
    }

    $is_team_member = class_exists('KPI_Teams') && KPI_Teams::is_team_member($user_id);

    // Team members use their owner's channels — skip default seeding and setup
    if (!$is_team_member) {
      KPI_DB::ensure_default_channels($user_id);
      if (!self::setup_done($user_id)) {
        return self::render_setup_form($user_id);
      }
    }

    return self::render_dashboard($user_id);
  }

  // -----------------------
  // Dashboard (Activity + Monthly + Charts)
  // -----------------------
  // -----------------------
  // View context (team awareness)
  // -----------------------
  private static function get_view_context($user_id)
  {
    $user_id = (int)$user_id;
    $default = [
      'mode'       => 'own',
      'is_owner'   => false,
      'is_member'  => false,
      'owner_id'   => $user_id,
      'view_uid'   => $user_id,
      'view_uids'  => [$user_id],
      'channel_uid'=> $user_id,
      'can_edit'   => true,
      'member_ids' => [],
      'all_uids'   => [$user_id],
    ];

    if (!class_exists('KPI_Teams')) return $default;

    // Is this user a team member?
    $owner_id = KPI_Teams::get_owner_for_member($user_id);
    if ($owner_id) {
      return array_merge($default, [
        'is_member'   => true,
        'owner_id'    => $owner_id,
        'channel_uid' => $owner_id, // display owner's channels
      ]);
    }

    // Is this user an owner with active members?
    $member_ids = KPI_Teams::get_active_member_ids($user_id);
    $all_uids   = array_merge([$user_id], $member_ids);
    $has_team   = !empty($member_ids);
    $limit      = KPI_Teams::get_member_limit($user_id);
    $is_owner   = $has_team || $limit > 0;

    $kpi_member = isset($_GET['kpi_member']) ? sanitize_text_field($_GET['kpi_member']) : '';

    if ($has_team && $kpi_member === 'combined') {
      return array_merge($default, [
        'mode'       => 'combined',
        'is_owner'   => true,
        'view_uids'  => $all_uids,
        'can_edit'   => false,
        'member_ids' => $member_ids,
        'all_uids'   => $all_uids,
      ]);
    }

    if ($has_team && is_numeric($kpi_member) && (int)$kpi_member > 0) {
      $mid = (int)$kpi_member;
      if (in_array($mid, $member_ids)) {
        return array_merge($default, [
          'mode'        => 'member',
          'is_owner'    => true,
          'view_uid'    => $mid,
          'view_uids'   => [$mid],
          'channel_uid' => $user_id, // owner's channel config for display
          'can_edit'    => false,    // read-only when viewing a member
          'member_ids'  => $member_ids,
          'all_uids'    => $all_uids,
        ]);
      }
    }

    // Owner viewing own data
    return array_merge($default, [
      'is_owner'   => $is_owner,
      'member_ids' => $member_ids,
      'all_uids'   => $all_uids,
    ]);
  }

  private static function render_dashboard($user_id)
  {
    // View context (team awareness)
    $ctx         = self::get_view_context($user_id);
    $data_uid    = (int)$ctx['view_uid'];    // whose KPI data to load
    $channel_uid = (int)$ctx['channel_uid']; // whose channels to display
    $can_edit    = (bool)$ctx['can_edit'];
    $kpi_member  = isset($_GET['kpi_member']) ? sanitize_text_field($_GET['kpi_member']) : '';

    // tab
    $tab = isset($_GET['kpi_tab']) ? sanitize_key($_GET['kpi_tab']) : 'activity';
    if (!in_array($tab, ['activity', 'monthly', 'charts', 'team'], true))
      $tab = 'activity';

    // Force to activity if member/combined view hits team tab
    if ($tab === 'team' && !$ctx['is_owner']) $tab = 'activity';

    // ---- TAB-AWARE date selection ----
    if ($tab === 'monthly' || $tab === 'charts') {
      $year = isset($_GET['kpi_year']) ? (int) $_GET['kpi_year'] : (int) date('Y');
      $month = (int) date('n');
      $ym = sprintf('%04d-%02d', (int) date('Y'), (int) date('n'));
    } else {
      $ym = isset($_GET['kpi_ym']) ? sanitize_text_field($_GET['kpi_ym']) : '';
      if (preg_match('/^\d{4}-\d{2}$/', $ym)) {
        $year = (int) substr($ym, 0, 4);
        $month = (int) substr($ym, 5, 2);
      } else {
        $year = isset($_GET['kpi_year']) ? (int) $_GET['kpi_year'] : (int) date('Y');
        $month = isset($_GET['kpi_month']) ? (int) $_GET['kpi_month'] : (int) date('n');
        $ym = sprintf('%04d-%02d', $year, $month);
      }
    }

    $minYear = 2026;
    $year = max($minYear, min(2100, $year));
    $month = max(1, min(12, $month));

    $daysInMonth = (int) date('t', strtotime(sprintf('%04d-%02d-01', $year, $month)));
    $monthTitle = date('F Y', strtotime(sprintf('%04d-%02d-01', $year, $month)));
    $subtitle = ($tab === 'activity') ? $monthTitle : (string) $year;

    $cycle = self::get_year_cycle_settings($user_id);
    $mode = $cycle['mode'];
    $fyStart = (int) $cycle['fyStart'];

    $yearSelectValue = $year;

    // ---- CHANNELS for Activity tab (per-period support) ----
    $channels = KPI_DB::get_channels_for_period($channel_uid, $ym, true);
    $periodHasOwnChannels = KPI_DB::period_has_own_channels($channel_uid, $ym);

    // Period prompt info for JS (only for owner viewing own data)
    $periodInfo = null;
    if ($tab === 'activity' && !$periodHasOwnChannels && $ctx['mode'] === 'own' && !$ctx['is_member']) {
      $nearestPeriod = KPI_DB::get_nearest_period_with_channels($channel_uid, $ym);
      $periodLabel = date('F Y', strtotime($ym . '-01'));
      $nearestLabel = $nearestPeriod ? date('F Y', strtotime($nearestPeriod . '-01')) : null;
      $periodInfo = [
        'period' => $ym,
        'period_label' => $periodLabel,
        'has_own_channels' => false,
        'nearest_period' => $nearestPeriod,
        'nearest_period_label' => $nearestLabel,
      ];
    }

    // Maps for lookup
    $channelIds = [];
    foreach ($channels as $c)
      $channelIds[] = (int) $c['id'];

    // Pipeline daily + totals
    if ($ctx['mode'] === 'combined') {
      $pipeByDate = KPI_DB::get_month_pipeline_rows_multi($ctx['view_uids'], $year, $month);
      $pipeTotals = KPI_DB::get_month_pipeline_totals_multi($ctx['view_uids'], $year, $month);
      $leadsByDate = KPI_DB::get_month_leads_map_multi($ctx['view_uids'], $year, $month);
      $leadsTotalsByChannel = KPI_DB::get_month_leads_totals_by_channel_multi($ctx['view_uids'], $year, $month);
    } else {
      $pipeByDate = KPI_DB::get_month_pipeline_rows($data_uid, $year, $month);
      $pipeTotals = KPI_DB::get_month_pipeline_totals($data_uid, $year, $month);
      $leadsByDate = KPI_DB::get_month_leads_map($data_uid, $year, $month);
      $leadsTotalsByChannel = KPI_DB::get_month_leads_totals_by_channel($data_uid, $year, $month);
    }

    // compute totals/stats
    $leadTotal = 0;
    foreach ($channelIds as $cid)
      $leadTotal += (float) ($leadsTotalsByChannel[$cid] ?? 0);

    $calls = (float) ($pipeTotals['calls'] ?? 0);
    $apps = (float) ($pipeTotals['appointments'] ?? 0);
    $quotes = (float) ($pipeTotals['quotes'] ?? 0);
    $quoteVal = (float) ($pipeTotals['quote_value'] ?? 0);
    $sales = (float) ($pipeTotals['sales'] ?? 0);
    $salesVal = (float) ($pipeTotals['sales_value'] ?? 0);

    $avgQuote = $quotes > 0 ? $quoteVal / $quotes : 0;
    $avgSale = $sales > 0 ? $salesVal / $sales : 0;

    $callsFromLeads = $leadTotal > 0 ? $calls / $leadTotal : 0;
    $appsFromCalls = $calls > 0 ? $apps / $calls : 0;
    $appsFromLeads = $leadTotal > 0 ? $apps / $leadTotal : 0;
    $quotesFromApps = $apps > 0 ? $quotes / $apps : 0;
    $salesFromQuotes = $quotes > 0 ? $sales / $quotes : 0;
    $salesFromCalls = $calls > 0 ? $sales / $calls : 0;
    $salesFromLeads = $leadTotal > 0 ? $sales / $leadTotal : 0;

    // Build Leads rows meta for Handsontable (KEY = lead_{channel_id})
    $leadsRows = [];
    foreach ($channels as $c) {
      $cid = (int) $c['id'];
      $leadsRows[] = [
        'key' => 'lead_' . $cid,
        'label' => $c['name'],
        'type' => 'int',
        'group' => 'Leads',
      ];
    }

    // Pipeline rows meta
    $salesRows = [
      ['key' => 'calls', 'label' => 'Calls', 'type' => 'int', 'group' => 'Pipeline'],
      ['key' => 'appointments', 'label' => 'Appointments', 'type' => 'int', 'group' => 'Pipeline'],
      ['key' => 'quotes', 'label' => 'Quotes/Presentations', 'type' => 'int', 'group' => 'Pipeline'],
      ['key' => 'quote_value', 'label' => '$$ Value', 'type' => 'money', 'group' => 'Pipeline'],
      ['key' => 'sales', 'label' => 'Sales', 'type' => 'int', 'group' => 'Pipeline'],
      ['key' => 'sales_value', 'label' => 'Sales Value $$', 'type' => 'money', 'group' => 'Pipeline'],
    ];

    // Prefill leads: [rowIndex][dayIndex]
    $leadsPrefill = [];
    for ($ri = 0; $ri < count($leadsRows); $ri++) {
      $leadsPrefill[$ri] = [];
      $cid = (int) str_replace('lead_', '', $leadsRows[$ri]['key']);
      for ($d = 1; $d <= $daysInMonth; $d++) {
        $date = sprintf('%04d-%02d-%02d', $year, $month, $d);
        $val = isset($leadsByDate[$date][$cid]) ? (int) $leadsByDate[$date][$cid] : 0;
        $leadsPrefill[$ri][] = $val;
      }
    }

    // Prefill pipeline: [rowIndex][dayIndex]
    $salesPrefill = [];
    for ($ri = 0; $ri < count($salesRows); $ri++) {
      $salesPrefill[$ri] = [];
      $key = $salesRows[$ri]['key'];
      for ($d = 1; $d <= $daysInMonth; $d++) {
        $date = sprintf('%04d-%02d-%02d', $year, $month, $d);
        $val = isset($pipeByDate[$date][$key]) ? $pipeByDate[$date][$key] : 0;
        $salesPrefill[$ri][] = $val;
      }
    }

    // --------------------
    // Monthly figures data (Issue 2: respect FY cycle)
    // --------------------
    $monthlyBaseYear = isset($_GET['kpi_year']) ? (int) $_GET['kpi_year'] : $year;
    $monthlyBaseYear = max($minYear, min(2100, $monthlyBaseYear));

    // Issue 2: FY view toggle (defaults ON when in financial mode)
    $fyViewEnabled = ($mode === 'financial') &&
      (!isset($_GET['kpi_fy_view']) || (int) $_GET['kpi_fy_view'] !== 0);

    // Build monthly data with FY support
    $fyMonthLabels = [];

    if ($fyViewEnabled) {
      // Pull two calendar years because FY can span across years
      if ($ctx['mode'] === 'combined') {
        $pipeA  = KPI_DB::get_year_pipeline_monthly_totals_multi($ctx['view_uids'], $monthlyBaseYear);
        $pipeB  = KPI_DB::get_year_pipeline_monthly_totals_multi($ctx['view_uids'], $monthlyBaseYear + 1);
        $leadsA = KPI_DB::get_year_leads_monthly_totals_by_name_multi($ctx['view_uids'], $monthlyBaseYear);
        $leadsB = KPI_DB::get_year_leads_monthly_totals_by_name_multi($ctx['view_uids'], $monthlyBaseYear + 1);
      } else {
        $pipeA  = KPI_DB::get_year_pipeline_monthly_totals($data_uid, $monthlyBaseYear);
        $pipeB  = KPI_DB::get_year_pipeline_monthly_totals($data_uid, $monthlyBaseYear + 1);
        $leadsA = KPI_DB::get_year_leads_monthly_totals_by_name($data_uid, $monthlyBaseYear);
        $leadsB = KPI_DB::get_year_leads_monthly_totals_by_name($data_uid, $monthlyBaseYear + 1);
      }

      $monthlyPipeline = [];
      $monthlyLeads = [];

      // i=1..12 are display columns in FY order starting at fyStart
      for ($i = 1; $i <= 12; $i++) {
        $offset = $i - 1;
        $m = (($fyStart - 1 + $offset) % 12) + 1; // calendar month number
        $useNext = (($fyStart - 1 + $offset) >= 12);    // spills into next calendar year?

        $monthlyPipeline[$i] = $useNext ? ($pipeB[$m] ?? []) : ($pipeA[$m] ?? []);
        $monthlyLeads[$i] = $useNext ? ($leadsB[$m] ?? []) : ($leadsA[$m] ?? []);

        $calYear = $useNext ? ($monthlyBaseYear + 1) : $monthlyBaseYear;
        $fyMonthLabels[] = date('M', mktime(0, 0, 0, $m, 1)) . '-' . substr((string) $calYear, -2);
      }

      // Channel names from both years
      if ($ctx['mode'] === 'combined') {
        $namesA = KPI_DB::get_channel_names_for_monthly_view_multi($ctx['view_uids'], $monthlyBaseYear);
        $namesB = KPI_DB::get_channel_names_for_monthly_view_multi($ctx['view_uids'], $monthlyBaseYear + 1);
      } else {
        $namesA = KPI_DB::get_channel_names_for_monthly_view($data_uid, $monthlyBaseYear);
        $namesB = KPI_DB::get_channel_names_for_monthly_view($data_uid, $monthlyBaseYear + 1);
      }
      $monthlyChannelNames = array_values(array_unique(array_merge($namesA, $namesB)));

    } else {
      // Calendar year: Jan..Dec
      if ($ctx['mode'] === 'combined') {
        $monthlyPipeline     = KPI_DB::get_year_pipeline_monthly_totals_multi($ctx['view_uids'], $monthlyBaseYear);
        $monthlyLeads        = KPI_DB::get_year_leads_monthly_totals_by_name_multi($ctx['view_uids'], $monthlyBaseYear);
        $monthlyChannelNames = KPI_DB::get_channel_names_for_monthly_view_multi($ctx['view_uids'], $monthlyBaseYear);
      } else {
        $monthlyPipeline     = KPI_DB::get_year_pipeline_monthly_totals($data_uid, $monthlyBaseYear);
        $monthlyLeads        = KPI_DB::get_year_leads_monthly_totals_by_name($data_uid, $monthlyBaseYear);
        $monthlyChannelNames = KPI_DB::get_channel_names_for_monthly_view($data_uid, $monthlyBaseYear);
      }

      $monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
      $ys = substr((string) $monthlyBaseYear, -2);
      foreach ($monthNames as $mn)
        $fyMonthLabels[] = $mn . '-' . $ys;
    }

    $active = self::get_active_months_user($data_uid, $monthlyBaseYear);

    // Build monthly grid rows (name-based, Issue 1+2)
    $monthlyGrid = [];

    // Section header: Lead Data Totals
    $monthlyGrid[] = self::build_section_header('Lead Data Totals');

    foreach ($monthlyChannelNames as $chName) {
      $monthlyGrid[] = self::build_monthly_row(
        $chName,
        'int',
        $monthlyLeads,
        $active,
        function ($m) use ($chName) {
          return (float) ($m[$chName] ?? 0);
        }
      );
    }

    $monthlyGrid[] = self::build_monthly_row(
      'Total Number Of Leads',
      'int',
      $monthlyLeads,
      $active,
      function ($m) use ($monthlyChannelNames) {
        $sum = 0;
        foreach ($monthlyChannelNames as $n)
          $sum += (float) ($m[$n] ?? 0);
        return $sum;
      },
      'total'
    );

    // Section header: Sales Data Totals
    $monthlyGrid[] = self::build_section_header('Sales Data Totals');

    $monthlyGrid[] = self::build_monthly_row('Calls', 'int', $monthlyPipeline, $active, fn($m) => (float) ($m['calls'] ?? 0));
    $monthlyGrid[] = self::build_monthly_row('Appointments', 'int', $monthlyPipeline, $active, fn($m) => (float) ($m['appointments'] ?? 0));
    $monthlyGrid[] = self::build_monthly_row('Quotes/Proposals Submitted', 'int', $monthlyPipeline, $active, fn($m) => (float) ($m['quotes'] ?? 0));
    $monthlyGrid[] = self::build_monthly_row('$$ Value', 'money0', $monthlyPipeline, $active, fn($m) => (float) ($m['quote_value'] ?? 0));
    $monthlyGrid[] = self::build_monthly_row('Sales', 'int', $monthlyPipeline, $active, fn($m) => (float) ($m['sales'] ?? 0));
    $monthlyGrid[] = self::build_monthly_row('Total Value of Sales', 'money0', $monthlyPipeline, $active, fn($m) => (float) ($m['sales_value'] ?? 0), 'total');

    // Section header: Statistics
    $monthlyGrid[] = self::build_section_header('Statistics');

    $monthlyGrid[] = self::build_monthly_row('Average Quote/Proposal Value', 'money0', $monthlyPipeline, $active, function ($m) {
      $q = (float) ($m['quotes'] ?? 0);
      $v = (float) ($m['quote_value'] ?? 0);
      return $q > 0 ? $v / $q : 0;
    });
    $monthlyGrid[] = self::build_monthly_row('Average Sale Value', 'money0', $monthlyPipeline, $active, function ($m) {
      $s = (float) ($m['sales'] ?? 0);
      $v = (float) ($m['sales_value'] ?? 0);
      return $s > 0 ? $v / $s : 0;
    });

    $monthlyGrid[] = self::build_empty_row();

    $monthlyGrid[] = self::build_monthly_row_with_month_index('Calls from Leads', 'pct', $active, function ($month) use ($monthlyLeads, $monthlyPipeline, $monthlyChannelNames) {
      $leads = 0;
      foreach ($monthlyChannelNames as $n)
        $leads += (float) ($monthlyLeads[$month][$n] ?? 0);
      $calls = (float) ($monthlyPipeline[$month]['calls'] ?? 0);
      return $leads > 0 ? $calls / $leads : 0;
    });

    $monthlyGrid[] = self::build_monthly_row_with_month_index('Appointments from Calls', 'pct', $active, function ($month) use ($monthlyPipeline) {
      $calls = (float) ($monthlyPipeline[$month]['calls'] ?? 0);
      $apps = (float) ($monthlyPipeline[$month]['appointments'] ?? 0);
      return $calls > 0 ? $apps / $calls : 0;
    });

    $monthlyGrid[] = self::build_monthly_row_with_month_index('Appointments from Leads', 'pct', $active, function ($month) use ($monthlyLeads, $monthlyPipeline, $monthlyChannelNames) {
      $leads = 0;
      foreach ($monthlyChannelNames as $n)
        $leads += (float) ($monthlyLeads[$month][$n] ?? 0);
      $apps = (float) ($monthlyPipeline[$month]['appointments'] ?? 0);
      return $leads > 0 ? $apps / $leads : 0;
    });

    $monthlyGrid[] = self::build_monthly_row_with_month_index('Quotes/Proposals From Appointments', 'pct', $active, function ($month) use ($monthlyPipeline) {
      $apps = (float) ($monthlyPipeline[$month]['appointments'] ?? 0);
      $q = (float) ($monthlyPipeline[$month]['quotes'] ?? 0);
      return $apps > 0 ? $q / $apps : 0;
    });

    $monthlyGrid[] = self::build_monthly_row_with_month_index('Sales from Quotes/Proposals', 'pct', $active, function ($month) use ($monthlyPipeline) {
      $q = (float) ($monthlyPipeline[$month]['quotes'] ?? 0);
      $s = (float) ($monthlyPipeline[$month]['sales'] ?? 0);
      return $q > 0 ? $s / $q : 0;
    });

    $monthlyGrid[] = self::build_monthly_row_with_month_index('Sales from Calls', 'pct', $active, function ($month) use ($monthlyPipeline) {
      $calls = (float) ($monthlyPipeline[$month]['calls'] ?? 0);
      $s = (float) ($monthlyPipeline[$month]['sales'] ?? 0);
      return $calls > 0 ? $s / $calls : 0;
    });

    $monthlyGrid[] = self::build_monthly_row_with_month_index('Sales From Leads', 'pct', $active, function ($month) use ($monthlyLeads, $monthlyPipeline, $monthlyChannelNames) {
      $leads = 0;
      foreach ($monthlyChannelNames as $n)
        $leads += (float) ($monthlyLeads[$month][$n] ?? 0);
      $s = (float) ($monthlyPipeline[$month]['sales'] ?? 0);
      return $leads > 0 ? $s / $leads : 0;
    });

    // Lead totals by key for summary cards
    $leadTotalsByKey = [];
    foreach ($channels as $c) {
      $cid = (int) $c['id'];
      $leadTotalsByKey['lead_' . $cid] = (int) ($leadsTotalsByChannel[$cid] ?? 0);
    }

    // --------------------
    // Chart data (Issue 3)
    // --------------------
    $chartYear = ($tab === 'charts') ? $year : $monthlyBaseYear;

    // Leads by channel for the year (use FY-resolved data for chart too)
    $chartLeadsAll = [];
    for ($m = 1; $m <= 12; $m++) {
      foreach ($monthlyLeads[$m] as $chName => $total) {
        $chartLeadsAll[$chName] = ($chartLeadsAll[$chName] ?? 0) + (int) $total;
      }
    }
    arsort($chartLeadsAll);

    $chartData = [
      'leadsByChannel' => [
        'labels' => array_keys($chartLeadsAll),
        'values' => array_values($chartLeadsAll),
      ],
      'monthlyPipeline' => [
        'months' => $fyMonthLabels,
        'calls' => [],
        'appointments' => [],
        'quotes' => [],
        'sales' => [],
      ],
      'monthlyRevenue' => [
        'months' => $fyMonthLabels,
        'quoteValue' => [],
        'salesValue' => [],
      ],
    ];

    for ($i = 1; $i <= 12; $i++) {
      $chartData['monthlyPipeline']['calls'][] = (int) ($monthlyPipeline[$i]['calls'] ?? 0);
      $chartData['monthlyPipeline']['appointments'][] = (int) ($monthlyPipeline[$i]['appointments'] ?? 0);
      $chartData['monthlyPipeline']['quotes'][] = (int) ($monthlyPipeline[$i]['quotes'] ?? 0);
      $chartData['monthlyPipeline']['sales'][] = (int) ($monthlyPipeline[$i]['sales'] ?? 0);
      $chartData['monthlyRevenue']['quoteValue'][] = (float) ($monthlyPipeline[$i]['quote_value'] ?? 0);
      $chartData['monthlyRevenue']['salesValue'][] = (float) ($monthlyPipeline[$i]['sales_value'] ?? 0);
    }

    // FY label for display
    $fyLabel = '';
    if ($fyViewEnabled) {
      $fyEndYear = ($fyStart === 1) ? $monthlyBaseYear : $monthlyBaseYear + 1;
      $fyLabel = 'FY ' . $monthlyBaseYear . '–' . substr((string) $fyEndYear, -2);
    }

    ob_start(); ?>
          <div class="kpi-wrap">
            <div class="kpi-shell">
              <div class="kpi-topbar">
                <div class="kpi-title">
                  <div class="kpi-badge">KPI System</div>
                  <h2>Your KPI Dashboard</h2>
                  <p class="kpi-subtitle"><?php echo esc_html($subtitle); ?></p>
                </div>

                <div class="kpi-controls">
                  <?php if ($tab === 'activity'): ?>
                    <div class="kpi-control-group">
                      <div class="kpi-select-control">
                        <label for="kpiYearSelectActivity">Year</label>
                        <select id="kpiYearSelectActivity" onchange="updateActivityUrl()">
                          <?php
                          $currentYear = (int) date('Y');
                          $startYear = max($minYear, $currentYear);
                          for ($y = $startYear; $y <= $startYear + 10; $y++): ?>
                              <option value="<?php echo $y; ?>" <?php selected($yearSelectValue, $y); ?>><?php echo $y; ?></option>
                          <?php endfor; ?>
                        </select>
                      </div>
                      <div class="kpi-select-control">
                        <label for="kpiMonthSelectActivity">Month</label>
                        <select id="kpiMonthSelectActivity" onchange="updateActivityUrl()">
                          <?php
                          if ($mode === 'financial') {
                            for ($i = 0; $i < 12; $i++) {
                              $m = (($fyStart - 1 + $i) % 12) + 1;
                              ?>
                                    <option value="<?php echo $m; ?>" <?php selected($month, $m); ?>>
                                      <?php echo esc_html(date('F', mktime(0, 0, 0, $m, 1))); ?>
                                    </option>
                                    <?php
                            }
                          } else {
                            for ($m = 1; $m <= 12; $m++) {
                              ?>
                                    <option value="<?php echo $m; ?>" <?php selected($month, $m); ?>>
                                      <?php echo esc_html(date('F', mktime(0, 0, 0, $m, 1))); ?>
                                    </option>
                                    <?php
                            }
                          }
                          ?>
                        </select>
                      </div>
                    </div>
                  
                    <script>
                      function updateActivityUrl() {
                        var y = parseInt(document.getElementById("kpiYearSelectActivity").value, 10);
                        var m = parseInt(document.getElementById("kpiMonthSelectActivity").value, 10);
                        var ym = y + "-" + String(m).padStart(2, "0");
                        var u = new URL(window.location.href);
                        u.searchParams.set("kpi_ym", ym);
                        u.searchParams.set("kpi_year", y);
                        u.searchParams.set("kpi_tab", "activity");
                        window.location.href = u.toString();
                      }
                    </script>

                  <?php elseif ($tab === 'monthly'): ?>
                    <div class="kpi-year-selector">
                      <label for="kpiYearSelectMonthly">Year</label>
                      <select id="kpiYearSelectMonthly" onchange="updateMonthlyYearUrl()">
                        <?php
                        $currentYear = (int) date('Y');
                        $startYear = max($minYear, $currentYear);
                        for ($y = $startYear; $y <= $startYear + 10; $y++): ?>
                              <option value="<?php echo $y; ?>" <?php selected($monthlyBaseYear, $y); ?>><?php echo $y; ?></option>
                        <?php endfor; ?>
                      </select>
                    </div>

                    <?php if ($mode === 'financial'): ?>
                      <div class="kpi-fy-toggle-wrap">
                        <label class="kpi-fy-toggle" title="Toggle Financial Year view">
                          <input type="checkbox" id="kpiFyViewToggle"
                            <?php checked($fyViewEnabled, true); ?>
                            onchange="toggleFyView(this.checked)">
                          <span class="kpi-fy-toggle-track"></span>
                          <span class="kpi-fy-toggle-label">FY View<?php echo $fyViewEnabled ? ' <em>' . esc_html($fyLabel) . '</em>' : ''; ?></span>
                        </label>
                      </div>
                      <script>
                        function toggleFyView(enabled) {
                          var u = new URL(window.location.href);
                          u.searchParams.set("kpi_fy_view", enabled ? "1" : "0");
                          window.location.href = u.toString();
                        }
                      </script>
                    <?php endif; ?>

                    <script>
                      function updateMonthlyYearUrl() {
                        var y = parseInt(document.getElementById("kpiYearSelectMonthly").value, 10);
                        var u = new URL(window.location.href);
                        var curYm = u.searchParams.get("kpi_ym") || (kpiFront?.todayYm ?? "");
                        var m = 1;
                        if (/^\d{4}-\d{2}$/.test(curYm)) m = parseInt(curYm.slice(5, 7), 10);
                        var ym = y + "-" + String(m).padStart(2, "0");
                        u.searchParams.set("kpi_tab", "monthly");
                        u.searchParams.set("kpi_year", y);
                        u.searchParams.set("kpi_ym", ym);
                        window.location.href = u.toString();
                      }
                    </script>

                  <?php elseif ($tab === 'charts'): ?>
                    <div class="kpi-year-selector">
                      <label for="kpiYearSelectCharts">Year</label>
                      <select id="kpiYearSelectCharts" onchange="updateChartsYearUrl()">
                        <?php
                        $currentYear = (int) date('Y');
                        $startYear = max($minYear, $currentYear);
                        for ($y = $startYear; $y <= $startYear + 10; $y++): ?>
                              <option value="<?php echo $y; ?>" <?php selected($monthlyBaseYear, $y); ?>><?php echo $y; ?></option>
                        <?php endfor; ?>
                      </select>
                    </div>
                    <script>
                      function updateChartsYearUrl() {
                        var y = parseInt(document.getElementById("kpiYearSelectCharts").value, 10);
                        var u = new URL(window.location.href);
                        u.searchParams.set("kpi_tab", "charts");
                        u.searchParams.set("kpi_year", y);
                        window.location.href = u.toString();
                      }
                    </script>
                  <?php endif; ?>

                  <?php if ($ctx['is_owner'] && !empty($ctx['member_ids']) && $tab !== 'team'): ?>
                    <div class="kpi-member-switcher">
                      <label for="kpiMemberSwitch">Viewing:</label>
                      <select id="kpiMemberSwitch" onchange="switchMemberView(this.value)">
                        <option value="" <?php selected($kpi_member, ''); ?>>My Data</option>
                        <option value="combined" <?php selected($kpi_member, 'combined'); ?>>Combined (Team)</option>
                        <?php foreach ($ctx['member_ids'] as $mid):
                          $mu = get_user_by('id', $mid);
                          if (!$mu) continue;
                          ?>
                          <option value="<?php echo (int)$mid; ?>" <?php selected((string)$kpi_member, (string)$mid); ?>>
                            <?php echo esc_html($mu->display_name ?: $mu->user_email); ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <script>
                      function switchMemberView(val) {
                        var u = new URL(window.location.href);
                        if (val) { u.searchParams.set('kpi_member', val); }
                        else { u.searchParams.delete('kpi_member'); }
                        window.location.href = u.toString();
                      }
                    </script>
                  <?php endif; ?>

                  <div class="kpi-tabs">
                    <a class="kpi-tab <?php echo $tab === 'activity' ? 'is-active' : ''; ?>" href="<?php echo esc_url(add_query_arg(['kpi_tab' => 'activity', 'kpi_member' => $kpi_member ?: null])); ?>">Activity</a>
                    <a class="kpi-tab <?php echo $tab === 'monthly' ? 'is-active' : ''; ?>" href="<?php echo esc_url(add_query_arg(['kpi_tab' => 'monthly', 'kpi_member' => $kpi_member ?: null])); ?>">Monthly figures</a>
                    <a class="kpi-tab <?php echo $tab === 'charts' ? 'is-active' : ''; ?>" href="<?php echo esc_url(add_query_arg(['kpi_tab' => 'charts', 'kpi_member' => $kpi_member ?: null])); ?>">Charts</a>
                    <?php if ($ctx['is_owner']): ?>
                      <a class="kpi-tab <?php echo $tab === 'team' ? 'is-active' : ''; ?>" href="<?php echo esc_url(add_query_arg(['kpi_tab' => 'team'])); ?>">Team</a>
                    <?php endif; ?>
                  </div>

                  <?php if (!$ctx['is_member']): ?>
                    <button type="button" id="kpiSettingsToggle" class="kpi-settings-btn" title="Settings">
                      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="3"></circle>
                        <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
                      </svg>
                    </button>
                  <?php endif; ?>
                </div>
              </div>

              <?php if ($ctx['is_member']): ?>
                <?php
                $owner_user   = get_user_by('id', $ctx['owner_id']);
                $owner_name   = $owner_user ? $owner_user->display_name : 'your team admin';
                $current_name = wp_get_current_user()->display_name;
                ?>
                <div class="kpi-team-member-notice">
                  <span>You're entering data as part of <strong><?php echo esc_html($owner_name); ?></strong>'s team.</span>
                  <div class="kpi-member-name-row">
                    <label for="kpiMemberNameInput">Your name:</label>
                    <input type="text" id="kpiMemberNameInput"
                      value="<?php echo esc_attr($current_name); ?>"
                      placeholder="Enter your display name"
                      class="kpi-member-name-input">
                    <button type="button" id="kpiMemberNameSave" class="kpi-btn kpi-btn--sm">Save</button>
                    <span id="kpiMemberNameMsg" class="kpi-member-name-msg" style="display:none;"></span>
                  </div>
                </div>
              <?php endif; ?>

              <?php if ($tab === 'activity' && $can_edit): ?>
                  <div class="kpi-savebar">
                    <span id="kpiAutosaveStatus" class="kpi-autosave-status"></span>
                    <button class="kpi-btn kpi-btn--save kpi-btn--save-bar" type="submit" form="kpiActivityForm">Save Month</button>
                  </div>
              <?php elseif ($tab === 'activity' && !$can_edit): ?>
                  <div class="kpi-savebar kpi-savebar--readonly">
                    <span class="kpi-autosave-status">
                      <?php echo $ctx['mode'] === 'combined' ? 'Combined view — read only' : 'Viewing member data — read only'; ?>
                    </span>
                  </div>
              <?php endif; ?>

              <?php if ($tab === 'activity'): ?>

                  <?php if ($periodInfo): ?>
                    <script type="application/json" id="kpi_period_info"><?php echo wp_json_encode($periodInfo); ?></script>
                  <?php endif; ?>

                  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="kpiActivityForm">
                    <?php wp_nonce_field('kpi_front_save_month'); ?>
                    <input type="hidden" name="action" value="kpi_front_save_month">
                    <input type="hidden" name="kpi_ym" value="<?php echo esc_attr($ym); ?>">
                    <input type="hidden" name="kpi_payload" id="kpi_payload" value="">

                    <!-- Leads Table -->
                    <div class="kpi-card kpi-card--glass kpi-mb">
                      <div class="kpi-hot-head">
                        <div>
                          <h3>Leads — <?php echo esc_html($monthTitle); ?></h3>
                          <p class="kpi-muted">Click a cell and type. Use arrow keys like a spreadsheet.</p>
                        </div>
                      </div>
                      <div id="kpiHotLeads" class="kpi-hot"></div>
                    </div>

                    <!-- Sales Statistics Table -->
                    <div class="kpi-card kpi-card--glass kpi-mb">
                      <div class="kpi-hot-head">
                        <div>
                          <h3>Sales Statistics — <?php echo esc_html($monthTitle); ?></h3>
                        </div>
                      </div>
                      <div id="kpiHotSales" class="kpi-hot"></div>
                    </div>
                  </form>

                  <!-- Summary Section -->
                  <div class="kpi-summary-flex">
                    <div class="kpi-card kpi-card--summary">
                      <h3>Lead Data Totals</h3>
                      <div class="kpi-summary-grid">
                        <?php foreach ($channels as $c):
                          $cid = (int) $c['id'];
                          $k = 'lead_' . $cid;
                          $val = (int) ($leadsTotalsByChannel[$cid] ?? 0);
                          ?>
                            <div class="kpi-kv">
                              <span><?php echo esc_html($c['name']); ?></span>
                              <strong class="kpi-lead-total" data-key="<?php echo esc_attr($k); ?>"><?php echo esc_html($val); ?></strong>
                            </div>
                        <?php endforeach; ?>
                        <div class="kpi-kv kpi-kv--total">
                          <span>Total Number Of Leads</span>
                          <strong id="kpi_total_leads" class="kpi-total-highlight"><?php echo esc_html((int) $leadTotal); ?></strong>
                        </div>
                      </div>
                    </div>

                    <div class="kpi-card kpi-card--summary">
                      <h3>Sales Data Totals</h3>
                      <div class="kpi-summary-grid">
                        <div class="kpi-kv"><span>Calls</span><strong id="kpi_total_calls"><?php echo esc_html((int) $calls); ?></strong></div>
                        <div class="kpi-kv"><span>Appointments</span><strong id="kpi_total_apps"><?php echo esc_html((int) $apps); ?></strong></div>
                        <div class="kpi-kv"><span>Quotes/Proposals Submitted</span><strong id="kpi_total_quotes"><?php echo esc_html((int) $quotes); ?></strong></div>
                        <div class="kpi-kv"><span>Total Value of Quote/Proposals</span><strong id="kpi_total_quote_val"><?php echo esc_html(self::fmt_money2($quoteVal)); ?></strong></div>
                        <div class="kpi-kv"><span>Number of Sales Won</span><strong id="kpi_total_sales"><?php echo esc_html((int) $sales); ?></strong></div>
                        <div class="kpi-kv kpi-kv--total"><span>Total Value of Sales</span><strong id="kpi_total_sales_val" class="kpi-total-highlight"><?php echo esc_html(self::fmt_money2($salesVal)); ?></strong></div>
                      </div>
                    </div>

                    <div class="kpi-card kpi-card--summary">
                      <h3>Statistics</h3>
                      <div class="kpi-summary-grid">
                        <div class="kpi-kv"><span>Average Quoted Value</span><strong id="kpi_avg_quote"><?php echo esc_html(self::fmt_money2($avgQuote)); ?></strong></div>
                        <div class="kpi-kv"><span>Average Sales Value</span><strong id="kpi_avg_sale"><?php echo esc_html(self::fmt_money2($avgSale)); ?></strong></div>
                        <div class="kpi-kv kpi-kv--spacer"></div>
                        <div class="kpi-kv"><span>Calls from Leads</span><strong id="kpi_calls_from_leads"><?php echo esc_html(self::fmt_percent2($callsFromLeads)); ?></strong></div>
                        <div class="kpi-kv"><span>Appointments from Calls</span><strong id="kpi_apps_from_calls"><?php echo esc_html(self::fmt_percent2($appsFromCalls)); ?></strong></div>
                        <div class="kpi-kv"><span>Appointments from Leads</span><strong id="kpi_apps_from_leads"><?php echo esc_html(self::fmt_percent2($appsFromLeads)); ?></strong></div>
                        <div class="kpi-kv"><span>Quotes/Proposals From Appointments</span><strong id="kpi_quotes_from_apps"><?php echo esc_html(self::fmt_percent2($quotesFromApps)); ?></strong></div>
                        <div class="kpi-kv"><span>Sales from Quotes/Proposals</span><strong id="kpi_sales_from_quotes"><?php echo esc_html(self::fmt_percent2($salesFromQuotes)); ?></strong></div>
                        <div class="kpi-kv"><span>Sales from Calls</span><strong id="kpi_sales_from_calls"><?php echo esc_html(self::fmt_percent2($salesFromCalls)); ?></strong></div>
                        <div class="kpi-kv"><span>Sales from Leads</span><strong id="kpi_sales_from_leads"><?php echo esc_html(self::fmt_percent2($salesFromLeads)); ?></strong></div>
                      </div>
                    </div>
                  </div>

                  <script type="application/json" id="kpi_leads_rows"><?php echo wp_json_encode($leadsRows); ?></script>
                  <script type="application/json" id="kpi_leads_prefill"><?php echo wp_json_encode($leadsPrefill); ?></script>
                  <script type="application/json" id="kpi_sales_rows"><?php echo wp_json_encode($salesRows); ?></script>
                  <script type="application/json" id="kpi_sales_prefill"><?php echo wp_json_encode($salesPrefill); ?></script>
                  <script type="application/json" id="kpi_activity_meta"><?php echo wp_json_encode([
                    'ym' => $ym,
                    'daysInMonth' => $daysInMonth,
                    'selectedLeadKeys' => array_map(function ($c) {
                    return 'lead_' . (int) $c['id']; }, $channels),
                    'canEdit' => $can_edit,
                    'viewMode' => $ctx['mode'],
                  ]); ?></script>

              <?php elseif ($tab === 'monthly'): ?>
                  <div class="kpi-card kpi-card--glass">
                    <div class="kpi-hot-head">
                      <div>
                        <h3>Monthly figures — <?php echo $fyViewEnabled ? esc_html($fyLabel) : esc_html($monthlyBaseYear); ?></h3>
                        <p class="kpi-muted">View your yearly KPI breakdown by month.</p>
                      </div>
                    </div>

                    <div id="kpiHotMonthly" class="kpi-hot kpi-hot--monthly"></div>

                    <script type="application/json" id="kpi_monthly_grid"><?php echo wp_json_encode($monthlyGrid); ?></script>
                    <script type="application/json" id="kpi_monthly_meta"><?php echo wp_json_encode([
                      'year' => $monthlyBaseYear,
                      'yearShort' => substr((string) $monthlyBaseYear, -2),
                      'monthLabels' => $fyMonthLabels,
                      'fyView' => $fyViewEnabled,
                    ]); ?></script>
                  </div>

              <?php elseif ($tab === 'charts'): ?>
                  <div class="kpi-card kpi-card--glass">
                    <div class="kpi-hot-head">
                      <div>
                        <h3>Charts — <?php echo $fyViewEnabled ? esc_html($fyLabel) : esc_html($monthlyBaseYear); ?></h3>
                        <p class="kpi-muted">Visual overview of your KPI performance for the year.</p>
                      </div>
                    </div>

                    <div class="kpi-charts-grid">
                      <div class="kpi-chart-card">
                        <h4>Leads by Channel</h4>
                        <div class="kpi-chart-wrap">
                          <canvas id="kpiChartLeads"></canvas>
                        </div>
                      </div>
                      <div class="kpi-chart-card">
                        <h4>Monthly Pipeline</h4>
                        <div class="kpi-chart-wrap">
                          <canvas id="kpiChartPipeline"></canvas>
                        </div>
                      </div>
                      <div class="kpi-chart-card kpi-chart-card--full">
                        <h4>Monthly Revenue</h4>
                        <div class="kpi-chart-wrap">
                          <canvas id="kpiChartRevenue"></canvas>
                        </div>
                      </div>
                    </div>

                    <script type="application/json" id="kpi_chart_data"><?php echo wp_json_encode($chartData); ?></script>
                  </div>
              <?php elseif ($tab === 'team' && $ctx['is_owner']): ?>
                  <?php echo self::render_team_tab($user_id); ?>

              <?php endif; ?>

              <?php if (!$ctx['is_member']): ?>
              <!-- Settings Drawer Overlay -->
              <div id="kpiSettingsOverlay" class="kpi-drawer-overlay"></div>

              <!-- Settings Drawer -->
              <div id="kpiSettingsDrawer" class="kpi-drawer">
                <div class="kpi-drawer-header">
                  <h3>Channel Settings</h3>
                  <button type="button" id="kpiSettingsClose" class="kpi-drawer-close">&times;</button>
                </div>
                <div class="kpi-drawer-body">
                  <?php
                  $globalChannels = KPI_DB::get_channels($user_id, false);
                  $drawerPeriodChannels = KPI_DB::get_channels_for_period($user_id, $ym, false);
                  ?>
                  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="kpi-drawer-form" id="kpiSettingsForm">
                    <?php wp_nonce_field('kpi_save_user_setup'); ?>
                    <input type="hidden" name="action" value="kpi_save_user_setup">
                    <!-- Populated by JS before submit: "" = global (null), "YYYY-MM" = period -->
                    <input type="hidden" name="kpi_period" id="kpiDrawerPeriodInput" value="" data-ym="<?php echo esc_attr($ym); ?>">
                    <input type="hidden" name="kpi_channels_json" id="kpi_channels_json_settings" value="">
                    <!-- Per-editor JSON (no name = not submitted directly) -->
                    <input type="hidden" id="kpi_channels_json_global" value="">
                    <input type="hidden" id="kpi_channels_json_period" value="">

                    <?php $cycle = self::get_year_cycle_settings($user_id); ?>
                    <div class="kpi-cycle-box" style="margin:0 0 16px;">
                      <p class="kpi-drawer-hint" style="margin:0 0 10px;">Year cycle:</p>

                      <label class="kpi-drawer-check" style="margin-bottom:8px;">
                        <input type="radio" name="kpi_year_mode" value="calendar" <?php checked($cycle['mode'], 'calendar'); ?>>
                        <span>Calendar year (Jan–Dec)</span>
                      </label>

                      <label class="kpi-drawer-check">
                        <input type="radio" name="kpi_year_mode" value="financial" <?php checked($cycle['mode'], 'financial'); ?>>
                        <span>Financial year</span>
                      </label>

                      <div id="kpiFyStartWrap" style="margin-top:10px; <?php echo $cycle['mode'] === 'financial' ? '' : 'display:none;'; ?>">
                        <div class="kpi-drawer-field">
                          <label>Financial year start month</label>
                          <select name="kpi_fy_start_month" id="kpiFyStartSelect" style="width:100%;height:44px;border-radius:12px;">
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?php echo $m; ?>" <?php selected($cycle['fyStart'], $m); ?>>
                                  <?php echo esc_html(date('F', mktime(0, 0, 0, $m, 1))); ?>
                                </option>
                            <?php endfor; ?>
                          </select>
                        </div>
                      </div>
                    </div>

                    <!-- Channel set tabs -->
                    <div class="kpi-dtabs" role="tablist">
                      <button type="button" class="kpi-dtab is-active" data-view="global" role="tab"
                        data-kpi-tip="Global channels are the default for all months">Global</button>
                      <button type="button" class="kpi-dtab" data-view="period" role="tab"
                        data-kpi-tip="Override channels for <?php echo esc_attr(date('F Y', strtotime($ym . '-01'))); ?> only">
                        <?php echo esc_html(date('M Y', strtotime($ym . '-01'))); ?>
                        <?php if ($periodHasOwnChannels): ?>
                            <span class="kpi-dtab-badge">Custom</span>
                        <?php endif; ?>
                      </button>
                    </div>

                    <!-- Global panel -->
                    <div class="kpi-drawer-panel" id="kpiDrawerPanel_global" role="tabpanel">
                      <p class="kpi-drawer-hint">Default channels used across all months.</p>
                      <div class="kpi-drawer-editor">
                        <?php echo self::render_channel_editor($globalChannels, '_global'); ?>
                      </div>
                    </div>

                    <!-- This Month panel -->
                    <div class="kpi-drawer-panel" id="kpiDrawerPanel_period" role="tabpanel" style="display:none;">
                      <p class="kpi-drawer-hint">
                        Channels for <strong><?php echo esc_html(date('F Y', strtotime($ym . '-01'))); ?></strong>
                        <?php if (!$periodHasOwnChannels): ?>
                            <span class="kpi-drawer-hint-tag">(using global)</span>
                        <?php endif; ?>
                      </p>
                      <div class="kpi-drawer-editor">
                        <?php echo self::render_channel_editor($drawerPeriodChannels, '_period'); ?>
                      </div>
                    </div>

                    <button type="submit" class="kpi-btn kpi-drawer-submit">Save Settings</button>
                  </form>
                </div>
              </div>
              <?php endif; // !$ctx['is_member'] — end settings drawer ?>
            </div>
          </div>
        <?php
        return ob_get_clean();
  }

  // -----------------------
  // Team tab
  // -----------------------
  private static function render_team_tab($owner_id)
  {
    $limit        = class_exists('KPI_Teams') ? KPI_Teams::get_member_limit($owner_id) : 0;
    $members      = class_exists('KPI_Teams') ? KPI_Teams::get_team_members($owner_id) : [];
    $active_count = 0;
    foreach ($members as $m) {
      if ($m['status'] === 'active') $active_count++;
    }
    $pending_active_count = 0;
    foreach ($members as $m) {
      if (in_array($m['status'], ['active', 'pending'], true)) $pending_active_count++;
    }

    ob_start(); ?>
        <div class="kpi-card kpi-card--glass">
          <div class="kpi-hot-head">
            <div>
              <h3>Team Management</h3>
              <p class="kpi-muted">Invite team members to track their own KPI data alongside yours.</p>
            </div>
          </div>

          <?php if ($limit <= 0): ?>
            <div class="kpi-team-upgrade">
              <p>Your current plan does not include team members.</p>
              <?php if (function_exists('pmpro_url')): ?>
                <a class="kpi-btn" href="<?php echo esc_url(pmpro_url('levels')); ?>">Upgrade Plan</a>
              <?php endif; ?>
            </div>
          <?php else: ?>

            <!-- Usage bar -->
            <div class="kpi-team-usage">
              <div class="kpi-team-usage-label">
                <span><?php echo esc_html($active_count); ?> of <?php echo esc_html($limit); ?> members active</span>
              </div>
              <div class="kpi-team-usage-bar">
                <div class="kpi-team-usage-fill" style="width:<?php echo esc_attr(min(100, $limit > 0 ? round($active_count / $limit * 100) : 0)); ?>%"></div>
              </div>
            </div>

            <!-- Invite form -->
            <?php if ($pending_active_count < $limit): ?>
            <div class="kpi-team-invite">
              <h4>Invite a team member</h4>
              <div class="kpi-team-invite-row">
                <input type="email" id="kpiTeamInviteEmail" placeholder="colleague@example.com" class="kpi-team-email-input">
                <button type="button" id="kpiTeamInviteBtn" class="kpi-btn">Send Invite</button>
              </div>
              <p id="kpiTeamInviteMsg" class="kpi-team-msg" style="display:none;"></p>
            </div>
            <?php else: ?>
            <p class="kpi-muted" style="padding:8px 0;">
              Team limit reached (<?php echo esc_html($limit); ?> members). Disable or remove a member to invite someone new.
            </p>
            <?php endif; ?>

            <!-- Members list -->
            <div class="kpi-team-list" id="kpiTeamList">
              <?php if (!empty($members)): ?>
              <h4>Current Members</h4>
              <div class="kpi-team-table">
                <div class="kpi-team-row kpi-team-row--head">
                  <span>Email / Name</span>
                  <span>Status</span>
                  <span>Invited</span>
                  <span>Actions</span>
                </div>
                <?php foreach ($members as $m):
                  $mu = $m['member_id'] ? get_user_by('id', $m['member_id']) : null;
                  echo KPI_Teams::render_member_row($m, $mu);
                endforeach; ?>
              </div>
              <?php else: ?>
              <p class="kpi-muted" style="padding:12px 0;">No team members yet. Invite someone above to get started.</p>
              <?php endif; ?>
            </div>

          <?php endif; ?>
        </div>
    <?php
    return ob_get_clean();
  }

  // -----------------------
  // Monthly grid helpers
  // -----------------------
  private static function build_monthly_row($label, $fmt, $monthly, $active, $getter, $rowType = '')
  {
    $row = [];
    $row[] = $label;

    $ytd = 0;
    $den = 0;

    for ($m = 1; $m <= 12; $m++) {
      $val = (float) $getter($monthly[$m] ?? []);
      $row[] = self::format_monthly_cell($fmt, $val);

      if ($val > 0 || self::month_has_any_data($monthly[$m] ?? [])) {
        $ytd += $val;
        $den++;
      }
    }

    $avg = $den > 0 ? $ytd / $den : 0;

    $row[] = self::format_monthly_cell($fmt, $ytd);
    $row[] = self::format_monthly_cell($fmt, $avg);

    $row[] = $rowType;
    return $row;
  }

  private static function build_monthly_row_with_month_index($label, $fmt, $active, $getter, $rowType = '')
  {
    $row = [];
    $row[] = $label;

    $ytd = 0;
    $den = 0;

    for ($m = 1; $m <= 12; $m++) {
      $val = (float) $getter($m);
      $row[] = self::format_monthly_cell($fmt, $val);

      if ($val > 0) {
        $ytd += $val;
        $den++;
      }
    }

    $avg = $den > 0 ? $ytd / $den : 0;

    $row[] = self::format_monthly_cell($fmt, $ytd);
    $row[] = self::format_monthly_cell($fmt, $avg);

    $row[] = $rowType;
    return $row;
  }

  private static function month_has_any_data($monthData)
  {
    if (!is_array($monthData))
      return false;
    foreach ($monthData as $val) {
      if ((float) $val > 0)
        return true;
    }
    return false;
  }

  private static function build_section_header($title)
  {
    $row = [$title];
    for ($m = 1; $m <= 14; $m++) { // 12 months + YTD + Avg
      $row[] = '';
    }
    $row[] = '__section__';
    return $row;
  }

  private static function build_empty_row()
  {
    $row = [''];
    for ($m = 1; $m <= 14; $m++) {
      $row[] = '';
    }
    $row[] = '__empty__';
    return $row;
  }

  private static function format_monthly_cell($fmt, $val)
  {
    if ($fmt === 'money0')
      return self::fmt_money0($val);
    if ($fmt === 'money2')
      return self::fmt_money2($val);
    if ($fmt === 'pct')
      return self::fmt_percent2($val);
    return (string) ((int) round($val));
  }

  // -----------------------
  // Save Activity payload (Handsontable -> DB)
  // -----------------------
  public static function handle_front_save_month()
  {
    if (!is_user_logged_in())
      wp_die('Forbidden');
    if (!self::user_can_access())
      wp_die('Forbidden');

    check_admin_referer('kpi_front_save_month');

    $user_id = get_current_user_id();

    $ym = isset($_POST['kpi_ym']) ? sanitize_text_field($_POST['kpi_ym']) : '';
    if (!preg_match('/^\d{4}-\d{2}$/', $ym))
      wp_die('Invalid month');

    $payloadRaw = isset($_POST['kpi_payload']) ? wp_unslash($_POST['kpi_payload']) : '';
    $payload = json_decode($payloadRaw, true);
    if (!is_array($payload) || empty($payload['kpi']) || !is_array($payload['kpi'])) {
      wp_die('Invalid payload');
    }

    $pipelineKeys = KPI_DB::pipeline_fields();

    foreach ($payload['kpi'] as $date => $fields) {
      $date = sanitize_text_field($date);
      if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date))
        continue;
      if (!is_array($fields))
        continue;

      // 1) Leads per channel (lead_{id})
      foreach ($fields as $k => $v) {
        if (strpos($k, 'lead_') === 0) {
          $cid = (int) substr($k, 5);
          KPI_DB::upsert_lead_day($user_id, $cid, $date, $v);
        }
      }

      // 2) Pipeline in kpi_daily (calls, appointments, etc)
      $pipe = [];
      foreach ($pipelineKeys as $pk) {
        if (!array_key_exists($pk, $fields))
          continue;
        $pipe[$pk] = KPI_DB::sanitize_numeric($pk, $fields[$pk]);
      }
      if (!empty($pipe)) {
        KPI_DB::upsert_pipeline_day($user_id, $date, $pipe);
      }
    }

    wp_safe_redirect(wp_get_referer() ?: home_url('/'));
    exit;
  }

  public static function handle_autosave_patch()
  {
    if (!is_user_logged_in())
      wp_send_json_error(['msg' => 'Forbidden'], 403);
    if (!self::user_can_access())
      wp_send_json_error(['msg' => 'Forbidden'], 403);

    check_ajax_referer('kpi_autosave', 'nonce');

    $user_id = get_current_user_id();

    $raw = isset($_POST['patch']) ? wp_unslash($_POST['patch']) : '';
    $patch = json_decode($raw, true);

    if (!is_array($patch) || empty($patch['changes']) || !is_array($patch['changes'])) {
      wp_send_json_error(['msg' => 'Invalid patch'], 400);
    }

    $pipelineKeys = KPI_DB::pipeline_fields();
    $pipeByDate = [];

    foreach ($patch['changes'] as $ch) {
      $date = isset($ch['date']) ? sanitize_text_field($ch['date']) : '';
      $key = isset($ch['key']) ? sanitize_text_field($ch['key']) : '';
      $val = $ch['value'] ?? 0;

      if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date))
        continue;

      if (strpos($key, 'lead_') === 0) {
        $cid = (int) substr($key, 5);
        KPI_DB::upsert_lead_day($user_id, $cid, $date, $val);
        continue;
      }

      if (in_array($key, $pipelineKeys, true)) {
        if (!isset($pipeByDate[$date]))
          $pipeByDate[$date] = [];
        $pipeByDate[$date][$key] = KPI_DB::sanitize_numeric($key, $val);
      }
    }

    foreach ($pipeByDate as $date => $fields) {
      KPI_DB::upsert_pipeline_day($user_id, $date, $fields);
    }

    wp_send_json_success(['ok' => true]);
  }
}

add_action('init', ['KPI_Frontend', 'init']);
