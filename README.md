# DimdianChatbot

A lightweight, self-hosted customer support chat system for an auto-parts marketplace. It is designed around existing Hostinger PHP + MySQL hosting rather than a paid support SaaS.

## Product goals

- Let shoppers contact support without leaving a product listing or creating an account.
- Preserve guest conversations across product pages using a browser-held guest token.
- Give staff the product URL and SKU the customer was viewing.
- Accept secure JPG, PNG, and WEBP attachments up to 3 MB for part and VIN verification.
- Provide a protected, split-screen staff inbox at \`/admin/support\`.
- Keep staff informed with smart short polling, unread indicators, tab-title updates, and optional audio alerts.
- Leave a modular seam for optional cloud AI triage (Gemini or Groq), with human escalation for fitment, VIN, and order-specific questions.

## Explicit non-goals

- No locally hosted LLM.
- No persistent WebSocket daemon; messaging uses stateless HTTP and short polling.
- No seller-specific or multi-vendor routing; inquiries go to the central support team.
- No native iOS or Android staff app.

## Runtime and hosting assumptions

- PHP 8.2 or newer.
- MySQL 8.0+ or MariaDB 10.5+.
- PHP extensions: PDO MySQL, fileinfo, and JSON.
- HTTPS enabled.
- No Composer or npm runtime dependency is required by the core application.

The repository is structured so the web server's document root can point to \`public/\`. Keep \`config.php\` and uploaded files outside the public document root.

## Current implementation

- \`database/schema.sql\`: initial relational schema for staff, customers, conversations, messages, attachments, and rate-limit events.
- \`app/bootstrap.php\`: shared PDO, JSON response, request parsing, CORS, and method helpers.
- \`public/index.php\`: minimal database-backed health endpoint.
- \`config.example.php\`: deployment configuration template.

The customer-facing guest chat flow and staff inbox are implemented, including persistent browser identity, listing context, customer/staff text messages, delta polling, private image attachments, staff login, CSRF-protected state changes, unread counts, and staff notifications. Optional AI triage and authenticated marketplace-account linking remain integration work.

## Customer widget integration

After deploying the support app, add this script to the marketplace layout (replace the SKU expression with the product page's real SKU variable):

```html
<script
  src="https://support.example.com/widget.js"
  data-api-base="https://support.example.com/api/"
  data-context-sku="YOUR_PRODUCT_SKU"
  defer
></script>
```

The widget also captures the current page URL and document title automatically. Add the storefront's exact origin to `app.allowed_origins` in `config.php`. The widget uses browser local storage for its opaque guest token and pauses polling while it is closed or the tab is hidden.

## Implemented customer API

- `POST /api/session.php`: create or resume a guest identity.
- `POST /api/conversations.php`: create or reuse the customer's active conversation and refresh listing context.
- `GET /api/messages.php?conversation_id=…&after_id=0`: fetch only messages newer than the supplied message ID.
- `POST /api/messages.php`: send a customer text message.
- `POST /api/upload.php`: attach one validated JPG, PNG, or WEBP image (maximum 3 MB).
- `GET /api/attachment.php?id=…`: retrieve an attachment only when it belongs to the authenticated guest's conversation.

All customer endpoints require the `X-Dimdian-Guest-Token` header except the initial guest-session creation request. The token is 32 cryptographically random bytes represented as 64 hexadecimal characters; only its SHA-256 hash is stored in MySQL.

## Validation

GitHub Actions runs PHP syntax checks over `app/` and `public/` and checks the widget JavaScript syntax on pushes to `main`.

## Hostinger setup

1. Create a MySQL database and database user.
2. Import \`database/schema.sql\` using phpMyAdmin or the MySQL CLI.
3. Copy \`config.example.php\` to \`config.php\` in the repository root.
4. Set database credentials, production base URL, exact allowed storefront origins, and a private upload directory.
5. Point the domain document root to the repository's \`public/\` directory.
6. Ensure PHP can write to the configured private upload directory.
7. Visit the site's root URL. A healthy installation returns JSON with \`status: ok\` and \`database: connected\`.

Do not commit \`config.php\`, database credentials, or uploaded customer images.

## Security baseline

- Database access uses PDO prepared statements and native prepares.
- Configuration and uploads are intended to remain outside the public document root.
- CORS is an explicit allowlist; it is not authentication.
- Guest identity uses a random browser token whose hash is stored server-side; the raw token is never stored in the database.
- Staff authentication must use password hashing, secure HTTP-only cookies, session rotation, and CSRF protection.
- Uploads must be checked by detected MIME type and image decoding, not filename extension alone.
- User-provided text must be escaped at the point it is rendered.

## Build sequence

1. Database and PHP foundation.
2. Guest identity, conversation creation, customer message and delta-poll APIs.
3. Validated private image uploads and attachment delivery.
4. Staff login, authorization, and \`/admin/support\` inbox.
5. Smart polling, unread state, browser title and audio notifications.
6. Security hardening, integration tests, and Hostinger deployment verification.
7. Optional cloud AI adapter and deterministic human-escalation rules.
