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
        string $tenantId, string $eventId, string $eventType, string $payloadHash, string $phone,
        string $senderType, string $body, string $providerMessageId,
        string $deliveryStatus, string $sentAt
    ): string {
        if (!in_array($senderType, ['visitor', 'business'], true)) {
            throw new InvalidArgumentException('Unsupported provider message sender.');
        }
        if (!in_array($deliveryStatus, ['received', 'sent'], true)) {
            throw new InvalidArgumentException('Unsupported provider message delivery state.');
        }

        return $this->transaction(function () use ($tenantId, $eventId, $eventType, $payloadHash, $phone, $senderType, $body, $providerMessageId, $deliveryStatus, $sentAt): string {
            if (!$this->insertWebhookEvent($tenantId, $eventId, $eventType, $payloadHash)) return 'duplicate';

            $conversation = PdoSqlDialect::prepare($this->pdo, 
                'SELECT c.id
                 FROM whatsapp_identity_links l
                 INNER JOIN conversations c ON c.id = l.conversation_id
                 WHERE l.tenant_id = :tenant_id AND c.tenant_id = :tenant_id
                   AND l.phone_normalized = :phone AND l.is_active = 1 AND c.status = "open"
                 LIMIT 1'
            );
            $conversation->execute([':tenant_id' => $tenantId, ':phone' => $phone]);
            $conversationId = $conversation->fetchColumn();
            if (!is_string($conversationId)) {
                $this->markWebhookEvent($tenantId, $eventId, 'ignored');
                return 'ignored';
            }

            $insert = PdoSqlDialect::prepare($this->pdo, 
                'INSERT IGNORE INTO messages
                    (id, tenant_id, conversation_id, sender_type, body, provider_message_id, delivery_status, sent_at, created_at)
                 VALUES
                    (:id, :tenant_id, :conversation_id, :sender_type, :body, :provider_id, :delivery_status, :sent_at, UTC_TIMESTAMP())'
            );
            $insert->execute([
                ':id' => uuid_v4(),
                ':tenant_id' => $tenantId,
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

            $update = PdoSqlDialect::prepare($this->pdo, 
                'UPDATE conversations SET last_message_at = :sent_at, updated_at = UTC_TIMESTAMP() WHERE id = :id AND tenant_id = :tenant_id'
            );
            $update->execute([':sent_at' => $sentAt, ':id' => $conversationId, ':tenant_id' => $tenantId]);
            $this->markWebhookEvent($tenantId, $eventId, 'processed');
            return 'processed';
        });
    }

    public function reconcileProviderMessageStatus(string $tenantId, string $providerMessageId): bool
    {
        $lookup = PdoSqlDialect::prepare($this->pdo, 
            'SELECT status FROM message_status_events
             WHERE tenant_id = :tenant_id AND provider_message_id = :provider_id
             ORDER BY CASE status WHEN "read" THEN 4 WHEN "delivered" THEN 3 WHEN "failed" THEN 2 ELSE 1 END DESC,
                      event_timestamp DESC LIMIT 1'
        );
        $lookup->execute([':tenant_id' => $tenantId, ':provider_id' => $providerMessageId]);
        $status = $lookup->fetchColumn();
        if (!is_string($status)) return false;

        $update = PdoSqlDialect::prepare($this->pdo, 'UPDATE messages SET delivery_status = :status WHERE tenant_id = :tenant_id AND provider_message_id = :provider_id');
        $update->execute([':status' => $status, ':tenant_id' => $tenantId, ':provider_id' => $providerMessageId]);
        return $update->rowCount() > 0;
    }

    public function recordIgnoredProviderEvent(string $tenantId, string $eventId, string $eventType, string $payloadHash): string
    {
        return $this->transaction(function () use ($tenantId, $eventId, $eventType, $payloadHash): string {
            if (!$this->insertWebhookEvent($tenantId, $eventId, $eventType, $payloadHash)) return 'duplicate';
            $this->markWebhookEvent($tenantId, $eventId, 'ignored');
            return 'ignored';
        });
    }

    public function recordProviderStatusEvent(
        string $tenantId, string $eventId, string $payloadHash, string $providerMessageId,
        string $status, int $eventTimestamp
    ): string {
        if (!in_array($status, ['sent', 'delivered', 'read', 'failed'], true)) {
            throw new InvalidArgumentException('Unsupported provider message status.');
        }

        return $this->transaction(function () use ($tenantId, $eventId, $payloadHash, $providerMessageId, $status, $eventTimestamp): string {
            if (!$this->insertWebhookEvent($tenantId, $eventId, 'message_status', $payloadHash)) return 'duplicate';

            $store = PdoSqlDialect::prepare($this->pdo, 
                'INSERT IGNORE INTO message_status_events
                    (tenant_id, provider_message_id, status, event_timestamp, created_at)
                 VALUES (:tenant_id, :provider_id, :status, :event_timestamp, UTC_TIMESTAMP())'
            );
            $store->execute([
                ':tenant_id' => $tenantId,
                ':provider_id' => $providerMessageId,
                ':status' => $status,
                ':event_timestamp' => max(0, $eventTimestamp),
            ]);

            $found = $this->reconcileProviderMessageStatus($tenantId, $providerMessageId);
            $this->markWebhookEvent($tenantId, $eventId, $found ? 'processed' : 'ignored');
            return $found ? 'processed' : 'ignored';
        });
    }

    private function insertWebhookEvent(string $tenantId, string $eventId, string $eventType, string $payloadHash): bool
    {
        $insert = PdoSqlDialect::prepare($this->pdo, 
            'INSERT IGNORE INTO webhook_events
                (tenant_id, provider_event_id, event_type, payload_sha256, received_at, processing_status)
             VALUES (:tenant_id, :event_id, :event_type, :payload_hash, UTC_TIMESTAMP(), "received")'
        );
        $insert->execute([
            ':tenant_id' => $tenantId,
            ':event_id' => substr($eventId, 0, 191),
            ':event_type' => substr($eventType, 0, 100),
            ':payload_hash' => $payloadHash,
        ]);
        return $insert->rowCount() === 1;
    }

    private function markWebhookEvent(string $tenantId, string $eventId, string $status): void
    {
        $update = PdoSqlDialect::prepare($this->pdo, 
            'UPDATE webhook_events SET processing_status = :status, processed_at = UTC_TIMESTAMP()
             WHERE tenant_id = :tenant_id AND provider_event_id = :event_id'
        );
        $update->execute([':status' => $status, ':tenant_id' => $tenantId, ':event_id' => substr($eventId, 0, 191)]);
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
