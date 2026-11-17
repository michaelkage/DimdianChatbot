<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/app/bootstrap.php';
require_once dirname(__DIR__).'/app/storage.php';
require_once dirname(__DIR__).'/app/woocommerce.php';

$failures=0;$warnings=0;
function preflight(string $label,bool $ok,string $detail='',bool $warning=false): void {
 global $failures,$warnings;
 $status=$ok?'PASS':($warning?'WARN':'FAIL');
 if(!$ok){if($warning)$warnings++;else $failures++;}
 echo '['.$status.'] '.$label.($detail!==''?' — '.$detail:'')."\n";
}
$production=app_config('app.environment','production')==='production';
preflight('PHP runtime',version_compare(PHP_VERSION,'8.2.0','>='),PHP_VERSION);
foreach(['pdo','pdo_mysql','fileinfo','openssl'] as $extension)preflight('PHP extension '.$extension,extension_loaded($extension));
if((bool)app_config('woocommerce.enabled',false)||(bool)app_config('ai.provider_ready',false))preflight('PHP extension curl',extension_loaded('curl'));
$baseUrl=(string)app_config('app.base_url','');
$baseParts=parse_url($baseUrl);
preflight('Application base URL',is_array($baseParts)&&!empty($baseParts['host'])&&(!$production||strtolower((string)($baseParts['scheme']??''))==='https'),$baseUrl);
preflight('Secure staff cookies',!$production||(bool)app_config('app.secure_cookies',false));
$allowedOrigins=app_config('app.allowed_origins',[]);
$httpsOrigins=is_array($allowedOrigins)?array_values(array_filter($allowedOrigins,static function($origin):bool{
 if(!is_string($origin))return false;$parts=parse_url($origin);
 return is_array($parts)&&!empty($parts['host'])&&strtolower((string)($parts['scheme']??''))==='https';
})): [];
preflight('HTTPS storefront origin allowlist',!$production||count($httpsOrigins)>0,'count='.count($httpsOrigins));
$rateSecret=(string)app_config('security.rate_limit_secret','');
preflight('Rate-limit secret',strlen($rateSecret)>=32&&!str_starts_with($rateSecret,'replace-with-'));
$vinKey=(string)app_config('integration.vehicle_data_encryption_key','');
preflight('VIN encryption key',strlen($vinKey)>=32&&!str_starts_with($vinKey,'replace-with-'));
try{
 $pdo=db();$pdo->query('SELECT 1');
 preflight('Database connection',true,(string)$pdo->getAttribute(PDO::ATTR_SERVER_VERSION));
 $required=['staff_users','customers','conversations','messages','attachments','app_settings','rate_limit_buckets','conversation_vehicles','product_fitments','conversation_product_context'];
 $query=$pdo->prepare('SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('.implode(',',array_fill(0,count($required),'?')).')');
 $query->execute($required);$found=$query->fetchAll(PDO::FETCH_COLUMN);
 $missing=array_values(array_diff($required,$found));
 preflight('Database migrations',count($missing)===0,$missing?'Missing: '.implode(', ',$missing):'all required tables exist');
}catch(Throwable $error){preflight('Database connection',false,$error->getMessage());}
$uploadDirectory=private_upload_directory(true);
preflight('Private upload directory',$uploadDirectory!==null&&is_writable((string)$uploadDirectory),$uploadDirectory??'invalid path or inside public document root');
$backupConfigured=(string)app_config('backup.directory','');
$backupOk=false;$backupReal='';
if($backupConfigured!==''&&str_starts_with($backupConfigured,DIRECTORY_SEPARATOR)&&!is_link($backupConfigured)){
 if(!is_dir($backupConfigured))@mkdir($backupConfigured,0700,true);
 $backupReal=realpath($backupConfigured)?:'';
 $publicReal=realpath(dirname(__DIR__).'/public')?:'';
 $backupOk=$backupReal!==''&&is_writable($backupReal)&&$publicReal!==''&&$backupReal!==$publicReal&&!str_starts_with($backupReal.DIRECTORY_SEPARATOR,$publicReal.DIRECTORY_SEPARATOR);
}
preflight('Private backup directory',$backupOk,$backupOk?$backupReal:($backupConfigured!==''?$backupConfigured:'not configured'));
$wooEnabled=(bool)app_config('woocommerce.enabled',false);
if($wooEnabled){
 preflight('WooCommerce configuration',woocommerce_ready());
 $storeParts=parse_url((string)app_config('woocommerce.store_url',''));
 $storeOrigin=is_array($storeParts)?strtolower((string)($storeParts['scheme']??'')).'://'.strtolower((string)($storeParts['host']??'')):'';
 $allowStore=is_array($allowedOrigins)&&in_array($storeOrigin,$allowedOrigins,true);
 preflight('Storefront CORS origin',!$production||$allowStore,$storeOrigin);
 try{$products=woocommerce_request('/wp-json/wc/v3/products',['per_page'=>1]);preflight('Live WooCommerce API',is_array($products),'read-only product request completed');}
 catch(Throwable $error){preflight('Live WooCommerce API',false,$error->getMessage());}
 preflight('MPN lookup route',(string)app_config('woocommerce.mpn_lookup_path','')!=='','not configured',true);
}else preflight('Live WooCommerce catalogue',false,'disabled; product search will return 503',true);
if((bool)app_config('ai.provider_ready',false))preflight('AI provider configuration',ai_provider_available(),'connectivity must still be tested in the staff inbox',!ai_provider_available());
else preflight('AI provider',false,'disabled; human-only support remains active',true);
echo "\nPreflight summary: ".$failures." failure(s), ".$warnings." warning(s).\n";
exit($failures===0?0:1);
