<?php
declare(strict_types=1);

/**
 * Backward-compatible procedural entry point.
 * Consent policy and persistence now live behind ChatRepository.
 */
function record_whatsapp_consent(PDO $pdo, string $guestId, string $conversationId, string $phone, string $action, string $source = 'website_widget'): void
{
    if (!in_array($action, ['opt_in', 'opt_out'], true)) {
        throw new InvalidArgumentException('Unsupported consent action.');
    }
    $normalizedPhone = normalize_whatsapp_number($phone);
    if ($action === 'opt_in' && !preg_match('/^[1-9][0-9]{7,14}$/', $normalizedPhone)) {
        throw new InvalidArgumentException('A valid international WhatsApp number is required.');
    }
    chat_repository($pdo)->recordWhatsAppConsent($guestId, $conversationId, $normalizedPhone, $action, $source);
}
