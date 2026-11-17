<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/app/bootstrap.php';

$email=strtolower(trim($argv[1]??''));
$name=trim($argv[2]??'');
$role=$argv[3]??'admin';
if(!filter_var($email,FILTER_VALIDATE_EMAIL)||$name===''||!in_array($role,['admin','agent'],true)){
 fwrite(STDERR,"Usage: php bin/create-admin.php email@example.com \"Display Name\" [admin|agent]\n");
 exit(2);
}
function read_secret(string $prompt): string {
 fwrite(STDOUT,$prompt);
 $hidden = PHP_OS_FAMILY !== 'Windows' && function_exists('shell_exec') && function_exists('stream_isatty') && stream_isatty(STDIN);
 if ($hidden) shell_exec('stty -echo');
 $value = fgets(STDIN) ?: '';
 if ($hidden) shell_exec('stty echo');
 fwrite(STDOUT,"\n");
 return rtrim($value,"\r\n");
}
$password=read_secret('New staff password (minimum 12 characters): ');
$confirm=read_secret('Confirm password: ');
if(strlen($password)<12||!hash_equals($password,$confirm)){
 fwrite(STDERR,"Password confirmation failed or password is too short.\n");exit(2);
}
$hash=password_hash($password,PASSWORD_DEFAULT);
try{
 $insert=db()->prepare('INSERT INTO staff_users (email,password_hash,display_name,`role`,is_active,created_at,updated_at) VALUES (:email,:hash,:name,:role,1,UTC_TIMESTAMP(),UTC_TIMESTAMP())');
 $insert->execute(['email'=>$email,'hash'=>$hash,'name'=>$name,'role'=>$role]);
 fwrite(STDOUT,"Staff account created: {$email} ({$role})\n");
}catch(PDOException $error){
 if((string)$error->getCode()==='23000')fwrite(STDERR,"A staff account with that email already exists.\n");
 else throw $error;
 exit(1);
}
