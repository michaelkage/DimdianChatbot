<?php
declare(strict_types=1);

// CLI-only tenant bootstrap. Site origins remain pending until an operator has
// independently verified domain ownership and marks them verified in MySQL.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

$name = trim((string)($argv[1] ?? ''));
$slug = strtolower(trim((string)($argv[2] ?? '')));
$origin = normalize_request_origin((string)($argv[3] ?? ''));

if ($name === '' || strlen($name) > 160 || !preg_match('/^[a-z0-9][a-z0-9-]{1,78}[a-z0-9]$/', $slug) || $origin === null) {
    fwrite(STDERR, "Usage: php bin/provision-tenant.php \"Business name\" business-slug https://shop.example.com\n");
    exit(2);
}

try {
    $pdo = database();
    $tenantId = uuid_v4();
    $pdo->beginTransaction();
    $tenant = $pdo->prepare(
        'INSERT INTO tenants (id, name, slug, status, created_at, updated_at)
         VALUES (:id, :name, :slug, "active", UTC_TIMESTAMP(), UTC_TIMESTAMP())'
    );
    $tenant->execute([':id' => $tenantId, ':name' => $name, ':slug' => $slug]);

    $site = $pdo->prepare(
        'INSERT INTO tenant_sites (tenant_id, origin, status, created_at, updated_at)
         VALUES (:tenant_id, :origin, "pending", UTC_TIMESTAMP(), UTC_TIMESTAMP())'
    );
    $site->execute([':tenant_id' => $tenantId, ':origin' => $origin]);
    $pdo->commit();

    fwrite(STDOUT, "Tenant created: {$tenantId}\n");
    fwrite(STDOUT, "Site awaiting ownership verification: {$origin}\n");
    fwrite(STDOUT, "After verifying domain ownership, set this tenant_sites row to status=verified.\n");
} catch (Throwable $error) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, "Tenant provisioning failed: " . $error->getMessage() . "\n");
    exit(1);
}
