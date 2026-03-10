<?php
/**
 * Plugin Name: KPI Calculator
 * Description: KPI Tracker (Frontend only) - Activity + Monthly Figures (per user)
 * Version: 1.9.0
 */

if (!defined('ABSPATH')) exit;

define('KPI_CALC_VERSION', '1.9.0');
define('KPI_CALC_PATH', plugin_dir_path(__FILE__));
define('KPI_CALC_URL', plugin_dir_url(__FILE__));

require_once KPI_CALC_PATH . 'includes/class-kpi-db.php';
require_once KPI_CALC_PATH . 'includes/class-kpi-teams.php';
require_once KPI_CALC_PATH . 'includes/class-kpi-frontend.php';

register_activation_hook(__FILE__, function () {
  KPI_DB::activate();
  KPI_Teams::create_table();

  // Register kpi_team_member role (safe to call multiple times)
  add_role('kpi_team_member', 'KPI Team Member', [
    'read' => true,
  ]);
});

add_action('plugins_loaded', function () {
  // Run DB upgrades on every load (safe: each checks if already done)
  KPI_DB::maybe_upgrade_channels_table_v2();
  KPI_Teams::create_table(); // idempotent via dbDelta
  KPI_Teams::register_ajax();
  KPI_Frontend::init();

  // Register role (in case it was removed or plugin activated before this code)
  if (!get_role('kpi_team_member')) {
    add_role('kpi_team_member', 'KPI Team Member', ['read' => true]);
  }
});

// Activate pending team members when they first log in
add_action('wp_login', function ($user_login, $user) {
  KPI_Teams::maybe_activate_on_login((int)$user->ID);
}, 10, 2);

// Style the WP login page for the invite / password-reset flow
add_action('login_enqueue_scripts', function () {
  $action = isset($_GET['action']) ? sanitize_key($_GET['action']) : 'login';
  if (!in_array($action, ['rp', 'resetpass', 'lostpassword', 'retrievepassword'], true)) return;

  wp_enqueue_style('kpi-login', KPI_CALC_URL . 'assets/login.css', [], KPI_CALC_VERSION);
});

add_action('login_head', function () {
  $action = isset($_GET['action']) ? sanitize_key($_GET['action']) : 'login';
  if (!in_array($action, ['rp', 'resetpass', 'lostpassword', 'retrievepassword'], true)) return;

  // Resolve business name from the invited user's owner
  $business_name = '';
  if (!empty($_GET['login']) && class_exists('KPI_Teams')) {
    $wp_user = get_user_by('login', sanitize_user(wp_unslash($_GET['login'])));
    if ($wp_user) {
      $owner_id = KPI_Teams::get_owner_for_member((int) $wp_user->ID);
      if ($owner_id) {
        $business_name = trim((string) get_user_meta($owner_id, 'kpi_business_name', true));
      }
    }
  }
  $title = $business_name ?: get_bloginfo('name');
  ?>
  <style>
    body.login::before {
      content: '';
      position: fixed;
      inset: 0;
      background: radial-gradient(ellipse at 20% 30%, rgba(102,240,194,0.06) 0%, transparent 60%),
                  radial-gradient(ellipse at 80% 80%, rgba(26,61,53,0.4) 0%, transparent 60%);
      pointer-events: none;
    }
  </style>
  <script>
  document.addEventListener('DOMContentLoaded', function () {
    var wrap = document.getElementById('login');
    if (!wrap) return;
    var brand = document.createElement('div');
    brand.id = 'kpi-login-brand';
    brand.innerHTML =
      '<div class="kpi-login-badge">KPI System</div>' +
      '<h1><?php echo esc_js($title); ?></h1>' +
      '<p>You\'ve been invited to join the team</p>';
    wrap.insertBefore(brand, wrap.firstChild);
    // Hide the default WP h1 logo link
    var logo = document.querySelector('#login h1 a');
    if (logo) logo.closest('h1').style.display = 'none';
  });
  </script>
  <?php
});

// Allow active team members to pass PMPro's page-level access check
add_filter('pmpro_has_membership_access', function ($hasaccess, $post, $user, $levels) {
  if ($hasaccess) return $hasaccess; // already allowed, don't interfere
  if (!$user || !isset($user->ID)) return $hasaccess;

  $owner_id = KPI_Teams::get_owner_for_member((int)$user->ID);
  if (!$owner_id) return $hasaccess;

  // Grant access only if the owner's plan supports team members
  if (KPI_Teams::get_member_limit($owner_id) > 0) return true;

  return $hasaccess;
}, 10, 4);



