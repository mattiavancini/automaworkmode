# Automa Work Mode Risk Review

## 1. Global state is not scoped per user or per browser session

Files:
- `automa-work-mode/includes/class-automa-work-mode.php`

Methods:
- `activate_work_mode()`
- `restore_work_mode()`
- `maybe_activate_on_login()`
- `maybe_restore_on_logout()`

Issue:
- Active session state is stored in the single global option `automa_work_mode_state`.
- Auto-login activation and logout restoration are triggered by whichever eligible user logs in or out.

Risk:
- One administrator can activate Work Mode and another administrator can implicitly restore it on logout.
- The stored `activated_by` field is recorded but never enforced.
- This is hidden coupling between multiple admins sharing the same site.

## 2. Restore may overwrite intentional plugin-state changes made during the session

Files:
- `automa-work-mode/includes/class-automa-work-mode.php`

Methods:
- `activate_work_mode()`
- `restore_work_mode()`

Issue:
- The plugin snapshots plugin state at activation and later attempts to reactivate all originally disabled plugins.
- It does not check whether an admin intentionally kept some plugin disabled during the session.

Risk:
- Manual plugin-management actions made while Work Mode is active can be silently reversed at restore time.

## 3. Login detection relies on request heuristics

Files:
- `automa-work-mode/includes/class-automa-work-mode.php`

Methods:
- `maybe_activate_on_login()`
- `is_backend_login_request()`

Issue:
- Backend-login detection depends on `is_admin()` or `redirect_to` / `requested_redirect_to` request values.

Risk:
- Some custom login flows may not be recognized as backend logins.
- Other flows might be incorrectly treated as backend-targeted if redirect handling is customized upstream.

## 4. Scheduled restore handling only unschedules one event timestamp

Files:
- `automa-work-mode/includes/class-automa-work-mode.php`

Method:
- `clear_restore_schedule()`

Issue:
- It uses `wp_next_scheduled()` and `wp_unschedule_event()` for only the next matching occurrence.

Risk:
- Under unusual duplicate scheduling conditions, stale events could survive.
- WordPress normally should not create many duplicates here, but the cleanup strategy is minimal.

## 5. Capability and role model is internally inconsistent

Files:
- `automa-work-mode/includes/class-automa-work-mode.php`
- `automa-work-mode/uninstall.php`

Methods:
- `grant_capabilities()`
- `remove_legacy_capabilities()`
- `sanitize_roles()`

Issue:
- `grant_capabilities()` only grants `automa_work_mode` to administrators.
- `sanitize_roles()` ultimately restricts allowed roles to `administrator` even if broader roles are posted.
- `remove_legacy_capabilities()` and `uninstall.php` still handle `editor`, which suggests older assumptions are still present.

Risk:
- The code implies configurability for allowed roles, but the implementation hard-limits it to administrators.
- This is maintainability debt and misleading surface area in the settings UI model.

## 6. No multisite awareness

Files:
- `automa-work-mode/includes/class-automa-work-mode.php`

Methods:
- `activate_work_mode()`
- `restore_work_mode()`
- `get_plugin_inventory()`

Issue:
- The plugin always uses site-level plugin APIs and options such as `active_plugins`.
- There is no handling for network-activated plugins or network admin context.

Risk:
- Behavior on multisite is undefined or incomplete.
- Restores may not reflect the actual activation topology on network-managed installs.

## 7. Hidden dependency on plugin file path conventions

Files:
- `automa-work-mode/includes/class-automa-work-mode.php`

Methods:
- `normalize_selected_plugins()`
- `get_internal_links_plugin_files()`
- `classify_plugin()`

Issue:
- Several behaviors rely on string matching of plugin file paths and names.
- Internal Link Juicer support is path-based and special-cased.
- Protection/recommendation classification uses substring heuristics.

Risk:
- Renamed plugin directories or unexpected vendor packaging can bypass intended classification.
- Classification may produce false positives or false negatives.

## 8. Logging exists but there is no in-plugin log viewer

Files:
- `automa-work-mode/includes/class-automa-work-mode.php`
- `automa-work-mode/includes/class-automa-work-mode-admin.php`

Methods:
- `log_event()`
- `get_logs()`

Issue:
- Logs are stored in `automa_work_mode_log`, but no admin screen renders them.

Risk:
- Important restore failures are persisted but operationally hard to inspect.
- The plugin's own error-reporting path says "Review the log" even though the UI offers no log reader.

## 9. Cache flush runs after deleting state

Files:
- `automa-work-mode/includes/class-automa-work-mode.php`

Method:
- `restore_work_mode()`

Issue:
- `delete_option(self::STATE_OPTION)` happens before `flush_cache_layers()`.

Risk:
- Extensions hooked to `automa_work_mode_flush_cache` cannot inspect active-session state from the standard option during the flush phase.
- That ordering may be intentional, but it limits extension possibilities.

## 10. Frontend/admin UX relies partly on inline script inside PHP rendering

Files:
- `automa-work-mode/includes/class-automa-work-mode-admin.php`

Method:
- `render_activate_form()`

Issue:
- The plugin-selection synchronization script is printed inline only when the full page variant is used.

Risk:
- The form contract is split between PHP markup and an embedded script rather than a dedicated asset/module.
- This makes the selection flow less discoverable and harder to test or reuse.

## 11. Limited validation around redirect source

Files:
- `automa-work-mode/includes/class-automa-work-mode-admin.php`

Method:
- `redirect_with_message()`

Issue:
- The method prefers `$_POST['_wp_http_referer']` and sanitizes it with `esc_url_raw()` before `wp_safe_redirect()`.

Risk:
- `wp_safe_redirect()` is the main safeguard, so this is not an immediate vulnerability, but redirect intent depends on a posted referer rather than a tightly controlled internal route.
- Simpler redirection rules would reduce ambiguity.

## 12. Missing automated test surface

Repository-wide issue:
- No tests are present for activation, restore, login, logout, cron, or admin-post flows.

Risk:
- The plugin modifies core plugin activation state, which is high-impact behavior.
- Regression risk is concentrated around side effects that are hard to reason about without tests.
