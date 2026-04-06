# Automa Work Mode Hooks Map

## Hook registrations in code

| Hook | Type | Callback | Where registered | Purpose | Phase |
| --- | --- | --- | --- | --- | --- |
| `plugin_action_links_{plugin_basename}` | filter | anonymous static closure in `automa-work-mode.php` | `automa-work-mode/automa-work-mode.php:38` | Adds the "Impostazioni" link to the plugin row in the plugins screen. | Admin / plugins list UI |
| `admin_init` | action | `Automa_Work_Mode::maybe_restore_expired_mode` | `includes/class-automa-work-mode.php:37` | Admin-side fallback restore when the stored end time has passed. | Admin bootstrap |
| `automa_work_mode_restore_event` | action | `Automa_Work_Mode::restore_work_mode_from_cron` | `includes/class-automa-work-mode.php:38` | Runs the scheduled restore via WP-Cron. | Runtime / cron |
| `wp_login` | action | `Automa_Work_Mode::maybe_activate_on_login` | `includes/class-automa-work-mode.php:39` | Auto-starts Work Mode for allowed backend logins. | Runtime / login |
| `wp_logout` | action | `Automa_Work_Mode::maybe_restore_on_logout` | `includes/class-automa-work-mode.php:40` | Restores disabled plugins when the current session logs out. | Runtime / logout |
| `admin_menu` | action | `Automa_Work_Mode_Admin::register_menu` | `includes/class-automa-work-mode.php:41` | Adds the Tools page. | Admin menu build |
| `wp_dashboard_setup` | action | `Automa_Work_Mode_Admin::register_dashboard_widget` | `includes/class-automa-work-mode.php:42` | Adds the dashboard widget controller. | Admin dashboard setup |
| `admin_post_automa_work_mode_activate` | action | `Automa_Work_Mode_Admin::handle_activate_request` | `includes/class-automa-work-mode.php:43` | Handles the activation/settings form POST. | Admin request handling |
| `admin_post_automa_work_mode_restore` | action | `Automa_Work_Mode_Admin::handle_restore_request` | `includes/class-automa-work-mode.php:44` | Handles the restore form POST. | Admin request handling |
| `admin_notices` | action | `Automa_Work_Mode_Admin::render_countdown_notice` | `includes/class-automa-work-mode.php:45` | Shows the active-session countdown banner and restore button. | Admin page render |
| `admin_enqueue_scripts` | action | `Automa_Work_Mode_Admin::enqueue_assets` | `includes/class-automa-work-mode.php:46` | Loads plugin CSS/JS on relevant admin screens or while active. | Admin asset loading |
| `postbox_classes_dashboard_automa_work_mode_dashboard_widget` | filter | `Automa_Work_Mode_Admin::filter_dashboard_widget_classes` | `includes/class-automa-work-mode-admin.php:43` | Adds an active-state CSS class to the dashboard postbox. | Admin dashboard render |
| `automa_work_mode_flush_cache` | action dispatch | fired by `Automa_Work_Mode::flush_cache_layers` | `includes/class-automa-work-mode.php:650` | Extension point for third-party cache flushing during restore. | Runtime / restore |

## Activation and deactivation hooks

These are not `add_action()` calls, but they are part of the plugin lifecycle:

| WordPress API | Callback | Where | Purpose |
| --- | --- | --- | --- |
| `register_activation_hook()` | `Automa_Work_Mode::activate` | `automa-work-mode/automa-work-mode.php:35` | Creates initial options, grants capability, upgrades settings. |
| `register_deactivation_hook()` | `Automa_Work_Mode::deactivate` | `automa-work-mode/automa-work-mode.php:36` | Clears cron and restores plugins before shutdown. |

## Notes for a learner

- All public hook callbacks on the runtime class are registered centrally in `Automa_Work_Mode::boot()`. This is the plugin's effective service container.
- The admin class does not self-register. It is instantiated by the runtime and then attached to hooks from there.
- The plugin exposes exactly one custom extension hook of its own: `automa_work_mode_flush_cache`.
- The `admin_post_*` pattern is the key form-processing mechanism in this codebase. Forms are rendered in `Automa_Work_Mode_Admin`, posted to `admin-post.php`, and then routed back to dedicated handlers by action name.
