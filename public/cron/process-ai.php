<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/whatsapp.php';
require_once dirname(__DIR__, 2) . '/app/crypto.php';
require_once dirname(__DIR__, 2) . '/app/ai.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

try {
    $tenantId = trim((string)($argv[1] ?? app_config('app.tenant_id')));
    if ($tenantId === '') {
        throw new RuntimeException('app.tenant_id must be configured for this tenant-scoped AI worker.');
    }
    $summary = process_tenant_ai_jobs(database(), $tenantId, 5, 3);
    fwrite(STDOUT, json_encode($summary, JSON_UNESCAPED_SLASHES) . PHP_EOL);
} catch (Throwable $error) {
    error_log('[ChatBridge AI worker] ' . $error->getMessage());
    fwrite(STDERR, "AI worker failed. Check the private service log for details.\n");
    exit(1);
}
