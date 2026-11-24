# Public API contract

For the versioned compatibility entry points under /api/v1, see [API v1](API-V1.md). The existing /api/*.php routes remain supported during migration.

Base path: `/api`. All responses are JSON except the Meta webhook verification handshake. Timestamps are UTC ISO-8601 strings.

## Operational health check

`GET /api/health.php`

Returns a small, read-only status report for the PHP chat API, its MySQL connection and the required chat tables. It does not return credentials, customer data or raw SQL errors. A 200 response with `status: healthy` confirms only those chat components.

This endpoint does **not** test WordPress, WooCommerce, WCFM, Paystack, Meta connectivity or live WhatsApp delivery. It is intended as a first diagnostic check for the business owner; detailed errors remain in the private server PHP error log.

## Authentication

All conversation endpoints require `X-DIMDIAN-GUEST-TOKEN`. The token is returned once by session creation. Only its SHA-256 hash is stored. Tokens are never accepted in query strings.

## Create a guest session

`POST /api/session.php`

Body fields are optional except that WhatsApp opt-in requires a phone number:

```json
{
  "name": "Ada",
  "phone": "+2348012345678",
  "whatsapp_opt_in": true
}
```

No account, password, phone verification or OTP is required. If a phone number is supplied, it must include an international country-code prefix (+ or 00); local trunk formats such as 080... are rejected. The opt-in timestamp is stored separately from the contact number. Each opt-in is also appended to `guest_consent_events` and creates one active `whatsapp_identity_links` route. The session response includes the current `whatsapp_opt_in` state.

## Send a website message

`POST /api/messages.php`

Headers: `Content-Type: application/json`, `X-DIMDIAN-GUEST-TOKEN`

Body: `{"message":"Hi, I have a question.","client_message_id":"550e8400-e29b-41d4-a716-446655440000"}`

The widget supplies a UUID `client_message_id`. Repeating the same ID and same message in the same conversation returns the original record instead of creating a duplicate. Reusing the ID with different text returns 409.

Messages are stored in the authenticated conversation. The response confirms database persistence only. It never claims WhatsApp delivery.

- If WhatsApp is enabled and the visitor explicitly opted in with a phone number, a template-send job is written to the durable outbox in the same transaction.
- Otherwise the message is marked `web_only` and remains available in the website chat.
- The outbox worker later changes status to `sent` after Meta accepts the request; webhook status callbacks can advance it to `delivered`, `read` or `failed`.

## Poll messages and delivery states

`GET /api/messages.php?after=0&limit=50`

The cursor returns new messages in sequence order. `meta.delivery_updates` also returns the latest 50 visitor-message delivery states, allowing the widget to reflect webhook status changes even when no new message was added.

A valid session may continue reading history after a conversation is closed, but cannot submit new messages. Expired/revoked sessions return 401.

## Meta webhook

`GET|POST /api/webhook.php`

- GET validates Meta's `hub.verify_token` handshake and returns `hub.challenge`.
- POST validates `X-Hub-Signature-256` over the exact raw body with the Meta App Secret.
- `messages` text events from the visitor are matched through the single active, consent-backed phone-to-conversation link.
- `smb_message_echoes` text events from the Business app are matched through that same explicit route using the echo recipient.
- `messages.statuses` updates outbound message delivery states.
- Provider event keys and raw-payload hashes prevent duplicate processing.

The endpoint verifies the signature, encrypts the exact raw payload with AES-256-GCM and commits it to `webhook_inbox` before returning 200. It attempts one inline processing pass; `public/cron/process-webhook-inbox.php` recovers pending and expired-lease jobs. A transient processing error does not discard the accepted payload.

## Error format

```json
{"error":{"code":"invalid_field","message":"The supplied value is invalid."}}
```

Relevant statuses: 400 malformed JSON, 401 invalid/expired session or webhook signature, 403 failed webhook verification, 405 method mismatch, 409 closed conversation, 413 oversized body, 415 unsupported content type, 422 invalid fields, 429 rate limited, 500 unexpected failure, 503 app not configured.

## Security notes

- Guest tokens are 256-bit random values; only hashes are persisted.
- CORS is restricted to exact configured website origins.
- Webhook signatures are checked before parsing.
- Verified webhook payloads are encrypted at rest in the durable inbox; processed inbox rows are removed by the retention job.
- Consent changes are append-only in the consent ledger.
- The cron workers are CLI-only and cannot be invoked over HTTP.
- No production credentials belong in Git.


## Change WhatsApp consent

`POST /api/consent.php`

Headers: `Content-Type: application/json`, `X-DIMDIAN-GUEST-TOKEN`

Body: `{"whatsapp_opt_in":false}` to withdraw WhatsApp continuation. Website chat remains available. Re-opt-in is supported only when the guest session already has a valid international phone number. Every change is appended to the consent ledger. Withdrawal disables the active phone-to-conversation route and cancels pending outbound jobs for that guest.

## Meta Embedded Signup (administrator only)

`POST /api/meta-embedded-signup.php`

Requires `X-DIMDIAN-ADMIN-KEY` and a JSON body. `{"action":"begin"}` returns the Meta App ID, Embedded Signup configuration ID and a one-time state valid for ten minutes. `{"action":"exchange","state":"…","code":"…","phone_number_id":"…","waba_id":"…" }` exchanges the code server-side and stores the access token encrypted. The token is never returned. The endpoint has no public CORS policy; do not expose the admin key in browser JavaScript. A secure server-side operator proxy is required for a browser onboarding UI.

The administrator-only Meta endpoint also accepts `{"action":"subscribe"}` to retry subscribing the active WABA to this app's webhooks if the automatic post-exchange subscription did not succeed. The outbox worker attempts to refresh expiring 60-day system-user tokens when they enter the seven-day renewal window; a failed refresh is logged and the still-valid token is used until expiry.


## Request correlation and diagnostics

Every API request receives an opaque `X-DIMDIAN-REQUEST-ID` response header. Operational failures are written as structured JSON records to the private PHP error log. The logger allowlists diagnostic fields and excludes phone numbers, guest tokens, message bodies, access tokens and raw provider responses. Use the response request ID when correlating an issue with server logs.
