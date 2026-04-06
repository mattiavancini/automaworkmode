# Automa Work Mode Business Logic

## What the plugin does

The plugin temporarily disables selected "heavy" plugins to make the WordPress admin lighter during content work, then restores them later.

The central methods are:
- `Automa_Work_Mode::activate_work_mode()`
- `Automa_Work_Mode::restore_work_mode()`
- `Automa_Work_Mode::maybe_activate_on_login()`
- `Automa_Work_Mode::maybe_restore_on_logout()`
- `Automa_Work_Mode::maybe_restore_expired_mode()`
- `Automa_Work_Mode::restore_work_mode_from_cron()`

## Protected plugins

Protected plugins are plugins this system refuses to disable.

Method:
- `Automa_Work_Mode::get_protected_plugins()` in `includes/class-automa-work-mode.php:428`

Explicit protected list includes:
- the plugin itself
- Breeze
- Elementor
- Elementor Pro
- Classic Editor
- Classic Widgets
- Redis Cache
- WP Mail SMTP
- Wordfence
- Sucuri
- iThemes Security Pro
- Better WP Security

Protection is enforced in two ways:

1. `sanitize_selected_plugins()` removes protected plugin files from saved selections.
2. `classify_plugin()` labels plugins as `protected` when:
   - the plugin file is in the explicit protected list
   - or the plugin file/name contains protected keywords such as `redis`, `elementor`, `smtp`, `wordfence`, `sucuri`, `security`, `login`, `template`, `permalink`

UI effect:
- In `Automa_Work_Mode_Admin::render_page()`, protected plugins show disabled checkboxes in the installed plugins table.

## Disable flow

Main method:
- `Automa_Work_Mode::activate_work_mode()` at line 180

Sequence:
1. Ensures WordPress plugin helper functions are loaded via `ensure_plugin_functions_loaded()`.
2. Refuses to run if `is_active()` already reports an active session.
3. Sanitizes duration using `sanitize_minutes()`.
4. Builds plugin inventory with `get_plugin_inventory()`.
5. Reads current active plugins from core option `active_plugins`.
6. Computes current inactive plugins from `get_plugins()` minus `active_plugins`.
7. Resolves selected plugins:
   - explicit override for auto-login, or
   - saved selections from `get_selected_plugins()`
8. Intersects selected plugins with current active plugins to get `disabled_plugins`.
9. If no selected active plugins are found, returns an error result.
10. Builds and stores the session snapshot in `automa_work_mode_state`.
11. Calls `deactivate_plugins($disabled_plugins, true, false)`.
12. Schedules a one-shot restore event through `schedule_restore($end_timestamp)`.
13. Logs the `activated` event.

Important detail:
- Only plugins that are both selected and currently active are actually disabled.

## Restore flow

Main method:
- `Automa_Work_Mode::restore_work_mode()` at line 243

Sequence:
1. Reads the stored session state.
2. If there is no active state, returns an error result.
3. Ensures plugin helper functions are loaded.
4. Reads the current active plugin list.
5. Computes `plugins_to_restore` as the intersection of:
   - `state['disabled_plugins']`
   - `state['active_plugins']`
6. For each plugin:
   - if the plugin file no longer exists, records it in `missing_plugins`
   - if it is already active now, records it in `restored_plugins`
   - otherwise attempts activation with `activate_plugin($plugin_file, '', false, true)`
   - on error records a structured failure in `failed_plugins`
7. Deletes `automa_work_mode_state`.
8. Clears the scheduled restore event.
9. Flushes cache integrations through `flush_cache_layers()`.
10. Logs:
   - `restore_incomplete` if anything was missing or failed
   - otherwise `restored`

Return contract:
- Success with restored plugins list
- Or partial failure with missing/failed details

Important detail:
- Restoration is based only on the saved disabled list and original active list. It does not attempt a full diff against the whole plugin snapshot.

## Timer behavior

Timer state:
- `start_timestamp`
- `end_timestamp`
- `minutes`

Where created:
- `activate_work_mode()`

Where used:
- `get_end_time_label()`
- `get_remaining_time_label()`
- `render_countdown_notice()`
- `render_dashboard_widget()`
- `render_page()`
- `maybe_restore_expired_mode()`
- scheduled cron callback

Execution paths:
1. Primary path: `schedule_restore()` schedules `automa_work_mode_restore_event`.
2. Fallback path: `maybe_restore_expired_mode()` restores on any later admin request after expiry.
3. UI path: `admin.js` continuously updates the visible countdown client-side.

## Login behavior

Method:
- `Automa_Work_Mode::maybe_activate_on_login()` at line 457

Rules:
1. Auto-login activation must be enabled in settings.
2. The request must look like a backend login, detected by `is_backend_login_request()`.
3. Work Mode must not already be active.
4. The user must have at least one allowed role.
5. There must be at least one selected plugin to disable.

Plugin selection logic on login:
1. Start from saved selections via `get_selected_plugins()`.
2. Merge in `get_auto_login_default_plugins()`.
3. `get_auto_login_default_plugins()` currently returns the installed base Internal Link Juicer plugin path(s) from `get_internal_links_plugin_files()`.
4. Sanitize again with `sanitize_selected_plugins()`.

Activation duration on login:
- Always `Automa_Work_Mode::AUTO_LOGIN_MINUTES` = `10`

Logging:
- Success logs `auto_activated_on_login`
- Refusals log `auto_activation_skipped` with a reason

## Logout behavior

Method:
- `Automa_Work_Mode::maybe_restore_on_logout()` at line 327

Rule:
- If Work Mode is active during logout, restore immediately through `restore_work_mode('logout')`.

This makes the session effectively user-session-aware even though state is stored globally.

## Cron behavior

Scheduling:
- `Automa_Work_Mode::schedule_restore()` at line 534

Unscheduling:
- `Automa_Work_Mode::clear_restore_schedule()` at line 542

Execution:
- `Automa_Work_Mode::restore_work_mode_from_cron()` at line 320

Behavior:
- The plugin always clears any existing scheduled restore before scheduling a new one.
- Deactivation also clears the schedule.
- Restore deletes state and clears the schedule again.

## Plugin classification and defaults

Inventory method:
- `Automa_Work_Mode::get_plugin_inventory()` at line 150

Classification method:
- `Automa_Work_Mode::classify_plugin()` at line 591

Classification outputs:
- `protected`
- `recommended-heavy`
- `neutral`
- `unknown`

Base selected plugins:
- Computed by `get_base_selected_plugins()`
- Currently includes:
  - Broken Link Checker
  - WP Compress variants
  - installed Internal Link Juicer base plugin files

Settings upgrade behavior:
- `maybe_upgrade_settings()` merges new base selections into stored settings once per plugin version, tracked by `automa_work_mode_meta['base_defaults_version']`.

## Cache flush behavior after restore

Method:
- `Automa_Work_Mode::flush_cache_layers()` at line 632

Integrations:
- `rocket_clean_domain()`
- `LiteSpeed_Cache_API::purge_all()`
- `sg_cachepress_purge_cache()`
- `do_action('automa_work_mode_flush_cache')`

Purpose:
- After reactivating previously disabled plugins, the plugin tries to clear page-cache layers so the restored environment is reflected consistently.
