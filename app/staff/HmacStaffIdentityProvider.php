<?php
declare(strict_types=1);

/**
 * Provider-neutral HMAC assertion adapter for trusted server-side integrations.
 *
 * A website/CMS backend signs one assertion per API request. The signing
 * secret is shared only by the two servers and is never sent to the browser.
 */
final class HmacStaffIdentityProvider implements StaffIdentityProvider
{
    public function __construct(
        private string $secret,
        private StaffInboxRepository $repository,
        private int $clockSkewSeconds = 60
    ) {
        if (strlen($this->secret) < 32) {
            throw new InvalidArgumentException('Staff assertion secret must contain at least 32 characters.');
        }
        $this->clockSkewSeconds = max(15, min(300, $this->clockSkewSeconds));
    }

    public function authenticate(array $server): ?array
    {
        $token = $server['HTTP_X_CHATBRIDGE_STAFF_ASSERTION'] ?? '';
        if (!is_string($token) || strlen($token) > 8192 || substr_count($token, '.') !== 1) {
            return null;
        }
        [$encodedPayload, $encodedSignature] = explode('.', $token, 2);
        if ($encodedPayload === '' || $encodedSignature === '') return null;

        $expectedSignature = self::base64UrlEncode(hash_hmac('sha256', $encodedPayload, $this->secret, true));
        if (!hash_equals($expectedSignature, $encodedSignature)) return null;

        $rawPayload = self::base64UrlDecode($encodedPayload);
        if ($rawPayload === null) return null;
        try {
            $payload = json_decode($rawPayload, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!is_array($payload)) return null;

        $issuedAt = filter_var($payload['iat'] ?? null, FILTER_VALIDATE_INT);
        $nonce = $payload['nonce'] ?? null;
        $method = strtoupper((string)($server['REQUEST_METHOD'] ?? 'GET'));
        $requestTarget = (string)($server['REQUEST_URI'] ?? '/');
        $signedMethod = strtoupper((string)($payload['method'] ?? ''));
        $signedTarget = $payload['target'] ?? null;
        $signedBodyHash = $payload['body_sha256'] ?? null;
        $rawBody = $server['CHATBRIDGE_RAW_BODY'] ?? null;
        if (!is_string($rawBody)) {
            $readBody = file_get_contents('php://input');
            $rawBody = is_string($readBody) ? $readBody : '';
        }
        $actualBodyHash = hash('sha256', $rawBody);

        if ($issuedAt === false || abs(time() - $issuedAt) > $this->clockSkewSeconds
            || !is_string($nonce) || !preg_match('/^[A-Za-z0-9_-]{32,128}$/', $nonce)
            || $signedMethod !== $method || !is_string($signedTarget) || $signedTarget !== $requestTarget
            || !is_string($signedBodyHash) || !preg_match('/^[a-f0-9]{64}$/', $signedBodyHash)
            || !hash_equals($actualBodyHash, $signedBodyHash)) {
            return null;
        }

        // Tenant identity is part of the signed payload, never a header, query
        // parameter or body field supplied separately by the browser.
        $tenantId = strtolower((string)($payload['tenant_id'] ?? ''));
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $tenantId)) {
            return null;
        }

        $provider = strtolower(trim((string)($payload['auth_provider'] ?? '')));
        $subject = (string)($payload['external_subject'] ?? '');
        $name = trim((string)($payload['display_name'] ?? ''));
        $email = $payload['email'] ?? null;
        if (!preg_match('/^[a-z0-9._-]{1,40}$/', $provider)
            || $subject === '' || strlen($subject) > 191
            || $name === '' || strlen($name) > 100
            || ($email !== null && !is_string($email))) {
            return null;
        }

        $expiresAt = gmdate('Y-m-d H:i:s', max(time(), $issuedAt) + $this->clockSkewSeconds + 1);
        if (!$this->repository->consumeStaffAuthNonce(hash('sha256', $nonce), $expiresAt, $tenantId)) {
            return null;
        }

        return [
            'tenant_id' => $tenantId,
            'auth_provider' => $provider,
            'external_subject' => $subject,
            'display_name' => $name,
            'email' => $email,
        ];
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): ?string
    {
        if ($value === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $value)) return null;
        $padding = (4 - (strlen($value) % 4)) % 4;
        $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', $padding), true);
        return is_string($decoded) ? $decoded : null;
    }
}
