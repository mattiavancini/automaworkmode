<?php
/**
 * Cleanup plugin-specific options on uninstall.
 */

if (! defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

foreach (array('administrator', 'editor') as $role_name) {
	$role = get_role($role_name);

	if ($role) {
		$role->remove_cap('automa_work_mode');
	}
}

delete_option('automa_work_mode_settings');
delete_option('automa_work_mode_state');
delete_option('automa_work_mode_log');
