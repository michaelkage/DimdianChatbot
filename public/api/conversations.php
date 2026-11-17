<?php
declare(strict_types=1);
require_once dirname(__DIR__,2) . '/app/customer.php';
require_method('POST');

$customer = require_customer();
if (rate_limit_exceeded('customer-conversation:'.$customer['id'],20,60)) json_response(['error'=>'too_many_requests'],429);
$input = request_json();
$contextUrl = validate_context_url($input['context_url'] ?? null);
$sku = $input['context_sku'] ?? null;
if ($sku !== null && (!is_string($sku) || strlen($sku) > 120)) json_response(['error'=>'invalid_context_sku'],422);
$subject = $input['subject'] ?? null;
if ($subject !== null && (!is_string($subject) || strlen($subject) > 180)) json_response(['error'=>'invalid_subject'],422);

$pdo = db();
$existing = $pdo->prepare("SELECT id,public_id,status FROM conversations WHERE customer_id=:customer_id AND status IN ('open','needs_human') ORDER BY last_message_at DESC LIMIT 1");
$existing->execute(['customer_id'=>$customer['id']]);
$conversation = $existing->fetch();

if ($conversation) {
 $update = $pdo->prepare('UPDATE conversations SET context_url=COALESCE(:context_url,context_url), context_sku=COALESCE(:context_sku,context_sku), subject=COALESCE(:subject,subject), updated_at=UTC_TIMESTAMP() WHERE id=:id');
 $update->execute(['context_url'=>$contextUrl,'context_sku'=>$sku,'subject'=>$subject,'id'=>$conversation['id']]);
 json_response(['conversation'=>['id'=>$conversation['public_id'],'status'=>$conversation['status']],'created'=>false]);
}

$publicId = create_public_conversation_id();
$insert = $pdo->prepare("INSERT INTO conversations (public_id,customer_id,status,subject,context_url,context_sku,last_message_at,created_at,updated_at) VALUES (:public_id,:customer_id,'open',:subject,:context_url,:context_sku,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())");
$insert->execute(['public_id'=>$publicId,'customer_id'=>$customer['id'],'subject'=>$subject,'context_url'=>$contextUrl,'context_sku'=>$sku]);
json_response(['conversation'=>['id'=>$publicId,'status'=>'open'],'created'=>true],201);
