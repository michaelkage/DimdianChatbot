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

    public function recordWhatsAppConsent(string $guestId, string $conversationId, string $normalizedPhone, string $action, string $source = 'website_widget'): void
    {
        if (!in_array($action, ['opt_in', 'opt_out'], true)) {
            throw new InvalidArgumentException('Unsupported consent action.');
        }
        if ($action === 'opt_in' && !preg_match('/^[1-9][0-9]{7,14}$/', $normalizedPhone)) {
            throw new InvalidArgumentException('A valid international WhatsApp number is required.');
        }

        $this->transaction(function () use ($guestId, $conversationId, $normalizedPhone, $action, $source): void {
            if ($action === 'opt_in') {
                // A phone number can route to one active website conversation only.
                // Re-consent deliberately moves the route; prior conversations remain readable.
                $deactivate = PdoSqlDialect::prepare($this->pdo, 
                    'UPDATE whatsapp_identity_links
                     SET is_active = 0, deactivated_at = UTC_TIMESTAMP()
                     WHERE phone_normalized = :phone AND is_active = 1'
                );
                $deactivate->execute([':phone' => $normalizedPhone]);
                $link = PdoSqlDialect::prepare($this->pdo, 
                    'INSERT INTO whatsapp_identity_links
                        (phone_normalized, guest_id, conversation_id, is_active, created_at)
                     VALUES (:phone, :guest, :conversation, 1, UTC_TIMESTAMP())'
                );
                $link->execute([':phone' => $normalizedPhone, ':guest' => $guestId, ':conversation' => $conversationId]);
                $update = PdoSqlDialect::prepare($this->pdo, 
                    'UPDATE guests SET phone_normalized = :phone, whatsapp_opt_in_at = :now, updated_at = UTC_TIMESTAMP() WHERE id = :guest'
                );
                $update->execute([':phone' => $normalizedPhone, ':now' => gmdate('Y-m-d H:i:s'), ':guest' => $guestId]);
            } else {
                $deactivate = PdoSqlDialect::prepare($this->pdo, 
                    'UPDATE whatsapp_identity_links
                     SET is_active = 0, deactivated_at = UTC_TIMESTAMP()
                     WHERE guest_id = :guest AND is_active = 1'
                );
                $deactivate->execute([':guest' => $guestId]);
                $update = PdoSqlDialect::prepare($this->pdo, 
                    'UPDATE guests SET whatsapp_opt_in_at = NULL, updated_at = UTC_TIMESTAMP() WHERE id = :guest'
                );
                $update->execute([':guest' => $guestId]);

                // Prevent queued messages from being sent after consent withdrawal.
                $cancel = PdoSqlDialect::prepare($this->pdo, 
                    'UPDATE message_outbox o
                     INNER JOIN messages m ON m.id = o.message_id
                     INNER JOIN conversations c ON c.id = m.conversation_id
                     SET o.status = "failed", o.locked_at = NULL, o.lock_token = NULL,
                         o.last_error = "Cancelled after WhatsApp consent withdrawal.",
                         o.updated_at = UTC_TIMESTAMP()
                     WHERE c.guest_id = :guest AND o.status IN ("pending","processing")'
                );
                $cancel->execute([':guest' => $guestId]);
                $mark = PdoSqlDialect::prepare($this->pdo, 
                    'UPDATE messages m INNER JOIN conversations c ON c.id = m.conversation_id
                     SET m.delivery_status = "web_only"
                     WHERE c.guest_id = :guest AND m.delivery_status = "queued"'
                );
                $mark->execute([':guest' => $guestId]);
            }

            $event = PdoSqlDialect::prepare($this->pdo, 
                'INSERT INTO guest_consent_events (guest_id, channel, action, source, policy_version, created_at)
                 VALUES (:guest, "whatsapp", :action, :source, "2026-11", UTC_TIMESTAMP())'
            );
            $event->execute([
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
