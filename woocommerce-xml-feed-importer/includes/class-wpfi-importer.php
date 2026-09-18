<?php
if (!defined('ABSPATH')) exit;

final class WPFI_Importer {
    private $logger;
    public function __construct($logger) { $this->logger = $logger; }

    public function import(array $feed) {
        if (!class_exists('WooCommerce')) { $this->logger->write($feed, 'error', 'WooCommerce is not active.'); return false; }
        $response = wp_safe_remote_get($feed['url'], ['timeout'=>90, 'redirection'=>3, 'user-agent'=>'WPFI/'.WPFI_VERSION]);
        if (is_wp_error($response)) { $this->logger->write($feed, 'error', $response->get_error_message()); return false; }
        $status = wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) { $this->logger->write($feed, 'error', 'Feed returned HTTP '.$status); return false; }
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string(wp_remote_retrieve_body($response), 'SimpleXMLElement', LIBXML_NOCDATA);
        if (!$xml) { $this->logger->write($feed, 'error', 'The feed is not valid XML.'); return false; }
        foreach ((array)($feed['namespaces'] ?? []) as $prefix=>$uri) $xml->registerXPathNamespace($prefix, $uri);
        $nodes = $xml->xpath($feed['product_xpath'] ?: '/products/product');
        if (!is_array($nodes)) { $this->logger->write($feed, 'warning', 'No product nodes matched the configured XPath.'); return false; }
        $products = 0; $variations = 0;
        foreach ($nodes as $node) {
            $values = $this->values($node, $feed['map'] ?? []);
            $identifier = trim((string)($values[$feed['identifier_field'] ?? 'sku'] ?? ($values['sku'] ?? '')));
            if (!$identifier) { $this->logger->write($feed, 'warning', 'Skipped a product without an identifier.'); continue; }
            $existing = ($feed['identifier_field'] ?? 'sku') === 'sku' ? wc_get_product_id_by_sku($identifier) : $this->find_meta_product($feed['identifier_field'], $identifier);
            $is_variable = !empty($feed['variation_xpath']);
            $product = $existing ? wc_get_product($existing) : ($is_variable ? new WC_Product_Variable() : new WC_Product_Simple());
            if (!$product) $product = $is_variable ? new WC_Product_Variable() : new WC_Product_Simple();
            $this->apply_values($product, $values, $identifier);
            $product_id = $product->save();
            $this->apply_attributes($product_id, $node, $feed['attributes'] ?? []);
            if ($is_variable) {
                $product = wc_get_product($product_id); $product->set_attributes($this->variation_attributes($product_id, $node, $feed['attributes'] ?? [])); $product->save();
                $variation_nodes = $node->xpath($feed['variation_xpath']);
                foreach ((array)$variation_nodes as $variation_node) { if ($this->save_variation($product_id, $variation_node, $feed)) $variations++; }
            }
            $products++;
        }
        $this->logger->write($feed, 'info', sprintf('Import completed: %d products and %d variations.', $products, $variations));
        return true;
    }

    private function values($node, $map) { $values=[]; foreach ((array)$map as $key=>$selector) $values[$key]=$this->value($node,$selector); return $values; }
    private function value($node, $selector) {
        if (!$selector) return '';
        if (strpos($selector,'/')!==false || strpos($selector,'@')!==false || strpos($selector,'[')!==false) { $found=$node->xpath($selector); return is_array($found) ? trim((string)($found[0]??'')) : ''; }
        $current=$node; foreach (explode('.',$selector) as $part) { if (!isset($current->{$part})) return ''; $current=$current->{$part}; } return trim((string)$current);
    }
    private function apply_values($product, $values, $identifier) {
        $product->set_name($values['name'] ?? $identifier); $product->set_sku($identifier);
        if (isset($values['description'])) $product->set_description(wp_kses_post($values['description']));
        if (isset($values['short_description'])) $product->set_short_description(wp_kses_post($values['short_description']));
        if (isset($values['price']) && $values['price'] !== '') { $price=(string)(float)$values['price']; $product->set_regular_price($price); $product->set_price($price); }
        if (isset($values['sale_price']) && $values['sale_price'] !== '') $product->set_sale_price((string)(float)$values['sale_price']);
        if (isset($values['stock_quantity']) && $values['stock_quantity'] !== '') { $product->set_manage_stock(true); $product->set_stock_quantity((int)$values['stock_quantity']); }
        if (!empty($values['stock_status'])) $product->set_stock_status(in_array(strtolower($values['stock_status']), ['outofstock','onbackorder'], true) ? strtolower($values['stock_status']) : 'instock');
    }
    private function find_meta_product($field,$value) { $ids=get_posts(['post_type'=>'product','post_status'=>'any','posts_per_page'=>1,'meta_key'=>'_'.sanitize_key($field),'meta_value'=>$value,'fields'=>'ids']); return $ids ? (int)$ids[0] : 0; }
    private function taxonomy($name) { $taxonomy='pa_'.sanitize_title($name); if (!taxonomy_exists($taxonomy)) register_taxonomy($taxonomy,'product',['public'=>false,'show_ui'=>false,'rewrite'=>false]); return $taxonomy; }
    private function apply_attributes($product_id,$node,$attributes) { foreach ((array)$attributes as $name=>$selector) { $value=$this->value($node,$selector); if ($value==='') continue; $taxonomy=$this->taxonomy($name); wp_set_object_terms($product_id, array_filter(array_map('trim', explode('|',$value))), $taxonomy, false); } }
    private function variation_attributes($product_id,$node,$attributes) { $out=[]; foreach ((array)$attributes as $name=>$selector) { $value=$this->value($node,$selector); if ($value==='') continue; $taxonomy=$this->taxonomy($name); $out[$taxonomy]=['name'=>$taxonomy,'value'=>$value,'is_visible'=>1,'is_variation'=>1,'is_taxonomy'=>1]; } return $out; }
    private function save_variation($parent_id,$node,$feed) {
        $values=$this->values($node,$feed['map']??[]); $field=$feed['variation_identifier_field']??'sku'; $identifier=trim((string)($values[$field]??($values['sku']??''))); if (!$identifier) return false;
        $variation_id=wc_get_product_id_by_sku($identifier); $variation=$variation_id ? wc_get_product($variation_id) : new WC_Product_Variation(); $variation->set_parent_id($parent_id); $variation->set_sku($identifier);
        if (isset($values['price']) && $values['price']!=='') { $price=(string)(float)$values['price']; $variation->set_regular_price($price); $variation->set_price($price); }
        if (isset($values['sale_price']) && $values['sale_price']!=='') $variation->set_sale_price((string)(float)$values['sale_price']);
        if (isset($values['stock_quantity']) && $values['stock_quantity']!=='') { $variation->set_manage_stock(true); $variation->set_stock_quantity((int)$values['stock_quantity']); }
        $attributes=[]; foreach ((array)($feed['attributes']??[]) as $name=>$selector) { $value=$this->value($node,$selector); if ($value!=='') $attributes['pa_'.sanitize_title($name)]=sanitize_title($value); } $variation->set_attributes($attributes); $variation->save(); return true;
    }
}
