<?php
declare(strict_types=1);

require_once __DIR__ . '/channels/whatsapp/MetaTokenCipher.php';
require_once __DIR__ . '/channels/whatsapp/MetaGraphApi.php';
require_once __DIR__ . '/channels/whatsapp/MetaGraphApiClient.php';
require_once __DIR__ . '/channels/whatsapp/MetaEmbeddedSignupService.php';

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

/** Build the Meta application service with the configured persistence adapter. */
function meta_embedded_signup_service(PDO $pdo): MetaEmbeddedSignupService
{
    return new MetaEmbeddedSignupService(
        meta_connection_repository($pdo),
        new MetaTokenCipher((string) app_config('app.app_key')),
        new MetaGraphApiClient(),
        app_config()
    );
}

function meta_subscribe_active_waba(PDO $pdo): bool
{
    return meta_embedded_signup_service($pdo)->subscribeActiveWaba();
}

function meta_begin_embedded_signup(PDO $pdo): array
{
    return meta_embedded_signup_service($pdo)->begin();
}

function meta_exchange_embedded_signup(PDO $pdo, string $state, string $code, string $phoneId, string $wabaId): array
{
    return meta_embedded_signup_service($pdo)->exchange($state, $code, $phoneId, $wabaId);
}

function load_meta_whatsapp_config(PDO $pdo, array $base): array
{
    return meta_embedded_signup_service($pdo)->loadWhatsAppConfig($base);
}

function meta_active_phone_number_id(PDO $pdo): string
{
    return chat_repository($pdo)->activeWhatsAppPhoneNumberId()
        ?? (string) app_config('whatsapp.phone_number_id');
}
