<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/app/customer.php';
require_once dirname(__DIR__,2).'/app/vehicle.php';
require_method('POST');
$customer=require_customer();
if(rate_limit_exceeded('vehicle-submit-ip:'.request_ip_hash(),30,3600)||rate_limit_exceeded('vehicle-submit:'.$customer['id'],8,3600))json_response(['error'=>'too_many_requests'],429);
$in=request_json();
foreach(['conversation_id','make','model'] as $required)if(!isset($in[$required])||!is_string($in[$required]))json_response(['error'=>'invalid_vehicle_details'],422);
foreach(['engine','transmission','trim','vin'] as $optional)if(isset($in[$optional])&&!is_string($in[$optional]))json_response(['error'=>'invalid_vehicle_details'],422);
$id=$in['conversation_id'];$make=trim($in['make']);$model=trim($in['model']);
$year=filter_var($in['year']??null,FILTER_VALIDATE_INT);$engine=trim($in['engine']??'');$transmission=trim($in['transmission']??'');$trim=trim($in['trim']??'');$vin=strtoupper(trim($in['vin']??''));
if(!is_string($id)||!preg_match('/^[a-f0-9]{26}$/D',$id)||$make===''||$model===''||$year===false||$year<1950||$year>((int)gmdate('Y')+1)||strlen($make)>100||strlen($model)>120||strlen($engine)>120||strlen($transmission)>100||strlen($trim)>120)json_response(['error'=>'invalid_vehicle_details'],422);
if($vin!==''&&!preg_match('/^[A-HJ-NPR-Z0-9]{17}$/D',$vin))json_response(['error'=>'invalid_vin'],422);
try{$encrypted=$vin!==''?vehicle_encrypt_vin($vin):null;}catch(RuntimeException $e){json_response(['error'=>$e->getMessage()],503);}
$pdo=db();$find=$pdo->prepare("SELECT id,context_sku,status FROM conversations WHERE public_id=:id AND customer_id=:customer AND status<>'closed' LIMIT 1");
$find->execute(['id'=>$id,'customer'=>$customer['id']]);$conversation=$find->fetch();
if(!$conversation)json_response(['error'=>'conversation_not_found'],404);
// Resolve the selected product or original page SKU against WooCommerce outside the DB transaction.
$selectedContext=db()->prepare('SELECT product_id FROM conversation_product_context WHERE conversation_id=:id LIMIT 1');
$selectedContext->execute(['id'=>$conversation['id']]);
$selectedValue=$selectedContext->fetchColumn();
$selectedId=$selectedValue===false||$selectedValue===null?null:(int)$selectedValue;
$originalSku=$conversation['context_sku'];
$productId=null;$productType='unknown';
if(woocommerce_ready()){
 try{
  $product=$selectedId!==null?woocommerce_find_by_id($selectedId):($originalSku?woocommerce_find_by_sku((string)$originalSku):null);
  // Preserve the actual selected listing ID for staff, but only simple products can
  // participate in automatic parent-level matching until variation IDs are modeled.
  $productId=$product['id']??null;$productType=$product['type']??'unknown';
 }catch(Throwable $e){$productId=null;}
}
$pdo->beginTransaction();
try{
 $lock=$pdo->prepare("SELECT id,status,context_sku FROM conversations WHERE id=:id AND customer_id=:customer_id FOR UPDATE");
 $lock->execute(['id'=>$conversation['id'],'customer_id'=>$customer['id']]);$locked=$lock->fetch();
 if(!$locked){$pdo->rollBack();json_response(['error'=>'conversation_not_found'],404);}
 if($locked['status']==='closed'){$pdo->rollBack();json_response(['error'=>'conversation_closed'],409);}
 $currentSelected=$pdo->prepare('SELECT product_id FROM conversation_product_context WHERE conversation_id=:id LIMIT 1');
 $currentSelected->execute(['id'=>$conversation['id']]);$currentSelectedValue=$currentSelected->fetchColumn();
 $currentSelectedId=$currentSelectedValue===false||$currentSelectedValue===null?null:(int)$currentSelectedValue;
 if($locked['context_sku']!==$originalSku||$currentSelectedId!==$selectedId)$productId=null;
 $insert=$pdo->prepare('INSERT INTO conversation_vehicles (conversation_id,product_id,make,model,model_year,engine,transmission,trim_level,vin_ciphertext,vin_last4,created_at) VALUES (:cid,:pid,:make,:model,:year,:engine,:transmission,:trim,:vin,:last4,UTC_TIMESTAMP())');
 $insert->execute(['cid'=>$conversation['id'],'pid'=>$productId,'make'=>$make,'model'=>$model,'year'=>$year,'engine'=>$engine!==''?$engine:null,'transmission'=>$transmission!==''?$transmission:null,'trim'=>$trim!==''?$trim:null,'vin'=>$encrypted,'last4'=>$vin!==''?substr($vin,-4):null]);
 $vehicleId=(int)$pdo->lastInsertId();
 $fitment=$productId&&$productType==='simple'&&$engine!==''&&$transmission!==''?vehicle_find_verified_fitment($pdo,(int)$productId,$make,$model,(int)$year,$engine,$transmission,$trim):null;
 if(!$fitment){
  $pdo->prepare("UPDATE conversations SET status='needs_human',last_message_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=:id AND status='open'")->execute(['id'=>$conversation['id']]);
  $notice=$productType==='variable'?'This listing has product variants. A parts specialist must confirm the exact variant before we recommend a part.':'Vehicle details submitted for a fitment check. A parts specialist must verify compatibility before we recommend a part.';
 }else{
  $notice='A verified fitment record was found for the selected listing and vehicle details.';
 }
 // An unresolved fitment submission is a customer-originated inbox event so it increments
 // unread counts and triggers the staff notification path. A verified match remains informational.
 $senderType=$fitment?'system':'customer';
 $pdo->prepare("INSERT INTO messages (conversation_id,sender_type,body,created_at) VALUES (:id,:sender,:body,UTC_TIMESTAMP())")->execute(['id'=>$conversation['id'],'sender'=>$senderType,'body'=>$notice]);
 $pdo->commit();
 json_response(['vehicle_id'=>$vehicleId,'fitment'=>$fitment?['status'=>'verified','source'=>$fitment['verification_source'],'verified_at'=>$fitment['verified_at']]:['status'=>'needs_specialist'],'product_id'=>$productId,'product_type'=>$productType],201);
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
