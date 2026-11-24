<?php
declare(strict_types=1);

/** Session persistence operations, independent of message transport. */
interface GuestSessionRepository
{
    /** @param array<string,mixed> $session */
    public function createGuestSession(array $session): void;

    /** @return array<string,mixed>|null */
    public function findGuestSessionByTokenHash(string $tokenHash): ?array;

    public function touchGuestSession(string $sessionId): void;
}

/** Fixed-window request accounting. */
interface RateLimitRepository
{
    /** Increment a fixed-window bucket and report whether this request is within its limit. */
    public function consumeRateLimit(string $bucket, int $maxRequests, int $windowSeconds): bool;
}

/** Visitor-visible conversation message persistence and polling. */
interface VisitorMessageRepository
{
    /**
     * Persist a visitor message and its optional WhatsApp outbox row atomically.
     *
     * @param array<string,mixed> $message
     * @return array{message:array<string,mixed>,inserted:bool,transport:string}
     * @throws ChatStorageConflict when an idempotency key is reused with different content.
     */
    public function createVisitorMessage(array $message): array;

    /** @return array{items:list<array<string,mixed>>,has_more:bool,next_cursor:int} */
    public function listConversationMessages(string $conversationId, int $after, int $limit): array;

    /** @return list<array{id:string,delivery_status:string}> */
    public function listVisitorDeliveryUpdates(string $conversationId, int $limit = 50): array;
}

/** Auditable visitor consent transitions. */
interface ConsentRepository
{
    public function recordWhatsAppConsent(
        string $guestId,
        string $conversationId,
        string $normalizedPhone,
        string $action,
        string $source = 'website_widget'
    ): void;
}

/** Lookup of the active WhatsApp identity used by inbound webhook routing. */
interface WhatsAppIdentityRepository
{
    /** Return the currently active Meta phone-number ID, or null when none is connected. */
    public function activeWhatsAppPhoneNumberId(): ?string;
}

/** Durable, encrypted provider webhook inbox operations. */
interface WebhookInboxRepository
{
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
}

/** Idempotent persistence for provider message and delivery-status events. */
interface ProviderEventRepository
{
    /**
     * Record an inbound visitor message or Business App echo, including event
     * deduplication, identity routing, message insertion and conversation activity.
     * Returns processed, ignored, or duplicate.
     */
    public function recordProviderMessageEvent(
        string $eventId,
        string $eventType,
        string $payloadHash,
        string $phone,
        string $senderType,
        string $body,
        string $providerMessageId,
        string $deliveryStatus,
        string $sentAt
    ): string;

    /** Reconcile the highest-precedence stored status onto a provider message. */
    public function reconcileProviderMessageStatus(string $providerMessageId): bool;

    /** Record an identified but unsupported provider event without creating a message. */
    public function recordIgnoredProviderEvent(string $eventId, string $eventType, string $payloadHash): string;

    /** Persist a provider status event and reconcile the message's current status. */
    public function recordProviderStatusEvent(
        string $eventId,
        string $payloadHash,
        string $providerMessageId,
        string $status,
        int $eventTimestamp
    ): string;
}

/** Transactional WhatsApp outbox lifecycle and immediate pre-send consent check. */
interface WhatsAppOutboxRepository
{
    /** Create an outbound WhatsApp job; normally called in the message transaction. */
    public function enqueueWhatsAppOutbox(string $tenantId, string $messageId, string $recipient, string $text, string $outboxId): void;

    /**
     * Lease one due outbound job and terminally fail expired jobs at the attempt limit.
     * @return array{job:array<string,mixed>|null,exhausted:int}
     */
    public function claimWhatsAppOutbox(string $lockToken, int $maxAttempts = 6, int $leaseSeconds = 300): array;

    /** Consent is deliberately checked immediately before the provider request. */
    public function hasWhatsAppConsent(string $messageId): bool;

    /** Complete a cancelled lease only if its token is still current. */
    public function cancelWhatsAppOutbox(string $id, string $lockToken, string $messageId): bool;

    /** Persist provider acceptance and reconcile any status callback received early. */
    public function completeWhatsAppOutbox(string $id, string $lockToken, string $messageId, string $providerMessageId): bool;

    /** Retry or terminally fail only the job still owned by this lease token. */
    public function failWhatsAppOutbox(string $id, string $lockToken, string $messageId, bool $retry, int $delaySeconds, string $error): bool;
}

/** Minimum capabilities required to create and authenticate guest sessions. */
interface GuestSessionOperationsRepository extends GuestSessionRepository, RateLimitRepository
{
}

/** Minimum capabilities required by the visitor message application service. */
interface VisitorMessagingRepository extends VisitorMessageRepository, RateLimitRepository
{
}

/** Capabilities used together by webhook ingestion and processing. */
interface WebhookProcessingRepository extends WebhookInboxRepository, WhatsAppIdentityRepository, ProviderEventRepository
{
}

/**
 * Backward-compatible aggregate persistence contract for the PHP reference backend.
 *
 * Application services should depend on the narrowest capability interface they
 * require. Existing integration points may continue to depend on ChatRepository.
 * Implementations own transaction boundaries; this interface exposes no PDO or SQL.
 */
interface ChatRepository extends
    GuestSessionOperationsRepository,
    VisitorMessagingRepository,
    ConsentRepository,
    WhatsAppIdentityRepository,
    WebhookProcessingRepository,
    WhatsAppOutboxRepository
{
}

final class ChatStorageConflict extends RuntimeException
{
}
