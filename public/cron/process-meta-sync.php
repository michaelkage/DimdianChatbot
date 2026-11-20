<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/whatsapp.php';
require_once dirname(__DIR__, 2) . '/app/crypto.php';
require_once dirname(__DIR__, 2) . '/app/meta.php';

try {
    $tenantId = trim((string)($argv[1] ?? app_config('app.tenant_id')));
    if ($tenantId === '') throw new RuntimeException('Pass a tenant UUID to the Meta sync worker.');
    $summary = process_meta_coexistence_sync_jobs(database(), $tenantId, 10);
    fwrite(STDOUT, json_encode($summary, JSON_UNESCAPED_SLASHES) . PHP_EOL);
} catch (Throwable $error) {
    error_log('[ChatBridge Meta sync worker] ' . $error->getMessage());
    fwrite(STDERR, "Meta sync worker failed. Check the private service log.\n");
    exit(1);
}
