<?php
declare(strict_types=1);

/**
 * Verifies Meta's X-Hub-Signature-256 against the exact raw request bytes.
 */
final class MetaWebhookSignatureVerifier
{
    public function verify(string $rawBody, ?string $signatureHeader, string $appSecret): bool
    {
        if (
            $appSecret === ''
            || $signatureHeader === null
            || !preg_match('/^sha256=([a-f0-9]{64})$/i', trim($signatureHeader), $match)
        ) {
            return false;
        }

        $expected = hash_hmac('sha256', $rawBody, $appSecret);
        return hash_equals($expected, strtolower($match[1]));
    }
}
