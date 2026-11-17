<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$configPath=dirname(__DIR__).'/config.php';
if(!is_file($configPath)){fwrite(STDERR,"config.php is missing\n");exit(1);}
$config=require $configPath;$db=$config['database']??[];
if(!function_exists('proc_open')||!function_exists('gzopen')){fwrite(STDERR,"CLI process support and PHP zlib are required; use Hostinger phpMyAdmin export instead.\n");exit(1);}
$directory=(string)($config['backup']['directory']??getenv('DIMDIAN_BACKUP_DIRECTORY')?:'');
if($directory===''||!str_starts_with($directory,DIRECTORY_SEPARATOR)||is_link($directory)){fwrite(STDERR,"Set backup.directory to an absolute private directory outside public_html.\n");exit(1);}
$public=realpath(dirname(__DIR__).'/public');$documentRoot=realpath((string)($config['app']['document_root']??dirname(__DIR__).'/public'));
$systemTemp=realpath(sys_get_temp_dir());$production=($config['app']['environment']??'production')==='production';
if($public===false||$documentRoot===false){fwrite(STDERR,"Configured web document root cannot be resolved safely.\n");exit(1);}
$webRoots=array_values(array_unique([$public,$documentRoot]));
$insideOrAncestor=static fn(string $path,string $root):bool=>$path===$root||str_starts_with($path.DIRECTORY_SEPARATOR,$root.DIRECTORY_SEPARATOR)||str_starts_with($root.DIRECTORY_SEPARATOR,$path.DIRECTORY_SEPARATOR);
if(!is_dir($directory)){
 $parent=realpath(dirname($directory));
 $unsafeParent=$parent===false||$parent===DIRECTORY_SEPARATOR;
 if(!$unsafeParent)foreach($webRoots as $root)if($parent===$root||str_starts_with($parent.DIRECTORY_SEPARATOR,$root.DIRECTORY_SEPARATOR)){$unsafeParent=true;break;}
 if(!$unsafeParent&&$production&&$systemTemp!==false&&($parent===$systemTemp||str_starts_with($parent.DIRECTORY_SEPARATOR,$systemTemp.DIRECTORY_SEPARATOR)))$unsafeParent=true;
 if($unsafeParent){fwrite(STDERR,"Backup directory parent must be an existing private path outside web and temporary roots.\n");exit(1);}
 if(!mkdir($directory,0700,false)&&!is_dir($directory)){fwrite(STDERR,"Cannot create backup directory.\n");exit(1);}
}
$real=realpath($directory);
if(!$real||$real===DIRECTORY_SEPARATOR){fwrite(STDERR,"Backup directory cannot be resolved safely.\n");exit(1);}
foreach($webRoots as $root)if($insideOrAncestor($real,$root)){fwrite(STDERR,"Backup directory must be outside every web document root.\n");exit(1);}
if($production&&$systemTemp!==false&&$insideOrAncestor($real,$systemTemp)){fwrite(STDERR,"Production backups must use persistent storage outside system temporary directories.\n");exit(1);}
@chmod($real,0700);clearstatcache(true,$real);$directoryPermissions=fileperms($real);
if($directoryPermissions===false||($directoryPermissions&0077)!==0){fwrite(STDERR,"Backup directory must be owner-only (0700).\n");exit(1);}
$tmp=tempnam(sys_get_temp_dir(),'dimdian-mysql-');if($tmp===false)exit(1);chmod($tmp,0600);
$slash=chr(92);$mysqlOption=static function(string $value) use($slash):string {
 if(str_contains($value,"\n")||str_contains($value,"\r"))throw new RuntimeException('Database option values cannot contain line breaks.');
 return '"'.str_replace([$slash,'"'],[$slash.$slash,$slash.'"'],$value).'"';
};
$ini="[client]\nhost=".$mysqlOption((string)$db['host'])."\nport=".(int)($db['port']??3306)."\nuser=".$mysqlOption((string)$db['username'])."\npassword=".$mysqlOption((string)$db['password'])."\n";
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
