<?php
/**
 * Plugin Name: WooCommerce XML Feed Importer
 * Description: Scheduled XML product imports with configurable authentication, field mappings, namespaces, and variable products.
 * Version: 3.2.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 */
if (!defined('ABSPATH')) exit;
define('WPFI_VERSION','3.2.0');
define('WPFI_FILE',__FILE__);
define('WPFI_DIR',plugin_dir_path(__FILE__));
require_once WPFI_DIR.'includes/class-wpfi-feed-repository.php';
require_once WPFI_DIR.'includes/class-wpfi-logger.php';
require_once WPFI_DIR.'includes/class-wpfi-importer.php';
require_once WPFI_DIR.'includes/class-wpfi-scheduler.php';
require_once WPFI_DIR.'includes/class-wpfi-admin.php';
require_once WPFI_DIR.'includes/class-wpfi-plugin.php';
register_activation_hook(__FILE__,['WPFI_Plugin','activate']);
register_deactivation_hook(__FILE__,['WPFI_Plugin','deactivate']);
add_action('plugins_loaded',static function(){ WPFI_Plugin::instance()->boot(); });
