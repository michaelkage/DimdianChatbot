<?php
declare(strict_types=1);

/**
 * MySQL/MariaDB consent ledger and WhatsApp identity-route persistence.
 *
 * Consent transitions, identity routing and cancellation of queued outbound
 * work are one transaction. The application service owns policy; this adapter
 * owns the atomic storage transition.
 */
final class PdoConsentRepository implements ConsentRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function recordWhatsAppConsent(string $tenantId, string $guestId, string $conversationId, string $normalizedPhone, string $action, string $source = 'website_widget'): void
    {
        if (!in_array($action, ['opt_in', 'opt_out'], true)) {
            throw new InvalidArgumentException('Unsupported consent action.');
        }
        if ($action === 'opt_in' && !preg_match('/^[1-9][0-9]{7,14}$/', $normalizedPhone)) {
            throw new InvalidArgumentException('A valid international WhatsApp number is required.');
        }

        $this->transaction(function () use ($tenantId, $guestId, $conversationId, $normalizedPhone, $action, $source): void {
            if ($action === 'opt_in') {
                $deactivate = PdoSqlDialect::prepare($this->pdo,
                    'UPDATE whatsapp_identity_links
                     SET is_active = 0, deactivated_at = UTC_TIMESTAMP()
                     WHERE tenant_id = :tenant_id AND phone_normalized = :phone AND is_active = 1'
                );
                $deactivate->execute([':tenant_id' => $tenantId, ':phone' => $normalizedPhone]);
                $link = PdoSqlDialect::prepare($this->pdo,
                    'INSERT INTO whatsapp_identity_links
                        (tenant_id, phone_normalized, guest_id, conversation_id, is_active, created_at)
                     VALUES (:tenant_id, :phone, :guest, :conversation, 1, UTC_TIMESTAMP())'
                );
                $link->execute([
                    ':tenant_id' => $tenantId,
                    ':phone' => $normalizedPhone,
                    ':guest' => $guestId,
                    ':conversation' => $conversationId,
                ]);
                $update = PdoSqlDialect::prepare($this->pdo,
                    'UPDATE guests
                     SET phone_normalized = :phone, whatsapp_opt_in_at = :now, updated_at = UTC_TIMESTAMP()
                     WHERE tenant_id = :tenant_id AND id = :guest'
                );
                $update->execute([
                    ':phone' => $normalizedPhone,
                    ':now' => gmdate('Y-m-d H:i:s'),
                    ':tenant_id' => $tenantId,
                    ':guest' => $guestId,
                ]);
            } else {
                $deactivate = PdoSqlDialect::prepare($this->pdo,
                    'UPDATE whatsapp_identity_links
                     SET is_active = 0, deactivated_at = UTC_TIMESTAMP()
                     WHERE tenant_id = :tenant_id AND guest_id = :guest AND is_active = 1'
                );
                $deactivate->execute([':tenant_id' => $tenantId, ':guest' => $guestId]);
                $update = PdoSqlDialect::prepare($this->pdo,
                    'UPDATE guests
                     SET whatsapp_opt_in_at = NULL, updated_at = UTC_TIMESTAMP()
                     WHERE tenant_id = :tenant_id AND id = :guest'
                );
                $update->execute([':tenant_id' => $tenantId, ':guest' => $guestId]);

                $cancelSql = PdoSqlDialect::isPostgres($this->pdo)
                    ? 'UPDATE message_outbox AS o
                       SET status = \'failed\', locked_at = NULL, lock_token = NULL,
                           last_error = \'Cancelled after WhatsApp consent withdrawal.\',
                           updated_at = CURRENT_TIMESTAMP
                       FROM messages AS m
                       INNER JOIN conversations AS c
                         ON c.id = m.conversation_id AND c.tenant_id = m.tenant_id
                       WHERE m.id = o.message_id AND o.tenant_id = :tenant_id
                         AND m.tenant_id = :tenant_id AND c.tenant_id = :tenant_id
                         AND c.guest_id = :guest AND o.status IN (\'pending\', \'processing\')'
                    : 'UPDATE message_outbox o
                       INNER JOIN messages m ON m.id = o.message_id AND m.tenant_id = o.tenant_id
                       INNER JOIN conversations c ON c.id = m.conversation_id AND c.tenant_id = m.tenant_id
                       SET o.status = "failed", o.locked_at = NULL, o.lock_token = NULL,
                           o.last_error = "Cancelled after WhatsApp consent withdrawal.",
                           o.updated_at = UTC_TIMESTAMP()
                       WHERE o.tenant_id = :tenant_id AND c.tenant_id = :tenant_id
                         AND c.guest_id = :guest AND o.status IN ("pending","processing")';
                $cancel = PdoSqlDialect::prepare($this->pdo, $cancelSql);
                $cancel->execute([':tenant_id' => $tenantId, ':guest' => $guestId]);

                $markSql = PdoSqlDialect::isPostgres($this->pdo)
                    ? 'UPDATE messages AS m
                       SET delivery_status = \'web_only\'
                       FROM conversations AS c
                       WHERE c.id = m.conversation_id AND c.tenant_id = m.tenant_id
                         AND m.tenant_id = :tenant_id AND c.tenant_id = :tenant_id
                         AND c.guest_id = :guest AND m.delivery_status = \'queued\''
                    : 'UPDATE messages m
                       INNER JOIN conversations c ON c.id = m.conversation_id AND c.tenant_id = m.tenant_id
                       SET m.delivery_status = "web_only"
                       WHERE m.tenant_id = :tenant_id AND c.tenant_id = :tenant_id
                         AND c.guest_id = :guest AND m.delivery_status = "queued"';
                $mark = PdoSqlDialect::prepare($this->pdo, $markSql);
                $mark->execute([':tenant_id' => $tenantId, ':guest' => $guestId]);
            }

            $event = PdoSqlDialect::prepare($this->pdo,
                'INSERT INTO guest_consent_events
                    (tenant_id, guest_id, channel, action, source, policy_version, created_at)
                 VALUES (:tenant_id, :guest, "whatsapp", :action, :source, "2026-11", UTC_TIMESTAMP())'
            );
            $event->execute([
                ':tenant_id' => $tenantId,
                ':guest' => $guestId,
                ':action' => $action,
                ':source' => substr(preg_replace('/[^a-z0-9_-]/i', '', $source) ?: 'website_widget', 0, 40),
            ]);
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
