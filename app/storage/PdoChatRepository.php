<?php
declare(strict_types=1);

/**
 * Backward-compatible composition adapter for the MySQL/MariaDB repository slices.
 *
 * SQL dialect details live in the focused PDO adapters. This class only wires
 * them together over one PDO connection and preserves the ChatRepository
 * aggregate contract for existing routes, workers and integration callers.
 */
final class PdoChatRepository implements ChatRepository
{
    private PdoWebhookInboxRepository $webhookInbox;
    private PdoRateLimitRepository $rateLimits;
    private PdoConsentRepository $consent;
    private PdoGuestSessionRepository $guestSessions;
    private PdoVisitorMessageRepository $visitorMessages;
    private PdoProviderEventRepository $providerEvents;
    private PdoWhatsAppOutboxRepository $whatsAppOutbox;
    private PdoMetaConnectionRepository $metaConnections;

    public function __construct(PDO $pdo)
    {
        $this->webhookInbox = new PdoWebhookInboxRepository($pdo);
        $this->rateLimits = new PdoRateLimitRepository($pdo);
        $this->consent = new PdoConsentRepository($pdo);
        $this->guestSessions = new PdoGuestSessionRepository($pdo, $this->consent);
        $this->providerEvents = new PdoProviderEventRepository($pdo);
        $this->whatsAppOutbox = new PdoWhatsAppOutboxRepository($pdo, $this->providerEvents);
        $this->visitorMessages = new PdoVisitorMessageRepository($pdo, $this->whatsAppOutbox);
        $this->metaConnections = new PdoMetaConnectionRepository($pdo);
    }

    public function enqueueWhatsAppOutbox(string $tenantId, string $messageId, string $recipient, string $text, string $outboxId): void
    {
        $this->whatsAppOutbox->enqueueWhatsAppOutbox($tenantId, $messageId, $recipient, $text, $outboxId);
    }

    public function claimWhatsAppOutbox(string $lockToken, int $maxAttempts = 6, int $leaseSeconds = 300): array
    {
        return $this->whatsAppOutbox->claimWhatsAppOutbox($lockToken, $maxAttempts, $leaseSeconds);
    }

    public function hasWhatsAppConsent(string $messageId): bool
    {
        return $this->whatsAppOutbox->hasWhatsAppConsent($messageId);
    }

    public function cancelWhatsAppOutbox(string $id, string $lockToken, string $messageId): bool
    {
        return $this->whatsAppOutbox->cancelWhatsAppOutbox($id, $lockToken, $messageId);
    }

    public function completeWhatsAppOutbox(string $id, string $lockToken, string $messageId, string $providerMessageId): bool
    {
        return $this->whatsAppOutbox->completeWhatsAppOutbox($id, $lockToken, $messageId, $providerMessageId);
    }

    public function failWhatsAppOutbox(string $id, string $lockToken, string $messageId, bool $retry, int $delaySeconds, string $error): bool
    {
        return $this->whatsAppOutbox->failWhatsAppOutbox($id, $lockToken, $messageId, $retry, $delaySeconds, $error);
    }

    public function recordWhatsAppConsent(string $tenantId, string $guestId, string $conversationId, string $normalizedPhone, string $action, string $source = 'website_widget'): void
    {
        $this->consent->recordWhatsAppConsent($tenantId, $guestId, $conversationId, $normalizedPhone, $action, $source);
    }

    public function consumeRateLimit(string $tenantId, string $bucket, int $maxRequests, int $windowSeconds): bool
    {
        return $this->rateLimits->consumeRateLimit($tenantId, $bucket, $maxRequests, $windowSeconds);
    }

    public function createGuestSession(array $session): void
    {
        $this->guestSessions->createGuestSession($session);
    }

    public function findGuestSessionByTokenHash(string $tokenHash, string $tenantId): ?array
    {
        return $this->guestSessions->findGuestSessionByTokenHash($tokenHash, $tenantId);
    }

    public function touchGuestSession(string $sessionId, string $tenantId): void
    {
        $this->guestSessions->touchGuestSession($sessionId, $tenantId);
    }

    public function createVisitorMessage(array $message): array
    {
        return $this->visitorMessages->createVisitorMessage($message);
    }

    public function listConversationMessages(string $tenantId, string $conversationId, int $after, int $limit): array
    {
        return $this->visitorMessages->listConversationMessages($tenantId, $conversationId, $after, $limit);
    }

    public function listVisitorDeliveryUpdates(string $tenantId, string $conversationId, int $limit = 50): array
    {
        return $this->visitorMessages->listVisitorDeliveryUpdates($tenantId, $conversationId, $limit);
    }


    public function activeWhatsAppPhoneNumberId(): ?string
    {
        return $this->metaConnections->activePhoneNumberId();
    }

    public function enqueueWebhookInbox(string $payloadHash, array $encrypted): array
    {
        return $this->webhookInbox->enqueueWebhookInbox($payloadHash, $encrypted);
    }

    public function claimWebhookInbox(string $lockToken, int $maxAttempts = 8): ?array
    {
        return $this->webhookInbox->claimWebhookInbox($lockToken, $maxAttempts);
    }

    public function completeWebhookInbox(int $id, string $lockToken): void
    {
        $this->webhookInbox->completeWebhookInbox($id, $lockToken);
    }

    public function failWebhookInbox(int $id, string $lockToken, bool $retry, int $delaySeconds, string $error): void
    {
        $this->webhookInbox->failWebhookInbox($id, $lockToken, $retry, $delaySeconds, $error);
    }


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
    ): string {
        return $this->providerEvents->recordProviderMessageEvent(
            $eventId,
            $eventType,
            $payloadHash,
            $phone,
            $senderType,
            $body,
            $providerMessageId,
            $deliveryStatus,
            $sentAt
        );
    }

    public function reconcileProviderMessageStatus(string $providerMessageId): bool
    {
        return $this->providerEvents->reconcileProviderMessageStatus($providerMessageId);
    }

    public function recordIgnoredProviderEvent(string $eventId, string $eventType, string $payloadHash): string
    {
        return $this->providerEvents->recordIgnoredProviderEvent($eventId, $eventType, $payloadHash);
    }

    public function recordProviderStatusEvent(
        string $eventId,
        string $payloadHash,
        string $providerMessageId,
        string $status,
        int $eventTimestamp
    ): string {
        return $this->providerEvents->recordProviderStatusEvent(
            $eventId,
            $payloadHash,
            $providerMessageId,
            $status,
            $eventTimestamp
        );
    }


}
