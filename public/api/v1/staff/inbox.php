<?php
declare(strict_types=1);
require_once dirname(__DIR__, 4) . '/app/bootstrap.php';
require_once dirname(__DIR__, 4) . '/app/staff/bootstrap.php';
staff_inbox_guard_enabled();
require_method('GET');
staff_inbox_dispatch(static function (): array {
    if (isset($_GET['status']) && !is_string($_GET['status'])) {
        throw new InvalidArgumentException('status must be text.');
    }
    if (isset($_GET['search']) && !is_string($_GET['search'])) {
        throw new InvalidArgumentException('search must be text.');
    }
    $status = isset($_GET['status']) ? $_GET['status'] : 'open';
    $after = staff_inbox_query_int('after', 0, 0, PHP_INT_MAX);
    $limit = staff_inbox_query_int('limit', 50, 1, 100);
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $searchLength = preg_match_all('/./us', $search);
    if ($searchLength === false || $searchLength > 100) throw new InvalidArgumentException('search must be 100 characters or fewer.');
    return ['items' => staff_inbox_service()->listConversations($_SERVER, $status, $after, $limit, $search)];
});
