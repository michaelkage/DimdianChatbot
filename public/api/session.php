<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

enforce_allowed_origin();
handle_preflight();
require_method('POST');

$contentType = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
if ($contentType !== 'application/json') {
    json_response(['error' => ['code' => 'unsupported_media_type', 'message' => 'Content-Type must be application/json.']], 415);
}

$body = request_json();
$name = clean_optional_string($body['name'] ?? null, 100, 'name');
$phone = clean_optional_string($body['phone'] ?? null, 32, 'phone');

try {
    $pdo = database();

    $rateConfig = app_config('app.session_rate_limit') ?? [];
    $maxRequests = max(1, (int)($rateConfig['max_requests'] ?? 10));
    $windowSeconds = max(60, (int)($rateConfig['window_seconds'] ?? 900));
    enforce_rate_limit($pdo, 'session:' . client_fingerprint(), $maxRequests, $windowSeconds);

    $sessionId = uuid_v4();
    $conversationId = uuid_v4();
    $guestId = uuid_v4();
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);

    $ttlHours = max(1, min(720, (int)(app_config('app.guest_session_ttl_hours') ?? 168)));
    $createdAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $expiresAt = $createdAt->modify('+' . $ttlHours . ' hours');

    $pdo->beginTransaction();

    $guest = $pdo->prepare(
        'INSERT INTO guests (id, display_name, phone, created_at, updated_at)
         VALUES (:id, :name, :phone, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
    );
    $guest->execute([':id' => $guestId, ':name' => $name, ':phone' => $phone]);

    $conversation = $pdo->prepare(
        'INSERT INTO conversations (id, guest_id, status, created_at, updated_at)
         VALUES (:id, :guest_id, "open", UTC_TIMESTAMP(), UTC_TIMESTAMP())'
    );
    $conversation->execute([':id' => $conversationId, ':guest_id' => $guestId]);

    $session = $pdo->prepare(
        'INSERT INTO guest_sessions (id, guest_id, conversation_id, token_hash, created_at, expires_at)
         VALUES (:id, :guest_id, :conversation_id, :token_hash, UTC_TIMESTAMP(), :expires_at)'
    );
    $session->execute([
        ':id' => $sessionId,
        ':guest_id' => $guestId,
        ':conversation_id' => $conversationId,
        ':token_hash' => $tokenHash,
        ':expires_at' => $expiresAt->format('Y-m-d H:i:s'),
    ]);

    $pdo->commit();

    json_response([
        'data' => [
            'session_id' => $sessionId,
            'conversation_id' => $conversationId,
            'session_token' => $token,
            'expires_at' => $expiresAt->format(DateTimeInterface::ATOM),
            'created_at' => $createdAt->format(DateTimeInterface::ATOM),
        ],
    ], 201);
} catch (Throwable $error) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('[DimdianChatbot] Guest session creation failed: ' . $error->getMessage());
    json_response(['error' => ['code' => 'server_error', 'message' => 'A chat session could not be started. Please try again.']], 500);
}
