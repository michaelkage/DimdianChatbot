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

interface StaffIdentityRepository
{
    /** Upsert a verified external identity; never accept a browser-supplied subject. */
    public function upsertStaffIdentity(array $identity): string;
    /** @return array<string,mixed>|null */
    public function findStaffIdentityById(string $staffIdentityId, string $tenantId = '00000000-0000-4000-8000-000000000001'): ?array;
    /** Atomically consume a one-time authentication nonce until its expiry. */
    public function consumeStaffAuthNonce(string $nonceHash, string $expiresAtUtc, string $tenantId = '00000000-0000-4000-8000-000000000001'): bool;
    /** @return list<array<string,mixed>> */
    public function listStaffIdentities(string $tenantId = '00000000-0000-4000-8000-000000000001'): array;
    /** Owner-only access change; implementation must protect the last active owner. */
    public function updateStaffIdentityAccess(
        string $targetStaffId,
        string $actorStaffId,
        string $role,
        string $status,
        ?string $requestId = null,
        string $tenantId = '00000000-0000-4000-8000-000000000001'
    ): void;
}

interface StaffConversationRepository
{
    public function getActiveAssignee(string $conversationId, string $tenantId = '00000000-0000-4000-8000-000000000001'): ?string;
    /** @return list<array<string,mixed>> */
    public function listConversations(
        string $status,
        ?string $assignedStaffId,
        int $afterSequence,
        int $limit,
        bool $includeUnassigned = false,
        string $search = '',
        string $tenantId = '00000000-0000-4000-8000-000000000001'
    ): array;
    public function assignConversation(
        string $conversationId,
        string $staffIdentityId,
        string $actorStaffId,
        ?string $requestId = null,
        string $tenantId = '00000000-0000-4000-8000-000000000001'
    ): void;
    /** @return list<array<string,mixed>> */
    public function listAssignmentHistory(string $conversationId, int $afterId, int $limit, ?string $requiredAssigneeId = null, string $tenantId = '00000000-0000-4000-8000-000000000001'): array;
    public function unassignConversation(
        string $conversationId,
        string $actorStaffId,
        ?string $requestId = null,
        string $tenantId = '00000000-0000-4000-8000-000000000001'
    ): bool;
    public function selfAssignConversation(string $conversationId, string $staffIdentityId, ?string $requestId = null, string $tenantId = '00000000-0000-4000-8000-000000000001'): bool;
    public function unassignOwnConversation(string $conversationId, string $staffIdentityId, ?string $requestId = null, string $tenantId = '00000000-0000-4000-8000-000000000001'): bool;
}

interface StaffMessageRepository
{
    /** @return list<array<string,mixed>> */
    public function listConversationMessages(string $conversationId, int $afterSequence, int $limit, ?string $requiredAssigneeId = null, string $tenantId = '00000000-0000-4000-8000-000000000001'): array;
    /** Create a private note. Notes are immutable and never become customer messages. */
    public function addInternalNote(
        string $id,
        string $conversationId,
        string $authorStaffId,
        string $body,
        ?string $requestId = null,
        bool $requireAssignment = false,
        string $tenantId = '00000000-0000-4000-8000-000000000001'
    ): bool;
    /** @return list<array<string,mixed>> */
    public function listInternalNotes(string $conversationId, int $afterId, int $limit, ?string $requiredAssigneeId = null, string $tenantId = '00000000-0000-4000-8000-000000000001'): array;
    /**
     * Persist a website-visible business reply and its staff-author link atomically.
     * This operation does not enqueue WhatsApp delivery.
     */
    public function createStaffReply(array $message, string $staffIdentityId, ?string $requestId = null, bool $requireAssignment = false, string $tenantId = '00000000-0000-4000-8000-000000000001'): array;
    /** @return list<array<string,mixed>> */
    public function listAuditEvents(string $conversationId, int $afterId, int $limit, string $tenantId = '00000000-0000-4000-8000-000000000001'): array;
}

/** Backward-compatible aggregate persistence contract for the optional staff inbox. */
interface StaffInboxRepository extends StaffIdentityRepository, StaffConversationRepository, StaffMessageRepository
{
}
