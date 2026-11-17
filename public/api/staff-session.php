<?php
declare(strict_types=1);
require_once dirname(__DIR__,2) . '/app/staff.php';
require_method('GET','POST');
start_staff_session();
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
 $staff = $_SESSION['staff'] ?? null;
 if (!is_array($staff)) json_response(['authenticated'=>false]);
 json_response(['authenticated'=>true,'staff'=>$staff,'csrf_token'=>$_SESSION['csrf_token'] ?? null]);
}
require_staff_csrf();
$_SESSION = [];
if (ini_get('session.use_cookies')) {
 $params = session_get_cookie_params();
 setcookie(session_name(),'',[
  'expires'=>time()-42000,'path'=>$params['path'],'domain'=>$params['domain'],
  'secure'=>$params['secure'],'httponly'=>$params['httponly'],'samesite'=>'Strict',
 ]);
}
session_destroy();
json_response(['authenticated'=>false]);
