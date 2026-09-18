<?php
/**
 * Plugin Name: WooCommerce XML Feed Importer
 * Description: Scheduled XML product imports with a feed editor, XML namespaces, variable products, variations, and custom product attributes.
 * Version: 2.0.0
 * Requires PHP: 7.4
 * Requires at least: 6.0
 * WC requires at least: 7.0
 */
if (!defined('ABSPATH')) exit;

final class WPFI_Importer {
    const OPT = 'wpfi_feeds_v2';
    const CRON = 'wpfi_import_feed';

    public function __construct() {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_wpfi_save', [$this, 'save']);
        add_action('admin_post_wpfi_run', [$this, 'run_now']);
        add_filter('cron_schedules', [$this, 'schedules']);
        add_action(self::CRON, [$this, 'cron'], 10, 1);
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
    }

    public function activate() { $this->reschedule(); }
    public function deactivate() { $this->clear_schedules(); }
    public function schedules($s) {
        $s['wpfi_15m'] = ['interval'=>900, 'display'=>'Every 15 minutes'];
        $s['wpfi_30m'] = ['interval'=>1800, 'display'=>'Every 30 minutes'];
        $s['wpfi_6h'] = ['interval'=>21600, 'display'=>'Every 6 hours'];
        return $s;
    }
    private function feeds() { $f = get_option(self::OPT, []); return is_array($f) ? $f : []; }
    private function clear_schedules() { foreach ($this->feeds() as $f) if (!empty($f['slug'])) wp_clear_scheduled_hook(self::CRON, [$f['slug']]); }
    private function reschedule() {
        $this->clear_schedules();
        foreach ($this->feeds() as $f) if (!empty($f['enabled']) && !empty($f['slug']) && !empty($f['url'])) {
            wp_schedule_event(time()+120, $f['frequency'] ?: 'daily', self::CRON, [$f['slug']]);
        }
    }
    public function cron($slug) { foreach ($this->feeds() as $f) if (($f['slug'] ?? '') === $slug && !empty($f['enabled'])) $this->import($f); }

    public function menu() { add_submenu_page('woocommerce', 'XML Feed Importer', 'XML Feed Importer', 'manage_woocommerce', 'wpfi', [$this, 'page']); }
    private function esc($v) { return esc_attr($v ?? ''); }
    private function row($name, $value='') { return '<input type="text" name="'.$name.'" value="'.$this->esc($value).'" class="regular-text" />'; }
    private function map_row($key='', $selector='') {
        return '<tr><td><input type="text" name="map_key[]" value="'.$this->esc($key).'" placeholder="name, sku, price" /></td><td><input type="text" name="map_selector[]" value="'.$this->esc($selector).'" placeholder="product/name or ns:title" /></td><td><button type="button" class="button wpfi-remove">Remove</button></td></tr>';
    }
    private function attr_row($name='', $selector='') {
        return '<tr><td><input type="text" name="attr_name[]" value="'.$this->esc($name).'" placeholder="Color" /></td><td><input type="text" name="attr_selector[]" value="'.$this->esc($selector).'" placeholder="attributes/color" /></td><td><button type="button" class="button wpfi-remove">Remove</button></td></tr>';
    }
    private function ns_row($prefix='', $uri='') {
        return '<tr><td><input type="text" name="ns_prefix[]" value="'.$this->esc($prefix).'" placeholder="g" /></td><td><input type="url" name="ns_uri[]" value="'.$this->esc($uri).'" placeholder="https://example.com/schema" class="large-text" /></td><td><button type="button" class="button wpfi-remove">Remove</button></td></tr>';
    }
    public function page() {
        if (!current_user_can('manage_woocommerce')) return;
        $feeds = $this->feeds();
        $active = isset($_GET['feed']) ? max(0, (int) $_GET['feed']) : 0;
        if (!$feeds) $feeds = [[]];
        if (!isset($feeds[$active])) $active = 0;
        $f = wp_parse_args($feeds[$active], ['name'=>'','slug'=>'','url'=>'','enabled'=>1,'frequency'=>'daily','product_xpath'=>'/products/product','variation_xpath'=>'','identifier_field'=>'sku','variation_identifier_field'=>'sku','map'=>[],'attributes'=>[],'namespaces'=>[]]);
        ?>
        <div class="wrap"><h1>WooCommerce XML Feed Importer</h1>
        <?php if (!empty($_GET['saved'])): ?><div class="notice notice-success"><p>Feed settings saved.</p></div><?php endif; ?>
        <?php if (!empty($_GET['ran'])): ?><div class="notice notice-info"><p>Import finished. Check WooCommerce logs for details.</p></div><?php endif; ?>
        <p>Each feed has its own URL, schedule, XPath, field mappings, namespaces, attributes, and variable-product settings.</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="wpfi-form">
        <input type="hidden" name="action" value="wpfi_save" /><?php wp_nonce_field('wpfi_save'); ?>
        <p><label><strong>Feed</strong> <select onchange="location.href='<?php echo esc_js(admin_url('admin.php?page=wpfi&feed=')); ?>'+this.value"><?php foreach ($feeds as $i=>$x): ?><option value="<?php echo (int)$i; ?>" <?php selected($active,$i); ?>><?php echo esc_html($x['name'] ?? ('Feed '.($i+1))); ?></option><?php endforeach; ?></select></label> <button type="button" class="button" id="wpfi-add-feed">Add feed</button> <button type="submit" class="button button-primary">Save feed</button></p>
        <input type="hidden" name="feed_index" value="<?php echo (int)$active; ?>" />
        <table class="form-table"><tr><th>Feed name</th><td><?php echo $this->row('name',$f['name']); ?></td></tr><tr><th>Slug</th><td><?php echo $this->row('slug',$f['slug']); ?><p class="description">Unique identifier; used by WP-Cron.</p></td></tr><tr><th>XML URL</th><td><?php echo $this->row('url',$f['url']); ?></td></tr><tr><th>Enabled</th><td><label><input type="checkbox" name="enabled" value="1" <?php checked(!empty($f['enabled'])); ?> /> Import this feed</label></td></tr><tr><th>Import frequency</th><td><select name="frequency"><?php foreach (['wpfi_15m'=>'Every 15 minutes','wpfi_30m'=>'Every 30 minutes','hourly'=>'Hourly','twicedaily'=>'Twice daily','daily'=>'Daily','wpfi_6h'=>'Every 6 hours','weekly'=>'Weekly'] as $k=>$v): ?><option value="<?php echo esc_attr($k); ?>" <?php selected($f['frequency'],$k); ?>><?php echo esc_html($v); ?></option><?php endforeach; ?></select></td></tr><tr><th>Product XPath</th><td><?php echo $this->row('product_xpath',$f['product_xpath']); ?><p class="description">Example: /catalog/product or //g:item. Namespace prefixes must be defined below.</p></td></tr><tr><th>Identifier field</th><td><?php echo $this->row('identifier_field',$f['identifier_field']); ?><p class="description">Usually sku. Products are created or updated using this value.</p></td></tr></table>
        <h2>Product field mappings</h2><p>Use XML child paths such as <code>name</code>, <code>offer.price</code>, or namespace XPath such as <code>g:title</code>. Supported keys include name, sku, description, short_description, price, sale_price, stock_quantity, stock_status, image.</p>
        <table class="widefat wpfi-map"><thead><tr><th>WooCommerce field</th><th>XML selector</th><th></th></tr></thead><tbody id="wpfi-map-body"><?php foreach ($f['map'] as $k=>$v) echo $this->map_row($k,$v); if (!$f['map']) echo $this->map_row(); ?></tbody></table><p><button type="button" class="button wpfi-add-map">Add field mapping</button></p>
        <h2>Custom product attributes</h2><p>Attributes are stored as WooCommerce product attributes and can be used for variation options. Attribute names become global <code>pa_</code> taxonomies.</p>
        <table class="widefat"><thead><tr><th>Attribute name</th><th>XML selector</th><th></th></tr></thead><tbody id="wpfi-attr-body"><?php foreach ($f['attributes'] as $k=>$v) echo $this->attr_row($k,$v); if (!$f['attributes']) echo $this->attr_row(); ?></tbody></table><p><button type="button" class="button wpfi-add-attr">Add attribute</button></p>
        <h2>XML namespaces</h2><p>Register every prefix used in XPath or selectors. The default XML namespace must be assigned a prefix here.</p>
        <table class="widefat"><thead><tr><th>Prefix</th><th>Namespace URI</th><th></th></tr></thead><tbody id="wpfi-ns-body"><?php foreach ($f['namespaces'] as $k=>$v) echo $this->ns_row($k,$v); if (!$f['namespaces']) echo $this->ns_row(); ?></tbody></table><p><button type="button" class="button wpfi-add-ns">Add namespace</button></p>
        <h2>Variable products</h2><table class="form-table"><tr><th>Variation XPath</th><td><?php echo $this->row('variation_xpath',$f['variation_xpath']); ?><p class="description">Relative or absolute XPath to variation nodes, e.g. <code>variants/variant</code>. Leave blank for simple products.</p></td></tr><tr><th>Variation identifier field</th><td><?php echo $this->row('variation_identifier_field',$f['variation_identifier_field']); ?></td></tr></table>
        </form>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:15px"><input type="hidden" name="action" value="wpfi_run" /><input type="hidden" name="feed_index" value="<?php echo (int)$active; ?>" /><?php wp_nonce_field('wpfi_run'); ?><button class="button">Run this feed now</button></form>
        <script>(function(){const map=<?php echo wp_json_encode($this->map_row()); ?>,attr=<?php echo wp_json_encode($this->attr_row()); ?>,ns=<?php echo wp_json_encode($this->ns_row()); ?>;document.addEventListener('click',function(e){if(e.target.matches('.wpfi-remove')){e.target.closest('tr').remove();}if(e.target.matches('.wpfi-add-map')){document.querySelector('#wpfi-map-body').insertAdjacentHTML('beforeend',map);}if(e.target.matches('.wpfi-add-attr')){document.querySelector('#wpfi-attr-body').insertAdjacentHTML('beforeend',attr);}if(e.target.matches('.wpfi-add-ns')){document.querySelector('#wpfi-ns-body').insertAdjacentHTML('beforeend',ns);}if(e.target.id==='wpfi-add-feed'){if(confirm('Save this feed before adding another?')){document.querySelector('[name=feed_index]').value='-1';document.querySelector('#wpfi-form').submit();}}});})();</script>
        </div><?php
    }
    public function save() {
        if (!current_user_can('manage_woocommerce') || !check_admin_referer('wpfi_save')) wp_die('Permission denied.');
        $feeds = $this->feeds(); $i = (int)($_POST['feed_index'] ?? 0);
        if ($i < 0) { $i = count($feeds); }
        $clean = ['name'=>sanitize_text_field(wp_unslash($_POST['name'] ?? '')), 'slug'=>sanitize_title(wp_unslash($_POST['slug'] ?? '')), 'url'=>esc_url_raw(wp_unslash($_POST['url'] ?? '')), 'enabled'=>!empty($_POST['enabled'])?1:0, 'frequency'=>sanitize_key($_POST['frequency'] ?? 'daily'), 'product_xpath'=>sanitize_text_field(wp_unslash($_POST['product_xpath'] ?? '/products/product')), 'identifier_field'=>sanitize_key($_POST['identifier_field'] ?? 'sku'), 'variation_xpath'=>sanitize_text_field(wp_unslash($_POST['variation_xpath'] ?? '')), 'variation_identifier_field'=>sanitize_key($_POST['variation_identifier_field'] ?? 'sku'), 'map'=>[], 'attributes'=>[], 'namespaces'=>[]];
        foreach ((array)($_POST['map_key'] ?? []) as $n=>$key) { $key=sanitize_key($key); $val=sanitize_text_field(wp_unslash($_POST['map_selector'][$n] ?? '')); if ($key && $val) $clean['map'][$key]=$val; }
        foreach ((array)($_POST['attr_name'] ?? []) as $n=>$key) { $key=sanitize_text_field(wp_unslash($key)); $val=sanitize_text_field(wp_unslash($_POST['attr_selector'][$n] ?? '')); if ($key && $val) $clean['attributes'][$key]=$val; }
        foreach ((array)($_POST['ns_prefix'] ?? []) as $n=>$key) { $key=sanitize_key($key); $val=esc_url_raw(wp_unslash($_POST['ns_uri'][$n] ?? '')); if ($key && $val) $clean['namespaces'][$key]=$val; }
        $feeds[$i]=$clean; update_option(self::OPT, array_values($feeds), false); $this->reschedule(); wp_safe_redirect(admin_url('admin.php?page=wpfi&feed='.$i.'&saved=1')); exit;
    }
    public function run_now() { if (!current_user_can('manage_woocommerce') || !check_admin_referer('wpfi_run')) wp_die('Permission denied.'); $feeds=$this->feeds(); $f=$feeds[(int)($_POST['feed_index']??0)]??null; if($f) $this->import($f); wp_safe_redirect(admin_url('admin.php?page=wpfi&ran=1')); exit; }

    private function log($f,$message) { if (function_exists('wc_get_logger')) wc_get_logger()->info($message, ['source'=>'wpfi-'.$f['slug']]); }
    private function value($node,$selector) {
        if (!$selector) return '';
        $r = (strpos($selector,'/')!==false || strpos($selector,'@')!==false || strpos($selector,'[')!==false) ? $node->xpath($selector) : null;
        if (is_array($r)) return trim((string)($r[0]??''));
        $cur=$node; foreach(explode('.',$selector) as $part){ if(!isset($cur->{$part})) return ''; $cur=$cur->{$part}; } return trim((string)$cur);
    }
    private function find_product($field,$value) { if ($field==='sku') return wc_get_product_id_by_sku($value); $q=get_posts(['post_type'=>'product','post_status'=>'any','posts_per_page'=>1,'meta_key'=>'_'.sanitize_key($field),'meta_value'=>$value,'fields'=>'ids']); return $q ? (int)$q[0] : 0; }
    private function register_attr($name) { $tax='pa_'.sanitize_title($name); if(!taxonomy_exists($tax)) register_taxonomy($tax,'product',['public'=>false,'show_ui'=>false,'rewrite'=>false]); return $tax; }
    private function set_attrs($product_id,$node,$attrs,$names) { $out=[]; foreach($attrs as $name=>$selector){$v=$this->value($node,$selector);if($v==='')continue;$tax=$this->register_attr($name);$vals=array_filter(array_map('trim',explode('|',$v)));wp_set_object_terms($product_id,$vals,$tax,false);$out[$tax]=['name'=>$tax,'value'=>implode(' | ',$vals),'is_visible'=>1,'is_variation'=>1,'is_taxonomy'=>1];} return $out; }
    private function set_common($p,$v) { $p->set_name($v['name']??'Imported product'); if(!empty($v['sku']))$p->set_sku($v['sku']); if(isset($v['description']))$p->set_description(wp_kses_post($v['description'])); if(isset($v['short_description']))$p->set_short_description(wp_kses_post($v['short_description'])); if(isset($v['price'])){$p->set_regular_price((string)(float)$v['price']);$p->set_price((string)(float)$v['price']);} if(isset($v['sale_price']))$p->set_sale_price((string)(float)$v['sale_price']); if(isset($v['stock_quantity'])&&$v['stock_quantity']!==''){$p->set_manage_stock(true);$p->set_stock_quantity((int)$v['stock_quantity']);} if(isset($v['stock_status'])&&$v['stock_status']!=='')$p->set_stock_status(in_array(strtolower($v['stock_status']),['outofstock','onbackorder'],true)?strtolower($v['stock_status']):'instock'); }
    private function import($f) {
        $r=wp_remote_get($f['url'],['timeout'=>90,'user-agent'=>'WooCommerce XML Feed Importer/2.0']); if(is_wp_error($r)){ $this->log($f,$r->get_error_message()); return; } libxml_use_internal_errors(true); $xml=simplexml_load_string(wp_remote_retrieve_body($r)); if(!$xml){$this->log($f,'Invalid XML');return;} foreach((array)($f['namespaces']??[]) as $p=>$uri)$xml->registerXPathNamespace($p,$uri); $items=$xml->xpath($f['product_xpath']); if(!is_array($items)){ $this->log($f,'No products matched XPath');return; } $count=0; foreach($items as $node){$v=[];foreach($f['map'] as $k=>$s)$v[$k]=$this->value($node,$s);$id=$v[$f['identifier_field']]??($v['sku']??'');if(!$id)continue;$pid=$this->find_product($f['identifier_field'],$id);$has=!empty($f['variation_xpath']);if($pid)$p=wc_get_product($pid);else$p=$has?new WC_Product_Variable():new WC_Product_Simple();$this->set_common($p,$v);$p->set_sku($id);$pid=$p->save();$attrs=$this->set_attrs($pid,$node,$f['attributes'], $f['namespaces']);if($has){$p=wc_get_product($pid);$p->set_attributes($attrs);$p->save();$vars=$node->xpath($f['variation_xpath']);foreach((array)$vars as $vn)$this->variation($pid,$vn,$f);} $count++;} $this->log($f,'Imported '.$count.' products.');
    }
    private function variation($parent,$node,$f){$v=[];foreach($f['map'] as $k=>$s)$v[$k]=$this->value($node,$s);$id=$v[$f['variation_identifier_field']]??($v['sku']??'');$vid=$id?wc_get_product_id_by_sku($id):0;$p=$vid?wc_get_product($vid):new WC_Product_Variation();$p->set_parent_id($parent);if($id)$p->set_sku($id);if(isset($v['price'])){$p->set_regular_price((string)(float)$v['price']);$p->set_price((string)(float)$v['price']);}if(isset($v['sale_price']))$p->set_sale_price((string)(float)$v['sale_price']);if(isset($v['stock_quantity'])&&$v['stock_quantity']!==''){$p->set_manage_stock(true);$p->set_stock_quantity((int)$v['stock_quantity']);}$attrs=$this->set_attrs($parent,$node,$f['attributes'],$f['namespaces']);$pa=[];foreach($attrs as $tax=>$a){$pa[$tax]=sanitize_title($a['value']);}$p->set_attributes($pa);$p->save();}
}
new WPFI_Importer();
