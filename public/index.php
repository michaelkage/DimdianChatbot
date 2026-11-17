<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_method('GET');
try {
 db()->query('SELECT 1');
 json_response(['service'=>'DimdianChatbot','status'=>'ok','database'=>'connected']);
} catch (Throwable $error) {
 error_log('[DimdianChatbot] Health check failed: '.$error->getMessage());
 json_response(['service'=>'DimdianChatbot','status'=>'degraded','database'=>'unavailable'],503);
}
