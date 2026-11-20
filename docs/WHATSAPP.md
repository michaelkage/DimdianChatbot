# WhatsApp integration boundary

This integration is deliberately disabled by default. Do not enable it against the live business number until WhatsApp Business App Coexistence, the actual number's eligibility, and app-originated message echo delivery have been verified.

## Visitor consent and outbound template

- The phone field remains optional.
- A visitor must enter a full international phone number and explicitly tick the WhatsApp consent checkbox before the backend queues a WhatsApp send.
- No OTP or account is created.
- The first website message is sent to that visitor's WhatsApp number using an approved Meta message template. The template must contain exactly one BODY text parameter, represented as `{{1}}`, which receives the visitor's message.
- If there is no number, no opt-in, or outbound transport is disabled, the message remains available in the website chat and is marked `web_only`.
- Create and approve the template in WhatsApp Manager before enabling the worker. Template name and language must match the approved template.

The template path is intentional: a message typed on a website does not itself open a WhatsApp customer-service window. Meta may reject a free-form business-initiated message when no WhatsApp service window is open.

## Configuration

Copy the relevant values into the private `config.php` file:

| Key | Purpose |
|---|---|
| `whatsapp.outbound_enabled` | Must remain false until the integration is verified |
| `whatsapp.graph_api_version` | Supported Graph API version, e.g. `vXX.X` |
| `whatsapp.phone_number_id` | Legacy single-tenant phone-number ID for the CLI sender |
| `whatsapp.access_token` | Legacy single-tenant access token with WhatsApp messaging permission |
| `whatsapp.app_secret` | Meta App Secret used to validate webhook POST signatures |
| `whatsapp.verify_token` | Private random string used only for the GET subscription handshake |
| `whatsapp.template_name` | Approved template name with one BODY text parameter |
| `whatsapp.template_language` | Approved template language code |

Never commit production tokens, secrets, or verify tokens.

## Outbox worker

The public message API writes the visitor message and an outbox job in the same database transaction when the visitor opted in and outbound transport is enabled. A separate CLI worker sends the template. It is not executable over HTTP.

Run it from a hosting cron task, for example once per minute:

```sh
php /absolute/path/to/app/public/cron/process-outbox.php
```

The worker claims one due job under a database row lock, sends outside the transaction, then records the provider message ID. The outbox sends to the visitor's opted-in WhatsApp number using the configured approved template; it does not send to a staff number. Retryable failures use exponential backoff (15 seconds, doubling to a one-hour cap), up to six attempts. Permanent failures become `failed`. Stale processing locks can be reclaimed after five minutes only while attempts remain; a worker lease that expires during the sixth attempt is marked failed rather than triggering a seventh send.

Delivery is at-least-once, not exactly-once: if Meta accepts a message but the process dies before recording the response, a later retry could send it again. The webhook deduplicates status and message events by provider message ID.

## Webhook

Configure the callback URL as:

```text
https://YOUR-API-HOST/api/webhook.php
```

The GET subscription handshake checks `hub.mode=subscribe` and `hub.verify_token`, then returns `hub.challenge` as plain text.

Every POST is checked against `X-Hub-Signature-256` using HMAC-SHA256 over the exact raw request body and the Meta App Secret. Invalid signatures are rejected before JSON parsing or database access. After the tenant migration, each webhook change is resolved by its `metadata.phone_number_id` against a connected `channel_connections` row. Unknown or disconnected numbers are ignored; a global configured phone-number ID is no longer the tenant resolver.

Subscribe to the WhatsApp webhook fields required by the current Embedded Signup/Coexistence configuration:
- `account_update`: account connection, partner removal and disconnection lifecycle events.
- `messages`: inbound visitor messages and sent/delivered/read/failed statuses.
- `smb_message_echoes`: messages sent from the WhatsApp Business app on supported Coexistence integrations.
- `history` and `smb_app_state_sync`: history/contact synchronization events required by the Coexistence lifecycle; the current PHP runtime does not yet ingest or synchronize these payloads.

Inbound text messages are matched to an open website conversation using the normalized visitor phone number. Business-app text echoes are matched using the echo's `to` number. The matching visitor must have supplied the same international phone number. Unsupported message types and unmatched phone numbers are safely ignored; media is not implemented.

Webhook events are persisted with unique event keys so Meta retries do not duplicate messages. The raw payload is hashed, not stored. Status events are also stored separately so a fast status callback that arrives before the send worker records its WAMID can be reconciled when the send response is saved. Status transitions do not downgrade a delivered/read state.

## Coexistence caveat

The `smb_message_echoes` event is the mechanism used to mirror text replies sent from the Business app back into the website conversation. It is not safe to assume that every onboarding path or country/account supports this event. Confirm actual +234-number eligibility and test a real app-originated reply before enabling production traffic. If the webhook echo does not arrive, website visitors will not see staff replies from the Business app.

## Current scope

Text messages only. No media download, attachments, voice notes, group chats, template management, message editing/deletion sync, or automatic conversation assignment.

## Embedded Signup and Coexistence onboarding

### Supported Embedded Signup version

As of 20 November 2026, implement against **Embedded Signup v5**, released on 20 October 2026. Meta deprecated v2 and v3 on 15 October 2026. v5 changes the session-info contract and Coexistence defaults; it is not just a cosmetic version bump. The v5 session event can provide `messaging_account_ids`, `primary_waac_ids` and `is_on_biz_app`; code must not assume the old `waba_id` property is always present. The old `sessionInfoVersion` parameter is no longer used by v5. WhatsApp Business app onboarding is on by default in v5, with an opt-out.

Sources: [Meta Embedded Signup v5 update summary](https://easytawasul.com/en/meta-whatsapp-updates) and [current version comparison and migration notes](https://360dialog.com/blog/embedded-signup-versions-migration/). Meta's own developer pages are authoritative, but were rate-limited during this review; re-check the live versions page before release.

### Platform prerequisites

- The integrating platform must be an eligible Meta Tech Provider or Solution Partner and complete the required Meta app review/advanced access.
- Create a Facebook Login for Business configuration for WhatsApp Embedded Signup. Select the WhatsApp products, assets and permissions in that configuration; do not paste the old v2/v3 permission list into launch JavaScript.
- Register the exact HTTPS onboarding origin and redirect URI with Meta.
- Subscribe the app to the required webhook fields, including `account_update`, `messages`, `history`, `smb_app_state_sync` and `smb_message_echoes`.
- The browser relays the short-lived authorization code to the backend. The backend exchanges it server-to-server using the app secret; never expose the app secret or resulting business token to WordPress browser JavaScript.
- Resolve all returned messaging-account and phone-number IDs to the initiating tenant before persisting any connection.
- Encrypt credentials at rest and keep them out of WordPress public options, logs, Git and API responses.

Meta's partner overview identifies Tech Providers as a partner type and documents Embedded Signup as a Tech Provider capability: [Meta partner overview](https://whatsappbusiness.com/partners/become-a-partner/). Meta's official developer documentation is linked at [Embedded Signup overview](https://developers.facebook.com/documentation/business-messaging/whatsapp/embedded-signup/overview).

### Coexistence lifecycle

- In v5, WhatsApp Business app onboarding is enabled by default. Coexistence eligibility is determined during the Meta-hosted flow; do not hard-code the old v4 `featureType` plus `sessionInfoVersion` snippet into v5 launch code.
- Keep the business owner's existing WhatsApp Business app available during setup. Meta may present an in-app QR confirmation; the owner must complete the step inside the app.
- Treat the SDK's completion event and the authorization-code callback as separate signals. A completion event alone is not proof that the server has exchanged credentials and completed provisioning.
- Use the v5 session-info fields, then verify the returned WABA/messaging-account and phone-number IDs with Graph API. Do not assume a phone-number ID will always be present in the browser event.
- Subscribe the WABA/app as required and test inbound messages, app-originated echoes, delivery statuses, account disconnection/revocation, and history/contact sync.
- Coexistence history and contact synchronization have a limited post-onboarding window. Implement and monitor these tasks before claiming that historical chats or contacts are synchronized.
- Do not enable outbound sends until number identity, approved templates, recipient opt-in and the webhook round-trip have been tested.

**Current implementation state:** the PHP webhook now resolves supported message/echo/status events to a tenant through the registered Meta phone-number ID. The PHP backend does not yet implement the v5 Embedded Signup launcher, authorization-code exchange, token encryption/decryption, WABA phone-number discovery, app subscription, `account_update` lifecycle processing, or Coexistence history/contact sync. The separate Supabase receiver remains a diagnostic tool for the mock-site experiment; it is not the production tenant or conversation store. Meta partner eligibility and the app's live configuration must be confirmed before onboarding the live Dimdian number.
