<?php
declare(strict_types=1);

/** MySQL/MariaDB implementation of MetaConnectionRepository. */
final class PdoMetaConnectionRepository implements MetaConnectionRepository
{
    /** @var PDO */
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function createSignupState(string $stateHash, int $ttlSeconds = 600): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $stateHash) || $ttlSeconds < 1 || $ttlSeconds > 3600) {
            throw new InvalidArgumentException('Meta signup state parameters are invalid.');
        }
        $statement = PdoSqlDialect::prepare($this->pdo, 
            'INSERT INTO meta_signup_states (state_sha256, expires_at, created_at)
             VALUES (:hash, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ' . $ttlSeconds . ' SECOND), UTC_TIMESTAMP())'
        );
        $statement->execute([':hash' => $stateHash]);
    }

    public function consumeSignupState(string $stateHash): bool
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $stateHash)) {
            return false;
        }
        return $this->transaction(function () use ($stateHash): bool {
            $statement = PdoSqlDialect::prepare($this->pdo, 
                'SELECT id FROM meta_signup_states
                 WHERE state_sha256 = :hash AND used_at IS NULL AND expires_at > UTC_TIMESTAMP()
                 FOR UPDATE'
            );
            $statement->execute([':hash' => $stateHash]);
            $id = $statement->fetchColumn();
            if ($id === false) {
                return false;
            }
            $consume = PdoSqlDialect::prepare($this->pdo, 
                'UPDATE meta_signup_states SET used_at = UTC_TIMESTAMP()
                 WHERE id = :id AND used_at IS NULL'
            );
            $consume->execute([':id' => $id]);
            return $consume->rowCount() === 1;
        });
    }

    public function saveActiveConnection(array $connection): void
    {
        $tenantId = (string) ($connection['tenant_id'] ?? '00000000-0000-4000-8000-000000000001');
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $tenantId)) {
            throw new InvalidArgumentException('Meta connection requires a valid tenant UUID.');
        }

        $binary = PdoSqlDialect::isPostgres($this->pdo);
        $ciphertext = $binary ? base64_encode((string) $connection['token_ciphertext']) : $connection['token_ciphertext'];
        $nonce = $binary ? base64_encode((string) $connection['token_nonce']) : $connection['token_nonce'];
        $tag = $binary ? base64_encode((string) $connection['token_tag']) : $connection['token_tag'];

        $this->transaction(function () use ($tenantId, $connection, $ciphertext, $nonce, $tag): void {
            $existing = PdoSqlDialect::prepare($this->pdo,
                'SELECT tenant_id FROM meta_connections WHERE phone_number_id = :phone LIMIT 1 FOR UPDATE'
            );
            $existing->execute([':phone' => $connection['phone_number_id']]);
            $existingTenantId = $existing->fetchColumn();

            if (is_string($existingTenantId)) {
                if (!hash_equals($existingTenantId, $tenantId)) {
                    throw new RuntimeException('This Meta phone-number identity is already owned by another tenant.');
                }
                $update = PdoSqlDialect::prepare($this->pdo,
                    'UPDATE meta_connections
                     SET waba_id = :waba, access_token_ciphertext = :cipher,
                         access_token_nonce = :nonce, access_token_tag = :tag,
                         token_expires_at = :expires, status = "active", updated_at = UTC_TIMESTAMP()
                     WHERE phone_number_id = :phone AND tenant_id = :tenant_id'
                );
                $update->execute([
                    ':waba' => $connection['waba_id'],
                    ':cipher' => $ciphertext,
                    ':nonce' => $nonce,
                    ':tag' => $tag,
                    ':expires' => $connection['token_expires_at'],
                    ':phone' => $connection['phone_number_id'],
                    ':tenant_id' => $tenantId,
                ]);
                return;
            }

            $insert = PdoSqlDialect::prepare($this->pdo,
                'INSERT INTO meta_connections
                    (tenant_id, waba_id, phone_number_id, access_token_ciphertext, access_token_nonce,
                     access_token_tag, token_expires_at, status, connected_at, updated_at)
                 VALUES (:tenant_id, :waba, :phone, :cipher, :nonce, :tag, :expires, "active", UTC_TIMESTAMP(), UTC_TIMESTAMP())'
            );
            $insert->execute([
                ':tenant_id' => $tenantId,
                ':waba' => $connection['waba_id'],
                ':phone' => $connection['phone_number_id'],
                ':cipher' => $ciphertext,
                ':nonce' => $nonce,
                ':tag' => $tag,
                ':expires' => $connection['token_expires_at'],
            ]);
        });
    }

    public function findActiveConnection(): ?array
    {
        $statement = PdoSqlDialect::query($this->pdo, 
            'SELECT id, waba_id, phone_number_id, access_token_ciphertext,
                    access_token_nonce, access_token_tag, token_expires_at
             FROM meta_connections
             WHERE status = "active"
             ORDER BY updated_at DESC
             LIMIT 1'
        );
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }
        if (PdoSqlDialect::isPostgres($this->pdo)) {
            foreach (['access_token_ciphertext', 'access_token_nonce', 'access_token_tag'] as $field) {
                $decoded = base64_decode((string) $row[$field], true);
                if (!is_string($decoded)) {
                    throw new RuntimeException('Stored Meta token material is corrupt.');
                }
                $row[$field] = $decoded;
            }
        }
        return $row;
    }

    public function updateAccessToken(int $connectionId, array $encrypted, ?string $expiresAt): void
    {
        $binary = PdoSqlDialect::isPostgres($this->pdo);
        $statement = PdoSqlDialect::prepare($this->pdo,
            'UPDATE meta_connections
             SET access_token_ciphertext = :cipher, access_token_nonce = :nonce,
                 access_token_tag = :tag, token_expires_at = :expires, updated_at = UTC_TIMESTAMP()
             WHERE id = :id AND status = "active"'
        );
        $statement->execute([
            ':cipher' => $binary ? base64_encode((string) $encrypted['ciphertext']) : $encrypted['ciphertext'],
            ':nonce' => $binary ? base64_encode((string) $encrypted['nonce']) : $encrypted['nonce'],
            ':tag' => $binary ? base64_encode((string) $encrypted['tag']) : $encrypted['tag'],
            ':expires' => $expiresAt,
            ':id' => $connectionId,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('The active Meta connection changed before its token could be refreshed.');
        }
    }

    public function markConnectionExpired(int $connectionId): void
    {
        $statement = PdoSqlDialect::prepare($this->pdo, 
            'UPDATE meta_connections SET status = "expired", updated_at = UTC_TIMESTAMP()
             WHERE id = :id AND status = "active"'
        );
        $statement->execute([':id' => $connectionId]);
    }

    public function activePhoneNumberId(): ?string
    {
        $statement = PdoSqlDialect::query($this->pdo, 
            'SELECT phone_number_id FROM meta_connections
             WHERE status = "active" ORDER BY updated_at DESC LIMIT 1'
        );
        $value = $statement->fetchColumn();
        return is_string($value) && $value !== '' ? $value : null;
    }

    public function tenantIdForPhoneNumberId(string $phoneNumberId): ?string
    {
        if ($phoneNumberId === '' || strlen($phoneNumberId) > 80) {
            return null;
        }
        $statement = PdoSqlDialect::prepare($this->pdo,
            'SELECT tenant_id FROM meta_connections
             WHERE phone_number_id = :phone AND status = "active"
             LIMIT 1'
        );
        $statement->execute([':phone' => $phoneNumberId]);
        $tenantId = $statement->fetchColumn();
        return is_string($tenantId) && $tenantId !== '' ? $tenantId : null;
    }

    /** @template T @param callable():T $operation @return T */
    private function transaction(callable $operation)
    {
        $this->pdo->beginTransaction();
        try {
            $result = $operation();
            $this->pdo->commit();
            return $result;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }
}
