<?php
declare(strict_types=1);

/** AES-256-GCM for tenant API credentials. The master key lives outside the DB. */
function chatbridge_encryption_key(): string
{
    $configured = (string)(app_config('app.encryption_key') ?? '');
    if (preg_match('/^[a-f0-9]{64}$/i', $configured)) {
        $key = hex2bin($configured);
    } else {
        $key = base64_decode($configured, true);
    }
    if (!is_string($key) || strlen($key) !== 32) {
        throw new RuntimeException('app.encryption_key must be a 32-byte key encoded as 64 hex characters or base64.');
    }
    return $key;
}

function chatbridge_encrypt_secret(string $plaintext): string
{
    if ($plaintext === '') throw new InvalidArgumentException('Cannot encrypt an empty secret.');
    $nonce = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', chatbridge_encryption_key(), OPENSSL_RAW_DATA, $nonce, $tag, 'chatbridge:v1', 16);
    if (!is_string($ciphertext) || strlen($tag) !== 16) {
        throw new RuntimeException('Credential encryption failed.');
    }
    return 'v1:' . base64_encode($nonce . $tag . $ciphertext);
}

function chatbridge_decrypt_secret(string $encoded): string
{
    if (strpos($encoded, 'v1:') !== 0) {
        throw new RuntimeException('Unsupported encrypted credential version.');
    }
    $raw = base64_decode(substr($encoded, 3), true);
    if (!is_string($raw) || strlen($raw) < 29) {
        throw new RuntimeException('Encrypted credential is malformed.');
    }
    $nonce = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $ciphertext = substr($raw, 28);
    $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', chatbridge_encryption_key(), OPENSSL_RAW_DATA, $nonce, $tag, 'chatbridge:v1');
    if (!is_string($plaintext)) {
        throw new RuntimeException('Credential authentication failed.');
    }
    return $plaintext;
}
