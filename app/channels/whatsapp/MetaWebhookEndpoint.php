<?php
declare(strict_types=1);

/**
 * Framework-free HTTP application controller for Meta's WhatsApp webhook.
 *
 * It returns a response description instead of emitting headers or exiting,
 * leaving the public entry point responsible only for PHP HTTP I/O.
 */
final class MetaWebhookEndpoint
{
    public function __construct(
        private readonly ChatRepository $repository,
        private readonly WebhookPayloadCipher $cipher,
        private readonly WebhookInboxWorker $worker,
        private readonly MetaWebhookSignatureVerifier $signatureVerifier
    ) {}

    /**
     * @param array<string,mixed> $query
     * @return array{status:int,headers:array<string,string>,body:string}
     */
    public function handle(
        string $method,
        array $query,
        ?string $signatureHeader,
        string $rawBody,
        string $verifyToken,
        string $appSecret,
        int $maxBodyBytes = 1048576
    ): array {
        $method = strtoupper($method);

        if ($method === 'GET') {
            $mode = (string)($query['hub_mode'] ?? $query['hub.mode'] ?? '');
            $token = (string)($query['hub_verify_token'] ?? $query['hub.verify_token'] ?? '');
            $challenge = (string)($query['hub_challenge'] ?? $query['hub.challenge'] ?? '');
            if ($mode === 'subscribe' && $verifyToken !== '' && hash_equals($verifyToken, $token) && $challenge !== '') {
                return $this->response(200, $challenge, [
                    'Content-Type' => 'text/plain; charset=utf-8',
                    'Cache-Control' => 'no-store',
                ]);
            }
            return $this->response(403, 'Forbidden', ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        if ($method !== 'POST') {
            return $this->response(405, 'Method Not Allowed', [
                'Content-Type' => 'text/plain; charset=utf-8',
                'Allow' => 'GET, POST',
            ]);
        }

        if (strlen($rawBody) > $maxBodyBytes) {
            return $this->response(413, 'Payload Too Large', ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        if (!$this->signatureVerifier->verify($rawBody, $signatureHeader, $appSecret)) {
            return $this->response(401, 'Invalid signature', ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        try {
            $payload = json_decode($rawBody, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->response(400, 'Invalid JSON', ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        if (!is_array($payload) || ($payload['object'] ?? '') !== 'whatsapp_business_account') {
            return $this->response(400, 'Invalid payload', ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        $encrypted = $this->cipher->encrypt($rawBody);
        $queued = $this->repository->enqueueWebhookInbox(hash('sha256', $rawBody), $encrypted);
        $summary = ['processed' => 0, 'retried' => 0, 'failed' => 0];
        if ($queued['status'] !== 'processed') {
            $summary = $this->worker->process(1, 8);
        }

        return $this->response(200, (string)json_encode([
            'ok' => true,
            'queued' => $queued['inserted'],
            'inbox_id' => $queued['id'],
            'processed' => $summary['processed'],
            'retry_scheduled' => $summary['retried'] > 0,
        ], JSON_UNESCAPED_SLASHES), ['Content-Type' => 'application/json; charset=utf-8']);
    }

    /** @param array<string,string> $headers */
    private function response(int $status, string $body, array $headers): array
    {
        return ['status' => $status, 'headers' => $headers, 'body' => $body];
    }
}
