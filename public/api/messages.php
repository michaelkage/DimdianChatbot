<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/chat/ChatMessageService.php';

enforce_allowed_origin();
handle_preflight();

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST, OPTIONS');
    json_response(['error' => ['code' => 'method_not_allowed', 'message' => 'Use GET to retrieve messages or POST to send one.']], 405);
}

try {
    $repository = chat_repository();
    $session = authenticate_guest_session($repository, $method === 'POST');
    $service = new ChatMessageService($repository, app_config());

    if ($method === 'POST') {
        $contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
        if ($contentType !== 'application/json') {
            json_response(['error' => ['code' => 'unsupported_media_type', 'message' => 'Content-Type must be application/json.']], 415);
        }

        $body = request_json(20000);
        $messageText = clean_optional_string($body['message'] ?? null, 4000, 'message');
        if ($messageText === null) {
            json_response(['error' => ['code' => 'message_required', 'message' => 'Write a message before sending.']], 422);
        }

        $clientMessageId = $body['client_message_id'] ?? null;
        try {
            $result = $service->send($session, $messageText, $clientMessageId);
        } catch (ChatStorageConflict $error) {
            json_response(['error' => ['code' => 'idempotency_conflict', 'message' => 'This message ID was already used for different text.']], 409);
        } catch (InvalidArgumentException $error) {
            json_response(['error' => ['code' => 'invalid_idempotency_key', 'message' => 'client_message_id must be a UUID.']], 422);
        }
        json_response($result['body'], $result['http_status']);
    }

    $afterRaw = $_GET['after'] ?? '0';
    if (!is_string($afterRaw) || !preg_match('/^\\d{1,18}$/', $afterRaw)) {
        json_response(['error' => ['code' => 'invalid_cursor', 'message' => 'The after cursor must be a non-negative integer.']], 422);
    }
    $after = (int) $afterRaw;

    $limitRaw = $_GET['limit'] ?? '50';
    if (!is_string($limitRaw) || !preg_match('/^\\d{1,3}$/', $limitRaw)) {
        json_response(['error' => ['code' => 'invalid_limit', 'message' => 'The limit must be an integer between 1 and 100.']], 422);
    }
    $limit = max(1, min(100, (int) $limitRaw));

    json_response($service->poll($session, $after, $limit));
} catch (MessageRateLimitExceeded $error) {
    header('Retry-After: ' . $error->retryAfterSeconds);
    json_response([
        'error' => [
            'code' => 'rate_limited',
            'message' => 'Too many attempts. Please wait a little and try again.',
        ],
    ], 429);
} catch (Throwable $error) {
    app_log_event('message.api_failed', ['endpoint' => 'messages', 'exception_class' => get_class($error)]);
    json_response(['error' => ['code' => 'server_error', 'message' => 'The chat service could not complete that request. Please try again.']], 500);
}
