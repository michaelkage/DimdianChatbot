<?php
declare(strict_types=1);

/** Domain signal for an opt-in request without a persisted WhatsApp phone number. */
final class WhatsAppConsentPhoneRequired extends RuntimeException
{
}

/** Applies visitor WhatsApp consent through the auditable ConsentRepository transition. */
final class WhatsAppConsentService
{
    /** @var ConsentRepository */
    private $repository;

    public function __construct(ConsentRepository $repository)
    {
        $this->repository = $repository;
    }

    /** @param array<string,mixed> $session @return array{whatsapp_opt_in:bool,updated_at:string} */
    public function update(array $session, bool $optIn): array
    {
        $phone = (string) ($session['phone_normalized'] ?? '');
        if ($optIn && $phone === '') {
            throw new WhatsAppConsentPhoneRequired('A WhatsApp number must be added before opting in.');
        }

        $this->repository->recordWhatsAppConsent(
            (string) $session['tenant_id'],
            (string) $session['guest_id'],
            (string) $session['conversation_id'],
            $phone,
            $optIn ? 'opt_in' : 'opt_out',
            'consent_settings'
        );

        return [
            'whatsapp_opt_in' => $optIn,
            'updated_at' => gmdate(DateTimeInterface::ATOM),
        ];
    }
}
