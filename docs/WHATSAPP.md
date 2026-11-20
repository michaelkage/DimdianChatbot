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
- `history` and `smb_app_state_sync`: Coexistence history/contact synchronization events. The PHP runtime requests contact sync first and history second, records each Meta request ID/status, stores contact changes in a tenant-scoped contact table and stores raw history chunks in a tenant-scoped archive.

Inbound text messages are matched to an open website conversation using the normalized visitor phone number. Business-app text echoes are matched using the echo's `to` number. The matching visitor must have supplied the same international phone number. Unsupported message types and unmatched phone numbers are safely ignored; media is not implemented.

Webhook events are persisted with unique event keys so Meta retries do not duplicate messages. The raw payload is hashed, not stored. Status events are also stored separately so a fast status callback that arrives before the send worker records its WAMID can be reconciled when the send response is saved. Status transitions do not downgrade a delivered/read state.

## Coexistence caveat

The `smb_message_echoes` event is the mechanism used to mirror text replies sent from the Business app back into the website conversation. It is not safe to assume that every onboarding path or country/account supports this event. Confirm actual +234-number eligibility and test a real app-originated reply before enabling production traffic. If the webhook echo does not arrive, website visitors will not see staff replies from the Business app.

## Current scope

Text messages only. No media download, attachments, voice notes, group chats, template management, message editing/deletion sync, or automatic conversation assignment.

## Embedded Signup and Coexistence onboarding

### Supported Embedded Signup version

As of 20 November 2026, implement against **Embedded Signup v5**, released on 20 October 2026. Meta deprecated v2 and v3 on 15 October 2026. v5 changes the session-info contract and Coexistence defaults; it is not just a cosmetic version bump. The v5 session event can provide `messaging_account_ids`, `primary_waac_ids` and `is_on_biz_app`; code must not assume the old `waba_id` property is always present. Meta's 2026 account-model evolution separates the Messaging Account (PMA; legacy WABA ID) from the WhatsApp Account (WAAC; phone-number asset). Persist both IDs: use `primary_waac_ids` to discover phone numbers where available, and the Messaging Account ID for templates/billing and WABA app subscription. The old `sessionInfoVersion` parameter is no longer used by v5. WhatsApp Business app onboarding is on by default in v5, with an opt-out. The separate `v5-public-preview` version is not selected by this integration; use stable `v5` for production unless Meta's live configuration explicitly requires otherwise.

Sources: [Meta Developers — WhatsApp Account Model Evolution](https://developers.meta.com/resources/videos/whatsapp-account-model-evolution/), [Meta Graph API — WABA phone-number edge](https://developers.facebook.com/docs/graph-api/reference/whats-app-business-account/phone_numbers/), [Meta Graph API — subscribed apps](https://developers.facebook.com/docs/graph-api/reference/whats-app-business-account/subscribed_apps/), [Meta Embedded Signup v5 update summary](https://easytawasul.com/en/meta-whatsapp-updates), and [current version comparison and migration notes](https://360dialog.com/blog/embedded-signup-versions-migration/). Meta's developer pages were intermittently rate-limited during this review; re-check the live versions page before release.

### Platform prerequisites

- The integrating platform must be an eligible Meta Tech Provider or Solution Partner and complete the required Meta app review/advanced access.
- Create a Facebook Login for Business configuration for WhatsApp Embedded Signup. Select the WhatsApp products, assets and permissions in that configuration; do not paste the old v2/v3 permission list into launch JavaScript.
- Register the exact HTTPS onboarding origin and redirect URI with Meta.
- Subscribe the app to the required webhook fields, including `account_update`, `messages`, `history`, `smb_app_state_sync` and `smb_message_echoes`.
- The browser relays the short-lived authorization code and v5 session-info object to the backend through a WordPress admin AJAX relay. The WordPress server authenticates to ChatBridge with a tenant API key. The backend exchanges the code server-to-server using the app secret; never expose the app secret, tenant API key or resulting business token to browser JavaScript.
- Resolve all returned messaging-account and phone-number IDs to the initiating tenant before persisting any connection.
- Encrypt credentials at rest and keep them out of WordPress public options, logs, Git and API responses.

Meta's partner overview identifies Tech Providers as a partner type and documents Embedded Signup as a Tech Provider capability: [Meta partner overview](https://whatsappbusiness.com/partners/become-a-partner/). Meta's official developer documentation is linked at [Embedded Signup overview](https://developers.facebook.com/documentation/business-messaging/whatsapp/embedded-signup/overview).

### Coexistence lifecycle

- In v5, WhatsApp Business app onboarding is enabled by default. Coexistence eligibility is determined during the Meta-hosted flow; do not hard-code the old v4 `featureType` plus `sessionInfoVersion` snippet into v5 launch code.
- Keep the business owner's existing WhatsApp Business app available during setup. Meta may present an in-app QR confirmation; the owner must complete the step inside the app.
- Treat the SDK's completion event and the authorization-code callback as separate signals. A completion event alone is not proof that the server has exchanged credentials and completed provisioning.
- Use the v5 session-info fields. Treat `messaging_account_ids` as Messaging Account/PMA IDs and `primary_waac_ids` as WhatsApp Account/WAAC IDs. Discover phone numbers through the WAAC edge first, with the legacy Messaging Account/WABA phone-number edge as a compatibility fallback. If the session event includes a phone-number ID, still verify it through Graph API. Verify `is_on_biz_app` and `platform_type` on the phone-number resource before recording the connection mode.
- The backend subscribes the app to each selected WABA and verifies the phone-number resource. Test inbound messages, app-originated echoes, delivery statuses, account disconnection/revocation, and history/contact sync.
- Coexistence contact and history sync are one-shot requests that must be initiated within 24 hours. The backend creates per-channel jobs and requests contacts first, then history. It records request IDs and receives contact changes into a tenant-scoped contacts table. History chunks are retained as raw tenant-scoped JSON payloads; they are not yet normalized into the live conversation/message tables.
- Sync request failures are marked for manual review rather than automatically retried, because the Meta requests are one-shot and a network timeout can leave acceptance ambiguous. A separate worker can process pending jobs while the 24-hour window remains open.
- The raw history archive currently has no automated retention purge or customer-facing history browser. Define retention, export and deletion behavior before production use.
- Do not enable outbound sends until number identity, approved templates, recipient opt-in and the webhook round-trip have been tested.

**Current implementation state (feature branch only):** the WordPress admin connector now contains a gated Embedded Signup v5 launcher. It captures the v5 finish event and authorization code, relays both server-to-server, exchanges the code, resolves Messaging Account/PMA and WhatsApp Account/WAAC IDs, discovers and verifies phone numbers, checks `is_on_biz_app` / `platform_type`, subscribes the Messaging Account, encrypts the token with AES-256-GCM and stores phone-number connections under the authenticated tenant. Tenant API keys are stored hashed. The webhook routes account-level updates by Messaging Account/WAAC and handles partner removal, offboarding, disconnection and reconnection state changes. The onboarding endpoint triggers one-shot Coexistence contact and history sync requests immediately after successful connection. This code is not merged, deployed or tested against a real Meta app yet.

The runtime now triggers Coexistence history/contact synchronization (`history` and `smb_app_state_sync`) in contacts-first order within Meta's 24-hour window, tracks request IDs/statuses, and stores tenant-scoped contact records plus raw history payloads. It does not yet normalize historical messages into live conversations, provide a history browser, enforce archive retention/purge, refresh tokens, manage templates, send outbound messages with per-tenant channel credentials, or provide a full channel reconnect UI. The existing outbox worker remains configured for a single tenant's legacy transport; it must be migrated to decrypt and use each tenant's channel credential before any multi-tenant outbound messaging. The separate Supabase receiver remains a diagnostic tool for the mock-site experiment; it is not the production tenant or conversation store.

Meta partner eligibility, the actual app review/advanced access, the stable v5 configuration ID, the correct partner solution ID and a dedicated staging WordPress site must be confirmed before testing. Do not configure `meta.enabled` or initiate signup on the live Dimdian website. The mock GitHub Pages site cannot host the WordPress admin connector or PHP callback, so a separate staging WordPress installation is required for an end-to-end test.


## Coexistence sync operations

Successful Embedded Signup creates two one-shot sync jobs for each verified Coexistence phone number. The onboarding endpoint attempts them immediately, in this order:

1. `smb_app_state_sync` — contacts
2. `history` — chat-history chunks

The requests are sent to the phone-number resource's `smb_app_data` edge with `messaging_product=whatsapp` and the corresponding `sync_type`. Meta request IDs and job state are retained in `channel_sync_jobs`. Incoming contacts are upserted into `whatsapp_contacts`; incoming history chunks are stored in `coexistence_history_payloads`.

To process still-pending jobs after a temporary service interruption, run the tenant worker before the 24-hour window expires:

```sh
php public/cron/process-meta-sync.php TENANT_UUID
```

A job marked `failed` is not automatically retried. Verify whether Meta accepted the one-shot request before attempting any manual recovery. The history archive is not yet rendered as normal conversation history and currently has no automated retention purge.
