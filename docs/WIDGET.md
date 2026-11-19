# Website chat widget

The dependency-free browser widget runs inside a Shadow DOM, isolating its styling from the host website.

## Embed snippet

```html
<script src="https://chat.dimdian.com/widget.js" data-api-base="https://chat.dimdian.com/api" data-title="Chat with Dimdian" data-subtitle="Send us a message. Our team will reply here." defer></script>
```

The hostname above is an example deployment value. Do not add it to WordPress until the application is deployed.

## Visitor behavior

- Floating launcher opens an accessible chat panel.
- Visitors can send as guests; no account, OTP or registration is required.
- Name and phone are optional. A phone number is only needed for WhatsApp continuation.
- WhatsApp continuation requires a separate explicit checkbox and a full international phone number. No OTP is sent.
- The guest token is held in sessionStorage, scoped to API origin and website origin. It is never placed in a URL.
- Closing and reopening preserves the current conversation. Reloading the same tab restores it and reloads history.
- Message bodies are inserted as text, never interpreted as HTML.
- Polling runs while the panel is open and the page is visible, with backoff on errors.
- Messages are labelled Website only unless an opted-in WhatsApp template send is actually queued. Delivery statuses update as webhook events arrive.
- The welcome screen offers optional quick starts for finding a part, checking availability and asking about an order. These only prefill the message box; they do not send anything.
- The launcher exposes its expanded state to assistive technology. Escape closes the panel and returns focus to the launcher.
- If a visitor enters a phone number, it must use international format. WhatsApp continuation additionally requires a phone number and explicit consent.

## Script attributes

| Attribute | Description |
|---|---|
| src | URL of widget.js; widget.css is loaded beside it |
| data-api-base | API base URL; defaults to the script origin plus /api |
| data-title | Chat header title |
| data-subtitle | Supporting header text |
| data-open="true" | Open the panel on initialization |

## Boundary

The widget can exchange text messages with the PHP API. Actual WhatsApp delivery and Business-app reply mirroring depend on the approved template, verified Meta credentials and working Coexistence webhooks for the target number.

## Demo and connection diagnostics

The GitHub Pages demo deliberately remains simulated and does not create real customer conversations. The private [connection diagnostics page](https://michaelkage.github.io/DimdianChatbot/whatsapp-test.html) has two separate checks:
- Event monitor: confirms the owner-only monitor key can read the latest 50 webhook events.
- Webhook verification: sends a random challenge to the deployed Supabase webhook and confirms the configured verify token is accepted. The token is used only for the request and is not saved by the page.

Neither check sends a WhatsApp message or changes the production PHP/MySQL service.
