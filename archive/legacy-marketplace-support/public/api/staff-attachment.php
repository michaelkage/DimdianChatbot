<?php
declare(strict_types=1);
require_once dirname(__DIR__,2) . '/app/staff.php';
require_once dirname(__DIR__,2) . '/app/storage.php';
require_method('GET');
require_staff();
$id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT);
if($id===false||$id===null||$id<1)json_response(['error'=>'invalid_attachment_id'],422);
$query=db()->prepare('SELECT storage_key,mime_type,size_bytes,sha256 FROM attachments WHERE id=:id LIMIT 1');
$query->execute(['id'=>$id]);$attachment=$query->fetch();
if(!$attachment)json_response(['error'=>'attachment_not_found'],404);
$path=private_attachment_path((string)$attachment['storage_key']);
if($path===null)json_response(['error'=>'attachment_not_found'],404);
$actualHash=hash_file('sha256',$path);$actualSize=filesize($path);
if($actualHash===false||$actualSize===false||!hash_equals((string)$attachment['sha256'],$actualHash)||(int)$actualSize!==(int)$attachment['size_bytes']){
 error_log('[DimdianChatbot] Attachment integrity check failed for record '.$id);
 json_response(['error'=>'attachment_integrity_failed'],500);
}
header('Content-Type: '.$attachment['mime_type']);
header('Content-Length: '.(int)$attachment['size_bytes']);
header('Content-Disposition: inline; filename="attachment"');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
readfile($path);exit;
