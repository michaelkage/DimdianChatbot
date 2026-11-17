# DimdianChatbot

A lightweight, self-hosted customer support chat system for an auto-parts marketplace. It is designed around existing Hostinger PHP + MySQL hosting rather than a paid support SaaS.

## Product goals

- Let shoppers contact support without leaving a product listing or creating an account.
- Preserve guest conversations across product pages using a browser-held guest token.
- Give staff the product URL and SKU the customer was viewing.
- Accept secure JPG, PNG, and WEBP attachments up to 3 MB for part and VIN verification.
- Provide a protected, split-screen staff inbox at `/admin/support`.
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

The repository is structured so the web server's document root can point to `public/`. Keep `config.php` and uploaded files outside the public document root.

## Current implementation

- `database/schema.sql`: initial relational schema for staff, customers, conversations, messages, attachments, and rate-limit events.
- `app/bootstrap.php`: shared PDO, JSON response, request parsing, CORS, and method helpers.
- `public/index.php`: minimal database-backed health endpoint.
- `public/widget.js`: zero-dependency, shadow-DOM customer chat widget.
- `public/api/`: guest session, conversation, message, image upload, and staff endpoints.
- `public/admin/support/`: responsive split-screen staff inbox with polling, sound/title alerts, and an administrator-only AI mode control.
- `public/api/ai-settings.php`: administrator-only AI mode endpoint; AI remains fail-closed until a provider adapter is configured.
- `app/staff.php`: hardened staff sessions and CSRF checks.
- `bin/create-admin.php`: CLI-only staff account provisioning.
- `.github/workflows/ci.yml`: PHP, JavaScript, and MySQL schema validation.
- `config.example.php`: deployment configuration template, including the fail-closed AI provider readiness flag.

The customer-facing guest chat flow and staff inbox are implemented, including persistent browser identity, listing context, customer/staff text messages, delta polling, private image attachments, staff login, CSRF-protected state changes, unread counts, and staff notifications. The backend AI kill switch and human-routing path are implemented. AI provider integration and authenticated marketplace-account linking remain integration work.

### AI / human support control

- AI is off by default. New conversations are created with `needs_human` status and appear in the staff inbox.
- An administrator sees the **AI replies** control in `/admin/support/`. Turning AI off moves every currently `open` conversation to `needs_human`; existing human-handled conversations are never automatically returned to AI.
- Agents can view and reply to conversations, but only administrators can change the global AI setting.
- The switch cannot enable AI until a provider adapter is installed and `ai.provider_ready` is explicitly set to `true` in the private `config.php`. This guard is deliberate: enabling a setting without a working provider must never strand a customer in an unattended AI queue.
- When a provider is added, its runtime must treat `needs_human` as a hard no-AI state and fall back to the human queue on quota exhaustion, provider errors, or timeouts. Provider errors must never be shown to customers.

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

The widget also captures the current page URL and document title automatically. Add the storefront's exact origin to `app.allowed_origins` in `config.php`. It uses browser local storage for its opaque guest token and pauses polling while closed or when the tab is hidden.

For logged-in shoppers, render the optional identity attributes server-side:

```php
<?php
$customerId = (string) $loggedInCustomer['id'];
$signature = hash_hmac('sha256', $customerId, $sharedIdentitySecret);
?>
<script
  src="https://support.example.com/widget.js"
  data-api-base="https://support.example.com/api/"
  data-context-sku="<?= htmlspecialchars($productSku, ENT_QUOTES, 'UTF-8') ?>"
  data-customer-id="<?= htmlspecialchars($customerId, ENT_QUOTES, 'UTF-8') ?>"
  data-customer-signature="<?= htmlspecialchars($signature, ENT_QUOTES, 'UTF-8') ?>"
  defer
></script>
```

Configure the same random secret as `integration.customer_identity_secret` in the support app and the storefront's server environment. Never expose the shared secret in browser code; only the generated signature is sent. The staff inbox displays the linked marketplace account ID. If the attributes are omitted, the session is treated as a guest and any previous account link on that browser token is cleared.

## Implemented customer API

- `POST /api/session.php`: create or resume a guest identity.
- `POST /api/conversations.php`: create or reuse the customer's active conversation and refresh listing context.
- `GET /api/messages.php?conversation_id=…&after_id=0`: fetch only messages newer than the supplied message ID.
- `POST /api/messages.php`: send a customer text message.
- `POST /api/upload.php`: attach one validated JPG, PNG, or WEBP image (maximum 3 MB).
- `GET /api/attachment.php?id=…`: retrieve an attachment only when it belongs to the authenticated guest's conversation.

All customer endpoints require the `X-Dimdian-Guest-Token` header except the initial guest-session creation request. The token is 32 cryptographically random bytes represented as 64 hexadecimal characters; only its SHA-256 hash is stored in MySQL.

## Validation

GitHub Actions lints PHP source (including the CLI provisioning script), checks widget JavaScript syntax, imports the schema into a disposable MySQL 8 service, exercises schema relationships/query shapes, and runs an end-to-end customer-to-staff API smoke test including a real image upload and the administrator AI-off / human-routing path.

## Hostinger setup

1. Create a MySQL database and database user.
2. Import `database/schema.sql` using phpMyAdmin or the MySQL CLI.
3. Copy `config.example.php` to `config.php` in the repository root.
4. Set database credentials, production base URL, exact allowed storefront origins, a private upload directory, and unique random secrets for rate limiting and optional storefront identity signing.
5. Point the domain document root to the repository's `public/` directory.
6. Ensure PHP can write to the configured private upload directory. Set `upload_max_filesize` to at least `3M` and `post_max_size` to at least `4M` in Hostinger PHP settings so the 3 MB image limit can be reached.
7. Create the first staff account over SSH from the repository root: `php bin/create-admin.php support@example.com "Support Admin" admin`. Enter the password at the prompts; do not put it in shell history.
8. Visit the site's root URL. A healthy installation returns JSON with `status: ok` and `database: connected`.
9. Open `/admin/support/` and sign in.

Do not commit `config.php`, database credentials, or uploaded customer images.

## Security baseline

- Database access uses PDO prepared statements and native prepares.
- Configuration and uploads are intended to remain outside the public document root.
- CORS is an explicit allowlist; it is not authentication.
- Guest identity uses a random browser token whose hash is stored server-side; the raw token is never stored in the database.
- Staff authentication uses password hashing, secure HTTP-only cookies, session rotation, CSRF protection, and IP/account login throttling.
- Guest message and image upload endpoints are rate-limited by both guest identity and source address.
- Uploads must be checked by detected MIME type and image decoding, not filename extension alone.
- User-provided text must be escaped at the point it is rendered.

## Build sequence

1. Completed: database/PHP foundation and customer messaging APIs.
2. Completed: guest widget, part-page context, image uploads, and private attachment delivery.
3. Completed: staff authentication, split-screen inbox, conversation status controls, polling, unread counts, and browser/audio alerts.
4. Remaining: deploy to Hostinger and run end-to-end tests against the actual storefront and MySQL account.
5. Remaining: optional cloud AI triage with deterministic human escalation. The signed account-ID handoff is implemented; storefront-side signature generation remains an integration step.
