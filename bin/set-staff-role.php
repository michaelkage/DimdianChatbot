<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

if (($argc ?? 0) !== 4) {
    fwrite(STDERR, "Usage: php bin/set-staff-role.php <provider> <external-subject> <owner|admin|agent>\n");
    exit(2);
}

$provider = strtolower(trim((string)$argv[1]));
$subject = trim((string)$argv[2]);
$role = strtolower(trim((string)$argv[3]));

if (!preg_match('/^[a-z0-9._-]{1,40}$/', $provider)
    || $subject === '' || strlen($subject) > 191
    || !in_array($role, ['owner', 'admin', 'agent'], true)) {
    fwrite(STDERR, "Invalid provider, external subject or role.\n");
    exit(2);
}

if ((app_config('staff_inbox.enabled') ?? false) !== true) {
    fwrite(STDERR, "The optional staff inbox must be enabled before staff roles can be provisioned.\n");
    exit(2);
}

try {
    $pdo = database();
    $pdo->beginTransaction();

    $lookup = $pdo->prepare(
        'SELECT id, display_name, status FROM staff_identities
         WHERE auth_provider = :provider AND external_subject = :subject
         LIMIT 1 FOR UPDATE'
    );
    $lookup->execute([':provider' => $provider, ':subject' => $subject]);
    $identity = $lookup->fetch();

    if (!is_array($identity) || ($identity['status'] ?? '') !== 'active') {
        $pdo->rollBack();
        fwrite(STDERR, "No active staff identity exists for that provider and subject. Have the user sign in to the inbox once, then retry.\n");
        exit(1);
    }

    $update = $pdo->prepare(
        'UPDATE staff_identities SET role = :role, updated_at = UTC_TIMESTAMP() WHERE id = :id'
    );
    $update->execute([':role' => $role, ':id' => $identity['id']]);
    $pdo->commit();

    fwrite(STDOUT, json_encode([
        'staff_identity_id' => $identity['id'],
        'display_name' => $identity['display_name'],
        'role' => $role,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
} catch (Throwable $error) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    app_log_event('staff_inbox.role_provision_failed', [
        'endpoint' => 'staff_role_provision',
        'exception_class' => get_class($error),
    ]);
    fwrite(STDERR, "Staff role provisioning failed. Check the private server log and database schema.\n");
    exit(1);
}
