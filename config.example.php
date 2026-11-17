<?php
declare(strict_types=1);

/** Copy to config.php. Keep secrets outside the public document root. */
return [
 'app' => [
  'environment' => 'production',
  'base_url' => 'https://example.com',
  'timezone' => 'UTC',
  'allowed_origins' => ['https://example.com','https://www.example.com'],
  'session_name' => 'dimdian_staff',
  'secure_cookies' => true,
 ],
 'database' => [
  'host' => 'localhost', 'port' => 3306, 'name' => 'your_database',
  'username' => 'your_database_user', 'password' => 'replace-this', 'charset' => 'utf8mb4',
 ],
 'uploads' => [
  'directory' => dirname(__DIR__) . '/dimdian-private-uploads',
  'max_bytes' => 3 * 1024 * 1024,
  'allowed_mime_types' => ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'],
 ],
 'ai' => [
  // Set true only after the selected provider is configured and tested.
  'provider_ready' => false,
  // Supported names: groq, gemini, openai_compatible.
  'provider' => [
   'name' => 'groq',
   'api_key' => '',
   'model' => '',
   // Used only by openai_compatible; use HTTPS in production.
   'base_url' => '',
   'connect_timeout_seconds' => 3,
   'timeout_seconds' => 12,
   'max_tokens' => 350,
  ],
 ],
 'integration' => [
  // Optional shared secret for signed marketplace-account identity linking.
  'customer_identity_secret' => 'replace-with-a-separate-random-secret',
 ],
 'security' => [
  'guest_token_bytes' => 32, 'message_max_chars' => 5000, 'poll_limit' => 100,
  'rate_limit_secret' => 'replace-with-a-long-random-secret',
 ],
];
