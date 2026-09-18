<?php
if (!defined('ABSPATH')) exit;

final class WPFI_Logger {
    const OPTION = 'wpfi_import_logs';
    const LIMIT = 500;

    public function write($feed, $level, $message, array $context = []) {
        $logs = get_option(self::OPTION, []);
        if (!is_array($logs)) $logs = [];
        array_unshift($logs, [
            'time' => current_time('mysql'), 'feed' => (string)($feed['name'] ?? ''),
            'slug' => (string)($feed['slug'] ?? ''), 'level' => sanitize_key($level),
            'message' => wp_strip_all_tags($message), 'context' => $context,
        ]);
        update_option(self::OPTION, array_slice($logs, 0, self::LIMIT), false);
        if (function_exists('wc_get_logger')) wc_get_logger()->log($level, $message, ['source' => 'wpfi-' . ($feed['slug'] ?? 'importer')]);
    }

    public function all($limit = 200) {
        $logs = get_option(self::OPTION, []);
        return is_array($logs) ? array_slice($logs, 0, absint($limit)) : [];
    }

    public function clear() { delete_option(self::OPTION); }
}
