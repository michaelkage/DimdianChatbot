<?php
declare(strict_types=1);

/** MySQL/MariaDB read adapter for health and queue diagnostics. */
final class PdoHealthRepository implements HealthRepository
{
    /** @var PDO */
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function missingTables(array $requiredTables): array
    {
        if ($requiredTables === []) {
            return [];
        }
        foreach ($requiredTables as $table) {
            if (!is_string($table) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/', $table)) {
                throw new InvalidArgumentException('Health schema table names must be safe identifiers.');
            }
        }
        $placeholders = implode(',', array_fill(0, count($requiredTables), '?'));
        $statement = $this->pdo->prepare(
            'SELECT table_name
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_type = "BASE TABLE"
               AND table_name IN (' . $placeholders . ')'
        );
        $statement->execute($requiredTables);
        $found = $statement->fetchAll(PDO::FETCH_COLUMN);
        return array_values(array_diff($requiredTables, $found));
    }

    public function queueMetrics(): array
    {
        return [
            'webhook_pending' => (int) $this->pdo->query(
                'SELECT COUNT(*) FROM webhook_inbox WHERE status IN ("pending", "processing")'
            )->fetchColumn(),
            'webhook_failed' => (int) $this->pdo->query(
                'SELECT COUNT(*) FROM webhook_inbox WHERE status = "failed"'
            )->fetchColumn(),
            'outbox_pending' => (int) $this->pdo->query(
                'SELECT COUNT(*) FROM message_outbox WHERE status IN ("pending", "processing")'
            )->fetchColumn(),
            'outbox_failed' => (int) $this->pdo->query(
                'SELECT COUNT(*) FROM message_outbox WHERE status = "failed"'
            )->fetchColumn(),
        ];
    }
}
