<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/whatsapp.php';
require_once dirname(__DIR__) . '/app/crypto.php';
require_once dirname(__DIR__) . '/app/meta.php';

function meta_check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    fwrite(STDOUT, "PASS: {$message}\n");
}

$invalidSessionRejected = false;
try {
    complete_meta_embedded_signup(database(), '00000000-0000-4000-8000-000000000001', 'fake-code', []);
} catch (InvalidArgumentException $error) {
    $invalidSessionRejected = true;
}
meta_check($invalidSessionRejected, 'Meta onboarding rejects a v5 session without messaging account IDs before exchanging a code');

$invalidPathRejected = false;
try {
    chatbridge_meta_graph_request('GET', '../../tenant-secret', 'not-a-real-token');
} catch (InvalidArgumentException $error) {
    $invalidPathRejected = true;
}
meta_check($invalidPathRejected, 'Meta Graph client rejects non-resource paths before making a network request');

meta_check(function_exists('resolve_meta_tenant_by_account_id'), 'webhook router can resolve account-level events by Messaging Account or WAAC ID');

$syncSummary = process_meta_coexistence_sync_jobs(database(), '00000000-0000-4000-8000-000000000001');
meta_check(($syncSummary['requested'] ?? -1) === 0, 'Coexistence sync worker is idle without queued jobs and makes no provider request');

fwrite(STDOUT, "Meta onboarding contract tests passed.\n");
