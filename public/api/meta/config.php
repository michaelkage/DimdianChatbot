<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/tenant_auth.php';

require_method('GET');

try {
    $pdo = database();
    $auth = authenticate_tenant_api_key($pdo, ['owner', 'admin']);
    $meta = app_config('meta') ?? [];
    if (!is_array($meta) || empty($meta['enabled']) || empty($meta['app_id']) || empty($meta['configuration_id'])) {
        json_response(['error' => ['code' => 'meta_not_configured', 'message' => 'Meta Embedded Signup is not configured for this service.']], 503);
    }
    json_response([
        'enabled' => true,
        'app_id' => (string)$meta['app_id'],
        'configuration_id' => (string)$meta['configuration_id'],
        'solution_id' => (string)($meta['solution_id'] ?? ''),
        'graph_api_version' => (string)($meta['graph_api_version'] ?? 'v27.0'),
        'embedded_signup_version' => 'v5',
    ]);
} catch (Throwable $error) {
    error_log('[ChatBridge Meta config] ' . $error->getMessage());
    json_response(['error' => ['code' => 'meta_config_unavailable', 'message' => 'Meta onboarding configuration is unavailable.']], 500);
}
