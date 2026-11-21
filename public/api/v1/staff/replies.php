<?php
declare(strict_types=1);
require_once dirname(__DIR__, 4) . '/app/bootstrap.php';
require_once dirname(__DIR__, 4) . '/app/staff/bootstrap.php';
staff_inbox_guard_enabled();
require_method('POST');
$input = request_json(8192);
staff_inbox_dispatch(static function () use ($input): array {
    $conversationId = staff_inbox_required_string($input, 'conversation_id', 36);
    if (!staff_inbox_is_uuid($1)) throw new InvalidArgumentException('conversation_id must be a UUID.');
    $body = staff_inbox_required_string($input, 'message', 5000);
    $clientMessageId = staff_inbox_required_string($input, 'client_message_id', 36);
    if (!preg_match('/^[a-f0-9-]{36}$/i', $clientMessageId)) throw new InvalidArgumentException('client_message_id must be a UUID.');
    return staff_inbox_service()->createStaffReply($_SERVER, [
        'id' => uuid_v4(),
        'conversation_id' => $conversationId,
        'client_message_id' => $clientMessageId,
        'body' => $body,
    ], app_request_id());
});
