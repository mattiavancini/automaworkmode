# Automa Work Mode Admin UI Map

## Admin entry points

### Tools page
- Registered by `Automa_Work_Mode_Admin::register_menu()` in `includes/class-automa-work-mode-admin.php:25`
- Uses `add_management_page()`
- Menu title and page title: `Automa Work Mode`
- Capability required: `Automa_Work_Mode::CAPABILITY` (`automa_work_mode`)
- Slug: `automa-work-mode`
- Render callback: `Automa_Work_Mode_Admin::render_page()`

URL:
- `wp-admin/tools.php?page=automa-work-mode`

### Dashboard widget
- Registered by `Automa_Work_Mode_Admin::register_dashboard_widget()` in `includes/class-automa-work-mode-admin.php:38`
- Uses `wp_add_dashboard_widget()`
- Widget ID: `automa_work_mode_dashboard_widget`
- Render callback: `Automa_Work_Mode_Admin::render_dashboard_widget()`
- Only shown to users who pass `current_user_can(Automa_Work_Mode::CAPABILITY)`

### Plugin row action link
- Added in `automa-work-mode/automa-work-mode.php:38`
- Links from the plugins list table to the Tools page

## Page-level UI composition

Method: `Automa_Work_Mode_Admin::render_page()` at line 173

The Tools page renders three cards:

1. `Stato Sistema`
   - Shows active/inactive state
   - If active:
     - expected end time
     - live countdown
     - disabled plugins list
     - manual restore form
   - If inactive:
     - activation/settings form

2. `Fotografia Base`
   - If active, shows the stored session snapshot:
     - start time
     - end time
     - remaining time
     - installed plugin count
     - active plugin count at start
     - inactive plugin count at start
     - selected plugin list
   - If inactive, shows explanatory placeholder text

3. `Plugin Installati`
   - Renders a table of installed plugins from `Automa_Work_Mode::get_plugin_inventory()`
   - Columns:
     - checkbox
     - name
     - plugin file/path
     - status
     - classification
   - Protected plugins render a disabled checkbox
   - Other plugins can be selected for temporary shutdown

## Dashboard widget UI

Method: `Automa_Work_Mode_Admin::render_dashboard_widget()` at line 146

Inactive state:
- Shows current status
- Renders a compact activation form via `render_activate_form(...)`
- Hides editable settings flags by passing `$show_settings_flags = false`

Active state:
- Shows current status
- Shows end time and live countdown
- Shows manual restore button via `render_restore_form()`

## Notices

### Flash notices after redirect
- Rendered by `Automa_Work_Mode_Admin::render_flash_notice()` at line 336
- Triggered when redirect query args exist:
  - `automa_message`
  - `automa_type`
- Notice class becomes `notice notice-{type} is-dismissible`

### Persistent active-session notice
- Rendered by `Automa_Work_Mode_Admin::render_countdown_notice()` at line 119
- Hooked to `admin_notices`
- Only shown when:
  - current user has capability
  - `Automa_Work_Mode::is_active()` is true
- Contains:
  - active-mode title
  - remaining time countdown
  - inline restore form

## Forms

### Activation/settings form
- Rendered by `Automa_Work_Mode_Admin::render_activate_form()` at line 359
- Posts to: `admin_url('admin-post.php')`
- Hidden action: `automa_work_mode_activate`
- Nonce: `wp_nonce_field('automa_work_mode_activate')`

Fields:
- `minutes`
- `auto_activate_on_login`
- `allowed_roles[]`
- `selected_plugins[]`
- optional `automa_mode_action=save`

Buttons:
- main submit button
- optional `Salva selezione` button when `$allow_save` is true

Behavior:
- On the full page, selected plugins are chosen in the table and copied into hidden `selected_plugins[]` inputs by an inline script before submit.
- On the widget, the form reuses the current stored hidden selections instead of showing the full table.

### Restore form
- Rendered by `Automa_Work_Mode_Admin::render_restore_form()` at line 438
- Posts to: `admin_url('admin-post.php')`
- Hidden action: `automa_work_mode_restore`
- Nonce: `wp_nonce_field('automa_work_mode_restore')`

Used in:
- Tools page active state
- Dashboard widget active state
- Persistent admin notice

## Admin-post handlers

### `admin_post_automa_work_mode_activate`
- Callback: `Automa_Work_Mode_Admin::handle_activate_request()` at line 85
- Security:
  - `assert_permissions()`
  - `check_admin_referer('automa_work_mode_activate')`
- Work:
  - reads settings from `$_POST`
  - persists settings via `Automa_Work_Mode::update_settings()`
  - if save-only, redirects with success
  - otherwise activates Work Mode via `Automa_Work_Mode::activate_work_mode()`

### `admin_post_automa_work_mode_restore`
- Callback: `Automa_Work_Mode_Admin::handle_restore_request()` at line 108
- Security:
  - `assert_permissions()`
  - `check_admin_referer('automa_work_mode_restore')`
- Work:
  - restores via `Automa_Work_Mode::restore_work_mode('manual')`
  - redirects with result message

## Assets

### CSS
- Registered/enqueued by `Automa_Work_Mode_Admin::enqueue_assets()` at line 55
- Handle: `automa-work-mode-admin`
- File: `automa-work-mode/assets/css/admin.css`
- Loaded on:
  - dashboard (`index.php`)
  - plugin page (`tools_page_automa-work-mode`)
  - any admin page while Work Mode is active

### JS
- Registered/enqueued by `Automa_Work_Mode_Admin::enqueue_assets()` at line 55
- Handle: `automa-work-mode-admin`
- File: `automa-work-mode/assets/js/admin.js`
- Purpose:
  - refreshes visible countdown values every second
  - uses timestamps already rendered server-side

## Permission checks

UI and handlers consistently gate access with `Automa_Work_Mode::CAPABILITY`:
- `register_dashboard_widget()`
- `enqueue_assets()`
- `render_countdown_notice()`
- `render_page()`
- `assert_permissions()`

This capability is added to administrators by `Automa_Work_Mode::grant_capabilities()`.
