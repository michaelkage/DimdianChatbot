# Website chat widget

The dependency-free browser widget runs inside a Shadow DOM, isolating its styling from the host website.

## Embed snippet

```html
<script src="https://chat.dimdian.com/widget.js" data-api-base="https://chat.dimdian.com/api" data-title="Chat with Dimdian" data-subtitle="Send us a message. Our team will reply here." defer></script>
```

The hostname above is an example deployment value. Do not add it to WordPress until the application is deployed.

## Behavior

- Floating launcher opens an accessible chat panel.
- Visitors can send as guests; no account, phone, OTP or registration is required.
- Name and phone are optional and are submitted only when the first message creates a session.
- Guest token is held in sessionStorage, scoped to API origin and website origin. It is never placed in a URL.
- Closing and reopening preserves the current conversation. Reloading the same tab can restore it.
- Message bodies are inserted as text, never interpreted as HTML.
- Polling runs while the panel is open and the page is visible, with backoff on errors.
- Visitor messages are labelled Saved, not Delivered. WhatsApp transport is not implemented yet.

## Script attributes

| Attribute | Description |
|---|---|
| src | URL of widget.js; widget.css is loaded beside it |
| data-api-base | API base URL; defaults to the script origin plus /api |
| data-title | Chat header title |
| data-subtitle | Supporting header text |
| data-open="true" | Open the panel on initialization |

## Boundary

This widget connects to the current guest-session and messages APIs. It does not imply WhatsApp delivery, delivery receipts, business replies or Coexistence support. Those are separate integration tasks.
