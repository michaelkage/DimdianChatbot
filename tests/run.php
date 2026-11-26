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
require_once dirname(__DIR__) . '/app/staff/StaffInboxRepository.php';
require_once dirname(__DIR__) . '/app/staff/StaffIdentityProvider.php';
require_once dirname(__DIR__) . '/app/staff/StaffIdentityService.php';
require_once dirname(__DIR__) . '/app/staff/StaffInboxService.php';
require_once dirname(__DIR__) . '/app/staff/PdoStaffPersistenceSupport.php';
require_once dirname(__DIR__) . '/app/staff/PdoStaffIdentityRepository.php';
require_once dirname(__DIR__) . '/app/staff/PdoStaffConversationRepository.php';
require_once dirname(__DIR__) . '/app/staff/PdoStaffMessageRepository.php';
require_once dirname(__DIR__) . '/app/staff/PdoStaffInboxRepository.php';

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

check(
    is_subclass_of(PdoStaffIdentityRepository::class, StaffIdentityRepository::class),
    'PdoStaffIdentityRepository directly implements the staff identity persistence capability'
);

check(
    is_subclass_of(PdoStaffConversationRepository::class, StaffConversationRepository::class),
    'PdoStaffConversationRepository directly implements the staff conversation capability'
);
check(
    is_subclass_of(PdoStaffMessageRepository::class, StaffMessageRepository::class),
    'PdoStaffMessageRepository directly implements the staff message capability'
);
$staffAggregateProperties = array_map(
    static fn(ReflectionProperty $property): string => $property->getName(),
    (new ReflectionClass(PdoStaffInboxRepository::class))->getProperties()
);
check(
    is_subclass_of(PdoStaffInboxRepository::class, StaffInboxRepository::class)
        && !in_array('pdo', $staffAggregateProperties, true),
    'PdoStaffInboxRepository is a SQL-free aggregate composition root'
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
    StaffIdentityService::class => StaffInboxRepository::class,
    GuestSessionAuthenticator::class => GuestSessionRepository::class,
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

final class TenantResolverTestRepository implements TenantRepository
{
    public ?array $widget = null;
    public int $lookups = 0;

    public function findActiveTenantWidget(string $publicWidgetId): ?array
    {
        $this->lookups++;
        return $this->widget;
    }
}

$tenantRepository = new TenantResolverTestRepository();
$tenantRepository->widget = [
    'tenant_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
    'tenant_status' => 'active',
    'allowed_origins' => '["https://shop.example.test"]',
];
$sharedTenantRejected = false;
try {
    (new TenantResolver($tenantRepository, ['mode' => 'shared_database']))
        ->resolve('widget_0123456789abcdef', 'https://shop.example.test');
} catch (TenantResolutionException $error) {
    $sharedTenantRejected = str_contains($error->getMessage(), 'not enabled');
}
check(
    $sharedTenantRejected && $tenantRepository->lookups === 0,
    'tenant resolver fails closed before widget lookup while persistence is not tenant-scoped'
);

$singleTenantContext = (new TenantResolver($tenantRepository, ['mode' => 'single_tenant']))
    ->resolve(null, null);
check(
    $singleTenantContext->tenantId === '00000000-0000-4000-8000-000000000001',
    'single-tenant compatibility mode uses the explicit default tenant identity'
);

$applicationServiceFiles = [
    'app/chat/GuestSessionAuthenticator.php',
    'app/chat/GuestSessionService.php',
    'app/chat/ChatMessageService.php',
    'app/chat/WhatsAppConsentService.php',
    'app/staff/StaffIdentityService.php',
    'app/staff/StaffInboxService.php',
    'app/channels/whatsapp/MetaEmbeddedSignupService.php',
    'app/maintenance/RetentionService.php',
    'app/operations/HealthService.php',
];
foreach ($applicationServiceFiles as $relativePath) {
    $source = file_get_contents(dirname(__DIR__) . '/' . $relativePath);
    check(
        is_string($source)
            && !preg_match('/\\bjson_response\\s*\\(|\\bheader\\s*\\(|\\$_(?:SERVER|GET|POST|REQUEST)\\b|\\bnew\\s+PDO\\b|->(?:prepare|query)\\s*\\(/i', $source),
        $relativePath . ' stays outside HTTP response, request-global and SQL/PDO boundaries'
    );
}

$httpRouteFiles = [
    'public/api/messages.php',
    'public/api/session.php',
    'public/api/consent.php',
    'public/api/health.php',
    'public/api/meta-embedded-signup.php',
    'public/api/webhook.php',
    'public/api/v1/staff/inbox.php',
    'public/api/v1/staff/messages.php',
    'public/api/v1/staff/replies.php',
    'public/api/v1/staff/notes.php',
    'public/api/v1/staff/assignments.php',
    'public/api/v1/staff/agents.php',
    'public/api/v1/staff/roles.php',
    'public/api/v1/staff/history.php',
    'public/api/v1/staff/audit.php',
    'public/api/v1/staff/me.php',
];
foreach ($httpRouteFiles as $relativePath) {
    $source = file_get_contents(dirname(__DIR__) . '/' . $relativePath);
    check(
        is_string($source)
            && !preg_match('/\\bnew\\s+PDO\\b|->(?:prepare|query)\\s*\\(/i', $source),
        $relativePath . ' delegates persistence instead of issuing SQL'
    );
}

final class GuestSessionAuthenticatorTestRepository implements GuestSessionRepository
{
    public ?array $session = null;
    public ?string $lookedUpHash = null;
    public ?string $lookedUpTenantId = null;
    public ?string $touchedTenantId = null;
    public array $touched = [];

    public function createGuestSession(array $session): void
    {
    }

    public function findGuestSessionByTokenHash(string $tokenHash, string $tenantId): ?array
    {
        $this->lookedUpHash = $tokenHash;
        $this->lookedUpTenantId = $tenantId;
        return $this->session;
    }

    public function touchGuestSession(string $sessionId, string $tenantId): void
    {
        $this->touched[] = $sessionId;
        $this->touchedTenantId = $tenantId;
    }
}

$authToken = str_repeat('a', 64);
$authTenantContext = new TenantContext('00000000-0000-4000-8000-000000000001');
$authRepository = new GuestSessionAuthenticatorTestRepository();
$authRepository->session = ['session_id' => 'session-1', 'conversation_status' => 'open'];
$authenticator = new GuestSessionAuthenticator($authRepository);
$authenticatedSession = $authenticator->authenticate($authToken, $authTenantContext);
check(
    $authenticatedSession['session_id'] === 'session-1'
        && $authRepository->lookedUpHash === hash('sha256', $authToken)
        && $authRepository->lookedUpTenantId === $authTenantContext->tenantId
        && $authRepository->touched === ['session-1']
        && $authRepository->touchedTenantId === $authTenantContext->tenantId,
    'guest authenticator hashes tokens, resolves the session and updates last-seen state'
);

$invalidTokenRejected = false;
try {
    $authenticator->authenticate('not-a-token', $authTenantContext);
} catch (GuestSessionTokenInvalid) {
    $invalidTokenRejected = true;
}
check($invalidTokenRejected, 'guest authenticator rejects malformed tokens before repository lookup');

$missingRepository = new GuestSessionAuthenticatorTestRepository();
$missingSessionRejected = false;
$missingSessionMessage = '';
try {
    (new GuestSessionAuthenticator($missingRepository))->authenticate($authToken, $authTenantContext);
} catch (GuestSessionNotFound $error) {
    $missingSessionRejected = true;
    $missingSessionMessage = $error->getMessage();
}
check(
    $missingSessionRejected
        && $missingSessionMessage === 'This chat session is no longer valid. Start a new chat to continue.',
    'guest authenticator rejects expired sessions with the established API message'
);

$closedRepository = new GuestSessionAuthenticatorTestRepository();
$closedRepository->session = ['session_id' => 'closed-session', 'conversation_status' => 'closed'];
$closedAuthenticator = new GuestSessionAuthenticator($closedRepository);
$closedSessionRejected = false;
$closedSessionMessage = '';
try {
    $closedAuthenticator->authenticate($authToken, $authTenantContext);
} catch (GuestConversationClosed $error) {
    $closedSessionRejected = true;
    $closedSessionMessage = $error->getMessage();
}
$closedSessionReadable = $closedAuthenticator->authenticate($authToken, $authTenantContext, false);
check(
    $closedSessionRejected
        && $closedSessionMessage === 'This conversation has been closed. Start a new chat to send another message.'
        && $closedSessionReadable['session_id'] === 'closed-session'
        && $closedRepository->touched === ['closed-session', 'closed-session'],
    'guest authenticator enforces open conversations only when requested'
);

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
            'tenant_id' => $connection['tenant_id'] ?? '00000000-0000-4000-8000-000000000001',
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

    public function findActiveConnectionForTenant(string $tenantId): ?array
    {
        $row = $this->findActiveConnection();
        return is_array($row) && ($row['tenant_id'] ?? null) === $tenantId ? $row : null;
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

    public function tenantIdForPhoneNumberId(string $phoneNumberId): ?string
    {
        if (($this->connection['phone_number_id'] ?? null) !== $phoneNumberId) return null;
        return (string) ($this->connection['tenant_id'] ?? '00000000-0000-4000-8000-000000000001');
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

final class MetaWebhookTenantResolverTestRepository implements WhatsAppIdentityRepository
{
    public array $phoneTenants = [];

    public function activeWhatsAppPhoneNumberId(): ?string
    {
        return null;
    }

    public function tenantIdForWhatsAppPhoneNumberId(string $phoneNumberId): ?string
    {
        return $this->phoneTenants[$phoneNumberId] ?? null;
    }
}

$webhookTenantA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
$webhookTenantB = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
$webhookTenantRepository = new MetaWebhookTenantResolverTestRepository();
$webhookTenantRepository->phoneTenants = [
    'phone-tenant-a-1' => $webhookTenantA,
    'phone-tenant-a-2' => $webhookTenantA,
    'phone-tenant-b-1' => $webhookTenantB,
];
$webhookTenantResolver = new MetaWebhookTenantResolver($webhookTenantRepository);
$multiPhonePayload = [
    'object' => 'whatsapp_business_account',
    'entry' => [[
        'changes' => [
            ['field' => 'messages', 'value' => ['metadata' => ['phone_number_id' => 'phone-tenant-a-1'], 'messages' => []]],
            ['field' => 'smb_message_echoes', 'value' => ['metadata' => ['phone_number_id' => 'phone-tenant-a-2'], 'message_echoes' => []]],
        ],
    ]],
];
$resolvedWebhookTenant = $webhookTenantResolver->resolve($multiPhonePayload);
check(
    is_array($resolvedWebhookTenant)
        && $resolvedWebhookTenant['tenant_id'] === $webhookTenantA
        && $resolvedWebhookTenant['phone_number_ids'] === ['phone-tenant-a-1', 'phone-tenant-a-2'],
    'verified webhook phone identities resolve to one owning tenant'
);
$unknownWebhookRejected = false;
try {
    $webhookTenantResolver->resolve([
        'object' => 'whatsapp_business_account',
        'entry' => [['changes' => [[
            'field' => 'messages',
            'value' => ['metadata' => ['phone_number_id' => 'not-connected'], 'messages' => []],
        ]]]],
    ]);
} catch (MetaWebhookTenantResolutionException) {
    $unknownWebhookRejected = true;
}
check($unknownWebhookRejected, 'unknown or revoked Meta phone identities fail closed');
$mixedWebhookTenantsRejected = false;
try {
    $webhookTenantResolver->resolve([
        'object' => 'whatsapp_business_account',
        'entry' => [['changes' => [
            ['field' => 'messages', 'value' => ['metadata' => ['phone_number_id' => 'phone-tenant-a-1'], 'messages' => []]],
            ['field' => 'messages', 'value' => ['metadata' => ['phone_number_id' => 'phone-tenant-b-1'], 'messages' => []]],
        ]]],
    ]);
} catch (MetaWebhookTenantResolutionException) {
    $mixedWebhookTenantsRejected = true;
}
check($mixedWebhookTenantsRejected, 'a single Meta webhook cannot mix multiple tenant destinations');
$missingWebhookPhoneRejected = false;
$missingMetadataPayload = [
    'object' => 'whatsapp_business_account',
    'entry' => [
        [
            'changes' => [
                [
                    'field' => 'messages',
                    'value' => ['messages' => []],
                ],
            ],
        ],
    ],
];
try {
    $webhookTenantResolver->resolve($missingMetadataPayload);
} catch (MetaWebhookTenantResolutionException) {
    $missingWebhookPhoneRejected = true;
}
check($missingWebhookPhoneRejected, 'routable Meta changes require a provider phone-number identity');
check(
    $webhookTenantResolver->resolve(['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['field' => 'account_update', 'value' => []]]]]]) === null,
    'unsupported account-level webhook changes are acknowledged without arbitrary tenant assignment'
);

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
$healthyHealth = (new HealthService($healthyHealthRepository))->report(true, true, true, 'mysql');
check(
    $healthyHealth['status'] === 'healthy'
        && $healthyHealth['http_status'] === 200
        && $healthyHealth['queues'] === $healthMetrics
        && $healthyHealth['whatsapp_outbound'] === 'enabled_in_config',
    'Health service composes healthy runtime, schema and queue diagnostics'
);

$postgresHealth = (new HealthService(new HealthServiceTestRepository([], $healthMetrics)))->report(true, true, false, 'pgsql');
check(
    $postgresHealth['status'] === 'healthy'
        && $postgresHealth['checks']['pdo_driver'] === 'pgsql',
    'Health service recognizes PostgreSQL as a supported PDO backend'
);
$incompleteHealthRepository = new HealthServiceTestRepository(['meta_connections'], $healthMetrics);
$incompleteHealth = (new HealthService($incompleteHealthRepository))->report(true, true, false, 'mysql');
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
$loadedMetaConfig = $metaService->loadWhatsAppConfig(['phone_number_id' => 'fallback', 'access_token' => 'fallback'], '00000000-0000-4000-8000-000000000001');
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
check($metaService->subscribeActiveWaba('00000000-0000-4000-8000-000000000001'), 'Meta signup service can subscribe the persisted active WABA');
$otherTenantMetaLookupRejected = false;
try {
    $metaService->loadWhatsAppConfig(
        ['phone_number_id' => 'global-fallback', 'access_token' => 'global-token'],
        'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'
    );
} catch (RuntimeException) {
    $otherTenantMetaLookupRejected = true;
}
check($otherTenantMetaLookupRejected, 'Meta token loading never falls back to another tenant connection');
$crossTenantTransportRejected = false;
try {
    (new MetaCloudApiTransport([
        'graph_api_version' => 'v22.0',
        'phone_number_id' => 'global-phone',
        'access_token' => 'global-token',
    ]))->sendTemplate('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', '2348031234567', 'Hello');
} catch (WhatsAppTransportException $error) {
    $crossTenantTransportRejected = !$error->retryable;
}
check($crossTenantTransportRejected, 'legacy Meta transport cannot send with default-tenant credentials for another tenant');


$logContext = app_sanitize_log_context(['attempt' => 3, 'exception_class' => 'PDOException', 'phone_number' => '+2348012345678', 'message' => 'private chat content']);
check($logContext === ['attempt' => 3, 'exception_class' => 'PDOException'], 'structured operational logs allow only non-PII diagnostic fields');

$transportRejected = false;
try {
    (new MetaCloudApiTransport([]))->sendTemplate('00000000-0000-4000-8000-000000000001', '2348031234567', 'Hello');
} catch (WhatsAppTransportException $error) {
    $transportRejected = !$error->retryable;
}
check($transportRejected, 'incomplete Meta transport configuration fails without retry');

fwrite(STDOUT, "\n{$passed} passed; {$failed} failed.\n");
exit($failed === 0 ? 0 : 1);
