<?php
declare(strict_types=1);

/**
 * Durable, replay-safe processing of signature-verified Meta webhook inbox rows.
 *
 * Each provider event is committed by MetaWebhookProcessor through its
 * ProviderEventRepository capability. If a process dies midway through a multi-event payload, a retry
 * safely skips already-recorded events and resumes the remaining events.
 */
final class WebhookInboxWorker
{
    /** @var Closure():array{tenant_id:string,phone_number_id:string} */
    private Closure $phoneNumberIdResolver;

    public function __construct(
        private readonly WebhookInboxRepository $repository,
        private readonly MetaWebhookProcessor $processor,
        private readonly WebhookPayloadCipher $cipher,
        Closure $phoneNumberIdResolver
    ) {
        $this->phoneNumberIdResolver = $phoneNumberIdResolver;
    }

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

            $attempt = (int) $job['attempts'];
            try {
                $raw = $this->cipher->decrypt(
                    (string) $job['payload_ciphertext'],
                    (string) $job['payload_nonce'],
                    (string) $job['payload_tag']
                );
                $payload = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
                if (!is_array($payload) || ($payload['object'] ?? '') !== 'whatsapp_business_account') {
                    throw new RuntimeException('Stored webhook payload is invalid.');
                }

                $routing = ($this->phoneNumberIdResolver)();
                if (!is_array($routing)
                    || !is_string($routing['tenant_id'] ?? null)
                    || !is_string($routing['phone_number_id'] ?? null)
                    || $routing['tenant_id'] === ''
                    || $routing['phone_number_id'] === '') {
                    throw new RuntimeException('Active Meta tenant and phone-number configuration are invalid.');
                }

                $result = $this->processor->process($routing['tenant_id'], $payload, $raw, $routing['phone_number_id']);
                $this->repository->completeWebhookInbox((int) $job['id'], $lockToken);
                $summary['processed'] += (int) ($result['processed'] ?? 0);
            } catch (Throwable $error) {
                $retry = $attempt < $maxAttempts;
                $delay = min(3600, 15 * (2 ** min(8, max(0, $attempt - 1))));
                $this->repository->failWebhookInbox(
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
