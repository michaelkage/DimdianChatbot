<?php
declare(strict_types=1);

/**
 * Tenant resolution is server-side only. Browser-supplied tenant IDs are never
 * accepted. A website Origin must match a verified tenant_sites record.
 */

const CHATBRIDGE_LEGACY_TENANT_ID = '00000000-0000-4000-8000-000000000001';

function normalize_request_origin(?string $origin): ?string
{
    if ($origin === null || trim($origin) === '') {
        return null;
    }
    $parts = parse_url(trim($origin));
    if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
        return null;
    }
    $scheme = strtolower((string)$parts['scheme']);
    $host = strtolower(rtrim((string)$parts['host'], '.'));
    if (!in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
        return null;
    }
    if (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/') {
        return null;
    }
    if (isset($parts['query']) || isset($parts['fragment'])) {
        return null;
    }
    $port = isset($parts['port']) ? (int)$parts['port'] : null;
    if ($port !== null && !(($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80))) {
        return $scheme . '://' . $host . ':' . $port;
    }
    return $scheme . '://' . $host;
}

function resolve_request_tenant_id(PDO $pdo): string
{
    $origin = normalize_request_origin(request_origin());
    if ($origin === null) {
        json_response(['error' => ['code' => 'tenant_origin_required', 'message' => 'A registered website origin is required.']], 403);
    }

    $query = $pdo->prepare(
        'SELECT t.id
         FROM tenant_sites s
         INNER JOIN tenants t ON t.id = s.tenant_id
         WHERE s.origin = :origin AND s.status = "verified" AND t.status = "active"
         LIMIT 1'
    );
    $query->execute([':origin' => $origin]);
    $tenantId = $query->fetchColumn();
    if (!is_string($tenantId) || $tenantId === '') {
        json_response(['error' => ['code' => 'tenant_not_registered', 'message' => 'This website is not registered with the chat service.']], 403);
    }
    return $tenantId;
}

function resolve_meta_tenant(PDO $pdo, string $phoneNumberId): ?string
{
    if ($phoneNumberId === '') {
        return null;
    }
    $query = $pdo->prepare(
        'SELECT tenant_id
         FROM channel_connections
         WHERE provider = "meta"
           AND phone_number_id = :phone_number_id
           AND connection_status IN ("webhook_subscribed", "number_verified", "connection_tested", "connected")
         LIMIT 1'
    );
    $query->execute([':phone_number_id' => $phoneNumberId]);
    $tenantId = $query->fetchColumn();
    return is_string($tenantId) && $tenantId !== '' ? $tenantId : null;
}
