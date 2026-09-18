<?php
if (!defined('ABSPATH')) exit;

final class WPFI_Plugin {
    private static $instance;
    public static function instance() { return self::$instance ?: self::$instance = new self(); }
    public static function activate() { if (!get_option(WPFI_Feed_Repository::OPTION)) update_option(WPFI_Feed_Repository::OPTION, [], false); flush_rewrite_rules(); }
    public static function deactivate() { wp_clear_scheduled_hook(WPFI_Scheduler::HOOK); }
    public function boot() {
        if (!class_exists('WooCommerce')) { add_action('admin_notices', static function(){ echo '<div class="notice notice-warning"><p>WooCommerce XML Feed Importer requires WooCommerce.</p></div>'; }); return; }
        $repo=new WPFI_Feed_Repository(); $logger=new WPFI_Logger(); $importer=new WPFI_Importer($logger); $scheduler=new WPFI_Scheduler($repo,$importer); new WPFI_Admin($repo,$scheduler,$importer,$logger);
    }
}
