<?php
declare(strict_types=1);

// Import one plain-text knowledge source into a tenant's private knowledge base.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

$tenantId = trim((string)($argv[1] ?? ''));
$type = trim((string)($argv[2] ?? 'faq'));
$title = trim((string)($argv[3] ?? ''));
$file = (string)($argv[4] ?? '');
$allowedTypes = ['faq', 'policy', 'product', 'page', 'document'];
$path = $file !== '' ? realpath($file) : false;

if (!preg_match('/^[0-9a-f-]{36}$/i', $tenantId) || !in_array($type, $allowedTypes, true) || $title === '' || strlen($title) > 240 || $path === false || !is_file($path) || !is_readable($path)) {
    fwrite(STDERR, "Usage: php bin/import-knowledge.php TENANT_UUID faq|policy|product|page|document \"Title\" /path/to/source.txt\n");
    exit(2);
}
$size = filesize($path);
if (!is_int($size) || $size < 1 || $size > 100000) {
    fwrite(STDERR, "Knowledge source must be between 1 byte and 100 KB.\n");
    exit(2);
}
$content = trim((string)file_get_contents($path));
if ($content === '' || preg_match('//u', $content) !== 1) {
    fwrite(STDERR, "Knowledge source must contain readable UTF-8 text.\n");
    exit(2);
}
$hash = hash('sha256', $content);

try {
    $pdo = database();
    $insert = $pdo->prepare(
        'INSERT INTO knowledge_sources
            (id, tenant_id, source_type, title, content, content_sha256, status, created_at, updated_at)
         VALUES (:id, :tenant_id, :source_type, :title, :content, :hash, "published", UTC_TIMESTAMP(), UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE title = VALUES(title), content = VALUES(content),
            status = "published", updated_at = UTC_TIMESTAMP()'
    );
    $insert->execute([
        ':id' => uuid_v4(),
        ':tenant_id' => $tenantId,
        ':source_type' => $type,
        ':title' => $title,
        ':content' => $content,
        ':hash' => $hash,
    ]);
    fwrite(STDOUT, "Knowledge source published for tenant {$tenantId}.\n");
} catch (Throwable $error) {
    fwrite(STDERR, "Knowledge import failed: " . $error->getMessage() . "\n");
    exit(1);
}
