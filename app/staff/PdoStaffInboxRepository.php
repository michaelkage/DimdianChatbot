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

    public function findStaffIdentityById(string $staffIdentityId, string $tenantId = '00000000-0000-4000-8000-000000000001'): ?array
    {
        return $this->identities->findStaffIdentityById($staffIdentityId, $tenantId);
    }

    public function getActiveAssignee(string $conversationId, string $tenantId = '00000000-0000-4000-8000-000000000001'): ?string
    {
        return $this->conversations->getActiveAssignee($conversationId, $tenantId);
    }

    public function consumeStaffAuthNonce(string $nonceHash, string $expiresAtUtc, string $tenantId = '00000000-0000-4000-8000-000000000001'): bool
    {
        return $this->identities->consumeStaffAuthNonce($nonceHash, $expiresAtUtc, $tenantId);
    }

    public function listStaffIdentities(string $tenantId = '00000000-0000-4000-8000-000000000001'): array
    {
        return $this->identities->listStaffIdentities($tenantId);
    }

    public function updateStaffIdentityAccess(
        string $targetStaffId,
        string $actorStaffId,
        string $role,
        string $status,
        ?string $requestId = null,
        string $tenantId = '00000000-0000-4000-8000-000000000001'
    ): void {
        $this->identities->updateStaffIdentityAccess($targetStaffId, $actorStaffId, $role, $status, $requestId, $tenantId);
    }

    public function listConversationMessages(
        string $conversationId,
        int $afterSequence,
        int $limit,
        ?string $requiredAssigneeId = null,
        string $tenantId = '00000000-0000-4000-8000-000000000001'
    ): array {
        return $this->messages->listConversationMessages($conversationId, $afterSequence, $limit, $requiredAssigneeId, $tenantId);
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
        return $this->conversations->listConversations(
            $status,
            $assignedStaffId,
            $afterSequence,
            $limit,
            $includeUnassigned,
            $search,
            $tenantId
        );
    }

    public function assignConversation(
        string $conversationId,
        string $staffIdentityId,
        string $actorStaffId,
        ?string $requestId = null,
        string $tenantId = '00000000-0000-4000-8000-000000000001'
    ): void {
        $this->conversations->assignConversation($conversationId, $staffIdentityId, $actorStaffId, $requestId, $tenantId);
    }

    public function listAssignmentHistory(
        string $conversationId,
        int $afterId,
        int $limit,
        ?string $requiredAssigneeId = null,
        string $tenantId = '00000000-0000-4000-8000-000000000001'
    ): array {
        return $this->conversations->listAssignmentHistory($conversationId, $afterId, $limit, $requiredAssigneeId, $tenantId);
    }

    public function unassignConversation(
        string $conversationId,
        string $actorStaffId,
        ?string $requestId = null,
        string $tenantId = '00000000-0000-4000-8000-000000000001'
    ): bool {
        return $this->conversations->unassignConversation($conversationId, $actorStaffId, $requestId, $tenantId);
    }

    public function selfAssignConversation(
        string $conversationId,
        string $staffIdentityId,
        ?string $requestId = null,
        string $tenantId = '00000000-0000-4000-8000-000000000001'
    ): bool {
        return $this->conversations->selfAssignConversation($conversationId, $staffIdentityId, $requestId, $tenantId);
    }

    public function unassignOwnConversation(
        string $conversationId,
        string $staffIdentityId,
        ?string $requestId = null,
        string $tenantId = '00000000-0000-4000-8000-000000000001'
    ): bool {
        return $this->conversations->unassignOwnConversation($conversationId, $staffIdentityId, $requestId, $tenantId);
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
        return $this->messages->addInternalNote(
            $id,
            $conversationId,
            $authorStaffId,
            $body,
            $requestId,
            $requireAssignment,
            $tenantId
        );
    }

    public function listInternalNotes(
        string $conversationId,
        int $afterId,
        int $limit,
        ?string $requiredAssigneeId = null,
        string $tenantId = '00000000-0000-4000-8000-000000000001'
    ): array {
        return $this->messages->listInternalNotes($conversationId, $afterId, $limit, $requiredAssigneeId, $tenantId);
    }

    public function createStaffReply(
        array $message,
        string $staffIdentityId,
        ?string $requestId = null,
        bool $requireAssignment = false,
        string $tenantId = '00000000-0000-4000-8000-000000000001'
    ): array {
        return $this->messages->createStaffReply($message, $staffIdentityId, $requestId, $requireAssignment, $tenantId);
    }

    public function listAuditEvents(string $conversationId, int $afterId, int $limit, string $tenantId = '00000000-0000-4000-8000-000000000001'): array
    {
        return $this->messages->listAuditEvents($conversationId, $afterId, $limit, $tenantId);
    }
}
