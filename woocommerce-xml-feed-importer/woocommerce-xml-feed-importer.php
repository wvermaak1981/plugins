<?php
/**
 * Plugin Name: WooCommerce XML Feed Importer
 * Description: Scheduled XML product imports with multiple feeds, customizable XML field mappings, namespaces, variable products, attributes, and an import log.
 * Version: 3.1.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * Text Domain: wpfi
 */
if (!defined('ABSPATH')) exit;

define('WPFI_VERSION', '3.1.0');
define('WPFI_FILE', __FILE__);
define('WPFI_DIR', plugin_dir_path(__FILE__));
define('WPFI_URL', plugin_dir_url(__FILE__));

require_once WPFI_DIR . 'includes/class-wpfi-feed-repository.php';
require_once WPFI_DIR . 'includes/class-wpfi-logger.php';
require_once WPFI_DIR . 'includes/class-wpfi-scheduler.php';
require_once WPFI_DIR . 'includes/class-wpfi-importer.php';
require_once WPFI_DIR . 'includes/class-wpfi-admin.php';
require_once WPFI_DIR . 'includes/class-wpfi-plugin.php';

register_activation_hook(__FILE__, ['WPFI_Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['WPFI_Plugin', 'deactivate']);

add_action('plugins_loaded', static function () {
    WPFI_Plugin::instance()->boot();
});
