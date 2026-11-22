<?php
declare(strict_types=1);

/**
 * MySQL/MariaDB implementation of the optional staff inbox repository.
 *
 * This adapter assumes one business per database installation. It must not be
 * used as a shared multi-tenant adapter.
 */
final class PdoStaffInboxRepository implements StaffInboxRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function upsertStaffIdentity(array $identity): string
    {
        $id = (string)($identity['id'] ?? '');
        $provider = (string)($identity['auth_provider'] ?? '');
        $subject = (string)($identity['external_subject'] ?? '');
        $name = trim((string)($identity['display_name'] ?? ''));
        $email = isset($identity['email']) ? trim((string)$identity['email']) : null;

        if (!self::isUuid($id) || !preg_match('/^[a-z0-9._-]{1,40}$/i', $provider)
            || $subject === '' || strlen($subject) > 191 || $name === '' || strlen($name) > 100
            || ($email !== null && (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false))) {
            throw new InvalidArgumentException('Verified staff identity is incomplete or invalid.');
        }

        $query = $this->pdo->prepare(
            'INSERT INTO staff_identities
                (id, auth_provider, external_subject, display_name, email, role, status, created_at, updated_at)
             VALUES
                (:id, :provider, :subject, :name, :email, "agent", "active", UTC_TIMESTAMP(), UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                display_name = VALUES(display_name),
                email = VALUES(email),
                updated_at = UTC_TIMESTAMP()'
        );
        $query->execute([
            ':id' => $id,
            ':provider' => strtolower($provider),
            ':subject' => $subject,
            ':name' => $name,
            ':email' => $email,
        ]);
        $lookup = $this->pdo->prepare(
            'SELECT id FROM staff_identities WHERE auth_provider = :provider AND external_subject = :subject LIMIT 1'
        );
        $lookup->execute([':provider' => strtolower($provider), ':subject' => $subject]);
        $localId = $lookup->fetchColumn();
        if (!is_string($localId) || $localId === '') {
            throw new RuntimeException('Staff identity could not be resolved after synchronization.');
        }
        return $localId;
    }

    public function findStaffIdentityById(string $staffIdentityId): ?array
    {
        $query = $this->pdo->prepare(
            'SELECT id, auth_provider, external_subject, display_name, email, role, status
             FROM staff_identities WHERE id = :id LIMIT 1'
        );
        $query->execute([':id' => $staffIdentityId]);
        $identity = $query->fetch();
        return is_array($identity) ? $identity : null;
    }

    public function getActiveAssignee(string $conversationId): ?string
    {
        $query = $this->pdo->prepare(
            'SELECT staff_identity_id FROM staff_conversation_assignments
             WHERE conversation_id = :conversation AND ended_at IS NULL LIMIT 1'
        );
        $query->execute([':conversation' => $conversationId]);
        $staffId = $query->fetchColumn();
        return is_string($staffId) ? $staffId : null;
    }

    public function consumeStaffAuthNonce(string $nonceHash, string $expiresAtUtc): bool
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $nonceHash)) {
            throw new InvalidArgumentException('Staff authentication nonce hash is invalid.');
        }
        $query = $this->pdo->prepare(
            'INSERT IGNORE INTO staff_auth_nonces (nonce_sha256, expires_at, created_at)
             VALUES (:nonce, :expires, UTC_TIMESTAMP())'
        );
        $query->execute([':nonce' => $nonceHash, ':expires' => $expiresAtUtc]);
        return $query->rowCount() === 1;
    }

    public function listStaffIdentities(): array
    {
        $query = $this->pdo->query(
            'SELECT id, display_name, email, role, status
             FROM staff_identities
             ORDER BY status ASC, display_name ASC LIMIT 250'
        );
        return $query->fetchAll();
    }

    public function updateStaffIdentityAccess(
        string $targetStaffId,
        string $actorStaffId,
        string $role,
        string $status,
        ?string $requestId = null
    ): void {
        if (!in_array($role, ['owner', 'admin', 'agent'], true)
            || !in_array($status, ['active', 'disabled'], true)) {
            throw new InvalidArgumentException('Unsupported staff role or status.');
        }

        $this->transaction(function () use ($targetStaffId, $actorStaffId, $role, $status, $requestId): void {
            // Serialize owner changes so two concurrent requests cannot remove the final owner.
            $owners = $this->pdo->query(
                'SELECT id FROM staff_identities WHERE role = "owner" AND status = "active" ORDER BY id FOR UPDATE'
            )->fetchAll(PDO::FETCH_COLUMN);
            $actor = $this->pdo->prepare(
                'SELECT role, status FROM staff_identities WHERE id = :id LIMIT 1 FOR UPDATE'
            );
            $actor->execute([':id' => $actorStaffId]);
            $actorRow = $actor->fetch();
            if (!is_array($actorRow) || $actorRow['role'] !== 'owner' || $actorRow['status'] !== 'active') {
                throw new RuntimeException('Only an active owner may manage staff access.');
            }

            $target = $this->pdo->prepare(
                'SELECT role, status FROM staff_identities WHERE id = :id LIMIT 1 FOR UPDATE'
            );
            $target->execute([':id' => $targetStaffId]);
            $targetRow = $target->fetch();
            if (!is_array($targetRow)) {
                throw new InvalidArgumentException('The selected staff identity does not exist.');
            }
            if ($targetStaffId === $actorStaffId && ($role !== 'owner' || $status !== 'active')) {
                throw new RuntimeException('An owner cannot demote or disable their own account. Use another active owner account.');
            }

            $removingOwner = $targetRow['role'] === 'owner'
                && $targetRow['status'] === 'active'
                && ($role !== 'owner' || $status !== 'active');
            if ($removingOwner && count($owners) <= 1) {
                throw new RuntimeException('The last active owner cannot be demoted or disabled.');
            }

            $update = $this->pdo->prepare(
                'UPDATE staff_identities SET role = :role, status = :status, updated_at = UTC_TIMESTAMP()
                 WHERE id = :id'
            );
            $update->execute([':role' => $role, ':status' => $status, ':id' => $targetStaffId]);
            $this->recordAudit(
                $actorStaffId,
                null,
                'staff.access_updated',
                $targetStaffId,
                ['role' => $role, 'status' => $status],
                $requestId
            );
        });
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

    public function listConversations(
        string $status,
        ?string $assignedStaffId,
        int $afterSequence,
        int $limit,
        bool $includeUnassigned = false,
        string $search = ''
    ): array {
        if (!in_array($status, ['open', 'closed', 'all'], true)) {
            throw new InvalidArgumentException('Unsupported conversation status filter.');
        }
        $limit = max(1, min(100, $limit));
        $search = trim($search);
        if (self::characterLength($search) > 100) throw new InvalidArgumentException('Search text is too long.');
        $like = '%' . strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        $sql = 'SELECT c.id, c.status, c.created_at, c.updated_at, c.last_message_at,
                       g.display_name AS visitor_name, g.phone AS visitor_phone,
                       COALESCE(MAX(m.sequence_id), 0) AS latest_sequence,
                       a.staff_identity_id AS assigned_staff_id,
                       assigned.display_name AS assigned_staff_name
                FROM conversations c
                INNER JOIN guests g ON g.id = c.guest_id
                LEFT JOIN messages m ON m.conversation_id = c.id
                LEFT JOIN staff_conversation_assignments a
                    ON a.conversation_id = c.id AND a.ended_at IS NULL
                LEFT JOIN staff_identities assigned ON assigned.id = a.staff_identity_id
                WHERE (:status_all = 1 OR c.status = :status_value)
                  AND (:assigned_filter = 1 OR a.staff_identity_id = :assigned_staff OR (:include_unassigned = 1 AND a.staff_identity_id IS NULL))
                  AND (:search_empty = 1 OR g.display_name LIKE :search_name ESCAPE '!' OR g.phone LIKE :search_phone ESCAPE '!'
                       OR EXISTS (SELECT 1 FROM messages sm WHERE sm.conversation_id = c.id AND sm.body LIKE :search_message ESCAPE '!'))
                GROUP BY c.id, c.status, c.created_at, c.updated_at, c.last_message_at,
                         g.display_name, g.phone, a.staff_identity_id, assigned.display_name
                HAVING COALESCE(MAX(m.sequence_id), 0) > :after_sequence
                    OR (:after_sequence_empty = 0 AND COALESCE(MAX(m.sequence_id), 0) = 0)
                ORDER BY latest_sequence DESC, c.updated_at DESC
                LIMIT ' . $limit;
        $query = $this->pdo->prepare($sql);
        $query->execute([
            ':status_all' => $status === 'all' ? 1 : 0,
            ':status_value' => $status,
            ':assigned_filter' => $assignedStaffId === null ? 1 : 0,
            ':assigned_staff' => $assignedStaffId,
            ':include_unassigned' => $includeUnassigned ? 1 : 0,
            ':search_empty' => $search === '' ? 1 : 0,
            ':search_name' => $like,
            ':search_phone' => $like,
            ':search_message' => $like,
            ':after_sequence' => max(0, $afterSequence),
            ':after_sequence_empty' => max(0, $afterSequence),
        ]);
        return $query->fetchAll();
    }

    public function assignConversation(
        string $conversationId,
        string $staffIdentityId,
        string $actorStaffId,
        ?string $requestId = null
    ): void {
        $this->transaction(function () use ($conversationId, $staffIdentityId, $actorStaffId, $requestId): void {
            $conversation = $this->pdo->prepare(
                'SELECT status FROM conversations WHERE id = :id FOR UPDATE'
            );
            $conversation->execute([':id' => $conversationId]);
            if ($conversation->fetchColumn() !== 'open') {
                throw new RuntimeException('Only an existing open conversation can be assigned.');
            }
            $this->requireActiveStaff($staffIdentityId);
            $this->requireActiveStaff($actorStaffId);

            $end = $this->pdo->prepare(
                'UPDATE staff_conversation_assignments
                 SET ended_at = UTC_TIMESTAMP(), active_conversation_id = NULL
                 WHERE conversation_id = :conversation AND ended_at IS NULL'
            );
            $end->execute([':conversation' => $conversationId]);

            $insert = $this->pdo->prepare(
                'INSERT INTO staff_conversation_assignments
                    (conversation_id, staff_identity_id, assigned_by_staff_id, assigned_at, active_conversation_id)
                 VALUES (:conversation, :staff, :actor, UTC_TIMESTAMP(), :conversation)'
            );
            $insert->execute([
                ':conversation' => $conversationId,
                ':staff' => $staffIdentityId,
                ':actor' => $actorStaffId,
            ]);
            $this->recordAudit($actorStaffId, $conversationId, 'conversation.assigned', $staffIdentityId, [], $requestId);
        });
    }

    public function selfAssignConversation(string $conversationId, string $staffIdentityId, ?string $requestId = null): bool
    {
        return $this->transaction(function () use ($conversationId, $staffIdentityId, $requestId): bool {
            $conversation = $this->pdo->prepare(
                'SELECT status FROM conversations WHERE id = :id FOR UPDATE'
            );
            $conversation->execute([':id' => $conversationId]);
            if ($conversation->fetchColumn() !== 'open') {
                throw new RuntimeException('Only an existing open conversation can be self-assigned.');
            }
            $this->requireActiveStaff($staffIdentityId);

            $current = $this->pdo->prepare(
                'SELECT staff_identity_id FROM staff_conversation_assignments
                 WHERE conversation_id = :conversation AND ended_at IS NULL LIMIT 1 FOR UPDATE'
            );
            $current->execute([':conversation' => $conversationId]);
            $assignee = $current->fetchColumn();
            if (is_string($assignee) && $assignee !== '') {
                return hash_equals($assignee, $staffIdentityId);
            }

            $insert = $this->pdo->prepare(
                'INSERT INTO staff_conversation_assignments
                    (conversation_id, staff_identity_id, assigned_by_staff_id, assigned_at, active_conversation_id)
                 VALUES (:conversation, :staff, :actor, UTC_TIMESTAMP(), :conversation)'
            );
            $insert->execute([
                ':conversation' => $conversationId,
                ':staff' => $staffIdentityId,
                ':actor' => $staffIdentityId,
            ]);
            $this->recordAudit($staffIdentityId, $conversationId, 'conversation.assigned', $staffIdentityId, ['self_assigned' => true], $requestId);
            return true;
        });
    }

    public function listAssignmentHistory(string $conversationId, int $afterId, int $limit, ?string $requiredAssigneeId = null): array
    {
        $query = $this->pdo->prepare(
            'SELECT a.id, a.staff_identity_id, a.assigned_by_staff_id, a.assigned_at, a.ended_at,
                    assignee.display_name AS assignee_name, assigner.display_name AS assigned_by_name
             FROM staff_conversation_assignments a
             INNER JOIN staff_identities assignee ON assignee.id = a.staff_identity_id
             INNER JOIN staff_identities assigner ON assigner.id = a.assigned_by_staff_id
             WHERE a.conversation_id = :conversation AND a.id > :after_id
               AND (:assignment_required = 0 OR EXISTS (
                   SELECT 1 FROM staff_conversation_assignments access
                   WHERE access.conversation_id = a.conversation_id
                     AND access.staff_identity_id = :required_assignee AND access.ended_at IS NULL
               ))
             ORDER BY a.id ASC LIMIT ' . max(1, min(100, $limit))
        );
        $query->execute([
            ':conversation' => $conversationId,
            ':after_id' => max(0, $afterId),
            ':assignment_required' => $requiredAssigneeId === null ? 0 : 1,
            ':required_assignee' => $requiredAssigneeId,
        ]);
        return $query->fetchAll();
    }

    public function unassignConversation(
        string $conversationId,
        string $actorStaffId,
        ?string $requestId = null
    ): bool {
        return $this->transaction(function () use ($conversationId, $actorStaffId, $requestId): bool {
            $this->requireActiveStaff($actorStaffId);
            $conversation = $this->pdo->prepare('SELECT id FROM conversations WHERE id = :id FOR UPDATE');
            $conversation->execute([':id' => $conversationId]);
            if ($conversation->fetchColumn() === false) return false;
            $query = $this->pdo->prepare(
                'UPDATE staff_conversation_assignments
                 SET ended_at = UTC_TIMESTAMP(), active_conversation_id = NULL
                 WHERE conversation_id = :conversation AND ended_at IS NULL'
            );
            $query->execute([':conversation' => $conversationId]);
            if ($query->rowCount() !== 1) return false;
            $this->recordAudit($actorStaffId, $conversationId, 'conversation.unassigned', null, [], $requestId);
            return true;
        });
    }

    public function unassignOwnConversation(string $conversationId, string $staffIdentityId, ?string $requestId = null): bool
    {
        return $this->transaction(function () use ($conversationId, $staffIdentityId, $requestId): bool {
            $conversation = $this->pdo->prepare(
                'SELECT id FROM conversations WHERE id = :id FOR UPDATE'
            );
            $conversation->execute([':id' => $conversationId]);
            if ($conversation->fetchColumn() === false) return false;
            $this->requireActiveStaff($staffIdentityId);

            $query = $this->pdo->prepare(
                'UPDATE staff_conversation_assignments
                 SET ended_at = UTC_TIMESTAMP(), active_conversation_id = NULL
                 WHERE conversation_id = :conversation
                   AND staff_identity_id = :staff AND ended_at IS NULL'
            );
            $query->execute([':conversation' => $conversationId, ':staff' => $staffIdentityId]);
            if ($query->rowCount() !== 1) return false;
            $this->recordAudit($staffIdentityId, $conversationId, 'conversation.unassigned', null, ['self_unassigned' => true], $requestId);
            return true;
        });
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
        if (!self::isUuid($id) || $body === '' || self::characterLength($body) > 10000) {
            throw new InvalidArgumentException('Internal note ID or body is invalid.');
        }

        return $this->transaction(function () use ($id, $conversationId, $authorStaffId, $body, $requestId, $requireAssignment): bool {
            $this->requireActiveStaff($authorStaffId);
            $conversation = $this->pdo->prepare('SELECT id FROM conversations WHERE id = :id FOR UPDATE');
            $conversation->execute([':id' => $conversationId]);
            if ($conversation->fetchColumn() === false) throw new RuntimeException('Conversation does not exist.');
            if ($requireAssignment) $this->requireActiveAssignment($conversationId, $authorStaffId);
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
            $this->recordAudit($authorStaffId, $conversationId, 'note.created', null, ['note_id' => $id], $requestId);
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
        if (!self::isUuid($id) || !self::isUuid($clientMessageId) || $body === ''
            || self::characterLength($body) > 5000) {
            throw new InvalidArgumentException('Staff reply ID or body is invalid.');
        }

        return $this->transaction(function () use (
            $id, $conversationId, $clientMessageId, $body, $staffIdentityId, $requestId, $requireAssignment
        ): array {
            $this->requireActiveStaff($staffIdentityId);
            $conversation = $this->pdo->prepare(
                'SELECT status FROM conversations WHERE id = :id FOR UPDATE'
            );
            $conversation->execute([':id' => $conversationId]);
            if ($conversation->fetchColumn() !== 'open') {
                throw new RuntimeException('Cannot reply to a missing or closed conversation.');
            }
            if ($requireAssignment) $this->requireActiveAssignment($conversationId, $staffIdentityId);

            $existing = $this->pdo->prepare(
                'SELECT id, conversation_id, client_message_id, sender_type, body, delivery_status, sent_at, sequence_id
                 FROM messages WHERE conversation_id = :conversation AND client_message_id = :client_id LIMIT 1'
            );
            $existing->execute([':conversation' => $conversationId, ':client_id' => $clientMessageId]);
            $prior = $existing->fetch();
            if (is_array($prior)) {
                if ($prior['sender_type'] !== 'business' || !hash_equals((string)$prior['body'], $body)) {
                    throw new ChatStorageConflict('The client message ID was already used for different content.');
                }
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
            $this->recordAudit($staffIdentityId, $conversationId, 'message.staff_reply_created', null, ['message_id' => $id], $requestId);

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

    private function requireActiveStaff(string $staffIdentityId): void
    {
        $query = $this->pdo->prepare(
            'SELECT status FROM staff_identities WHERE id = :id LIMIT 1'
        );
        $query->execute([':id' => $staffIdentityId]);
        if ($query->fetchColumn() !== 'active') {
            throw new RuntimeException('Staff identity is missing or disabled.');
        }
    }

    /** Must be called after locking the parent conversation row in the same transaction. */
    private function requireActiveAssignment(string $conversationId, string $staffIdentityId): void
    {
        $query = $this->pdo->prepare(
            'SELECT 1 FROM staff_conversation_assignments
             WHERE conversation_id = :conversation AND staff_identity_id = :staff
               AND ended_at IS NULL LIMIT 1 FOR UPDATE'
        );
        $query->execute([':conversation' => $conversationId, ':staff' => $staffIdentityId]);
        if ($query->fetchColumn() === false) {
            throw new StaffInboxAssignmentException('Conversation is no longer assigned to this staff identity.');
        }
    }

    private function recordAudit(
        string $actorStaffId,
        ?string $conversationId,
        string $eventType,
        ?string $targetStaffId,
        array $metadata,
        ?string $requestId
    ): void {
        $query = $this->pdo->prepare(
            'INSERT INTO staff_audit_events
                (actor_staff_id, conversation_id, event_type, target_staff_id, metadata_json, request_id, created_at)
             VALUES (:actor, :conversation, :event, :target, :metadata, :request_id, UTC_TIMESTAMP())'
        );
        $query->execute([
            ':actor' => $actorStaffId,
            ':conversation' => $conversationId,
            ':event' => $eventType,
            ':target' => $targetStaffId,
            ':metadata' => $metadata === [] ? null : json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ':request_id' => $requestId,
        ]);
    }

    private function transaction(callable $operation): mixed
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) $this->pdo->beginTransaction();
        try {
            $result = $operation();
            if ($ownsTransaction) $this->pdo->commit();
            return $result;
        } catch (Throwable $error) {
            if ($ownsTransaction && $this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    private static function isUuid(string $value): bool
    {
        return (bool)preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $value);
    }

    private static function characterLength(string $value): int
    {
        $count = preg_match_all('/./us', $value);
        if ($count === false) throw new InvalidArgumentException('Text must be valid UTF-8.');
        return $count;
    }
}
