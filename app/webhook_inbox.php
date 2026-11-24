<?php
declare(strict_types=1);

/**
 * Webhook inbox composition root and backwards-compatible procedural facade.
 *
 * Cryptography, durable queue orchestration and Meta event interpretation live
 * in dedicated services. This file retains the functions used by the HTTP
 * webhook and CLI recovery entry points.
 */
require_once __DIR__ . '/channels/whatsapp/WebhookPayloadCipher.php';
require_once __DIR__ . '/channels/whatsapp/WebhookInboxWorker.php';
require_once __DIR__ . '/channels/whatsapp/MetaWebhookEndpoint.php';

function webhook_payload_cipher(): WebhookPayloadCipher
{
    return new WebhookPayloadCipher((string) app_config('app.app_key'));
}

/** Backwards-compatible key accessor for operational diagnostics. */
function webhook_payload_key(): string
{
    $secret = (string) app_config('app.app_key');
    if (strlen($secret) < 32) {
        throw new RuntimeException('A 32-character app.app_key is required for webhook encryption.');
    }
    return hash('sha256', 'dimdian-webhook-inbox:' . $secret, true);
}

/** @return array{ciphertext:string,nonce:string,tag:string} */
function encrypt_webhook_payload(string $raw): array
{
    return webhook_payload_cipher()->encrypt($raw);
}

function decrypt_webhook_payload(string $cipher, string $nonce, string $tag): string
{
    return webhook_payload_cipher()->decrypt($cipher, $nonce, $tag);
}

/** @return array{id:int,inserted:bool,status:string} */
function enqueue_meta_webhook(WebhookInboxRepository $repository, string $rawBody): array
{
    $encrypted = webhook_payload_cipher()->encrypt($rawBody);
    return $repository->enqueueWebhookInbox(hash('sha256', $rawBody), $encrypted);
}

/**
 * Process a due inbox batch without coupling the worker to PDO or HTTP routing.
 *
 * The legacy PDO parameter remains accepted for existing cron/deployment
 * scripts. Active connection lookup now goes through WebhookProcessingRepository.
 *
 * @return array{processed:int,retried:int,failed:int,empty:bool}
 */
function process_webhook_inbox(PDO $pdo, WebhookProcessingRepository $repository, int $limit = 10, int $maxAttempts = 8): array
{
    return process_webhook_inbox_with_repository($repository, $limit, $maxAttempts);
}

/** @return WebhookInboxWorker */
function webhook_inbox_worker(
    WebhookProcessingRepository $repository,
    ?WebhookPayloadCipher $cipher = null
): WebhookInboxWorker {
    $resolver = static function () use ($repository): string {
        return $repository->activeWhatsAppPhoneNumberId()
            ?? (string) app_config('whatsapp.phone_number_id');
    };

    return new WebhookInboxWorker(
        $repository,
        new MetaWebhookProcessor($repository),
        $cipher ?? webhook_payload_cipher(),
        $resolver
    );
}

function process_webhook_inbox_with_repository(WebhookProcessingRepository $repository, int $limit = 10, int $maxAttempts = 8): array
{
    return webhook_inbox_worker($repository)->process($limit, $maxAttempts);
}
