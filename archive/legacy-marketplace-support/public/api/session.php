<?php
declare(strict_types=1);
require_once dirname(__DIR__,2) . '/app/customer.php';
require_method('POST');
$input = request_json();
$externalUserId = verified_external_user_id($input);

$token = $_SERVER['HTTP_X_DIMDIAN_GUEST_TOKEN'] ?? '';
if (is_string($token) && preg_match('/^[a-f0-9]{64}$/D',$token)) {
 $digest = hash('sha256',$token);
 $find = db()->prepare('SELECT id,display_name,email FROM customers WHERE guest_token_hash = :digest LIMIT 1');
 $find->execute(['digest'=>$digest]);
 $customer = $find->fetch();
 if ($customer) {
  db()->prepare('UPDATE customers SET external_user_id=:external_user_id,last_seen_at=UTC_TIMESTAMP() WHERE id=:id')->execute(['external_user_id'=>$externalUserId,'id'=>$customer['id']]);
  json_response(['token'=>$token,'customer'=>['id'=>(int)$customer['id'],'display_name'=>$customer['display_name']]]);
 }
}

if (rate_limit_exceeded('guest-session-ip:'.request_ip_hash(),40,3600)) json_response(['error'=>'too_many_sessions'],429);
$bytes = 32; // Token format is fixed at 64 lowercase hexadecimal characters.
$token = bin2hex(random_bytes($bytes));
$digest = hash('sha256',$token);
$insert = db()->prepare('INSERT INTO customers (guest_token_hash,external_user_id,created_at,last_seen_at) VALUES (:digest,:external_user_id,UTC_TIMESTAMP(),UTC_TIMESTAMP())');
$insert->execute(['digest'=>$digest,'external_user_id'=>$externalUserId]);
json_response(['token'=>$token,'customer'=>['id'=>(int)db()->lastInsertId(),'display_name'=>null]],201);
