<?php
declare(strict_types=1);

require_once __DIR__ . '/channels/whatsapp/MetaTokenCipher.php';
require_once __DIR__ . '/channels/whatsapp/MetaGraphApiClient.php';

function meta_signup_admin_authorized(): bool
{
    $expected = (string) app_config('meta.admin_api_key');
    $supplied = $_SERVER['HTTP_X_DIMDIAN_ADMIN_KEY'] ?? '';
    return strlen($expected) >= 32 && is_string($supplied) && hash_equals($expected, $supplied);
}

function meta_token_key(): string
{
    $secret = (string) app_config('app.app_key');
    if (strlen($secret) < 32) {
        throw new RuntimeException('app.app_key must contain at least 32 characters.');
    }
    return hash('sha256', 'dimdian-meta-token:' . $secret, true);
}

function meta_seal_token(string $token): array
{
    return (new MetaTokenCipher((string) app_config('app.app_key')))->seal($token);
}

function meta_open_token(string $cipher, string $nonce, string $tag): string
{
    return (new MetaTokenCipher((string) app_config('app.app_key')))->open($cipher, $nonce, $tag);
}

function meta_graph_get(string $url): array
{
    return (new MetaGraphApiClient())->get($url);
}

function meta_graph_post(string $url, string $token): bool
{
    return (new MetaGraphApiClient())->post($url, $token);
}

function meta_subscribe_active_waba(PDO $pdo): bool
{
    $row = meta_connection_repository($pdo)->findActiveConnection();
    if (!$row) {
        return false;
    }
    $version = (string) app_config('whatsapp.graph_api_version');
    if (!preg_match('/^v[0-9]+\\.[0-9]+$/', $version)) {
        throw new RuntimeException('Meta Graph API version is not configured.');
    }
    $token = meta_open_token(
        (string) $row['access_token_ciphertext'],
        (string) $row['access_token_nonce'],
        (string) $row['access_token_tag']
    );
    return meta_graph_post(
        'https://graph.facebook.com/' . $version . '/' . rawurlencode((string) $row['waba_id']) . '/subscribed_apps',
        $token
    );
}

function meta_begin_embedded_signup(PDO $pdo): array
{
    $appId = (string) app_config('meta.app_id');
    $configId = (string) app_config('meta.config_id');
    if ($appId === '' || $configId === '') {
        throw new RuntimeException('Meta app_id and config_id must be configured.');
    }
    $version = (string) app_config('whatsapp.graph_api_version');
    if (!preg_match('/^v[0-9]+\\.[0-9]+$/', $version)) {
        throw new RuntimeException('Meta Graph API version is not configured.');
    }

    $state = bin2hex(random_bytes(32));
    meta_connection_repository($pdo)->createSignupState(hash('sha256', $state), 600);

    return [
        'app_id' => $appId,
        'config_id' => $configId,
        'graph_api_version' => $version,
        'state' => $state,
        'expires_in_seconds' => 600,
    ];
}

function meta_exchange_embedded_signup(PDO $pdo, string $state, string $code, string $phoneId, string $wabaId): array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $state) || $code === '' || strlen($code) > 4096) {
        throw new InvalidArgumentException('Meta signup response is incomplete.');
    }
    if (!preg_match('/^[0-9]{5,80}$/', $phoneId) || !preg_match('/^[0-9]{5,80}$/', $wabaId)) {
        throw new InvalidArgumentException('Meta returned invalid WhatsApp identifiers.');
    }

    $repository = meta_connection_repository($pdo);
    // Consume before the remote exchange: even a failed exchange cannot replay the authorization code.
    if (!$repository->consumeSignupState(hash('sha256', $state))) {
        throw new RuntimeException('Meta signup state is invalid, expired, or already used.');
    }

    $version = (string) app_config('whatsapp.graph_api_version');
    $appId = (string) app_config('meta.app_id');
    $secret = (string) app_config('whatsapp.app_secret');
    if (!preg_match('/^v[0-9]+\\.[0-9]+$/', $version) || $appId === '' || $secret === '') {
        throw new RuntimeException('Meta app credentials or Graph API version are not configured.');
    }

    $url = 'https://graph.facebook.com/' . $version . '/oauth/access_token?' . http_build_query([
        'client_id' => $appId,
        'client_secret' => $secret,
        'code' => $code,
    ]);
    $tokenData = meta_graph_get($url);
    $token = (string) $tokenData['access_token'];
    $expires = max(0, (int) ($tokenData['expires_in'] ?? 0));
    $sealed = meta_seal_token($token);

    $repository->saveActiveConnection([
        'waba_id' => $wabaId,
        'phone_number_id' => $phoneId,
        'token_ciphertext' => $sealed['ciphertext'],
        'token_nonce' => $sealed['nonce'],
        'token_tag' => $sealed['tag'],
        'token_expires_at' => $expires ? gmdate('Y-m-d H:i:s', time() + $expires) : null,
    ]);

    $webhookSubscribed = meta_graph_post(
        'https://graph.facebook.com/' . $version . '/' . rawurlencode($wabaId) . '/subscribed_apps',
        $token
    );

    return [
        'connected' => true,
        'phone_number_id' => $phoneId,
        'waba_id' => $wabaId,
        'expires_at' => $expires ? gmdate(DateTimeInterface::ATOM, time() + $expires) : null,
        'webhook_subscribed' => $webhookSubscribed,
    ];
}

function load_meta_whatsapp_config(PDO $pdo, array $base): array
{
    $repository = meta_connection_repository($pdo);
    $row = $repository->findActiveConnection();
    if (!$row) {
        return $base;
    }

    $token = meta_open_token(
        (string) $row['access_token_ciphertext'],
        (string) $row['access_token_nonce'],
        (string) $row['access_token_tag']
    );
    $expiry = !empty($row['token_expires_at'])
        ? strtotime((string) $row['token_expires_at'] . ' UTC')
        : null;

    if ($expiry !== null && $expiry <= time() + 7 * 86400) {
        $version = (string) app_config('whatsapp.graph_api_version');
        $appId = (string) app_config('meta.app_id');
        $secret = (string) app_config('whatsapp.app_secret');

        try {
            if (!preg_match('/^v[0-9]+\\.[0-9]+$/', $version) || $appId === '' || $secret === '') {
                throw new RuntimeException('Meta refresh credentials are not configured.');
            }
            $url = 'https://graph.facebook.com/' . $version . '/oauth/access_token?' . http_build_query([
                'grant_type' => 'fb_exchange_token',
                'client_id' => $appId,
                'client_secret' => $secret,
                'set_token_expires_in_60_days' => 'true',
                'fb_exchange_token' => $token,
            ]);
            $refreshed = meta_graph_get($url);
            $token = (string) $refreshed['access_token'];
            $expires = max(0, (int) ($refreshed['expires_in'] ?? 0));
            $sealed = meta_seal_token($token);
            $repository->updateAccessToken(
                (int) $row['id'],
                $sealed,
                $expires ? gmdate('Y-m-d H:i:s', time() + $expires) : null
            );
            $expiry = $expires ? time() + $expires : null;
        } catch (Throwable $error) {
            error_log('[DimdianChatbot] Meta token refresh attempt failed: ' . $error->getMessage());
            if ($expiry !== null && $expiry <= time()) {
                $repository->markConnectionExpired((int) $row['id']);
                throw new RuntimeException('The connected Meta token has expired. Reconnect WhatsApp.');
            }
        }
    }

    $base['phone_number_id'] = (string) $row['phone_number_id'];
    $base['access_token'] = $token;
    return $base;
}

function meta_active_phone_number_id(PDO $pdo): string
{
    return chat_repository($pdo)->activeWhatsAppPhoneNumberId()
        ?? (string) app_config('whatsapp.phone_number_id');
}
