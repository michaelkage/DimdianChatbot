<?php
declare(strict_types=1);
require_once dirname(__DIR__, 4) . '/app/bootstrap.php';
require_once dirname(__DIR__, 4) . '/app/staff/bootstrap.php';
staff_inbox_guard_enabled();
require_method('POST');
$input = request_json(4096);
staff_inbox_dispatch(static function () use ($input): array {
    $conversationId = staff_inbox_required_string($input, 'conversation_id', 36);
    if (!preg_match('/^[a-f0-9-]{36}$/i', $conversationId)) throw new InvalidArgumentException('conversation_id must be a UUID.');
    $action = (string)($input['action'] ?? 'assign');
    $service = staff_inbox_service();
    if ($action === 'unassign') {
        return ['unassigned' => $service->unassignConversation($_SERVER, $conversationId, app_request_id())];
    }
    if ($action !== 'assign') throw new InvalidArgumentException('action must be assign or unassign.');
    $staffId = staff_inbox_required_string($input, 'staff_identity_id', 36);
    if (!preg_match('/^[a-f0-9-]{36}$/i', $staffId)) throw new InvalidArgumentException('staff_identity_id must be a UUID.');
    $service->assignConversation($_SERVER, $conversationId, $staffId, app_request_id());
    return ['assigned' => true];
});
