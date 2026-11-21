<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/whatsapp.php';
require_once dirname(__DIR__, 2) . '/app/consent.php';

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
$whatsappOptIn = $body['whatsapp_opt_in'] ?? false;
if (!is_bool($whatsappOptIn)) {
    json_response(['error' => ['code' => 'invalid_field', 'message' => 'whatsapp_opt_in must be true or false.']], 422);
}
$normalizedPhone = $phone !== null ? normalize_whatsapp_number($phone) : null;
if ($phone !== null) {
    $phoneInput = preg_replace('/[\s().-]+/', '', $phone) ?? '';
    $validInternationalInput = preg_match('/^(?:\+[1-9][0-9]{7,14}|00[1-9][0-9]{7,14})$/', $phoneInput) === 1;
    if (
        !$validInternationalInput ||
        $normalizedPhone === '' ||
        !preg_match('/^[1-9][0-9]{7,14}$/', $normalizedPhone)
    ) {
        json_response(['error' => ['code' => 'invalid_field', 'message' => 'Enter a valid international phone number with its country code, such as +2348012345678.']], 422);
    }
}
if ($whatsappOptIn && $normalizedPhone === null) {
    json_response(['error' => ['code' => 'whatsapp_phone_required', 'message' => 'Enter your WhatsApp number to opt in.']], 422);
}

try {
    $pdo = database();
    $repository = chat_repository($pdo);

    $rateConfig = app_config('app.session_rate_limit') ?? [];
    $maxRequests = max(1, (int)($rateConfig['max_requests'] ?? 10));
    $windowSeconds = max(60, (int)($rateConfig['window_seconds'] ?? 900));
    enforce_rate_limit($repository, 'session:' . client_fingerprint(), $maxRequests, $windowSeconds);

    $sessionId = uuid_v4();
    $conversationId = uuid_v4();
    $guestId = uuid_v4();
    // The browser receives this random token once. Only its SHA-256 hash is stored in MySQL, so a database read does not reveal reusable session credentials.
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);

    $ttlHours = max(1, min(720, (int)(app_config('app.guest_session_ttl_hours') ?? 168)));
    $createdAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $expiresAt = $createdAt->modify('+' . $ttlHours . ' hours');

    $repository->createGuestSession([
        'session_id' => $sessionId,
        'conversation_id' => $conversationId,
        'guest_id' => $guestId,
        'name' => $name,
        'phone' => $phone,
        'phone_normalized' => $normalizedPhone,
        'whatsapp_opt_in' => $whatsappOptIn,
        'token_hash' => $tokenHash,
        'expires_at' => $expiresAt,
        'created_at' => $createdAt,
    ]);

    json_response([
        'data' => [
            'session_id' => $sessionId,
            'conversation_id' => $conversationId,
            'session_token' => $token,
            'expires_at' => $expiresAt->format(DateTimeInterface::ATOM),
            'created_at' => $createdAt->format(DateTimeInterface::ATOM),
            'whatsapp_opt_in' => $whatsappOptIn,
        ],
    ], 201);
} catch (Throwable $error) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    app_log_event('session.create_failed', ['endpoint' => 'session', 'exception_class' => get_class($error)]);
    json_response(['error' => ['code' => 'server_error', 'message' => 'A chat session could not be started. Please try again.']], 500);
}
