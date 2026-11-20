<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/whatsapp.php';
require_once dirname(__DIR__) . '/app/crypto.php';
require_once dirname(__DIR__) . '/app/ai.php';

function ai_check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    fwrite(STDOUT, "PASS: {$message}\n");
}

$secret = 'test-provider-secret-that-must-not-be-stored-plaintext';
$encrypted = chatbridge_encrypt_secret($secret);
ai_check(strpos($encrypted, 'v1:') === 0 && strpos($encrypted, $secret) === false, 'provider credentials are encrypted before storage');
ai_check(chatbridge_decrypt_secret($encrypted) === $secret, 'encrypted provider credentials round-trip');

$tampered = substr($encrypted, 0, -2) . 'AA';
$tamperRejected = false;
try {
    chatbridge_decrypt_secret($tampered);
} catch (Throwable $error) {
    $tamperRejected = true;
}
ai_check($tamperRejected, 'AES-GCM authentication rejects modified ciphertext');

$httpRejected = false;
try {
    new OpenAICompatibleChatProvider('http://127.0.0.1:8080', 'key', 'model');
} catch (InvalidArgumentException $error) {
    $httpRejected = true;
}
ai_check($httpRejected, 'AI provider adapter rejects non-HTTPS endpoints');
ai_check(is_subclass_of(OpenAICompatibleChatProvider::class, ChatBridgeAIProvider::class), 'OpenAI-compatible adapter implements provider abstraction');

fwrite(STDOUT, "AI foundation tests passed.\n");
