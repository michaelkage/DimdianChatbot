<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/staff/StaffInboxRepository.php';
require_once dirname(__DIR__) . '/app/staff/PdoStaffInboxRepository.php';
require_once dirname(__DIR__) . '/app/staff/StaffIdentityProvider.php';
require_once dirname(__DIR__) . '/app/staff/StaffInboxService.php';
require_once dirname(__DIR__) . '/app/staff/HmacStaffIdentityProvider.php';

$pdo = new PDO(
    getenv('DIMDIAN_TEST_DSN') ?: 'mysql:host=127.0.0.1;dbname=dimdian_test;charset=utf8mb4',
    getenv('DIMDIAN_TEST_DB_USER') ?: 'root',
    getenv('DIMDIAN_TEST_DB_PASSWORD') ?: 'root',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$repository = new PdoStaffInboxRepository($pdo);
$passed = 0;
$failed = 0;

final class StaffTestIdentityProvider implements StaffIdentityProvider
{
    public function __construct(private ?array $identity) {}

    public function authenticate(array $server): ?array
    {
        return $this->identity;
    }
}



function staff_base64url(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function staff_sign_assertion(array $payload, string $secret): string
{
    $encoded = staff_base64url(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $signature = staff_base64url(hash_hmac('sha256', $encoded, $secret, true));
    return $encoded . '.' . $signature;
}

function staff_expect(bool $condition, string $label): void
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

$guestId = uuid_v4();
$conversationId = uuid_v4();
$staffA = uuid_v4();
$staffB = uuid_v4();
$pdo->prepare(
    'INSERT INTO guests (id, created_at, updated_at) VALUES (:id, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
)->execute([':id' => $guestId]);
$pdo->prepare(
    'INSERT INTO conversations (id, guest_id, status, created_at, updated_at)
     VALUES (:id, :guest, "open", UTC_TIMESTAMP(), UTC_TIMESTAMP())'
)->execute([':id' => $conversationId, ':guest' => $guestId]);

$resolvedA = $repository->upsertStaffIdentity([
    'id' => $staffA,
    'auth_provider' => 'test-idp',
    'external_subject' => 'staff-subject-a',
    'display_name' => 'Agent A',
    'email' => 'agent-a@example.test',
]);
$resolvedB = $repository->upsertStaffIdentity([
    'id' => $staffB,
    'auth_provider' => 'test-idp',
    'external_subject' => 'staff-subject-b',
    'display_name' => 'Agent B',
    'email' => 'agent-b@example.test',
]);
$resolvedAgain = $repository->upsertStaffIdentity([
    'id' => uuid_v4(),
    'auth_provider' => 'test-idp',
    'external_subject' => 'staff-subject-a',
    'display_name' => 'Agent A Updated',
    'email' => 'agent-a@example.test',
]);
staff_expect($resolvedA === $resolvedAgain, 'external staff identity maps to a stable local ID');

$assertionSecret = str_repeat('s', 64);
$hmacProvider = new HmacStaffIdentityProvider($assertionSecret, $repository);
$assertionPayload = [
    'auth_provider' => 'signed-app',
    'external_subject' => 'hmac-staff-01',
    'display_name' => 'Signed Staff',
    'email' => 'signed@example.test',
    'iat' => time(),
    'nonce' => staff_base64url(random_bytes(32)),
    'method' => 'GET',
    'path' => '/api/v1/staff/inbox.php',
];
$assertionServer = [
    'HTTP_X_CHATBRIDGE_STAFF_ASSERTION' => staff_sign_assertion($assertionPayload, $assertionSecret),
    'REQUEST_METHOD' => 'GET',
    'REQUEST_URI' => '/api/v1/staff/inbox.php?status=open',
];
$verifiedAssertion = $hmacProvider->authenticate($assertionServer);
staff_expect(is_array($verifiedAssertion) && $verifiedAssertion['external_subject'] === 'hmac-staff-01', 'valid server-signed staff assertion is accepted');
staff_expect($hmacProvider->authenticate($assertionServer) === null, 'signed staff assertion nonce cannot be replayed');

$wrongPathPayload = array_merge($assertionPayload, ['nonce' => staff_base64url(random_bytes(32)), 'path' => '/api/v1/staff/notes.php']);
$wrongPathServer = $assertionServer;
$wrongPathServer['HTTP_X_CHATBRIDGE_STAFF_ASSERTION'] = staff_sign_assertion($wrongPathPayload, $assertionSecret);
staff_expect($hmacProvider->authenticate($wrongPathServer) === null, 'signed staff assertion cannot be replayed against another API path');

$expiredPayload = array_merge($assertionPayload, ['nonce' => staff_base64url(random_bytes(32)), 'iat' => time() - 300]);
$expiredServer = $assertionServer;
$expiredServer['HTTP_X_CHATBRIDGE_STAFF_ASSERTION'] = staff_sign_assertion($expiredPayload, $assertionSecret);
staff_expect($hmacProvider->authenticate($expiredServer) === null, 'expired staff assertion is rejected');


$repository->assignConversation($conversationId, $resolvedA, $resolvedA, 'staff-test-assign-1');
$repository->assignConversation($conversationId, $resolvedB, $resolvedA, 'staff-test-assign-2');
$allConversations = $repository->listConversations('open', null, 0, 20);
$assignedConversations = $repository->listConversations('open', $resolvedB, 0, 20);
staff_expect(count(array_filter($allConversations, static fn(array $row): bool => $row['id'] === $conversationId)) === 1, 'inbox list includes an open conversation');
staff_expect(count(array_filter($assignedConversations, static fn(array $row): bool => $row['id'] === $conversationId)) === 1, 'agent inbox filter returns only conversations assigned to that agent');
$assignmentCounts = $pdo->prepare(
    'SELECT COUNT(*) AS total, SUM(ended_at IS NULL) AS active
     FROM staff_conversation_assignments WHERE conversation_id = :conversation'
);
$assignmentCounts->execute([':conversation' => $conversationId]);
$assignmentState = $assignmentCounts->fetch();
staff_expect((int)$assignmentState['total'] === 2 && (int)$assignmentState['active'] === 1, 'reassignment retains history and enforces one active assignee');

$noteId = uuid_v4();
$repository->addInternalNote($noteId, $conversationId, $resolvedA, 'Internal-only note.', 'staff-test-note');
$notes = $repository->listInternalNotes($conversationId, 0, 20);
$noteFound = count($notes) === 1 && $notes[0]['body'] === 'Internal-only note.';
$messageCount = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE conversation_id = :conversation');
$messageCount->execute([':conversation' => $conversationId]);
staff_expect($noteFound && (int)$messageCount->fetchColumn() === 0, 'internal notes are stored separately from visitor-visible messages');
$assignmentHistory = $repository->listAssignmentHistory($conversationId, 0, 20);
staff_expect(count($assignmentHistory) === 2, 'assignment history is queryable without overwriting prior assignees');

$reply = [
    'id' => uuid_v4(),
    'conversation_id' => $conversationId,
    'client_message_id' => uuid_v4(),
    'body' => 'A reply from the staff inbox.',
];
$created = $repository->createStaffReply($reply, $resolvedB, 'staff-test-reply');
$replayed = $repository->createStaffReply(array_merge($reply, ['id' => uuid_v4()]), $resolvedB, 'staff-test-reply-replay');
$author = $pdo->prepare('SELECT staff_identity_id FROM staff_message_authors WHERE message_id = :id');
$author->execute([':id' => $created['message']['id']]);
$outbox = $pdo->prepare('SELECT COUNT(*) FROM message_outbox WHERE message_id = :id');
$outbox->execute([':id' => $created['message']['id']]);
$visitorPoll = chat_repository($pdo)->listConversationMessages($conversationId, 0, 50);
staff_expect(count($visitorPoll['items']) === 1 && $visitorPoll['items'][0]['message'] === 'A reply from the staff inbox.', 'visitor message polling returns staff replies but never private notes');
staff_expect(
    $created['inserted'] === true
        && $created['message']['sender_type'] === 'business'
        && $created['message']['delivery_status'] === 'web_only'
        && $replayed['inserted'] === false
        && $author->fetchColumn() === $resolvedB
        && (int)$outbox->fetchColumn() === 0,
    'staff reply is idempotent, visitor-visible, attributed and website-only'
);

$conflict = false;
try {
    $repository->createStaffReply(array_merge($reply, ['id' => uuid_v4(), 'body' => 'Different content']), $resolvedB);
} catch (ChatStorageConflict) {
    $conflict = true;
}
staff_expect($conflict, 'staff reply idempotency rejects reuse with different content');

$audit = $repository->listAuditEvents($conversationId, 0, 50);
$eventTypes = array_column($audit, 'event_type');
staff_expect(
    in_array('conversation.assigned', $eventTypes, true)
        && in_array('note.created', $eventTypes, true)
        && in_array('message.staff_reply_created', $eventTypes, true),
    'assignment, private note and staff reply create audit events'
);

staff_expect($repository->unassignConversation($conversationId, $resolvedA, 'staff-test-unassign'), 'active assignment can be ended');
staff_expect(!$repository->unassignConversation($conversationId, $resolvedA, 'staff-test-unassign-again'), 'unassigning an unassigned conversation is idempotent');

$secondGuestId = uuid_v4();
$secondConversationId = uuid_v4();
$pdo->prepare(
    'INSERT INTO guests (id, created_at, updated_at) VALUES (:id, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
)->execute([':id' => $secondGuestId]);
$pdo->prepare(
    'INSERT INTO conversations (id, guest_id, status, created_at, updated_at)
     VALUES (:id, :guest, "open", UTC_TIMESTAMP(), UTC_TIMESTAMP())'
)->execute([':id' => $secondConversationId, ':guest' => $secondGuestId]);

$pdo->prepare('UPDATE staff_identities SET role = "owner" WHERE id = :id')->execute([':id' => $resolvedA]);
$agentService = new StaffInboxService(new StaffTestIdentityProvider([
    'auth_provider' => 'test-idp',
    'external_subject' => 'staff-subject-b',
    'display_name' => 'Agent B',
    'email' => 'agent-b@example.test',
    'role' => 'owner', // Untrusted external role claims must be ignored.
]), $repository);
$ownerService = new StaffInboxService(new StaffTestIdentityProvider([
    'auth_provider' => 'test-idp',
    'external_subject' => 'staff-subject-a',
    'display_name' => 'Agent A',
    'email' => 'agent-a@example.test',
]), $repository);
$agentServer = ['HTTP_AUTHORIZATION' => 'Bearer test-agent-token'];
$ownerServer = ['HTTP_AUTHORIZATION' => 'Bearer test-owner-token'];
$agentIdentity = $agentService->authenticate($agentServer);
$agentList = $agentService->listConversations($agentServer, 'open', 0, 50);
staff_expect($agentIdentity['role'] === 'agent', 'local staff role overrides untrusted external role claims');
staff_expect(count($agentList) === 1 && $agentList[0]['id'] === $conversationId, 'agent inbox is scoped to the current agent assignment');

$agentCannotReassign = false;
try {
    $agentService->assignConversation($agentServer, $conversationId, $resolvedA);
} catch (StaffInboxAuthorizationException) {
    $agentCannotReassign = true;
}
staff_expect($agentCannotReassign, 'agent cannot assign a conversation to another staff member');

$agentCannotReadUnassigned = false;
try {
    $agentService->addInternalNote($agentServer, uuid_v4(), $secondConversationId, 'No access.');
} catch (StaffInboxAuthorizationException) {
    $agentCannotReadUnassigned = true;
}
staff_expect($agentCannotReadUnassigned, 'agent cannot add notes to an unassigned conversation');
$agentService->assignConversation($agentServer, $secondConversationId, $resolvedB, 'staff-agent-self-assign');
staff_expect($repository->getActiveAssignee($secondConversationId) === $resolvedB, 'agent can self-assign an unassigned conversation');

$ownerList = $ownerService->listConversations($ownerServer, 'open', 0, 50);
staff_expect(count($ownerList) >= 2, 'owner can list all conversations across the installation');
$ownerService->addInternalNote($ownerServer, uuid_v4(), $secondConversationId, 'Owner note.');
staff_expect(count($repository->listInternalNotes($secondConversationId, 0, 10)) === 1, 'owner can manage an unassigned conversation');

$agentCannotReadAudit = false;
try {
    $agentService->listAuditEvents($agentServer, $conversationId, 0, 50);
} catch (StaffInboxAuthorizationException) {
    $agentCannotReadAudit = true;
}
staff_expect($agentCannotReadAudit, 'agents cannot inspect the operational audit trail');

$noIdentityService = new StaffInboxService(new StaffTestIdentityProvider(null), $repository);
$unauthenticatedRejected = false;
try {
    $noIdentityService->listConversations([], 'open', 0, 10);
} catch (StaffInboxAuthenticationException) {
    $unauthenticatedRejected = true;
}
staff_expect($unauthenticatedRejected, 'missing verified external identity fails closed');

$pdo->prepare('UPDATE staff_identities SET status = "disabled" WHERE id = :id')->execute([':id' => $resolvedB]);
$disabledRejected = false;
try {
    $repository->addInternalNote(uuid_v4(), $conversationId, $resolvedB, 'Should not be accepted.');
} catch (RuntimeException) {
    $disabledRejected = true;
}
staff_expect($disabledRejected, 'disabled staff identities cannot create private notes');

fwrite(STDOUT, "\n{$passed} passed; {$failed} failed.\n");
exit($failed === 0 ? 0 : 1);
