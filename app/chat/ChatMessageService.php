<?php
declare(strict_types=1);

/** Domain-level signal translated to HTTP 429 by the messages route. */
final class MessageRateLimitExceeded extends RuntimeException
{
    /** @var int */
    public $retryAfterSeconds;

    public function __construct(int $retryAfterSeconds)
    {
        parent::__construct('The message rate limit has been reached.');
        $this->retryAfterSeconds = max(1, $retryAfterSeconds);
    }
}

/** Visitor message send/poll use cases, independent of PDO and HTTP globals. */
final class ChatMessageService
{
    /** @var ChatRepository */
    private $repository;
    /** @var array<string,mixed> */
    private $config;

    /** @param array<string,mixed> $config */
    public function __construct(ChatRepository $repository, array $config)
    {
        $this->repository = $repository;
        $this->config = $config;
    }

    /**
     * @param array<string,mixed> $session Authenticated guest-session data.
     * @return array{http_status:int,body:array<string,mixed>}
     */
    public function send(array $session, string $messageText, ?string $clientMessageId = null): array
    {
        $rateConfig = $this->config['app']['message_rate_limit'] ?? [];
        if (!is_array($rateConfig)) {
            $rateConfig = [];
        }
        $maxRequests = max(1, (int) ($rateConfig['max_requests'] ?? 8));
        $windowSeconds = max(60, (int) ($rateConfig['window_seconds'] ?? 60));
        if (!$this->repository->consumeRateLimit('message:' . (string) $session['session_id'], $maxRequests, $windowSeconds)) {
            throw new MessageRateLimitExceeded($windowSeconds);
        }

        if ($clientMessageId === null) {
            $clientMessageId = $this->uuidV4();
        }
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $clientMessageId)) {
            throw new InvalidArgumentException('client_message_id must be a UUID.');
        }

        $whatsappConfig = $this->config['whatsapp'] ?? [];
        $visitorWhatsApp = (string) ($session['phone_normalized'] ?? '');
        $visitorOptedIn = !empty($session['whatsapp_opt_in_at']) && $visitorWhatsApp !== '';
        $outboundEnabled = is_array($whatsappConfig)
            && !empty($whatsappConfig['outbound_enabled'])
            && $visitorOptedIn;

        $result = $this->repository->createVisitorMessage([
            'id' => $this->uuidV4(),
            'conversation_id' => (string) $session['conversation_id'],
            'client_message_id' => $clientMessageId,
            'body' => $messageText,
            'outbound_enabled' => $outboundEnabled,
            'outbox_id' => $this->uuidV4(),
            'recipient' => $visitorWhatsApp,
            'outbox_payload' => $this->truncate($messageText, 1000),
        ]);

        $saved = $result['message'];
        $inserted = $result['inserted'];
        $body = [
            'data' => [
                'id' => $saved['id'],
                'sequence_id' => (int) $saved['sequence_id'],
                'conversation_id' => $session['conversation_id'],
                'sender' => 'visitor',
                'message' => $messageText,
                'delivery_status' => $saved['delivery_status'],
                'created_at' => (new DateTimeImmutable($saved['sent_at'], new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            ],
            'meta' => [
                'transport' => $inserted ? $result['transport'] : 'replayed',
                'idempotent_replay' => !$inserted,
                'note' => $saved['delivery_status'] === 'web_only'
                    ? 'Your message is saved in this website chat. WhatsApp delivery is not enabled for this conversation.'
                    : 'Your message is saved. WhatsApp delivery status is tracked separately.',
            ],
        ];

        return ['http_status' => $inserted ? 201 : 200, 'body' => $body];
    }

    /** @param array<string,mixed> $session @return array<string,mixed> */
    public function poll(array $session, int $after, int $limit): array
    {
        $page = $this->repository->listConversationMessages((string) $session['conversation_id'], $after, $limit);
        $deliveryUpdates = $this->repository->listVisitorDeliveryUpdates((string) $session['conversation_id'], 50);
        return [
            'data' => $page['items'],
            'meta' => [
                'after' => $after,
                'next_cursor' => $page['next_cursor'],
                'has_more' => $page['has_more'],
                'poll_after_ms' => 3000,
                'delivery_updates' => $deliveryUpdates,
            ],
        ];
    }

    private function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20)
        );
    }

    private function truncate(string $value, int $length): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $length);
        }
        if (preg_match_all('/./us', $value, $matches) !== false) {
            return implode('', array_slice($matches[0], 0, $length));
        }
        return substr($value, 0, $length);
    }
}
