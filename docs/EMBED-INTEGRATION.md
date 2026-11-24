# Standalone widget installation

ChatBridge can be embedded on a site without WordPress. Any page that can load a JavaScript file can use the widget.

## Requirements

- A running ChatBridge API.
- The website origin registered and allowed by the API.
- HTTPS on the website and API host.

## Embed

Add the script near the end of the page's body. Replace the URL and display text with the business's own values.

```html
<script
  src="https://chat.example.com/widget.js"
  data-api-base="https://chat.example.com/api"
  data-title="Support"
  data-subtitle="How can we help?"
  data-offline-queue="false"
  defer
></script>
```

The widget creates an isolated Shadow DOM and loads its stylesheet from the same host. Tenant resolution and authorization remain server-side. Never place a tenant secret, database credential, Meta token, AI provider key or private API credential in the public script tag.

## Optional attributes

| Attribute | Purpose |
| --- | --- |
| `data-title`, `data-subtitle` | Header text |
| `data-offline-queue` | Explicitly opt in to the local offline message queue; default is disabled |

## Offline message queue

The offline queue is deliberately opt-in. Set `data-offline-queue="true"` only after considering the device and privacy implications.

- Up to 20 unsent messages are stored in this browser's local storage.
- Each queued message keeps its original client-generated UUID. The API uses that ID for idempotency, so a response lost after a successful server write can be retried without creating a duplicate in the same conversation.
- The widget clears the composer only after the browser confirms that the queue write succeeded. If storage is blocked or full, the message remains in the composer.
- When connectivity returns, messages are sent in order. Network failures, HTTP 408/425/429 and server errors use bounded exponential retry. Non-transient errors remain queued and are shown as needing attention.
- If the current guest session has expired, the widget starts a fresh website chat session before retrying the saved message.
- If the tab is refreshed, the browser's tab-scoped session token remains available and queued messages can continue in that conversation. A new tab may not have that token and can therefore start a new session.

Local storage is not an encrypted vault. Anyone with access to the same browser profile may be able to inspect its contents. Do not enable this feature on shared/public devices, and do not encourage visitors to queue passwords, payment details or other sensitive information. Clearing the site's local storage removes queued messages.

## Scope boundary

The offline queue does not provide background delivery after the browser is closed, a cross-device outbox, encrypted local storage, or a guarantee of delivery. It is a best-effort browser-side recovery mechanism; the API remains the authoritative record after accepting a message.
