<?php
declare(strict_types=1);
require_once dirname(__DIR__,2) . '/app/staff.php';
require_method('POST');
require_staff();
require_staff_csrf();
$input=request_json();
$publicId=$input['conversation_id']??'';
$status=$input['status']??'';
if(!is_string($publicId)||!preg_match('/^[a-f0-9]{26}$/D',$publicId))json_response(['error'=>'invalid_conversation_id'],422);
if(!is_string($status)||!in_array($status,['open','needs_human','closed'],true))json_response(['error'=>'invalid_status'],422);
$update=db()->prepare('UPDATE conversations SET status=:status,updated_at=UTC_TIMESTAMP() WHERE public_id=:public_id');
$update->execute(['status'=>$status,'public_id'=>$publicId]);
if($update->rowCount()===0){
 $exists=db()->prepare('SELECT 1 FROM conversations WHERE public_id=:id');$exists->execute(['id'=>$publicId]);
 if(!$exists->fetchColumn())json_response(['error'=>'conversation_not_found'],404);
}
json_response(['conversation_id'=>$publicId,'status'=>$status]);
