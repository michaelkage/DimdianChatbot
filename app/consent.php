<?php
declare(strict_types=1);

/**
 * Auditable WhatsApp consent lifecycle. The guest's current consent timestamp
 * remains a fast authorization flag; this append-only table is the audit trail.
 */
function record_whatsapp_consent(PDO $pdo, string $guestId, string $conversationId, string $phone, string $action, string $source = 'website_widget'): void
{
    if (!in_array($action, ['opt_in', 'opt_out'], true)) {
        throw new InvalidArgumentException('Unsupported consent action.');
    }
    $phone = normalize_whatsapp_number($phone);
    if ($action === 'opt_in' && !preg_match('/^[1-9][0-9]{7,14}$/', $phone)) {
        throw new InvalidArgumentException('A valid international WhatsApp number is required.');
    }

    $now = gmdate('Y-m-d H:i:s');
    if ($action === 'opt_in') {
        // A phone number can route to one active website conversation only.
        // Re-consent moves the route deliberately; old conversations remain readable.
        $deactivate = $pdo->prepare(
            'UPDATE whatsapp_identity_links
             SET is_active = 0, deactivated_at = UTC_TIMESTAMP()
             WHERE phone_normalized = :phone AND is_active = 1'
        );
        $deactivate->execute([':phone' => $phone]);
        $link = $pdo->prepare(
            'INSERT INTO whatsapp_identity_links
                (phone_normalized, guest_id, conversation_id, is_active, created_at)
             VALUES (:phone, :guest, :conversation, 1, UTC_TIMESTAMP())'
        );
        $link->execute([':phone' => $phone, ':guest' => $guestId, ':conversation' => $conversationId]);
        $update = $pdo->prepare('UPDATE guests SET phone_normalized = :phone, whatsapp_opt_in_at = :now, updated_at = UTC_TIMESTAMP() WHERE id = :guest');
        $update->execute([':phone' => $phone, ':now' => $now, ':guest' => $guestId]);
    } else {
        $deactivate = $pdo->prepare(
            'UPDATE whatsapp_identity_links
             SET is_active = 0, deactivated_at = UTC_TIMESTAMP()
             WHERE guest_id = :guest AND is_active = 1'
        );
        $deactivate->execute([':guest' => $guestId]);
        $update = $pdo->prepare('UPDATE guests SET whatsapp_opt_in_at = NULL, updated_at = UTC_TIMESTAMP() WHERE id = :guest');
        $update->execute([':guest' => $guestId]);

        // Prevent queued messages from being sent after the visitor withdraws consent.
        $cancel = $pdo->prepare(
            'UPDATE message_outbox o
             INNER JOIN messages m ON m.id = o.message_id
             INNER JOIN conversations c ON c.id = m.conversation_id
             SET o.status = "failed", o.locked_at = NULL, o.lock_token = NULL,
                 o.last_error = "Cancelled after WhatsApp consent withdrawal.",
                 o.updated_at = UTC_TIMESTAMP()
             WHERE c.guest_id = :guest AND o.status IN ("pending","processing")'
        );
        $cancel->execute([':guest' => $guestId]);
        $mark = $pdo->prepare(
            'UPDATE messages m INNER JOIN conversations c ON c.id = m.conversation_id
             SET m.delivery_status = "web_only"
             WHERE c.guest_id = :guest AND m.delivery_status = "queued"'
        );
        $mark->execute([':guest' => $guestId]);
    }

    $event = $pdo->prepare(
        'INSERT INTO guest_consent_events (guest_id, channel, action, source, policy_version, created_at)
         VALUES (:guest, "whatsapp", :action, :source, "2026-11", UTC_TIMESTAMP())'
    );
    $event->execute([
        ':guest' => $guestId,
        ':action' => $action,
        ':source' => substr(preg_replace('/[^a-z0-9_-]/i', '', $source) ?: 'website_widget', 0, 40),
    ]);
}
