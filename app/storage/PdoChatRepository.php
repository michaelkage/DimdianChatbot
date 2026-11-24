<?php
declare(strict_types=1);

/**
 * Reference ChatRepository implementation for MySQL 8 / compatible MariaDB.
 *
 * SQL dialect details stay in this adapter. A PostgreSQL implementation must
 * implement the same contract and pass the shared API/integration tests; it
 * must not be selected just because PDO can connect to a PostgreSQL DSN.
 */
final class PdoChatRepository implements ChatRepository
{
    private PdoWebhookInboxRepository $webhookInbox;
    private PdoRateLimitRepository $rateLimits;
    private PdoConsentRepository $consent;
    private PdoGuestSessionRepository $guestSessions;
    private PdoVisitorMessageRepository $visitorMessages;
    private PdoProviderEventRepository $providerEvents;

    public function __construct(private PDO $pdo)
    {
        $this->webhookInbox = new PdoWebhookInboxRepository($pdo);
        $this->rateLimits = new PdoRateLimitRepository($pdo);
        $this->consent = new PdoConsentRepository($pdo);
        $this->guestSessions = new PdoGuestSessionRepository($pdo, $this->consent);
        $this->visitorMessages = new PdoVisitorMessageRepository($pdo);
        $this->providerEvents = new PdoProviderEventRepository($pdo);
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
            $this->reconcileProviderMessageStatus($providerMessageId);
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

    public function recordWhatsAppConsent(string $guestId, string $conversationId, string $normalizedPhone, string $action, string $source = 'website_widget'): void
    {
        $this->consent->recordWhatsAppConsent($guestId, $conversationId, $normalizedPhone, $action, $source);
    }

    public function consumeRateLimit(string $bucket, int $maxRequests, int $windowSeconds): bool
    {
        return $this->rateLimits->consumeRateLimit($bucket, $maxRequests, $windowSeconds);
    }

    public function createGuestSession(array $session): void
    {
        $this->guestSessions->createGuestSession($session);
    }

    public function findGuestSessionByTokenHash(string $tokenHash): ?array
    {
        return $this->guestSessions->findGuestSessionByTokenHash($tokenHash);
    }

    public function touchGuestSession(string $sessionId): void
    {
        $this->guestSessions->touchGuestSession($sessionId);
    }

    public function createVisitorMessage(array $message): array
    {
        return $this->visitorMessages->createVisitorMessage($message);
    }

    public function listConversationMessages(string $conversationId, int $after, int $limit): array
    {
        return $this->visitorMessages->listConversationMessages($conversationId, $after, $limit);
    }

    public function listVisitorDeliveryUpdates(string $conversationId, int $limit = 50): array
    {
        return $this->visitorMessages->listVisitorDeliveryUpdates($conversationId, $limit);
    }


    public function activeWhatsAppPhoneNumberId(): ?string
    {
        $statement = $this->pdo->query(
            'SELECT phone_number_id
             FROM meta_connections
             WHERE status = "active"
             ORDER BY updated_at DESC
             LIMIT 1'
        );
        $value = $statement->fetchColumn();
        return is_string($value) && $value !== '' ? $value : null;
    }

    public function enqueueWebhookInbox(string $payloadHash, array $encrypted): array
    {
        return $this->webhookInbox->enqueueWebhookInbox($payloadHash, $encrypted);
    }

    public function claimWebhookInbox(string $lockToken, int $maxAttempts = 8): ?array
    {
        return $this->webhookInbox->claimWebhookInbox($lockToken, $maxAttempts);
    }

    public function completeWebhookInbox(int $id, string $lockToken): void
    {
        $this->webhookInbox->completeWebhookInbox($id, $lockToken);
    }

    public function failWebhookInbox(int $id, string $lockToken, bool $retry, int $delaySeconds, string $error): void
    {
        $this->webhookInbox->failWebhookInbox($id, $lockToken, $retry, $delaySeconds, $error);
    }


    public function recordProviderMessageEvent(
        string $eventId,
        string $eventType,
        string $payloadHash,
        string $phone,
        string $senderType,
        string $body,
        string $providerMessageId,
        string $deliveryStatus,
        string $sentAt
    ): string {
        return $this->providerEvents->recordProviderMessageEvent(
            $eventId,
            $eventType,
            $payloadHash,
            $phone,
            $senderType,
            $body,
            $providerMessageId,
            $deliveryStatus,
            $sentAt
        );
    }

    public function reconcileProviderMessageStatus(string $providerMessageId): bool
    {
        return $this->providerEvents->reconcileProviderMessageStatus($providerMessageId);
    }

    public function recordIgnoredProviderEvent(string $eventId, string $eventType, string $payloadHash): string
    {
        return $this->providerEvents->recordIgnoredProviderEvent($eventId, $eventType, $payloadHash);
    }

    public function recordProviderStatusEvent(
        string $eventId,
        string $payloadHash,
        string $providerMessageId,
        string $status,
        int $eventTimestamp
    ): string {
        return $this->providerEvents->recordProviderStatusEvent(
            $eventId,
            $payloadHash,
            $providerMessageId,
            $status,
            $eventTimestamp
        );
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
