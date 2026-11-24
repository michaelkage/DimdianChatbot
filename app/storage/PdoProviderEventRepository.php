<?php
declare(strict_types=1);

/**
 * MySQL/MariaDB provider event ledger and message/status reconciliation.
 *
 * Provider event deduplication, identity routing, message insertion and
 * delivery-state precedence are committed atomically.
 */
final class PdoProviderEventRepository implements ProviderEventRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function recordProviderMessageEvent(
        string $eventId, string $eventType, string $payloadHash, string $phone,
        string $senderType, string $body, string $providerMessageId,
        string $deliveryStatus, string $sentAt
    ): string {
        if (!in_array($senderType, ['visitor', 'business'], true)) {
            throw new InvalidArgumentException('Unsupported provider message sender.');
        }
        if (!in_array($deliveryStatus, ['received', 'sent'], true)) {
            throw new InvalidArgumentException('Unsupported provider message delivery state.');
        }

        return $this->transaction(function () use ($eventId, $eventType, $payloadHash, $phone, $senderType, $body, $providerMessageId, $deliveryStatus, $sentAt): string {
            if (!$this->insertWebhookEvent($eventId, $eventType, $payloadHash)) return 'duplicate';

            $conversation = $this->pdo->prepare(
                'SELECT c.id
                 FROM whatsapp_identity_links l
                 INNER JOIN conversations c ON c.id = l.conversation_id
                 WHERE l.phone_normalized = :phone AND l.is_active = 1 AND c.status = "open"
                 LIMIT 1'
            );
            $conversation->execute([':phone' => $phone]);
            $conversationId = $conversation->fetchColumn();
            if (!is_string($conversationId)) {
                $this->markWebhookEvent($eventId, 'ignored');
                return 'ignored';
            }

            $insert = $this->pdo->prepare(
                'INSERT IGNORE INTO messages
                    (id, conversation_id, sender_type, body, provider_message_id, delivery_status, sent_at, created_at)
                 VALUES
                    (:id, :conversation_id, :sender_type, :body, :provider_id, :delivery_status, :sent_at, UTC_TIMESTAMP())'
            );
            $insert->execute([
                ':id' => uuid_v4(),
                ':conversation_id' => $conversationId,
                ':sender_type' => $senderType,
                ':body' => $body,
                ':provider_id' => $providerMessageId,
                ':delivery_status' => $deliveryStatus,
                ':sent_at' => $sentAt,
            ]);
            if ($insert->rowCount() !== 1) {
                $this->markWebhookEvent($eventId, 'ignored');
                return 'ignored';
            }

            $update = $this->pdo->prepare(
                'UPDATE conversations SET last_message_at = :sent_at, updated_at = UTC_TIMESTAMP() WHERE id = :id'
            );
            $update->execute([':sent_at' => $sentAt, ':id' => $conversationId]);
            $this->markWebhookEvent($eventId, 'processed');
            return 'processed';
        });
    }

    public function reconcileProviderMessageStatus(string $providerMessageId): bool
    {
        $lookup = $this->pdo->prepare(
            'SELECT status FROM message_status_events
             WHERE provider_message_id = :provider_id
             ORDER BY CASE status WHEN "read" THEN 4 WHEN "delivered" THEN 3 WHEN "failed" THEN 2 ELSE 1 END DESC,
                      event_timestamp DESC LIMIT 1'
        );
        $lookup->execute([':provider_id' => $providerMessageId]);
        $status = $lookup->fetchColumn();
        if (!is_string($status)) return false;

        $update = $this->pdo->prepare('UPDATE messages SET delivery_status = :status WHERE provider_message_id = :provider_id');
        $update->execute([':status' => $status, ':provider_id' => $providerMessageId]);
        return $update->rowCount() > 0;
    }

    public function recordIgnoredProviderEvent(string $eventId, string $eventType, string $payloadHash): string
    {
        return $this->transaction(function () use ($eventId, $eventType, $payloadHash): string {
            if (!$this->insertWebhookEvent($eventId, $eventType, $payloadHash)) return 'duplicate';
            $this->markWebhookEvent($eventId, 'ignored');
            return 'ignored';
        });
    }

    public function recordProviderStatusEvent(
        string $eventId, string $payloadHash, string $providerMessageId,
        string $status, int $eventTimestamp
    ): string {
        if (!in_array($status, ['sent', 'delivered', 'read', 'failed'], true)) {
            throw new InvalidArgumentException('Unsupported provider message status.');
        }

        return $this->transaction(function () use ($eventId, $payloadHash, $providerMessageId, $status, $eventTimestamp): string {
            if (!$this->insertWebhookEvent($eventId, 'message_status', $payloadHash)) return 'duplicate';

            $store = $this->pdo->prepare(
                'INSERT IGNORE INTO message_status_events
                    (provider_message_id, status, event_timestamp, created_at)
                 VALUES (:provider_id, :status, :event_timestamp, UTC_TIMESTAMP())'
            );
            $store->execute([
                ':provider_id' => $providerMessageId,
                ':status' => $status,
                ':event_timestamp' => max(0, $eventTimestamp),
            ]);

            $found = $this->reconcileProviderMessageStatus($providerMessageId);
            $this->markWebhookEvent($eventId, $found ? 'processed' : 'ignored');
            return $found ? 'processed' : 'ignored';
        });
    }

    private function insertWebhookEvent(string $eventId, string $eventType, string $payloadHash): bool
    {
        $insert = $this->pdo->prepare(
            'INSERT IGNORE INTO webhook_events
                (provider_event_id, event_type, payload_sha256, received_at, processing_status)
             VALUES (:event_id, :event_type, :payload_hash, UTC_TIMESTAMP(), "received")'
        );
        $insert->execute([
            ':event_id' => substr($eventId, 0, 191),
            ':event_type' => substr($eventType, 0, 100),
            ':payload_hash' => $payloadHash,
        ]);
        return $insert->rowCount() === 1;
    }

    private function markWebhookEvent(string $eventId, string $status): void
    {
        $update = $this->pdo->prepare(
            'UPDATE webhook_events SET processing_status = :status, processed_at = UTC_TIMESTAMP()
             WHERE provider_event_id = :event_id'
        );
        $update->execute([':status' => $status, ':event_id' => substr($eventId, 0, 191)]);
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
