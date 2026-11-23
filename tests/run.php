<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/whatsapp.php';
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/webhook_inbox.php';
require_once dirname(__DIR__) . '/app/meta_signup.php';

$passed = 0;
$failed = 0;

function check(bool $condition, string $name): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        fwrite(STDOUT, "PASS {$name}\n");
    } else {
        $failed++;
        fwrite(STDERR, "FAIL {$name}\n");
    }
}

$raw = '{"object":"whatsapp_business_account","entry":[]}';
$secret = 'unit-test-meta-app-secret';
$signature = 'sha256=' . hash_hmac('sha256', $raw, $secret);
check(verify_meta_webhook_signature($raw, $signature, $secret), 'valid Meta webhook signature is accepted');
check(!verify_meta_webhook_signature($raw . ' ', $signature, $secret), 'modified raw webhook body is rejected');
check(!verify_meta_webhook_signature($raw, $signature, 'wrong-secret'), 'wrong app secret is rejected');
check(!verify_meta_webhook_signature($raw, 'sha256=bad', $secret), 'malformed signature is rejected');
check(!verify_meta_webhook_signature($raw, null, $secret), 'missing signature is rejected');
check(normalize_whatsapp_number('+234 803-123-4567') === '2348031234567', 'WhatsApp number normalization retains international digits');
check(normalize_whatsapp_number('00234 803-123-4567') === '2348031234567', '00-prefixed international numbers normalize to country-code digits');
check(normalize_whatsapp_number('') === '', 'empty phone remains empty');
check(webhook_timestamp('1739321024') === gmdate('Y-m-d H:i:s', 1739321024), 'provider timestamp is converted to UTC');
check(whatsapp_retry_delay_seconds(1) === 15, 'first retry delay is 15 seconds');
check(whatsapp_retry_delay_seconds(2) === 30, 'retry delay doubles');
check(whatsapp_retry_delay_seconds(6) === 480, 'sixth retry delay is bounded exponential');
check(whatsapp_retry_delay_seconds(99) === 3600, 'retry delay is capped at one hour');
$encryptedPayload = encrypt_webhook_payload('{"object":"whatsapp_business_account"}');
check(decrypt_webhook_payload($encryptedPayload['ciphertext'], $encryptedPayload['nonce'], $encryptedPayload['tag']) === '{"object":"whatsapp_business_account"}', 'webhook payload encryption round-trips with authenticated encryption');
$tamperedWebhookCiphertext = $encryptedPayload['ciphertext'];
$tamperedWebhookCiphertext[0] = chr(ord($tamperedWebhookCiphertext[0]) ^ 1);
$webhookTamperRejected = false;
try {
    decrypt_webhook_payload($tamperedWebhookCiphertext, $encryptedPayload['nonce'], $encryptedPayload['tag']);
} catch (RuntimeException) {
    $webhookTamperRejected = true;
}
check($webhookTamperRejected, 'webhook payload authentication rejects ciphertext tampering');
$sealedMetaToken = meta_seal_token('test-meta-access-token');
check(meta_open_token($sealedMetaToken['ciphertext'], $sealedMetaToken['nonce'], $sealedMetaToken['tag']) === 'test-meta-access-token', 'Meta access tokens are encrypted at rest and authenticated on read');
$tamperedMetaCiphertext = $sealedMetaToken['ciphertext'];
$tamperedMetaCiphertext[0] = chr(ord($tamperedMetaCiphertext[0]) ^ 1);
$metaTokenTamperRejected = false;
try {
    meta_open_token($tamperedMetaCiphertext, $sealedMetaToken['nonce'], $sealedMetaToken['tag']);
} catch (RuntimeException) {
    $metaTokenTamperRejected = true;
}
check($metaTokenTamperRejected, 'Meta token authentication rejects ciphertext tampering');
$metaGraphUrlRejected = false;
try {
    (new MetaGraphApiClient())->get('https://example.com/v20.0/oauth/access_token');
} catch (InvalidArgumentException) {
    $metaGraphUrlRejected = true;
}
check($metaGraphUrlRejected, 'Meta Graph client rejects non-Meta hosts before network access');
$metaGraphHttpRejected = false;
try {
    (new MetaGraphApiClient())->post('http://graph.facebook.com/v20.0/123/subscribed_apps', 'token');
} catch (InvalidArgumentException) {
    $metaGraphHttpRejected = true;
}
check($metaGraphHttpRejected, 'Meta Graph client rejects non-HTTPS requests before network access');
$metaGraphHeaderRejected = false;
try {
    (new MetaGraphApiClient())->post('https://graph.facebook.com/v20.0/123/subscribed_apps', "token\r\nX-Injected: yes");
} catch (InvalidArgumentException) {
    $metaGraphHeaderRejected = true;
}
check($metaGraphHeaderRejected, 'Meta Graph client rejects CR/LF in bearer tokens');
$logContext = app_sanitize_log_context(['attempt' => 3, 'exception_class' => 'PDOException', 'phone_number' => '+2348012345678', 'message' => 'private chat content']);
check($logContext === ['attempt' => 3, 'exception_class' => 'PDOException'], 'structured operational logs allow only non-PII diagnostic fields');

$transportRejected = false;
try {
    (new MetaCloudApiTransport([]))->sendTemplate('2348031234567', 'Hello');
} catch (WhatsAppTransportException $error) {
    $transportRejected = !$error->retryable;
}
check($transportRejected, 'incomplete Meta transport configuration fails without retry');

fwrite(STDOUT, "\n{$passed} passed; {$failed} failed.\n");
exit($failed === 0 ? 0 : 1);
