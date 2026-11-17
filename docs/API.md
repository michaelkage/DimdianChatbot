# Initial API contract

Base path: `/api`. Responses are JSON. All timestamps are UTC ISO-8601 strings.

## Create a guest chat session

`POST /api/session.php`

Headers:

- `Content-Type: application/json`
- `Origin: https://the-embedding-website.example` (sent by browsers; must match `app.allowed_origins`)

Body (both fields optional):

```json
{
  "name": "Ada",
  "phone": "+2348012345678"
}
```

No account, password, phone verification, or OTP is required. The visitor can omit both contact fields.

Success: HTTP `201 Created`

```json
{
  "data": {
    "session_id": "uuid",
    "conversation_id": "uuid",
    "session_token": "64-character-random-hex-token",
    "expires_at": "2026-11-24T12:00:00+00:00",
    "created_at": "2026-11-17T12:00:00+00:00"
  }
}
```

The plaintext `session_token` is returned once. The browser should keep it in its own storage and send it on later chat API requests as `X-DIMDIAN-GUEST-TOKEN`. The database stores only its SHA-256 hash. Do not put the token in a URL, log it, or expose it to the embedding page's server.

Errors use this shape:

```json
{
  "error": {
    "code": "invalid_json",
    "message": "Send a valid JSON object."
  }
}
```

Relevant status codes: `400` malformed JSON, `403` disallowed origin, `405` wrong method, `413` oversized body, `415` wrong content type, `422` invalid optional field, `429` rate limited, `500` unexpected server failure, `503` missing configuration.

## Session security model

- Tokens contain 256 bits of cryptographic randomness.
- Only a one-way SHA-256 token hash is persisted.
- Session records expire after the configured TTL (default seven days).
- Session creation is rate-limited by an HMAC of the remote IP address; raw IP addresses are not stored.
- CORS is restricted to exact origins in `app.allowed_origins`; wildcard origins are not supported.
- Phone number is contact metadata only. It is not used to authenticate or gate access.

## Not implemented yet

Message submission, message polling, WhatsApp outbound delivery, webhook verification, and widget JavaScript are deliberately not claimed as available by this first slice.
