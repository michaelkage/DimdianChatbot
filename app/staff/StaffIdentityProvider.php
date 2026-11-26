<?php
declare(strict_types=1);

/**
 * Authentication extension point for optional staff inbox deployments.
 *
 * Implementations must validate the presented credential cryptographically or
 * through a trusted identity service. Never derive identity from a request body,
 * public widget key, guest token, Origin header or unverified JWT claims.
 */
interface StaffIdentityProvider
{
    /**
     * Return only a verified stable external identity and its signed tenant.
     *
     * @param array<string,mixed> $server Server request context, including headers.
     * @return array{tenant_id:string,auth_provider:string,external_subject:string,display_name:string,email?:string|null}|null
     */
    public function authenticate(array $server): ?array;
}
