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
        $statement = $this->pdo->prepare(
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
            $statement = $this->pdo->prepare(
                'SELECT id FROM meta_signup_states
                 WHERE state_sha256 = :hash AND used_at IS NULL AND expires_at > UTC_TIMESTAMP()
                 FOR UPDATE'
            );
            $statement->execute([':hash' => $stateHash]);
            $id = $statement->fetchColumn();
            if ($id === false) {
                return false;
            }
            $consume = $this->pdo->prepare(
                'UPDATE meta_signup_states SET used_at = UTC_TIMESTAMP()
                 WHERE id = :id AND used_at IS NULL'
            );
            $consume->execute([':id' => $id]);
            return $consume->rowCount() === 1;
        });
    }

    public function saveActiveConnection(array $connection): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO meta_connections
                (waba_id, phone_number_id, access_token_ciphertext, access_token_nonce,
                 access_token_tag, token_expires_at, status, connected_at, updated_at)
             VALUES
                (:waba, :phone, :cipher, :nonce, :tag, :expires, "active", UTC_TIMESTAMP(), UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                waba_id = VALUES(waba_id),
                access_token_ciphertext = VALUES(access_token_ciphertext),
                access_token_nonce = VALUES(access_token_nonce),
                access_token_tag = VALUES(access_token_tag),
                token_expires_at = VALUES(token_expires_at),
                status = "active",
                updated_at = UTC_TIMESTAMP()'
        );
        $statement->execute([
            ':waba' => $connection['waba_id'],
            ':phone' => $connection['phone_number_id'],
            ':cipher' => $connection['token_ciphertext'],
            ':nonce' => $connection['token_nonce'],
            ':tag' => $connection['token_tag'],
            ':expires' => $connection['token_expires_at'],
        ]);
    }

    public function findActiveConnection(): ?array
    {
        $statement = $this->pdo->query(
            'SELECT id, waba_id, phone_number_id, access_token_ciphertext,
                    access_token_nonce, access_token_tag, token_expires_at
             FROM meta_connections
             WHERE status = "active"
             ORDER BY updated_at DESC
             LIMIT 1'
        );
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    public function updateAccessToken(int $connectionId, array $encrypted, ?string $expiresAt): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE meta_connections
             SET access_token_ciphertext = :cipher, access_token_nonce = :nonce,
                 access_token_tag = :tag, token_expires_at = :expires, updated_at = UTC_TIMESTAMP()
             WHERE id = :id AND status = "active"'
        );
        $statement->execute([
            ':cipher' => $encrypted['ciphertext'],
            ':nonce' => $encrypted['nonce'],
            ':tag' => $encrypted['tag'],
            ':expires' => $expiresAt,
            ':id' => $connectionId,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('The active Meta connection changed before its token could be refreshed.');
        }
    }

    public function markConnectionExpired(int $connectionId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE meta_connections SET status = "expired", updated_at = UTC_TIMESTAMP()
             WHERE id = :id AND status = "active"'
        );
        $statement->execute([':id' => $connectionId]);
    }

    public function activePhoneNumberId(): ?string
    {
        $statement = $this->pdo->query(
            'SELECT phone_number_id FROM meta_connections
             WHERE status = "active" ORDER BY updated_at DESC LIMIT 1'
        );
        $value = $statement->fetchColumn();
        return is_string($value) && $value !== '' ? $value : null;
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
