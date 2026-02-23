<?php
/**
 * Plugin Name: KPI Calculator
 * Description: KPI Tracker (Frontend only) - Activity + Monthly Figures (per user)
 * Version: 1.7.1
 */

if (!defined('ABSPATH')) exit;

define('KPI_CALC_VERSION', '1.7.1');
define('KPI_CALC_PATH', plugin_dir_path(__FILE__));
define('KPI_CALC_URL', plugin_dir_url(__FILE__));

require_once KPI_CALC_PATH . 'includes/class-kpi-db.php';
require_once KPI_CALC_PATH . 'includes/class-kpi-frontend.php';

register_activation_hook(__FILE__, ['KPI_DB', 'activate']);

add_action('plugins_loaded', function () {
  // Run DB upgrades on every load (safe: each checks if already done)
  KPI_DB::maybe_upgrade_channels_table_v2();
  KPI_Frontend::init();
});



