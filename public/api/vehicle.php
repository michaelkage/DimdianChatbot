<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/app/customer.php';
require_once dirname(__DIR__,2).'/app/vehicle.php';
require_method('POST');
$customer=require_customer();
if(rate_limit_exceeded('vehicle-submit:'.$customer['id'],8,3600))json_response(['error'=>'too_many_requests'],429);
$in=request_json();
$id=$in['conversation_id']??'';$make=trim((string)($in['make']??''));$model=trim((string)($in['model']??''));
$year=filter_var($in['year']??null,FILTER_VALIDATE_INT);$engine=trim((string)($in['engine']??''));$transmission=trim((string)($in['transmission']??''));$trim=trim((string)($in['trim']??''));$vin=strtoupper(trim((string)($in['vin']??'')));
if(!is_string($id)||!preg_match('/^[a-f0-9]{26}$/D',$id)||$make===''||$model===''||$year===false||$year<1950||$year>((int)gmdate('Y')+1)||strlen($make)>100||strlen($model)>120||strlen($engine)>120||strlen($transmission)>100||strlen($trim)>120)json_response(['error'=>'invalid_vehicle_details'],422);
if($vin!==''&&!preg_match('/^[A-HJ-NPR-Z0-9]{17}$/D',$vin))json_response(['error'=>'invalid_vin'],422);
try{$encrypted=$vin!==''?vehicle_encrypt_vin($vin):null;}catch(RuntimeException $e){json_response(['error'=>$e->getMessage()],503);}
$pdo=db();$find=$pdo->prepare("SELECT id,context_sku,status FROM conversations WHERE public_id=:id AND customer_id=:customer AND status<>'closed' LIMIT 1");
$find->execute(['id'=>$id,'customer'=>$customer['id']]);$conversation=$find->fetch();
if(!$conversation)json_response(['error'=>'conversation_not_found'],404);
$productId=isset($in['product_id'])?filter_var($in['product_id'],FILTER_VALIDATE_INT):null;
if($productId===false||($productId!==null&&$productId<1))json_response(['error'=>'invalid_product_id'],422);
if(!$productId&&$conversation['context_sku']&&woocommerce_ready()){
 try{$product=woocommerce_find_by_sku((string)$conversation['context_sku']);$productId=$product['id']??null;}catch(Throwable $e){$productId=null;}
}
$pdo->beginTransaction();
try{
 $insert=$pdo->prepare('INSERT INTO conversation_vehicles (conversation_id,product_id,make,model,model_year,engine,transmission,trim_level,vin_ciphertext,vin_last4,created_at) VALUES (:cid,:pid,:make,:model,:year,:engine,:transmission,:trim,:vin,:last4,UTC_TIMESTAMP())');
 $insert->execute(['cid'=>$conversation['id'],'pid'=>$productId,'make'=>$make,'model'=>$model,'year'=>$year,'engine'=>$engine!==''?$engine:null,'transmission'=>$transmission!==''?$transmission:null,'trim'=>$trim!==''?$trim:null,'vin'=>$encrypted,'last4'=>$vin!==''?substr($vin,-4):null]);
 $vehicleId=(int)$pdo->lastInsertId();
 $fitment=null;
 if($productId&&$engine!==''&&$transmission!==''){
  $q=$pdo->prepare("SELECT id,verification_source,verified_at FROM product_fitments WHERE product_id=:pid AND status='verified' AND make=:make AND model=:model AND :year BETWEEN year_from AND year_to AND engine=:engine AND transmission=:transmission AND (trim_level IS NULL OR trim_level=:trim) ORDER BY verified_at DESC LIMIT 1");
  $q->execute(['pid'=>$productId,'make'=>$make,'model'=>$model,'year'=>$year,'engine'=>$engine,'transmission'=>$transmission,'trim'=>$trim!==''?$trim:null]);
  $fitment=$q->fetch()?:null;
 }
 if(!$fitment){
  $pdo->prepare("UPDATE conversations SET status='needs_human',last_message_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=:id AND status='open'")->execute(['id'=>$conversation['id']]);
  $notice='Vehicle details received. A parts specialist must verify compatibility before we recommend a part.';
 }else{
  $notice='A verified fitment record was found for the selected listing and vehicle details.';
 }
 $pdo->prepare("INSERT INTO messages (conversation_id,sender_type,body,created_at) VALUES (:id,'system',:body,UTC_TIMESTAMP())")->execute(['id'=>$conversation['id'],'body'=>$notice]);
 $pdo->commit();
 json_response(['vehicle_id'=>$vehicleId,'fitment'=>$fitment?['status'=>'verified','source'=>$fitment['verification_source'],'verified_at'=>$fitment['verified_at']]:['status'=>'needs_specialist'],'product_id'=>$productId],201);
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
