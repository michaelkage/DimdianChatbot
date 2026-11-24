<?php
declare(strict_types=1);

/**
 * MySQL/MariaDB durable WhatsApp outbox adapter.
 *
 * Claims and state transitions are lease-token fenced. Provider HTTP calls
 * remain outside repository transactions in WhatsAppOutboxWorker.
 */
final class PdoWhatsAppOutboxRepository implements WhatsAppOutboxRepository
{
    private PDO $pdo;
    private ProviderEventRepository $providerEvents;

    public function __construct(PDO $pdo, ProviderEventRepository $providerEvents)
    {
        $this->pdo = $pdo;
        $this->providerEvents = $providerEvents;
    }

    public function enqueueWhatsAppOutbox(string $messageId, string $recipient, string $text, string $outboxId): void
    {
        $insert = $this->pdo->prepare(
            'INSERT INTO message_outbox
                (id, message_id, recipient, payload_text, status, attempts, available_at, created_at, updated_at)
             VALUES
                (:id, :message_id, :recipient, :payload, "pending", 0, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );
        $insert->execute([
            ':id' => $outboxId,
            ':message_id' => $messageId,
            ':recipient' => $recipient,
            ':payload' => $text,
        ]);
    }

    public function claimWhatsAppOutbox(string $lockToken, int $maxAttempts = 6, int $leaseSeconds = 300): array
    {
        return $this->transaction(function () use ($lockToken, $maxAttempts, $leaseSeconds): array {
            $maxAttempts = max(1, $maxAttempts);
            $leaseSeconds = max(1, $leaseSeconds);
            $exhausted = $this->pdo->prepare(
                'SELECT id, message_id
                 FROM message_outbox
                 WHERE status = "processing"
                   AND locked_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL :lease_seconds SECOND)
                   AND attempts >= :max_attempts
                 FOR UPDATE'
            );
            $exhausted->execute([':lease_seconds' => $leaseSeconds, ':max_attempts' => $maxAttempts]);
            $expiredJobs = $exhausted->fetchAll();
            foreach ($expiredJobs as $expiredJob) {
                $failJob = $this->pdo->prepare(
                    'UPDATE message_outbox
                     SET status = "failed", locked_at = NULL, lock_token = NULL,
                         last_error = "Worker lease expired after maximum attempts.",
                         updated_at = UTC_TIMESTAMP()
                     WHERE id = :id AND status = "processing"'
                );
                $failJob->execute([':id' => $expiredJob['id']]);
                $failMessage = $this->pdo->prepare(
                    'UPDATE messages SET delivery_status = "failed" WHERE id = :id'
                );
                $failMessage->execute([':id' => $expiredJob['message_id']]);
            }

            $query = $this->pdo->prepare(
                'SELECT id, message_id, recipient, payload_text, attempts
                 FROM message_outbox
                 WHERE (status = "pending" AND available_at <= UTC_TIMESTAMP())
                    OR (status = "processing"
                        AND locked_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL :lease_seconds SECOND)
                        AND attempts < :max_attempts)
                 ORDER BY available_at ASC, created_at ASC
                 LIMIT 1 FOR UPDATE'
            );
            $query->execute([':lease_seconds' => $leaseSeconds, ':max_attempts' => $maxAttempts]);
            $job = $query->fetch();
            if (!is_array($job)) {
                return ['job' => null, 'exhausted' => count($expiredJobs)];
            }

            $attempt = (int) $job['attempts'] + 1;
            $claim = $this->pdo->prepare(
                'UPDATE message_outbox
                 SET status = "processing", attempts = :attempt, locked_at = UTC_TIMESTAMP(),
                     lock_token = :lock_token, updated_at = UTC_TIMESTAMP()
                 WHERE id = :id'
            );
            $claim->execute([':attempt' => $attempt, ':lock_token' => $lockToken, ':id' => $job['id']]);
            $job['attempts'] = $attempt;
            return ['job' => $job, 'exhausted' => count($expiredJobs)];
        });
    }

    public function hasWhatsAppConsent(string $messageId): bool
    {
        $query = $this->pdo->prepare(
            'SELECT g.whatsapp_opt_in_at
             FROM messages m
             INNER JOIN conversations c ON c.id = m.conversation_id
             INNER JOIN guests g ON g.id = c.guest_id
             WHERE m.id = :message_id LIMIT 1'
        );
        $query->execute([':message_id' => $messageId]);
        $consent = $query->fetchColumn();
        return $consent !== false && $consent !== null;
    }

    public function cancelWhatsAppOutbox(string $id, string $lockToken, string $messageId): bool
    {
        return $this->transaction(function () use ($id, $lockToken, $messageId): bool {
            $cancel = $this->pdo->prepare(
                'UPDATE message_outbox
                 SET status = "failed", locked_at = NULL, lock_token = NULL,
                     last_error = "Cancelled after WhatsApp consent withdrawal.", updated_at = UTC_TIMESTAMP()
                 WHERE id = :id AND status = "processing" AND lock_token = :lock_token'
            );
            $cancel->execute([':id' => $id, ':lock_token' => $lockToken]);
            if ($cancel->rowCount() !== 1) return false;
            $message = $this->pdo->prepare(
                'UPDATE messages SET delivery_status = "web_only"
                 WHERE id = :message_id AND delivery_status = "queued"'
            );
            $message->execute([':message_id' => $messageId]);
            return true;
        });
    }

    public function completeWhatsAppOutbox(string $id, string $lockToken, string $messageId, string $providerMessageId): bool
    {
        return $this->transaction(function () use ($id, $lockToken, $messageId, $providerMessageId): bool {
            $complete = $this->pdo->prepare(
                'UPDATE message_outbox
                 SET status = "sent", provider_message_id = :provider_id, locked_at = NULL,
                     lock_token = NULL, last_error = NULL, updated_at = UTC_TIMESTAMP()
                 WHERE id = :id AND status = "processing" AND lock_token = :lock_token'
            );
            $complete->execute([':provider_id' => $providerMessageId, ':id' => $id, ':lock_token' => $lockToken]);
            if ($complete->rowCount() !== 1) return false;

            $message = $this->pdo->prepare(
                'UPDATE messages SET provider_message_id = :provider_id, delivery_status = "sent"
                 WHERE id = :message_id'
            );
            $message->execute([':provider_id' => $providerMessageId, ':message_id' => $messageId]);
            $this->providerEvents->reconcileProviderMessageStatus($providerMessageId);
            return true;
        });
    }

    public function failWhatsAppOutbox(string $id, string $lockToken, string $messageId, bool $retry, int $delaySeconds, string $error): bool
    {
        return $this->transaction(function () use ($id, $lockToken, $messageId, $retry, $delaySeconds, $error): bool {
            $update = $this->pdo->prepare(
                'UPDATE message_outbox
                 SET status = :status, available_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL :delay SECOND),
                     locked_at = NULL, lock_token = NULL, last_error = :last_error, updated_at = UTC_TIMESTAMP()
                 WHERE id = :id AND status = "processing" AND lock_token = :lock_token'
            );
            $update->execute([
                ':status' => $retry ? 'pending' : 'failed',
                ':delay' => max(0, $delaySeconds),
                ':last_error' => mb_substr_safe($error, 0, 900),
                ':id' => $id,
                ':lock_token' => $lockToken,
            ]);
            if ($update->rowCount() !== 1) return false;
            if (!$retry) {
                $failed = $this->pdo->prepare(
                    'UPDATE messages SET delivery_status = "failed" WHERE id = :message_id'
                );
                $failed->execute([':message_id' => $messageId]);
            }
            return true;
        });
    }

    private function transaction(callable $operation): mixed
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $result = $operation();
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
            return $result;
        } catch (Throwable $error) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }
}
