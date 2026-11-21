<?php
declare(strict_types=1);

/**
 * Persistence contract for visitor chat sessions and website messages.
 *
 * Implementations own transaction boundaries for the operations in this contract.
 * The interface exposes domain-level operations, not PDO or SQL dialect details.
 */
interface ChatRepository
{
    /** @param array<string,mixed> $session */
    public function createGuestSession(array $session): void;

    /** Apply an auditable WhatsApp consent transition atomically. */
    public function recordWhatsAppConsent(string $guestId, string $conversationId, string $normalizedPhone, string $action, string $source = 'website_widget'): void;

    /** Increment a fixed-window bucket and report whether this request is within its limit. */
    public function consumeRateLimit(string $bucket, int $maxRequests, int $windowSeconds): bool;

    /** @return array<string,mixed>|null */
    public function findGuestSessionByTokenHash(string $tokenHash): ?array;

    public function touchGuestSession(string $sessionId): void;

    /**
     * Persist a visitor message and its optional WhatsApp outbox row atomically.
     *
     * @param array<string,mixed> $message
     * @return array{message:array<string,mixed>,inserted:bool,transport:string}
     * @throws ChatStorageConflict when an idempotency key is reused with different content.
     */
    public function createVisitorMessage(array $message): array;

    /**
     * @return array{items:list<array<string,mixed>>,has_more:bool,next_cursor:int}
     */
    public function listConversationMessages(string $conversationId, int $after, int $limit): array;

    /** @return list<array{id:string,delivery_status:string}> */
    public function listVisitorDeliveryUpdates(string $conversationId, int $limit = 50): array;

    /**
     * Persist an encrypted, signature-verified provider payload before acknowledging it.
     * @param array{ciphertext:string,nonce:string,tag:string} $encrypted
     * @return array{id:int,inserted:bool,status:string}
     */
    public function enqueueWebhookInbox(string $payloadHash, array $encrypted): array;

    /** Atomically lease one due inbox row; null means no work is currently available. */
    public function claimWebhookInbox(string $lockToken, int $maxAttempts = 8): ?array;

    /** Complete or reschedule only the row owned by the supplied lease token. */
    public function completeWebhookInbox(int $id, string $lockToken): void;
    public function failWebhookInbox(int $id, string $lockToken, bool $retry, int $delaySeconds, string $error): void;

    /**
     * Record an inbound visitor message or Business App echo, including event
     * deduplication, identity routing, message insertion and conversation activity.
     * Returns processed, ignored, or duplicate.
     */
    public function recordProviderMessageEvent(
        string $eventId, string $eventType, string $payloadHash, string $phone,
        string $senderType, string $body, string $providerMessageId,
        string $deliveryStatus, string $sentAt
    ): string;

    /** Reconcile the highest-precedence stored status onto a provider message. */
    public function reconcileProviderMessageStatus(string $providerMessageId): bool;

    /** Record an identified but unsupported provider event without creating a message. */
    public function recordIgnoredProviderEvent(string $eventId, string $eventType, string $payloadHash): string;

    /** Persist a provider status event and reconcile the message's current status. */
    public function recordProviderStatusEvent(
        string $eventId, string $payloadHash, string $providerMessageId,
        string $status, int $eventTimestamp
    ): string;
}

final class ChatStorageConflict extends RuntimeException
{
}
