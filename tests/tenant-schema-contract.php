<?php
declare(strict_types=1);

/**
 * Static contract checks for tenant ownership in provider-facing schemas.
 * This does not prove runtime isolation; it prevents the clean-install schemas
 * and additive upgrade migrations from drifting apart.
 */
$root = dirname(__DIR__);
$tables = ['guest_consent_events', 'whatsapp_identity_links', 'webhook_events', 'webhook_inbox', 'meta_signup_states', 'meta_connections'];
$legacyTenant = '00000000-0000-4000-8000-000000000001';

function tenant_schema_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('Tenant schema contract failed: ' . $message);
    }
    echo 'PASS ' . $message . PHP_EOL;
}

foreach ([
    'database/schema.sql' => 'mysql',
    'database/postgresql/schema.sql' => 'postgresql',
] as $relative => $dialect) {
    $sql = file_get_contents($root . '/' . $relative);
    tenant_schema_expect(is_string($sql) && $sql !== '', $dialect . ' clean-install schema is readable');

    foreach ($tables as $table) {
        $pattern = '/CREATE TABLE\s+' . preg_quote($table, '/') . '\s*\((.*?)\)\s*(?:ENGINE=|;)/is';
        tenant_schema_expect(
            preg_match($pattern, $sql, $match) === 1
                && preg_match('/\btenant_id\b/i', $match[1]) === 1
                && stripos($match[1], $legacyTenant) !== false
                && stripos($match[1], 'REFERENCES tenants(id)') !== false,
            $dialect . ' ' . $table . ' has a legacy-safe tenant_id and tenant foreign key'
        );
    }

    tenant_schema_expect(
        preg_match('/UNIQUE\s*(?:KEY\s+\w+\s*)?\(\s*tenant_id\s*,\s*provider_event_id\s*\)/i', $sql) === 1,
        $dialect . ' webhook event deduplication is tenant-scoped'
    );
    tenant_schema_expect(
        preg_match('/UNIQUE\s*(?:KEY\s+\w+\s*)?\(\s*tenant_id\s*,\s*payload_sha256\s*\)/i', $sql) === 1,
        $dialect . ' webhook inbox deduplication is tenant-scoped'
    );
    tenant_schema_expect(
        preg_match('/(?:UNIQUE\s+KEY\s+\w+\s*\(\s*phone_number_id\s*\)|phone_number_id\s+VARCHAR\(80\)\s+NOT NULL UNIQUE)/i', $sql) === 1,
        $dialect . ' Meta phone-number ownership remains globally unique within one deployment'
    );
}

$migrations = [
    'database/migrations/005_tenant_owned_provider_storage.sql' => $tables,
    // PostgreSQL's preceding clean-install schema already tenant-scoped consent
    // events and identity links; its upgrade only needs the four provider tables
    // that were unscoped in that schema.
    'database/postgresql/migrations/005_tenant_owned_provider_storage.sql' => [
        'webhook_events', 'webhook_inbox', 'meta_signup_states', 'meta_connections',
    ],
];
foreach ($migrations as $relative => $migrationTables) {
    $sql = file_get_contents($root . '/' . $relative);
    tenant_schema_expect(is_string($sql) && $sql !== '', $relative . ' is readable');
    foreach ($migrationTables as $table) {
        tenant_schema_expect(
            preg_match('/ALTER TABLE\s+' . preg_quote($table, '/') . '[\s\S]*?ADD COLUMN\s+tenant_id/i', $sql) === 1,
            $relative . ' adds tenant ownership to ' . $table
        );
    }
    tenant_schema_expect(
        stripos($sql, $legacyTenant) !== false,
        $relative . ' backfills existing rows into the deterministic legacy tenant'
    );
}

$providerRepository = file_get_contents($root . '/app/storage/PdoProviderEventRepository.php');
$providerContract = file_get_contents($root . '/app/storage/ChatRepository.php');
$workerSource = file_get_contents($root . '/app/channels/whatsapp/WebhookInboxWorker.php');
tenant_schema_expect(
    is_string($providerRepository)
        && preg_match('/WHERE l\\.tenant_id = :link_tenant_id AND c\\.tenant_id = :conversation_tenant_id/', $providerRepository) === 1
        && preg_match('/\\(id, tenant_id, conversation_id, sender_type/', $providerRepository) === 1,
    'provider message identity routing and message insertion both require explicit tenant scope'
);
tenant_schema_expect(
    is_string($providerRepository)
        && preg_match('/WHERE tenant_id = :tenant_id AND provider_message_id = :provider_id/', $providerRepository) === 1
        && preg_match('/WHERE tenant_id = :tenant_id AND provider_event_id = :event_id/', $providerRepository) === 1,
    'provider status reconciliation and event updates are tenant-scoped'
);
tenant_schema_expect(
    is_string($providerContract)
        && preg_match('/recordProviderMessageEvent\\(\\s*string \\$tenantId/', $providerContract) === 1
        && preg_match('/reconcileProviderMessageStatus\\(string \\$tenantId,/', $providerContract) === 1
        && preg_match('/recordProviderStatusEvent\\(\\s*string \\$tenantId/', $providerContract) === 1,
    'provider repository contract makes tenant identity an explicit required argument'
);
tenant_schema_expect(
    is_string($workerSource)
        && strpos($workerSource, "$"."job['tenant_id']") !== false
        && strpos($workerSource, 'tenantIdForWhatsAppPhoneNumberId') !== false,
    'webhook worker passes its trusted tenant routing context into provider processing'
);

$inboxRepository = file_get_contents($root . '/app/storage/PdoWebhookInboxRepository.php');
$metaConnectionRepository = file_get_contents($root . '/app/storage/PdoMetaConnectionRepository.php');
$tenantResolver = file_get_contents($root . '/app/channels/whatsapp/MetaWebhookTenantResolver.php');
tenant_schema_expect(
    is_string($inboxRepository)
        && preg_match('/INSERT IGNORE INTO webhook_inbox\\s*\\(tenant_id, payload_sha256/', $inboxRepository) === 1
        && preg_match('/WHERE id = :id AND tenant_id = :tenant_id AND status = "processing" AND lock_token = :token/', $inboxRepository) === 1,
    'webhook inbox writes and lease transitions require the owning tenant'
);
tenant_schema_expect(
    is_string($metaConnectionRepository)
        && preg_match('/WHERE phone_number_id = :phone AND status = "active"/', $metaConnectionRepository) === 1
        && is_string($tenantResolver)
        && strpos($tenantResolver, 'tenantIdForWhatsAppPhoneNumberId') !== false
        && strpos($tenantResolver, 'MetaWebhookTenantResolutionException') !== false,
    'webhook tenant routing resolves only verified, active Meta phone identities and fails closed'
);

$signupService = file_get_contents($root . '/app/channels/whatsapp/MetaEmbeddedSignupService.php');
$metaSaveRepository = file_get_contents($root . '/app/storage/PdoMetaConnectionRepository.php');
tenant_schema_expect(
    is_string($signupService)
        && strpos($signupService, "'tenant_id' =>") !== false
        && strpos($signupService, "tenancy.default_tenant_id") !== false
        && is_string($metaSaveRepository)
        && strpos($metaSaveRepository, 'already owned by another tenant') !== false,
    'Meta signup binds connections to server configuration and refuses cross-tenant phone reassignment'
);
tenant_schema_expect(
    is_string($inboxRepository)
        && preg_match('/SELECT id, tenant_id, payload_ciphertext/', $inboxRepository) === 1
        && preg_match('/WHERE tenant_id = :tenant_id AND payload_sha256 = :hash/', $inboxRepository) === 1,
    'claimed webhook jobs retain tenant identity and payload deduplication is tenant-local'
);

echo "Tenant provider-schema contract checks passed." . PHP_EOL;
