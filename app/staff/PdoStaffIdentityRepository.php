<?php
declare(strict_types=1);

/** MySQL/MariaDB persistence adapter for staff identity and access lifecycle. */
final class PdoStaffIdentityRepository implements StaffIdentityRepository
{
    private PDO $pdo;
    private PdoStaffPersistenceSupport $support;

    public function __construct(PDO $pdo, PdoStaffPersistenceSupport $support)
    {
        $this->pdo = $pdo;
        $this->support = $support;
    }

    public function upsertStaffIdentity(array $identity): string
    {
        $id = (string)($identity['id'] ?? '');
        $provider = (string)($identity['auth_provider'] ?? '');
        $subject = (string)($identity['external_subject'] ?? '');
        $name = trim((string)($identity['display_name'] ?? ''));
        $email = isset($identity['email']) ? trim((string)$identity['email']) : null;

        if (!PdoStaffPersistenceSupport::isUuid($id) || !preg_match('/^[a-z0-9._-]{1,40}$/i', $provider)
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

        $this->support->transaction(function () use ($targetStaffId, $actorStaffId, $role, $status, $requestId): void {
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
            if ($targetRow['role'] === $role && $targetRow['status'] === $status) {
                return;
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

            $this->support->recordAudit(
                $actorStaffId,
                null,
                'staff.access_updated',
                $targetStaffId,
                ['role' => $role, 'status' => $status],
                $requestId
            );
        });
    }

}
