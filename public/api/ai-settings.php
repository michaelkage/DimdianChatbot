<?php
declare(strict_types=1);
require_once dirname(__DIR__,2) . '/app/staff.php';
require_once dirname(__DIR__,2) . '/app/ai.php';
require_method('GET','POST');
$staff = require_staff();
$providerReady = ai_provider_available();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
 $value = db()->query("SELECT setting_value FROM app_settings WHERE setting_key='ai_enabled' LIMIT 1")->fetchColumn();
 $enabled = $providerReady && $value === '1';
 json_response(['ai_enabled'=>$enabled,'ai_available'=>$providerReady,'mode'=>$enabled?'ai_assisted':'human_only','reason'=>$providerReady?null:'provider_not_configured']);
}

if (($staff['role'] ?? '') !== 'admin') json_response(['error'=>'admin_required'],403);
require_staff_csrf();
$input = request_json();
if (($input['test_provider'] ?? false) === true) {
 if (!$providerReady) json_response(['test_ok'=>false,'error'=>'ai_provider_not_configured'],409);
 $probe=ai_provider_complete([
  ['role'=>'system','content'=>'You are a connectivity test. Reply with exactly: DIMDIAN_PROVIDER_OK'],
  ['role'=>'user','content'=>'Return the required test phrase.'],
 ]);
 if (($probe['kind'] ?? '') === 'quota_exhausted' || ($probe['kind'] ?? '') === 'configuration_error') {
  ai_disable_globally((int)$staff['id']);
 }
 if (($probe['kind'] ?? '') !== 'ok' || trim((string)($probe['content']??'')) !== 'DIMDIAN_PROVIDER_OK') json_response(['test_ok'=>false,'error'=>'provider_test_failed','provider_disabled'=>in_array($probe['kind']??'', ['quota_exhausted','configuration_error'],true)],502);
 json_response(['test_ok'=>true,'provider_response'=>'DIMDIAN_PROVIDER_OK']);
}
if (!array_key_exists('ai_enabled',$input) || !is_bool($input['ai_enabled'])) json_response(['error'=>'invalid_ai_setting'],422);
if ($input['ai_enabled'] && !$providerReady) json_response(['error'=>'ai_provider_not_configured'],409);

if (!$input['ai_enabled']) {
 $routed = ai_disable_globally((int)$staff['id']);
 json_response(['ai_enabled'=>false,'mode'=>'human_only','routed_to_human'=>$routed]);
}

$pdo = db();
$upsert = $pdo->prepare("INSERT INTO app_settings (setting_key,setting_value,updated_by,updated_at) VALUES ('ai_enabled','1',:staff_id,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE setting_value='1',updated_by=VALUES(updated_by),updated_at=UTC_TIMESTAMP()");
$upsert->execute(['staff_id'=>$staff['id']]);
// Enabling AI affects only new eligible conversations. Existing human handoffs remain human-owned.
json_response(['ai_enabled'=>true,'mode'=>'ai_assisted','routed_to_human'=>0]);
