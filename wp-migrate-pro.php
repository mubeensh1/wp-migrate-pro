<?php

/**
 * Plugin Name: WP Migrate Pro
 * Plugin URI:  https://github.com/mubeensh1/wp-migrate-pro/
 * Description: Full-site backup, restore & migration with real-time progress. 524/timeout safe.
 * Version:     1.4.1
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * Author:      WP Migrate Pro
 * License:     GPL-2.0+
 * Text Domain: wp-migrate-pro
 */
if (! defined('ABSPATH')) {
    exit;
}
if (defined('WMP_VERSION')) {
    return;
}

define('WMP_VERSION',     '1.4.0');
define('WMP_PLUGIN_FILE', __FILE__);
define('WMP_PLUGIN_DIR',  plugin_dir_path(__FILE__));
define('WMP_PLUGIN_URL',  plugin_dir_url(__FILE__));

if (defined('WMP_BACKUP_PATH') && is_string(WMP_BACKUP_PATH)) {
    define('WMP_BACKUP_DIR', trailingslashit(WMP_BACKUP_PATH));
} else {
    define('WMP_BACKUP_DIR', WP_CONTENT_DIR . '/wmp-backups/');
}

if (! defined('WMP_MAX_FILE_SIZE')) {
    define('WMP_MAX_FILE_SIZE', 2 * 1024 * 1024 * 1024);
}

require_once WMP_PLUGIN_DIR . 'includes/class-wmp-compat.php';
require_once WMP_PLUGIN_DIR . 'includes/class-wmp-progress.php';
require_once WMP_PLUGIN_DIR . 'includes/class-wmp-runner.php';
require_once WMP_PLUGIN_DIR . 'includes/class-wmp-backup.php';
require_once WMP_PLUGIN_DIR . 'includes/class-wmp-import.php';
require_once WMP_PLUGIN_DIR . 'includes/class-wmp-ajax.php';
require_once WMP_PLUGIN_DIR . 'includes/class-wmp-admin.php';

register_activation_hook(__FILE__, array('WMP_Admin', 'activate'));
register_deactivation_hook(__FILE__, array('WMP_Admin', 'deactivate'));

function wmp_plugins_loaded()
{
    WMP_Admin::init();
    WMP_Ajax::init();
}
add_action('plugins_loaded', 'wmp_plugins_loaded');
