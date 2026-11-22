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

/** @return array{status:int,stdout:string,stderr:string} */
function staff_run_cli(array $arguments): array
{
    $command = array_merge([PHP_BINARY], $arguments);
    $pipes = [];
    $process = proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        dirname(__DIR__)
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start CLI role-provisioning test process.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [
        'status' => proc_close($process),
        'stdout' => is_string($stdout) ? $stdout : '',
        'stderr' => is_string($stderr) ? $stderr : '',
    ];
}

$guestId = uuid_v4();
$conversationId = uuid_v4();
$staffA = uuid_v4();
$staffB = uuid_v4();
$pdo->prepare(
    'INSERT INTO guests (id, created_at, updated_at) VALUES (:id, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
)->execute([':id' => $guestId]);
$pdo->prepare('UPDATE guests SET display_name = :name, phone = :phone WHERE id = :id')
    ->execute([':name' => 'Search Visitor', ':phone' => '+2348012345678', ':id' => $guestId]);
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
    'target' => '/api/v1/staff/inbox.php?status=open',
    'body_sha256' => hash('sha256', ''),
];
$assertionServer = [
    'HTTP_X_CHATBRIDGE_STAFF_ASSERTION' => staff_sign_assertion($assertionPayload, $assertionSecret),
    'REQUEST_METHOD' => 'GET',
    'REQUEST_URI' => '/api/v1/staff/inbox.php?status=open',
    'CHATBRIDGE_RAW_BODY' => '',
];
$verifiedAssertion = $hmacProvider->authenticate($assertionServer);
staff_expect(is_array($verifiedAssertion) && $verifiedAssertion['external_subject'] === 'hmac-staff-01', 'valid server-signed staff assertion is accepted');
staff_expect($hmacProvider->authenticate($assertionServer) === null, 'signed staff assertion nonce cannot be replayed');

$wideSkewProvider = new HmacStaffIdentityProvider($assertionSecret, $repository, 300);
$futureIssuedAt = time() + 240;
$futureNonce = staff_base64url(random_bytes(32));
$futurePayload = array_merge($assertionPayload, [
    'iat' => $futureIssuedAt,
    'nonce' => $futureNonce,
]);
$futureServer = $assertionServer;
$futureServer['HTTP_X_CHATBRIDGE_STAFF_ASSERTION'] = staff_sign_assertion($futurePayload, $assertionSecret);
$futureAccepted = $wideSkewProvider->authenticate($futureServer);
$futureNonceQuery = $pdo->prepare('SELECT expires_at FROM staff_auth_nonces WHERE nonce_sha256 = :nonce');
$futureNonceQuery->execute([':nonce' => hash('sha256', $futureNonce)]);
$futureNonceExpiry = $futureNonceQuery->fetchColumn();
staff_expect(
    is_array($futureAccepted) && is_string($futureNonceExpiry)
        && strtotime($futureNonceExpiry . ' UTC') >= $futureIssuedAt + 300,
    'assertion nonce retention covers the full future clock-skew acceptance window'
);
staff_expect($wideSkewProvider->authenticate($futureServer) === null, 'future-dated accepted assertion remains non-replayable');

$wrongPathPayload = array_merge($assertionPayload, ['nonce' => staff_base64url(random_bytes(32)), 'target' => '/api/v1/staff/notes.php?status=open']);
$wrongPathServer = $assertionServer;
$wrongPathServer['HTTP_X_CHATBRIDGE_STAFF_ASSERTION'] = staff_sign_assertion($wrongPathPayload, $assertionSecret);
staff_expect($hmacProvider->authenticate($wrongPathServer) === null, 'signed staff assertion cannot be replayed against another API path');

$wrongQueryPayload = array_merge($assertionPayload, ['nonce' => staff_base64url(random_bytes(32)), 'target' => '/api/v1/staff/inbox.php?status=closed']);
$wrongQueryServer = $assertionServer;
$wrongQueryServer['HTTP_X_CHATBRIDGE_STAFF_ASSERTION'] = staff_sign_assertion($wrongQueryPayload, $assertionSecret);
staff_expect($hmacProvider->authenticate($wrongQueryServer) === null, 'signed staff assertion cannot be replayed with a different query string');

$wrongBodyPayload = array_merge($assertionPayload, ['nonce' => staff_base64url(random_bytes(32)), 'body_sha256' => hash('sha256', '{"changed":true}')]);
$wrongBodyServer = $assertionServer;
$wrongBodyServer['HTTP_X_CHATBRIDGE_STAFF_ASSERTION'] = staff_sign_assertion($wrongBodyPayload, $assertionSecret);
$wrongBodyServer['CHATBRIDGE_RAW_BODY'] = '{"original":true}';
staff_expect($hmacProvider->authenticate($wrongBodyServer) === null, 'signed staff assertion rejects a modified request body');

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
$firstNoteCreated = $repository->addInternalNote($noteId, $conversationId, $resolvedA, 'Internal-only note.', 'staff-test-note');
$retryNoteCreated = $repository->addInternalNote($noteId, $conversationId, $resolvedA, 'Internal-only note.', 'staff-test-note-retry');
staff_expect($firstNoteCreated && !$retryNoteCreated, 'note repository distinguishes first insert from an idempotent replay');
$notes = $repository->listInternalNotes($conversationId, 0, 20);
$noteFound = count($notes) === 1 && $notes[0]['body'] === 'Internal-only note.';
$noteConflict = false;
try {
    $repository->addInternalNote($noteId, $conversationId, $resolvedA, 'Changed note content.', 'staff-test-note-conflict');
} catch (ChatStorageConflict) {
    $noteConflict = true;
}
staff_expect($noteConflict, 'note idempotency rejects reuse with different content');
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
$crossAuthorReplayRejected = false;
try {
    $repository->createStaffReply(array_merge($reply, ['id' => uuid_v4()]), $resolvedA, 'staff-test-cross-author-replay');
} catch (ChatStorageConflict) {
    $crossAuthorReplayRejected = true;
}
staff_expect($crossAuthorReplayRejected, 'staff reply idempotency keys cannot be replayed by another staff author');
$author = $pdo->prepare('SELECT staff_identity_id FROM staff_message_authors WHERE message_id = :id');
$author->execute([':id' => $created['message']['id']]);
$outbox = $pdo->prepare('SELECT COUNT(*) FROM message_outbox WHERE message_id = :id');
$outbox->execute([':id' => $created['message']['id']]);
$visitorPoll = chat_repository($pdo)->listConversationMessages($conversationId, 0, 50);
staff_expect(count($visitorPoll['items']) === 1 && $visitorPoll['items'][0]['message'] === 'A reply from the staff inbox.', 'visitor message polling returns staff replies but never private notes');
$staffMessages = $repository->listConversationMessages($conversationId, 0, 50);
$staffDirectory = $repository->listStaffIdentities();
staff_expect(count($staffMessages['items']) === 1 && $staffMessages['items'][0]['body'] === 'A reply from the staff inbox.', 'staff message repository returns conversation messages');
staff_expect(count($staffDirectory) >= 2, 'staff directory repository lists active identities');

staff_expect(
    $created['inserted'] === true
        && $created['message']['sender_type'] === 'business'
        && $created['message']['delivery_status'] === 'web_only'
        && $replayed['inserted'] === false
        && !array_key_exists('staff_author_id', $replayed['message'])
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
$pdo->prepare('UPDATE guests SET display_name = :name WHERE id = :id')
    ->execute([':name' => 'Unassigned Visitor', ':id' => $secondGuestId]);
$pdo->prepare(
    'INSERT INTO conversations (id, guest_id, status, created_at, updated_at)
     VALUES (:id, :guest, "open", UTC_TIMESTAMP(), UTC_TIMESTAMP())'
)->execute([':id' => $secondConversationId, ':guest' => $secondGuestId]);

$thirdGuestId = uuid_v4();
$thirdConversationId = uuid_v4();
$pdo->prepare(
    'INSERT INTO guests (id, created_at, updated_at) VALUES (:id, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
)->execute([':id' => $thirdGuestId]);
$pdo->prepare('UPDATE guests SET display_name = :name WHERE id = :id')
    ->execute([':name' => 'Owner Only Visitor', ':id' => $thirdGuestId]);
$pdo->prepare(
    'INSERT INTO conversations (id, guest_id, status, created_at, updated_at)
     VALUES (:id, :guest, "open", UTC_TIMESTAMP(), UTC_TIMESTAMP())'
)->execute([':id' => $thirdConversationId, ':guest' => $thirdGuestId]);

$repository->assignConversation($conversationId, $resolvedB, $resolvedA, 'staff-service-reassign');
$repository->assignConversation($thirdConversationId, $resolvedA, $resolvedA, 'staff-owner-assignment');
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
$agentCannotManageRoles = false;
try {
    $agentService->updateStaffIdentityAccess($agentServer, $resolvedA, 'admin', 'active');
} catch (StaffInboxAuthorizationException) {
    $agentCannotManageRoles = true;
}
staff_expect($agentCannotManageRoles, 'agents cannot manage staff roles or account status');

$ownerService->updateStaffIdentityAccess($ownerServer, $resolvedB, 'admin', 'active', 'staff-test-promote');
$promoted = $repository->findStaffIdentityById($resolvedB);
staff_expect(($promoted['role'] ?? '') === 'admin', 'owner can promote a staff identity in product policy');
$ownerService->updateStaffIdentityAccess($ownerServer, $resolvedB, 'agent', 'active', 'staff-test-demote');
$restored = $repository->findStaffIdentityById($resolvedB);
staff_expect(($restored['role'] ?? '') === 'agent', 'owner can change a staff identity back to agent');

$lastOwnerProtected = false;
try {
    $ownerService->updateStaffIdentityAccess($ownerServer, $resolvedA, 'admin', 'active', 'staff-test-last-owner');
} catch (RuntimeException) {
    $lastOwnerProtected = true;
}
$stillOwner = $repository->findStaffIdentityById($resolvedA);
staff_expect($lastOwnerProtected && ($stillOwner['role'] ?? '') === 'owner', 'only active owner cannot remove its own owner access');

$ownerService->updateStaffIdentityAccess($ownerServer, $resolvedB, 'owner', 'active', 'staff-test-second-owner');
$selfDemotionRejected = false;
try {
    $ownerService->updateStaffIdentityAccess($ownerServer, $resolvedA, 'agent', 'active', 'staff-test-self-demotion');
} catch (RuntimeException) {
    $selfDemotionRejected = true;
}
$selfDisableRejected = false;
try {
    $ownerService->updateStaffIdentityAccess($ownerServer, $resolvedA, 'owner', 'disabled', 'staff-test-self-disable');
} catch (RuntimeException) {
    $selfDisableRejected = true;
}
$selfStillOwner = $repository->findStaffIdentityById($resolvedA);
staff_expect(
    $selfDemotionRejected && $selfDisableRejected
        && ($selfStillOwner['role'] ?? '') === 'owner'
        && ($selfStillOwner['status'] ?? '') === 'active',
    'owner cannot demote or disable its own account even when another owner exists'
);
$ownerService->updateStaffIdentityAccess($ownerServer, $resolvedB, 'agent', 'active', 'staff-test-remove-second-owner');

$searchResults = $ownerService->listConversations($ownerServer, 'open', 0, 50, 'A reply from the staff inbox.');
staff_expect(
    count(array_filter($searchResults, static fn(array $row): bool => $row['id'] === $conversationId)) === 1,
    'conversation search finds conversations by message body'
);
$privateNoteSearch = $ownerService->listConversations($ownerServer, 'open', 0, 50, 'Internal-only note.');
staff_expect($privateNoteSearch === [], 'conversation search never indexes private internal-note bodies');
$nameSearch = $ownerService->listConversations($ownerServer, 'open', 0, 50, 'Search Visitor');
$phoneSearch = $ownerService->listConversations($ownerServer, 'open', 0, 50, '+2348012345678');
$wildcardSearch = $ownerService->listConversations($ownerServer, 'open', 0, 50, '%');
staff_expect(count(array_filter($nameSearch, static fn(array $row): bool => $row['id'] === $conversationId)) === 1, 'conversation search finds visitor names');
staff_expect(count(array_filter($phoneSearch, static fn(array $row): bool => $row['id'] === $conversationId)) === 1, 'conversation search finds visitor phone numbers');
staff_expect($wildcardSearch === [], 'conversation search treats SQL wildcard characters literally');

$agentServer = ['HTTP_AUTHORIZATION' => 'Bearer test-agent-token'];
$ownerServer = ['HTTP_AUTHORIZATION' => 'Bearer test-owner-token'];
$agentIdentity = $agentService->authenticate($agentServer);
$agentSearchOwnerOnly = $agentService->listConversations($agentServer, 'open', 0, 50, 'Owner Only Visitor');
staff_expect($agentSearchOwnerOnly === [], 'agent search cannot reveal a visitor assigned to another agent');
$agentList = $agentService->listConversations($agentServer, 'open', 0, 50);
staff_expect($agentIdentity['role'] === 'agent', 'local staff role overrides untrusted external role claims');
staff_expect(count($agentList) === 2 && count(array_filter($agentList, static fn(array $row): bool => in_array($row['id'], [$conversationId, $secondConversationId], true))) === 2, 'agent inbox includes own assignments and unassigned conversations but excludes another agent’s assignments');
$agentMessages = $agentService->listConversationMessages($agentServer, $conversationId, 0, 50);
staff_expect(count($agentMessages['items']) === 1, 'assigned agent can read conversation messages');
$agentNotes = $agentService->listInternalNotes($agentServer, $conversationId, 0, 20);
staff_expect(count($agentNotes) === 1, 'assigned agent can read private notes for their active conversation');
staff_expect($agentService->unassignConversation($agentServer, $conversationId, 'staff-agent-own-unassign'), 'agent can unassign their own active conversation');
staff_expect($repository->getActiveAssignee($conversationId) === null, 'agent self-unassignment ends only their active assignment');
$agentService->assignConversation($agentServer, $conversationId, $resolvedB, 'staff-agent-self-assign-again');
staff_expect($repository->getActiveAssignee($conversationId) === $resolvedB, 'agent can self-assign the conversation again after releasing it');
$agentCannotListStaff = false;
try {
    $agentService->listStaffIdentities($agentServer);
} catch (StaffInboxAuthorizationException) {
    $agentCannotListStaff = true;
}
staff_expect($agentCannotListStaff, 'agent cannot enumerate all staff identities');

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

$ownerService->assignConversation($ownerServer, $secondConversationId, $resolvedA, 'staff-owner-reassign');
$ownerService->addInternalNote($ownerServer, uuid_v4(), $secondConversationId, 'Private note after reassignment.');
$agentCannotStealAssignment = false;
try {
    $agentService->assignConversation($agentServer, $secondConversationId, $resolvedB, 'staff-agent-race');
} catch (StaffInboxAuthorizationException) {
    $agentCannotStealAssignment = true;
}
staff_expect($agentCannotStealAssignment && $repository->getActiveAssignee($secondConversationId) === $resolvedA, 'agent self-assignment cannot overwrite an assignment created concurrently by an administrator');

$agentCannotUnassignAnother = false;
try {
    $agentService->unassignConversation($agentServer, $secondConversationId, 'staff-agent-unassign-other');
} catch (StaffInboxAuthorizationException) {
    $agentCannotUnassignAnother = true;
}
staff_expect($agentCannotUnassignAnother && $repository->getActiveAssignee($secondConversationId) === $resolvedA, 'agent cannot unassign a conversation reassigned to another staff member');

$agentCannotReadReassigned = false;
try {
    $agentService->listInternalNotes($agentServer, $secondConversationId, 0, 20);
} catch (StaffInboxAuthorizationException) {
    $agentCannotReadReassigned = true;
}
staff_expect($agentCannotReadReassigned, 'agent cannot read private notes after assignment is revoked');

$agentCannotReplyAfterReassignment = false;
try {
    $agentService->createStaffReply($agentServer, [
        'id' => uuid_v4(),
        'conversation_id' => $secondConversationId,
        'client_message_id' => uuid_v4(),
        'body' => 'This must not be sent after reassignment.',
    ]);
} catch (StaffInboxAuthorizationException) {
    $agentCannotReplyAfterReassignment = true;
}
staff_expect($agentCannotReplyAfterReassignment, 'agent cannot reply after assignment is revoked');

$staleNoteDenied = false;
try {
    $repository->addInternalNote(uuid_v4(), $secondConversationId, $resolvedB, 'Stale assignment write.', null, true);
} catch (StaffInboxAssignmentException) {
    $staleNoteDenied = true;
}
staff_expect($staleNoteDenied, 'repository rechecks assignment inside the note-write transaction');

$staleReplyDenied = false;
try {
    $repository->createStaffReply([
        'id' => uuid_v4(),
        'conversation_id' => $secondConversationId,
        'client_message_id' => uuid_v4(),
        'body' => 'Stale assignment reply.',
    ], $resolvedB, null, true);
} catch (StaffInboxAssignmentException) {
    $staleReplyDenied = true;
}
staff_expect($staleReplyDenied, 'repository rechecks assignment inside the reply transaction');

$staleMessages = $repository->listConversationMessages($secondConversationId, 0, 20, $resolvedB);
$staleNotes = $repository->listInternalNotes($secondConversationId, 0, 20, $resolvedB);
$staleAssignments = $repository->listAssignmentHistory($secondConversationId, 0, 20, $resolvedB);
staff_expect($staleMessages['items'] === [] && $staleNotes === [] && $staleAssignments === [], 'repository read queries return no conversation data after assignment revocation');

$ownerList = $ownerService->listConversations($ownerServer, 'open', 0, 50);
staff_expect(count($ownerList) >= 3, 'owner can list all conversations across the installation');
staff_expect(count($ownerService->listStaffIdentities($ownerServer)) >= 2, 'owner can list active staff identities');
$ownerService->addInternalNote($ownerServer, uuid_v4(), $secondConversationId, 'Owner note.');
staff_expect(count($repository->listInternalNotes($secondConversationId, 0, 10)) === 2, 'owner can manage a conversation assigned to another agent');

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

$ownerService->updateStaffIdentityAccess($ownerServer, $resolvedB, 'agent', 'disabled', 'staff-test-disable');
$disabledState = $repository->findStaffIdentityById($resolvedB);
staff_expect(($disabledState['status'] ?? '') === 'disabled', 'owner can disable a staff identity');
$disabledRejected = false;
try {
    $repository->addInternalNote(uuid_v4(), $conversationId, $resolvedB, 'Should not be accepted.');
} catch (RuntimeException) {
    $disabledRejected = true;
}
staff_expect($disabledRejected, 'disabled staff identities cannot create private notes');
$ownerService->updateStaffIdentityAccess($ownerServer, $resolvedB, 'agent', 'active', 'staff-test-enable');
$reenabled = $repository->findStaffIdentityById($resolvedB);
staff_expect(($reenabled['status'] ?? '') === 'active', 'owner can re-enable a disabled staff identity');

// Exercise the actual CLI entry points against the same disposable MySQL database.
$setRoleScript = dirname(__DIR__) . '/bin/set-staff-role.php';
$legacyProvisionScript = dirname(__DIR__) . '/bin/provision-staff.php';
$setRoleLastOwner = staff_run_cli([$setRoleScript, 'test-idp', 'staff-subject-a', 'agent']);
$afterSetRoleGuard = $repository->findStaffIdentityById($resolvedA);
staff_expect(
    $setRoleLastOwner['status'] === 1 && ($afterSetRoleGuard['role'] ?? '') === 'owner',
    'set-staff-role CLI refuses to demote the final active owner'
);

$legacyLastOwner = staff_run_cli([
    $legacyProvisionScript,
    '--provider=test-idp',
    '--subject=staff-subject-a',
    '--role=agent',
]);
$afterLegacyGuard = $repository->findStaffIdentityById($resolvedA);
staff_expect(
    $legacyLastOwner['status'] === 1 && ($afterLegacyGuard['role'] ?? '') === 'owner',
    'legacy provision-staff CLI refuses to demote the final active owner'
);

$ownerService->updateStaffIdentityAccess($ownerServer, $resolvedB, 'owner', 'active', 'staff-test-cli-second-owner');
$setRoleSuccess = staff_run_cli([$setRoleScript, 'test-idp', 'staff-subject-a', 'agent']);
$afterSetRoleSuccess = $repository->findStaffIdentityById($resolvedA);
staff_expect(
    $setRoleSuccess['status'] === 0 && ($afterSetRoleSuccess['role'] ?? '') === 'agent',
    'set-staff-role CLI permits demotion when another active owner remains'
);

$pdo->prepare('UPDATE staff_identities SET role = "owner", updated_at = UTC_TIMESTAMP() WHERE id = :id')
    ->execute([':id' => $resolvedA]);
$legacySuccess = staff_run_cli([
    $legacyProvisionScript,
    '--provider=test-idp',
    '--subject=staff-subject-b',
    '--role=agent',
]);
$afterLegacySuccess = $repository->findStaffIdentityById($resolvedB);
staff_expect(
    $legacySuccess['status'] === 0 && ($afterLegacySuccess['role'] ?? '') === 'agent',
    'legacy provision-staff CLI permits demotion when another active owner remains'
);

fwrite(STDOUT, "\n{$passed} passed; {$failed} failed.\n");
exit($failed === 0 ? 0 : 1);
