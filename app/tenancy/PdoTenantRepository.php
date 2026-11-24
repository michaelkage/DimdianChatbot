<?php
declare(strict_types=1);

final class PdoTenantRepository implements TenantRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function findActiveTenantWidget(string $publicWidgetId): ?array
    {
        $statement = PdoSqlDialect::prepare($this->pdo,
            'SELECT t.id AS tenant_id, t.status AS tenant_status, w.allowed_origins
             FROM tenant_widgets w
             INNER JOIN tenants t ON t.id = w.tenant_id
             WHERE w.public_widget_id = :widget
               AND w.status = "active"
             LIMIT 1'
        );
        $statement->execute([':widget' => $publicWidgetId]);
        $row = $statement->fetch();
        if (!is_array($row) || ($row['tenant_status'] ?? '') !== 'active') {
            return null;
        }
        return [
            'tenant_id' => (string) $row['tenant_id'],
            'tenant_status' => (string) $row['tenant_status'],
            'allowed_origins' => (string) $row['allowed_origins'],
        ];
    }
}
