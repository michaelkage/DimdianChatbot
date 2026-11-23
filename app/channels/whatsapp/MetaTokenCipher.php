<?php
declare(strict_types=1);

/**
 * Authenticated encryption for Meta access tokens.
 *
 * The key derivation and AAD are fixed to preserve existing database rows.
 */
final class MetaTokenCipher
{
    private string $key;

    public function __construct(string $applicationKey)
    {
        if (strlen($applicationKey) < 32) {
            throw new RuntimeException('app.app_key must contain at least 32 characters.');
        }
        $this->key = hash('sha256', 'dimdian-meta-token:' . $applicationKey, true);
    }

    /** @return array{ciphertext:string,nonce:string,tag:string} */
    public function seal(string $token): array
    {
        if (!function_exists('openssl_encrypt')) {
            throw new RuntimeException('OpenSSL is required for Meta token encryption.');
        }
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $token,
            'aes-256-gcm',
            $this->key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            'dimdian-meta-token-v1',
            16
        );
        if (!is_string($ciphertext) || strlen($tag) !== 16) {
            throw new RuntimeException('Meta token encryption failed.');
        }
        return ['ciphertext' => $ciphertext, 'nonce' => $nonce, 'tag' => $tag];
    }

    public function open(string $ciphertext, string $nonce, string $tag): string
    {
        if (!function_exists('openssl_decrypt')) {
            throw new RuntimeException('OpenSSL is required for Meta token decryption.');
        }
        if (strlen($nonce) !== 12 || strlen($tag) !== 16) {
            throw new RuntimeException('Stored Meta token encryption metadata is invalid.');
        }
        $token = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            $this->key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            'dimdian-meta-token-v1'
        );
        if (!is_string($token) || $token === '') {
            throw new RuntimeException('Stored Meta token could not be authenticated.');
        }
        return $token;
    }
}
