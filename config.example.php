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
 'integration' => [
  // Optional shared secret for signed marketplace-account identity linking.
  'customer_identity_secret' => 'replace-with-a-separate-random-secret',
 ],
 'security' => [
  'guest_token_bytes' => 32, 'message_max_chars' => 5000, 'poll_limit' => 100,
  'rate_limit_secret' => 'replace-with-a-long-random-secret',
 ],
];
