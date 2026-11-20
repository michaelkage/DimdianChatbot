<?php
declare(strict_types=1);

/**
 * Server-only Supabase adapter for the mock/reference environment.
 * The widget never receives the Supabase service-role credential.
 */

function chatbridge_store_driver(): string
{
    return strtolower((string)(app_config('chat_store.driver') ?? 'mysql'));
}

function chatbridge_supabase_config(): array
{
    $settings = app_config('supabase');
    if (!is_array($settings)) {
        throw new RuntimeException('Supabase storage is not configured.');
    }
    $url = rtrim((string)($settings['url'] ?? ''), '/');
    $key = trim((string)($settings['service_role_key'] ?? ''));
    if (!preg_match('#^https://[a-z0-9.-]+$#i', $url) || $key === '') {
        throw new RuntimeException('Supabase URL and server-side service-role key are required.');
    }
    return ['url' => $url, 'key' => $key];
}

/**
 * Call the Supabase REST or Storage API from the PHP server.
 * Paths must be application-authored; never accept an arbitrary URL from a request.
 */
function chatbridge_supabase_request(string $method, string $path, ?array $payload = null, array $extraHeaders = []): mixed
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('The PHP cURL extension is required for the Supabase adapter.');
    }
    $config = chatbridge_supabase_config();
    if ($path === '' || $path[0] !== '/' || str_contains($path, '://')) {
        throw new InvalidArgumentException('Invalid Supabase API path.');
    }

    $headers = [
        'apikey: ' . $config['key'],
        'Authorization: Bearer ' . $config['key'],
        'Accept: application/json',
    ];
    foreach ($extraHeaders as $header) {
        if (is_string($header) && $header !== '') {
            $headers[] = $header;
        }
    }
    $body = $payload === null ? null : json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
    }

    $handle = curl_init($config['url'] . $path);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    if ($body !== null) {
        curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
    }
    $response = curl_exec($handle);
    $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($handle);
    curl_close($handle);

    if (!is_string($response)) {
        throw new RuntimeException('Supabase request failed: ' . ($curlError !== '' ? $curlError : 'unknown transport error'));
    }
    if ($status < 200 || $status >= 300) {
        error_log('[ChatBridge] Supabase request failed with HTTP ' . $status . ': ' . mb_substr($response, 0, 500));
        $error = new RuntimeException('Supabase request failed.');
        $error->supabaseStatus = $status;
        $error->supabaseBody = $response;
        throw $error;
    }
    if ($response === '') {
        return [];
    }
    $decoded = json_decode($response, true);
    return $decoded;
}

function chatbridge_supabase_resolve_tenant_from_origin(string $origin): ?string
{
    $normalized = normalize_request_origin($origin);
    if ($normalized === null) {
        return null;
    }
    $query = http_build_query([
        'select' => 'tenant_id',
        'origin' => 'eq.' . $normalized,
        'status' => 'eq.verified',
        'limit' => '1',
    ], '', '&', PHP_QUERY_RFC3986);
    $origins = chatbridge_supabase_request('GET', '/rest/v1/chatbridge_site_origins?' . $query);
    if (!isset($origins[0]['tenant_id']) || !is_string($origins[0]['tenant_id'])) {
        return null;
    }
    $tenantId = $origins[0]['tenant_id'];
    $tenantQuery = http_build_query([
        'select' => 'id',
        'id' => 'eq.' . $tenantId,
        'status' => 'eq.active',
        'limit' => '1',
    ], '', '&', PHP_QUERY_RFC3986);
    $tenants = chatbridge_supabase_request('GET', '/rest/v1/chatbridge_tenants?' . $tenantQuery);
    return isset($tenants[0]['id']) ? (string)$tenants[0]['id'] : null;
}

function chatbridge_supabase_request_tenant_id(): string
{
    $origin = normalize_request_origin(request_origin());
    if ($origin === null) {
        json_response(['error' => ['code' => 'tenant_origin_required', 'message' => 'A registered website origin is required.']], 403);
    }
    $tenantId = chatbridge_supabase_resolve_tenant_from_origin($origin);
    if ($tenantId === null) {
        json_response(['error' => ['code' => 'tenant_not_registered', 'message' => 'This website is not registered with the chat service.']], 403);
    }
    return $tenantId;
}

function chatbridge_supabase_take_rate_limit(string $tenantId, string $bucket, int $maxRequests, int $windowSeconds): void
{
    $bucketKey = hash('sha256', $bucket);
    $result = chatbridge_supabase_request('POST', '/rest/v1/rpc/chatbridge_take_rate_limit', [
        'p_bucket_key' => $bucketKey,
        'p_tenant_id' => $tenantId,
        'p_max_requests' => max(1, $maxRequests),
        'p_window_seconds' => max(1, $windowSeconds),
    ]);
    if (($result[0] ?? $result) === false) {
        header('Retry-After: ' . max(1, $windowSeconds));
        json_response(['error' => ['code' => 'rate_limited', 'message' => 'Too many attempts. Please wait a little and try again.']], 429);
    }
}

function chatbridge_supabase_create_session(?string $name, ?string $phone, ?string $normalizedPhone, bool $whatsappOptIn): array
{
    $tenantId = chatbridge_supabase_request_tenant_id();
    $rateConfig = app_config('app.session_rate_limit') ?? [];
    chatbridge_supabase_take_rate_limit(
        $tenantId,
        'session:' . $tenantId . ':' . client_fingerprint(),
        max(1, (int)($rateConfig['max_requests'] ?? 10)),
        max(60, (int)($rateConfig['window_seconds'] ?? 900))
    );

    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $ttlHours = max(1, min(720, (int)(app_config('app.guest_session_ttl_hours') ?? 168)));
    $createdAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $expiresAt = $createdAt->modify('+' . $ttlHours . ' hours');
    $result = chatbridge_supabase_request('POST', '/rest/v1/rpc/chatbridge_create_guest_session', [
        'p_tenant_id' => $tenantId,
        'p_name' => $name,
        'p_phone' => $phone,
        'p_phone_normalized' => $normalizedPhone,
        'p_whatsapp_opt_in' => $whatsappOptIn,
        'p_token_hash' => $tokenHash,
        'p_expires_at' => $expiresAt->format(DateTimeInterface::ATOM),
    ]);
    $data = $result[0] ?? $result;
    if (!is_array($data) || empty($data['session_id']) || empty($data['conversation_id'])) {
        throw new RuntimeException('Supabase did not create a complete guest session.');
    }
    return [
        'session_id' => $data['session_id'],
        'conversation_id' => $data['conversation_id'],
        'session_token' => $token,
        'expires_at' => $expiresAt->format(DateTimeInterface::ATOM),
        'created_at' => $createdAt->format(DateTimeInterface::ATOM),
    ];
}

function chatbridge_supabase_authenticate_guest(string $tenantId, bool $requireOpen = true): array
{
    $token = $_SERVER['HTTP_X_DIMDIAN_GUEST_TOKEN'] ?? '';
    if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        json_response(['error' => ['code' => 'unauthorized', 'message' => 'A valid chat session token is required.']], 401);
    }
    try {
        $result = chatbridge_supabase_request('POST', '/rest/v1/rpc/chatbridge_auth_guest_session', [
            'p_tenant_id' => $tenantId,
            'p_token_hash' => hash('sha256', $token),
            'p_require_open' => $requireOpen,
        ]);
    } catch (Throwable $error) {
        $body = (string)($error->supabaseBody ?? '');
        if (str_contains($body, 'session_expired')) {
            json_response(['error' => ['code' => 'session_expired', 'message' => 'This chat session is no longer valid. Start a new chat to continue.']], 401);
        }
        if (str_contains($body, 'conversation_closed')) {
            json_response(['error' => ['code' => 'conversation_closed', 'message' => 'This conversation has been closed. Start a new chat to send another message.']], 409);
        }
        throw $error;
    }
    $session = $result[0] ?? $result;
    if (!is_array($session) || empty($session['session_id'])) {
        json_response(['error' => ['code' => 'session_expired', 'message' => 'This chat session is no longer valid. Start a new chat to continue.']], 401);
    }
    return $session;
}

function chatbridge_supabase_messages_api(string $method): never
{
    $tenantId = chatbridge_supabase_request_tenant_id();
    if ($method === 'POST') {
        $contentType = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
        if ($contentType !== 'application/json') {
            json_response(['error' => ['code' => 'unsupported_media_type', 'message' => 'Content-Type must be application/json.']], 415);
        }
        $body = request_json(20000);
        $message = clean_optional_string($body['message'] ?? null, 4000, 'message');
        if ($message === null) {
            json_response(['error' => ['code' => 'message_required', 'message' => 'Write a message before sending.']], 422);
        }
        $session = chatbridge_supabase_authenticate_guest($tenantId, true);
        $rateConfig = app_config('app.message_rate_limit') ?? [];
        chatbridge_supabase_take_rate_limit(
            $tenantId,
            'message:' . $tenantId . ':' . $session['session_id'],
            max(1, (int)($rateConfig['max_requests'] ?? 8)),
            max(60, (int)($rateConfig['window_seconds'] ?? 60))
        );
        $clientMessageId = $body['client_message_id'] ?? uuid_v4();
        if (!is_string($clientMessageId) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $clientMessageId)) {
            json_response(['error' => ['code' => 'invalid_idempotency_key', 'message' => 'client_message_id must be a UUID.']], 422);
        }
        try {
            $result = chatbridge_supabase_request('POST', '/rest/v1/rpc/chatbridge_append_message', [
                'p_tenant_id' => $tenantId,
                'p_conversation_id' => $session['conversation_id'],
                'p_client_message_id' => $clientMessageId,
                'p_body' => $message,
            ]);
        } catch (Throwable $error) {
            $responseBody = (string)($error->supabaseBody ?? '');
            if (str_contains($responseBody, 'idempotency_conflict')) {
                json_response(['error' => ['code' => 'idempotency_conflict', 'message' => 'This message ID was already used for different text.']], 409);
            }
            if (str_contains($responseBody, 'conversation_closed_or_missing')) {
                json_response(['error' => ['code' => 'conversation_closed', 'message' => 'This conversation has been closed. Start a new chat to send another message.']], 409);
            }
            throw $error;
        }
        $saved = $result[0] ?? $result;
        if (!is_array($saved) || empty($saved['id'])) {
            throw new RuntimeException('Supabase did not return the saved message.');
        }
        $inserted = !empty($saved['inserted']);
        unset($saved['inserted']);
        json_response([
            'data' => $saved,
            'meta' => [
                'transport' => $inserted ? 'website_chat_only' : 'replayed',
                'idempotent_replay' => !$inserted,
                'note' => 'Your message is saved in this website chat. WhatsApp delivery is not enabled for this mock database.',
            ],
        ], $inserted ? 201 : 200);
    }

    if ($method !== 'GET') {
        header('Allow: GET, POST, OPTIONS');
        json_response(['error' => ['code' => 'method_not_allowed', 'message' => 'Use GET to retrieve messages or POST to send one.']], 405);
    }
    $session = chatbridge_supabase_authenticate_guest($tenantId, false);
    $afterRaw = $_GET['after'] ?? '0';
    if (!is_string($afterRaw) || !preg_match('/^\d{1,18}$/', $afterRaw)) {
        json_response(['error' => ['code' => 'invalid_cursor', 'message' => 'The after cursor must be a non-negative integer.']], 422);
    }
    $limitRaw = $_GET['limit'] ?? '50';
    if (!is_string($limitRaw) || !preg_match('/^\d{1,3}$/', $limitRaw)) {
        json_response(['error' => ['code' => 'invalid_limit', 'message' => 'The limit must be an integer between 1 and 100.']], 422);
    }
    $after = (int)$afterRaw;
    $limit = max(1, min(100, (int)$limitRaw));
    $query = http_build_query([
        'select' => 'id,sequence_id,sender_type,body,delivery_status,created_at',
        'tenant_id' => 'eq.' . $tenantId,
        'conversation_id' => 'eq.' . $session['conversation_id'],
        'sequence_id' => 'gt.' . $after,
        'order' => 'sequence_id.asc',
        'limit' => (string)($limit + 1),
    ], '', '&', PHP_QUERY_RFC3986);
    $rows = chatbridge_supabase_request('GET', '/rest/v1/chatbridge_messages?' . $query);
    $hasMore = count($rows) > $limit;
    if ($hasMore) {
        $rows = array_slice($rows, 0, $limit);
    }
    $items = array_map(static function (array $row): array {
        return [
            'id' => $row['id'],
            'sequence_id' => (int)$row['sequence_id'],
            'sender' => $row['sender_type'],
            'message' => $row['body'],
            'delivery_status' => $row['delivery_status'],
            'created_at' => (new DateTimeImmutable((string)$row['created_at']))->format(DateTimeInterface::ATOM),
        ];
    }, $rows);
    $nextCursor = $items === [] ? $after : $items[array_key_last($items)]['sequence_id'];
    json_response([
        'data' => $items,
        'meta' => [
            'handoff' => null,
            'after' => $after,
            'next_cursor' => $nextCursor,
            'has_more' => $hasMore,
            'poll_after_ms' => 3000,
            'delivery_updates' => [],
        ],
    ]);
}
