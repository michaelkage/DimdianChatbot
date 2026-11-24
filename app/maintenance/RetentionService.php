<?php
declare(strict_types=1);

/** Applies configured retention policy without coupling it to a database or CLI. */
final class RetentionService
{
    /** @var RetentionRepository */
    private $repository;
    /** @var array<string,mixed> */
    private $config;

    /** @param array<string,mixed> $config */
    public function __construct(RetentionRepository $repository, array $config)
    {
        $this->repository = $repository;
        $this->config = $config;
    }

    /** @return array<string,int> */
    public function run(?DateTimeImmutable $now = null): array
    {
        $privacy = $this->config['privacy'] ?? [];
        if (!is_array($privacy)) {
            $privacy = [];
        }
        $closedDays = max(30, min(3650, (int) ($privacy['closed_conversation_retention_days'] ?? 365)));
        $webhookDays = max(7, min(365, (int) ($privacy['webhook_event_retention_days'] ?? 30)));
        $sessionDays = max(7, min(180, (int) ($privacy['expired_session_retention_days'] ?? 30)));

        $utc = new DateTimeZone('UTC');
        $reference = ($now ?? new DateTimeImmutable('now', $utc))->setTimezone($utc);
        $closedBefore = $reference->modify('-' . $closedDays . ' days')->format('Y-m-d H:i:s');
        $webhooksBefore = $reference->modify('-' . $webhookDays . ' days')->format('Y-m-d H:i:s');
        $sessionsBefore = $reference->modify('-' . $sessionDays . ' days')->format('Y-m-d H:i:s');

        return $this->repository->prune(
            $closedBefore,
            $webhooksBefore,
            $sessionsBefore,
            ($this->config['staff_inbox']['enabled'] ?? false) === true
        );
    }
}
