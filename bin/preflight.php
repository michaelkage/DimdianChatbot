<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/app/bootstrap.php';
require_once dirname(__DIR__).'/app/storage.php';
require_once dirname(__DIR__).'/app/woocommerce.php';
require_once dirname(__DIR__).'/app/ai_provider.php';

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
function preflight_ini_bytes(string $value): int {
 $value=trim($value);if($value===''||$value==='0')return 0;if($value==='-1')return PHP_INT_MAX;
 $number=(float)$value;$unit=strtolower(substr($value,-1));
 return (int)($number*match($unit){'g'=>1024**3,'m'=>1024**2,'k'=>1024,default=>1});
}
$uploadLimit=preflight_ini_bytes((string)ini_get('upload_max_filesize'));
$postLimit=preflight_ini_bytes((string)ini_get('post_max_size'));
preflight('PHP upload_max_filesize',!$production||$uploadLimit>=3*1024*1024,ini_get('upload_max_filesize'));
preflight('PHP post_max_size',!$production||$postLimit>=4*1024*1024,ini_get('post_max_size'));
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
$backupConfigured=(string)(getenv('DIMDIAN_BACKUP_DIRECTORY')?:app_config('backup.directory',''));
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
 $mpnPath=(string)app_config('woocommerce.mpn_lookup_path','');
 if($mpnPath==='')preflight('MPN lookup route',false,'not configured',true);
 else{
  try{$mpnProbe=woocommerce_request($mpnPath,['mpn'=>'DIMDIAN_PREFLIGHT_NONMATCH','per_page'=>1]);preflight('Live MPN lookup route',is_array($mpnProbe),$mpnPath);}
  catch(Throwable $error){preflight('Live MPN lookup route',false,$error->getMessage());}
 }
}else preflight('Live WooCommerce catalogue',false,'disabled; product search will return 503',true);
if((bool)app_config('ai.provider_ready',false)){
 $providerAvailable=ai_provider_available();
 preflight('AI provider configuration',$providerAvailable);
 if($providerAvailable)preflight('AI provider verification',ai_provider_verified(),'run Test provider in the staff inbox before enabling AI',!ai_provider_verified());
}else preflight('AI provider',false,'disabled; human-only support remains active',true);
echo "\nPreflight summary: ".$failures." failure(s), ".$warnings." warning(s).\n";
exit($failures===0?0:1);
