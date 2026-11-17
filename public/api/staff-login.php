<?php
declare(strict_types=1);
require_once dirname(__DIR__,2) . '/app/staff.php';
require_method('POST');
$input = request_json();
$email = strtolower(trim((string)($input['email'] ?? '')));
$password = (string)($input['password'] ?? '');
if (!filter_var($email,FILTER_VALIDATE_EMAIL) || $password === '' || strlen($password) > 4096) json_response(['error'=>'invalid_credentials'],422);
$ipHash = request_ip_hash();
if (rate_limit_exceeded('staff-login:'.$ipHash.':'.$email,10,900)) json_response(['error'=>'too_many_attempts'],429);

$query = db()->prepare('SELECT id,email,password_hash,display_name,role,is_active FROM staff_users WHERE email=:email LIMIT 1');
$query->execute(['email'=>$email]);
$user = $query->fetch();
if (!$user || !(bool)$user['is_active'] || !password_verify($password,$user['password_hash'])) {
 usleep(250000);
 json_response(['error'=>'invalid_credentials'],401);
}
if (password_needs_rehash($user['password_hash'],PASSWORD_DEFAULT)) {
 db()->prepare('UPDATE staff_users SET password_hash=:hash WHERE id=:id')->execute(['hash'=>password_hash($password,PASSWORD_DEFAULT),'id'=>$user['id']]);
}
db()->prepare('UPDATE staff_users SET last_login_at=UTC_TIMESTAMP() WHERE id=:id')->execute(['id'=>$user['id']]);
start_staff_session();
session_regenerate_id(true);
$_SESSION['staff']=['id'=>(int)$user['id'],'email'=>$user['email'],'display_name'=>$user['display_name'],'role'=>$user['role']];
$_SESSION['csrf_token']=bin2hex(random_bytes(32));
json_response(['authenticated'=>true,'staff'=>$_SESSION['staff'],'csrf_token'=>$_SESSION['csrf_token']]);
