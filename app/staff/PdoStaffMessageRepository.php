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

    public function listConversationMessages(string $conversationId, int $afterSequence, int $limit, ?string $requiredAssigneeId = null, string $tenantId = '00000000-0000-4000-8000-000000000001'): array
    {
        $query = PdoSqlDialect::prepare($this->pdo, 
            'SELECT m.sequence_id, m.id, m.sender_type, m.body, m.delivery_status, m.sent_at,
                    author.display_name AS staff_author_name
             FROM messages m
             LEFT JOIN staff_message_authors sa ON sa.tenant_id = m.tenant_id AND sa.message_id = m.id
             LEFT JOIN staff_identities author ON author.tenant_id = sa.tenant_id AND author.id = sa.staff_identity_id
             WHERE m.tenant_id = :tenant_id AND m.conversation_id = :conversation AND m.sequence_id > :after_sequence
               AND (:assignment_required = 0 OR EXISTS (
                   SELECT 1 FROM staff_conversation_assignments access
                   WHERE access.tenant_id = m.tenant_id AND access.conversation_id = m.conversation_id
                     AND access.staff_identity_id = :required_assignee AND access.ended_at IS NULL
               ))
             ORDER BY m.sequence_id ASC LIMIT ' . (max(1, min(100, $limit)) + 1)
        );
        $query->execute([
            ':tenant_id' => $tenantId,
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
        bool $requireAssignment = false,
        string $tenantId = '00000000-0000-4000-8000-000000000001'
    ): bool {
        $body = trim($body);
        if (!PdoStaffPersistenceSupport::isUuid($id) || $body === '' || PdoStaffPersistenceSupport::characterLength($body) > 10000) {
            throw new InvalidArgumentException('Internal note ID or body is invalid.');
        }

        return $this->support->transaction(function () use ($id, $conversationId, $authorStaffId, $body, $requestId, $requireAssignment, $tenantId): bool {
            $this->support->requireActiveStaff($authorStaffId, $tenantId);
            $conversation = PdoSqlDialect::prepare($this->pdo, 'SELECT id FROM conversations WHERE tenant_id = :tenant_id AND id = :id FOR UPDATE');
            $conversation->execute([':tenant_id' => $tenantId, ':id' => $conversationId]);
            if ($conversation->fetchColumn() === false) throw new RuntimeException('Conversation does not exist.');
            if ($requireAssignment) $this->support->requireActiveAssignment($conversationId, $authorStaffId, $tenantId);
            $existing = PdoSqlDialect::prepare($this->pdo, 
                'SELECT conversation_id, author_staff_id, body
                 FROM staff_internal_notes WHERE tenant_id = :tenant_id AND id = :id LIMIT 1'
            );
            $existing->execute([':tenant_id' => $tenantId, ':id' => $id]);
            $prior = $existing->fetch();
            if (is_array($prior)) {
                if ($prior['conversation_id'] !== $conversationId
                    || $prior['author_staff_id'] !== $authorStaffId
                    || !hash_equals((string)$prior['body'], $body)) {
                    throw new ChatStorageConflict('The note ID was already used for different content.');
                }
                return false;
            }
            $insert = PdoSqlDialect::prepare($this->pdo, 
                'INSERT INTO staff_internal_notes
                    (id, tenant_id, conversation_id, author_staff_id, body, created_at)
                 VALUES (:id, :tenant_id, :conversation, :author, :body, UTC_TIMESTAMP())'
            );
            $insert->execute([
                ':id' => $id,
                ':tenant_id' => $tenantId,
                ':conversation' => $conversationId,
                ':author' => $authorStaffId,
                ':body' => $body,
            ]);
            $this->support->recordAudit($authorStaffId, $conversationId, 'note.created', null, ['note_id' => $id], $requestId, $tenantId);
            return true;
        });
    }

    public function listInternalNotes(string $conversationId, int $afterId, int $limit, ?string $requiredAssigneeId = null, string $tenantId = '00000000-0000-4000-8000-000000000001'): array
    {
        $query = PdoSqlDialect::prepare($this->pdo, 
            'SELECT n.id, n.sequence_id, n.body, n.created_at,
                    s.id AS author_id, s.display_name AS author_name
             FROM staff_internal_notes n
             INNER JOIN staff_identities s ON s.tenant_id = n.tenant_id AND s.id = n.author_staff_id
             WHERE n.tenant_id = :tenant_id AND n.conversation_id = :conversation AND n.sequence_id > :after_id
               AND (:assignment_required = 0 OR EXISTS (
                   SELECT 1 FROM staff_conversation_assignments access
                   WHERE access.tenant_id = n.tenant_id AND access.conversation_id = n.conversation_id
                     AND access.staff_identity_id = :required_assignee AND access.ended_at IS NULL
               ))
             ORDER BY n.sequence_id ASC LIMIT ' . max(1, min(100, $limit))
        );
        $query->execute([
            ':tenant_id' => $tenantId,
            ':conversation' => $conversationId,
            ':after_id' => max(0, $afterId),
            ':assignment_required' => $requiredAssigneeId === null ? 0 : 1,
            ':required_assignee' => $requiredAssigneeId,
        ]);
        return $query->fetchAll();
    }

    public function createStaffReply(array $message, string $staffIdentityId, ?string $requestId = null, bool $requireAssignment = false, string $tenantId = '00000000-0000-4000-8000-000000000001'): array
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
            $id, $conversationId, $clientMessageId, $body, $staffIdentityId, $requestId, $requireAssignment, $tenantId
        ): array {
            $this->support->requireActiveStaff($staffIdentityId, $tenantId);
            $conversation = PdoSqlDialect::prepare($this->pdo, 
                'SELECT status FROM conversations WHERE tenant_id = :tenant_id AND id = :id FOR UPDATE'
            );
            $conversation->execute([':tenant_id' => $tenantId, ':id' => $conversationId]);
            if ($conversation->fetchColumn() !== 'open') {
                throw new RuntimeException('Cannot reply to a missing or closed conversation.');
            }
            if ($requireAssignment) $this->support->requireActiveAssignment($conversationId, $staffIdentityId, $tenantId);

            $existing = PdoSqlDialect::prepare($this->pdo, 
                'SELECT m.id, m.conversation_id, m.client_message_id, m.sender_type, m.body,
                        m.delivery_status, m.sent_at, m.sequence_id,
                        author.staff_identity_id AS staff_author_id
                 FROM messages m
                 LEFT JOIN staff_message_authors author ON author.tenant_id = m.tenant_id AND author.message_id = m.id
                 WHERE m.tenant_id = :tenant_id AND m.conversation_id = :conversation AND m.client_message_id = :client_id LIMIT 1'
            );
            $existing->execute([':tenant_id' => $tenantId, ':conversation' => $conversationId, ':client_id' => $clientMessageId]);
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

            $insert = PdoSqlDialect::prepare($this->pdo, 
                'INSERT INTO messages
                    (id, tenant_id, conversation_id, client_message_id, sender_type, body, delivery_status, sent_at, created_at)
                 VALUES (:id, :tenant_id, :conversation, :client_id, "business", :body, "web_only", UTC_TIMESTAMP(), UTC_TIMESTAMP())'
            );
            $insert->execute([
                ':id' => $id,
                ':tenant_id' => $tenantId,
                ':conversation' => $conversationId,
                ':client_id' => $clientMessageId,
                ':body' => $body,
            ]);
            $author = PdoSqlDialect::prepare($this->pdo, 
                'INSERT INTO staff_message_authors (tenant_id, message_id, staff_identity_id) VALUES (:tenant_id, :message, :staff)'
            );
            $author->execute([':tenant_id' => $tenantId, ':message' => $id, ':staff' => $staffIdentityId]);
            $activity = PdoSqlDialect::prepare($this->pdo, 
                'UPDATE conversations SET last_message_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE tenant_id = :tenant_id AND id = :id'
            );
            $activity->execute([':tenant_id' => $tenantId, ':id' => $conversationId]);
            $this->support->recordAudit($staffIdentityId, $conversationId, 'message.staff_reply_created', null, ['message_id' => $id], $requestId, $tenantId);

            $read = PdoSqlDialect::prepare($this->pdo, 
                'SELECT id, conversation_id, client_message_id, sender_type, body, delivery_status, sent_at, sequence_id
                 FROM messages WHERE tenant_id = :tenant_id AND id = :id'
            );
            $read->execute([':tenant_id' => $tenantId, ':id' => $id]);
            return ['message' => $read->fetch(), 'inserted' => true, 'transport' => 'web_only'];
        });
    }

    public function listAuditEvents(string $conversationId, int $afterId, int $limit, string $tenantId = '00000000-0000-4000-8000-000000000001'): array
    {
        $query = PdoSqlDialect::prepare($this->pdo, 
            'SELECT e.id, e.event_type, e.target_staff_id, e.metadata_json, e.request_id, e.created_at,
                    actor.display_name AS actor_name, target.display_name AS target_name
             FROM staff_audit_events e
             INNER JOIN staff_identities actor ON actor.tenant_id = e.tenant_id AND actor.id = e.actor_staff_id
             LEFT JOIN staff_identities target ON target.tenant_id = e.tenant_id AND target.id = e.target_staff_id
             WHERE e.tenant_id = :tenant_id AND e.conversation_id = :conversation AND e.id > :after_id
             ORDER BY e.id ASC LIMIT ' . max(1, min(100, $limit))
        );
        $query->execute([':tenant_id' => $tenantId, ':conversation' => $conversationId, ':after_id' => max(0, $afterId)]);
        return $query->fetchAll();
    }
}
