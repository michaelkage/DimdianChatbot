<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/crypto.php';

$tenantId = trim((string)($argv[1] ?? ''));
$model = trim((string)($argv[2] ?? ''));
$baseUrl = rtrim(trim((string)($argv[3] ?? '')), '/');
$monthlyBudget = max(0, (int)($argv[4] ?? 0));
$apiKey = (string)getenv('CHATBRIDGE_AI_API_KEY');
$parts = parse_url($baseUrl);

if (!preg_match('/^[0-9a-f-]{36}$/i', $tenantId) || $model === '' || strlen($model) > 120
    || !is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
    || empty($parts['host']) || $apiKey === '') {
    fwrite(STDERR, "Usage: CHATBRIDGE_AI_API_KEY=... php bin/configure-ai.php TENANT_UUID model https://provider.example/v1 [monthly_token_budget]\n");
    exit(2);
}

try {
    $pdo = database();
    $encrypted = chatbridge_encrypt_secret($apiKey);
    $statement = $pdo->prepare(
        'INSERT INTO tenant_ai_settings
            (tenant_id, enabled, provider, model, base_url, api_key_ciphertext, api_key_key_version, max_output_tokens, monthly_token_budget, created_at, updated_at)
         VALUES (:tenant_id, 1, "openai_compatible", :model, :base_url, :api_key, "v1", 500, :budget, UTC_TIMESTAMP(), UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE enabled = 1, provider = "openai_compatible", model = VALUES(model),
            base_url = VALUES(base_url), api_key_ciphertext = VALUES(api_key_ciphertext),
            api_key_key_version = "v1", monthly_token_budget = VALUES(monthly_token_budget),
            updated_at = UTC_TIMESTAMP()'
    );
    $statement->execute([
        ':tenant_id' => $tenantId,
        ':model' => $model,
        ':base_url' => $baseUrl,
        ':api_key' => $encrypted,
        ':budget' => $monthlyBudget,
    ]);
    fwrite(STDOUT, "AI provider configured for tenant {$tenantId}; API key stored encrypted.\n");
} catch (Throwable $error) {
    fwrite(STDERR, "AI configuration failed: " . $error->getMessage() . "\n");
    exit(1);
}
