<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/whatsapp.php';
require_once dirname(__DIR__) . '/app/bootstrap.php';

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
[$verifyStatus, $verifyBody] = request_api('GET', '/api/webhook.php?hub.mode=subscribe&hub.verify_token=test-webhook-token&hub.challenge=challenge-123');
expect($verifyStatus === 200 && $verifyBody === null, 'Meta webhook verification returns a plain-text challenge');
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
            'value' => ['messages' => [[
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
            'value' => ['statuses' => [[
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
$pdo->prepare(
    'INSERT INTO messages (id, conversation_id, sender_type, body, provider_message_id, delivery_status, sent_at, created_at)
     VALUES (:id, :conversation_id, "visitor", "status race", :provider_id, "sent", UTC_TIMESTAMP(), UTC_TIMESTAMP())'
)->execute([
    ':id' => 'e0000000-0000-4000-8000-000000000001',
    ':conversation_id' => $conversationId,
    ':provider_id' => 'wamid.integration.early-status',
]);
expect(apply_provider_message_status($pdo, 'wamid.integration.early-status'), 'early status is reconciled after the provider message ID is attached');
$statusCheck = $pdo->query("SELECT delivery_status FROM messages WHERE provider_message_id = 'wamid.integration.early-status'")->fetchColumn();
expect($statusCheck === 'delivered', 'reconciled early status updates the message row');
$pdo->prepare(
    'INSERT IGNORE INTO message_status_events (provider_message_id, status, event_timestamp, created_at)
     VALUES ("wamid.integration.early-status", "failed", 1739321027, UTC_TIMESTAMP())'
)->execute();
apply_provider_message_status($pdo, 'wamid.integration.early-status');
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
apply_provider_message_status($pdo, 'wamid.integration.sent-then-failed');
$failedAfterSent = $pdo->query("SELECT delivery_status FROM messages WHERE id = '{$sentThenFailedId}'")->fetchColumn();
expect($failedAfterSent === 'failed', 'failure status supersedes sent when delivery does not complete');

$successMessageId = 'e0000000-0000-4000-8000-000000000002';
$pdo->prepare(
    'INSERT INTO messages (id, conversation_id, sender_type, body, delivery_status, sent_at, created_at)
     VALUES (:id, :conversation_id, "visitor", "template success", "queued", UTC_TIMESTAMP(), UTC_TIMESTAMP())'
)->execute([':id' => $successMessageId, ':conversation_id' => $conversationId]);
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

fwrite(STDOUT, "\n{$passed} passed; {$failed} failed.\n");
exit($failed === 0 ? 0 : 1);
