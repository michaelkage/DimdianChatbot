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
interface StaffInboxRepository
{
    /** Upsert a verified external identity; never accept a browser-supplied subject. */
    public function upsertStaffIdentity(array $identity): void;

    /**
     * List conversations for the staff inbox.
     * Status is one of open, closed or all; cursor is a message sequence cursor.
     */
    public function listConversations(
        string $status,
        ?string $assignedStaffId,
        int $afterSequence,
        int $limit
    ): array;

    /** Assign an open conversation, replacing its prior active assignment atomically. */
    public function assignConversation(
        string $conversationId,
        string $staffIdentityId,
        string $actorStaffId,
        ?string $requestId = null
    ): void;

    /** End the active assignment, retaining assignment history. */
    public function unassignConversation(
        string $conversationId,
        string $actorStaffId,
        ?string $requestId = null
    ): bool;

    /** Create a private note. Notes are immutable and never become customer messages. */
    public function addInternalNote(
        string $id,
        string $conversationId,
        string $authorStaffId,
        string $body,
        ?string $requestId = null
    ): void;

    /** @return list<array<string,mixed>> */
    public function listInternalNotes(string $conversationId, int $afterId, int $limit): array;

    /**
     * Persist a website-visible business reply and its staff-author link atomically.
     * This operation does not enqueue WhatsApp delivery.
     */
    public function createStaffReply(array $message, string $staffIdentityId, ?string $requestId = null): array;

    /** @return list<array<string,mixed>> */
    public function listAuditEvents(string $conversationId, int $afterId, int $limit): array;
}
