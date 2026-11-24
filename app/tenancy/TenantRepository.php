<?php
declare(strict_types=1);

interface TenantRepository
{
    /** @return array{tenant_id:string,tenant_status:string,allowed_origins:string}|null */
    public function findActiveTenantWidget(string $publicWidgetId): ?array;
}
