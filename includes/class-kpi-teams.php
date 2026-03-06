<?php
if (!defined('ABSPATH')) exit;

class KPI_Teams {

  // ----------- table -----------
  public static function table_teams() {
    global $wpdb;
    return $wpdb->prefix . 'kpi_teams';
  }

  public static function create_table() {
    global $wpdb;
    $t       = self::table_teams();
    $charset = $wpdb->get_charset_collate();

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $sql = "CREATE TABLE $t (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      owner_id BIGINT UNSIGNED NOT NULL,
      member_id BIGINT UNSIGNED NULL DEFAULT NULL,
      invite_email VARCHAR(191) NOT NULL,
      invite_token VARCHAR(64) NOT NULL DEFAULT '',
      status ENUM('pending','active','disabled') NOT NULL DEFAULT 'pending',
      invited_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      activated_at DATETIME NULL DEFAULT NULL,
      PRIMARY KEY (id),
      UNIQUE KEY owner_email (owner_id, invite_email),
      KEY owner_id (owner_id),
      KEY member_id (member_id),
      KEY status (status),
      KEY invite_token (invite_token)
    ) $charset;";

    dbDelta($sql);
  }

  // ----------- plan limits -----------
  // Level 1 = 0 members, Level 2 = 5, Level 3 = 10
  public static function get_member_limit($user_id) {
    $user_id = (int)$user_id;

    // Allow override without PMPro
    $override = apply_filters('kpi_team_member_limit', -1, $user_id);
    if ($override >= 0) return (int)$override;

    if (!function_exists('pmpro_getMembershipLevelForUser')) return 0;

    $level = pmpro_getMembershipLevelForUser($user_id);
    if (!$level) return 0;

    $limits = apply_filters('kpi_team_member_limits', [
      1 => 0,
      2 => 0,
      3 => 5,
      4 => 10,
    ]);

    return (int)($limits[(int)$level->id] ?? 0);
  }

  // ----------- queries -----------
  public static function get_team_members($owner_id) {
    global $wpdb;
    $t = self::table_teams();
    return $wpdb->get_results($wpdb->prepare(
      "SELECT * FROM $t WHERE owner_id=%d ORDER BY invited_at ASC",
      (int)$owner_id
    ), ARRAY_A);
  }

  public static function get_active_member_ids($owner_id) {
    global $wpdb;
    $t    = self::table_teams();
    $rows = $wpdb->get_col($wpdb->prepare(
      "SELECT member_id FROM $t WHERE owner_id=%d AND status='active' AND member_id IS NOT NULL",
      (int)$owner_id
    ));
    return array_map('intval', (array)$rows);
  }

  public static function get_active_member_count($owner_id) {
    global $wpdb;
    $t = self::table_teams();
    return (int)$wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM $t WHERE owner_id=%d AND status='active'",
      (int)$owner_id
    ));
  }

  // Returns owner_id if this user is an active or pending team member, or null
  public static function get_owner_for_member($user_id) {
    global $wpdb;
    $t      = self::table_teams();
    $result = $wpdb->get_var($wpdb->prepare(
      "SELECT owner_id FROM $t WHERE member_id=%d AND status IN ('pending','active') LIMIT 1",
      (int)$user_id
    ));
    return $result ? (int)$result : null;
  }

  public static function is_team_member($user_id) {
    return self::get_owner_for_member($user_id) !== null;
  }

  // ----------- invite -----------
  public static function invite_member($owner_id, $email) {
    global $wpdb;
    $t        = self::table_teams();
    $owner_id = (int)$owner_id;
    $email    = sanitize_email($email);

    if (!is_email($email)) {
      return ['success' => false, 'msg' => 'Invalid email address.'];
    }

    $limit = self::get_member_limit($owner_id);
    if ($limit <= 0) {
      return ['success' => false, 'msg' => 'Your plan does not support team members. Please upgrade.'];
    }

    // Count pending + active (don't over-invite)
    $total_count = (int)$wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM $t WHERE owner_id=%d AND status IN ('pending','active')",
      $owner_id
    ));

    if ($total_count >= $limit) {
      return ['success' => false, 'msg' => "You've reached your team limit of $limit members."];
    }

    $existing = $wpdb->get_row($wpdb->prepare(
      "SELECT * FROM $t WHERE owner_id=%d AND invite_email=%s",
      $owner_id, $email
    ), ARRAY_A);

    if ($existing && $existing['status'] === 'active') {
      return ['success' => false, 'msg' => 'This person is already an active team member.'];
    }

    // Find or create WP user for this email
    $wp_user = get_user_by('email', $email);
    if (!$wp_user) {
      $base     = sanitize_user(strstr($email, '@', true), true) ?: 'kpi_user';
      $username = $base;
      $i        = 1;
      while (username_exists($username)) {
        $username = $base . '_' . $i++;
      }
      $password = wp_generate_password(24, true, true);
      $user_id  = wp_create_user($username, $password, $email);
      if (is_wp_error($user_id)) {
        return ['success' => false, 'msg' => 'Could not create account: ' . $user_id->get_error_message()];
      }
      $wp_user = get_user_by('id', $user_id);
    }

    $wp_user->set_role('kpi_team_member');

    $token     = bin2hex(random_bytes(24));
    $member_id = (int)$wp_user->ID;

    // Mark setup done (they use owner's channels, no setup screen needed)
    update_user_meta($member_id, 'kpi_setup_done', 1);

    if ($existing) {
      $wpdb->update($t, [
        'member_id'    => $member_id,
        'invite_token' => $token,
        'status'       => 'pending',
        'invited_at'   => current_time('mysql'),
        'activated_at' => null,
      ], ['id' => (int)$existing['id']], ['%d','%s','%s','%s','%s'], ['%d']);
    } else {
      $wpdb->insert($t, [
        'owner_id'     => $owner_id,
        'member_id'    => $member_id,
        'invite_email' => $email,
        'invite_token' => $token,
        'status'       => 'pending',
        'invited_at'   => current_time('mysql'),
      ], ['%d','%d','%s','%s','%s','%s']);
    }

    // Send invite email (password reset link)
    $reset_key = get_password_reset_key($wp_user);
    if (!is_wp_error($reset_key)) {
      $owner      = get_user_by('id', $owner_id);
      $owner_name = $owner ? $owner->display_name : 'Your team admin';
      $reset_url  = network_site_url(
        "wp-login.php?action=rp&key=$reset_key&login=" . rawurlencode($wp_user->user_login),
        'login'
      );

      $subject = "You've been invited to join {$owner_name}'s KPI team";
      $message  = "Hi {$wp_user->display_name},\n\n";
      $message .= "{$owner_name} has invited you to join their KPI Dashboard team.\n\n";
      $message .= "Click the link below to set your password and get started:\n";
      $message .= $reset_url . "\n\n";
      $message .= "This link will expire in 24 hours.\n\n";
      $message .= "Best regards,\nThe KPI System";

      wp_mail($email, $subject, $message);
    }

    $record = $wpdb->get_row($wpdb->prepare(
      "SELECT * FROM $t WHERE owner_id=%d AND invite_email=%s",
      $owner_id, $email
    ), ARRAY_A);

    return ['success' => true, 'msg' => "Invitation sent to $email.", 'member' => $record];
  }

  // ----------- activation on login -----------
  public static function maybe_activate_on_login($user_id) {
    global $wpdb;
    $t    = self::table_teams();
    $user = get_user_by('id', (int)$user_id);
    if (!$user) return;

    $email = $user->user_email;

    $rows = $wpdb->get_results($wpdb->prepare(
      "SELECT * FROM $t WHERE invite_email=%s AND status='pending'",
      $email
    ), ARRAY_A);

    foreach ($rows as $row) {
      $wpdb->update($t, [
        'member_id'    => (int)$user_id,
        'status'       => 'active',
        'activated_at' => current_time('mysql'),
      ], ['id' => (int)$row['id']], ['%d','%s','%s'], ['%d']);

      $user->set_role('kpi_team_member');
      update_user_meta((int)$user_id, 'kpi_setup_done', 1);
    }
  }

  // ----------- disable / enable / remove -----------
  public static function disable_member($owner_id, $member_id) {
    global $wpdb;
    $t = self::table_teams();
    return $wpdb->update($t,
      ['status' => 'disabled'],
      ['owner_id' => (int)$owner_id, 'member_id' => (int)$member_id, 'status' => 'active'],
      ['%s'], ['%d','%d','%s']
    );
  }

  public static function enable_member($owner_id, $member_id) {
    global $wpdb;
    $t = self::table_teams();

    $limit        = self::get_member_limit($owner_id);
    $active_count = self::get_active_member_count($owner_id);
    if ($active_count >= $limit) return false;

    return $wpdb->update($t,
      ['status' => 'active'],
      ['owner_id' => (int)$owner_id, 'member_id' => (int)$member_id, 'status' => 'disabled'],
      ['%s'], ['%d','%d','%s']
    );
  }

  public static function remove_member($owner_id, $member_id) {
    global $wpdb;
    $t         = self::table_teams();
    $owner_id  = (int)$owner_id;
    $member_id = (int)$member_id;

    $deleted = $wpdb->delete($t, ['owner_id' => $owner_id, 'member_id' => $member_id], ['%d','%d']);

    if ($deleted) {
      // Remove kpi_team_member role if no longer in any team
      $still_member = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $t WHERE member_id=%d AND status IN ('pending','active')",
        $member_id
      ));
      if (!$still_member) {
        $user = get_user_by('id', $member_id);
        if ($user && in_array('kpi_team_member', (array)$user->roles)) {
          $user->set_role('subscriber');
        }
      }
    }

    return $deleted;
  }

  // ----------- AJAX handlers -----------
  public static function ajax_invite() {
    if (!is_user_logged_in()) wp_send_json_error(['msg' => 'Forbidden'], 403);
    check_ajax_referer('kpi_team_action', 'nonce');

    $owner_id = get_current_user_id();
    $email    = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
    $result   = self::invite_member($owner_id, $email);

    if ($result['success']) {
      $member = $result['member'];
      $mu     = $member && $member['member_id'] ? get_user_by('id', $member['member_id']) : null;
      $result['member_html'] = self::render_member_row($member, $mu);
      wp_send_json_success($result);
    } else {
      wp_send_json_error($result);
    }
  }

  public static function ajax_disable() {
    if (!is_user_logged_in()) wp_send_json_error(['msg' => 'Forbidden'], 403);
    check_ajax_referer('kpi_team_action', 'nonce');

    $owner_id  = get_current_user_id();
    $member_id = isset($_POST['member_id']) ? (int)$_POST['member_id'] : 0;
    if (!$member_id) wp_send_json_error(['msg' => 'Invalid member']);

    self::disable_member($owner_id, $member_id);
    wp_send_json_success(['msg' => 'Member disabled.']);
  }

  public static function ajax_enable() {
    if (!is_user_logged_in()) wp_send_json_error(['msg' => 'Forbidden'], 403);
    check_ajax_referer('kpi_team_action', 'nonce');

    $owner_id  = get_current_user_id();
    $member_id = isset($_POST['member_id']) ? (int)$_POST['member_id'] : 0;
    if (!$member_id) wp_send_json_error(['msg' => 'Invalid member']);

    $result = self::enable_member($owner_id, $member_id);
    if ($result === false) {
      wp_send_json_error(['msg' => "You've reached your team member limit."]);
    }
    wp_send_json_success(['msg' => 'Member enabled.']);
  }

  public static function ajax_remove() {
    if (!is_user_logged_in()) wp_send_json_error(['msg' => 'Forbidden'], 403);
    check_ajax_referer('kpi_team_action', 'nonce');

    $owner_id  = get_current_user_id();
    $member_id = isset($_POST['member_id']) ? (int)$_POST['member_id'] : 0;
    if (!$member_id) wp_send_json_error(['msg' => 'Invalid member']);

    self::remove_member($owner_id, $member_id);
    wp_send_json_success(['msg' => 'Member removed.']);
  }

  public static function ajax_save_name() {
    if (!is_user_logged_in()) wp_send_json_error(['msg' => 'Forbidden'], 403);
    check_ajax_referer('kpi_team_action', 'nonce');

    $user_id = get_current_user_id();
    $name    = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';

    if ($name === '') wp_send_json_error(['msg' => 'Name cannot be empty.']);

    $result = wp_update_user(['ID' => $user_id, 'display_name' => $name]);
    if (is_wp_error($result)) {
      wp_send_json_error(['msg' => $result->get_error_message()]);
    }

    wp_send_json_success(['msg' => 'Name updated.', 'name' => $name]);
  }

  public static function register_ajax() {
    add_action('wp_ajax_kpi_team_invite',     [__CLASS__, 'ajax_invite']);
    add_action('wp_ajax_kpi_team_disable',    [__CLASS__, 'ajax_disable']);
    add_action('wp_ajax_kpi_team_enable',     [__CLASS__, 'ajax_enable']);
    add_action('wp_ajax_kpi_team_remove',     [__CLASS__, 'ajax_remove']);
    add_action('wp_ajax_kpi_team_save_name',  [__CLASS__, 'ajax_save_name']);
  }

  // ----------- UI helper -----------
  public static function render_member_row($m, $wp_user = null) {
    if (!$wp_user && !empty($m['member_id'])) {
      $wp_user = get_user_by('id', $m['member_id']);
    }
    $display_name  = $wp_user ? $wp_user->display_name : '';
    $status_labels = ['pending' => 'Pending', 'active' => 'Active', 'disabled' => 'Disabled'];
    $status_label  = $status_labels[$m['status']] ?? $m['status'];
    $member_id     = (int)$m['member_id'];

    ob_start(); ?>
    <div class="kpi-team-row" data-member-id="<?php echo $member_id; ?>">
      <span class="kpi-team-email">
        <?php echo esc_html($m['invite_email']); ?>
        <?php if ($display_name): ?><small><?php echo esc_html($display_name); ?></small><?php endif; ?>
      </span>
      <span class="kpi-team-status kpi-team-status--<?php echo esc_attr($m['status']); ?>">
        <?php echo esc_html($status_label); ?>
      </span>
      <span class="kpi-team-date"><?php echo esc_html(date('d M Y', strtotime($m['invited_at']))); ?></span>
      <span class="kpi-team-actions">
        <?php if ($m['status'] === 'active'): ?>
          <button type="button" class="kpi-team-action kpi-team-action--disable" data-member-id="<?php echo $member_id; ?>">Disable</button>
        <?php elseif ($m['status'] === 'disabled'): ?>
          <button type="button" class="kpi-team-action kpi-team-action--enable" data-member-id="<?php echo $member_id; ?>">Enable</button>
        <?php else: ?>
          <span class="kpi-team-pending-label">Awaiting login</span>
        <?php endif; ?>
        <button type="button" class="kpi-team-action kpi-team-action--remove" data-member-id="<?php echo $member_id; ?>">Remove</button>
      </span>
    </div>
    <?php
    return ob_get_clean();
  }
}
