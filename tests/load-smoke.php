<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/bootstrap.php';

function assertLoad(bool $condition,string $message): void {
 if(!$condition){fwrite(STDERR,"FAIL: ".$message."\n");exit(1);}
 echo "PASS: ".$message."\n";
}
assertLoad(function_exists('curl_multi_init'),'cURL multi transport is available');

$pdo=db();
$rawToken=bin2hex(random_bytes(32));
$customer=$pdo->prepare('INSERT INTO customers (guest_token_hash,created_at,last_seen_at) VALUES (:token,UTC_TIMESTAMP(),UTC_TIMESTAMP())');
$customer->execute(['token'=>hash('sha256',$rawToken)]);
$customerId=(int)$pdo->lastInsertId();
$publicId=bin2hex(random_bytes(13));
$conversation=$pdo->prepare("INSERT INTO conversations (public_id,customer_id,status,last_message_at,created_at,updated_at) VALUES (:public_id,:customer_id,'needs_human',UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())");
$conversation->execute(['public_id'=>$publicId,'customer_id'=>$customerId]);

$server=proc_open([PHP_BINARY,'-S','127.0.0.1:8082','-t',dirname(__DIR__).'/public'],[
 0=>['file','/dev/null','r'],1=>['file','/dev/null','a'],2=>['file','/dev/null','a']
],$pipes);
if(!is_resource($server)){fwrite(STDERR,"Unable to start API test server\n");exit(1);}
try{
 $healthy=false;
 for($i=0;$i<40;$i++){
  $ctx=stream_context_create(['http'=>['timeout'=>1]]);
  $response=@file_get_contents('http://127.0.0.1:8082/',false,$ctx);
  if(is_string($response)&&str_contains($response,'"status":"ok"')){$healthy=true;break;}
  usleep(150000);
 }
 assertLoad($healthy,'API server starts against the migrated MySQL schema');

 $multi=curl_multi_init();$handles=[];
 for($i=0;$i<24;$i++){
  $ch=curl_init('http://127.0.0.1:8082/api/messages.php?conversation_id='.$publicId.'&after_id=0');
  curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['X-Dimdian-Guest-Token: '.$rawToken],CURLOPT_TIMEOUT=>8]);
  curl_multi_add_handle($multi,$ch);$handles[]=$ch;
 }
 do{$status=curl_multi_exec($multi,$running);if($running)curl_multi_select($multi,0.2);}while($running&&$status===CURLM_OK);
 $pollOk=0;
 foreach($handles as $ch){$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$body=curl_multi_getcontent($ch);if($code===200&&is_string($body)&&json_decode($body,true)!==null)$pollOk++;curl_multi_remove_handle($multi,$ch);curl_close($ch);}
 curl_multi_close($multi);
 assertLoad($pollOk===24,'24 concurrent delta-poll requests complete successfully');

 $multi=curl_multi_init();$handles=[];
 for($i=0;$i<32;$i++){
  $ch=curl_init('http://127.0.0.1:8082/api/products.php?type=name&q=alternator');
  curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['X-Dimdian-Guest-Token: '.$rawToken],CURLOPT_TIMEOUT=>8]);
  curl_multi_add_handle($multi,$ch);$handles[]=$ch;
 }
 do{$status=curl_multi_exec($multi,$running);if($running)curl_multi_select($multi,0.2);}while($running&&$status===CURLM_OK);
 $limited=0;$allowed=0;
 foreach($handles as $ch){$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);if($code===429)$limited++;elseif(in_array($code,[200,502,503],true))$allowed++;curl_multi_remove_handle($multi,$ch);curl_close($ch);}
 curl_multi_close($multi);
 assertLoad($limited===12&&$allowed===20,'concurrent catalogue requests enforce 12 rate-limit rejections and 20 admitted requests atomically');
}finally{
 proc_terminate($server);proc_close($server);
 $pdo->prepare('DELETE FROM conversations WHERE public_id=:id')->execute(['id'=>$publicId]);
 $pdo->prepare('DELETE FROM customers WHERE id=:id')->execute(['id'=>$customerId]);
}
