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
}

final class ChatStorageConflict extends RuntimeException
{
}
