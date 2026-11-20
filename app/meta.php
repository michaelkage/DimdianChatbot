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
        $headers .= "Content-Type: application/json\r\n";
        $content = $parameters === [] ? '' : (string)json_encode($parameters, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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

function chatbridge_meta_audit_event(PDO $pdo, string $tenantId, ?string $connectionId, string $eventType, string $identity): void
{
    $insert = $pdo->prepare(
        'INSERT INTO channel_connection_events (tenant_id, channel_connection_id, event_type, event_sha256, created_at)
         VALUES (:tenant_id, :connection_id, :event_type, :event_hash, UTC_TIMESTAMP())'
    );
    $insert->execute([
        ':tenant_id' => $tenantId,
        ':connection_id' => $connectionId,
        ':event_type' => substr($eventType, 0, 100),
        ':event_hash' => hash('sha256', $tenantId . ':' . $eventType . ':' . $identity),
    ]);
}

function complete_meta_embedded_signup(PDO $pdo, string $tenantId, string $code, array $sessionInfo): array
{
    if ($code === '' || strlen($code) > 10000) throw new InvalidArgumentException('The Meta authorization code is invalid.');

    // v5 distinguishes Messaging Account IDs (legacy WABA/PMA IDs) from
    // WhatsApp Account IDs (WAACs that hold phone numbers).
    $messagingIds = $sessionInfo['messaging_account_ids'] ?? $sessionInfo['waba_id'] ?? [];
    if (is_string($messagingIds)) $messagingIds = [$messagingIds];
    if (!is_array($messagingIds)) $messagingIds = [];
    $messagingIds = array_values(array_unique(array_filter(array_map('strval', $messagingIds), static fn(string $id): bool => preg_match('/^\d{5,40}$/', $id) === 1)));
    if ($messagingIds === []) throw new InvalidArgumentException('The v5 signup event did not include a valid messaging account ID.');

    $waacIds = $sessionInfo['primary_waac_ids'] ?? [];
    if (is_string($waacIds)) $waacIds = [$waacIds];
    if (!is_array($waacIds)) $waacIds = [];
    $waacIds = array_values(array_unique(array_filter(array_map('strval', $waacIds), static fn(string $id): bool => preg_match('/^\d{5,40}$/', $id) === 1)));

    $sessionPhoneIds = $sessionInfo['phone_number_ids'] ?? $sessionInfo['phone_number_id'] ?? [];
    if (is_string($sessionPhoneIds)) $sessionPhoneIds = [$sessionPhoneIds];
    if (!is_array($sessionPhoneIds)) $sessionPhoneIds = [];
    $sessionPhoneIds = array_values(array_unique(array_filter(array_map('strval', $sessionPhoneIds), static fn(string $id): bool => preg_match('/^\d{5,40}$/', $id) === 1)));

    $isOnBizApp = !empty($sessionInfo['is_on_biz_app']);
    $portfolioId = (string)($sessionInfo['business_id'] ?? '');
    if ($portfolioId !== '' && !preg_match('/^\d{5,40}$/', $portfolioId)) $portfolioId = '';

    $tokenResponse = chatbridge_meta_exchange_code($code);
    $accessToken = (string)$tokenResponse['access_token'];
    chatbridge_meta_audit_event($pdo, $tenantId, null, 'authorization_code_exchanged', hash('sha256', $code));
    $encryptedToken = chatbridge_encrypt_secret($accessToken);
    $expiresAt = null;
    if (isset($tokenResponse['expires_in']) && is_numeric($tokenResponse['expires_in']) && (int)$tokenResponse['expires_in'] > 0) {
        $expiresAt = gmdate('Y-m-d H:i:s', time() + (int)$tokenResponse['expires_in']);
    }

    $connected = [];
    foreach ($messagingIds as $index => $messagingAccountId) {
        $waacId = $waacIds[$index] ?? ($waacIds[0] ?? $messagingAccountId);

        $tenantConflict = $pdo->prepare(
            'SELECT id FROM channel_connections
             WHERE provider = "meta"
               AND (business_account_id = :messaging_id OR whatsapp_account_id = :waac_id)
               AND tenant_id <> :tenant_id LIMIT 1'
        );
        $tenantConflict->execute([
            ':messaging_id' => $messagingAccountId,
            ':waac_id' => $waacId,
            ':tenant_id' => $tenantId,
        ]);
        if ($tenantConflict->fetchColumn()) {
            throw new RuntimeException('This Meta account is already connected to another tenant.');
        }

        // Messaging Account IDs retain the old WABA ID. Verify the resource
        // before subscribing the app to its webhook events.
        $account = chatbridge_meta_graph_request('GET', $messagingAccountId, $accessToken, ['fields' => 'id,name']);
        if ((string)($account['id'] ?? '') !== $messagingAccountId) {
            throw new RuntimeException('Meta returned an unexpected Messaging Account ID.');
        }
        chatbridge_meta_audit_event($pdo, $tenantId, null, 'messaging_account_discovered', $messagingAccountId);

        $phoneRows = [];
        if (count($sessionPhoneIds) === count($messagingIds) && isset($sessionPhoneIds[$index])) {
            $phoneRows = [['id' => $sessionPhoneIds[$index]]];
        } elseif (count($messagingIds) === 1 && count($sessionPhoneIds) === 1) {
            $phoneRows = [['id' => $sessionPhoneIds[0]]];
        } else {
            // Prefer the WAAC edge in the evolved account model. Older and
            // compatibility flows may still expose phone_numbers on the PMA/WABA.
            try {
                $numbers = chatbridge_meta_graph_request('GET', $waacId . '/phone_numbers', $accessToken, [
                    'fields' => 'id,display_phone_number,verified_name,quality_rating,platform_type',
                    'limit' => 100,
                ]);
            } catch (Throwable $waacError) {
                $numbers = chatbridge_meta_graph_request('GET', $messagingAccountId . '/phone_numbers', $accessToken, [
                    'fields' => 'id,display_phone_number,verified_name,quality_rating,platform_type',
                    'limit' => 100,
                ]);
            }
            $phoneRows = is_array($numbers['data'] ?? null) ? $numbers['data'] : [];
        }
        if ($phoneRows === []) throw new RuntimeException('No WhatsApp phone numbers were returned for a selected account.');

        // Subscribe at the Messaging Account (legacy WABA/PMA) level.
        chatbridge_meta_graph_request('POST', $messagingAccountId . '/subscribed_apps', $accessToken);
        chatbridge_meta_audit_event($pdo, $tenantId, null, 'webhook_subscribed', $messagingAccountId);

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
            $platformType = strtoupper((string)($phoneDetails['platform_type'] ?? $phone['platform_type'] ?? ''));
            if ($platformType !== '' && $platformType !== 'CLOUD_API') {
                throw new RuntimeException('The selected phone number is not registered on WhatsApp Cloud API.');
            }
            $phoneConnectionMode = $phoneIsOnBizApp ? 'coexistence' : 'standard';
            chatbridge_meta_audit_event($pdo, $tenantId, null, 'phone_number_verified', $phoneNumberId);

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
                    (id, tenant_id, provider, business_account_id, whatsapp_account_id, business_portfolio_id,
                     phone_number_id, display_phone_number, connection_status, connection_mode, is_on_biz_app,
                     credential_ciphertext, credential_key_version, token_expires_at, connected_at, created_at, updated_at)
                 VALUES
                    (:id, :tenant_id, "meta", :messaging_id, :waac_id, :portfolio_id,
                     :phone_number_id, :display_phone_number, "connected", :connection_mode, :is_on_biz_app,
                     :credential, "v1", :expires_at, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE
                    business_account_id = VALUES(business_account_id),
                    whatsapp_account_id = VALUES(whatsapp_account_id),
                    business_portfolio_id = VALUES(business_portfolio_id),
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
                ':messaging_id' => $messagingAccountId,
                ':waac_id' => $waacId,
                ':portfolio_id' => $portfolioId !== '' ? $portfolioId : null,
                ':phone_number_id' => $phoneNumberId,
                ':display_phone_number' => $displayNumber,
                ':connection_mode' => $phoneConnectionMode,
                ':is_on_biz_app' => $phoneIsOnBizApp ? 1 : 0,
                ':credential' => $encryptedToken,
                ':expires_at' => $expiresAt,
            ]);

            chatbridge_meta_audit_event($pdo, $tenantId, $connectionId, 'connected', $phoneNumberId);
            if ($phoneConnectionMode === 'coexistence') {
                $syncInsert = $pdo->prepare(
                    'INSERT IGNORE INTO channel_sync_jobs
                        (id, tenant_id, channel_connection_id, sync_type, status, created_at, updated_at)
                     VALUES (:id, :tenant_id, :connection_id, :sync_type, "pending", UTC_TIMESTAMP(), UTC_TIMESTAMP())'
                );
                foreach (['smb_app_state_sync', 'history'] as $syncType) {
                    $syncInsert->execute([
                        ':id' => uuid_v4(),
                        ':tenant_id' => $tenantId,
                        ':connection_id' => $connectionId,
                        ':sync_type' => $syncType,
                    ]);
                }
            }
            $connected[] = [
                'phone_number_id' => $phoneNumberId,
                'display_phone_number' => $displayNumber,
                'business_account_id' => $messagingAccountId,
                'whatsapp_account_id' => $waacId,
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
    $query = $pdo->prepare('SELECT tenant_id FROM channel_connections WHERE provider = "meta" AND (business_account_id = :messaging_id OR whatsapp_account_id = :waac_id) LIMIT 1');
    $query->execute([':messaging_id' => $businessAccountId, ':waac_id' => $businessAccountId]);
    $tenantId = $query->fetchColumn();
    return is_string($tenantId) && $tenantId !== '' ? $tenantId : null;
}
