<?php
declare(strict_types=1);

/** Meta WhatsApp Cloud API transport boundary. */

interface WhatsAppTextTransport
{
    /** @return array{provider_message_id:string} */
    public function sendTemplate(string $recipient, string $bodyParameter): array;
}

final class WhatsAppTransportException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable)
    {
        parent::__construct($message);
    }
}

final class MetaCloudApiTransport implements WhatsAppTextTransport
{
    public function __construct(private readonly array $config) {}

    public function sendTemplate(string $recipient, string $bodyParameter): array
    {
        $version = (string)($this->config['graph_api_version'] ?? '');
        $phoneNumberId = (string)($this->config['phone_number_id'] ?? '');
        $accessToken = (string)($this->config['access_token'] ?? '');

        if (!preg_match('/^v\d+\.\d+$/', $version) || $phoneNumberId === '' || $accessToken === '') {
            throw new WhatsAppTransportException('WhatsApp transport configuration is incomplete.', false);
        }
        $templateName = (string)($this->config['template_name'] ?? '');
        $templateLanguage = (string)($this->config['template_language'] ?? 'en');
        if ($recipient === '' || $bodyParameter === '' || $templateName === '') {
            throw new WhatsAppTransportException('WhatsApp recipient and message are required.', false);
        }

        $url = 'https://graph.facebook.com/' . $version . '/' . rawurlencode($phoneNumberId) . '/messages';
        $payload = json_encode([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $recipient,
            'type' => 'template',
            'template' => [
                'name' => $templateName,
                'language' => ['code' => $templateLanguage],
                'components' => [[
                    'type' => 'body',
                    'parameters' => [['type' => 'text', 'text' => $bodyParameter]],
                ]],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'timeout' => 15,
            'ignore_errors' => true,
            'header' => "Authorization: Bearer {$accessToken}\r\nContent-Type: application/json\r\nAccept: application/json\r\n",
            'content' => $payload,
        ]]);

        $response = @file_get_contents($url, false, $context);
        $statusCode = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $match)) {
                $statusCode = (int)$match[1];
            }
        }

        if ($response === false) {
            throw new WhatsAppTransportException('WhatsApp API connection failed.', true);
        }

        try {
            $decoded = json_decode($response, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new WhatsAppTransportException('WhatsApp API returned an invalid response.', $statusCode === 0 || $statusCode >= 500);
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            $error = is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
            $code = (int)($error['code'] ?? 0);
            $message = (string)($error['message'] ?? 'WhatsApp API rejected the message.');
            $retryable = $statusCode === 429 || $statusCode >= 500 || in_array($code, [130429, 131056], true);
            throw new WhatsAppTransportException($message, $retryable);
        }

        $providerId = $decoded['messages'][0]['id'] ?? null;
        if (!is_string($providerId) || $providerId === '') {
            throw new WhatsAppTransportException('WhatsApp API accepted no identifiable message.', true);
        }

        return ['provider_message_id' => $providerId];
    }
}
