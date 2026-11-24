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
require_once dirname(__DIR__, 2) . '/app/operations/HealthService.php';

enforce_allowed_origin();
handle_preflight();
require_method('GET');

try {
    $whatsapp = app_config('whatsapp') ?? [];
    $whatsappEnabled = is_array($whatsapp) && !empty($whatsapp['outbound_enabled']);
    $service = new HealthService(health_repository(database()));
    $report = $service->report(
        extension_loaded('openssl'),
        extension_loaded('pdo_mysql'),
        $whatsappEnabled
    );

    $httpStatus = (int) $report['http_status'];
    unset($report['http_status']);

    json_response(array_merge($report, [
        'request_id' => app_request_id(),
        'service' => 'Dimdian website chat',
        'checked_at' => gmdate(DateTimeInterface::ATOM),
        'note' => 'This check does not test WordPress, WooCommerce, WCFM, Paystack, Meta connectivity or live WhatsApp delivery.',
    ]), $httpStatus);
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
        'note' => 'Ask the technical maintainer to inspect the private server error log.',
    ], 503);
}
