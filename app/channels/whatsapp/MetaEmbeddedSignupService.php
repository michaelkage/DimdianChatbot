<?php
declare(strict_types=1);

/**
 * Application service for Meta Embedded Signup and connected-token lifecycle.
 * It coordinates provider HTTP calls and repository operations without PDO,
 * HTTP request globals or application bootstrap dependencies.
 */
final class MetaEmbeddedSignupService
{
    /** @var MetaConnectionRepository */
    private $repository;
    /** @var MetaTokenCipher */
    private $cipher;
    /** @var MetaGraphApi */
    private $graph;
    /** @var array<string,mixed> */
    private $config;

    /** @param array<string,mixed> $config */
    public function __construct(
        MetaConnectionRepository $repository,
        MetaTokenCipher $cipher,
        MetaGraphApi $graph,
        array $config
    ) {
        $this->repository = $repository;
        $this->cipher = $cipher;
        $this->graph = $graph;
        $this->config = $config;
    }

    /** @return array{app_id:string,config_id:string,graph_api_version:string,state:string,expires_in_seconds:int} */
    public function begin(): array
    {
        $appId = (string) $this->configValue('meta.app_id');
        $configId = (string) $this->configValue('meta.config_id');
        if ($appId === '' || $configId === '') {
            throw new RuntimeException('Meta app_id and config_id must be configured.');
        }
        $version = $this->graphVersion();
        $state = bin2hex(random_bytes(32));
        $this->repository->createSignupState(hash('sha256', $state), 600);

        return [
            'app_id' => $appId,
            'config_id' => $configId,
            'graph_api_version' => $version,
            'state' => $state,
            'expires_in_seconds' => 600,
        ];
    }

    /** @return array<string,mixed> */
    public function exchange(string $state, string $code, string $phoneId, string $wabaId): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $state) || $code === '' || strlen($code) > 4096) {
            throw new InvalidArgumentException('Meta signup response is incomplete.');
        }
        if (!preg_match('/^[0-9]{5,80}$/', $phoneId) || !preg_match('/^[0-9]{5,80}$/', $wabaId)) {
            throw new InvalidArgumentException('Meta returned invalid WhatsApp identifiers.');
        }

        // Consume before remote exchange so a failed exchange cannot replay the authorization code.
        if (!$this->repository->consumeSignupState(hash('sha256', $state))) {
            throw new RuntimeException('Meta signup state is invalid, expired, or already used.');
        }

        $version = $this->graphVersion();
        $appId = (string) $this->configValue('meta.app_id');
        $secret = (string) $this->configValue('whatsapp.app_secret');
        if ($appId === '' || $secret === '') {
            throw new RuntimeException('Meta app credentials or Graph API version are not configured.');
        }

        $url = 'https://graph.facebook.com/' . $version . '/oauth/access_token?' . http_build_query([
            'client_id' => $appId,
            'client_secret' => $secret,
            'code' => $code,
        ]);
        $tokenData = $this->graph->get($url);
        $token = (string) ($tokenData['access_token'] ?? '');
        if ($token === '') {
            throw new RuntimeException('Meta token exchange returned an empty access token.');
        }
        $expires = max(0, (int) ($tokenData['expires_in'] ?? 0));
        $sealed = $this->cipher->seal($token);

        $this->repository->saveActiveConnection([
            'waba_id' => $wabaId,
            'phone_number_id' => $phoneId,
            'token_ciphertext' => $sealed['ciphertext'],
            'token_nonce' => $sealed['nonce'],
            'token_tag' => $sealed['tag'],
            'token_expires_at' => $expires ? gmdate('Y-m-d H:i:s', time() + $expires) : null,
        ]);

        $webhookSubscribed = $this->graph->post(
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

    public function subscribeActiveWaba(): bool
    {
        $row = $this->repository->findActiveConnection();
        if (!$row) {
            return false;
        }
        $token = $this->cipher->open(
            (string) $row['access_token_ciphertext'],
            (string) $row['access_token_nonce'],
            (string) $row['access_token_tag']
        );
        $url = 'https://graph.facebook.com/' . $this->graphVersion() . '/'
            . rawurlencode((string) $row['waba_id']) . '/subscribed_apps';
        return $this->graph->post($url, $token);
    }

    /** @param array<string,mixed> $base @return array<string,mixed> */
    public function loadWhatsAppConfig(array $base): array
    {
        $row = $this->repository->findActiveConnection();
        if (!$row) {
            return $base;
        }

        $token = $this->cipher->open(
            (string) $row['access_token_ciphertext'],
            (string) $row['access_token_nonce'],
            (string) $row['access_token_tag']
        );
        $expiry = !empty($row['token_expires_at'])
            ? strtotime((string) $row['token_expires_at'] . ' UTC')
            : null;

        if ($expiry !== null && $expiry <= time() + 7 * 86400) {
            try {
                $appId = (string) $this->configValue('meta.app_id');
                $secret = (string) $this->configValue('whatsapp.app_secret');
                if ($appId === '' || $secret === '') {
                    throw new RuntimeException('Meta refresh credentials are not configured.');
                }
                $url = 'https://graph.facebook.com/' . $this->graphVersion() . '/oauth/access_token?' . http_build_query([
                    'grant_type' => 'fb_exchange_token',
                    'client_id' => $appId,
                    'client_secret' => $secret,
                    'set_token_expires_in_60_days' => 'true',
                    'fb_exchange_token' => $token,
                ]);
                $refreshed = $this->graph->get($url);
                $token = (string) ($refreshed['access_token'] ?? '');
                if ($token === '') {
                    throw new RuntimeException('Meta token refresh returned an empty access token.');
                }
                $expires = max(0, (int) ($refreshed['expires_in'] ?? 0));
                $this->repository->updateAccessToken(
                    (int) $row['id'],
                    $this->cipher->seal($token),
                    $expires ? gmdate('Y-m-d H:i:s', time() + $expires) : null
                );
                $expiry = $expires ? time() + $expires : null;
            } catch (Throwable $error) {
                error_log('[DimdianChatbot] Meta token refresh attempt failed: ' . $error->getMessage());
                if ($expiry !== null && $expiry <= time()) {
                    $this->repository->markConnectionExpired((int) $row['id']);
                    throw new RuntimeException('The connected Meta token has expired. Reconnect WhatsApp.');
                }
            }
        }

        $base['phone_number_id'] = (string) $row['phone_number_id'];
        $base['access_token'] = $token;
        return $base;
    }

    private function graphVersion(): string
    {
        $version = (string) $this->configValue('whatsapp.graph_api_version');
        if (!preg_match('/^v[0-9]+\\.[0-9]+$/', $version)) {
            throw new RuntimeException('Meta Graph API version is not configured.');
        }
        return $version;
    }

    private function configValue(string $path)
    {
        $value = $this->config;
        foreach (explode('.', $path) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return null;
            }
            $value = $value[$part];
        }
        return $value;
    }
}
