#!/usr/bin/env bash
set -euo pipefail
ROOT="$PWD"
cd "$ROOT"
PASSWORD="SuperSecureCIpassword123!"

cat > config.php <<'PHP'
<?php
declare(strict_types=1);
return [
 'app'=>['environment'=>'test','base_url'=>'http://127.0.0.1:8080','timezone'=>'UTC','allowed_origins'=>['http://127.0.0.1:8080'],'session_name'=>'dimdian_ci_staff','secure_cookies'=>false],
 'database'=>['host'=>'127.0.0.1','port'=>3306,'name'=>'dimdian_test','username'=>'root','password'=>'root','charset'=>'utf8mb4'],
 'uploads'=>['directory'=>'/tmp/dimdian-ci-private-uploads','max_bytes'=>3*1024*1024,'allowed_mime_types'=>['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp']],
 'integration'=>['customer_identity_secret'=>'ci-identity-secret'],
 'security'=>['guest_token_bytes'=>32,'message_max_chars'=>5000,'poll_limit'=>100,'rate_limit_secret'=>'ci-rate-limit-secret'],
];
PHP
mkdir -p /tmp/dimdian-ci-private-uploads
php -S 127.0.0.1:8080 -t public >/tmp/dimdian-ci-server.log 2>&1 &
for attempt in $(seq 1 30); do
  if curl -fsS http://127.0.0.1:8080/ >/tmp/dimdian-health.json; then break; fi
  sleep 1
done
jq -e '.status == "ok" and .database == "connected"' /tmp/dimdian-health.json >/dev/null
echo 'health check passed'
printf '%s\n%s\n' "$PASSWORD" "$PASSWORD" | php bin/create-admin.php ci-admin@example.invalid "CI Admin" admin >/dev/null
echo 'staff provisioning passed'
SIGNATURE="$(php -r "echo hash_hmac('sha256', 'customer-123', 'ci-identity-secret');")"
SESSION_JSON="$(curl -fsS -X POST http://127.0.0.1:8080/api/session.php -H 'Content-Type: application/json' -d "{\"external_user_id\":\"customer-123\",\"identity_signature\":\"$SIGNATURE\"}")"
TOKEN="$(jq -r '.token' <<<"$SESSION_JSON")"
test "$(printf '%s' "$TOKEN" | wc -c)" -eq 64
echo 'guest session passed'
CONVERSATION_JSON="$(curl -fsS -X POST http://127.0.0.1:8080/api/conversations.php -H "X-Dimdian-Guest-Token: $TOKEN" -H 'Content-Type: application/json' -d '{"context_url":"https://parts.example.invalid/brake-pad","context_sku":"BP-100","subject":"Brake pad listing"}')"
CONVERSATION_ID="$(jq -r '.conversation.id' <<<"$CONVERSATION_JSON")"
test "$(printf '%s' "$CONVERSATION_ID" | wc -c)" -eq 26
echo 'conversation creation passed'
curl -fsS -X POST http://127.0.0.1:8080/api/messages.php -H "X-Dimdian-Guest-Token: $TOKEN" -H 'Content-Type: application/json' -d "{\"conversation_id\":\"$CONVERSATION_ID\",\"body\":\"Will this fit my vehicle?\"}" >/tmp/dimdian-customer-message.json
printf '%s' 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADUlEQVR4nGP4z8AAAAMBAQDJ/pLvAAAAAElFTkSuQmCC' | base64 -d >/tmp/dimdian-test.png
UPLOAD_JSON="$(curl -fsS -X POST http://127.0.0.1:8080/api/upload.php -H "X-Dimdian-Guest-Token: $TOKEN" -F "conversation_id=$CONVERSATION_ID" -F 'file=@/tmp/dimdian-test.png;type=image/png')"
ATTACHMENT_ID="$(jq -r '.attachment.id' <<<"$UPLOAD_JSON")"
test "$ATTACHMENT_ID" -gt 0
echo 'image upload passed'
CUSTOMER_POLL="$(curl -fsS "http://127.0.0.1:8080/api/messages.php?conversation_id=$CONVERSATION_ID&after_id=0" -H "X-Dimdian-Guest-Token: $TOKEN")"
jq -e '.messages | length == 2' <<<"$CUSTOMER_POLL" >/dev/null
curl -fsS "http://127.0.0.1:8080/api/attachment.php?id=$ATTACHMENT_ID" -H "X-Dimdian-Guest-Token: $TOKEN" -o /tmp/dimdian-downloaded.png
cmp /tmp/dimdian-test.png /tmp/dimdian-downloaded.png
echo 'private attachment retrieval passed'
LOGIN_JSON="$(curl -fsS -c /tmp/dimdian-ci-cookies.txt -X POST http://127.0.0.1:8080/api/staff-login.php -H 'Content-Type: application/json' -d "{\"email\":\"ci-admin@example.invalid\",\"password\":\"$PASSWORD\"}")"
CSRF="$(jq -r '.csrf_token' <<<"$LOGIN_JSON")"
echo 'staff login passed'
AI_SETTINGS="$(curl -fsS -b /tmp/dimdian-ci-cookies.txt 'http://127.0.0.1:8080/api/ai-settings.php')"
jq -e '.ai_enabled == false and .ai_available == false and .mode == "human_only"' <<<"$AI_SETTINGS" >/dev/null
curl -fsS -b /tmp/dimdian-ci-cookies.txt -X POST http://127.0.0.1:8080/api/staff-status.php -H "X-CSRF-Token: $CSRF" -H 'Content-Type: application/json' -d "{\"conversation_id\":\"$CONVERSATION_ID\",\"status\":\"open\"}" >/dev/null
AI_OFF="$(curl -fsS -b /tmp/dimdian-ci-cookies.txt -X POST http://127.0.0.1:8080/api/ai-settings.php -H "X-CSRF-Token: $CSRF" -H 'Content-Type: application/json' -d '{"ai_enabled":false}')"
jq -e '.ai_enabled == false and .mode == "human_only" and .routed_to_human == 1' <<<"$AI_OFF" >/dev/null
HUMAN_QUEUE="$(curl -fsS -b /tmp/dimdian-ci-cookies.txt 'http://127.0.0.1:8080/api/staff-conversations.php?status=needs_human')"
jq -e --arg id "$CONVERSATION_ID" '.conversations[] | select(.public_id == $id and .status == "needs_human")' <<<"$HUMAN_QUEUE" >/dev/null
echo 'admin AI-off switch and human routing passed'
QUEUE_JSON="$(curl -fsS -b /tmp/dimdian-ci-cookies.txt 'http://127.0.0.1:8080/api/staff-conversations.php?status=active')"
jq -e --arg id "$CONVERSATION_ID" '.conversations[] | select(.public_id == $id and .customer_account_id == "customer-123" and .context_sku == "BP-100")' <<<"$QUEUE_JSON" >/dev/null
echo 'staff inbox passed'
STAFF_REPLY="$(curl -fsS -b /tmp/dimdian-ci-cookies.txt -X POST http://127.0.0.1:8080/api/staff-messages.php -H "X-CSRF-Token: $CSRF" -H 'Content-Type: application/json' -d "{\"conversation_id\":\"$CONVERSATION_ID\",\"body\":\"Please share your vehicle model and year.\"}")"
STAFF_MESSAGE_ID="$(jq -r '.message.id' <<<"$STAFF_REPLY")"
CUSTOMER_REPLY="$(curl -fsS "http://127.0.0.1:8080/api/messages.php?conversation_id=$CONVERSATION_ID&after_id=$ATTACHMENT_ID" -H "X-Dimdian-Guest-Token: $TOKEN")"
jq -e '.messages[1].sender_type == "system" and .messages[2].sender_type == "staff"' <<<"$CUSTOMER_REPLY" >/dev/null
curl -fsS -b /tmp/dimdian-ci-cookies.txt -X POST http://127.0.0.1:8080/api/staff-status.php -H "X-CSRF-Token: $CSRF" -H 'Content-Type: application/json' -d "{\"conversation_id\":\"$CONVERSATION_ID\",\"status\":\"closed\"}" | jq -e '.status == "closed"' >/dev/null
CLOSED_POLL="$(curl -fsS "http://127.0.0.1:8080/api/messages.php?conversation_id=$CONVERSATION_ID&after_id=$STAFF_MESSAGE_ID" -H "X-Dimdian-Guest-Token: $TOKEN")"
jq -e '.status == "closed"' <<<"$CLOSED_POLL" >/dev/null
echo "API smoke test passed: guest identity, signed account link, conversation, messages, image upload/retrieval, staff login, reply, and closure."
