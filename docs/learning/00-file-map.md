# Automa Work Mode File Map

## Relevant tree
```text
automa-work-mode/
├── automa-work-mode.php
├── uninstall.php
├── assets/
│   ├── css/
│   │   └── admin.css
│   └── js/
│       └── admin.js
└── includes/
    ├── class-automa-work-mode.php
    └── class-automa-work-mode-admin.php
```

## PHP files

### `automa-work-mode/automa-work-mode.php`
- Main plugin bootstrap file and WordPress entry point.
- Defines plugin constants, loads both class files, registers activation and deactivation hooks, adds the plugin row "Impostazioni" link, and calls `automa_work_mode()->boot()`.

### `automa-work-mode/includes/class-automa-work-mode.php`
- Core runtime and business logic in `Automa_Work_Mode`.
- Owns settings/state/log/meta persistence, plugin inventory/classification, activation and restore flows, auto-login behavior, logout behavior, cron restore scheduling, and cache flush integration.

### `automa-work-mode/includes/class-automa-work-mode-admin.php`
- Admin controller in `Automa_Work_Mode_Admin`.
- Registers the Tools page and dashboard widget, handles admin-post form submissions, renders notices/forms/tables, and enqueues admin assets.

### `automa-work-mode/uninstall.php`
- Uninstall cleanup entry point.
- Removes the custom capability from roles and deletes the plugin's persistent options.

## Non-PHP support files

### `automa-work-mode/assets/js/admin.js`
- Client-side countdown refresh for elements rendered by `Automa_Work_Mode_Admin::render_countdown()`.
- Reads `data-automa-end-timestamp` and updates the visible remaining time every second.

### `automa-work-mode/assets/css/admin.css`
- Styles for the Tools page, dashboard widget, active-state highlighting, countdown notice, badges, and disabled plugin list.
