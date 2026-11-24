<?php
declare(strict_types=1);

/** MySQL/MariaDB fixed-window rate-limit bucket persistence. */
final class PdoRateLimitRepository implements RateLimitRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function consumeRateLimit(string $tenantId, string $bucket, int $maxRequests, int $windowSeconds): bool
    {
        $bucketKey = hash('sha256', $bucket);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $cutoff = $now->modify('-' . max(1, $windowSeconds) . ' seconds')->format('Y-m-d H:i:s');
        $nowString = $now->format('Y-m-d H:i:s');

        return $this->transaction(function () use ($tenantId, $bucketKey, $cutoff, $nowString, $maxRequests): bool {
            $sql = PdoSqlDialect::isPostgres($this->pdo)
                ? 'INSERT INTO rate_limits (tenant_id, bucket_key, window_started_at, request_count)
                   VALUES (:tenant_id, :bucket, :now, 1)
                   ON CONFLICT (tenant_id, bucket_key) DO UPDATE SET
                     request_count = CASE WHEN rate_limits.window_started_at <= :cutoff
                                          THEN 1 ELSE rate_limits.request_count + 1 END,
                     window_started_at = CASE WHEN rate_limits.window_started_at <= :cutoff2
                                              THEN :now2 ELSE rate_limits.window_started_at END'
                : 'INSERT INTO rate_limits (tenant_id, bucket_key, window_started_at, request_count)
                   VALUES (:tenant_id, :bucket, :now, 1)
                   ON DUPLICATE KEY UPDATE
                     request_count = IF(window_started_at <= :cutoff, 1, request_count + 1),
                     window_started_at = IF(window_started_at <= :cutoff2, :now2, window_started_at)';
            $insert = PdoSqlDialect::prepare($this->pdo, $sql);
            $insert->execute([
                ':tenant_id' => $tenantId,
                ':bucket' => $bucketKey,
                ':now' => $nowString,
                ':cutoff' => $cutoff,
                ':cutoff2' => $cutoff,
                ':now2' => $nowString,
            ]);
            $select = PdoSqlDialect::prepare($this->pdo, 'SELECT request_count FROM rate_limits WHERE tenant_id = :tenant_id AND bucket_key = :bucket FOR UPDATE');
            $select->execute([':tenant_id' => $tenantId, ':bucket' => $bucketKey]);
            $row = $select->fetch();
            return is_array($row) && (int) $row['request_count'] <= max(1, $maxRequests);
        });
    }

    private function transaction(callable $operation): mixed
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $result = $operation();
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
            return $result;
        } catch (Throwable $error) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }
}
