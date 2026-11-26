<?php
declare(strict_types=1);

final class StaffInboxAuthenticationException extends RuntimeException
{
}

/**
 * Resolves a cryptographically verified external principal to a locally
 * provisioned staff identity. Local role/status remain authoritative.
 *
 * The verified tenant is preserved in the returned actor context. Persistence
 * and authorization consumers must still scope every operation by this value;
 * the current single-tenant adapters do not yet provide that shared-DB guarantee.
 */
final class StaffIdentityService
{
    private StaffIdentityProvider $identityProvider;
    private StaffInboxRepository $repository;

    public function __construct(StaffIdentityProvider $identityProvider, StaffInboxRepository $repository)
    {
        $this->identityProvider = $identityProvider;
        $this->repository = $repository;
    }

    /** @return array<string,mixed> */
    public function authenticate(array $server): array
    {
        try {
            $principal = $this->identityProvider->authenticate($server);
        } catch (Throwable) {
            throw new StaffInboxAuthenticationException('Staff authentication failed.');
        }
        if (!is_array($principal)) {
            throw new StaffInboxAuthenticationException('A valid staff identity is required.');
        }

        $tenantId = strtolower((string)($principal['tenant_id'] ?? ''));
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $tenantId)) {
            throw new StaffInboxAuthenticationException('A signed staff tenant is required.');
        }

        $provider = strtolower(trim((string) ($principal['auth_provider'] ?? '')));
        $subject = (string) ($principal['external_subject'] ?? '');
        $name = trim((string) ($principal['display_name'] ?? ''));
        $email = isset($principal['email']) ? (string) $principal['email'] : null;
        if (
            !preg_match('/^[a-z0-9._-]{1,40}$/', $provider)
            || $subject === ''
            || strlen($subject) > 191
            || $name === ''
            || strlen($name) > 100
        ) {
            throw new StaffInboxAuthenticationException('The verified staff identity is incomplete.');
        }

        try {
            $id = $this->repository->upsertStaffIdentity([
                'id' => self::uuidV4(),
                'tenant_id' => $tenantId,
                'auth_provider' => $provider,
                'external_subject' => $subject,
                'display_name' => $name,
                'email' => $email,
            ]);
            $identity = $this->repository->findStaffIdentityById($id);
        } catch (Throwable) {
            throw new StaffInboxAuthenticationException('Staff identity could not be resolved.');
        }

        if (!is_array($identity) || ($identity['status'] ?? '') !== 'active') {
            throw new StaffInboxAuthenticationException('This staff account is not active.');
        }

        // Do not infer or overwrite this from persistence or request parameters.
        $identity['tenant_id'] = $tenantId;
        return $identity;
    }

    private static function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20)
        );
    }
}
