<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/whatsapp.php';
require_once dirname(__DIR__, 2) . '/app/consent.php';
require_once dirname(__DIR__, 2) . '/app/chat/GuestSessionService.php';

enforce_allowed_origin();
handle_preflight();
require_method('POST');

$contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
if ($contentType !== 'application/json') {
    json_response(['error' => ['code' => 'unsupported_media_type', 'message' => 'Content-Type must be application/json.']], 415);
}

$body = request_json();
$name = clean_optional_string($body['name'] ?? null, 100, 'name');
$phone = clean_optional_string($body['phone'] ?? null, 32, 'phone');
$whatsappOptIn = $body['whatsapp_opt_in'] ?? false;
if (!is_bool($whatsappOptIn)) {
    json_response(['error' => ['code' => 'invalid_field', 'message' => 'whatsapp_opt_in must be true or false.']], 422);
}
$normalizedPhone = $phone !== null ? normalize_whatsapp_number($phone) : null;
if ($phone !== null) {
    $phoneInput = preg_replace('/[\\s().-]+/', '', $phone) ?? '';
    $validInternationalInput = preg_match('/^(?:\\+[1-9][0-9]{7,14}|00[1-9][0-9]{7,14})$/', $phoneInput) === 1;
    if (
        !$validInternationalInput
        || $normalizedPhone === ''
        || !preg_match('/^[1-9][0-9]{7,14}$/', $normalizedPhone)
    ) {
        json_response(['error' => ['code' => 'invalid_field', 'message' => 'Enter a valid international phone number with its country code, such as +2348012345678.']], 422);
    }
}
if ($whatsappOptIn && $normalizedPhone === null) {
    json_response(['error' => ['code' => 'whatsapp_phone_required', 'message' => 'Enter your WhatsApp number to opt in.']], 422);
}

try {
    $tenantContext = tenant_context_from_request();
    $service = new GuestSessionService(chat_repository(), app_config(), $tenantContext);
    $session = $service->create([
        'name' => $name,
        'phone' => $phone,
        'phone_normalized' => $normalizedPhone,
        'whatsapp_opt_in' => $whatsappOptIn,
    ], client_fingerprint());

    json_response(['data' => $session], 201);
} catch (GuestSessionRateLimitExceeded $error) {
    header('Retry-After: ' . $error->retryAfterSeconds);
    json_response([
        'error' => [
            'code' => 'rate_limited',
            'message' => 'Too many attempts. Please wait a little and try again.',
        ],
    ], 429);
} catch (Throwable $error) {
    app_log_event('session.create_failed', [
        'endpoint' => 'session',
        'exception_class' => get_class($error),
    ]);
    json_response(['error' => ['code' => 'server_error', 'message' => 'A chat session could not be started. Please try again.']], 500);
}
