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
| `whatsapp.phone_number_id` | Meta phone-number ID for the business sender |
| `whatsapp.access_token` | Access token with WhatsApp messaging permission |
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

Every POST is checked against `X-Hub-Signature-256` using HMAC-SHA256 over the exact raw request body and the Meta App Secret. Invalid signatures are rejected before JSON parsing or database access. When `whatsapp.phone_number_id` is configured, webhook changes for any other phone-number ID are ignored.

Subscribe to the WhatsApp webhook fields needed for:
- `messages`: inbound visitor messages and sent/delivered/read/failed statuses.
- `smb_message_echoes`: messages sent from the WhatsApp Business app on supported Coexistence integrations.

Inbound text messages are matched to an open website conversation using the normalized visitor phone number. Business-app text echoes are matched using the echo's `to` number. The matching visitor must have supplied the same international phone number. Unsupported message types and unmatched phone numbers are safely ignored; media is not implemented.

Webhook events are persisted with unique event keys so Meta retries do not duplicate messages. The raw payload is hashed, not stored. Status events are also stored separately so a fast status callback that arrives before the send worker records its WAMID can be reconciled when the send response is saved. Status transitions do not downgrade a delivered/read state.

## Coexistence caveat

The `smb_message_echoes` event is the mechanism used to mirror text replies sent from the Business app back into the website conversation. It is not safe to assume that every onboarding path or country/account supports this event. Confirm actual +234-number eligibility and test a real app-originated reply before enabling production traffic. If the webhook echo does not arrive, website visitors will not see staff replies from the Business app.

## Current scope

Text messages only. No media download, attachments, voice notes, group chats, template management, message editing/deletion sync, or automatic conversation assignment.

## Embedded Signup and Coexistence onboarding

The mock-site integration must use **WhatsApp Embedded Signup v4**, not older v2/v3 snippets. Meta's v2/v3 Embedded Signup flow was retired for new onboarding on 15 October 2026. Create a v4 Facebook Login for Business configuration in the Meta App Dashboard before attempting to launch signup.

For a Coexistence-capable flow, the Embedded Signup launch uses the Facebook JavaScript SDK and the configuration ID created in Meta. The launch request uses `response_type: "code"`, `override_default_response_type: true`, and the Coexistence feature flag:

```js
FB.login(callback, {
  config_id: "YOUR_V4_CONFIGURATION_ID",
  response_type: "code",
  override_default_response_type: true,
  extras: {
    setup: {
      solutionID: "YOUR_PARTNER_SOLUTION_ID"
    },
    featureType: "whatsapp_business_app_onboarding"
  }
});
```

The page must also listen for the `WA_EMBEDDED_SIGNUP` window message and handle the `FINISH`, `CANCEL`, and `ERROR` events. The login callback separately returns the short-lived authorization code. Do not confuse the session-info message with the authorization-code callback.

The authorization code must be exchanged server-side. Never send the Meta App Secret to the browser or commit it to Git. The App ID, v4 Configuration ID and Partner Solution ID are identifiers, not secrets, but the Meta app still needs the appropriate Tech Provider/partner setup and permissions before the flow can complete.

**Current state:** the Supabase webhook receiver and owner-only event monitor are scaffolded in this repository. The Embedded Signup launcher and server-side authorization-code exchange are not yet enabled. They require the actual Meta App ID, v4 Configuration ID and Partner Solution ID. Do not start onboarding the live Dimdian number until the owner explicitly approves the exact flow and a safe test number/account is selected.
