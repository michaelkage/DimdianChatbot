<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/app/bootstrap.php';
try{
 $deleted=db()->exec("DELETE FROM rate_limit_buckets WHERE updated_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 DAY) LIMIT 10000");
 echo 'Removed '.(int)$deleted." expired rate-limit buckets.\n";
}catch(Throwable $error){
 fwrite(STDERR,"Rate-limit cleanup failed: ".$error->getMessage()."\n");
 exit(1);
}
