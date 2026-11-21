<?php
declare(strict_types=1);
function webhook_payload_key(): string {
    $secret=(string)app_config('app.app_key');
    if(strlen($secret)<32) throw new RuntimeException('A 32-character app.app_key is required for webhook encryption.');
    return hash('sha256',"dimdian-webhook-inbox:".$secret,true);
}
function encrypt_webhook_payload(string $raw): array {
    if(!function_exists('openssl_encrypt')) throw new RuntimeException('OpenSSL is required for encrypted webhook storage.');
    $nonce=random_bytes(12);$tag='';
    $cipher=openssl_encrypt($raw,'aes-256-gcm',webhook_payload_key(),OPENSSL_RAW_DATA,$nonce,$tag,'dimdian-webhook-v1',16);
    if(!is_string($cipher)||strlen($tag)!==16) throw new RuntimeException('Webhook payload encryption failed.');
    return ['ciphertext'=>$cipher,'nonce'=>$nonce,'tag'=>$tag];
}
function decrypt_webhook_payload(string $cipher,string $nonce,string $tag): string {
    $plain=openssl_decrypt($cipher,'aes-256-gcm',webhook_payload_key(),OPENSSL_RAW_DATA,$nonce,$tag,'dimdian-webhook-v1');
    if(!is_string($plain)) throw new RuntimeException('Webhook payload authentication failed.');
    return $plain;
}
function enqueue_meta_webhook(PDO $pdo,string $rawBody): array {
    $e=encrypt_webhook_payload($rawBody);$hash=hash('sha256',$rawBody);
    $insert=$pdo->prepare('INSERT IGNORE INTO webhook_inbox (payload_sha256,payload_ciphertext,payload_nonce,payload_tag,status,attempts,available_at,received_at) VALUES (:hash,:cipher,:nonce,:tag,"pending",0,UTC_TIMESTAMP(),UTC_TIMESTAMP())');
    $insert->execute([':hash'=>$hash,':cipher'=>$e['ciphertext'],':nonce'=>$e['nonce'],':tag'=>$e['tag']]);
    $q=$pdo->prepare('SELECT id,status,attempts FROM webhook_inbox WHERE payload_sha256=:hash LIMIT 1');$q->execute([':hash'=>$hash]);$row=$q->fetch();
    if(!$row) throw new RuntimeException('Webhook inbox insert could not be confirmed.');
    // Meta may retry an event after a transient failure. Re-arm the existing
    // deduplicated row instead of permanently stranding it in failed state.
    if($row['status']==='failed'&&(int)$row['attempts']<8){
        $pdo->prepare('UPDATE webhook_inbox SET status="pending",available_at=UTC_TIMESTAMP(),last_error=NULL WHERE id=:id')->execute([':id'=>$row['id']]);
        $row['status']='pending';
    }
    return ['id'=>(int)$row['id'],'inserted'=>$insert->rowCount()===1,'status'=>(string)$row['status']];
}
function process_webhook_inbox(PDO $pdo,int $limit=10,int $maxAttempts=8): array {
    $summary=['processed'=>0,'retried'=>0,'failed'=>0,'empty'=>false];$limit=max(1,min(50,$limit));$maxAttempts=max(1,$maxAttempts);
    for($i=0;$i<$limit;$i++){
        $token=uuid_v4();$pdo->beginTransaction();
        try{
            $expired=$pdo->prepare('UPDATE webhook_inbox SET status="failed",locked_at=NULL,lock_token=NULL,last_error="Worker lease expired after maximum attempts." WHERE status="processing" AND locked_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 5 MINUTE) AND attempts>=:max_attempts');
            $expired->execute([':max_attempts'=>$maxAttempts]);
            $q=$pdo->prepare('SELECT id,payload_ciphertext,payload_nonce,payload_tag,attempts FROM webhook_inbox WHERE (status="pending" AND available_at<=UTC_TIMESTAMP()) OR (status="processing" AND locked_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 5 MINUTE) AND attempts<:max_attempts) ORDER BY received_at ASC LIMIT 1 FOR UPDATE');
            $q->execute([':max_attempts'=>$maxAttempts]);$job=$q->fetch();
            if(!$job){$pdo->commit();$summary['empty']=true;break;}
            $attempt=(int)$job['attempts']+1;
            $pdo->prepare('UPDATE webhook_inbox SET status="processing",attempts=:attempt,locked_at=UTC_TIMESTAMP(),lock_token=:token WHERE id=:id')->execute([':attempt'=>$attempt,':token'=>$token,':id'=>$job['id']]);
            $pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        try{
            $raw=decrypt_webhook_payload((string)$job['payload_ciphertext'],(string)$job['payload_nonce'],(string)$job['payload_tag']);
            $payload=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
            if(!is_array($payload)||($payload['object']??'')!=='whatsapp_business_account')throw new RuntimeException('Stored webhook payload is invalid.');
            $pdo->beginTransaction();
            $phone=function_exists('meta_active_phone_number_id')?meta_active_phone_number_id($pdo):(string)app_config('whatsapp.phone_number_id');
            $result=process_meta_webhook($pdo,$payload,$raw,$phone);
            $done=$pdo->prepare('UPDATE webhook_inbox SET status="processed",processed_at=UTC_TIMESTAMP(),locked_at=NULL,lock_token=NULL,last_error=NULL WHERE id=:id AND lock_token=:token');
            $done->execute([':id'=>$job['id'],':token'=>$token]);$pdo->commit();$summary['processed']+=(int)($result['processed']??0);
        }catch(Throwable $e){
            if($pdo->inTransaction())$pdo->rollBack();$retry=$attempt<$maxAttempts;$delay=min(3600,15*(2**min(8,max(0,$attempt-1))));
            $u=$pdo->prepare('UPDATE webhook_inbox SET status=:status,available_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL :delay SECOND),locked_at=NULL,lock_token=NULL,last_error=:error WHERE id=:id AND lock_token=:token');
            $u->execute([':status'=>$retry?'pending':'failed',':delay'=>$delay,':error'=>mb_substr_safe($e->getMessage(),0,450),':id'=>$job['id'],':token'=>$token]);
            $summary[$retry?'retried':'failed']++;error_log('[DimdianChatbot] Webhook inbox processing failed: '.$e->getMessage());
        }
    }
    return $summary;
}
