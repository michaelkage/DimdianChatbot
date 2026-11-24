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

// Optional environment overrides keep secrets out of deployed config files.
$environmentMap = [
    'DIMDIAN_APP_KEY' => ['app', 'app_key'],
    'DIMDIAN_DATABASE_DSN' => ['database', 'dsn'],
    'DIMDIAN_DATABASE_USERNAME' => ['database', 'username'],
    'DIMDIAN_DATABASE_PASSWORD' => ['database', 'password'],
    'DIMDIAN_META_APP_ID' => ['meta', 'app_id'],
    'DIMDIAN_META_CONFIG_ID' => ['meta', 'config_id'],
    'DIMDIAN_META_ADMIN_API_KEY' => ['meta', 'admin_api_key'],
    'DIMDIAN_WHATSAPP_ACCESS_TOKEN' => ['whatsapp', 'access_token'],
    'DIMDIAN_WHATSAPP_APP_SECRET' => ['whatsapp', 'app_secret'],
    'DIMDIAN_WHATSAPP_VERIFY_TOKEN' => ['whatsapp', 'verify_token'],
    'DIMDIAN_WHATSAPP_PHONE_NUMBER_ID' => ['whatsapp', 'phone_number_id'],
    'DIMDIAN_WHATSAPP_GRAPH_API_VERSION' => ['whatsapp', 'graph_api_version'],
];
foreach ($environmentMap as $environmentName => $path) {
    $value = getenv($environmentName);
    if ($value === false) continue;
    if (!isset($config[$path[0]]) || !is_array($config[$path[0]])) $config[$path[0]] = [];
    $config[$path[0]][$path[1]] = $value;
}
$outboundEnvironment = getenv('DIMDIAN_WHATSAPP_OUTBOUND_ENABLED');
if ($outboundEnvironment !== false) {
    $config['whatsapp']['outbound_enabled'] = filter_var($outboundEnvironment, FILTER_VALIDATE_BOOL);
}

require_once __DIR__ . '/observability.php';
app_request_id();

date_default_timezone_set((string)($config['app']['timezone'] ?? 'UTC'));

require_once __DIR__ . '/storage/PdoSqlDialect.php';
require_once __DIR__ . '/tenancy/TenantContext.php';
require_once __DIR__ . '/tenancy/TenantRepository.php';
require_once __DIR__ . '/tenancy/TenantResolver.php';
require_once __DIR__ . '/tenancy/PdoTenantRepository.php';
require_once __DIR__ . '/storage/ChatRepository.php';
require_once __DIR__ . '/storage/MetaConnectionRepository.php';
require_once __DIR__ . '/storage/PdoMetaConnectionRepository.php';
require_once __DIR__ . '/storage/PdoConsentRepository.php';
require_once __DIR__ . '/storage/PdoGuestSessionRepository.php';
require_once __DIR__ . '/storage/PdoProviderEventRepository.php';
require_once __DIR__ . '/storage/PdoRateLimitRepository.php';
require_once __DIR__ . '/storage/PdoVisitorMessageRepository.php';
require_once __DIR__ . '/storage/PdoWhatsAppOutboxRepository.php';
require_once __DIR__ . '/storage/PdoWebhookInboxRepository.php';
require_once __DIR__ . '/storage/PdoChatRepository.php';
require_once __DIR__ . '/chat/GuestSessionAuthenticator.php';
require_once __DIR__ . '/storage/RetentionRepository.php';
require_once __DIR__ . '/storage/PdoRetentionRepository.php';
require_once __DIR__ . '/storage/HealthRepository.php';
require_once __DIR__ . '/storage/PdoHealthRepository.php';

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

/** Resolve the request's public widget routing hint into trusted tenant context. */
function tenant_context_from_request(): TenantContext
{
    static $resolved = null;
    if ($resolved instanceof TenantContext) {
        return $resolved;
    }

    $tenancy = app_config('tenancy') ?? [];
    if (!is_array($tenancy)) {
        $tenancy = [];
    }
    $widgetId = $_SERVER['HTTP_X_DIMDIAN_WIDGET_ID'] ?? null;
    $widgetId = is_string($widgetId) && $widgetId !== '' ? $widgetId : null;
    $origin = request_origin();

    if (($tenancy['mode'] ?? 'single_tenant') === 'single_tenant') {
        $tenantId = (string) ($tenancy['default_tenant_id'] ?? '00000000-0000-4000-8000-000000000001');
        $resolved = new TenantContext($tenantId, $widgetId);
        return $resolved;
    }

    $resolver = new TenantResolver(new PdoTenantRepository(database()), $tenancy);
    $resolved = $resolver->resolve($widgetId, $origin);
    return $resolved;
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
    $tenancyMode = (string) (app_config('tenancy.mode') ?? 'single_tenant');
    if ($tenancyMode === 'shared_database') {
        try {
            tenant_context_from_request();
        } catch (TenantResolutionException $error) {
            json_response([
                'error' => [
                    'code' => 'tenant_widget_not_authorized',
                    'message' => $error->getMessage(),
                ],
            ], 403);
        }
    } else {
        $allowed = app_config('app.allowed_origins') ?? [];
        if (!is_array($allowed)) {
            $allowed = [];
        }

        $normalized = array_map(static fn($value): string => rtrim((string) $value, '/'), $allowed);
        if ($origin !== null && !in_array($origin, $normalized, true)) {
            json_response(['error' => ['code' => 'origin_not_allowed', 'message' => 'This website is not allowed to use this chat service.']], 403);
        }
    }

    if ($origin !== null) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-DIMDIAN-GUEST-TOKEN, X-DIMDIAN-WIDGET-ID');
        header('Access-Control-Max-Age: 600');
    }
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
    if ($raw === false) $raw = '';
    // Preserve the exact bytes so the optional staff HMAC adapter can verify
    // the body after the JSON decoder has consumed php://input.
    $_SERVER['CHATBRIDGE_RAW_BODY'] = $raw;
    if (trim($raw) === '') {
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
    if (PdoSqlDialect::isPostgres($pdo)) {
        // All persisted timestamps are UTC wall-clock values, matching MySQL DATETIME semantics.
        $pdo->exec("SET TIME ZONE 'UTC'");
    }
    return $pdo;
}

/**
 * Resolve the configured persistence adapter.
 * The current reference adapter is PDO/MySQL; alternative adapters must
 * implement ChatRepository and be selected through explicit configuration.
 */
function chat_repository(?PDO $connection = null): ChatRepository
{
    return new PdoChatRepository($connection ?? database());
}


/** Resolve the configured Meta connection persistence adapter. */
function meta_connection_repository(?PDO $connection = null): MetaConnectionRepository
{
    return new PdoMetaConnectionRepository($connection ?? database());
}


/** Resolve the configured retention persistence adapter. */
function retention_repository(?PDO $connection = null): RetentionRepository
{
    return new PdoRetentionRepository($connection ?? database());
}


/** Resolve the configured read-only health diagnostics adapter. */
function health_repository(?PDO $connection = null): HealthRepository
{
    return new PdoHealthRepository($connection ?? database());
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

function enforce_rate_limit(RateLimitRepository $repository, string $tenantId, string $bucket, int $maxRequests, int $windowSeconds): void
{
    if (!$repository->consumeRateLimit($tenantId, $bucket, $maxRequests, $windowSeconds)) {
        header('Retry-After: ' . max(1, $windowSeconds));
        json_response(['error' => ['code' => 'rate_limited', 'message' => 'Too many attempts. Please wait a little and try again.']], 429);
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
 * Resolve the bearer-like guest token through the configured storage adapter.
 * The raw token is never queried or persisted; only its SHA-256 hash is used.
 */
function authenticate_guest_session(GuestSessionRepository $repository, TenantContext $tenantContext, bool $requireOpen = true): array
{
    $token = $_SERVER['HTTP_X_DIMDIAN_GUEST_TOKEN'] ?? '';
    $token = is_string($token) ? $token : '';

    try {
        return (new GuestSessionAuthenticator($repository))->authenticate($token, $tenantContext, $requireOpen);
    } catch (GuestSessionTokenInvalid $error) {
        json_response(['error' => ['code' => 'unauthorized', 'message' => $error->getMessage()]], 401);
    } catch (GuestSessionNotFound $error) {
        json_response(['error' => ['code' => 'session_expired', 'message' => $error->getMessage()]], 401);
    } catch (GuestConversationClosed $error) {
        json_response(['error' => ['code' => 'conversation_closed', 'message' => $error->getMessage()]], 409);
    }
}
