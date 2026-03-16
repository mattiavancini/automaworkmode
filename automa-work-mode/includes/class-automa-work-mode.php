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
	const RESTORE_HOOK = 'automa_work_mode_restore_event';
	const CAPABILITY = 'automa_work_mode';
	const MAX_LOG_ENTRIES = 100;

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

		add_action('admin_init', array($this, 'maybe_restore_expired_mode'));
		add_action(self::RESTORE_HOOK, array($this, 'restore_work_mode_from_cron'));
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

		if (! get_option(self::SETTINGS_OPTION)) {
			add_option(self::SETTINGS_OPTION, $self->get_default_settings());
		}

		if (! get_option(self::LOG_OPTION)) {
			add_option(self::LOG_OPTION, array());
		}

		$self->grant_capabilities();
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
		foreach (array('administrator', 'editor') as $role_name) {
			$role = get_role($role_name);

			if ($role && ! $role->has_cap(self::CAPABILITY)) {
				$role->add_cap(self::CAPABILITY);
			}
		}
	}

	/**
	 * Default plugin settings.
	 */
	public function get_default_settings(): array {
		return array(
			'default_minutes' => 120,
			'selected_plugins' => array(
				'broken-link-checker/broken-link-checker.php',
				'wp-compress-image-optimizer/wp-compress.php',
				'wp-compress/wp-compress.php',
			),
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
	public function activate_work_mode(int $minutes, int $user_id = 0): array {
		$this->ensure_plugin_functions_loaded();

		if ($this->is_active()) {
			return array(
				'success' => false,
				'message' => __('Work Mode is already active.', 'automa-work-mode'),
				'disabled_plugins' => array(),
			);
		}

		$minutes = $this->sanitize_minutes($minutes);
		$current_settings = $this->get_settings();
		$this->update_settings(array(
			'default_minutes' => $minutes,
			'selected_plugins' => $current_settings['selected_plugins'] ?? array(),
		));

		$inventory = $this->get_plugin_inventory();
		$active_plugins = get_option('active_plugins', array());
		$inactive_plugins = array_values(array_diff(array_keys(get_plugins()), $active_plugins));
		$selected_plugins = $this->get_selected_plugins();
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
		$plugins_to_restore = array_filter($plugins_to_restore, static function ($plugin_file) {
			return file_exists(WP_PLUGIN_DIR . '/' . $plugin_file);
		});

		$restored_plugins = array();

		foreach ($plugins_to_restore as $plugin_file) {
			if (in_array($plugin_file, $current_active_plugins, true)) {
				continue;
			}

			$result = activate_plugin($plugin_file, '', false, true);

			if (! is_wp_error($result)) {
				$restored_plugins[] = $plugin_file;
			}
		}

		delete_option(self::STATE_OPTION);
		$this->clear_restore_schedule();
		$this->flush_cache_layers();
		$this->log_event('restored', array(
			'source' => $source,
			'restored_plugins' => $restored_plugins,
		));

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

		foreach (array('redis', 'object cache', 'elementor', 'classic editor', 'classic widgets', 'smtp', 'wordfence', 'sucuri', 'security', 'login', 'template', 'permalink') as $keyword) {
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
			return 120;
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

		return array_values(array_unique(array_diff($selected_plugins, $this->get_protected_plugins())));
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
