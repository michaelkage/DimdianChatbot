<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

enforce_allowed_origin();
handle_preflight();

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST, OPTIONS');
    json_response(['error' => ['code' => 'method_not_allowed', 'message' => 'Use GET to list attachments or POST to upload one.']], 405);
}

try {
    chatbridge_supabase_attachments_api($method);
} catch (Throwable $error) {
    error_log('[ChatBridge] Attachment API failed: ' . $error->getMessage());
    json_response(['error' => ['code' => 'server_error', 'message' => 'The attachment could not be processed. Please try again.']], 500);
}
