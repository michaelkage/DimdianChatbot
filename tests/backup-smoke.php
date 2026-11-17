<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/bootstrap.php';

function backupCheck(bool $ok,string $message): void {
 if(!$ok){fwrite(STDERR,"FAIL: ".$message."\n");exit(1);}
 echo "PASS: ".$message."\n";
}
$directory=sys_get_temp_dir().'/dimdian-backup-'.bin2hex(random_bytes(6));
mkdir($directory,0700,true);
$expiredBackup=$directory.'/dimdian-20200101-000000-deadbeef.sql.gz';
file_put_contents($expiredBackup,'expired fixture');touch($expiredBackup,time()-(20*86400));
putenv('DIMDIAN_BACKUP_DIRECTORY='.$directory);
$backupProcess=proc_open([PHP_BINARY,dirname(__DIR__).'/bin/backup-db.php'],[
 0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']
],$backupPipes,dirname(__DIR__));
fclose($backupPipes[0]);
$backupOutput=stream_get_contents($backupPipes[1]);$backupError=stream_get_contents($backupPipes[2]);
fclose($backupPipes[1]);fclose($backupPipes[2]);
$backupExit=proc_close($backupProcess);
backupCheck($backupExit===0,'database backup command exits successfully: '.trim((string)$backupError));
$backupPath=explode(' (',trim((string)$backupOutput),2)[0];
backupCheck(is_file($backupPath)&&filesize($backupPath)>0,'compressed SQL backup is created in private storage');
backupCheck((fileperms($backupPath)&0777)===0600,'backup file permissions are owner-only');
backupCheck(preg_match('/^dimdian-\\d{8}-\\d{6}-[a-f0-9]{8}\\.sql\\.gz$/D',basename($backupPath))===1,'backup filename is collision-resistant and predictable for retention');
$publicPath=realpath(dirname(__DIR__).'/public');$realBackupPath=realpath($backupPath);
backupCheck($publicPath!==false&&$realBackupPath!==false&&!str_starts_with($realBackupPath,$publicPath.DIRECTORY_SEPARATOR),'backup file is outside the public document root');
backupCheck(!is_file($expiredBackup),'expired backups are pruned after a successful backup');

$target='dimdian_restore_'.bin2hex(random_bytes(4));
db()->exec('CREATE DATABASE '.$target.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$restoreProcess=proc_open([PHP_BINARY,dirname(__DIR__).'/bin/restore-db.php',$backupPath,$target],[
 0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']
],$restorePipes,dirname(__DIR__));
fwrite($restorePipes[0],"RESTORE\n");fclose($restorePipes[0]);
$restoreOutput=stream_get_contents($restorePipes[1]);$restoreError=stream_get_contents($restorePipes[2]);
fclose($restorePipes[1]);fclose($restorePipes[2]);
$restoreExit=proc_close($restoreProcess);
backupCheck($restoreExit===0,'restore command completes into a separate database: '.trim((string)$restoreError));
$check=new PDO('mysql:host='.app_config('database.host').';port='.(int)app_config('database.port',3306).';dbname='.$target.';charset=utf8mb4',(string)app_config('database.username'),(string)app_config('database.password'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$tables=(int)$check->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('conversations','messages','conversation_vehicles','product_fitments','conversation_product_context')")->fetchColumn();
backupCheck($tables===5,'restored database contains core support, vehicle, fitment and selected-product context tables');

$corruptPath=$directory.'/dimdian-corrupt.sql.gz';file_put_contents($corruptPath,'not a gzip archive');
$corruptProcess=proc_open([PHP_BINARY,dirname(__DIR__).'/bin/restore-db.php',$corruptPath,$target],[
 0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']
],$corruptPipes,dirname(__DIR__));
fwrite($corruptPipes[0],"RESTORE\n");fclose($corruptPipes[0]);
$corruptOutput=stream_get_contents($corruptPipes[1]);$corruptError=stream_get_contents($corruptPipes[2]);
fclose($corruptPipes[1]);fclose($corruptPipes[2]);$corruptExit=proc_close($corruptProcess);
backupCheck($corruptExit!==0,'corrupt compressed backup is rejected before database import');
$tablesAfter=(int)$check->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('conversations','messages','conversation_vehicles','product_fitments','conversation_product_context')")->fetchColumn();
backupCheck($tablesAfter===5,'rejected corrupt backup leaves the restore target unchanged');
db()->exec('DROP DATABASE '.$target);
@unlink($backupPath);@rmdir($directory);
