<?php
declare(strict_types=1);
require_once dirname(__DIR__,2) . '/app/customer.php';
require_method('POST');
$customer = require_customer();
if (rate_limit_exceeded('customer-upload:'.$customer['id'],10,3600)) json_response(['error'=>'upload_rate_limited'],429);

$publicId = $_POST['conversation_id'] ?? '';
if (!is_string($publicId) || !preg_match('/^[a-f0-9]{26}$/D',$publicId)) json_response(['error'=>'invalid_conversation_id'],422);
if (!isset($_FILES['file']) || !is_array($_FILES['file'])) json_response(['error'=>'file_required'],422);

$file = $_FILES['file'];
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) json_response(['error'=>'upload_failed','upload_error'=>(int)$file['error']],422);
$maxBytes = min(3 * 1024 * 1024,(int)app_config('uploads.max_bytes',3 * 1024 * 1024));
if (!is_uploaded_file($file['tmp_name']) || (int)$file['size'] < 1 || (int)$file['size'] > $maxBytes) {
 json_response(['error'=>'file_too_large_or_invalid','max_bytes'=>$maxBytes],413);
}

$contents = file_get_contents($file['tmp_name']);
if ($contents === false) json_response(['error'=>'file_read_failed'],422);
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->buffer($contents);
$allowed = app_config('uploads.allowed_mime_types',[]);
if (!is_string($mime) || !is_array($allowed) || !isset($allowed[$mime])) json_response(['error'=>'unsupported_image_type'],415);
$imageInfo = @getimagesizefromstring($contents);
if (!is_array($imageInfo) || ($imageInfo['mime'] ?? '') !== $mime) json_response(['error'=>'invalid_image'],415);
$width = (int)$imageInfo[0];
$height = (int)$imageInfo[1];
if ($width < 1 || $height < 1 || $width > 8000 || $height > 8000 || ($width * $height) > 30000000) {
 json_response(['error'=>'image_dimensions_exceeded'],422);
}

$directory = (string)app_config('uploads.directory','');
if ($directory === '' || !str_starts_with($directory,DIRECTORY_SEPARATOR) || str_starts_with(realpath($directory) ?: $directory,realpath(dirname(__DIR__)) ?: dirname(__DIR__))) {
 json_response(['error'=>'private_upload_directory_invalid'],503);
}
if (!is_dir($directory) && !mkdir($directory,0700,true) && !is_dir($directory)) {
 json_response(['error'=>'upload_storage_unavailable'],503);
}
$key = bin2hex(random_bytes(24)).'.'.$allowed[$mime];
$target = rtrim($directory,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$key;
if (file_put_contents($target,$contents,LOCK_EX) === false) json_response(['error'=>'upload_storage_failed'],503);
@chmod($target,0600);

$pdo = db();
try {
 $pdo->beginTransaction();
 $find = $pdo->prepare("SELECT id,status FROM conversations WHERE public_id=:public_id AND customer_id=:customer_id FOR UPDATE");
 $find->execute(['public_id'=>$publicId,'customer_id'=>$customer['id']]);
 $conversation = $find->fetch();
 if (!$conversation) { $pdo->rollBack(); @unlink($target); json_response(['error'=>'conversation_not_found'],404); }
 if ($conversation['status'] === 'closed') { $pdo->rollBack(); @unlink($target); json_response(['error'=>'conversation_closed'],409); }

 $message = $pdo->prepare("INSERT INTO messages (conversation_id,sender_type,body,created_at) VALUES (:conversation_id,'customer','Image attachment',UTC_TIMESTAMP())");
 $message->execute(['conversation_id'=>$conversation['id']]);
 $messageId = (int)$pdo->lastInsertId();
 $originalName = basename((string)($file['name'] ?? 'image'));
 $originalName = preg_replace('/[^a-zA-Z0-9._ -]/','_',substr($originalName,0,180)) ?: 'image';
 $attachment = $pdo->prepare('INSERT INTO attachments (message_id,storage_key,original_name,mime_type,size_bytes,sha256,created_at) VALUES (:message_id,:storage_key,:original_name,:mime_type,:size_bytes,:sha256,UTC_TIMESTAMP())');
 $attachment->execute(['message_id'=>$messageId,'storage_key'=>$key,'original_name'=>$originalName,'mime_type'=>$mime,'size_bytes'=>strlen($contents),'sha256'=>hash('sha256',$contents)]);
 $attachmentId = (int)$pdo->lastInsertId();
 $pdo->prepare('UPDATE conversations SET last_message_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=:id')->execute(['id'=>$conversation['id']]);
 $pdo->commit();
 json_response(['message'=>['id'=>$messageId,'sender_type'=>'customer','body'=>'Image attachment'],'attachment'=>['id'=>$attachmentId,'name'=>$originalName,'mime_type'=>$mime,'size_bytes'=>strlen($contents)]],201);
} catch (Throwable $error) {
 if ($pdo->inTransaction()) $pdo->rollBack();
 @unlink($target);
 throw $error;
}
