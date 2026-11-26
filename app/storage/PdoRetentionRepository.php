<?php
declare(strict_types=1);

/** MySQL/MariaDB retention adapter; preserves the existing cleanup order and transaction. */
final class PdoRetentionRepository implements RetentionRepository
{
    /** @var PDO */
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function prune(
        string $closedBefore,
        string $webhooksBefore,
        string $sessionsBefore,
        bool $includeStaff
    ): array {
        foreach ([$closedBefore, $webhooksBefore, $sessionsBefore] as $cutoff) {
            if (!preg_match('/^\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2}$/', $cutoff)) {
                throw new InvalidArgumentException('Retention cutoffs must use UTC Y-m-d H:i:s values.');
            }
        }

        $summary = [
            'outbox' => 0, 'statuses' => 0, 'messages' => 0, 'sessions' => 0,
            'links' => 0, 'conversations' => 0, 'consent_events' => 0, 'guests' => 0,
            'webhooks' => 0, 'inbox' => 0, 'signup_states' => 0, 'rate_limits' => 0,
            'staff_notes' => 0, 'staff_audit_events' => 0, 'staff_assignments' => 0,
            'staff_message_authors' => 0, 'staff_auth_nonces' => 0,
        ];

        $this->pdo->beginTransaction();
        try {
            if ($includeStaff) {
                // Optional staff tables are touched only when the operator enabled the module.
                // Delete dependants before their parent conversations/messages are pruned.
                $summary['staff_audit_events'] = $this->delete(
                    'DELETE e FROM staff_audit_events e
                     INNER JOIN conversations c ON c.tenant_id = e.tenant_id AND c.id = e.conversation_id
                     WHERE c.status = "closed" AND c.updated_at < :cutoff',
                    [':cutoff' => $closedBefore]
                );
                $summary['staff_notes'] = $this->delete(
                    'DELETE n FROM staff_internal_notes n
                     INNER JOIN conversations c ON c.tenant_id = n.tenant_id AND c.id = n.conversation_id
                     WHERE c.status = "closed" AND c.updated_at < :cutoff',
                    [':cutoff' => $closedBefore]
                );
                $summary['staff_assignments'] = $this->delete(
                    'DELETE a FROM staff_conversation_assignments a
                     INNER JOIN conversations c ON c.tenant_id = a.tenant_id AND c.id = a.conversation_id
                     WHERE c.status = "closed" AND c.updated_at < :cutoff',
                    [':cutoff' => $closedBefore]
                );
                $summary['staff_message_authors'] = $this->delete(
                    'DELETE sa FROM staff_message_authors sa
                     INNER JOIN messages m ON m.tenant_id = sa.tenant_id AND m.id = sa.message_id
                     INNER JOIN conversations c ON c.tenant_id = m.tenant_id AND c.id = m.conversation_id
                     WHERE c.status = "closed" AND c.updated_at < :cutoff',
                    [':cutoff' => $closedBefore]
                );
                $summary['staff_auth_nonces'] = $this->delete(
                    'DELETE FROM staff_auth_nonces WHERE expires_at < UTC_TIMESTAMP()'
                );
            }

            $summary['outbox'] = $this->delete(
                'DELETE o FROM message_outbox o
                 INNER JOIN messages m ON m.tenant_id = o.tenant_id AND m.id = o.message_id
                 INNER JOIN conversations c ON c.tenant_id = m.tenant_id AND c.id = m.conversation_id
                 WHERE c.status = "closed" AND c.updated_at < :cutoff',
                [':cutoff' => $closedBefore]
            );
            $summary['statuses'] = $this->delete(
                'DELETE e FROM message_status_events e
                 INNER JOIN messages m ON m.tenant_id = e.tenant_id AND m.provider_message_id = e.provider_message_id
                 INNER JOIN conversations c ON c.tenant_id = m.tenant_id AND c.id = m.conversation_id
                 WHERE c.status = "closed" AND c.updated_at < :cutoff',
                [':cutoff' => $closedBefore]
            );
            $summary['messages'] = $this->delete(
                'DELETE m FROM messages m
                 INNER JOIN conversations c ON c.tenant_id = m.tenant_id AND c.id = m.conversation_id
                 WHERE c.status = "closed" AND c.updated_at < :cutoff',
                [':cutoff' => $closedBefore]
            );
            $summary['sessions'] = $this->delete(
                'DELETE FROM guest_sessions WHERE expires_at < :cutoff',
                [':cutoff' => $sessionsBefore]
            );
            $summary['links'] = $this->delete(
                'DELETE l FROM whatsapp_identity_links l
                 INNER JOIN conversations c ON c.tenant_id = l.tenant_id AND c.id = l.conversation_id
                 WHERE c.status = "closed" AND c.updated_at < :cutoff',
                [':cutoff' => $closedBefore]
            );
            $summary['conversations'] = $this->delete(
                'DELETE c FROM conversations c
                 WHERE c.status = "closed" AND c.updated_at < :cutoff
                   AND NOT EXISTS (SELECT 1 FROM messages m WHERE m.tenant_id = c.tenant_id AND m.conversation_id = c.id)
                   AND NOT EXISTS (SELECT 1 FROM guest_sessions s WHERE s.tenant_id = c.tenant_id AND s.conversation_id = c.id)',
                [':cutoff' => $closedBefore]
            );
            $summary['consent_events'] = $this->delete(
                'DELETE e FROM guest_consent_events e
                 INNER JOIN guests g ON g.tenant_id = e.tenant_id AND g.id = e.guest_id
                 WHERE g.created_at < :cutoff
                   AND NOT EXISTS (SELECT 1 FROM conversations c WHERE c.tenant_id = g.tenant_id AND c.guest_id = g.id)
                   AND NOT EXISTS (SELECT 1 FROM guest_sessions s WHERE s.tenant_id = g.tenant_id AND s.guest_id = g.id)',
                [':cutoff' => $closedBefore]
            );
            $summary['guests'] = $this->delete(
                'DELETE g FROM guests g
                 WHERE g.created_at < :cutoff
                   AND NOT EXISTS (SELECT 1 FROM conversations c WHERE c.tenant_id = g.tenant_id AND c.guest_id = g.id)
                   AND NOT EXISTS (SELECT 1 FROM guest_sessions s WHERE s.tenant_id = g.tenant_id AND s.guest_id = g.id)',
                [':cutoff' => $closedBefore]
            );
            $summary['webhooks'] = $this->delete(
                'DELETE FROM webhook_events
                 WHERE received_at < :cutoff AND processing_status IN ("processed", "ignored")',
                [':cutoff' => $webhooksBefore]
            );
            $summary['inbox'] = $this->delete(
                'DELETE FROM webhook_inbox
                 WHERE received_at < :cutoff AND status IN ("processed", "failed")',
                [':cutoff' => $webhooksBefore]
            );
            $summary['signup_states'] = $this->delete(
                'DELETE FROM meta_signup_states
                 WHERE expires_at < UTC_TIMESTAMP()
                    OR used_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)'
            );
            $summary['rate_limits'] = $this->delete(
                'DELETE FROM rate_limits
                 WHERE window_started_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 DAY)'
            );

            $this->pdo->commit();
            return $summary;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @param array<string,mixed> $parameters */
    private function delete(string $sql, array $parameters = []): int
    {
        $statement = PdoSqlDialect::prepare($this->pdo, $sql);
        $statement->execute($parameters);
        return $statement->rowCount();
    }
}
