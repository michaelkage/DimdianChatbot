<?php
declare(strict_types=1);

/**
 * Webhook inbox application service.
 *
 * Encryption and Meta event interpretation remain outside the storage adapter;
 * durable inbox SQL and lease transitions are delegated to ChatRepository.
 */
function webhook_payload_key(): string
{
    $secret = (string) app_config('app.app_key');
    if (strlen($secret) < 32) {
        throw new RuntimeException('A 32-character app.app_key is required for webhook encryption.');
    }
    return hash('sha256', 'dimdian-webhook-inbox:' . $secret, true);
}

function encrypt_webhook_payload(string $raw): array
{
    if (!function_exists('openssl_encrypt')) {
        throw new RuntimeException('OpenSSL is required for encrypted webhook storage.');
    }
    $nonce = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($raw, 'aes-256-gcm', webhook_payload_key(), OPENSSL_RAW_DATA, $nonce, $tag, 'dimdian-webhook-v1', 16);
    if (!is_string($cipher) || strlen($tag) !== 16) {
        throw new RuntimeException('Webhook payload encryption failed.');
    }
    return ['ciphertext' => $cipher, 'nonce' => $nonce, 'tag' => $tag];
}

function decrypt_webhook_payload(string $cipher, string $nonce, string $tag): string
{
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', webhook_payload_key(), OPENSSL_RAW_DATA, $nonce, $tag, 'dimdian-webhook-v1');
    if (!is_string($plain)) {
        throw new RuntimeException('Webhook payload authentication failed.');
    }
    return $plain;
}

/** @return array{id:int,inserted:bool,status:string} */
function enqueue_meta_webhook(ChatRepository $repository, string $rawBody): array
{
    $encrypted = encrypt_webhook_payload($rawBody);
    return $repository->enqueueWebhookInbox(hash('sha256', $rawBody), $encrypted);
}

/**
 * Drain a small batch. Inbox lifecycle is repository-backed; processing of the
 * WhatsApp event's domain effects remains in the existing PDO service for now.
 */
function process_webhook_inbox(PDO $pdo, ChatRepository $repository, int $limit = 10, int $maxAttempts = 8): array
{
    $summary = ['processed' => 0, 'retried' => 0, 'failed' => 0, 'empty' => false];
    $limit = max(1, min(50, $limit));
    $maxAttempts = max(1, $maxAttempts);

    for ($i = 0; $i < $limit; $i++) {
        $token = uuid_v4();
        $job = $repository->claimWebhookInbox($token, $maxAttempts);
        if ($job === null) {
            $summary['empty'] = true;
            break;
        }
        $attempt = (int) $job['attempts'];

        try {
            $raw = decrypt_webhook_payload((string) $job['payload_ciphertext'], (string) $job['payload_nonce'], (string) $job['payload_tag']);
            $payload = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($payload) || ($payload['object'] ?? '') !== 'whatsapp_business_account') {
                throw new RuntimeException('Stored webhook payload is invalid.');
            }

            $pdo->beginTransaction();
            $phone = function_exists('meta_active_phone_number_id')
                ? meta_active_phone_number_id($pdo)
                : (string) app_config('whatsapp.phone_number_id');
            $result = process_meta_webhook($pdo, $payload, $raw, $phone);
            $repository->completeWebhookInbox((int) $job['id'], $token);
            $pdo->commit();
            $summary['processed'] += (int) ($result['processed'] ?? 0);
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $retry = $attempt < $maxAttempts;
            $delay = min(3600, 15 * (2 ** min(8, max(0, $attempt - 1))));
            $repository->failWebhookInbox((int) $job['id'], $token, $retry, $delay, $error->getMessage());
            $summary[$retry ? 'retried' : 'failed']++;
            app_log_event('webhook.inbox.processing_failed', [
                'job_id' => (int) $job['id'],
                'attempt' => $attempt,
                'exception_class' => get_class($error),
            ]);
        }
    }
    return $summary;
}
