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
    public function __construct(private PDO $pdo)
    {
    }

    public function createGuestSession(array $session): void
    {
        $this->transaction(function () use ($session): void {
            $guest = $this->pdo->prepare(
                'INSERT INTO guests (id, display_name, phone, phone_normalized, whatsapp_opt_in_at, created_at, updated_at)
                 VALUES (:id, :name, :phone, :phone_normalized, :whatsapp_opt_in_at, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
            );
            $guest->execute([
                ':id' => $session['guest_id'],
                ':name' => $session['name'],
                ':phone' => $session['phone'],
                ':phone_normalized' => $session['phone_normalized'],
                ':whatsapp_opt_in_at' => $session['whatsapp_opt_in'] ? $session['created_at']->format('Y-m-d H:i:s') : null,
            ]);

            $conversation = $this->pdo->prepare(
                'INSERT INTO conversations (id, guest_id, status, created_at, updated_at)
                 VALUES (:id, :guest_id, "open", UTC_TIMESTAMP(), UTC_TIMESTAMP())'
            );
            $conversation->execute([
                ':id' => $session['conversation_id'],
                ':guest_id' => $session['guest_id'],
            ]);

            $guestSession = $this->pdo->prepare(
                'INSERT INTO guest_sessions (id, guest_id, conversation_id, token_hash, created_at, expires_at)
                 VALUES (:id, :guest_id, :conversation_id, :token_hash, UTC_TIMESTAMP(), :expires_at)'
            );
            $guestSession->execute([
                ':id' => $session['session_id'],
                ':guest_id' => $session['guest_id'],
                ':conversation_id' => $session['conversation_id'],
                ':token_hash' => $session['token_hash'],
                ':expires_at' => $session['expires_at']->format('Y-m-d H:i:s'),
            ]);

            if ($session['whatsapp_opt_in'] && $session['phone_normalized'] !== null) {
                if (!function_exists('record_whatsapp_consent')) {
                    throw new LogicException('The WhatsApp consent service must be loaded before creating an opted-in session.');
                }
                // Keep the existing consent policy/identity-link implementation as the single source of truth.
                // It runs on this adapter's connection and therefore commits atomically with the guest/session rows.
                record_whatsapp_consent(
                    $this->pdo,
                    (string) $session['guest_id'],
                    (string) $session['conversation_id'],
                    (string) $session['phone_normalized'],
                    'opt_in',
                    'website_widget'
                );
            }
        });
    }

    public function findGuestSessionByTokenHash(string $tokenHash): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT gs.id AS session_id, gs.guest_id, gs.conversation_id,
                    g.display_name, g.phone, g.phone_normalized, g.whatsapp_opt_in_at,
                    c.status AS conversation_status
             FROM guest_sessions gs
             INNER JOIN conversations c ON c.id = gs.conversation_id
             INNER JOIN guests g ON g.id = gs.guest_id
             WHERE gs.token_hash = :token_hash
               AND gs.revoked_at IS NULL
               AND gs.expires_at > UTC_TIMESTAMP()
             LIMIT 1'
        );
        $statement->execute([':token_hash' => $tokenHash]);
        $session = $statement->fetch();
        return is_array($session) ? $session : null;
    }

    public function touchGuestSession(string $sessionId): void
    {
        $touch = $this->pdo->prepare('UPDATE guest_sessions SET last_seen_at = UTC_TIMESTAMP() WHERE id = :id');
        $touch->execute([':id' => $sessionId]);
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
                    $outbox = $this->pdo->prepare(
                        'INSERT INTO message_outbox
                            (id, message_id, recipient, payload_text, status, attempts, available_at, created_at, updated_at)
                         VALUES
                            (:id, :message_id, :recipient, :payload, "pending", 0, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())'
                    );
                    $outbox->execute([
                        ':id' => $message['outbox_id'],
                        ':message_id' => $saved['id'],
                        ':recipient' => $message['recipient'],
                        ':payload' => $message['outbox_payload'],
                    ]);
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
