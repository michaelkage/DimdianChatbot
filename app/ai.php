<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/ai_provider.php';

/** AI is active only when a working provider is configured and an administrator opted in. */
function ai_runtime_enabled(?PDO $pdo = null): bool {
 if (!ai_provider_verified()) return false;
 $pdo ??= db();
 $value = $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='ai_enabled' LIMIT 1")->fetchColumn();
 return $value === '1';
}

/** Hand one conversation to staff without exposing provider errors to the customer. */
function ai_handoff_conversation(int $conversationId): void {
 $pdo = db();
 $pdo->beginTransaction();
 try {
  $find = $pdo->prepare("SELECT id,status FROM conversations WHERE id=:id FOR UPDATE");
  $find->execute(['id'=>$conversationId]);
  $conversation = $find->fetch();
  if (!$conversation || $conversation['status'] !== 'open') {
   $pdo->commit();
   return;
  }
  $pdo->prepare("UPDATE conversations SET status='needs_human',last_message_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=:id")->execute(['id'=>$conversationId]);
  $pdo->prepare("INSERT INTO messages (conversation_id,sender_type,body,created_at) VALUES (:id,'system',:body,UTC_TIMESTAMP())")
   ->execute(['id'=>$conversationId,'body'=>'A member of our support team will take over this chat. Your message has been saved.']);
  $pdo->commit();
 } catch (Throwable $error) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  throw $error;
 }
}

/** Global AI kill switch for admin shutdown, exhausted quota, or invalid provider credentials. */
function ai_disable_globally(?int $actorId = null): int {
 $pdo = db();
 $pdo->beginTransaction();
 try {
  $setting = $pdo->prepare("INSERT INTO app_settings (setting_key,setting_value,updated_by,updated_at) VALUES ('ai_enabled','0',:actor_id,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE setting_value='0',updated_by=VALUES(updated_by),updated_at=UTC_TIMESTAMP()");
  $setting->execute(['actor_id'=>$actorId]);
  ai_provider_clear_verification($pdo);
  $query = $pdo->query("SELECT id FROM conversations WHERE status='open' FOR UPDATE");
  $ids = array_map('intval',$query->fetchAll(PDO::FETCH_COLUMN));
  if ($ids) {
   $update = $pdo->prepare("UPDATE conversations SET status='needs_human',last_message_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=:id AND status='open'");
   $message = $pdo->prepare("INSERT INTO messages (conversation_id,sender_type,body,created_at) VALUES (:id,'system',:body,UTC_TIMESTAMP())");
   foreach ($ids as $id) {
    $update->execute(['id'=>$id]);
    if ($update->rowCount() > 0) $message->execute(['id'=>$id,'body'=>'A member of our support team will take over this chat. Your message has been saved.']);
   }
  }
  $pdo->commit();
  return count($ids);
 } catch (Throwable $error) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  throw $error;
 }
}

/** Do not send VIN, fitment, order, payment or complaint cases to a general-purpose LLM. */
function ai_requires_human(string $message): bool {
 $text = strtolower($message);
 $patterns = [
  '/\bvin\b|\b[A-HJ-NPR-Z0-9]{17}\b/i',
  '/\bfit(?:s|ment|ment check)?\b|\bwill (?:this|it) fit\b|\bcompatib(?:le|ility)\b|\bcorrect part for my\b/',
  '/\b(order|delivery|shipment)\b.*\b(status|tracking|track|where)\b|\bwhere(?:\'s| is) my (?:order|delivery|shipment)\b|\btrack my order\b/',
  '/\b(refund|return|chargeback|payment|paid|invoice)\b/',
  '/\b(damaged|broken|wrong item|complaint|dispute|unsafe|accident)\b/',
  '/\b(human|person|agent|staff|specialist)\b|\b(speak|talk) to (?:a |an )?(?:human|person|agent|staff|specialist)\b/',
 ];
 foreach ($patterns as $pattern) if (preg_match($pattern,$text)) return true;
 return false;
}

/** Process a customer turn after it has been committed. All provider failures degrade to staff handoff. */
function ai_process_customer_message(int $conversationId): void {
 try {
  if (!ai_runtime_enabled()) {
   $configuredSetting=db()->query("SELECT setting_value FROM app_settings WHERE setting_key='ai_enabled' LIMIT 1")->fetchColumn();
   if($configuredSetting==='1')ai_disable_globally();
   return;
  }
  $pdo = db();
  $find = $pdo->prepare("SELECT id,status,context_url,context_sku FROM conversations WHERE id=:id LIMIT 1");
  $find->execute(['id'=>$conversationId]);
  $conversation = $find->fetch();
  if (!$conversation || $conversation['status'] !== 'open') return;
  // A staff handoff is sticky. Reopening the status for staff workflow must not silently re-enable AI.
  $handoff = $pdo->prepare("SELECT 1 FROM messages WHERE conversation_id=:id AND sender_type='system' AND body LIKE 'A member of our support team will take over%' LIMIT 1");
  $handoff->execute(['id'=>$conversationId]);
  if ($handoff->fetchColumn()) return;

  $historyQuery = $pdo->prepare("SELECT sender_type,body FROM messages WHERE conversation_id=:id ORDER BY id DESC LIMIT 12");
  $historyQuery->execute(['id'=>$conversationId]);
  $history = array_reverse($historyQuery->fetchAll());
  $latestCustomer = '';
  foreach (array_reverse($history) as $message) {
   if ($message['sender_type'] !== 'customer') continue;
   if ($latestCustomer === '') $latestCustomer=(string)$message['body'];
   // A later harmless message must not erase an earlier request that requires a specialist.
   if (ai_requires_human((string)$message['body'])) {
    ai_handoff_conversation($conversationId);
    return;
   }
  }
  if ($latestCustomer === '') return;

  $messages = [['role'=>'system','content'=>ai_system_prompt()]];
  if (!empty($conversation['context_sku'])) $messages[] = ['role'=>'system','content'=>'Current listing SKU context (not proof of compatibility or stock): '.(string)$conversation['context_sku']];
  if (!empty($conversation['context_url'])) $messages[] = ['role'=>'system','content'=>'Current listing URL context only: '.(string)$conversation['context_url']];
  foreach ($history as $item) {
   $sender = $item['sender_type'];
   if ($sender === 'customer') $role='user';
   elseif ($sender === 'assistant') $role='assistant';
   else continue;
   $messages[] = ['role'=>$role,'content'=>(string)$item['body']];
  }

  $result = ai_provider_complete($messages);
  if ($result['kind'] === 'quota_exhausted') {
   error_log('[DimdianChatbot AI] Provider quota exhausted; disabling AI and routing active chats to staff.');
   ai_disable_globally();
   return;
  }
  if ($result['kind'] === 'configuration_error') {
   error_log('[DimdianChatbot AI] Provider credentials invalid; disabling AI and routing active chats to staff.');
   ai_disable_globally();
   return;
  }
  if ($result['kind'] !== 'ok') {
   ai_handoff_conversation($conversationId);
   return;
  }

  $answer = trim((string)$result['content']);
  if ($answer === '') { ai_handoff_conversation($conversationId); return; }
  $answer = function_exists('mb_substr') ? mb_substr($answer,0,4000) : substr($answer,0,4000);
  $pdo->beginTransaction();
  try {
   $lock = $pdo->prepare("SELECT status FROM conversations WHERE id=:id FOR UPDATE");
   $lock->execute(['id'=>$conversationId]);
   $status = $lock->fetchColumn();
   // The provider may finish after an administrator disabled AI or a staff member took over.
   if ($status !== 'open' || !ai_runtime_enabled($pdo)) {
    $pdo->commit();
    return;
   }
   $insert = $pdo->prepare("INSERT INTO messages (conversation_id,sender_type,body,created_at) VALUES (:id,'assistant',:body,UTC_TIMESTAMP())");
   $insert->execute(['id'=>$conversationId,'body'=>$answer]);
   $pdo->prepare("UPDATE conversations SET last_message_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=:id")->execute(['id'=>$conversationId]);
   $pdo->commit();
  } catch (Throwable $error) {
   if ($pdo->inTransaction()) $pdo->rollBack();
   throw $error;
  }
 } catch (Throwable $error) {
  error_log('[DimdianChatbot AI] Turn processing failed; attempting human handoff: '.$error->getMessage());
  try { ai_handoff_conversation($conversationId); }
  catch (Throwable $handoffError) { error_log('[DimdianChatbot AI] Human handoff persistence failed: '.$handoffError->getMessage()); }
 }
}
