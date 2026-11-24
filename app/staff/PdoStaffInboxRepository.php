<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/storage/PdoSqlDialect.php';
require_once __DIR__ . '/StaffInboxRepository.php';
require_once __DIR__ . '/PdoStaffPersistenceSupport.php';
require_once __DIR__ . '/PdoStaffIdentityRepository.php';
require_once __DIR__ . '/PdoStaffConversationRepository.php';
require_once __DIR__ . '/PdoStaffMessageRepository.php';

/**
 * Backward-compatible composition adapter for the optional staff inbox.
 *
 * Focused PDO adapters own SQL and transactions. All adapters share one PDO
 * connection and one persistence-support instance so multi-step operations
 * retain their original locking and audit transaction boundaries.
 */
final class PdoStaffInboxRepository implements StaffInboxRepository
{
    private PdoStaffIdentityRepository $identities;
    private PdoStaffConversationRepository $conversations;
    private PdoStaffMessageRepository $messages;

    public function __construct(PDO $pdo)
    {
        $support = new PdoStaffPersistenceSupport($pdo);
        $this->identities = new PdoStaffIdentityRepository($pdo, $support);
        $this->conversations = new PdoStaffConversationRepository($pdo, $support);
        $this->messages = new PdoStaffMessageRepository($pdo, $support);
    }

    public function upsertStaffIdentity(array $identity): string
    {
        return $this->identities->upsertStaffIdentity($identity);
    }

    public function findStaffIdentityById(string $staffIdentityId): ?array
    {
        return $this->identities->findStaffIdentityById($staffIdentityId);
    }

    public function getActiveAssignee(string $conversationId): ?string
    {
        return $this->conversations->getActiveAssignee($conversationId);
    }

    public function consumeStaffAuthNonce(string $nonceHash, string $expiresAtUtc): bool
    {
        return $this->identities->consumeStaffAuthNonce($nonceHash, $expiresAtUtc);
    }

    public function listStaffIdentities(): array
    {
        return $this->identities->listStaffIdentities();
    }

    public function updateStaffIdentityAccess(
        string $targetStaffId,
        string $actorStaffId,
        string $role,
        string $status,
        ?string $requestId = null
    ): void {
        $this->identities->updateStaffIdentityAccess($targetStaffId, $actorStaffId, $role, $status, $requestId);
    }

    public function listConversationMessages(
        string $conversationId,
        int $afterSequence,
        int $limit,
        ?string $requiredAssigneeId = null
    ): array {
        return $this->messages->listConversationMessages($conversationId, $afterSequence, $limit, $requiredAssigneeId);
    }

    public function listConversations(
        string $status,
        ?string $assignedStaffId,
        int $afterSequence,
        int $limit,
        bool $includeUnassigned = false,
        string $search = ''
    ): array {
        return $this->conversations->listConversations(
            $status,
            $assignedStaffId,
            $afterSequence,
            $limit,
            $includeUnassigned,
            $search
        );
    }

    public function assignConversation(
        string $conversationId,
        string $staffIdentityId,
        string $actorStaffId,
        ?string $requestId = null
    ): void {
        $this->conversations->assignConversation($conversationId, $staffIdentityId, $actorStaffId, $requestId);
    }

    public function listAssignmentHistory(
        string $conversationId,
        int $afterId,
        int $limit,
        ?string $requiredAssigneeId = null
    ): array {
        return $this->conversations->listAssignmentHistory($conversationId, $afterId, $limit, $requiredAssigneeId);
    }

    public function unassignConversation(
        string $conversationId,
        string $actorStaffId,
        ?string $requestId = null
    ): bool {
        return $this->conversations->unassignConversation($conversationId, $actorStaffId, $requestId);
    }

    public function selfAssignConversation(
        string $conversationId,
        string $staffIdentityId,
        ?string $requestId = null
    ): bool {
        return $this->conversations->selfAssignConversation($conversationId, $staffIdentityId, $requestId);
    }

    public function unassignOwnConversation(
        string $conversationId,
        string $staffIdentityId,
        ?string $requestId = null
    ): bool {
        return $this->conversations->unassignOwnConversation($conversationId, $staffIdentityId, $requestId);
    }

    public function addInternalNote(
        string $id,
        string $conversationId,
        string $authorStaffId,
        string $body,
        ?string $requestId = null,
        bool $requireAssignment = false
    ): bool {
        return $this->messages->addInternalNote(
            $id,
            $conversationId,
            $authorStaffId,
            $body,
            $requestId,
            $requireAssignment
        );
    }

    public function listInternalNotes(
        string $conversationId,
        int $afterId,
        int $limit,
        ?string $requiredAssigneeId = null
    ): array {
        return $this->messages->listInternalNotes($conversationId, $afterId, $limit, $requiredAssigneeId);
    }

    public function createStaffReply(
        array $message,
        string $staffIdentityId,
        ?string $requestId = null,
        bool $requireAssignment = false
    ): array {
        return $this->messages->createStaffReply($message, $staffIdentityId, $requestId, $requireAssignment);
    }

    public function listAuditEvents(string $conversationId, int $afterId, int $limit): array
    {
        return $this->messages->listAuditEvents($conversationId, $afterId, $limit);
    }
}
