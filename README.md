# DimdianChatbot

A lightweight, self-hosted customer support chat system for an auto-parts marketplace. It is designed around existing Hostinger PHP + MySQL hosting rather than a paid support SaaS.

## Product goals

- Let shoppers contact support without leaving a product listing or creating an account.
- Preserve guest conversations across product pages using a browser-held guest token.
- Give staff the product URL and SKU the customer was viewing.
- Accept secure JPG, PNG, and WEBP attachments up to 3 MB for part and VIN verification.
- Provide a protected, split-screen staff inbox at `/admin/support`.
- Keep staff informed with smart short polling, unread indicators, tab-title updates, and optional audio alerts.
- Optionally use a server-side cloud AI first response (Groq, Gemini, or another OpenAI-compatible endpoint), with deterministic human escalation for fitment, VIN, order and payment questions.

## Explicit non-goals

- No locally hosted LLM.
- No persistent WebSocket daemon; messaging uses stateless HTTP and short polling.
- No seller-specific or multi-vendor routing; inquiries go to the central support team.
- No native iOS or Android staff app.

## Runtime and hosting assumptions

- PHP 8.2 or newer.
- MySQL 8.0+ or MariaDB 10.5+.
- PHP extensions: PDO MySQL, fileinfo, JSON, and OpenSSL for VIN encryption; cURL is required when WooCommerce or AI integration is enabled.
- HTTPS enabled.
- No Composer or npm runtime dependency is required by the core application.

The repository is structured so the web server's document root can point to `public/`. Keep `config.php` and uploaded files outside the public document root.

## Current implementation

- `database/schema.sql`: initial relational schema for staff, customers, conversations, messages, attachments, and rate-limit events.
- `app/bootstrap.php`: shared PDO, JSON response, request parsing, CORS, and method helpers.
- `app/ai.php` and `app/ai_provider.php`: guarded provider calls, short timeouts, deterministic escalation, per-conversation handoff, and global AI shutdown on exhausted quota or invalid credentials.
- `app/woocommerce.php`: server-side read-only WooCommerce product lookup and safe product URL normalization.
- `app/vehicle.php`: AES-256-GCM VIN protection and exact verified-fitment matching.
- `app/storage.php`: canonical private attachment path validation.
- `bin/preflight.php`, `bin/backup-db.php`, `bin/restore-db.php`, and `bin/cleanup-rate-limits.php`: production checks, database recovery and scheduled maintenance.
- `public/index.php`: minimal database-backed health endpoint.
- `public/widget.js`: zero-dependency, shadow-DOM customer chat widget.
- `public/api/`: guest session, conversation, message, image upload, and staff endpoints.
- `public/admin/support/`: responsive split-screen staff inbox with polling, sound/title alerts, and an administrator-only AI mode control.
- `public/api/ai-settings.php`: administrator-only AI mode endpoint; AI remains fail-closed until a provider adapter is configured.
- `app/staff.php`: hardened staff sessions and CSRF checks.
- `bin/create-admin.php`: CLI-only staff account provisioning.
- `.github/workflows/ci.yml`: PHP, JavaScript, and MySQL schema validation.
- `config.example.php`: deployment configuration template, including the fail-closed AI provider readiness flag.

The customer-facing guest chat flow and staff inbox are implemented, including persistent browser identity, listing context, customer/staff text messages, delta polling, private image attachments, staff login, CSRF-protected state changes, unread counts, and staff notifications. The server-side AI provider adapter is wired into customer messages; it supports Groq, Gemini's OpenAI-compatible endpoint, and a configurable OpenAI-compatible endpoint. AI remains opt-in and fail-closed.

### AI / human support control

- AI is off by default. New conversations are created with `needs_human` status and appear in the staff inbox.
- An administrator sees the **AI replies** control in `/admin/support/`. Turning AI off moves every currently `open` conversation to `needs_human`; existing human-handled conversations are never automatically returned to AI.
- Agents can view and reply to conversations, but only administrators can change the global AI setting.
- The switch is available only when the provider name, API key, model, cURL transport and provider-ready flag are all valid in the private `config.php`. AI remains off until an administrator opts in.
- Provider calls have bounded connection and total timeouts. A timeout, temporary upstream error or malformed response hands that conversation to staff; it does not expose provider errors to the shopper or turn off AI globally.
- Explicit quota/billing exhaustion (for example HTTP 402 or a provider's `insufficient_quota` / `resource_exhausted` response) disables AI globally, moves all open conversations to `needs_human`, and inserts a neutral handoff notice in one database transaction. Invalid provider credentials also fail closed globally.
- `needs_human` conversations are never sent to AI. A handoff is sticky: reopening a conversation for staff workflow does not silently return it to AI. Fitment, VIN, order tracking, payment, returns/refunds, complaints and human requests are escalated before any provider request. AI must not claim compatibility or live stock/order data.
- When AI is unavailable or disabled, a new chat immediately tells the shopper that the support team will take over; the conversation is placed in the staff queue before the shopper's first message.
- If the provider signals a generic request-rate limit without indicating exhausted quota, only the current conversation is handed off; the global switch stays on so a temporary rate limit does not unnecessarily disable the service.
- Provider API keys stay in private `config.php`, never in browser JavaScript or the repository.

## AI provider setup (optional)

The adapter uses the OpenAI Chat Completions request/response shape. Supported provider names are `groq`, `gemini`, and `openai_compatible`:

- `groq`: fixed endpoint `https://api.groq.com/openai/v1/chat/completions`.
- `gemini`: fixed Gemini OpenAI-compatible endpoint `https://generativelanguage.googleapis.com/v1beta/openai/chat/completions`.
- `openai_compatible`: the private `ai.provider.base_url` value; production requires HTTPS.

In private `config.php`, set `ai.provider_ready` to `true`, select a provider, set its API key and exact model ID, and configure timeouts. Keep `ai_enabled` off in the staff inbox until the provider has been verified. Example:

```php
'ai' => [
  'provider_ready' => true,
  'provider' => [
    'name' => 'groq', // or gemini / openai_compatible
    'api_key' => 'YOUR_SERVER_SIDE_API_KEY',
    'model' => 'YOUR_PROVIDER_MODEL_ID',
    'connect_timeout_seconds' => 3,
    'timeout_seconds' => 12,
    'max_tokens' => 350,
  ],
],
```

The customer message is committed to MySQL before the provider is called. The request then waits only for the configured bounded timeout; failures are converted into a normal human handoff and the customer message remains saved. Shared hosting does not provide a background worker here, so provider response latency is bounded but still occurs during the message POST.

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
- `POST /api/product-context.php`: attach a shopper-selected, server-revalidated live WooCommerce listing to the conversation before submitting vehicle details.
- `GET /api/attachment.php?id=…`: retrieve an attachment only when it belongs to the authenticated guest's conversation.

All customer endpoints require the `X-Dimdian-Guest-Token` header except the initial guest-session creation request. The token is 32 cryptographically random bytes represented as 64 hexadecimal characters; only its SHA-256 hash is stored in MySQL.

## Validation

GitHub Actions lints PHP source and staff/widget JavaScript, imports the schema and additive migration into a disposable MySQL 8 service, exercises schema relationships/query shapes, and runs end-to-end customer/staff API tests including live WooCommerce fixture lookups, selected-product context, exact fitment/mismatch handling, trusted listing URL validation, image upload, AI completion, timeout handoff, quota-triggered global shutdown, the administrator provider probe, backup/restore recovery, and multi-client polling/rate-limit burst checks.

## Hostinger setup

1. Create a MySQL database and database user.
2. Import `database/schema.sql` using phpMyAdmin or the MySQL CLI.
3. Apply `database/migrations/2026-11-17-marketplace-fitment-hardening.sql` to the same database.
4. Copy `config.example.php` to `config.php` in the repository root.
5. Set database credentials, production base URL, exact allowed storefront origins, a private upload directory, and unique random secrets for rate limiting and optional storefront identity signing.
6. Point the domain document root to the repository's `public/` directory.
7. Ensure PHP can write to the configured private upload directory. Set `upload_max_filesize` to at least `3M` and `post_max_size` to at least `4M` in Hostinger PHP settings so the 3 MB image limit can be reached.
8. Run `php bin/preflight.php` over SSH. Resolve every `FAIL` before opening traffic; review `WARN` entries such as disabled AI or an unconfigured MPN route.
9. Create the first staff account over SSH from the repository root: `php bin/create-admin.php support@example.com "Support Admin" admin`. Enter the password at the prompts; do not put it in shell history.
10. Visit the site's root URL. A healthy installation returns JSON with `status: ok` and `database: connected`.
11. Open `/admin/support/` and sign in.

Do not commit `config.php`, database credentials, or uploaded customer images.

## Security baseline

- Database access uses PDO prepared statements and native prepares.
- Configuration and uploads are intended to remain outside the public document root.
- CORS is an explicit allowlist; it is not authentication.
- Guest identity uses a random browser token whose hash is stored server-side; the raw token is never stored in the database.
- Staff authentication uses password hashing, secure HTTP-only cookies, session rotation, CSRF protection, IP/account login throttling, an eight-hour idle/twelve-hour absolute session limit, and a live account-active check that revokes sessions when an administrator disables a staff account.
- Guest message and image upload endpoints are rate-limited by both guest identity and source address.
- Uploads must be checked by detected MIME type and image decoding, not filename extension alone.
- User-provided text must be escaped at the point it is rendered.

## Build sequence

1. Completed: database/PHP foundation and customer messaging APIs.
2. Completed: guest widget, part-page context, image uploads, and private attachment delivery.
3. Completed: staff authentication, split-screen inbox, conversation status controls, polling, unread counts, and browser/audio alerts.
4. Remaining: deploy to Hostinger and run end-to-end tests against the actual storefront and MySQL account.
5. Completed: optional cloud AI adapter, bounded provider timeouts, quota-triggered global shutdown, and deterministic human escalation. The signed account-ID handoff is implemented; storefront-side signature generation remains an integration step.


## Live WooCommerce catalogue

The customer widget now has a live catalogue search panel. Shoppers can explicitly select a result as the subject of a vehicle fitment check; the server re-fetches the product by ID before saving its context. Product links are accepted only when they use the configured store's canonical HTTPS host. The server calls the WooCommerce REST API; it does not read the historical Shop HTML snapshot.

Add this to private `config.php`:

```php
'woocommerce' => [
  'enabled' => true,
  'store_url' => 'https://YOUR-DIMDIAN-STORE-DOMAIN',
  'consumer_key' => 'ck_...',
  'consumer_secret' => 'cs_...',
  'currency_code' => 'NGN', // use the actual WooCommerce store currency
  'mpn_lookup_path' => '/wp-json/dimdian/v1/products',
],
```

Create the WooCommerce REST API key in WordPress with **Read** permission only. Never use a Read/Write or Write key. The credentials remain server-side and the adapter only issues GET requests. Product results contain the current WooCommerce price, stock status/quantity, SKU, product ID, permalink and first image URL. A catalogue outage or missing configuration fails closed; the widget does not substitute static snapshot values.

### Manufacturer part numbers

Standard WooCommerce `/wc/v3/products?search=` does not guarantee a search across arbitrary custom product metadata. This repository includes `integrations/wordpress/dimdian-mpn-lookup.php`, a small read-only WordPress plugin route. Install it in WordPress, then define the exact meta key DIMDIAN uses for manufacturer part numbers in `wp-config.php`:

```php
define('DIMDIAN_MPN_META_KEY', 'YOUR_ACTUAL_PRODUCT_META_KEY');
```

Do not copy the example placeholder as the real key. Confirm the field key used by the current product editor/custom-fields plugin first. The support app's `woocommerce.mpn_lookup_path` must point to `/wp-json/dimdian/v1/products`. The route returns only published, visible products and public listing fields.

## Structured vehicle and verified-fitment workflow

- The customer widget collects make, model, year, engine, transmission, trim and an optional 17-character vehicle identifier.
- Vehicle details are stored against the conversation and shown in the authenticated staff inbox. The full identifier is encrypted at rest using AES-256-GCM; staff can view it only through the authenticated staff API. The inbox summary displays the last four characters.
- Configure a unique random `integration.vehicle_data_encryption_key` (at least 32 characters) in private `config.php`. If this key is missing, identifier submission is rejected; the customer can still submit the other vehicle details.
- Back up this encryption key separately and securely. Losing it makes previously encrypted vehicle identifiers unreadable; changing it requires a planned re-encryption migration. The database backup does not include private `config.php` secrets.
- A customer submission is not itself proof of compatibility. A match is returned as verified only when an exact product ID, make, model, year range, engine and transmission match a row whose `product_fitments.status` is `verified`. Trim-specific records are also respected.
- If no verified record matches, the conversation is routed to `needs_human`. The assistant is bypassed for fitment questions.
- An administrator can record a verified fitment from the conversation panel after checking the vehicle against a trusted manufacturer/supplier catalogue. The app first checks that the product ID exists in the live WooCommerce store and records the source, staff account and verification timestamp. Do not mark a row verified based only on product-name similarity.
- Existing compatibility data is not included in the source baseline. Until DIMDIAN's verified fitment records are entered, uncertain requests correctly go to a parts specialist.

## Database migration, backups and recovery

For an existing installation, take a database backup first, then apply:

```sh
mysql -u DB_USER -p DB_NAME < database/migrations/2026-11-17-marketplace-fitment-hardening.sql
```

For a fresh installation, import `database/schema.sql` and then the same migration. The migration adds atomic rate-limit buckets, conversation vehicle records and verified product-fitment records. Do not skip it.

Set `backup.directory` to an absolute private directory outside the configured web document root. From SSH, run:

```sh
php bin/backup-db.php
php bin/restore-db.php /absolute/private/path/dimdian-YYYYMMDD-HHMMSS.sql.gz
```

The restore command requires typing `RESTORE` and overwrites database objects/data. Test recovery on a disposable database before using it on production. These scripts require the `mysqldump` and `mysql` command-line clients; if Hostinger's plan does not expose them, use phpMyAdmin's export/import tools and keep exports outside the public web root. Backups contain customer and support data and must be access-restricted.

## Live provider activation

The administrator panel now includes **Test provider**. It sends a minimal probe to the configured provider and requires the exact expected response. A successful test does not turn AI on; an administrator must still enable AI separately. Quota exhaustion or invalid credentials keep AI disabled and route open conversations to staff.

CI tests the provider adapter using a local stub. This is not a live Groq/Gemini test. Production activation still requires a real API key and model ID in private `config.php`, a successful **Test provider** result from the deployed server, and an administrator enabling AI.

## Hostinger production checklist

1. Deploy the repository with the web document root pointed at `public/`; keep `config.php`, backups and uploaded files outside it.
2. Import the base schema and run the additive migration above.
3. Configure the WooCommerce REST key with **Read-only** permissions, the actual store URL, currency and MPN metadata route.
4. Configure a unique rate-limit secret, upload directory and vehicle encryption key. Keep provider credentials private.
5. Verify the PHP version/extensions available on the actual plan: PHP 8.2+, PDO MySQL, cURL, fileinfo and OpenSSL. Check PHP upload limits.
6. Create the first admin, sign in, test the staff inbox and confirm a customer can send a message and a vehicle submission.
7. Test a real WooCommerce SKU, name and MPN lookup. Confirm prices and stock against the store's product editor.
8. Add a small, trusted set of verified fitment records; test both a positive match and a non-match that must reach staff.
9. Run a backup, restore it into a separate test database, and verify the application health endpoint.
10. Configure and test the real AI provider. Keep AI off until the provider test passes and human fallback is confirmed.

Hostinger deployment, actual WooCommerce credentials, the live MPN metadata key, verified fitment dataset and real AI credentials are environment inputs; they are not present in this repository and are not fabricated by the build.


For release and rollback steps, see [DEPLOYMENT.md](DEPLOYMENT.md). Run `php bin/preflight.php` after configuration and migrations to validate the actual hosting environment. Schedule `php bin/cleanup-rate-limits.php` as a daily Hostinger cron task to prune expired rate-limit buckets without adding cleanup work to customer requests.
