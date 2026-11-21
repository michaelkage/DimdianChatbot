<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/whatsapp.php';
require_once dirname(__DIR__, 2) . '/app/consent.php';

enforce_allowed_origin();
handle_preflight();
require_method('POST');
if (strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0])) !== 'application/json') {
    json_response(['error' => ['code' => 'unsupported_media_type', 'message' => 'Content-Type must be application/json.']], 415);
}

try {
    $pdo = database();
    $repository = chat_repository($pdo);
    $session = authenticate_guest_session($repository, false);
    $body = request_json(4096);
    $optIn = $body['whatsapp_opt_in'] ?? null;
    if (!is_bool($optIn)) {
        json_response(['error' => ['code' => 'invalid_field', 'message' => 'whatsapp_opt_in must be true or false.']], 422);
    }
    $pdo->beginTransaction();
    if ($optIn) {
        $phone = (string) ($session['phone_normalized'] ?? '');
        if ($phone === '') {
            $pdo->rollBack();
            json_response(['error' => ['code' => 'whatsapp_phone_required', 'message' => 'A WhatsApp number must be added before opting in.']], 422);
        }
        record_whatsapp_consent($pdo, (string) $session['guest_id'], (string) $session['conversation_id'], $phone, 'opt_in', 'consent_settings');
    } else {
        record_whatsapp_consent($pdo, (string) $session['guest_id'], (string) $session['conversation_id'], (string) ($session['phone_normalized'] ?? ''), 'opt_out', 'consent_settings');
    }
    $pdo->commit();
    json_response(['data' => ['whatsapp_opt_in' => $optIn, 'updated_at' => gmdate(DateTimeInterface::ATOM)]]);
} catch (Throwable $error) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    app_log_event('consent.update_failed', ['endpoint' => 'consent', 'exception_class' => get_class($error)]);
    json_response(['error' => ['code' => 'server_error', 'message' => 'Your privacy setting could not be updated. Please try again.']], 500);
}
