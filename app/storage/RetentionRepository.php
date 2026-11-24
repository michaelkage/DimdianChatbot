<?php
declare(strict_types=1);

/** Persistence boundary for privacy retention and lifecycle pruning. */
interface RetentionRepository
{
    /**
     * Delete eligible records in dependency-safe order inside one transaction.
     * @return array<string,int>
     */
    public function prune(
        string $closedBefore,
        string $webhooksBefore,
        string $sessionsBefore,
        bool $includeStaff
    ): array;
}
