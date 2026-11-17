<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/app/staff.php';
require_once dirname(__DIR__,2).'/app/vehicle.php';
require_method('GET');
require_staff();
$publicId=$_GET['conversation_id']??'';
if(!is_string($publicId)||!preg_match('/^[a-f0-9]{26}$/D',$publicId))json_response(['error'=>'invalid_conversation_id'],422);
$query=db()->prepare('SELECT v.make,v.model,v.model_year,v.engine,v.transmission,v.trim_level,v.product_id,v.vin_ciphertext,v.vin_last4 FROM conversation_vehicles v INNER JOIN conversations c ON c.id=v.conversation_id WHERE c.public_id=:id ORDER BY v.id DESC LIMIT 1');
$query->execute(['id'=>$publicId]);$vehicle=$query->fetch();
if(!$vehicle)json_response(['error'=>'vehicle_details_not_found'],404);
json_response([
 'vehicle'=>[
  'make'=>$vehicle['make'],'model'=>$vehicle['model'],'year'=>(int)$vehicle['model_year'],
  'engine'=>$vehicle['engine'],'transmission'=>$vehicle['transmission'],'trim'=>$vehicle['trim_level'],
  'product_id'=>$vehicle['product_id']===null?null:(int)$vehicle['product_id'],
  'vin'=>vehicle_decrypt_vin($vehicle['vin_ciphertext']??null),
  'vin_last4'=>$vehicle['vin_last4'],
 ],
]);
