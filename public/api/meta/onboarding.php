<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/tenant_auth.php';
require_once dirname(__DIR__, 3) . '/app/crypto.php';
require_once dirname(__DIR__, 3) . '/app/whatsapp.php';
require_once dirname(__DIR__, 3) . '/app/meta.php';

require_method('POST');
$contentType = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
if ($contentType !== 'application/json') {
    json_response(['error' => ['code' => 'unsupported_media_type', 'message' => 'Content-Type must be application/json.']], 415);
}

try {
    $pdo = database();
    $auth = authenticate_tenant_api_key($pdo, ['owner', 'admin']);
    $body = request_json(30000);
    $code = clean_optional_string($body['code'] ?? null, 10000, 'code');
    $sessionInfo = $body['session_info'] ?? null;
    if ($code === null || !is_array($sessionInfo)) {
        json_response(['error' => ['code' => 'invalid_signup_payload', 'message' => 'The Meta authorization code and v5 session info are required.']], 422);
    }

    $result = complete_meta_embedded_signup($pdo, (string)$auth['tenant_id'], $code, $sessionInfo);
    $syncSummary = process_meta_coexistence_sync_jobs($pdo, (string)$auth['tenant_id'], 10);
    json_response([
        'ok' => true,
        'connections' => $result['connections'],
        'connection_mode' => $result['connection_mode'],
        'coexistence_sync' => $syncSummary,
    ], 201);
} catch (InvalidArgumentException $error) {
    json_response(['error' => ['code' => 'invalid_signup_payload', 'message' => $error->getMessage()]], 422);
} catch (Throwable $error) {
    error_log('[ChatBridge Meta onboarding] ' . $error->getMessage());
    json_response(['error' => ['code' => 'meta_onboarding_failed', 'message' => 'Meta onboarding could not be completed. Check the private service log.']], 502);
}
