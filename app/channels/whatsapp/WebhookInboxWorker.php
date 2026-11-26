<?php
declare(strict_types=1);

/**
 * Durable, replay-safe processing of signature-verified Meta webhook inbox rows.
 *
 * The tenant is read from the persisted inbox row, then every provider phone
 * identity is revalidated against the active Meta connection before processing.
 */
final class WebhookInboxWorker
{
    public function __construct(
        private readonly WebhookProcessingRepository $repository,
        private readonly MetaWebhookProcessor $processor,
        private readonly WebhookPayloadCipher $cipher
    ) {}

    /** @return array{processed:int,retried:int,failed:int,empty:bool} */
    public function process(int $limit = 10, int $maxAttempts = 8): array
    {
        $summary = ['processed' => 0, 'retried' => 0, 'failed' => 0, 'empty' => false];
        $limit = max(1, min(50, $limit));
        $maxAttempts = max(1, $maxAttempts);

        for ($i = 0; $i < $limit; $i++) {
            $lockToken = uuid_v4();
            $job = $this->repository->claimWebhookInbox($lockToken, $maxAttempts);
            if ($job === null) {
                $summary['empty'] = true;
                break;
            }

            $tenantId = (string) ($job['tenant_id'] ?? '');
            $attempt = (int) $job['attempts'];
            try {
                if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $tenantId)) {
                    throw new RuntimeException('Webhook inbox row has no valid tenant ownership.');
                }

                $raw = $this->cipher->decrypt(
                    (string) $job['payload_ciphertext'],
                    (string) $job['payload_nonce'],
                    (string) $job['payload_tag']
                );
                $payload = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
                if (!is_array($payload) || ($payload['object'] ?? '') !== 'whatsapp_business_account') {
                    throw new RuntimeException('Stored webhook payload is invalid.');
                }

                $phoneNumberIds = MetaWebhookTenantResolver::phoneNumberIdsFromPayload($payload);
                foreach ($phoneNumberIds as $phoneNumberId) {
                    $resolvedTenantId = $this->repository->tenantIdForWhatsAppPhoneNumberId($phoneNumberId);
                    if (!is_string($resolvedTenantId) || !hash_equals($tenantId, $resolvedTenantId)) {
                        throw new RuntimeException('Webhook destination is no longer active for its persisted tenant.');
                    }
                }

                $result = $this->processor->process($tenantId, $payload, $raw, $phoneNumberIds);
                $this->repository->completeWebhookInbox($tenantId, (int) $job['id'], $lockToken);
                $summary['processed'] += (int) ($result['processed'] ?? 0);
            } catch (Throwable $error) {
                $retry = $attempt < $maxAttempts;
                $delay = min(3600, 15 * (2 ** min(8, max(0, $attempt - 1))));
                $this->repository->failWebhookInbox(
                    $tenantId,
                    (int) $job['id'],
                    $lockToken,
                    $retry,
                    $delay,
                    $error->getMessage()
                );
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
}
