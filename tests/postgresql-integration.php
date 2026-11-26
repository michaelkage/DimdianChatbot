<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

function pg_expect(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException('PostgreSQL integration failure: ' . $label);
    }
    echo "PASS " . $label . PHP_EOL;
}

$pdo = database();
$defaultTenantId = (string) (app_config('tenancy.default_tenant_id') ?? '00000000-0000-4000-8000-000000000001');
pg_expect(PdoSqlDialect::isPostgres($pdo), 'test connection uses the PostgreSQL PDO dialect');

$webhookHash = hash('sha256', 'pg-webhook-' . bin2hex(random_bytes(16)));
$webhookCipher = "\0postgres-webhook-cipher\xff";
$webhookNonce = random_bytes(12);
$webhookTag = random_bytes(16);
$webhookId = null;

$guestId = uuid_v4();
$conversationId = uuid_v4();
$messageId = uuid_v4();
$outboxId = uuid_v4();
$providerMessageId = 'wamid.pg.' . bin2hex(random_bytes(12));
$providerEventId = 'pg-status-' . bin2hex(random_bytes(12));

$metaStateHash = hash('sha256', 'pg-meta-state-' . bin2hex(random_bytes(16)));
$metaPhoneId = 'pg-phone-' . bin2hex(random_bytes(10));
$metaCipher = "\0meta-token-cipher\xff";
$metaNonce = random_bytes(12);
$metaTag = random_bytes(16);

try {
    // Encrypted webhook inbox: binary-safe storage, duplicate suppression,
    // lease fencing, retry scheduling and terminal completion.
    $webhooks = new PdoWebhookInboxRepository($pdo);
    $first = $webhooks->enqueueWebhookInbox($defaultTenantId, $webhookHash, [
        'ciphertext' => $webhookCipher,
        'nonce' => $webhookNonce,
        'tag' => $webhookTag,
    ]);
    $webhookId = $first['id'];
    $second = $webhooks->enqueueWebhookInbox($defaultTenantId, $webhookHash, [
        'ciphertext' => $webhookCipher,
        'nonce' => $webhookNonce,
        'tag' => $webhookTag,
    ]);
    pg_expect($first['inserted'] === true && $second['inserted'] === false && $first['id'] === $second['id'],
        'PostgreSQL webhook inbox deduplicates the same encrypted payload');

    $firstLease = uuid_v4();
    $claimed = $webhooks->claimWebhookInbox($firstLease);
    pg_expect(
        is_array($claimed)
            && (int) $claimed['id'] === $webhookId
            && $claimed['payload_ciphertext'] === $webhookCipher
            && $claimed['payload_nonce'] === $webhookNonce
            && $claimed['payload_tag'] === $webhookTag,
        'PostgreSQL webhook inbox preserves encrypted binary payload bytes through base64 storage'
    );

    $webhooks->failWebhookInbox($defaultTenantId, $webhookId, $firstLease, true, 0, 'retry test');
    $secondLease = uuid_v4();
    $retried = $webhooks->claimWebhookInbox($secondLease);
    pg_expect(is_array($retried) && (int) $retried['attempts'] === 2,
        'PostgreSQL webhook inbox makes retry work available and increments attempts');
    $webhooks->completeWebhookInbox($defaultTenantId, $webhookId, $secondLease);
    pg_expect($webhooks->claimWebhookInbox(uuid_v4()) === null,
        'PostgreSQL webhook inbox completes and releases its fenced lease');

    // Meta state is one-time, and encrypted access-token bytes survive storage.
    $meta = new PdoMetaConnectionRepository($pdo);
    $meta->createSignupState($metaStateHash, 600);
    pg_expect($meta->consumeSignupState($metaStateHash), 'PostgreSQL Meta signup state is consumed once');
    pg_expect(!$meta->consumeSignupState($metaStateHash), 'PostgreSQL Meta signup state rejects replay');

    $meta->saveActiveConnection([
        'waba_id' => 'pg-waba-' . bin2hex(random_bytes(6)),
        'phone_number_id' => $metaPhoneId,
        'token_ciphertext' => $metaCipher,
        'token_nonce' => $metaNonce,
        'token_tag' => $metaTag,
        'token_expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
    ]);
    $connection = $meta->findActiveConnection();
    pg_expect(
        is_array($connection)
            && $connection['access_token_ciphertext'] === $metaCipher
            && $connection['access_token_nonce'] === $metaNonce
            && $connection['access_token_tag'] === $metaTag,
        'PostgreSQL Meta connection restores encrypted token bytes exactly'
    );

    $refreshedCipher = "\0refreshed-token\xfe";
    $refreshedNonce = random_bytes(12);
    $refreshedTag = random_bytes(16);
    $meta->updateAccessToken((int) $connection['id'], [
        'ciphertext' => $refreshedCipher,
        'nonce' => $refreshedNonce,
        'tag' => $refreshedTag,
    ], gmdate('Y-m-d H:i:s', time() + 7200));
    $refreshed = $meta->findActiveConnection();
    pg_expect(
        is_array($refreshed)
            && $refreshed['access_token_ciphertext'] === $refreshedCipher
            && $refreshed['access_token_nonce'] === $refreshedNonce
            && $refreshed['access_token_tag'] === $refreshedTag,
        'PostgreSQL Meta token refresh preserves encrypted material'
    );
    $meta->markConnectionExpired((int) $connection['id']);

    // Outbox transaction: early delivery status is stored before send acceptance,
    // then reconciled atomically when the worker records the provider message ID.
    $now = gmdate('Y-m-d H:i:s');
    $guest = $pdo->prepare(
        'INSERT INTO guests (id, phone_normalized, whatsapp_opt_in_at, created_at, updated_at)
         VALUES (:id, :phone, :opt_in, :now, :now)'
    );
    $guest->execute([
        ':id' => $guestId,
        ':phone' => '234' . substr(hash('sha256', $guestId), 0, 10),
        ':opt_in' => $now,
        ':now' => $now,
    ]);
    $conversation = $pdo->prepare(
        'INSERT INTO conversations (id, guest_id, status, created_at, updated_at)
         VALUES (:id, :guest, \'open\', :now, :now)'
    );
    $conversation->execute([':id' => $conversationId, ':guest' => $guestId, ':now' => $now]);
    $message = $pdo->prepare(
        'INSERT INTO messages (id, conversation_id, sender_type, body, delivery_status, sent_at, created_at)
         VALUES (:id, :conversation, \'business\', \'PostgreSQL outbox test\', \'queued\', :now, :now)'
    );
    $message->execute([':id' => $messageId, ':conversation' => $conversationId, ':now' => $now]);
    $outbox = $pdo->prepare(
        'INSERT INTO message_outbox
            (id, message_id, recipient, payload_text, status, attempts, available_at, created_at, updated_at)
         VALUES (:id, :message, \'2348031234567\', \'test payload\', \'pending\', 0, :now, :now, :now)'
    );
    $outbox->execute([':id' => $outboxId, ':message' => $messageId, ':now' => $now]);

    $providerEvents = new PdoProviderEventRepository($pdo);
    $earlyStatus = $providerEvents->recordProviderStatusEvent(
        $defaultTenantId,
        $providerEventId,
        hash('sha256', $providerEventId),
        $providerMessageId,
        'delivered',
        time()
    );
    pg_expect($earlyStatus === 'ignored',
        'PostgreSQL stores an early provider status before its outbound message is accepted');

    $outboxRepository = new PdoWhatsAppOutboxRepository($pdo, $providerEvents);
    pg_expect($outboxRepository->hasWhatsAppConsent($messageId),
        'PostgreSQL outbox checks current WhatsApp consent before send');
    $outboxLease = uuid_v4();
    $claimedOutbox = $outboxRepository->claimWhatsAppOutbox($outboxLease);
    pg_expect(
        is_array($claimedOutbox['job'] ?? null)
            && $claimedOutbox['job']['id'] === $outboxId
            && (int) $claimedOutbox['job']['attempts'] === 1,
        'PostgreSQL outbox atomically claims a due job under a lease'
    );
    $lease = $outboxLease;
    pg_expect(
        $outboxRepository->completeWhatsAppOutbox($outboxId, $lease, $messageId, $providerMessageId),
        'PostgreSQL outbox accepts completion only for the current lease token'
    );

    $status = $pdo->prepare('SELECT delivery_status FROM messages WHERE id = :id');
    $status->execute([':id' => $messageId]);
    pg_expect($status->fetchColumn() === 'delivered',
        'PostgreSQL outbox reconciles an early delivery callback after send acceptance');

    // Execute the full optional/core retention delete set with cutoffs that
    // intentionally match no test records. This validates PostgreSQL DELETE USING.
    $retention = new PdoRetentionRepository($pdo);
    $pruned = $retention->prune(
        '1970-01-01 00:00:00',
        '1970-01-01 00:00:00',
        '1970-01-01 00:00:00',
        true
    );
    pg_expect(
        count($pruned) === 17
            && !array_filter($pruned, static fn($count): bool => !is_int($count) || $count < 0),
        'PostgreSQL retention executes core and optional staff cleanup statements and returns safe row counts'
    );
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $pdo->prepare('DELETE FROM message_status_events WHERE provider_message_id = :provider')->execute([':provider' => $providerMessageId]);
    $pdo->prepare('DELETE FROM webhook_events WHERE provider_event_id = :event')->execute([':event' => $providerEventId]);
    if ($outboxId !== '') {
        $pdo->prepare('DELETE FROM message_outbox WHERE id = :id')->execute([':id' => $outboxId]);
    }
    $pdo->prepare('DELETE FROM messages WHERE id = :id')->execute([':id' => $messageId]);
    $pdo->prepare('DELETE FROM conversations WHERE id = :id')->execute([':id' => $conversationId]);
    $pdo->prepare('DELETE FROM guests WHERE id = :id')->execute([':id' => $guestId]);
    $pdo->prepare('DELETE FROM meta_connections WHERE phone_number_id = :phone')->execute([':phone' => $metaPhoneId]);
    $pdo->prepare('DELETE FROM meta_signup_states WHERE state_sha256 = :hash')->execute([':hash' => $metaStateHash]);
    if (is_int($webhookId)) {
        $pdo->prepare('DELETE FROM webhook_inbox WHERE id = :id')->execute([':id' => $webhookId]);
    }
}

echo "PostgreSQL repository integration checks passed." . PHP_EOL;
