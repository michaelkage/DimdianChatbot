<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

$tenantId = trim((string)($argv[1] ?? ''));
$keyId = trim((string)($argv[2] ?? ''));
if (!preg_match('/^[0-9a-f-]{36}$/i', $tenantId) || !preg_match('/^[0-9a-f-]{36}$/i', $keyId)) {
    fwrite(STDERR, "Usage: php bin/revoke-tenant-api-key.php TENANT_UUID API_KEY_UUID\n");
    exit(2);
}

try {
    $statement = database()->prepare(
        'UPDATE tenant_api_keys
         SET status = "revoked", revoked_at = UTC_TIMESTAMP()
         WHERE tenant_id = :tenant_id AND id = :id AND status = "active"'
    );
    $statement->execute([':tenant_id' => $tenantId, ':id' => $keyId]);
    if ($statement->rowCount() !== 1) {
        fwrite(STDERR, "No active key matched that tenant and key ID.\n");
        exit(1);
    }
    fwrite(STDOUT, "Tenant API key revoked.\n");
} catch (Throwable $error) {
    fwrite(STDERR, "API key revocation failed: " . $error->getMessage() . "\n");
    exit(1);
}
