<?php
declare(strict_types=1);

/** Domain-level signal translated to HTTP 429 by the route adapter. */
final class GuestSessionRateLimitExceeded extends RuntimeException
{
    /** @var int */
    public $retryAfterSeconds;

    public function __construct(int $retryAfterSeconds)
    {
        parent::__construct('The guest-session rate limit has been reached.');
        $this->retryAfterSeconds = max(1, $retryAfterSeconds);
    }
}

/** Creates secure guest sessions without depending on PDO, HTTP globals or response helpers. */
final class GuestSessionService
{
    /** @var GuestSessionOperationsRepository */
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
     * @param array{name:?string,phone:?string,phone_normalized:?string,whatsapp_opt_in:bool} $visitor
     * @return array{session_id:string,conversation_id:string,session_token:string,expires_at:string,created_at:string,whatsapp_opt_in:bool}
     */
    public function create(array $visitor, string $rateLimitFingerprint): array
    {
        $name = $visitor['name'] ?? null;
        $phone = $visitor['phone'] ?? null;
        $normalizedPhone = $visitor['phone_normalized'] ?? null;
        $whatsappOptIn = $visitor['whatsapp_opt_in'] ?? false;
        if (
            ($name !== null && !is_string($name))
            || ($phone !== null && !is_string($phone))
            || ($normalizedPhone !== null && !is_string($normalizedPhone))
            || !is_bool($whatsappOptIn)
            || ($whatsappOptIn && (!is_string($normalizedPhone) || $normalizedPhone === ''))
        ) {
            throw new InvalidArgumentException('Guest session visitor data is invalid.');
        }
        if ($rateLimitFingerprint === '') {
            throw new InvalidArgumentException('A rate-limit fingerprint is required.');
        }

        $rateConfig = $this->config['app']['session_rate_limit'] ?? [];
        if (!is_array($rateConfig)) {
            $rateConfig = [];
        }
        $maxRequests = max(1, (int) ($rateConfig['max_requests'] ?? 10));
        $windowSeconds = max(60, (int) ($rateConfig['window_seconds'] ?? 900));
        if (!$this->repository->consumeRateLimit('session:' . $rateLimitFingerprint, $maxRequests, $windowSeconds)) {
            throw new GuestSessionRateLimitExceeded($windowSeconds);
        }

        $sessionId = $this->uuidV4();
        $conversationId = $this->uuidV4();
        $guestId = $this->uuidV4();
        // The browser receives this random token once; only its hash is persisted.
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);

        $ttlHours = max(1, min(720, (int) ($this->config['app']['guest_session_ttl_hours'] ?? 168)));
        $createdAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $expiresAt = $createdAt->modify('+' . $ttlHours . ' hours');

        $this->repository->createGuestSession([
            'session_id' => $sessionId,
            'conversation_id' => $conversationId,
            'guest_id' => $guestId,
            'name' => $name,
            'phone' => $phone,
            'phone_normalized' => $normalizedPhone,
            'whatsapp_opt_in' => $whatsappOptIn,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt,
            'created_at' => $createdAt,
        ]);

        return [
            'session_id' => $sessionId,
            'conversation_id' => $conversationId,
            'session_token' => $token,
            'expires_at' => $expiresAt->format(DateTimeInterface::ATOM),
            'created_at' => $createdAt->format(DateTimeInterface::ATOM),
            'whatsapp_opt_in' => $whatsappOptIn,
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
}
