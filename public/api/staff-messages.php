<?php
declare(strict_types=1);
require_once dirname(__DIR__,2) . '/app/staff.php';
require_method('GET','POST');
$staff = require_staff();
$pdo = db();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
 $publicId = $_GET['conversation_id'] ?? '';
 $afterId = filter_var($_GET['after_id'] ?? 0,FILTER_VALIDATE_INT);
 if (!is_string($publicId) || !preg_match('/^[a-f0-9]{26}$/D',$publicId) || $afterId === false || $afterId < 0) json_response(['error'=>'invalid_poll_parameters'],422);
 $find = $pdo->prepare('SELECT id,status FROM conversations WHERE public_id=:public_id LIMIT 1');
 $find->execute(['public_id'=>$publicId]);
 $conversation = $find->fetch();
 if (!$conversation) json_response(['error'=>'conversation_not_found'],404);
 $limit = min(100,max(1,(int)app_config('security.poll_limit',100)));
 $query = $pdo->prepare("SELECT m.id,m.sender_type,m.body,m.created_at,a.id AS attachment_id,a.original_name,a.mime_type,a.size_bytes FROM messages m LEFT JOIN attachments a ON a.message_id=m.id WHERE m.conversation_id=:conversation_id AND m.id>:after_id ORDER BY m.id ASC LIMIT {$limit}");
 $query->execute(['conversation_id'=>$conversation['id'],'after_id'=>$afterId]);
 $messages = $query->fetchAll();
 $maxId=(int)$afterId;
 foreach($messages as &$message){
  $message['id']=(int)$message['id'];$maxId=max($maxId,$message['id']);
  $message['attachment']=$message['attachment_id']===null?null:[
   'id'=>(int)$message['attachment_id'],'name'=>$message['original_name'],
   'mime_type'=>$message['mime_type'],'size_bytes'=>(int)$message['size_bytes']
  ];
  unset($message['attachment_id'],$message['original_name'],$message['mime_type'],$message['size_bytes']);
 }
 unset($message);
 if($maxId>(int)$afterId)$pdo->prepare('UPDATE conversations SET staff_last_read_message_id=:message_id WHERE id=:id')->execute(['message_id'=>$maxId,'id'=>$conversation['id']]);
 json_response(['messages'=>$messages,'next_after_id'=>$maxId,'status'=>$conversation['status']]);
}

require_staff_csrf();
$input=request_json();
$publicId=$input['conversation_id']??'';
$body=trim((string)($input['body']??''));
$maxChars=max(1,(int)app_config('security.message_max_chars',5000));
$count=preg_match_all('/./us',$body,$unused);
if(!is_string($publicId)||!preg_match('/^[a-f0-9]{26}$/D',$publicId))json_response(['error'=>'invalid_conversation_id'],422);
if($body===''||$count===false||$count>$maxChars)json_response(['error'=>'invalid_message_length','max_chars'=>$maxChars],422);
$pdo->beginTransaction();
try{
 $find=$pdo->prepare('SELECT id,status FROM conversations WHERE public_id=:public_id FOR UPDATE');
 $find->execute(['public_id'=>$publicId]);$conversation=$find->fetch();
 if(!$conversation){$pdo->rollBack();json_response(['error'=>'conversation_not_found'],404);}
 if($conversation['status']==='closed'){$pdo->rollBack();json_response(['error'=>'conversation_closed'],409);}
 $insert=$pdo->prepare("INSERT INTO messages (conversation_id,sender_type,sender_id,body,created_at) VALUES (:conversation_id,'staff',:sender_id,:body,UTC_TIMESTAMP())");
 $insert->execute(['conversation_id'=>$conversation['id'],'sender_id'=>$staff['id'],'body'=>$body]);
 $id=(int)$pdo->lastInsertId();
 $pdo->prepare('UPDATE conversations SET last_message_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=:id')->execute(['id'=>$conversation['id']]);
 $pdo->commit();
 json_response(['message'=>['id'=>$id,'sender_type'=>'staff','body'=>$body,'created_at'=>gmdate('Y-m-d H:i:s')]],201);
}catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
