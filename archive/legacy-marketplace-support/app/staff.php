<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

function start_staff_session(): void {
 if (session_status() === PHP_SESSION_ACTIVE) return;
 session_name((string)app_config('app.session_name','dimdian_staff'));
 $secure = (bool)app_config('app.secure_cookies',true);
 ini_set('session.use_strict_mode','1');
 ini_set('session.use_only_cookies','1');
 session_set_cookie_params([
  'lifetime'=>0,'path'=>'/','secure'=>$secure,'httponly'=>true,'samesite'=>'Strict',
 ]);
 session_start();
}

function require_staff(): array {
 start_staff_session();
 $staff=$_SESSION['staff']??null;
 if(!is_array($staff)||!isset($staff['id'],$staff['role']))json_response(['error'=>'staff_auth_required'],401);
 $now=time();
 $authenticatedAt=(int)($_SESSION['authenticated_at']??$now);
 $lastActivity=(int)($_SESSION['last_activity']??$authenticatedAt);
 if($now-$lastActivity>8*3600||$now-$authenticatedAt>12*3600){
  $_SESSION=[];session_regenerate_id(true);
  json_response(['error'=>'staff_session_expired'],401);
 }
 $query=db()->prepare('SELECT id,email,display_name,`role`,is_active FROM staff_users WHERE id=:id LIMIT 1');
 $query->execute(['id'=>(int)$staff['id']]);$current=$query->fetch();
 if(!$current||(int)$current['is_active']!==1){
  $_SESSION=[];session_regenerate_id(true);
  json_response(['error'=>'staff_auth_required'],401);
 }
 $_SESSION['staff']=['id'=>(int)$current['id'],'email'=>$current['email'],'display_name'=>$current['display_name'],'role'=>$current['role']];
 $_SESSION['authenticated_at']=$authenticatedAt;
 $_SESSION['last_activity']=$now;
 return $_SESSION['staff'];
}

function require_staff_csrf(): void {
 start_staff_session();
 $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
 $expected = $_SESSION['csrf_token'] ?? '';
 if (!is_string($provided) || !is_string($expected) || $expected === '' || !hash_equals($expected,$provided)) {
  json_response(['error'=>'csrf_validation_failed'],403);
 }
}
