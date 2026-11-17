<?php
declare(strict_types=1);
function probeAssert(bool $ok,string $message): void {
 if(!$ok){fwrite(STDERR,"FAIL: ".$message."\n");exit(1);}
 echo "PASS: ".$message."\n";
}
function httpJson(string $url,array $payload,string $cookieFile='',array $headers=[]): array {
 $ch=curl_init($url);
 $options=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>array_merge(['Content-Type: application/json'],$headers),CURLOPT_POSTFIELDS=>json_encode($payload),CURLOPT_TIMEOUT=>5];
 if($cookieFile!==''){$options[CURLOPT_COOKIEJAR]=$cookieFile;$options[CURLOPT_COOKIEFILE]=$cookieFile;}
 curl_setopt_array($ch,$options);$body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
 return [$status,is_string($body)?(json_decode($body,true)?:[]):[]];
}
$base='http://127.0.0.1:8080/api/';$cookie=sys_get_temp_dir().'/dimdian-probe-'.bin2hex(random_bytes(5)).'.cookies';
[$loginStatus,$login]=httpJson($base.'staff-login.php',['email'=>'ci-admin@example.invalid','password'=>'SuperSecureCIpassword123!'],$cookie);
probeAssert($loginStatus===200&&isset($login['csrf_token']),'administrator session can authenticate for provider test');
[$probeStatus,$probe]=httpJson($base.'ai-settings.php',['test_provider'=>true],$cookie,['X-CSRF-Token: '.$login['csrf_token']]);
probeAssert($probeStatus===200&&($probe['test_ok']??false)===true&&($probe['provider_response']??'')==='DIMDIAN_PROVIDER_OK','administrator live provider probe succeeds against local CI stub');
$get=curl_init($base.'ai-settings.php');curl_setopt_array($get,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>$cookie,CURLOPT_TIMEOUT=>5]);$settingsBody=curl_exec($get);$settingsStatus=(int)curl_getinfo($get,CURLINFO_HTTP_CODE);curl_close($get);$settings=is_string($settingsBody)?(json_decode($settingsBody,true)?:[]):[];
probeAssert($settingsStatus===200&&($settings['ai_enabled']??true)===false&&($settings['ai_provider_tested']??false)===true,'provider probe verifies the configuration but does not enable AI automatically');
@unlink($cookie);
