<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$configPath=dirname(__DIR__).'/config.php';
if(!is_file($configPath)){fwrite(STDERR,"config.php is missing\n");exit(1);}
$config=require $configPath;$db=$config['database']??[];
if(!function_exists('proc_open')||!function_exists('gzopen')){fwrite(STDERR,"CLI process support and PHP zlib are required; use Hostinger phpMyAdmin export instead.\n");exit(1);}
$directory=(string)($config['backup']['directory']??getenv('DIMDIAN_BACKUP_DIRECTORY')?:'');
if($directory===''||!str_starts_with($directory,DIRECTORY_SEPARATOR)||is_link($directory)){fwrite(STDERR,"Set backup.directory to an absolute private directory outside public_html.\n");exit(1);}
if(!is_dir($directory)&&!mkdir($directory,0700,true)&&!is_dir($directory)){fwrite(STDERR,"Cannot create backup directory.\n");exit(1);}
$real=realpath($directory);$public=realpath(dirname(__DIR__).'/public');$systemTemp=realpath(sys_get_temp_dir());$production=($config['app']['environment']??'production')==='production';
if(!$real||$real===DIRECTORY_SEPARATOR||($production&&$systemTemp!==false&&($real===$systemTemp||str_starts_with($real.DIRECTORY_SEPARATOR,$systemTemp.DIRECTORY_SEPARATOR)||str_starts_with($systemTemp.DIRECTORY_SEPARATOR,$real.DIRECTORY_SEPARATOR)))||($public&&($real===$public||str_starts_with($real.DIRECTORY_SEPARATOR,$public.DIRECTORY_SEPARATOR)||str_starts_with($public.DIRECTORY_SEPARATOR,$real.DIRECTORY_SEPARATOR)))){fwrite(STDERR,"Backup directory must be outside public/.\n");exit(1);}
@chmod($real,0700);clearstatcache(true,$real);$directoryPermissions=fileperms($real);
if($directoryPermissions===false||($directoryPermissions&0077)!==0){fwrite(STDERR,"Backup directory must be owner-only (0700).\\n");exit(1);}
$tmp=tempnam(sys_get_temp_dir(),'dimdian-mysql-');if($tmp===false)exit(1);chmod($tmp,0600);
$ini="[client]\nhost=".(string)$db['host']."\nport=".(int)($db['port']??3306)."\nuser=".(string)$db['username']."\npassword=".(string)$db['password']."\n";
file_put_contents($tmp,$ini,LOCK_EX);chmod($tmp,0600);
$file=$real.'/dimdian-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)).'.sql.gz';
$gz=gzopen($file,'wb6');
if($gz===false){unlink($tmp);fwrite(STDERR,"Cannot create compressed backup file.\n");exit(1);}
$proc=proc_open(['mysqldump','--defaults-extra-file='.$tmp,'--single-transaction','--quick',(string)$db['name']],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
if(!is_resource($proc)){gzclose($gz);unlink($tmp);@unlink($file);fwrite(STDERR,"mysqldump is unavailable. Use Hostinger phpMyAdmin export instead.\n");exit(1);}
fclose($pipes[0]);$bytes=0;$streamOk=true;$writeOk=true;
while(!feof($pipes[1])){
 $chunk=fread($pipes[1],65536);
 if($chunk===false){$streamOk=false;break;}
 if($chunk==='')continue;
 $length=strlen($chunk);$bytes+=$length;
 if($writeOk&&gzwrite($gz,$chunk)!==$length)$writeOk=false;
}
if(!$streamOk)proc_terminate($proc);
fclose($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[2]);$exit=proc_close($proc);$gzClosed=gzclose($gz);unlink($tmp);
if($exit!==0||$bytes===0||!$streamOk||!$writeOk||!$gzClosed){@unlink($file);fwrite(STDERR,"Backup failed: ".trim((string)$error)."\n");exit(1);}
chmod($file,0600);
$retentionDays=max(1,min(365,(int)($config['backup']['retention_days']??14)));
$cutoff=time()-($retentionDays*86400);
foreach(glob($real.'/dimdian-*.sql.gz')?:[] as $oldFile){
 if($oldFile===$file||is_link($oldFile)||!is_file($oldFile))continue;
 if(!preg_match('/^dimdian-\\d{8}-\\d{6}(?:-[a-f0-9]{8})?\\.sql\\.gz$/D',basename($oldFile)))continue;
 $modified=filemtime($oldFile);
 if($modified!==false&&$modified<$cutoff)@unlink($oldFile);
}
echo $file." (".$bytes." bytes SQL)\n";
