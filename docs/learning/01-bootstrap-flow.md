# Automa Work Mode Bootstrap Flow

## 1. Main plugin file load

File: `automa-work-mode/automa-work-mode.php`

1. WordPress loads the plugin file.
2. The file blocks direct access with `if (! defined('ABSPATH')) { exit; }`.
3. It defines:
   - `AUTOMA_WORK_MODE_VERSION`
   - `AUTOMA_WORK_MODE_FILE`
   - `AUTOMA_WORK_MODE_DIR`
   - `AUTOMA_WORK_MODE_URL`
4. It includes:
   - `includes/class-automa-work-mode.php`
   - `includes/class-automa-work-mode-admin.php`
5. It defines `automa_work_mode(): Automa_Work_Mode` at line 25 as a singleton-style accessor using a static `$instance`.
6. It registers:
   - `register_activation_hook(__FILE__, array('Automa_Work_Mode', 'activate'))`
   - `register_deactivation_hook(__FILE__, array('Automa_Work_Mode', 'deactivate'))`
7. It adds a plugin row action link through the dynamic filter `plugin_action_links_{plugin_basename}`.
8. It calls `automa_work_mode()->boot()` at line 52.

## 2. Core boot sequence

Method: `Automa_Work_Mode::boot()` in `includes/class-automa-work-mode.php:33`

Boot does two things before registering runtime hooks:

1. Instantiates the admin controller:
   - `$this->admin = new Automa_Work_Mode_Admin($this);`
2. Runs settings migration logic:
   - `$this->maybe_upgrade_settings();`

Then it registers the runtime hooks:

1. `admin_init` -> `Automa_Work_Mode::maybe_restore_expired_mode()`
2. `automa_work_mode_restore_event` -> `Automa_Work_Mode::restore_work_mode_from_cron()`
3. `wp_login` -> `Automa_Work_Mode::maybe_activate_on_login()`
4. `wp_logout` -> `Automa_Work_Mode::maybe_restore_on_logout()`
5. `admin_menu` -> `Automa_Work_Mode_Admin::register_menu()`
6. `wp_dashboard_setup` -> `Automa_Work_Mode_Admin::register_dashboard_widget()`
7. `admin_post_automa_work_mode_activate` -> `Automa_Work_Mode_Admin::handle_activate_request()`
8. `admin_post_automa_work_mode_restore` -> `Automa_Work_Mode_Admin::handle_restore_request()`
9. `admin_notices` -> `Automa_Work_Mode_Admin::render_countdown_notice()`
10. `admin_enqueue_scripts` -> `Automa_Work_Mode_Admin::enqueue_assets()`

## 3. Activation flow

Hook target: `Automa_Work_Mode::activate()` in `includes/class-automa-work-mode.php:52`

When the plugin is activated:

1. The method creates a fresh runtime instance: `$self = new self();`
2. It creates default options if they do not exist:
   - `automa_work_mode_settings` via `add_option(..., $self->get_default_settings())`
   - `automa_work_mode_log` via `add_option(..., array())`
   - `automa_work_mode_meta` via `add_option(..., array())`
3. It grants the custom capability through `Automa_Work_Mode::grant_capabilities()`.
4. It removes old capability assignments through `Automa_Work_Mode::remove_legacy_capabilities()`.
5. It runs `Automa_Work_Mode::maybe_upgrade_settings()` to merge new base defaults into stored settings.

Notably, activation does not create `automa_work_mode_state`; state exists only during an active session.

## 4. Deactivation flow

Hook target: `Automa_Work_Mode::deactivate()` in `includes/class-automa-work-mode.php:75`

When the plugin is deactivated:

1. The method creates a fresh runtime instance: `$self = new self();`
2. It clears any pending scheduled restore event through `Automa_Work_Mode::clear_restore_schedule()`.
3. It forces plugin restoration through `Automa_Work_Mode::restore_work_mode('plugin_deactivation')`.

This matters because the plugin may have disabled other plugins; deactivation tries to leave WordPress in its pre-session state.

## 5. Request-time runtime flow

### Admin request safety path
On each admin request, `Automa_Work_Mode::maybe_restore_expired_mode()` runs on `admin_init`.

Flow:
1. Confirms `is_admin()`.
2. Reads `automa_work_mode_state` via `get_state()`.
3. If `end_timestamp <= time()`, calls `restore_work_mode('admin_fallback')`.

This is a safety net if cron does not fire.

### Manual activation path
Triggered by form POST to `admin-post.php?action=automa_work_mode_activate`.

Flow:
1. `Automa_Work_Mode_Admin::handle_activate_request()` checks capability and nonce.
2. It builds settings from `$_POST`.
3. It persists settings through `Automa_Work_Mode::update_settings()`.
4. If `automa_mode_action=save`, it only saves settings and redirects.
5. Otherwise it calls `Automa_Work_Mode::activate_work_mode()`.
6. `activate_work_mode()` snapshots installed/active/inactive plugins, computes selected active plugins to disable, writes `automa_work_mode_state`, deactivates plugins with `deactivate_plugins()`, schedules cron restore, logs the event, and returns a status array.
7. The admin handler redirects back with a flash message.

### Manual restore path
Triggered by form POST to `admin-post.php?action=automa_work_mode_restore`.

Flow:
1. `Automa_Work_Mode_Admin::handle_restore_request()` checks capability and nonce.
2. It calls `Automa_Work_Mode::restore_work_mode('manual')`.
3. `restore_work_mode()` re-activates previously disabled plugins, deletes `automa_work_mode_state`, clears the scheduled event, flushes cache layers, writes a log entry, and returns a status array.
4. The admin handler redirects back with a flash message.

## 6. Login/logout/cron flows

### Login auto-activation
Hook: `wp_login` -> `Automa_Work_Mode::maybe_activate_on_login()` at line 457

Sequence:
1. Verifies the `$user` object is a `WP_User`.
2. Reads settings via `get_settings()`.
3. Requires `auto_activate_on_login` to be enabled.
4. Requires `is_backend_login_request()` to detect an admin login flow.
5. Refuses to run if Work Mode is already active.
6. Builds plugin selection via `get_auto_login_selected_plugins()`.
7. Checks user roles against `get_allowed_roles()`.
8. Calls `activate_work_mode(self::AUTO_LOGIN_MINUTES, (int) $user->ID, $selected_plugins)`.
9. Logs either `auto_activated_on_login` or `auto_activation_skipped`.

### Logout restore
Hook: `wp_logout` -> `Automa_Work_Mode::maybe_restore_on_logout()` at line 327

Sequence:
1. Checks `is_active()`.
2. Calls `restore_work_mode('logout')` if a session is active.

### Cron restore
Scheduling happens in `Automa_Work_Mode::schedule_restore()` at line 534 via:
- `wp_schedule_single_event($timestamp, self::RESTORE_HOOK)`

Execution happens through:
- hook `automa_work_mode_restore_event`
- callback `Automa_Work_Mode::restore_work_mode_from_cron()`
- which delegates to `restore_work_mode('cron')`

## 7. Uninstall flow

File: `automa-work-mode/uninstall.php`

When the plugin is uninstalled, WordPress loads `uninstall.php` if `WP_UNINSTALL_PLUGIN` is defined.

Sequence:
1. Removes capability `automa_work_mode` from:
   - `administrator`
   - `editor`
2. Deletes:
   - `automa_work_mode_settings`
   - `automa_work_mode_state`
   - `automa_work_mode_log`
   - `automa_work_mode_meta`

Unlike deactivation, uninstall performs destructive cleanup of persistent plugin data.
