<?php
declare(strict_types=1);
require_once dirname(__DIR__,2) . '/app/customer.php';
require_once dirname(__DIR__,2) . '/app/ai.php';
require_method('GET','POST');

$customer = require_customer();
$pdo = db();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
 $publicId = $_GET['conversation_id'] ?? '';
 $afterId = filter_var($_GET['after_id'] ?? 0,FILTER_VALIDATE_INT);
 if (!is_string($publicId) || !preg_match('/^[a-f0-9]{26}$/D',$publicId) || $afterId === false || $afterId < 0) {
  json_response(['error'=>'invalid_poll_parameters'],422);
 }
 $find = $pdo->prepare('SELECT id,status FROM conversations WHERE public_id=:public_id AND customer_id=:customer_id LIMIT 1');
 $find->execute(['public_id'=>$publicId,'customer_id'=>$customer['id']]);
 $conversation = $find->fetch();
 if (!$conversation) json_response(['error'=>'conversation_not_found'],404);

 $limit = min(100,max(1,(int)app_config('security.poll_limit',100)));
 $query = $pdo->prepare("SELECT m.id,m.sender_type,m.body,m.created_at,a.id AS attachment_id,a.storage_key,a.original_name,a.mime_type,a.size_bytes FROM messages m LEFT JOIN attachments a ON a.message_id=m.id WHERE m.conversation_id=:conversation_id AND m.id>:after_id ORDER BY m.id ASC LIMIT {$limit}");
 $query->execute(['conversation_id'=>$conversation['id'],'after_id'=>$afterId]);
 $messages = $query->fetchAll();
 $maxId = (int)$afterId;
 foreach ($messages as &$message) {
  $message['id'] = (int)$message['id'];
  $maxId = max($maxId,$message['id']);
  if ($message['attachment_id'] !== null) {
   $message['attachment'] = ['id'=>(int)$message['attachment_id'],'name'=>$message['original_name'],'mime_type'=>$message['mime_type'],'size_bytes'=>(int)$message['size_bytes']];
  } else { $message['attachment'] = null; }
  unset($message['attachment_id'],$message['storage_key'],$message['original_name'],$message['mime_type'],$message['size_bytes']);
 }
 unset($message);
 if ($maxId > (int)$afterId) {
  $read = $pdo->prepare('UPDATE conversations SET customer_last_read_message_id=:message_id WHERE id=:id');
  $read->execute(['message_id'=>$maxId,'id'=>$conversation['id']]);
 }
 json_response(['messages'=>$messages,'next_after_id'=>$maxId,'status'=>$conversation['status']]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
 if (rate_limit_exceeded('customer-message-ip:'.request_ip_hash(),120,60) || rate_limit_exceeded('customer-message:'.$customer['id'],20,60)) json_response(['error'=>'message_rate_limited'],429);
 $input = request_json();
 $publicId = $input['conversation_id'] ?? '';
 $bodyInput = $input['body'] ?? null;
 if (!is_string($bodyInput)) json_response(['error'=>'invalid_message_body'],422);
 $body = trim($bodyInput);
 $maxChars = max(1,(int)app_config('security.message_max_chars',5000));
 if (!is_string($publicId) || !preg_match('/^[a-f0-9]{26}$/D',$publicId)) json_response(['error'=>'invalid_conversation_id'],422);
 $charCount = preg_match_all('/./us',$body,$unused);
 if ($body === '' || $charCount === false || $charCount > $maxChars) json_response(['error'=>'invalid_message_length','max_chars'=>$maxChars],422);

 $pdo->beginTransaction();
 try {
  $find = $pdo->prepare('SELECT id,status FROM conversations WHERE public_id=:public_id AND customer_id=:customer_id FOR UPDATE');
  $find->execute(['public_id'=>$publicId,'customer_id'=>$customer['id']]);
  $conversation = $find->fetch();
  if (!$conversation) { $pdo->rollBack(); json_response(['error'=>'conversation_not_found'],404); }
  if ($conversation['status'] === 'closed') { $pdo->rollBack(); json_response(['error'=>'conversation_closed'],409); }

  $insert = $pdo->prepare("INSERT INTO messages (conversation_id,sender_type,body,created_at) VALUES (:conversation_id,'customer',:body,UTC_TIMESTAMP())");
  $insert->execute(['conversation_id'=>$conversation['id'],'body'=>$body]);
  $messageId = (int)$pdo->lastInsertId();
  $update = $pdo->prepare('UPDATE conversations SET last_message_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=:id');
  $update->execute(['id'=>$conversation['id']]);
  $pdo->commit();
  // The customer message is already safely stored. AI failures are swallowed and converted to staff handoff.
  if ($conversation['status'] === 'open') ai_process_customer_message((int)$conversation['id']);
  json_response(['message'=>['id'=>$messageId,'sender_type'=>'customer','body'=>$body],'accepted'=>true],201);
 } catch (Throwable $error) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  throw $error;
 }
}

