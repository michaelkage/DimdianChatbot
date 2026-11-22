<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/staff/StaffInboxRepository.php';
require_once dirname(__DIR__) . '/app/staff/PdoStaffInboxRepository.php';

$base = getenv('DIMDIAN_TEST_BASE_URL') ?: 'http://127.0.0.1:8080';
$secret = str_repeat('s', 64);
$pdo = new PDO(
    getenv('DIMDIAN_TEST_DSN') ?: 'mysql:host=127.0.0.1;dbname=dimdian_test;charset=utf8mb4',
    getenv('DIMDIAN_TEST_DB_USER') ?: 'root',
    getenv('DIMDIAN_TEST_DB_PASSWORD') ?: 'root',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$repository = new PdoStaffInboxRepository($pdo);
$passed = 0;
$failed = 0;

function staff_http_expect(bool $condition, string $label): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        fwrite(STDOUT, "PASS {$label}\n");
    } else {
        $failed++;
        fwrite(STDERR, "FAIL {$label}\n");
    }
}

function staff_http_b64url(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function staff_http_assertion(string $method, string $path, string $body = '', string $externalSubject = 'staff-http-owner'): string
{
    global $secret;
    $payload = [
        'auth_provider' => 'http-test',
        'external_subject' => $externalSubject,
        'display_name' => $externalSubject === 'staff-http-owner' ? 'HTTP Staff Owner' : 'HTTP Staff Agent',
        'email' => $externalSubject === 'staff-http-owner' ? 'http-staff@example.test' : 'http-agent@example.test',
        'iat' => time(),
        'nonce' => staff_http_b64url(random_bytes(32)),
        'method' => strtoupper($method),
        'target' => $path,
        'body_sha256' => hash('sha256', $body),
    ];
    $encoded = staff_http_b64url(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    return $encoded . '.' . staff_http_b64url(hash_hmac('sha256', $encoded, $secret, true));
}

function staff_http_request(string $method, string $path, ?array $body = null, bool $signed = true, string $externalSubject = 'staff-http-owner'): array
{
    global $base;
    $headers = ['Accept: application/json'];
    $content = '';
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        $content = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
    if ($signed) $headers[] = 'X-ChatBridge-Staff-Assertion: ' . staff_http_assertion($method, $path, $content, $externalSubject);
    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'content' => $content,
        'ignore_errors' => true,
        'timeout' => 10,
    ]]);
    $raw = file_get_contents($base . $path, false, $context);
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $match)) $status = (int)$match[1];
    }
    return [$status, is_string($raw) ? json_decode($raw, true) : null];
}

$guestId = uuid_v4();
$conversationId = uuid_v4();
$pdo->prepare(
    'INSERT INTO guests (id, created_at, updated_at) VALUES (:id, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
)->execute([':id' => $guestId]);
$pdo->prepare(
    'INSERT INTO conversations (id, guest_id, status, created_at, updated_at)
     VALUES (:id, :guest, "open", UTC_TIMESTAMP(), UTC_TIMESTAMP())'
)->execute([':id' => $conversationId, ':guest' => $guestId]);
$staffId = $repository->upsertStaffIdentity([
    'id' => uuid_v4(),
    'auth_provider' => 'http-test',
    'external_subject' => 'staff-http-owner',
    'display_name' => 'HTTP Staff Owner',
    'email' => 'http-staff@example.test',
]);
$pdo->prepare('UPDATE staff_identities SET role = "owner" WHERE id = :id')->execute([':id' => $staffId]);
$repository->assignConversation($conversationId, $staffId, $staffId, 'staff-http-assign');
$targetStaffId = $repository->upsertStaffIdentity([
    'id' => uuid_v4(),
    'auth_provider' => 'http-test',
    'external_subject' => 'staff-http-agent',
    'display_name' => 'HTTP Staff Agent',
    'email' => 'http-agent@example.test',
]);


[$missingAuthStatus] = staff_http_request('GET', '/api/v1/staff/inbox.php?status=open', null, false);
staff_http_expect($missingAuthStatus === 401, 'staff API rejects requests without a signed identity assertion');

[$inboxStatus, $inboxBody] = staff_http_request('GET', '/api/v1/staff/inbox.php?status=open');
[$meStatus, $meBody] = staff_http_request('GET', '/api/v1/staff/me.php');
staff_http_expect($meStatus === 200 && ($meBody['data']['role'] ?? '') === 'owner', 'staff identity endpoint returns the local role');

[$roleUpdateStatus, $roleUpdateBody] = staff_http_request('POST', '/api/v1/staff/roles.php', [
    'staff_identity_id' => $targetStaffId,
    'role' => 'admin',
    'status' => 'active',
]);
$targetAfterRoleUpdate = $repository->findStaffIdentityById($targetStaffId);
staff_http_expect(
    $roleUpdateStatus === 200
        && ($roleUpdateBody['data']['updated'] ?? false) === true
        && ($targetAfterRoleUpdate['role'] ?? '') === 'admin',
    'owner can update staff roles through the signed role-management endpoint'
);
[$adminRoleUpdateStatus] = staff_http_request('POST', '/api/v1/staff/roles.php', [
    'staff_identity_id' => $staffId,
    'role' => 'agent',
    'status' => 'active',
], true, 'staff-http-agent');
$ownerAfterDeniedUpdate = $repository->findStaffIdentityById($staffId);
staff_http_expect(
    $adminRoleUpdateStatus === 403 && ($ownerAfterDeniedUpdate['role'] ?? '') === 'owner',
    'administrator cannot change staff roles through the owner-only endpoint'
);
[$selfDemotionStatus] = staff_http_request('POST', '/api/v1/staff/roles.php', [
    'staff_identity_id' => $staffId,
    'role' => 'agent',
    'status' => 'active',
]);
$ownerAfterSelfDemotion = $repository->findStaffIdentityById($staffId);
staff_http_expect(
    $selfDemotionStatus === 409 && ($ownerAfterSelfDemotion['role'] ?? '') === 'owner',
    'owner cannot remove its own access through the HTTP role endpoint'
);

[$agentsStatus, $agentsBody] = staff_http_request('GET', '/api/v1/staff/agents.php');
staff_http_expect(
    $agentsStatus === 200
        && count(array_filter($agentsBody['data']['items'] ?? [], static fn(array $row): bool => $row['id'] === $staffId)) === 1,
    'administrator can list active staff identities'
);

staff_http_expect(
    $inboxStatus === 200
        && count(array_filter($inboxBody['data']['items'] ?? [], static fn(array $row): bool => $row['id'] === $conversationId)) === 1,
    'signed staff API returns the authenticated owner inbox'
);

$noteId = uuid_v4();
$notePayload = [
    'id' => $noteId,
    'conversation_id' => $conversationId,
    'body' => 'Private note through HTTP.',
];
[$noteStatus, $noteBody] = staff_http_request('POST', '/api/v1/staff/notes.php', $notePayload);
[$noteRetryStatus, $noteRetryBody] = staff_http_request('POST', '/api/v1/staff/notes.php', $notePayload);
staff_http_expect(
    $noteStatus === 200 && ($noteBody['data']['created'] ?? false) === true
        && $noteRetryStatus === 200 && ($noteRetryBody['data']['created'] ?? true) === false,
    'staff notes endpoint safely accepts an idempotent retry'
);
$conflictingNotePayload = $notePayload;
$conflictingNotePayload['body'] = 'Different content under the same note ID.';
[$conflictingNoteStatus] = staff_http_request('POST', '/api/v1/staff/notes.php', $conflictingNotePayload);
staff_http_expect($conflictingNoteStatus === 409, 'staff notes endpoint rejects conflicting reuse of an idempotency key');

$longNotePayload = [
    'id' => uuid_v4(),
    'conversation_id' => $conversationId,
    'body' => str_repeat("\u{1F600}", 1500),
];
[$longNoteStatus] = staff_http_request('POST', '/api/v1/staff/notes.php', $longNotePayload);
staff_http_expect($longNoteStatus === 200, 'notes endpoint accepts multi-byte text within its character limit');

[$notesStatus, $notesBody] = staff_http_request('GET', '/api/v1/staff/notes.php?conversation_id=' . $conversationId);
staff_http_expect(
    $notesStatus === 200
        && count($notesBody['data']['items'] ?? []) === 2
        && ($notesBody['data']['items'][0]['body'] ?? '') === 'Private note through HTTP.',
    'staff notes endpoint returns private notes to authenticated staff'
);

[$replyStatus, $replyBody] = staff_http_request('POST', '/api/v1/staff/replies.php', [
    'conversation_id' => $conversationId,
    'message' => 'Website reply through staff API.',
    'client_message_id' => uuid_v4(),
]);
staff_http_expect(
    $replyStatus === 200
        && ($replyBody['data']['message']['sender_type'] ?? '') === 'business'
        && ($replyBody['data']['message']['delivery_status'] ?? '') === 'web_only',
    'staff reply endpoint creates a website-only business message'
);

[$malformedSearchStatus] = staff_http_request('GET', '/api/v1/staff/inbox.php?status=open&search%5B%5D=unexpected');
staff_http_expect($malformedSearchStatus === 422, 'staff inbox rejects malformed array-valued search filters');

[$searchStatus, $searchBody] = staff_http_request('GET', '/api/v1/staff/inbox.php?status=open&search=Website%20reply%20through%20staff%20API');
staff_http_expect(
    $searchStatus === 200
        && count(array_filter($searchBody['data']['items'] ?? [], static fn(array $row): bool => $row['id'] === $conversationId)) === 1,
    'staff inbox HTTP endpoint searches conversation message content'
);

$longReplyPayload = [
    'conversation_id' => $conversationId,
    'message' => str_repeat("\u{1F600}", 1000),
    'client_message_id' => uuid_v4(),
];
[$longReplyStatus] = staff_http_request('POST', '/api/v1/staff/replies.php', $longReplyPayload);
staff_http_expect($longReplyStatus === 200, 'replies endpoint accepts multi-byte text within its character limit');

[$messagesStatus, $messagesBody] = staff_http_request('GET', '/api/v1/staff/messages.php?conversation_id=' . $conversationId);
staff_http_expect($messagesStatus === 200 && count($messagesBody['data']['items'] ?? []) === 2, 'staff messages endpoint returns the conversation thread');

[$historyStatus, $historyBody] = staff_http_request('GET', '/api/v1/staff/history.php?conversation_id=' . $conversationId);
staff_http_expect($historyStatus === 200 && count($historyBody['data']['items'] ?? []) === 1, 'staff history endpoint returns assignment history');

[$auditStatus, $auditBody] = staff_http_request('GET', '/api/v1/staff/audit.php?conversation_id=' . $conversationId);
staff_http_expect(
    $auditStatus === 200 && count($auditBody['data']['items'] ?? []) >= 3,
    'staff audit endpoint exposes assignment, note and reply events to owner'
);

fwrite(STDOUT, "\n{$passed} passed; {$failed} failed.\n");
exit($failed === 0 ? 0 : 1);
