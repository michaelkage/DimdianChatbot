<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/maintenance/RetentionService.php';

try {
    $service = new RetentionService(retention_repository(database()), app_config());
    $summary = $service->run();
    fwrite(STDOUT, json_encode($summary, JSON_UNESCAPED_SLASHES) . PHP_EOL);
} catch (Throwable $error) {
    app_log_event('retention.job_failed', [
        'endpoint' => 'retention',
        'exception_class' => get_class($error),
    ]);
    fwrite(STDERR, "Retention job failed. Check the private server log.\n");
    exit(1);
}
