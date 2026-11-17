<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/**
 * Resolve a guest from the opaque bearer-like token sent in the custom header.
 * Only its SHA-256 digest is persisted; the raw token remains in the shopper's browser.
 */
function require_customer(): array {
 $token = $_SERVER['HTTP_X_DIMDIAN_GUEST_TOKEN'] ?? '';
 if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D',$token)) {
  json_response(['error'=>'guest_session_required'],401);
 }
 $digest = hash('sha256',$token);
 $statement = db()->prepare('SELECT id,display_name,email FROM customers WHERE guest_token_hash = :digest LIMIT 1');
 $statement->execute(['digest'=>$digest]);
 $customer = $statement->fetch();
 if (!$customer) json_response(['error'=>'guest_session_invalid'],401);
 db()->prepare('UPDATE customers SET last_seen_at = UTC_TIMESTAMP() WHERE id = :id')->execute(['id'=>$customer['id']]);
 return $customer;
}

function validate_context_url(mixed $url): ?string {
 if ($url === null || $url === '') return null;
 if (!is_string($url) || strlen($url) > 2048) json_response(['error'=>'invalid_context_url'],422);
 $parts = parse_url($url);
 if (!is_array($parts) || !isset($parts['scheme'],$parts['host']) || !in_array(strtolower($parts['scheme']),['http','https'],true)) {
  json_response(['error'=>'invalid_context_url'],422);
 }
 return $url;
}

function create_public_conversation_id(): string {
 // 26 URL-safe hexadecimal characters; the numeric database ID is never exposed.
 return bin2hex(random_bytes(13));
}
