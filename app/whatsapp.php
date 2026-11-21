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

function process_meta_webhook(ChatRepository $repository, array $payload, string $rawBody, string $expectedPhoneNumberId = ''): array
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

            if ($field === 'smb_message_echoes') {
                foreach (($value['message_echoes'] ?? []) as $echo) {
                    if (!is_array($echo)) continue;
                    $wamid = (string)($echo['id'] ?? '');
                    if ($wamid === '') { $ignored++; continue; }
                    $body = (string)($echo['text']['body'] ?? '');
                    $recipient = normalize_whatsapp_number((string)($echo['to'] ?? ''));
                    $eventId = 'echo:' . $wamid;
                    if (($echo['type'] ?? '') !== 'text' || $body === '' || $recipient === '') {
                        // Preserve the event ledger for unsupported but validly identified provider events.
                        $result = $repository->recordIgnoredProviderEvent($eventId, 'smb_message_echoes', $payloadHash);
                        if ($result !== 'processed') $ignored++;
                        continue;
                    }
                    $result = $repository->recordProviderMessageEvent(
                        $eventId, 'smb_message_echoes', $payloadHash, $recipient, 'business',
                        $body, $wamid, 'sent', webhook_timestamp((string)($echo['timestamp'] ?? ''))
                    );
                    if ($result === 'processed') $processed++;
                    elseif ($result === 'ignored') $ignored++;
                }
            } elseif ($field === 'messages') {
                foreach (($value['messages'] ?? []) as $incoming) {
                    if (!is_array($incoming)) continue;
                    $wamid = (string)($incoming['id'] ?? '');
                    if ($wamid === '') { $ignored++; continue; }
                    $sender = normalize_whatsapp_number((string)($incoming['from'] ?? ''));
                    $body = (string)($incoming['text']['body'] ?? '');
                    $eventId = 'inbound:' . $wamid;
                    if (($incoming['type'] ?? '') !== 'text' || $body === '' || $sender === '') {
                        $result = $repository->recordIgnoredProviderEvent($eventId, 'inbound_message', $payloadHash);
                        if ($result !== 'processed') $ignored++;
                        continue;
                    }
                    $result = $repository->recordProviderMessageEvent(
                        $eventId, 'inbound_message', $payloadHash, $sender, 'visitor',
                        $body, $wamid, 'received', webhook_timestamp((string)($incoming['timestamp'] ?? ''))
                    );
                    if ($result === 'processed') $processed++;
                    elseif ($result === 'ignored') $ignored++;
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
                    $result = $repository->recordProviderStatusEvent(
                        $eventId, $payloadHash, $wamid, $state,
                        ctype_digit($timestamp) ? (int)$timestamp : 0
                    );
                    if ($result === 'processed') $processed++;
                    elseif ($result === 'ignored') $ignored++;
                }
            }
        }
    }
    return ['processed' => $processed, 'ignored' => $ignored];
}

function webhook_timestamp(string $timestamp): string
{
    if ($timestamp !== '' && ctype_digit($timestamp) && (int)$timestamp > 0) {
        return gmdate('Y-m-d H:i:s', (int)$timestamp);
    }
    return gmdate('Y-m-d H:i:s');
}

function enqueue_whatsapp_notification(PDO $pdo, string $messageId, string $recipient, string $text): void
{
    chat_repository($pdo)->enqueueWhatsAppOutbox($messageId, $recipient, $text, uuid_v4());
}

function process_whatsapp_outbox(PDO $pdo, WhatsAppTextTransport $transport, int $batchSize = 10, int $maxAttempts = 6): array
{
    $summary = ['sent' => 0, 'retried' => 0, 'failed' => 0];
    $batchSize = max(1, min(50, $batchSize));
    $maxAttempts = max(1, $maxAttempts);
    $repository = chat_repository($pdo);

    for ($i = 0; $i < $batchSize; $i++) {
        $lockToken = uuid_v4();
        $claim = $repository->claimWhatsAppOutbox($lockToken, $maxAttempts, 300);
        $summary['failed'] += $claim['exhausted'];
        $job = $claim['job'];
        if (!is_array($job)) break;

        $attempt = (int) $job['attempts'];
        try {
            // Re-check consent immediately before the external send. A withdrawal
            // can invalidate a job after it was claimed by this worker.
            if (!$repository->hasWhatsAppConsent((string) $job['message_id'])) {
                $repository->cancelWhatsAppOutbox((string) $job['id'], $lockToken, (string) $job['message_id']);
                continue;
            }

            // Provider I/O is deliberately outside every repository transaction.
            $result = $transport->sendTemplate((string) $job['recipient'], (string) $job['payload_text']);
            if ($repository->completeWhatsAppOutbox(
                (string) $job['id'],
                $lockToken,
                (string) $job['message_id'],
                (string) $result['provider_message_id']
            )) {
                $summary['sent']++;
            }
        } catch (Throwable $error) {
            $retryable = !($error instanceof WhatsAppTransportException) || $error->retryable;
            $shouldRetry = $retryable && $attempt < $maxAttempts;
            $delay = whatsapp_retry_delay_seconds($attempt);
            $messageText = mb_substr_safe($error->getMessage(), 0, 900);
            $recorded = $repository->failWhatsAppOutbox(
                (int) $job['id'],
                $lockToken,
                (string) $job['message_id'],
                $shouldRetry,
                $delay,
                $messageText
            );
            if ($recorded) {
                if ($shouldRetry) {
                    $summary['retried']++;
                } else {
                    $summary['failed']++;
                }
                app_log_event('outbox.attempt_failed', [
                    'job_id' => (int) $job['id'],
                    'attempt' => $attempt,
                    'result' => $shouldRetry ? 'retry' : 'failed',
                ]);
            }
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
