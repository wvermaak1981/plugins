<?php
if (!defined('ABSPATH')) exit;

final class WPFI_Feed_Repository {
    const OPTION = 'wpfi_feeds_v3';

    public function all() {
        $feeds = get_option(self::OPTION, []);
        return is_array($feeds) ? array_values($feeds) : [];
    }

    public function get($id) {
        foreach ($this->all() as $feed) {
            if (($feed['id'] ?? '') === $id) return $feed;
        }
        return null;
    }

    public function save(array $feed) {
        $feeds = $this->all();
        $feed['id'] = $feed['id'] ?: wp_generate_uuid4();
        $found = false;
        foreach ($feeds as $index => $existing) {
            if (($existing['id'] ?? '') === $feed['id']) {
                $feeds[$index] = $feed;
                $found = true;
                break;
            }
        }
        if (!$found) $feeds[] = $feed;
        update_option(self::OPTION, array_values($feeds), false);
        return $feed['id'];
    }

    public function delete($id) {
        $feeds = array_filter($this->all(), static function ($feed) use ($id) {
            return ($feed['id'] ?? '') !== $id;
        });
        update_option(self::OPTION, array_values($feeds), false);
    }

    public function defaults() {
        return [
            'id' => '', 'name' => '', 'slug' => '', 'url' => '', 'enabled' => 1,
            'frequency' => 'daily', 'product_xpath' => '/products/product',
            'variation_xpath' => '', 'identifier_field' => 'sku',
            'variation_identifier_field' => 'sku', 'map' => [], 'attributes' => [], 'namespaces' => [],
        ];
    }
}
