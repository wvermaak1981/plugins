<?php
/**
 * Plugin Name: WooCommerce CSV Product Feed Importer
 * Description: Preview-first WooCommerce CSV importer with automatic mapping, saved presets, dry-run summaries and batched imports.
 * Version: 1.5.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 */
if (!defined('ABSPATH')) { exit; }

final class WC_CSV_Product_Feed_Importer {
    private static $instance;
    private $batch_size = 20;
    private $fields = ['name'=>'ProductName','sku'=>'ProductCode','category'=>'Category','description'=>'ProductSummary','price'=>'Price','stock'=>'AvailableQty','image'=>'Image'];

    public static function instance() { return self::$instance ?: (self::$instance = new self()); }
    private function __construct() {
        add_action('admin_menu', [$this,'admin_menu']);
        add_action('admin_post_wc_csv_product_feed_import', [$this,'handle_import']);
        add_action('admin_post_wc_csv_product_feed_confirm', [$this,'confirm_import']);
        add_action('wp_ajax_wc_csv_feed_batch', [$this,'ajax_batch']);
    }
    public function admin_menu() { add_submenu_page('woocommerce','CSV Feed Importer','CSV Feed Importer','manage_woocommerce','wc-csv-feed-importer',[$this,'page']); }

    public function page() {
        if (!current_user_can('manage_woocommerce')) return;
        $preview = sanitize_key(wp_unslash($_GET['preview'] ?? ''));
        $job = sanitize_key(wp_unslash($_GET['job'] ?? ''));
        echo '<div class="wrap"><h1>WooCommerce CSV Product Feed Importer</h1>';
        $notice = sanitize_key(wp_unslash($_GET['notice'] ?? ''));
        if ('error' === $notice) echo '<div class="notice notice-error"><p>Could not read the CSV feed or the preview expired.</p></div>';
        if ('success' === $notice) echo '<div class="notice notice-success"><p>' . esc_html(sprintf('%d product(s) imported.',absint($_GET['imported'] ?? 0))) . '</p></div>';
        if ('partial' === $notice) echo '<div class="notice notice-warning"><p>' . esc_html(sprintf('%d product(s) imported; some rows failed. Check the PHP error log.',absint($_GET['imported'] ?? 0))) . '</p></div>';
        if ($job) $this->render_progress($job); elseif ($preview) $this->render_preview($preview); else $this->render_form();
        echo '</div>';
    }

    private function render_form() {
        $saved = get_user_meta(get_current_user_id(),'wc_csv_feed_mapping',true);
        $mapping = is_array($saved) ? wp_parse_args($saved,$this->fields) : $this->fields;
        echo '<p>Upload the feed, review the automatic mapping and dry-run summary, then import in batches of 20.</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'" enctype="multipart/form-data">';
        wp_nonce_field('wc_csv_product_feed_import'); echo '<input type="hidden" name="action" value="wc_csv_product_feed_import"><table class="form-table"><tbody>';
        echo '<tr><th>CSV Feed URL</th><td><input type="url" class="regular-text" name="csv_feed_url" placeholder="https://example.com/products.csv"><p class="description">Use this or upload a file.</p></td></tr><tr><th>Upload CSV File</th><td><input type="file" name="csv_feed_file" accept=".csv,text/csv"></td></tr><tr><th>Column mapping</th><td><p class="description">Headers are detected automatically; adjust them if necessary.</p>';
        foreach ($this->fields as $field=>$default) echo '<p><label style="display:inline-block;width:170px" for="map_'.esc_attr($field).'">'.esc_html(ucwords(str_replace('_',' ',$field))).'</label><input class="regular-text" id="map_'.esc_attr($field).'" name="mapping['.esc_attr($field).']" value="'.esc_attr($mapping[$field]).'"></p>';
        echo '<label><input type="checkbox" name="save_mapping" value="1"> Save this mapping as my default</label></td></tr><tr><th>Default category</th><td><input class="regular-text" name="default_category" placeholder="Imported Products"></td></tr><tr><th>Product status</th><td><select name="product_status">';
        foreach(['publish'=>'Published','draft'=>'Draft','pending'=>'Pending','private'=>'Private'] as $v=>$label) echo '<option value="'.esc_attr($v).'">'.esc_html($label).'</option>';
        echo '</select></td></tr><tr><th>Preview row limit</th><td><input type="number" name="preview_limit" min="1" max="1000" value="100" class="small-text"></td></tr></tbody></table>'; submit_button('Preview Products'); echo '</form>';
    }

    private function render_preview($token) {
        $data=get_transient($this->key($token));
        if (!is_array($data)||empty($data['rows'])) { echo '<div class="notice notice-error"><p>This preview expired. Please start again.</p></div>'; return; }
        $rows=$data['rows'];$s=$data['summary'];$limit=max(1,min(1000,absint($data['limit'])));
        echo '<h2>Import Preview</h2><p>Showing '.esc_html(min(count($rows),$limit)).' of '.esc_html(count($rows)).' rows. No products have been saved.</p><p><strong>Detected mapping:</strong> '.esc_html(implode(', ',array_map(function($k,$v){return $k.' = '.$v;},array_keys($data['mapping']),$data['mapping']))).'</p><div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin:20px 0;">';
        foreach(['Rows skipped'=>'skipped','Duplicate SKUs'=>'duplicates','Invalid prices'=>'invalid_prices','Missing image URLs'=>'missing_images','Would create'=>'create','Would update'=>'update'] as $label=>$key) echo '<div style="background:#fff;border:1px solid #dcdcde;padding:12px"><small>'.esc_html($label).'</small><strong style="display:block;font-size:28px">'.esc_html($s[$key]).'</strong></div>';
        echo '</div><table class="widefat striped"><thead><tr><th>#</th><th>Product</th><th>SKU</th><th>Category</th><th>Price</th><th>Quantity</th><th>Image</th></tr></thead><tbody>';
        foreach(array_slice($rows,0,$limit) as $i=>$row) echo '<tr><td>'.esc_html($i+1).'</td><td>'.esc_html($row['name']).'</td><td>'.esc_html($row['sku']).'</td><td>'.esc_html($row['category']).'</td><td>'.esc_html($this->decimal($row['price'])).'</td><td>'.esc_html($row['stock']).'</td><td>'.($this->valid_url($row['image'])?'Yes':'No').'</td></tr>';
        echo '</tbody></table><p><strong>Status:</strong> '.esc_html($data['status']).' &nbsp; <strong>Default category:</strong> '.esc_html($data['category']?:'None').'</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'; wp_nonce_field('wc_csv_feed_confirm_'.$token); echo '<input type="hidden" name="action" value="wc_csv_product_feed_confirm"><input type="hidden" name="preview_token" value="'.esc_attr($token).'">'; submit_button('Confirm Import','primary','submit',false); echo ' <a class="button" href="'.esc_url(admin_url('admin.php?page=wc-csv-feed-importer')).'">Cancel</a></form>';
    }

    private function render_progress($job) {
        $data=get_transient($this->job_key($job));
        if (!is_array($data)) { echo '<div class="notice notice-error"><p>This import job expired.</p></div>'; return; }
        echo '<h2>Importing products</h2><div style="max-width:760px;background:#dcdcde;height:28px;border-radius:4px;overflow:hidden"><div id="wc-csv-progress-bar" style="height:28px;width:0;background:#2271b1;transition:width .3s"></div></div><p id="wc-csv-progress-text">Starting…</p><div id="wc-csv-progress-errors"></div><script>jQuery(function($){var token='.wp_json_encode($job).',nonce='.wp_json_encode(wp_create_nonce('wc_csv_batch_'.$job)).';function run(){ $.post(ajaxurl,{action:"wc_csv_feed_batch",job:token,nonce:nonce},function(r){if(!r.success){$("#wc-csv-progress-text").text(r.data||"Import failed.");return;}var d=r.data,p=d.total?Math.round(d.processed/d.total*100):100;$("#wc-csv-progress-bar").css("width",p+"%");$("#wc-csv-progress-text").text(d.processed+" of "+d.total+" processed ("+p+"%) — "+d.imported+" imported");if(d.done){if(d.failed){$("#wc-csv-progress-errors").html("<div class=\"notice notice-warning\"><p>"+d.failed+" row(s) failed. Check the PHP error log.</p></div>");}else{$("#wc-csv-progress-text").text("Import complete: "+d.imported+" product(s) imported.");}}else{setTimeout(run,300);}},"json").fail(function(){setTimeout(run,1000);});}run();});</script>';
    }

    public function handle_import() {
        if(!current_user_can('manage_woocommerce'))wp_die('Permission denied.'); check_admin_referer('wc_csv_product_feed_import'); $mapping=$this->mapping($_POST['mapping']??[]);$rows=[];$file=$_FILES['csv_feed_file']??[];
        if(!empty($file['error'])&&UPLOAD_ERR_NO_FILE!==(int)$file['error'])wp_die('CSV upload failed.');
        if(!empty($file['tmp_name'])&&is_uploaded_file($file['tmp_name']))$rows=$this->read_csv($file['tmp_name'],$mapping); else {$url=esc_url_raw(wp_unslash($_POST['csv_feed_url']??''));if($url){$r=wp_safe_remote_get($url,['timeout'=>120,'redirection'=>3]);if(is_wp_error($r)||200!==(int)wp_remote_retrieve_response_code($r))wp_die('Unable to fetch the CSV feed.');$tmp=wp_tempnam('wc-csv-feed');if(!$tmp||false===file_put_contents($tmp,wp_remote_retrieve_body($r)))wp_die('Unable to create a temporary CSV file.');$rows=$this->read_csv($tmp,$mapping);@unlink($tmp);}}
        if(!$rows)$this->go(['notice'=>'error']); if(!empty($_POST['save_mapping']))update_user_meta(get_current_user_id(),'wc_csv_feed_mapping',$mapping);$status=sanitize_key($_POST['product_status']??'publish');if(!in_array($status,['publish','draft','pending','private'],true))$status='publish';$category=sanitize_text_field(wp_unslash($_POST['default_category']??''));$limit=max(1,min(1000,absint($_POST['preview_limit']??100)));$token=wp_generate_password(32,false,false);set_transient($this->key($token),['rows'=>$rows,'mapping'=>$mapping,'summary'=>$this->summary($rows),'status'=>$status,'category'=>$category,'limit'=>$limit],HOUR_IN_SECONDS);$this->go(['preview'=>$token]);
    }

    public function confirm_import() {
        if(!current_user_can('manage_woocommerce'))wp_die('Permission denied.');$token=sanitize_key(wp_unslash($_POST['preview_token']??''));check_admin_referer('wc_csv_feed_confirm_'.$token);$key=$this->key($token);$data=get_transient($key);delete_transient($key);if(!is_array($data)||empty($data['rows']))$this->go(['notice'=>'error']);$job=wp_generate_password(32,false,false);set_transient($this->job_key($job),['rows'=>$data['rows'],'index'=>0,'total'=>count($data['rows']),'imported'=>0,'failed'=>0,'status'=>$data['status'],'category'=>$data['category']],DAY_IN_SECONDS);$this->go(['job'=>$job]);
    }

    public function ajax_batch() {
        if(!current_user_can('manage_woocommerce'))wp_send_json_error('Permission denied.',403);$job=sanitize_key(wp_unslash($_POST['job']??''));if(!$job||!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce']??'')),'wc_csv_batch_'.$job))wp_send_json_error('Invalid import token.',403);$key=$this->job_key($job);$data=get_transient($key);if(!is_array($data))wp_send_json_error('Import job expired.',410);$end=min($data['index']+$this->batch_size,$data['total']);for($i=$data['index'];$i<$end;$i++){try{if($this->save_row($data['rows'][$i],$data['status'],$data['category']))$data['imported']++;else$data['failed']++;}catch(Throwable $e){$data['failed']++;error_log('CSV Feed Importer: '.$e->getMessage());}}$data['index']=$end;$done=$end >= $data['total'];if($done)delete_transient($key);else set_transient($key,$data,DAY_IN_SECONDS);wp_send_json_success(['processed'=>$end,'total'=>$data['total'],'imported'=>$data['imported'],'failed'=>$data['failed'],'done'=>$done]);
    }

    private function read_csv($path,$mapping){if(!is_readable($path)||false===($h=fopen($path,'rb')))return[];$headers=fgetcsv($h,0,',','"');if(!$headers){fclose($h);return[];}$normalized=array_map([$this,'norm'],$headers);$detected=$this->detect_mapping($normalized,$mapping);$rows=[];while(false!==($line=fgetcsv($h,0,',','"'))){if(!$line||(1===count($line)&&''===trim((string)$line[0]))){continue;}$row=[];foreach($detected as $field=>$header){$i=array_search($this->norm($header),$normalized,true);$row[$field]=false!==$i&&isset($line[$i])?trim((string)$line[$i]):'';}$rows[]=$row;}fclose($h);return$rows;}
    private function detect_mapping($headers,$mapping){$aliases=['name'=>['productname','name','title','producttitle'],'sku'=>['productcode','sku','code','itemcode'],'category'=>['category','categories','productcategory'],'description'=>['productsummary','description','productdescription','shortdescription'],'price'=>['price','regularprice','saleprice'],'stock'=>['availableqty','stock','stockquantity','quantity','qty'],'image'=>['image','imageurl','image_url','picture','photo']];foreach($aliases as $field=>$names)foreach($names as $name)if(in_array($this->norm($name),$headers,true)){$mapping[$field]=$headers[array_search($this->norm($name),$headers,true)];break;}return$mapping;}
    private function summary($rows){$s=['skipped'=>0,'duplicates'=>0,'invalid_prices'=>0,'missing_images'=>0,'create'=>0,'update'=>0];$seen=[];foreach($rows as $r){$name=trim($r['name']);$sku=strtolower(trim($r['sku']));if(!$name){$s['skipped']++;continue;}if($sku&&in_array($sku,$seen,true)){$s['duplicates']++;continue;}if($sku)$seen[]=$sku;if($r['price']!==''&&!$this->decimal($r['price'])){$s['invalid_prices']++;continue;}if(!$this->valid_url($r['image']))$s['missing_images']++;$id=$sku&&function_exists('wc_get_product_id_by_sku')?wc_get_product_id_by_sku($sku):0;$id?$s['update']++:$s['create']++;}return$s;}
    private function save_row($r,$status,$default_category){if(!$r['name']||($r['price']!==''&&!$this->decimal($r['price'])))return false;$sku=trim($r['sku']);$id=$sku&&function_exists('wc_get_product_id_by_sku')?(int)wc_get_product_id_by_sku($sku):0;$p=$id?wc_get_product($id):new WC_Product_Simple();if(!$p||!is_a($p,'WC_Product'))return false;if(!$sku)$sku=sanitize_title($r['name']).'-'.wp_rand(1000,9999);$p->set_name($r['name']);$p->set_sku($sku);$p->set_status($status);$p->set_catalog_visibility('visible');if($r['description']){$p->set_description(wp_kses_post($r['description']));$p->set_short_description(wp_trim_words(wp_strip_all_tags($r['description']),30));}$price=$this->decimal($r['price']);if($price){$p->set_regular_price($price);$p->set_price($price);}$stock=max(0,(int)preg_replace('/[^0-9-]/','',$r['stock']));$p->set_manage_stock(true);$p->set_stock_quantity($stock);$p->set_stock_status($stock?'instock':'outofstock');$p->save();$id=$p->get_id();$cat=$r['category']?:$default_category;$terms=[];foreach(preg_split('/\s*[|,>\/]+\s*/',$cat,-1,PREG_SPLIT_NO_EMPTY) as $c){$t=term_exists(trim($c),'product_cat');if(!$t)$t=wp_insert_term(trim($c),'product_cat');if(!is_wp_error($t))$terms[]=(int)(is_array($t)?$t['term_id']:$t);}if($terms)wp_set_object_terms($id,$terms,'product_cat',false);if($r['image']){$img=$this->image($r['image'],$id);if($img){$p->set_image_id($img);$p->save();}}return true;}
    private function mapping($input){$m=$this->fields;foreach($m as $k=>$v)if(isset($input[$k]))$m[$k]=sanitize_text_field(wp_unslash($input[$k]));return$m;}
    private function norm($v){return strtolower(preg_replace('/[^a-z0-9]+/','',(string)$v));}
    private function decimal($v){$v=preg_replace('/[^0-9.\-]/','',str_replace(',','',(string)$v));return(!$v||$v==='-'||$v==='.')?'':number_format((float)$v,2,'.','');}
    private function valid_url($v){return is_string($v)&&$v!==''&&(bool)filter_var($v,FILTER_VALIDATE_URL);}
    private function image($url,$id){require_once ABSPATH.'wp-admin/includes/media.php';require_once ABSPATH.'wp-admin/includes/file.php';require_once ABSPATH.'wp-admin/includes/image.php';$a=media_sideload_image(esc_url_raw($url),$id,null,'id');return is_wp_error($a)?0:(int)$a;}
    private function key($token){return'wc_csv_preview_'.get_current_user_id().'_'.sanitize_key($token);}
    private function job_key($token){return'wc_csv_job_'.get_current_user_id().'_'.sanitize_key($token);}
    private function go($args){wp_safe_redirect(add_query_arg(array_merge(['page'=>'wc-csv-feed-importer'],$args),admin_url('admin.php')));exit;}
}
add_action('plugins_loaded',static function(){if(class_exists('WooCommerce'))WC_CSV_Product_Feed_Importer::instance();});
