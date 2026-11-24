<?php
declare(strict_types=1);

final class TenantResolutionException extends RuntimeException
{
}

/** Immutable server-resolved tenant scope for one HTTP request. */
final class TenantContext
{
    public string $tenantId;
    public ?string $publicWidgetId;

    public function __construct(string $tenantId, ?string $publicWidgetId = null)
    {
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $tenantId)) {
            throw new InvalidArgumentException('Tenant context requires a UUID tenant ID.');
        }
        $this->tenantId = strtolower($tenantId);
        $this->publicWidgetId = $publicWidgetId;
    }
}
