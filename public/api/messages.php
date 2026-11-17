<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/whatsapp.php';

enforce_allowed_origin();
handle_preflight();

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST, OPTIONS');
    json_response(['error' => ['code' => 'method_not_allowed', 'message' => 'Use GET to retrieve messages or POST to send one.']], 405);
}

try {
    $pdo = database();
    $session = authenticate_guest_session($pdo, $method === 'POST');

    if ($method === 'POST') {
        $contentType = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
        if ($contentType !== 'application/json') {
            json_response(['error' => ['code' => 'unsupported_media_type', 'message' => 'Content-Type must be application/json.']], 415);
        }

        $body = request_json(6000);
        $messageText = clean_optional_string($body['message'] ?? null, 4000, 'message');
        if ($messageText === null) {
            json_response(['error' => ['code' => 'message_required', 'message' => 'Write a message before sending.']], 422);
        }

        $rateConfig = app_config('app.message_rate_limit') ?? [];
        $maxRequests = max(1, (int)($rateConfig['max_requests'] ?? 8));
        $windowSeconds = max(60, (int)($rateConfig['window_seconds'] ?? 60));
        enforce_rate_limit($pdo, 'message:' . $session['session_id'], $maxRequests, $windowSeconds);

        $clientMessageId = $body['client_message_id'] ?? null;
        if ($clientMessageId === null) $clientMessageId = uuid_v4();
        if (!is_string($clientMessageId) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $clientMessageId)) {
            json_response(['error' => ['code' => 'invalid_idempotency_key', 'message' => 'client_message_id must be a UUID.']], 422);
        }
        $messageId = uuid_v4();
        $transportState = 'not_configured';
        $whatsappConfig = app_config('whatsapp') ?? [];
        $visitorWhatsApp = (string)($session['phone_normalized'] ?? '');
        $visitorOptedIn = !empty($session['whatsapp_opt_in_at']) && $visitorWhatsApp !== '';
        $outboundEnabled = is_array($whatsappConfig) && !empty($whatsappConfig['outbound_enabled']) && $visitorOptedIn;
        $pdo->beginTransaction();
        try {
            $insert = $pdo->prepare(
                'INSERT INTO messages
                    (id, conversation_id, client_message_id, sender_type, body, delivery_status, sent_at, created_at)
                 VALUES
                    (:id, :conversation_id, :client_message_id, "visitor", :body, :delivery_status, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
            );
            $insert = $pdo->prepare(str_replace('INSERT INTO messages', 'INSERT IGNORE INTO messages', $insert->queryString));
            $insert->execute([
                ':id' => $messageId,
                ':conversation_id' => $session['conversation_id'],
                ':client_message_id' => $clientMessageId,
                ':body' => $messageText,
                ':delivery_status' => $outboundEnabled ? 'queued' : 'web_only',
            ]);

            $wasInserted = $insert->rowCount() === 1;
            $sequenceQuery = $pdo->prepare('SELECT id, sequence_id, sent_at, delivery_status, body FROM messages WHERE conversation_id = :conversation_id AND client_message_id = :client_message_id LIMIT 1');
            $sequenceQuery->execute([':conversation_id' => $session['conversation_id'], ':client_message_id' => $clientMessageId]);
            $saved = $sequenceQuery->fetch();
            if (!$saved) throw new RuntimeException('Message idempotency lookup failed.');
            if (!hash_equals((string)$saved['body'], $messageText)) {
                $pdo->rollBack();
                json_response(['error' => ['code' => 'idempotency_conflict', 'message' => 'This message ID was already used for different text.']], 409);
            }
            $messageId = (string)$saved['id'];

            $update = $pdo->prepare(
                'UPDATE conversations
                 SET last_message_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
                 WHERE id = :id AND status = "open"'
            );
            if ($wasInserted) $update->execute([':id' => $session['conversation_id']]);

            if ($wasInserted && $outboundEnabled) {
                enqueue_whatsapp_notification($pdo, $messageId, $visitorWhatsApp, mb_substr_safe($messageText, 0, 1000));
                $transportState = 'queued';
            }

            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }

        json_response([
            'data' => [
                'id' => $messageId,
                'sequence_id' => (int)$saved['sequence_id'],
                'conversation_id' => $session['conversation_id'],
                'sender' => 'visitor',
                'message' => $messageText,
                'delivery_status' => $saved['delivery_status'],
                'created_at' => (new DateTimeImmutable($saved['sent_at'], new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            ],
            'meta' => [
                'transport' => $wasInserted ? $transportState : ($saved['delivery_status'] === 'queued' ? 'queued' : 'replayed'),
                'idempotent_replay' => !$wasInserted,
                'note' => $transportState === 'queued'
                    ? 'Your message is saved and queued for WhatsApp delivery. Delivery is confirmed separately.'
                    : 'Your message is saved in this website chat. WhatsApp delivery is not enabled for this conversation.',
            ],
        ], $wasInserted ? 201 : 200);
    }

    $afterRaw = $_GET['after'] ?? '0';
    if (!is_string($afterRaw) || !preg_match('/^\d{1,18}$/', $afterRaw)) {
        json_response(['error' => ['code' => 'invalid_cursor', 'message' => 'The after cursor must be a non-negative integer.']], 422);
    }
    $after = (int)$afterRaw;
    $limitRaw = $_GET['limit'] ?? '50';
    if (!is_string($limitRaw) || !preg_match('/^\d{1,3}$/', $limitRaw)) {
        json_response(['error' => ['code' => 'invalid_limit', 'message' => 'The limit must be an integer between 1 and 100.']], 422);
    }
    $limit = max(1, min(100, (int)$limitRaw));

    $query = $pdo->prepare(
        'SELECT sequence_id, id, sender_type, body, delivery_status, sent_at
         FROM messages
         WHERE conversation_id = :conversation_id AND sequence_id > :after
         ORDER BY sequence_id ASC
         LIMIT ' . ($limit + 1)
    );
    $query->bindValue(':conversation_id', $session['conversation_id']);
    $query->bindValue(':after', $after, PDO::PARAM_INT);
    $query->execute();

    $rows = $query->fetchAll();
    $hasMore = count($rows) > $limit;
    if ($hasMore) {
        $rows = array_slice($rows, 0, $limit);
    }
    $items = array_map(static function (array $row): array {
        return [
            'id' => $row['id'],
            'sequence_id' => (int)$row['sequence_id'],
            'sender' => $row['sender_type'],
            'message' => $row['body'],
            'delivery_status' => $row['delivery_status'],
            'created_at' => (new DateTimeImmutable($row['sent_at'], new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
        ];
    }, $rows);

    $nextCursor = $items === [] ? $after : $items[array_key_last($items)]['sequence_id'];
    $statusQuery = $pdo->prepare(
        'SELECT id, delivery_status FROM messages
         WHERE conversation_id = :conversation_id AND sender_type = "visitor"
         ORDER BY sequence_id DESC LIMIT 50'
    );
    $statusQuery->execute([':conversation_id' => $session['conversation_id']]);
    $deliveryUpdates = array_map(static fn(array $row): array => [
        'id' => $row['id'], 'delivery_status' => $row['delivery_status'],
    ], $statusQuery->fetchAll());

    json_response([
        'data' => $items,
        'meta' => [
            'after' => $after,
            'next_cursor' => $nextCursor,
            'has_more' => $hasMore,
            'poll_after_ms' => 3000,
            'delivery_updates' => $deliveryUpdates,
        ],
    ]);
} catch (Throwable $error) {
    error_log('[DimdianChatbot] Message API failed: ' . $error->getMessage());
    json_response(['error' => ['code' => 'server_error', 'message' => 'The chat service could not complete that request. Please try again.']], 500);
}
