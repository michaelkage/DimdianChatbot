<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/tenant_auth.php';

require_method('GET');

try {
    $pdo = database();
    $auth = authenticate_tenant_api_key($pdo, ['owner', 'admin']);
    $query = $pdo->prepare(
        'SELECT id, business_account_id, whatsapp_account_id, business_portfolio_id, phone_number_id, display_phone_number,
                connection_status, connection_mode, is_on_biz_app, token_expires_at,
                last_error_code, last_error_message, connected_at, updated_at
         FROM channel_connections
         WHERE tenant_id = :tenant_id AND provider = "meta"
         ORDER BY updated_at DESC'
    );
    $query->execute([':tenant_id' => $auth['tenant_id']]);
    $connections = $query->fetchAll();
    $syncQuery = $pdo->prepare(
        'SELECT channel_connection_id, sync_type, status, request_id, requested_at, completed_at, last_error
         FROM channel_sync_jobs WHERE tenant_id = :tenant_id ORDER BY created_at DESC'
    );
    $syncQuery->execute([':tenant_id' => $auth['tenant_id']]);
    json_response(['connections' => $connections, 'coexistence_sync' => $syncQuery->fetchAll()]);
} catch (Throwable $error) {
    error_log('[ChatBridge Meta status] ' . $error->getMessage());
    json_response(['error' => ['code' => 'meta_status_unavailable', 'message' => 'Meta connection status is unavailable.']], 500);
}
