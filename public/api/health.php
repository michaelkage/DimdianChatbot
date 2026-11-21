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
    'guest_consent_events',
    'whatsapp_identity_links',
    'webhook_inbox',
    'meta_signup_states',
    'meta_connections',
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
    $opensslOk = extension_loaded('openssl');
    $pdoMysqlOk = extension_loaded('pdo_mysql');
    $runtimeOk = $opensslOk && $pdoMysqlOk;
    $queueMetrics = ['webhook_pending' => null, 'webhook_failed' => null, 'outbox_pending' => null, 'outbox_failed' => null];
    if ($schemaOk) {
        $queueMetrics['webhook_pending'] = (int) $pdo->query('SELECT COUNT(*) FROM webhook_inbox WHERE status IN ("pending","processing")')->fetchColumn();
        $queueMetrics['webhook_failed'] = (int) $pdo->query('SELECT COUNT(*) FROM webhook_inbox WHERE status = "failed"')->fetchColumn();
        $queueMetrics['outbox_pending'] = (int) $pdo->query('SELECT COUNT(*) FROM message_outbox WHERE status IN ("pending","processing")')->fetchColumn();
        $queueMetrics['outbox_failed'] = (int) $pdo->query('SELECT COUNT(*) FROM message_outbox WHERE status = "failed"')->fetchColumn();
    }
    $whatsapp = app_config('whatsapp') ?? [];
    $whatsappEnabled = is_array($whatsapp) && !empty($whatsapp['outbound_enabled']);

    json_response([
        'status' => ($schemaOk && $runtimeOk) ? 'healthy' : 'degraded',
        'service' => 'Dimdian website chat',
        'checked_at' => gmdate(DateTimeInterface::ATOM),
        'checks' => [
            'php_api' => 'ok',
            'database' => 'ok',
            'schema' => $schemaOk ? 'ok' : 'incomplete',
            'openssl' => $opensslOk ? 'ok' : 'missing',
            'pdo_mysql' => $pdoMysqlOk ? 'ok' : 'missing',
        ],
        'missing_tables' => $missingTables,
        'queues' => $queueMetrics,
        'whatsapp_outbound' => $whatsappEnabled ? 'enabled_in_config' : 'disabled',
        'note' => 'This check does not test WordPress, WooCommerce, WCFM, Paystack, Meta connectivity or live WhatsApp delivery.',
    ], ($schemaOk && $runtimeOk) ? 200 : 503);
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
