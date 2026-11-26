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
        $tenantId = strtolower((string)($identity['tenant_id'] ?? '00000000-0000-4000-8000-000000000001'));
        $provider = (string)($identity['auth_provider'] ?? '');
        $subject = (string)($identity['external_subject'] ?? '');
        $name = trim((string)($identity['display_name'] ?? ''));
        $email = isset($identity['email']) ? trim((string)$identity['email']) : null;

        if (!PdoStaffPersistenceSupport::isUuid($id) || !PdoStaffPersistenceSupport::isUuid($tenantId)
            || !preg_match('/^[a-z0-9._-]{1,40}$/i', $provider)
            || $subject === '' || strlen($subject) > 191 || $name === '' || strlen($name) > 100
            || ($email !== null && (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false))) {
            throw new InvalidArgumentException('Verified staff identity is incomplete or invalid.');
        }

        $sql = PdoSqlDialect::isPostgres($this->pdo)
            ? 'INSERT INTO staff_identities
                   (id, tenant_id, auth_provider, external_subject, display_name, email, role, status, created_at, updated_at)
               VALUES
                   (:id, :tenant_id, :provider, :subject, :name, :email, \'agent\', \'active\', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
               ON CONFLICT (tenant_id, auth_provider, external_subject) DO UPDATE SET
                   display_name = EXCLUDED.display_name,
                   email = EXCLUDED.email,
                   updated_at = CURRENT_TIMESTAMP'
            : 'INSERT INTO staff_identities
                   (id, tenant_id, auth_provider, external_subject, display_name, email, role, status, created_at, updated_at)
               VALUES
                   (:id, :tenant_id, :provider, :subject, :name, :email, "agent", "active", UTC_TIMESTAMP(), UTC_TIMESTAMP())
               ON DUPLICATE KEY UPDATE
                   display_name = VALUES(display_name),
                   email = VALUES(email),
                   updated_at = UTC_TIMESTAMP()';
        $query = PdoSqlDialect::prepare($this->pdo, $sql);
        $query->execute([
            ':id' => $id,
            ':tenant_id' => $tenantId,
            ':provider' => strtolower($provider),
            ':subject' => $subject,
            ':name' => $name,
            ':email' => $email,
        ]);
        $lookup = PdoSqlDialect::prepare($this->pdo, 
            'SELECT id FROM staff_identities
             WHERE tenant_id = :tenant_id AND auth_provider = :provider AND external_subject = :subject LIMIT 1'
        );
        $lookup->execute([':tenant_id' => $tenantId, ':provider' => strtolower($provider), ':subject' => $subject]);
        $localId = $lookup->fetchColumn();
        if (!is_string($localId) || $localId === '') {
            throw new RuntimeException('Staff identity could not be resolved after synchronization.');
        }
        return $localId;
    }

    public function findStaffIdentityById(string $staffIdentityId, string $tenantId = '00000000-0000-4000-8000-000000000001'): ?array
    {
        $query = PdoSqlDialect::prepare($this->pdo, 
            'SELECT id, auth_provider, external_subject, display_name, email, role, status
             FROM staff_identities WHERE id = :id AND tenant_id = :tenant_id LIMIT 1'
        );
        $query->execute([':id' => $staffIdentityId, ':tenant_id' => $tenantId]);
        $identity = $query->fetch();
        return is_array($identity) ? $identity : null;
    }

    public function consumeStaffAuthNonce(string $nonceHash, string $expiresAtUtc, string $tenantId = '00000000-0000-4000-8000-000000000001'): bool
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $nonceHash)) {
            throw new InvalidArgumentException('Staff authentication nonce hash is invalid.');
        }
        $query = PdoSqlDialect::prepare($this->pdo, 
            'INSERT IGNORE INTO staff_auth_nonces (tenant_id, nonce_sha256, expires_at, created_at)
             VALUES (:tenant_id, :nonce, :expires, UTC_TIMESTAMP())'
        );
        $query->execute([':tenant_id' => $tenantId, ':nonce' => $nonceHash, ':expires' => $expiresAtUtc]);
        return $query->rowCount() === 1;
    }

    public function listStaffIdentities(string $tenantId = '00000000-0000-4000-8000-000000000001'): array
    {
        $query = PdoSqlDialect::prepare($this->pdo, 
            'SELECT id, display_name, email, role, status
             FROM staff_identities WHERE tenant_id = :tenant_id
             ORDER BY status ASC, display_name ASC LIMIT 250'
        );
        $query->execute([':tenant_id' => $tenantId]);
        return $query->fetchAll();
    }

    public function updateStaffIdentityAccess(
        string $targetStaffId,
        string $actorStaffId,
        string $role,
        string $status,
        ?string $requestId = null,
        string $tenantId = '00000000-0000-4000-8000-000000000001'
    ): void {
        if (!in_array($role, ['owner', 'admin', 'agent'], true)
            || !in_array($status, ['active', 'disabled'], true)) {
            throw new InvalidArgumentException('Unsupported staff role or status.');
        }

        $this->support->transaction(function () use ($targetStaffId, $actorStaffId, $role, $status, $requestId, $tenantId): void {
            // Serialize owner changes so two concurrent requests cannot remove the final owner.
            $ownerQuery = PdoSqlDialect::prepare($this->pdo, 
                'SELECT id FROM staff_identities WHERE tenant_id = :tenant_id AND role = "owner" AND status = "active" ORDER BY id FOR UPDATE'
            );
            $ownerQuery->execute([':tenant_id' => $tenantId]);
            $owners = $ownerQuery->fetchAll(PDO::FETCH_COLUMN);
            $actor = PdoSqlDialect::prepare($this->pdo, 
                'SELECT role, status FROM staff_identities WHERE tenant_id = :tenant_id AND id = :id LIMIT 1 FOR UPDATE'
            );
            $actor->execute([':tenant_id' => $tenantId, ':id' => $actorStaffId]);
            $actorRow = $actor->fetch();
            if (!is_array($actorRow) || $actorRow['role'] !== 'owner' || $actorRow['status'] !== 'active') {
                throw new RuntimeException('Only an active owner may manage staff access.');
            }

            $target = PdoSqlDialect::prepare($this->pdo, 
                'SELECT role, status FROM staff_identities WHERE tenant_id = :tenant_id AND id = :id LIMIT 1 FOR UPDATE'
            );
            $target->execute([':tenant_id' => $tenantId, ':id' => $targetStaffId]);
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

            $update = PdoSqlDialect::prepare($this->pdo, 
                'UPDATE staff_identities SET role = :role, status = :status, updated_at = UTC_TIMESTAMP()
                 WHERE tenant_id = :tenant_id AND id = :id'
            );
            $update->execute([':role' => $role, ':status' => $status, ':tenant_id' => $tenantId, ':id' => $targetStaffId]);

            $this->support->recordAudit(
                $actorStaffId,
                null,
                'staff.access_updated',
                $targetStaffId,
                ['role' => $role, 'status' => $status],
                $requestId,
                $tenantId
            );
        });
    }

}
