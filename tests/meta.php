<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/whatsapp.php';
require_once dirname(__DIR__) . '/app/crypto.php';
require_once dirname(__DIR__) . '/app/meta.php';

function meta_check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    fwrite(STDOUT, "PASS: {$message}\n");
}

$invalidSessionRejected = false;
try {
    complete_meta_embedded_signup(database(), '00000000-0000-4000-8000-000000000001', 'fake-code', []);
} catch (InvalidArgumentException $error) {
    $invalidSessionRejected = true;
}
meta_check($invalidSessionRejected, 'Meta onboarding rejects a v5 session without messaging account IDs before exchanging a code');

$invalidPathRejected = false;
try {
    chatbridge_meta_graph_request('GET', '../../tenant-secret', 'not-a-real-token');
} catch (InvalidArgumentException $error) {
    $invalidPathRejected = true;
}
meta_check($invalidPathRejected, 'Meta Graph client rejects non-resource paths before making a network request');

meta_check(function_exists('resolve_meta_tenant_by_account_id'), 'webhook router can resolve account-level events by Messaging Account or WAAC ID');

$syncSummary = process_meta_coexistence_sync_jobs(database(), '00000000-0000-4000-8000-000000000001');
meta_check(($syncSummary['requested'] ?? -1) === 0, 'Coexistence sync worker is idle without queued jobs and makes no provider request');

$tenantB = '00000000-0000-4000-8000-000000000002';
$pdo = database();
$pdo->prepare('INSERT INTO tenants (id, name, slug, status, created_at, updated_at) VALUES (:id, "Tenant B", "tenant-b-meta-test", "active", UTC_TIMESTAMP(), UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE name = VALUES(name)')->execute([':id' => $tenantB]);
$pdo->prepare(
    'INSERT INTO channel_connections
        (id, tenant_id, provider, business_account_id, phone_number_id, connection_status, credential_ciphertext, token_expires_at, connected_at, created_at, updated_at)
     VALUES
        ("10000000-0000-4000-8000-000000000002", :tenant_id, "meta", "test-waba-b", "test-phone-b", "connected", "test-ciphertext-b", DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR), UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())
     ON DUPLICATE KEY UPDATE token_expires_at = VALUES(token_expires_at), connection_status = "connected"'
)->execute([':tenant_id' => $tenantB]);
$channelA = get_tenant_meta_channel($pdo, '00000000-0000-4000-8000-000000000001');
$channelB = get_tenant_meta_channel($pdo, $tenantB);
meta_check(($channelA['phone_number_id'] ?? '') === 'test-phone-id' && ($channelB['phone_number_id'] ?? '') === 'test-phone-b', 'outbound channel lookup is isolated by tenant');

$expiredTransportRejected = false;
try {
    (new TenantMetaCloudApiTransport($pdo, $tenantB))->sendTemplate('2348037654321', 'test');
} catch (WhatsAppTransportException $error) {
    $expiredTransportRejected = !$error->retryable;
}
$expiredStatus = $pdo->prepare('SELECT connection_status FROM channel_connections WHERE tenant_id = :tenant_id AND phone_number_id = "test-phone-b"');
$expiredStatus->execute([':tenant_id' => $tenantB]);
meta_check($expiredTransportRejected && $expiredStatus->fetchColumn() === 'needs_action', 'expired tenant token requires reauthorization and is not sent or retried');

fwrite(STDOUT, "Meta onboarding contract tests passed.\n");
