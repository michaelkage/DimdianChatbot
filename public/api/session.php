<?php
declare(strict_types=1);
require_once dirname(__DIR__,2) . '/app/bootstrap.php';
require_method('POST');

$token = $_SERVER['HTTP_X_DIMDIAN_GUEST_TOKEN'] ?? '';
if (is_string($token) && preg_match('/^[a-f0-9]{64}$/D',$token)) {
 $digest = hash('sha256',$token);
 $find = db()->prepare('SELECT id,display_name,email FROM customers WHERE guest_token_hash = :digest LIMIT 1');
 $find->execute(['digest'=>$digest]);
 $customer = $find->fetch();
 if ($customer) {
  db()->prepare('UPDATE customers SET last_seen_at = UTC_TIMESTAMP() WHERE id = :id')->execute(['id'=>$customer['id']]);
  json_response(['token'=>$token,'customer'=>['id'=>(int)$customer['id'],'display_name'=>$customer['display_name']]]);
 }
}

$bytes = max(32,(int)app_config('security.guest_token_bytes',32));
$token = bin2hex(random_bytes($bytes));
$digest = hash('sha256',$token);
$insert = db()->prepare('INSERT INTO customers (guest_token_hash,created_at,last_seen_at) VALUES (:digest,UTC_TIMESTAMP(),UTC_TIMESTAMP())');
$insert->execute(['digest'=>$digest]);
json_response(['token'=>$token,'customer'=>['id'=>(int)db()->lastInsertId(),'display_name'=>null]],201);
