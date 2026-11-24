<?php
declare(strict_types=1);

/** Optional staff inbox HTTP boundary. Never include this from the visitor widget. */
function staff_inbox_guard_enabled(): void
{
    if ((app_config('staff_inbox.enabled') ?? false) !== true) {
        json_response(['error' => ['code' => 'staff_inbox_disabled', 'message' => 'The staff inbox is not enabled.']], 404);
    }
    $secret = app_config('staff_inbox.assertion_secret');
    if (!is_string($secret) || strlen($secret) < 32) {
        json_response(['error' => ['code' => 'staff_inbox_not_configured', 'message' => 'Staff authentication is not configured.']], 503);
    }
}

function staff_inbox_service(): StaffInboxService
{
    require_once __DIR__ . '/StaffInboxRepository.php';
    require_once __DIR__ . '/PdoStaffPersistenceSupport.php';
    require_once __DIR__ . '/PdoStaffIdentityRepository.php';
    require_once __DIR__ . '/PdoStaffInboxRepository.php';
    require_once __DIR__ . '/StaffIdentityProvider.php';
    require_once __DIR__ . '/HmacStaffIdentityProvider.php';
    require_once __DIR__ . '/StaffIdentityService.php';
    require_once __DIR__ . '/StaffInboxService.php';

    $secret = app_config('staff_inbox.assertion_secret');
    if (!is_string($secret) || strlen($secret) < 32) {
        throw new RuntimeException('Staff inbox assertion secret is not configured.');
    }
    $repository = new PdoStaffInboxRepository(database());
    return new StaffInboxService(
        new HmacStaffIdentityProvider($secret, $repository),
        $repository
    );
}

/** @param callable():mixed $operation */
function staff_inbox_dispatch(callable $operation): never
{
    try {
        json_response(['data' => $operation(), 'request_id' => app_request_id()]);
    } catch (StaffInboxAuthenticationException) {
        json_response(['error' => ['code' => 'unauthorized', 'message' => 'A valid staff identity is required.']], 401);
    } catch (StaffInboxAuthorizationException) {
        json_response(['error' => ['code' => 'forbidden', 'message' => 'You are not allowed to perform this staff operation.']], 403);
    } catch (ChatStorageConflict) {
        json_response(['error' => ['code' => 'conflict', 'message' => 'This request conflicts with an existing message.']], 409);
    } catch (PDOException $error) {
        $driverCode = is_array($error->errorInfo ?? null) ? (string)($error->errorInfo[1] ?? '') : '';
        app_log_event('staff_inbox.storage_failed', [
            'endpoint' => 'staff_inbox',
            'exception_class' => get_class($error),
            'reason_code' => (string)$error->getCode() . ($driverCode !== '' ? ':' . $driverCode : ''),
        ]);
        json_response(['error' => ['code' => 'storage_error', 'message' => 'The staff operation could not be completed.']], 500);
    } catch (InvalidArgumentException $error) {
        json_response(['error' => ['code' => 'invalid_request', 'message' => $error->getMessage()]], 422);
    } catch (RuntimeException) {
        json_response(['error' => ['code' => 'operation_rejected', 'message' => 'The staff operation was rejected.']], 409);
    } catch (Throwable $error) {
        app_log_event('staff_inbox.request_failed', [
            'endpoint' => 'staff_inbox',
            'exception_class' => get_class($error),
        ]);
        json_response(['error' => ['code' => 'internal_error', 'message' => 'The staff operation could not be completed.']], 500);
    }
}

function staff_inbox_required_string(array $input, string $key, int $maxLength): string
{
    $value = $input[$key] ?? null;
    if (!is_string($value)) throw new InvalidArgumentException($key . ' must be text.');
    $value = trim($value);
    $length = preg_match_all('/./us', $value);
    if ($value === '' || $length === false || $length > $maxLength) {
        throw new InvalidArgumentException($key . ' is missing or too long.');
    }
    return $value;
}

function staff_inbox_query_int(string $key, int $default, int $min, int $max): int
{
    $value = $_GET[$key] ?? $default;
    if (is_string($value) && ctype_digit($value)) $value = (int)$value;
    if (!is_int($value) || $value < $min || $value > $max) {
        throw new InvalidArgumentException($key . ' is outside the allowed range.');
    }
    return $value;
}

function staff_inbox_is_uuid(string $value): bool
{
    return (bool)preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $value);
}
