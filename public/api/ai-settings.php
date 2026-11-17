<?php
declare(strict_types=1);
require_once dirname(__DIR__,2) . '/app/staff.php';
require_method('GET','POST');
$staff = require_staff();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
 $value = db()->query("SELECT setting_value FROM app_settings WHERE setting_key='ai_enabled' LIMIT 1")->fetchColumn();
 // No provider adapter is installed yet. A setting alone must never route customers away from staff.
 json_response(['ai_enabled'=>$value === '1','ai_available'=>false,'mode'=>'human_only','reason'=>'provider_not_configured']);
}

if (($staff['role'] ?? '') !== 'admin') json_response(['error'=>'admin_required'],403);
require_staff_csrf();
$input = request_json();
if (!array_key_exists('ai_enabled',$input) || !is_bool($input['ai_enabled'])) json_response(['error'=>'invalid_ai_setting'],422);
if ($input['ai_enabled']) json_response(['error'=>'ai_provider_not_configured'],409);

$pdo = db();
$pdo->beginTransaction();
try {
 $upsert = $pdo->prepare("INSERT INTO app_settings (setting_key,setting_value,updated_by,updated_at) VALUES ('ai_enabled','0',:staff_id,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE setting_value='0',updated_by=VALUES(updated_by),updated_at=UTC_TIMESTAMP()");
 $upsert->execute(['staff_id'=>$staff['id']]);
 // Stop AI eligibility immediately and put every open conversation into the human queue.
 $move = $pdo->prepare("UPDATE conversations SET status='needs_human',updated_at=UTC_TIMESTAMP() WHERE status='open'");
 $move->execute();
 $routed = $move->rowCount();
 $pdo->commit();
 json_response(['ai_enabled'=>false,'mode'=>'human_only','routed_to_human'=>$routed]);
} catch (Throwable $error) {
 if ($pdo->inTransaction()) $pdo->rollBack();
 throw $error;
}
