# Supabase integration for the GitHub Pages mock site

This folder contains an isolated test integration for the public mock site:

- GitHub Pages remains a static frontend.
- Supabase Edge Functions receive Meta's HTTPS webhook.
- Supabase Postgres stores a minimal event log for verification.
- The existing Hostinger/PHP production backend is not changed or called by this integration.

## Current implementation

- `functions/meta-webhook/index.ts`: Meta GET verification and POST event receiver.
- `functions/meta-events/index.ts`: owner-only, read-only event viewer API.
- `config.toml`: disables Supabase's JWT gate for both functions; each function performs its own authentication.
- `schema.sql`: private event-log table with RLS enabled and no browser-role policies.
- `../docs/whatsapp-test.html`: static owner test console. The owner enters the project URL and monitor key; the key is not stored by the page.

The webhook verifies the exact raw POST body using HMAC-SHA256 and `X-Hub-Signature-256` before parsing or storing it. It stores deduplicated event identifiers, event type, masked phone numbers, message text, timestamps and a SHA-256 payload hash. It does not store the complete raw webhook payload or an access token.

The event viewer requires a separate `DEMO_MONITOR_ACCESS_KEY`, checks the exact GitHub Pages Origin, and returns only the latest 50 selected event fields. The browser never receives the Supabase database credential.

## Setup after the Supabase project is created

1. Open the Supabase SQL Editor and run `supabase/schema.sql`.
2. In Edge Function Secrets, add:
   - `META_APP_SECRET`: the Meta app secret.
   - `META_WEBHOOK_VERIFY_TOKEN`: a long random value you create; this is not the Meta app secret.
   - `DEMO_MONITOR_ACCESS_KEY`: a separate long random value for the owner-only event viewer.
   - `META_PHONE_NUMBER_ID` (optional): restrict event recording to one expected Meta phone-number ID.
3. Deploy both functions, `meta-webhook` and `meta-events`, using the Supabase CLI or a supported dashboard deployment workflow. The root `supabase/config.toml` contains the required `verify_jwt = false` settings.
4. Set the Meta WhatsApp webhook callback URL to:
   `https://PROJECT_REF.supabase.co/functions/v1/meta-webhook`
5. Set the exact same `META_WEBHOOK_VERIFY_TOKEN` value in Meta's Verify Token field.
6. Subscribe to the WhatsApp Business Account fields required for the test, including `messages` and `smb_message_echoes` if Meta exposes the latter for the connected Coexistence setup.
7. Open `https://michaelkage.github.io/DimdianChatbot/whatsapp-test.html`, enter the project URL and monitor key, and verify that events appear after Meta sends them.

Do not put the Meta App Secret, access token, verify token, monitor key, or Supabase secret key in `docs/`, GitHub Pages JavaScript, or any public browser code. The monitor key is entered by the owner at runtime and held only in page memory.

## Important limits

- This is a webhook receiver and event recorder, not yet the full live chat bridge.
- The public mock widget remains in explicit simulation mode; it does not send messages to or receive replies from WhatsApp.
- The event viewer is a private diagnostic view, not a customer-facing inbox.
- Coexistence eligibility and real `smb_message_echoes` delivery for the actual business number are still unverified.
- Do not onboard, migrate, or register the live business number until the owner explicitly approves the exact Meta flow.

## Local checks

The functions are Deno/Edge Runtime code. Test them with the Supabase CLI's local Edge Runtime before deploying. Verify at least:
- valid GET challenge returns the exact challenge as plain text;
- invalid verify token returns 403;
- a valid HMAC POST is accepted;
- an invalid HMAC POST returns 401 and writes nothing;
- repeated provider message IDs do not create duplicate rows;
- the monitor rejects a missing/incorrect access key and any Origin other than the GitHub Pages origin.
