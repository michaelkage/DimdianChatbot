<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

enforce_allowed_origin();
handle_preflight();

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST, OPTIONS');
    json_response(['error' => ['code' => 'method_not_allowed', 'message' => 'Use GET to retrieve messages or POST to send one.']], 405);
}

try {
    $pdo = database();
    $session = authenticate_guest_session($pdo);

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

        $messageId = uuid_v4();
        $pdo->beginTransaction();
        try {
            $insert = $pdo->prepare(
                'INSERT INTO messages
                    (id, conversation_id, sender_type, body, delivery_status, sent_at, created_at)
                 VALUES
                    (:id, :conversation_id, "visitor", :body, "queued", UTC_TIMESTAMP(), UTC_TIMESTAMP())'
            );
            $insert->execute([
                ':id' => $messageId,
                ':conversation_id' => $session['conversation_id'],
                ':body' => $messageText,
            ]);

            $sequenceQuery = $pdo->prepare('SELECT sequence_id, sent_at FROM messages WHERE id = :id LIMIT 1');
            $sequenceQuery->execute([':id' => $messageId]);
            $saved = $sequenceQuery->fetch();

            $update = $pdo->prepare(
                'UPDATE conversations
                 SET last_message_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
                 WHERE id = :id AND status = "open"'
            );
            $update->execute([':id' => $session['conversation_id']]);

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
                'delivery_status' => 'queued',
                'created_at' => (new DateTimeImmutable($saved['sent_at'], new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            ],
            'meta' => [
                'transport' => 'not_configured',
                'note' => 'Your message is saved. WhatsApp delivery will become available when the WhatsApp integration is configured.',
            ],
        ], 201);
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
         LIMIT ' . $limit
    );
    $query->bindValue(':conversation_id', $session['conversation_id']);
    $query->bindValue(':after', $after, PDO::PARAM_INT);
    $query->execute();

    $rows = $query->fetchAll();
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

    json_response([
        'data' => $items,
        'meta' => [
            'after' => $after,
            'next_cursor' => $nextCursor,
            'has_more' => count($items) === $limit,
            'poll_after_ms' => 3000,
        ],
    ]);
} catch (Throwable $error) {
    error_log('[DimdianChatbot] Message API failed: ' . $error->getMessage());
    json_response(['error' => ['code' => 'server_error', 'message' => 'The chat service could not complete that request. Please try again.']], 500);
}
