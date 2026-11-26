<?php
declare(strict_types=1);

/** MySQL/MariaDB persistence for staff conversation visibility and assignments. */
final class PdoStaffConversationRepository implements StaffConversationRepository
{
    private PDO $pdo;
    private PdoStaffPersistenceSupport $support;

    public function __construct(PDO $pdo, PdoStaffPersistenceSupport $support)
    {
        $this->pdo = $pdo;
        $this->support = $support;
    }

    public function getActiveAssignee(string $conversationId, string $tenantId = '00000000-0000-4000-8000-000000000001'): ?string
    {
        $query = PdoSqlDialect::prepare($this->pdo, 
            'SELECT staff_identity_id FROM staff_conversation_assignments
             WHERE tenant_id = :tenant_id AND conversation_id = :conversation AND ended_at IS NULL LIMIT 1'
        );
        $query->execute([':tenant_id' => $tenantId, ':conversation' => $conversationId]);
        $staffId = $query->fetchColumn();
        return is_string($staffId) ? $staffId : null;
    }

    public function listConversations(
        string $status,
        ?string $assignedStaffId,
        int $afterSequence,
        int $limit,
        bool $includeUnassigned = false,
        string $search = '',
        string $tenantId = '00000000-0000-4000-8000-000000000001'
    ): array {
        if (!in_array($status, ['open', 'closed', 'all'], true)) {
            throw new InvalidArgumentException('Unsupported conversation status filter.');
        }
        $limit = max(1, min(100, $limit));
        $search = trim($search);
        if (PdoStaffPersistenceSupport::characterLength($search) > 100) throw new InvalidArgumentException('Search text is too long.');
        $like = '%' . strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        $sql = 'SELECT c.id, c.status, c.created_at, c.updated_at, c.last_message_at,
                       g.display_name AS visitor_name, g.phone AS visitor_phone,
                       COALESCE(MAX(m.sequence_id), 0) AS latest_sequence,
                       a.staff_identity_id AS assigned_staff_id,
                       assigned.display_name AS assigned_staff_name
                FROM conversations c
                INNER JOIN guests g ON g.tenant_id = c.tenant_id AND g.id = c.guest_id
                LEFT JOIN messages m ON m.tenant_id = c.tenant_id AND m.conversation_id = c.id
                LEFT JOIN staff_conversation_assignments a
                    ON a.tenant_id = c.tenant_id AND a.conversation_id = c.id AND a.ended_at IS NULL
                LEFT JOIN staff_identities assigned ON assigned.tenant_id = a.tenant_id AND assigned.id = a.staff_identity_id
                WHERE c.tenant_id = :tenant_id AND (:status_all = 1 OR c.status = :status_value)
                  AND (:assigned_filter = 1 OR a.staff_identity_id = :assigned_staff OR (:include_unassigned = 1 AND a.staff_identity_id IS NULL))
                  AND (:search_empty = 1 OR g.display_name LIKE :search_name ESCAPE \'!\' OR g.phone LIKE :search_phone ESCAPE \'!\'
                       OR EXISTS (SELECT 1 FROM messages sm WHERE sm.tenant_id = c.tenant_id AND sm.conversation_id = c.id AND sm.body LIKE :search_message ESCAPE \'!\'))
                GROUP BY c.id, c.status, c.created_at, c.updated_at, c.last_message_at,
                         g.display_name, g.phone, a.staff_identity_id, assigned.display_name
                HAVING COALESCE(MAX(m.sequence_id), 0) > :after_sequence
                    OR (:after_sequence_empty = 0 AND COALESCE(MAX(m.sequence_id), 0) = 0)
                ORDER BY latest_sequence DESC, c.updated_at DESC
                LIMIT ' . $limit;
        $query = PdoSqlDialect::prepare($this->pdo, $sql);
        $query->execute([
            ':tenant_id' => $tenantId,
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
        ?string $requestId = null,
        string $tenantId = '00000000-0000-4000-8000-000000000001'
    ): void {
        $this->support->transaction(function () use ($conversationId, $staffIdentityId, $actorStaffId, $requestId, $tenantId): void {
            $conversation = PdoSqlDialect::prepare($this->pdo, 
                'SELECT status FROM conversations WHERE tenant_id = :tenant_id AND id = :id FOR UPDATE'
            );
            $conversation->execute([':tenant_id' => $tenantId, ':id' => $conversationId]);
            if ($conversation->fetchColumn() !== 'open') {
                throw new RuntimeException('Only an existing open conversation can be assigned.');
            }
            $this->support->requireActiveStaff($staffIdentityId, $tenantId);
            $this->support->requireActiveStaff($actorStaffId, $tenantId);

            $end = PdoSqlDialect::prepare($this->pdo, 
                'UPDATE staff_conversation_assignments
                 SET ended_at = UTC_TIMESTAMP(), active_conversation_id = NULL
                 WHERE tenant_id = :tenant_id AND conversation_id = :conversation AND ended_at IS NULL'
            );
            $end->execute([':tenant_id' => $tenantId, ':conversation' => $conversationId]);

            $insert = PdoSqlDialect::prepare($this->pdo, 
                'INSERT INTO staff_conversation_assignments
                    (tenant_id, conversation_id, staff_identity_id, assigned_by_staff_id, assigned_at, active_conversation_id)
                 VALUES (:tenant_id, :conversation_id, :staff, :actor, UTC_TIMESTAMP(), :active_conversation_id)'
            );
            $insert->execute([
                ':tenant_id' => $tenantId,
                ':tenant_id' => $tenantId,
                ':conversation_id' => $conversationId,
                ':active_conversation_id' => $conversationId,
                ':staff' => $staffIdentityId,
                ':actor' => $actorStaffId,
            ]);
            $this->support->recordAudit($actorStaffId, $conversationId, 'conversation.assigned', $staffIdentityId, [], $requestId, $tenantId);
        });
    }

    public function selfAssignConversation(string $conversationId, string $staffIdentityId, ?string $requestId = null, string $tenantId = '00000000-0000-4000-8000-000000000001'): bool
    {
        return $this->support->transaction(function () use ($conversationId, $staffIdentityId, $requestId, $tenantId): bool {
            $conversation = PdoSqlDialect::prepare($this->pdo, 
                'SELECT status FROM conversations WHERE tenant_id = :tenant_id AND id = :id FOR UPDATE'
            );
            $conversation->execute([':tenant_id' => $tenantId, ':id' => $conversationId]);
            if ($conversation->fetchColumn() !== 'open') {
                throw new RuntimeException('Only an existing open conversation can be self-assigned.');
            }
            $this->support->requireActiveStaff($staffIdentityId, $tenantId);

            $current = PdoSqlDialect::prepare($this->pdo, 
                'SELECT staff_identity_id FROM staff_conversation_assignments
                 WHERE tenant_id = :tenant_id AND conversation_id = :conversation AND ended_at IS NULL LIMIT 1 FOR UPDATE'
            );
            $current->execute([':tenant_id' => $tenantId, ':conversation' => $conversationId]);
            $assignee = $current->fetchColumn();
            if (is_string($assignee) && $assignee !== '') {
                return hash_equals($assignee, $staffIdentityId);
            }

            $insert = PdoSqlDialect::prepare($this->pdo, 
                'INSERT INTO staff_conversation_assignments
                    (tenant_id, conversation_id, staff_identity_id, assigned_by_staff_id, assigned_at, active_conversation_id)
                 VALUES (:tenant_id, :conversation_id, :staff, :actor, UTC_TIMESTAMP(), :active_conversation_id)'
            );
            $insert->execute([
                ':tenant_id' => $tenantId,
                ':conversation_id' => $conversationId,
                ':active_conversation_id' => $conversationId,
                ':staff' => $staffIdentityId,
                ':actor' => $staffIdentityId,
            ]);
            $this->support->recordAudit($staffIdentityId, $conversationId, 'conversation.assigned', $staffIdentityId, ['self_assigned' => true], $requestId, $tenantId);
            return true;
        });
    }

    public function listAssignmentHistory(string $conversationId, int $afterId, int $limit, ?string $requiredAssigneeId = null, string $tenantId = '00000000-0000-4000-8000-000000000001'): array
    {
        $query = PdoSqlDialect::prepare($this->pdo, 
            'SELECT a.id, a.staff_identity_id, a.assigned_by_staff_id, a.assigned_at, a.ended_at,
                    assignee.display_name AS assignee_name, assigner.display_name AS assigned_by_name
             FROM staff_conversation_assignments a
             INNER JOIN staff_identities assignee ON assignee.tenant_id = a.tenant_id AND assignee.id = a.staff_identity_id
             INNER JOIN staff_identities assigner ON assigner.tenant_id = a.tenant_id AND assigner.id = a.assigned_by_staff_id
             WHERE a.tenant_id = :tenant_id AND a.conversation_id = :conversation AND a.id > :after_id
               AND (:assignment_required = 0 OR EXISTS (
                   SELECT 1 FROM staff_conversation_assignments access
                   WHERE access.tenant_id = a.tenant_id AND access.conversation_id = a.conversation_id
                     AND access.staff_identity_id = :required_assignee AND access.ended_at IS NULL
               ))
             ORDER BY a.id ASC LIMIT ' . max(1, min(100, $limit))
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

    public function unassignConversation(
        string $conversationId,
        string $actorStaffId,
        ?string $requestId = null,
        string $tenantId = '00000000-0000-4000-8000-000000000001'
    ): bool {
        return $this->support->transaction(function () use ($conversationId, $actorStaffId, $requestId, $tenantId): bool {
            $this->support->requireActiveStaff($actorStaffId, $tenantId);
            $conversation = PdoSqlDialect::prepare($this->pdo, 'SELECT id FROM conversations WHERE tenant_id = :tenant_id AND id = :id FOR UPDATE');
            $conversation->execute([':tenant_id' => $tenantId, ':id' => $conversationId]);
            if ($conversation->fetchColumn() === false) return false;
            $query = PdoSqlDialect::prepare($this->pdo, 
                'UPDATE staff_conversation_assignments
                 SET ended_at = UTC_TIMESTAMP(), active_conversation_id = NULL
                 WHERE tenant_id = :tenant_id AND conversation_id = :conversation AND ended_at IS NULL'
            );
            $query->execute([':tenant_id' => $tenantId, ':conversation' => $conversationId]);
            if ($query->rowCount() !== 1) return false;
            $this->support->recordAudit($actorStaffId, $conversationId, 'conversation.unassigned', null, [], $requestId, $tenantId);
            return true;
        });
    }

    public function unassignOwnConversation(string $conversationId, string $staffIdentityId, ?string $requestId = null, string $tenantId = '00000000-0000-4000-8000-000000000001'): bool
    {
        return $this->support->transaction(function () use ($conversationId, $staffIdentityId, $requestId, $tenantId): bool {
            $conversation = PdoSqlDialect::prepare($this->pdo, 
                'SELECT id FROM conversations WHERE tenant_id = :tenant_id AND id = :id FOR UPDATE'
            );
            $conversation->execute([':tenant_id' => $tenantId, ':id' => $conversationId]);
            if ($conversation->fetchColumn() === false) return false;
            $this->support->requireActiveStaff($staffIdentityId, $tenantId);

            $query = PdoSqlDialect::prepare($this->pdo, 
                'UPDATE staff_conversation_assignments
                 SET ended_at = UTC_TIMESTAMP(), active_conversation_id = NULL
                 WHERE tenant_id = :tenant_id AND conversation_id = :conversation
                   AND staff_identity_id = :staff AND ended_at IS NULL'
            );
            $query->execute([':tenant_id' => $tenantId, ':conversation' => $conversationId, ':staff' => $staffIdentityId]);
            if ($query->rowCount() !== 1) return false;
            $this->support->recordAudit($staffIdentityId, $conversationId, 'conversation.unassigned', null, ['self_unassigned' => true], $requestId, $tenantId);
            return true;
        });
    }
}
