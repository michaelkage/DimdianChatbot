<?php
declare(strict_types=1);
function meta_signup_admin_authorized(): bool {
    $expected=(string)app_config('meta.admin_api_key');$supplied=$_SERVER['HTTP_X_DIMDIAN_ADMIN_KEY']??'';
    return strlen($expected)>=32&&is_string($supplied)&&hash_equals($expected,$supplied);
}
function meta_token_key(): string {
    $secret=(string)app_config('app.app_key');if(strlen($secret)<32)throw new RuntimeException('app.app_key must contain at least 32 characters.');
    return hash('sha256','dimdian-meta-token:'.$secret,true);
}
function meta_seal_token(string $token): array {
    $nonce=random_bytes(12);$tag='';$cipher=openssl_encrypt($token,'aes-256-gcm',meta_token_key(),OPENSSL_RAW_DATA,$nonce,$tag,'dimdian-meta-token-v1',16);
    if(!is_string($cipher)||strlen($tag)!==16)throw new RuntimeException('Meta token encryption failed.');
    return ['ciphertext'=>$cipher,'nonce'=>$nonce,'tag'=>$tag];
}
function meta_open_token(string $cipher,string $nonce,string $tag): string {
    $token=openssl_decrypt($cipher,'aes-256-gcm',meta_token_key(),OPENSSL_RAW_DATA,$nonce,$tag,'dimdian-meta-token-v1');
    if(!is_string($token)||$token==='')throw new RuntimeException('Stored Meta token could not be authenticated.');
    return $token;
}
function meta_graph_get(string $url): array {
    $ctx=stream_context_create(['http'=>['method'=>'GET','timeout'=>15,'ignore_errors'=>true,'header'=>"Accept: application/json\r\n"]]);
    $raw=@file_get_contents($url,false,$ctx);$status=0;
    foreach($http_response_header??[] as $h)if(preg_match('/^HTTP\/\S+\s+(\d{3})/',$h,$m))$status=(int)$m[1];
    $data=is_string($raw)?json_decode($raw,true):null;
    if(!is_array($data)||$status<200||$status>=300||empty($data['access_token']))throw new RuntimeException('Meta token exchange was rejected or returned no token.');
    return $data;
}
function meta_begin_embedded_signup(PDO $pdo): array {
    $appId=(string)app_config('meta.app_id');$configId=(string)app_config('meta.config_id');
    if($appId===''||$configId==='')throw new RuntimeException('Meta app_id and config_id must be configured.');
    $state=bin2hex(random_bytes(32));
    $q=$pdo->prepare('INSERT INTO meta_signup_states(state_sha256,expires_at,created_at) VALUES(:hash,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 10 MINUTE),UTC_TIMESTAMP())');
    $q->execute([':hash'=>hash('sha256',$state)]);
    return ['app_id'=>$appId,'config_id'=>$configId,'state'=>$state,'expires_in_seconds'=>600];
}
function meta_exchange_embedded_signup(PDO $pdo,string $state,string $code,string $phoneId,string $wabaId): array {
    if(!preg_match('/^[a-f0-9]{64}$/',$state)||$code===''||strlen($code)>4096)throw new InvalidArgumentException('Meta signup response is incomplete.');
    if(!preg_match('/^[0-9]{5,80}$/',$phoneId)||!preg_match('/^[0-9]{5,80}$/',$wabaId))throw new InvalidArgumentException('Meta returned invalid WhatsApp identifiers.');
    $pdo->beginTransaction();
    $q=$pdo->prepare('SELECT id FROM meta_signup_states WHERE state_sha256=:hash AND used_at IS NULL AND expires_at>UTC_TIMESTAMP() FOR UPDATE');$q->execute([':hash'=>hash('sha256',$state)]);$id=$q->fetchColumn();
    if(!$id){$pdo->rollBack();throw new RuntimeException('Meta signup state is invalid, expired, or already used.');}
    $pdo->prepare('UPDATE meta_signup_states SET used_at=UTC_TIMESTAMP() WHERE id=:id')->execute([':id'=>$id]);$pdo->commit();
    $version=(string)app_config('whatsapp.graph_api_version');$appId=(string)app_config('meta.app_id');$secret=(string)app_config('whatsapp.app_secret');
    if(!preg_match('/^v[0-9]+\.[0-9]+$/',$version)||$appId===''||$secret==='')throw new RuntimeException('Meta app credentials or Graph API version are not configured.');
    $url='https://graph.facebook.com/'.$version.'/oauth/access_token?'.http_build_query(['client_id'=>$appId,'client_secret'=>$secret,'code'=>$code]);
    $tokenData=meta_graph_get($url);$token=(string)$tokenData['access_token'];$expires=max(0,(int)($tokenData['expires_in']??0));$sealed=meta_seal_token($token);
    $save=$pdo->prepare('INSERT INTO meta_connections(waba_id,phone_number_id,access_token_ciphertext,access_token_nonce,access_token_tag,token_expires_at,status,connected_at,updated_at) VALUES(:waba,:phone,:cipher,:nonce,:tag,:expires,"active",UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE waba_id=VALUES(waba_id),access_token_ciphertext=VALUES(access_token_ciphertext),access_token_nonce=VALUES(access_token_nonce),access_token_tag=VALUES(access_token_tag),token_expires_at=VALUES(token_expires_at),status="active",updated_at=UTC_TIMESTAMP()');
    $save->execute([':waba'=>$wabaId,':phone'=>$phoneId,':cipher'=>$sealed['ciphertext'],':nonce'=>$sealed['nonce'],':tag'=>$sealed['tag'],':expires'=>$expires?gmdate('Y-m-d H:i:s',time()+$expires):null]);
    return ['connected'=>true,'phone_number_id'=>$phoneId,'waba_id'=>$wabaId,'expires_at'=>$expires?gmdate(DateTimeInterface::ATOM,time()+$expires):null];
}
function load_meta_whatsapp_config(PDO $pdo,array $base): array {
    $row=$pdo->query('SELECT phone_number_id,access_token_ciphertext,access_token_nonce,access_token_tag,token_expires_at FROM meta_connections WHERE status="active" ORDER BY updated_at DESC LIMIT 1')->fetch();
    if(!$row)return $base;
    if(!empty($row['token_expires_at'])&&strtotime((string)$row['token_expires_at'].' UTC')<=time()){
        $pdo->prepare('UPDATE meta_connections SET status="expired",updated_at=UTC_TIMESTAMP() WHERE phone_number_id=:phone')->execute([':phone'=>$row['phone_number_id']]);
        throw new RuntimeException('The connected Meta token has expired. Reconnect WhatsApp.');
    }
    $base['phone_number_id']=(string)$row['phone_number_id'];
    $base['access_token']=meta_open_token((string)$row['access_token_ciphertext'],(string)$row['access_token_nonce'],(string)$row['access_token_tag']);
    return $base;
}
function meta_active_phone_number_id(PDO $pdo): string {
    $id=$pdo->query('SELECT phone_number_id FROM meta_connections WHERE status="active" ORDER BY updated_at DESC LIMIT 1')->fetchColumn();
    return is_string($id)?$id:(string)app_config('whatsapp.phone_number_id');
}
