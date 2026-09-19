<?php
if (!defined('ABSPATH')) exit;
final class WPFI_Feed_Repository {
 const OPTION='wpfi_feeds_v3';
 public function all(){ $v=get_option(self::OPTION,[]); return is_array($v)?array_values($v):[]; }
 public function get($id){ foreach($this->all() as $f) if(($f['id']??'')===$id)return $f; return null; }
 public function save(array $feed){ $feeds=$this->all();$feed['id']=$feed['id']?:wp_generate_uuid4();$found=false;foreach($feeds as $i=>$old)if(($old['id']??'')===$feed['id']){$feeds[$i]=$feed;$found=true;break;}if(!$found)$feeds[]=$feed;update_option(self::OPTION,array_values($feeds),false);return $feed['id']; }
 public function delete($id){ update_option(self::OPTION,array_values(array_filter($this->all(),static function($f)use($id){return ($f['id']??'')!==$id;})),false); }
 public function defaults(){return ['id'=>'','name'=>'','slug'=>'','url'=>'','format'=>'csv','enabled'=>1,'frequency'=>'daily','product_xpath'=>'/products/product','variation_xpath'=>'','identifier_field'=>'sku','variation_identifier_field'=>'sku','map'=>['name'=>'ProductName','sku'=>'ProductCode','category'=>'Category','description'=>'ProductSummary','price'=>'Price','stock_quantity'=>'AvailableQty','image'=>'Image'],'attributes'=>[],'namespaces'=>[],'csv'=>['delimiter'=>',','enclosure'=>'"','has_header'=>1,'encoding'=>'UTF-8'],'auth'=>['type'=>'none','api_key_name'=>'X-API-Key','api_key'=>'','bearer_token'=>'','username'=>'','password'=>'','query_params'=>[],'headers'=>[]]];}
}
