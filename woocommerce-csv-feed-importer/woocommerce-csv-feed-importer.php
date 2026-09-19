<?php
/**
 * Plugin Name: WooCommerce CSV Product Feed Importer
 * Description: Imports CSV product feeds using the supplied DataFeed mapping template: ProductName, ProductCode, Category, ProductSummary, Price, AvailableQty, Image.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

final class WC_CSV_Product_Feed_Importer {
    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function __construct() {
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_post_wc_csv_product_feed_import', [$this, 'handle_import']);
    }

    public function admin_menu() {
        add_submenu_page(
            'woocommerce',
            'CSV Feed Importer',
            'CSV Feed Importer',
            'manage_woocommerce',
            'wc-csv-feed-importer',
            [$this, 'render_page']
        );
    }

    public function render_page() {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $imported = isset($_GET['imported']) ? absint($_GET['imported']) : 0;
        ?>
        <div class="wrap">
            <h1>WooCommerce CSV Product Feed Importer</h1>

            <?php if ($imported) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php echo esc_html(sprintf('%d product(s) imported successfully.', $imported)); ?></p>
                </div>
            <?php endif; ?>

            <p>This importer uses the supplied CSV template with the following default mapping:</p>

            <pre><code>ProductName = name
ProductCode = sku
Category = product_cat
ProductSummary = description
Price = regular_price
AvailableQty = stock_quantity
Image = image</code></pre>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                <?php wp_nonce_field('wc_csv_product_feed_import'); ?>
                <input type="hidden" name="action" value="wc_csv_product_feed_import" />

                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="csv_feed_url">CSV Feed URL</label></th>
                            <td>
                                <input type="url" id="csv_feed_url" name="csv_feed_url" class="regular-text" value="" placeholder="https://example.com/products.csv" />
                                <p class="description">Optional. If left blank, upload a CSV file below.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="csv_feed_file">Upload CSV File</label></th>
                            <td>
                                <input type="file" id="csv_feed_file" name="csv_feed_file" accept=".csv,text/csv" />
                                <p class="description">Use the attached CSV as the template and upload it here.</p>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <?php submit_button('Import Products'); ?>
            </form>
        </div>
        <?php
    }

    public function handle_import() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Permission denied.');
        }

        check_admin_referer('wc_csv_product_feed_import');

        $rows = [];
        $url = isset($_POST['csv_feed_url']) ? esc_url_raw(wp_unslash($_POST['csv_feed_url'])) : '';
        $uploaded_file = $_FILES['csv_feed_file'] ?? [];

        if (!empty($uploaded_file['tmp_name'])) {
            $rows = $this->read_csv_file($uploaded_file['tmp_name']);
        } elseif (!empty($url)) {
            $response = wp_remote_get($url, ['timeout' => 120]);

            if (is_wp_error($response)) {
                wp_die('Unable to fetch the CSV feed: ' . esc_html($response->get_error_message()));
            }

            $body = wp_remote_retrieve_body($response);
            if (empty($body)) {
                wp_die('The remote CSV feed is empty.');
            }

            $tmp = wp_tempnam('wc-csv-feed-import');
            file_put_contents($tmp, $body);
            $rows = $this->read_csv_file($tmp);
            @unlink($tmp);
        }

        if (empty($rows)) {
            wp_safe_redirect(add_query_arg(['page' => 'wc-csv-feed-importer', 'error' => 1], admin_url('admin.php')));
            exit;
        }

        $count = $this->import_rows($rows);

        wp_safe_redirect(add_query_arg(['page' => 'wc-csv-feed-importer', 'imported' => $count], admin_url('admin.php')));
        exit;
    }

    private function read_csv_file($path) {
        if (!is_readable($path)) {
            return [];
        }

        $handle = fopen($path, 'rb');
        if (!$handle) {
            return [];
        }

        $rows = [];
        $header = null;

        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            if ($row === [null] || count($row) === 1 && $row[0] === null) {
                continue;
            }

            if ($header === null) {
                $header = $row;
                continue;
            }

            $assoc = [];
            foreach ($header as $index => $column_name) {
                $key = $this->normalize_field_name($column_name);
                $assoc[$key] = isset($row[$index]) ? $this->clean_field($row[$index]) : '';
            }

            $rows[] = $assoc;
        }

        fclose($handle);

        return $rows;
    }

    private function clean_field($value) {
        return is_string($value) ? wp_unslash(trim($value)) : trim((string) $value);
    }

    private function normalize_field_name($field_name) {
        $normalized = strtolower((string) $field_name);
        $normalized = preg_replace('/[^a-z0-9]+/', '', $normalized);

        $aliases = [
            'productname' => 'name',
            'productcode' => 'sku',
            'category' => 'product_cat',
            'productsummary' => 'description',
            'price' => 'regular_price',
            'availableqty' => 'stock_quantity',
            'image' => 'image',
            'productdescription' => 'description',
            'shortdescription' => 'description',
            'stockquantity' => 'stock_quantity',
            'regularprice' => 'regular_price',
        ];

        return $aliases[$normalized] ?? $normalized;
    }

    private function import_rows($rows) {
        $count = 0;

        foreach ($rows as $row) {
            if (!$this->import_row($row)) {
                continue;
            }

            $count++;
        }

        return $count;
    }

    private function import_row($row) {
        $name = trim((string) ($row['name'] ?? ''));
        if ($name === '') {
            return false;
        }

        $sku = trim((string) ($row['sku'] ?? ''));
        $product_id = $sku ? wc_get_product_id_by_sku($sku) : 0;
        $product = $product_id ? wc_get_product($product_id) : new WC_Product_Simple();

        if (!$product || !$product instanceof WC_Product) {
            return false;
        }

        $product->set_name($name);
        $product->set_status('publish');
        $product->set_catalog_visibility('visible');
        $product->set_type('simple');

        if ($sku === '') {
            $sku = sanitize_title($name) . '-' . time() . '-' . wp_rand(1000, 9999);
        }

        $product->set_sku($sku);

        $description = trim((string) ($row['description'] ?? ''));
        if ($description !== '') {
            $description = wp_strip_all_tags($description);
            $product->set_description($description);
            $product->set_short_description(wp_trim_words($description, 30));
        }

        $regular_price = $this->to_decimal($row['regular_price'] ?? '');
        if ($regular_price !== '') {
            $product->set_regular_price((string) $regular_price);
            $product->set_price((string) $regular_price);
        }

        $stock_quantity = (int) ($row['stock_quantity'] ?? 0);
        $product->set_manage_stock(true);
        $product->set_stock_quantity($stock_quantity);
        $product->set_stock_status($stock_quantity > 0 ? 'instock' : 'outofstock');

        $product->save();
        $product_id = $product->get_id();

        $category_value = (string) ($row['product_cat'] ?? '');
        $term_ids = $this->apply_categories($product_id, $category_value);
        if (!empty($term_ids)) {
            wp_set_object_terms($product_id, $term_ids, 'product_cat', false);
        }

        $image_url = trim((string) ($row['image'] ?? ''));
        if ($image_url !== '') {
            $image_id = $this->import_image($image_url, $product_id);
            if ($image_id) {
                $product->set_image_id((int) $image_id);
                $product->save();
            }
        }

        return true;
    }

    private function apply_categories($product_id, $raw_category_value) {
        $categories = $this->normalize_categories($raw_category_value);
        if (empty($categories)) {
            return [];
        }

        $term_ids = [];

        foreach ($categories as $category_name) {
            if ($category_name === '') {
                continue;
            }

            $term = term_exists($category_name, 'product_cat');
            if (!$term) {
                $result = wp_insert_term($category_name, 'product_cat');
                if (is_wp_error($result)) {
                    continue;
                }
                $term = $result;
            }

            if (is_array($term) && !empty($term['term_id'])) {
                $term_ids[] = (int) $term['term_id'];
            }
        }

        return $term_ids;
    }

    private function normalize_categories($raw_value) {
        if ($raw_value === '') {
            return [];
        }

        $categories = preg_split('/\s*[|,>\/]+\s*/', (string) $raw_value);
        $normalized = [];

        foreach ($categories as $category) {
            $category = trim((string) $category);
            if ($category === '') {
                continue;
            }

            $category = str_replace([' - ', '–'], ' ', $category);
            $normalized[] = $category;
        }

        return array_values(array_unique($normalized));
    }

    private function import_image($image_url, $product_id) {
        $image_url = trim((string) $image_url);
        if ($image_url === '') {
            return 0;
        }

        $attachment_id = attachment_url_to_postid($image_url);
        if ($attachment_id) {
            return $attachment_id;
        }

        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $image_id = media_sideload_image($image_url, $product_id, null, 'id');

        if (is_wp_error($image_id)) {
            return 0;
        }

        return (int) $image_id;
    }

    private function to_decimal($value) {
        if ($value === null || $value === '') {
            return '';
        }

        $value = str_replace([' ', ',', 'R'], '', (string) $value);
        $value = preg_replace('/[^0-9.\-]/', '', $value);

        if ($value === '' || $value === '-' || $value === '.') {
            return '';
        }

        return number_format((float) $value, 2, '.', '');
    }
}

add_action('plugins_loaded', static function () {
    if (class_exists('WooCommerce')) {
        WC_CSV_Product_Feed_Importer::instance();
    }
});

add_filter('plugin_action_links_' . plugin_basename(__FILE__), static function ($links) {
    $settings_link = '<a href="' . esc_url(admin_url('admin.php?page=wc-csv-feed-importer')) . '">Import</a>';
    array_unshift($links, $settings_link);
    return $links;
});

