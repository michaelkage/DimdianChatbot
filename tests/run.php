<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/whatsapp.php';
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/webhook_inbox.php';
require_once dirname(__DIR__) . '/app/meta_signup.php';
require_once dirname(__DIR__) . '/app/maintenance/RetentionService.php';
require_once dirname(__DIR__) . '/app/operations/HealthService.php';
require_once dirname(__DIR__) . '/app/chat/GuestSessionService.php';
require_once dirname(__DIR__) . '/app/chat/ChatMessageService.php';
require_once dirname(__DIR__) . '/app/chat/WhatsAppConsentService.php';

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

foreach ([
    GuestSessionOperationsRepository::class,
    VisitorMessagingRepository::class,
    ConsentRepository::class,
    WhatsAppIdentityRepository::class,
    WebhookInboxRepository::class,
    ProviderEventRepository::class,
    WebhookProcessingRepository::class,
    WhatsAppOutboxRepository::class,
] as $capability) {
    check(is_subclass_of(ChatRepository::class, $capability), 'ChatRepository preserves the ' . $capability . ' capability');
}

check(
    is_subclass_of(PdoWebhookInboxRepository::class, WebhookInboxRepository::class),
    'PdoWebhookInboxRepository directly implements the durable inbox capability'
);

check(
    is_subclass_of(PdoRateLimitRepository::class, RateLimitRepository::class),
    'PdoRateLimitRepository directly implements the rate-limit capability'
);

check(
    is_subclass_of(PdoConsentRepository::class, ConsentRepository::class),
    'PdoConsentRepository directly implements the consent capability'
);

check(
    is_subclass_of(PdoGuestSessionRepository::class, GuestSessionRepository::class),
    'PdoGuestSessionRepository directly implements the guest-session capability'
);

check(
    is_subclass_of(PdoVisitorMessageRepository::class, VisitorMessageRepository::class),
    'PdoVisitorMessageRepository directly implements the visitor-message capability'
);

$visitorMessageConstructor = (new ReflectionClass(PdoVisitorMessageRepository::class))->getConstructor();
$visitorOutboxType = $visitorMessageConstructor?->getParameters()[1]->getType() ?? null;
check(
    $visitorOutboxType instanceof ReflectionNamedType
        && $visitorOutboxType->getName() === WhatsAppOutboxRepository::class,
    'PdoVisitorMessageRepository reuses the outbox capability for atomic queue insertion'
);

check(
    is_subclass_of(PdoProviderEventRepository::class, ProviderEventRepository::class),
    'PdoProviderEventRepository directly implements the provider-event capability'
);

check(
    is_subclass_of(PdoWhatsAppOutboxRepository::class, WhatsAppOutboxRepository::class),
    'PdoWhatsAppOutboxRepository directly implements the WhatsApp outbox capability'
);

$aggregateProperties = array_map(
    static fn(ReflectionProperty $property): string => $property->getName(),
    (new ReflectionClass(PdoChatRepository::class))->getProperties()
);
check(
    is_subclass_of(PdoChatRepository::class, ChatRepository::class)
        && !in_array('pdo', $aggregateProperties, true),
    'PdoChatRepository remains the aggregate contract without retaining direct PDO state'
);

$serviceRepositoryContracts = [
    GuestSessionService::class => GuestSessionOperationsRepository::class,
    ChatMessageService::class => VisitorMessagingRepository::class,
    WhatsAppConsentService::class => ConsentRepository::class,
    MetaWebhookProcessor::class => ProviderEventRepository::class,
    WebhookInboxWorker::class => WebhookInboxRepository::class,
    WhatsAppOutboxWorker::class => WhatsAppOutboxRepository::class,
    MetaEmbeddedSignupService::class => MetaConnectionRepository::class,
    RetentionService::class => RetentionRepository::class,
    HealthService::class => HealthRepository::class,
];
foreach ($serviceRepositoryContracts as $serviceClass => $expectedRepository) {
    $constructor = (new ReflectionClass($serviceClass))->getConstructor();
    $repositoryTypes = [];
    $hasInfrastructureLeak = false;
    foreach ($constructor?->getParameters() ?? [] as $parameter) {
        $type = $parameter->getType();
        if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) continue;
        $name = $type->getName();
        if ($name === $expectedRepository) $repositoryTypes[] = $name;
        if ($name === PDO::class || $name === ChatRepository::class) $hasInfrastructureLeak = true;
    }
    check(
        count($repositoryTypes) === 1 && !$hasInfrastructureLeak,
        $serviceClass . ' depends on ' . $expectedRepository . ' rather than PDO or aggregate ChatRepository'
    );
}

final class HealthServiceTestRepository implements HealthRepository
{
    private $missing;
    private $metrics;
    public $metricsRead = false;

    public function __construct(array $missing, array $metrics)
    {
        $this->missing = $missing;
        $this->metrics = $metrics;
    }

    public function missingTables(array $requiredTables): array
    {
        return $this->missing;
    }

    public function queueMetrics(): array
    {
        $this->metricsRead = true;
        return $this->metrics;
    }
}

final class RetentionServiceTestRepository implements RetentionRepository
{
    public $arguments = null;

    public function prune(string $closedBefore, string $webhooksBefore, string $sessionsBefore, bool $includeStaff): array
    {
        $this->arguments = [$closedBefore, $webhooksBefore, $sessionsBefore, $includeStaff];
        return ['pruned' => 0];
    }
}

final class MetaServiceTestRepository implements MetaConnectionRepository
{
    public $states = [];
    public $connection = null;
    public $expired = [];

    public function createSignupState(string $stateHash, int $ttlSeconds = 600): void
    {
        $this->states[$stateHash] = true;
    }

    public function consumeSignupState(string $stateHash): bool
    {
        if (empty($this->states[$stateHash])) return false;
        unset($this->states[$stateHash]);
        return true;
    }

    public function saveActiveConnection(array $connection): void
    {
        $this->connection = [
            'id' => 1,
            'waba_id' => $connection['waba_id'],
            'phone_number_id' => $connection['phone_number_id'],
            'access_token_ciphertext' => $connection['token_ciphertext'],
            'access_token_nonce' => $connection['token_nonce'],
            'access_token_tag' => $connection['token_tag'],
            'token_expires_at' => $connection['token_expires_at'],
        ];
    }

    public function findActiveConnection(): ?array
    {
        return $this->connection;
    }

    public function updateAccessToken(int $connectionId, array $encrypted, ?string $expiresAt): void
    {
        if ($this->connection === null || $connectionId !== 1) throw new RuntimeException('Missing fake connection.');
        $this->connection['access_token_ciphertext'] = $encrypted['ciphertext'];
        $this->connection['access_token_nonce'] = $encrypted['nonce'];
        $this->connection['access_token_tag'] = $encrypted['tag'];
        $this->connection['token_expires_at'] = $expiresAt;
    }

    public function markConnectionExpired(int $connectionId): void
    {
        $this->expired[] = $connectionId;
        $this->connection = null;
    }

    public function activePhoneNumberId(): ?string
    {
        return $this->connection['phone_number_id'] ?? null;
    }
}

final class MetaServiceTestGraph implements MetaGraphApi
{
    public $getResponses = [];
    public $postResult = true;
    public $posts = [];

    public function get(string $url): array
    {
        $response = array_shift($this->getResponses);
        if ($response instanceof Throwable) throw $response;
        return is_array($response) ? $response : [];
    }

    public function post(string $url, string $token): bool
    {
        $this->posts[] = ['url' => $url, 'token' => $token];
        return $this->postResult;
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
$healthMetrics = [
    'webhook_pending' => 2,
    'webhook_failed' => 0,
    'outbox_pending' => 1,
    'outbox_failed' => 3,
];
$healthyHealthRepository = new HealthServiceTestRepository([], $healthMetrics);
$healthyHealth = (new HealthService($healthyHealthRepository))->report(true, true, true);
check(
    $healthyHealth['status'] === 'healthy'
        && $healthyHealth['http_status'] === 200
        && $healthyHealth['queues'] === $healthMetrics
        && $healthyHealth['whatsapp_outbound'] === 'enabled_in_config',
    'Health service composes healthy runtime, schema and queue diagnostics'
);
$incompleteHealthRepository = new HealthServiceTestRepository(['meta_connections'], $healthMetrics);
$incompleteHealth = (new HealthService($incompleteHealthRepository))->report(true, true, false);
check(
    $incompleteHealth['status'] === 'degraded'
        && $incompleteHealth['http_status'] === 503
        && $incompleteHealth['queues']['outbox_pending'] === null
        && !$incompleteHealthRepository->metricsRead,
    'Health service suppresses queue queries when required schema is incomplete'
);

$retentionTestRepository = new RetentionServiceTestRepository();
$retentionService = new RetentionService($retentionTestRepository, [
    'privacy' => [
        'closed_conversation_retention_days' => 1,
        'webhook_event_retention_days' => 999,
        'expired_session_retention_days' => 999,
    ],
    'staff_inbox' => ['enabled' => true],
]);
$retentionResult = $retentionService->run(new DateTimeImmutable('2026-01-31T12:00:00+00:00'));
check(
    $retentionResult === ['pruned' => 0]
        && $retentionTestRepository->arguments === [
            '2026-01-01 12:00:00',
            '2025-01-31 12:00:00',
            '2025-08-04 12:00:00',
            true,
        ],
    'Retention service clamps configured windows, calculates UTC cutoffs and gates optional staff cleanup'
);

$serviceRepository = new MetaServiceTestRepository();
$serviceGraph = new MetaServiceTestGraph();
$serviceConfig = [
    'meta' => ['app_id' => 'test-app-id', 'config_id' => 'test-config-id'],
    'whatsapp' => ['graph_api_version' => 'v20.0', 'app_secret' => 'test-app-secret'],
    'app' => ['app_key' => (string) app_config('app.app_key')],
];
$metaService = new MetaEmbeddedSignupService(
    $serviceRepository,
    new MetaTokenCipher((string) app_config('app.app_key')),
    $serviceGraph,
    $serviceConfig
);
$signup = $metaService->begin();
check(
    strlen($signup['state']) === 64
        && $signup['app_id'] === 'test-app-id'
        && $signup['expires_in_seconds'] === 600
        && isset($serviceRepository->states[hash('sha256', $signup['state'])]),
    'Meta signup service creates a short-lived opaque state without exposing its hash'
);
$serviceGraph->getResponses[] = ['access_token' => 'service-access-token', 'expires_in' => 3600];
$serviceExchange = $metaService->exchange($signup['state'], 'one-time-code', '123456789012345', '987654321098765');
check(
    ($serviceExchange['connected'] ?? false) === true
        && ($serviceExchange['webhook_subscribed'] ?? false) === true
        && $serviceRepository->connection !== null
        && (new MetaTokenCipher((string) app_config('app.app_key')))->open(
            $serviceRepository->connection['access_token_ciphertext'],
            $serviceRepository->connection['access_token_nonce'],
            $serviceRepository->connection['access_token_tag']
        ) === 'service-access-token',
    'Meta signup service exchanges the code, encrypts the token and subscribes the WABA'
);
$metaStateReplayRejected = false;
try {
    $metaService->exchange($signup['state'], 'one-time-code', '123456789012345', '987654321098765');
} catch (RuntimeException) {
    $metaStateReplayRejected = true;
}
check($metaStateReplayRejected, 'Meta signup service rejects replayed state before another Graph request');
$serviceGraph->getResponses[] = ['access_token' => 'refreshed-service-token', 'expires_in' => 86400];
$loadedMetaConfig = $metaService->loadWhatsAppConfig(['phone_number_id' => 'fallback', 'access_token' => 'fallback']);
check(
    ($loadedMetaConfig['phone_number_id'] ?? '') === '123456789012345'
        && ($loadedMetaConfig['access_token'] ?? '') === 'refreshed-service-token'
        && (new MetaTokenCipher((string) app_config('app.app_key')))->open(
            $serviceRepository->connection['access_token_ciphertext'],
            $serviceRepository->connection['access_token_nonce'],
            $serviceRepository->connection['access_token_tag']
        ) === 'refreshed-service-token',
    'Meta signup service refreshes near-expiry credentials and persists the new encrypted token'
);
check($metaService->subscribeActiveWaba(), 'Meta signup service can subscribe the persisted active WABA');

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
