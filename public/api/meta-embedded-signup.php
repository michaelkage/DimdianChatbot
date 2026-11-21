<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/app/bootstrap.php';
require_once dirname(__DIR__,2).'/app/whatsapp.php';
require_once dirname(__DIR__,2).'/app/meta_signup.php';
require_method('POST');
if(!meta_signup_admin_authorized())json_response(['error'=>['code'=>'unauthorized','message'=>'Administrator authorization is required.']],401);
if(strtolower(trim(explode(';',(string)($_SERVER['CONTENT_TYPE']??''))[0]))!=='application/json')json_response(['error'=>['code'=>'unsupported_media_type','message'=>'Content-Type must be application/json.']],415);
try{
    $body=request_json(8192);$pdo=database();$action=(string)($body['action']??'');
    if($action==='begin')json_response(['data'=>meta_begin_embedded_signup($pdo)]);
    if($action==='exchange')json_response(['data'=>meta_exchange_embedded_signup($pdo,(string)($body['state']??''),(string)($body['code']??''),(string)($body['phone_number_id']??''),(string)($body['waba_id']??''))]);
    json_response(['error'=>['code'=>'invalid_action','message'=>'Use begin or exchange.']],422);
}catch(InvalidArgumentException $e){json_response(['error'=>['code'=>'invalid_signup_response','message'=>$e->getMessage()]],422);}
catch(Throwable $e){error_log('[DimdianChatbot] Meta Embedded Signup failed: '.$e->getMessage());json_response(['error'=>['code'=>'meta_signup_failed','message'=>'Meta onboarding could not be completed. Check the private server log.']],502);}
