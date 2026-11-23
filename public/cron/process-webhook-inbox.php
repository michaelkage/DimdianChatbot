<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__,2).'/app/bootstrap.php';
require_once dirname(__DIR__,2).'/app/whatsapp.php';
require_once dirname(__DIR__,2).'/app/webhook_inbox.php';
try{$pdo=database();$summary=process_webhook_inbox_with_repository(chat_repository($pdo),25,8);fwrite(STDOUT,json_encode($summary,JSON_UNESCAPED_SLASHES).PHP_EOL);}
catch(Throwable $e){app_log_event('webhook.inbox_worker_failed', ['endpoint' => 'webhook_inbox_worker', 'exception_class' => get_class($e)]);fwrite(STDERR,"Webhook inbox worker failed. Check the private server log.\n");exit(1);}
