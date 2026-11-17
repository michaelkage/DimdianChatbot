<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$configPath=dirname(__DIR__).'/config.php';
if(!is_file($configPath)){fwrite(STDERR,"config.php is missing\n");exit(1);}
$config=require $configPath;$db=$config['database']??[];
$directory=(string)($config['backup']['directory']??getenv('DIMDIAN_BACKUP_DIRECTORY')?:'');
if($directory===''||!str_starts_with($directory,DIRECTORY_SEPARATOR)||is_link($directory)){fwrite(STDERR,"Set backup.directory to an absolute private directory outside public_html.\n");exit(1);}
if(!is_dir($directory)&&!mkdir($directory,0700,true)&&!is_dir($directory)){fwrite(STDERR,"Cannot create backup directory.\n");exit(1);}
$real=realpath($directory);$public=realpath(dirname(__DIR__).'/public');
if(!$real||($public&&str_starts_with($real.DIRECTORY_SEPARATOR,$public.DIRECTORY_SEPARATOR))){fwrite(STDERR,"Backup directory must be outside public/.\n");exit(1);}
$tmp=tempnam(sys_get_temp_dir(),'dimdian-mysql-');if($tmp===false)exit(1);chmod($tmp,0600);
$ini="[client]\nhost=".(string)$db['host']."\nport=".(int)($db['port']??3306)."\nuser=".(string)$db['username']."\npassword=".(string)$db['password']."\n";
file_put_contents($tmp,$ini,LOCK_EX);chmod($tmp,0600);
$file=$real.'/dimdian-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)).'.sql.gz';
$proc=proc_open(['mysqldump','--defaults-extra-file='.$tmp,'--single-transaction','--quick','--routines','--triggers',(string)$db['name']],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
if(!is_resource($proc)){unlink($tmp);fwrite(STDERR,"mysqldump is unavailable. Use Hostinger phpMyAdmin export instead.\n");exit(1);}
fclose($pipes[0]);$gz=gzopen($file,'wb6');$bytes=0;
while(!feof($pipes[1])){$chunk=fread($pipes[1],65536);if($chunk===false)break;$bytes+=strlen($chunk);gzwrite($gz,$chunk);}
fclose($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[2]);$exit=proc_close($proc);gzclose($gz);unlink($tmp);
if($exit!==0||$bytes===0){@unlink($file);fwrite(STDERR,"Backup failed: ".trim((string)$error)."\n");exit(1);}
chmod($file,0600);
$retentionDays=max(1,min(365,(int)($config['backup']['retention_days']??14)));
$cutoff=time()-($retentionDays*86400);
foreach(glob($real.'/dimdian-*.sql.gz')?:[] as $oldFile){
 if($oldFile===$file||is_link($oldFile)||!is_file($oldFile))continue;
 if(!preg_match('/^dimdian-\\d{8}-\\d{6}-[a-f0-9]{8}\\.sql\\.gz$/D',basename($oldFile)))continue;
 $modified=filemtime($oldFile);
 if($modified!==false&&$modified<$cutoff)@unlink($oldFile);
}
echo $file." (".$bytes." bytes SQL)\n";
