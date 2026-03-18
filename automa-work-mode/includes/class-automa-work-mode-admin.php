<?php
/**
 * Admin UI and request handling.
 */

if (! defined('ABSPATH')) {
	exit;
}

class Automa_Work_Mode_Admin {
	/**
	 * Core plugin runtime.
	 *
	 * @var Automa_Work_Mode
	 */
	private $plugin;

	public function __construct(Automa_Work_Mode $plugin) {
		$this->plugin = $plugin;
	}

	/**
	 * Register the admin screen under Tools.
	 */
	public function register_menu(): void {
		add_management_page(
			__('Automa Work Mode', 'automa-work-mode'),
			__('Automa Work Mode', 'automa-work-mode'),
			Automa_Work_Mode::CAPABILITY,
			'automa-work-mode',
			array($this, 'render_page')
		);
	}

	/**
	 * Register a compact controller inside the dashboard.
	 */
	public function register_dashboard_widget(): void {
		if (! current_user_can(Automa_Work_Mode::CAPABILITY)) {
			return;
		}

		wp_add_dashboard_widget(
			'automa_work_mode_dashboard_widget',
			__('Automa Work Mode', 'automa-work-mode'),
			array($this, 'render_dashboard_widget')
		);
	}

	/**
	 * Enqueue styles where the widget or notice may appear.
	 */
	public function enqueue_assets(string $hook_suffix): void {
		if (! current_user_can(Automa_Work_Mode::CAPABILITY) || ! is_admin()) {
			return;
		}

		$should_load = in_array($hook_suffix, array('index.php', 'tools_page_automa-work-mode'), true) || $this->plugin->is_active();

		if (! $should_load) {
			return;
		}

		wp_enqueue_style(
			'automa-work-mode-admin',
			AUTOMA_WORK_MODE_URL . 'assets/css/admin.css',
			array(),
			AUTOMA_WORK_MODE_VERSION
		);

		wp_enqueue_script(
			'automa-work-mode-admin',
			AUTOMA_WORK_MODE_URL . 'assets/js/admin.js',
			array(),
			AUTOMA_WORK_MODE_VERSION,
			true
		);
	}

	/**
	 * Handle activation form submission.
	 */
	public function handle_activate_request(): void {
		$this->assert_permissions();
		check_admin_referer('automa_work_mode_activate');

		$settings = array(
			'default_minutes' => absint($_POST['minutes'] ?? 120),
			'auto_activate_on_login' => ! empty($_POST['auto_activate_on_login']),
			'allowed_roles' => wp_unslash($_POST['allowed_roles'] ?? array()),
			'selected_plugins' => wp_unslash($_POST['selected_plugins'] ?? array()),
		);
		$settings = $this->plugin->update_settings($settings);

		if (! empty($_POST['automa_mode_action']) && wp_unslash($_POST['automa_mode_action']) === 'save') {
			$this->redirect_with_message(__('Selezione plugin salvata.', 'automa-work-mode'), 'success');
		}

		$result = $this->plugin->activate_work_mode((int) $settings['default_minutes'], get_current_user_id());
		$this->redirect_with_message($result['message'], $result['success'] ? 'success' : 'error');
	}

	/**
	 * Handle manual restore form submission.
	 */
	public function handle_restore_request(): void {
		$this->assert_permissions();
		check_admin_referer('automa_work_mode_restore');

		$result = $this->plugin->restore_work_mode('manual');
		$this->redirect_with_message($result['message'], $result['success'] ? 'success' : 'warning');
	}

	/**
	 * Render persistent banner while the mode is active.
	 */
	public function render_countdown_notice(): void {
		if (! current_user_can(Automa_Work_Mode::CAPABILITY) || ! $this->plugin->is_active()) {
			return;
		}

		$state = $this->plugin->get_state();
		$remaining = max(0, (int) $state['end_timestamp'] - time());
		?>
		<div class="notice automa-work-mode-notice">
			<p>
				<strong><?php esc_html_e('Automa Modalita Operativa', 'automa-work-mode'); ?></strong>
			</p>
			<p>
				<?php esc_html_e('Tempo restante alla riattivazione dei plugin:', 'automa-work-mode'); ?>
				<span class="automa-work-mode-countdown automa-work-mode-notice__countdown" data-automa-end-timestamp="<?php echo esc_attr((int) $state['end_timestamp']); ?>"><?php echo esc_html($this->plugin->get_remaining_time_label()); ?></span>
			</p>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="automa-work-mode-inline-form">
				<input type="hidden" name="action" value="automa_work_mode_restore" />
				<?php wp_nonce_field('automa_work_mode_restore'); ?>
				<?php submit_button(__('Riattiva ora', 'automa-work-mode'), 'secondary', 'submit', false); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render dashboard widget content.
	 */
	public function render_dashboard_widget(): void {
		$settings = $this->plugin->get_settings();
		$state = $this->plugin->get_state();

		echo '<p><strong>' . esc_html__('Stato Work Mode:', 'automa-work-mode') . '</strong> ';
		echo ! empty($state['active']) ? esc_html__('ACTIVE', 'automa-work-mode') : esc_html__('INACTIVE', 'automa-work-mode');
		echo '</p>';

		if (! empty($state['active'])) {
			echo '<p>' . esc_html(sprintf(__('Fine prevista: %s', 'automa-work-mode'), $this->plugin->get_end_time_label())) . '</p>';
			echo '<p>' . esc_html__('Tempo residuo:', 'automa-work-mode') . ' <span class="automa-work-mode-countdown" data-automa-end-timestamp="' . esc_attr((int) $state['end_timestamp']) . '">' . esc_html($this->plugin->get_remaining_time_label()) . '</span></p>';
			$this->render_restore_form(__('Riattiva ora', 'automa-work-mode'));
			return;
		}

		$this->render_activate_form($settings, $this->plugin->get_selected_plugins(), __('Avvia modalita operativa', 'automa-work-mode'), false, array(), false, $this->plugin->get_available_roles());
	}

	/**
	 * Render the main plugin page.
	 */
	public function render_page(): void {
		if (! current_user_can(Automa_Work_Mode::CAPABILITY)) {
			wp_die(esc_html__('You do not have permission to access this page.', 'automa-work-mode'));
		}

		$settings = $this->plugin->get_settings();
		$state = $this->plugin->get_state();
		$inventory = $this->plugin->get_plugin_inventory();
		$selected_plugins = $this->plugin->get_selected_plugins();
		$available_roles = $this->plugin->get_available_roles();
		$plugin_map = $this->get_plugin_map($inventory);
		?>
		<div class="wrap automa-work-mode">
			<h1><?php esc_html_e('Automa Work Mode', 'automa-work-mode'); ?></h1>
			<?php $this->render_flash_notice(); ?>

			<div class="automa-work-mode__grid">
				<div class="automa-work-mode__card">
					<h2><?php esc_html_e('Stato Sistema', 'automa-work-mode'); ?></h2>
					<p><strong><?php esc_html_e('Stato Work Mode:', 'automa-work-mode'); ?></strong> <?php echo ! empty($state['active']) ? esc_html__('ACTIVE', 'automa-work-mode') : esc_html__('INACTIVE', 'automa-work-mode'); ?></p>
					<?php if (! empty($state['active'])) : ?>
						<p><strong><?php esc_html_e('Ora di fine prevista:', 'automa-work-mode'); ?></strong> <?php echo esc_html($this->plugin->get_end_time_label()); ?></p>
						<p><strong><?php esc_html_e('Tempo residuo:', 'automa-work-mode'); ?></strong> <span class="automa-work-mode-countdown" data-automa-end-timestamp="<?php echo esc_attr((int) $state['end_timestamp']); ?>"><?php echo esc_html($this->plugin->get_remaining_time_label()); ?></span></p>
						<div class="automa-work-mode__disabled-list-wrap">
							<strong><?php esc_html_e('Plugin spenti dalla Work Mode:', 'automa-work-mode'); ?></strong>
							<?php $this->render_plugin_file_list($state['disabled_plugins'] ?? array(), $plugin_map); ?>
						</div>
						<?php $this->render_restore_form(__('Riattiva ora', 'automa-work-mode')); ?>
					<?php else : ?>
						<?php $this->render_activate_form($settings, $selected_plugins, __('Avvia modalita operativa', 'automa-work-mode'), true, $inventory, true, $available_roles); ?>
					<?php endif; ?>
				</div>

				<div class="automa-work-mode__card">
					<h2><?php esc_html_e('Fotografia Base', 'automa-work-mode'); ?></h2>
					<?php if (! empty($state['active'])) : ?>
						<ul class="automa-work-mode__summary">
							<li><strong><?php esc_html_e('Start:', 'automa-work-mode'); ?></strong> <?php echo esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) $state['start_timestamp'])); ?></li>
							<li><strong><?php esc_html_e('End:', 'automa-work-mode'); ?></strong> <?php echo esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) $state['end_timestamp'])); ?></li>
							<li><strong><?php esc_html_e('Tempo residuo:', 'automa-work-mode'); ?></strong> <span class="automa-work-mode-countdown" data-automa-end-timestamp="<?php echo esc_attr((int) $state['end_timestamp']); ?>"><?php echo esc_html($this->plugin->get_remaining_time_label()); ?></span></li>
							<li><strong><?php esc_html_e('Plugin installati:', 'automa-work-mode'); ?></strong> <?php echo esc_html((string) count($state['installed_plugins'])); ?></li>
							<li><strong><?php esc_html_e('Plugin attivi all\'avvio:', 'automa-work-mode'); ?></strong> <?php echo esc_html((string) count($state['active_plugins'])); ?></li>
							<li><strong><?php esc_html_e('Plugin inattivi all\'avvio:', 'automa-work-mode'); ?></strong> <?php echo esc_html((string) count($state['inactive_plugins'])); ?></li>
							<li><strong><?php esc_html_e('Plugin selezionati:', 'automa-work-mode'); ?></strong> <?php echo esc_html(implode(', ', $state['selected_plugins'])); ?></li>
						</ul>
					<?php else : ?>
						<p><?php esc_html_e('La fotografia completa viene salvata all\'avvio della Work Mode.', 'automa-work-mode'); ?></p>
					<?php endif; ?>
				</div>

				<div class="automa-work-mode__card">
					<h2><?php esc_html_e('Plugin Installati', 'automa-work-mode'); ?></h2>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e('Seleziona', 'automa-work-mode'); ?></th>
								<th><?php esc_html_e('Nome', 'automa-work-mode'); ?></th>
								<th><?php esc_html_e('Plugin file/path', 'automa-work-mode'); ?></th>
								<th><?php esc_html_e('Stato', 'automa-work-mode'); ?></th>
								<th><?php esc_html_e('Classificazione', 'automa-work-mode'); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($inventory as $plugin) : ?>
								<tr>
									<td>
										<?php if ($plugin['classification'] === 'protected') : ?>
											<input type="checkbox" disabled="disabled" title="<?php esc_attr_e('Plugin protetto', 'automa-work-mode'); ?>" />
										<?php else : ?>
											<input
												type="checkbox"
												form="automa-work-mode-activate-form"
												name="selected_plugins[]"
												value="<?php echo esc_attr($plugin['plugin_file']); ?>"
												<?php checked(in_array($plugin['plugin_file'], $selected_plugins, true)); ?>
											/>
										<?php endif; ?>
									</td>
									<td><?php echo esc_html($plugin['name']); ?></td>
									<td><code><?php echo esc_html($plugin['plugin_file']); ?></code></td>
									<td><?php echo esc_html(strtoupper($plugin['status'])); ?></td>
									<td><span class="automa-work-mode__badge automa-work-mode__badge--<?php echo esc_attr($plugin['classification']); ?>"><?php echo esc_html($plugin['classification']); ?></span></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Build an inventory map indexed by plugin file.
	 */
	private function get_plugin_map(array $inventory): array {
		$map = array();

		foreach ($inventory as $plugin) {
			$map[$plugin['plugin_file']] = $plugin;
		}

		return $map;
	}

	/**
	 * Render plugin list vertically with primary name and secondary file path.
	 */
	private function render_plugin_file_list(array $plugin_files, array $plugin_map): void {
		if (empty($plugin_files)) {
			echo '<p class="automa-work-mode__muted">' . esc_html__('Nessun plugin spento in questa sessione.', 'automa-work-mode') . '</p>';
			return;
		}

		echo '<ul class="automa-work-mode__disabled-list">';

		foreach ($plugin_files as $plugin_file) {
			$plugin_name = $plugin_map[$plugin_file]['name'] ?? $plugin_file;

			echo '<li class="automa-work-mode__disabled-item">';
			echo '<span class="automa-work-mode__disabled-name">' . esc_html($plugin_name) . '</span>';
			echo '<code class="automa-work-mode__disabled-path">' . esc_html($plugin_file) . '</code>';
			echo '</li>';
		}

		echo '</ul>';
	}

	/**
	 * Render flash notices after redirects.
	 */
	private function render_flash_notice(): void {
		if (empty($_GET['automa_message'])) {
			return;
		}

		$class = sanitize_html_class(wp_unslash($_GET['automa_type'] ?? 'info'));
		$message = sanitize_text_field(wp_unslash($_GET['automa_message']));

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr($class),
			esc_html($message)
		);
	}

	/**
	 * Render the start form with timer and auto-login settings.
	 *
	 * @param array<int|string,mixed>              $settings
	 * @param array<int,string>                    $selected_plugins
	 * @param array<int,array<string,string>>      $available_roles
	 */
	private function render_activate_form(array $settings, array $selected_plugins, string $button_label, bool $with_plugin_hint = false, array $inventory = array(), bool $allow_save = false, array $available_roles = array()): void {
		$default_minutes = (int) ($settings['default_minutes'] ?? 120);
		$auto_activate_on_login = ! empty($settings['auto_activate_on_login']);
		$allowed_roles = $settings['allowed_roles'] ?? array('administrator');
		?>
		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="automa-work-mode__form" id="automa-work-mode-activate-form">
			<input type="hidden" name="action" value="automa_work_mode_activate" />
			<?php wp_nonce_field('automa_work_mode_activate'); ?>
			<p>
				<label for="automa-minutes"><strong><?php esc_html_e('Minuti prima della riattivazione automatica', 'automa-work-mode'); ?></strong></label><br />
				<input id="automa-minutes" type="number" min="1" max="720" step="1" name="minutes" value="<?php echo esc_attr($default_minutes); ?>" class="small-text" />
			</p>
			<p>
				<label>
					<input type="checkbox" name="auto_activate_on_login" value="1" <?php checked($auto_activate_on_login); ?> />
					<?php esc_html_e('Attiva automaticamente la Modalita Operativa al login', 'automa-work-mode'); ?>
				</label>
			</p>
			<fieldset>
				<legend><strong><?php esc_html_e('Ruoli ammessi per l\'attivazione automatica', 'automa-work-mode'); ?></strong></legend>
				<?php foreach ($available_roles as $role) : ?>
					<label style="display:block; margin-bottom:4px;">
						<input
							type="checkbox"
							name="allowed_roles[]"
							value="<?php echo esc_attr($role['key']); ?>"
							<?php checked(in_array($role['key'], $allowed_roles, true)); ?>
						/>
						<?php echo esc_html($role['label']); ?>
					</label>
				<?php endforeach; ?>
			</fieldset>
			<?php if ($with_plugin_hint) : ?>
				<p class="description"><?php esc_html_e('Seleziona manualmente dalla tabella i plugin da disattivare. I plugin protected non sono selezionabili.', 'automa-work-mode'); ?></p>
			<?php endif; ?>
			<?php foreach ($selected_plugins as $plugin_file) : ?>
				<input type="hidden" name="selected_plugins[]" value="<?php echo esc_attr($plugin_file); ?>" class="automa-work-mode-hidden-selection" />
			<?php endforeach; ?>
			<?php submit_button($button_label, 'primary', 'submit', false); ?>
			<?php if ($allow_save) : ?>
				<button type="submit" name="automa_mode_action" value="save" class="button"><?php esc_html_e('Salva selezione', 'automa-work-mode'); ?></button>
			<?php endif; ?>
		</form>
		<?php if ($with_plugin_hint && ! empty($inventory)) : ?>
			<script>
				(function () {
					var form = document.getElementById('automa-work-mode-activate-form');
					if (!form) {
						return;
					}
					form.addEventListener('submit', function () {
						form.querySelectorAll('.automa-work-mode-hidden-selection').forEach(function (node) {
							node.remove();
						});
						document.querySelectorAll('input[name="selected_plugins[]"][form="automa-work-mode-activate-form"]:checked').forEach(function (node) {
							var hidden = document.createElement('input');
							hidden.type = 'hidden';
							hidden.name = 'selected_plugins[]';
							hidden.value = node.value;
							hidden.className = 'automa-work-mode-hidden-selection';
							form.appendChild(hidden);
						});
					});
				}());
			</script>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render the stop form.
	 */
	private function render_restore_form(string $button_label): void {
		?>
		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="automa-work-mode__form">
			<input type="hidden" name="action" value="automa_work_mode_restore" />
			<?php wp_nonce_field('automa_work_mode_restore'); ?>
			<?php submit_button($button_label, 'secondary', 'submit', false); ?>
		</form>
		<?php
	}

	/**
	 * Permission gate for admin actions.
	 */
	private function assert_permissions(): void {
		if (! current_user_can(Automa_Work_Mode::CAPABILITY)) {
			wp_die(esc_html__('You do not have permission to perform this action.', 'automa-work-mode'));
		}
	}

	/**
	 * Redirect back to the originating admin page.
	 */
	private function redirect_with_message(string $message, string $type): void {
		$redirect_to = admin_url('tools.php?page=automa-work-mode');

		if (! empty($_POST['_wp_http_referer'])) {
			$redirect_to = esc_url_raw(wp_unslash($_POST['_wp_http_referer']));
		}

		$redirect_to = add_query_arg(
			array(
				'automa_message' => $message,
				'automa_type' => $type,
			),
			$redirect_to
		);

		wp_safe_redirect($redirect_to);
		exit;
	}
}
