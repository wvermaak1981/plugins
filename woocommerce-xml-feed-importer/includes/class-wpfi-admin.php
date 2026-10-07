<?php
if (!defined('ABSPATH')) exit;
final class WPFI_Admin {
 private $repo,$scheduler,$importer,$logger;
 public function __construct($r,$s,$i,$l){
  $this->repo=$r;
  $this->scheduler=$s;
  $this->importer=$i;
  $this->logger=$l;
  add_action('admin_menu',[$this,'menu']);
  add_action('admin_post_wpfi_save_feed',[$this,'save']);
  add_action('admin_post_wpfi_delete_feed',[$this,'delete']);
 }
 public function menu(){
  add_submenu_page('woocommerce','XML Feed Importer','XML Feed Importer','manage_woocommerce','wpfi-feeds',[$this,'page']);
 }
 private function can(){
  return current_user_can('manage_woocommerce');
 }
 private function url($a=[]){
  return add_query_arg(array_merge(['page'=>'wpfi-feeds'],$a),admin_url('admin.php'));
 }
 private function log($message, $level='info', $context=[]){
  $timestamp=date('Y-m-d H:i:s');
  $user=wp_get_current_user();
  $user_login=$user->user_login??'unknown';
  $log_message="[$timestamp] [$level] [$user_login] $message";
  if(!empty($context)){
   $log_message.=" | Context: ".wp_json_encode($context);
  }
  error_log($log_message,3,WP_CONTENT_DIR.'/wpfi-admin.log');
 }
 public function page(){
  if(!$this->can())return;
  $action=sanitize_key($_GET['action']??'list');
  $this->log("Page accessed: $action");
  if($action==='edit'||$action==='new')$this->edit();
  else $this->list();
 }
 private function list(){
  echo '<div class="wrap">';
  echo '<h1>XML Feed Importer</h1>';
  echo '<p><a class="button button-primary" href="'.esc_url($this->url(['action'=>'new'])).'">Add Feed</a></p>';
  echo '<table class="widefat striped"><thead><tr><th>Feed Name</th><th>URL</th><th>Status</th><th>Frequency</th><th>Actions</th></tr></thead><tbody>';
  $feeds=$this->repo->all();
  if(empty($feeds)){
   echo '<tr><td colspan="5">No feeds configured yet.</td></tr>';
  }else{
   foreach($feeds as $f){
    echo '<tr>';
    echo '<td><strong>'.esc_html($f['name']??'Untitled').'</strong><br><small>'.esc_html($f['id']??'').'</small></td>';
    echo '<td><code>'.esc_html(substr($f['url']??'',0,50)).(strlen($f['url']??'')>50?'...':'').'</code></td>';
    echo '<td>'.($f['enabled']?'<span style="color:green">✓ Enabled</span>':'<span style="color:red">✗ Disabled</span>').'</td>';
    echo '<td>'.esc_html($f['frequency']??'daily').'</td>';
    echo '<td><a href="'.esc_url($this->url(['action'=>'edit','id'=>$f['id']])).'">Edit</a> | <a href="'.esc_url(wp_nonce_url($this->url(['action'=>'delete','id'=>$f['id']]),'wpfi_delete_'.$f['id'])).'" onclick="return confirm(\'Delete this feed?\')">Delete</a></td>';
    echo '</tr>';
   }
  }
  echo '</tbody></table>';
  echo '</div>';
 }
 private function edit(){
  $id=sanitize_text_field($_GET['id']??'');
  $f=wp_parse_args($id?($this->repo->get($id)??[]):[],$this->repo->defaults());
  $a=$f['auth']??[];
  $this->log("Feed edit form opened", 'info', ['feed_id'=>$id??'new']);
  echo '<div class="wrap">';
  echo '<h1>'.($id?'Edit':'Add').' XML Feed</h1>';
  echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" enctype="multipart/form-data">';
  echo '<input type="hidden" name="action" value="wpfi_save_feed">';
  echo '<input type="hidden" name="id" value="'.esc_attr($id).'">';
  wp_nonce_field('wpfi_save_feed');
  
  echo '<h2>Feed Settings</h2>';
  echo '<table class="form-table"><tbody>';
  echo '<tr><th><label for="name">Feed Name</label></th><td><input type="text" id="name" name="name" value="'.esc_attr($f['name']??'').'" class="regular-text" required></td></tr>';
  echo '<tr><th><label for="url">Base URL</label></th><td><input type="url" id="url" name="url" value="'.esc_attr($f['url']??'').'" class="regular-text" required></td></tr>';
  echo '<tr><th><label for="format">Format</label></th><td>';
  echo '<select id="format" name="format">';
  echo '<option value="xml" '.selected($f['format']??'xml','xml',false).'>XML</option>';
  echo '<option value="csv" '.selected($f['format']??'xml','csv',false).'>CSV</option>';
  echo '</select>';
  echo '</td></tr>';
  echo '<tr><th><label for="product_xpath">Product XPath</label></th><td><input type="text" id="product_xpath" name="product_xpath" value="'.esc_attr($f['product_xpath']??'').'" class="regular-text" placeholder="/products/product"></td></tr>';
  echo '<tr><th><label for="frequency">Import Frequency</label></th><td>';
  echo '<select id="frequency" name="frequency">';
  echo '<option value="daily" '.selected($f['frequency']??'daily','daily',false).'>Daily</option>';
  echo '<option value="twicedaily" '.selected($f['frequency']??'daily','twicedaily',false).'>Twice Daily</option>';
  echo '<option value="hourly" '.selected($f['frequency']??'daily','hourly',false).'>Hourly</option>';
  echo '</select>';
  echo '</td></tr>';
  echo '<tr><th><label for="enabled">Enabled</label></th><td><input type="checkbox" id="enabled" name="enabled" value="1" '.checked($f['enabled']??1,1,false).'></td></tr>';
  echo '<tr><th><label for="skip_zero_stock">Skip Zero Stock Items</label></th><td><input type="checkbox" id="skip_zero_stock" name="skip_zero_stock" value="1" '.checked($f['skip_zero_stock']??0,1,false).'> <span class="description">Skip products with stock_quantity &lt;= 0</span></td></tr>';
  echo '</tbody></table>';
  
  echo '<h2>Authentication</h2>';
  echo '<table class="form-table"><tbody>';
  echo '<tr><th><label for="auth_type">Authentication Type</label></th><td>';
  echo '<select id="auth_type" name="auth[type]">';
  echo '<option value="none" '.selected($a['type']??'none','none',false).'>None</option>';
  echo '<option value="api_key" '.selected($a['type']??'none','api_key',false).'>API Key</option>';
  echo '<option value="basic" '.selected($a['type']??'none','basic',false).'>Basic Auth</option>';
  echo '<option value="bearer" '.selected($a['type']??'none','bearer',false).'>Bearer Token</option>';
  echo '<option value="custom" '.selected($a['type']??'none','custom',false).'>Custom Header</option>';
  echo '</select>';
  echo '</td></tr>';
  echo '<tr><th><label for="auth_api_key">API Key</label></th><td><input type="text" id="auth_api_key" name="auth[api_key]" value="'.esc_attr($a['api_key']??'').'" class="regular-text"></td></tr>';
  echo '<tr><th><label for="auth_api_key_name">API Key Header</label></th><td><input type="text" id="auth_api_key_name" name="auth[api_key_name]" value="'.esc_attr($a['api_key_name']??'X-API-Key').'" class="regular-text"></td></tr>';
  echo '<tr><th><label for="auth_username">Username</label></th><td><input type="text" id="auth_username" name="auth[username]" value="'.esc_attr($a['username']??'').'" class="regular-text"></td></tr>';
  echo '<tr><th><label for="auth_password">Password</label></th><td><input type="password" id="auth_password" name="auth[password]" value="'.esc_attr($a['password']??'').'" class="regular-text"></td></tr>';
  echo '<tr><th><label for="auth_token">Bearer Token</label></th><td><input type="text" id="auth_token" name="auth[token]" value="'.esc_attr($a['token']??'').'" class="regular-text"></td></tr>';
  echo '<tr><th><label for="auth_header_name">Custom Header Name</label></th><td><input type="text" id="auth_header_name" name="auth[header_name]" value="'.esc_attr($a['header_name']??'').'" class="regular-text"></td></tr>';
  echo '<tr><th><label for="auth_header_value">Custom Header Value</label></th><td><input type="text" id="auth_header_value" name="auth[header_value]" value="'.esc_attr($a['header_value']??'').'" class="regular-text"></td></tr>';
  echo '<tr><th><label for="auth_query_params">Query Parameters</label></th><td>';
  echo '<textarea id="auth_query_params" name="auth[query_params]" rows="4" class="large-text code" placeholder="key1=value1&#10;key2=value2">'.esc_textarea($this->format_params($a['query_params']??[])).'</textarea>';
  echo '<p class="description">One key=value pair per line for query string parameters</p>';
  echo '</td></tr>';
  echo '<tr><th><label for="auth_path_params">Path Parameters</label></th><td>';
  echo '<textarea id="auth_path_params" name="auth[path_params]" rows="4" class="large-text code" placeholder="id=11305&#10;uid=bf672543-bc4c-40a9-a8c6-0ac6259bb4de">'.esc_textarea($this->format_params($a['path_params']??[])).'</textarea>';
  echo '<p class="description">One key=value pair per line. For Pinnacle feeds use:<br>id=11305<br>uid=bf672543-bc4c-40a9-a8c6-0ac6259bb4de</p>';
  echo '</td></tr>';
  echo '</tbody></table>';
  
  echo '<h2>Field Mappings</h2>';
  echo '<table class="form-table"><tbody>';
  echo '<tr><th><label for="map">Field Mappings</label></th><td>';
  echo '<textarea id="map" name="map" rows="10" class="large-text code" placeholder="name=name&#10;sku=sku&#10;price=price">'.esc_textarea($this->format_map($f['map']??[])).'</textarea>';
  echo '<p class="description">One mapping per line: WooCommerce_field=XML_selector<br>For Pinnacle use:<br>name=ProdName<br>sku=StockCode<br>description=TopCat<br>price=ProdPriceExclVAT<br>stock_quantity=ProdQty<br>image=ProdImg<br>category=category_tree</p>';
  echo '</td></tr>';
  echo '</tbody></table>';
  
  submit_button();
  echo '</form>';
  echo '</div>';
 }
 public function save(){
  try {
   $this->log("Save feed initiated");
   
   if(!$this->can()) {
    $this->log("Permission denied on save", 'error');
    wp_die('Permission denied.');
   }
   
   if(!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'wpfi_save_feed')) {
    $this->log("Nonce verification failed on save", 'error');
    wp_die('Security check failed.');
   }
   
   if(!isset($_POST['name']) || empty(trim($_POST['name']))) {
    $this->log("Feed name missing on save", 'error');
    wp_die('Feed name is required.');
   }
   
   if(!isset($_POST['url']) || empty(trim($_POST['url']))) {
    $this->log("Feed URL missing on save", 'error');
    wp_die('Feed URL is required.');
   }

   $f=$this->repo->defaults();
   
   $f['id']=sanitize_text_field($_POST['id']??'');
   if(empty($f['id'])) {
    $f['id']=wp_generate_uuid4();
    $this->log("New feed created with ID: ".$f['id']);
   } else {
    $this->log("Updating existing feed: ".$f['id']);
   }
   
   $f['name']=sanitize_text_field($_POST['name']??'');
   $f['url']=esc_url_raw($_POST['url']??'');
   
   $format=$_POST['format']??'xml';
   $f['format']=in_array($format,['xml','csv'],true)?sanitize_key($format):'xml';
   
   $f['product_xpath']=sanitize_text_field($_POST['product_xpath']??'');
   
   $frequency=$_POST['frequency']??'daily';
   $f['frequency']=in_array($frequency,['daily','twicedaily','hourly'],true)?sanitize_key($frequency):'daily';
   
   $f['enabled']=isset($_POST['enabled']) && $_POST['enabled']==='1'?1:0;
   $f['skip_zero_stock']=isset($_POST['skip_zero_stock']) && $_POST['skip_zero_stock']==='1'?1:0;
   
   $this->log("Feed basic settings saved", 'info', ['feed'=>$f['name'], 'format'=>$f['format'], 'enabled'=>$f['enabled']]);
   
   $auth=$f['auth']??[];
   $auth['type']=sanitize_key($_POST['auth']['type']??'none');
   $auth['api_key']=sanitize_text_field($_POST['auth']['api_key']??'');
   $auth['api_key_name']=sanitize_text_field($_POST['auth']['api_key_name']??'X-API-Key');
   $auth['username']=sanitize_user($_POST['auth']['username']??'');
   $auth['password']=sanitize_text_field($_POST['auth']['password']??'');
   $auth['token']=sanitize_text_field($_POST['auth']['token']??'');
   $auth['header_name']=sanitize_text_field($_POST['auth']['header_name']??'');
   $auth['header_value']=sanitize_text_field($_POST['auth']['header_value']??'');
   
   if(!empty($_POST['auth']['query_params'])) {
    $query_params=sanitize_textarea_field(wp_unslash($_POST['auth']['query_params']??''));
    $auth['query_params']=$this->parse_params($query_params);
    $this->log("Query parameters set", 'info', ['count'=>count($auth['query_params'])]);
   } else {
    $auth['query_params']=[];
   }
   
   if(!empty($_POST['auth']['path_params'])) {
    $path_params=sanitize_textarea_field(wp_unslash($_POST['auth']['path_params']??''));
    $auth['path_params']=$this->parse_params($path_params);
    $this->log("Path parameters set", 'info', ['count'=>count($auth['path_params']), 'keys'=>implode(',',array_keys($auth['path_params']))]);
   } else {
    $auth['path_params']=[];
   }
   
   $f['auth']=$auth;
   $this->log("Authentication settings saved", 'info', ['type'=>$auth['type']]);
   
   if(!empty($_POST['map'])) {
    $map_raw=sanitize_textarea_field(wp_unslash($_POST['map']??''));
    $f['map']=$this->parse_map($map_raw);
    $this->log("Field mappings saved", 'info', ['count'=>count($f['map']), 'fields'=>implode(',',array_keys($f['map']))]);
   } else {
    $f['map']=[];
   }
   
   $this->repo->save($f);
   $this->log("Feed saved to repository", 'info', ['feed_id'=>$f['id']]);
   
   if(is_callable([$this->scheduler,'schedule'])) {
    $this->scheduler->schedule($f);
    $this->log("Feed scheduled", 'info', ['frequency'=>$f['frequency']]);
   }
   
   wp_safe_redirect($this->url(['notice'=>'saved']));
   exit;
  } catch(Exception $e) {
   $error_msg='Error saving feed: '.$e->getMessage();
   $this->log($error_msg, 'error', ['exception'=>$e->getTraceAsString()]);
   wp_die($error_msg);
  }
 }
 public function delete(){
  try {
   $id=sanitize_text_field($_GET['id']??'');
   $this->log("Delete feed initiated", 'info', ['feed_id'=>$id]);
   
   if(!$this->can()) {
    $this->log("Permission denied on delete", 'error');
    wp_die('Permission denied.');
   }
   
   if(empty($id)) {
    $this->log("Feed ID missing on delete", 'error');
    wp_die('Feed ID is required.');
   }
   
   if(!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'wpfi_delete_'.$id)) {
    $this->log("Nonce verification failed on delete", 'error', ['feed_id'=>$id]);
    wp_die('Security check failed.');
   }
   
   $this->repo->delete($id);
   $this->log("Feed deleted from repository", 'info', ['feed_id'=>$id]);
   
   if(is_callable([$this->scheduler,'unschedule'])) {
    $this->scheduler->unschedule($id);
    $this->log("Feed unscheduled", 'info', ['feed_id'=>$id]);
   }
   
   wp_safe_redirect($this->url(['notice'=>'deleted']));
   exit;
  } catch(Exception $e) {
   $error_msg='Error deleting feed: '.$e->getMessage();
   $this->log($error_msg, 'error', ['exception'=>$e->getTraceAsString()]);
   wp_die($error_msg);
  }
 }
 private function format_params($params){
  if(empty($params))return '';
  $lines=[];
  foreach((array)$params as $k=>$v) {
   if(!empty($k)) {
    $lines[]=$k.'='.(string)$v;
   }
  }
  return implode("\n",$lines);
 }
 private function parse_params($raw){
  $raw=(string)$raw;
  if(empty($raw))return [];
  $lines=preg_split('/\r\n|\r|\n/',$raw);
  $params=[];
  foreach($lines as $line){
   $line=trim($line);
   if($line==='')continue;
   if(strpos($line,'=')===false)continue;
   $parts=explode('=',$line,2);
   if(count($parts)!==2)continue;
   $key=trim($parts[0]);
   $value=trim($parts[1]);
   if($key!=='')$params[$key]=$value;
  }
  return $params;
 }
 private function format_map($map){
  if(empty($map))return '';
  $lines=[];
  foreach((array)$map as $k=>$v) {
   if(!empty($k)) {
    $lines[]=$k.'='.(string)$v;
   }
  }
  return implode("\n",$lines);
 }
 private function parse_map($raw){
  $raw=(string)$raw;
  if(empty($raw))return [];
  $lines=preg_split('/\r\n|\r|\n/',$raw);
  $map=[];
  foreach($lines as $line){
   $line=trim($line);
   if($line==='')continue;
   if(strpos($line,'=')===false)continue;
   $parts=explode('=',$line,2);
   if(count($parts)!==2)continue;
   $key=trim($parts[0]);
   $value=trim($parts[1]);
   if($key!=='')$map[$key]=$value;
  }
  return $map;
 }
}
