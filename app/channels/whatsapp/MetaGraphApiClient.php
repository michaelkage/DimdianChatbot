<?php
declare(strict_types=1);

/**
 * Minimal Meta Graph API HTTP client.
 *
 * Requests are HTTPS-only and pinned to graph.facebook.com. Redirects are
 * disabled so an Authorization bearer token cannot be forwarded elsewhere.
 */
final class MetaGraphApiClient
{
    /** @return array<string,mixed> */
    public function get(string $url): array
    {
        $this->assertGraphUrl($url);
        $context = stream_context_create(['http' => [
            'method' => 'GET',
            'timeout' => 15,
            'ignore_errors' => true,
            'follow_location' => 0,
            'max_redirects' => 0,
            'header' => "Accept: application/json\r\n",
        ]]);

        $raw = @file_get_contents($url, false, $context);
        $status = $this->responseStatus($http_response_header ?? []);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data) || $status < 200 || $status >= 300 || empty($data['access_token'])) {
            throw new RuntimeException('Meta token exchange was rejected or returned no token.');
        }
        return $data;
    }

    public function post(string $url, string $token): bool
    {
        $this->assertGraphUrl($url);
        if ($token === '') {
            throw new InvalidArgumentException('A Meta access token is required.');
        }

        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'timeout' => 15,
            'ignore_errors' => true,
            'follow_location' => 0,
            'max_redirects' => 0,
            'header' => "Authorization: Bearer " . $token . "\r\nAccept: application/json\r\nContent-Length: 0\r\n",
        ]]);

        $raw = @file_get_contents($url, false, $context);
        $status = $this->responseStatus($http_response_header ?? []);
        if (!is_string($raw) || $status < 200 || $status >= 300) {
            error_log('[DimdianChatbot] Meta WABA webhook subscription failed with HTTP ' . $status . '.');
            return false;
        }
        return true;
    }

    private function assertGraphUrl(string $url): void
    {
        $parts = parse_url($url);
        if (
            !is_array($parts)
            || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
            || strtolower((string)($parts['host'] ?? '')) !== 'graph.facebook.com'
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
            || (isset($parts['port']) && (int)$parts['port'] !== 443)
            || !str_starts_with((string)($parts['path'] ?? ''), '/v')
        ) {
            throw new InvalidArgumentException('Meta Graph API requests must target an HTTPS graph.facebook.com versioned endpoint.');
        }
    }

    /** @param list<string> $headers */
    private function responseStatus(array $headers): int
    {
        $status = 0;
        foreach ($headers as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $match)) {
                $status = (int)$match[1];
            }
        }
        return $status;
    }
}
