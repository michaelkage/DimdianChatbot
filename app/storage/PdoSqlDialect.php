<?php
declare(strict_types=1);

/**
 * Small SQL compatibility boundary for the PDO reference repositories.
 *
 * Repository contracts and transaction boundaries remain identical across
 * MySQL/MariaDB and PostgreSQL. Dialect-specific statements that cannot be
 * translated mechanically are selected explicitly by the owning repository.
 */
final class PdoSqlDialect
{
    public static function driver(PDO $pdo): string
    {
        return strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    }

    public static function isPostgres(PDO $pdo): bool
    {
        return self::driver($pdo) === 'pgsql';
    }

    public static function prepare(PDO $pdo, string $sql): PDOStatement
    {
        $statement = $pdo->prepare(self::translate($pdo, $sql));
        if (!$statement instanceof PDOStatement) {
            throw new RuntimeException('The database could not prepare a repository statement.');
        }
        return $statement;
    }

    public static function query(PDO $pdo, string $sql): PDOStatement
    {
        $statement = $pdo->query(self::translate($pdo, $sql));
        if (!$statement instanceof PDOStatement) {
            throw new RuntimeException('The database could not execute a repository query.');
        }
        return $statement;
    }

    private static function translate(PDO $pdo, string $sql): string
    {
        if (!self::isPostgres($pdo)) {
            return $sql;
        }

        // These expressions are intentionally limited to syntax used by the
        // repository SQL. Complex upserts and joined writes use explicit branches.
        $sql = str_replace('UTC_TIMESTAMP()', 'CURRENT_TIMESTAMP', $sql);
        // Existing repository SQL uses double quotes only for string literals,
        // never for SQL identifiers.
        $sql = preg_replace_callback(
            '/"([^"]*)"/',
            static fn(array $match): string => "'" . str_replace("'", "''", $match[1]) . "'",
            $sql
        ) ?? $sql;

        $sql = preg_replace_callback(
            '/DATE_SUB\(\s*CURRENT_TIMESTAMP\s*,\s*INTERVAL\s+(\d+)\s+(SECOND|MINUTE|HOUR|DAY)\s*\)/i',
            static fn(array $match): string => "(CURRENT_TIMESTAMP - INTERVAL '" . $match[1] . ' ' . strtolower($match[2]) . "')",
            $sql
        ) ?? $sql;
        $sql = preg_replace_callback(
            '/DATE_SUB\(\s*CURRENT_TIMESTAMP\s*,\s*INTERVAL\s+:([a-zA-Z_][a-zA-Z0-9_]*)\s+(SECOND|MINUTE|HOUR|DAY)\s*\)/i',
            static fn(array $match): string => "(CURRENT_TIMESTAMP - (:" . $match[1] . " * INTERVAL '1 " . strtolower($match[2]) . "'))",
            $sql
        ) ?? $sql;
        $sql = preg_replace_callback(
            '/DATE_ADD\(\s*CURRENT_TIMESTAMP\s*,\s*INTERVAL\s+(\d+)\s+(SECOND|MINUTE|HOUR|DAY)\s*\)/i',
            static fn(array $match): string => "(CURRENT_TIMESTAMP + INTERVAL '" . $match[1] . ' ' . strtolower($match[2]) . "')",
            $sql
        ) ?? $sql;
        $sql = preg_replace_callback(
            '/DATE_ADD\(\s*CURRENT_TIMESTAMP\s*,\s*INTERVAL\s+:([a-zA-Z_][a-zA-Z0-9_]*)\s+(SECOND|MINUTE|HOUR|DAY)\s*\)/i',
            static fn(array $match): string => "(CURRENT_TIMESTAMP + (:" . $match[1] . " * INTERVAL '1 " . strtolower($match[2]) . "'))",
            $sql
        ) ?? $sql;

        if (preg_match('/^\s*INSERT\s+IGNORE\s+INTO\b/i', $sql) === 1) {
            $sql = preg_replace('/^\s*INSERT\s+IGNORE\s+INTO\b/i', 'INSERT INTO', $sql, 1) ?? $sql;
            $sql = rtrim($sql) . ' ON CONFLICT DO NOTHING';
        }

        return $sql;
    }
}
