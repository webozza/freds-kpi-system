<?php
/**
 * Plugin Name: KPI Calculator
 * Description: KPI Tracker (Frontend only) - Activity + Monthly Figures (per user)
 * Version: 1.8.2
 */

if (!defined('ABSPATH')) exit;

define('KPI_CALC_VERSION', '1.8.2');
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



