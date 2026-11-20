<?php
declare(strict_types=1);

/**
 * Safe, read-only operational health check.
 *
 * This endpoint deliberately reports component state only. It never returns
 * database credentials, hostnames, SQL errors, secrets, customer data or rows.
 * It checks the chat database only; it does not test WordPress, Paystack or Meta.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

enforce_allowed_origin();
handle_preflight();
require_method('GET');

$requiredTables = [
    'guests',
    'conversations',
    'guest_sessions',
    'messages',
    'rate_limits',
    'webhook_events',
    'message_outbox',
    'message_status_events',
    'tenants',
    'tenant_sites',
    'channel_connections',
    'tenant_api_keys',
    'tenant_ai_settings',
    'knowledge_sources',
    'ai_jobs',
    'conversation_handoffs',
    'ai_usage_events',
    'channel_connection_events',
    'channel_sync_jobs',
    'whatsapp_contacts',
    'coexistence_history_payloads',
];

try {
    $pdo = database();

    // Compare the installed schema with the tables the active chat code needs.
    $placeholders = implode(',', array_fill(0, count($requiredTables), '?'));
    $statement = $pdo->prepare(
        'SELECT table_name
         FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_type = "BASE TABLE"
           AND table_name IN (' . $placeholders . ')'
    );
    $statement->execute($requiredTables);
    $foundTables = $statement->fetchAll(PDO::FETCH_COLUMN);
    $missingTables = array_values(array_diff($requiredTables, $foundTables));

    $schemaOk = $missingTables === [];
    $whatsapp = app_config('whatsapp') ?? [];
    $whatsappEnabled = is_array($whatsapp) && !empty($whatsapp['outbound_enabled']);

    json_response([
        'status' => $schemaOk ? 'healthy' : 'degraded',
        'service' => 'Dimdian website chat',
        'checked_at' => gmdate(DateTimeInterface::ATOM),
        'checks' => [
            'php_api' => 'ok',
            'database' => 'ok',
            'schema' => $schemaOk ? 'ok' : 'incomplete',
        ],
        'missing_tables' => $missingTables,
        'whatsapp_outbound' => $whatsappEnabled ? 'enabled_in_config' : 'disabled',
        'note' => 'This check validates required schema tables only. It does not test WordPress, WooCommerce, WCFM, Paystack, Meta connectivity, AI provider credentials or live WhatsApp delivery.',
    ], $schemaOk ? 200 : 503);
} catch (Throwable $error) {
    // Detailed diagnostics belong in the private hosting error log, not HTTP.
    error_log('[DimdianChatbot] Health check failed: ' . $error->getMessage());
    json_response([
        'status' => 'degraded',
        'service' => 'Dimdian website chat',
        'checks' => [
            'php_api' => 'ok',
            'database' => 'unavailable',
            'schema' => 'not_checked',
        ],
        'note' => 'Ask the technical maintainer to inspect the private Hostinger PHP error log.',
    ], 503);
}
