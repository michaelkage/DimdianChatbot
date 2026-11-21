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
        if (!in_array($action, ['opt_in', 'opt_out'], true)) {
            throw new InvalidArgumentException('Unsupported consent action.');
        }
        if ($action === 'opt_in' && !preg_match('/^[1-9][0-9]{7,14}$/', $normalizedPhone)) {
            throw new InvalidArgumentException('A valid international WhatsApp number is required.');
        }

        $this->transaction(function () use ($guestId, $conversationId, $normalizedPhone, $action, $source): void {
            if ($action === 'opt_in') {
                // A phone number can route to one active website conversation only.
                // Re-consent deliberately moves the route; prior conversations remain readable.
                $deactivate = $this->pdo->prepare(
                    'UPDATE whatsapp_identity_links
                     SET is_active = 0, deactivated_at = UTC_TIMESTAMP()
                     WHERE phone_normalized = :phone AND is_active = 1'
                );
                $deactivate->execute([':phone' => $normalizedPhone]);
                $link = $this->pdo->prepare(
                    'INSERT INTO whatsapp_identity_links
                        (phone_normalized, guest_id, conversation_id, is_active, created_at)
                     VALUES (:phone, :guest, :conversation, 1, UTC_TIMESTAMP())'
                );
                $link->execute([':phone' => $normalizedPhone, ':guest' => $guestId, ':conversation' => $conversationId]);
                $update = $this->pdo->prepare(
                    'UPDATE guests SET phone_normalized = :phone, whatsapp_opt_in_at = :now, updated_at = UTC_TIMESTAMP() WHERE id = :guest'
                );
                $update->execute([':phone' => $normalizedPhone, ':now' => gmdate('Y-m-d H:i:s'), ':guest' => $guestId]);
            } else {
                $deactivate = $this->pdo->prepare(
                    'UPDATE whatsapp_identity_links
                     SET is_active = 0, deactivated_at = UTC_TIMESTAMP()
                     WHERE guest_id = :guest AND is_active = 1'
                );
                $deactivate->execute([':guest' => $guestId]);
                $update = $this->pdo->prepare(
                    'UPDATE guests SET whatsapp_opt_in_at = NULL, updated_at = UTC_TIMESTAMP() WHERE id = :guest'
                );
                $update->execute([':guest' => $guestId]);

                // Prevent queued messages from being sent after consent withdrawal.
                $cancel = $this->pdo->prepare(
                    'UPDATE message_outbox o
                     INNER JOIN messages m ON m.id = o.message_id
                     INNER JOIN conversations c ON c.id = m.conversation_id
                     SET o.status = "failed", o.locked_at = NULL, o.lock_token = NULL,
                         o.last_error = "Cancelled after WhatsApp consent withdrawal.",
                         o.updated_at = UTC_TIMESTAMP()
                     WHERE c.guest_id = :guest AND o.status IN ("pending","processing")'
                );
                $cancel->execute([':guest' => $guestId]);
                $mark = $this->pdo->prepare(
                    'UPDATE messages m INNER JOIN conversations c ON c.id = m.conversation_id
                     SET m.delivery_status = "web_only"
                     WHERE c.guest_id = :guest AND m.delivery_status = "queued"'
                );
                $mark->execute([':guest' => $guestId]);
            }

            $event = $this->pdo->prepare(
                'INSERT INTO guest_consent_events (guest_id, channel, action, source, policy_version, created_at)
                 VALUES (:guest, "whatsapp", :action, :source, "2026-11", UTC_TIMESTAMP())'
            );
            $event->execute([
                ':guest' => $guestId,
                ':action' => $action,
                ':source' => substr(preg_replace('/[^a-z0-9_-]/i', '', $source) ?: 'website_widget', 0, 40),
            ]);
        });
    }

    public function consumeRateLimit(string $bucket, int $maxRequests, int $windowSeconds): bool
    {
        $bucketKey = hash('sha256', $bucket);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $cutoff = $now->modify('-' . max(1, $windowSeconds) . ' seconds')->format('Y-m-d H:i:s');
        $nowString = $now->format('Y-m-d H:i:s');

        return $this->transaction(function () use ($bucketKey, $cutoff, $nowString, $maxRequests): bool {
            $insert = $this->pdo->prepare(
                'INSERT INTO rate_limits (bucket_key, window_started_at, request_count)
                 VALUES (:bucket, :now, 1)
                 ON DUPLICATE KEY UPDATE
                   request_count = IF(window_started_at <= :cutoff, 1, request_count + 1),
                   window_started_at = IF(window_started_at <= :cutoff2, :now2, window_started_at)'
            );
            $insert->execute([
                ':bucket' => $bucketKey,
                ':now' => $nowString,
                ':cutoff' => $cutoff,
                ':cutoff2' => $cutoff,
                ':now2' => $nowString,
            ]);
            $select = $this->pdo->prepare('SELECT request_count FROM rate_limits WHERE bucket_key = :bucket FOR UPDATE');
            $select->execute([':bucket' => $bucketKey]);
            $row = $select->fetch();
            return is_array($row) && (int) $row['request_count'] <= max(1, $maxRequests);
        });
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
                $this->recordWhatsAppConsent(
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
