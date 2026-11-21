# Production hardening implementation

This document describes the hardening layer added to the PHP/MySQL service. It preserves the product boundary: the website widget remains guest-first, MySQL remains authoritative, WhatsApp Business app continuation is conditional on confirmed Meta Coexistence support, and Supabase remains a separate diagnostic receiver for the GitHub Pages demo.

## Implemented controls

- **Configuration:** `app/bootstrap.php` supports private `config.php` values with optional `DIMDIAN_*` environment overrides. Environment values win. Secrets are never returned by health endpoints.
- **Consent lifecycle:** `guest_consent_events` is an append-only audit ledger. `guests.whatsapp_opt_in_at` remains the current authorization flag. The widget offers an explicit opt-out after a session starts; `POST /api/consent.php` records withdrawal, disables the active route and cancels pending WhatsApp sends.
- **Exact conversation correlation:** `whatsapp_identity_links` enforces one active conversation per normalized WhatsApp number. A new explicit opt-in moves the active route to the newly consented conversation. Old conversations remain in MySQL but no longer receive phone-number echoes.
- **Durable webhook intake:** `POST /api/webhook.php` verifies Meta's HMAC signature, encrypts the raw payload with AES-256-GCM, commits it to `webhook_inbox`, and only then acknowledges Meta. It attempts one inline processing pass for low latency; the CLI worker recovers pending or expired-lease jobs after a crash.
- **Meta Embedded Signup:** `POST /api/meta-embedded-signup.php` provides admin-key-protected `begin` and `exchange` actions. It consumes a one-time, ten-minute state, exchanges the Meta code server-side, and encrypts the resulting access token at rest. The outbox worker prefers this stored connection over static token configuration.
- **Retention:** `public/cron/retention.php` removes old closed conversations and related records, expired sessions, old processed webhook data and stale rate-limit buckets. Open conversations are not automatically deleted.
- **Health:** `/api/health.php` checks the new required tables but does not disclose secrets or claim to test live Meta delivery.

## Database upgrade

Back up the database first. For an existing installation, apply `database/migrations/004_production_hardening.sql` once after migrations 002 and 003. For a new installation, import the complete `database/schema.sql` only; do not run migrations 002–004 afterward.

The migration creates `guest_consent_events`, `whatsapp_identity_links`, `webhook_inbox`, `meta_signup_states` and `meta_connections`. Run it in staging first and confirm `/api/health.php` reports `healthy`.

## Configuration

Copy `config.example.php` to private `config.php`. Important settings:

| Setting | Requirement |
|---|---|
| `app.app_key` | At least 32 random characters; derives independent AES-GCM keys for webhook payloads and Meta tokens |
| `meta.app_id` | Meta Developer App ID |
| `meta.config_id` | WhatsApp Embedded Signup configuration ID |
| `meta.admin_api_key` | At least 32 random characters; protect the onboarding endpoint |
| `whatsapp.app_secret` | Meta App Secret; used for webhook signature verification and OAuth code exchange |
| `whatsapp.verify_token` | Random Meta webhook verification token |
| `whatsapp.graph_api_version` | Graph API version enabled for the Meta app |
| `whatsapp.outbound_enabled` | Keep `false` until Coexistence, approved template, callbacks and opt-out have been tested |

Supported environment overrides: `DIMDIAN_APP_KEY`, `DIMDIAN_DATABASE_DSN`, `DIMDIAN_DATABASE_USERNAME`, `DIMDIAN_DATABASE_PASSWORD`, `DIMDIAN_META_APP_ID`, `DIMDIAN_META_CONFIG_ID`, `DIMDIAN_META_ADMIN_API_KEY`, `DIMDIAN_WHATSAPP_ACCESS_TOKEN`, `DIMDIAN_WHATSAPP_APP_SECRET`, `DIMDIAN_WHATSAPP_VERIFY_TOKEN`, `DIMDIAN_WHATSAPP_PHONE_NUMBER_ID`, `DIMDIAN_WHATSAPP_GRAPH_API_VERSION`, and `DIMDIAN_WHATSAPP_OUTBOUND_ENABLED`.

Do not put `meta.admin_api_key`, the App Secret or access tokens in the widget, WordPress options, GitHub Pages or client-side JavaScript.

## Meta Embedded Signup sequence

1. Configure the Meta Developer App, WhatsApp product, Embedded Signup configuration, allowed domains and required permissions in Meta Business settings.
2. Configure `meta.app_id`, `meta.config_id`, `whatsapp.app_secret`, `whatsapp.graph_api_version`, and a random `meta.admin_api_key` in private configuration.
3. An authenticated operator calls `POST /api/meta-embedded-signup.php` with `X-DIMDIAN-ADMIN-KEY` and JSON `{"action":"begin"}`.
4. The server returns the app ID, config ID and one-time state. A secure operator page initializes the Meta JavaScript SDK and passes the state through Embedded Signup. Configure the SDK to return an authorization code.
5. After Meta returns the code and the selected `phone_number_id` and `waba_id`, the secure operator page calls the same endpoint with `{"action":"exchange","state":"…","code":"…","phone_number_id":"…","waba_id":"…" }`.
6. The server consumes the state once, exchanges the code and stores the token encrypted. The token is never returned.
7. Test webhook delivery and `smb_message_echoes` with a test number before enabling outbound messages.

The endpoint deliberately has no public CORS policy and requires an administrator secret. Do not expose that secret in a browser bundle. A production operator UI should proxy these actions through an authenticated server-side WordPress/admin session; that UI is not included.

## Scheduled jobs

Use the actual Hostinger PHP CLI path and absolute project path. Schedule:

- `public/cron/process-outbox.php` — every minute after WhatsApp outbound has been deliberately enabled.
- `public/cron/process-webhook-inbox.php` — every minute.
- `public/cron/retention.php` — daily during a quiet period.

All scripts are CLI-only. They return non-zero on fatal errors and write operational summaries to stdout; details remain in the private PHP error log.

## Operational limits and launch gates

- Delivery remains at-least-once. A process can die after Meta accepts a send but before the provider ID is committed; a retry can therefore create a duplicate send.
- Embedded Signup token expiry is stored and checked, but token refresh is an operator action. Reconnect through Embedded Signup when expired.
- Coexistence eligibility and Business App echo delivery must be confirmed with Meta. A successful OAuth exchange does not prove replies will be mirrored.
- The PHP backend and Supabase demo receiver are separate. Do not point production Meta webhooks at the demo Supabase function.
- Retention defaults are closed conversations 365 days, processed webhook events 30 days, and expired sessions 30 days. Confirm these against the privacy notice and applicable legal requirements.
- No staff accounts, shared inbox, or staff reply dashboard are introduced. Website-only messages still need an agreed operational response channel.
