<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/app/customer.php';
require_once dirname(__DIR__,2).'/app/woocommerce.php';
require_method('POST');
$customer=require_customer();
if(rate_limit_exceeded('product-context:'.$customer['id'],30,3600))json_response(['error'=>'too_many_requests'],429);
$input=request_json();
$conversationId=$input['conversation_id']??null;
$productId=isset($input['product_id'])?filter_var($input['product_id'],FILTER_VALIDATE_INT):false;
if(!is_string($conversationId)||!preg_match('/^[a-f0-9]{26}$/D',$conversationId)||$productId===false||$productId===null||$productId<1)json_response(['error'=>'invalid_product_context'],422);
$find=db()->prepare("SELECT id,status FROM conversations WHERE public_id=:public_id AND customer_id=:customer_id LIMIT 1");
$find->execute(['public_id'=>$conversationId,'customer_id'=>$customer['id']]);
$conversation=$find->fetch();
if(!$conversation)json_response(['error'=>'conversation_not_found'],404);
if($conversation['status']==='closed')json_response(['error'=>'conversation_closed'],409);
try{
 $product=woocommerce_find_by_id((int)$productId);
}catch(Throwable $error){
 error_log('[DimdianChatbot] Product context lookup failed: '.$error->getMessage());
 json_response(['error'=>'catalogue_unavailable'],502);
}
if(!$product)json_response(['error'=>'catalogue_product_not_found'],404);
if($product['permalink']==='')json_response(['error'=>'product_link_unavailable'],422);
$pdo=db();
$pdo->beginTransaction();
try{
 $upsert=$pdo->prepare('INSERT INTO conversation_product_context (conversation_id,product_id,sku,product_name,product_permalink,selected_at) VALUES (:conversation_id,:product_id,:sku,:name,:permalink,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE product_id=VALUES(product_id),sku=VALUES(sku),product_name=VALUES(product_name),product_permalink=VALUES(product_permalink),selected_at=UTC_TIMESTAMP()');
 $upsert->execute(['conversation_id'=>$conversation['id'],'product_id'=>$product['id'],'sku'=>$product['sku']!==''?$product['sku']:null,'name'=>$product['name'],'permalink'=>$product['permalink']]);
 $pdo->prepare('UPDATE conversations SET updated_at=UTC_TIMESTAMP() WHERE id=:id')->execute(['id'=>$conversation['id']]);
 $pdo->commit();
 json_response(['selected'=>true,'product'=>['id'=>$product['id'],'name'=>$product['name'],'sku'=>$product['sku'],'permalink'=>$product['permalink']]]);
}catch(Throwable $error){
 if($pdo->inTransaction())$pdo->rollBack();
 throw $error;
}
