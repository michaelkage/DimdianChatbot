<?php
declare(strict_types=1);

/**
 * Persistence boundary for Meta Embedded Signup and connected WhatsApp accounts.
 * Implementations own atomic state consumption and connection writes.
 */
interface MetaConnectionRepository
{
    public function createSignupState(string $stateHash, int $ttlSeconds = 600): void;

    /** Atomically consume a valid, unexpired state. Returns false for missing, expired or replayed state. */
    public function consumeSignupState(string $stateHash): bool;

    /** @param array{tenant_id?:string,waba_id:string,phone_number_id:string,token_ciphertext:string,token_nonce:string,token_tag:string,token_expires_at:?string} $connection */
    public function saveActiveConnection(array $connection): void;

    /** @return array<string,mixed>|null */
    public function findActiveConnection(): ?array;

    /** @return array<string,mixed>|null */
    public function findActiveConnectionForTenant(string $tenantId): ?array;

    /** @param array{ciphertext:string,nonce:string,tag:string} $encrypted */
    public function updateAccessToken(int $connectionId, array $encrypted, ?string $expiresAt): void;

    public function markConnectionExpired(int $connectionId): void;

    public function activePhoneNumberId(): ?string;

    /** Resolve only an active, non-revoked provider number to its owning tenant. */
    public function tenantIdForPhoneNumberId(string $phoneNumberId): ?string;
}
