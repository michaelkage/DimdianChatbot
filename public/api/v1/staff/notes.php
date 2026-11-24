<?php
declare(strict_types=1);
require_once dirname(__DIR__, 4) . '/app/bootstrap.php';
require_once dirname(__DIR__, 4) . '/app/staff/bootstrap.php';
staff_inbox_guard_enabled();
$service = staff_inbox_service();
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'GET') {
    staff_inbox_dispatch(static function () use ($service): array {
        $conversationId = isset($_GET['conversation_id']) && is_string($_GET['conversation_id']) ? $_GET['conversation_id'] : '';
        if (!staff_inbox_is_uuid($conversationId)) throw new InvalidArgumentException('conversation_id must be a UUID.');
        return ['items' => $service->listInternalNotes(
            $_SERVER,
            $conversationId,
            staff_inbox_query_int('after', 0, 0, PHP_INT_MAX),
            staff_inbox_query_int('limit', 50, 1, 100)
        )];
    });
}
if ($method === 'POST') {
    $input = request_json(131072);
    staff_inbox_dispatch(static function () use ($service, $input): array {
        $conversationId = staff_inbox_required_string($input, 'conversation_id', 36);
        if (!staff_inbox_is_uuid($conversationId)) throw new InvalidArgumentException('conversation_id must be a UUID.');
        $body = staff_inbox_required_string($input, 'body', 10000);
        if (isset($input['id']) && !is_string($input['id'])) {
            throw new InvalidArgumentException('id must be a UUID.');
        }
        $id = isset($input['id']) ? trim($input['id']) : uuid_v4();
        if (!staff_inbox_is_uuid($id)) throw new InvalidArgumentException('id must be a UUID.');
        $created = $service->addInternalNote($_SERVER, $id, $conversationId, $body, app_request_id());
        return ['id' => $id, 'created' => $created];
    });
}
header('Allow: GET, POST');
json_response(['error' => ['code' => 'method_not_allowed', 'message' => 'Use GET or POST.']], 405);
