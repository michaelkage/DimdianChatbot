<?php
declare(strict_types=1);

/**
 * WhatsApp transport boundary, Meta Cloud API adapter, webhook verification,
 * webhook event ingestion, and durable outbox worker.
 */

interface WhatsAppTextTransport
{
    /** @return array{provider_message_id:string} */
    public function sendTemplate(string $recipient, string $bodyParameter): array;
}

final class WhatsAppTransportException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable)
    {
        parent::__construct($message);
    }
}

final class MetaCloudApiTransport implements WhatsAppTextTransport
{
    public function __construct(private readonly array $config) {}

    public function sendTemplate(string $recipient, string $bodyParameter): array
    {
        $version = (string)($this->config['graph_api_version'] ?? '');
        $phoneNumberId = (string)($this->config['phone_number_id'] ?? '');
        $accessToken = (string)($this->config['access_token'] ?? '');

        if (!preg_match('/^v\d+\.\d+$/', $version) || $phoneNumberId === '' || $accessToken === '') {
            throw new WhatsAppTransportException('WhatsApp transport configuration is incomplete.', false);
        }
        $templateName = (string)($this->config['template_name'] ?? '');
        $templateLanguage = (string)($this->config['template_language'] ?? 'en');
        if ($recipient === '' || $bodyParameter === '' || $templateName === '') {
            throw new WhatsAppTransportException('WhatsApp recipient and message are required.', false);
        }

        $url = 'https://graph.facebook.com/' . $version . '/' . rawurlencode($phoneNumberId) . '/messages';
        $payload = json_encode([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $recipient,
            'type' => 'template',
            'template' => [
                'name' => $templateName,
                'language' => ['code' => $templateLanguage],
                'components' => [[
                    'type' => 'body',
                    'parameters' => [['type' => 'text', 'text' => $bodyParameter]],
                ]],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'timeout' => 15,
            'ignore_errors' => true,
            'header' => "Authorization: Bearer {$accessToken}\r\nContent-Type: application/json\r\nAccept: application/json\r\n",
            'content' => $payload,
        ]]);

        $response = @file_get_contents($url, false, $context);
        $statusCode = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $match)) {
                $statusCode = (int)$match[1];
            }
        }

        if ($response === false) {
            throw new WhatsAppTransportException('WhatsApp API connection failed.', true);
        }

        try {
            $decoded = json_decode($response, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new WhatsAppTransportException('WhatsApp API returned an invalid response.', $statusCode === 0 || $statusCode >= 500);
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            $error = is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
            $code = (int)($error['code'] ?? 0);
            $message = (string)($error['message'] ?? 'WhatsApp API rejected the message.');
            $retryable = $statusCode === 429 || $statusCode >= 500 || in_array($code, [130429, 131056], true);
            throw new WhatsAppTransportException($message, $retryable);
        }

        $providerId = $decoded['messages'][0]['id'] ?? null;
        if (!is_string($providerId) || $providerId === '') {
            throw new WhatsAppTransportException('WhatsApp API accepted no identifiable message.', true);
        }

        return ['provider_message_id' => $providerId];
    }
}

function verify_meta_webhook_signature(string $rawBody, ?string $signatureHeader, string $appSecret): bool
{
    if ($appSecret === '' || $signatureHeader === null || !preg_match('/^sha256=([a-f0-9]{64})$/i', trim($signatureHeader), $match)) {
        return false;
    }
    return hash_equals(hash_hmac('sha256', $rawBody, $appSecret), strtolower($match[1]));
}

function normalize_whatsapp_number(string $number): string
{
    $number = trim($number);
    if (str_starts_with($number, '00')) {
        $number = substr($number, 2);
    }
    return preg_replace('/\D+/', '', $number) ?? '';
}

function webhook_event_insert(PDO $pdo, string $tenantId, string $eventId, string $eventType, string $payloadHash): bool
{
    $insert = $pdo->prepare(
        'INSERT IGNORE INTO webhook_events
            (tenant_id, provider_event_id, event_type, payload_sha256, received_at, processing_status)
         VALUES (:tenant_id, :event_id, :event_type, :payload_hash, UTC_TIMESTAMP(), "received")'
    );
    $insert->execute([
        ':tenant_id' => $tenantId,
        ':event_id' => substr($eventId, 0, 191),
        ':event_type' => substr($eventType, 0, 100),
        ':payload_hash' => $payloadHash,
    ]);
    return $insert->rowCount() === 1;
}

function process_meta_webhook(PDO $pdo, array $payload, string $rawBody, string $tenantId, string $expectedPhoneNumberId = ''): array
{
    $processed = 0;
    $ignored = 0;
    $payloadHash = hash('sha256', $rawBody);

    foreach (($payload['entry'] ?? []) as $entry) {
        if (!is_array($entry)) continue;
        foreach (($entry['changes'] ?? []) as $change) {
            if (!is_array($change)) continue;
            $field = (string)($change['field'] ?? '');
            $value = is_array($change['value'] ?? null) ? $change['value'] : [];
            $receivedPhoneNumberId = (string)($value['metadata']['phone_number_id'] ?? '');
            if ($expectedPhoneNumberId !== '' && !hash_equals($expectedPhoneNumberId, $receivedPhoneNumberId)) {
                $ignored++;
                continue;
            }

            // Only Coexistence app-echo events can mirror staff replies typed in WhatsApp Business. We accept text echoes and match the echo recipient to the visitor's opted-in number; unsupported media is deliberately ignored.
            if ($field === 'smb_message_echoes') {
                foreach (($value['message_echoes'] ?? []) as $echo) {
                    if (!is_array($echo)) continue;
                    $wamid = (string)($echo['id'] ?? '');
                    if ($wamid === '') { $ignored++; continue; }
                    $eventId = 'echo:' . $wamid;
                    if (!webhook_event_insert($pdo, $tenantId, $eventId, 'smb_message_echoes', $payloadHash)) continue;

                    $body = (string)($echo['text']['body'] ?? '');
                    $recipient = normalize_whatsapp_number((string)($echo['to'] ?? ''));
                    if (($echo['type'] ?? '') !== 'text' || $body === '' || $recipient === '') {
                        mark_webhook_event($pdo, $tenantId, $eventId, 'ignored');
                        $ignored++;
                        continue;
                    }

                    $conversationQuery = $pdo->prepare(
                        'SELECT c.id
                         FROM guests g
                         INNER JOIN conversations c ON c.guest_id = g.id
                         WHERE g.tenant_id = :guest_tenant_id AND c.tenant_id = :conversation_tenant_id AND g.phone_normalized = :phone AND c.status = "open"
                         ORDER BY c.updated_at DESC
                         LIMIT 1'
                    );
                    $conversationQuery->execute([':guest_tenant_id' => $tenantId, ':conversation_tenant_id' => $tenantId, ':phone' => $recipient]);
                    $conversationId = $conversationQuery->fetchColumn();
                    if (!is_string($conversationId)) {
                        mark_webhook_event($pdo, $tenantId, $eventId, 'ignored');
                        $ignored++;
                        continue;
                    }

                    $sentAt = webhook_timestamp((string)($echo['timestamp'] ?? ''));
                    $insertMessage = $pdo->prepare(
                        'INSERT IGNORE INTO messages
                            (id, tenant_id, conversation_id, sender_type, body, provider_message_id, delivery_status, sent_at, created_at)
                         VALUES
                            (:id, :tenant_id, :conversation_id, "business", :body, :provider_id, "sent", :sent_at, UTC_TIMESTAMP())'
                    );
                    $insertMessage->execute([
                        ':id' => uuid_v4(),
                        ':tenant_id' => $tenantId,
                        ':conversation_id' => $conversationId,
                        ':body' => $body,
                        ':provider_id' => $wamid,
                        ':sent_at' => $sentAt,
                    ]);
                    if ($insertMessage->rowCount() === 1) {
                        $update = $pdo->prepare('UPDATE conversations SET last_message_at = :sent_at, updated_at = UTC_TIMESTAMP() WHERE tenant_id = :tenant_id AND id = :id');
                        $update->execute([':sent_at' => $sentAt, ':tenant_id' => $tenantId, ':id' => $conversationId]);
                        mark_webhook_event($pdo, $tenantId, $eventId, 'processed');
                        $processed++;
                    } else {
                        mark_webhook_event($pdo, $tenantId, $eventId, 'ignored');
                        $ignored++;
                    }
                }
            } elseif ($field === 'messages') {
                foreach (($value['messages'] ?? []) as $incoming) {
                    if (!is_array($incoming)) continue;
                    $wamid = (string)($incoming['id'] ?? '');
                    $sender = normalize_whatsapp_number((string)($incoming['from'] ?? ''));
                    $body = (string)($incoming['text']['body'] ?? '');
                    if ($wamid === '') { $ignored++; continue; }
                    $eventId = 'inbound:' . $wamid;
                    if (!webhook_event_insert($pdo, $tenantId, $eventId, 'inbound_message', $payloadHash)) continue;

                    if (($incoming['type'] ?? '') !== 'text' || $body === '' || $sender === '') {
                        mark_webhook_event($pdo, $tenantId, $eventId, 'ignored');
                        $ignored++;
                        continue;
                    }

                    $conversationQuery = $pdo->prepare(
                        'SELECT c.id FROM guests g
                         INNER JOIN conversations c ON c.guest_id = g.id
                         WHERE g.phone_normalized = :phone AND c.status = "open"
                         ORDER BY c.updated_at DESC LIMIT 1'
                    );
                    $conversationQuery->execute([':guest_tenant_id' => $tenantId, ':conversation_tenant_id' => $tenantId, ':phone' => $sender]);
                    $conversationId = $conversationQuery->fetchColumn();
                    if (!is_string($conversationId)) {
                        mark_webhook_event($pdo, $tenantId, $eventId, 'ignored');
                        $ignored++;
                        continue;
                    }

                    $sentAt = webhook_timestamp((string)($incoming['timestamp'] ?? ''));
                    $insertMessage = $pdo->prepare(
                        'INSERT IGNORE INTO messages
                            (id, tenant_id, conversation_id, sender_type, body, provider_message_id, delivery_status, sent_at, created_at)
                         VALUES
                            (:id, :tenant_id, :conversation_id, "visitor", :body, :provider_id, "received", :sent_at, UTC_TIMESTAMP())'
                    );
                    $insertMessage->execute([
                        ':id' => uuid_v4(), ':tenant_id' => $tenantId, ':conversation_id' => $conversationId,
                        ':body' => $body, ':provider_id' => $wamid, ':sent_at' => $sentAt,
                    ]);
                    if ($insertMessage->rowCount() === 1) {
                        $update = $pdo->prepare('UPDATE conversations SET last_message_at = :sent_at, updated_at = UTC_TIMESTAMP() WHERE tenant_id = :tenant_id AND id = :id');
                        $update->execute([':sent_at' => $sentAt, ':id' => $conversationId]);
                        mark_webhook_event($pdo, $tenantId, $eventId, 'processed');
                        $processed++;
                    } else {
                        mark_webhook_event($pdo, $tenantId, $eventId, 'ignored');
                        $ignored++;
                    }
                }

                foreach (($value['statuses'] ?? []) as $status) {
                    if (!is_array($status)) continue;
                    $wamid = (string)($status['id'] ?? '');
                    $state = (string)($status['status'] ?? '');
                    $timestamp = (string)($status['timestamp'] ?? '');
                    if ($wamid === '' || !in_array($state, ['sent', 'delivered', 'read', 'failed'], true)) {
                        $ignored++;
                        continue;
                    }
                    $eventId = 'status:' . $wamid . ':' . $state . ':' . $timestamp;
                    if (!webhook_event_insert($pdo, $tenantId, $eventId, 'message_status', $payloadHash)) continue;

                    $storeStatus = $pdo->prepare(
                        'INSERT IGNORE INTO message_status_events
                            (tenant_id, provider_message_id, status, event_timestamp, created_at)
                         VALUES (:tenant_id, :provider_id, :status, :event_timestamp, UTC_TIMESTAMP())'
                    );
                    $storeStatus->execute([
                        ':tenant_id' => $tenantId,
                        ':provider_id' => $wamid, ':status' => $state,
                        ':event_timestamp' => ctype_digit($timestamp) ? (int)$timestamp : 0,
                    ]);
                    $found = apply_provider_message_status($pdo, $wamid, $tenantId);
                    mark_webhook_event($pdo, $tenantId, $eventId, $found ? 'processed' : 'ignored');
                    $found ? $processed++ : $ignored++;
                }
            }
        }
    }

    return ['processed' => $processed, 'ignored' => $ignored];
}


function apply_provider_message_status(PDO $pdo, string $providerMessageId, string $tenantId): bool
{
    $lookup = $pdo->prepare(
        'SELECT status FROM message_status_events
         WHERE tenant_id = :tenant_id AND provider_message_id = :provider_id
         ORDER BY CASE status WHEN "read" THEN 4 WHEN "delivered" THEN 3 WHEN "failed" THEN 2 ELSE 1 END DESC,
                  event_timestamp DESC LIMIT 1'
    );
    $lookup->execute([':tenant_id' => $tenantId, ':provider_id' => $providerMessageId]);
    $status = $lookup->fetchColumn();
    if (!is_string($status)) return false;

    $update = $pdo->prepare('UPDATE messages SET delivery_status = :status WHERE tenant_id = :tenant_id AND provider_message_id = :provider_id');
    $update->execute([':status' => $status, ':tenant_id' => $tenantId, ':provider_id' => $providerMessageId]);
    return $update->rowCount() > 0;
}

function mark_webhook_event(PDO $pdo, string $tenantId, string $eventId, string $status): void
{
    $update = $pdo->prepare(
        'UPDATE webhook_events SET processing_status = :status, processed_at = UTC_TIMESTAMP()
         WHERE tenant_id = :tenant_id AND provider_event_id = :event_id'
    );
    $update->execute([':status' => $status, ':tenant_id' => $tenantId, ':event_id' => substr($eventId, 0, 191)]);
}

function webhook_timestamp(string $timestamp): string
{
    if ($timestamp !== '' && ctype_digit($timestamp) && (int)$timestamp > 0) {
        return gmdate('Y-m-d H:i:s', (int)$timestamp);
    }
    return gmdate('Y-m-d H:i:s');
}

function enqueue_whatsapp_notification(PDO $pdo, string $tenantId, string $messageId, string $recipient, string $text): void
{
    $insert = $pdo->prepare(
        'INSERT INTO message_outbox
            (id, tenant_id, message_id, recipient, payload_text, status, attempts, available_at, created_at, updated_at)
         VALUES
            (:id, :tenant_id, :message_id, :recipient, :payload, "pending", 0, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())'
    );
    $insert->execute([
        ':id' => uuid_v4(),
        ':tenant_id' => $tenantId,
        ':message_id' => $messageId,
        ':recipient' => $recipient,
        ':payload' => $text,
    ]);
}

function process_whatsapp_outbox(PDO $pdo, WhatsAppTextTransport $transport, string $tenantId, int $batchSize = 10, int $maxAttempts = 6): array
{
    $summary = ['sent' => 0, 'retried' => 0, 'failed' => 0];
    $batchSize = max(1, min(50, $batchSize));
    $maxAttempts = max(1, $maxAttempts);

    for ($i = 0; $i < $batchSize; $i++) {
        $lockToken = uuid_v4();
        $pdo->beginTransaction();
        try {
            // A worker that died during its final permitted attempt must not
            // silently create a seventh send when its lease becomes stale.
            $exhausted = $pdo->prepare(
                'SELECT id, tenant_id, message_id
                 FROM message_outbox
                 WHERE tenant_id = :tenant_id AND status = "processing"
                   AND locked_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE)
                   AND attempts >= :max_attempts
                 FOR UPDATE'
            );
            $exhausted->execute([':tenant_id' => $tenantId, ':max_attempts' => $maxAttempts]);
            foreach ($exhausted->fetchAll() as $expiredJob) {
                $failJob = $pdo->prepare(
                    'UPDATE message_outbox
                     SET status = "failed", locked_at = NULL, lock_token = NULL,
                         last_error = "Worker lease expired after maximum attempts.",
                         updated_at = UTC_TIMESTAMP()
                     WHERE tenant_id = :tenant_id AND id = :id AND status = "processing"'
                );
                $failJob->execute([':tenant_id' => $tenantId, ':id' => $expiredJob['id']]);
                $failMessage = $pdo->prepare(
                    'UPDATE messages SET delivery_status = "failed" WHERE tenant_id = :tenant_id AND id = :id'
                );
                $failMessage->execute([':tenant_id' => $expiredJob['tenant_id'], ':id' => $expiredJob['message_id']]);
                $summary['failed']++;
            }

            $query = $pdo->prepare(
                'SELECT id, tenant_id, message_id, recipient, payload_text, attempts
                 FROM message_outbox
                 WHERE tenant_id = :tenant_id AND ((status = "pending" AND available_at <= UTC_TIMESTAMP())
                    OR (status = "processing" AND locked_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE)
                        AND attempts < :max_attempts))
                 ORDER BY available_at ASC, created_at ASC
                 LIMIT 1 FOR UPDATE'
            );
            $query->execute([':tenant_id' => $tenantId, ':max_attempts' => $maxAttempts]);
            $job = $query->fetch();
            if (!$job) {
                $pdo->commit();
                break;
            }
            $attempt = (int)$job['attempts'] + 1;
            $claim = $pdo->prepare(
                'UPDATE message_outbox
                 SET status = "processing", attempts = :attempt, locked_at = UTC_TIMESTAMP(), lock_token = :lock_token, updated_at = UTC_TIMESTAMP()
                 WHERE tenant_id = :tenant_id AND id = :id'
            );
            $claim->execute([':attempt' => $attempt, ':lock_token' => $lockToken, ':tenant_id' => $tenantId, ':id' => $job['id']]);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }

        try {
            $result = $transport->sendTemplate((string)$job['recipient'], (string)$job['payload_text']);
            $pdo->beginTransaction();
            $complete = $pdo->prepare(
                'UPDATE message_outbox
                 SET status = "sent", provider_message_id = :provider_id, locked_at = NULL, lock_token = NULL, last_error = NULL, updated_at = UTC_TIMESTAMP()
                 WHERE tenant_id = :tenant_id AND id = :id AND lock_token = :lock_token'
            );
            $complete->execute([':provider_id' => $result['provider_message_id'], ':tenant_id' => $tenantId, ':id' => $job['id'], ':lock_token' => $lockToken]);
            if ($complete->rowCount() === 1) {
                $message = $pdo->prepare(
                    'UPDATE messages SET provider_message_id = :provider_id, delivery_status = "sent"
                     WHERE tenant_id = :tenant_id AND id = :message_id'
                );
                $message->execute([':provider_id' => $result['provider_message_id'], ':tenant_id' => $job['tenant_id'], ':message_id' => $job['message_id']]);
                apply_provider_message_status($pdo, $result['provider_message_id'], (string)$job['tenant_id']);
                $summary['sent']++;
            }
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $retryable = !($error instanceof WhatsAppTransportException) || $error->retryable;
            $shouldRetry = $retryable && $attempt < $maxAttempts;
            $delay = whatsapp_retry_delay_seconds($attempt);
            $messageText = mb_substr_safe($error->getMessage(), 0, 900);

            $pdo->beginTransaction();
            $update = $pdo->prepare(
                'UPDATE message_outbox
                 SET status = :status, available_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL :delay SECOND),
                     locked_at = NULL, lock_token = NULL, last_error = :last_error, updated_at = UTC_TIMESTAMP()
                 WHERE tenant_id = :tenant_id AND id = :id AND lock_token = :lock_token'
            );
            $update->execute([
                ':status' => $shouldRetry ? 'pending' : 'failed',
                ':delay' => $delay,
                ':last_error' => $messageText,
                ':tenant_id' => $tenantId,
                ':id' => $job['id'],
                ':lock_token' => $lockToken,
            ]);
            if (!$shouldRetry) {
                $failed = $pdo->prepare('UPDATE messages SET delivery_status = "failed" WHERE tenant_id = :tenant_id AND id = :id');
                $failed->execute([':tenant_id' => $job['tenant_id'], ':id' => $job['message_id']]);
                $summary['failed']++;
            } else {
                $summary['retried']++;
            }
            $pdo->commit();
            error_log('[DimdianChatbot] WhatsApp outbox attempt failed: ' . $messageText);
        }
    }

    return $summary;
}

function mb_substr_safe(string $value, int $start, int $length): string
{
    if (function_exists('mb_substr')) return mb_substr($value, $start, $length);
    if (preg_match_all('/./us', $value, $matches) !== false) {
        return implode('', array_slice($matches[0], $start, $length));
    }
    return substr($value, $start, $length);
}

function whatsapp_retry_delay_seconds(int $attempt): int
{
    return min(3600, 15 * (2 ** min(8, max(0, $attempt - 1))));
}
