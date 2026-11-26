<?php
declare(strict_types=1);

final class MetaWebhookTenantResolutionException extends RuntimeException
{
}

/**
 * Resolve verified Meta webhook destinations through active server-side
 * connection records. Payloads never supply a tenant identifier.
 */
final class MetaWebhookTenantResolver
{
    private WhatsAppIdentityRepository $repository;

    public function __construct(WhatsAppIdentityRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{tenant_id:string,phone_number_ids:list<string>}|null
     */
    public function resolve(array $payload): ?array
    {
        $phoneNumberIds = self::phoneNumberIdsFromPayload($payload);
        if ($phoneNumberIds === []) {
            return null;
        }

        $tenantId = null;
        foreach ($phoneNumberIds as $phoneNumberId) {
            $resolvedTenantId = $this->repository->tenantIdForWhatsAppPhoneNumberId($phoneNumberId);
            if (!is_string($resolvedTenantId) || $resolvedTenantId === '') {
                throw new MetaWebhookTenantResolutionException('Webhook destination is not connected or is no longer active.');
            }
            if ($tenantId !== null && !hash_equals($tenantId, $resolvedTenantId)) {
                throw new MetaWebhookTenantResolutionException('A webhook payload cannot span multiple tenant destinations.');
            }
            $tenantId = $resolvedTenantId;
        }

        return ['tenant_id' => (string) $tenantId, 'phone_number_ids' => $phoneNumberIds];
    }

    /** @param array<string,mixed> $payload @return list<string> */
    public static function phoneNumberIdsFromPayload(array $payload): array
    {
        $ids = [];
        foreach (($payload['entry'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            foreach (($entry['changes'] ?? []) as $change) {
                if (!is_array($change)) {
                    continue;
                }
                $field = (string) ($change['field'] ?? '');
                if (!in_array($field, ['messages', 'smb_message_echoes'], true)) {
                    continue;
                }
                $value = is_array($change['value'] ?? null) ? $change['value'] : [];
                $phoneNumberId = $value['metadata']['phone_number_id'] ?? null;
                if (!is_string($phoneNumberId) || $phoneNumberId === '' || strlen($phoneNumberId) > 80) {
                    throw new MetaWebhookTenantResolutionException('A routable Meta webhook change is missing its phone-number identity.');
                }
                $ids[] = $phoneNumberId;
            }
        }
        return array_values(array_unique($ids));
    }
}
