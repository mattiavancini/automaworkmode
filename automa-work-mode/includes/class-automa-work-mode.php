<?php
/**
 * Core runtime for Automa Work Mode.
 */

if (! defined('ABSPATH')) {
	exit;
}

class Automa_Work_Mode {
	const SETTINGS_OPTION = 'automa_work_mode_settings';
	const STATE_OPTION = 'automa_work_mode_state';
	const LOG_OPTION = 'automa_work_mode_log';
	const META_OPTION = 'automa_work_mode_meta';
	const RESTORE_HOOK = 'automa_work_mode_restore_event';
	const CAPABILITY = 'automa_work_mode';
	const MAX_LOG_ENTRIES = 100;
	const DEFAULT_MINUTES = 120;
	const AUTO_LOGIN_MINUTES = 10;
	const INTERNAL_LINKS_PLUGIN = 'internal-links/wp-internal-linkjuicer.php';
	const INTERNAL_LINKS_LEGACY_PLUGIN = 'internal-links/internal-links.php';

	/**
	 * Admin handler.
	 *
	 * @var Automa_Work_Mode_Admin
	 */
	private $admin;

	/**
	 * Bootstrap hooks.
	 */
	public function boot(): void {
		$this->admin = new Automa_Work_Mode_Admin($this);
		$this->maybe_upgrade_settings();

		add_action('admin_init', array($this, 'maybe_restore_expired_mode'));
		add_action(self::RESTORE_HOOK, array($this, 'restore_work_mode_from_cron'));
		add_action('wp_login', array($this, 'maybe_activate_on_login'), 10, 2);
		add_action('wp_logout', array($this, 'maybe_restore_on_logout'));
		add_action('admin_menu', array($this->admin, 'register_menu'));
		add_action('wp_dashboard_setup', array($this->admin, 'register_dashboard_widget'));
		add_action('admin_post_automa_work_mode_activate', array($this->admin, 'handle_activate_request'));
		add_action('admin_post_automa_work_mode_restore', array($this->admin, 'handle_restore_request'));
		add_action('admin_notices', array($this->admin, 'render_countdown_notice'));
		add_action('admin_enqueue_scripts', array($this->admin, 'enqueue_assets'));
	}

	/**
	 * Activation bootstrap.
	 */
	public static function activate(): void {
		$self = new self();

		if (get_option(self::SETTINGS_OPTION, null) === null) {
			add_option(self::SETTINGS_OPTION, $self->get_default_settings());
		}

		if (get_option(self::LOG_OPTION, null) === null) {
			add_option(self::LOG_OPTION, array());
		}

		if (get_option(self::META_OPTION, null) === null) {
			add_option(self::META_OPTION, array());
		}

		$self->grant_capabilities();
		$self->remove_legacy_capabilities();
		$self->maybe_upgrade_settings();
	}

	/**
	 * Restore plugins before shutting down if needed.
	 */
	public static function deactivate(): void {
		$self = new self();
		$self->clear_restore_schedule();
		$self->restore_work_mode('plugin_deactivation');
	}

	/**
	 * Add Work Mode capability to supported roles.
	 */
	public function grant_capabilities(): void {
		$role = get_role('administrator');

		if ($role && ! $role->has_cap(self::CAPABILITY)) {
			$role->add_cap(self::CAPABILITY);
		}
	}

	/**
	 * Default plugin settings.
	 */
	public function get_default_settings(): array {
		return array(
			'default_minutes' => self::DEFAULT_MINUTES,
			'auto_activate_on_login' => true,
			'allowed_roles' => array('administrator'),
			'selected_plugins' => $this->get_base_selected_plugins(),
		);
	}

	/**
	 * Get persisted settings merged with defaults.
	 */
	public function get_settings(): array {
		$settings = get_option(self::SETTINGS_OPTION, array());

		return wp_parse_args($settings, $this->get_default_settings());
	}

	/**
	 * Persist minimal settings.
	 */
	public function update_settings(array $raw_settings): array {
		$sanitized = array(
			'default_minutes' => $this->sanitize_minutes($raw_settings['default_minutes'] ?? 120),
			'auto_activate_on_login' => ! empty($raw_settings['auto_activate_on_login']),
			'allowed_roles' => $this->sanitize_roles($raw_settings['allowed_roles'] ?? array()),
			'selected_plugins' => $this->sanitize_selected_plugins($raw_settings['selected_plugins'] ?? array()),
		);

		update_option(self::SETTINGS_OPTION, $sanitized, false);

		return $sanitized;
	}

	/**
	 * Return the active mode state or an empty array.
	 */
	public function get_state(): array {
		$state = get_option(self::STATE_OPTION, array());

		return is_array($state) ? $state : array();
	}

	/**
	 * Check whether a work mode session is currently active.
	 */
	public function is_active(): bool {
		$state = $this->get_state();

		return ! empty($state['active']) && ! empty($state['end_timestamp']);
	}

	/**
	 * Get the current plugin inventory with classification.
	 */
	public function get_plugin_inventory(): array {
		$this->ensure_plugin_functions_loaded();

		$installed_plugins = get_plugins();
		$active_plugins = get_option('active_plugins', array());
		$rows = array();

		foreach ($installed_plugins as $plugin_file => $plugin_data) {
			$is_active = in_array($plugin_file, $active_plugins, true);
			$rows[] = array(
				'name' => $plugin_data['Name'] ?: $plugin_file,
				'plugin_file' => $plugin_file,
				'active' => $is_active,
				'status' => $is_active ? 'active' : 'inactive',
				'classification' => $this->classify_plugin($plugin_file, $plugin_data),
			);
		}

		usort($rows, static function ($left, $right) {
			return strcasecmp($left['name'], $right['name']);
		});

		return $rows;
	}

	/**
	 * Start a new work mode session.
	 *
	 * @return array{success:bool,message:string,disabled_plugins:array}
	 */
	public function activate_work_mode(int $minutes, int $user_id = 0, ?array $selected_plugins_override = null): array {
		$this->ensure_plugin_functions_loaded();

		if ($this->is_active()) {
			return array(
				'success' => false,
				'message' => __('Work Mode is already active.', 'automa-work-mode'),
				'disabled_plugins' => array(),
			);
		}

		$minutes = $this->sanitize_minutes($minutes);
		$inventory = $this->get_plugin_inventory();
		$active_plugins = get_option('active_plugins', array());
		$inactive_plugins = array_values(array_diff(array_keys(get_plugins()), $active_plugins));
		$selected_plugins = is_array($selected_plugins_override) ? $this->sanitize_selected_plugins($selected_plugins_override) : $this->get_selected_plugins();
		$disabled_plugins = array_values(array_intersect($selected_plugins, $active_plugins));
		$start_timestamp = time();
		$end_timestamp = $start_timestamp + ($minutes * MINUTE_IN_SECONDS);

		if (empty($disabled_plugins)) {
			return array(
				'success' => false,
				'message' => __('No selected active plugins were found to disable.', 'automa-work-mode'),
				'disabled_plugins' => array(),
			);
		}

		$state = array(
			'active' => true,
			'start_timestamp' => $start_timestamp,
			'end_timestamp' => $end_timestamp,
			'minutes' => $minutes,
			'installed_plugins' => array_values(array_map(static function ($row) {
				return $row['plugin_file'];
			}, $inventory)),
			'active_plugins' => $active_plugins,
			'inactive_plugins' => array_values($inactive_plugins),
			'selected_plugins' => $selected_plugins,
			'disabled_plugins' => $disabled_plugins,
			'activated_by' => $user_id,
		);

		update_option(self::STATE_OPTION, $state, false);
		deactivate_plugins($disabled_plugins, true, false);
		$this->schedule_restore($end_timestamp);
		$this->log_event('activated', array(
			'user_id' => $user_id,
			'minutes' => $minutes,
			'disabled_plugins' => $disabled_plugins,
			'selected_plugins' => $selected_plugins,
		));

		return array(
			'success' => true,
			'message' => __('Work Mode activated successfully.', 'automa-work-mode'),
			'disabled_plugins' => $disabled_plugins,
		);
	}

	/**
	 * Restore the plugin list to its initial state.
	 */
	public function restore_work_mode(string $source = 'manual'): array {
		$state = $this->get_state();

		if (empty($state['active'])) {
			return array(
				'success' => false,
				'message' => __('No active Work Mode session was found.', 'automa-work-mode'),
				'restored_plugins' => array(),
			);
		}

		$this->ensure_plugin_functions_loaded();

		$current_active_plugins = get_option('active_plugins', array());
		$plugins_to_restore = array_values(array_intersect($state['disabled_plugins'] ?? array(), $state['active_plugins'] ?? array()));
		$restored_plugins = array();
		$failed_plugins = array();
		$missing_plugins = array();

		foreach ($plugins_to_restore as $plugin_file) {
			if (! file_exists(WP_PLUGIN_DIR . '/' . $plugin_file)) {
				$missing_plugins[] = $plugin_file;
				continue;
			}

			if (in_array($plugin_file, $current_active_plugins, true)) {
				$restored_plugins[] = $plugin_file;
				continue;
			}

			$result = activate_plugin($plugin_file, '', false, true);

			if (! is_wp_error($result)) {
				$restored_plugins[] = $plugin_file;
				continue;
			}

			$failed_plugins[] = array(
				'plugin_file' => $plugin_file,
				'message' => $result->get_error_message(),
			);
		}

		delete_option(self::STATE_OPTION);
		$this->clear_restore_schedule();
		$this->flush_cache_layers();
		$log_context = array(
			'source' => $source,
			'restored_plugins' => $restored_plugins,
			'missing_plugins' => $missing_plugins,
			'failed_plugins' => $failed_plugins,
		);

		if (! empty($missing_plugins) || ! empty($failed_plugins)) {
			$this->log_event('restore_incomplete', $log_context);

			return array(
				'success' => false,
				'message' => __('Work Mode ended, but some plugins could not be restored automatically. Review the log and reactivate them manually if needed.', 'automa-work-mode'),
				'restored_plugins' => $restored_plugins,
				'missing_plugins' => $missing_plugins,
				'failed_plugins' => $failed_plugins,
			);
		}

		$this->log_event('restored', $log_context);

		return array(
			'success' => true,
			'message' => __('Work Mode restored successfully.', 'automa-work-mode'),
			'restored_plugins' => $restored_plugins,
		);
	}

	/**
	 * Restore callback executed by WP-Cron.
	 */
	public function restore_work_mode_from_cron(): void {
		$this->restore_work_mode('cron');
	}

	/**
	 * Restore active Work Mode when the current backend session logs out.
	 */
	public function maybe_restore_on_logout(): void {
		if (! $this->is_active()) {
			return;
		}

		$this->restore_work_mode('logout');
	}

	/**
	 * Safety net executed on every admin request.
	 */
	public function maybe_restore_expired_mode(): void {
		if (! is_admin()) {
			return;
		}

		$state = $this->get_state();

		if (empty($state['active']) || empty($state['end_timestamp'])) {
			return;
		}

		if ((int) $state['end_timestamp'] <= time()) {
			$this->restore_work_mode('admin_fallback');
		}
	}

	/**
	 * Human-readable end timestamp for UI.
	 */
	public function get_end_time_label(): string {
		$state = $this->get_state();

		if (empty($state['end_timestamp'])) {
			return '';
		}

		return wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) $state['end_timestamp']);
	}

	/**
	 * Return selected plugin list from settings.
	 */
	public function get_selected_plugins(): array {
		$settings = $this->get_settings();

		return $this->sanitize_selected_plugins($settings['selected_plugins'] ?? array());
	}

	/**
	 * Return the currently allowed roles for automatic login activation.
	 */
	public function get_allowed_roles(): array {
		$settings = $this->get_settings();

		return $this->sanitize_roles($settings['allowed_roles'] ?? array());
	}

	/**
	 * Return editable roles for the admin settings UI.
	 */
	public function get_available_roles(): array {
		return array(
			array(
				'key' => 'administrator',
				'label' => translate_user_role(__('Administrator')),
			),
		);
	}

	/**
	 * Return a compact remaining time label.
	 */
	public function get_remaining_time_label(): string {
		$state = $this->get_state();

		if (empty($state['end_timestamp'])) {
			return '0 min';
		}

		$remaining = max(0, (int) $state['end_timestamp'] - time());
		$minutes = (int) ceil($remaining / MINUTE_IN_SECONDS);

		if ($remaining < HOUR_IN_SECONDS) {
			return max(0, $minutes) . ' min';
		}

		$hours = (int) floor($remaining / HOUR_IN_SECONDS);
		$remaining_minutes = (int) ceil(($remaining % HOUR_IN_SECONDS) / MINUTE_IN_SECONDS);

		if ($remaining_minutes === 60) {
			$hours++;
			$remaining_minutes = 0;
		}

		return $hours . 'h ' . $remaining_minutes . 'm';
	}

	/**
	 * Return plugins that the MVP must never disable.
	 */
	public function get_protected_plugins(): array {
		return array(
			plugin_basename(AUTOMA_WORK_MODE_FILE),
			'breeze/breeze.php',
			'elementor/elementor.php',
			'elementor-pro/elementor-pro.php',
			'classic-editor/classic-editor.php',
			'classic-widgets/classic-widgets.php',
			'redis-cache/redis-cache.php',
			'wp-mail-smtp/wp_mail_smtp.php',
			'wordfence/wordfence.php',
			'sucuri-scanner/sucuri.php',
			'ithemes-security-pro/ithemes-security-pro.php',
			'better-wp-security/better-wp-security.php',
		);
	}

	/**
	 * Fetch recent log entries.
	 */
	public function get_logs(): array {
		$logs = get_option(self::LOG_OPTION, array());

		return is_array($logs) ? $logs : array();
	}

	/**
	 * Maybe activate Work Mode automatically after a backend login.
	 */
	public function maybe_activate_on_login(string $user_login, $user): void {
		if (! is_a($user, 'WP_User')) {
			return;
		}

		$settings = $this->get_settings();

		if (empty($settings['auto_activate_on_login'])) {
			return;
		}

		if (! $this->is_backend_login_request()) {
			$this->log_event('auto_activation_skipped', array(
				'user_id' => (int) $user->ID,
				'username' => $user_login,
				'reason' => 'non_backend_login',
			));
			return;
		}

		if ($this->is_active()) {
			$this->log_event('auto_activation_skipped', array(
				'user_id' => (int) $user->ID,
				'username' => $user_login,
				'reason' => 'already_active',
			));
			return;
		}

		$selected_plugins = $this->get_auto_login_selected_plugins();

		if (empty($selected_plugins)) {
			$this->log_event('auto_activation_skipped', array(
				'user_id' => (int) $user->ID,
				'username' => $user_login,
				'reason' => 'no_selected_plugins',
			));
			return;
		}

		$allowed_roles = $this->get_allowed_roles();
		$user_roles = is_array($user->roles) ? $user->roles : array();

		if (empty(array_intersect($allowed_roles, $user_roles))) {
			$this->log_event('auto_activation_skipped', array(
				'user_id' => (int) $user->ID,
				'username' => $user_login,
				'reason' => 'role_not_allowed',
				'user_roles' => $user_roles,
				'allowed_roles' => $allowed_roles,
			));
			return;
		}

		$result = $this->activate_work_mode(self::AUTO_LOGIN_MINUTES, (int) $user->ID, $selected_plugins);

		if (! empty($result['success'])) {
			$this->log_event('auto_activated_on_login', array(
				'user_id' => (int) $user->ID,
				'username' => $user_login,
				'minutes' => self::AUTO_LOGIN_MINUTES,
				'disabled_plugins' => $result['disabled_plugins'] ?? array(),
			));
			return;
		}

		$this->log_event('auto_activation_skipped', array(
			'user_id' => (int) $user->ID,
			'username' => $user_login,
			'reason' => 'activation_failed',
			'message' => $result['message'] ?? '',
		));
	}

	/**
	 * Schedule one restore event for the current session.
	 */
	private function schedule_restore(int $timestamp): void {
		$this->clear_restore_schedule();
		wp_schedule_single_event($timestamp, self::RESTORE_HOOK);
	}

	/**
	 * Remove scheduled restore hooks.
	 */
	private function clear_restore_schedule(): void {
		$next = wp_next_scheduled(self::RESTORE_HOOK);

		if ($next) {
			wp_unschedule_event($next, self::RESTORE_HOOK);
		}
	}

	/**
	 * Remove legacy capability grants from roles that should no longer operate the plugin.
	 */
	private function remove_legacy_capabilities(): void {
		foreach (array('editor') as $role_name) {
			$role = get_role($role_name);

			if ($role && $role->has_cap(self::CAPABILITY)) {
				$role->remove_cap(self::CAPABILITY);
			}
		}
	}

	/**
	 * Detect whether the login flow is redirecting to the WordPress backend.
	 */
	private function is_backend_login_request(): bool {
		if (is_admin()) {
			return true;
		}

		$redirect_candidates = array(
			wp_unslash($_REQUEST['redirect_to'] ?? ''),
			wp_unslash($_REQUEST['requested_redirect_to'] ?? ''),
		);
		$admin_base = admin_url();

		foreach ($redirect_candidates as $candidate) {
			$validated = wp_validate_redirect($candidate, '');

			if ($validated !== '' && strpos($validated, $admin_base) === 0) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Determine which plugins are candidates for temporary shutdown.
	 */
	private function classify_plugin(string $plugin_file, array $plugin_data): string {
		$plugin_file = strtolower($plugin_file);
		$plugin_name = strtolower($plugin_data['Name'] ?? '');
		$haystack = $plugin_file . ' ' . $plugin_name;

		$recommended_heavy = array(
			'broken-link-checker/broken-link-checker.php',
			'wp-compress-image-optimizer/wp-compress.php',
			'wp-compress/wp-compress.php',
		);

		if (in_array($plugin_file, array_map('strtolower', $this->get_protected_plugins()), true)) {
			return 'protected';
		}

		foreach (array('redis', 'elementor', 'classic editor', 'classic widgets', 'smtp', 'wordfence', 'sucuri', 'security', 'login', 'template', 'permalink') as $keyword) {
			if (strpos($haystack, $keyword) !== false) {
				return 'protected';
			}
		}

		if (in_array($plugin_file, $recommended_heavy, true)) {
			return 'recommended-heavy';
		}

		foreach (array('broken-link-checker', 'wp-compress') as $keyword) {
			if (strpos($haystack, $keyword) !== false) {
				return 'recommended-heavy';
			}
		}

		if (empty($plugin_file)) {
			return 'unknown';
		}

		return 'neutral';
	}

	/**
	 * Flush page-cache layers without touching Breeze or object cache backends.
	 */
	private function flush_cache_layers(): void {
		$flushed = array();

		if (function_exists('rocket_clean_domain')) {
			rocket_clean_domain();
			$flushed[] = 'wp-rocket';
		}

		if (class_exists('LiteSpeed_Cache_API') && method_exists('LiteSpeed_Cache_API', 'purge_all')) {
			LiteSpeed_Cache_API::purge_all();
			$flushed[] = 'litespeed';
		}

		if (function_exists('sg_cachepress_purge_cache')) {
			sg_cachepress_purge_cache();
			$flushed[] = 'siteground-optimizer';
		}

		do_action('automa_work_mode_flush_cache');

		$this->log_event('cache_flush', array(
			'drivers' => $flushed,
		));
	}

	/**
	 * Append a log entry and keep the history bounded.
	 */
	private function log_event(string $event, array $context = array()): void {
		$logs = $this->get_logs();
		$logs[] = array(
			'timestamp' => time(),
			'event' => $event,
			'context' => $context,
		);

		if (count($logs) > self::MAX_LOG_ENTRIES) {
			$logs = array_slice($logs, -1 * self::MAX_LOG_ENTRIES);
		}

		update_option(self::LOG_OPTION, $logs, false);
	}

	/**
	 * Sanitize duration in minutes.
	 */
	private function sanitize_minutes($minutes): int {
		$minutes = absint($minutes);

		if ($minutes < 1) {
			return self::DEFAULT_MINUTES;
		}

		if ($minutes > 720) {
			return 720;
		}

		return $minutes;
	}

	/**
	 * Sanitize selected plugins to exclude protected entries.
	 */
	private function sanitize_selected_plugins($selected_plugins): array {
		if (! is_array($selected_plugins)) {
			return array();
		}

		$selected_plugins = array_map('sanitize_text_field', $selected_plugins);
		$selected_plugins = array_filter(array_map('trim', $selected_plugins));
		$selected_plugins = $this->normalize_selected_plugins($selected_plugins);

		return array_values(array_unique(array_diff($selected_plugins, $this->get_protected_plugins())));
	}

	/**
	 * Return plugin selections that should exist in the default base list.
	 *
	 * @return array<int,string>
	 */
	private function get_base_selected_plugins(): array {
		$selected_plugins = array(
			'broken-link-checker/broken-link-checker.php',
			'wp-compress-image-optimizer/wp-compress.php',
			'wp-compress/wp-compress.php',
		);

		foreach ($this->get_internal_links_plugin_files() as $plugin_file) {
			$selected_plugins[] = $plugin_file;
		}

		return array_values(array_unique($selected_plugins));
	}

	/**
	 * Merge manual selections with login-specific defaults.
	 */
	private function get_auto_login_selected_plugins(): array {
		$selected_plugins = $this->get_selected_plugins();

		foreach ($this->get_auto_login_default_plugins() as $plugin_file) {
			$selected_plugins[] = $plugin_file;
		}

		return $this->sanitize_selected_plugins($selected_plugins);
	}

	/**
	 * Resolve plugins that should be disabled by default during auto-login activation.
	 *
	 * Only the base "internal-links" plugin is included here, never Pro variants.
	 *
	 * @return array<int,string>
	 */
	private function get_auto_login_default_plugins(): array {
		return $this->get_internal_links_plugin_files();
	}

	/**
	 * Upgrade persisted settings once when base defaults change.
	 */
	private function maybe_upgrade_settings(): void {
		$meta = get_option(self::META_OPTION, array());
		$meta = is_array($meta) ? $meta : array();
		$stored_settings = get_option(self::SETTINGS_OPTION, null);

		if (! is_array($stored_settings)) {
			$meta['base_defaults_version'] = AUTOMA_WORK_MODE_VERSION;
			update_option(self::META_OPTION, $meta, false);
			return;
		}

		if (($meta['base_defaults_version'] ?? '') === AUTOMA_WORK_MODE_VERSION) {
			return;
		}

		$selected_plugins = $this->sanitize_selected_plugins($stored_settings['selected_plugins'] ?? array());
		$selected_plugins = array_values(array_unique(array_merge($selected_plugins, $this->get_base_selected_plugins())));

		$stored_settings['selected_plugins'] = $this->sanitize_selected_plugins($selected_plugins);
		update_option(self::SETTINGS_OPTION, $stored_settings, false);

		$meta['base_defaults_version'] = AUTOMA_WORK_MODE_VERSION;
		update_option(self::META_OPTION, $meta, false);
	}

	/**
	 * Normalize plugin paths that may vary between releases or installations.
	 *
	 * @param array<int,string> $selected_plugins
	 * @return array<int,string>
	 */
	private function normalize_selected_plugins(array $selected_plugins): array {
		$normalized = array();
		$replace_internal_links = false;

		foreach ($selected_plugins as $plugin_file) {
			$plugin_file = strtolower($plugin_file);

			if ($plugin_file === self::INTERNAL_LINKS_LEGACY_PLUGIN || $plugin_file === self::INTERNAL_LINKS_PLUGIN) {
				$replace_internal_links = true;
				continue;
			}

			$normalized[] = $plugin_file;
		}

		if ($replace_internal_links) {
			foreach ($this->get_internal_links_plugin_files() as $plugin_file) {
				$normalized[] = $plugin_file;
			}
		}

		return $normalized;
	}

	/**
	 * Resolve the installed base Internal Link Juicer plugin file.
	 *
	 * @return array<int,string>
	 */
	private function get_internal_links_plugin_files(): array {
		$this->ensure_plugin_functions_loaded();
		$matches = array();

		foreach (array_keys(get_plugins()) as $plugin_file) {
			if (strtolower(dirname($plugin_file)) === 'internal-links') {
				$matches[] = $plugin_file;
			}
		}

		if (! empty($matches)) {
			return array_values(array_unique($matches));
		}

		return array(self::INTERNAL_LINKS_PLUGIN);
	}

	/**
	 * Sanitize allowed role slugs against editable WordPress roles.
	 */
	private function sanitize_roles($roles): array {
		if (! is_array($roles)) {
			$roles = array();
		}

		$roles = array_map('sanitize_key', $roles);
		$roles = array_filter(array_map('trim', $roles));
		$available_roles = array_keys(wp_roles()->roles);
		$roles = array_values(array_intersect($roles, $available_roles));

		if (empty($roles)) {
			return array('administrator');
		}

		return array_values(array_intersect(array_unique($roles), array('administrator')));
	}

	/**
	 * Load wp-admin plugin helpers when the request path does not include them.
	 */
	private function ensure_plugin_functions_loaded(): void {
		if (! function_exists('deactivate_plugins') || ! function_exists('activate_plugin') || ! function_exists('get_plugins')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
	}
}
