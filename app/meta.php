<?php
declare(strict_types=1);

function chatbridge_meta_settings(): array
{
    $settings = app_config('meta') ?? [];
    return is_array($settings) ? $settings : [];
}

/** Make a server-side request only to Meta's fixed Graph API host. */
function chatbridge_meta_graph_request(string $method, string $path, string $accessToken, array $parameters = []): array
{
    $config = chatbridge_meta_settings();
    $version = (string)($config['graph_api_version'] ?? 'v27.0');
    if (!preg_match('/^v\d+\.\d+$/', $version)) {
        throw new RuntimeException('Meta Graph API version is not configured.');
    }
    if (!preg_match('~^[0-9]+(?:/[a-zA-Z0-9_]+)*$~', trim($path, '/'))) {
        throw new InvalidArgumentException('Invalid Meta Graph API resource path.');
    }

    $url = 'https://graph.facebook.com/' . $version . '/' . trim($path, '/');
    $headers = "Accept: application/json\r\nAuthorization: Bearer " . $accessToken . "\r\n";
    $content = '';
    if (strtoupper($method) === 'GET') {
        $url .= '?' . http_build_query($parameters);
    } else {
        $headers .= "Content-Type: application/x-www-form-urlencoded\r\n";
        $content = http_build_query($parameters);
    }

    $context = stream_context_create([
        'http' => [
            'method' => strtoupper($method),
            'timeout' => 20,
            'ignore_errors' => true,
            'header' => $headers,
            'content' => $content,
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $response = @file_get_contents($url, false, $context);
    $status = 0;
    foreach (($http_response_header ?? []) as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $match)) $status = (int)$match[1];
    }
    $decoded = is_string($response) ? json_decode($response, true) : null;
    if ($status < 200 || $status >= 300 || !is_array($decoded)) {
        // Never include response bodies, tokens or Meta error descriptions in exceptions/logs.
        throw new RuntimeException('Meta Graph API request failed (HTTP ' . ($status ?: 'network error') . ').');
    }
    return $decoded;
}

function chatbridge_meta_exchange_code(string $code): array
{
    $config = chatbridge_meta_settings();
    $appId = (string)($config['app_id'] ?? '');
    $appSecret = (string)($config['app_secret'] ?? '');
    if (empty($config['enabled']) || $appId === '' || $appSecret === '') {
        throw new RuntimeException('Meta Embedded Signup is not enabled or configured.');
    }
    $version = (string)($config['graph_api_version'] ?? 'v27.0');
    if (!preg_match('/^v\\d+\\.\\d+$/', $version)) throw new RuntimeException('Meta Graph API version is not configured.');
    $url = 'https://graph.facebook.com/' . $version . '/oauth/access_token?' . http_build_query([
        'client_id' => $appId,
        'client_secret' => $appSecret,
        'code' => $code,
    ]);
    $context = stream_context_create([
        'http' => ['method' => 'GET', 'timeout' => 20, 'ignore_errors' => true, 'header' => "Accept: application/json\r\n"],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $response = @file_get_contents($url, false, $context);
    $status = 0;
    foreach (($http_response_header ?? []) as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $match)) $status = (int)$match[1];
    }
    $decoded = is_string($response) ? json_decode($response, true) : null;
    if ($status < 200 || $status >= 300 || !is_array($decoded) || empty($decoded['access_token'])) {
        throw new RuntimeException('Meta authorization-code exchange failed (HTTP ' . ($status ?: 'network error') . ').');
    }
    return $decoded;
}

function complete_meta_embedded_signup(PDO $pdo, string $tenantId, string $code, array $sessionInfo): array
{
    if ($code === '' || strlen($code) > 10000) throw new InvalidArgumentException('The Meta authorization code is invalid.');

    $accountIds = $sessionInfo['messaging_account_ids'] ?? $sessionInfo['waba_id'] ?? [];
    if (is_string($accountIds)) $accountIds = [$accountIds];
    if (!is_array($accountIds)) $accountIds = [];
    $accountIds = array_values(array_unique(array_filter(array_map('strval', $accountIds), static fn(string $id): bool => preg_match('/^\d{5,40}$/', $id) === 1)));
    if ($accountIds === []) throw new InvalidArgumentException('The v5 signup event did not include a valid messaging account ID.');

    $isOnBizApp = !empty($sessionInfo['is_on_biz_app']);
    $connectionMode = $isOnBizApp ? 'coexistence' : 'standard';

    $tokenResponse = chatbridge_meta_exchange_code($code);
    $accessToken = (string)$tokenResponse['access_token'];
    $encryptedToken = chatbridge_encrypt_secret($accessToken);
    $expiresAt = null;
    if (isset($tokenResponse['expires_in']) && is_numeric($tokenResponse['expires_in']) && (int)$tokenResponse['expires_in'] > 0) {
        $expiresAt = gmdate('Y-m-d H:i:s', time() + (int)$tokenResponse['expires_in']);
    }

    $connected = [];
    foreach ($accountIds as $businessAccountId) {
        $tenantConflict = $pdo->prepare(
            'SELECT id FROM channel_connections
             WHERE provider = "meta" AND business_account_id = :waba_id
               AND tenant_id <> :tenant_id LIMIT 1'
        );
        $tenantConflict->execute([':waba_id' => $businessAccountId, ':tenant_id' => $tenantId]);
        if ($tenantConflict->fetchColumn()) {
            throw new RuntimeException('This Meta business account is already connected to another tenant.');
        }

        // Confirm the WABA exists and is accessible with the exchanged business token.
        $account = chatbridge_meta_graph_request('GET', $businessAccountId, $accessToken, ['fields' => 'id,name']);
        if ((string)($account['id'] ?? '') !== $businessAccountId) {
            throw new RuntimeException('Meta returned an unexpected WhatsApp business account.');
        }

        $numbers = chatbridge_meta_graph_request('GET', $businessAccountId . '/phone_numbers', $accessToken, [
            'fields' => 'id,display_phone_number,verified_name,quality_rating,platform_type',
            'limit' => 100,
        ]);
        $phoneRows = is_array($numbers['data'] ?? null) ? $numbers['data'] : [];
        if ($phoneRows === []) throw new RuntimeException('No WhatsApp phone numbers were returned for a selected account.');

        // Subscribe the app before marking the channel as connected.
        chatbridge_meta_graph_request('POST', $businessAccountId . '/subscribed_apps', $accessToken);

        foreach ($phoneRows as $phone) {
            if (!is_array($phone)) continue;
            $phoneNumberId = (string)($phone['id'] ?? '');
            if (!preg_match('/^\d{5,40}$/', $phoneNumberId)) continue;
            $phoneDetails = chatbridge_meta_graph_request('GET', $phoneNumberId, $accessToken, ['fields' => 'id,is_on_biz_app,platform_type']);
            if ((string)($phoneDetails['id'] ?? '') !== $phoneNumberId) {
                throw new RuntimeException('Meta phone-number verification returned an unexpected ID.');
            }
            $phoneIsOnBizApp = array_key_exists('is_on_biz_app', $phoneDetails)
                ? !empty($phoneDetails['is_on_biz_app'])
                : $isOnBizApp;
            $phoneConnectionMode = $phoneIsOnBizApp ? 'coexistence' : 'standard';

            $existing = $pdo->prepare('SELECT tenant_id, id FROM channel_connections WHERE provider = "meta" AND phone_number_id = :phone_id LIMIT 1');
            $existing->execute([':phone_id' => $phoneNumberId]);
            $existingRow = $existing->fetch();
            if (is_array($existingRow) && !hash_equals((string)$existingRow['tenant_id'], $tenantId)) {
                throw new RuntimeException('This Meta phone number is already connected to another tenant.');
            }

            $connectionId = is_array($existingRow) ? (string)$existingRow['id'] : uuid_v4();
            $displayNumber = mb_substr_safe((string)($phone['display_phone_number'] ?? ''), 0, 64);
            $upsert = $pdo->prepare(
                'INSERT INTO channel_connections
                    (id, tenant_id, provider, business_account_id, phone_number_id, display_phone_number,
                     connection_status, connection_mode, is_on_biz_app, credential_ciphertext, credential_key_version,
                     token_expires_at, connected_at, created_at, updated_at)
                 VALUES
                    (:id, :tenant_id, "meta", :business_account_id, :phone_number_id, :display_phone_number,
                     "connected", :connection_mode, :is_on_biz_app, :credential, "v1",
                     :expires_at, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE
                    business_account_id = VALUES(business_account_id),
                    display_phone_number = VALUES(display_phone_number),
                    connection_status = "connected",
                    connection_mode = VALUES(connection_mode),
                    is_on_biz_app = VALUES(is_on_biz_app),
                    credential_ciphertext = VALUES(credential_ciphertext),
                    credential_key_version = "v1",
                    token_expires_at = VALUES(token_expires_at),
                    last_error_code = NULL,
                    last_error_message = NULL,
                    connected_at = UTC_TIMESTAMP(),
                    updated_at = UTC_TIMESTAMP()'
            );
            $upsert->execute([
                ':id' => $connectionId,
                ':tenant_id' => $tenantId,
                ':business_account_id' => $businessAccountId,
                ':phone_number_id' => $phoneNumberId,
                ':display_phone_number' => $displayNumber,
                ':connection_mode' => $phoneConnectionMode,
                ':is_on_biz_app' => $phoneIsOnBizApp ? 1 : 0,
                ':credential' => $encryptedToken,
                ':expires_at' => $expiresAt,
            ]);

            $event = $pdo->prepare(
                'INSERT INTO channel_connection_events (tenant_id, channel_connection_id, event_type, event_sha256, created_at)
                 VALUES (:tenant_id, :connection_id, "embedded_signup_connected", :event_hash, UTC_TIMESTAMP())'
            );
            $event->execute([
                ':tenant_id' => $tenantId,
                ':connection_id' => $connectionId,
                ':event_hash' => hash('sha256', $tenantId . ':' . $phoneNumberId . ':connected'),
            ]);
            $connected[] = [
                'phone_number_id' => $phoneNumberId,
                'display_phone_number' => $displayNumber,
                'business_account_id' => $businessAccountId,
                'connection_mode' => $phoneConnectionMode,
            ];
        }
    }

    if ($connected === []) throw new RuntimeException('Meta signup completed but no usable phone number was provisioned.');
    $modes = array_values(array_unique(array_column($connected, 'connection_mode')));
    return ['connections' => $connected, 'connection_mode' => count($modes) === 1 ? $modes[0] : 'mixed'];
}

function resolve_meta_tenant_by_waba(PDO $pdo, string $businessAccountId): ?string
{
    if ($businessAccountId === '') return null;
    $query = $pdo->prepare('SELECT tenant_id FROM channel_connections WHERE provider = "meta" AND business_account_id = :waba LIMIT 1');
    $query->execute([':waba' => $businessAccountId]);
    $tenantId = $query->fetchColumn();
    return is_string($tenantId) && $tenantId !== '' ? $tenantId : null;
}
