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

function process_meta_webhook(PDO $pdo, array $payload, string $rawBody, string $tenantId, string $expectedPhoneNumberId = '', string $expectedBusinessAccountId = ''): array
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
            $receivedPhoneNumberId = (string)($value['metadata']['phone_number_id'] ?? $value['phone_number_id'] ?? '');
            if ($expectedPhoneNumberId !== '' && $receivedPhoneNumberId !== '' && !hash_equals($expectedPhoneNumberId, $receivedPhoneNumberId)) {
                $ignored++;
                continue;
            }

            if ($field === 'account_update') {
                $eventName = strtoupper((string)($value['event'] ?? 'UNKNOWN'));
                $accountEventId = 'account_update:' . hash('sha256', $eventName . ':' . $receivedPhoneNumberId . ':' . $expectedBusinessAccountId . ':' . $payloadHash);
                if (!webhook_event_insert($pdo, $tenantId, $accountEventId, 'account_update:' . strtolower($eventName), $payloadHash)) continue;

                $revoked = in_array($eventName, ['PARTNER_REMOVED', 'ACCOUNT_REMOVED', 'ACCOUNT_REVOKED', 'ACCOUNT_OFFBOARDED'], true);
                $disconnected = in_array($eventName, ['PHONE_NUMBER_DISCONNECTED', 'NUMBER_DISCONNECTED'], true);
                $reconnected = in_array($eventName, ['ACCOUNT_RECONNECTED', 'PHONE_NUMBER_CONNECTED'], true);
                if ($revoked || $disconnected || $reconnected) {
                    if ($receivedPhoneNumberId !== '') {
                        $updateConnection = $pdo->prepare(
                            'UPDATE channel_connections
                             SET connection_status = CASE WHEN :reconnected = 1 AND credential_ciphertext IS NOT NULL THEN "connected" WHEN :reconnected = 1 THEN "needs_action" ELSE :status END,
                                 credential_ciphertext = CASE WHEN :clear_token = 1 THEN NULL ELSE credential_ciphertext END,
                                 last_error_code = CASE WHEN :reconnected = 1 THEN NULL ELSE :event_name END,
                                 last_error_message = CASE WHEN :reconnected = 1 THEN NULL ELSE "Meta reported an account or number access change." END,
                                 updated_at = UTC_TIMESTAMP()
                             WHERE tenant_id = :tenant_id AND provider = "meta" AND phone_number_id = :phone_id'
                        );
                        $updateConnection->execute([
                            ':status' => $revoked ? 'revoked' : 'disconnected',
                            ':reconnected' => $reconnected ? 1 : 0,
                            ':clear_token' => $revoked ? 1 : 0,
                            ':event_name' => substr($eventName, 0, 100),
                            ':tenant_id' => $tenantId,
                            ':phone_id' => $receivedPhoneNumberId,
                        ]);
                    } elseif ($expectedBusinessAccountId !== '') {
                        $updateConnection = $pdo->prepare(
                            'UPDATE channel_connections
                             SET connection_status = CASE WHEN :reconnected = 1 AND credential_ciphertext IS NOT NULL THEN "connected" WHEN :reconnected = 1 THEN "needs_action" ELSE :status END,
                                 credential_ciphertext = CASE WHEN :clear_token = 1 THEN NULL ELSE credential_ciphertext END,
                                 last_error_code = CASE WHEN :reconnected = 1 THEN NULL ELSE :event_name END,
                                 last_error_message = CASE WHEN :reconnected = 1 THEN NULL ELSE "Meta reported an account or number access change." END,
                                 updated_at = UTC_TIMESTAMP()
                             WHERE tenant_id = :tenant_id AND provider = "meta" AND (business_account_id = :waba_id OR whatsapp_account_id = :waac_id)'
                        );
                        $updateConnection->execute([
                            ':status' => $revoked ? 'revoked' : 'disconnected',
                            ':reconnected' => $reconnected ? 1 : 0,
                            ':clear_token' => $revoked ? 1 : 0,
                            ':event_name' => substr($eventName, 0, 100),
                            ':tenant_id' => $tenantId,
                            ':waba_id' => $expectedBusinessAccountId,
                            ':waac_id' => $expectedBusinessAccountId,
                        ]);
                    }
                }

                $connectionLookup = $pdo->prepare(
                    'SELECT id FROM channel_connections
                     WHERE tenant_id = :tenant_id AND provider = "meta"
                       AND ((:phone_id <> "" AND phone_number_id = :phone_id_lookup)
                         OR (:waba_id <> "" AND (business_account_id = :waba_lookup OR whatsapp_account_id = :waac_lookup)))
                     LIMIT 1'
                );
                $connectionLookup->execute([
                    ':tenant_id' => $tenantId,
                    ':phone_id' => $receivedPhoneNumberId,
                    ':phone_id_lookup' => $receivedPhoneNumberId,
                    ':waba_id' => $expectedBusinessAccountId,
                    ':waba_lookup' => $expectedBusinessAccountId,
                    ':waac_lookup' => $expectedBusinessAccountId,
                ]);
                $connectionId = $connectionLookup->fetchColumn();
                $audit = $pdo->prepare(
                    'INSERT INTO channel_connection_events (tenant_id, channel_connection_id, event_type, event_sha256, created_at)
                     VALUES (:tenant_id, :connection_id, :event_type, :event_hash, UTC_TIMESTAMP())'
                );
                $audit->execute([
                    ':tenant_id' => $tenantId,
                    ':connection_id' => is_string($connectionId) ? $connectionId : null,
                    ':event_type' => 'meta_account_update:' . strtolower($eventName),
                    ':event_hash' => hash('sha256', $tenantId . ':' . $accountEventId),
                ]);
                mark_webhook_event($pdo, $tenantId, $accountEventId, 'processed');
                $processed++;
            } elseif ($field === 'smb_app_state_sync') {
                $syncEventId = 'smb_app_state_sync:' . hash('sha256', $tenantId . ':' . $receivedPhoneNumberId . ':' . $payloadHash);
                if (!webhook_event_insert($pdo, $tenantId, $syncEventId, 'smb_app_state_sync', $payloadHash)) continue;

                $channelQuery = $pdo->prepare(
                    'SELECT id FROM channel_connections
                     WHERE tenant_id = :tenant_id AND provider = "meta"
                       AND ((:phone_filter <> "" AND phone_number_id = :phone_id)
                         OR (:account_filter <> "" AND (business_account_id = :messaging_id OR whatsapp_account_id = :waac_id)))
                     LIMIT 1'
                );
                $channelQuery->execute([
                    ':tenant_id' => $tenantId,
                    ':phone_filter' => $receivedPhoneNumberId,
                    ':phone_id' => $receivedPhoneNumberId,
                    ':account_filter' => $expectedBusinessAccountId,
                    ':messaging_id' => $expectedBusinessAccountId,
                    ':waac_id' => $expectedBusinessAccountId,
                ]);
                $channelId = $channelQuery->fetchColumn();
                if (!is_string($channelId) || $channelId === '') {
                    mark_webhook_event($pdo, $tenantId, $syncEventId, 'ignored');
                    $ignored++;
                    continue;
                }
                $contacts = $value['state_sync'] ?? $value['contacts'] ?? [];
                $stored = 0;
                if (is_array($contacts)) {
                    foreach ($contacts as $contactEvent) {
                        if (!is_array($contactEvent)) continue;
                        $contact = is_array($contactEvent['contact'] ?? null) ? $contactEvent['contact'] : $contactEvent;
                        $phone = normalize_whatsapp_number((string)($contact['phone_number'] ?? $contact['wa_id'] ?? ''));
                        if ($phone === '') continue;
                        $action = strtolower((string)($contactEvent['action'] ?? $contact['action'] ?? 'add'));
                        $contactStatus = $action === 'remove' ? 'removed' : 'active';
                        $fullName = mb_substr_safe((string)($contact['full_name'] ?? $contact['name'] ?? ''), 0, 191);
                        $firstName = mb_substr_safe((string)($contact['first_name'] ?? ''), 0, 100);
                        $upsertContact = $pdo->prepare(
                            'INSERT INTO whatsapp_contacts
                                (tenant_id, channel_connection_id, phone_normalized, display_name, first_name, status, last_synced_at, created_at, updated_at)
                             VALUES (:tenant_id, :channel_id, :phone, :display_name, :first_name, :status, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())
                             ON DUPLICATE KEY UPDATE display_name = VALUES(display_name), first_name = VALUES(first_name),
                                status = VALUES(status), last_synced_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()'
                        );
                        $upsertContact->execute([
                            ':tenant_id' => $tenantId,
                            ':channel_id' => $channelId,
                            ':phone' => $phone,
                            ':display_name' => $fullName !== '' ? $fullName : null,
                            ':first_name' => $firstName !== '' ? $firstName : null,
                            ':status' => $contactStatus,
                        ]);
                        $stored++;
                    }
                }
                $progress = $value['progress'] ?? ($value['metadata']['progress'] ?? 0);
                $isFinal = !empty($value['is_final']) || (int)$progress >= 100 || strtolower((string)($value['status'] ?? '')) === 'completed';
                if (is_string($channelId) && $channelId !== '') {
                    $syncUpdate = $pdo->prepare(
                        'UPDATE channel_sync_jobs SET status = :status,
                            completed_at = CASE WHEN :is_final = 1 THEN UTC_TIMESTAMP() ELSE completed_at END,
                            updated_at = UTC_TIMESTAMP()
                         WHERE tenant_id = :tenant_id AND channel_connection_id = :channel_id AND sync_type = "smb_app_state_sync"
                           AND status IN ("submitting", "requested", "receiving", "failed")'
                    );
                    $syncUpdate->execute([
                        ':status' => $isFinal ? 'completed' : 'receiving',
                        ':is_final' => $isFinal ? 1 : 0,
                        ':tenant_id' => $tenantId,
                        ':channel_id' => $channelId,
                    ]);
                    chatbridge_meta_audit_event($pdo, $tenantId, $channelId, 'coexistence_contacts_received', (string)$stored . ':' . $payloadHash);
                }
                mark_webhook_event($pdo, $tenantId, $syncEventId, 'processed');
                $processed++;
            } elseif ($field === 'history') {
                $historyEventId = 'history:' . hash('sha256', $tenantId . ':' . $receivedPhoneNumberId . ':' . $payloadHash);
                if (!webhook_event_insert($pdo, $tenantId, $historyEventId, 'history', $payloadHash)) continue;

                $channelQuery = $pdo->prepare(
                    'SELECT id FROM channel_connections
                     WHERE tenant_id = :tenant_id AND provider = "meta"
                       AND ((:phone_filter <> "" AND phone_number_id = :phone_id)
                         OR (:account_filter <> "" AND (business_account_id = :messaging_id OR whatsapp_account_id = :waac_id)))
                     LIMIT 1'
                );
                $channelQuery->execute([
                    ':tenant_id' => $tenantId,
                    ':phone_filter' => $receivedPhoneNumberId,
                    ':phone_id' => $receivedPhoneNumberId,
                    ':account_filter' => $expectedBusinessAccountId,
                    ':messaging_id' => $expectedBusinessAccountId,
                    ':waac_id' => $expectedBusinessAccountId,
                ]);
                $channelId = $channelQuery->fetchColumn();
                $historyJson = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if (is_string($channelId) && $channelId !== '' && is_string($historyJson) && strlen($historyJson) <= 15000000) {
                    $storeHistory = $pdo->prepare(
                        'INSERT IGNORE INTO coexistence_history_payloads
                            (tenant_id, channel_connection_id, payload_sha256, payload_json, received_at)
                         VALUES (:tenant_id, :channel_id, :payload_hash, :payload, UTC_TIMESTAMP())'
                    );
                    $storeHistory->execute([
                        ':tenant_id' => $tenantId,
                        ':channel_id' => $channelId,
                        ':payload_hash' => hash('sha256', $historyJson),
                        ':payload' => $historyJson,
                    ]);
                    $progress = $value['progress'] ?? ($value['metadata']['progress'] ?? 0);
                    $isFinal = !empty($value['is_final']) || (int)$progress >= 100 || strtolower((string)($value['status'] ?? '')) === 'completed';
                    $syncUpdate = $pdo->prepare(
                        'UPDATE channel_sync_jobs SET status = :status,
                            completed_at = CASE WHEN :is_final = 1 THEN UTC_TIMESTAMP() ELSE completed_at END,
                            updated_at = UTC_TIMESTAMP()
                         WHERE tenant_id = :tenant_id AND channel_connection_id = :channel_id AND sync_type = "history"
                           AND status IN ("submitting", "requested", "receiving", "failed")'
                    );
                    $syncUpdate->execute([
                        ':status' => $isFinal ? 'completed' : 'receiving',
                        ':is_final' => $isFinal ? 1 : 0,
                        ':tenant_id' => $tenantId,
                        ':channel_id' => $channelId,
                    ]);
                    chatbridge_meta_audit_event($pdo, $tenantId, $channelId, 'coexistence_history_received', $payloadHash);
                    mark_webhook_event($pdo, $tenantId, $historyEventId, 'processed');
                    $processed++;
                } else {
                    mark_webhook_event($pdo, $tenantId, $historyEventId, 'ignored');
                    $ignored++;
                }
            } elseif ($field === 'smb_message_echoes') {
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
                         WHERE g.tenant_id = :guest_tenant_id AND c.tenant_id = :conversation_tenant_id AND g.phone_normalized = :phone AND c.status = "open"
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
                        $update->execute([':sent_at' => $sentAt, ':tenant_id' => $tenantId, ':id' => $conversationId]);
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
