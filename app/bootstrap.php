<?php
declare(strict_types=1);

/** Shared bootstrap for API and admin entry points. Requires PHP 8.2+, PDO MySQL and fileinfo. */
$configPath = dirname(__DIR__) . '/config.php';
if (!is_file($configPath)) {
 http_response_code(503);
 header('Content-Type: application/json; charset=utf-8');
 echo json_encode(['error'=>'application_not_configured']);
 exit;
}
$config = require $configPath;
date_default_timezone_set($config['app']['timezone'] ?? 'UTC');
if (($config['app']['environment'] ?? 'production') === 'production') ini_set('display_errors','0');


set_exception_handler(static function (Throwable $error): void {
 error_log('[DimdianChatbot] Unhandled exception: '.$error->getMessage());
 if (!headers_sent()) {
  http_response_code(500);
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store, private');
 }
 echo json_encode(['error'=>'internal_server_error']);
});

function app_config(?string $key = null): mixed {
 global $config;
 if ($key === null) return $config;
 $value = $config;
 foreach (explode('.', $key) as $segment) {
  if (!is_array($value) || !array_key_exists($segment,$value)) return null;
  $value = $value[$segment];
 }
 return $value;
}

function db(): PDO {
 static $pdo = null;
 if ($pdo instanceof PDO) return $pdo;
 $database = app_config('database');
 $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
  $database['host'],(int)($database['port'] ?? 3306),$database['name'],$database['charset'] ?? 'utf8mb4');
 $pdo = new PDO($dsn,$database['username'],$database['password'],[
  PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
  PDO::ATTR_EMULATE_PREPARES=>false,
  PDO::ATTR_STRINGIFY_FETCHES=>false,
 ]);
 return $pdo;
}

function json_response(array $payload,int $status=200): never {
 http_response_code($status);
 header('Content-Type: application/json; charset=utf-8');
 header('Cache-Control: no-store, private');
 header('X-Content-Type-Options: nosniff');
 echo json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
 exit;
}

function require_method(string ...$methods): void {
 $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
 if (!in_array($method,$methods,true)) {
  header('Allow: '.implode(', ',$methods));
  json_response(['error'=>'method_not_allowed'],405);
 }
}

function request_json(): array {
 $raw = file_get_contents('php://input');
 if ($raw === false || $raw === '') return [];
 try { $data = json_decode($raw,true,32,JSON_THROW_ON_ERROR); }
 catch (JsonException) { json_response(['error'=>'invalid_json'],400); }
 if (!is_array($data)) json_response(['error'=>'invalid_json_object'],400);
 return $data;
}

function request_ip_hash(): string {
 $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
 return hash_hmac('sha256',$ip,(string)app_config('security.rate_limit_secret','dimdian-local-fallback'));
}

function rate_limit_exceeded(string $scope,int $limit,int $windowSeconds): bool {
 $secret=(string)app_config('security.rate_limit_secret','');
 if($secret===''||str_starts_with($secret,'replace-with-')) throw new RuntimeException('rate_limit_secret_not_configured');
 $windowSeconds=max(1,$windowSeconds);
 $bucket=hash_hmac('sha256',$scope,$secret);
 $windowStart=intdiv(time(),$windowSeconds)*$windowSeconds;
 $pdo=db();
 $upsert=$pdo->prepare('INSERT INTO rate_limit_buckets (bucket_key,window_start,hit_count,updated_at) VALUES (:bucket,:window_start,1,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE hit_count=hit_count+1,updated_at=UTC_TIMESTAMP()');
 $upsert->execute(['bucket'=>$bucket,'window_start'=>$windowStart]);
 $count=$pdo->prepare('SELECT hit_count FROM rate_limit_buckets WHERE bucket_key=:bucket AND window_start=:window_start');
 $count->execute(['bucket'=>$bucket,'window_start'=>$windowStart]);
 return (int)$count->fetchColumn()>$limit;
}

function apply_cors(): void {
 $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
 $allowed = app_config('app.allowed_origins',[]);
 if ($origin !== '' && is_array($allowed) && in_array($origin,$allowed,true)) {
  header('Access-Control-Allow-Origin: '.$origin);
  header('Vary: Origin');
  header('Access-Control-Allow-Credentials: true');
 }
 header('Access-Control-Allow-Methods: GET, POST, PATCH, OPTIONS');
 header('Access-Control-Allow-Headers: Content-Type, X-Dimdian-Guest-Token, X-CSRF-Token');
 header('X-Content-Type-Options: nosniff');
}
apply_cors();
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
