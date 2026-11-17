<?php
declare(strict_types=1);

/*
 * Copy this file to config.php and fill in the values.
 * Keep config.php outside version control. On shared hosting, place it
 * outside public/ where possible and adjust the path in app/bootstrap.php.
 */
return [
    'app' => [
        'environment' => 'production',
        'app_key' => 'replace-with-at-least-32-random-bytes',
        'timezone' => 'Africa/Lagos',
        'public_base_url' => 'https://chat.example.com',
        'allowed_origins' => [
            'https://www.example.com',
            'https://example.com',
        ],
        'guest_session_ttl_hours' => 168,
        'session_rate_limit' => [
            'max_requests' => 10,
            'window_seconds' => 900,
        ],
    ],
    'whatsapp' => [
        // Keep disabled until the business number and Coexistence behavior are verified.
        'outbound_enabled' => false,
        'graph_api_version' => 'vXX.X',
        'phone_number_id' => '',
        'access_token' => '',
        'app_secret' => '',
        'verify_token' => '',
        // A separate staff WhatsApp number, not the business sender number.
        'notification_recipient' => '',
    ],
    'database' => [
        'dsn' => 'mysql:host=localhost;dbname=dimdian_chatbot;charset=utf8mb4',
        'username' => 'database_user',
        'password' => 'change-this',
        'options' => [],
    ],
];
