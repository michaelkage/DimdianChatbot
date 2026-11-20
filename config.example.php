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
        // For a migrated single-tenant deployment, use its tenant UUID. Hosted
        // multi-tenant API requests resolve tenant ownership from tenant_sites.
        'tenant_id' => '00000000-0000-4000-8000-000000000001',
        // Generate once with: php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
        // Keep this key outside the database and back it up securely.
        'encryption_key' => 'REPLACE_WITH_64_HEX_CHARACTERS',
        'guest_session_ttl_hours' => 168,
        'session_rate_limit' => [
            'max_requests' => 10,
            'window_seconds' => 900,
        ],
    ],
    'meta' => [
        // Keep false until Meta has approved the app and the Tech Provider /
        // Solution Partner prerequisites are complete.
        'enabled' => false,
        'app_id' => '',
        'app_secret' => '',
        'configuration_id' => '',
        'solution_id' => '',
        'graph_api_version' => 'v27.0',
    ],
    'whatsapp' => [
        // Keep disabled until the business number and Coexistence behavior are verified.
        'outbound_enabled' => false,
        'graph_api_version' => 'v27.0',
        'phone_number_id' => '',
        'access_token' => '',
        'app_secret' => '',
        'verify_token' => '',
        // Create and approve a WhatsApp template with exactly one BODY text parameter.
        'template_name' => '',
        'template_language' => 'en',
    ],
    'database' => [
        'dsn' => 'mysql:host=localhost;dbname=dimdian_chatbot;charset=utf8mb4',
        'username' => 'database_user',
        'password' => 'change-this',
        'options' => [],
    ],
    // Choose one authoritative conversation store per deployment.
    // Keep mysql for self-hosted MySQL/MariaDB; use supabase for the mock environment.
    'chat_store' => [
        'driver' => 'mysql',
    ],
    'supabase' => [
        'url' => 'https://YOUR_PROJECT_REF.supabase.co',
        // Server-only service_role key. Never expose this to the widget or WordPress.
        'service_role_key' => '',
    ],
];
