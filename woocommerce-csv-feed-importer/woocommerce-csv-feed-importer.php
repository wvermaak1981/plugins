<?php
/**
 * Plugin Name: WooCommerce CSV Product Feed Importer
 * Description: Preview-first WooCommerce CSV product importer with editable column mapping, product status/category controls and dry-run summaries.
 * Version: 1.3.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 */
if (!defined('ABSPATH')) { exit; }

final class WC_CSV_Product_Feed_Importer {
    private static $instance;
    private $preview_limit = 100;
    private $fields = ['name' => 'ProductName', 'sku' => 'ProductCode', 'category' => 'Category', 'description' => 'ProductSummary', 'price' => 'Price', 'stock' => 'AvailableQty', 'image' => 'Image'];

    public static function instance() {
        if (!self::$instance) { self::$instance = new self(); }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_post_wc_csv_product_feed_import', [$this, 'handle_import']);
        add_action('admin_post_wc_csv_product_feed_confirm', [$this, 'confirm_import']);
    }

    public function admin_menu() {
        add_submenu_page('woocommerce', 'CSV Feed Importer', 'CSV Feed Importer', 'manage_woocommerce', 'wc-csv-feed-importer', [$this, 'render_page']);
    }

    public function render_page() {
        if (!current_user_can('manage_woocommerce')) { return; }
        $token = isset($_GET['preview']) ? sanitize_key(wp_unslash($_GET['preview'])) : '';
        $notice = isset($_GET['notice']) ? sanitize_key(wp_unslash($_GET['notice'])) : '';
        echo '<div class="wrap"><h1>WooCommerce CSV Product Feed Importer</h1>';
        if ('success' === $notice) echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf('%d product(s) imported successfully.', absint($_GET['imported'] ?? 0))) . '</p></div>';
        if ('partial' === $notice) echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html(sprintf('%d product(s) imported; some rows failed. Check the PHP error log.', absint($_GET['imported'] ?? 0))) . '</p></div>';
        if ('error' === $notice) echo '<div class="notice notice-error is-dismissible"><p>No readable CSV data was found. Check the file, URL and mapping.</p></div>';
        if ($token) { $this->render_preview($token); } else { $this->render_upload_form(); }
        echo '</div>';
    }

    private function render_upload_form() {
        echo '<p>Upload a CSV or provide a URL, then review the dry-run summary before saving products.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" enctype="multipart/form-data">';
        wp_nonce_field('wc_csv_product_feed_import');
        echo '<input type="hidden" name="action" value="wc_csv_product_feed_import">';
        echo '<table class="form-table"><tbody>';
        echo '<tr><th><label for="csv_feed_url">CSV Feed URL</label></th><td><input type="url" class="regular-text" id="csv_feed_url" name="csv_feed_url" placeholder="https://example.com/products.csv"><p class="description">Use this or upload a file.</p></td></tr>';
        echo '<tr><th><label for="csv_feed_file">Upload CSV File</label></th><td><input type="file" id="csv_feed_file" name="csv_feed_file" accept=".csv,text/csv"></td></tr>';
        echo '<tr><th>Column mapping</th><td><p class="description">Enter the exact CSV header for each WooCommerce field. Defaults match the supplied feed.</p>';
        foreach ($this->fields as $field => $default) {
            echo '<p><label style="display:inline-block;width:170px;" for="map_' . esc_attr($field) . '">' . esc_html(ucwords(str_replace('_', ' ', $field))) . '</label><input type="text" name="mapping[' . esc_attr($field) . ']" id="map_' . esc_attr($field) . '" value="' . esc_attr($default) . '" class="regular-text"></p>';
        }
        echo '</td></tr>';
        echo '<tr><th><label for="default_category">Default product category</label></th><td><input type="text" class="regular-text" id="default_category" name="default_category" placeholder="Imported Products"><p class="description">Used when the mapped category column is empty.</p></td></tr>';
        echo '<tr><th><label for="product_status">Product status</label></th><td><select name="product_status" id="product_status">';
        foreach (['publish' => 'Published', 'draft' => 'Draft', 'pending' => 'Pending', 'private' => 'Private'] as $value => $label) echo '<option value="' . esc_attr($value) . '">' . esc_html($label) . '</option>';
        echo '</select></td></tr>';
        echo '<tr><th><label for="preview_limit">Preview rows</label></th><td><input type="number" min="1" max="1000" class="small-text" id="preview_limit" name="preview_limit" value="100"><p class="description">Controls how many rows are displayed. All valid rows are still considered for import.</p></td></tr>';
        echo '</tbody></table>';
        submit_button('Preview Products');
        echo '</form>';
    }

    private function render_preview($token) {
        $payload = get_transient($this->preview_key($token));
        if (!is_array($payload) || empty($payload['rows'])) { echo '<div class="notice notice-error"><p>This preview has expired. Please start again.</p></div>'; return; }
        $rows = $payload['rows']; $summary = $payload['summary']; $limit = max(1, min(1000, absint($payload['preview_limit'] ?? 100)));
        echo '<h2>Import Preview</h2><p>Showing ' . esc_html(min(count($rows), $limit)) . ' of ' . esc_html(count($rows)) . ' row(s). No products have been saved.</p>';
        echo '<p><strong>Status:</strong> ' . esc_html($payload['status']) . ' &nbsp; <strong>Default category:</strong> ' . esc_html($payload['default_category'] ?: 'None') . '</p>';
        echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin:20px 0;">';
        foreach (['Rows skipped' => 'skipped', 'Duplicate SKUs' => 'duplicate_skus', 'Invalid prices' => 'invalid_prices', 'Missing image URLs' => 'missing_images', 'Would create' => 'create_count', 'Would update' => 'update_count'] as $label => $key) {
            echo '<div style="background:#fff;border:1px solid #dcdcde;padding:12px;border-radius:4px;"><div style="font-size:11px;text-transform:uppercase;color:#50575e;">' . esc_html($label) . '</div><div style="font-size:28px;font-weight:700;">' . esc_html((string) ($summary[$key] ?? 0)) . '</div></div>';
        }
        echo '</div><table class="widefat striped"><thead><tr><th>#</th><th>Product</th><th>SKU</th><th>Category</th><th>Price</th><th>Quantity</th><th>Image</th></tr></thead><tbody>';
        foreach (array_slice($rows, 0, $limit) as $index => $row) {
            echo '<tr><td>' . esc_html($index + 1) . '</td><td>' . esc_html($row['name'] ?? '') . '</td><td>' . esc_html($row['sku'] ?? '') . '</td><td>' . esc_html($row['category'] ?? '') . '</td><td>' . esc_html($this->decimal($row['price'] ?? '')) . '</td><td>' . esc_html($row['stock'] ?? '') . '</td><td>' . ($this->valid_image_url($row['image'] ?? '') ? 'Yes' : 'No') . '</td></tr>';
        }
        echo '</tbody></table><p><strong>Confirming will create/update products, categories and images.</strong></p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:8px;">';
        wp_nonce_field('wc_csv_product_feed_confirm_' . $token);
        echo '<input type="hidden" name="action" value="wc_csv_product_feed_confirm"><input type="hidden" name="preview_token" value="' . esc_attr($token) . '">';
        submit_button('Confirm Import', 'primary', 'submit', false);
        echo '</form> <a class="button" href="' . esc_url(admin_url('admin.php?page=wc-csv-feed-importer')) . '">Cancel</a>';
    }

    public function handle_import() {
        if (!current_user_can('manage_woocommerce')) { wp_die('Permission denied.'); }
        check_admin_referer('wc_csv_product_feed_import');
        $mapping = $this->sanitize_mapping($_POST['mapping'] ?? []);
        $rows = [];
        $file = $_FILES['csv_feed_file'] ?? [];
        if (!empty($file['error']) && UPLOAD_ERR_NO_FILE !== (int) $file['error']) wp_die('CSV upload failed. Error code: ' . absint($file['error']));
        if (!empty($file['tmp_name']) && is_uploaded_file($file['tmp_name'])) {
            $rows = $this->read_csv($file['tmp_name'], $mapping);
        } else {
            $url = isset($_POST['csv_feed_url']) ? esc_url_raw(wp_unslash($_POST['csv_feed_url'])) : '';
            if ($url) {
                $response = wp_safe_remote_get($url, ['timeout' => 120, 'redirection' => 3]);
                if (is_wp_error($response)) wp_die(esc_html('Unable to fetch CSV: ' . $response->get_error_message()));
                if (200 !== (int) wp_remote_retrieve_response_code($response)) wp_die('The CSV URL returned an HTTP error.');
                $tmp = wp_tempnam('wc-csv-feed-import');
                if (!$tmp || false === file_put_contents($tmp, wp_remote_retrieve_body($response))) wp_die('Unable to create a temporary CSV file.');
                $rows = $this->read_csv($tmp, $mapping); @unlink($tmp);
            }
        }
        if (!$rows) { $this->redirect(['notice' => 'error']); }
        $status = sanitize_key($_POST['product_status'] ?? 'publish');
        if (!in_array($status, ['publish', 'draft', 'pending', 'private'], true)) $status = 'publish';
        $default_category = sanitize_text_field(wp_unslash($_POST['default_category'] ?? ''));
        $preview_limit = max(1, min(1000, absint($_POST['preview_limit'] ?? 100)));
        $token = wp_generate_password(32, false, false);
        set_transient($this->preview_key($token), ['rows' => $rows, 'summary' => $this->dry_run_summary($rows, $default_category), 'mapping' => $mapping, 'status' => $status, 'default_category' => $default_category, 'preview_limit' => $preview_limit], HOUR_IN_SECONDS);
        $this->redirect(['preview' => $token]);
    }

    public function confirm_import() {
        if (!current_user_can('manage_woocommerce')) { wp_die('Permission denied.'); }
        $token = sanitize_key(wp_unslash($_POST['preview_token'] ?? ''));
        if (!$token) wp_die('Missing preview token.');
        check_admin_referer('wc_csv_product_feed_confirm_' . $token);
        $key = $this->preview_key($token); $payload = get_transient($key); delete_transient($key);
        if (!is_array($payload) || empty($payload['rows'])) $this->redirect(['notice' => 'error']);
        $result = $this->import_rows($payload['rows'], $payload['status'], $payload['default_category']);
        $this->redirect(['notice' => $result['failed'] ? 'partial' : 'success', 'imported' => $result['imported']]);
    }

    private function sanitize_mapping($mapping) {
        $result = $this->fields;
        foreach ($result as $field => $default) if (isset($mapping[$field])) $result[$field] = sanitize_text_field(wp_unslash($mapping[$field]));
        return $result;
    }
    private function preview_key($token) { return 'wc_csv_preview_' . get_current_user_id() . '_' . sanitize_key($token); }
    private function redirect($args) { wp_safe_redirect(add_query_arg(array_merge(['page' => 'wc-csv-feed-importer'], $args), admin_url('admin.php'))); exit; }

    private function dry_run_summary($rows, $default_category) {
        $summary = ['skipped' => 0, 'duplicate_skus' => 0, 'invalid_prices' => 0, 'missing_images' => 0, 'create_count' => 0, 'update_count' => 0]; $seen = [];
        foreach ($rows as $row) {
            $name = trim((string) ($row['name'] ?? '')); $sku = strtolower(trim((string) ($row['sku'] ?? ''))); $price = trim((string) ($row['price'] ?? '')); $image = trim((string) ($row['image'] ?? ''));
            if (!$name) { $summary['skipped']++; continue; }
            if ($sku && in_array($sku, $seen, true)) { $summary['duplicate_skus']++; continue; }
            if ($sku) $seen[] = $sku;
            if ($price !== '' && $this->decimal($price) === '') { $summary['invalid_prices']++; continue; }
            if (!$image || !$this->valid_image_url($image)) $summary['missing_images']++;
            $existing = $sku && function_exists('wc_get_product_id_by_sku') ? wc_get_product_id_by_sku($sku) : 0;
            $existing ? $summary['update_count']++ : $summary['create_count']++;
        }
        return $summary;
    }

    private function read_csv($path, $mapping) {
        if (!is_readable($path) || false === ($handle = fopen($path, 'rb'))) return [];
        $headers = fgetcsv($handle, 0, ',', '"'); if (!$headers) { fclose($handle); return []; }
        $normalized = array_map([$this, 'normalize_header'], $headers); $rows = [];
        while (false !== ($line = fgetcsv($handle, 0, ',', '"'))) {
            if (!$line || (1 === count($line) && '' === trim((string) $line[0]))) continue;
            $row = [];
            foreach ($mapping as $field => $header) {
                $wanted = $this->normalize_header($header); $index = array_search($wanted, $normalized, true); $row[$field] = false !== $index && isset($line[$index]) ? trim((string) $line[$index]) : '';
            }
            $rows[] = $row;
        }
        fclose($handle); return $rows;
    }
    private function normalize_header($value) { return strtolower(preg_replace('/[^a-z0-9]+/', '', (string) $value)); }

    private function import_rows($rows, $status, $default_category) {
        $imported = 0; $failed = 0;
        foreach ($rows as $row) { try { if ($this->import_row($row, $status, $default_category)) $imported++; else $failed++; } catch (Throwable $e) { $failed++; error_log('CSV Feed Importer: ' . $e->getMessage()); } }
        return ['imported' => $imported, 'failed' => $failed];
    }

    private function import_row($row, $status, $default_category) {
        $name = trim((string) ($row['name'] ?? '')); if (!$name || !function_exists('wc_get_product')) return false;
        $sku = trim((string) ($row['sku'] ?? '')); $product_id = $sku && function_exists('wc_get_product_id_by_sku') ? (int) wc_get_product_id_by_sku($sku) : 0; $product = $product_id ? wc_get_product($product_id) : new WC_Product_Simple();
        if (!$product || !is_a($product, 'WC_Product')) return false; if (!$sku) $sku = sanitize_title($name) . '-' . wp_rand(1000, 9999);
        $product->set_name($name); $product->set_status($status); $product->set_catalog_visibility('visible'); $product->set_sku($sku);
        $description = trim((string) ($row['description'] ?? '')); if ($description) { $product->set_description(wp_kses_post($description)); $product->set_short_description(wp_trim_words(wp_strip_all_tags($description), 30)); }
        $price = $this->decimal($row['price'] ?? ''); if ($price !== '') { $product->set_regular_price($price); $product->set_price($price); }
        $stock = max(0, (int) preg_replace('/[^0-9-]/', '', (string) ($row['stock'] ?? '0'))); $product->set_manage_stock(true); $product->set_stock_quantity($stock); $product->set_stock_status($stock > 0 ? 'instock' : 'outofstock'); $product->save(); $id = $product->get_id();
        $category_text = trim((string) ($row['category'] ?? '')); if (!$category_text) $category_text = $default_category; $term_ids = [];
        foreach (preg_split('/\s*[|,>\/]+\s*/', $category_text, -1, PREG_SPLIT_NO_EMPTY) as $category) { $term = term_exists(trim($category), 'product_cat'); if (!$term) $term = wp_insert_term(trim($category), 'product_cat'); if (!is_wp_error($term)) $term_ids[] = (int) (is_array($term) ? $term['term_id'] : $term); }
        if ($term_ids) wp_set_object_terms($id, $term_ids, 'product_cat', false);
        if (!empty($row['image'])) { $image_id = $this->import_image($row['image'], $id); if ($image_id) { $product->set_image_id($image_id); $product->save(); } }
        return true;
    }
    private function valid_image_url($url) { return is_string($url) && $url !== '' && (bool) filter_var($url, FILTER_VALIDATE_URL); }
    private function import_image($url, $product_id) { require_once ABSPATH . 'wp-admin/includes/media.php'; require_once ABSPATH . 'wp-admin/includes/file.php'; require_once ABSPATH . 'wp-admin/includes/image.php'; $id = media_sideload_image(esc_url_raw($url), $product_id, null, 'id'); return is_wp_error($id) ? 0 : (int) $id; }
    private function decimal($value) { $value = preg_replace('/[^0-9.\-]/', '', str_replace(',', '', (string) $value)); return (!$value || '-' === $value || '.' === $value) ? '' : number_format((float) $value, 2, '.', ''); }
}
add_action('plugins_loaded', static function () { if (class_exists('WooCommerce')) WC_CSV_Product_Feed_Importer::instance(); });
