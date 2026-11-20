<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/whatsapp.php';
require_once dirname(__DIR__) . '/app/crypto.php';
require_once dirname(__DIR__) . '/app/ai.php';

function ai_check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    fwrite(STDOUT, "PASS: {$message}\n");
}

$secret = 'test-provider-secret-that-must-not-be-stored-plaintext';
$encrypted = chatbridge_encrypt_secret($secret);
ai_check(strpos($encrypted, 'v1:') === 0 && strpos($encrypted, $secret) === false, 'provider credentials are encrypted before storage');
ai_check(chatbridge_decrypt_secret($encrypted) === $secret, 'encrypted provider credentials round-trip');

$tampered = substr($encrypted, 0, -2) . 'AA';
$tamperRejected = false;
try {
    chatbridge_decrypt_secret($tampered);
} catch (Throwable $error) {
    $tamperRejected = true;
}
ai_check($tamperRejected, 'AES-GCM authentication rejects modified ciphertext');

$httpRejected = false;
try {
    new OpenAICompatibleChatProvider('http://127.0.0.1:8080', 'key', 'model');
} catch (InvalidArgumentException $error) {
    $httpRejected = true;
}
ai_check($httpRejected, 'AI provider adapter rejects non-HTTPS endpoints');
ai_check(is_subclass_of(OpenAICompatibleChatProvider::class, ChatBridgeAIProvider::class), 'OpenAI-compatible adapter implements provider abstraction');

$pdo = database();
$tenantA = '00000000-0000-4000-8000-000000000001';
$tenantB = uuid_v4();
$pdo->prepare('INSERT INTO tenants (id, name, slug, status, created_at, updated_at) VALUES (:id, "AI test tenant", :slug, "active", UTC_TIMESTAMP(), UTC_TIMESTAMP())')
    ->execute([':id' => $tenantB, ':slug' => 'ai-' . substr(str_replace('-', '', $tenantB), 0, 12)]);
foreach ([
    [$tenantA, 'Returns policy', 'Our refund policy accepts returns within 30 days.'],
    [$tenantB, 'Returns policy', 'Our refund policy accepts returns within 7 days.'],
] as $row) {
    $pdo->prepare(
        'INSERT INTO knowledge_sources (id, tenant_id, source_type, title, content, content_sha256, status, created_at, updated_at)
         VALUES (:id, :tenant_id, "policy", :title, :content, :hash, "published", UTC_TIMESTAMP(), UTC_TIMESTAMP())'
    )->execute([
        ':id' => uuid_v4(),
        ':tenant_id' => $row[0],
        ':title' => $row[1],
        ':content' => $row[2],
        ':hash' => hash('sha256', $row[2]),
    ]);
}
$knowledgeA = chatbridge_retrieve_knowledge($pdo, $tenantA, 'refund policy returns');
ai_check(count($knowledgeA) === 1 && strpos($knowledgeA[0]['content'], '30 days') !== false, 'knowledge retrieval returns only the requesting tenant content');

$pdo->prepare(
    'INSERT INTO tenant_ai_settings (tenant_id, enabled, provider, model, base_url, max_output_tokens, monthly_token_budget, created_at, updated_at)
     VALUES (:tenant_id, 1, "openai_compatible", "test-model", "https://api.example.test/v1", 300, 10000, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
)->execute([':tenant_id' => $tenantA]);
$guestId = uuid_v4();
$conversationId = uuid_v4();
$messageId = uuid_v4();
$pdo->prepare('INSERT INTO guests (id, tenant_id, display_name, phone, phone_normalized, created_at, updated_at) VALUES (:id, :tenant_id, "AI test visitor", NULL, NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP())')
    ->execute([':id' => $guestId, ':tenant_id' => $tenantA]);
$pdo->prepare('INSERT INTO conversations (id, tenant_id, guest_id, status, created_at, updated_at) VALUES (:id, :tenant_id, :guest_id, "open", UTC_TIMESTAMP(), UTC_TIMESTAMP())')
    ->execute([':id' => $conversationId, ':tenant_id' => $tenantA, ':guest_id' => $guestId]);
$pdo->prepare('INSERT INTO messages (id, tenant_id, conversation_id, sender_type, body, delivery_status, sent_at, created_at) VALUES (:id, :tenant_id, :conversation_id, "visitor", "What is your return policy?", "web_only", UTC_TIMESTAMP(), UTC_TIMESTAMP())')
    ->execute([':id' => $messageId, ':tenant_id' => $tenantA, ':conversation_id' => $conversationId]);
chatbridge_ai_queue_or_handover($pdo, $tenantA, $conversationId, $messageId, 'What is your return policy?');
$jobCount = $pdo->prepare('SELECT COUNT(*) FROM ai_jobs WHERE tenant_id = :tenant_id AND source_message_id = :message_id');
$jobCount->execute([':tenant_id' => $tenantA, ':message_id' => $messageId]);
ai_check((int)$jobCount->fetchColumn() === 1, 'enabled tenant AI queues one idempotent response job');

chatbridge_ai_queue_or_handover($pdo, $tenantA, $conversationId, uuid_v4(), 'I need a human agent please');
$handoffQuery = $pdo->prepare('SELECT reason, status FROM conversation_handoffs WHERE tenant_id = :tenant_id AND conversation_id = :conversation_id');
$handoffQuery->execute([':tenant_id' => $tenantA, ':conversation_id' => $conversationId]);
$handoff = $handoffQuery->fetch();
ai_check(($handoff['reason'] ?? '') === 'visitor_requested' && ($handoff['status'] ?? '') === 'open', 'visitor request creates an open human handover');
$jobCount->execute([':tenant_id' => $tenantA, ':message_id' => $messageId]);
ai_check((int)$jobCount->fetchColumn() === 1, 'human handover does not duplicate or cross-scope queued AI jobs');

fwrite(STDOUT, "AI foundation tests passed.\n");
