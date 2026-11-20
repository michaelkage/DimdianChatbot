<?php
declare(strict_types=1);

/** Resolve a tenant from a hashed server-to-server API key. */
function authenticate_tenant_api_key(PDO $pdo, array $allowedRoles = ['owner', 'admin']): array
{
    $token = trim((string)($_SERVER['HTTP_X_CHATBRIDGE_TENANT_KEY'] ?? ''));
    if (!preg_match('/^[a-f0-9]{64}$/i', $token)) {
        json_response(['error' => ['code' => 'tenant_auth_required', 'message' => 'A valid tenant API key is required.']], 401);
    }

    $query = $pdo->prepare(
        'SELECT k.id, k.tenant_id, k.role
         FROM tenant_api_keys k
         INNER JOIN tenants t ON t.id = k.tenant_id
         WHERE k.key_sha256 = :key_hash AND k.status = "active" AND t.status = "active"
         LIMIT 1'
    );
    $query->execute([':key_hash' => hash('sha256', $token)]);
    $record = $query->fetch();
    if (!is_array($record) || !in_array((string)$record['role'], $allowedRoles, true)) {
        json_response(['error' => ['code' => 'tenant_auth_denied', 'message' => 'The tenant API key is invalid or lacks permission.']], 403);
    }

    $touch = $pdo->prepare('UPDATE tenant_api_keys SET last_used_at = UTC_TIMESTAMP() WHERE id = :id');
    $touch->execute([':id' => $record['id']]);
    return $record;
}
