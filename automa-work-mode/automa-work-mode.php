<?php
/**
 * Plugin Name: Automa Work Mode
 * Plugin URI:  https://automa.biz
 * Description: Disattiva temporaneamente i plugin selezionati piu pesanti per alleggerire il backend WordPress durante le attivita di copywriting e gestione contenuti.
 * Version:     0.1.3
 * Author:      Automa
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: automa-work-mode
 */

if (! defined('ABSPATH')) {
	exit;
}

define('AUTOMA_WORK_MODE_VERSION', '0.1.3');
define('AUTOMA_WORK_MODE_FILE', __FILE__);
define('AUTOMA_WORK_MODE_DIR', plugin_dir_path(__FILE__));
define('AUTOMA_WORK_MODE_URL', plugin_dir_url(__FILE__));

require_once AUTOMA_WORK_MODE_DIR . 'includes/class-automa-work-mode.php';
require_once AUTOMA_WORK_MODE_DIR . 'includes/class-automa-work-mode-admin.php';

function automa_work_mode(): Automa_Work_Mode {
	static $instance = null;

	if ($instance === null) {
		$instance = new Automa_Work_Mode();
	}

	return $instance;
}

register_activation_hook(__FILE__, array('Automa_Work_Mode', 'activate'));
register_deactivation_hook(__FILE__, array('Automa_Work_Mode', 'deactivate'));

add_filter('plugin_action_links_' . plugin_basename(__FILE__), static function (array $links): array {
	$url = admin_url('tools.php?page=automa-work-mode');
	array_unshift(
		$links,
		sprintf(
			'<a href="%s">%s</a>',
			esc_url($url),
			esc_html__('Impostazioni', 'automa-work-mode')
		)
	);

	return $links;
});

automa_work_mode()->boot();
