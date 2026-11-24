<?php
declare(strict_types=1);
require_once dirname(__DIR__, 4) . '/app/bootstrap.php';
require_once dirname(__DIR__, 4) . '/app/staff/bootstrap.php';
staff_inbox_guard_enabled();
require_method('GET');
staff_inbox_dispatch(static function (): array {
    return ['items' => staff_inbox_service()->listStaffIdentities($_SERVER)];
});
