<?php
declare(strict_types=1);
require_once dirname(__DIR__,2) . '/app/customer.php';
require_once dirname(__DIR__,2) . '/app/ai.php';
require_method('POST');

$customer = require_customer();
if (rate_limit_exceeded('customer-conversation-ip:'.request_ip_hash(),60,60) || rate_limit_exceeded('customer-conversation:'.$customer['id'],20,60)) json_response(['error'=>'too_many_requests'],429);
$input = request_json();
$contextUrl = validate_context_url($input['context_url'] ?? null);
$sku = $input['context_sku'] ?? null;
if ($sku !== null && (!is_string($sku) || strlen($sku) > 120)) json_response(['error'=>'invalid_context_sku'],422);
$subject = $input['subject'] ?? null;
if ($subject !== null && (!is_string($subject) || strlen($subject) > 180)) json_response(['error'=>'invalid_subject'],422);

$pdo = db();
// Fail closed: a missing, unreadable, or unset setting must never activate AI.
$aiSetting = $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='ai_enabled' LIMIT 1")->fetchColumn();
$aiEnabled = $aiSetting === '1' && ai_provider_available();
$initialStatus = $aiEnabled ? 'open' : 'needs_human';

$pdo->beginTransaction();
try {
 // Serialize conversation creation per customer so two tabs cannot open duplicate active tickets.
 $lock = $pdo->prepare('SELECT id FROM customers WHERE id=:customer_id FOR UPDATE');
 $lock->execute(['customer_id'=>$customer['id']]);
 $existing = $pdo->prepare("SELECT id,public_id,status,context_url,context_sku FROM conversations WHERE customer_id=:customer_id AND status IN ('open','needs_human') ORDER BY last_message_at DESC LIMIT 1 FOR UPDATE");
 $existing->execute(['customer_id'=>$customer['id']]);
 $conversation = $existing->fetch();

 if ($conversation) {
  $selected=$pdo->prepare('SELECT sku FROM conversation_product_context WHERE conversation_id=:id LIMIT 1');
  $selected->execute(['id'=>$conversation['id']]);$selectedSku=$selected->fetchColumn();
  $pageChanged=$contextUrl!==null&&$contextUrl!==(string)($conversation['context_url']??'');
  $skuChanged=$sku!==null&&$sku!==(string)($conversation['context_sku']??'');
  if($selectedSku!==false&&($pageChanged||$skuChanged)){
   $pdo->prepare('DELETE FROM conversation_product_context WHERE conversation_id=:id')->execute(['id'=>$conversation['id']]);
  }
  $update = $pdo->prepare('UPDATE conversations SET context_url=COALESCE(:context_url,context_url), context_sku=COALESCE(:context_sku,context_sku), subject=COALESCE(:subject,subject), updated_at=UTC_TIMESTAMP() WHERE id=:id');
  $update->execute(['context_url'=>$contextUrl,'context_sku'=>$sku,'subject'=>$subject,'id'=>$conversation['id']]);
  $selectedQuery=$pdo->prepare('SELECT product_id,product_name FROM conversation_product_context WHERE conversation_id=:id LIMIT 1');
  $selectedQuery->execute(['id'=>$conversation['id']]);$selectedProduct=$selectedQuery->fetch()?:null;
  if($selectedProduct)$selectedProduct=['id'=>(int)$selectedProduct['product_id'],'name'=>$selectedProduct['product_name']];
  $pdo->commit();
  json_response(['conversation'=>['id'=>$conversation['public_id'],'status'=>$conversation['status']],'created'=>false,'support_mode'=>$aiEnabled?'ai':'human','selected_product'=>$selectedProduct]);
 }

 $publicId = create_public_conversation_id();
 $insert = $pdo->prepare("INSERT INTO conversations (public_id,customer_id,status,subject,context_url,context_sku,last_message_at,created_at,updated_at) VALUES (:public_id,:customer_id,:status,:subject,:context_url,:context_sku,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())");
 $insert->execute(['public_id'=>$publicId,'customer_id'=>$customer['id'],'status'=>$initialStatus,'subject'=>$subject,'context_url'=>$contextUrl,'context_sku'=>$sku]);
 if ($initialStatus === 'needs_human') {
  $conversationDbId = (int)$pdo->lastInsertId();
  $notice = $pdo->prepare("INSERT INTO messages (conversation_id,sender_type,body,created_at) VALUES (:id,'system',:body,UTC_TIMESTAMP())");
  $notice->execute(['id'=>$conversationDbId,'body'=>'A member of our support team will take over this chat. Send your message and the team will be able to help.']);
 }
 $pdo->commit();
 json_response(['conversation'=>['id'=>$publicId,'status'=>$initialStatus],'created'=>true,'support_mode'=>$aiEnabled?'ai':'human','selected_product'=>null],201);
} catch (Throwable $error) {
 if ($pdo->inTransaction()) $pdo->rollBack();
 throw $error;
}
