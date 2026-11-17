<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/app/customer.php';
require_once dirname(__DIR__,2).'/app/woocommerce.php';
require_method('GET');
$customer=require_customer();
if(rate_limit_exceeded('catalogue-search-ip:'.request_ip_hash(),120,60)||rate_limit_exceeded('catalogue-search:'.$customer['id'],20,60))json_response(['error'=>'too_many_requests'],429);
$type=$_GET['type']??'name';$q=$_GET['q']??'';
if(!is_string($type)||!in_array($type,['name','sku','mpn'],true)||!is_string($q)||trim($q)===''||strlen($q)>100)json_response(['error'=>'invalid_search'],422);
try{
 $products=woocommerce_search_products($type,$q);
 json_response(['products'=>$products,'source'=>'woocommerce_live','currency'=>(string)(woocommerce_config()['currency_code']??''),'as_of'=>gmdate('c')]);
}catch(InvalidArgumentException $e){json_response(['error'=>'invalid_search'],422);}
catch(RuntimeException $e){
 $code=$e->getMessage();
 $status=match($code){'catalogue_not_configured'=>503,'catalogue_credentials_invalid'=>503,'mpn_lookup_not_configured'=>501,default=>502};
 json_response(['error'=>$code],$status);
}
