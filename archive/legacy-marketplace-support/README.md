# DimdianChatbot

A lightweight, self-hosted customer support chat system for DIMDIAN's auto-parts marketplace. It is designed for the PHP and MySQL hosting DIMDIAN already operates, rather than depending on a paid support platform.

## What this system does — plain-English overview

Customers can ask DIMDIAN for help while browsing the store without first creating an account. The chat remembers the browser's support session as the customer moves between product pages and gives staff the page/product context the shopper was viewing.

Customers can send messages and photos (JPG, PNG or WEBP, up to 3 MB), search the live catalogue by product name or SKU, and search by manufacturer part number once DIMDIAN's actual MPN field is connected. They can select a product and submit vehicle details: make, model, year, engine, transmission, trim and, when appropriate, a VIN.

Staff use a password-protected inbox at `/admin/support/`. They can review incoming conversations, reply, inspect product and vehicle context, view customer attachments and manage the support queue. Unread indicators, browser-tab title updates and optional sound alerts help staff notice new messages.

The catalogue adapter reads product information from WooCommerce on the server. Product prices, stock status, product links and listing details come from WooCommerce's API response—not the old Shop-page HTML snapshot. If the live catalogue is unavailable, the app does not silently substitute old snapshot data.

Compatibility checks are deliberately strict. A part is reported as compatible only when DIMDIAN has a verified fitment record for the exact product and matching vehicle details. Missing, incomplete or uncertain matches go to a human parts specialist. VINs are encrypted before storage.

An optional cloud AI can answer suitable routine questions, but the support system does not depend on it. AI is off by default. Fitment, VIN, order tracking, payment, returns/refunds, complaints and requests for a person are routed to staff. Timeouts, invalid credentials and quota exhaustion trigger human fallback; quota/credential failures disable AI globally. The application has no live order-management integration and AI must not invent order, stock or compatibility facts.

**In practical terms:** this is a customer chat and staff inbox with live product lookup and a controlled vehicle-fitment workflow. It is not yet deployed to DIMDIAN's hosting, does not arrive with a populated compatibility database, and does not replace staff judgement.

## How it works — developer view

The application is a conventional PHP/MySQL web app with no required Composer or npm runtime dependency:

1. **Browser widget** — `public/widget.js` creates/resumes a guest identity, captures page URL/title and optional SKU, and calls the support API over HTTPS.
2. **Customer API** — endpoints under `public/api/` validate the guest token, request method, origin, payload and rate limits. The browser holds the raw random token; MySQL stores only its SHA-256 hash.
3. **Persistence** — PDO prepared statements store customers, conversations, messages, attachments, selected product context and vehicle submissions. Conversation states are `open`, `needs_human` and `closed`.
4. **Message delivery** — the widget fetches only messages newer than its last message ID. Short polling replaces a persistent WebSocket service and pauses while the widget is closed or the tab is hidden.
5. **Staff inbox** — `public/admin/support/` uses authenticated staff sessions and CSRF-protected actions. Agents handle conversations; administrator-only controls govern AI activation.
6. **WooCommerce adapter** — `app/woocommerce.php` makes server-side HTTPS GET requests using a read-only REST API key. SKU/name searches use WooCommerce's products endpoint. MPN search uses the optional WordPress route in `integrations/wordpress/dimdian-mpn-lookup.php`. Selected products are re-fetched by ID before attachment to a vehicle check.
7. **Fitment engine** — `app/vehicle.php` checks a concrete simple-product ID, make, model, year range, engine and transmission against rows marked `verified` in `product_fitments`; trim-specific records are respected. A miss is a staff handoff, not a fuzzy match. VINs use AES-256-GCM encryption with a private application key.
8. **AI adapter** — `app/ai.php` applies deterministic escalation rules before `app/ai_provider.php` calls Groq, Gemini or an OpenAI-compatible endpoint. The customer message is saved first; the provider call then runs synchronously with bounded timeouts. Failures hand off the conversation, while explicit quota exhaustion or invalid credentials disables AI globally.
9. **Operations** — private attachments/configuration/backups are kept outside web roots. CLI tools provide preflight checks, staff provisioning, database backup/restore and rate-limit cleanup.

This is designed for shared hosting: no queue worker, local language model, WebSocket daemon or automatic staff-presence/assignment service is required. A human handoff means the conversation enters the staff inbox; it does not guarantee an agent is online or instantly connected.

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

The repository is structured so the web server's document root can point to `public/`. Set `app.document_root` in private `config.php` to the actual Hostinger document root (the template assumes `public/` is the root). If the app is installed beneath a larger `public_html` root, set the parent web root explicitly. Attachment and backup storage must be outside every configured/active web root; runtime checks reject paths inside those roots. Keep `config.php` and uploaded files outside the public document root.

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
- The backend requires a successful **Test provider** probe before the AI switch can be enabled. A keyed fingerprint of the provider endpoint, model and API key is stored; the raw key is never stored in the fingerprint record. Changing provider identity/configuration, exhausting quota, invalid credentials, or using the global AI-off switch invalidates the test and requires another successful probe.
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

In private `config.php`, set `ai.provider_ready` to `true`, select a provider, set its API key and exact model ID, and configure timeouts. Keep `ai_enabled` off until **Test provider** succeeds. The server enforces this: attempting to enable AI before a successful test returns `ai_provider_test_required`. Example:

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

## Detailed Hostinger setup guide

This is the fresh-install procedure. For an existing installation, back up the database and private files first and follow [DEPLOYMENT.md](DEPLOYMENT.md). Do not import the base schema over a production database that already contains data.

### 1. Check hosting requirements

Confirm the Hostinger plan provides PHP 8.2+, MySQL 8.0+ or MariaDB 10.5+, HTTPS, PDO MySQL, fileinfo, OpenSSL, JSON and cURL (for WooCommerce/AI). PHP zlib is needed for compressed backups. SSH is recommended for installation and CLI tools. Set `upload_max_filesize` to at least `3M` and `post_max_size` to at least `4M`.

The web application needs no Composer, npm, Node runtime, persistent daemon or root access. The automated backup/restore scripts additionally require the `mysqldump` and `mysql` command-line programs; if unavailable on the plan, use phpMyAdmin for export/import.

### 2. Upload the application and set the document root

Use a dedicated support subdomain (recommended) and upload the repository to a private application directory. A suitable layout is:

```text
/home/ACCOUNT/dimdian-chatbot/
  app/ bin/ database/ integrations/ public/ tests/
  config.example.php
  config.php                    # private; create this; never commit
/home/ACCOUNT/dimdian-private-uploads/
/home/ACCOUNT/dimdian-private-backups/
```

Set the support domain's document root to the repository's `public/` directory. Only `public/` should be web-accessible. Keep `config.php`, uploads and backups outside every active web document root. If the app is installed below `public_html`, configure the actual parent web root accurately and keep private files outside it. Do not fix permissions by making private directories world-readable.

Set `app.document_root` in private `config.php` to the absolute canonical path of the actual support-domain document root. The template assumes the release's `public/` directory; replace that with the real Hostinger path. Runtime checks reject upload/backup paths that are inside or overlap configured/active web roots.

### 3. Create and initialize MySQL

Create a database and database user in hPanel. Record the exact host, database name, username and password provided by Hostinger; the host is not necessarily `localhost`. Grant the application user the privileges needed for initial schema/migration setup (SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX and REFERENCES).

From the repository root, import the base schema into a new empty database:

```sh
mysql -h DB_HOST -u DB_USER -p DB_NAME < database/schema.sql
mysql -h DB_HOST -u DB_USER -p DB_NAME < database/migrations/2026-11-17-marketplace-fitment-hardening.sql
```

Enter the password at the prompt. Do not include it in the command, shell history or source code. If CLI MySQL is unavailable, import both files in phpMyAdmin, in that order. For an existing installation, take a backup first; the migration is additive and idempotent.

### 4. Create private config.php

Copy the template into the repository root:

```sh
cp config.example.php config.php
```

Edit `config.php` and set:

| Key | Required value |
|---|---|
| `app.environment` | `production` |
| `app.document_root` | Actual absolute support-domain document-root path |
| `app.base_url` | Support subdomain HTTPS URL, no trailing slash |
| `app.allowed_origins` | Exact storefront origins, including scheme and hostname, no path |
| `database.host/name/username/password` | Exact Hostinger database credentials |
| `uploads.directory` | Absolute private path outside all web roots |
| `backup.directory` | Absolute persistent private path outside all web roots |
| `security.rate_limit_secret` | Unique random secret, 32+ random bytes |
| `integration.customer_identity_secret` | Separate random secret, only if signed account linking is used |
| `integration.vehicle_data_encryption_key` | Unique random secret, 32+ characters/bytes, for VIN encryption |

Generate a fresh value separately for each secret:

```sh
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

Do not reuse the same secret for multiple purposes. Keep the VIN encryption key in a secure password manager and protected backup. Losing it makes previously stored VINs unreadable; changing it requires a planned re-encryption migration.

The template's upload and backup paths are intended as sibling directories outside the application web root. Ensure their parent exists and the PHP account can write there. Keep WooCommerce disabled and `ai.provider_ready` false for the initial installation. Never commit `config.php`.

### 5. Connect the live WooCommerce catalogue

In WordPress, create a WooCommerce REST API key for a dedicated integration user and set its permission to **Read** only. Do not use a Read/Write or Write key.

Add the following section to private `config.php`, using the real store URL and credentials:

```php
'woocommerce' => [
  'enabled' => true,
  'store_url' => 'https://ACTUAL-DIMDIAN-STOREFRONT',
  'consumer_key' => 'ck_actual_read_only_key',
  'consumer_secret' => 'cs_actual_read_only_secret',
  'currency_code' => 'NGN', // confirm the store's configured currency
  'mpn_lookup_path' => '/wp-json/dimdian/v1/products',
],
```

The adapter uses server-side HTTPS GET requests only. SKU and product-name searches use the standard WooCommerce products endpoint. Returned price, sale/regular price, stock status/quantity, SKU, link, image and short description come from the WooCommerce response at lookup time. The application does not write to WooCommerce. If the API is unavailable, product search fails closed; it does not fall back to the historical Shop HTML snapshot.

**Manufacturer part numbers require a separate WordPress setup.** WooCommerce has no universal MPN field. Confirm the exact custom-field/meta key DIMDIAN uses in its product editor. Install `integrations/wordpress/dimdian-mpn-lookup.php` as a WordPress plugin (copy it into `wp-content/plugins/dimdian-mpn-lookup/`, activate it, and add the confirmed key to `wp-config.php`):

```php
define('DIMDIAN_MPN_META_KEY', 'THE_CONFIRMED_DIMDIAN_MPN_META_KEY');
```

Do not copy the example string as the actual key. Confirm the key with the person who manages DIMDIAN's product fields. The plugin exposes `/wp-json/dimdian/v1/products`; set `woocommerce.mpn_lookup_path` to that path. Test authentication and a known MPN against the real store before advertising MPN search as available.

### 6. Add the widget to the storefront

Add this script to the shared WooCommerce storefront layout on pages where support chat should appear:

```html
<script
  src="https://SUPPORT-SUBDOMAIN/widget.js"
  data-api-base="https://SUPPORT-SUBDOMAIN/api/"
  data-context-sku="PRODUCT_PAGE_SKU"
  defer
></script>
```

Replace the support hostname and SKU expression with the actual values from DIMDIAN's storefront template. The widget also captures the current page URL and title. Add the exact storefront HTTPS origin to `app.allowed_origins`.

Logged-in account linking is optional. If wanted, use the server-side HMAC example under [Customer widget integration](#customer-widget-integration), and configure the same random shared secret in the storefront's private environment and `integration.customer_identity_secret`. Never expose that secret in HTML or JavaScript. Guest chat works without account linking.

### 7. Create the first administrator

From SSH, in the repository root, run:

```sh
php bin/create-admin.php support@example.com "DIMDIAN Support Admin" admin
```

Enter a unique password of at least 12 characters at the prompts. The password is hashed and is not supplied in the command line. Create agents with the final argument `agent`. Limit administrator accounts to trusted staff.

Open `https://SUPPORT-SUBDOMAIN/admin/support/` and sign in. The root health endpoint should return JSON showing `status: ok` and `database: connected`. This checks basic application/database health; it does not prove external integrations work.

### 8. Run preflight

Run from SSH:

```sh
php bin/preflight.php
```

Resolve every `FAIL` before exposing the widget to customers. Review every `WARN`: disabled WooCommerce, disabled AI or missing MPN route may be intentional during setup, but those capabilities will not be available. Preflight checks runtime requirements, database tables, private storage and configured WooCommerce/AI connectivity.

A successful WooCommerce probe does not prove that every listing is correct or that MPN metadata is mapped properly. Manually test a known SKU, product name and MPN against the store.

### 9. Populate verified fitment records

The repository does not contain DIMDIAN's compatibility dataset. A DIMDIAN parts specialist must verify each fitment against a trusted manufacturer or supplier source before recording it.

From the staff inbox, open the relevant product/conversation and use the verified-fitment action only after checking the source. Record the source and exact product, make, model, year range, engine and transmission; include trim when the source specifies it. Only rows marked `verified` can produce a positive match. Missing or uncertain records must remain human handoffs.

Automatic matching currently supports simple WooCommerce products. Variable/grouped parent listings can be searched and sent to staff, but are not treated as one universally compatible part.

### 10. Enable AI only if DIMDIAN wants it

AI is optional. For human-only support, leave `ai.provider_ready` false and the database `ai_enabled` setting off.

To enable later, configure Groq, Gemini or an HTTPS OpenAI-compatible provider in private `config.php` with a real API key and exact model ID, then set `ai.provider_ready` true. Run preflight. In the staff inbox, an administrator must use **Test provider** and receive a successful response before enabling **AI replies**.

Test routine FAQs and all escalation categories. CI uses a local provider stub; it does not validate live credentials or spend real provider quota. AI calls are synchronous during the customer message POST, although connection and total timeouts are bounded. A timeout hands that conversation to staff; exhausted quota or invalid credentials disables AI globally.

### 11. Backups and scheduled maintenance

Run a manual backup:

```sh
php bin/backup-db.php
```

This requires `mysqldump` and a writable private backup directory. The script creates a compressed SQL dump with owner-only permissions and retains 14 days by default. If the host does not expose `mysqldump`, export using phpMyAdmin and store the export outside the web root.

In hPanel Cron Jobs, configure:
- A daily `php /absolute/path/to/dimdian-chatbot/bin/backup-db.php` task, if CLI database tools are available.
- A daily `php /absolute/path/to/dimdian-chatbot/bin/cleanup-rate-limits.php` task.
- Notifications/monitoring for failed cron executions.

Use Hostinger's actual PHP CLI binary and absolute application path. Confirm cron loads the same private `config.php` and database as the website. Back up `config.php`, the VIN encryption key and private uploads separately; SQL backups do not contain them.

Perform a restore drill into a separate test database before launch and periodically thereafter. The restore script requires the `mysql` CLI, asks you to type `RESTORE`, and overwrites the selected target database. Never test recovery against the live database.

### 12. Launch acceptance checklist

- [ ] Health endpoint reports a database connection.
- [ ] Administrator and agent logins work; disabling an account revokes its sessions.
- [ ] A guest can start a chat, browse another product page and continue the same conversation.
- [ ] Staff replies reach the customer.
- [ ] Product URL/title/SKU context is visible to staff.
- [ ] Valid JPG, PNG and WEBP files upload; invalid/oversized files are rejected; attachments are private to the owning conversation.
- [ ] Known live WooCommerce SKU and product-name searches return expected details.
- [ ] A known MPN works if the custom route is configured.
- [ ] A verified fitment matches; a missing or mismatched fitment reaches staff.
- [ ] VIN encryption works and the encryption key is backed up securely.
- [ ] AI stays off unless the real provider probe passes and an administrator deliberately enables it.
- [ ] A database backup restores successfully into a separate test database.
- [ ] Staff understand that `needs_human` means queued for the team, not that an agent is guaranteed to be online instantly.

Follow [DEPLOYMENT.md](DEPLOYMENT.md) for the controlled release and rollback procedure. Do not describe the system as live until these checks have passed on DIMDIAN's actual Hostinger account and storefront.

## Security baseline

- Database access uses PDO prepared statements and native prepares.
- Configuration and uploads are intended to remain outside the public document root.
- CORS is an explicit allowlist; it is not authentication.
- Guest identity uses a random browser token whose hash is stored server-side; the raw token is never stored in the database.
- Staff authentication uses password hashing, secure HTTP-only cookies, session rotation, CSRF protection, IP/account login throttling, an eight-hour idle/twelve-hour absolute session limit, and a live account-active check that revokes sessions when an administrator disables a staff account.
- Guest message and image upload endpoints are rate-limited by both guest identity and source address.
- Uploads must be checked by detected MIME type and image decoding, not filename extension alone.
- User-provided text must be escaped at the point it is rendered.
- Responses include frame denial, MIME sniffing protection, a restrictive referrer policy, disabled unused device permissions and HSTS when the production base URL is HTTPS.

## Delivery status

| Area | Repository status | Still required before launch |
|---|---|---|
| Customer chat and staff inbox | Implemented and covered by automated tests | Deploy and test with DIMDIAN staff |
| Guest identity, listing context and image attachments | Implemented | Verify storefront embed and Hostinger file permissions |
| WooCommerce SKU/name lookup | Server-side read-only adapter implemented | Add real read-only API credentials and test against the live store |
| Manufacturer part-number lookup | Optional WordPress route included | Confirm DIMDIAN's real MPN meta key, install plugin and test |
| Vehicle details and VIN encryption | Implemented | Configure the private encryption key |
| Verified fitment matching | Exact-match workflow implemented | DIMDIAN must build and maintain verified fitment records |
| AI provider and human fallback | Implemented, opt-in and fail-closed | Add a real provider key/model, test live and explicitly enable |
| Backups, recovery and preflight | CLI tooling and CI tests implemented | Confirm Hostinger CLI tools/permissions and complete a restore drill |
| Hostinger production deployment | Not performed | Hosting access, configuration and acceptance testing |

GitHub Actions validates PHP/JavaScript syntax, applies the schema and migration to a disposable MySQL 8 service, and runs API, integration, provider-stub, backup/restore and concurrent polling/rate-limit tests. Passing CI is evidence for the tested code paths in that test environment; it is not evidence of a successful Hostinger deployment or live DIMDIAN integration.

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

Standard WooCommerce `/wc/v3/products?search=` does not guarantee a search across arbitrary custom product metadata. This repository includes `integrations/wordpress/dimdian-mpn-lookup.php`, a small read-only WordPress plugin route that requires the WooCommerce read-only API credentials. Install it in WordPress, then define the exact meta key DIMDIAN uses for manufacturer part numbers in `wp-config.php`:

```php
define('DIMDIAN_MPN_META_KEY', 'YOUR_ACTUAL_PRODUCT_META_KEY');
```

Do not copy the example placeholder as the real key. Confirm the field key used by the current product editor/custom-fields plugin first. The support app's `woocommerce.mpn_lookup_path` must point to `/wp-json/dimdian/v1/products`. The route requires an authenticated WordPress user with read capability (the configured WooCommerce API key supplies this) and returns only published, visible products and public listing fields.

## Structured vehicle and verified-fitment workflow

- The customer widget collects make, model, year, engine, transmission, trim and an optional 17-character vehicle identifier.
- Vehicle details are stored against the conversation and shown in the authenticated staff inbox. The full identifier is encrypted at rest using AES-256-GCM; staff can view it only through the authenticated staff API. The inbox summary displays the last four characters.
- Configure a unique random `integration.vehicle_data_encryption_key` (at least 32 characters) in private `config.php`. If this key is missing, identifier submission is rejected; the customer can still submit the other vehicle details.
- Back up this encryption key separately and securely. Losing it makes previously encrypted vehicle identifiers unreadable; changing it requires a planned re-encryption migration. The database backup does not include private `config.php` secrets.
- A customer submission is not itself proof of compatibility. A match is returned as verified only when an exact product ID, make, model, year range, engine and transmission match a row whose `product_fitments.status` is `verified`. Trim-specific records are also respected.
- Automatic matching and staff verification currently require a simple WooCommerce product. Variable/grouped listings remain searchable, but their parent product is not treated as a single compatible part; those requests go to a parts specialist until variation-level fitment is implemented.
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
php bin/restore-db.php /absolute/private/path/dimdian-YYYYMMDD-HHMMSS-randomhex.sql.gz test_database_name
```

The restore command requires typing `RESTORE` and overwrites database objects/data. Test recovery on a disposable database before using it on production. These scripts require the `mysqldump` and `mysql` command-line clients; if Hostinger's plan does not expose them, use phpMyAdmin's export/import tools and keep exports outside the public web root. Backups contain customer and support data and must be access-restricted. Files are created with owner-only permissions, collision-resistant names, and a configurable retention window (`backup.retention_days`, default 14 days; clamped to 1–365). Expired matching backups are pruned only after a new backup succeeds.

## Live provider activation

The administrator panel now includes **Test provider**. It sends a minimal probe to the configured provider and requires the exact expected response. A successful test records a keyed configuration fingerprint; it does not turn AI on. An administrator must still enable AI separately. The backend refuses activation until the current endpoint/model/key combination has passed the probe. Manual AI shutdown, quota exhaustion or invalid credentials clears that verification. Quota exhaustion or invalid credentials keep AI disabled and route open conversations to staff.

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
