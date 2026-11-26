<?php
declare(strict_types=1);

/**
 * Maps Meta webhook payloads to repository-level provider events.
 *
 * This service intentionally does not own webhook verification, durable raw
 * payload inbox storage, Meta connection lookup or HTTP acknowledgement.
 */
final class MetaWebhookProcessor
{
    public function __construct(private readonly ProviderEventRepository $repository) {}

    /** @return array{processed:int,ignored:int} */
    public function process(string $tenantId, array $payload, string $rawBody, string $expectedPhoneNumberId = ''): array
    {
        $processed = 0;
        $ignored = 0;
        $payloadHash = hash('sha256', $rawBody);

        foreach (($payload['entry'] ?? []) as $entry) {
            if (!is_array($entry)) continue;
            foreach (($entry['changes'] ?? []) as $change) {
                if (!is_array($change)) continue;
                $field = (string)($change['field'] ?? '');
                $value = is_array($change['value'] ?? null) ? $change['value'] : [];
                $receivedPhoneNumberId = (string)($value['metadata']['phone_number_id'] ?? '');
                if ($expectedPhoneNumberId !== '' && !hash_equals($expectedPhoneNumberId, $receivedPhoneNumberId)) {
                    $ignored++;
                    continue;
                }

                if ($field === 'smb_message_echoes') {
                    foreach (($value['message_echoes'] ?? []) as $echo) {
                        if (!is_array($echo)) continue;
                        $wamid = (string)($echo['id'] ?? '');
                        if ($wamid === '') { $ignored++; continue; }
                        $body = (string)($echo['text']['body'] ?? '');
                        $recipient = normalize_whatsapp_number((string)($echo['to'] ?? ''));
                        $eventId = 'echo:' . $wamid;
                        if (($echo['type'] ?? '') !== 'text' || $body === '' || $recipient === '') {
                            $result = $this->repository->recordIgnoredProviderEvent($tenantId, $eventId, 'smb_message_echoes', $payloadHash);
                            if ($result !== 'processed') $ignored++;
                            continue;
                        }
                        $result = $this->repository->recordProviderMessageEvent(
                            $tenantId, $eventId, 'smb_message_echoes', $payloadHash, $recipient, 'business',
                            $body, $wamid, 'sent', webhook_timestamp((string)($echo['timestamp'] ?? ''))
                        );
                        if ($result === 'processed') $processed++;
                        elseif ($result === 'ignored') $ignored++;
                    }
                } elseif ($field === 'messages') {
                    foreach (($value['messages'] ?? []) as $incoming) {
                        if (!is_array($incoming)) continue;
                        $wamid = (string)($incoming['id'] ?? '');
                        if ($wamid === '') { $ignored++; continue; }
                        $sender = normalize_whatsapp_number((string)($incoming['from'] ?? ''));
                        $body = (string)($incoming['text']['body'] ?? '');
                        $eventId = 'inbound:' . $wamid;
                        if (($incoming['type'] ?? '') !== 'text' || $body === '' || $sender === '') {
                            $result = $this->repository->recordIgnoredProviderEvent($tenantId, $eventId, 'inbound_message', $payloadHash);
                            if ($result !== 'processed') $ignored++;
                            continue;
                        }
                        $result = $this->repository->recordProviderMessageEvent(
                            $tenantId, $eventId, 'inbound_message', $payloadHash, $sender, 'visitor',
                            $body, $wamid, 'received', webhook_timestamp((string)($incoming['timestamp'] ?? ''))
                        );
                        if ($result === 'processed') $processed++;
                        elseif ($result === 'ignored') $ignored++;
                    }

                    foreach (($value['statuses'] ?? []) as $status) {
                        if (!is_array($status)) continue;
                        $wamid = (string)($status['id'] ?? '');
                        $state = (string)($status['status'] ?? '');
                        $timestamp = (string)($status['timestamp'] ?? '');
                        if ($wamid === '' || !in_array($state, ['sent', 'delivered', 'read', 'failed'], true)) {
                            $ignored++;
                            continue;
                        }
                        $eventId = 'status:' . $wamid . ':' . $state . ':' . $timestamp;
                        $result = $this->repository->recordProviderStatusEvent(
                            $tenantId, $eventId, $payloadHash, $wamid, $state,
                            ctype_digit($timestamp) ? (int)$timestamp : 0
                        );
                        if ($result === 'processed') $processed++;
                        elseif ($result === 'ignored') $ignored++;
                    }
                }
            }
        }

        return ['processed' => $processed, 'ignored' => $ignored];
    }
}
