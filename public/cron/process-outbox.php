<?php
declare(strict_types=1);

// This endpoint is intentionally CLI-only. Run it from the hosting cron scheduler.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/whatsapp.php';
require_once dirname(__DIR__, 2) . '/app/meta_signup.php';

$config = app_config('whatsapp') ?? [];
if (!is_array($config) || empty($config['outbound_enabled'])) {
    fwrite(STDOUT, "WhatsApp outbound transport is disabled; no jobs processed.\n");
    exit(0);
}

try {
    $config = load_meta_whatsapp_config(database(), $config);
    $transport = new MetaCloudApiTransport($config);
    $summary = process_whatsapp_outbox(database(), $transport, 10, 6);
    fwrite(STDOUT, json_encode($summary, JSON_UNESCAPED_SLASHES) . PHP_EOL);
} catch (Throwable $error) {
    error_log('[DimdianChatbot] Outbox worker failed: ' . $error->getMessage());
    fwrite(STDERR, "Outbox worker failed. Check the server error log.\n");
    exit(1);
}
