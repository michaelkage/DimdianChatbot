<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$configPath=dirname(__DIR__).'/config.php';
if(!is_file($configPath)){fwrite(STDERR,"config.php is missing.\\n");exit(1);}
$config=require $configPath;$db=$config['database']??[];
if(($argc!==2&&$argc!==3)||!is_string($argv[1])||!str_starts_with($argv[1],DIRECTORY_SEPARATOR)||is_link($argv[1])||!is_file($argv[1])||!preg_match('/\\.sql(?:\\.gz)?$/i',$argv[1])){
 fwrite(STDERR,"Usage: php bin/restore-db.php /absolute/private/backup.sql[.gz] [target_database]\n");exit(2);
}
$backupPath=realpath($argv[1]);$webRoots=[];
foreach([dirname(__DIR__).'/public',(string)($config['app']['document_root']??dirname(__DIR__).'/public')] as $root){$resolved=realpath($root);if($resolved===false){fwrite(STDERR,"Configured web document root cannot be resolved safely.\\n");exit(1);}$webRoots[]=$resolved;}
$webRoots=array_values(array_unique($webRoots));$insideOrAncestor=static fn(string $path,string $root):bool=>$path===$root||str_starts_with($path.DIRECTORY_SEPARATOR,$root.DIRECTORY_SEPARATOR)||str_starts_with($root.DIRECTORY_SEPARATOR,$path.DIRECTORY_SEPARATOR);
$systemTemp=realpath(sys_get_temp_dir());$production=($config['app']['environment']??'production')==='production';
if($backupPath===false||is_link($argv[1])){fwrite(STDERR,"Backup must be a real file outside web roots.\\n");exit(2);}
foreach($webRoots as $root)if($insideOrAncestor($backupPath,$root)){fwrite(STDERR,"Backup must be outside every web document root.\\n");exit(2);}
if($production&&$systemTemp!==false&&$insideOrAncestor($backupPath,$systemTemp)){fwrite(STDERR,"Production backups must be stored outside system temporary directories.\\n");exit(2);}
if(!function_exists('proc_open')){fwrite(STDERR,"proc_open is unavailable; use phpMyAdmin import instead.\n");exit(1);}
fwrite(STDERR,"WARNING: this overwrites matching database objects/data. Type RESTORE to continue: ");
$answer=trim((string)fgets(STDIN));if($answer!=='RESTORE'){fwrite(STDERR,"Cancelled.\n");exit(1);}
$targetDatabase=$argc===3?(string)$argv[2]:(string)$db['name'];
if(!preg_match('/^[A-Za-z0-9_]{1,64}$/D',$targetDatabase)){fwrite(STDERR,"Invalid target database name.\n");exit(2);}

// Validate/decompress gzip completely before opening a database connection. A corrupt
// archive must never stream a partial SQL dump into the target database.
$temporarySql=null;$restoreInputPath=$backupPath;
if(str_ends_with(strtolower($backupPath),'.gz')){
 if(!function_exists('gzopen')){fwrite(STDERR,"PHP zlib is unavailable; use phpMyAdmin import instead.\n");exit(1);}
 $temporarySql=tempnam(sys_get_temp_dir(),'dimdian-restore-');
 if($temporarySql===false){fwrite(STDERR,"Cannot create private temporary restore file.\n");exit(1);}
 chmod($temporarySql,0600);
 $compressed=gzopen($backupPath,'rb');$output=fopen($temporarySql,'wb');
 if($compressed===false||$output===false){
  if($compressed!==false)gzclose($compressed);
  if($output!==false)fclose($output);
  @unlink($temporarySql);fwrite(STDERR,"Cannot read compressed backup.\n");exit(1);
 }
 $valid=true;
 while(!gzeof($compressed)){
  $chunk=gzread($compressed,65536);
  if($chunk===false){$valid=false;break;}
  if($chunk===''){if(!gzeof($compressed))$valid=false;break;}
  $offset=0;$length=strlen($chunk);
  while($offset<$length){
   $written=fwrite($output,substr($chunk,$offset));
   if($written===false||$written===0){$valid=false;break 2;}
   $offset+=$written;
  }
 }
 $closed=gzclose($compressed);$flushed=fflush($output);fclose($output);chmod($temporarySql,0600);
 if(!$valid||!$closed||!$flushed||!is_file($temporarySql)||filesize($temporarySql)<1){
  @unlink($temporarySql);fwrite(STDERR,"Compressed backup is corrupt or could not be fully decompressed.\n");exit(1);
 }
 $restoreInputPath=$temporarySql;
}

$tmp=tempnam(sys_get_temp_dir(),'dimdian-mysql-');
if($tmp===false){if($temporarySql!==null)@unlink($temporarySql);exit(1);}
chmod($tmp,0600);
$slash=chr(92);$mysqlOption=static function(string $value) use($slash):string {
 if(str_contains($value,"\n")||str_contains($value,"\r"))throw new RuntimeException('Database option values cannot contain line breaks.');
 return '"'.str_replace([$slash,'"'],[$slash.$slash,$slash.'"'],$value).'"';
};
$ini="[client]\nhost=".$mysqlOption((string)$db['host'])."\nport=".(int)($db['port']??3306)."\nuser=".$mysqlOption((string)$db['username'])."\npassword=".$mysqlOption((string)$db['password'])."\n";
file_put_contents($tmp,$ini,LOCK_EX);chmod($tmp,0600);
$proc=proc_open(['mysql','--defaults-extra-file='.$tmp,$targetDatabase],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
if(!is_resource($proc)){
 unlink($tmp);if($temporarySql!==null)@unlink($temporarySql);
 fwrite(STDERR,"mysql client unavailable; use phpMyAdmin import instead.\n");exit(1);
}
$input=fopen($restoreInputPath,'rb');
if(!$input){
 fclose($pipes[0]);$exit=proc_close($proc);unlink($tmp);if($temporarySql!==null)@unlink($temporarySql);exit(1);
}
$streamOk=true;
while(!feof($input)){
 $chunk=fread($input,65536);
 if($chunk===false){$streamOk=false;break;}
 if($chunk==='')break;
 $offset=0;$length=strlen($chunk);
 while($offset<$length){
  $written=fwrite($pipes[0],substr($chunk,$offset));
  if($written===false||$written===0){$streamOk=false;break 2;}
  $offset+=$written;
 }
}
fclose($input);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
$exit=proc_close($proc);unlink($tmp);if($temporarySql!==null)@unlink($temporarySql);
if(!$streamOk||$exit!==0){fwrite(STDERR,trim((string)$err).($streamOk?'':"\nBackup stream read failed.")."\n");exit($exit!==0?$exit:1);}
echo "Database restore completed. Verify application health before reopening traffic.\n";
