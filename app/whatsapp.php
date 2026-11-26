<?php
declare(strict_types=1);

/**
 * WhatsApp transport boundary, Meta Cloud API adapter, webhook verification,
 * webhook event ingestion, and durable outbox worker.
 */

require_once __DIR__ . '/channels/whatsapp/MetaCloudApiTransport.php';
require_once __DIR__ . '/channels/whatsapp/WhatsAppOutboxWorker.php';
require_once __DIR__ . '/channels/whatsapp/MetaWebhookProcessor.php';
require_once __DIR__ . '/channels/whatsapp/MetaWebhookSignatureVerifier.php';

function verify_meta_webhook_signature(string $rawBody, ?string $signatureHeader, string $appSecret): bool
{
    return (new MetaWebhookSignatureVerifier())->verify($rawBody, $signatureHeader, $appSecret);
}

function normalize_whatsapp_number(string $number): string
{
    $number = trim($number);
    if (str_starts_with($number, '00')) {
        $number = substr($number, 2);
    }
    return preg_replace('/\D+/', '', $number) ?? '';
}

function process_meta_webhook(ProviderEventRepository $repository, string $tenantId, array $payload, string $rawBody, array $expectedPhoneNumberIds = []): array
{
    return (new MetaWebhookProcessor($repository))->process($tenantId, $payload, $rawBody, $expectedPhoneNumberIds);
}

function webhook_timestamp(string $timestamp): string
{
    if ($timestamp !== '' && ctype_digit($timestamp) && (int)$timestamp > 0) {
        return gmdate('Y-m-d H:i:s', (int)$timestamp);
    }
    return gmdate('Y-m-d H:i:s');
}

function enqueue_whatsapp_notification(PDO $pdo, string $messageId, string $recipient, string $text, ?string $tenantId = null): void
{
    // Legacy callers are pinned to the configured default tenant. New
    // application flows must pass the authenticated tenant explicitly.
    $tenantId = $tenantId ?? (string) (app_config('tenancy.default_tenant_id') ?? '00000000-0000-4000-8000-000000000001');
    chat_repository($pdo)->enqueueWhatsAppOutbox($tenantId, $messageId, $recipient, $text, uuid_v4());
}

function process_whatsapp_outbox(PDO $pdo, WhatsAppTextTransport $transport, int $batchSize = 10, int $maxAttempts = 6): array
{
    return (new WhatsAppOutboxWorker(chat_repository($pdo), $transport))->process($batchSize, $maxAttempts);
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
