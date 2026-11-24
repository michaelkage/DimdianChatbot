<?php
declare(strict_types=1);

/** MySQL/MariaDB persistence for staff-visible messages, private notes and audit reads. */
final class PdoStaffMessageRepository implements StaffMessageRepository
{
    private PDO $pdo;
    private PdoStaffPersistenceSupport $support;

    public function __construct(PDO $pdo, PdoStaffPersistenceSupport $support)
    {
        $this->pdo = $pdo;
        $this->support = $support;
    }

    public function listConversationMessages(string $conversationId, int $afterSequence, int $limit, ?string $requiredAssigneeId = null): array
    {
        $query = $this->pdo->prepare(
            'SELECT m.sequence_id, m.id, m.sender_type, m.body, m.delivery_status, m.sent_at,
                    author.display_name AS staff_author_name
             FROM messages m
             LEFT JOIN staff_message_authors sa ON sa.message_id = m.id
             LEFT JOIN staff_identities author ON author.id = sa.staff_identity_id
             WHERE m.conversation_id = :conversation AND m.sequence_id > :after_sequence
               AND (:assignment_required = 0 OR EXISTS (
                   SELECT 1 FROM staff_conversation_assignments access
                   WHERE access.conversation_id = m.conversation_id
                     AND access.staff_identity_id = :required_assignee AND access.ended_at IS NULL
               ))
             ORDER BY m.sequence_id ASC LIMIT ' . (max(1, min(100, $limit)) + 1)
        );
        $query->execute([
            ':conversation' => $conversationId,
            ':after_sequence' => max(0, $afterSequence),
            ':assignment_required' => $requiredAssigneeId === null ? 0 : 1,
            ':required_assignee' => $requiredAssigneeId,
        ]);
        $rows = $query->fetchAll();
        $hasMore = count($rows) > max(1, min(100, $limit));
        if ($hasMore) $rows = array_slice($rows, 0, max(1, min(100, $limit)));
        return [
            'items' => $rows,
            'has_more' => $hasMore,
            'next_cursor' => $rows === [] ? $afterSequence : (int)$rows[array_key_last($rows)]['sequence_id'],
        ];
    }

    public function addInternalNote(
        string $id,
        string $conversationId,
        string $authorStaffId,
        string $body,
        ?string $requestId = null,
        bool $requireAssignment = false
    ): bool {
        $body = trim($body);
        if (!PdoStaffPersistenceSupport::isUuid($id) || $body === '' || PdoStaffPersistenceSupport::characterLength($body) > 10000) {
            throw new InvalidArgumentException('Internal note ID or body is invalid.');
        }

        return $this->support->transaction(function () use ($id, $conversationId, $authorStaffId, $body, $requestId, $requireAssignment): bool {
            $this->support->requireActiveStaff($authorStaffId);
            $conversation = $this->pdo->prepare('SELECT id FROM conversations WHERE id = :id FOR UPDATE');
            $conversation->execute([':id' => $conversationId]);
            if ($conversation->fetchColumn() === false) throw new RuntimeException('Conversation does not exist.');
            if ($requireAssignment) $this->support->requireActiveAssignment($conversationId, $authorStaffId);
            $existing = $this->pdo->prepare(
                'SELECT conversation_id, author_staff_id, body
                 FROM staff_internal_notes WHERE id = :id LIMIT 1'
            );
            $existing->execute([':id' => $id]);
            $prior = $existing->fetch();
            if (is_array($prior)) {
                if ($prior['conversation_id'] !== $conversationId
                    || $prior['author_staff_id'] !== $authorStaffId
                    || !hash_equals((string)$prior['body'], $body)) {
                    throw new ChatStorageConflict('The note ID was already used for different content.');
                }
                return false;
            }
            $insert = $this->pdo->prepare(
                'INSERT INTO staff_internal_notes
                    (id, conversation_id, author_staff_id, body, created_at)
                 VALUES (:id, :conversation, :author, :body, UTC_TIMESTAMP())'
            );
            $insert->execute([
                ':id' => $id,
                ':conversation' => $conversationId,
                ':author' => $authorStaffId,
                ':body' => $body,
            ]);
            $this->support->recordAudit($authorStaffId, $conversationId, 'note.created', null, ['note_id' => $id], $requestId);
            return true;
        });
    }

    public function listInternalNotes(string $conversationId, int $afterId, int $limit, ?string $requiredAssigneeId = null): array
    {
        $query = $this->pdo->prepare(
            'SELECT n.id, n.sequence_id, n.body, n.created_at,
                    s.id AS author_id, s.display_name AS author_name
             FROM staff_internal_notes n
             INNER JOIN staff_identities s ON s.id = n.author_staff_id
             WHERE n.conversation_id = :conversation AND n.sequence_id > :after_id
               AND (:assignment_required = 0 OR EXISTS (
                   SELECT 1 FROM staff_conversation_assignments access
                   WHERE access.conversation_id = n.conversation_id
                     AND access.staff_identity_id = :required_assignee AND access.ended_at IS NULL
               ))
             ORDER BY n.sequence_id ASC LIMIT ' . max(1, min(100, $limit))
        );
        $query->execute([
            ':conversation' => $conversationId,
            ':after_id' => max(0, $afterId),
            ':assignment_required' => $requiredAssigneeId === null ? 0 : 1,
            ':required_assignee' => $requiredAssigneeId,
        ]);
        return $query->fetchAll();
    }

    public function createStaffReply(array $message, string $staffIdentityId, ?string $requestId = null, bool $requireAssignment = false): array
    {
        $id = (string)($message['id'] ?? '');
        $conversationId = (string)($message['conversation_id'] ?? '');
        $clientMessageId = (string)($message['client_message_id'] ?? '');
        $body = trim((string)($message['body'] ?? ''));
        if (!PdoStaffPersistenceSupport::isUuid($id) || !PdoStaffPersistenceSupport::isUuid($clientMessageId) || $body === ''
            || PdoStaffPersistenceSupport::characterLength($body) > 5000) {
            throw new InvalidArgumentException('Staff reply ID or body is invalid.');
        }

        return $this->support->transaction(function () use (
            $id, $conversationId, $clientMessageId, $body, $staffIdentityId, $requestId, $requireAssignment
        ): array {
            $this->support->requireActiveStaff($staffIdentityId);
            $conversation = $this->pdo->prepare(
                'SELECT status FROM conversations WHERE id = :id FOR UPDATE'
            );
            $conversation->execute([':id' => $conversationId]);
            if ($conversation->fetchColumn() !== 'open') {
                throw new RuntimeException('Cannot reply to a missing or closed conversation.');
            }
            if ($requireAssignment) $this->support->requireActiveAssignment($conversationId, $staffIdentityId);

            $existing = $this->pdo->prepare(
                'SELECT m.id, m.conversation_id, m.client_message_id, m.sender_type, m.body,
                        m.delivery_status, m.sent_at, m.sequence_id,
                        author.staff_identity_id AS staff_author_id
                 FROM messages m
                 LEFT JOIN staff_message_authors author ON author.message_id = m.id
                 WHERE m.conversation_id = :conversation AND m.client_message_id = :client_id LIMIT 1'
            );
            $existing->execute([':conversation' => $conversationId, ':client_id' => $clientMessageId]);
            $prior = $existing->fetch();
            if (is_array($prior)) {
                if ($prior['sender_type'] !== 'business'
                    || $prior['staff_author_id'] !== $staffIdentityId
                    || !hash_equals((string)$prior['body'], $body)) {
                    throw new ChatStorageConflict('The client message ID was already used for different content.');
                }
                unset($prior['staff_author_id']);
                return ['message' => $prior, 'inserted' => false, 'transport' => 'web_only'];
            }

            $insert = $this->pdo->prepare(
                'INSERT INTO messages
                    (id, conversation_id, client_message_id, sender_type, body, delivery_status, sent_at, created_at)
                 VALUES (:id, :conversation, :client_id, "business", :body, "web_only", UTC_TIMESTAMP(), UTC_TIMESTAMP())'
            );
            $insert->execute([
                ':id' => $id,
                ':conversation' => $conversationId,
                ':client_id' => $clientMessageId,
                ':body' => $body,
            ]);
            $author = $this->pdo->prepare(
                'INSERT INTO staff_message_authors (message_id, staff_identity_id) VALUES (:message, :staff)'
            );
            $author->execute([':message' => $id, ':staff' => $staffIdentityId]);
            $activity = $this->pdo->prepare(
                'UPDATE conversations SET last_message_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = :id'
            );
            $activity->execute([':id' => $conversationId]);
            $this->support->recordAudit($staffIdentityId, $conversationId, 'message.staff_reply_created', null, ['message_id' => $id], $requestId);

            $read = $this->pdo->prepare(
                'SELECT id, conversation_id, client_message_id, sender_type, body, delivery_status, sent_at, sequence_id
                 FROM messages WHERE id = :id'
            );
            $read->execute([':id' => $id]);
            return ['message' => $read->fetch(), 'inserted' => true, 'transport' => 'web_only'];
        });
    }

    public function listAuditEvents(string $conversationId, int $afterId, int $limit): array
    {
        $query = $this->pdo->prepare(
            'SELECT e.id, e.event_type, e.target_staff_id, e.metadata_json, e.request_id, e.created_at,
                    actor.display_name AS actor_name, target.display_name AS target_name
             FROM staff_audit_events e
             INNER JOIN staff_identities actor ON actor.id = e.actor_staff_id
             LEFT JOIN staff_identities target ON target.id = e.target_staff_id
             WHERE e.conversation_id = :conversation AND e.id > :after_id
             ORDER BY e.id ASC LIMIT ' . max(1, min(100, $limit))
        );
        $query->execute([':conversation' => $conversationId, ':after_id' => max(0, $afterId)]);
        return $query->fetchAll();
    }
}
