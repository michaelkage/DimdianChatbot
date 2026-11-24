<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/chat/WhatsAppConsentService.php';

enforce_allowed_origin();
handle_preflight();
require_method('POST');

if (strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0])) !== 'application/json') {
    json_response(['error' => ['code' => 'unsupported_media_type', 'message' => 'Content-Type must be application/json.']], 415);
}

try {
    $repository = chat_repository();
    $session = authenticate_guest_session($repository, false);
    $body = request_json(4096);
    $optIn = $body['whatsapp_opt_in'] ?? null;
    if (!is_bool($optIn)) {
        json_response(['error' => ['code' => 'invalid_field', 'message' => 'whatsapp_opt_in must be true or false.']], 422);
    }

    $service = new WhatsAppConsentService($repository);
    try {
        $result = $service->update($session, $optIn);
    } catch (WhatsAppConsentPhoneRequired $error) {
        json_response(['error' => ['code' => 'whatsapp_phone_required', 'message' => $error->getMessage()]], 422);
    }

    json_response(['data' => $result]);
} catch (Throwable $error) {
    app_log_event('consent.update_failed', [
        'endpoint' => 'consent',
        'exception_class' => get_class($error),
    ]);
    json_response(['error' => ['code' => 'server_error', 'message' => 'Your privacy setting could not be updated. Please try again.']], 500);
}
