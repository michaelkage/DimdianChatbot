<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

/** AI is active only when the provider adapter is explicitly marked ready and an admin has enabled it. */
function ai_runtime_enabled(?PDO $pdo = null): bool {
 if (!(bool)app_config('ai.provider_ready',false)) return false;
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
   ->execute(['id'=>$conversationId,'body'=>'A member of our support team will take over from here. Your message has been saved.']);
  $pdo->commit();
 } catch (Throwable $error) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  throw $error;
 }
}

/**
 * Global kill switch used by administrators and by provider quota exhaustion.
 * All AI-eligible open conversations are moved to the human queue in the same transaction.
 */
function ai_disable_globally(?int $actorId = null): int {
 $pdo = db();
 $pdo->beginTransaction();
 try {
  $setting = $pdo->prepare("INSERT INTO app_settings (setting_key,setting_value,updated_by,updated_at) VALUES ('ai_enabled','0',:actor_id,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE setting_value='0',updated_by=VALUES(updated_by),updated_at=UTC_TIMESTAMP()");
  $setting->execute(['actor_id'=>$actorId]);
  $query = $pdo->query("SELECT id FROM conversations WHERE status='open' FOR UPDATE");
  $ids = array_map('intval',$query->fetchAll(PDO::FETCH_COLUMN));
  if ($ids) {
   $update = $pdo->prepare("UPDATE conversations SET status='needs_human',last_message_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=:id AND status='open'");
   $message = $pdo->prepare("INSERT INTO messages (conversation_id,sender_type,body,created_at) VALUES (:id,'system',:body,UTC_TIMESTAMP())");
   foreach ($ids as $id) {
    $update->execute(['id'=>$id]);
    if ($update->rowCount() > 0) {
     $message->execute(['id'=>$id,'body'=>'A member of our support team will take over from here. Your message has been saved.']);
    }
   }
  }
  $pdo->commit();
  return count($ids);
 } catch (Throwable $error) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  throw $error;
 }
}
