<?php
declare(strict_types=1);

final class StaffInboxAuthorizationException extends RuntimeException
{
}

/**
 * Application policy boundary for staff operations.
 *
 * Roles are read from local staff_identities storage. Roles or permissions
 * returned by an external identity provider are deliberately ignored.
 */
final class StaffInboxService
{
    private StaffIdentityService $identities;
    private StaffInboxRepository $repository;

    public function __construct(
        StaffIdentityProvider $identityProvider,
        StaffInboxRepository $repository
    ) {
        $this->identities = new StaffIdentityService($identityProvider, $repository);
        $this->repository = $repository;
    }

    /** @return array<string,mixed> */
    public function authenticate(array $server): array
    {
        return $this->identities->authenticate($server);
    }

    /** @return array{id:string,role:string,display_name:string} */
    public function currentIdentity(array $server): array
    {
        $actor = $this->authenticate($server);
        return [
            'id' => (string)$actor['id'],
            'role' => (string)$actor['role'],
            'display_name' => (string)$actor['display_name'],
        ];
    }

    /** @return list<array<string,mixed>> */
    public function listConversations(array $server, string $status, int $afterSequence, int $limit, string $search = ''): array
    {
        $actor = $this->authenticate($server);
        $assignedTo = self::isAdministrator($actor) ? null : (string)$actor['id'];
        return $this->repository->listConversations($status, $assignedTo, $afterSequence, $limit, !self::isAdministrator($actor), $search);
    }

    public function assignConversation(
        array $server,
        string $conversationId,
        string $targetStaffId,
        ?string $requestId = null
    ): void {
        $actor = $this->authenticate($server);
        if (!self::isAdministrator($actor)) {
            if ($targetStaffId !== (string)$actor['id']) {
                throw new StaffInboxAuthorizationException('Agents may only assign conversations to themselves.');
            }
            if (!$this->repository->selfAssignConversation($conversationId, (string)$actor['id'], $requestId)) {
                throw new StaffInboxAuthorizationException('This conversation is already assigned to another agent.');
            }
            return;
        }
        $this->repository->assignConversation($conversationId, $targetStaffId, (string)$actor['id'], $requestId);
    }

    public function unassignConversation(
        array $server,
        string $conversationId,
        ?string $requestId = null
    ): bool {
        $actor = $this->authenticate($server);
        if (!self::isAdministrator($actor)) {
            if (!$this->repository->unassignOwnConversation($conversationId, (string)$actor['id'], $requestId)) {
                throw new StaffInboxAuthorizationException('Agents may only unassign their own active conversations.');
            }
            return true;
        }
        return $this->repository->unassignConversation($conversationId, (string)$actor['id'], $requestId);
    }

    /** Update the local role and status of a staff identity; owner-only. */
    public function updateStaffIdentityAccess(
        array $server,
        string $targetStaffId,
        string $role,
        string $status,
        ?string $requestId = null
    ): void {
        $actor = $this->authenticate($server);
        if (($actor['role'] ?? '') !== 'owner') {
            throw new StaffInboxAuthorizationException('Only the owner can manage staff roles and access.');
        }
        $this->repository->updateStaffIdentityAccess(
            $targetStaffId, (string)$actor['id'], $role, $status, $requestId
        );
    }

    public function listStaffIdentities(array $server): array
    {
        $actor = $this->authenticate($server);
        if (!self::isAdministrator($actor)) {
            throw new StaffInboxAuthorizationException('Only administrators can list staff identities.');
        }
        return $this->repository->listStaffIdentities();
    }

    /** @return array<string,mixed> */
    public function listConversationMessages(array $server, string $conversationId, int $afterSequence, int $limit): array
    {
        $actor = $this->authenticate($server);
        $requiredAssignee = self::isAdministrator($actor) ? null : (string)$actor['id'];
        if ($requiredAssignee !== null && $this->repository->getActiveAssignee($conversationId) !== $requiredAssignee) {
            throw new StaffInboxAuthorizationException('This conversation is not assigned to the current agent.');
        }
        return $this->repository->listConversationMessages($conversationId, $afterSequence, $limit, $requiredAssignee);
    }

    /** @return list<array<string,mixed>> */
    public function listAssignmentHistory(array $server, string $conversationId, int $afterId, int $limit): array
    {
        $actor = $this->authenticate($server);
        $requiredAssignee = self::isAdministrator($actor) ? null : (string)$actor['id'];
        if ($requiredAssignee !== null && $this->repository->getActiveAssignee($conversationId) !== $requiredAssignee) {
            throw new StaffInboxAuthorizationException('This conversation is not assigned to the current agent.');
        }
        return $this->repository->listAssignmentHistory($conversationId, $afterId, $limit, $requiredAssignee);
    }

    public function addInternalNote(
        array $server,
        string $id,
        string $conversationId,
        string $body,
        ?string $requestId = null
    ): bool {
        $actor = $this->authenticate($server);
        try {
            return $this->repository->addInternalNote(
                $id, $conversationId, (string)$actor['id'], $body, $requestId, !self::isAdministrator($actor)
            );
        } catch (StaffInboxAssignmentException) {
            throw new StaffInboxAuthorizationException('This conversation is no longer assigned to the current agent.');
        }
    }

    /** @return list<array<string,mixed>> */
    public function listInternalNotes(array $server, string $conversationId, int $afterId, int $limit): array
    {
        $actor = $this->authenticate($server);
        $requiredAssignee = self::isAdministrator($actor) ? null : (string)$actor['id'];
        if ($requiredAssignee !== null && $this->repository->getActiveAssignee($conversationId) !== $requiredAssignee) {
            throw new StaffInboxAuthorizationException('This conversation is not assigned to the current agent.');
        }
        return $this->repository->listInternalNotes($conversationId, $afterId, $limit, $requiredAssignee);
    }

    public function createStaffReply(
        array $server,
        array $message,
        ?string $requestId = null
    ): array {
        $actor = $this->authenticate($server);
        try {
            return $this->repository->createStaffReply(
                $message, (string)$actor['id'], $requestId, !self::isAdministrator($actor)
            );
        } catch (StaffInboxAssignmentException) {
            throw new StaffInboxAuthorizationException('This conversation is no longer assigned to the current agent.');
        }
    }

    /** @return list<array<string,mixed>> */
    public function listAuditEvents(array $server, string $conversationId, int $afterId, int $limit): array
    {
        $actor = $this->authenticate($server);
        if (!self::isAdministrator($actor)) {
            throw new StaffInboxAuthorizationException('Only administrators can inspect the audit trail.');
        }
        return $this->repository->listAuditEvents($conversationId, $afterId, $limit);
    }

    private static function isAdministrator(array $actor): bool
    {
        return in_array((string)($actor['role'] ?? ''), ['owner', 'admin'], true);
    }


}
