# Automa Work Mode Options and State

## Persistent options owned by the plugin

### `automa_work_mode_settings`
- Constant: `Automa_Work_Mode::SETTINGS_OPTION`
- Defined in `includes/class-automa-work-mode.php:11`
- Stores:
  - `default_minutes`
  - `auto_activate_on_login`
  - `allowed_roles`
  - `selected_plugins`

Read by:
- `Automa_Work_Mode::activate()` to detect first install
- `Automa_Work_Mode::get_settings()`
- `Automa_Work_Mode::maybe_upgrade_settings()`

Written by:
- `Automa_Work_Mode::activate()` through `add_option()`
- `Automa_Work_Mode::update_settings()`
- `Automa_Work_Mode::maybe_upgrade_settings()`
- `uninstall.php` deletes it

Sanitization path:
- `update_settings()` calls `sanitize_minutes()`, `sanitize_roles()`, and `sanitize_selected_plugins()`

### `automa_work_mode_state`
- Constant: `Automa_Work_Mode::STATE_OPTION`
- Defined in `includes/class-automa-work-mode.php:12`
- Exists only during an active Work Mode session.
- Stores:
  - `active`
  - `start_timestamp`
  - `end_timestamp`
  - `minutes`
  - `installed_plugins`
  - `active_plugins`
  - `inactive_plugins`
  - `selected_plugins`
  - `disabled_plugins`
  - `activated_by`

Read by:
- `Automa_Work_Mode::get_state()`
- `Automa_Work_Mode::is_active()`
- `Automa_Work_Mode::restore_work_mode()`
- `Automa_Work_Mode::maybe_restore_expired_mode()`
- `Automa_Work_Mode::get_end_time_label()`
- `Automa_Work_Mode::get_remaining_time_label()`
- `Automa_Work_Mode_Admin::render_countdown_notice()`
- `Automa_Work_Mode_Admin::render_dashboard_widget()`
- `Automa_Work_Mode_Admin::render_page()`

Written by:
- `Automa_Work_Mode::activate_work_mode()` through `update_option()`

Deleted by:
- `Automa_Work_Mode::restore_work_mode()`
- `uninstall.php`

### `automa_work_mode_log`
- Constant: `Automa_Work_Mode::LOG_OPTION`
- Defined in `includes/class-automa-work-mode.php:13`
- Stores an array of bounded log entries with:
  - `timestamp`
  - `event`
  - `context`

Read by:
- `Automa_Work_Mode::activate()` to detect first install
- `Automa_Work_Mode::get_logs()`
- `Automa_Work_Mode::log_event()` when appending

Written by:
- `Automa_Work_Mode::activate()` through `add_option()`
- `Automa_Work_Mode::log_event()` through `update_option()`

Deleted by:
- `uninstall.php`

Event types currently written:
- `activated`
- `restored`
- `restore_incomplete`
- `cache_flush`
- `auto_activation_skipped`
- `auto_activated_on_login`

### `automa_work_mode_meta`
- Constant: `Automa_Work_Mode::META_OPTION`
- Defined in `includes/class-automa-work-mode.php:14`
- Stores internal plugin metadata, currently:
  - `base_defaults_version`

Read by:
- `Automa_Work_Mode::activate()` to detect first install
- `Automa_Work_Mode::maybe_upgrade_settings()`

Written by:
- `Automa_Work_Mode::activate()` through `add_option()`
- `Automa_Work_Mode::maybe_upgrade_settings()`

Deleted by:
- `uninstall.php`

Purpose:
- Tracks whether base default plugin selections have already been merged into stored settings for the current plugin version.

## Core WordPress state read or affected

### `active_plugins` option
- Core WordPress option, not owned by this plugin.
- Read by:
  - `Automa_Work_Mode::get_plugin_inventory()`
  - `Automa_Work_Mode::activate_work_mode()`
  - `Automa_Work_Mode::restore_work_mode()`
- Indirectly written by WordPress when this plugin calls:
  - `deactivate_plugins()` in `activate_work_mode()`
  - `activate_plugin()` in `restore_work_mode()`

### Role capabilities
- Custom capability: `automa_work_mode`
- Granted by `Automa_Work_Mode::grant_capabilities()`
- Removed from editors by `Automa_Work_Mode::remove_legacy_capabilities()`
- Removed on uninstall in `uninstall.php`

### Scheduled event state
- Event hook name: `automa_work_mode_restore_event`
- Scheduled by `Automa_Work_Mode::schedule_restore()`
- Inspected by `Automa_Work_Mode::clear_restore_schedule()` via `wp_next_scheduled()`
- Removed by `clear_restore_schedule()` via `wp_unschedule_event()`

This is cron state rather than an option the plugin manages directly.

## Request state and transient inputs

### `$_POST`
Used by:
- `Automa_Work_Mode_Admin::handle_activate_request()`
  - `minutes`
  - `auto_activate_on_login`
  - `allowed_roles[]`
  - `selected_plugins[]`
  - `automa_mode_action`
- `Automa_Work_Mode_Admin::handle_restore_request()`
  - nonce only
- `Automa_Work_Mode_Admin::redirect_with_message()`
  - `_wp_http_referer`

### `$_GET`
Used by:
- `Automa_Work_Mode_Admin::render_flash_notice()`
  - `automa_message`
  - `automa_type`

### `$_REQUEST`
Used by:
- `Automa_Work_Mode::is_backend_login_request()`
  - `redirect_to`
  - `requested_redirect_to`

## State model summary

The state model is simple:

1. Settings persist across requests and across activations.
2. Session state is created only when Work Mode starts.
3. Logs accumulate up to `MAX_LOG_ENTRIES`.
4. Meta stores only upgrade bookkeeping.
5. Actual plugin activation state is ultimately enforced through WordPress core plugin APIs and the core `active_plugins` option.
