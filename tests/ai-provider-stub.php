<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
$input = json_decode((string)file_get_contents('php://input'),true) ?: [];
$messages = $input['messages'] ?? [];
$last = '';
foreach (array_reverse($messages) as $message) {
 if (($message['role'] ?? '') === 'user') { $last=(string)($message['content'] ?? ''); break; }
}
if (str_contains($last,'TRIGGER_TIMEOUT')) {
 usleep(4000000);
}
if (str_contains($last,'TRIGGER_QUOTA')) {
 http_response_code(429);
 echo json_encode(['error'=>['code'=>'insufficient_quota','message'=>'You exceeded your current quota.']]);
 exit;
}
if (str_contains($last,'TRIGGER_TRANSIENT')) {
 http_response_code(503);
 echo json_encode(['error'=>['message'=>'Temporary upstream failure.']]);
 exit;
}
echo json_encode(['choices'=>[['message'=>['role'=>'assistant','content'=>'Thanks for contacting DIMDIAN. Our team can help with that.']] ]]);
