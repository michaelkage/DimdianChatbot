<?php
declare(strict_types=1);

/** Read-only persistence queries required by the operational health report. */
interface HealthRepository
{
    /** @param list<string> $requiredTables @return list<string> */
    public function missingTables(array $requiredTables): array;

    /** @return array{webhook_pending:int,webhook_failed:int,outbox_pending:int,outbox_failed:int} */
    public function queueMetrics(): array;
}
