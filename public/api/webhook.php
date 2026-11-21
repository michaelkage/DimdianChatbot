<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/whatsapp.php';
require_once dirname(__DIR__, 2) . '/app/webhook_inbox.php';
require_once dirname(__DIR__, 2) . '/app/meta_signup.php';

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$verifyToken = (string)app_config('whatsapp.verify_token');

if ($method === 'GET') {
    $mode = (string)($_GET['hub_mode'] ?? $_GET['hub.mode'] ?? '');
    $token = (string)($_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? '');
    $challenge = (string)($_GET['hub_challenge'] ?? $_GET['hub.challenge'] ?? '');
    if ($mode === 'subscribe' && $verifyToken !== '' && hash_equals($verifyToken, $token) && $challenge !== '') {
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        echo $challenge;
        exit;
    }
    http_response_code(403);
    exit('Forbidden');
}

if ($method !== 'POST') {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Method Not Allowed');
}

$appSecret = (string)app_config('whatsapp.app_secret');
$signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? null;
$rawBody = file_get_contents('php://input');
if (!is_string($rawBody) || strlen($rawBody) > 1048576) {
    http_response_code(413);
    exit('Payload Too Large');
}
if (!verify_meta_webhook_signature($rawBody, is_string($signature) ? $signature : null, $appSecret)) {
    http_response_code(401);
    exit('Invalid signature');
}

try {
    $payload = json_decode($rawBody, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($payload) || ($payload['object'] ?? '') !== 'whatsapp_business_account') {
        http_response_code(400);
        exit('Invalid payload');
    }

    $pdo = database();
    // Persist the verified payload before acknowledging Meta. Inline processing
    // is an optimization; the CLI inbox worker recovers jobs after a process crash.
    $pdo->beginTransaction();
    try {
        $queued = enqueue_meta_webhook($pdo, $rawBody);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    $summary = ['processed' => 0, 'retried' => 0, 'failed' => 0];
    if ($queued['status'] !== 'processed') $summary = process_webhook_inbox($pdo, 1, 8);

    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => true, 'queued' => $queued['inserted'], 'inbox_id' => $queued['id'],
        'processed' => $summary['processed'], 'retry_scheduled' => $summary['retried'] > 0,
    ]);
} catch (JsonException) {
    http_response_code(400);
    exit('Invalid JSON');
} catch (Throwable $error) {
    error_log('[DimdianChatbot] WhatsApp webhook processing failed: ' . $error->getMessage());
    http_response_code(500);
    exit('Webhook processing failed');
}
