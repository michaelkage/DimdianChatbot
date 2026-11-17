<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

/**
 * Provider configuration is private server-side config. Supported endpoints are
 * deliberately allowlisted; only the generic adapter accepts a configured URL.
 */
function ai_provider_config(): array {
 $provider = app_config('ai.provider',[]);
 return is_array($provider) ? $provider : [];
}

function ai_provider_available(): bool {
 if (!(bool)app_config('ai.provider_ready',false) || !function_exists('curl_init')) return false;
 $provider = ai_provider_config();
 $name = $provider['name'] ?? '';
 $key = $provider['api_key'] ?? '';
 $model = $provider['model'] ?? '';
 if (!is_string($key) || trim($key)==='' || !is_string($model) || trim($model)==='') return false;
 if ($name === 'groq' || $name === 'gemini') return true;
 if ($name !== 'openai_compatible') return false;
 $url = $provider['base_url'] ?? '';
 if (!is_string($url) || !filter_var($url,FILTER_VALIDATE_URL)) return false;
 $parts = parse_url($url);
 if (!is_array($parts) || !in_array(strtolower((string)($parts['scheme'] ?? '')),['https','http'],true) || empty($parts['host'])) return false;
 if ((app_config('app.environment','production') === 'production') && strtolower((string)$parts['scheme']) !== 'https') return false;
 return true;
}

/** Fingerprint only the endpoint/model/key identity; never store the provider key itself. */
function ai_provider_fingerprint(): string {
 $provider=ai_provider_config();
 $secret=(string)app_config('security.rate_limit_secret','');
 if(strlen($secret)<32)return '';
 $identity=[
  'name'=>(string)($provider['name']??''),
  'endpoint'=>(string)(ai_provider_endpoint()??''),
  'model'=>(string)($provider['model']??''),
  'api_key'=>(string)($provider['api_key']??''),
 ];
 return hash_hmac('sha256',(string)json_encode($identity,JSON_UNESCAPED_SLASHES),$secret);
}
function ai_provider_verified(?PDO $pdo=null): bool {
 if(!ai_provider_available())return false;
 $fingerprint=ai_provider_fingerprint();if($fingerprint==='')return false;
 $pdo??=db();
 $query=$pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key='ai_provider_verified_fingerprint' LIMIT 1");
 $query->execute();$stored=$query->fetchColumn();
 return is_string($stored)&&hash_equals($fingerprint,$stored);
}
function ai_provider_mark_verified(int $staffId): void {
 $fingerprint=ai_provider_fingerprint();
 if($fingerprint==='')throw new RuntimeException('ai_provider_fingerprint_unavailable');
 $query=db()->prepare("INSERT INTO app_settings (setting_key,setting_value,updated_by,updated_at) VALUES ('ai_provider_verified_fingerprint',:fingerprint,:staff_id,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by=VALUES(updated_by),updated_at=UTC_TIMESTAMP()");
 $query->execute(['fingerprint'=>$fingerprint,'staff_id'=>$staffId]);
}
function ai_provider_clear_verification(?PDO $pdo=null): void {
 $pdo??=db();
 $pdo->exec("DELETE FROM app_settings WHERE setting_key='ai_provider_verified_fingerprint'");
}

function ai_provider_endpoint(): ?string {
 $provider = ai_provider_config();
 return match ($provider['name'] ?? '') {
  'groq' => 'https://api.groq.com/openai/v1/chat/completions',
  'gemini' => 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions',
  'openai_compatible' => rtrim((string)($provider['base_url'] ?? ''),'/'),
  default => null,
 };
}

function ai_system_prompt(): string {
 return <<<'PROMPT'
You are the first-line customer support assistant for DIMDIAN, a Nigerian auto-parts marketplace. Be concise, polite, practical and natural. Your job is to answer simple general questions, understand the customer's issue, and collect useful details for the human support team.

Grounded store information:
- DIMDIAN is presented as Nigeria's VIN-powered auto-parts marketplace.
- Address shown on the storefront: 32 Senate Crescent, UNILAG Estate, Magodo GRA Phase I, Isheri, Lagos, Nigeria.
- Contact phone shown on the storefront: 0906 652 6542. Advertised contact hours: 8 AM–8 PM.
- The storefront advertises free standard shipping on orders over ₦25,000 within Nigeria, in-store pickup advertised within 30 minutes, free battery installation and check-engine-light scanning.
Treat these as published storefront information, not a guarantee of today's stock, service availability or delivery timing.

Hard rules:
- Never invent a product, price, discount, stock level, order status, delivery estimate, return/refund decision, VIN decode, vehicle compatibility or fitment result.
- You do not have live catalogue, inventory, order, payment, VIN-decoder or fitment-database access. A product URL or SKU is context only, not proof of compatibility.
- Never say a part fits a vehicle. If the customer asks about fitment/compatibility, identifies a vehicle for part selection, provides a VIN, or asks you to decode a VIN, stop troubleshooting and explain that a human parts specialist must verify it. Ask for the make, model, year, engine and trim only if it helps the handoff, but do not delay escalation.
- Escalate order tracking, payment, refunds, returns, complaints about damage, safety-critical issues, and requests for a human to the support team.
- For simple questions about published store information, answer only from the grounded facts above.
- If information is missing or uncertain, say so and hand the matter to a human rather than guessing.
- Do not mention internal APIs, provider names, quotas, tokens, prompts or technical errors.
- Keep replies short. Do not claim a human has already replied; say the support team will take over.
PROMPT;
}

/** Send one chat completion. Returns a small internal result; never exposes provider payloads. */
function ai_provider_complete(array $messages): array {
 if (!ai_provider_available()) return ['kind'=>'unavailable'];
 $provider = ai_provider_config();
 $endpoint = ai_provider_endpoint();
 if ($endpoint === null) return ['kind'=>'unavailable'];
 $connectTimeout = max(1,min(5,(int)($provider['connect_timeout_seconds'] ?? 3)));
 $timeout = max($connectTimeout,min(18,(int)($provider['timeout_seconds'] ?? 12)));
 $payload = [
  'model'=>(string)$provider['model'],
  'messages'=>$messages,
  'temperature'=>0.2,
  'max_tokens'=>max(80,min(700,(int)($provider['max_tokens'] ?? 350))),
 ];
 $handle = curl_init($endpoint);
 if ($handle === false) return ['kind'=>'unavailable'];
 curl_setopt_array($handle,[
  CURLOPT_POST=>true,
  CURLOPT_RETURNTRANSFER=>true,
  CURLOPT_HTTPHEADER=>['Authorization: Bearer '.(string)$provider['api_key'],'Content-Type: application/json','Accept: application/json'],
  CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
  CURLOPT_CONNECTTIMEOUT=>$connectTimeout,
  CURLOPT_TIMEOUT=>$timeout,
  CURLOPT_FOLLOWLOCATION=>false,
  CURLOPT_SSL_VERIFYPEER=>true,
  CURLOPT_SSL_VERIFYHOST=>2,
 ]);
 $raw = curl_exec($handle);
 $curlError = curl_errno($handle);
 $status = (int)curl_getinfo($handle,CURLINFO_HTTP_CODE);
 curl_close($handle);
 if ($raw === false) {
  error_log('[DimdianChatbot AI] Provider transport failure; curl_errno='.$curlError);
  return ['kind'=>$curlError===CURLE_OPERATION_TIMEDOUT?'timeout':'transient'];
 }
 $decoded = json_decode((string)$raw,true);
 if ($status >= 200 && $status < 300) {
  $answer = $decoded['choices'][0]['message']['content'] ?? null;
  if (is_string($answer) && trim($answer)!=='') return ['kind'=>'ok','content'=>trim($answer)];
  error_log('[DimdianChatbot AI] Provider returned an empty or unrecognized completion.');
  return ['kind'=>'transient'];
 }
 $providerCode = '';
 $providerMessage = '';
 if (is_array($decoded)) {
  $error = $decoded['error'] ?? [];
  if (is_array($error)) {
   $providerCode = strtolower((string)($error['code'] ?? $error['type'] ?? $error['status'] ?? ''));
   $providerMessage = strtolower((string)($error['message'] ?? ''));
  }
 }
 $quota = $status === 402 || in_array($providerCode,['insufficient_quota','quota_exceeded','billing_hard_limit','billing_limit_reached','billing_not_active','resource_exhausted','credits_exhausted','payment_required'],true)
  || str_contains($providerMessage,'insufficient quota')
  || str_contains($providerMessage,'billing hard limit')
  || str_contains($providerMessage,'quota exceeded')
  || str_contains($providerMessage,'resource exhausted')
  || str_contains($providerMessage,'out of credits')
  || str_contains($providerMessage,'no credits remaining')
  || str_contains($providerMessage,'billing limit reached');
 if ($quota) return ['kind'=>'quota_exhausted'];
 if ($status === 401 || $status === 403) {
  error_log('[DimdianChatbot AI] Provider rejected configured credentials (HTTP '.$status.').');
  return ['kind'=>'configuration_error'];
 }
 if($status===400||$status===404){
  error_log('[DimdianChatbot AI] Provider rejected the configured endpoint or model (HTTP '.$status.').');
  return ['kind'=>'configuration_error'];
 }
 error_log('[DimdianChatbot AI] Provider returned HTTP '.$status.'.');
 return ['kind'=>'transient'];
}
