<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
$privacy=app_config('privacy')??[];
$closedDays=max(30,min(3650,(int)($privacy['closed_conversation_retention_days']??365)));
$webhookDays=max(7,min(365,(int)($privacy['webhook_event_retention_days']??30)));
$sessionDays=max(7,min(180,(int)($privacy['expired_session_retention_days']??30)));
$pdo=database();
$summary=['outbox'=>0,'statuses'=>0,'messages'=>0,'sessions'=>0,'links'=>0,'conversations'=>0,'consent_events'=>0,'guests'=>0,'webhooks'=>0,'inbox'=>0,'signup_states'=>0,'rate_limits'=>0];
try{
 $pdo->beginTransaction();
 $closed=gmdate('Y-m-d H:i:s',time()-$closedDays*86400);
 $webhooks=gmdate('Y-m-d H:i:s',time()-$webhookDays*86400);
 $sessions=gmdate('Y-m-d H:i:s',time()-$sessionDays*86400);
 $q=$pdo->prepare('DELETE o FROM message_outbox o INNER JOIN messages m ON m.id=o.message_id INNER JOIN conversations c ON c.id=m.conversation_id WHERE c.status="closed" AND c.updated_at<:cutoff');$q->execute([':cutoff'=>$closed]);$summary['outbox']=$q->rowCount();
 $q=$pdo->prepare('DELETE e FROM message_status_events e INNER JOIN messages m ON m.provider_message_id=e.provider_message_id INNER JOIN conversations c ON c.id=m.conversation_id WHERE c.status="closed" AND c.updated_at<:cutoff');$q->execute([':cutoff'=>$closed]);$summary['statuses']=$q->rowCount();
 $q=$pdo->prepare('DELETE m FROM messages m INNER JOIN conversations c ON c.id=m.conversation_id WHERE c.status="closed" AND c.updated_at<:cutoff');$q->execute([':cutoff'=>$closed]);$summary['messages']=$q->rowCount();
 $q=$pdo->prepare('DELETE s FROM guest_sessions s WHERE s.expires_at<:cutoff');$q->execute([':cutoff'=>$sessions]);$summary['sessions']=$q->rowCount();
 $q=$pdo->prepare('DELETE l FROM whatsapp_identity_links l INNER JOIN conversations c ON c.id=l.conversation_id WHERE c.status="closed" AND c.updated_at<:cutoff');$q->execute([':cutoff'=>$closed]);$summary['links']=$q->rowCount();
 $q=$pdo->prepare('DELETE c FROM conversations c WHERE c.status="closed" AND c.updated_at<:cutoff AND NOT EXISTS (SELECT 1 FROM messages m WHERE m.conversation_id=c.id) AND NOT EXISTS (SELECT 1 FROM guest_sessions s WHERE s.conversation_id=c.id)');$q->execute([':cutoff'=>$closed]);$summary['conversations']=$q->rowCount();
 $q=$pdo->prepare('DELETE e FROM guest_consent_events e INNER JOIN guests g ON g.id=e.guest_id WHERE g.created_at<:cutoff AND NOT EXISTS (SELECT 1 FROM conversations c WHERE c.guest_id=g.id) AND NOT EXISTS (SELECT 1 FROM guest_sessions s WHERE s.guest_id=g.id)');$q->execute([':cutoff'=>$closed]);$summary['consent_events']=$q->rowCount();
 $q=$pdo->prepare('DELETE g FROM guests g WHERE g.created_at<:cutoff AND NOT EXISTS (SELECT 1 FROM conversations c WHERE c.guest_id=g.id) AND NOT EXISTS (SELECT 1 FROM guest_sessions s WHERE s.guest_id=g.id)');$q->execute([':cutoff'=>$closed]);$summary['guests']=$q->rowCount();
 $q=$pdo->prepare('DELETE FROM webhook_events WHERE received_at<:cutoff AND processing_status IN ("processed","ignored")');$q->execute([':cutoff'=>$webhooks]);$summary['webhooks']=$q->rowCount();
 $q=$pdo->prepare('DELETE FROM webhook_inbox WHERE received_at<:cutoff AND status IN ("processed","failed")');$q->execute([':cutoff'=>$webhooks]);$summary['inbox']=$q->rowCount();
 $q=$pdo->prepare('DELETE FROM meta_signup_states WHERE expires_at<UTC_TIMESTAMP() OR used_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY)');$q->execute();$summary['signup_states']=$q->rowCount();
 $q=$pdo->prepare('DELETE FROM rate_limits WHERE window_started_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 2 DAY)');$q->execute();$summary['rate_limits']=$q->rowCount();
 $pdo->commit();fwrite(STDOUT,json_encode($summary,JSON_UNESCAPED_SLASHES).PHP_EOL);
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();app_log_event('retention.job_failed', ['endpoint' => 'retention', 'exception_class' => get_class($e)]);fwrite(STDERR,"Retention job failed. Check the private server log.\n");exit(1);}
