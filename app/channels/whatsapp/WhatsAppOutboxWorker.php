<?php
declare(strict_types=1);

/**
 * Coordinates durable WhatsApp outbox jobs.
 *
 * Persistence and lease fencing belong to ChatRepository. Provider I/O is
 * deliberately performed after a claim transaction has committed.
 */
final class WhatsAppOutboxWorker
{
    public function __construct(
        private readonly ChatRepository $repository,
        private readonly WhatsAppTextTransport $transport
    ) {}

    /** @return array{sent:int,retried:int,failed:int} */
    public function process(int $batchSize = 10, int $maxAttempts = 6): array
    {
        $summary = ['sent' => 0, 'retried' => 0, 'failed' => 0];
        $batchSize = max(1, min(50, $batchSize));
        $maxAttempts = max(1, $maxAttempts);

        for ($i = 0; $i < $batchSize; $i++) {
            $lockToken = uuid_v4();
            $claim = $this->repository->claimWhatsAppOutbox($lockToken, $maxAttempts, 300);
            $summary['failed'] += $claim['exhausted'];
            $job = $claim['job'];
            if (!is_array($job)) {
                break;
            }

            $attempt = (int)$job['attempts'];
            try {
                // Consent may have been withdrawn after this worker claimed the job.
                if (!$this->repository->hasWhatsAppConsent((string)$job['message_id'])) {
                    $this->repository->cancelWhatsAppOutbox(
                        (string)$job['id'],
                        $lockToken,
                        (string)$job['message_id']
                    );
                    continue;
                }

                // Never hold a database transaction open during provider HTTP I/O.
                $result = $this->transport->sendTemplate(
                    (string)$job['recipient'],
                    (string)$job['payload_text']
                );

                if ($this->repository->completeWhatsAppOutbox(
                    (string)$job['id'],
                    $lockToken,
                    (string)$job['message_id'],
                    (string)$result['provider_message_id']
                )) {
                    $summary['sent']++;
                }
            } catch (Throwable $error) {
                $retryable = !($error instanceof WhatsAppTransportException) || $error->retryable;
                $shouldRetry = $retryable && $attempt < $maxAttempts;
                $messageText = mb_substr_safe($error->getMessage(), 0, 900);

                $recorded = $this->repository->failWhatsAppOutbox(
                    (string)$job['id'],
                    $lockToken,
                    (string)$job['message_id'],
                    $shouldRetry,
                    whatsapp_retry_delay_seconds($attempt),
                    $messageText
                );

                if ($recorded) {
                    if ($shouldRetry) {
                        $summary['retried']++;
                    } else {
                        $summary['failed']++;
                    }
                    app_log_event('outbox.attempt_failed', [
                        'job_id' => (string)$job['id'],
                        'attempt' => $attempt,
                        'result' => $shouldRetry ? 'retry' : 'failed',
                    ]);
                }
            }
        }

        return $summary;
    }
}
