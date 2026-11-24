<?php
declare(strict_types=1);

/**
 * MySQL/MariaDB persistence for visitor-visible messages and polling.
 *
 * A visitor message and its optional WhatsApp outbox row are inserted in one
 * transaction. Idempotent replay returns the original message and never
 * creates a second outbound job.
 */
final class PdoVisitorMessageRepository implements VisitorMessageRepository
{
    private PDO $pdo;
    private WhatsAppOutboxRepository $outbox;

    public function __construct(PDO $pdo, WhatsAppOutboxRepository $outbox)
    {
        $this->pdo = $pdo;
        $this->outbox = $outbox;
    }

    public function createVisitorMessage(array $message): array
    {
        return $this->transaction(function () use ($message): array {
            $insert = $this->pdo->prepare(
                'INSERT IGNORE INTO messages
                    (id, conversation_id, client_message_id, sender_type, body, delivery_status, sent_at, created_at)
                 VALUES
                    (:id, :conversation_id, :client_message_id, "visitor", :body, :delivery_status, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
            );
            $insert->execute([
                ':id' => $message['id'],
                ':conversation_id' => $message['conversation_id'],
                ':client_message_id' => $message['client_message_id'],
                ':body' => $message['body'],
                ':delivery_status' => $message['outbound_enabled'] ? 'queued' : 'web_only',
            ]);
            $inserted = $insert->rowCount() === 1;

            $lookup = $this->pdo->prepare(
                'SELECT id, sequence_id, sent_at, delivery_status, body
                 FROM messages
                 WHERE conversation_id = :conversation_id
                   AND client_message_id = :client_message_id
                 LIMIT 1'
            );
            $lookup->execute([
                ':conversation_id' => $message['conversation_id'],
                ':client_message_id' => $message['client_message_id'],
            ]);
            $saved = $lookup->fetch();
            if (!is_array($saved)) {
                throw new RuntimeException('Message idempotency lookup failed.');
            }
            if (!hash_equals((string) $saved['body'], (string) $message['body'])) {
                throw new ChatStorageConflict('This message ID was already used for different text.');
            }

            if ($inserted) {
                $update = $this->pdo->prepare(
                    'UPDATE conversations
                     SET last_message_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
                     WHERE id = :id AND status = "open"'
                );
                $update->execute([':id' => $message['conversation_id']]);

                if ($message['outbound_enabled']) {
                    // The outbox adapter uses this same PDO connection; its insert
                    // participates in the message transaction and rolls back with it.
                    $this->outbox->enqueueWhatsAppOutbox(
                        (string) $saved['id'],
                        (string) $message['recipient'],
                        (string) $message['outbox_payload'],
                        (string) $message['outbox_id']
                    );
                }
            }

            return [
                'message' => $saved,
                'inserted' => $inserted,
                'transport' => $inserted
                    ? ($message['outbound_enabled'] ? 'queued' : 'not_configured')
                    : 'replayed',
            ];
        });
    }

    public function listConversationMessages(string $conversationId, int $after, int $limit): array
    {
        $query = $this->pdo->prepare(
            'SELECT sequence_id, id, sender_type, body, delivery_status, sent_at
             FROM messages
             WHERE conversation_id = :conversation_id AND sequence_id > :after
             ORDER BY sequence_id ASC
             LIMIT ' . ($limit + 1)
        );
        $query->bindValue(':conversation_id', $conversationId);
        $query->bindValue(':after', $after, PDO::PARAM_INT);
        $query->execute();
        $rows = $query->fetchAll();
        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            $rows = array_slice($rows, 0, $limit);
        }
        $items = array_map(static function (array $row): array {
            return [
                'id' => $row['id'],
                'sequence_id' => (int) $row['sequence_id'],
                'sender' => $row['sender_type'],
                'message' => $row['body'],
                'delivery_status' => $row['delivery_status'],
                'created_at' => (new DateTimeImmutable($row['sent_at'], new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            ];
        }, $rows);
        $nextCursor = $items === [] ? $after : $items[array_key_last($items)]['sequence_id'];

        return ['items' => $items, 'has_more' => $hasMore, 'next_cursor' => $nextCursor];
    }

    public function listVisitorDeliveryUpdates(string $conversationId, int $limit = 50): array
    {
        $query = $this->pdo->prepare(
            'SELECT id, delivery_status FROM messages
             WHERE conversation_id = :conversation_id AND sender_type = "visitor"
             ORDER BY sequence_id DESC LIMIT ' . max(1, min(100, $limit))
        );
        $query->execute([':conversation_id' => $conversationId]);
        return array_map(static fn(array $row): array => [
            'id' => (string) $row['id'],
            'delivery_status' => (string) $row['delivery_status'],
        ], $query->fetchAll());
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
