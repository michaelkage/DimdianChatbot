<?php
declare(strict_types=1);

/**
 * Shared HTTP bootstrap for the small public API.
 * No framework, Composer packages, or long-running process required.
 */

// Production secrets live in the private project-root config.php, never under public/ or in the Git repository.
$configPath = dirname(__DIR__) . '/config.php';
if (!is_file($configPath)) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => ['code' => 'not_configured', 'message' => 'The application has not been configured.']]);
    exit;
}

$config = require $configPath;
if (!is_array($config)) {
    throw new RuntimeException('config.php must return an array.');
}

date_default_timezone_set((string)($config['app']['timezone'] ?? 'UTC'));

function app_config(?string $key = null): mixed
{
    global $config;
    if ($key === null) {
        return $config;
    }

    $value = $config;
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return null;
        }
        $value = $value[$part];
    }
    return $value;
}

function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function request_origin(): ?string
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
    return is_string($origin) && $origin !== '' ? rtrim($origin, '/') : null;
}

function enforce_allowed_origin(): void
{
    $origin = request_origin();
    if ($origin === null) {
        return;
    }

    // The verified tenant_sites registry is the authoritative CORS allowlist.
    // Do not trust an Origin merely because it appears in a client payload or
    // in a static global allowlist shared by every tenant.
    $normalizedOrigin = normalize_request_origin($origin);
    if ($normalizedOrigin === null) {
        json_response(['error' => ['code' => 'origin_not_allowed', 'message' => 'This website origin is invalid.']], 403);
    }

    try {
        if (chatbridge_store_driver() === 'supabase') {
            $tenantId = chatbridge_supabase_resolve_tenant_from_origin($normalizedOrigin);
            if ($tenantId === null) {
                json_response(['error' => ['code' => 'origin_not_allowed', 'message' => 'This website is not registered with the chat service.']], 403);
            }
        } else {
            $pdo = database();
            $check = $pdo->prepare(
                'SELECT 1 FROM tenant_sites s
                 INNER JOIN tenants t ON t.id = s.tenant_id
                 WHERE s.origin = :origin AND s.status = "verified" AND t.status = "active"
                 LIMIT 1'
            );
            $check->execute([':origin' => $normalizedOrigin]);
            if (!$check->fetchColumn()) {
                json_response(['error' => ['code' => 'origin_not_allowed', 'message' => 'This website is not registered with the chat service.']], 403);
            }
        }
    } catch (Throwable $error) {
        error_log('[ChatBridge] Origin validation failed: ' . $error->getMessage());
        json_response(['error' => ['code' => 'service_unavailable', 'message' => 'The chat service is temporarily unavailable.']], 503);
    }

    header('Access-Control-Allow-Origin: ' . $normalizedOrigin);
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-DIMDIAN-GUEST-TOKEN');
    header('Access-Control-Max-Age: 600');
}

function handle_preflight(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

function require_method(string $method): void
{
    $actual = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($actual !== strtoupper($method)) {
        header('Allow: ' . strtoupper($method) . ', OPTIONS');
        json_response(['error' => ['code' => 'method_not_allowed', 'message' => 'Use ' . strtoupper($method) . ' for this endpoint.']], 405);
    }
}

function request_json(int $maxBytes = 8192): array
{
    $length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length > $maxBytes) {
        json_response(['error' => ['code' => 'payload_too_large', 'message' => 'The request is too large.']], 413);
    }

    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    if (strlen($raw) > $maxBytes) {
        json_response(['error' => ['code' => 'payload_too_large', 'message' => 'The request is too large.']], 413);
    }

    try {
        $data = json_decode($raw, false, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        json_response(['error' => ['code' => 'invalid_json', 'message' => 'Send a valid JSON object.']], 400);
    }

    if (!is_object($data)) {
        json_response(['error' => ['code' => 'invalid_payload', 'message' => 'The request body must be a JSON object.']], 400);
    }
    return get_object_vars($data);
}

function database(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $db = app_config('database');
    if (!is_array($db) || empty($db['dsn'])) {
        throw new RuntimeException('Database configuration is incomplete.');
    }

    $options = $db['options'] ?? [];
    $options[PDO::ATTR_ERRMODE] = PDO::ERRMODE_EXCEPTION;
    $options[PDO::ATTR_DEFAULT_FETCH_MODE] = PDO::FETCH_ASSOC;
    $options[PDO::ATTR_EMULATE_PREPARES] = false;

    $pdo = new PDO((string)$db['dsn'], (string)($db['username'] ?? ''), (string)($db['password'] ?? ''), $options);
    return $pdo;
}

function client_fingerprint(): string
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $key = (string)app_config('app.app_key');
    if (strlen($key) < 32) {
        throw new RuntimeException('app.app_key must contain at least 32 characters.');
    }
    return hash_hmac('sha256', $ip, $key);
}

function enforce_rate_limit(PDO $pdo, string $bucket, int $maxRequests, int $windowSeconds): void
{
    // rate_limits.bucket_key is fixed at 64 hex characters; hash all bucket names.
    $bucketKey = hash('sha256', $bucket);
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $cutoff = $now->modify('-' . max(1, $windowSeconds) . ' seconds')->format('Y-m-d H:i:s');
    $nowString = $now->format('Y-m-d H:i:s');

    $pdo->beginTransaction();
    try {
        $insert = $pdo->prepare(
            'INSERT INTO rate_limits (bucket_key, window_started_at, request_count)
             VALUES (:bucket, :now, 1)
             ON DUPLICATE KEY UPDATE
               request_count = IF(window_started_at <= :cutoff, 1, request_count + 1),
               window_started_at = IF(window_started_at <= :cutoff2, :now2, window_started_at)'
        );
        $insert->execute([
            ':bucket' => $bucketKey,
            ':now' => $nowString,
            ':cutoff' => $cutoff,
            ':cutoff2' => $cutoff,
            ':now2' => $nowString,
        ]);

        $select = $pdo->prepare('SELECT request_count FROM rate_limits WHERE bucket_key = :bucket FOR UPDATE');
        $select->execute([':bucket' => $bucketKey]);
        $row = $select->fetch();
        $pdo->commit();

        if (!$row || (int)$row['request_count'] > $maxRequests) {
            header('Retry-After: ' . max(1, $windowSeconds));
            json_response(['error' => ['code' => 'rate_limited', 'message' => 'Too many attempts. Please wait a little and try again.']], 429);
        }
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function uuid_v4(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
}

function clean_optional_string(mixed $value, int $maxLength, string $field): ?string
{
    if ($value === null || $value === '') {
        return null;
    }
    if (!is_string($value)) {
        json_response(['error' => ['code' => 'invalid_field', 'message' => $field . ' must be text.']], 422);
    }

    $value = trim($value);
    if ($value === '') {
        return null;
    }

    $characterCount = preg_match_all('/./us', $value);
    if ($characterCount === false) {
        json_response(['error' => ['code' => 'invalid_field', 'message' => $field . ' must be valid UTF-8 text.']], 422);
    }
    if ($characterCount > $maxLength) {
        json_response(['error' => ['code' => 'invalid_field', 'message' => $field . ' is too long.']], 422);
    }
    return $value;
}

/**
 * Resolve the bearer-like guest token to its one active conversation.
 * The raw token is never queried or persisted; only its SHA-256 hash is used.
 */
function authenticate_guest_session(PDO $pdo, bool $requireOpen = true): array
{
    $tenantId = resolve_request_tenant_id($pdo);
    $token = $_SERVER['HTTP_X_DIMDIAN_GUEST_TOKEN'] ?? '';
    if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        json_response(['error' => ['code' => 'unauthorized', 'message' => 'A valid chat session token is required.']], 401);
    }

    $tokenHash = hash('sha256', $token);
    $statement = $pdo->prepare(
        'SELECT gs.id AS session_id, gs.tenant_id, gs.guest_id, gs.conversation_id,
                g.display_name, g.phone, g.phone_normalized, g.whatsapp_opt_in_at,
                c.status AS conversation_status
         FROM guest_sessions gs
         INNER JOIN conversations c ON c.id = gs.conversation_id
         INNER JOIN guests g ON g.id = gs.guest_id
         WHERE gs.token_hash = :token_hash
           AND gs.tenant_id = :tenant_id_session
           AND c.tenant_id = :tenant_id_conversation
           AND g.tenant_id = :tenant_id_guest
           AND gs.revoked_at IS NULL
           AND gs.expires_at > UTC_TIMESTAMP()
         LIMIT 1'
    );
    $statement->execute([
        ':token_hash' => $tokenHash,
        ':tenant_id_session' => $tenantId,
        ':tenant_id_conversation' => $tenantId,
        ':tenant_id_guest' => $tenantId,
    ]);
    $session = $statement->fetch();

    if (!$session) {
        json_response(['error' => ['code' => 'session_expired', 'message' => 'This chat session is no longer valid. Start a new chat to continue.']], 401);
    }

    $touch = $pdo->prepare('UPDATE guest_sessions SET last_seen_at = UTC_TIMESTAMP() WHERE tenant_id = :tenant_id AND id = :id');
    $touch->execute([':tenant_id' => $tenantId, ':id' => $session['session_id']]);

    if ($requireOpen && $session['conversation_status'] !== 'open') {
        json_response(['error' => ['code' => 'conversation_closed', 'message' => 'This conversation has been closed. Start a new chat to send another message.']], 409);
    }

    return $session;
}


require_once __DIR__ . '/tenancy.php';
require_once __DIR__ . '/supabase_store.php';
