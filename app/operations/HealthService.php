<?php
declare(strict_types=1);

/** Builds a safe health report without exposing SQL or database connection details. */
final class HealthService
{
    private const REQUIRED_TABLES = [
        'guests',
        'conversations',
        'guest_sessions',
        'messages',
        'rate_limits',
        'webhook_events',
        'message_outbox',
        'message_status_events',
        'guest_consent_events',
        'whatsapp_identity_links',
        'webhook_inbox',
        'meta_signup_states',
        'meta_connections',
    ];

    /** @var HealthRepository */
    private $repository;

    public function __construct(HealthRepository $repository)
    {
        $this->repository = $repository;
    }

    /** @return array<string,mixed> */
    public function report(bool $opensslAvailable, bool $pdoMysqlAvailable, bool $whatsappEnabled): array
    {
        $missingTables = $this->repository->missingTables(self::REQUIRED_TABLES);
        $schemaOk = $missingTables === [];
        $runtimeOk = $opensslAvailable && $pdoMysqlAvailable;
        $queues = [
            'webhook_pending' => null,
            'webhook_failed' => null,
            'outbox_pending' => null,
            'outbox_failed' => null,
        ];
        if ($schemaOk) {
            $queues = $this->repository->queueMetrics();
        }

        return [
            'status' => ($schemaOk && $runtimeOk) ? 'healthy' : 'degraded',
            'http_status' => ($schemaOk && $runtimeOk) ? 200 : 503,
            'checks' => [
                'php_api' => 'ok',
                'database' => 'ok',
                'schema' => $schemaOk ? 'ok' : 'incomplete',
                'openssl' => $opensslAvailable ? 'ok' : 'missing',
                'pdo_mysql' => $pdoMysqlAvailable ? 'ok' : 'missing',
            ],
            'missing_tables' => $missingTables,
            'queues' => $queues,
            'whatsapp_outbound' => $whatsappEnabled ? 'enabled_in_config' : 'disabled',
        ];
    }
}
