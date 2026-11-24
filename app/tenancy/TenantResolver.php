<?php
declare(strict_types=1);

/** Resolves public widget routing hints into trusted tenant context. */
final class TenantResolver
{
    private TenantRepository $repository;
    /** @var array<string,mixed> */
    private array $config;

    /** @param array<string,mixed> $config */
    public function __construct(TenantRepository $repository, array $config)
    {
        $this->repository = $repository;
        $this->config = $config;
    }

    public function resolve(?string $publicWidgetId, ?string $origin): TenantContext
    {
        $mode = (string) ($this->config['mode'] ?? 'single_tenant');
        if ($mode === 'single_tenant') {
            $tenantId = (string) ($this->config['default_tenant_id'] ?? '00000000-0000-4000-8000-000000000001');
            return new TenantContext($tenantId, $publicWidgetId !== '' ? $publicWidgetId : null);
        }
        if ($mode !== 'shared_database') {
            throw new TenantResolutionException('The configured tenancy mode is not supported.');
        }
        if (
            !is_string($publicWidgetId)
            || !preg_match('/^[A-Za-z0-9_-]{16,128}$/', $publicWidgetId)
        ) {
            throw new TenantResolutionException('A valid public widget identifier is required.');
        }
        if (!is_string($origin) || $origin === '') {
            throw new TenantResolutionException('A registered website origin is required.');
        }

        $widget = $this->repository->findActiveTenantWidget($publicWidgetId);
        if ($widget === null) {
            throw new TenantResolutionException('This chat widget is not registered or is disabled.');
        }
        try {
            $origins = json_decode($widget['allowed_origins'], true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new TenantResolutionException('The registered widget origin policy is invalid.');
        }
        if (!is_array($origins) || !in_array($origin, $origins, true)) {
            throw new TenantResolutionException('This website origin is not authorized for the chat widget.');
        }

        return new TenantContext($widget['tenant_id'], $publicWidgetId);
    }
}
