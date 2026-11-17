<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/app/staff.php';
require_once dirname(__DIR__,2).'/app/woocommerce.php';
require_method('POST');
$staff=require_staff();
if(($staff['role']??'')!=='admin')json_response(['error'=>'admin_required'],403);
require_staff_csrf();
$input=request_json();
$conversationId=$input['conversation_id']??'';$source=trim((string)($input['verification_source']??''));
$productId=filter_var($input['product_id']??null,FILTER_VALIDATE_INT);
if(!is_string($conversationId)||!preg_match('/^[a-f0-9]{26}$/D',$conversationId)||$source===''||strlen($source)>255||$productId===false||($productId!==null&&$productId<1))json_response(['error'=>'invalid_fitment_verification'],422);
if(!woocommerce_ready())json_response(['error'=>'catalogue_not_configured'],503);
$pdo=db();
$find=$pdo->prepare('SELECT c.id,v.id AS vehicle_id,v.product_id,v.make,v.model,v.model_year,v.engine,v.transmission,v.trim_level FROM conversations c INNER JOIN conversation_vehicles v ON v.conversation_id=c.id WHERE c.public_id=:public_id ORDER BY v.id DESC LIMIT 1');
$find->execute(['public_id'=>$conversationId]);$vehicle=$find->fetch();
if(!$vehicle)json_response(['error'=>'vehicle_details_not_found'],404);
$productId=$productId?:($vehicle['product_id']?(int)$vehicle['product_id']:null);
if(!$productId||empty($vehicle['engine'])||empty($vehicle['transmission']))json_response(['error'=>'complete_vehicle_and_product_details_required'],422);
try{$product=woocommerce_request('/wp-json/wc/v3/products/'.(int)$productId);}
catch(Throwable $e){json_response(['error'=>'catalogue_product_verification_failed'],502);}
if(!is_array($product)|| (int)($product['id']??0)!==$productId)json_response(['error'=>'catalogue_product_not_found'],404);
$insert=$pdo->prepare("INSERT INTO product_fitments (product_id,make,model,year_from,year_to,engine,transmission,trim_level,verification_source,verified_by,verified_at,status) VALUES (:product_id,:make,:model,:year,:year,:engine,:transmission,:trim,:source,:staff_id,UTC_TIMESTAMP(),'verified')");
$insert->execute([
 'product_id'=>$productId,'make'=>$vehicle['make'],'model'=>$vehicle['model'],'year'=>(int)$vehicle['model_year'],
 'engine'=>$vehicle['engine'],'transmission'=>$vehicle['transmission'],'trim'=>$vehicle['trim_level'],
 'source'=>$source,'staff_id'=>$staff['id'],
]);
$pdo->prepare('UPDATE conversation_vehicles SET product_id=:product_id WHERE id=:id')->execute(['product_id'=>$productId,'id'=>$vehicle['vehicle_id']]);
json_response(['verified'=>true,'fitment_id'=>(int)$pdo->lastInsertId(),'product_id'=>$productId,'product_name'=>(string)($product['name']??'')],201);
