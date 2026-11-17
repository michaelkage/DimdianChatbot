# Public API contract

Base path: `/api`. All responses are JSON and timestamps are UTC ISO-8601 strings.

## Authentication

All conversation endpoints require:

```http
X-DIMDIAN-GUEST-TOKEN: <64-character-session-token>
```

The token is returned once by session creation. Only its SHA-256 hash is stored in the database. Tokens are never accepted in query strings.

## Create a guest chat session

`POST /api/session.php`

Headers: `Content-Type: application/json`

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

## Send a visitor message

`POST /api/messages.php`

Headers: `Content-Type: application/json`, `X-DIMDIAN-GUEST-TOKEN`

Body:

```json
{
  "message": "Hi, I have a question about your service."
}
```

- Message text is trimmed, must not be empty, and is limited to 4,000 Unicode characters.
- Per-session rate limit defaults to 8 messages per 60 seconds.
- The message is stored in the conversation with `sender_type=visitor` and `delivery_status=queued`.
- HTTP `201 Created` confirms database persistence only. It does **not** mean WhatsApp received the message.
- The response explicitly reports `transport=not_configured` until the WhatsApp outbound adapter is implemented.

Example success:

```json
{
  "data": {
    "id": "uuid",
    "sequence_id": 1,
    "conversation_id": "uuid",
    "sender": "visitor",
    "message": "Hi, I have a question about your service.",
    "delivery_status": "queued",
    "created_at": "2026-11-17T12:00:00+00:00"
  },
  "meta": {
    "transport": "not_configured",
    "note": "Your message is saved. WhatsApp delivery will become available when the WhatsApp integration is configured."
  }
}
```

## Poll for new messages

`GET /api/messages.php?after=0&limit=50`

Headers: `X-DIMDIAN-GUEST-TOKEN`

- `after` is the last `sequence_id` received; defaults to `0`.
- `limit` is optional and clamped to 1–100; defaults to `50`.
- Results are ordered by the monotonically increasing `sequence_id`, not UUID or timestamp.
- Save `meta.next_cursor` and pass it as `after` on the next request.
- Poll approximately every 3 seconds while the widget is open. Back off when the tab is hidden or requests fail.

Example response:

```json
{
  "data": [
    {
      "id": "uuid",
      "sequence_id": 1,
      "sender": "visitor",
      "message": "Hi, I have a question.",
      "delivery_status": "queued",
      "created_at": "2026-11-17T12:00:00+00:00"
    }
  ],
  "meta": {
    "after": 0,
    "next_cursor": 1,
    "has_more": false,
    "poll_after_ms": 3000
  }
}
```

The endpoint returns both visitor and business messages for the authenticated conversation. At this stage only visitor messages can be created through the public API; business replies will enter through the future verified WhatsApp webhook.

## Error format

```json
{
  "error": {
    "code": "invalid_json",
    "message": "Send a valid JSON object."
  }
}
```

Relevant status codes:

| Status | Meaning |
|---|---|
| 400 | Malformed JSON |
| 401 | Missing, invalid, revoked or expired guest token |
| 403 | Disallowed browser origin |
| 405 | Wrong HTTP method |
| 409 | Conversation closed |
| 413 | Request body too large |
| 415 | Wrong content type |
| 422 | Invalid field, cursor or limit |
| 429 | Rate limit exceeded |
| 500 | Unexpected server failure |
| 503 | Application configuration missing |

## Security and operational notes

- Session tokens contain 256 bits of cryptographic randomness.
- Only a one-way SHA-256 token hash is persisted.
- Session records expire after the configured TTL (default seven days).
- Session creation is rate-limited by an HMAC of the remote IP address; raw IP addresses are not stored.
- Message submission is rate-limited by guest session.
- CORS is restricted to exact origins in `app.allowed_origins`; wildcard origins are not supported.
- Phone number is optional contact metadata, never an authentication gate.
- API responses are marked `Cache-Control: no-store`.
- Do not log guest tokens or put them in URLs.

## Current limitations

Message persistence and polling are implemented. WhatsApp outbound delivery, delivery-status callbacks, inbound webhook verification, app-originated reply correlation, and the browser widget are not yet implemented. A saved visitor message currently remains `queued` until the transport worker/adapter is added.
