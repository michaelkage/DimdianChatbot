<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/app/bootstrap.php';

$options = getopt('', ['provider:', 'subject:', 'role:']);
$provider = strtolower(trim((string)($options['provider'] ?? '')));
$subject = trim((string)($options['subject'] ?? ''));
$role = strtolower(trim((string)($options['role'] ?? '')));

if (!preg_match('/^[a-z0-9._-]{1,40}$/', $provider)
    || $subject === '' || strlen($subject) > 191
    || !in_array($role, ['owner', 'admin', 'agent'], true)) {
    fwrite(STDERR, "Usage: php bin/provision-staff.php --provider=wordpress --subject=user:1 --role=owner\n");
    exit(2);
}
if ((app_config('staff_inbox.enabled') ?? false) !== true) {
    fwrite(STDERR, "The optional staff inbox is not enabled in private service configuration.\n");
    exit(1);
}

try {
    $pdo = database();
    $query = $pdo->prepare(
        'UPDATE staff_identities
         SET role = :role, updated_at = UTC_TIMESTAMP()
         WHERE auth_provider = :provider AND external_subject = :subject AND status = "active"'
    );
    $query->execute([':role' => $role, ':provider' => $provider, ':subject' => $subject]);
    if ($query->rowCount() !== 1) {
        fwrite(STDERR, "No active staff identity matched. Have the user sign in to the inbox once first.\n");
        exit(1);
    }
    fwrite(STDOUT, "Staff role updated successfully.\n");
} catch (Throwable $error) {
    app_log_event('staff_inbox.provision_failed', ['endpoint' => 'provision-staff', 'exception_class' => get_class($error)]);
    fwrite(STDERR, "Staff role update failed. Check the private server log.\n");
    exit(1);
}
