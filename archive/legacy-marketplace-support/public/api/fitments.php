<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/app/staff.php';
require_once dirname(__DIR__,2).'/app/woocommerce.php';
require_method('POST');
$staff=require_staff();
if(($staff['role']??'')!=='admin')json_response(['error'=>'admin_required'],403);
require_staff_csrf();

$input=request_json();
$conversationId=$input['conversation_id']??'';
$source=$input['verification_source']??'';
$productId=array_key_exists('product_id',$input)?filter_var($input['product_id'],FILTER_VALIDATE_INT):null;
if(!is_string($conversationId)||!preg_match('/^[a-f0-9]{26}$/D',$conversationId)||
   !is_string($source)||trim($source)===''||strlen(trim($source))>255||
   $productId===false||($productId!==null&&$productId<1)){
 json_response(['error'=>'invalid_fitment_verification'],422);
}
$source=trim($source);
if(!woocommerce_ready())json_response(['error'=>'catalogue_not_configured'],503);

$pdo=db();
$find=$pdo->prepare('SELECT c.id,v.id AS vehicle_id,v.product_id,v.make,v.model,v.model_year,v.engine,v.transmission,v.trim_level FROM conversations c INNER JOIN conversation_vehicles v ON v.conversation_id=c.id WHERE c.public_id=:public_id ORDER BY v.id DESC LIMIT 1');
$find->execute(['public_id'=>$conversationId]);
$vehicle=$find->fetch();
if(!$vehicle)json_response(['error'=>'vehicle_details_not_found'],404);

$productId=$productId?:($vehicle['product_id']?(int)$vehicle['product_id']:null);
if(!$productId||empty($vehicle['engine'])||empty($vehicle['transmission']))json_response(['error'=>'complete_vehicle_and_product_details_required'],422);
try{
 $product=woocommerce_find_by_id((int)$productId);
}catch(Throwable $error){
 error_log('[DimdianChatbot] Staff fitment catalogue verification failed: '.$error->getMessage());
 json_response(['error'=>'catalogue_product_verification_failed'],502);
}
if(!$product||$product['permalink']==='')json_response(['error'=>'catalogue_product_not_found'],404);
if(!woocommerce_fitment_product_supported($product))json_response(['error'=>'specific_product_variant_required'],422);

// Treat verification as one atomic, repeat-safe operation. Re-submitting the same
// exact vehicle/product tuple updates its evidence instead of creating duplicates.
$pdo->beginTransaction();
try{
 // Serialize against new customer vehicle submissions before persisting this verification.
 $conversationLock=$pdo->prepare('SELECT id FROM conversations WHERE id=:id FOR UPDATE');
 $conversationLock->execute(['id'=>$vehicle['id']]);
 if(!$conversationLock->fetchColumn()){$pdo->rollBack();json_response(['error'=>'conversation_not_found'],404);}
 $latestVehicle=$pdo->prepare('SELECT id AS vehicle_id,product_id,make,model,model_year,engine,transmission,trim_level FROM conversation_vehicles WHERE conversation_id=:id ORDER BY id DESC LIMIT 1 FOR UPDATE');
 $latestVehicle->execute(['id'=>$vehicle['id']]);
 $currentVehicle=$latestVehicle->fetch();
 if(!$currentVehicle|| (int)$currentVehicle['vehicle_id']!==(int)$vehicle['vehicle_id']){
  $pdo->rollBack();json_response(['error'=>'vehicle_details_changed'],409);
 }
 $vehicle=$currentVehicle;
 $existing=$pdo->prepare("SELECT id FROM product_fitments WHERE product_id=:product_id AND make=:make AND model=:model AND year_from=:year_from AND year_to=:year_to AND engine=:engine AND transmission=:transmission AND trim_level <=> :trim AND status='verified' LIMIT 1 FOR UPDATE");
 $existing->execute([
  'product_id'=>$productId,'make'=>$vehicle['make'],'model'=>$vehicle['model'],
  'year_from'=>(int)$vehicle['model_year'],'year_to'=>(int)$vehicle['model_year'],
  'engine'=>$vehicle['engine'],'transmission'=>$vehicle['transmission'],'trim'=>$vehicle['trim_level'],
 ]);
 $fitmentId=$existing->fetchColumn();
 if($fitmentId!==false){
  $update=$pdo->prepare('UPDATE product_fitments SET verification_source=:source,verified_by=:staff_id,verified_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=:id');
  $update->execute(['source'=>$source,'staff_id'=>$staff['id'],'id'=>$fitmentId]);
  $fitmentId=(int)$fitmentId;
  $created=false;
 }else{
  $insert=$pdo->prepare("INSERT INTO product_fitments (product_id,make,model,year_from,year_to,engine,transmission,trim_level,verification_source,verified_by,verified_at,status) VALUES (:product_id,:make,:model,:year_from,:year_to,:engine,:transmission,:trim,:source,:staff_id,UTC_TIMESTAMP(),'verified')");
  $insert->execute([
   'product_id'=>$productId,'make'=>$vehicle['make'],'model'=>$vehicle['model'],
   'year_from'=>(int)$vehicle['model_year'],'year_to'=>(int)$vehicle['model_year'],
   'engine'=>$vehicle['engine'],'transmission'=>$vehicle['transmission'],'trim'=>$vehicle['trim_level'],
   'source'=>$source,'staff_id'=>$staff['id'],
  ]);
  $fitmentId=(int)$pdo->lastInsertId();
  $created=true;
 }
 $pdo->prepare('UPDATE conversation_vehicles SET product_id=:product_id WHERE id=:id')->execute(['product_id'=>$productId,'id'=>$vehicle['vehicle_id']]);
 $pdo->commit();
 json_response(['verified'=>true,'created'=>$created,'fitment_id'=>$fitmentId,'product_id'=>$productId,'product_name'=>(string)$product['name']],$created?201:200);
}catch(Throwable $error){
 if($pdo->inTransaction())$pdo->rollBack();
 throw $error;
}
