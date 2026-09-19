<?php
/**
 * Plugin Name: WooCommerce CSV Product Feed Importer
 * Description: Imports WooCommerce products from CSV feeds using ProductName, ProductCode, Category, ProductSummary, Price, AvailableQty and Image columns.
 * Version: 1.1.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 */
if (!defined('ABSPATH')) { exit; }

final class WC_CSV_Product_Feed_Importer {
    private static $instance;
    private $preview_limit = 100;

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
        $notice = isset($_GET['notice']) ? sanitize_key(wp_unslash($_GET['notice'])) : '';
        $preview_token = isset($_GET['preview']) ? sanitize_key(wp_unslash($_GET['preview'])) : '';
        ?>
        <div class="wrap">
            <h1>WooCommerce CSV Product Feed Importer</h1>
            <?php if ('success' === $notice) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html(sprintf('%d product(s) imported successfully.', absint($_GET['imported'] ?? 0))); ?></p></div>
            <?php elseif ('error' === $notice) : ?>
                <div class="notice notice-error is-dismissible"><p>No readable CSV data was found. Check the file, URL and CSV header.</p></div>
            <?php elseif ('partial' === $notice) : ?>
                <div class="notice notice-warning is-dismissible"><p><?php echo esc_html(sprintf('%d product(s) imported. Some rows were skipped; check the PHP/WooCommerce error log for details.', absint($_GET['imported'] ?? 0))); ?></p></div>
            <?php endif; ?>

            <?php if ($preview_token) : $this->render_preview($preview_token); else : ?>
                <p>Expected columns: <code>ProductName, ProductCode, Category, ProductSummary, Price, AvailableQty, Image</code></p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                    <?php wp_nonce_field('wc_csv_product_feed_import'); ?>
                    <input type="hidden" name="action" value="wc_csv_product_feed_import">
                    <table class="form-table"><tbody>
                        <tr><th><label for="csv_feed_url">CSV Feed URL</label></th><td><input type="url" class="regular-text" id="csv_feed_url" name="csv_feed_url" placeholder="https://example.com/products.csv"><p class="description">Use this or upload a file below.</p></td></tr>
                        <tr><th><label for="csv_feed_file">Upload CSV File</label></th><td><input type="file" id="csv_feed_file" name="csv_feed_file" accept=".csv,text/csv"></td></tr>
                    </tbody></table>
                    <?php submit_button('Preview Products', 'primary', 'submit', false); ?>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_preview($token) {
        $rows = get_transient($this->preview_key($token));
        if (!is_array($rows) || !$rows) {
            echo '<div class="notice notice-error"><p>This preview has expired. Please upload or fetch the CSV again.</p></div>';
            return;
        }
        $shown = min(count($rows), $this->preview_limit);
        echo '<h2>Import Preview</h2><p>Reviewing ' . esc_html($shown) . ' of ' . esc_html(count($rows)) . ' row(s). No products have been saved yet.</p>';
        echo '<table class="widefat striped"><thead><tr><th>#</th><th>Product</th><th>SKU</th><th>Category</th><th>Price</th><th>Quantity</th><th>Image</th></tr></thead><tbody>';
        foreach (array_slice($rows, 0, $this->preview_limit) as $index => $row) {
            echo '<tr><td>' . esc_html($index + 1) . '</td><td>' . esc_html($row['name'] ?? '') . '</td><td>' . esc_html($row['sku'] ?? '') . '</td><td>' . esc_html($row['category'] ?? '') . '</td><td>' . esc_html($this->decimal($row['price'] ?? '')) . '</td><td>' . esc_html($row['stock'] ?? '') . '</td><td>' . ($this->valid_image_url($row['image'] ?? '') ? 'Yes' : 'No') . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<p><strong>Important:</strong> Confirming will create or update products, categories and featured images.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:8px;">';
        wp_nonce_field('wc_csv_product_feed_confirm_' . $token);
        echo '<input type="hidden" name="action" value="wc_csv_product_feed_confirm"><input type="hidden" name="preview_token" value="' . esc_attr($token) . '">';
        submit_button('Confirm Import', 'primary', 'submit', false);
        echo '</form> <a class="button" href="' . esc_url(admin_url('admin.php?page=wc-csv-feed-importer')) . '">Cancel</a>';
    }

    public function handle_import() {
        if (!current_user_can('manage_woocommerce')) { wp_die('Permission denied.'); }
        check_admin_referer('wc_csv_product_feed_import');
        $rows = [];
        $file = isset($_FILES['csv_feed_file']) ? $_FILES['csv_feed_file'] : [];
        if (!empty($file['error']) && UPLOAD_ERR_NO_FILE !== (int) $file['error']) { wp_die('CSV upload failed. Error code: ' . absint($file['error'])); }
        if (!empty($file['tmp_name']) && is_uploaded_file($file['tmp_name'])) {
            $rows = $this->read_csv($file['tmp_name']);
        } else {
            $url = isset($_POST['csv_feed_url']) ? esc_url_raw(wp_unslash($_POST['csv_feed_url'])) : '';
            if ($url) {
                $response = wp_safe_remote_get($url, ['timeout' => 120, 'redirection' => 3]);
                if (is_wp_error($response)) { wp_die(esc_html('Unable to fetch CSV: ' . $response->get_error_message())); }
                if (200 !== (int) wp_remote_retrieve_response_code($response)) { wp_die('The CSV URL returned an HTTP error.'); }
                $tmp = wp_tempnam('wc-csv-feed-import');
                if (!$tmp || false === file_put_contents($tmp, wp_remote_retrieve_body($response))) { wp_die('Unable to create a temporary CSV file.'); }
                $rows = $this->read_csv($tmp);
                @unlink($tmp);
            }
        }
        if (!$rows) { $this->redirect(['notice' => 'error']); }
        $token = wp_generate_password(32, false, false);
        set_transient($this->preview_key($token), $rows, HOUR_IN_SECONDS);
        $this->redirect(['preview' => $token]);
    }

    public function confirm_import() {
        if (!current_user_can('manage_woocommerce')) { wp_die('Permission denied.'); }
        $token = isset($_POST['preview_token']) ? sanitize_key(wp_unslash($_POST['preview_token'])) : '';
        if (!$token) { wp_die('Missing preview token.'); }
        check_admin_referer('wc_csv_product_feed_confirm_' . $token);
        $key = $this->preview_key($token);
        $rows = get_transient($key);
        delete_transient($key);
        if (!is_array($rows) || !$rows) { $this->redirect(['notice' => 'error']); }
        $result = $this->import_rows($rows);
        $this->redirect(['notice' => $result['failed'] ? 'partial' : 'success', 'imported' => $result['imported']]);
    }

    private function preview_key($token) { return 'wc_csv_preview_' . get_current_user_id() . '_' . sanitize_key($token); }

    private function redirect($args) {
        wp_safe_redirect(add_query_arg(array_merge(['page' => 'wc-csv-feed-importer'], $args), admin_url('admin.php')));
        exit;
    }

    private function read_csv($path) {
        if (!is_readable($path) || false === ($handle = fopen($path, 'rb'))) { return []; }
        $header = null; $rows = [];
        while (false !== ($row = fgetcsv($handle, 0, ',', '"'))) {
            if (!$row || (1 === count($row) && '' === trim((string) $row[0]))) { continue; }
            if (null === $header) { $header = array_map([$this, 'header_key'], $row); continue; }
            $item = [];
            foreach ($header as $i => $key) { if ($key) { $item[$key] = isset($row[$i]) ? trim((string) $row[$i]) : ''; } }
            if ($item) { $rows[] = $item; }
        }
        fclose($handle);
        return $rows;
    }

    private function header_key($header) {
        $key = strtolower(preg_replace('/[^a-z0-9]+/', '', (string) $header));
        $map = ['productname'=>'name','productcode'=>'sku','category'=>'category','productsummary'=>'description','price'=>'price','availableqty'=>'stock','image'=>'image'];
        return isset($map[$key]) ? $map[$key] : $key;
    }

    private function import_rows($rows) {
        $imported = 0; $failed = 0;
        foreach ($rows as $row) {
            try { if ($this->import_row($row)) { $imported++; } else { $failed++; } }
            catch (Throwable $e) { $failed++; error_log('CSV Feed Importer: ' . $e->getMessage()); }
        }
        return ['imported' => $imported, 'failed' => $failed];
    }

    private function import_row($row) {
        $name = trim((string) ($row['name'] ?? ''));
        if ('' === $name || !function_exists('wc_get_product')) { return false; }
        $sku = trim((string) ($row['sku'] ?? ''));
        $product_id = $sku && function_exists('wc_get_product_id_by_sku') ? (int) wc_get_product_id_by_sku($sku) : 0;
        $product = $product_id ? wc_get_product($product_id) : new WC_Product_Simple();
        if (!$product || !is_a($product, 'WC_Product')) { return false; }
        if ('' === $sku) { $sku = sanitize_title($name) . '-' . wp_rand(1000, 9999); }
        $product->set_name($name); $product->set_status('publish'); $product->set_catalog_visibility('visible'); $product->set_sku($sku);
        $description = trim((string) ($row['description'] ?? ''));
        if ($description !== '') { $product->set_description(wp_kses_post($description)); $product->set_short_description(wp_trim_words(wp_strip_all_tags($description), 30)); }
        $price = $this->decimal($row['price'] ?? '');
        if ($price !== '') { $product->set_regular_price($price); $product->set_price($price); }
        $stock = max(0, (int) preg_replace('/[^0-9-]/', '', (string) ($row['stock'] ?? '0')));
        $product->set_manage_stock(true); $product->set_stock_quantity($stock); $product->set_stock_status($stock > 0 ? 'instock' : 'outofstock'); $product->save();
        $id = $product->get_id();
        $categories = preg_split('/\s*[|,>\/]+\s*/', (string) ($row['category'] ?? ''), -1, PREG_SPLIT_NO_EMPTY); $term_ids = [];
        foreach ($categories as $category) { $category = trim($category); if (!$category) { continue; } $term = term_exists($category, 'product_cat'); if (!$term) { $term = wp_insert_term($category, 'product_cat'); } if (!is_wp_error($term)) { $term_ids[] = (int) (is_array($term) ? $term['term_id'] : $term); } }
        if ($term_ids) { wp_set_object_terms($id, $term_ids, 'product_cat', false); }
        if (!empty($row['image'])) { $image_id = $this->import_image($row['image'], $id); if ($image_id) { $product->set_image_id($image_id); $product->save(); } }
        return true;
    }

    private function valid_image_url($url) { return (bool) filter_var($url, FILTER_VALIDATE_URL); }
    private function import_image($url, $product_id) { require_once ABSPATH . 'wp-admin/includes/media.php'; require_once ABSPATH . 'wp-admin/includes/file.php'; require_once ABSPATH . 'wp-admin/includes/image.php'; $id = media_sideload_image(esc_url_raw($url), $product_id, null, 'id'); return is_wp_error($id) ? 0 : (int) $id; }
    private function decimal($value) { $value = preg_replace('/[^0-9.\-]/', '', str_replace(',', '', (string) $value)); return ('' === $value || '-' === $value || '.' === $value) ? '' : number_format((float) $value, 2, '.', ''); }
}

add_action('plugins_loaded', static function () { if (class_exists('WooCommerce')) { WC_CSV_Product_Feed_Importer::instance(); } });
