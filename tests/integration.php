<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/whatsapp.php';
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/maintenance/RetentionService.php';
require_once dirname(__DIR__) . '/app/chat/GuestSessionService.php';

$base = getenv('DIMDIAN_TEST_BASE_URL') ?: 'http://127.0.0.1:8080';
$origin = 'https://website.example.test';
$pdo = new PDO(
    getenv('DIMDIAN_TEST_DSN') ?: 'mysql:host=127.0.0.1;dbname=dimdian_test;charset=utf8mb4',
    getenv('DIMDIAN_TEST_DB_USER') ?: 'root',
    getenv('DIMDIAN_TEST_DB_PASSWORD') ?: 'root',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$passed = 0;
$failed = 0;

function expect(bool $condition, string $label): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        fwrite(STDOUT, "PASS {$label}\n");
    } else {
        $failed++;
        fwrite(STDERR, "FAIL {$label}\n");
    }
}

// GuestSessionService contract: session creation, hash-only token storage and rate-limit signaling.
$guestSessionService = new GuestSessionService(chat_repository($pdo), app_config());
$serviceGuestSession = $guestSessionService->create([
    'name' => 'Service integration visitor',
    'phone' => null,
    'phone_normalized' => null,
    'whatsapp_opt_in' => false,
], 'integration-session-service');
$storedGuestToken = $pdo->prepare('SELECT token_hash FROM guest_sessions WHERE id = :id');
$storedGuestToken->execute([':id' => $serviceGuestSession['session_id']]);
expect(
    preg_match('/^[a-f0-9]{64}$/', $serviceGuestSession['session_token']) === 1
        && hash_equals(hash('sha256', $serviceGuestSession['session_token']), (string) $storedGuestToken->fetchColumn())
        && $serviceGuestSession['conversation_id'] !== ''
        && $serviceGuestSession['expires_at'] !== '',
    'guest session service returns a one-time token while persisting only its SHA-256 hash'
);

$defaultTenantId = (string) (app_config('tenancy.default_tenant_id') ?? '00000000-0000-4000-8000-000000000001');
$otherTenantId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
$tenantScopedRepository = chat_repository($pdo);
$serviceTokenHash = hash('sha256', $serviceGuestSession['session_token']);
expect(
    $tenantScopedRepository->findGuestSessionByTokenHash($serviceTokenHash, $defaultTenantId) !== null,
    'guest session is visible inside its owning tenant'
);
expect(
    $tenantScopedRepository->findGuestSessionByTokenHash($serviceTokenHash, $otherTenantId) === null,
    'guest session token cannot resolve under another tenant'
);
expect(
    $tenantScopedRepository->listConversationMessages($otherTenantId, $serviceGuestSession['conversation_id'], 0, 50)['items'] === [],
    'conversation polling cannot expose messages across tenant boundaries'
);
$limitedSessionService = new GuestSessionService(chat_repository($pdo), [
    'app' => [
        'session_rate_limit' => ['max_requests' => 1, 'window_seconds' => 60],
        'guest_session_ttl_hours' => 1,
    ],
]);
$limitedSessionService->create([
    'name' => null,
    'phone' => null,
    'phone_normalized' => null,
    'whatsapp_opt_in' => false,
], 'integration-session-rate-limit');
$sessionRateLimitRejected = false;
try {
    $limitedSessionService->create([
        'name' => null,
        'phone' => null,
        'phone_normalized' => null,
        'whatsapp_opt_in' => false,
    ], 'integration-session-rate-limit');
} catch (GuestSessionRateLimitExceeded $error) {
    $sessionRateLimitRejected = $error->retryAfterSeconds === 60;
}
expect($sessionRateLimitRejected, 'guest session service raises a bounded retry signal when the rate limit is exhausted');

// Meta connection repository contract: one-time state consumption and durable token lifecycle.
$metaRepository = meta_connection_repository($pdo);
$metaStateHash = hash('sha256', bin2hex(random_bytes(32)));
$metaRepository->createSignupState($metaStateHash, 600);
expect($metaRepository->consumeSignupState($metaStateHash), 'Meta signup state is consumed once while valid');
expect(!$metaRepository->consumeSignupState($metaStateHash), 'Meta signup state cannot be replayed');

$metaPhoneId = (string) random_int(100000000000000000, 999999999999999999);
$metaWabaId = (string) random_int(100000000000000000, 999999999999999999);
$metaRepository->saveActiveConnection([
    'waba_id' => $metaWabaId,
    'phone_number_id' => $metaPhoneId,
    'token_ciphertext' => 'integration-ciphertext',
    'token_nonce' => str_repeat('n', 12),
    'token_tag' => str_repeat('t', 16),
    'token_expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
]);
$metaConnection = $metaRepository->findActiveConnection();
expect(
    is_array($metaConnection)
        && (string) $metaConnection['phone_number_id'] === $metaPhoneId
        && (string) $metaConnection['waba_id'] === $metaWabaId
        && $metaRepository->activePhoneNumberId() === $metaPhoneId,
    'Meta repository persists and resolves the active WABA and phone connection'
);
$metaRepository->updateAccessToken((int) $metaConnection['id'], [
    'ciphertext' => 'refreshed-integration-ciphertext',
    'nonce' => str_repeat('r', 12),
    'tag' => str_repeat('u', 16),
], gmdate('Y-m-d H:i:s', time() + 7200));
$metaRefreshed = $metaRepository->findActiveConnection();
expect(
    is_array($metaRefreshed)
        && (string) $metaRefreshed['access_token_ciphertext'] === 'refreshed-integration-ciphertext',
    'Meta repository persists refreshed encrypted token material'
);
$metaRepository->markConnectionExpired((int) $metaConnection['id']);
$metaStatus = $pdo->prepare('SELECT status FROM meta_connections WHERE id = :id');
$metaStatus->execute([':id' => $metaConnection['id']]);
expect($metaStatus->fetchColumn() === 'expired', 'Meta repository marks an expired connection without deleting its audit record');
$pdo->prepare('DELETE FROM meta_connections WHERE phone_number_id = :phone')->execute([':phone' => $metaPhoneId]);
$pdo->prepare('DELETE FROM meta_signup_states WHERE state_sha256 = :hash')->execute([':hash' => $metaStateHash]);

// Exercise every production retention DELETE path against a clean database using non-matching cutoffs.
$retentionService = new RetentionService(retention_repository($pdo), [
    'privacy' => [
        'closed_conversation_retention_days' => 365,
        'webhook_event_retention_days' => 30,
        'expired_session_retention_days' => 30,
    ],
    'staff_inbox' => ['enabled' => true],
]);
$retentionNoop = $retentionService->run(new DateTimeImmutable('1970-01-01T00:00:00+00:00'));
expect(
    count($retentionNoop) === 17
        && array_sum($retentionNoop) === 0
        && isset($retentionNoop['staff_audit_events'], $retentionNoop['staff_auth_nonces']),
    'retention repository executes all core and enabled optional cleanup paths without pruning newer records'
);

function request_api(string $method, string $path, ?array $body = null, array $headers = []): array
{
    global $base;
    $headers = array_merge(['Accept: application/json'], $headers);
    $content = '';
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        $content = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'content' => $content,
        'ignore_errors' => true,
        'timeout' => 10,
    ]]);
    $raw = file_get_contents($base . $path, false, $context);
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $match)) $status = (int)$match[1];
    }
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    return [$status, $decoded, is_string($raw) ? $raw : ''];
}

function webhook_raw_post(string $raw, string $secret): array
{
    global $base;
    $signature = 'sha256=' . hash_hmac('sha256', $raw, $secret);
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\nX-Hub-Signature-256: {$signature}\r\n",
        'content' => $raw,
        'ignore_errors' => true,
        'timeout' => 10,
    ]]);
    $response = file_get_contents($base . '/api/webhook.php', false, $context);
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $match)) $status = (int) $match[1];
    }
    return [$status, is_string($response) ? $response : ''];
}

function webhook_post(array $payload, string $secret): array
{
    global $base;
    $raw = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $signature = 'sha256=' . hash_hmac('sha256', $raw, $secret);
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\nX-Hub-Signature-256: {$signature}\r\n",
        'content' => $raw,
        'ignore_errors' => true,
        'timeout' => 10,
    ]]);
    $response = file_get_contents($base . '/api/webhook.php', false, $context);
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $match)) $status = (int)$match[1];
    }
    return [$status, is_string($response) ? json_decode($response, true) : null];
}

[$healthStatus, $healthBody] = request_api('GET', '/api/health.php', null, ['Origin: ' . $origin]);
expect(
    $healthStatus === 200
        && ($healthBody['status'] ?? '') === 'healthy'
        && ($healthBody['checks']['database'] ?? '') === 'ok'
        && ($healthBody['checks']['schema'] ?? '') === 'ok',
    'read-only health endpoint confirms database and required schema'
);


// The versioned routes must preserve the exact same behavior as the legacy API.
[$v1HealthStatus, $v1HealthBody] = request_api('GET', '/api/v1/health.php', null, ['Origin: ' . $origin]);
expect(
    $v1HealthStatus === 200
        && ($v1HealthBody['status'] ?? '') === 'healthy'
        && ($v1HealthBody['checks']['database'] ?? '') === 'ok',
    'versioned health route delegates to the same healthy service'
);

[$v1SessionStatus, $v1SessionBody] = request_api('POST', '/api/v1/session.php', [
    'name' => 'Versioned API Visitor',
], ['Origin: ' . $origin]);
expect($v1SessionStatus === 201 && isset($v1SessionBody['data']['session_token']), 'versioned session route creates a guest session');
$v1Token = $v1SessionBody['data']['session_token'] ?? '';
$v1Headers = ['Origin: ' . $origin, 'X-DIMDIAN-GUEST-TOKEN: ' . $v1Token];
$v1MessageId = '650e8400-e29b-41d4-a716-446655440000';
[$v1MessageStatus, $v1MessageBody] = request_api('POST', '/api/v1/messages.php', [
    'message' => 'Hello through the versioned API.',
    'client_message_id' => $v1MessageId,
], $v1Headers);
expect($v1MessageStatus === 201 && ($v1MessageBody['data']['delivery_status'] ?? '') === 'web_only', 'versioned message route persists a website-only message');
[$v1PollStatus, $v1PollBody] = request_api('GET', '/api/v1/messages.php?after=0&limit=50', null, $v1Headers);
expect($v1PollStatus === 200 && count($v1PollBody['data'] ?? []) === 1, 'versioned message route polls the authenticated conversation');

final class IntegrationSuccessTransport implements WhatsAppTextTransport
{
    public function sendTemplate(string $recipient, string $bodyParameter): array
    {
        if ($recipient !== '2348031234567' || $bodyParameter === '') throw new RuntimeException('Unexpected transport input.');
        return ['provider_message_id' => 'wamid.integration.outbox.success'];
    }
}

final class IntegrationPermanentFailureTransport implements WhatsAppTextTransport
{
    public function sendTemplate(string $recipient, string $bodyParameter): array
    {
        throw new WhatsAppTransportException('Template rejected in integration test.', false);
    }
}

final class IntegrationRetryTransport implements WhatsAppTextTransport
{
    public function sendTemplate(string $recipient, string $bodyParameter): array
    {
        throw new WhatsAppTransportException('Temporary integration test failure.', true);
    }
}

final class IntegrationCountingTransport implements WhatsAppTextTransport
{
    public int $calls = 0;

    public function sendTemplate(string $recipient, string $bodyParameter): array
    {
        $this->calls++;
        return ['provider_message_id' => 'wamid.integration.stale-lease'];
    }
}

$sessionHeaders = ['Origin: ' . $origin];
[$badOriginStatus] = request_api('POST', '/api/session.php', [], ['Origin: https://evil.example']);
expect($badOriginStatus === 403, 'session endpoint rejects an unlisted origin');
[$localPhoneStatus] = request_api('POST', '/api/session.php', [
    'phone' => '08031234567',
    'whatsapp_opt_in' => true,
], $sessionHeaders);
expect($localPhoneStatus === 422, 'session endpoint rejects a local-format phone number for WhatsApp continuation');
[$malformedPhoneStatus] = request_api('POST', '/api/session.php', [
    'phone' => '+234 ABC 1234567',
    'whatsapp_opt_in' => true,
], $sessionHeaders);
expect($malformedPhoneStatus === 422, 'session endpoint rejects non-numeric phone input');

[$sessionStatus, $sessionBody] = request_api('POST', '/api/session.php', [
    'name' => 'Integration Visitor',
    'phone' => '+234 803 123 4567',
    'whatsapp_opt_in' => true,
], $sessionHeaders);
expect($sessionStatus === 201 && isset($sessionBody['data']['session_token']), 'guest session can be created with optional WhatsApp opt-in');
$token = $sessionBody['data']['session_token'] ?? '';
$conversationId = $sessionBody['data']['conversation_id'] ?? '';
$guestHeaders = array_merge($sessionHeaders, ['X-DIMDIAN-GUEST-TOKEN: ' . $token]);
expect(is_string($token) && preg_match('/^[a-f0-9]{64}$/', $token) === 1, 'session returns a 256-bit hex token');
$consentAudit = $pdo->prepare('SELECT COUNT(*) FROM guest_consent_events WHERE action = "opt_in" AND channel = "whatsapp" AND guest_id = (SELECT guest_id FROM conversations WHERE id = :conversation)');
$consentAudit->execute([':conversation' => $conversationId]);
expect((int) $consentAudit->fetchColumn() === 1, 'WhatsApp opt-in is written to the auditable consent ledger');
$identityLink = $pdo->prepare('SELECT COUNT(*) FROM whatsapp_identity_links WHERE conversation_id = :conversation AND is_active = 1');
$identityLink->execute([':conversation' => $conversationId]);
expect((int) $identityLink->fetchColumn() === 1, 'opted-in phone is linked to exactly one active conversation');

[$missingTokenStatus] = request_api('GET', '/api/messages.php', null, $sessionHeaders);
expect($missingTokenStatus === 401, 'message endpoint requires guest token');

$clientMessageId = '550e8400-e29b-41d4-a716-446655440000';
[$messageStatus, $messageBody] = request_api('POST', '/api/messages.php', [
    'message' => 'Hello from the integration suite.',
    'client_message_id' => $clientMessageId,
], $guestHeaders);
expect($messageStatus === 201 && ($messageBody['data']['delivery_status'] ?? '') === 'web_only', 'message persists without enabling WhatsApp transport');
$messageId = $messageBody['data']['id'] ?? '';
$sequence = (int)($messageBody['data']['sequence_id'] ?? 0);

[$replayStatus, $replayBody] = request_api('POST', '/api/messages.php', [
    'message' => 'Hello from the integration suite.',
    'client_message_id' => $clientMessageId,
], $guestHeaders);
expect($replayStatus === 200 && ($replayBody['data']['id'] ?? '') === $messageId && ($replayBody['meta']['idempotent_replay'] ?? false), 'repeated message request is idempotent');

[$conflictStatus] = request_api('POST', '/api/messages.php', [
    'message' => 'Different text with the same key.',
    'client_message_id' => $clientMessageId,
], $guestHeaders);
expect($conflictStatus === 409, 'idempotency key cannot be reused for different text');

[$pollStatus, $pollBody] = request_api('GET', '/api/messages.php?after=0&limit=50', null, $guestHeaders);
expect($pollStatus === 200 && count($pollBody['data'] ?? []) === 1, 'poll returns the conversation history');
expect(($pollBody['meta']['delivery_updates'][0]['delivery_status'] ?? '') === 'web_only', 'poll includes current delivery-state updates');

$secret = 'test-meta-app-secret';
$webhookMetaRepository = meta_connection_repository($pdo);
$webhookMetaRepository->saveActiveConnection([
    'tenant_id' => $defaultTenantId,
    'waba_id' => 'test-waba',
    'phone_number_id' => 'test-phone-id',
    'token_ciphertext' => 'test-ciphertext',
    'token_nonce' => str_repeat('n', 12),
    'token_tag' => str_repeat('t', 16),
    'token_expires_at' => null,
]);
expect(
    $webhookMetaRepository->tenantIdForPhoneNumberId('test-phone-id') === $defaultTenantId
        && $webhookMetaRepository->tenantIdForPhoneNumberId('unknown-phone-id') === null,
    'Meta webhook destination lookup resolves only an active phone-number connection'
);
$crossTenantMetaReassignmentRejected = false;
try {
    $webhookMetaRepository->saveActiveConnection([
        'tenant_id' => $otherTenantId,
        'waba_id' => 'another-waba',
        'phone_number_id' => 'test-phone-id',
        'token_ciphertext' => 'other-ciphertext',
        'token_nonce' => str_repeat('o', 12),
        'token_tag' => str_repeat('p', 16),
        'token_expires_at' => null,
    ]);
} catch (RuntimeException) {
    $crossTenantMetaReassignmentRejected = true;
}
expect(
    $crossTenantMetaReassignmentRejected
        && $webhookMetaRepository->tenantIdForPhoneNumberId('test-phone-id') === $defaultTenantId,
    'an existing Meta phone-number identity cannot be reassigned to another tenant'
);
[$verifyStatus, $verifyBody] = request_api('GET', '/api/webhook.php?hub.mode=subscribe&hub.verify_token=test-webhook-token&hub.challenge=challenge-123');
expect($verifyStatus === 200 && $verifyBody === null, 'Meta webhook verification returns a plain-text challenge');
[$unsupportedWebhookMethod] = request_api('DELETE', '/api/webhook.php');
expect($unsupportedWebhookMethod === 405, 'Meta webhook route rejects unsupported HTTP methods');
[$invalidWebhookJson] = webhook_raw_post('{', $secret);
expect($invalidWebhookJson === 400, 'Meta webhook route rejects malformed JSON after signature verification');
$echoPayload = [
    'object' => 'whatsapp_business_account',
    'entry' => [[
        'id' => 'test-waba',
        'changes' => [[
            'field' => 'smb_message_echoes',
            'value' => [
                'metadata' => ['phone_number_id' => 'test-phone-id'],
                'message_echoes' => [[
                    'from' => '2348000000000',
                    'to' => '2348031234567',
                    'id' => 'wamid.integration.echo.1',
                    'timestamp' => '1739321024',
                    'type' => 'text',
                    'text' => ['body' => 'Support reply from WhatsApp Business app.'],
                ]],
            ],
        ]],
    ]],
];
[$echoStatus, $echoBody] = webhook_post($echoPayload, $secret);
expect($echoStatus === 200 && ($echoBody['processed'] ?? 0) === 1, 'valid app-message echo is accepted and correlated');
[$echoReplayStatus, $echoReplayBody] = webhook_post($echoPayload, $secret);
expect($echoReplayStatus === 200 && ($echoReplayBody['processed'] ?? -1) === 0, 'duplicate app-message echo is deduplicated');

[$pollAfterEchoStatus, $pollAfterEcho] = request_api('GET', '/api/messages.php?after=' . $sequence . '&limit=50', null, $guestHeaders);
expect($pollAfterEchoStatus === 200 && count($pollAfterEcho['data'] ?? []) === 1 && ($pollAfterEcho['data'][0]['sender'] ?? '') === 'business', 'website polling receives the Business-app reply');

$inboundPayload = [
    'object' => 'whatsapp_business_account',
    'entry' => [[
        'id' => 'test-waba',
        'changes' => [[
            'field' => 'messages',
            'value' => ['metadata' => ['phone_number_id' => 'test-phone-id'], 'messages' => [[
                'from' => '2348031234567',
                'id' => 'wamid.integration.inbound.1',
                'timestamp' => '1739321025',
                'type' => 'text',
                'text' => ['body' => 'Visitor replied from WhatsApp.'],
            ]]],
        ]],
    ]],
];
[$inboundStatus, $inboundBody] = webhook_post($inboundPayload, $secret);
expect($inboundStatus === 200 && ($inboundBody['processed'] ?? 0) === 1, 'inbound WhatsApp text is correlated to the website conversation');

$statusPayload = [
    'object' => 'whatsapp_business_account',
    'entry' => [[
        'id' => 'test-waba',
        'changes' => [[
            'field' => 'messages',
            'value' => ['metadata' => ['phone_number_id' => 'test-phone-id'], 'statuses' => [[
                'id' => 'wamid.integration.early-status',
                'status' => 'delivered',
                'timestamp' => '1739321026',
                'recipient_id' => '2348031234567',
            ]]],
        ]],
    ]],
];
[$earlyStatusCode] = webhook_post($statusPayload, $secret);
expect($earlyStatusCode === 200, 'early delivery status webhook is accepted');
$pdo->prepare('DELETE FROM meta_connections WHERE phone_number_id = :phone')->execute([':phone' => 'test-phone-id']);
expect($webhookMetaRepository->tenantIdForPhoneNumberId('test-phone-id') === null, 'revoked or deleted Meta destinations no longer resolve to a tenant');
$pdo->prepare(
    'INSERT INTO messages (id, conversation_id, sender_type, body, provider_message_id, delivery_status, sent_at, created_at)
     VALUES (:id, :conversation_id, "visitor", "status race", :provider_id, "sent", UTC_TIMESTAMP(), UTC_TIMESTAMP())'
)->execute([
    ':id' => 'e0000000-0000-4000-8000-000000000001',
    ':conversation_id' => $conversationId,
    ':provider_id' => 'wamid.integration.early-status',
]);
expect(chat_repository($pdo)->reconcileProviderMessageStatus($defaultTenantId, 'wamid.integration.early-status'), 'early status is reconciled after the provider message ID is attached');
$statusCheck = $pdo->query("SELECT delivery_status FROM messages WHERE provider_message_id = 'wamid.integration.early-status'")->fetchColumn();
expect($statusCheck === 'delivered', 'reconciled early status updates the message row');
$pdo->prepare(
    'INSERT IGNORE INTO message_status_events (provider_message_id, status, event_timestamp, created_at)
     VALUES ("wamid.integration.early-status", "failed", 1739321027, UTC_TIMESTAMP())'
)->execute();
chat_repository($pdo)->reconcileProviderMessageStatus($defaultTenantId, 'wamid.integration.early-status');
$statusAfterLateFailure = $pdo->query("SELECT delivery_status FROM messages WHERE provider_message_id = 'wamid.integration.early-status'")->fetchColumn();
expect($statusAfterLateFailure === 'delivered', 'late failure callback cannot downgrade a delivered message');

$sentThenFailedId = 'e0000000-0000-4000-8000-000000000006';
$pdo->prepare(
    'INSERT INTO messages (id, conversation_id, sender_type, body, provider_message_id, delivery_status, sent_at, created_at)
     VALUES (:id, :conversation_id, "visitor", "sent then failed", "wamid.integration.sent-then-failed", "sent", UTC_TIMESTAMP(), UTC_TIMESTAMP())'
)->execute([':id' => $sentThenFailedId, ':conversation_id' => $conversationId]);
$pdo->prepare(
    'INSERT IGNORE INTO message_status_events (provider_message_id, status, event_timestamp, created_at)
     VALUES ("wamid.integration.sent-then-failed", "sent", 1739321028, UTC_TIMESTAMP()),
            ("wamid.integration.sent-then-failed", "failed", 1739321029, UTC_TIMESTAMP())'
)->execute();
chat_repository($pdo)->reconcileProviderMessageStatus($defaultTenantId, 'wamid.integration.sent-then-failed');
$failedAfterSent = $pdo->query("SELECT delivery_status FROM messages WHERE id = '{$sentThenFailedId}'")->fetchColumn();
expect($failedAfterSent === 'failed', 'failure status supersedes sent when delivery does not complete');

$successMessageId = 'e0000000-0000-4000-8000-000000000002';
$pdo->prepare(
    'INSERT INTO messages (id, conversation_id, sender_type, body, delivery_status, sent_at, created_at)
     VALUES (:id, :conversation_id, "visitor", "template success", "queued", UTC_TIMESTAMP(), UTC_TIMESTAMP())'
)->execute([':id' => $successMessageId, ':conversation_id' => $conversationId]);
expect(chat_repository($pdo)->hasWhatsAppConsent($successMessageId), 'outbox worker can read the opted-in guest consent for a queued message');
enqueue_whatsapp_notification($pdo, $successMessageId, '2348031234567', 'Template body parameter');
$success = process_whatsapp_outbox($pdo, new IntegrationSuccessTransport(), 1, 3);
expect($success['sent'] === 1, 'outbox worker sends and acknowledges a successful job');
$successStatus = $pdo->query("SELECT delivery_status FROM messages WHERE id = '{$successMessageId}'")->fetchColumn();
expect($successStatus === 'sent', 'successful outbox job updates message state');

$retryMessageId = 'e0000000-0000-4000-8000-000000000003';
$pdo->prepare(
    'INSERT INTO messages (id, conversation_id, sender_type, body, delivery_status, sent_at, created_at)
     VALUES (:id, :conversation_id, "visitor", "temporary failure", "queued", UTC_TIMESTAMP(), UTC_TIMESTAMP())'
)->execute([':id' => $retryMessageId, ':conversation_id' => $conversationId]);
enqueue_whatsapp_notification($pdo, $retryMessageId, '2348031234567', 'Temporary failure');
$retry = process_whatsapp_outbox($pdo, new IntegrationRetryTransport(), 1, 3);
$retryRow = $pdo->query("SELECT status, attempts FROM message_outbox WHERE message_id = '{$retryMessageId}'")->fetch();
expect($retry['retried'] === 1 && $retryRow['status'] === 'pending' && (int)$retryRow['attempts'] === 1, 'retryable outbox failure is rescheduled');

$failedMessageId = 'e0000000-0000-4000-8000-000000000004';
$pdo->prepare(
    'INSERT INTO messages (id, conversation_id, sender_type, body, delivery_status, sent_at, created_at)
     VALUES (:id, :conversation_id, "visitor", "permanent failure", "queued", UTC_TIMESTAMP(), UTC_TIMESTAMP())'
)->execute([':id' => $failedMessageId, ':conversation_id' => $conversationId]);
enqueue_whatsapp_notification($pdo, $failedMessageId, '2348031234567', 'Permanent failure');
$failedSummary = process_whatsapp_outbox($pdo, new IntegrationPermanentFailureTransport(), 1, 3);
$failedStatus = $pdo->query("SELECT delivery_status FROM messages WHERE id = '{$failedMessageId}'")->fetchColumn();
expect($failedSummary['failed'] === 1 && $failedStatus === 'failed', 'permanent outbox failure is terminal');

$staleMessageId = 'e0000000-0000-4000-8000-000000000005';
$staleOutboxId = 'f0000000-0000-4000-8000-000000000005';
$pdo->prepare(
    'INSERT INTO messages (id, conversation_id, sender_type, body, delivery_status, sent_at, created_at)
     VALUES (:id, :conversation_id, "visitor", "exhausted worker lease", "queued", UTC_TIMESTAMP(), UTC_TIMESTAMP())'
)->execute([':id' => $staleMessageId, ':conversation_id' => $conversationId]);
$pdo->prepare(
    'INSERT INTO message_outbox
        (id, message_id, recipient, payload_text, status, attempts, available_at, locked_at, lock_token, created_at, updated_at)
     VALUES (:id, :message_id, "2348031234567", "already attempted six times", "processing", 6,
             UTC_TIMESTAMP(), DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE), "f0000000-0000-4000-8000-000000000006",
             UTC_TIMESTAMP(), UTC_TIMESTAMP())'
)->execute([':id' => $staleOutboxId, ':message_id' => $staleMessageId]);
$countingTransport = new IntegrationCountingTransport();
$staleSummary = process_whatsapp_outbox($pdo, $countingTransport, 1, 6);
$staleOutboxStatus = $pdo->query("SELECT status FROM message_outbox WHERE id = '{$staleOutboxId}'")->fetchColumn();
$staleMessageStatus = $pdo->query("SELECT delivery_status FROM messages WHERE id = '{$staleMessageId}'")->fetchColumn();
expect($countingTransport->calls === 0 && $staleOutboxStatus === 'failed', 'stale final-attempt job is failed without a seventh send');
expect($staleSummary['failed'] === 1 && $staleMessageStatus === 'failed', 'exhausted stale job updates visitor delivery state');

/*
 * WhatsApp outbox repository contract: lease reclaim, token fencing,
 * consent checks and atomic message/outbox state transitions.
 */
$outboxRepository = chat_repository($pdo);
$leaseMessageId = 'e0000000-0000-4000-8000-000000000007';
$leaseOutboxId = 'f0000000-0000-4000-8000-000000000006';
$pdo->prepare(
    'INSERT INTO messages (id, conversation_id, sender_type, body, delivery_status, sent_at, created_at)
     VALUES (:id, :conversation_id, "visitor", "lease fencing", "queued", UTC_TIMESTAMP(), UTC_TIMESTAMP())'
)->execute([':id' => $leaseMessageId, ':conversation_id' => $conversationId]);
$outboxRepository->enqueueWhatsAppOutbox('00000000-0000-4000-8000-000000000001', $leaseMessageId, '2348031234567', 'Lease fencing test', $leaseOutboxId);
expect($outboxRepository->hasWhatsAppConsent($leaseMessageId), 'outbox repository confirms current WhatsApp consent before sending');

$firstLeaseToken = uuid_v4();
$firstLease = $outboxRepository->claimWhatsAppOutbox($firstLeaseToken, 3, 300);
expect(is_array($firstLease['job']) && (string) $firstLease['job']['id'] === $leaseOutboxId && (int) $firstLease['job']['attempts'] === 1, 'outbox repository atomically leases a due job and increments attempts');
$pdo->prepare('UPDATE message_outbox SET locked_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE) WHERE id = :id')
    ->execute([':id' => $leaseOutboxId]);
$secondLeaseToken = uuid_v4();
$secondLease = $outboxRepository->claimWhatsAppOutbox($secondLeaseToken, 3, 300);
expect(is_array($secondLease['job']) && (string) $secondLease['job']['id'] === $leaseOutboxId && (int) $secondLease['job']['attempts'] === 2, 'expired outbox lease is reclaimed with a new attempt and token');
$staleCompletion = $outboxRepository->completeWhatsAppOutbox($leaseOutboxId, $firstLeaseToken, $leaseMessageId, 'wamid.integration.stale-owner');
expect(!$staleCompletion, 'stale outbox lease token cannot complete a job reclaimed by another worker');
$currentCompletion = $outboxRepository->completeWhatsAppOutbox($leaseOutboxId, $secondLeaseToken, $leaseMessageId, 'wamid.integration.lease-fenced');
$leaseState = $pdo->prepare('SELECT o.status, o.lock_token, m.delivery_status, m.provider_message_id FROM message_outbox o INNER JOIN messages m ON m.id=o.message_id WHERE o.id=:id');
$leaseState->execute([':id' => $leaseOutboxId]);
$leaseRow = $leaseState->fetch();
expect($currentCompletion && $leaseRow && $leaseRow['status'] === 'sent' && $leaseRow['lock_token'] === null, 'current outbox lease completes and releases its lock');
expect($leaseRow && $leaseRow['delivery_status'] === 'sent' && $leaseRow['provider_message_id'] === 'wamid.integration.lease-fenced', 'outbox completion updates the correlated visitor message atomically');

[$consentStatus, $consentBody] = request_api('POST', '/api/consent.php', ['whatsapp_opt_in' => false], $guestHeaders);
expect($consentStatus === 200 && ($consentBody['data']['whatsapp_opt_in'] ?? true) === false, 'visitor can revoke WhatsApp continuation without closing website chat');
$revoked = $pdo->prepare('SELECT g.whatsapp_opt_in_at, l.is_active FROM guests g INNER JOIN conversations c ON c.guest_id=g.id INNER JOIN whatsapp_identity_links l ON l.conversation_id=c.id WHERE c.id=:conversation');
$revoked->execute([':conversation' => $conversationId]);
$revokedRow = $revoked->fetch();
expect($revokedRow && $revokedRow['whatsapp_opt_in_at'] === null && (int)$revokedRow['is_active'] === 0, 'opt-out clears current consent and disables WhatsApp identity routing');
$optOutAudit = $pdo->prepare('SELECT COUNT(*) FROM guest_consent_events WHERE action="opt_out" AND guest_id=(SELECT guest_id FROM conversations WHERE id=:conversation)');
$optOutAudit->execute([':conversation' => $conversationId]);
expect((int)$optOutAudit->fetchColumn() === 1, 'WhatsApp opt-out is appended to the consent ledger');
$pdo->prepare('UPDATE conversations SET status = "closed" WHERE id = :id')->execute([':id' => $conversationId]);
[$closedHistoryStatus] = request_api('GET', '/api/messages.php?after=0&limit=50', null, $guestHeaders);
[$closedPostStatus] = request_api('POST', '/api/messages.php', ['message' => 'Cannot send', 'client_message_id' => '550e8400-e29b-41d4-a716-446655440001'], $guestHeaders);
expect($closedHistoryStatus === 200, 'closed conversation remains readable');
expect($closedPostStatus === 409, 'closed conversation rejects new visitor messages');

$badSignaturePayload = json_encode(['object' => 'whatsapp_business_account', 'entry' => []], JSON_THROW_ON_ERROR);
$badContext = stream_context_create(['http' => [
    'method' => 'POST',
    'header' => "Content-Type: application/json\r\nX-Hub-Signature-256: sha256=" . str_repeat('0', 64) . "\r\n",
    'content' => $badSignaturePayload,
    'ignore_errors' => true,
]]);
file_get_contents($base . '/api/webhook.php', false, $badContext);
$badSignatureStatus = 0;
foreach ($http_response_header ?? [] as $header) {
    if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $match)) $badSignatureStatus = (int)$match[1];
}
expect($badSignatureStatus === 401, 'invalid Meta webhook signature is rejected');


/*
 * Repository contract regression: the message and outbox writes must share
 * one transaction, and an idempotent replay must not enqueue a second send.
 */
[$repositorySessionStatus, $repositorySessionBody] = request_api('POST', '/api/session.php', [
    'name' => 'Repository Contract Visitor',
    'phone' => '+234 803 999 1234',
    'whatsapp_opt_in' => true,
], $sessionHeaders);
expect($repositorySessionStatus === 201, 'repository contract test creates an opted-in guest session');
$repositoryConversationId = (string) ($repositorySessionBody['data']['conversation_id'] ?? '');
$repositoryTenantId = '00000000-0000-4000-8000-000000000001';
$repositoryClientMessageId = '760e8400-e29b-41d4-a716-446655440000';
$repositoryOutboxId = uuid_v4();
$repositoryInput = [
    'tenant_id' => $repositoryTenantId,
    'id' => uuid_v4(),
    'conversation_id' => $repositoryConversationId,
    'client_message_id' => $repositoryClientMessageId,
    'body' => 'Repository transaction test.',
    'outbound_enabled' => true,
    'outbox_id' => $repositoryOutboxId,
    'recipient' => '2348039991234',
    'outbox_payload' => 'Repository transaction test.',
];
$repository = chat_repository($pdo);
$repositoryResult = $repository->createVisitorMessage($repositoryInput);
expect($repositoryResult['inserted'] === true && $repositoryResult['transport'] === 'queued', 'repository persists an outbound-eligible message');
$repositoryMessageId = (string) $repositoryResult['message']['id'];
$outboxCount = $pdo->prepare('SELECT COUNT(*) FROM message_outbox WHERE tenant_id = :tenant_id AND message_id = :message_id');
$outboxCount->execute([':tenant_id' => $repositoryTenantId, ':message_id' => $repositoryMessageId]);
expect((int) $outboxCount->fetchColumn() === 1, 'repository atomically creates exactly one WhatsApp outbox job');

$repositoryReplayInput = $repositoryInput;
$repositoryReplayInput['id'] = uuid_v4();
$repositoryReplayInput['outbox_id'] = uuid_v4();
$repositoryReplay = $repository->createVisitorMessage($repositoryReplayInput);
expect($repositoryReplay['inserted'] === false && $repositoryReplay['message']['id'] === $repositoryMessageId, 'repository returns the original message for an idempotent replay');
$outboxCount->execute([':tenant_id' => $repositoryTenantId, ':message_id' => $repositoryMessageId]);
expect((int) $outboxCount->fetchColumn() === 1, 'idempotent replay does not create a duplicate WhatsApp outbox job');

$atomicityClientMessageId = '760e8400-e29b-41d4-a716-446655440001';
$atomicityInput = $repositoryInput;
$atomicityInput['id'] = uuid_v4();
$atomicityInput['client_message_id'] = $atomicityClientMessageId;
$atomicityInput['body'] = 'This transaction must roll back.';
$atomicityInput['outbox_id'] = $repositoryOutboxId; // Deliberately collide with the already-created outbox primary key.
$atomicityFailed = false;
try {
    $repository->createVisitorMessage($atomicityInput);
} catch (PDOException $error) {
    $atomicityFailed = true;
}
$atomicityCheck = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE tenant_id = :tenant_id AND conversation_id = :conversation_id AND client_message_id = :client_message_id');
$atomicityCheck->execute([':tenant_id' => $repositoryTenantId, ':conversation_id' => $repositoryConversationId, ':client_message_id' => $atomicityClientMessageId]);
expect($atomicityFailed && (int) $atomicityCheck->fetchColumn() === 0, 'outbox insert failure rolls back the visitor message insert');


/*
 * Consent and rate-limit persistence are part of the shared repository contract.
 */
$repositoryGuestIdQuery = $pdo->prepare('SELECT guest_id FROM conversations WHERE tenant_id = :tenant_id AND id = :id');
$repositoryGuestIdQuery->execute([':tenant_id' => $repositoryTenantId, ':id' => $repositoryConversationId]);
$repositoryGuestId = (string) $repositoryGuestIdQuery->fetchColumn();
$repositoryPhoneQuery = $pdo->prepare('SELECT phone_normalized FROM guests WHERE tenant_id = :tenant_id AND id = :id');
$repositoryPhoneQuery->execute([':tenant_id' => $repositoryTenantId, ':id' => $repositoryGuestId]);
$repositoryPhone = (string) $repositoryPhoneQuery->fetchColumn();
$repository->recordWhatsAppConsent($repositoryTenantId, $repositoryGuestId, $repositoryConversationId, $repositoryPhone, 'opt_out', 'repository_contract_test');
$repositoryConsentState = $pdo->prepare('SELECT whatsapp_opt_in_at FROM guests WHERE tenant_id = :tenant_id AND id = :id');
$repositoryConsentState->execute([':tenant_id' => $repositoryTenantId, ':id' => $repositoryGuestId]);
$consentTimestamp = $repositoryConsentState->fetchColumn();
expect($consentTimestamp === false || $consentTimestamp === null, 'repository consent transition clears the current opt-in timestamp');
$repositoryConsentAudit = $pdo->prepare('SELECT COUNT(*) FROM guest_consent_events WHERE tenant_id = :tenant_id AND guest_id = :guest AND action = "opt_out" AND source = "repository_contract_test"');
$repositoryConsentAudit->execute([':tenant_id' => $repositoryTenantId, ':guest' => $repositoryGuestId]);
expect((int) $repositoryConsentAudit->fetchColumn() === 1, 'repository consent transition appends its audit event');
$repository->recordWhatsAppConsent($repositoryTenantId, $repositoryGuestId, $repositoryConversationId, $repositoryPhone, 'opt_in', 'repository_contract_test');
$repositoryConsentState->execute([':tenant_id' => $repositoryTenantId, ':id' => $repositoryGuestId]);
expect($repositoryConsentState->fetchColumn() !== false && $repositoryConsentState->fetchColumn() !== null, 'repository consent transition can restore opt-in');

$rateBucket = 'integration-contract:' . uuid_v4();
expect($repository->consumeRateLimit('00000000-0000-4000-8000-000000000001', $rateBucket, 2, 60), 'rate-limit repository admits the first request');
expect($repository->consumeRateLimit('00000000-0000-4000-8000-000000000001', $rateBucket, 2, 60), 'rate-limit repository admits requests up to the configured maximum');
expect(!$repository->consumeRateLimit('00000000-0000-4000-8000-000000000001', $rateBucket, 2, 60), 'rate-limit repository rejects requests above the configured maximum');

$webhookHash = hash('sha256', 'repository-webhook:' . uuid_v4());
$webhookEncrypted = [
    'ciphertext' => random_bytes(48),
    'nonce' => random_bytes(12),
    'tag' => random_bytes(16),
];
$webhookQueued = $repository->enqueueWebhookInbox($defaultTenantId, $webhookHash, $webhookEncrypted);
expect($webhookQueued['inserted'] && $webhookQueued['status'] === 'pending', 'webhook repository persists a new encrypted inbox row');
$webhookReplay = $repository->enqueueWebhookInbox($defaultTenantId, $webhookHash, $webhookEncrypted);
expect(!$webhookReplay['inserted'] && $webhookReplay['id'] === $webhookQueued['id'], 'webhook repository deduplicates an identical payload hash');
$webhookLease = uuid_v4();
$webhookJob = $repository->claimWebhookInbox($webhookLease, 8);
expect(is_array($webhookJob) && (int) $webhookJob['id'] === $webhookQueued['id'] && (int) $webhookJob['attempts'] === 1 && $webhookJob['tenant_id'] === $defaultTenantId, 'webhook repository atomically leases the oldest due inbox row with tenant ownership');
$repository->failWebhookInbox($defaultTenantId, $webhookQueued['id'], $webhookLease, true, 0, 'contract retry');
$webhookRetryState = $pdo->prepare('SELECT status, attempts FROM webhook_inbox WHERE id = :id');
$webhookRetryState->execute([':id' => $webhookQueued['id']]);
$webhookRetryRow = $webhookRetryState->fetch();
expect($webhookRetryRow && $webhookRetryRow['status'] === 'pending' && (int) $webhookRetryRow['attempts'] === 1, 'webhook repository releases a failed lease for retry');
$webhookRetryLease = uuid_v4();
$webhookRetryJob = $repository->claimWebhookInbox($webhookRetryLease, 8);
expect(is_array($webhookRetryJob) && (int) $webhookRetryJob['id'] === $webhookQueued['id'] && (int) $webhookRetryJob['attempts'] === 2, 'webhook repository increments attempts when reclaiming a retry');
$wrongTenantLeaseRejected = false;
try {
    $repository->completeWebhookInbox($otherTenantId, $webhookQueued['id'], $webhookRetryLease);
} catch (RuntimeException) {
    $wrongTenantLeaseRejected = true;
}
expect($wrongTenantLeaseRejected, 'a different tenant cannot complete another tenant webhook lease');
$repository->completeWebhookInbox($defaultTenantId, $webhookQueued['id'], $webhookRetryLease);
$webhookDoneState = $pdo->prepare('SELECT status, processed_at, lock_token FROM webhook_inbox WHERE id = :id');
$webhookDoneState->execute([':id' => $webhookQueued['id']]);
$webhookDoneRow = $webhookDoneState->fetch();
expect($webhookDoneRow && $webhookDoneRow['status'] === 'processed' && $webhookDoneRow['processed_at'] !== null && $webhookDoneRow['lock_token'] === null, 'webhook repository completes only the currently leased row');

fwrite(STDOUT, "\n{$passed} passed; {$failed} failed.\n");
exit($failed === 0 ? 0 : 1);
