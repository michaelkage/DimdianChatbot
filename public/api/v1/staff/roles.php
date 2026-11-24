<?php
declare(strict_types=1);
require_once dirname(__DIR__, 4) . '/app/bootstrap.php';
require_once dirname(__DIR__, 4) . '/app/staff/bootstrap.php';
staff_inbox_guard_enabled();
require_method('POST');
$input = request_json(4096);
staff_inbox_dispatch(static function () use ($input): array {
    $staffId = staff_inbox_required_string($input, 'staff_identity_id', 36);
    if (!staff_inbox_is_uuid($staffId)) throw new InvalidArgumentException('staff_identity_id must be a UUID.');
    $role = staff_inbox_required_string($input, 'role', 5);
    $status = staff_inbox_required_string($input, 'status', 8);
    staff_inbox_service()->updateStaffIdentityAccess($_SERVER, $staffId, $role, $status, app_request_id());
    return ['updated' => true];
});
