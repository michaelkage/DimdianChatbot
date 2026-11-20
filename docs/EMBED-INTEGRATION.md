# Standalone widget installation

ChatBridge does not require WordPress. Any site that can include a JavaScript file can embed the widget.

## Requirements

- A running ChatBridge PHP API with the tenant migrations applied.
- The exact website origin registered and verified for the tenant in `tenant_sites`.
- HTTPS on both the website and ChatBridge API.
- The API host configured to allow that website origin.

## Embed

Add this script near the end of the page's `<body>`. Replace the API URL and public display text with the values for the business.

```html
<script
  src="https://chat.example.com/widget.js"
  data-api-base="https://chat.example.com/api"
  data-title="Support"
  data-subtitle="How can we help?"
  data-launcher-text="Message us"
  data-primary-color="#171717"
  data-accent-color="#d7eee6"
  data-offline-queue="false"
  data-typing-indicator="false"
  defer
></script>
```

The script creates an isolated Shadow DOM widget and loads its stylesheet from the same ChatBridge host. The API determines the tenant from the verified request origin; never add a tenant ID, API key, WhatsApp token or AI provider key to this public script tag.

## Optional attributes

| Attribute | Purpose |
| --- | --- |
| `data-title`, `data-subtitle` | Header text |
| `data-launcher-text` | Launcher label |
| `data-logo-url` | Optional HTTPS logo image |
| `data-primary-color`, `data-accent-color` | Six-digit hex colours |
| `data-offline-queue` | Set to `true` to save up to 20 unsent messages in this browser and retry when online |
| `data-typing-indicator` | Set to `true` to show the animated reply-pending indicator |
| `data-handover-ack` | Set to `true` to show the configured handover acknowledgement |
| `data-handover-message` | Custom handover acknowledgement text |
| `data-hide-paths` | Comma-separated exact paths or prefix patterns ending in `*` |
| `data-show-paths` | Optional allow-list; when set, only matching paths display the widget |
| `data-hide-selectors` | Comma-separated CSS selectors; a match suppresses the widget |
| `data-compact-selectors` | Comma-separated CSS selectors; a match uses a compact launcher |

Example: `data-hide-paths="/checkout,/watch/*"` hides the widget on checkout and video-watch paths. Use selectors such as `.video-player-fullscreen` only if those selectors are stable on your site.

## Offline behaviour and privacy

The offline queue is opt-in. It stores message text and client-generated IDs in the browser's local storage until the API accepts the messages. It is limited to 20 messages and should not be enabled on shared/public devices. It is not an encrypted offline vault. Customers can clear site data to remove queued messages.

## Current boundaries

This release adds page targeting, appearance controls, optional local retry, visitor-side conversation switching and optional custom human-handover acknowledgement. It does not yet provide screenshot uploads, private diagnostic bundles, a hosted agent dashboard, third-party dashboard webhooks, provider OAuth login, or a full agent inbox. Those require server-side attachment access controls, consent/retention controls and an authenticated staff interface; the public widget must not send browser logs, cookies, tokens or arbitrary page contents to the service.
