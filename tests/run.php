<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/whatsapp.php';

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
check(normalize_whatsapp_number('') === '', 'empty phone remains empty');
check(webhook_timestamp('1739321024') === gmdate('Y-m-d H:i:s', 1739321024), 'provider timestamp is converted to UTC');
check(whatsapp_retry_delay_seconds(1) === 15, 'first retry delay is 15 seconds');
check(whatsapp_retry_delay_seconds(2) === 30, 'retry delay doubles');
check(whatsapp_retry_delay_seconds(6) === 480, 'sixth retry delay is bounded exponential');
check(whatsapp_retry_delay_seconds(99) === 3600, 'retry delay is capped at one hour');

$transportRejected = false;
try {
    (new MetaCloudApiTransport([]))->sendTemplate('2348031234567', 'Hello');
} catch (WhatsAppTransportException $error) {
    $transportRejected = !$error->retryable;
}
check($transportRejected, 'incomplete Meta transport configuration fails without retry');

fwrite(STDOUT, "\n{$passed} passed; {$failed} failed.\n");
exit($failed === 0 ? 0 : 1);
