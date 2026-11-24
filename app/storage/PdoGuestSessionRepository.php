<?php
declare(strict_types=1);

/**
 * MySQL/MariaDB persistence for guest, conversation and guest-session creation.
 *
 * The consent adapter shares this connection so initial opt-in is committed
 * atomically with guest/session creation.
 */
final class PdoGuestSessionRepository implements GuestSessionRepository
{
    private PDO $pdo;
    private PdoConsentRepository $consent;

    public function __construct(PDO $pdo, PdoConsentRepository $consent)
    {
        $this->pdo = $pdo;
        $this->consent = $consent;
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
                // The consent ledger, active identity route and guest flag share this transaction.
                $this->consent->recordWhatsAppConsent(
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
