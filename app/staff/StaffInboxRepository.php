<?php
declare(strict_types=1);

/**
 * Optional persistence boundary for the staff inbox.
 *
 * This contract is loaded only when the optional staff inbox module is enabled.
 * Authentication and authorization are application concerns: callers must pass
 * a staff identity resolved by a trusted authentication adapter and must enforce
 * role/permission policy before invoking mutating operations.
 */
final class StaffInboxAssignmentException extends RuntimeException
{
}

interface StaffInboxRepository
{
    /** Upsert a verified external identity; never accept a browser-supplied subject. */
    public function upsertStaffIdentity(array $identity): string;

    /** @return array<string,mixed>|null */
    public function findStaffIdentityById(string $staffIdentityId): ?array;

    public function getActiveAssignee(string $conversationId): ?string;

    /** Atomically consume a one-time authentication nonce until its expiry. */
    public function consumeStaffAuthNonce(string $nonceHash, string $expiresAtUtc): bool;

    /**
     * List conversations for the staff inbox.
     * Status is one of open, closed or all; cursor is a message sequence cursor.
     */
    /** @return list<array<string,mixed>> */
    public function listStaffIdentities(): array;

    /** Owner-only access change; implementation must protect the last active owner. */
    public function updateStaffIdentityAccess(
        string $targetStaffId,
        string $actorStaffId,
        string $role,
        string $status,
        ?string $requestId = null
    ): void;

    /** @return list<array<string,mixed>> */
    public function listConversationMessages(string $conversationId, int $afterSequence, int $limit, ?string $requiredAssigneeId = null): array;

    public function listConversations(
        string $status,
        ?string $assignedStaffId,
        int $afterSequence,
        int $limit,
        bool $includeUnassigned = false,
        string $search = ''
    ): array;

    /** Assign an open conversation, replacing its prior active assignment atomically. */
    public function assignConversation(
        string $conversationId,
        string $staffIdentityId,
        string $actorStaffId,
        ?string $requestId = null
    ): void;

    /** @return list<array<string,mixed>> */
    public function listAssignmentHistory(string $conversationId, int $afterId, int $limit, ?string $requiredAssigneeId = null): array;

    /** End the active assignment, retaining assignment history. */
    public function unassignConversation(
        string $conversationId,
        string $actorStaffId,
        ?string $requestId = null
    ): bool;

    /** Atomically assign an unassigned conversation to the current agent only. */
    public function selfAssignConversation(string $conversationId, string $staffIdentityId, ?string $requestId = null): bool;

    /** Atomically end an assignment only when the current agent still owns it. */
    public function unassignOwnConversation(string $conversationId, string $staffIdentityId, ?string $requestId = null): bool;

    /** Create a private note. Notes are immutable and never become customer messages. */
    public function addInternalNote(
        string $id,
        string $conversationId,
        string $authorStaffId,
        string $body,
        ?string $requestId = null,
        bool $requireAssignment = false
    ): void;

    /** @return list<array<string,mixed>> */
    public function listInternalNotes(string $conversationId, int $afterId, int $limit, ?string $requiredAssigneeId = null): array;

    /**
     * Persist a website-visible business reply and its staff-author link atomically.
     * This operation does not enqueue WhatsApp delivery.
     */
    public function createStaffReply(array $message, string $staffIdentityId, ?string $requestId = null, bool $requireAssignment = false): array;

    /** @return list<array<string,mixed>> */
    public function listAuditEvents(string $conversationId, int $afterId, int $limit): array;
}
