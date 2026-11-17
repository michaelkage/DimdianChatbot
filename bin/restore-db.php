<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(($argc!==2&&$argc!==3)||!is_file($argv[1])){fwrite(STDERR,"Usage: php bin/restore-db.php /absolute/path/backup.sql[.gz] [target_database]\n");exit(2);}
fwrite(STDERR,"WARNING: this overwrites matching database objects/data. Type RESTORE to continue: ");
$answer=trim((string)fgets(STDIN));if($answer!=='RESTORE'){fwrite(STDERR,"Cancelled.\n");exit(1);}
$config=require dirname(__DIR__).'/config.php';$db=$config['database']??[];
$targetDatabase=$argc===3?(string)$argv[2]:(string)$db['name'];
if(!preg_match('/^[A-Za-z0-9_]{1,64}$/D',$targetDatabase)){fwrite(STDERR,"Invalid target database name.\\n");exit(2);}
$tmp=tempnam(sys_get_temp_dir(),'dimdian-mysql-');if($tmp===false)exit(1);chmod($tmp,0600);
file_put_contents($tmp,"[client]\nhost=".(string)$db['host']."\nport=".(int)($db['port']??3306)."\nuser=".(string)$db['username']."\npassword=".(string)$db['password']."\n",LOCK_EX);chmod($tmp,0600);
$proc=proc_open(['mysql','--defaults-extra-file='.$tmp,$targetDatabase],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
if(!is_resource($proc)){unlink($tmp);fwrite(STDERR,"mysql client unavailable.\n");exit(1);}
$input=str_ends_with(strtolower($argv[1]),'.gz')?gzopen($argv[1],'rb'):fopen($argv[1],'rb');
if(!$input){fclose($pipes[0]);$exit=proc_close($proc);unlink($tmp);exit(1);}
while(!feof($input)){$chunk=str_ends_with(strtolower($argv[1]),'.gz')?gzread($input,65536):fread($input,65536);if($chunk===false||$chunk==='')break;fwrite($pipes[0],$chunk);}
str_ends_with(strtolower($argv[1]),'.gz')?gzclose($input):fclose($input);
fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($proc);unlink($tmp);
if($exit!==0){fwrite(STDERR,trim((string)$err)."\n");exit($exit);}
echo "Database restore completed. Verify application health before reopening traffic.\n";
