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
 if(!is_array($parts)||empty($parts['host'])||isset($parts['user'])||isset($parts['pass'])||isset($parts['query'])||isset($parts['fragment']))return false;
 $scheme=strtolower((string)($parts['scheme']??''));
 $secure=$scheme==='https'||(app_config('app.environment','production')==='test'&&$scheme==='http');
 $port=(int)($parts['port']??($scheme==='https'?443:80));
 return $secure&&$port>=1&&$port<=65535;
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
  CURLOPT_PROTOCOLS=>(app_config('app.environment','production')==='test'?(CURLPROTO_HTTP|CURLPROTO_HTTPS):CURLPROTO_HTTPS),
  CURLOPT_MAXFILESIZE=>2*1024*1024,
  CURLOPT_HTTPHEADER=>['Accept: application/json','User-Agent: DimdianChatbot-Catalogue/1.0'],
 ]);
 $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$errno=curl_errno($ch);curl_close($ch);
 if ($raw===false) {error_log('[DimdianChatbot WooCommerce] Transport error '.$errno);throw new RuntimeException('catalogue_unavailable');}
 if ($status<200||$status>=300) {error_log('[DimdianChatbot WooCommerce] Read request failed with HTTP '.$status);throw new RuntimeException($status===401||$status===403?'catalogue_credentials_invalid':'catalogue_unavailable');}
 $data=json_decode((string)$raw,true);
 if (!is_array($data)) throw new RuntimeException('catalogue_invalid_response');
 return $data;
}
function woocommerce_public_permalink(mixed $url): string {
 if(!is_string($url)||$url==='')return '';
 $parts=parse_url($url);$store=parse_url((string)(woocommerce_config()['store_url']??''));
 $scheme=strtolower((string)($parts['scheme']??''));
 $test=app_config('app.environment','production')==='test';
 if(!is_array($parts)||!is_array($store)||($scheme!=='https'&&!($test&&$scheme==='http'))||
    empty($parts['host'])||empty($store['host'])||strtolower((string)$parts['host'])!==strtolower((string)$store['host'])||
    isset($parts['user'])||isset($parts['pass']))return '';
 $port=(int)($parts['port']??($scheme==='https'?443:80));
 $storeScheme=strtolower((string)($store['scheme']??'https'));
 $storePort=(int)($store['port']??($storeScheme==='https'?443:80));
 if($port!==$storePort)return '';
 return $url;
}
function woocommerce_public_image(mixed $url): ?string {
 if(!is_string($url)||$url==='')return null;
 $parts=parse_url($url);
 $scheme=strtolower((string)($parts['scheme']??''));
 if(!is_array($parts)||($scheme!=='https'&&!(app_config('app.environment','production')==='test'&&$scheme==='http'))||empty($parts['host'])||isset($parts['user'])||isset($parts['pass']))return null;
 return $url;
}
function woocommerce_find_by_id(int $productId): ?array {
 if($productId<1)return null;
 $product=woocommerce_request('/wp-json/wc/v3/products/'.$productId);
 if(!is_array($product)||(int)($product['id']??0)!==$productId||($product['status']??'')!=='publish')return null;
 return woocommerce_normalize_product($product);
}
/** Fitment records currently attach to a concrete, non-variable WooCommerce listing. */
function woocommerce_fitment_product_supported(array $product): bool {
 return ($product['type']??'')==='simple';
}
function woocommerce_normalize_product(array $p): array {
 $images=is_array($p['images']??null)?$p['images']:[];
 $image=$images[0]['src']??null;
 return [
  'id'=>(int)($p['id']??0),'name'=>(string)($p['name']??''),
  'sku'=>(string)($p['sku']??''),'mpn'=>(string)($p['mpn']??''),'price'=>(string)($p['price']??''),
  'regular_price'=>(string)($p['regular_price']??''),'sale_price'=>(string)($p['sale_price']??''),
  'stock_status'=>(string)($p['stock_status']??'unknown'),
  'stock_quantity'=>array_key_exists('stock_quantity',$p)?$p['stock_quantity']:null,
  'permalink'=>woocommerce_public_permalink($p['permalink']??null),'image'=>woocommerce_public_image($image),
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
 foreach ($rows as $p) {
  if(!is_array($p)||($p['status']??'')!=='publish'||(int)($p['id']??0)<1)continue;
  $out[]=woocommerce_normalize_product($p);
 }
 return $out;
}
function woocommerce_find_by_sku(string $sku): ?array {
 $items=woocommerce_search_products('sku',$sku);
 return $items[0]??null;
}
