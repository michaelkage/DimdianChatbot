<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/whatsapp.php';
require_once dirname(__DIR__, 2) . '/app/webhook_inbox.php';

try {
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'GET' || $method !== 'POST') {
        $response = $method === 'GET'
            ? MetaWebhookEndpoint::verifyHandshake($_GET, (string)app_config('whatsapp.verify_token'))
            : MetaWebhookEndpoint::methodNotAllowed();
        http_response_code($response['status']);
        foreach ($response['headers'] as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $response['body'];
        exit;
    }

    $rawBody = '';
    if ($method === 'POST') {
        $body = file_get_contents('php://input');
        if (!is_string($body)) {
            http_response_code(413);
            exit('Payload Too Large');
        }
        $rawBody = $body;
    }

    $signature = isset($_SERVER['HTTP_X_HUB_SIGNATURE_256']) && is_string($_SERVER['HTTP_X_HUB_SIGNATURE_256'])
        ? $_SERVER['HTTP_X_HUB_SIGNATURE_256']
        : null;
    $appSecret = (string)app_config('whatsapp.app_secret');
    $validation = MetaWebhookEndpoint::validatePost($rawBody, $signature, $appSecret);
    if (isset($validation['status'])) {
        http_response_code($validation['status']);
        foreach ($validation['headers'] as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $validation['body'];
        exit;
    }

    $repository = chat_repository();
    try {
        $routing = (new MetaWebhookTenantResolver($repository))->resolve($validation['payload']);
    } catch (MetaWebhookTenantResolutionException $error) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Unknown webhook destination');
    }

    // Meta can send account-level changes unrelated to message processing.
    // Acknowledge those without assigning them to an arbitrary tenant.
    if ($routing === null) {
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'ignored' => true], JSON_UNESCAPED_SLASHES);
        exit;
    }

    $cipher = webhook_payload_cipher();
    $worker = webhook_inbox_worker($repository, $cipher);
    $endpoint = new MetaWebhookEndpoint($repository, $cipher, $worker);
    $response = $endpoint->processValidatedPayload($rawBody, $routing['tenant_id']);

    http_response_code($response['status']);
    foreach ($response['headers'] as $name => $value) {
        header($name . ': ' . $value);
    }
    echo $response['body'];
} catch (Throwable $error) {
    app_log_event('webhook.request_failed', [
        'endpoint' => 'webhook',
        'exception_class' => get_class($error),
    ]);
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Webhook processing failed');
}
