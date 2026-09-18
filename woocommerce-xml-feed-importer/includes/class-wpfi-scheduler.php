<?php
if (!defined('ABSPATH')) exit;

final class WPFI_Scheduler {
    const HOOK = 'wpfi_import_feed';
    private $repo;
    private $importer;

    public function __construct($repo, $importer) {
        $this->repo = $repo; $this->importer = $importer;
        add_filter('cron_schedules', [$this, 'schedules']);
        add_action(self::HOOK, [$this, 'run'], 10, 1);
    }
    public function schedules($schedules) {
        $schedules['wpfi_15m'] = ['interval'=>900, 'display'=>__('Every 15 minutes','wpfi')];
        $schedules['wpfi_30m'] = ['interval'=>1800, 'display'=>__('Every 30 minutes','wpfi')];
        $schedules['wpfi_6h'] = ['interval'=>21600, 'display'=>__('Every 6 hours','wpfi')];
        return $schedules;
    }
    public function clear() { foreach ($this->repo->all() as $feed) wp_clear_scheduled_hook(self::HOOK, [$feed['id'] ?? '']); }
    public function sync() {
        $this->clear();
        foreach ($this->repo->all() as $feed) {
            if (!empty($feed['enabled']) && !empty($feed['url']) && !empty($feed['id']) && wp_get_schedules()[$feed['frequency'] ?? 'daily'] ?? false) {
                wp_schedule_event(time() + 120, $feed['frequency'], self::HOOK, [$feed['id']]);
            }
        }
    }
    public function run($id) { $feed = $this->repo->get($id); if ($feed && !empty($feed['enabled'])) $this->importer->import($feed); }
}
