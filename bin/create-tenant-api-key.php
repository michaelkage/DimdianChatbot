<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

$tenantId = trim((string)($argv[1] ?? ''));
$label = trim((string)($argv[2] ?? ''));
$role = trim((string)($argv[3] ?? 'admin'));
if (!preg_match('/^[0-9a-f-]{36}$/i', $tenantId) || $label === '' || strlen($label) > 120 || !in_array($role, ['owner', 'admin', 'support'], true)) {
    fwrite(STDERR, "Usage: php bin/create-tenant-api-key.php TENANT_UUID \"WordPress connector\" owner|admin|support\n");
    exit(2);
}

try {
    $token = bin2hex(random_bytes(32));
    $keyId = uuid_v4();
    $statement = database()->prepare(
        'INSERT INTO tenant_api_keys (id, tenant_id, label, key_sha256, role, status, created_at)
         VALUES (:id, :tenant_id, :label, :hash, :role, "active", UTC_TIMESTAMP())'
    );
    $statement->execute([
        ':id' => $keyId,
        ':tenant_id' => $tenantId,
        ':label' => $label,
        ':hash' => hash('sha256', $token),
        ':role' => $role,
    ]);
    fwrite(STDOUT, "Key ID: {$keyId}\n");
    fwrite(STDOUT, "Copy this key now. It is shown only once:\n{$token}\n");
} catch (Throwable $error) {
    fwrite(STDERR, "API key creation failed: " . $error->getMessage() . "\n");
    exit(1);
}
