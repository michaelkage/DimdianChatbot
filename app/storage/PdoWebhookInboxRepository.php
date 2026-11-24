<?php
declare(strict_types=1);

/**
 * MySQL/MariaDB adapter for the durable, encrypted webhook inbox.
 *
 * Provider payload interpretation and event idempotency are deliberately
 * outside this adapter. Each lease transition is atomic and fenced by its
 * current lock token.
 */
final class PdoWebhookInboxRepository implements WebhookInboxRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function enqueueWebhookInbox(string $payloadHash, array $encrypted): array
    {
        return $this->transaction(function () use ($payloadHash, $encrypted): array {
            $insert = $this->pdo->prepare(
                'INSERT IGNORE INTO webhook_inbox
                    (payload_sha256, payload_ciphertext, payload_nonce, payload_tag, status, attempts, available_at, received_at)
                 VALUES (:hash, :cipher, :nonce, :tag, "pending", 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
            );
            $insert->execute([
                ':hash' => $payloadHash,
                ':cipher' => $encrypted['ciphertext'],
                ':nonce' => $encrypted['nonce'],
                ':tag' => $encrypted['tag'],
            ]);
            $query = $this->pdo->prepare('SELECT id, status, attempts FROM webhook_inbox WHERE payload_sha256 = :hash LIMIT 1');
            $query->execute([':hash' => $payloadHash]);
            $row = $query->fetch();
            if (!is_array($row)) {
                throw new RuntimeException('Webhook inbox insert could not be confirmed.');
            }
            if ($row['status'] === 'failed' && (int) $row['attempts'] < 8) {
                $this->pdo->prepare(
                    'UPDATE webhook_inbox SET status = "pending", available_at = UTC_TIMESTAMP(), last_error = NULL
                     WHERE id = :id AND status = "failed"'
                )->execute([':id' => $row['id']]);
                $row['status'] = 'pending';
            }
            return ['id' => (int) $row['id'], 'inserted' => $insert->rowCount() === 1, 'status' => (string) $row['status']];
        });
    }

    public function claimWebhookInbox(string $lockToken, int $maxAttempts = 8): ?array
    {
        return $this->transaction(function () use ($lockToken, $maxAttempts): ?array {
            $maxAttempts = max(1, $maxAttempts);
            $this->pdo->prepare(
                'UPDATE webhook_inbox
                 SET status = "failed", locked_at = NULL, lock_token = NULL,
                     last_error = "Worker lease expired after maximum attempts."
                 WHERE status = "processing"
                   AND locked_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE)
                   AND attempts >= :max_attempts'
            )->execute([':max_attempts' => $maxAttempts]);

            $query = $this->pdo->prepare(
                'SELECT id, payload_ciphertext, payload_nonce, payload_tag, attempts
                 FROM webhook_inbox
                 WHERE (status = "pending" AND available_at <= UTC_TIMESTAMP())
                    OR (status = "processing" AND locked_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE)
                        AND attempts < :max_attempts)
                 ORDER BY received_at ASC
                 LIMIT 1 FOR UPDATE'
            );
            $query->execute([':max_attempts' => $maxAttempts]);
            $job = $query->fetch();
            if (!is_array($job)) return null;

            $attempt = (int) $job['attempts'] + 1;
            $this->pdo->prepare(
                'UPDATE webhook_inbox
                 SET status = "processing", attempts = :attempt, locked_at = UTC_TIMESTAMP(), lock_token = :token
                 WHERE id = :id'
            )->execute([':attempt' => $attempt, ':token' => $lockToken, ':id' => $job['id']]);
            $job['attempts'] = $attempt;
            return $job;
        });
    }

    public function completeWebhookInbox(int $id, string $lockToken): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE webhook_inbox
             SET status = "processed", processed_at = UTC_TIMESTAMP(), locked_at = NULL,
                 lock_token = NULL, last_error = NULL
             WHERE id = :id AND status = "processing" AND lock_token = :token'
        );
        $statement->execute([':id' => $id, ':token' => $lockToken]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Webhook inbox lease was lost before completion.');
        }
    }

    public function failWebhookInbox(int $id, string $lockToken, bool $retry, int $delaySeconds, string $error): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE webhook_inbox
             SET status = :status, available_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL :delay SECOND),
                 locked_at = NULL, lock_token = NULL, last_error = :error
             WHERE id = :id AND status = "processing" AND lock_token = :token'
        );
        $statement->execute([
            ':status' => $retry ? 'pending' : 'failed',
            ':delay' => max(0, $delaySeconds),
            ':error' => mb_substr_safe($error, 0, 450),
            ':id' => $id,
            ':token' => $lockToken,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Webhook inbox lease was lost before failure could be recorded.');
        }
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
