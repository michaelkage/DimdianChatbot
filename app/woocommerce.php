<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

/** WooCommerce credentials must be a read-only REST API key, stored only in private config.php. */
function woocommerce_config(): array {
 $value=app_config('woocommerce',[]);
 return is_array($value)?$value:[];
}
function woocommerce_ready(): bool {
 $c=woocommerce_config();
 if (!(bool)($c['enabled']??false) || !function_exists('curl_init')) return false;
 $url=$c['store_url']??'';$key=$c['consumer_key']??'';$secret=$c['consumer_secret']??'';
 if (!is_string($url)||!is_string($key)||!is_string($secret)||$key===''||$secret==='') return false;
 $parts=parse_url($url);
 return is_array($parts)&&strtolower((string)($parts['scheme']??''))==='https'&&!empty($parts['host']);
}
function woocommerce_request(string $path,array $query=[]): array {
 if (!woocommerce_ready()) throw new RuntimeException('catalogue_not_configured');
 if (!str_starts_with($path,'/wp-json/')||str_contains($path,'..')) throw new RuntimeException('invalid_catalogue_path');
 $c=woocommerce_config();$base=rtrim((string)$c['store_url'],'/');
 $url=$base.$path.($query?'?'.http_build_query($query,'','&',PHP_QUERY_RFC3986):'');
 $ch=curl_init($url);
 if (!$ch) throw new RuntimeException('catalogue_transport_unavailable');
 curl_setopt_array($ch,[
  CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPGET=>true,CURLOPT_USERPWD=>$c['consumer_key'].':'.$c['consumer_secret'],
  CURLOPT_HTTPAUTH=>CURLAUTH_BASIC,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>8,
  CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
  CURLOPT_HTTPHEADER=>['Accept: application/json','User-Agent: DimdianChatbot-Catalogue/1.0'],
 ]);
 $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$errno=curl_errno($ch);curl_close($ch);
 if ($raw===false) {error_log('[DimdianChatbot WooCommerce] Transport error '.$errno);throw new RuntimeException('catalogue_unavailable');}
 if ($status<200||$status>=300) {error_log('[DimdianChatbot WooCommerce] Read request failed with HTTP '.$status);throw new RuntimeException($status===401||$status===403?'catalogue_credentials_invalid':'catalogue_unavailable');}
 $data=json_decode((string)$raw,true);
 if (!is_array($data)) throw new RuntimeException('catalogue_invalid_response');
 return $data;
}
function woocommerce_normalize_product(array $p): array {
 $images=is_array($p['images']??null)?$p['images']:[];
 $image=$images[0]['src']??null;
 return [
  'id'=>(int)($p['id']??0),'name'=>(string)($p['name']??''),
  'sku'=>(string)($p['sku']??''),'price'=>(string)($p['price']??''),
  'regular_price'=>(string)($p['regular_price']??''),'sale_price'=>(string)($p['sale_price']??''),
  'stock_status'=>(string)($p['stock_status']??'unknown'),
  'stock_quantity'=>array_key_exists('stock_quantity',$p)?$p['stock_quantity']:null,
  'permalink'=>(string)($p['permalink']??''),'image'=>is_string($image)?$image:null,
  'type'=>(string)($p['type']??'simple'),
 ];
}
/** Search live WooCommerce data. MPN requires an explicitly configured WP REST lookup route. */
function woocommerce_search_products(string $type,string $term): array {
 $term=trim($term);
 if ($term===''||strlen($term)>100) throw new InvalidArgumentException('invalid_search_term');
 if ($type==='sku') {
  $rows=woocommerce_request('/wp-json/wc/v3/products',['sku'=>$term,'per_page'=>10]);
  $rows=array_values(array_filter($rows,static fn($p)=>is_array($p)&&strcasecmp((string)($p['sku']??''),$term)===0));
 } elseif ($type==='name') {
  $rows=woocommerce_request('/wp-json/wc/v3/products',['search'=>$term,'per_page'=>10,'orderby'=>'relevance']);
 } elseif ($type==='mpn') {
  $path=(string)(woocommerce_config()['mpn_lookup_path']??'');
  if ($path==='') throw new RuntimeException('mpn_lookup_not_configured');
  $rows=woocommerce_request($path,['mpn'=>$term,'per_page'=>10]);
 } else throw new InvalidArgumentException('invalid_search_type');
 $out=[];
 foreach ($rows as $p) if (is_array($p)) $out[]=woocommerce_normalize_product($p);
 return $out;
}
function woocommerce_find_by_sku(string $sku): ?array {
 $items=woocommerce_search_products('sku',$sku);
 return $items[0]??null;
}
