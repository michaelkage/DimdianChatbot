<?php
declare(strict_types=1);
require_once dirname(__DIR__, 4) . '/app/bootstrap.php';
require_once dirname(__DIR__, 4) . '/app/staff/bootstrap.php';
staff_inbox_guard_enabled();
require_method('GET');
staff_inbox_dispatch(static function (): array {
    $conversationId = isset($_GET['conversation_id']) && is_string($_GET['conversation_id']) ? $_GET['conversation_id'] : '';
    if (!preg_match('/^[a-f0-9-]{36}$/i', $conversationId)) throw new InvalidArgumentException('conversation_id must be a UUID.');
    return ['items' => staff_inbox_service()->listAuditEvents(
        $_SERVER,
        $conversationId,
        staff_inbox_query_int('after', 0, 0, PHP_INT_MAX),
        staff_inbox_query_int('limit', 50, 1, 100)
    )];
});
