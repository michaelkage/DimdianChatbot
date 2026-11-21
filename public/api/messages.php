<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/whatsapp.php';

enforce_allowed_origin();
handle_preflight();

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST, OPTIONS');
    json_response(['error' => ['code' => 'method_not_allowed', 'message' => 'Use GET to retrieve messages or POST to send one.']], 405);
}

try {
    $pdo = database();
    $repository = chat_repository($pdo);
    $session = authenticate_guest_session($repository, $method === 'POST');

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

        $rateConfig = app_config('app.message_rate_limit') ?? [];
        $maxRequests = max(1, (int) ($rateConfig['max_requests'] ?? 8));
        $windowSeconds = max(60, (int) ($rateConfig['window_seconds'] ?? 60));
        enforce_rate_limit($repository, 'message:' . $session['session_id'], $maxRequests, $windowSeconds);

        $clientMessageId = $body['client_message_id'] ?? null;
        if ($clientMessageId === null) {
            $clientMessageId = uuid_v4();
        }
        if (!is_string($clientMessageId) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $clientMessageId)) {
            json_response(['error' => ['code' => 'invalid_idempotency_key', 'message' => 'client_message_id must be a UUID.']], 422);
        }

        $whatsappConfig = app_config('whatsapp') ?? [];
        $visitorWhatsApp = (string) ($session['phone_normalized'] ?? '');
        $visitorOptedIn = !empty($session['whatsapp_opt_in_at']) && $visitorWhatsApp !== '';
        $outboundEnabled = is_array($whatsappConfig) && !empty($whatsappConfig['outbound_enabled']) && $visitorOptedIn;

        try {
            $result = $repository->createVisitorMessage([
                'id' => uuid_v4(),
                'conversation_id' => (string) $session['conversation_id'],
                'client_message_id' => $clientMessageId,
                'body' => $messageText,
                'outbound_enabled' => $outboundEnabled,
                'outbox_id' => uuid_v4(),
                'recipient' => $visitorWhatsApp,
                'outbox_payload' => mb_substr_safe($messageText, 0, 1000),
            ]);
        } catch (ChatStorageConflict $error) {
            json_response(['error' => ['code' => 'idempotency_conflict', 'message' => 'This message ID was already used for different text.']], 409);
        }

        $saved = $result['message'];
        $wasInserted = $result['inserted'];
        $transportState = $result['transport'];

        json_response([
            'data' => [
                'id' => $saved['id'],
                'sequence_id' => (int) $saved['sequence_id'],
                'conversation_id' => $session['conversation_id'],
                'sender' => 'visitor',
                'message' => $messageText,
                'delivery_status' => $saved['delivery_status'],
                'created_at' => (new DateTimeImmutable($saved['sent_at'], new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            ],
            'meta' => [
                'transport' => $wasInserted ? $transportState : 'replayed',
                'idempotent_replay' => !$wasInserted,
                'note' => $saved['delivery_status'] === 'web_only'
                    ? 'Your message is saved in this website chat. WhatsApp delivery is not enabled for this conversation.'
                    : 'Your message is saved. WhatsApp delivery status is tracked separately.',
            ],
        ], $wasInserted ? 201 : 200);
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

    $page = $repository->listConversationMessages((string) $session['conversation_id'], $after, $limit);
    $deliveryUpdates = $repository->listVisitorDeliveryUpdates((string) $session['conversation_id'], 50);

    json_response([
        'data' => $page['items'],
        'meta' => [
            'after' => $after,
            'next_cursor' => $page['next_cursor'],
            'has_more' => $page['has_more'],
            'poll_after_ms' => 3000,
            'delivery_updates' => $deliveryUpdates,
        ],
    ]);
} catch (Throwable $error) {
    app_log_event('message.api_failed', ['endpoint' => 'messages', 'exception_class' => get_class($error)]);
    json_response(['error' => ['code' => 'server_error', 'message' => 'The chat service could not complete that request. Please try again.']], 500);
}
