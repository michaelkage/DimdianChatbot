<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/tenant_auth.php';
require_once dirname(__DIR__, 2) . '/app/crypto.php';

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST, OPTIONS');
    json_response(['error' => ['code' => 'method_not_allowed', 'message' => 'Use GET to read AI settings or POST to update them.']], 405);
}

try {
    $pdo = database();
    $auth = authenticate_tenant_api_key($pdo, ['owner', 'admin']);
    $tenantId = (string)$auth['tenant_id'];

    if ($method === 'GET') {
        $query = $pdo->prepare(
            'SELECT enabled, provider, model, base_url, api_key_ciphertext,
                    max_output_tokens, monthly_token_budget
             FROM tenant_ai_settings WHERE tenant_id = :tenant_id LIMIT 1'
        );
        $query->execute([':tenant_id' => $tenantId]);
        $settings = $query->fetch();
        json_response([
            'enabled' => is_array($settings) && (int)$settings['enabled'] === 1,
            'provider' => is_array($settings) ? (string)$settings['provider'] : 'openai_compatible',
            'model' => is_array($settings) ? (string)$settings['model'] : '',
            'base_url' => is_array($settings) ? (string)$settings['base_url'] : 'https://api.openai.com/v1',
            'has_api_key' => is_array($settings) && !empty($settings['api_key_ciphertext']),
            'max_output_tokens' => is_array($settings) ? (int)$settings['max_output_tokens'] : 500,
            'monthly_token_budget' => is_array($settings) ? (int)$settings['monthly_token_budget'] : 0,
        ]);
    }

    $contentType = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
    if ($contentType !== 'application/json') {
        json_response(['error' => ['code' => 'unsupported_media_type', 'message' => 'Content-Type must be application/json.']], 415);
    }
    $body = request_json(12000);
    $provider = clean_optional_string($body['provider'] ?? 'openai_compatible', 60, 'provider');
    if ($provider !== 'openai_compatible') {
        json_response(['error' => ['code' => 'unsupported_ai_provider', 'message' => 'This release supports OpenAI-compatible API endpoints.']], 422);
    }
    $baseUrl = clean_optional_string($body['base_url'] ?? 'https://api.openai.com/v1', 500, 'base_url');
    $model = clean_optional_string($body['model'] ?? null, 120, 'model');
    $apiKey = clean_optional_string($body['api_key'] ?? null, 2048, 'api_key');
    $enabled = $body['enabled'] ?? false;
    $maxTokens = $body['max_output_tokens'] ?? 500;
    $monthlyBudget = $body['monthly_token_budget'] ?? 0;
    if (!is_bool($enabled) || !is_int($maxTokens) || !is_int($monthlyBudget)) {
        json_response(['error' => ['code' => 'invalid_ai_settings', 'message' => 'AI enabled, output token limit and monthly budget have invalid types.']], 422);
    }
    $parts = is_string($baseUrl) ? parse_url($baseUrl) : false;
    if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])) {
        json_response(['error' => ['code' => 'invalid_ai_base_url', 'message' => 'The AI API endpoint must be a valid HTTPS URL.']], 422);
    }
    if ($model === null || $model === '') {
        json_response(['error' => ['code' => 'ai_model_required', 'message' => 'Choose a model name.']], 422);
    }
    if ($maxTokens < 64 || $maxTokens > 2000 || $monthlyBudget < 0 || $monthlyBudget > 100000000) {
        json_response(['error' => ['code' => 'invalid_ai_limits', 'message' => 'Output tokens must be 64–2000 and monthly budget must be 0–100,000,000 tokens.']], 422);
    }

    $existingQuery = $pdo->prepare('SELECT api_key_ciphertext FROM tenant_ai_settings WHERE tenant_id = :tenant_id LIMIT 1');
    $existingQuery->execute([':tenant_id' => $tenantId]);
    $existingCiphertext = $existingQuery->fetchColumn();
    $ciphertext = $apiKey !== null && $apiKey !== '' ? chatbridge_encrypt_secret($apiKey) : null;
    if ($enabled && $ciphertext === null && !is_string($existingCiphertext)) {
        json_response(['error' => ['code' => 'ai_api_key_required', 'message' => 'Enter an API key before enabling AI.']], 422);
    }

    $save = $pdo->prepare(
        'INSERT INTO tenant_ai_settings
            (tenant_id, enabled, provider, model, base_url, api_key_ciphertext,
             max_output_tokens, monthly_token_budget, created_at, updated_at)
         VALUES
            (:tenant_id, :enabled, :provider, :model, :base_url, :api_key_ciphertext,
             :max_output_tokens, :monthly_token_budget, UTC_TIMESTAMP(), UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE
            enabled = VALUES(enabled), provider = VALUES(provider), model = VALUES(model),
            base_url = VALUES(base_url),
            api_key_ciphertext = COALESCE(VALUES(api_key_ciphertext), api_key_ciphertext),
            max_output_tokens = VALUES(max_output_tokens),
            monthly_token_budget = VALUES(monthly_token_budget), updated_at = UTC_TIMESTAMP()'
    );
    $save->execute([
        ':tenant_id' => $tenantId,
        ':enabled' => $enabled ? 1 : 0,
        ':provider' => $provider,
        ':model' => $model,
        ':base_url' => rtrim($baseUrl, '/'),
        ':api_key_ciphertext' => $ciphertext,
        ':max_output_tokens' => $maxTokens,
        ':monthly_token_budget' => $monthlyBudget,
    ]);

    json_response(['ok' => true, 'enabled' => $enabled, 'has_api_key' => $ciphertext !== null || is_string($existingCiphertext)]);
} catch (InvalidArgumentException $error) {
    json_response(['error' => ['code' => 'invalid_ai_settings', 'message' => $error->getMessage()]], 422);
} catch (Throwable $error) {
    error_log('[ChatBridge AI settings] ' . $error->getMessage());
    json_response(['error' => ['code' => 'ai_settings_unavailable', 'message' => 'AI settings could not be saved. Check the private service log.']], 500);
}
