<?php
declare(strict_types=1);

/** Shared transaction, staff-state and audit primitives for focused staff PDO adapters. */
final class PdoStaffPersistenceSupport
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function requireActiveStaff(string $staffIdentityId): void
    {
        $query = PdoSqlDialect::prepare($this->pdo, 
            'SELECT status FROM staff_identities WHERE id = :id LIMIT 1'
        );
        $query->execute([':id' => $staffIdentityId]);
        if ($query->fetchColumn() !== 'active') {
            throw new RuntimeException('Staff identity is missing or disabled.');
        }
    }

    /** Must be called after locking the parent conversation row in the same transaction. */
    public function requireActiveAssignment(string $conversationId, string $staffIdentityId): void
    {
        $query = PdoSqlDialect::prepare($this->pdo, 
            'SELECT 1 FROM staff_conversation_assignments
             WHERE conversation_id = :conversation AND staff_identity_id = :staff
               AND ended_at IS NULL LIMIT 1 FOR UPDATE'
        );
        $query->execute([':conversation' => $conversationId, ':staff' => $staffIdentityId]);
        if ($query->fetchColumn() === false) {
            throw new StaffInboxAssignmentException('Conversation is no longer assigned to this staff identity.');
        }
    }

    public function recordAudit(
        string $actorStaffId,
        ?string $conversationId,
        string $eventType,
        ?string $targetStaffId,
        array $metadata,
        ?string $requestId
    ): void {
        $query = PdoSqlDialect::prepare($this->pdo, 
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

    public function transaction(callable $operation): mixed
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

    public static function isUuid(string $value): bool
    {
        return (bool)preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $value);
    }

    public static function characterLength(string $value): int
    {
        $count = preg_match_all('/./us', $value);
        if ($count === false) throw new InvalidArgumentException('Text must be valid UTF-8.');
        return $count;
    }
}
