<?php
declare(strict_types=1);

/**
 * Authenticated encryption for durable Meta webhook payloads.
 *
 * The key derivation and AAD are intentionally stable: existing inbox rows
 * written by the procedural implementation remain decryptable.
 */
final class WebhookPayloadCipher
{
    private string $key;

    public function __construct(string $applicationKey)
    {
        if (strlen($applicationKey) < 32) {
            throw new RuntimeException('A 32-character app.app_key is required for webhook encryption.');
        }
        $this->key = hash('sha256', 'dimdian-webhook-inbox:' . $applicationKey, true);
    }

    /** @return array{ciphertext:string,nonce:string,tag:string} */
    public function encrypt(string $plaintext): array
    {
        if (!function_exists('openssl_encrypt')) {
            throw new RuntimeException('OpenSSL is required for encrypted webhook storage.');
        }
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $this->key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            'dimdian-webhook-v1',
            16
        );
        if (!is_string($ciphertext) || strlen($tag) !== 16) {
            throw new RuntimeException('Webhook payload encryption failed.');
        }
        return ['ciphertext' => $ciphertext, 'nonce' => $nonce, 'tag' => $tag];
    }

    public function decrypt(string $ciphertext, string $nonce, string $tag): string
    {
        if (!function_exists('openssl_decrypt')) {
            throw new RuntimeException('OpenSSL is required for encrypted webhook storage.');
        }
        if (strlen($nonce) !== 12 || strlen($tag) !== 16) {
            throw new RuntimeException('Stored webhook encryption metadata is invalid.');
        }
        $plaintext = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            $this->key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            'dimdian-webhook-v1'
        );
        if (!is_string($plaintext)) {
            throw new RuntimeException('Webhook payload authentication failed.');
        }
        return $plaintext;
    }
}
