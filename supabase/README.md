# Supabase integration for the GitHub Pages mock site

This folder contains an isolated test integration for the public mock site:

- GitHub Pages remains a static frontend.
- Supabase Edge Functions receive Meta's HTTPS webhook.
- Supabase Postgres stores a minimal event log for verification.
- The existing Hostinger/PHP production backend is not changed or called by this integration.

## Current implementation

- `functions/meta-webhook/index.ts`: Meta GET verification and POST event receiver.
- `config.toml`: disables Supabase's JWT gate for this one function because Meta signs requests with the Meta App Secret instead.
- `schema.sql`: private event-log table with RLS enabled and no browser-role policies.

The function verifies the exact raw POST body using HMAC-SHA256 and `X-Hub-Signature-256` before parsing or storing it. It stores deduplicated event identifiers, event type, masked phone numbers, message text, timestamps and a SHA-256 payload hash. It does not store the complete raw webhook payload or access token.

## Setup after the Supabase project is created

1. Open the Supabase SQL Editor and run `supabase/schema.sql`.
2. In Edge Function Secrets, add:
   - `META_APP_SECRET`: the Meta app secret.
   - `META_WEBHOOK_VERIFY_TOKEN`: a long random value you create; this is not the Meta app secret.
3. Deploy the `meta-webhook` function from the repository using the Supabase CLI or the Supabase dashboard's supported deployment workflow.
4. Set the Meta WhatsApp webhook callback URL to:
   `https://PROJECT_REF.supabase.co/functions/v1/meta-webhook`
5. Set the exact same `META_WEBHOOK_VERIFY_TOKEN` value in Meta's Verify Token field.
6. Subscribe to the WhatsApp Business Account fields required for the test, including `messages` and `smb_message_echoes` if Meta exposes the latter for the connected Coexistence setup.
7. Send a test event and confirm it appears in the private table.

Do not put the Meta App Secret, access token, verify token, or Supabase secret key in `docs/`, GitHub Pages JavaScript, or any public browser code.

## Important limits

- This is a webhook receiver and event recorder, not yet the full live chat bridge.
- The public mock widget remains in explicit simulation mode until a separate browser-safe demo API is implemented.
- There is no event viewer endpoint yet; the event table is not readable by anonymous visitors.
- Coexistence eligibility and real `smb_message_echoes` delivery for the actual business number are still unverified.
- Do not onboard, migrate, or register the live business number until the owner explicitly approves the exact Meta flow.

## Local checks

The function is Deno/Edge Runtime code. Test it with the Supabase CLI's local Edge Runtime before deploying. Verify at least:
- valid GET challenge returns the exact challenge as plain text;
- invalid verify token returns 403;
- a valid HMAC POST is accepted;
- an invalid HMAC POST returns 401 and writes nothing;
- repeated provider message IDs do not create duplicate rows.
